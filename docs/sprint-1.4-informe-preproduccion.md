# Masa & Maña — preproducción, Sprint 1.4

**Veredicto: NO APTO PARA DESPLIEGUE CONTROLADO.** La discrepancia de compilación se corrigió y se validó localmente. Siguen bloqueados la identidad y versión exacta de MySQL en SiteGround, el ensayo con ese motor y un respaldo de procedencia confirmada, la infraestructura real y las pruebas físicas. Este informe no autoriza publicar, ejecutar migraciones productivas, contactar al banco ni imprimir físicamente. El punto de partida fue `b2858c2`; el cambio local resultante debe identificarse por su commit y hashes al preparar un paquete.

## CSS, fuentes y compilación sin red

La causa reproducida es que Tailwind 4 detectaba automáticamente contenido desde el directorio de trabajo y, además, `app.css` incluía explícitamente las vistas compiladas de `storage/framework/views`. En el mismo código y dependencias se agregaron, solo para la prueba, `z-[782]` en un HTML temporal y `z-[783]` en una vista compilada: ambas aparecieron en el CSS y cambiaron su hash. El contenido temporal de cada workspace y las vistas previamente compiladas podían, por tanto, alterar el resultado. No se conservó un inventario de todas las entradas presentes cuando se generó el asset histórico; no se atribuye cada diferencia histórica a un archivo concreto.

Se cambió `resources/css/app.css` a `source(none)` y se registraron explícitamente `resources/views`, `resources/js`, `app` —que contiene clases utilizadas por PHP— y las plantillas de paginación del framework. Nuevos canarios en `tmp` y vistas compiladas no entraron en la salida. Las versiones instaladas coincidieron con `package-lock.json`: Tailwind y su plugin 4.3.3, Vite 8.2.2, laravel-vite-plugin 3.2.0 y PostCSS 8.5.26. No hay archivo de configuración PostCSS independiente. El lockfile npm tiene SHA-256 `dd4e8469171ed385520fde361ced4cda85ef2a57510b87c88c3e51c77131a953`; el de Composer, `a381f22a218405c0a248b13fe3aa08461c4a3183287f8bfaa3c41ee22753d2a7`.

El plugin de fuentes también dependía de una caché local o de descargar su definición. `vite.config.js` usa ahora el proveedor local y `resources/fonts` contiene copias idénticas de las seis fuentes Instrument Sans ya presentes en los assets versionados, manteniendo pesos y orden de formatos. El manifest de fuentes cambia: la versión local ya no emite las mismas declaraciones `unicode-range`; no se certifican todos los idiomas o glifos posibles. Una muestra visual con pesos 400/500/600, tildes, ñ, cifras y caracteres de fallback produjo PNG idéntico al anterior.

Se realizaron dos compilaciones de fuentes extraídas del commit y modificadas con los archivos del cambio, en directorios distintos **sin caché de fuentes**, más una en el workspace. Se bloquearon `fetch` y conexiones de Node; las dependencias npm y plantillas de paginación de Composer ya estaban instaladas localmente, por lo que **no se ensayó una instalación limpia de dependencias sin red**. Los 11 assets del manifest y los dos manifests coinciden entre las tres compilaciones y `public/build`:

- CSS `app-rww4T21R.css`: SHA-256 `f7eaf3901ec8fe52788092327139b49dc86d17565bf22986bcdf4db87fb1223a`.
- JavaScript `app-DL72G6ne.js`: SHA-256 `1606a49c68d1cb7e1e9343d47555644be75cd4418700e26c6c8ac4cbd109becf`.
- CSS de fuentes `fonts-aQ6sSooP.css`: SHA-256 `22781ce127bc670e5c327ec0fb72505874f19d15c63e9cb78ece680ca038eb86`.
- `manifest.json`: `3ec0a5096d7d450b8cf669aa438668330582109451e2b3e55b593c68f668096d`; `fonts-manifest.json`: `a59c068b9ed81f91edb7044f671ec994a8e09d49dc45c4379b562b6a91a9518c`.

Chrome comparó antes/después panel, Venta, mesas, checkout y caja en 1440×900, con una cuenta y pedido sintéticos en MariaDB aislada. Excluyendo el reloj y la etiqueta del arnés, no hubo píxeles distintos; Venta y checkout a 500×850 tampoco mostraron diferencias fuera de la etiqueta. Las pantallas se inspeccionaron visualmente. Es una muestra de estados iniciales, no una certificación de todos los diálogos, idiomas, tamaños o navegadores. Una primera captura móvil falló por timeout y se repitió correctamente. Las capturas están en `tmp/sprint14-*`; no son datos de restaurante.

