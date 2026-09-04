<div class='page-heading mt-8'><div><h2>Pedidos pagados</h2><p>Historial disponible para consulta y reimpresión.</p></div></div>
<div class='card'><div class='table-wrap'><table>
<thead><tr><th>Pedido</th><th>Atención</th><th>Cierre</th><th>Total</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($paidOrders as $order)
<tr>
<td class='font-semibold'><a class='link' href='{{ route('orders.show',$order->ulid) }}'>{{ $order->formattedNumber() }}</a></td>
<td>{{ $order->restaurantTable?->name ?? 'Para llevar' }}@if($order->customer_name)<p class='text-xs text-stone-500'>{{ $order->customer_name }}</p>@endif</td>
<td>{{ \App\Support\UiFormatter::date($order->closed_at ?? $order->opened_at,true) }}</td>
<td class='font-semibold'>{{ \App\Support\UiFormatter::money($order->total) }}</td>
<td><span class='rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700'>Pagado</span></td>
<td><div class='flex flex-wrap justify-end gap-2'>
@can('reprintKitchen',$order)<form method='POST' action='{{ route('orders.reprint.kitchen',$order->ulid) }}'>@csrf<button class='btn-secondary'>Reimprimir cocina</button></form>@endcan
@can('reprintCustomerTicket',$order)<form method='POST' action='{{ route('orders.reprint.ticket',$order->ulid) }}'>@csrf<button class='btn-secondary'>Reimprimir ticket cliente</button></form>@endcan
</div></td>
</tr>
@empty<tr><td colspan='6'><div class='empty-state'>Todavía no hay pedidos pagados.</div></td></tr>@endforelse
</tbody></table></div>
@if($paidOrders->hasPages())<div class='border-t border-stone-100 p-4'>{{ $paidOrders->links() }}</div>@endif
</div>
