@extends('layouts.storefront')

@section('title', 'Horarios y ubicación')
@section('page', 'location')

@section('content')
<section class="location-hero">
    <div class="shell location-hero-grid">
        <div>
            <span class="eyebrow">Planifica tu visita o tu pedido</span>
            <h1>Horario de atención<br><span>&amp; ubicación</span></h1>
            <p>Encuentra la información oficial de {{ $branch->name }} y elige cómo quieres disfrutar nuestra carta artesanal.</p>
        </div>
        <div class="location-hero-actions">
            @if($whatsappUrl)
                <a class="button button-primary" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Pedir por WhatsApp <span aria-hidden="true">↗</span></a>
            @endif
            <a class="button button-ghost" href="#como-llegar">Cómo llegar <span aria-hidden="true">⌖</span></a>
            @if($demoMode)<span class="demo-contact-note">Contacto desactivado durante la revisión</span>@endif
        </div>
    </div>
</section>

<section class="location-main">
    <div class="shell location-grid">
        <article class="hours-card">
            <header class="location-card-heading">
                <span class="location-icon" aria-hidden="true">◷</span>
                <div><small>Punto de fuego</small><h2>Horario de atención</h2></div>
            </header>

            @if(count($hours) > 0)
                <div class="hours-list">
                    @foreach($hours as $schedule)
                        <div class="hours-row">
                            <span class="hours-row-icon" aria-hidden="true">{{ $loop->last ? '☼' : '▥' }}</span>
                            <div><strong>{{ $schedule['days'] }}</strong>@if(!empty($schedule['note']))<small>{{ $schedule['note'] }}</small>@endif</div>
                            <b>{{ $schedule['time'] }}</b>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="hours-pending">
                    <span aria-hidden="true">◷</span>
                    <div>
                        <strong>Horario oficial pendiente de publicación</strong>
                        <p>Estamos preparando esta información para que siempre esté actualizada.</p>
                    </div>
                </div>
            @endif

            <div class="freshness-note">
                <span aria-hidden="true">♨</span>
                <p><strong>Preparación artesanal</strong> Cada pedido se prepara con el tiempo y cuidado de nuestra masa madre.</p>
            </div>

            <footer class="location-card-footer">
                <span>⌖ {{ $branch->name }}</span>
                <a href="{{ route('storefront.menu') }}">Explorar carta completa →</a>
            </footer>
        </article>

        <div class="location-side">
            <article class="delivery-banner">
                <span class="eyebrow inverse">Entrega coordinada</span>
                <h2>Delivery a domicilio</h2>
                <p>{{ $deliveryNote ?: 'La cobertura y el costo de envío se confirman directamente por WhatsApp antes de completar tu pedido.' }}</p>
                @if($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Consultar mi zona <span aria-hidden="true">↗</span></a>
                @endif
            </article>

            <article class="address-card" id="como-llegar">
                <header>
                    <div><small>Nuestra pizzería</small><h2>Dónde encontrarnos</h2></div>
                    <span>{{ $branch->name }}</span>
                </header>
                <div class="address-copy">
                    <span aria-hidden="true">⌖</span>
                    <div>
                        <strong>{{ $branch->name }}</strong>
                        @if($branch->address)
                            <p>{{ $branch->address }}</p>
                        @else
                            <p>Dirección pendiente de configuración.</p>
                        @endif
                    </div>
                </div>
                <div class="map-placeholder" aria-label="Referencia visual de ubicación">
                    <span class="map-road road-one"></span><span class="map-road road-two"></span><span class="map-road road-three"></span>
                    <span class="map-pin"><b>MM</b><small>{{ $branch->name }}</small></span>
                    @if($mapUrl)
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer">Abrir mapa <span aria-hidden="true">↗</span></a>
                    @endif
                </div>
                <div class="address-meta">
                    <span><small>Sucursal</small><strong>{{ $branch->name }}</strong></span>
                    <span><small>Contacto</small><strong>{{ $phone ?: 'Por configurar' }}</strong></span>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="location-options section">
    <div class="shell">
        <div class="section-heading centered">
            <span class="eyebrow">Cómo disfrutar nuestra masa</span>
            <h2>Opciones para tu comanda</h2>
            <p>Elige la modalidad que mejor se adapte a tu momento.</p>
        </div>
        <div class="visit-option-grid">
            <article><i aria-hidden="true">♨</i><h3>Comer en el salón</h3><p>Disfruta las pizzas recién horneadas en nuestra sucursal. Consulta la disponibilidad al llegar.</p><small>Atención en {{ $branch->name }}</small></article>
            <article><i aria-hidden="true">▣</i><h3>Pasa a recoger</h3><p>Arma tu pedido en la tienda y coordina por WhatsApp el momento de recojo.</p><a href="{{ route('storefront.menu') }}">Preparar pedido →</a></article>
            <article><i aria-hidden="true">⌁</i><h3>Delivery a domicilio</h3><p>Indícanos tu dirección en el checkout. La cobertura y cualquier costo adicional se confirman por WhatsApp.</p><small>Entrega coordinada directamente</small></article>
        </div>
    </div>
</section>

<section class="location-faq section">
    <div class="shell faq-grid">
        <div class="faq-intro">
            <span class="eyebrow">Preguntas frecuentes</span>
            <h2>Antes de venir o pedir</h2>
            <p>La información que depende de la operación del día se confirma directamente con el restaurante.</p>
            @if($whatsappUrl)<a class="arrow-link" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">Hablar con {{ $company->name }} →</a>@endif
        </div>
        <div class="faq-list">
            <article><h3>¿Cómo confirmo el horario de hoy?</h3><p>@if($whatsappUrl)Escríbenos al WhatsApp oficial antes de visitarnos y te confirmaremos la atención del día.@else El horario oficial se publicará aquí cuando el restaurante complete su configuración.@endif</p></article>
            <article><h3>¿Cómo sé si hay delivery hasta mi dirección?</h3><p>Agrega tu dirección al finalizar el pedido. El equipo confirmará la cobertura y cualquier costo antes de coordinar el envío.</p></article>
            <article><h3>¿Dónde recojo mi pedido?</h3><p>El recojo se coordina en {{ $branch->name }}@if($branch->address), {{ $branch->address }}@endif.</p></article>
        </div>
    </div>
</section>
@endsection