La prueba `tests/Frontend/BuildReproducibility.test.mjs` construye con red bloqueada, introduce canarios temporales exclusivos y comprueba que no aparezcan en CSS, que los assets coincidan con el manifest y que el manifest de fuentes coincida. La documentación de Tailwind explica la detección automática y `source(none)`: https://tailwindcss.com/docs/detecting-classes-in-source-files.

## Migraciones y recuperación

Respecto al respaldo **candidato no confirmado**, siguen siendo candidatas las cinco migraciones descritas en el informe local del Sprint 1.3, no incluido en este commit. No se conoce el historial real de SiteGround. Se revisaron las migraciones, sus tablas, columnas, índices y claves; no se modificó ninguna migración histórica.

En MariaDB 10.4.32 se restauró el mismo respaldo en `pizzeria_qr_test_807e786bd685` y se aplicaron las cinco migraciones. Sus duraciones locales fueron 583, 104, 218, 356 y 23 ms; no predicen bloqueo ni duración en producción. Los hashes de 68 tablas históricas y columnas originales permanecieron iguales, incluidos 658 pedidos, 658 pagos, seis usuarios, 77 saldos, 5.699 movimientos de inventario, 384 de caja y configuraciones bancarias e impresoras. Una repetición posterior comprobó las cinco migraciones registradas y los datos de las 74 tablas: sin cambios. La copia contiene datos potencialmente sensibles del respaldo y está aislada por esquema en un servidor local compartido; acordar su retención y restringir acceso. Evidencia: `tmp/audit_backend/result-sprint14-migrations-normal.json` y `result-sprint14-idempotence-final.json`.

La repetición es idempotente porque Laravel encuentra las cinco entradas en `migrations` y las omite; **las sentencias `up()` no son idempotentes frente a objetos parcialmente creados**.

Se ensayaron dos interrupciones **simuladas entre sentencias** en copias nuevas:

1. En `pizzeria_qr_test_6c31c3f6aab0`, se interrumpió después del primer `CREATE TABLE` de cargos. La primera tabla existía, faltaba la segunda y no había registro de migración; el reintento ciego falló y los datos históricos coincidieron. Se dejó el estado parcial para análisis, sin reparación automática.
2. En `pizzeria_qr_test_84bac8082fd0`, se ejecutó la primera alteración de versión y se simuló la interrupción antes de la segunda y del registro. Otra conexión observó `Waiting for table metadata lock`; el reintento ciego falló por columna duplicada. Solo en la copia se completó manualmente la segunda alteración y el registro tras verificar el estado; la comprobación posterior conservó los hashes históricos y la idempotencia.

Evidencia: `tmp/audit_backend/result-sprint14-interruption-first.json` y `result-sprint14-interruption-version.json`. Estas simulaciones no son una caída física, no cubren cada frontera de las cinco migraciones y no establecen tiempos del proveedor. La reparación hecha en ensayo **no es un procedimiento automático ni una instrucción para producción**.

### Procedimiento de diagnóstico ante interrupción — solo lectura

1. Detener escritores y no repetir migraciones. Identificar de forma independiente servidor, base, código, backup y hora; crear y verificar un nuevo respaldo que preserve también los pagos posteriores al corte. No usar `migrate:fresh`, `db:wipe`, `down()`, borrados ni inserciones manuales en `migrations` como reacción automática.
2. Desde una cuenta autorizada de solo lectura y la base identificada, guardar `SELECT VERSION(), DATABASE()`, `SELECT migration, batch FROM migrations ORDER BY id`, `SHOW CREATE TABLE` de cada tabla involucrada y las consultas a `information_schema.columns`, `statistics` y `key_column_usage` filtradas por `table_schema = DATABASE()`. Evitar registrar datos personales o credenciales.
3. Comparar el registro y el esquema real con el ensayo aprobado: cargos requiere `order_delivery_charges` y `order_delivery_charge_audits`; avisos, `dynamic_qr_notifications`; entrega, `takeaway_deliveries` más las tres columnas `auto_kitchen_print_*` e índice correspondiente de `kitchen_dispatches`; intentos, `financial_payment_attempts` con sus únicas y foráneas; versión, `orders.financial_version` y `financial_payment_attempts.expected_financial_version`, ambas `bigint unsigned NOT NULL DEFAULT 0` en el ensayo MariaDB. Revisar además índices, FKs, nulabilidad, defaults y datos existentes, no solo nombres.
4. Si el registro está ausente y ningún objeto existe, la migración es candidata pendiente, sujeta a la revisión completa. Si está ausente y existe alguna parte, tratar como **parcial**. Si el registro existe pero falta o difiere una parte, tratar como **inconsistente**. Incluso registro y nombres presentes requieren comparar definiciones e integridad: no bastan por sí solos para autorizar la continuación.
5. Reproducir el estado exacto en otra copia aislada, diseñar y ensayar una reparación específica con validación de datos y respaldos, y pedir revisión y autorización explícitas antes de actuar sobre producción. Nunca restaurar encima de cobros posteriores ni asumir que volver a Git revierte datos.

