# Sprint 3.4: auditoría previa al banco QR online

Base examinada: `acce09092db516a75cd19303cf2b9b4ab41a751f`. Actualizada durante Sprint 3.4. Este trabajo no activa pagos, no modifica el POS ni autoriza despliegues. El contrato bancario disponible no define el ámbito de unicidad del identificador de pago; la integración real sigue bloqueada.

## Referencias y worktrees

- El worktree actual está en `feature/storefront-dynamic-qr-sprint3-3`, en `acce090`; su referencia remota local tiene el mismo commit. Hay archivos temporales y builds sin seguimiento que deben conservarse.
- `0e6f92e` es el commit indicado como desplegado por el propietario; no se ha verificado SiteGround. Es padre directo de `acce090`: un commit y 68 archivos de diferencia.
- La referencia local `origin/main` apunta a `a777125`: es ancestro de `0e6f92e` y `acce090`. Está 17 commits detrás del primero y 18 detrás del segundo. `origin/main..0e6f92e` cambia 231 archivos y `origin/main..acce090`, 272. El árbol de `acce090` frente a `0e6f92e` cambia 68 archivos.
- `main` local apunta a `5d958e9` y diverge de la referencia local `origin/main` (cinco commits delante y diez detrás). Se observaron 11 worktrees; no se cambió ninguno. Estas son referencias locales sin `fetch`: no prueban el estado remoto actual.

## Compatibilidad y bloqueos para el banco real

El flujo de prueba comparte `BankQrEmitter` y `BancoEconomicoGateway` con el POS: se arma una intención persistida antes del envío, se serializa por intención y una generación incierta queda en revisión. El conciliador comprueba el estado bancario antes de registrar el pago; valida QR, moneda e importe, y conserva evidencia. El webhook existente es una señal limitada por frecuencia y no autentica el contenido como prueba de pago. El contexto online verifica caja, empresa, sucursal, identidad de sistema y reservas. El POS continúa exigiendo el titular real de caja en su ruta ordinaria.

La compatibilidad efectiva con la API y el contrato real no está probada. La demostración local solo utiliza respuestas simuladas. Los siguientes controles impiden intencionadamente el uso real:

1. `STOREFRONT_ONLINE_DYNAMIC_QR_ENABLED` y `STOREFRONT_DELIVERY_PAYMENT_PREPARATION_ENABLED` son falsos por defecto; el checkout de demostración normal también está protegido por `storefront.demo_mode`. `BANECO_ENABLED` y la configuración activa de la empresa/sucursal son adicionales y compartidos con POS.
2. `EmitOnlineDynamicQr`, `DynamicQrAction::prepareOnline`, `ClaimPreparedDynamicQrAction::executeOnline` y `OnlineOrderActionContext::fromConfirmedIntent` exigen `runningUnitTests()`. La emisión online prohíbe HTTP no simulado con `preventStrayRequests()`; no se ha habilitado la generación ni el registro automático real de pagos online.
3. La consulta pública de estado puede leer el último estado persistido con las emisiones detenidas; valida solicitud, empresa, sucursal, orden, caja, identidad, intención y configuración bancaria en una sola consulta, y no llama al banco. Solo muestra `paid` si existe un pago QR completado vinculado a esa orden y caja; si no, indica revisión. El UUID v4 de la preparación funciona como credencial de portador: quien lo posea puede consultar el estado, no constituye autenticación de identidad. Debe mantenerse privado. La respuesta solo contiene `pending`, `paid` o `review` y usa `no-store`; CSRF y límite de frecuencia permanecen activos. El scheduler o webhook en backend deben actualizar el estado; sin ellos la respuesta puede estar desactualizada. La confirmación online real sigue bloqueada y requiere conciliación. Cambiar `BANECO_ENABLED` afectaría también al POS. La configuración cacheada y los procesos de larga duración pueden retrasar un cambio de bandera.
4. La bandera online es global, no una autorización por sucursal ni un límite de exposición. Antes de un piloto hace falta diseñar y probar una compuerta por empresa/sucursal que detenga nuevas emisiones y permita recuperar, consultar y conciliar las existentes, sin afectar las operaciones presenciales.
5. La validación de `DynamicQrAction::apply` y `OnlineOrderActionContext` compara `qrId`, moneda e importe, pero no exige `transactionId` ni impone su unicidad entre intenciones. La unicidad actual de `bank_qr_id`, `payment_id` y la clave por intención no demuestra deduplicación de una transacción bancaria repetida en QR distintos. Acordar con el banco identidad y ámbito de unicidad, examinar datos históricos y diseñar protección transaccional sin alterar las autorizaciones POS.
6. Acordar formato exacto de importes, evidencia, identificador de operación, estados, expiración, idempotencia, errores inciertos, cuotas, conciliación, notificaciones y horarios. Las credenciales y la cuenta reales no se inspeccionaron ni se modificaron. Verificar TLS, salida de red, almacenamiento cifrado, rotación y protección de datos en un entorno autorizado.
7. Validar caja cerrada, reservas inconsistentes, caídas, confirmaciones tardías, impresión, concurrencia y aislamiento en el motor de destino. El lock de caché debe ser compartido entre procesos; la prueba online aislada con caché `array` demuestra bloqueo de filas InnoDB, no un lock de caché distribuido.

