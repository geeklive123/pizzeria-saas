# Banco Económico — implementación y operación

Implementado el 27 de octubre de 2026 en `feature/qr-dinamico-banco-economico`. No se hicieron solicitudes bancarias reales, cobros reales, push ni despliegue. La función permanece desactivada; no se cargaron credenciales. La prueba real de extremo a extremo sigue pendiente de autorización.

## Comportamiento implementado

- `DynamicQrIntent` mantiene la intención separada de `Payment`. Generar o mostrar un QR nunca crea un pago QR completado.
- El saldo se calcula bajo bloqueo desde `OrderPaymentService`. JavaScript no envía el importe QR ni decide la moneda.
- `per_batch`: cobra exclusivamente la tanda seleccionada. La confirmación usa `RegisterPaymentAction` y `SettleKitchenDispatchAction`; libera sus líneas y conserva la mesa abierta. El consumo de reservas ocurre exactamente donde ya lo hacía `SettleKitchenDispatchAction`.
- `at_end`: exige solicitar el cierre; cobra el saldo completo de la cuenta y reutiliza `ClosePaidOrderAction`. No se cambiaron los estados ni las condiciones existentes de cocina. Los productos en preparación no se marcan servidos por pagar.
- Mixto: registra efectivo y la intención en una transacción **antes** de contactar al banco. Un fallo bancario conserva el efectivo. La repetición de la misma operación o una nueva pulsación con otro identificador reutiliza la intención activa.
- Una sola intención remotamente cobrable por pedido, incluidas sus tandas. No se aceptan nuevos pagos mientras haya un QR activo o una incidencia de conciliación.
- La confirmación recibe el usuario y la caja originales de la intención. Revalida permisos actuales, pertenencia, titular de caja y turno abierto. No modifica `auth()`, sesiones web ni permisos para ejecutar el pago.
- El estado bancario `1` exige detalle con `qrId`, importe exacto y moneda BOB coincidentes. El identificador de transacción del banco se conserva como dato de conciliación; no se confunde con la referencia del comercio.
- El estado `9` confirma anulación. Otros estados nunca se interpretan como pagados.
- El QR no crea movimientos de efectivo. El turno no puede cerrarse mientras tenga intenciones activas o incidencias pendientes.
- El ticket usa las claves originales de `ThermalPrintingService`. La impresión ocurre después del commit financiero; un fallo no revierte el pago. El scheduler reintenta el encolado si no se registró; una impresora desactivada deja el intento fallido auditable para el flujo existente de reimpresión.

## Contrato y decisiones de seguridad

Fuente inspeccionada: PHP y README del ZIP local `referencias/banco-economico/capibara solution economico.zip`, plugin `1.0.1-test`. No constituye una certificación bancaria de producción.

Contraste adicional: Especificaciones Técnicas API Market v1.4.0, Banco Económico, secciones 2–7 y anexo PaymentQR. El ambiente de certificación indicado es `https://apimktdesa.baneco.com.bo/ApiGateway/`; la URL predeterminada del plugin es `https://apimkt.baneco.com.bo/ApiGateway/`. No se presume que usuarios, cuentas o claves sirvan en ambos ambientes. El banco entrega una clave AES de 256 bits para los ambientes; el cliente usa el servicio de cifrado remoto y no deduce un modo AES local.

La documentación define `transactionId` de generación como texto, sin exigir 16 dígitos: la referencia numérica de 16 caracteres es una decisión local. En PaymentQR, `transactionId` es el número de transacción bancaria y no se debe equiparar con la referencia comercial. La tabla de estado dice `statusQRCode`, pero el ejemplo usa `statusQrCode`; se admiten ambos y se rechazan valores simultáneos contradictorios. El banco define 0 activo pendiente, 1 pagado y 9 anulado. El ejemplo usa `payment` como lista; se acepta un único elemento, sujeto a las validaciones estrictas de QR, moneda e importe.

El gateway ofrece `paidOn(setting, fecha)` como consulta de solo lectura a `GET api/qrsimple/v2/paidQR/{yyyyMMdd}`. Valida la fecha y cada elemento de `paymentList`, conserva únicamente campos necesarios y no modifica intenciones ni pagos. El historial administrativo lo invoca solo a petición explícita y conserva un resultado temporal en sesión, no una evidencia de abono. Una lista vacía no prueba que no se haya generado un QR; un pago sin `qrId` conocido no se asocia automáticamente por importe ni por `transactionId` bancario. Confirmar con el banco cobertura, paginación, fechas y correlación de referencias; el formato de fecha del pago se restringe al formato de fecha o al ejemplo de medianoche de la especificación.

