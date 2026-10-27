@extends('layouts.storefront')

@section('title', 'Pizzería artesanal')
@section('page', 'home')

@section('content')
<section class="hero">
    <div class="shell hero-grid">
        <div class="hero-copy">
            <span class="eyebrow">Pizzería artesanal desde el origen</span>
            <h1>Masa con <em>alma</em>,<br>maña en el <span>fuego.</span></h1>
            <p>Respetamos el tiempo que la prisa arruina. Nuestra masa madre y el fuego del horno dan forma a pizzas ligeras, honestas y hechas para compartir.</p>
            <div class="hero-actions">
                <a class="button button-accent" href="{{ route('storefront.menu') }}">Explorar la carta</a>
                <button class="button button-ghost" type="button" data-cart-open>Ver mi pedido</button>
            </div>
            <div class="hero-facts" aria-label="Características de la masa">
                <div><strong>48h</strong><span>Fermentación lenta</span></div>
                <div><strong>450°C</strong><span>Fuego intenso</span></div>
                <div><strong>100%</strong><span>Proceso artesanal</span></div>
            </div>
        </div>
        <div class="hero-art" aria-label="Pizza artesanal recién horneada">
            <div class="fire-glow"></div>
            <div class="pizza-stage">
                <div class="pizza-visual"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            </div>
            <div class="hero-note"><i></i><div><strong>Masa madre viva</strong><small>Tiempo, temperatura y manos expertas</small></div></div>
        </div>
    </div>
</section>

<section class="section pillars" id="experiencia">
    <div class="shell">
        <div class="section-heading centered">
            <span class="eyebrow">Nuestros tres pilares</span>
            <h2>El secreto de <em>Masa & Maña</em></h2>
            <p>No creemos en atajos. El sabor de una pizza auténtica nace del tiempo, los ingredientes y el dominio del fuego.</p>
        </div>
        <div class="pillar-grid">
            <article><span class="pillar-number">01</span><i>◷</i><h3>La masa</h3><p>Fermentación lenta para desarrollar sabor, ligereza y una textura con carácter.</p></article>
            <article class="featured"><span class="pillar-number">02</span><i>♨</i><h3>El horno</h3><p>Fuego vivo y temperatura intensa para lograr bordes aireados y un horneado preciso.</p></article>
            <article><span class="pillar-number">03</span><i>✦</i><h3>Los ingredientes</h3><p>Combinaciones cuidadas que respetan el producto y dejan hablar a cada sabor.</p></article>
        </div>
    </div>
</section>

<section class="section featured-menu">
    <div class="shell">
        <div class="section-heading split">
            <div><span class="eyebrow">Menú artesanal</span><h2>Nuestras pizzas con <em>maña</em></h2></div>
            <a class="arrow-link" href="{{ route('storefront.menu') }}">Ver carta completa →</a>
        </div>

        @if($featuredProducts->isEmpty())
            <div class="catalog-empty"><strong>La carta estará disponible muy pronto.</strong><p>El catálogo activo de esta tienda todavía no contiene pizzas.</p></div>
        @else
            <div class="home-product-grid">
                @foreach($featuredProducts as $product)
                    @php($defaultVariant = $product->variants->first(fn ($variant) => $variantAvailability->get($variant->ulid)?->status !== \App\Enums\MenuAvailabilityStatus::Unavailable))
                    @php($publicDescription = \App\Support\StorefrontCatalogPresenter::description($product->description))
                    <article class="home-product-card" data-product-card data-product-name="{{ $product->name }}">
                        <div class="product-art"><span>{{ str($product->name)->substr(0, 1)->upper() }}</span><small>{{ $product->category?->name }}</small></div>
                        <div class="home-product-content">
                            <h3>{{ $product->name }}</h3>
                            <p>{{ $publicDescription ?: 'Preparada al horno con nuestra masa madre artesanal.' }}</p>
                            <label class="sr-only" for="home-variant-{{ $product->ulid }}">Tamaño</label>
                            <select id="home-variant-{{ $product->ulid }}" class="variant-select" data-variant-select>
                                @foreach($product->variants as $variant)
                                    @php($availability = $variantAvailability->get($variant->ulid))
                                    <option value="{{ $variant->ulid }}" data-name="{{ $variant->name }}" data-price="{{ $variant->price }}" data-status="{{ $availability?->status->value ?? 'untracked' }}" data-status-label="{{ $availability?->status->label() ?? 'Disponibilidad por confirmar' }}" @selected($defaultVariant?->is($variant)) @disabled($availability?->status === \App\Enums\MenuAvailabilityStatus::Unavailable)>
                                        {{ $variant->name }} · {{ $variant->price }} Bs. · {{ $availability?->status->label() ?? 'Por confirmar' }}
                                    </option>
                                @endforeach
                            </select>
                            @if($defaultVariant)
                                @php($defaultAvailability = $variantAvailability->get($defaultVariant->ulid))
                                <small class="availability-note status-{{ $defaultAvailability?->status->value ?? 'untracked' }}" data-selected-availability>{{ $defaultAvailability?->status->label() ?? 'Disponibilidad por confirmar' }}</small>
                            @else
                                <small class="availability-note status-unavailable">Sin disponibilidad en esta sucursal</small>
                            @endif
                            <button class="add-button" type="button" data-add-product @disabled(!$defaultVariant)>Agregar al pedido <span>+</span></button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="section promotions-preview" id="promociones">
    <div class="shell">
        <div class="section-heading centered">
            <span class="eyebrow">Ofertas y combos especiales</span>
            <h2>Promociones <em>al horno</em></h2>
            <p>Propuestas vigentes publicadas por el restaurante, con sus precios reales.</p>
        </div>

        @if($featuredPromotions->isEmpty())
            <div class="promotions-empty compact">
                <span aria-hidden="true">✦</span>
                <strong>No hay promociones vigentes ahora.</strong>
                <p>Mientras tanto, descubre todas las opciones de nuestra carta artesanal.</p>
                <a class="button button-primary" href="{{ route('storefront.menu') }}">Ver el menú</a>
            </div>
        @else
            <div class="promotion-grid">
                @foreach($featuredPromotions as $promotion)
                    @include('storefront.partials.promotion-card', ['promotion' => $promotion, 'index' => $loop->iteration])
                @endforeach
            </div>
            <div class="promotions-more"><a class="arrow-link" href="{{ route('storefront.promotions') }}">Ver todas las promociones →</a></div>
        @endif
    </div>
</section>

<section class="section order-options">
    <div class="shell option-grid">
        <div class="option-copy"><span class="eyebrow">Tu pizza, a tu manera</span><h2>Del horno a tu mesa</h2><p>@if($demoMode)Arma un pedido de demostración con el catálogo y la disponibilidad vigente, sin enviarlo al restaurante.@else Arma tu pedido con los precios vigentes del catálogo y envíalo al WhatsApp oficial para confirmar disponibilidad.@endif</p><a class="button button-primary" href="{{ route('storefront.menu') }}">Comenzar pedido</a></div>
        <article><i>⌂</i><h3>Recojo en local</h3><p>Selecciona tus productos y coordina por WhatsApp cuándo pasar por {{ $branch->name }}.</p>@if($branch->address)<small>{{ $branch->address }}</small>@endif</article>
        <article><i>⌁</i><h3>Delivery</h3><p>Comparte tu dirección al finalizar. La cobertura y el costo se confirman directamente con el restaurante.</p><small>Sin reglas ni cargos automáticos</small></article>
    </div>
</section>
@endsection
