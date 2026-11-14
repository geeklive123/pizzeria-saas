# Conversión online al POS: borrador no habilitado

La implementación está en revisión. `STOREFRONT_ONLINE_POS_CONVERSION_ENABLED` está desactivada por defecto y no debe activarse hasta completar las pruebas y resolver los riesgos señalados abajo. Con la bandera desactivada, confirmar una solicitud devuelve un aviso y la conserva pendiente; cancelarla sigue el flujo existente.

Con la bandera activada, la confirmación propuesta bloquea la solicitud en una transacción, crea una orden para llevar, agrega las líneas mediante acciones POS, reserva los insumos correspondientes y guarda el vínculo y el usuario. Si falla la operación, la transacción debe revertir la orden y las reservas. Un reintento de una solicitud ya resuelta muestra el vínculo guardado. No se crea pago ni movimiento de caja al confirmar.

El personal debe enviar después la orden a cocina desde el POS. El flujo habitual para llevar puede consumir las reservas al liberar la comanda, antes del cobro, y solicita impresión según la configuración existente. El pago se registra posteriormente en el checkout POS, con una sesión de caja y las validaciones correspondientes.

La migración aditiva `2026_11_14_130000_link_online_requests_to_orders.php` agrega `pos_order_id`, `converted_by` y `converted_at`, un índice único de orden vinculada y una clave foránea con empresa y sucursal. No ejecutarla en la base restaurada ni en producción sin autorización y un plan revisado. La reversión operativa prevista es apagar la bandera y conservar la relación, las órdenes y las reservas; no ejecutar la reversión de la migración sobre datos convertidos.

La migración aditiva `2026_11_14_140000_add_online_item_promotion_snapshot.php` agrega `was_promotion` nullable a las líneas. Las solicitudes nuevas guardan esa clasificación. Las líneas anteriores quedan sin clasificación y la conversión se rechaza: no existe información suficiente para inferirla de forma segura si cambió el catálogo. Se necesita un procedimiento de revisión para esas solicitudes.

La migración `2026_11_14_150000_add_online_legacy_review.php` agrega el resultado, una nota, el responsable y la fecha de revisión a la solicitud. No clasifica ni modifica líneas antiguas.

## Revisión de solicitudes antiguas

1. Abrir Pedidos online y localizar las solicitudes pendientes marcadas «Requiere revisión histórica».
2. Revisar los productos y el total original; contactar al cliente mediante el botón de WhatsApp. El envío del mensaje es una acción manual del personal.
3. Si el cliente desea continuar, indicarle que deberá enviar una nueva solicitud para confirmar el detalle y los precios vigentes. No modificar el importe de la solicitud antigua ni inventar su composición.
4. Registrar una nota sin datos sensibles, confirmar que se contactó al cliente y elegir «Registrar revisión y cancelar solicitud». Se conserva el detalle, el precio original, la nota, el usuario y la fecha; no se crea orden POS, pago ni reserva.
5. Si no se ha contactado al cliente, dejar la solicitud pendiente. Solo personal autorizado para cancelar puede registrar esta resolución. Una revisión repetida no sustituye el primer resultado.

Pendientes antes de habilitar:

- Las solicitudes actuales no registran la composición histórica de promociones; el borrador bloquea su conversión. La resolución de una solicitud antigua sin clasificación se limita a cancelarla tras contactar al cliente; no habilita la conversión de promociones.
- El control comercial usa el vínculo persistente de la solicitud para impedir altas, ediciones, cancelaciones parciales, descuentos y recargos. Se conservan los flujos de cancelación operativa completa y sus reversiones. Falta un proceso de corrección autorizado y auditado con aceptación del cliente para modificar pedidos ya acordados.
- En SQLite aislado se probó el recorrido online hasta POS con reserva y consumo de inventario, despacho lógico, pagos parciales, saldo y reporte, además de la cancelación y restauración operativas y el rollback con varias líneas. No se conectó al banco ni se imprimió físicamente. Las pruebas no sustituyen la comprobación de la operación real y sus periféricos.
- Las cuatro migraciones se aplicaron a MariaDB 10.4 aislado. Pasaron 15 carreras de creación, 12 de transición, una carrera por un ingrediente compartido y una revisión histórica concurrente; también se verificó un reintento de confirmación tras una respuesta supuestamente perdida. Sigue pendiente repetir las pruebas con MySQL 8 y ampliar la comprobación de recuperación de respuestas inciertas.
- Las solicitudes confirmadas antes de esta funcionalidad no tienen orden vinculada; no se convierten automáticamente y requieren un plan específico.
