<?php

namespace App\Services;

use App\Actions\BuildStorefrontWhatsAppOrderAction;
use App\Enums\CashSessionStatus;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\OnlineOrderRequest;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlinePaymentPreparationService
{
    public function __construct(private readonly BuildStorefrontWhatsAppOrderAction $catalog, private readonly DeliveryCoverageService $coverage, private readonly OnlineOrderReservationVerifier $reservations) {}

    public function assertUsable(Company $company, Branch $branch, OnlineOrderRequest $preparation): void
    {
        $this->withUsable($company, $branch, $preparation, fn () => null);
    }

    /** Execute local work while the original cash session, preparation and variants remain locked. */
    public function withUsable(Company $company, Branch $branch, OnlineOrderRequest $preparation, callable $operation): mixed
    {
        $validated = false;
        try {
            return DB::transaction(function () use ($company, $branch, $preparation, $operation, &$validated): mixed {
                $validated = false;
                if ($preparation->status !== 'payment_preparation' || ! $preparation->preparation_expires_at || $preparation->preparation_expires_at->isPast()) {
                    throw ValidationException::withMessages(['preparation' => 'La preparación ya no está disponible. Inicia una nueva.']);
                }
                $session = CashSession::query()->forCompany($company)->forBranch($branch)
                    ->whereKey($preparation->preparation_cash_session_id)->where('status', CashSessionStatus::Open->value)
                    ->whereNotNull('active_cash_register_id')->lockForUpdate()->first();
                if (! $session) {
                    throw ValidationException::withMessages(['cash_session' => 'Estamos fuera de horario. Por el momento no podemos recibir pedidos online. Vuelve a intentarlo cuando el restaurante esté atendiendo.']);
                }
                $preparation = OnlineOrderRequest::query()->forCompany($company)->where('branch_id', $branch->id)
                    ->whereKey($preparation->id)->lockForUpdate()->firstOrFail();
                if ($preparation->status !== 'payment_preparation' || ! $preparation->preparation_expires_at || $preparation->preparation_expires_at->isPast()) {
                    throw ValidationException::withMessages(['preparation' => 'La preparación ya no está disponible. Inicia una nueva.']);
                }
                if ((int) $preparation->preparation_cash_session_id !== (int) $session->id) {
                    throw ValidationException::withMessages(['cash_session' => 'La caja de esta preparación cambió. Inicia una nueva.']);
                }
                try {
                    $coverage = $this->coverage->calculate($branch, $preparation->delivery_latitude, $preparation->delivery_longitude);
                } catch (\DomainException) {
                    throw ValidationException::withMessages(['location_confirmed' => 'No se pudo validar la cobertura. Inicia una nueva preparación.']);
                }
                if ($coverage['coverage'] !== 'free') {
                    throw ValidationException::withMessages(['location_confirmed' => 'La ubicación está fuera de cobertura.']);
                }
                $lines = $preparation->items()->get();
                ProductVariant::query()->forCompany($company)->whereIn('id', $lines->pluck('product_variant_id'))->orderBy('id')->lockForUpdate()->get();
                $variantsById = ProductVariant::query()->forCompany($company)->whereIn('id', $lines->pluck('product_variant_id'))->get()->keyBy('id');
                if ($lines->isEmpty() || $variantsById->count() !== $lines->count()) {
                    throw ValidationException::withMessages(['items' => 'Los productos cambiaron. Inicia una nueva preparación.']);
                }
                $items = $lines->map(fn ($line) => ['variant_ulid' => $variantsById[$line->product_variant_id]->ulid, 'quantity' => $line->quantity]);
                if ($preparation->pos_order_id) {
                    $variants = $this->catalog->visibleVariants($company, $items);
                    $this->reservations->verify($preparation, $session);
                } else {
                    $variants = $this->catalog->validatedVariants($company, $branch, $items);
                }
                $total = BigDecimal::zero();
                foreach ($lines as $line) {
                    $variant = $variants->get($variantsById[$line->product_variant_id]->ulid);
                    if (! BigDecimal::of($variant->price)->isEqualTo($line->unit_price)) {
                        throw ValidationException::withMessages(['items' => 'Los precios cambiaron. Inicia una nueva preparación.']);
                    }
                    $lineTotal = BigDecimal::of($line->unit_price)->multipliedBy($line->quantity);
                    if ($line->quantity < 1 || ! $lineTotal->isEqualTo($line->subtotal)) {
                        throw ValidationException::withMessages(['preparation' => 'El importe de la preparación no es válido.']);
                    }
                    $total = $total->plus($lineTotal);
                }
                if (! $total->isEqualTo($preparation->total) || ! $total->isEqualTo($preparation->subtotal)) {
                    throw ValidationException::withMessages(['preparation' => 'El importe de la preparación no es válido.']);
                }
                $validated = true;

                return $operation($preparation, $session);
            }, 3);
        } catch (ValidationException $exception) {
            if (! $validated) {
                OnlineOrderRequest::query()->forCompany($company)->where('branch_id', $branch->id)->whereKey($preparation->id)
                    ->where('status', 'payment_preparation')->update(['status' => 'preparation_invalidated']);
            }
            throw $exception;
        }
    }
}
