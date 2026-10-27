@php($variant = $promotion->productVariant)
@php($product = $variant->product)
@php($publicDescription = \App\Support\StorefrontCatalogPresenter::description($product->description))
@php($orderable = (bool) $promotion->getAttribute('storefront_orderable'))
<article class="promotion-card" data-product-card data-product-name="{{ $product->name }}">
    <div class="promotion-card-top">
        <span class="promotion-saving">Oferta vigente</span>
        @if($promotion->ends_at)
            <span class="promotion-validity">Hasta {{ $promotion->ends_at->format('d/m/Y') }}</span>
        @else
            <span class="promotion-validity">Por tiempo limitado</span>
        @endif
    </div>
    <span class="promotion-number" aria-hidden="true">{{ str_pad((string) $index, 2, '0', STR_PAD_LEFT) }}</span>
    <h3>{{ $product->name }}</h3>
    <p>{{ $publicDescription ?: 'Consulta el contenido y la disponibilidad al confirmar tu pedido.' }}</p>
    <div class="promotion-price">
        <small>Precio de la promoción</small>
        <strong>{{ $variant->price }} <span>Bs.</span></strong>
    </div>
    <small class="availability-note {{ $orderable ? 'status-available' : 'status-unavailable' }}">{{ $orderable ? 'Disponible en esta sucursal' : 'Sin disponibilidad en esta sucursal' }}</small>
    <label class="sr-only" for="promotion-{{ $promotion->ulid }}">Presentación de {{ $product->name }}</label>
    <select class="sr-only" id="promotion-{{ $promotion->ulid }}" data-variant-select tabindex="-1" aria-hidden="true">
        <option value="{{ $variant->ulid }}" data-name="{{ $variant->name }}" data-price="{{ $variant->price }}" selected>{{ $variant->name }}</option>
    </select>
    <button class="promotion-order" type="button" data-add-product @disabled(!$orderable)>Agregar promoción <span aria-hidden="true">+</span></button>
</article>
