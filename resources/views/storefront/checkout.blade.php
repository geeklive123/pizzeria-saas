@extends('layouts.storefront')

@section('title', 'Finalizar pedido')
@section('page', 'checkout')

@section('content')
<section class="checkout-page">
    <div class="shell">
        <a class="back-link" href="{{ route('storefront.menu') }}">← Volver a la carta artesanal</a>
        <div class="checkout-steps" aria-label="Progreso"><span class="done">✓ Carrito</span><i></i><span class="active">2 Datos y envío</span><i></i><span>3 WhatsApp</span></div>

        <div class="checkout-grid">
            <form class="checkout-form" data-checkout-form data-demo="{{ $demoMode ? 'true' : 'false' }}" action="{{ route('storefront.checkout.whatsapp') }}" method="POST" novalidate>
                @csrf
                <section class="checkout-card">
                    <span class="form-step">Modalidad</span>
                    <div class="fulfillment-grid">
                        <label class="choice-card"><input type="radio" name="fulfillment" value="delivery" checked><span><b>Delivery a domicilio</b><small>La cobertura y el costo se confirman por WhatsApp.</small></span></label>
                        <label class="choice-card"><input type="radio" name="fulfillment" value="pickup"><span><b>Recojo en local</b><small>{{ $branch->address ?: $branch->name }}</small></span></label>
                    </div>
                </section>

                <section class="checkout-card">
                    <div class="form-section-title"><span>1</span><div><h2>Datos de contacto</h2><p>Para identificar tu solicitud y poder confirmarla.</p></div></div>
                    <div class="field-grid">
                        <label class="field field-full"><span>Nombre y apellido</span><input name="customer_name" type="text" autocomplete="name" maxlength="120" required></label>
                        <label class="field"><span>Teléfono celular (WhatsApp)</span><input name="customer_phone" type="tel" autocomplete="tel" maxlength="30" required></label>
                        <label class="field"><span>Correo electrónico <small>(opcional)</small></span><input name="customer_email" type="email" autocomplete="email" maxlength="255"></label>
                    </div>
                </section>

                <section class="checkout-card" data-delivery-fields>
                    <div class="form-section-title"><span>2</span><div><h2>Dirección de entrega</h2><p>Escribe una dirección clara; no calculamos cobertura automáticamente.</p></div></div>
                    <div class="field-grid">
                        <label class="field field-full"><span>Calle, avenida, número y zona</span><input name="address" type="text" autocomplete="street-address" maxlength="500" required></label>
                        <label class="field field-full"><span>Edificio, piso o referencia visible <small>(opcional)</small></span><input name="reference" type="text" maxlength="500"></label>
                    </div>
                    <div class="delivery-notice"><i>⌁</i><div><strong>Costo y cobertura por confirmar</strong><p>El restaurante responderá en WhatsApp antes de confirmar el pedido.</p></div></div>
                </section>

                <section class="checkout-card">
                    <div class="form-section-title"><span>3</span><div><h2>Observaciones</h2><p>Indicaciones para el equipo de cocina o la entrega.</p></div></div>
                    <label class="field"><span>Notas del pedido <small>(opcional)</small></span><textarea name="notes" rows="4" maxlength="1000" placeholder="Ej.: tocar el timbre, alergia que debamos conocer…"></textarea></label>
                </section>
            </form>

            <aside class="checkout-summary">
                <div class="summary-card">
                    @if($demoMode)
                        <div class="demo-checkout-note"><strong>Revisión UX/UI</strong><span>Validaremos el carrito, pero no abriremos WhatsApp ni enviaremos el pedido.</span></div>
                    @endif
                    <span class="eyebrow">Resumen del pedido</span>
                    <h2>Tu comanda</h2>
                    <div class="summary-lines js-checkout-lines"></div>
                    <div class="summary-empty js-checkout-empty"><p>No hay productos en tu comanda.</p><a href="{{ route('storefront.menu') }}">Volver al menú</a></div>
                    <div class="summary-total"><span>Subtotal de productos</span><strong><span class="js-cart-total">0</span> Bs.</strong></div>
                    <p class="summary-note">No incluye delivery. Los precios se validarán otra vez en el servidor antes de abrir WhatsApp.</p>
                    <div class="form-errors" data-form-errors role="alert" hidden></div>
                    <div class="demo-confirmation" data-demo-confirmation role="status" hidden></div>
                    <button class="button whatsapp-button button-full" type="button" data-submit-order><span>◉</span> {{ $demoMode ? 'Simular finalización' : 'Enviar pedido por WhatsApp' }}</button>
                    <p class="legal-note">Esta acción no crea una venta, no registra un pago y no descuenta inventario.@if($demoMode) Tampoco genera un enlace de envío.@endif</p>
                </div>
                <div class="trust-row"><span>48h<br><small>Masa madre</small></span><span>♨<br><small>Horno artesanal</small></span><span>✓<br><small>Precio validado</small></span></div>
            </aside>
        </div>
    </div>
</section>
@endsection