Para el incidente histórico sin `qrId`, se necesita el HTTP y `responseCode` originales o evidencia del banco que correlacione inequívocamente la referencia comercial con el QR, además de confirmar cuenta, credenciales y ambiente. El contrato no identifica por sí solo la causa ni demuestra que sea seguro regenerar. No se cambia la intención existente.

La respuesta bancaria JSON con código numérico distinto de cero se conserva como `bank_response_<código>` y en el diagnóstico sanitizado. Una respuesta inválida o sin código identificable permanece como `bank_invalid_response`. En generación ambos casos conservan el estado incierto: el código por sí solo no demuestra que sea seguro repetir. Los diagnósticos anteriores no permiten recuperar un código que no fue registrado.

| Operación | Contrato usado |
| --- | --- |
| Cifrar contraseña/cuenta | `GET api/authentication/encrypt`, parámetros `text`, `aesKey` |
| Autenticar | `POST api/authentication/authenticate`, `userName`, contraseña cifrada |
| Generar | `POST api/qrsimple/generateQR` con Bearer |
| Consultar | `GET api/qrsimple/v2/statusQR/{qrId}` con Bearer |
| Anular | `DELETE api/qrsimple/cancelQR`, cuerpo `qrId` |

La generación envía BOB, `singleUse=true`, `modifyAmount=false`, referencia comercial numérica de 16 caracteres como el checkout del plugin y `dueDate` del día siguiente en Bolivia. El importe es un número JSON construido desde el decimal exacto, sin conversión a `float`. Los números recibidos del banco se preservan como texto antes de decodificar JSON. Se validan Base64, formato PNG/JPEG, tamaño y dimensiones de imagen.

Las credenciales y el token cacheado están cifrados con `APP_KEY`. Cada cambio de configuración crea una versión; las intenciones existentes conservan la versión original. La configuración de sucursal tiene prioridad sobre la de empresa. No revocar credenciales anteriores mientras existan operaciones pendientes sin coordinar su conciliación.

El cifrado remoto exige enviar secretos al banco en su query string, tal como hace el plugin. No se incluyen en URLs de la aplicación, mensajes, registros de esta integración ni respuestas al navegador. No habilitar trazas HTTP, proxy, APM o depuración que capturen esas URLs/cuerpos/cabeceras. Usar HTTPS, `APP_DEBUG=false` en producción y proteger los respaldos y `APP_KEY`. No hay implementación AES local inventada.

Timeout de conexión: 5 segundos; timeout por petición: 12 segundos. Las consultas renuevan el token una vez ante HTTP 401 y pueden repetirse mediante polling/scheduler. **`generateQR` no se repite automáticamente ante HTTP 401, timeout o una respuesta incierta**, porque el plugin no demuestra idempotencia remota ni búsqueda por referencia comercial. Un 401 durante la generación queda pendiente de conciliación; el token en caché puede requerir renovación antes de otra operación independiente.

Estados locales: `creating`, `pending`, `failed`, `generation_unknown`, `paid`, `cancelled`, `reconciliation_required`. Las generaciones interrumpidas por más de tres minutos pasan a `generation_unknown`. El vencimiento local nunca se usa para afirmar que el QR remoto quedó anulado.

## Pantallas y rutas

Integrado en el panel de cobro por tanda del POS, panel de cobro al final, checkout y alertas de caja. Incluye efectivo, efectivo parcial + QR, importe, imagen, espera, consulta manual, consulta automática cada 6,5 segundos, errores, cancelación y redirección posterior. Se limita a 100 consultas automáticas por carga; después queda disponible la consulta manual. Desactivar nuevas generaciones conserva la interfaz de consulta para intenciones existentes.

