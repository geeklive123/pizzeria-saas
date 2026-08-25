# Preparación de producción en SiteGround

Este documento es una lista de preparación. No autoriza ni ejecuta el despliegue.

## Requisitos del servidor

- Seleccionar PHP 8.3 o superior; se recomienda mantener PHP 8.4 para igualar el entorno validado.
- Confirmar las extensiones exigidas por Composer y Laravel, entre ellas PDO MySQL, Mbstring, OpenSSL, Tokenizer, XML, Ctype, JSON y Fileinfo.
- Instalar dependencias con `composer install --no-dev --optimize-autoloader`.
- Configurar el document root del dominio para que apunte exclusivamente a `public/`. No exponer la raíz del proyecto, `.env`, `storage/` ni `vendor/`.
- Dar permisos de escritura al usuario PHP únicamente sobre `storage/` y `bootstrap/cache/`.

## Variables de entorno

Crear `.env` directamente en el servidor, fuera de Git, con valores propios de producción:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMINIO_REAL
DB_CONNECTION=mysql
DB_HOST=HOST_MYSQL_SITEGROUND
DB_PORT=3306
DB_DATABASE=BASE_PRODUCCION
DB_USERNAME=USUARIO_PRODUCCION
DB_PASSWORD=SECRETO_NO_VERSIONADO
```

Generar una clave exclusiva con `php artisan key:generate`; no copiar la clave de desarrollo. Configurar también correo, sesión, caché y cola según los servicios disponibles. Para HTTPS se recomienda `SESSION_SECURE_COOKIE=true`.

## Base de datos

- Crear previamente la base y el usuario MySQL con privilegios limitados a esa base.
- Hacer backup antes de cualquier actualización de una base que ya contenga datos.
- Revisar `php artisan migrate:status` y ejecutar `php artisan migrate --force` una sola vez durante el despliegue autorizado.
- No usar `migrate:fresh`, `migrate:refresh`, `db:wipe` ni seeders demo en producción.

Las migraciones no crean el primer usuario administrativo. `DatabaseSeeder` prepara datos demo y no debe usarse como mecanismo automático de bootstrap de producción.

## Primer owner de producción

El mecanismo aprobado crea dentro de una transacción `User`, `Company`, `Branch` y la membership owner activa, reutilizando registros compatibles cuando ya existen:

```bash
php artisan app:provision-owner
```

El comando pide nombre, correo, empresa y sucursal; solicita y confirma la contraseña con entrada oculta. No ofrece una opción de contraseña que pueda quedar en el historial del shell. Al terminar valida el login mediante el guard de Laravel y comprueba todos los permisos administrativos.

Para una ejecución no interactiva deben existir solo durante el proceso estas variables:

```text
PROVISION_OWNER_NAME
PROVISION_OWNER_EMAIL
PROVISION_OWNER_COMPANY
PROVISION_OWNER_BRANCH
PROVISION_OWNER_PASSWORD
PROVISION_OWNER_PASSWORD_CONFIRMATION
```

Después se ejecuta `php artisan app:provision-owner --no-interaction` y se eliminan inmediatamente las variables del entorno. No deben agregarse al `.env`, a scripts, al repositorio ni a logs.

Por defecto el comando rechaza agregar una cuenta cuando la empresa ya tiene un owner activo. Si se necesita conservar Owner Demo y agregar explícitamente el owner real, un operador autorizado debe ejecutar interactivamente:

```bash
php artisan app:provision-owner --allow-existing-owner
```

La ejecución repetida con la misma empresa, correo y contraseña verifica y reutiliza los registros. Si el correo ya pertenece a un `User`, la contraseña suministrada debe autenticar esa cuenta; nunca se reemplaza su contraseña ni se cambia otra membership de forma implícita.

La migración `2026_08_24_170000_create_pizza_compositions` transforma datos existentes de packaging: crea reglas a partir de ingredientes de caja y elimina esas filas de `recipe_items`. Antes de ejecutarla sobre otra base con datos se requiere backup y ensayo sobre una copia verificando nombres y reglas resultantes.

## Frontend y cachés

`public/build` no se versiona. Los recursos deben compilarse con `npm ci && npm run build` en CI, en una máquina de build o en SiteGround si ofrece una versión compatible de Node.js; luego el artefacto `public/build` debe estar presente en la publicación.

Después de configurar `.env` y migrar:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Si se utiliza el disco público, crear el enlace con `php artisan storage:link`. Configurar cron para `php artisan schedule:run` y un worker persistente solo si la operación habilita tareas programadas o colas asíncronas.

## Impresión Epson

La impresión RAW actual no debe ejecutarse en SiteGround: depende de PowerShell, Windows y el spooler de la PC conectada por USB. Antes de habilitar impresión desde el dominio público se necesita un agente o puente local autenticado que conecte el servidor con esa PC. No se deben exponer endpoints de impresión sin autenticación ni abrir directamente el spooler a Internet.

## Verificación posterior autorizada

Cuando se autorice el despliegue, verificar HTTPS, login, selección de empresa/sucursal, permisos, ventas, cocina, caja, pagos, inventario, gastos, reportes, escritura de sesiones/logs y presencia de los assets de Vite. La impresión debe probarse separadamente desde la PC del restaurante una vez implementado el puente local.
