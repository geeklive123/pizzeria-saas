# Ensayo aislado de despliegue: Banco Económico QR

Estado: **preparado, no ejecutado**. No se dispone en esta intervención de una copia verificada ni se ha autorizado una restauración. La revisión de las nueve migraciones es estática; no demuestra que sean aplicables al esquema real. Referencia: `docs/preparacion-produccion-banco-qr.md`.

## Condiciones para autorizar el ensayo

1. Identificar responsable, copia consistente de producción, fecha de corte, suma de verificación, versión MySQL/MariaDB, espacio disponible y ubicación protegida. La copia contiene datos personales y financieros: limitar acceso y definir conservación. Comprobar que el respaldo incluye esquema, índices, claves foráneas, historial de migraciones y tablas relacionadas.
2. Tener una instancia de ensayo separada y una base **nueva y vacía** con nombre inequívoco `pizzeria_qr_rehearsal_<identificador>`; registrar host, puerto, servidor, nombre y propietario. Excluir `pizzeria_saas`, cualquier host de producción y bases preexistentes. Usar cuentas de mínimo privilegio cuyo alcance impida escribir fuera del ensayo. Verificarlo con el administrador antes de conectar o restaurar.
3. Aislar la aplicación y los datos restaurados: acceso restringido, sin usuarios operativos ni notificaciones, sin cron ni workers automáticos, y con salida de red bloqueada hacia Banco Económico y terceros. Un flag de nuevas generaciones desactivado **no desactiva necesariamente** el scheduler ni los jobs ya existentes. No ejecutar colas heredadas, webhooks ni comandos de conciliación contra el banco. El QR de humo debe usar `Http::fake()` en un proceso de pruebas, con `Http::preventStrayRequests()`; si no puede garantizarse, omitir la prueba e informar.
4. Preparar una instancia de aplicación independiente y confirmar, sin mostrar secretos, que puede leer lo cifrado con la clave correspondiente. No modificar `APP_KEY`, `.env`, credenciales ni configuración bancaria del proyecto o del destino; no copiar secretos a la documentación. No usar las credenciales históricas para contactar servicios.
5. Obtener aprobación explícita para crear la base, restaurar la copia y aplicar migraciones **solo allí**. La aprobación del ensayo no autoriza despliegue ni operaciones de producción.

## Restauración reproducible, pendiente de autorización

1. El responsable verifica integridad y origen del respaldo y registra su hash; ensaya la restauración en un servidor aislado y sin acceso externo. Acordar versión compatible y codificación con el operador de base de datos.
2. Antes de importar, revisar el dump para detectar `CREATE DATABASE`, `USE`, nombres de esquemas calificados, eventos, triggers, procedimientos, `DEFINER` y cualquier instrucción que pueda salir de la base designada o iniciar acciones. Si existe una referencia operativa, **detenerse**; un parámetro de base por defecto por sí solo no la neutraliza. Preparar con el responsable un mecanismo de restauración confinado y revisable sin alterar el respaldo fuente.
3. Confirmar desde la nueva conexión `SELECT DATABASE(), @@hostname, @@port, VERSION()`; cotejar cada valor con la autorización y comprobar que la base no existía antes y está vacía. Usar el cliente o herramienta del proveedor con fichero de credenciales protegido, sin contraseñas en comandos, logs o pantalla. Restaurar únicamente al destino confirmado; no se proporciona un comando de importación que presuponga que el contenido del dump es seguro.
4. Confirmar de nuevo servidor y `DATABASE()`, integridad de importación, motor InnoDB, historial de migraciones, índices y claves foráneas. No ejecutar aplicación, tareas ni pruebas mutantes hasta completar la línea base. Conservar la copia y no borrar bases de pruebas anteriores.

## Línea base y controles después de cada migración

Con cron y workers detenidos, capturar antes de la primera migración y después de cada una, desde la misma conexión y base de ensayo verificada:

