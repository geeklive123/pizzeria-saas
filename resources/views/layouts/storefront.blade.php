<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Carta artesanal y pedidos de {{ $company->name }}.">
    <title>@yield('title', 'Tienda') · {{ $company->name }}</title>
    @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
</head>
<body data-storefront-page="@yield('page', 'default')">
@php($storePhone = $branch->phone ?: $company->phone)
<a class="skip-link" href="#contenido">Saltar al contenido</a>

@if($demoMode ?? config('storefront.demo_mode', true))
    <div class="demo-banner" role="status">Modo demostración: puedes recorrer el pedido completo, pero no se enviará nada al restaurante.</div>
@endif

<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="{{ route('storefront.home') }}" aria-label="Ir al inicio de {{ $company->name }}">
            <span class="brand-mark" aria-hidden="true"><span>MM</span></span>
            <span class="brand-copy"><strong>{{ $company->name }}</strong><small>Pizza de masa madre</small></span>
        </a>

        <button class="nav-toggle" type="button" data-nav-toggle aria-controls="site-nav" aria-expanded="false">
            <span></span><span></span><span></span><span class="sr-only">Abrir navegación</span>
        </button>

        <nav class="site-nav" id="site-nav" data-site-nav aria-label="Navegación de la tienda">
            <a @class(['active' => request()->routeIs('storefront.home')]) href="{{ route('storefront.home') }}">Inicio</a>
            <a @class(['active' => request()->routeIs('storefront.menu')]) href="{{ route('storefront.menu') }}">Menú</a>
            <a @class(['active' => request()->routeIs('storefront.promotions')]) href="{{ route('storefront.promotions') }}">Promociones</a>
            <a @class(['active' => request()->routeIs('storefront.location')]) href="{{ route('storefront.location') }}">Horarios y ubicación</a>
        </nav>

        <button class="order-button" type="button" data-cart-open>
            <span aria-hidden="true">⌑</span> Pedido online
            <span class="cart-count js-cart-count" aria-label="Productos en el carrito">0</span>
        </button>
    </div>
</header>

<main id="contenido">
    @yield('content')
</main>

<footer class="site-footer" id="ubicacion">
    <div class="shell footer-grid">
        <section>
            <div class="footer-brand">{{ $company->name }}</div>
            <p>Pizza artesanal de masa madre, preparada con tiempo, oficio y fuego vivo.</p>
        </section>
        <section>
            <h2>Visítanos</h2>
            <p>{{ $branch->name }}</p>
            @if($branch->address)<p>{{ $branch->address }}</p>@endif
        </section>
        <section>
            <h2>Pedidos y contacto</h2>
            @if($storePhone)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $storePhone) }}">{{ $storePhone }}</a>
            @else
                <p>Contacto pendiente de configuración.</p>
            @endif
            <a href="{{ route('storefront.menu') }}">Explorar la carta</a>
        </section>
    </div>
    <div class="shell footer-bottom">
        <span>© {{ now()->year }} {{ $company->name }}.</span>
        <span>Hecho con masa madre y oficio artesanal.</span>
    </div>
</footer>

<div class="cart-backdrop" data-cart-close hidden></div>
<aside class="cart-drawer" data-cart-drawer aria-hidden="true" aria-labelledby="cart-title">
    <div class="cart-drawer-head">
        <div><span class="eyebrow">Tu pedido</span><h2 id="cart-title">Mi comanda</h2></div>
        <button class="icon-button" type="button" data-cart-close aria-label="Cerrar carrito">×</button>
    </div>
    <div class="cart-lines js-cart-lines"></div>
    <div class="cart-empty js-cart-empty">
        <span aria-hidden="true">🍕</span>
        <strong>Tu comanda está vacía</strong>
        <p>Elige un tamaño y agrega tus favoritas.</p>
    </div>
    <div class="cart-drawer-foot">
        <div class="cart-total"><span>Subtotal de productos</span><strong><span class="js-cart-total">0</span> Bs.</strong></div>
        <p>@if($demoMode ?? config('storefront.demo_mode', true))Esta demostración no envía pedidos reales.@else La disponibilidad y cualquier costo de delivery se confirman por WhatsApp.@endif</p>
        <a class="button button-primary button-full js-checkout-link" href="{{ route('storefront.checkout') }}">Finalizar pedido <span>→</span></a>
        <button class="text-button" type="button" data-cart-close>Seguir viendo la carta</button>
    </div>
</aside>

<div class="toast" data-toast role="status" aria-live="polite"></div>
</body>
</html>
