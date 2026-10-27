# Publicación controlada de `/tienda` en SiteGround

Esta guía prepara el despliegue y no autoriza ejecutarlo. La tienda forma parte
del Laravel existente y no requiere otro subdominio, otra base de datos ni una
segunda aplicación.

## 1. Referencia y document root

Antes de publicar, identificar el release real y comprobar si el document root
es el `public/` de Laravel o una carpeta distinta:

```bash
cd ~/www/kenkystores27.sg-host.com/pizzeria-saas || exit 1
pwd
git rev-parse HEAD
git status --short --branch --untracked-files=all
php -v
readlink -f public
ls -ld ~/www/kenkystores27.sg-host.com/public_html
readlink -f ~/www/kenkystores27.sg-host.com/public_html
sed -n '1,35p' ~/www/kenkystores27.sg-host.com/public_html/index.php
```

No continuar si el SHA, el estado del checkout o las rutas no coinciden con el
release aprobado. Estos comandos no leen `.env` ni escriben en producción.

Laravel `@vite` lee `APP_ROOT/public/build/manifest.json`, pero el navegador
solicita `/build/assets/...` desde el document root real. Si ambas rutas son
distintas, el mismo artefacto debe publicarse en las dos ubicaciones.

## 2. Construcción del artefacto

Desde Windows, con el commit aprobado y dependencias npm bloqueadas:

```powershell
npm.cmd ci
powershell -ExecutionPolicy Bypass -File scripts/package-vite-assets.ps1
```

El script compila en `storage/app/private/deployment/vite-build`, valida las
cuatro entradas obligatorias (panel CSS/JS y tienda CSS/JS) y genera
`vite-build.zip` con SHA-256. El contenido de `public/build` no forma parte del
PR: es un artefacto indivisible del release.

## 3. Publicación de assets

1. Respaldar el `build` que usa Laravel y el `build` servido por el dominio.
2. Descomprimir el artefacto en una carpeta temporal dentro de cada destino.
3. Verificar `manifest.json` y todos los archivos que referencia.
4. Copiar primero `assets/` y fuentes con hash.
5. Publicar `manifest.json` al final.
6. Conservar los assets anteriores hasta terminar el smoke test.

No publicar solo `storefront-*.css/js`: el manifest es compartido con el panel
administrativo. Tampoco ejecutar `npm run build` en SiteGround como sustituto de
la publicación del artefacto validado.

## 4. Código y cachés

La entrega no incorpora migraciones ni dependencias de producción. Después de
publicar el código revisado y los assets:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

No ejecutar `migrate`, `migrate:rollback`, seeders ni cambios de `.env` como
parte de esta publicación.

## 5. Verificación

Comprobar `/login`, `/dashboard`, el POS y las cuatro pantallas de `/tienda`.
En modo demostración, la respuesta del checkout debe contener `demo: true` y
`url: null`; ninguna vista debe contener `https://wa.me/`.

Verificar además HTTP 200 para cada archivo de `manifest.json` desde el dominio
público. El CSS/JS administrativo y el CSS/JS de tienda deben proceder del mismo
artefacto.

## 6. Reversión

Como no existen migraciones nuevas, la reversión consiste en restaurar el
release de código anterior y ambos respaldos de `build`, limpiar/regenerar cachés
y repetir el smoke test. No borrar datos ni revertir migraciones.
