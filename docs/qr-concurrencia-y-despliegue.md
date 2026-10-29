# QR: concurrencia y preparación de despliegue

## Ejecución aislada en MariaDB/MySQL local

La batería `tests/Concurrency/run_qr_mysql.php` requiere el PHP del proyecto y acceso explícitamente autorizado a un servidor TCP local. Crea una base nueva de nombre aleatorio `pizzeria_qr_test_...`, verifica que estaba vacía y aplica allí las migraciones; no ejecuta `migrate:fresh` ni borra bases. Arranca Laravel con SQLite en memoria, rechaza caché de configuración, DB_URL, sockets y direcciones distintas de loopback literal, y abre una conexión administrativa sin base seleccionada para crear la base de prueba. Cada proceso hijo valida el nombre y el marcador aleatorio antes de trabajar en ella. La suite PHPUnit sigue usando SQLite en memoria.

Solo tras autorizar la creación de la base, desde la raíz:

```powershell
& 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe' tests/Concurrency/run_qr_mysql.php --create-isolated-database
```

La salida JSON registra la base creada, las marcas de tiempo y el resultado de cada uno de dos procesos PHP independientes. El ejecutor comprueba solapamiento, InnoDB, pagos, movimientos, liberación de tanda e impresión. `Http::preventStrayRequests()` y `Http::fake()` bloquean el banco; las credenciales de las operaciones de prueba son ficticias. Las bases y las barreras temporales de prueba se conservan; cualquier eliminación debe autorizarse por separado.

La ejecución local final exitosa utilizó MariaDB 10.4.32 y la base nueva `pizzeria_qr_test_73f453a4738c`. Cubrió dos confirmaciones, consulta frente a job, generación por dos cajeros, confirmación frente a efectivo, cancelación frente a confirmación, dos comandos de conciliación simultáneos, mixto con doble envío y dos cajeros, mixto por tanda y confirmación frente a job. Cada carrera verificó un solo QR y/o los pagos esperados sin duplicar caja, inventario ni impresión. También se provocó un deadlock real y se verificó un reintento de una transacción externa que invoca `RegisterPaymentAction`, con un solo pago y movimiento de caja.

Bases de prueba conservadas durante esta sesión: `pizzeria_qr_test_2a30f1ae780c`, `pizzeria_qr_test_a0d52728386a`, `pizzeria_qr_test_74bc2e93753e`, `pizzeria_qr_test_486220838f63`, `pizzeria_qr_test_1f86758fe12d` y `pizzeria_qr_test_73f453a4738c`. La primera contiene una ejecución interrumpida por una aserción del propio verificador sobre movimientos de apertura; no se reutilizó. Su eliminación no está autorizada por esta batería.

Límites: los dos comandos de conciliación en paralelo no prueban el mutex del proceso real `schedule:run`; el fake devolvió pago en la carrera contra cancelación, no todas las respuestas ni órdenes posibles. La reconexión se simuló desconectando antes de la consulta, no durante un commit. El deadlock del cobro se provocó dentro de una transacción exterior explícita con tres intentos: no demuestra todos los patrones de deadlock reales. Las pruebas por tanda no contienen reservas de inventario reales. El comportamiento con diferentes procesos, versiones y carga de producción requiere ensayos adicionales. Ninguna de estas pruebas prueba la acreditación bancaria ni el alcance global de `transactionId`.

## Checklist previo a un despliegue posterior

- Revisar código, respaldo recuperable, versión exacta de PHP y extensiones, esquema de destino y un plan de reversión que conserve pagos, caja e inventario. El PHP CLI local verificado es 8.4.24; la versión de SiteGround no se inspeccionó.
- En la base local operativa figuraban pendientes `2026_10_28_170000_remove_dynamic_qr_expiry_auto_update` y `2026_10_28_180000_create_bank_qr_deposit_evidence`. Verificar por separado el destino; nunca asumir su estado ni ejecutar migraciones sin respaldo y autorización. La primera protege vencimientos futuros, la segunda habilita registrar evidencia de abono; no corrigen automáticamente estados históricos.
- Confirmar almacenamiento de caché compartido con locks atómicos entre web, scheduler y workers. Usar una cola duradera; con la cola de base de datos, `DB_QUEUE_RETRY_AFTER` debe superar el timeout de 90 segundos y el lock de 120 segundos. Supervisar `failed_jobs`, retrasos, logs sanitizados y reconciliaciones.
- Con popup abierto el navegador consulta cada ~6,5 segundos; con popup cerrado la recuperación depende de cron, scheduler y ejecución de cola. La cadencia configurada por Laravel no garantiza ejecución si cron o worker no están activos. SiteGround recomienda intervalos de al menos 30 minutos en hosting compartido: confirmar el plan y capacidad antes de fijar el SLA. [Política de uso razonable](https://www.siteground.com/kb/fair-use-siteground-hosting/), [Cron en Site Tools](https://www.siteground.com/kb/manage-cron-jobs/).
- Preparar solo para revisión: `php artisan schedule:run` mediante cron y un worker acotado compatible con el plan; sin worker persistente, estudiar `baneco:reconcile --inline` con exclusión externa y límites de ejecución. No programar ambos caminos simultáneamente. Medir carga y latencia reales antes de habilitar cobros.
- Confirmar con Banco Económico contrato de `transactionId`, idempotencia, estado, límites y liquidación; el QR ID está protegido por unicidad, pero no existe restricción global del identificador de transacción bancaria. No equiparar pago confirmado por API con abono en cuenta.
- Probar permisos, cambio de cajero y turno, efectivo parcial con fallo o cancelación QR, cierre por tanda y al final, reportes, impresión y recuperación con navegador cerrado en un entorno aislado. Acordar el procedimiento auditado de devolución de efectivo y conciliación de incidencias inciertas.