| Método y ruta | Nombre | Acceso |
| --- | --- | --- |
| `GET /orders/{order}/dynamic-qr` | `orders.dynamic-qr.show` | Cajero, empresa/sucursal activas |
| `POST /orders/{order}/dynamic-qr` | `orders.dynamic-qr.store` | Cajero, CSRF, 10/minuto |
| `POST /orders/{order}/dynamic-qr/{intent}/check` | `orders.dynamic-qr.check` | Cajero, CSRF, 20/minuto |
| `POST /orders/{order}/dynamic-qr/{intent}/cancel` | `orders.dynamic-qr.cancel` | Cajero, CSRF, 10/minuto |
| `POST /api/baneco/notify-payment` | `baneco.webhook` | Desactivado por defecto; 30/minuto por origen |

Pedidos e intenciones usan ULID públicos. Hay además una separación mínima de seis segundos por intención para consultas ordinarias, un límite por intención para cancelación y un lock compartido entre polling, job y webhook. La cancelación consulta el estado antes del DELETE y nuevamente después; no habilita regeneración si el banco no confirma `9`.

El webhook admite `{"payment":{"qrId":"IDENTIFICADOR"}}`, limita el cuerpo a 8 KiB y valida el identificador. Su contenido solo encola una consulta. No existe en el plugin una firma oficial verificable: no se inventó una. Responde de forma uniforme sin revelar si el QR existe. Mantenerlo desactivado hasta confirmar su alta oficial; polling y conciliación funcionan sin webhook.

El scheduler también vigila anulaciones confirmadas hasta siete días después del vencimiento local, como política adicional de detección de pagos tardíos. Después de ese plazo, un callback para la intención conservada sigue provocando consulta. Este plazo es una política operativa de la aplicación, no una garantía bancaria de irreversibilidad.

## Configurar credenciales reales

### Prueba administrativa independiente

La ruta local `/administracion/banco-economico/probar-qr` está disponible para owner y admin de la empresa activa. Usa únicamente la configuración bancaria activa de empresa; no crea pedidos, pagos ni movimientos contables. El importe inicial es BOB 1.00. Un QR de prueba puede recibir un pago real: coordinar su uso y conciliación con el responsable y el banco.

Las respuestas nuevas conservan `qrImage` original cifrado en el registro privado; la pantalla lo presenta como texto de solo lectura, sin renderizarlo ni sustituirlo por el valor normalizado. El botón de copia usa el valor original. La validación interpreta, sin modificar lo almacenado, un posible prefijo `data:image/png;base64,` o `data:image/jpeg;base64,` y comprueba Base64, formato y límites de imagen. Para valores de más de 2.000.000 caracteres de Base64, la inspección de imagen queda limitada y la operación permanece incierta. La longitud mostrada corresponde a los bytes del texto original. Los registros anteriores solo conservaban una representación procesada y no permiten recuperar de manera fiable el original; se indican como no disponible. La nueva migración incremental debe revisarse y aplicarse solo en un entorno autorizado.

La función se entrega desactivada (`BANECO_DIAGNOSTICS_ENABLED=false`). Su activación futura requiere primero revisar y aplicar la nueva migración en el entorno autorizado y confirmar credenciales, cuenta y entorno bancario. Solo después, en ese entorno, definir `BANECO_DIAGNOSTICS_ENABLED=true` y actualizar la caché de configuración según el procedimiento habitual. No se ha aplicado ninguna migración local ni se ha activado la función. La consulta y cancelación de pruebas existentes siguen disponibles al desactivar nuevas generaciones.

La generación, consultas y solicitudes de cancelación se guardan como eventos sanitizados. Una generación incierta no se repite con la misma referencia; se puede iniciar otra prueba independiente con una clave nueva. Sin `qrId` no se puede consultar ni anular automáticamente. La cancelación consulta estado antes y después; un pago o estado ambiguo no se marca como cancelado. No se registran respuestas, tokens o credenciales completos en los eventos.

Para conciliar una generación incierta sin `qrId`, un owner o admin puede registrar la fuente, fecha y detalle de una confirmación externa del banco. Este registro no modifica el estado. Si el banco informa un QR candidato, la pantalla consulta exclusivamente `statusQR` y conserva un evento cifrado con el resultado; aunque el importe, la moneda y el identificador coincidan, eso no demuestra que corresponda a la referencia comercial: no se asocia, cancela ni marca pagado automáticamente. Una incidencia con QR candidato no admite cierre como no generada por esta interfaz.