Consultas de inventario orientativas, a ejecutar solo con acceso de lectura autorizado y base ya identificada:

```sql
SELECT VERSION(), @@version_comment, DATABASE();
SELECT migration, batch FROM migrations
WHERE migration IN (
  '2026_10_30_120000_create_order_delivery_charges',
  '2026_10_31_120000_create_dynamic_qr_notifications_table',
  '2026_11_01_120000_add_takeaway_delivery_and_deferred_kitchen_print',
  '2026_11_08_120000_create_financial_payment_attempts',
  '2026_11_09_120000_add_order_financial_version'
) ORDER BY migration;
SELECT table_name, column_name, column_type, is_nullable, column_default
FROM information_schema.columns WHERE table_schema = DATABASE()
  AND table_name IN ('orders','kitchen_dispatches','order_delivery_charges',
    'order_delivery_charge_audits','dynamic_qr_notifications',
    'takeaway_deliveries','financial_payment_attempts')
ORDER BY table_name, ordinal_position;
SELECT table_name, index_name, non_unique, seq_in_index, column_name
FROM information_schema.statistics WHERE table_schema = DATABASE()
  AND table_name IN ('orders','kitchen_dispatches','order_delivery_charges',
    'order_delivery_charge_audits','dynamic_qr_notifications',
    'takeaway_deliveries','financial_payment_attempts')
ORDER BY table_name, index_name, seq_in_index;
SELECT table_name, constraint_name, column_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage WHERE constraint_schema = DATABASE()
  AND table_name IN ('orders','kitchen_dispatches','order_delivery_charges',
    'order_delivery_charge_audits','dynamic_qr_notifications',
    'takeaway_deliveries','financial_payment_attempts')
ORDER BY table_name, constraint_name, ordinal_position;
```

## MySQL 8 y SiteGround

No hay acceso autorizado disponible al servidor ni evidencia de su versión exacta. No se identificó un MySQL 8 aislado local seguro; la instancia usada fue MariaDB 10.4.32. La compatibilidad MySQL 8, el ensayo en un motor equivalente y las migraciones pendientes reales están **bloqueados**, no aprobados por equivalencia. Antes de una ventana, obtener de solo lectura `SELECT VERSION(), @@version_comment, DATABASE()` y el historial de migraciones; confirmar la procedencia y fecha del respaldo, restaurarlo en una instancia MySQL aislada equivalente sin cron, workers ni salida bancaria y repetir conservación, índices, bloqueo, interrupciones e idempotencia. La referencia de MySQL sobre DDL atómico aclara que una sentencia atómica no vuelve transaccional una migración de varias sentencias: https://dev.mysql.com/doc/refman/8.0/en/atomic-ddl.html.

La política pública de SiteGround indica dejar al menos 30 minutos entre ejecuciones de scripts en el contexto de uso razonable: https://www.siteground.com/kb/fair-use-siteground-hosting/. Es orientación publicada; aquí **no se ha demostrado** que sea un límite técnico impuesto o una cláusula contractual del plan concreto. La aplicación declara `baneco:reconcile` y `kitchen:enqueue-paid` cada minuto. Bajar la frecuencia sin un mecanismo alternativo y un SLA acordado puede retrasar conciliaciones y comandas 30 minutos o más, además de la espera de cola. No se cambió ninguna frecuencia. `ReconcileDynamicQr` declara timeout de 90 s y la configuración de ejemplo de cola usa `retry_after` de 90 s; los valores reales se desconocen. Laravel exige que timeout sea menor que `retry_after`: https://laravel.com/framework/docs/13.x/queues#job-expirations-and-timeouts.

