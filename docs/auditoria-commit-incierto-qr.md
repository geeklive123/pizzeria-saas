# Auditoría focalizada: commit incierto de QR

Esta auditoría se basa en el código, no en una desconexión real durante COMMIT.

Actualización: `DynamicQrAction::generate` intenta recuperar el intento mixto tras un fallo compatible con pérdida de conexión en su transacción exterior. Reconecta y comprueba la clave del intento, el contexto, el efectivo y su movimiento; si no puede comprobarlo, devuelve incertidumbre. No reenvía la solicitud bancaria. Una inyección desde `PDO::commit()` con commit y rollback reales pasó en `pizzeria_qr_test_46a683bfa2e2`. El intento recuperado puede permanecer en `creating`: esta implementación no demuestra ni reanuda automáticamente un envío bancario. La recuperación de la confirmación QR, el bloqueo durable de inconsistencias y el comportamiento con la base inaccesible siguen pendientes de validación e implementación.

## Pruebas aisladas añadidas

Ejecutar `php tests/Concurrency/commit_uncertain_mysql.php --create-isolated-database` con el PHP del proyecto. Crea una base nueva y la conserva. La ejecución `pizzeria_qr_test_5661c7f03803` pasó en InnoDB: rollback antes del commit (0 pagos, 0 movimientos, saldo 80), reintento posterior (1 pago, 1 movimiento, saldo 50), respuesta simulada perdida tras operación mixta (1 pago, 1 movimiento, saldo 50, una generación simulada) y respuesta simulada perdida tras confirmación QR (2 pagos, 1 movimiento, saldo 0, una generación simulada). Una conexión PDO independiente observó los datos. La ejecución previa `pizzeria_qr_test_c456cfe69859` también se conserva.

Las excepciones se inyectan después del retorno de las acciones; se desconecta y reconecta la conexión Laravel antes de reintentar. No se interrumpe COMMIT ni se reproduce una respuesta realmente indeterminada; tampoco se verifica recuperación automática mientras la base no está disponible. Esos escenarios permanecen pendientes. Las pruebas no prueban reservas reales ni acceso simultáneo.

- La creación del intento y el pago de efectivo mixto comparten la transacción exterior. El llamado bancario ocurre después del commit. La clave del efectivo es `bqcash:` más el SHA-1 de la clave del intento.
- La confirmación QR registra el Payment, sus efectos y el vínculo `payment_id` dentro de la misma transacción exterior. El Payment usa la clave `baneco:` más el ULID del intento.
- Los índices únicos por empresa y clave de idempotencia, el QR ID, el Payment vinculado y el marcador de pedido activo reducen duplicaciones; no demuestran por sí solos recuperación correcta tras perder la respuesta de COMMIT.
- Si se confirma un rollback completo, no persisten el intento y el efectivo de esa transacción, o bien no persisten el pago QR y su vínculo. Un reintento con la misma clave puede usar el flujo existente; debe comprobarse con un fallo reproducible.
- Si el servidor confirma pero PHP pierde la respuesta de COMMIT, Laravel propaga la excepción de conexión. No existe aquí un protocolo explícito que reconecte, consulte todas las relaciones y distinga el resultado. Una lectura fallida o un resultado desconocido no demuestra rollback.
- Si se confirmó la creación mixta, el reintento con la misma clave encuentra el intento y valida el efectivo. Si quedó `creating`, no vuelve a generar el QR; el scheduler lo marca `generation_unknown` tras tres minutos. El efectivo puede estar registrado aunque la interfaz haya recibido error, y requiere revisión. El mismo estado también puede aparecer después de una solicitud bancaria realmente iniciada: no debe interpretarse como prueba de que nunca se llamó al banco.
- Si se confirmó el pago QR, el reintento de consulta recupera el intento pagado; si no se confirmó, el intento permanece activo para una consulta posterior. Esto depende de que la base esté disponible y de que el commit atómico se haya recuperado correctamente; no se ha ensayado el corte real en COMMIT.
- `RegisterPaymentAction` captura `QueryException`, pero un fallo PDO al hacer commit puede propagarse como otra excepción. Su consulta de duplicados tampoco constituye una estrategia explícita de reconexión y verificación de todas las relaciones financieras.

## Pruebas pendientes

En una base nueva aislada InnoDB, usar dos procesos PHP y una interrupción controlada de la conexión que permita registrar cuándo el servidor recibió COMMIT y cuándo el cliente perdió su respuesta. Comprobar por una conexión independiente Payment, movimiento de caja, intento, QR ID, estado, importe, sucursal, cierre y efectos de cocina. Cubrir rollback confirmado, commit confirmado con respuesta perdida, dos recuperaciones simultáneas y efectivo mixto. Una excepción inyectada antes o después de COMMIT solo prueba el caso simulado, no una pérdida real de respuesta.

Hasta disponer de esas evidencias, no asumir que un error implica rollback, no registrar de nuevo el efectivo ni generar otro QR para la misma obligación, y revisar el intento y sus relaciones con la base disponible. La recuperación añadida se limita a la preparación del intento mixto y no resuelve todos los escenarios de commit incierto.
