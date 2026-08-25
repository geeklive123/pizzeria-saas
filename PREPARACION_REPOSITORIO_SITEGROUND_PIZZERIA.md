# Preparación final para repositorio y producción — Pizzería SaaS

## Objetivo

Preparar el proyecto para despliegue a producción en **SiteGround**, pero **NO hacer deploy, push ni cambios destructivos todavía**.

El objetivo de esta tarea es dejar el proyecto listo para:

1. Subirlo a un repositorio privado.
2. Desplegar posteriormente en SiteGround.
3. Confirmar que existe al menos un usuario administrativo funcional.
4. Detectar cualquier riesgo antes de mover el sistema a producción.

---

## 1. Validación final

Ejecutar:

```bash
php artisan migrate:status
php artisan test
vendor/bin/pint --test
npm run build
```

Registrar los resultados reales.

No considerar una validación como exitosa si no se ejecutó realmente.

---

## 2. Usuario administrativo existente

Revisar la base de datos local y confirmar que exista al menos un usuario administrativo funcional.

Debe verificarse:

- `owner` o `admin`;
- usuario activo;
- membership válida en la empresa;
- acceso correcto a la empresa/sucursal según la arquitectura existente;
- posibilidad de iniciar sesión;
- permisos correspondientes para administrar el sistema;
- acceso a usuarios;
- configuración;
- caja;
- reportes.

### Importante

NO mostrar:

- contraseñas;
- hashes;
- tokens;
- secretos.

Informar únicamente:

- Nombre
- Correo
- Rol
- Empresa
- Estado activo/inactivo

Si solamente existe **Owner Demo**, indicarlo claramente.

**No crear otro usuario automáticamente sin autorización.**

---

## 3. Revisar `.gitignore`

Confirmar que el repositorio NO vaya a incluir información privada o archivos innecesarios.

Como mínimo revisar:

```text
.env
/vendor/
/node_modules/
storage/logs/*
```

También excluir cuando corresponda:

- bases SQLite utilizadas para testing;
- archivos temporales de Codex;
- dumps de base de datos;
- backups;
- credenciales;
- claves SSH;
- claves privadas;
- scripts temporales de diagnóstico;
- archivos generados únicamente durante debugging;
- archivos del sistema operativo o IDE que no deban versionarse.

No ignorar archivos necesarios para que el proyecto pueda instalarse correctamente desde Git.

---

## 4. Limpiar diagnóstico temporal

Buscar código o archivos creados únicamente durante el diagnóstico y que ya no formen parte del sistema.

Revisar especialmente:

```text
.codex/
dd()
dump()
ray()
console.log()
```

También buscar:

- rutas HTTP temporales;
- controllers temporales;
- scripts PowerShell de diagnóstico que ya no sean necesarios;
- archivos `.diff` temporales;
- archivos `.tmp`;
- dumps;
- logs agregados exclusivamente para depuración.

Eliminar únicamente aquello que sea claramente temporal.

**No eliminar componentes reales del sistema ni la solución definitiva de impresión.**

---

## 5. Buscar secretos y configuración hardcodeada

Revisar el proyecto para detectar:

- contraseñas;
- tokens;
- API keys;
- credenciales MySQL;
- claves privadas;
- secretos;
- rutas privadas de la computadora de desarrollo;
- nombres/rutas hardcodeadas que impedirían ejecutar el proyecto en otro entorno.

La configuración dependiente del entorno debe utilizar `.env` o la configuración apropiada de Laravel.

Revisar especialmente:

```text
APP_ENV
APP_DEBUG
APP_URL

DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

No mostrar secretos encontrados en el informe.

Si se detecta uno, indicar únicamente el archivo y el tipo de secreto/configuración.

---

## 6. Configuración para producción

Confirmar que el proyecto permite configurar en SiteGround:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMINIO_REAL
```

La conexión MySQL de producción deberá venir exclusivamente del `.env`.

No modificar el `.env` local para simular producción.

No subir `.env` al repositorio.

---

## 7. Migraciones

Revisar TODAS las migraciones recientes relacionadas con:

- caja;
- permisos;
- usuarios;
- impresión;
- pedidos;
- configuraciones;
- cualquier ajuste realizado recientemente.

Confirmar que sean:

- incrementales;
- compatibles con MySQL;
- compatibles con una base que ya contenga datos;
- no destructivas;
- independientes de `migrate:fresh`.

