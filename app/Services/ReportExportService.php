<?php

namespace App\Services;

use App\Data\ReportDateRange;
use App\Models\Branch;
use App\Models\Company;
use Brick\Math\BigDecimal;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public function __construct(
        private readonly SalesReportService $sales,
        private readonly ExpenseReportService $expenses,
        private readonly PurchaseReportService $purchases,
        private readonly InventoryReportService $inventory,
        private readonly CashReportService $cash,
        private readonly CashSessionSummaryService $cashSummary,
    ) {}

    public function download(string $report, Company $company, Branch $branch, ReportDateRange $range, array $filters = []): StreamedResponse
    {
        [$headers, $rows] = match ($report) {
            'sales' => $this->salesRows($company, $branch, $range, $filters),
            'expenses' => $this->expenseRows($company, $branch, $range, $filters),
            'purchases' => $this->purchaseRows($company, $branch, $range),
            'inventory' => $this->inventoryRows($company, $branch, $filters),
            'cash' => $this->cashRows($company, $branch, $range),
        };
        $filename = 'reporte-'.$report.'-'.$range->from->format('Ymd').'-'.$range->to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function salesRows(Company $company, Branch $branch, ReportDateRange $range, array $filters): array
    {
        $orders = $this->sales->paidOrders($company, $branch, $range)
            ->when($filters['category_id'] ?? null, fn ($query, $category) => $query->whereHas('items.productVariant.product', fn ($products) => $products->where('category_id', $category)))
            ->when($filters['product_id'] ?? null, fn ($query, $product) => $query->whereHas('items.productVariant', fn ($variants) => $variants->where('product_id', $product)))
            ->when($filters['variant_id'] ?? null, fn ($query, $variant) => $query->whereHas('items', fn ($items) => $items->where('product_variant_id', $variant)))
            ->with(['payments' => fn ($query) => $query->where('status', 'completed')->when($filters['payment_method'] ?? null, fn ($payments, $method) => $payments->where('method', $method))])
            ->orderBy('closed_at')->get();
        if ($filters['payment_method'] ?? null) {
            $orders = $orders->filter(fn ($order) => $order->payments->isNotEmpty());
        }

        return [['Pedido', 'Fecha', 'Tipo', 'Total', 'Métodos'], $orders->map(fn ($order) => [$order->formattedNumber(), $order->closed_at->setTimezone($range->timezone)->format('Y-m-d H:i'), $order->type->value, $order->total, $order->payments->map(fn ($payment) => $payment->method->value)->unique()->join(' + ')])];
    }

    private function expenseRows(Company $company, Branch $branch, ReportDateRange $range, array $filters): array
    {
        $rows = $this->expenses->query($company, $branch, $range, $filters)->with(['category', 'supplier'])->orderBy('expense_date')->get();

        return [['Fecha', 'Descripción', 'Categoría', 'Proveedor', 'Documento', 'Método', 'Monto'], $rows->map(fn ($expense) => [$expense->expense_date->format('Y-m-d'), $expense->description, $expense->category->name, $expense->supplier?->name, $expense->document_number, $expense->payment_method->value, $expense->amount])];
    }

    private function purchaseRows(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $rows = $this->purchases->query($company, $branch, $range)->with('items')->orderBy('purchased_at')->get();

        return [['Fecha', 'Proveedor', 'Documento', 'Total'], $rows->map(fn ($purchase) => [$purchase->purchased_at->setTimezone($range->timezone)->format('Y-m-d H:i'), $purchase->supplier_name, $purchase->document_number, $purchase->items->reduce(fn ($total, $item) => $total->plus($item->total_cost), BigDecimal::zero())])];
    }

    private function inventoryRows(Company $company, Branch $branch, array $filters): array
    {
        $data = $this->inventory->inventory($company, $branch, $filters['sort'] ?? 'name');

        return [['Artículo', 'Unidad', 'Físico', 'Vencido', 'Reservado', 'Disponible', 'Costo promedio', 'Valor'], $data['items']->map(fn ($item) => [$item->name, $item->unit->symbol, $item->physical_quantity, $item->expired_quantity, $item->reserved_quantity, $item->available_quantity, $item->display_average_cost, $item->display_value])];
    }

    private function cashRows(Company $company, Branch $branch, ReportDateRange $range): array
    {
        $rows = $this->cash->query($company, $branch, $range)
            ->with(['cashRegister', 'openedBy', 'closedBy', 'payments', 'movements'])
            ->orderBy('opened_at')->get();

        return [['Caja', 'Apertura', 'Cierre', 'Abrió', 'Cerró', 'Fondo inicial', 'Referencia heredada', 'Diferencia apertura', 'Ventas efectivo', 'Ventas QR', 'Ventas totales', 'Retiros propietario', 'Otros ingresos', 'Otros egresos', 'Esperado', 'Contado', 'Diferencia'], $rows->map(function ($session) use ($range): array {
            $summary = $this->cashSummary->calculate($session);

            return [$session->cashRegister->name, $session->opened_at->setTimezone($range->timezone)->format('Y-m-d H:i'), $session->closed_at?->setTimezone($range->timezone)->format('Y-m-d H:i'), $session->openedBy->name, $session->closedBy?->name, $session->opening_amount, $session->inherited_cash_amount, $session->opening_difference_amount, $summary['cash_payments'], $summary['qr_payments'], $summary['sales_total'], $summary['owner_withdrawals'], $summary['manual_in'], $summary['manual_out'], $session->status->value === 'closed' ? $session->expected_cash_amount : $summary['expected_cash'], $session->counted_cash_amount, $session->difference_amount];
        })];
    }
}
