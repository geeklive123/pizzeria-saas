@extends('layouts.storefront')

@section('title', 'Menú y carta')
@section('page', 'menu')

@section('content')
<section class="menu-hero">
    <div class="shell">
        <div class="menu-intro">
            <div><span class="eyebrow">Masa madre viva · 48 horas de fermentación</span><h1>Nuestra carta artesanal</h1><p>Elige una pizza, selecciona el tamaño disponible y arma tu pedido con precios reales del catálogo.</p></div>
            <label class="search-box"><span aria-hidden="true">⌕</span><span class="sr-only">Buscar en la carta</span><input type="search" data-menu-search placeholder="Buscar por nombre o ingrediente…" autocomplete="off"></label>
        </div>
        <div class="category-tabs" data-category-tabs>
            <button class="active" type="button" data-category="all">Toda la carta</button>
            @foreach($categories as $category)
                <button type="button" data-category="{{ $category->ulid }}">{{ $category->name }}</button>
            @endforeach
        </div>
    </div>
</section>

<section class="menu-content">
    <div class="shell">
        <div class="size-guide">
            <div><span class="eyebrow">Guía de tamaños</span><h2>Elige tu porción favorita</h2></div>
            <div class="size-pills"><span><b>P</b> Personal</span><span><b>M</b> Mediana</span><span><b>F</b> Familiar</span></div>
            <p>Solo se muestran variantes activas y sus precios actuales.</p>
        </div>

        @forelse($categories as $category)
            <section class="catalog-section" data-category-section="{{ $category->ulid }}">
                @php($publicCategoryDescription = \App\Support\StorefrontCatalogPresenter::description($category->description))
                <div class="catalog-heading"><div><span class="eyebrow">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · Carta de la casa</span><h2>{{ $category->name }}</h2></div>@if($publicCategoryDescription)<p>{{ $publicCategoryDescription }}</p>@endif</div>
                <div class="product-grid">
                    @foreach($category->products as $product)
                        @php($defaultVariant = $product->variants->first(fn ($variant) => $variantAvailability->get($variant->ulid)?->status !== \App\Enums\MenuAvailabilityStatus::Unavailable))
                        @php($publicDescription = \App\Support\StorefrontCatalogPresenter::description($product->description))
                        <article class="product-card" data-product-card data-product-name="{{ $product->name }}" data-product-search="{{ $product->name }} {{ $publicDescription }}" data-product-category="{{ $category->ulid }}">
                            <div class="product-card-top"><span class="product-monogram">{{ str($product->name)->substr(0, 1)->upper() }}</span><span class="product-kind">{{ \App\Support\StorefrontCatalogPresenter::kind($product->type) }}</span></div>
                            <h3>{{ $product->name }}</h3>
                            <p>{{ $publicDescription ?: 'Consulta sus detalles y disponibilidad al confirmar tu pedido.' }}</p>
                            <fieldset class="variant-options">
                                <legend class="sr-only">Presentación de {{ $product->name }}</legend>
                                @foreach($product->variants as $variant)
                                    @php($availability = $variantAvailability->get($variant->ulid))
                                    <label>
                                        <input type="radio" name="variant_{{ $product->ulid }}" value="{{ $variant->ulid }}" data-name="{{ $variant->name }}" data-price="{{ $variant->price }}" @checked($defaultVariant?->is($variant)) @disabled($availability?->status === \App\Enums\MenuAvailabilityStatus::Unavailable)>
                                        <span>{{ $variant->name }} <b>{{ $variant->price }} Bs.</b><small class="status-{{ $availability?->status->value ?? 'untracked' }}">{{ $availability?->status->label() ?? 'Por confirmar' }}</small></span>
                                    </label>
                                @endforeach
                            </fieldset>
                            <div class="product-card-foot">
                                <div><small>Precio seleccionado</small><strong><span data-selected-price>{{ $defaultVariant?->price ?? '—' }}</span>@if($defaultVariant) Bs.@endif</strong></div>
                                <button class="add-button compact" type="button" data-add-product @disabled(!$defaultVariant)>Pedir <span>+</span></button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="catalog-empty"><strong>No hay productos activos en esta tienda.</strong><p>Revisa el catálogo administrativo antes de publicar la carta.</p></div>
        @endforelse

        <div class="search-empty" data-search-empty hidden><strong>No encontramos coincidencias.</strong><p>Prueba con otro nombre o ingrediente.</p></div>
    </div>
</section>

<div class="floating-cart" data-floating-cart hidden>
    <button type="button" data-cart-open><span><b class="js-cart-count">0</b> Tu comanda en marcha</span><strong><span class="js-cart-total">0</span> Bs.</strong><em>Revisar pedido →</em></button>
</div>
@endsection
