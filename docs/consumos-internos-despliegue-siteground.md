# Despliegue controlado en SiteGround: Consumos internos

Esta guía prepara el despliegue; no autoriza ejecutar comandos en producción. La referencia exacta actualmente desplegada debe identificarse antes de construir el paquete. No se debe desplegar la rama completa: `feature/consumos-internos` parte de un historial que incluye promociones y el estado real de SiteGround no está confirmado.

## 1. Paquete de archivos

### Dominio, persistencia y autorización

- `app/Actions/CancelInternalConsumptionAction.php`
- `app/Actions/CreateInternalConsumptionAction.php`
- `app/Enums/InternalConsumptionReason.php`
- `app/Enums/InternalConsumptionStatus.php`
- `app/Enums/InventoryMovementType.php`
- `app/Enums/MembershipRole.php`
- `app/Enums/Permission.php`
- `app/Enums/PermissionModule.php`
- `app/Models/InternalConsumption.php`
- `app/Models/InternalConsumptionCancellation.php`
- `app/Models/InternalConsumptionComponent.php`
- `app/Models/InternalConsumptionItem.php`
- `app/Models/PrintAttempt.php`
- `app/Policies/InternalConsumptionPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/InternalConsumptionPreparationService.php`
- `database/migrations/2026_10_10_120000_create_internal_consumptions.php`

### HTTP, interfaz y rutas

- `app/Http/Controllers/InternalConsumptionController.php`
- `app/Http/Requests/CancelInternalConsumptionRequest.php`
- `app/Http/Requests/PreviewInternalConsumptionRequest.php`
- `app/Http/Requests/PrintInternalConsumptionRequest.php`
- `app/Http/Requests/StoreInternalConsumptionRequest.php`
- `resources/js/app.js` (integrar solo el bloque `data-internal-consumption-form` si el archivo de producción difiere)
- `resources/views/internal-consumptions/confirm.blade.php`
- `resources/views/internal-consumptions/create.blade.php`
- `resources/views/internal-consumptions/index.blade.php`
- `resources/views/internal-consumptions/show.blade.php`
- `resources/views/partials/sidebar-navigation.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/inventory/movements.blade.php`
- `resources/views/inventory/show.blade.php`
- `routes/web.php` (integrar solo las rutas de consumos internos si la referencia difiere)

### Impresión y reportes

- `app/Printing/Renderers/InternalConsumptionCommandRenderer.php`
- `app/Services/ThermalPrintingService.php` (integrar solo la capacidad `internalConsumption` y sus dependencias)
- `app/Services/InternalConsumptionReportService.php`
- `app/Http/Controllers/ReportController.php`
- `app/Services/ProfitabilityReportService.php`
- `app/Services/ReportsOverviewService.php`
- `resources/views/reports/index.blade.php`

### Pruebas y documentación que deben conservarse en el repositorio

- `tests/Feature/InternalConsumptionTest.php`
- `tests/Feature/InternalConsumptionReportsTest.php`
- `tests/Feature/ThermalPrintingTest.php` (solo la aserción autorizada que distingue una fracción `1/1` de la fecha)
- `docs/verificar-fecha-consumo-interno.md`
- `docs/consumos-internos-despliegue-siteground.md`
- `docs/consumos-internos-manifiesto-produccion.md`

No incluir ningún otro cambio de `tests/Feature/ThermalPrintingTest.php`. Tampoco incluir commits o archivos adicionales solamente por pertenecer al historial de la rama.

El worktree `pizzeria-saas-consumos-integration` parte del SHA real de producción `e6cbf207f426d7c663c844684087db281345730f` y contiene solo la integración inventariada. Los assets desplegables son exclusivamente los generados desde ese worktree y enumerados en `docs/consumos-internos-manifiesto-produccion.md`.

## 2. Migración y dependencias

La única migración nueva del módulo es `2026_10_10_120000_create_internal_consumptions.php`. Crea cuatro tablas aisladas y agrega a `print_attempts` una columna nullable, un índice y una clave foránea compuesta. No actualiza, elimina ni recalcula pedidos, ventas, pagos, caja, compras, gastos, lotes o movimientos existentes.

No hay dependencias nuevas de Composer ni npm. Se mantienen PHP 8.4, las dependencias bloqueadas por `composer.lock` y el toolchain actual de Vite. La migración `down()` elimina datos del módulo y no debe ejecutarse después de registrar consumos reales.

Dependencias reutilizadas del sistema existente: `CompanyContext`, `CompanyAccessService`, memberships/permisos, `PizzaCompositionService`, `OrderPosCatalogService`, `InventoryAvailabilityService`, `ApplyInventoryMovementAction`, `ReverseInventoryMovementAction`, stocks/reservas/ledger, `ThermalPrintingService`, `PrintAttempt`, Printer Setting de cocina, cola/agente remoto y servicios de rango/reportes. No se reemplaza ni modifica `ConsumeInventoryReservationAction` ni `PrintAgentQueueService`.