No ejecutar:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe
```

No borrar datos locales existentes.

Informar si existe alguna migración pendiente.

---

## 8. Impresión Epson TM-T20II

La impresión física ya funciona actualmente en el entorno Windows local.

**No cambiar ahora la lógica de impresión que ya funciona.**

Revisar únicamente que el código de impresión no contenga:

- rutas absolutas innecesarias de la PC de desarrollo;
- credenciales;
- scripts temporales;
- configuraciones que deberían estar en `.env` o configuración por sucursal.

Documentar claramente qué componente depende de la PC Windows del restaurante.

### Arquitectura esperada en producción

Laravel estará alojado en SiteGround:

```text
Internet
   |
   v
SiteGround
Laravel + MySQL
```

La impresora continuará físicamente en:

```text
PC Windows del restaurante
        |
        | USB
        v
EPSON TM-T20II
```

No asumir que SiteGround puede acceder directamente al USB de la Epson.

Identificar claramente cómo la solución actual deberá comunicarse con la PC local cuando el sistema deje de ejecutarse en `localhost`.

**No rediseñar esta arquitectura en esta tarea. Solo documentar el punto pendiente para el despliegue.**

---

## 9. Revisar regresiones críticas

Antes de declarar el proyecto listo, confirmar que siguen funcionando:

- Login
- Usuarios
- Roles
- Permisos
- Venta
- Mesas
- Pedidos
- Cocina
- Caja
- Apertura de caja
- Cierre de caja
- Movimientos de efectivo
- Retiro propietario
- Pagos en efectivo
- Pagos QR
- Pagos mixtos
- Pagos parciales
- Inventario
- Recetas
- Compras
- Gastos
- Reportes
- KitchenDispatch
- Comandas
- Reimpresión
- Ticket de cliente/caja

No modificar módulos que no presenten regresiones.

---

## 10. Git

Todavía NO:

- crear repositorio;
- ejecutar push;
- hacer deploy;
- modificar producción.

Sí revisar que el proyecto esté preparado para versionarse.

Si ya existe `.git`, informar:

- rama actual;
- archivos modificados;
- archivos sin seguimiento;
- archivos potencialmente sensibles.

No descartar cambios automáticamente.

---

## 11. Resultado obligatorio

Al terminar responder exactamente con esta estructura:

# ESTADO

`LISTO PARA REPOSITORIO`

o

`NO LISTO PARA REPOSITORIO`

---

# USUARIO ADMINISTRATIVO EXISTENTE

- Nombre:
- Correo:
- Rol:
- Empresa:
- Estado:

No mostrar contraseña ni hash.

---

# VALIDACIONES

- `php artisan migrate:status`:
- `php artisan test`:
- Tests:
- Assertions:
- `vendor/bin/pint --test`:
- `npm run build`:

---

# GIT

- Estado:
- Rama:
- Archivos que deben quedar fuera:
- Archivos temporales encontrados/eliminados:
- Posibles secretos/configuraciones problemáticas:

---

# MIGRACIONES

- Migraciones pendientes:
- Compatibilidad MySQL:
- Riesgos:
- Operaciones destructivas encontradas:

---

# IMPRESIÓN EPSON

Indicar:

- estado actual de la impresión local;
- qué parte depende de Windows;
- qué parte no funcionará directamente desde SiteGround;
- qué habrá que resolver/configurar durante el despliegue.

No afirmar que se probó físicamente algo que no se haya probado.

---

# PRODUCCIÓN SITEGROUND

Enumerar las configuraciones manuales necesarias para el servidor:

- PHP;
- Composer;
- `.env`;
- MySQL;
- `APP_URL`;
- `APP_DEBUG=false`;
- permisos;
- document root/public;
- migraciones;
- build frontend;
- cachés Laravel.

---

# RIESGOS PENDIENTES

Indicar solamente riesgos reales encontrados.

Si no hay riesgos críticos, decirlo explícitamente.

---

# CONCLUSIÓN

Indicar si el código está preparado para realizar el **primer push al repositorio privado**.

## Restricciones finales

- NO hacer push.
- NO crear repositorio.
- NO hacer deploy.
- NO conectarse a SiteGround.
- NO ejecutar `migrate:fresh`.
- NO borrar datos.
- NO crear un nuevo administrador sin autorización.
- NO modificar nuevamente la impresión Epson si ya funciona.
- Detenerse después de entregar el informe.
