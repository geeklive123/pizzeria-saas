@extends('layouts.app')
@section('title', 'Gastos')
@section('heading', 'Gastos')
@section('content')
<div class="page-heading"><div><h1>Gastos operativos</h1><p>Costos que no ingresan existencias al inventario.</p></div>@can('create', \App\Models\Expense::class)<a class="btn-primary" href="{{ route('expenses.create') }}">Registrar gasto</a>@endcan</div>
<div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>¿Compraste ingredientes o productos para stock?</strong> Regístralo en <a class="link" href="{{ route('purchases.index') }}">Compras</a>. Gastos es únicamente para costos operativos que no ingresan stock.</div>
<form class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-6" method="GET">
    <input class="input" type="date" name="date_from" value="{{ request('date_from') }}" aria-label="Desde">
    <input class="input" type="date" name="date_to" value="{{ request('date_to') }}" aria-label="Hasta">
    <select class="input" name="category"><option value="">Todas las categorías</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category')==$category->id)>{{ $category->name }}</option>@endforeach</select>
    <select class="input" name="method"><option value="">Todos los métodos</option><option value="cash" @selected(request('method')==='cash')>Efectivo</option><option value="qr" @selected(request('method')==='qr')>QR</option><option value="transfer" @selected(request('method')==='transfer')>Transferencia</option><option value="other" @selected(request('method')==='other')>Otro</option></select>
    <select class="input" name="status"><option value="">Todos los estados</option><option value="posted" @selected(request('status')==='posted')>Publicado</option><option value="reversed" @selected(request('status')==='reversed')>Revertido</option></select>
    <div class="flex gap-2"><button class="btn-secondary flex-1">Filtrar</button><a class="btn-secondary" href="{{ route('expenses.index',['period'=>'today']) }}">Hoy</a></div>
</form>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Descripción</th><th>Categoría</th><th>Proveedor</th><th>Documento</th><th>Método</th><th>Estado</th><th>Monto</th><th></th></tr></thead><tbody>
@forelse($expenses as $expense)<tr>
    <td>{{ \App\Support\UiFormatter::date($expense->expense_date) }}</td><td class="font-medium">{{ $expense->description }}@if($expense->reversalOf)<div class="text-xs text-stone-500">Reversión de {{ $expense->reversalOf->ulid }}</div>@endif</td><td>{{ $expense->category->name }}</td><td>{{ $expense->supplier?->name ?: '—' }}</td><td>{{ \App\Support\UiFormatter::expenseDocument($expense->document_type) }}<div class="text-xs text-stone-500">{{ $expense->document_number ?: 'Sin número' }}</div></td><td>{{ \App\Support\UiFormatter::expensePayment($expense->payment_method) }}</td><td><span class="badge {{ $expense->status===\App\Enums\ExpenseStatus::Reversed ? 'badge-danger' : 'badge-success' }}">{{ \App\Support\UiFormatter::expenseStatus($expense->status) }}</span></td><td class="font-semibold">{{ \App\Support\UiFormatter::money($expense->amount) }}</td>
    <td>
        @if($expense->status===\App\Enums\ExpenseStatus::Posted && !$expense->reversal_of_id)
            @can('reverse',$expense)
                <form class="flex gap-2" method="POST" action="{{ route('expenses.reverse',$expense->ulid) }}" onsubmit="return confirm('¿Revertir este gasto?')">
                    @csrf
                    <input class="input min-w-40" name="reason" placeholder="Motivo" required>
                    <button class="btn-danger">Revertir</button>
                </form>
            @endcan
        @endif
    </td>
</tr>@empty<tr><td colspan="9"><div class="empty-state">No hay gastos con estos filtros.</div></td></tr>@endforelse
</tbody></table></div>@if($expenses->hasPages())<div class="border-t p-4">{{ $expenses->links() }}</div>@endif</div>
@endsection