- Conteos de `orders`, `payments`, `cash_movements`, `inventory_movements`, `inventory_stocks`, `inventory_reservations`, `kitchen_dispatches` y `order_cancellation_audits`; incluir tablas bancarias existentes y sus eventos o evidencias cuando existan. Confirmar los nombres en `information_schema` antes de consultar. Una tabla ausente se registra como ausente, no como cero.
- Por empresa y sucursal, resumir estados y sumas decimales relevantes de pedidos, pagos por método y estado, movimientos de caja e inventario; registrar `COUNT`, mínimos y máximos de IDs y relaciones. No exponer información personal, credenciales, imágenes ni números de cuenta. Guardar evidencia detallada protegida, incluidos hashes de filas canónicas o comparaciones por clave para detectar cambios que un conteo o suma no muestran.
- Registrar migraciones aplicadas y pendientes, definición e índices afectados (`SHOW CREATE TABLE` y `information_schema.statistics`/`key_column_usage`), versiones, duración y resultado. No imprimir columnas cifradas en el reporte.
- Comparar con la línea base: estas migraciones no deberían borrar ni cambiar estados o valores históricos de pedidos, pagos, caja, inventario, anulaciones o diagnósticos. Cualquier diferencia inesperada, error DDL, pérdida de conexión, timeout o cambio parcial detiene el ensayo. MySQL puede confirmar DDL implícitamente: inspeccionar el esquema real antes de decidir cómo continuar; no repetir a ciegas.

Ejemplos de consultas **solo después de confirmar la conexión de ensayo** (ejecutar cada una únicamente si la tabla existe):

```sql
SELECT DATABASE(), @@hostname, @@port, VERSION();
SELECT COUNT(*) AS n, MIN(id) AS min_id, MAX(id) AS max_id FROM orders;
SELECT company_id, branch_id, status, method, COUNT(*) AS n, SUM(amount) AS amount
FROM payments GROUP BY company_id, branch_id, status, method;
SELECT company_id, branch_id, type, COUNT(*) AS n, SUM(amount) AS amount
FROM cash_movements GROUP BY company_id, branch_id, type;
SELECT company_id, branch_id, type, COUNT(*) AS n FROM inventory_movements GROUP BY company_id, branch_id, type;
SELECT company_id, branch_id, COUNT(*) AS n FROM order_cancellation_audits GROUP BY company_id, branch_id;
```

Las sumas y conteos no prueban por sí solos conservación íntegra ni equivalen a un extracto bancario. No usar SQL manual para corregir datos ni desactivar restricciones.

## Comprobaciones específicas por migración

| Orden | Antes | Después |
|---|---|---|
| `2026_10_27_120000_create_dynamic_qr_intents` | Comprobar ausencia o historial coherente de `bank_qr_settings` y `dynamic_qr_intents`; tipos e índices compuestos de empresas, sucursales, pedidos, tandas, caja y pagos; coincidencia con el registro de migraciones. | Confirmar ambas tablas, FKs compuestas, `active_scope`, referencia, QR ID y `payment_id` únicos, `active_order_id` único y unicidad empresa/clave; columnas y tablas históricas intactas. |
| `2026_10_28_120000_create_bank_qr_diagnostics_table` | Confirmar configuración y FKs de empresa/usuario; tablas ausentes si la migración está pendiente. | Confirmar diagnósticos y eventos, FKs, referencia y `active_company_id` únicos iniciales, y conteos. |
| `2026_10_28_130000_add_original_image_to_bank_qr_diagnostics` | Diagnósticos presentes; comprobar columnas ausentes y longitud/tipo de imagen histórica sin leer su contenido. | `original_qr_image` y `generation_response_code` nullable; comparar diagnósticos, estados e imágenes existentes mediante evidencia protegida. |
| `2026_10_28_140000_create_bank_qr_reconciliation_evidence` | Diagnósticos y clave compuesta compatibles; comprobar ausencia o registro coherente. | Tabla, FK diagnóstico/empresa, FK usuario y empresa, e índice diagnóstico/tipo. |
| `2026_10_28_150000_add_bank_qr_configuration_metadata` | Configuración existente, columnas y tabla de auditoría ausentes si está pendiente; revisar efectos de valores por defecto en configuraciones históricas. | Verificar columnas, valores por defecto `enabled`, `due_days`, `technical_logging`, auditoría y FK; comparar credenciales cifradas sin mostrarlas. |
| `2026_10_28_160000_allow_independent_bank_qr_diagnostics` | Comprobar índice exacto `bank_qr_diagnostics_active_company_id_unique`, FK de `active_company_id`, ausencia del índice nuevo y de la columna HTTP; registrar estados y marcadores históricos. | Confirmar índice no único `bqd_active_company_idx`, eliminación del único global y conservación de FK, referencias únicas y registros; verificar columna nullable `generation_http_status`. |
| `2026_10_28_170000_remove_dynamic_qr_expiry_auto_update` | Guardar `SHOW CREATE TABLE`, definición de `expires_at`, versión, zona horaria de sesión y valores históricos; comprobar compatibilidad de `change()` en una copia. | Comparar definición y valores de vencimiento; comprobar con **un registro sintético de ensayo separado**, sin modificar historial, que una actualización no altera vencimiento. No asumir que el `down` restaura la definición anterior. |
| `2026_10_28_180000_create_bank_qr_deposit_evidence` | Intenciones, empresa, sucursal y usuario presentes; comprobar tabla ausente o historial coherente. | Confirmar tabla, `request_key` único, FKs e índice de ámbito; no interpretar `paid` como abono verificado. |
| `2026_10_28_190000_add_generation_phase_to_dynamic_qr_intents` | Intenciones presentes; comparar estados y evidencia histórica; columna ausente si está pendiente. | Confirmar columna nullable y `NULL` en operaciones históricas: nunca clasificarlas automáticamente como `prepared`. Confirmar que estados e identificadores permanecen intactos. |

