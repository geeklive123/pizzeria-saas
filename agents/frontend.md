# Guía del agente frontend

Aplica esta guía a Blade, CSS, JavaScript, Vite y futuras interfaces del sistema.

## Alcance actual

- La UI operativa incluye Venta, mesas administrables, usuarios, POS, cocina, caja, cobro, gastos y reportes. No avances al Sprint 10, facturación, delivery, impresión, PWA/offline ni otro módulo sin autorización expresa.
- No crees pantallas o endpoints de demostración fuera del sprint solicitado.
- Reutiliza el backend y las Policies existentes; no replique reglas de dominio en JavaScript.

## Arquitectura de interfaz

- Mantén la interfaz dentro del monolito Laravel y de su toolchain existente.
- Los controladores deben seguir delgados y delegar lógica a Actions o Services.
- Toda petición de negocio debe conservar el contexto de empresa y sucursal; nunca confíes en un `company_id` enviado por el cliente sin validación servidor.
- Usa ULID públicos en URLs y payloads cuando la entidad ya siga ese patrón; no expongas IDs internos innecesariamente.
- Trata dinero y cantidades como strings decimales en formularios y payloads; no uses aritmética de punto flotante en el navegador.
- En el POS, muestra disponibilidad calculada por el servidor y mensajes de stock insuficiente; JavaScript solo filtra el catálogo.
- Distingue claramente cancelar una cuenta de cobrarla o cerrarla financieramente.
- Muestra pago mixto como líneas separadas, QR fuera del efectivo esperado y vuelto como información del pago, no como gasto.
- Explica que las compras con entrada de stock pertenecen a `Purchase`; los gastos son costos operativos. CASH exige caja abierta y QR/transferencia no afectan efectivo físico.
- No calcules dinero con `Number`, `parseFloat` ni otra aritmética binaria en JavaScript; el servidor es la autoridad decimal.
- El configurador de pizza ofrece 1, 2, 3 o 4 sabores, tamaño compatible, fracciones, extras/removidos, observación y fulfillment.
- El cliente solo organiza campos del configurador; fracciones, precio, disponibilidad y reservas se validan nuevamente en servidor.
- POS y KDS muestran la composición desde snapshots; KDS nunca muestra precios.
- Los reportes comparten selector de rango y muestran claramente qué cifras son estimadas. Sus tabs, filtros y exportaciones deben respetar permisos financieros y el contexto activo.
- En pizzas fusionadas, muestra unidades completas una sola vez y sabores de forma proporcional a sus fracciones.
- Venta es la entrada principal: presenta En mesa y Para llevar, abre una cuenta libre o reutiliza la ocupada y continúa en el POS existente.
- Mesas muestra estados derivados y ofrece configuración solo a owner/admin; los roles operativos reciben orientación cuando faltan mesas.
- Usuarios permite alta y edición administrativa sin mostrar contraseñas existentes ni introducir recuperación o invitaciones por correo.

## Calidad

- Conserva accesibilidad básica: etiquetas, foco visible, navegación por teclado, contraste y mensajes de error asociados a sus campos.
- Diseña vistas responsivas sin introducir frameworks o dependencias nuevas sin autorización.
- Evita estado duplicado y lógica de inventario en el cliente; el servidor es la autoridad.
- No implementes Service Worker, IndexedDB, sincronización offline o PWA hasta que el sprint correspondiente sea autorizado.
- Cuando haya cambios PHP o de integración, sigue [testing.md](testing.md); añade pruebas frontend solo si existe infraestructura adecuada en el proyecto.
