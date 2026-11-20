<?php

namespace App\Services;

use App\Enums\DynamicQrStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BankQrDiagnostic;
use App\Models\Company;
use App\Models\DynamicQrIntent;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

class BankQrHistory
{
    public function report(Company $company, array $filters, ?array $snapshot, int $page): array
    {
        $from = CarbonImmutable::parse($filters['from'], 'America/La_Paz')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to'], 'America/La_Paz')->endOfDay()->utc();
        $payments = collect($snapshot['payments'] ?? [])->groupBy('qrId');
        $bankIds = $payments->keys()->all();
        $intents = DynamicQrIntent::query()->forCompany($company)->select(['id', 'ulid', 'company_id', 'branch_id', 'order_id', 'kitchen_dispatch_id', 'payment_id', 'bank_qr_setting_id', 'status', 'bank_status', 'bank_qr_id', 'commercial_reference', 'amount', 'currency', 'reconciliation_data', 'confirmed_at', 'last_checked_at', 'created_at', 'issue_code', 'expires_at'])
            ->selectRaw('CASE WHEN qr_image IS NULL THEN 0 ELSE 1 END AS has_image')->with(['order', 'kitchenDispatch', 'payment'])
            ->where(fn ($query) => $query->whereBetween(\DB::raw('COALESCE(confirmed_at, created_at)'), [$from, $to])->orWhereIn('bank_qr_id', $bankIds))->get();
        $diagnostics = BankQrDiagnostic::query()->forCompany($company)->select(['id', 'ulid', 'company_id', 'bank_qr_setting_id', 'bank_qr_id', 'commercial_reference', 'amount', 'status', 'created_at'])
            ->selectRaw('CASE WHEN original_qr_image IS NULL THEN 0 ELSE 1 END AS has_image')->with('setting:id,company_id,branch_id')
            ->where(fn ($query) => $query->whereBetween('created_at', [$from, $to])->orWhereIn('bank_qr_id', $bankIds))->get();
        $allIds = $intents->pluck('bank_qr_id')->merge($diagnostics->pluck('bank_qr_id'))->filter()->all();
        $idCounts = array_count_values($allIds);
        $rows = collect();
        foreach ($intents as $intent) {
            $payment = $intent->payment;
            $bank = $payments->get($intent->bank_qr_id, collect());
            $matchesAccount = $snapshot && $snapshot['setting_id'] === $intent->bank_qr_setting_id;
            [$localPaid, $state, $category] = $this->classifyIntent($intent, $bank, $snapshot, $idCounts);
            $rows->push(['kind' => 'Venta', 'intent' => $intent, 'diagnostic' => null, 'payment' => $payment, 'local_paid' => (bool) $localPaid,
                'category' => $category,
                'date' => $payment?->paid_at ?? $intent->confirmed_at ?? $intent->created_at, 'branch_id' => $intent->branch_id,
                'reference' => $intent->commercial_reference, 'qr_id' => $intent->bank_qr_id, 'amount' => $intent->amount,
                'bank_status' => $matchesAccount && $bank->count() === 1 ? 'Pagado (lista bancaria)' : $intent->bank_status, 'state' => $state, 'bank' => $matchesAccount && $bank->count() === 1 ? $bank->first() : null]);
        }
        foreach ($diagnostics as $diagnostic) {
            $bank = $payments->get($diagnostic->bank_qr_id, collect());
            $matchesAccount = $snapshot && $snapshot['setting_id'] === $diagnostic->bank_qr_setting_id;
            [$state, $category] = $this->classifyDiagnostic($diagnostic, $bank, $snapshot, $idCounts);
            $rows->push(['kind' => 'Diagnóstico', 'intent' => null, 'diagnostic' => $diagnostic, 'payment' => null, 'local_paid' => false,
                'category' => $category,
                'date' => $diagnostic->created_at, 'branch_id' => $diagnostic->setting?->branch_id,
                'reference' => $diagnostic->commercial_reference, 'qr_id' => $diagnostic->bank_qr_id, 'amount' => $diagnostic->amount,
                'bank_status' => $matchesAccount && $bank->count() === 1 ? 'Pagado (lista bancaria)' : $diagnostic->status, 'state' => $state, 'bank' => $matchesAccount && $bank->count() === 1 ? $bank->first() : null]);
        }
        if ($snapshot) {
            // Search outside the date range as well: an older QR can be paid on the selected day.
            $ids = $payments->keys()->all();
            $knownSales = DynamicQrIntent::query()->forCompany($company)->whereIn('bank_qr_id', $ids)->pluck('bank_qr_id')->all();
            $knownTests = BankQrDiagnostic::query()->forCompany($company)->whereIn('bank_qr_id', $ids)->pluck('bank_qr_id')->all();
            foreach ($payments as $id => $entries) {
                if (in_array($id, $allIds, true) || in_array($id, $knownSales, true) || in_array($id, $knownTests, true)) {
                    continue;
                }
                $entry = $entries->first();
                $rows->push(['kind' => 'Banco', 'intent' => null, 'diagnostic' => null, 'payment' => null, 'local_paid' => false,
                    'category' => 'review',
                    'date' => CarbonImmutable::parse(substr($entry['paymentDate'], 0, 10).' '.$entry['paymentTime'], 'America/La_Paz')->utc(),
                    'branch_id' => null, 'reference' => null, 'qr_id' => $id, 'amount' => $entry['amount'],
                    'bank_status' => 'pagado', 'state' => $entries->count() > 1 ? 'review' : 'bank_only', 'bank' => $entry]);
            }
        }
        $rows = $rows->filter(function ($row) use ($filters) {
            $intent = $row['intent'];

            return (! $filters['branch'] || (string) $row['branch_id'] === $filters['branch'])
                && (! $filters['order'] || ($intent && (string) $intent->order?->operational_number === (string) (int) $filters['order']))
                && (! $filters['reference'] || str_contains((string) $row['reference'], $filters['reference']))
                && (! $filters['qr'] || str_contains((string) $row['qr_id'], $filters['qr']))
                && (! $filters['state'] || $row['state'] === $filters['state']);
        })->sortByDesc(fn ($row) => $row['date']?->timestamp ?? 0)->values();
        $reviewCount = $rows->where('category', 'review')->count();
        $rows = $rows->where('category', $filters['category'] ?? 'confirmed')->values();
        $sum = BigDecimal::zero();
        foreach ($rows->where('local_paid', true) as $row) {
            $sum = $sum->plus($row['amount']);
        }
        $summary = ['confirmed' => $rows->where('local_paid', true)->count(), 'total' => (string) $sum->toScale(2),
            'matched' => $rows->where('state', 'matched')->count(), 'bank_only' => $rows->where('state', 'bank_only')->count(),
            'review' => $reviewCount];
        $paginator = new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page, ['path' => request()->url(), 'query' => request()->query()]);

