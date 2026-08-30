<?php

namespace App\Support;

use App\Enums\CashClosingBalanceStatus;
use App\Enums\CashMovementType;
use App\Enums\ExpenseDocumentType;
use App\Enums\ExpensePaymentMethod;
use App\Enums\ExpenseStatus;
use App\Enums\InventoryBatchStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStockStatus;
use App\Enums\MembershipRole;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;

class UiFormatter
{
    public static function money(int|string|null $value): string
    {
        return 'Bs '.self::decimal($value ?? '0', 2);
    }

    public static function quantity(int|string|null $value, ?string $unit = null): string
    {
        $formatted = self::decimal($value ?? '0', 3, true);

        return trim($formatted.' '.$unit);
    }

    public static function inputQuantity(int|string|null $value): string
    {
        return rtrim(rtrim((string) BigDecimal::of($value ?? '0')->toScale(3, RoundingMode::HalfUp), '0'), '.');
    }

    public static function decimal(int|string $value, int $scale, bool $trim = false): string
    {
        $normalized = (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) ?? $whole;

        if ($trim) {
            $fraction = rtrim($fraction, '0');
        }

        return $fraction === '' ? $whole : $whole.','.$fraction;
    }

    public static function date(?DateTimeInterface $date, bool $withTime = false): string
    {
        if (! $date) {
            return '—';
        }

        return $date->setTimezone(new \DateTimeZone('America/La_Paz'))
            ->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    }

    public static function movement(InventoryMovementType $type): string
    {
        return match ($type) {
            InventoryMovementType::Opening => 'Stock inicial',
            InventoryMovementType::Purchase => 'Compra',
            InventoryMovementType::Waste => 'Merma',
            InventoryMovementType::AdjustmentIn => 'Ajuste positivo',
            InventoryMovementType::AdjustmentOut => 'Ajuste negativo',
            InventoryMovementType::ManualIn => 'Entrada manual',
            InventoryMovementType::ManualOut => 'Salida manual',
            InventoryMovementType::OrderConsumption => 'Consumo de pedido',
            InventoryMovementType::Reversal => 'Reversión',
        };
    }

    public static function purchaseStatus(PurchaseStatus $status): string
    {
        return match ($status) {
            PurchaseStatus::Draft => 'Borrador',
            PurchaseStatus::Posted => 'Confirmada',
            PurchaseStatus::Reversed => 'Revertida',
        };
    }

    public static function orderItemStatus(OrderItemStatus $status): string
    {
        return match ($status) {
            OrderItemStatus::Draft => 'Borrador',
            OrderItemStatus::PendingPayment => 'Pendiente de cobro',
            OrderItemStatus::Sent => 'Enviado',
            OrderItemStatus::Preparing => 'Preparando',
            OrderItemStatus::Ready => 'Listo',
            OrderItemStatus::Served => 'Servido',
            OrderItemStatus::Cancelled => 'Cancelado',
        };
    }

    public static function paymentMethod(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Efectivo',
            PaymentMethod::Qr => 'QR',
            PaymentMethod::Card => 'Tarjeta',
            PaymentMethod::Transfer => 'Transferencia',
            PaymentMethod::Other => 'Otro',
        };
    }

    public static function paymentStatus(PaymentStatus $status): string
    {
        return $status === PaymentStatus::Completed ? 'Completado' : 'Revertido';
    }

    public static function cashMovement(CashMovementType $type): string
    {
        return match ($type) {
            CashMovementType::Opening => 'Apertura',
            CashMovementType::SaleCash => 'Venta en efectivo',
            CashMovementType::ManualIn => 'Ingreso manual',
            CashMovementType::ManualOut => 'Egreso manual',
            CashMovementType::ExpenseOut => 'Gasto en efectivo',
            CashMovementType::ExpenseReversal => 'Reversión de gasto',
            CashMovementType::OwnerWithdrawal => 'Retiro del propietario',
            CashMovementType::Reversal => 'Reversión',
        };
    }

    public static function cashClosingBalance(CashClosingBalanceStatus $status): string
    {
        return match ($status) {
            CashClosingBalanceStatus::Balanced => 'CUADRADA',
            CashClosingBalanceStatus::Short => 'FALTANTE',
            CashClosingBalanceStatus::Over => 'SOBRANTE',
        };
    }

    public static function expenseDocument(ExpenseDocumentType $type): string
    {
        return match ($type) {
            ExpenseDocumentType::WithInvoice => 'Con factura',
            ExpenseDocumentType::WithoutInvoice => 'Sin factura',
            ExpenseDocumentType::Receipt => 'Recibo',
            ExpenseDocumentType::Other => 'Otro',
        };
    }

    public static function expensePayment(ExpensePaymentMethod $method): string
    {
        return match ($method) {
            ExpensePaymentMethod::Cash => 'Efectivo',
            ExpensePaymentMethod::Qr => 'QR',
            ExpensePaymentMethod::Transfer => 'Transferencia',
            ExpensePaymentMethod::Other => 'Otro',
        };
    }

    public static function expenseStatus(ExpenseStatus $status): string
    {
        return $status === ExpenseStatus::Posted ? 'Publicado' : 'Revertido';
    }

    public static function role(MembershipRole $role): string
    {
        return $role->label();
    }

    public static function productType(ProductType $type): string
    {
        return match ($type) {
            ProductType::Pizza => 'Pizza',
            ProductType::Beverage => 'Bebida',
            ProductType::Extra => 'Extra',
            ProductType::Combo => 'Combo',
            ProductType::Other => 'Otro',
        };
    }

    public static function stockStatus(InventoryStockStatus $status): string
    {
        return match ($status) {
            InventoryStockStatus::Normal => 'Normal',
            InventoryStockStatus::Low => 'Stock bajo',
            InventoryStockStatus::Out => 'Agotado',
        };
    }

    public static function batchStatus(InventoryBatchStatus $status): string
    {
        return match ($status) {
            InventoryBatchStatus::Expired => 'Vencido',
            InventoryBatchStatus::ExpiringSoon => 'Próximo a vencer',
            InventoryBatchStatus::Ok => 'Vigente',
            InventoryBatchStatus::NoExpiration => 'Sin caducidad',
        };
    }
}