No ejecutar todas las migraciones por defecto. Con autorización específica, y solo después de confirmar destino, aplicar cada pendiente individualmente mediante el mecanismo Laravel revisado con `--path` y conexión de ensayo explícita; registrar resultado y repetir los controles antes de continuar. Si la tabla de migraciones y el esquema discrepan, detenerse para revisión. No usar `migrate:fresh`, `db:wipe`, `truncate`, `migrate:rollback` ni `down` en la copia.

## Dependencias del código y orden de publicación posterior

- `dynamic_qr_intents` se consulta desde registro de pagos, caja y anulación incluso con generación desactivada: publicar código sin la tabla puede romper efectivo y ventas. La creación QR escribe `generation_phase`; sin `190000` falla. Diagnósticos y conciliación usan sus tablas y columnas correspondientes. El historial admite la ausencia de `bank_qr_deposit_evidence`, pero el registro de evidencia devuelve 503.
- En un despliegue posterior, primero verificar respaldo y compatibilidad del código anterior con los cambios de esquema; aprobar y aplicar las migraciones pendientes antes del código que las requiere; publicar código y assets compatibles; verificar con QR desactivado y finalmente solicitar autorización separada para activación. No ejecutar `composer setup`, pues genera clave y ejecuta migraciones. No se ha verificado que el orden sea compatible con la versión realmente instalada: ese análisis es requisito previo.

## Humo en ensayo aislado

Usar usuarios y pedidos sintéticos claramente identificados en la copia, sin tocar registros históricos; no usar datos o acciones del cliente real. Para cada caso registrar resultado, IDs de ensayo y efectos esperados:

1. Login y permisos de una empresa/sucursal de ensayo; acceso ajeno rechazado.
2. Venta sintética en efectivo y parcial, cambio, un pago y un movimiento; verificar saldo y caja.
3. QR exclusivo y mixto con `Http::fake()`: generación de PNG simulado, pending sin pago, confirmación validada, reconsulta idempotente y cero solicitudes externas. No interpretar HTTP 200 de generación como pago.
4. Caja: importe físico solo en efectivo, QR separado y movimientos de reversión correspondientes.
5. Reportes e historial: totales diarios y por rango/sucursal, pagos desglosados, estados pendientes y reversados; no confundir API `paid` con abono.
6. Tandas: no liberar con QR pendiente, liberar tras confirmación; comprobar anulación de tanda no pagada y bloqueo seguro con cobro activo. La reversión de tanda pagada en mesa abierta sigue limitada.
7. Inventario: con artículo y stock sintéticos, reserva, consumo y reversión autorizada una sola vez; no alterar ni usar stock histórico. Verificar sus movimientos y cantidades.

Comparar la línea base histórica antes y después de la preparación y nuevamente tras el humo excluyendo explícitamente los IDs sintéticos. No borrar los registros de humo ni la base sin autorización separada.

## Rollback y SiteGround

Para el ensayo, ante un fallo detener ejecución y mantener la copia, logs sanitizados y evidencia; no ejecutar `down` ni borrar nada. Para una publicación futura, detener nuevas generaciones y coordinar conciliación de operaciones ya iniciadas. Revertir código solo tras comprobar compatibilidad con el esquema y datos nuevos. Nunca restaurar una copia antigua sobre pagos posteriores: preservar y conciliar los movimientos del intervalo y decidir una recuperación autorizada. Probar restauración en otro entorno aislado antes de cualquier decisión operativa.

Comprobar posteriormente en SiteGround: (1) PHP web y CLI, extensiones y versión de base; (2) capacidad real de cron, CPU y duración, sin asumir frecuencia por minuto ni workers permanentes; (3) caché compartida con locks, cola, timeout y `retry_after`; (4) respaldo y restauración verificables, espacio y acceso; (5) registro de migraciones y compatibilidad del código desplegado. No se ha accedido a SiteGround.