### Consulta técnica preparada para soporte — no enviada

> Para nuestro plan y sitio concretos, ¿cuál es la versión exacta de MySQL y qué motor usan nuestras tablas? ¿Qué PHP y extensiones están disponibles para web y CLI? La aplicación Laravel necesita tareas de conciliación QR y recuperación de comandas con una cadencia objetivo de un minuto y un worker de cola supervisado. ¿Es una recomendación de uso razonable, una restricción técnica o una condición contractual la separación de 30 minutos? ¿Qué frecuencia y duración se permiten, se admite un scheduler externo y un worker persistente supervisado, y qué límites de CPU, tiempo y procesos se aplican? ¿Qué mecanismos de locks compartidos y cola están disponibles, cómo se verifican sus ejecuciones y logs, y cuál es el procedimiento de respaldo y restauración aislada compatible con nuestro plan? Indiquen alternativas permitidas y tiempos esperados de recuperación sin compartir credenciales.

### Diagnóstico futuro de solo lectura

Sin acceso no se ejecutó nada en SiteGround. Identificar `APP_DIR`, `PHP_BIN`, base y credenciales de solo lectura sin mostrar secretos. Verificar web **y** CLI; no asumir que `schedule:list` demuestra ejecución. Consultar procesos, configuración cacheada, permisos de lectura/escritura y espacio, logs sanitizados, trabajos fallidos, tiempos y heartbeat del agente Windows; `queue:failed` puede contener información sensible. No ejecutar para diagnosticar `schedule:run`, reconciliadores, workers ni trabajos de impresión. Con autorización de lectura y entorno identificado pueden utilizarse los comandos de diagnóstico de la sección 5 del informe Sprint 1.3, más `SHOW CREATE TABLE` y consultas de `information_schema` descritas arriba. Contrastar en Site Tools el cron configurado y evidencia de ejecuciones reales. No copiar ni imprimir `.env`, APP_KEY, claves, tokens o credenciales.

## Pruebas finales

| Validación | Resultado |
| --- | --- |
| PHPUnit completo, SQLite en memoria | Repetición actual: 670 aprobadas, 1 omitida, 0 fallidas; 5.387 aserciones |
| JavaScript, incl. compilación offline y canarios | Repetición actual: 27 aprobadas, cero fallos u omisiones |
| Pint | Repetición actual aprobada con PHP 8.4 |
| Vite | tres compilaciones sin red aprobadas, dos sin caché de fuentes; 11 assets y dos manifests iguales por SHA-256 |
| Coordinador financiero, SQLite | 14 aprobadas, 77 aserciones |
| Recuperación financiera y QR simulado, MariaDB | 2 aprobadas, 23 aserciones |
| Bloqueos de reversión, MariaDB | 1 aprobada, 13 aserciones |
| Reversión concurrente y carrera de cajeros, MariaDB | aprobadas; una muestra cada una, sin duplicados |
| Cinco migraciones en copia candidata, MariaDB | aplicadas y repetidas sin cambio; dos interrupciones parciales simuladas y comprobadas |
| MySQL 8, SiteGround, banco real e impresoras físicas | no ejecutados; bloqueados o fuera de autorización |

La reversión concurrente registró un solo pago reversado, una compensación y una reposición; la carrera de cajeros registró un pago y un movimiento. Las pruebas usan datos sintéticos y esquemas aislados. Los cortes TCP reales aprobados del Sprint 1.3 siguen documentados allí; no se modificaron las rutas de pago en este sprint. La suite completa conserva una omisión SQLite que se cubrió por separado en MariaDB; ninguno de estos ensayos demuestra un apagón físico de la base.

## Plan actualizado y contingencia

Antes de autorizar cualquier ventana: confirmar respaldo actual y destino; ensayar las cinco migraciones y recuperación en MySQL equivalente; aprobar el paquete cuyo manifest y assets tienen los hashes de arriba; validar visualmente el restaurante y los dispositivos físicos; resolver con el proveedor cron, worker, locks, límites y SLA, y comprobar monitoreo. El plan local del Sprint 1.3, no incluido en este commit, detalla la secuencia: verificar commit y Git; respaldar código, configuración y base y probar restauración; cerrar la ventana a nuevos cobros y resolver intentos/QR/trabajos inciertos; pausar escritores, cron, workers y agente; publicar solo el paquete revisado sin sobrescribir `.env` ni regenerar APP_KEY; comprobar migraciones reales y aplicar únicamente las aprobadas, una por una; reactivar de forma supervisada; verificar operación e inspeccionar logs. No ejecutar esa secuencia mientras el veredicto sea NO APTO.

