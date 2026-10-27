@extends('layouts.storefront')

@section('title', 'Promociones')
@section('page', 'promotions')

@section('content')
<section class="promotions-hero">
    <div class="shell promotions-hero-inner">
        <div>
            <span class="eyebrow">Ofertas activas de la casa</span>
            <h1>Promociones <em>al horno</em></h1>
            <p>Combos y propuestas especiales publicados por {{ $company->name }}, con sus precios y vigencia reales.</p>
        </div>
        <a class="button button-ghost" href="{{ route('storefront.menu') }}">Explorar toda la carta →</a>
    </div>
</section>

<section class="promotions-catalog section">
    <div class="shell">
        @if($promotions->isEmpty())
            <div class="promotions-empty">
                <span aria-hidden="true">✦</span>
                <strong>No hay promociones vigentes en este momento.</strong>
                <p>Puedes seguir armando tu pedido con los productos disponibles en nuestra carta.</p>
                <a class="button button-primary" href="{{ route('storefront.menu') }}">Ver el menú</a>
            </div>
        @else
            <div class="promotion-grid promotion-grid-all">
                @foreach($promotions as $promotion)
                    @include('storefront.partials.promotion-card', ['promotion' => $promotion, 'index' => $loop->iteration])
                @endforeach
            </div>
            <p class="promotion-disclaimer">La disponibilidad se confirma con el restaurante al enviar el pedido. No se registra ninguna venta ni pago desde esta página.</p>
        @endif
    </div>
</section>

<div class="floating-cart" data-floating-cart hidden>
    <button type="button" data-cart-open><span><b class="js-cart-count">0</b> Tu comanda en marcha</span><strong><span class="js-cart-total">0</span> Bs.</strong><em>Revisar pedido →</em></button>
</div>
@endsection
