<dialog class="m-auto max-h-[85vh] w-[min(52rem,calc(100%_-_2rem))] overflow-hidden rounded-3xl bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/45" data-order-history-modal aria-labelledby="order-history-title">
    <div class="flex max-h-[85vh] flex-col">
        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-stone-200 bg-white px-5 py-4 sm:px-6">
            <div>
                <h2 class="text-lg font-bold tracking-tight" id="order-history-title">HISTORIAL DE LA CUENTA</h2>
                <p class="mt-1 text-sm text-slate-500">{{ str($order->restaurantTable?->name ?? 'Para llevar')->ucfirst() }} · <span class="font-semibold text-orange-600">{{ $order->formattedNumber() }}</span></p>
            </div>
            <button class="grid size-10 shrink-0 place-items-center rounded-xl border border-stone-200 text-xl text-slate-500 hover:bg-stone-50 hover:text-slate-900" type="button" data-order-history-close aria-label="Cerrar historial">×</button>
        </header>

        <div class="overflow-y-auto p-4 sm:p-6">
            <dl class="grid grid-cols-2 gap-3 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-slate-500">Tandas totales</dt><dd class="mt-1 font-bold">{{ $history['summary']['total_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pagadas</dt><dd class="mt-1 font-bold text-emerald-700">{{ $history['summary']['paid_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pendientes</dt><dd class="mt-1 font-bold text-amber-700">{{ $history['summary']['pending_batches'] }}</dd></div>
                <div><dt class="text-xs text-slate-500">Total consumido</dt><dd class="mt-1 font-bold">{{ \App\Support\UiFormatter::money($history['summary']['total']) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Total pagado</dt><dd class="mt-1 font-bold">{{ \App\Support\UiFormatter::money($history['summary']['paid']) }}</dd></div>
                <div><dt class="text-xs text-slate-500">Saldo pendiente</dt><dd class="mt-1 font-bold text-orange-700">{{ \App\Support\UiFormatter::money($history['summary']['balance']) }}</dd></div>
            </dl>

            <div class="mt-5 space-y-3">
                @forelse($history['batches'] as $batch)
                    <details class="group overflow-hidden rounded-2xl border border-stone-200 bg-white">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-sm">TANDA #{{ $batch['sequence'] }}</strong>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ $batch['status']['classes'] }}">{{ $batch['status']['label'] }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $batch['time'] ? $batch['time']->setTimezone(new \DateTimeZone('America/La_Paz'))->format('d/m/Y H:i') : 'Todavía sin enviar' }}
                                    @if($batch['payment']) · Pago: <span class="font-semibold">{{ $batch['payment']['label'] }}</span> @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <strong>{{ \App\Support\UiFormatter::money($batch['total']) }}</strong>
                                <span class="text-slate-400 transition group-open:rotate-180" aria-hidden="true">⌄</span>
                            </div>
                        </summary>
                        <div class="border-t border-stone-100 px-4 py-4">
                            <div class="space-y-4">
                                @foreach($batch['items'] as $item)
                                    <div class="grid grid-cols-[1fr_auto] gap-x-4 text-sm">
                                        <div class="min-w-0">
                                            <p class="font-semibold">{{ $item['name'] }}</p>
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
                                        <strong class="text-sm">{{ \App\Support\UiFormatter::money($item['total']) }}</strong>
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
