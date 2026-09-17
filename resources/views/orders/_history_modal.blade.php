<dialog class="m-auto max-h-[85vh] w-[min(52rem,calc(100%_-_2rem))] overflow-hidden rounded-3xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/45" data-order-history-modal aria-labelledby="order-history-title">
    <div class="flex max-h-[85vh] flex-col">
        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-stone-200 bg-white px-5 py-4 sm:px-6">
            <div>
                <h2 class="text-lg font-bold tracking-tight" id="order-history-title">HISTORIAL DE LA CUENTA</h2>
                <p class="mt-1 text-sm text-slate-500">{{ str($order->restaurantTable?->name ?? 'Para llevar')->ucfirst() }} · <span class="font-semibold text-orange-600">{{ $order->formattedOperationalNumber() }}</span></p>
            </div>
            <button class="grid size-10 shrink-0 place-items-center rounded-xl border border-stone-200 text-xl text-slate-500 hover:bg-stone-50 hover:text-slate-900" type="button" data-order-history-close aria-label="Cerrar historial">×</button>
        </header>

        <div class="overflow-y-auto p-4 sm:p-6">
            <dl class="grid grid-cols-2 gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Tandas totales</dt><dd class="mt-1 font-bold">{{ $history['summary']['total_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pagadas</dt><dd class="mt-1 font-bold text-emerald-700">{{ $history['summary']['paid_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Revertidas</dt><dd class="mt-1 font-bold text-red-700">{{ $history['summary']['reverted_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pendientes</dt><dd class="mt-1 font-bold text-amber-700">{{ $history['summary']['pending_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Total consumido</dt><dd class="mt-1 font-bold">{{ \App\Support\UiFormatter::money($history['summary']['total']) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Total pagado</dt><dd class="mt-1 font-bold">{{ \App\Support\UiFormatter::money($history['summary']['paid']) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Saldo pendiente</dt><dd class="mt-1 font-bold text-orange-700">{{ \App\Support\UiFormatter::money($history['summary']['balance']) }}</dd></div>
            </dl>

            <div class="mt-5 space-y-3">
                @forelse($history['batches'] as $batch)
                    <details class="group overflow-hidden rounded-2xl border {{ in_array($batch['status']['key'], ['cancelled', 'reverted'], true) ? 'border-red-200 bg-red-50/40' : 'border-stone-200 bg-white' }}">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-sm">TANDA #{{ $batch['sequence'] }}</strong>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $batch['status']['classes'] }}">{{ $batch['status']['label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $batch['time'] ? $batch['time']->setTimezone(new \DateTimeZone('America/La_Paz'))->format('d/m/Y H:i') : 'Todavía sin enviar' }}
                                    @if($batch['payment']) · Pago: <span class="font-semibold">{{ $batch['payment']['label'] }}</span> @endif
                                    @if($batch['dispatched_by'] ?? null) · Enviada por {{ $batch['dispatched_by'] }} @endif
                                </p>
                                @if($batch['cancellation_reason'] ?? null)
                                    <p class="mt-2 text-xs text-red-700">Motivo: {{ $batch['cancellation_reason'] }} · {{ $batch['cancelled_by'] }} · {{ \App\Support\UiFormatter::date($batch['cancelled_at'], true) }}</p>
                                @endif
                                @if($batch['cancellation_audit']['restored_at'] ?? null)
                                    <div class="mt-2 rounded-lg bg-emerald-50 p-2 text-xs text-emerald-800">
                                        <strong>ANULACIÓN REVERTIDA</strong> · {{ $batch['cancellation_audit']['restoration_reason'] }}
                                        · {{ $batch['cancellation_audit']['restored_by'] }} · {{ \App\Support\UiFormatter::date($batch['cancellation_audit']['restored_at'], true) }}
                                    </div>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <strong>{{ \App\Support\UiFormatter::money($batch['total']) }}</strong>
                                <span class="text-slate-400 transition group-open:rotate-180" aria-hidden="true">⌄</span>
                            </div>
                        </summary>
                        <div class="border-t border-stone-100 px-4 py-4">
                            <div class="space-y-4">
                                @foreach($batch['items'] as $item)
                                    <div class="grid grid-cols-[1fr_auto] gap-x-4 rounded-xl p-3 text-sm {{ $item['status'] === 'cancelled' ? 'bg-red-50 text-red-950' : '' }}">
                                        <div class="min-w-0">
                                            <p class="font-semibold {{ $item['status'] === 'cancelled' ? 'line-through decoration-red-400' : '' }}">{{ $item['name'] }}</p>
                                            <p class="mt-1 text-xs text-slate-500">Precio original: {{ \App\Support\UiFormatter::money($item['unit_price']) }} · Subtotal original: {{ \App\Support\UiFormatter::money($item['original_total']) }}</p>
                                            @if($item['status'] === 'cancelled')
                                                <span class="mt-2 inline-flex rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-bold text-red-700">ANULADO</span>
                                                <p class="mt-2 text-xs text-red-700">Motivo: {{ $item['cancellation_reason'] }}</p>
                                                <p class="mt-1 text-xs text-red-600">{{ $item['cancelled_by'] }} · {{ \App\Support\UiFormatter::date($item['cancelled_at'], true) }}</p>
                                            @endif
                                            @if($item['cancellation_audit']['restored_at'] ?? null)
                                                <div class="mt-2 rounded-lg bg-emerald-50 p-2 text-xs text-emerald-800">
                                                    <strong>ANULACIÓN REVERTIDA</strong> · {{ $item['cancellation_audit']['restoration_reason'] }}
                                                    · {{ $item['cancellation_audit']['restored_by'] }} · {{ \App\Support\UiFormatter::date($item['cancellation_audit']['restored_at'], true) }}
                                                </div>
                                            @endif
                                            @if(count($item['flavors']) > 1)
                                                <p class="mt-1 text-xs text-slate-500">{{ count($item['flavors']) }} sabores: {{ implode(' · ', $item['flavors']) }}</p>
                                            @elseif(count($item['flavors']) === 1)
                                                <p class="mt-1 text-xs text-slate-500">{{ $item['flavors'][0] }}</p>
                                            @endif
                                            @if($item['extras'])<p class="mt-1 text-xs text-emerald-700">Extras: {{ implode(' · ', $item['extras']) }}</p>@endif
                                            @if(\Brick\Math\BigDecimal::of($item['quantity'])->compareTo('1.000') !== 0)
                                                <p class="mt-1 text-xs text-slate-500">Cantidad: {{ \App\Support\UiFormatter::inputQuantity($item['quantity']) }}</p>
                                            @endif
                                            @if($item['notes'])<p class="mt-1 text-xs font-medium text-amber-800">Observación: {{ $item['notes'] }}</p>@endif
                                        </div>
                                        <div class="text-right">
                                            <strong class="text-sm">{{ \App\Support\UiFormatter::money($item['original_total']) }}</strong>
                                            @if($canCancelDispatchItems && ! $batch['is_current'] && $batch['status']['key'] !== 'paid' && $item['status'] !== 'cancelled')
                                                <button class="mt-2 block text-xs font-semibold text-red-700" type="button" data-partial-cancel-open data-cancel-kind="item" data-cancel-url="{{ route('orders.dispatches.items.cancel', [$order->ulid, $batch['ulid'], $item['ulid']]) }}">Anular ítem</button>
                                            @endif
                                            @if($batch['status']['key'] === 'paid' && $item['status'] !== 'cancelled')
                                                <p class="mt-2 max-w-64 text-xs font-medium text-amber-700">Esta tanda ya fue pagada. Para anular productos debes revertir la tanda completa.</p>
                                            @endif
                                            @if($canRestoreCancellations && $item['status'] === 'cancelled' && ($item['cancellation_audit'] ?? null) && ! $item['cancellation_audit']['parent_id'] && ! $item['cancellation_audit']['restored_at'])
                                                <details class="mt-2 text-left">
                                                    <summary class="cursor-pointer text-xs font-bold text-emerald-700">Deshacer anulación</summary>
                                                    <form class="mt-2 w-64 space-y-2 rounded-lg border border-emerald-200 bg-white p-3" method="POST" action="{{ route('orders.dispatches.items.restore', [$order->ulid, $batch['ulid'], $item['ulid']]) }}">
                                                        @csrf
                                                        <textarea class="input" name="reason" maxlength="500" placeholder="Motivo obligatorio" required></textarea>
                                                        <label class="flex gap-2 text-xs"><input type="checkbox" name="confirmed" value="1" required><span>Confirmo que esta anulación fue un error.</span></label>
                                                        <button class="btn-primary w-full" type="submit">Restaurar producto</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 flex justify-between border-t border-stone-200 pt-3 text-sm font-bold">
                                <span>TOTAL</span>
                                <span>{{ \App\Support\UiFormatter::money($batch['total']) }}</span>
                            </div>
                            @if($batch['payment'])
                                <div class="mt-3 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-800">
                                    <p><strong>Pago: {{ $batch['payment']['label'] }}</strong></p>
                                    @if(count($batch['payment']['methods']) > 1)
                                        <p class="mt-1">{{ collect($batch['payment']['methods'])->map(fn ($method) => $method['label'].' '.\App\Support\UiFormatter::money($method['amount']))->join(' + ') }}</p>
                                    @endif
                                </div>
                            @endif
                            @if($batch['status']['key'] === 'pending')
                                <p class="mt-3 text-xs font-semibold text-amber-700">Saldo de la tanda: {{ \App\Support\UiFormatter::money($batch['balance']) }}</p>
                            @endif
                            @if($canCancelDispatchItems && ! $batch['is_current'] && ! in_array($batch['status']['key'], ['cancelled', 'paid', 'reverted'], true))
                                <button class="btn-danger mt-4 w-full" type="button" data-partial-cancel-open data-cancel-kind="dispatch" data-cancel-url="{{ route('orders.dispatches.cancel', [$order->ulid, $batch['ulid']]) }}">Anular tanda completa</button>
                            @endif
                            @if($canReverseSettledDispatches && $batch['status']['key'] === 'paid')
                                <button class="btn-danger mt-4 w-full" type="button" data-settled-reversal-open data-reversal-sequence="{{ $batch['sequence'] }}" data-reversal-total="{{ \App\Support\UiFormatter::money($batch['total']) }}" data-reversal-url="{{ route('orders.dispatches.cancel-settled', [$order->ulid, $batch['ulid']]) }}">Revertir tanda pagada</button>
                            @endif
                            @if($canRestoreCancellations && $batch['status']['key'] === 'cancelled' && ($batch['cancellation_audit'] ?? null) && ! $batch['cancellation_audit']['restored_at'])
                                <details class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-emerald-800">Deshacer anulación de tanda</summary>
                                    <form class="mt-3 space-y-3" method="POST" action="{{ route('orders.dispatches.restore', [$order->ulid, $batch['ulid']]) }}">
                                        @csrf
                                        <textarea class="input" name="reason" maxlength="500" placeholder="Motivo obligatorio" required></textarea>
                                        <label class="flex gap-2 text-xs"><input type="checkbox" name="confirmed" value="1" required><span>Confirmo que esta tanda fue anulada por error.</span></label>
                                        <button class="btn-primary w-full" type="submit">Restaurar tanda</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </details>
                @empty
                    <div class="rounded-2xl border border-dashed border-stone-300 p-8 text-center text-sm text-slate-500">Esta cuenta todavía no tiene tandas.</div>
                @endforelse
            </div>
        </div>

        <footer class="border-t border-stone-200 bg-white px-5 py-4 text-right sm:px-6">
            <button class="btn-secondary min-w-28" type="button" data-order-history-close>Cerrar</button>
        </footer>
    </div>
</dialog>

@if($canReverseSettledDispatches && collect($history['batches'])->contains(fn ($batch) => $batch['status']['key'] === 'paid'))
<dialog class="m-auto w-[min(34rem,calc(100%_-_2rem))] rounded-3xl bg-white p-0 shadow-2xl backdrop:bg-slate-950/45" data-settled-reversal-modal aria-labelledby="settled-reversal-title">
    <form class="space-y-4 p-6" method="POST" data-settled-reversal-form>
        @csrf
        <div>
            <h2 class="text-xl font-bold" id="settled-reversal-title">Revertir tanda pagada #<span data-settled-reversal-sequence></span></h2>
            <p class="mt-2 text-sm text-slate-600">Importe de la tanda: <strong data-settled-reversal-total></strong></p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900">
            Esta operación revierte los pagos asociados, compensa la caja cuando corresponda y devuelve al inventario únicamente los productos de esta tanda. Las demás tandas permanecerán intactas.
        </div>
        <label class="block">
            <span class="label">Motivo obligatorio</span>
            <textarea class="input" name="reason" rows="3" maxlength="500" required data-settled-reversal-reason></textarea>
        </label>
        <label class="flex items-start gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-950">
            <input class="mt-1" type="checkbox" name="confirmed" value="1" required data-settled-reversal-confirmed>
            <span>Confirmo que deseo revertir por completo esta tanda pagada.</span>
        </label>
        <div class="flex justify-end gap-2">
            <button class="btn-secondary" type="button" data-settled-reversal-close>Volver</button>
            <button class="btn-danger" type="submit">Confirmar reversión</button>
        </div>
    </form>
</dialog>

<script>
    (() => {
        const modal = document.querySelector('[data-settled-reversal-modal]');
        if (! modal) return;
        const form = modal.querySelector('[data-settled-reversal-form]');
        const reason = modal.querySelector('[data-settled-reversal-reason]');
        const confirmed = modal.querySelector('[data-settled-reversal-confirmed]');
        const sequence = modal.querySelector('[data-settled-reversal-sequence]');
        const total = modal.querySelector('[data-settled-reversal-total]');
        document.querySelectorAll('[data-settled-reversal-open]').forEach((button) => button.addEventListener('click', () => {
            form.action = button.dataset.reversalUrl;
            sequence.textContent = button.dataset.reversalSequence;
            total.textContent = button.dataset.reversalTotal;
            reason.value = '';
            confirmed.checked = false;
            modal.showModal();
            reason.focus();
        }));
        modal.querySelector('[data-settled-reversal-close]').addEventListener('click', () => modal.close());
    })();
</script>
@endif

@if($canCancelDispatchItems && collect($history['batches'])->contains(fn ($batch) => ! $batch['is_current'] && $batch['status']['key'] !== 'cancelled'))
<dialog class="m-auto w-[min(32rem,calc(100%_-_2rem))] rounded-3xl bg-white p-0 shadow-2xl backdrop:bg-slate-950/45" data-partial-cancel-modal>
    <form class="space-y-4 p-6" method="POST" data-partial-cancel-form>
        @csrf
        <div>
            <h2 class="text-xl font-bold" data-partial-cancel-title>Anular producto</h2>
            <p class="mt-2 text-sm text-slate-600" data-item-cancel-message>Se anulará únicamente este producto.<br>El resto de la comanda continuará activo.</p>
            <p class="mt-2 hidden text-sm text-slate-600" data-dispatch-cancel-message>Se anularán todos los productos activos de esta tanda.<br>Las otras tandas no serán afectadas.</p>
        </div>
        <label class="block"><span class="label">Motivo obligatorio</span><textarea class="input" name="reason" rows="3" maxlength="500" required data-partial-cancel-reason></textarea></label>
        <label class="flex items-start gap-3 rounded-xl bg-red-50 p-3 text-sm text-red-900">
            <input class="mt-1" type="checkbox" name="confirmed" value="1" required>
            <span data-item-cancel-confirm>Confirmo la anulación de este ítem.</span>
            <span class="hidden" data-dispatch-cancel-confirm>Confirmo la anulación de esta tanda.</span>
        </label>
        <div class="flex justify-end gap-2">
            <button class="btn-secondary" type="button" data-partial-cancel-close>Volver</button>
            <button class="btn-danger" type="submit" data-partial-cancel-submit>Confirmar anulación</button>
        </div>
    </form>
</dialog>

<script>
    (() => {
        const modal = document.querySelector('[data-partial-cancel-modal]');
        if (! modal) return;
        const form = modal.querySelector('[data-partial-cancel-form]');
        const reason = modal.querySelector('[data-partial-cancel-reason]');
        const checkbox = modal.querySelector('input[name="confirmed"]');
        const itemMessage = modal.querySelector('[data-item-cancel-message]');
        const dispatchMessage = modal.querySelector('[data-dispatch-cancel-message]');
        const itemConfirm = modal.querySelector('[data-item-cancel-confirm]');
        const dispatchConfirm = modal.querySelector('[data-dispatch-cancel-confirm]');
        const title = modal.querySelector('[data-partial-cancel-title]');
        const submit = modal.querySelector('[data-partial-cancel-submit]');
        document.querySelectorAll('[data-partial-cancel-open]').forEach((button) => button.addEventListener('click', () => {
            const dispatch = button.dataset.cancelKind === 'dispatch';
            form.action = button.dataset.cancelUrl;
            reason.value = '';
            checkbox.checked = false;
            title.textContent = dispatch ? 'Anular tanda completa' : 'Anular producto';
            submit.textContent = dispatch ? 'Confirmar anulación de tanda' : 'Confirmar anulación';
            itemMessage.classList.toggle('hidden', dispatch);
            itemConfirm.classList.toggle('hidden', dispatch);
            dispatchMessage.classList.toggle('hidden', ! dispatch);
            dispatchConfirm.classList.toggle('hidden', ! dispatch);
            modal.showModal();
            reason.focus();
        }));
        modal.querySelector('[data-partial-cancel-close]').addEventListener('click', () => modal.close());
    })();
</script>
@endif