## 3. Permisos

Los permisos se declaran en el enum existente; no requieren un seeder adicional:

- `internal_consumptions.view`
- `internal_consumptions.create`
- `internal_consumptions.print`
- `internal_consumptions.cancel`
- `internal_consumptions.reports.view`

Revisar en la interfaz los overrides efectivos de memberships. Por defecto, Admin recibe los cinco permisos; Cashier y Waiter reciben ver/crear/imprimir; Kitchen recibe ver/imprimir. Solo Admin y Owner pueden anular y consultar el resumen propio del módulo, salvo overrides explícitos.

## 4. Preparación y respaldo

1. Identificar el commit o paquete exacto desplegado en SiteGround. Comparar el paquete archivo por archivo contra esa referencia; no asumir que `origin/main` es producción.
2. Construir un artefacto que contenga solo los archivos enumerados y `public/build` generado por el build validado. No copiar `.env`, `vendor` local, `node_modules`, dumps ni archivos de pruebas al document root público.
3. Confirmar en SiteGround la versión CLI de PHP 8.4 y la ruta del ejecutable. Sustituir `php` en los ejemplos por esa ruta verificada si corresponde.
4. Crear un respaldo descargable de los archivos actuales y un dump completo de MySQL desde las herramientas de SiteGround. Verificar que ambos respaldos tengan fecha, tamaño no cero y ubicación de restauración conocida.
5. Ejecutar primero el paquete y la migración en un staging con la misma familia/versión de MySQL/MariaDB y PHP que producción.
6. En staging, verificar el cambio de fecha de Bolivia cerca de medianoche: fecha mostrada del consumo, filtro mensual propio, Resumen, Inventario y Rentabilidad. Una diferencia reproducible detiene el despliegue; no se cambia timezone para ocultarla.

### Identificación de la referencia real de producción

Sin conectarse todavía, obtener primero del panel de SiteGround la ruta del release/document root y comprobar si el despliegue conserva `.git`. Cuando se autorice usar Site Tools > Devs > Terminal, ejecutar desde la raíz Laravel este bloque de solo lectura (sustituir la ruta; no usar `public` si la aplicación vive un nivel arriba):

```bash
cd /RUTA/REAL/DE/LARAVEL || exit 1
pwd
if [ -d .git ]; then
  git rev-parse --show-toplevel
  git rev-parse HEAD
  git log -1 --format='%H%n%cd%n%s' --date=iso-strict
  git status --short --branch --untracked-files=all
else
  echo 'NO_GIT_METADATA'
fi
php -r 'echo PHP_VERSION, PHP_EOL;'
sha256sum composer.json composer.lock package.json package-lock.json routes/web.php resources/js/app.js public/build/manifest.json
find app routes resources database/migrations config scripts public/build -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
```

El bloque no lee `.env`, no muestra credenciales y no escribe archivos. Guardar su salida como evidencia del baseline. Si no existe `.git`, el inventario SHA-256 de archivos, los manifests compilados y el historial del mecanismo de despliegue del panel pasan a ser la referencia real; no se puede atribuir honestamente un commit solo a partir de los archivos.

## 5. Validación MySQL/MariaDB descartable

No apuntar PHPUnit ni Artisan a `pizzeria_saas`. Crear previamente una base vacía y descartable, por ejemplo `pizzeria_saas_internal_consumptions_test`, con un usuario limitado a esa base. Mantener sus credenciales fuera del repositorio en un entorno aislado como `.env.mysql-testing` y comprobar antes de cualquier escritura:

```bash
php artisan about --env=mysql-testing
php artisan db:show --env=mysql-testing
```

La salida debe mostrar literalmente la base descartable. Si muestra `pizzeria_saas`, detenerse. Sobre la base nueva y vacía, sin `migrate:fresh`, `migrate:refresh` ni `db:wipe`:

```bash
php artisan migrate --env=mysql-testing --force
php artisan migrate:status --env=mysql-testing
```

Pruebas mínimas mediante la aplicación aislada:

1. Verificar la creación de las cuatro tablas, columna/FK de `print_attempts` e índices compuestos con `SHOW CREATE TABLE`.
2. Crear empresa, sucursal, usuario/membership, unidades, ingredientes, inventario, receta y Printer Setting exclusivamente de prueba.
3. Registrar un consumo y comprobar que consumo, items, componentes, movimientos y saldos se confirman en una sola transacción.
4. Forzar stock insuficiente y comprobar que no queda consumo, componente ni movimiento parcial.
5. Anular con la declaración y comprobar una sola cancelación, una reversión por movimiento y stock restaurado.
6. Intentar una relación de otra empresa/sucursal dentro de una transacción que se revierte y confirmar que la FK la rechaza.
7. Crear y reclamar una comanda interna, desconectar el agente, anular, esperar el timeout y confirmar que otro `claim` no la devuelve y que el ACK anterior es rechazado.
8. Lanzar dos anulaciones simultáneas: exactamente una debe confirmar; la otra debe rechazar duplicado, con una sola fila de cancelación y una reversión por original.

