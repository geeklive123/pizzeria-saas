# Efectos de las acciones existentes antes del cierre operativo

- `CancelOrderAction` exige permiso de anulación, rechaza pagos completados y determinados QR activos, libera reservas, revierte consumos de inventario existentes, cancela líneas y tandas y recalcula totales. No es una simple liberación: puede crear movimientos de reversión.
- `CancelPaidOrderAction` exige autorización para anular pagos, crea auditorías, revierte pagos y consumos, y cancela líneas y tandas. No ejecuta una devolución bancaria; sus reversiones no prueban que se devolvió dinero.
- `ReleaseInventoryReservationAction` reduce la cantidad reservada y marca la reserva liberada; no crea movimientos de inventario ni devuelve stock físico. Sus bloqueos dependen de una transacción exterior y debe invocarse una sola vez para cada disposición.
- `ConsumeInventoryReservationAction` crea movimientos de consumo y marca las reservas consumidas. No debe invocarse automáticamente para una incidencia abierta o una reserva inconsistente.
- `DynamicQrAction::check` puede marcar cancelada una intención por una consulta bancaria o por un fallo de generación sin identificador. El estado local de cancelación por sí solo no identifica la evidencia de no pagabilidad. La expiración local no confirma el estado bancario.
- La resolución de caja cerrada existente cifra el motivo y la evidencia, conserva la caja original y no crea pagos ni devoluciones. Todavía no determina el destino de la orden ni de las reservas.

Estas observaciones no certifican la seguridad de una disposición nueva. Cualquier procedimiento debe bloquear y verificar los vínculos de empresa, sucursal, caja, orden e intención y registrar el resultado bajo la misma transacción que sus efectos.

## Disposición administrativa en implementación

La acción nueva registra una sola disposición por operación e intención. Regularización y devolución documentan una acción externa y conservan el estado operativo y las reservas; no registran una venta ni consumen inventario. Una segunda acción administrativa permite documentar una entrega física ya realizada, verifica las reservas y registra su consumo una sola vez sin crear un pago POS. La evidencia de entrega y su resultado se conservan en un registro separado e inmutable. No marca artificialmente pagada la orden ni crea una impresión.

La cancelación administrativa online está bloqueada: la documentación interna identifica `9` como anulado, pero no acredita que no pueda existir un pago en tránsito o una liquidación tardía. El conciliador sigue vigilando anulaciones por posibles abonos tardíos. Antes de habilitar liberación se requiere confirmación bancaria verificable sobre finalización de la aceptación, pagos en tránsito, liquidaciones tardías y el medio para certificar ausencia de cobros. El vencimiento local no es evidencia de no pagabilidad. La exclusión con consultas bancarias usa el lock de caché existente, que debe ser compartido entre procesos.

Una devolución documentada con pedido no entregado conserva sus reservas mientras no haya prueba suficiente de no pagabilidad. No se debe tratar ese registro como cierre operativo. Una devolución con entrega física acreditada puede registrar el consumo mediante la segunda acción, sin revertirlo ni generar un reembolso bancario.

Para habilitar la cancelación debe solicitarse al banco, por un canal verificable y para la versión y ambiente contratados: (1) si `9` impide definitivamente nuevas autorizaciones para el `qrId`; (2) qué ocurre con pagos iniciados antes de la anulación, si pueden liquidarse después y durante cuánto tiempo; (3) cómo consultar o certificar que no existen pagos en tránsito ni abonos tardíos, identificando QR, comercio, cuenta y fecha; y (4) qué comprobante, latencia de consistencia y procedimiento de reconciliación deben conservarse. La etiqueta «anulado» y una respuesta simulada no acreditan por sí solas esas garantías. Hasta resolverlo, la acción rechaza la cancelación y no libera reservas.

Las pruebas con SQLite y banco simulado no sustituyen una prueba de concurrencia InnoDB ni demuestran semántica del banco real. La migración y el flujo no deben considerarse listos para producción hasta completar las pruebas y la revisión operativa del sprint.
