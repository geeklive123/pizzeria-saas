<?php

namespace App\Services;

use App\Actions\SaveBancoEconomicoSettingAction;
use App\Models\BankQrDiagnostic;
use App\Models\BankQrSetting;
use App\Models\DynamicQrIntent;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BancoEconomicoGateway
{
    private ?int $lastHttpStatus = null;

    private ?string $lastResponseCode = null;

    private bool $generationAttempted = false;

    public function generationAttempted(): bool
    {
        return $this->generationAttempted;
    }

    public function responseMetadata(): array
    {
        return ['http_status' => $this->lastHttpStatus, 'response_code' => $this->lastResponseCode];
    }

    public function testAuthentication(BankQrSetting $setting): void
    {
        $this->token($setting, true);
    }

    public function generate(DynamicQrIntent|BankQrDiagnostic $intent): array
    {
        $this->generationAttempted = false;
        $setting = $intent->setting;
        $account = $this->encrypt($setting, $setting->credentials['account_credit']);
        $token = $this->token($setting);
        $body = [
            'transactionId' => $intent->commercial_reference,
            'accountCredit' => $account,
            'currency' => 'BOB',
            'amount' => '__EXACT_AMOUNT__',
            'description' => 'Masa & Maña',
            'dueDate' => $intent->expires_at->timezone('America/La_Paz')->format('Y-m-d'),
            'singleUse' => true,
            'modifyAmount' => false,
        ];
        if (! empty($setting->credentials['branch_code'])) {
            $body['branchCode'] = $setting->credentials['branch_code'];
        }
        // JSON number, without ever converting the decimal to a PHP float.
        $json = str_replace('"__EXACT_AMOUNT__"', $intent->amount, json_encode($body, JSON_THROW_ON_ERROR));
        $data = $this->authorized($setting, 'POST', 'api/qrsimple/generateQR', $json, $token, true, 'generate');
        if (! is_string($data['qrId'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $data['qrId'])) {
            throw new BankQrException('invalid_generation_response', true, operation: 'generate', responseCode: '0');
        }

        return $data;
    }

    public function image(string $image): string
    {
        $image = preg_replace('#^data:image/(?:png|jpeg);base64,#', '', $image);
        $bytes = strlen($image) <= 2000000 ? base64_decode($image, true) : false;
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg'], true) || $info[0] > 4096 || $info[1] > 4096) {
            throw new BankQrException('invalid_qr_image', true, operation: 'image');
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
    }

    public function status(DynamicQrIntent|BankQrDiagnostic $intent): array
    {
        $data = $this->authorized($intent->setting, 'GET', 'api/qrsimple/v2/statusQR/'.rawurlencode($intent->bank_qr_id), operation: 'status');
        if (isset($data['statusQrCode'], $data['statusQRCode']) && $data['statusQrCode'] !== $data['statusQRCode']) {
            throw new BankQrException('conflicting_bank_status', operation: 'status', responseCode: '0');
        }

        return $data;
    }

    /** Read-only evidence; transactionId is the bank payment ID, not the merchant reference. */
    public function paidOn(BankQrSetting $setting, string $date): array
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Ymd', $date);
        if (! $parsed || $parsed->format('Ymd') !== $date) {
            throw new BankQrException('invalid_paid_date');
        }
        $data = $this->authorized($setting, 'GET', 'api/qrsimple/v2/paidQR/'.$date, operation: 'paid_list');
        $list = $data['paymentList'] ?? null;
        if (! is_array($list) || ! array_is_list($list)) {
            throw new BankQrException('invalid_paid_list');
        }
        $result = [];
        foreach ($list as $payment) {
            if (! is_array($payment) || ! is_string($payment['qrId'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $payment['qrId'])
                || ! is_string($payment['transactionId'] ?? null) || $payment['transactionId'] === '' || strlen($payment['transactionId']) > 190
                || ! in_array($payment['currency'] ?? null, ['BOB', 'USD'], true)
                || ! is_string($payment['amount'] ?? null) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $payment['amount'])
                || ! BigDecimal::of($payment['amount'])->isPositive()
                || ! is_string($payment['paymentDate'] ?? null) || ! in_array($payment['paymentDate'], [$parsed->format('Y-m-d'), $parsed->format('Y-m-d').'T00:00:00'], true)
                || ! is_string($payment['paymentTime'] ?? null) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $payment['paymentTime'])) {
                throw new BankQrException('invalid_paid_list');
            }
            $result[] = array_intersect_key($payment, array_flip(['qrId', 'transactionId', 'currency', 'amount', 'paymentDate', 'paymentTime']));
        }

        return $result;
    }

    public function cancel(DynamicQrIntent|BankQrDiagnostic $intent): void
    {
        $this->authorized($intent->setting, 'DELETE', 'api/qrsimple/cancelQR', json_encode(['qrId' => $intent->bank_qr_id], JSON_THROW_ON_ERROR), operation: 'cancel');
    }

    private function encrypt(BankQrSetting $setting, string $plain): string
    {
        $response = $this->request('GET', 'api/authentication/encrypt', query: ['text' => $plain, 'aesKey' => $setting->credentials['aes_key']], operation: 'encrypt', setting: $setting);
        $value = json_decode($response->body(), true);
        $value = is_string($value) ? $value : (json_last_error() !== JSON_ERROR_NONE ? trim($response->body()) : null);
        $value = is_string($value) ? trim($value) : null;
        if (! is_string($value) || $value === '' || strlen($value) > 16384) {
            throw new BankQrException('encryption_failed', operation: 'encrypt', httpStatus: $response->status());
        }

        return $value;
    }

    private function token(BankQrSetting $setting, bool $refresh = false): string
    {
        $key = 'baneco:token:'.$setting->id.':'.hash('sha256', $setting->getRawOriginal('credentials'));
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::lock($key.':lock', 40)->block(5, function () use ($setting, $key): string {
            if ($cached = Cache::get($key)) {
                return Crypt::decryptString($cached);
            }
            $password = $this->encrypt($setting, $setting->credentials['password']);
            $data = $this->decode($this->request('POST', 'api/authentication/authenticate', json_encode(['userName' => $setting->credentials['username'], 'password' => $password], JSON_THROW_ON_ERROR), operation: 'authenticate', setting: $setting), operation: 'authenticate', logging: $setting->technical_logging ?? true);
            if (! is_string($data['token'] ?? null) || $data['token'] === '') {
                throw new BankQrException('authentication_failed', operation: 'authenticate', responseCode: '0');
            }
            $token = $data['token'];
            $parts = explode('.', $token);
            $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
            $ttl = isset($payload['exp']) ? max(1, min(1800, (int) $payload['exp'] - time() - 60)) : 600;
            Cache::put($key, Crypt::encryptString($token), $ttl);

            return $token;
        });
    }

    private function authorized(BankQrSetting $setting, string $method, string $path, ?string $body = null, ?string $token = null, bool $generation = false, string $operation = 'status'): array
    {
        $token ??= $this->token($setting);
        $response = $this->request($method, $path, $body, $token, allowUnauthorized: true, uncertain: $generation, operation: $operation, setting: $setting);
        if ($response->status() === 401) {
            if ($generation) {
                throw new BankQrException('bank_http_401', true, $operation, 401, $this->responseCode($response));
            }
            $response = $this->request($method, $path, $body, $this->token($setting, true), uncertain: $generation, operation: $operation, setting: $setting);
        }

        return $this->decode($response, $generation, $operation, $setting->technical_logging ?? true);
    }

    private function request(string $method, string $path, ?string $body = null, ?string $token = null, array $query = [], bool $allowUnauthorized = false, bool $uncertain = false, string $operation = 'status', ?BankQrSetting $setting = null): Response
    {
        $this->lastHttpStatus = null;
        $this->lastResponseCode = null;
        $base = $setting?->base_url ?? config('baneco.base_url');
        if (! is_string($base) || ! in_array($base, SaveBancoEconomicoSettingAction::URLS, true)) {
            throw new BankQrException('invalid_api_url');
        }
        try {
            $request = Http::acceptJson()->withHeaders(['Content-Type' => 'application/json'])->connectTimeout(5)->timeout(12)->withoutRedirecting();
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            if ($body !== null) {
                $request = $request->withBody($body, 'application/json');
            }
            if ($operation === 'generate') {
                $this->generationAttempted = true;
            }
            $response = $request->send($method, rtrim($base, '/').'/'.$path, $query ? ['query' => $query] : []);
            $this->lastHttpStatus = $response->status();
            $this->lastResponseCode = $this->responseCode($response);
            if ($operation === 'generate' && ($setting?->technical_logging ?? true)) {
                $this->diagnoseShape($response);
            }
        } catch (ConnectionException) {
            // Never propagate transport exceptions: encryption URLs contain bank secrets.
            throw new BankQrException('bank_unreachable', $uncertain, $operation);
        }
        if (! $response->successful() && ! ($allowUnauthorized && $response->status() === 401)) {
            if ($setting?->technical_logging ?? true) {
                $this->diagnose($operation, $response, 'bank_http_error');
            }
            throw new BankQrException('bank_http_'.$response->status(), $uncertain, $operation, $response->status(), $this->responseCode($response));
        }

        return $response;
    }

    private function decode(Response $response, bool $uncertain = false, string $operation = 'status', bool $logging = true): array
    {
        // Check the original JSON first; rewriting invalid numeric tokens must not make them valid.
        $body = $response->body();
        json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        $validJson = json_last_error() === JSON_ERROR_NONE;
        $raw = $validJson ? $this->quoteJsonNumbers($body) : '';
        $data = json_decode($raw, true);
        if (! is_array($data) || ! in_array($data['responseCode'] ?? $data['response_code'] ?? null, ['0', 0], true)) {
            $code = is_array($data) ? ($data['responseCode'] ?? $data['response_code'] ?? null) : null;
            $issue = is_string($code) && preg_match('/^[0-9]{1,6}$/D', $code) && $code !== '0'
                ? 'bank_response_'.$code : 'bank_invalid_response';
            if ($logging) {
                $this->diagnose($operation, $response, $issue);
            }
            throw new BankQrException($issue, $uncertain, $operation, $response->status(), $this->responseCode($response));
        }

        if ($logging && $operation === 'generate' && (! is_string($data['qrId'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $data['qrId']) || ! is_string($data['qrImage'] ?? null))) {
            $this->diagnose($operation, $response, 'invalid_generation_fields');
        }

        return $data;
    }

    /** Preserve numeric tokens without PCRE backtracking on large Base64 strings. */
    private function quoteJsonNumbers(string $body): string
    {
        $result = '';
        $length = strlen($body);
        $inString = false;
        $escaped = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($inString) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
                $result .= $char;
            } elseif ($char === '-' || ($char >= '0' && $char <= '9')) {
                $start = $i;
                while ($i + 1 < $length && str_contains('0123456789.eE+-', $body[$i + 1])) {
                    $i++;
                }
                $result .= '"'.substr($body, $start, $i - $start + 1).'"';
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function diagnose(string $operation, Response $response, string $error): void
    {
        $data = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
        $valid = json_last_error() === JSON_ERROR_NONE;
        $code = is_array($data) ? ($data['responseCode'] ?? $data['response_code'] ?? null) : null;
        $code = (is_int($code) || is_string($code)) && preg_match('/^\d{1,6}$/D', (string) $code) ? (string) $code : null;

        Log::warning('baneco_response_diagnostic', [
            'operation' => $operation,
            'http_status' => $response->status(),
            'bank_response_code' => $code,
            'json_valid' => $valid,
            'generation_fields_present' => $operation === 'generate' && is_array($data)
                ? ['qrId' => array_key_exists('qrId', $data), 'qrImage' => array_key_exists('qrImage', $data)] : null,
            'error_code' => $error,
        ]);
    }

    private function diagnoseShape(Response $response): void
    {
        $decoded = json_decode($response->body());
        Log::info('baneco_response_shape', [
            'operation' => 'generate',
            'http_status' => $response->status(),
            'bank_response_code' => $this->responseCode($response),
            'fields' => $this->safeFields($decoded),
            'generation_fields_present' => is_object($decoded)
                ? ['qrId' => property_exists($decoded, 'qrId'), 'qrImage' => property_exists($decoded, 'qrImage')] : null,
        ]);
    }

    /** Never log arbitrary field names, which could themselves contain sensitive data. */
    private function safeFields(mixed $value): ?array
    {
        if (! is_object($value)) {
            return null;
        }
        $result = [];
        foreach (['responseCode', 'response_code', 'qrId', 'qrImage', 'message', 'data', 'result'] as $key) {
            if (property_exists($value, $key)) {
                $field = $value->{$key};
                $result[$key] = match (true) {
                    is_null($field) => 'null',
                    is_string($field) => 'string',
                    is_int($field), is_float($field) => 'number',
                    is_bool($field) => 'boolean',
                    is_array($field) => 'array',
                    is_object($field) => 'object',
                    default => 'unknown',
                };
                if (in_array($key, ['data', 'result'], true) && is_object($field)) {
                    $result[$key.'.fields'] = $this->safeNestedFields($field);
                }
            }
        }

        return $result;
    }

    private function safeNestedFields(object $value): array
    {
        $result = [];
        foreach (['qrId', 'qrImage', 'responseCode', 'response_code'] as $key) {
            if (property_exists($value, $key)) {
                $field = $value->{$key};
                $result[$key] = is_null($field) ? 'null' : (is_string($field) ? 'string' : (is_int($field) || is_float($field) ? 'number' : (is_array($field) ? 'array' : (is_object($field) ? 'object' : 'boolean'))));
            }
        }

        return $result;
    }

    private function responseCode(Response $response): ?string
    {
        $data = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
        $code = is_array($data) ? ($data['responseCode'] ?? $data['response_code'] ?? null) : null;

        return (is_int($code) || is_string($code)) && preg_match('/^\d{1,6}$/D', (string) $code) ? (string) $code : null;
    }
}
