@extends('layouts.storefront')

@section('title', 'Finalizar pedido')
@section('page', 'checkout')

@section('content')
<section class="checkout-page">
    <div class="shell">
        <a class="back-link" href="{{ route('storefront.menu') }}">← Volver a la carta artesanal</a>
        <div class="checkout-steps" aria-label="Progreso"><span class="done">✓ Carrito</span><i></i><span class="active" data-checkout-mode-label>2 Datos de recojo</span><i></i><span>3 Confirmación</span></div>

        <div class="checkout-grid">
            <form class="checkout-form" data-checkout-form data-demo="{{ $demoMode ? 'true' : 'false' }}" data-resolve-url="{{ route('storefront.checkout.resolve') }}" data-preparation-enabled="{{ config('storefront.delivery_payment_preparation_enabled') ? 'true' : 'false' }}" data-online-qr-enabled="{{ config('storefront.online_dynamic_qr_enabled') ? 'true' : 'false' }}" data-online-order-url="{{ route('storefront.preparation.order') }}" data-online-qr-url="{{ route('storefront.preparation.qr') }}" data-cash-availability-url="{{ route('storefront.preparation.availability') }}" data-preparation-url="{{ route('storefront.preparation.store') }}" data-preparation-resolve-url="{{ route('storefront.preparation.resolve') }}" data-preparation-cancel-url="{{ route('storefront.preparation.cancel') }}" action="{{ route('storefront.checkout.store') }}" method="POST" novalidate>
                @csrf
                <section class="checkout-card">
                    <span class="form-step">Modalidad</span>
                    <div class="fulfillment-grid"><label class="choice-card"><input type="radio" name="fulfillment" value="pickup" checked><span><b>Recojo en local</b><small>{{ $branch->address ?: $branch->name }}</small></span></label>
                        @if($delivery)<label class="choice-card"><input type="radio" name="fulfillment" value="delivery"><span><b>Delivery</b><small>Gratis hasta {{ $delivery['free_radius_m'] }} m en línea recta</small></span></label>@endif
                    </div>
                </section>

                <section class="checkout-card">
                    <div class="form-section-title"><span>1</span><div><h2>Datos de contacto</h2><p>Para identificar tu solicitud y poder confirmarla.</p></div></div>
                    <div class="field-grid">
                        <label class="field"><span>Nombre y apellido</span><input name="customer_name" type="text" autocomplete="name" maxlength="120" required></label>
                        <label class="field"><span>Teléfono celular (WhatsApp)</span><input name="customer_phone" type="tel" autocomplete="tel" maxlength="30" required></label>
                    </div>
                </section>

                @if($delivery)
                <section class="checkout-card delivery-fields" data-delivery-fields hidden
                    data-latitude="{{ $delivery['latitude'] }}" data-longitude="{{ $delivery['longitude'] }}" data-radius="{{ $delivery['free_radius_m'] }}"
                    data-tile-url="{{ config('storefront.delivery.tile_url') }}" data-search-url="{{ route('storefront.delivery.search') }}" data-coverage-url="{{ route('storefront.delivery.coverage') }}">
                    <div class="form-section-title"><span>2</span><div><h2>Ubicación de entrega</h2><p>Busca tu dirección o selecciona el punto en el mapa.</p></div></div>
                    <label class="field"><span>Dirección</span><input name="address" type="text" autocomplete="off" maxlength="500" data-delivery-address></label>
                    <ul class="delivery-suggestions" data-delivery-suggestions aria-label="Direcciones sugeridas"></ul>
                    <button class="text-button" type="button" data-delivery-geolocate>Usar mi ubicación actual</button>
                    <div class="delivery-map" data-delivery-map aria-label="Mapa para seleccionar el punto de entrega"></div>
                    <label class="field"><span>Referencia del domicilio</span><textarea name="reference" rows="2" maxlength="500" placeholder="Ej.: portón verde, frente a la plaza"></textarea></label>
                    <p class="delivery-status" data-delivery-status role="status" aria-live="polite">Toca el mapa o busca una dirección.</p>
                    <p class="delivery-cost-warning" data-delivery-cost-warning hidden>Ubicación fuera de cobertura. Cambia el punto o elige recojo en local.</p>
                    <button class="button button-primary" type="button" data-delivery-confirm disabled>Confirmar punto de entrega</button>
                    <p class="summary-note">Distancia en línea recta. Fuera del radio gratuito puedes elegir recojo en local.</p>
                </section>
                @endif

                <section class="checkout-card">
                    <div class="form-section-title"><span>3</span><div><h2>Observaciones</h2><p>Indicaciones para el restaurante.</p></div></div>
                    <label class="field"><span>Notas del pedido <small>(opcional)</small></span><textarea name="notes" rows="4" maxlength="1000" placeholder="Ej.: alergia que debamos conocer…"></textarea></label>
                </section>
            </form>

            <aside class="checkout-summary">
                <div class="summary-card">
                    @if($demoMode)
                        <div class="demo-checkout-note"><strong>Modo demostración</strong><span>No se registrarán solicitudes mientras esté activado.</span></div>
                    @endif
                    <span class="eyebrow">Resumen del pedido</span>
                    <h2>Tu comanda</h2>
                    <div class="summary-lines js-checkout-lines"></div>
                    <div class="summary-empty js-checkout-empty"><p>No hay productos en tu comanda.</p><a href="{{ route('storefront.menu') }}">Volver al menú</a></div>
                    <div class="summary-total"><span>Subtotal de productos</span><strong><span class="js-cart-total">0</span> Bs.</strong></div>
                    <p class="summary-note">Los precios y la disponibilidad se validarán al registrar la solicitud.</p>
                    @if(config('storefront.delivery_payment_preparation_enabled'))
                        <p class="summary-note" data-preparation-note hidden>@if(config('storefront.online_dynamic_qr_enabled')) Para delivery se preparará el pedido y se generará un QR. El pago requiere confirmación bancaria. @else Para delivery se prepararán los datos del pedido. No se generará un pago ni se confirmará la compra. @endif</p>
                    @endif
                    <div class="form-errors" data-form-errors role="alert" hidden></div>
                    <div class="form-errors" data-cash-warning role="status" aria-live="polite" hidden></div>
                    <div class="demo-confirmation" data-demo-confirmation role="status" hidden></div>
                    <div data-online-qr hidden><p data-online-qr-status role="status" aria-live="polite"></p><img data-online-qr-image alt="QR de pago" style="max-width: 100%; width: 260px" hidden><button type="button" class="button button-primary" data-online-qr-check data-status-url="{{ route('storefront.preparation.qr.status') }}" hidden>Verificar estado del pago</button></div>
                    <button class="button whatsapp-button button-full" type="button" data-submit-order data-demo="{{ $demoMode ? 'true' : 'false' }}" @disabled($demoMode)>Realizar pedido</button>
                    <p class="legal-note" data-checkout-legal-note>El restaurante revisará tu solicitud. No se registra un pago ni se descuenta inventario.</p>
                </div>
                <div class="trust-row"><span>48h<br><small>Masa madre</small></span><span>♨<br><small>Horno artesanal</small></span><span>✓<br><small>Precio validado</small></span></div>
            </aside>
        </div>
    </div>
</section>
@endsection
