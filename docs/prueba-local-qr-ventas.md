# Prueba controlada de QR en ventas locales

Esta prueba realiza un pago bancario real y registra una venta real en la base configurada. Usar únicamente un entorno local aislado y una cuenta bancaria autorizada; verificar antes la base y hacer un respaldo. No usar pedidos históricos ni una base de producción. La prueba debe ser realizada manualmente por un responsable autorizado.

## Preparación

- Confirmar empresa, sucursal, moneda BOB y configuración bancaria activa; comprobar que corresponden a la cuenta receptora prevista. Mantener credenciales fuera de capturas y registros.
- Verificar las migraciones necesarias con `php artisan migrate:status`. No aplicar migraciones sin respaldo y autorización expresa.
- Verificar en el entorno local la configuración `BANECO_ENABLED` y su caché antes de habilitarla localmente. No habilitarla en producción. Acordar importe pequeño, responsable, conciliación y tratamiento contable de la prueba.
- Confirmar que el caché utilizado proporciona bloqueos compartidos entre todos los procesos que consultarán el mismo intento, y que scheduler y worker estén configurados según el entorno. No lanzar conciliaciones automáticas sin conocer qué operaciones existentes consultarán.
- Abrir un turno de prueba y preparar un pedido nuevo para cada modalidad a verificar. Anotar saldo, caja, pedido y tanda antes de cobrar; los flujos normales de venta pueden afectar inventario, por lo que deben ejecutarse en el entorno aislado.

## Ejecución manual

1. En checkout seleccionar Banco Económico y generar el QR por el saldo. Comprobar imagen legible, importe y cuenta receptora en la aplicación bancaria antes de pagar.
2. Antes de pagar, consultar estado y confirmar que el pedido y la tanda sigan pendientes y que no exista pago QR completado.
3. Pagar una sola vez con el teléfono y consultar el estado hasta recibir confirmación. Verificar una sola línea de pago QR, importe y moneda correctos y cierre del pedido solo cuando corresponda. Repetir la consulta y confirmar que no aparece un segundo pago.
4. Para `at_end`, comprobar que la mesa se libera únicamente al quedar pagada la cuenta. Para `per_batch`, comprobar que se liquida la tanda correcta y que la cuenta permanece abierta cuando corresponde.
5. Para efectivo + QR, registrar un efectivo parcial acordado y verificar que el QR sea por el saldo, que existan líneas separadas y que solo el efectivo afecte caja. El efectivo se registra antes de la respuesta bancaria y se conserva si la generación falla: no volver a ingresarlo.
6. En una operación separada sin pagar, solicitar cancelación y verificar que el banco confirme el estado cancelado antes de generar otra referencia. No usar vencimiento local como prueba de cancelación.
7. Conciliar importe, referencia y QR ID con la evidencia bancaria y la sesión de prueba. Registrar cualquier incidencia para seguimiento sin borrar ni modificar el historial.

Si hay timeout, resultado incierto, estado inesperado o diferencia de importe, moneda o identificador, detener el cobro de ese pedido y conciliar con el banco. No generar otro QR ni volver a registrar efectivo para el mismo intento sin resolverlo.

## Antes de ampliar el uso

Confirmar con el banco el contrato efectivo de estados y datos de pago, realizar pruebas de concurrencia en una base MySQL/InnoDB aislada y verificar recuperación ante caídas, cancelaciones y pagos tardíos. SQLite y los dobles HTTP no prueban esas condiciones reales. Mantener la activación limitada a localhost hasta completar esta validación y acordar el procedimiento operativo.
