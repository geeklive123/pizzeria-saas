@extends('layouts.app')
@section('title', 'Solicitud online')
@section('heading', 'Solicitud online')
@section('content')
<div class="mx-auto max-w-3xl rounded-2xl border border-stone-200 bg-white p-6">
    <a class="link" href="{{ route('online-orders.index') }}">Volver a pedidos online</a>
    <h1 class="mt-4 text-2xl font-bold">{{ $order->publicCode() }}</h1>
    @if(session('online_order_notice'))<p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">{{ session('online_order_notice') }}</p>@endif
    <p class="mt-2">{{ $order->customer_name }} · {{ $order->customer_phone }}</p>
    <p>{{ $order->fulfillment === 'delivery' ? 'Delivery' : 'Recojo en local' }} · {{ \App\Support\UiFormatter::date($order->created_at, true) }} · {{ ['pending_confirmation' => 'Pendiente', 'confirmed' => 'Confirmado', 'cancelled' => 'Cancelado', 'paid' => 'PAGADO'][$order->status] ?? $order->status }}</p>
    @if($order->fulfillment === 'delivery')
        <p class="mt-3">Dirección: {{ $order->delivery_address }}</p>
        @if($order->delivery_reference)<p>Referencia: {{ $order->delivery_reference }}</p>@endif
        <p>Ubicación: {{ $order->delivery_latitude }}, {{ $order->delivery_longitude }} · {{ $order->delivery_distance_m }} m en línea recta.</p>
        <p>{{ $order->delivery_coverage === 'free' ? 'Envío gratuito' : 'Costo de envío por cotizar; no se puede convertir al POS.' }}</p>
    @endif
    @if($order->resolved_at)<p>Resuelta por {{ $order->resolvedBy?->name ?? 'Usuario no disponible' }} · {{ \App\Support\UiFormatter::date($order->resolved_at, true) }}</p>@endif
    @if($order->items->contains(fn ($item) => $item->was_promotion === null))
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Esta solicitud antigua no conserva toda la información necesaria para comprobar sus productos o promociones. No se puede convertir al POS. Revisa el detalle y contacta al cliente antes de resolverla.
        </div>
    @endif
    @if($order->legacy_reviewed_at)
        <p class="mt-3">Revisión registrada por {{ $order->legacyReviewedBy?->name ?? 'Usuario no disponible' }} · {{ \App\Support\UiFormatter::date($order->legacy_reviewed_at, true) }}</p>
        <p>Resultado: cancelada tras contactar al cliente.</p>
        <p>Nota de revisión: {{ $order->legacy_review_note }}</p>
    @endif
    @if($order->notes)<p class="mt-3">Observaciones: {{ $order->notes }}</p>@endif
    <ul class="my-4 space-y-2">@foreach($order->items as $item)<li>{{ $item->quantity }} × {{ $item->product_name }} ({{ $item->variant_name }}) · Bs {{ $item->unit_price }} · Subtotal Bs {{ $item->subtotal }}</li>@endforeach</ul>
    <p class="font-bold">{{ $order->delivery_quote_status === 'pending' ? 'Subtotal de productos' : 'Total' }}: Bs {{ $order->total }}</p>
    @if($order->posOrder)
        <p class="mt-3">Orden POS #{{ $order->posOrder->order_number }} · Pago {{ $order->posOrder->status === \App\Enums\OrderStatus::Paid ? 'registrado' : 'pendiente o por verificar en POS' }}</p>
        <a class="link" href="{{ route('orders.show', $order->posOrder->ulid) }}">Abrir orden POS</a>
        @if($order->status === 'paid' && $bankIntent)
            <p class="mt-3">Pago QR Banco Económico · Referencia {{ $bankIntent->bank_qr_id }} · {{ \App\Support\UiFormatter::date($bankIntent->confirmed_at, true) }}</p>
            <p>Impresión: {{ $order->posOrder->printAttempts()->count() }} trabajo(s) registrado(s).</p>
            @foreach($order->posOrder->printAttempts()->latest('id')->limit(6)->get() as $attempt)
                <p>{{ $attempt->purpose === \App\Enums\PrinterPurpose::Kitchen ? 'Comanda' : 'Ticket' }}: {{ ['pending' => 'En cola', 'printed' => 'Impreso', 'failed' => 'Error de impresión'][$attempt->status->value] ?? 'En proceso' }}</p>
            @endforeach
            @can('reprintKitchen', $order->posOrder)<form method="POST" action="{{ route('orders.reprint.kitchen', $order->posOrder->ulid) }}">@csrf<button class="btn-secondary">Reimprimir comanda</button></form>@endcan
            @can('reprintCustomerTicket', $order->posOrder)<form method="POST" action="{{ route('orders.reprint.ticket', $order->posOrder->ulid) }}">@csrf<button class="btn-secondary">Reimprimir ticket</button></form>@endcan
        @endif
    @endif
    @if($canResolve && $bankIntent)
        <section class="mt-5 rounded-2xl border border-stone-200 p-4 space-y-3">
            <h2 class="font-bold">Disposición de incidencia QR online</h2>
            <p>QR {{ $bankIntent->bank_qr_id ?? 'sin identificador' }} · Estado {{ $bankIntent->status->value }} · Estado bancario {{ $bankIntent->bank_status ?? 'sin confirmar' }} · Incidencia {{ $bankIntent->issue_code ?? 'ninguna' }}</p>
            <p>Caja original #{{ $bankIntent->cash_session_id }} · Importe Bs {{ $bankIntent->amount }}</p>
            <p class="font-semibold">Reservas de inventario</p>
            <ul>@forelse($reservations as $reservation)<li>{{ $reservation->inventoryItem?->name ?? 'Inventario no disponible' }}: {{ $reservation->quantity }} · {{ $reservation->status->value }}</li>@empty<li>Sin reservas registradas.</li>@endforelse</ul>
            @if(! $dispositionsAvailable)
                <p>La disposición administrativa no está disponible en esta instalación.</p>
            @elseif($disposition)
                <p>Disposición {{ $disposition->disposition }} · Responsable {{ $disposition->actor?->name ?? 'Usuario no disponible' }} · Fecha de acción {{ $disposition->action_date }} · Registrada {{ \App\Support\UiFormatter::date($disposition->resolved_at, true) }} · Resultado de orden {{ $disposition->order_result }}</p>
                <p>Inventario: {{ $disposition->inventory_result['result'] ?? 'no disponible' }}</p>
                @if($inventoryOutcome)
                    <p role="status">Entrega documentada por {{ $inventoryOutcome->actor?->name ?? 'Usuario no disponible' }} · Fecha {{ $inventoryOutcome->action_date }}. Las reservas verificadas fueron consumidas; no se creó un pago POS.</p>
                    <p>Motivo: {{ $inventoryOutcome->reason }}</p><p>Evidencia: {{ $inventoryOutcome->evidence }}</p>
                @elseif(($disposition->inventory_result['result'] ?? null) === 'unchanged')
                    <p role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-3">Inventario pendiente: esta documentación no confirma la entrega ni dispone de las reservas. La incidencia operativa permanece abierta.</p>
                    @if($inventoryOutcomeAvailable && $bankIntent->status === \App\Enums\DynamicQrStatus::ReconciliationRequired && $bankIntent->bank_status === '1' && ! $bankIntent->payment_id)
                        <form method="POST" action="{{ route('online-orders.qr-delivery', $order->id) }}" class="space-y-3" onsubmit="return confirm('¿Confirmar la entrega física y consumir las reservas verificadas?')">
                            @csrf
                            <p>Solo registra una entrega física ya realizada. El inventario se consumirá una vez y no se creará un pago POS.</p>
                            @if($errors->any())<div class="text-red-700" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                            <label>Fecha de entrega <input class="input" type="date" name="action_date" required></label>
                            <label>Motivo <textarea class="input w-full" name="reason" minlength="10" maxlength="500" required></textarea></label>
                            <label>Evidencia de entrega <textarea class="input w-full" name="evidence" minlength="10" maxlength="2000" required></textarea></label>
                            <label><input type="checkbox" name="confirmed" value="1" required> Confirmo que la entrega física ya se realizó y que la evidencia la respalda.</label>
                            <button class="btn-secondary">Confirmar entrega e inventario</button>
                        </form>
                    @endif
                @endif
                <ul>@foreach($disposition->inventory_result['before'] ?? [] as $prior)<li>Reserva #{{ $prior['id'] }} · Inventario #{{ $prior['item'] }} · Cantidad {{ $prior['quantity'] }} · Estado anterior {{ $prior['status'] }}</li>@endforeach</ul>
                <p>Motivo: {{ $disposition->reason }}</p><p>Evidencia: {{ $disposition->evidence }}</p>
            @else
                @php
                    $canCancelQr = false; // La no pagabilidad definitiva del estado 9 aún no está acreditada.
                    $paymentEvidence = $bankIntent->reconciliation_data ?? [];
                    $paymentAmount = $paymentEvidence['amount'] ?? null;
                    $canDocumentPayment = $bankIntent->status === \App\Enums\DynamicQrStatus::ReconciliationRequired && $bankIntent->bank_status === '1' && $bankIntent->bank_qr_id && $bankIntent->last_checked_at && ! $bankIntent->payment_id && ($paymentEvidence['qrId'] ?? null) === $bankIntent->bank_qr_id && ($paymentEvidence['currency'] ?? null) === $bankIntent->currency && is_string($paymentAmount) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $paymentAmount) && \Brick\Math\BigDecimal::of($paymentAmount)->isEqualTo($bankIntent->amount);
                @endphp
                @if($canCancelQr || $canDocumentPayment)
                    <p>La evidencia debe identificar la comprobación bancaria o la acción interna ya realizada. No se realiza ningún pago ni devolución.</p>
                    <form method="POST" action="{{ route('online-orders.qr-disposition', $order->id) }}" class="space-y-3" onsubmit="return confirm('¿Registrar esta disposición administrativa?')">
                        @csrf
                        @if($errors->any())<div class="text-red-700" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                        <label>Disposición <select name="disposition" class="input" required><option value="">Seleccionar</option>@if($canDocumentPayment && (! $resolution || $resolution->resolution === 'accounting'))<option value="accounting">Regularización para entrega</option>@endif @if($canDocumentPayment && (! $resolution || $resolution->resolution === 'refund'))<option value="refund">Devolución interna ya realizada</option>@endif @if($canCancelQr)<option value="cancel">Cancelar y liberar reservas</option>@endif</select></label>
                        <label>Importe Bs <input class="input" name="amount" value="{{ $bankIntent->amount }}" required></label>
                        <label>Fecha de la acción <input class="input" type="date" name="action_date" required></label>
                        <label>Motivo <textarea class="input w-full" name="reason" minlength="10" maxlength="500" required></textarea></label>
                        <label>Evidencia verificable <textarea class="input w-full" name="evidence" minlength="10" maxlength="2000" required></textarea></label>
                        <label><input type="checkbox" name="confirmed" value="1" required> Confirmo la verificación y la acción indicada.</label>
                        @error('disposition')<p class="text-red-700">{{ $message }}</p>@enderror
                        <button class="btn-secondary">Registrar disposición</button>
                    </form>
                @elseif($bankIntent->status === \App\Enums\DynamicQrStatus::Paid && $bankIntent->payment_id && ! $bankIntent->issue_code)
                    <p>Pago registrado. No hay una incidencia bancaria pendiente.</p>
                @else
                    <p>La incidencia permanece en revisión. La cancelación y liberación de reservas requieren confirmación bancaria de no pagabilidad definitiva.</p>
                @endif
            @endif
        </section>
    @endif
    @if($canResolve && $bankIntent && (! $disposition || $resolution) && $bankIntent->status === \App\Enums\DynamicQrStatus::ReconciliationRequired && $bankIntent->bank_status === '1' && $bankIntent->cashSession?->status === \App\Enums\CashSessionStatus::Closed)
        <section class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p>Incidencia bancaria: {{ $bankIntent->issue_code }} · QR {{ $bankIntent->bank_qr_id }} · Bs {{ $bankIntent->amount }}</p>
            @if($resolution)
                <p>Resolución: {{ $resolution->resolution === 'accounting' ? 'Regularización contable documentada' : 'Devolución interna documentada' }} · {{ $resolution->actor?->name ?? 'Usuario no disponible' }} · {{ \App\Support\UiFormatter::date($resolution->resolved_at, true) }}</p>
                <p>Motivo: {{ $resolution->reason }}</p><p>Evidencia: {{ $resolution->evidence }}</p>
            @else
                <p>Registra una acción ya documentada. No crea pagos ni ejecuta una devolución bancaria. La incidencia y la caja original se conservan.</p>
                <form method="POST" action="{{ route('online-orders.closed-payment-resolution', $order->id) }}" class="space-y-3">
                    @csrf
                    <label>Resolución <select class="input" name="resolution" required><option value="">Seleccionar</option><option value="accounting">Regularización contable documentada</option><option value="refund">Devolución interna documentada</option></select></label>
                    <label>Motivo <textarea class="input w-full" name="reason" minlength="10" maxlength="1000" required>{{ old('reason') }}</textarea></label>
                    <label>Evidencia o referencia verificable <textarea class="input w-full" name="evidence" minlength="10" maxlength="2000" required>{{ old('evidence') }}</textarea></label>
                    <label><input type="checkbox" name="confirmed" value="1" required> Confirmo que la acción seleccionada se realizó y que la evidencia la respalda.</label>
                    @error('resolution')<p class="text-red-700">{{ $message }}</p>@enderror
                    @error('reason')<p class="text-red-700">{{ $message }}</p>@enderror
                    @error('evidence')<p class="text-red-700">{{ $message }}</p>@enderror
                    <button class="btn-secondary">Registrar resolución</button>
                </form>
            @endif
        </section>
    @endif
    <div class="mt-5 flex flex-wrap gap-3">
        <a class="btn-secondary" href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer">Contactar por WhatsApp</a>
        @if($order->status === 'pending_confirmation' && ! $order->items->contains(fn ($item) => $item->was_promotion === null))
            @can('update', $order)
                @if($order->fulfillment !== 'delivery' || $order->delivery_quote_status === 'not_required')
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}">@csrf<input type="hidden" name="status" value="confirmed"><button class="btn-primary">Confirmar pedido</button></form>
                @endif
            @endcan
            @can('cancel', $order)
                <form method="POST" action="{{ route('online-orders.transition', $order->id) }}" onsubmit="return confirm('¿Cancelar esta solicitud?')">@csrf<input type="hidden" name="status" value="cancelled"><button class="btn-secondary">Cancelar solicitud</button></form>
            @endcan
        @endif
    </div>
    @if($order->status === 'pending_confirmation' && $order->items->contains(fn ($item) => $item->was_promotion === null))
        @can('cancel', $order)
            <form class="mt-5 space-y-3" method="POST" action="{{ route('online-orders.legacy-review', $order->id) }}" onsubmit="return confirm('¿Registrar la revisión y cancelar esta solicitud?')">
                @csrf
                <p>Si el cliente desea continuar, deberá enviar una nueva solicitud con el detalle y los precios vigentes. Esta acción conserva el pedido original y no crea una venta.</p>
                <label for="legacy-note">Resultado de la conversación (sin datos sensibles)</label>
                <textarea id="legacy-note" name="note" class="input w-full" required minlength="3" maxlength="500">{{ old('note') }}</textarea>
                @error('note')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <label class="flex gap-2"><input type="checkbox" name="contacted" value="1" required> Confirmo que contacté al cliente y le informé que esta solicitud se cancelará.</label>
                @error('contacted')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-secondary" type="submit">Registrar revisión y cancelar solicitud</button>
            </form>
        @endcan
    @endif
</div>
@endsection