Ante fallo, detener nuevos cobros y conservar los registros y operaciones posteriores al corte. Revertir código y assets con Git solo después de demostrar compatibilidad con el esquema nuevo; Git no revierte pagos, movimientos, DDL ni impresión física. Nunca sobrescribir la base con un respaldo anterior si existen cobros posteriores sin preservar y conciliar, ni hacer reparación automática de DDL parcial. La prueba física, el banco real y el funcionamiento del cron y agente del restaurante permanecen pendientes de autorización y aceptación operativa.

## Revisión de cierre del 10 de noviembre de 2026

El HEAD inicial fue `b2858c2`, sin commits posteriores en la rama `feature/qr-dinamico-banco-economico`. Los cambios pendientes corresponden a `.gitignore`, `resources/css/app.css`, `vite.config.js`, los dos manifests, los CSS compilados referenciados, las seis fuentes locales y `tests/Frontend/BuildReproducibility.test.mjs`; este informe también estaba sin seguimiento. Existen otros documentos, assets antiguos, diagnósticos y evidencias sin seguimiento que no se seleccionaron ni eliminaron. No se modificaron migraciones, configuración bancaria o de impresoras, rutas, usuarios, `.env` ni datos históricos.

En esta revisión se ejecutaron las 27 pruebas JavaScript sin fallos ni omisiones. Dos compilaciones Vite en directorios temporales independientes coincidieron mediante SHA-256 con `public/build` en los 11 recursos referenciados y ambos manifests. La prueba JavaScript ejecutó otra compilación con red bloqueada y comprobó los canarios temporales. No se ensayó instalación de dependencias desde cero. `git diff --check` pasó antes de esta actualización.

El primer intento de PHPUnit, Pint, `route:list` y `schedule:list` no arrancó con PHP 8.2.12 de XAMPP. Posteriormente se encontró PHP 8.4 en Herd: Pint pasó y `route:list` mostró 208 rutas. `schedule:list` falló inicialmente por la ausencia de `cache_locks` en la base de memoria; se repitió con caché `array` y mostró las dos tareas, sin ejecutarlas. La inspección estática de las cinco migraciones confirmó el orden y los `down()` declarados; los ensayos MariaDB y de interrupción descritos antes son históricos y no prueban MySQL ni SiteGround.

Se inspeccionaron capturas locales a 1440×900 y 500×850 de estados sintéticos de inicio, pedidos, mesas, caja, cobro, reportes, configuración bancaria, diálogo QR y sección de impresoras. Para aislar la interfaz se usaron SQLite en memoria y HTML estático con CSS y fuentes locales; no se ejecutó JavaScript de la aplicación y se abrió el diálogo QR expresamente sin generar un código. En la captura de impresoras se ocultaron las secciones anteriores para mostrarla. Se revisaron también capturas previas de Venta y cobro. Esta revisión cubre estados iniciales, no demuestra operación interactiva, QR real, impresión física ni todos los diálogos y dispositivos.

La repetición completa de `php artisan test` terminó con 671 pruebas: 670 aprobadas, una omitida, cero fallidas y 5.387 aserciones, en SQLite en memoria con PHP 8.4. La omisión corresponde a una limitación de SQLite que se cubrió por separado en un ensayo MariaDB anterior; no reemplaza la validación en el motor productivo. `node --test tests/Frontend/*.test.mjs` pasó las 27 pruebas sin fallos ni omisiones; `php vendor/bin/pint --test` pasó. Dos nuevos builds Vite independientes coincidieron por SHA-256 con los 11 recursos y dos manifests locales; se comprobó que existan los recursos del manifest y sus URLs locales CSS, sin URLs externas en esos CSS. Los recursos de fuente se tomaron de las seis copias locales Instrument Sans, pesos 400, 500 y 600 en WOFF y WOFF2. No se instaló ninguna dependencia ni se verificó una instalación desde cero.

Siguen pendientes la verificación de SiteGround y del motor real, el respaldo confirmado, los ensayos en un entorno equivalente y las comprobaciones físicas y del flujo bancario real. El cierre local del sprint no implica aptitud para despliegue. Antes de preparar cualquier ventana deben atenderse las verificaciones del apartado anterior y aprobar por separado la operación en producción.