Si el banco confirma externamente que no se generó ningún QR, un owner o admin registra primero la evidencia y después autoriza expresamente el cierre como `confirmed_not_generated`, indicando el motivo y confirmando la verificación. La aplicación conserva el registro y la identidad de quien documenta y autoriza; depende de la veracidad de la evidencia externa y no verifica por sí misma esa afirmación. El cierre no crea pagos ni elimina historial. La migración `2026_10_28_140000_create_bank_qr_reconciliation_evidence` debe revisarse y aplicarse solo después de respaldo y autorización; no se ejecutó en la base local.

Una prueba `generation_unknown` queda pendiente de conciliación y no bloquea pruebas independientes ni QR de venta de otros pedidos. Reutilizar su clave devuelve la operación anterior sin repetir la solicitud bancaria, incluso si falta el marcador activo. Una incidencia de venta sigue protegida por pedido; sin el contrato bancario de recuperación, una generación incierta no se resuelve automáticamente.

El aviso `baneco_response_shape` registra únicamente etapa, HTTP, código bancario, presencia de campos y tipos de una lista limitada de nombres conocidos; no registra valores ni nombres arbitrarios. Los avisos anteriores no permiten recuperar el contenido o el tamaño de una respuesta. La transformación anterior de números JSON podía agotar el límite de expresiones regulares ante imágenes Base64 largas, descartando el objeto completo antes de validar el QR. El decodificador ahora procesa el JSON de forma lineal y conserva decimales exactos sin aceptar JSON inválido.

En la terminal del entorno autorizado, dentro de la raíz del proyecto:

```bash
php artisan baneco:configure ULID_EMPRESA
# Solo cuando existe configuración bancaria específica para la sucursal:
php artisan baneco:configure ULID_EMPRESA --branch=ULID_SUCURSAL
```

Solicita usuario API, contraseña, AES y cuenta mediante entradas ocultas. Solicita `branchCode` opcional, máximo cinco caracteres. No pasar secretos como argumentos, no pegarlos en este documento ni en el chat. El comando solo guarda configuración cifrada; no contacta al banco. Utilizar los ULID reales del directorio de empresas/sucursales, no inventarlos.

Después de recibir autorización para operar contra el entorno bancario elegido:

```dotenv
BANECO_ENABLED=true
BANECO_BASE_URL=https://apimkt.baneco.com.bo/ApiGateway/
BANECO_WEBHOOK_ENABLED=false
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
```

La URL anterior es la predeterminada del plugin. Confirmar con el banco el entorno autorizado antes de activarla. Para pruebas usar únicamente una URL de pruebas suministrada oficialmente, si existe. Aplicar la configuración con el procedimiento habitual de caché del entorno (`php artisan config:cache` en el servidor autorizado). No regenerar `APP_KEY` en una instalación existente: impediría leer credenciales cifradas y otros datos de la aplicación.

## Prueba autorizada de extremo a extremo

Estos pasos **no se ejecutaron contra el banco**:

1. Obtener autorización explícita para un pago controlado, credenciales y entorno; verificar empresa, sucursal, caja propia abierta y ticket/print-agent configurados.
2. Abrir una mesa `per_batch`, agregar productos y enviar una tanda. Seleccionar Banco Económico y generar. Comparar importe del QR con el saldo de esa tanda. Comprobar que cocina sigue pendiente y no existe `Payment` QR.
3. Pagar el QR una sola vez desde la aplicación bancaria autorizada. Esperar confirmación. Verificar un único pago QR, tanda liquidada, líneas liberadas, ticket original único y mesa abierta. Recargar y verificar otra vez: no debe aparecer otro pago ni ticket original.
4. En otra cuenta `at_end`, enviar los consumos y solicitar cierre. Generar por el saldo completo. Tras pagar, comprobar pedido cerrado, mesa liberada y ticket. El pago no debe alterar los estados de preparación ya existentes.
5. Probar un mixto autorizado: ingresar el efectivo parcial, generar y verificar que el efectivo ya está registrado mientras el QR sigue pendiente. Pagar el saldo. Deben existir dos `Payment`, un movimiento de venta en efectivo y ningún movimiento físico por QR.
6. Probar cancelación de un QR **no pagado**: esperar confirmación de anulación antes de generar otro. No pagar un QR retirado. Ante timeout no repetir el cobro: revisar la intención.
7. Probar con la página cerrada y scheduler/worker activos. La confirmación debe aparecer por conciliación. Comprobar que una impresora desconectada no revierte el pago; recuperar el ticket mediante el mecanismo existente.

