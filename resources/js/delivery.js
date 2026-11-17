import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export function initDelivery(form) {
    const panel = document.querySelector('[data-delivery-fields]');
    if (!panel) return null;
    const address = panel.querySelector('[data-delivery-address]');
    const suggestions = panel.querySelector('[data-delivery-suggestions]');
    const status = panel.querySelector('[data-delivery-status]');
    const costWarning = panel.querySelector('[data-delivery-cost-warning]');
    const reference = form.elements.namedItem('reference');
    const confirm = panel.querySelector('[data-delivery-confirm]');
    const locate = panel.querySelector('[data-delivery-geolocate]');
    const origin = [Number(panel.dataset.latitude), Number(panel.dataset.longitude)];
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
    let map, marker, point, confirmed = false, distance = null, request, coverageRequest, timer;
    const invalidate = () => { confirmed = false; confirm.textContent = 'Confirmar punto de entrega'; };
    const select = async (lat, lon) => {
        form.dispatchEvent?.(new Event('storefront:location-changed'));
        point = [lat, lon]; distance = null; invalidate(); confirm.disabled = true; costWarning.hidden = true;
        if (marker) marker.setLatLng(point); else {
            marker = L.marker(point, { draggable: true, icon: L.divIcon({ className: 'delivery-marker', html: '<span></span>', iconSize: [28, 28], iconAnchor: [14, 28] }) }).addTo(map);
            marker.on('dragend', () => { const p = marker.getLatLng(); select(p.lat, p.lng); });
        }
        coverageRequest?.abort(); coverageRequest = new AbortController();
        try {
            const response = await fetch(panel.dataset.coverageUrl, { method: 'POST', headers, signal: coverageRequest.signal, body: JSON.stringify({ latitude: lat, longitude: lon }) });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (point[0] !== lat || point[1] !== lon) return;
            distance = result; confirm.disabled = result.coverage !== 'free';
            costWarning.hidden = result.coverage === 'free';
            status.textContent = `${Math.round(result.distance_m)} m en línea recta · ${result.coverage === 'free' ? 'Delivery gratuito disponible · Bs 0,00' : 'Fuera de cobertura'}.`;
        } catch (error) { if (error.name !== 'AbortError') status.textContent = 'No se pudo calcular la cobertura. Selecciona el punto nuevamente.'; }
    };
    const open = () => {
        const active = form.querySelector('input[name="fulfillment"]:checked')?.value === 'delivery';
        panel.hidden = !active; address.required = active; reference.required = active;
        const label = document.querySelector('[data-checkout-mode-label]');
        if (label) label.textContent = active ? '2 Datos de delivery' : '2 Datos de recojo';
        const note = document.querySelector('[data-preparation-note]');
        if (note && form.dataset.preparationEnabled === 'true') note.hidden = !active;
        const submit = document.querySelector('[data-submit-order]');
        if (submit && form.dataset.preparationEnabled === 'true') submit.textContent = active ? 'Preparar pedido' : 'Realizar pedido';
        const legal = document.querySelector('[data-checkout-legal-note]');
        if (legal && form.dataset.preparationEnabled === 'true') legal.textContent = active
            ? (form.dataset.onlineQrEnabled === 'true'
                ? 'Al preparar el QR se reserva inventario. El pago requiere confirmación bancaria.'
                : 'La preparación no confirma el pedido, no registra pagos ni reserva inventario.')
            : 'El restaurante revisará tu solicitud. No se registra un pago ni se descuenta inventario.';
        if (active && !map) {
            map = L.map(panel.querySelector('[data-delivery-map]')).setView(origin, 15);
            L.tileLayer(panel.dataset.tileUrl, { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
            L.circle(origin, { radius: Number(panel.dataset.radius), color: '#a33a25', fillOpacity: 0.08 }).addTo(map);
            L.circleMarker(origin, { radius: 7, color: '#70251c', fillOpacity: 1 }).addTo(map).bindPopup('Masa y Maña');
            map.on('click', (event) => select(event.latlng.lat, event.latlng.lng));
        }
        if (active) setTimeout(() => map.invalidateSize(), 0);
    };
    form.querySelectorAll('input[name="fulfillment"]').forEach((input) => input.addEventListener('change', open));
    address.addEventListener('input', () => {
        invalidate(); request?.abort(); clearTimeout(timer); suggestions.replaceChildren();
        const query = address.value.trim(); if (query.length < 3) return;
        timer = setTimeout(async () => {
            request = new AbortController();
            try {
                const response = await fetch(panel.dataset.searchUrl, { method: 'POST', headers, signal: request.signal, body: JSON.stringify({ query }) });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (address.value.trim() !== query) return;
                for (const item of data.results) {
                    const li = document.createElement('li'), button = document.createElement('button');
                    button.type = 'button'; button.textContent = item.label;
                    button.addEventListener('click', () => { address.value = item.label; suggestions.replaceChildren(); map.setView([item.latitude, item.longitude], 17); select(item.latitude, item.longitude); });
                    li.append(button); suggestions.append(li);
                }
            } catch (error) { if (error.name !== 'AbortError') status.textContent = 'Búsqueda no disponible. Elige el punto en el mapa.'; }
        }, 450);
    });
    locate.addEventListener('click', () => {
        if (!navigator.geolocation) { status.textContent = 'Ubicación no disponible. Elige el punto en el mapa.'; return; }
        navigator.geolocation.getCurrentPosition(({ coords }) => { map.setView([coords.latitude, coords.longitude], 17); select(coords.latitude, coords.longitude); }, () => { status.textContent = 'No se pudo obtener tu ubicación. Elige el punto en el mapa.'; }, { timeout: 10000, maximumAge: 60000 });
    });
    confirm.addEventListener('click', () => { if (point && distance) { confirmed = true; confirm.textContent = 'Punto confirmado'; } });
    open();
    return {
        payload() {
            if (form.querySelector('input[name="fulfillment"]:checked')?.value !== 'delivery') return { fulfillment: 'pickup' };
            if (!confirmed || !point || !address.value.trim()) { status.textContent = 'Indica la dirección y confirma el punto de entrega.'; return null; }
            if (distance.coverage !== 'free') { status.textContent = 'La ubicación está fuera de cobertura. Cambia el punto o elige recojo en local.'; return null; }
            return { fulfillment: 'delivery', address: address.value.trim(), reference: reference.value.trim(), latitude: point[0], longitude: point[1], location_confirmed: true };
        },
    };
}