Los puntos 7 y 8 validan comportamiento concurrente real solo si se ejecutan con dos procesos/conexiones separados contra InnoDB. La suite normal continúa usando SQLite en memoria; no debe reconfigurarse para apuntar accidentalmente a MySQL. Registrar versión del motor, comandos, resultados y cualquier caso no ejecutado.

## 6. Secuencia controlada

Con autorización de despliegue y desde la raíz real de Laravel:

```bash
php artisan down
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate:status
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate:status
php artisan up
```

Si el hosting no permite modo mantenimiento para el flujo acordado, detenerse y definir una ventana; no ejecutar la migración mientras una versión incompatible atiende tráfico. El frontend debe compilarse antes con el mismo `package-lock.json` validado:

```bash
npm ci
npm run build
```

Desplegar el `public/build` resultante. No es necesario ejecutar npm en SiteGround si se sube ese artefacto.

## 7. Impresión

SiteGround no imprime por USB. Debe mantenerse el agente Windows del restaurante con HTTPS, URL de producción y token de agente configurados fuera del repositorio. Verificar:

- Printer Setting de cocina activo para la empresa y sucursal correctas.
- nombre Windows exacto de la Epson y copias configuradas;
- agente activo, reloj razonablemente sincronizado y acceso HTTPS a SiteGround;
- `PRINT_AGENT_URL`, `PRINT_AGENT_TOKEN`, `KITCHEN_PRINTER`, timeouts y directorios en la PC del agente;
- una comanda interna llega al agente, se imprime una sola vez y cambia a `printed`.

No modificar ni reiniciar comandas comerciales para validar el módulo. Una anulación invalida los intentos internos pendientes, fallidos reintentables y reclamados; conserva los datos históricos del claim, pero el estado deja de ser `claimed`, por lo que no vuelve a la cola al expirar y una confirmación tardía del agente es rechazada. Los intentos ya impresos permanecen intactos.

Si el agente ya descargó el payload antes de la anulación, el servidor no puede retirar esos bytes de la PC ni impedir una impresión local que ya empezó. La protección garantiza que el trabajo no se entrega nuevamente después del timeout y que su ACK tardío no cambia el historial invalidado.

## 8. Verificación posterior

1. Iniciar sesión con un usuario autorizado de prueba y confirmar empresa/sucursal activas.
2. Confirmar que Cajera puede registrar e imprimir, pero no anular; Admin puede registrar, imprimir, anular y ver reportes; Cocina respeta sus permisos efectivos.
3. Registrar una pizza de prueba acordada. Anotar código, hora, ingredientes y stock anterior.
4. Verificar un movimiento `internal_consumption` por componente, cantidades a tres decimales, costo tomado del promedio real disponible y disminución exacta del stock. Un costo desconocido debe advertirse y no bloquear.
5. Confirmar que no aparecieron `orders`, `payments`, `cash_movements` ni `expenses` por el consumo.
6. Confirmar Resumen (vigentes, pizzas, costo/advertencia), Inventario (separado de comercial y sin revertidos), Rentabilidad (costo conocido una sola vez) y ausencia en Ventas/Caja.
7. Probar impresión interna. No reutilizar una comanda comercial como prueba.
8. Solo si la pizza de prueba no fue preparada ni se usaron ingredientes, probar la anulación marcando la declaración y comprobar movimientos compensatorios y stock restaurado. Si fue preparada, dejarla confirmada.

## 9. Plan de reversión

### Antes de registrar datos reales

Poner la aplicación en mantenimiento, restaurar el paquete de archivos previo y, solamente si se verificó que las cuatro tablas están vacías y no existen intentos asociados, evaluar con autorización explícita revertir esa migración. Restaurar archivos y base desde los respaldos si la migración falló parcialmente.

### Después de registrar datos reales

No ejecutar `migrate:rollback` y no borrar consumos. Revertir código no revierte movimientos de inventario. Mantener el esquema y una capa de compatibilidad que reconozca `internal_consumption` en el enum y en las vistas de inventario; ocultar temporalmente rutas/navegación del módulo mediante un paquete revisado si se necesita suspender nuevas altas.

Los consumos ya preparados permanecen confirmados. Un consumo no preparado solo se anula mediante el flujo auditado, que crea reversiones. Los intentos reclamados conservan sus datos de auditoría, pero quedan invalidados; los impresos permanecen intactos. Cualquier corrección adicional de ledger requiere una operación administrativa específica y autorizada; nunca una eliminación o edición directa del movimiento histórico.

Antes de volver a abrir el sitio, verificar migraciones, acceso, stock, reportes y cola. Documentar códigos afectados y el punto exacto de restauración.
