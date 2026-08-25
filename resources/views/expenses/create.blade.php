@extends('layouts.app')
@section('title', 'Registrar gasto')
@section('heading', 'Registrar gasto')
@section('content')
<div class="page-heading"><div><a class="back-link" href="{{ route('expenses.index') }}">← Volver a gastos</a><h1 class="mt-2">Nuevo gasto operativo</h1><p>El registro se publica inmediatamente y luego solo puede corregirse mediante reversión.</p></div></div>
<div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">¿Compraste ingredientes o productos para stock? Regístralo en <a class="link" href="{{ route('purchases.index') }}">Compras</a>.</div>
@if(!$hasOpenCash)<div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">Debes abrir caja antes de registrar un gasto en efectivo. Los gastos QR, transferencia u otro sí pueden registrarse.</div>@endif
<form class="card p-5 sm:p-7" method="POST" action="{{ route('expenses.store') }}">@csrf
    <div class="form-grid">
        <div><label class="label" for="expense_date">Fecha</label><input class="input" id="expense_date" type="date" name="expense_date" value="{{ old('expense_date',today()->toDateString()) }}" required></div>
        <div><label class="label" for="expense_category_id">Categoría</label><select class="input" id="expense_category_id" name="expense_category_id" required><option value="">Selecciona</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('expense_category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
        <div><label class="label" for="supplier_id">Proveedor (opcional)</label><select class="input" id="supplier_id" name="supplier_id"><option value="">Sin proveedor</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        <div><label class="label" for="amount">Monto</label><input class="input" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
        <div class="sm:col-span-2"><label class="label" for="description">Descripción</label><input class="input" id="description" name="description" maxlength="500" value="{{ old('description') }}" required></div>
        <div><label class="label" for="payment_method">Método de pago</label><select class="input" id="payment_method" name="payment_method" required><option value="cash" @selected(old('payment_method')==='cash')>Efectivo</option><option value="qr" @selected(old('payment_method','qr')==='qr')>QR</option><option value="transfer" @selected(old('payment_method')==='transfer')>Transferencia</option><option value="other" @selected(old('payment_method')==='other')>Otro</option></select></div>
        <div><label class="label" for="document_type">Documento</label><select class="input" id="document_type" name="document_type" required><option value="with_invoice" @selected(old('document_type')==='with_invoice')>Con factura</option><option value="without_invoice" @selected(old('document_type','without_invoice')==='without_invoice')>Sin factura</option><option value="receipt" @selected(old('document_type')==='receipt')>Recibo</option><option value="other" @selected(old('document_type')==='other')>Otro</option></select></div>
        <div><label class="label" for="document_number">Número de documento</label><input class="input" id="document_number" name="document_number" maxlength="190" value="{{ old('document_number') }}"><p class="mt-1 text-xs text-stone-500">Obligatorio cuando seleccionas “Con factura”.</p></div>
        <div class="sm:col-span-2"><label class="label" for="notes">Notas</label><textarea class="input min-h-24" id="notes" name="notes">{{ old('notes') }}</textarea></div>
    </div>
    <div class="mt-6 flex justify-end gap-3"><a class="btn-secondary" href="{{ route('expenses.index') }}">Cancelar</a><button class="btn-primary">Registrar gasto</button></div>
</form>
@endsection