        return compact('paginator', 'summary');
    }

    /** The same classification is used by the list, the global alert and deposit validation. */
    public function classifyIntent(DynamicQrIntent $intent, $bank, ?array $snapshot, array $idCounts): array
    {
        $payment = $intent->payment;
        $evidence = is_array($intent->reconciliation_data) ? $intent->reconciliation_data : [];
        $amount = $evidence['amount'] ?? null;
        $valid = is_string($intent->bank_qr_id) && $intent->bank_qr_id !== ''
            && ($evidence['qrId'] ?? null) === $intent->bank_qr_id && ($evidence['currency'] ?? null) === $intent->currency
            && is_string($amount) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)
            && BigDecimal::of($amount)->isEqualTo($intent->amount);
        $paid = $intent->status === DynamicQrStatus::Paid && $intent->bank_status === '1' && $intent->currency === 'BOB'
            && $valid && $payment && $payment->status === PaymentStatus::Completed && $payment->method === PaymentMethod::Qr
            && $payment->company_id === $intent->company_id && $payment->branch_id === $intent->branch_id
            && $payment->order_id === $intent->order_id && $payment->kitchen_dispatch_id === $intent->kitchen_dispatch_id
            && BigDecimal::of($payment->amount)->isEqualTo($intent->amount);
        $account = $snapshot && $snapshot['setting_id'] === $intent->bank_qr_setting_id;
        $covered = $account && $payment?->paid_at?->timezone('America/La_Paz')->toDateString() === $snapshot['date'];
        $transaction = $evidence['transactionId'] ?? null;
        $invalidTransaction = $transaction !== null && (! is_string($transaction) || trim($transaction) === '');
        $state = $invalidTransaction ? 'review' : $this->reconcile($intent->amount, $intent->currency, $transaction, $bank, (bool) $account, (bool) $paid, (bool) $covered);
        if ($bank->isNotEmpty() && ($idCounts[$intent->bank_qr_id] ?? 0) > 1) {
            $state = 'review';
        }
        $review = $intent->issue_code !== null || in_array($intent->status, [DynamicQrStatus::Failed, DynamicQrStatus::GenerationUnknown, DynamicQrStatus::ReconciliationRequired], true)
            || ($intent->status === DynamicQrStatus::Paid && ! $paid) || ($intent->bank_status === '1' && ! $paid)
            || in_array($state, ['review', 'mismatch', 'local_only', 'bank_only'], true);

        return [(bool) $paid, $state, $review ? 'review' : ($paid ? 'confirmed' : 'history')];
    }

    private function classifyDiagnostic(BankQrDiagnostic $diagnostic, $bank, ?array $snapshot, array $idCounts): array
    {
        $account = $snapshot && $snapshot['setting_id'] === $diagnostic->bank_qr_setting_id;
        $state = $account && $bank->count() > 1 ? 'review' : ($account && $bank->count() === 1
            ? (BigDecimal::of($bank->first()['amount'])->isEqualTo($diagnostic->amount) && $bank->first()['currency'] === 'BOB' ? 'diagnostic_paid' : 'mismatch') : 'unverified');
        if ($bank->isNotEmpty() && ($idCounts[$diagnostic->bank_qr_id] ?? 0) > 1) {
            $state = 'review';
        }
        $review = in_array($state, ['review', 'mismatch', 'diagnostic_paid'], true)
            || in_array($diagnostic->status, ['generation_unknown', 'reconciliation_required', 'failed', 'paid'], true);

        return [$state, $review ? 'review' : 'history'];
    }

    public function globalReviewCount(Company $company, ?string $branch, ?array $snapshot): int
    {
        $payments = collect($snapshot['payments'] ?? [])->groupBy('qrId');
        $ids = $payments->keys()->all();
        $known = DynamicQrIntent::query()->forCompany($company)->whereIn('bank_qr_id', $ids)->pluck('bank_qr_id')
            ->merge(BankQrDiagnostic::query()->forCompany($company)->whereIn('bank_qr_id', $ids)->pluck('bank_qr_id'))->filter()->all();
        $idCounts = array_count_values($known);
        $count = 0;
        DynamicQrIntent::query()->forCompany($company)->select(['id', 'company_id', 'branch_id', 'order_id', 'kitchen_dispatch_id', 'payment_id', 'bank_qr_setting_id', 'status', 'bank_status', 'bank_qr_id', 'amount', 'currency', 'reconciliation_data', 'issue_code'])
            ->when($branch, fn ($query) => $query->where('branch_id', $branch))
            ->with('payment')->chunkById(500, function ($intents) use (&$count, $payments, $snapshot, $idCounts): void {
                foreach ($intents as $intent) {
                    [, , $category] = $this->classifyIntent($intent, $payments->get($intent->bank_qr_id, collect()), $snapshot, $idCounts);
                    $count += $category === 'review' ? 1 : 0;
                }
            });
        BankQrDiagnostic::query()->forCompany($company)->select(['id', 'bank_qr_setting_id', 'bank_qr_id', 'amount', 'status'])
            ->when($branch, fn ($query) => $query->whereHas('setting', fn ($settings) => $settings->where('branch_id', $branch)))
            ->chunkById(500, function ($diagnostics) use (&$count, $payments, $snapshot, $idCounts): void {
                foreach ($diagnostics as $diagnostic) {
                    [, $category] = $this->classifyDiagnostic($diagnostic, $payments->get($diagnostic->bank_qr_id, collect()), $snapshot, $idCounts);
                    $count += $category === 'review' ? 1 : 0;
                }
            });
        if (! $branch) {
            $count += $payments->keys()->filter(fn ($id) => ! in_array($id, $known, true))->count();
        }

        return $count;
    }

    private function reconcile(string $amount, string $currency, ?string $transaction, $entries, bool $account, bool $paid, bool $covered): string
    {
        if ($entries->isNotEmpty() && ! $account) {
            return 'review';
        }
        if ($entries->count() > 1) {
            return 'review';
        }
        if ($entries->count() === 1) {
            $bank = $entries->first();
            if (! BigDecimal::of($bank['amount'])->isEqualTo($amount) || $bank['currency'] !== $currency || ($transaction && $transaction !== $bank['transactionId'])) {
                return 'mismatch';
            }

            return $paid ? 'matched' : 'bank_only';
        }

        return $paid && $covered ? 'local_only' : 'unverified';
    }
}
