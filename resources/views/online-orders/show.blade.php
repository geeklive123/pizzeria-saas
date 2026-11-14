@extends('layouts.app')
@section('title', 'Solicitud online')
@section('heading', 'Solicitud online')
@section('content')
<div class="mx-auto max-w-3xl rounded-2xl border border-stone-200 bg-white p-6">
    <a class="link" href="{{ route('online-orders.index') }}">Volver a pedidos online</a>
    <h1 class="mt-4 text-2xl font-bold">{{ $order->publicCode() }}</h1>
    @if(session('online_order_notice'))<p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">{{ session('online_order_notice') }}</p>@endif
    <p class="mt-2">{{ $order->customer_name }} · {{ $order->customer_phone }}</p>
    <p>Recojo en local · {{ \App\Support\UiFormatter::date($order->created_at, true) }} · {{ ['pending_confirmation' => 'Pendiente', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado'][$order->status] ?? $order->status }}</p>
    @if($order->resolved_at)<p>Resuelta por {{ $order->resolvedBy?->name ?? 'Usuario no disponible' }} · {{ \App\Support\UiFormatter::date($order->resolved_at, true) }}</p>@endif
    @if($order->items->contains(fn ($item) => $item->was_promotion === null))
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Esta solicitud antigua no conserva toda la información necesaria para comprobar sus productos o promociones. No se puede convertir al POS. Revisa el detalle y contacta al cliente antes de resolverla.
        </div>
    @endif
    @if($order->legacy_reviewed_at)
        <p class="mt-3">Revisión registrada por {{ $order->legacyReviewedBy?->name ?? 'Usuario no disponible' }} · {{ \App\Support\UiFormatter::date($order->legacy_reviewed_at, true) }}</p>
        <p>Resultado: cancelada tras contactar al cliente.</p>
        <p>Nota de revisión: {{ $order->legacy_review_note }}</p>
    @endif
    @if($order->notes)<p class="mt-3">Observaciones: {{ $order->notes }}</p>@endif
    <ul class="my-4 space-y-2">@foreach($order->items as $item)<li>{{ $item->quantity }} × {{ $item->product_name }} ({{ $item->variant_name }}) · Bs {{ $item->unit_price }} · Subtotal Bs {{ $item->subtotal }}</li>@endforeach</ul>
    <p class="font-bold">Total: Bs {{ $order->total }}</p>
    @if($order->posOrder)
        <p class="mt-3">Orden POS #{{ $order->posOrder->order_number }} · Pago {{ $order->posOrder->status === \App\Enums\OrderStatus::Paid ? 'registrado' : 'pendiente o por verificar en POS' }}</p>
        <a class="link" href="{{ route('orders.show', $order->posOrder->ulid) }}">Abrir orden POS</a>
    @endif
    <div class="mt-5 flex flex-wrap gap-3">
        <a class="btn-secondary" href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer">Contactar por WhatsApp</a>
        @if($order->status === 'pending_confirmation' && ! $order->items->contains(fn ($item) => $item->was_promotion === null))
            @can('update', $order)
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}">@csrf<input type="hidden" name="status" value="confirmed"><button class="btn-primary">Confirmar pedido</button></form>
            @endcan
            @can('cancel', $order)
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}" onsubmit="return confirm('¿Cancelar esta solicitud?')">@csrf<input type="hidden" name="status" value="cancelled"><button class="btn-secondary">Cancelar solicitud</button></form>
            @endcan
        @endif
    </div>
    @if($order->status === 'pending_confirmation' && $order->items->contains(fn ($item) => $item->was_promotion === null))
        @can('cancel', $order)
            <form class="mt-5 space-y-3" method="POST" action="{{ route('online-orders.legacy-review', $order->id) }}" onsubmit="return confirm('¿Registrar la revisión y cancelar esta solicitud?')">
                @csrf
                <p>Si el cliente desea continuar, deberá enviar una nueva solicitud con el detalle y los precios vigentes. Esta acción conserva el pedido original y no crea una venta.</p>
                <label for="legacy-note">Resultado de la conversación (sin datos sensibles)</label>
                <textarea id="legacy-note" name="note" class="input w-full" required minlength="3" maxlength="500">{{ old('note') }}</textarea>
                @error('note')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <label class="flex gap-2"><input type="checkbox" name="contacted" value="1" required> Confirmo que contacté al cliente y le informé que esta solicitud se cancelará.</label>
                @error('contacted')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-secondary" type="submit">Registrar revisión y cancelar solicitud</button>
            </form>
        @endcan
    @endif
</div>
@endsection
