# Pizzería SaaS

Aplicación Laravel para la operación multiempresa y multisucursal de una pizzería: catálogo, recetas, inventario, compras, mesas, pedidos, cocina, caja, pagos, gastos, reportes, usuarios e impresión térmica local.

## Requisitos

- PHP 8.3 o superior; el entorno de desarrollo usa PHP 8.4.
- Composer 2.
- MySQL o MariaDB para operación normal.
- Node.js y npm para compilar los recursos frontend.
- Extensiones PHP requeridas por Laravel y por `composer.lock`.

## Instalación local

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Configura la conexión de base de datos en `.env` y luego ejecuta las migraciones en el entorno correspondiente:

```bash
php artisan migrate
```

El archivo `.env` nunca debe versionarse. Las pruebas automatizadas usan SQLite en memoria mediante `phpunit.xml` y no deben apuntar a la base MySQL de operación.

## Primer owner de producción

Después de migrar una base nueva, aprovisiona el primer owner mediante el comando seguro e interactivo:

```bash
php artisan app:provision-owner
```

La contraseña se solicita de forma oculta y no existe una opción `--password`. El modo automatizado solo acepta variables de entorno temporales; consulta [docs/siteground-production.md](docs/siteground-production.md#primer-owner-de-producción).

## Validación

```bash
php artisan test
php vendor/bin/pint --test
npm run build
```

## Producción

La preparación y los pasos manuales para SiteGround están en [docs/siteground-production.md](docs/siteground-production.md). El document root debe apuntar a `public/`; las credenciales y opciones de producción se configuran exclusivamente mediante `.env`.

## Impresión térmica

El transporte Epson actual depende de Windows, PowerShell, el spooler local y una impresora instalada en la PC del restaurante. Un servidor remoto no puede acceder directamente al USB. Consulta [docs/impresion-termica-windows.md](docs/impresion-termica-windows.md).
