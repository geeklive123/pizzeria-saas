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
    @if($order->notes)<p class="mt-3">Observaciones: {{ $order->notes }}</p>@endif
    <ul class="my-4 space-y-2">@foreach($order->items as $item)<li>{{ $item->quantity }} × {{ $item->product_name }} ({{ $item->variant_name }}) · Bs {{ $item->unit_price }} · Subtotal Bs {{ $item->subtotal }}</li>@endforeach</ul>
    <p class="font-bold">Total: Bs {{ $order->total }}</p>
    <div class="mt-5 flex flex-wrap gap-3">
        <a class="btn-secondary" href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer">Contactar por WhatsApp</a>
        @if($order->status === 'pending_confirmation')
            @can('update', $order)
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}">@csrf<input type="hidden" name="status" value="confirmed"><button class="btn-primary">Confirmar solicitud</button></form>
            @endcan
            @can('cancel', $order)
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}" onsubmit="return confirm('¿Cancelar esta solicitud?')">@csrf<input type="hidden" name="status" value="cancelled"><button class="btn-secondary">Cancelar solicitud</button></form>
            @endcan
        @endif
    </div>
</div>
@endsection