Registrar evidencia de importes, ULID de intención, estado bancario, pago y ticket, sin contraseñas, tokens ni cuentas completas. Cualquier devolución de una prueba real requiere el procedimiento bancario y financiero autorizado; no se implementó un reembolso bancario ficticio.

## Scheduler, colas e impresión en SiteGround

No se cambió SiteGround. Estos son requisitos de la aplicación a preparar en un despliegue posterior autorizado; verificar rutas, binario PHP 8.4 y capacidades del plan concreto.

- La aplicación programa `baneco:reconcile` cada minuto con `withoutOverlapping`; solo se ejecuta cuando un cron invoca `cd /RUTA/PROYECTO && /RUTA/PHP84 artisan schedule:run`. La cadencia real depende del hosting. La política pública de SiteGround para planes compartidos recomienda al menos 30 minutos entre ejecuciones: confirmar con soporte las condiciones del plan antes de adoptar una cadencia menor. A 30 minutos la recuperación puede demorarse 30 minutos o más; no se garantiza recuperación casi inmediata. Fuentes: [cron en Site Tools](https://www.siteground.com/kb/manage-cron-jobs/) y [uso razonable](https://www.siteground.com/kb/fair-use-siteground-hosting/).
- Worker de base de datos: `/RUTA/PHP84 artisan queue:work database --sleep=3 --tries=3 --timeout=90`. Usar un supervisor si el alojamiento lo permite. Para workers acotados vía cron, usar `--stop-when-empty --max-time=50` y un bloqueo de proceso soportado por el entorno para evitar acumulación.
- `DB_QUEUE_RETRY_AFTER=180` debe superar el timeout de 90 segundos. El job conserva exclusión por intención durante 120 segundos y la confirmación financiera además usa locks e índices únicos de base de datos.
- Usar una caché persistente y compartida (`database` o Redis); `array` solo sirve para tests. Todos los procesos deben compartir base, caché y `APP_KEY`.
- Cada ejecución selecciona hasta 50 QR para consulta, priorizando los menos consultados; intentos activos con QR ID se revisan como máximo una vez por minuto desde el scheduler y cancelados se vigilan cada 15 minutos durante la ventana existente. La página también respeta el intervalo de seis segundos por intento y el lock compartido. La capacidad real depende de la cadencia del cron y la cola: vigilar retrasos y volumen antes de habilitar cobros.
- El evento sanitizado `baneco_status_check` registra el intento local, empresa y resultado de cada consulta de estado; no registra credenciales, imagen, cuerpo ni token. Revisar alertas, fallos de cola, retraso de pendientes y crecimiento de logs, con retención y acceso apropiados.
- La migración `2026_10_28_180000_create_bank_qr_deposit_evidence` habilita evidencias cifradas y declaraciones explícitas de administradores sobre abonos. Debe revisarse y aplicarse únicamente después de respaldo y autorización. Sin ella, el historial continúa disponible e indica que la función de evidencia no está disponible. No marca automáticamente ningún pago histórico como acreditado y no modifica `Payment`.
- La unicidad de `bank_qr_id`, `payment_id` e idempotencia protege una operación por QR. La base no garantiza unicidad global del `transactionId` bancario entre QR diferentes: confirmar con el banco su alcance y contrato antes de diseñar una restricción y una conciliación de operaciones históricas. El contrato disponible tampoco prueba la referencia comercial mediante `transactionId` de pago; no se equiparan ambos valores.
- Sin worker persistente se puede programar `php artisan baneco:reconcile --inline` con exclusión externa. No ejecutar simultáneamente dos programaciones redundantes. La duración aumenta con los pendientes y debe ajustarse al límite permitido por el hosting.
- Revisar fallos de cola y los avisos `baneco_reconciliation_required` / `baneco_ticket_pending`. La pantalla Caja muestra hasta 50 incidencias por empresa/sucursal; el comando informa el total pendiente de revisión.
- Los nuevos avisos `baneco_response_diagnostic` contienen únicamente operación, código HTTP, código bancario numérico sanitizado, validez JSON, presencia de `qrId`/`qrImage` y código de error. No incluyen el contenido ni identificadores de la operación. No permiten reconstruir respuestas anteriores a este diagnóstico; correlaciona por la hora con los avisos de conciliación existentes.
- Mantener el agente Windows existente para procesar `PrintAttempt`. Ver [print-agent-windows.md](print-agent-windows.md). La cola Laravel de conciliación y la cola de impresión existente tienen funciones distintas.
- El callback público solo se habilita cuando el banco confirme la URL HTTPS `/api/baneco/notify-payment`. No depende de la sesión del cajero y no necesita exención CSRF en rutas web porque está en `routes/api.php`.
- Desactivar `BANECO_ENABLED` detiene nuevas generaciones, pero conserva consultas, cancelaciones y conciliación de operaciones existentes. No detener workers con QR pendientes.

## Migraciones, respaldo y verificación local

Creada `2026_10_27_120000_create_dynamic_qr_intents.php`: tablas `bank_qr_settings` y `dynamic_qr_intents`, FK compuestas de contexto, índices únicos de referencia, `qrId`, pago, idempotencia y pedido activo. No modifica saldos ni datos históricos.

En `127.0.0.1/pizzeria_saas` se aplicaron ambas pendientes en batch **16**:

- `2026_10_11_120000_remove_automatic_updates_from_internal_consumption_timestamps`: se revisó el SQL; quita `ON UPDATE` de `confirmed_at` / `cancelled_at`. No se editó esta migración histórica. La inspección final confirma `EXTRA` vacío para ambas columnas.
- `2026_10_27_120000_create_dynamic_qr_intents`: aplicada. Al verificar quedaron cero configuraciones bancarias y cero intenciones reales.

Respaldo local previo: `storage/app/private/baneco-backups/pizzeria_saas_pre_baneco_20261027_155855.sql`, **1.845.072 bytes**. Se comprobó finalización de mysqldump, presencia de las tablas relevantes y SHA-256:

```text
0e064f60212a7d0135c8d907877c4a46f61557599c9f735a143b7dbd72f64428
```

Está fuera de Git. No se realizó un ensayo de restauración. El manifiesto privado conserva conteos y hashes de 11 tablas de negocio.

**Incidencia del procedimiento local:** entre el respaldo y la comprobación previa cambiaron el pedido interno 214, sus líneas, stocks y lotes, y aparecieron movimientos `order_consumption` 1418/1419 para la línea 327, fechados `2026-10-27 15:59:24` en los registros Laravel. Estas diferencias ya estaban presentes antes de ejecutar las migraciones. El verificador inicial lanzó una excepción que el proceso devolvió con código 0; por ello el comando continuó y migró pese al aviso de diferencias. Se corrigió el verificador privado para devolver código 1 explícito. El respaldo mantuvo su hash; pagos, sesiones/movimientos de caja y tablas de consumos internos mantuvieron sus hashes. No se restauraron ni sobrescribieron las operaciones concurrentes. No se puede afirmar que todas las tablas permanecieron inmóviles durante la sesión; confirmar con el operador el uso concurrente del pedido 214. Esta incidencia no debe repetirse en producción: verificar un estado estable y un código de salida efectivo antes de migrar.

## Validación y límites pendientes

- `tests/Feature/DynamicQrTest.php`: 31 pruebas, 144 aserciones, correctas. Usa `Http::fake()`, `Http::preventStrayRequests()` y SQLite en memoria.
- `tests/Frontend/DynamicQrUi.test.mjs`: cuatro pruebas correctas de flujo JavaScript, ejecutadas con `node --test tests/Frontend/DynamicQrUi.test.mjs`.
- `npm.cmd run build`: correcto; se regeneraron los assets y el manifiesto ya versionados por el proyecto. Las excepciones de `.gitignore` permiten incluir los assets de app/storefront referenciados por ese manifiesto; el ZIP bancario permanece excluido.
- `php vendor/bin/pint --test`: correcto.
- Rutas registradas y `migrate:status` verificados. Resultado definitivo de la suite completa: ver sección de entrega al final de este documento.

SQLite prueba transacciones, rollback, constraints, exclusión lógica e idempotencia; no reproduce bloqueos reales entre conexiones MySQL. Se revisó el orden caja → pedido → tanda → intención y `lockForUpdate()`, pero queda pendiente una prueba concurrente controlada en una base MySQL de ensayo. No se ejecutó una carrera contra ventas locales reales. Las pruebas de JavaScript usan DOM simulado; no equivalen a validación visual en navegador ni a una prueba bancaria real.

Bloqueos/confirmaciones externas pendientes:

1. Credenciales reales y confirmación del entorno bancario autorizado. La función no se activó automáticamente.
2. Pago real autorizado de punta a punta y validación de la impresión física. No se certificó producción con un cobro real.
3. Alta oficial del webhook y, si existe, especificación de firma, IPs oficiales y política de reintentos. No bloquea polling/scheduler.
4. Contrato oficial de recuperación por referencia y de idempotencia de generación. Sin él, un timeout con `qrId` desconocido requiere revisar con el banco; no se puede regenerar ni liberar automáticamente el pedido.
5. Confirmar semántica horaria de `dueDate`, estados distintos de 1/9 y eventual reversión bancaria posterior a un pago. El sistema no inventa equivalencias ni revierte pagos históricos basándose en estados no documentados.
6. Las incidencias `reconciliation_required` (pago tardío, moneda/importe incompatibles, caja cerrada, pedido cancelado, usuario sin permiso o saldo cambiado) no se resuelven forzando un pago o reabriendo caja. Requieren revisar evidencia bancaria y autorizar la corrección contable correspondiente. No hay botón que las marque pagadas sin evidencia.
7. Configurar y verificar cron, worker, caché compartida y print-agent en SiteGround durante el despliegue posteriormente autorizado.

## Inventario de archivos

Nuevos:

- `app/Actions/DynamicQrAction.php`
- `app/Console/Commands/ConfigureBancoEconomicoCommand.php`
- `app/Console/Commands/ReconcileDynamicQrCommand.php`
- `app/Enums/DynamicQrStatus.php`
- `app/Http/Controllers/DynamicQrController.php`
- `app/Http/Controllers/BancoEconomicoWebhookController.php`
- `app/Jobs/ReconcileDynamicQr.php`
- `app/Models/DynamicQrIntent.php`
- `app/Models/BankQrSetting.php`
- `app/Services/BancoEconomicoGateway.php`
- `app/Services/BankQrException.php`
- `config/baneco.php`
- `database/migrations/2026_10_27_120000_create_dynamic_qr_intents.php`
- `resources/js/dynamic-qr.js`
- `resources/views/orders/_dynamic_qr.blade.php`
- `tests/Feature/DynamicQrTest.php`
- `tests/Frontend/DynamicQrUi.test.mjs`
- Este documento.

Modificados:

- `app/Actions/RegisterPaymentAction.php`, `app/Actions/CloseCashSessionAction.php`
- `app/Http/Controllers/CashController.php`
- `resources/js/app.js`
- `resources/views/orders/_per_batch_payment.blade.php`, `_at_end_payment.blade.php`, `checkout.blade.php`
- `resources/views/cash/index.blade.php`
- `routes/web.php`, `routes/api.php`, `routes/console.php`
- `.env.example`, `.gitignore`, `phpunit.xml`
- `public/build/manifest.json` y assets generados: app JS/CSS reemplazan los hashes anteriores; storefront JS/CSS quedan incluidos para mantener completo el manifiesto.

Archivos locales privados fuera de Git: respaldo/manifiesto y `storage/app/private/baneco-validate-local.php` (verificación de destino, hashes e inspección de metadatos). No contienen credenciales en código. Ninguna migración histórica fue editada.

## Resultado final de entrega

- Suite completa estable: **530 pruebas totales, 529 aprobadas, una omitida, 4.077 aserciones**, 131,989 segundos. La omisión preexistente está en `SettledKitchenDispatchReversalTest` y requiere MySQL/InnoDB para comprobar un bloqueo real.
- QR: 31/31; JavaScript: 4/4; Pint: correcto; build: correcto; `git diff --check`: correcto.
- Migraciones locales: todas aplicadas; las dos pendientes de esta sesión quedaron en batch 16. Producción sin cambios.
- Rama: `feature/qr-dinamico-banco-economico`. Cambios sin commit ni staging: 15 archivos previamente versionados modificados, dos assets antiguos reemplazados y 22 archivos nuevos (incluidos cuatro assets compilados). No se hizo push.
- ZIP de referencia, respaldo, manifiesto y utilitario privado excluidos de Git. El archivo `.codex/project.md` indicado por AGENTS.md no estaba presente; se leyeron AGENTS.md y las cuatro guías especializadas.