### Identificador de pago y bloqueo pendiente

El contrato local describe `transactionId` de generación como referencia comercial y el `transactionId` del pago como número de transacción bancaria; no establece si este último es único globalmente, por entidad, cuenta, comercio, fecha u otro ámbito. La [página pública del banco](https://www.baneco.com.bo/mype_sf_cobros_qr) consultada describe el servicio QR, pero no documenta esa garantía. Antes de definir una restricción, solicitar al banco para el contrato y ambiente vigentes: ámbito exacto, estabilidad del identificador ante reintentos y consultas, reutilización en el tiempo, casos de pagos parciales y formato de evidencia verificable. Confirmar también cómo se distingue una colisión legítima de un pago duplicado.

La idempotencia existente evita registrar dos pagos de la misma intención; **no impide de forma demostrada asociar un mismo identificador bancario a intenciones diferentes**. No se ha añadido una restricción con un ámbito inventado ni se afirma que los conflictos entre intenciones se auditen automáticamente. Es un bloqueo para el uso bancario real: una futura implementación debe preservar la respuesta, registrar el conflicto, reservar el identificador en una transacción y comprobar tanto intenciones online como el historial POS según el ámbito contractual confirmado. Debe probar la simultaneidad en MariaDB y no modificar la autorización del cajero presencial.

La consulta de estado persistido está disponible fuera de pruebas, pero no inicia comunicación bancaria y no convierte el flujo real en operativo. La confirmación y emisión online reales continúan bloqueadas por `runningUnitTests()`. El interruptor global compartido del POS no se cambió.

### Validación del cambio de parada

- La consulta HTTP de estado se ejecutó en MariaDB 10.4.32/InnoDB aislada con dos procesos simultáneos sobre una misma intención. Se comprobaron `pending`, `paid`, `review` para conciliación e incertidumbre, y rechazos por otra empresa, sucursal u orden; las consultas no crearon pagos, movimientos, impresiones ni intenciones. El banco fue simulado y los procesos rechazaban HTTP externo no simulado.
- En la prueba HTTP aislada, 30 consultas sucesivas respondieron correctamente y la siguiente recibió 429. Se verificaron CSRF en entorno de producción simulado y `no-store` en las respuestas exitosas. El UUID se envía en el cuerpo POST, no en la URL ni en la respuesta; el endpoint rechaza parámetros de URL. La captura de eventos no detectó el UUID en logs de Laravel durante esas consultas. Un cliente que incumpla el protocolo podría incluirlo en la URL antes de que Laravel rechace la petición, y ese intento podría quedar en logs externos: la configuración del servidor web, proxies y servicios externos de producción debe verificarse por separado.
- Con nuevas emisiones y preparación desactivadas se comprobaron la programación del comando `baneco:reconcile`, su ejecución `--inline` y el webhook usando exclusivamente el banco simulado. Esto no demuestra que cron ni un worker estén funcionando en SiteGround.
- Las pruebas HTTP con banco simulado detienen emisión y preparación, comprueban el QR pendiente, un fallo de consulta y su reintento, un resultado incierto sin identificador, una confirmación normal repetida y una confirmación tardía que permanece en conciliación. No se crean nuevas intenciones con la bandera apagada.
- La recuperación del botón de consulta tras recargar se probó en frontend y en Chrome contra Laravel y SQLite aislados. El arnés detuvo emisiones, recargó la página y consultó la intención existente sin enviar peticiones de creación; se conservó una sola intención y, tras confirmación simulada, un pago, un consumo y dos impresiones. No constituye una prueba de conectividad con el banco real ni una habilitación de su API.
- Se repitieron las pruebas de carreras online y POS con InnoDB aislado. No cubren la exclusión entre identificadores bancarios iguales de intenciones distintas: ese control todavía no existe y requiere el contrato descrito arriba.
- La suite PHP completa ejecutada tras la validación final terminó con 766 pruebas, 764 aprobadas, 2 omitidas y 7.029 aserciones, sin fallos. Las omisiones requieren InnoDB. Las regresiones POS seleccionadas terminaron con 161 pruebas y 1.250 aserciones; frontend, 52 pruebas aprobadas.

La cancelación bancaria sigue siendo manual. La disposición administrativa de cancelación se rechaza antes de llegar a su lógica de liberación; la condición aparece duplicada, sin cambiar el resultado. El estado 9 o el vencimiento local no acreditan ausencia de pagos en tránsito. Pedir al banco, por escrito y para el contrato vigente, si aún puede aceptar un pago, si uno iniciado antes puede liquidarse después, durante cuánto tiempo y cómo obtener evidencia identificable de que no hay pagos pendientes. Hasta entonces conservar las reservas y la evidencia.

## Ocho migraciones de `acce090`

| Sufijo | Contenido y requisito principal |
| --- | --- |
| `120000` | Identidad de sistema por empresa/sucursal, claves únicas y FK compuesta a `branches` y FK a `users`. |
| `130000` | Operación online, una por solicitud; FK compuestas a solicitud, caja e identidad, y FK al cajero original. |
| `140000` | Orden POS nullable y única; FK compuesta por orden, empresa y sucursal. |
| `150000` | Intención bancaria nullable y única, FK a `dynamic_qr_intents`. |
| `160000` | Una resolución por intención y responsable; este archivo no crea triggers de inmutabilidad para esa tabla. |
| `170000` | Disposición, única por operación e intención; relaciones a solicitud, orden, caja y responsable; importe y evidencia. |
| `180000` | Triggers de rechazo de UPDATE y DELETE en disposiciones para SQLite o driver `mysql`; otros drivers fallan. |
| `190000` | Resultado de inventario único por disposición y dos triggers equivalentes; otros drivers fallan. |

Las ocho se aplicaron durante Sprint 3.3 en MariaDB 10.4.32/InnoDB aislada, con cuatro triggers observados. Esto no establece la versión, configuración, datos ni privilegios de SiteGround. Las FK compuestas requieren índices y tipos compatibles en las tablas de referencia. `CREATE`/`ALTER`, índices, FK y `TRIGGER` deben estar autorizados; en MySQL, la configuración del binary log puede imponer permisos adicionales. Las migraciones lanzan una excepción en `down()` para conservar auditoría: no hay rollback Laravel automático. En MariaDB las sentencias DDL causan commits implícitos; un error puede dejar aplicación parcial y exige un plan de recuperación y restauración ensayado, no reejecución ciega.

En el ensayo aislado de Sprint 3.4 se provocó un conflicto en el segundo trigger de `180000`: el primero quedó creado y el reintento directo falló. En una base nueva y marcada como prueba se retiraron únicamente los dos triggers de la maqueta y se volvió a aplicar la migración; se verificó que UPDATE y DELETE volvían a rechazarse. Este procedimiento de prueba **no autoriza eliminar triggers reales**: una recuperación de destino requiere inspección, respaldo y plan específico aprobados.

## Lista de comprobación de solo lectura para SiteGround

No ejecutar estas consultas durante esta auditoría. Un operador autorizado puede ejecutarlas después con la conexión de la aplicación, sin revelar contraseñas ni datos de clientes. Si una consulta no está permitida, registrar la limitación y pedir la información al proveedor; no conceder privilegios ni modificar variables aquí.

```sql
-- Versión, esquema efectivo y cuenta efectiva; redactar nombres al compartir.
SELECT VERSION() AS version_db, DATABASE() AS esquema, CURRENT_USER() AS cuenta_efectiva;

-- Verificar que estas tablas previas existen y son InnoDB.
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users','branches','cash_sessions','orders',
    'online_order_requests','dynamic_qr_intents','migrations')
ORDER BY TABLE_NAME;

-- Comparar el resultado con los ocho nombres exactos del repositorio.
SELECT migration, batch FROM migrations
WHERE migration LIKE '2026_11_17_%' ORDER BY migration;

-- Índices compuestos y orden de columnas necesarios para las FK.
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('branches','cash_sessions','orders','online_order_requests',
    'online_system_identities','online_qr_operations')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Comprobar presencia y definición de protecciones si ya existen.
SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'online_qr_%'
ORDER BY TRIGGER_NAME;

-- Los resultados pueden contener nombres de cuenta: no publicar sin revisar.
SHOW GRANTS FOR CURRENT_USER;
SHOW VARIABLES WHERE Variable_name IN ('log_bin','log_bin_trust_function_creators');
```

Confirmar versiones y privilegios efectivos con el proveedor, incluyendo `TRIGGER` en las tablas nuevas y cualquier condición de binary log. `SHOW GRANTS` y la lectura de variables no sustituyen una prueba de creación en staging equivalente; algunas variables o privilegios pueden no ser visibles al usuario. Confirmar además tipos y orden de columnas de FK con `information_schema.COLUMNS` y `KEY_COLUMN_USAGE`, espacio, conjunto de caracteres, configuración de caché/colas, cron, despliegue de workers y backup/restauración. Si faltan tablas, permisos o metadatos, detener la preparación del despliegue.

## Propuesta de activación futura, no autorizada por esta auditoría

1. Mantener ambas banderas de preparación y QR online en sus valores aprobados; no cambiar el interruptor global bancario del POS. Identificar responsables, instancia, contrato, versiones, respaldos y criterio de reversión; obtener autorización específica del propietario y del banco.
2. En rama y entorno separados, implementar y revisar las barreras de producción necesarias, sin quitar los controles sin reemplazarlos. Separar emisión nueva de consulta/conciliación existente, imponer ámbito de sucursal y resolver la deduplicación bancaria. Mantener cancelaciones manuales y la reserva bloqueada bajo incertidumbre.
3. Ensayar migración y recuperación parcial en una copia aislada equivalente al destino, sin datos reales innecesarios; probar triggers, permisos, DDL, índices, rollback operativo y restauración. Ejecutar suite completa, regresiones POS y carreras reales con caché compartida y motor/versión equivalentes.
4. Con simulador y después con el ambiente de certificación y datos que autorice el banco, probar generación, consulta, webhook, rechazo, tiempo de espera, cierre de caja, reservas, pagos tardíos, duplicados, impresión y caída de procesos. No inferir el resultado real del estado 9 por la simulación.
5. Solo con aprobación separada, diseñar un piloto limitado a una empresa/sucursal y exposición acordada; medir cada intención, pago, reserva, caja, impresión e incidencia con responsables y reconciliación independiente. El checkout y POS presencial deben supervisarse por separado.
6. La suspensión futura debe impedir nuevas emisiones, mantener consultas y conciliación de obligaciones existentes, conservar evidencia y reservas y no tocar el POS. La separación ya se comprueba en el simulador; antes de autorizar el piloto debe implementarse y ensayarse para el banco real, incluyendo caché de configuración, workers y operaciones en tránsito. No cancelar QR ni liberar stock automáticamente.

Referencias técnicas: documentación oficial de [MariaDB sobre commits implícitos de DDL](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit) y de [MySQL sobre binary logging y triggers](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html). El proveedor y la versión efectiva determinan las condiciones aplicables.
