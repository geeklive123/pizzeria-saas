<?php

namespace App\Services;

class BankQrImageInspection
{
    public function pngBytes(?string $original): ?string
    {
        if ($original === null || strlen($original) > 2000022) {
            return null;
        }

        $payload = str_starts_with($original, 'data:image/png;base64,') ? substr($original, 22) : $original;
        if ($payload === '' || strlen($payload) > 2000000 || strlen($payload) % 4 !== 0) {
            return null;
        }
        $bytes = base64_decode($payload, true);
        if ($bytes === false || base64_encode($bytes) !== $payload || ! $this->validPngChunks($bytes)) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);

        return $info && $info['mime'] === 'image/png' && $info[0] > 0 && $info[1] > 0 && $info[0] <= 4096 && $info[1] <= 4096 ? $bytes : null;
    }

    private function validPngChunks(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $offset = 8;
        $length = strlen($bytes);
        $hasData = false;
        $first = true;
        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            if ($size > $length - $offset - 12) {
                return false;
            }
            $type = substr($bytes, $offset + 4, 4);
            $chunk = substr($bytes, $offset + 4, $size + 4);
            if (($first && ($type !== 'IHDR' || $size !== 13)) || hash('crc32b', $chunk, true) !== substr($bytes, $offset + 8 + $size, 4)) {
                return false;
            }
            $first = false;
            $hasData = $hasData || $type === 'IDAT';
            $offset += $size + 12;
            if ($type === 'IEND') {
                return $size === 0 && $hasData && $offset === $length;
            }
        }

        return false;
    }

    public function inspect(string $original): array
    {
        $payload = preg_replace('#^data:image/(?:png|jpeg);base64,#', '', $original);
        $length = strlen($payload);
        $padding = strlen($payload) - strlen(rtrim($payload, '='));
        $body = $padding ? substr($payload, 0, -$padding) : $payload;
        $valid = $length > 0 && $length % 4 === 0 && $padding <= 2
            && strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') === strlen($body);
        $bytes = $valid && $length <= 2000000 ? base64_decode($payload, true) : false;
        if ($bytes === false && $length <= 2000000) {
            $valid = false;
        }
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        $mime = $info && in_array($info['mime'], ['image/png', 'image/jpeg'], true) ? $info['mime'] : null;

        return [
            'length' => strlen($original),
            'base64_valid' => $valid,
            'mime' => $mime,
            'image_valid' => $mime !== null && $info[0] <= 4096 && $info[1] <= 4096,
            'inspection_limited' => $length > 2000000,
        ];
    }
}
