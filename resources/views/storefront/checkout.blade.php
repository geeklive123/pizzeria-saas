@extends('layouts.storefront')

@section('title', 'Finalizar pedido')
@section('page', 'checkout')

@section('content')
<section class="checkout-page">
    <div class="shell">
        <a class="back-link" href="{{ route('storefront.menu') }}">← Volver a la carta artesanal</a>
        <div class="checkout-steps" aria-label="Progreso"><span class="done">✓ Carrito</span><i></i><span class="active">2 Datos de recojo</span><i></i><span>3 Confirmación</span></div>

        <div class="checkout-grid">
            <form class="checkout-form" data-checkout-form data-demo="{{ $demoMode ? 'true' : 'false' }}" data-resolve-url="{{ route('storefront.checkout.resolve') }}" action="{{ route('storefront.checkout.store') }}" method="POST" novalidate>
                @csrf
                <section class="checkout-card">
                    <span class="form-step">Modalidad</span>
                    <div class="fulfillment-grid"><label class="choice-card"><input type="radio" name="fulfillment" value="pickup" checked><span><b>Recojo en local</b><small>{{ $branch->address ?: $branch->name }}</small></span></label></div>
                </section>

                <section class="checkout-card">
                    <div class="form-section-title"><span>1</span><div><h2>Datos de contacto</h2><p>Para identificar tu solicitud y poder confirmarla.</p></div></div>
                    <div class="field-grid">
                        <label class="field"><span>Nombre y apellido</span><input name="customer_name" type="text" autocomplete="name" maxlength="120" required></label>
                        <label class="field"><span>Teléfono celular (WhatsApp)</span><input name="customer_phone" type="tel" autocomplete="tel" maxlength="30" required></label>
                    </div>
                </section>

                <section class="checkout-card">
                    <div class="form-section-title"><span>2</span><div><h2>Observaciones</h2><p>Indicaciones para el restaurante.</p></div></div>
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
                    <div class="form-errors" data-form-errors role="alert" hidden></div>
                    <div class="demo-confirmation" data-demo-confirmation role="status" hidden></div>
                    <button class="button whatsapp-button button-full" type="button" data-submit-order @disabled($demoMode)>Realizar pedido</button>
                    <p class="legal-note">El restaurante revisará tu solicitud. No se registra un pago ni se descuenta inventario.</p>
                </div>
                <div class="trust-row"><span>48h<br><small>Masa madre</small></span><span>♨<br><small>Horno artesanal</small></span><span>✓<br><small>Precio validado</small></span></div>
            </aside>
        </div>
    </div>
</section>
@endsection
