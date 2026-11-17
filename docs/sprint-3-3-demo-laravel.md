# Demostración local del QR online con Laravel

Esta demostración inicia Laravel en `127.0.0.1`, crea una base SQLite nueva dentro de `tmp/` y usa un banco simulado en el servidor. No usa la base operativa ni credenciales bancarias. El adaptador intercepta todas las peticiones HTTP de Laravel y rechaza las que no reconoce. La imagen devuelta por Laravel dice «NO PAGABLE» y no es un QR bancario válido. No ejecutar este arnés como aplicación de producción.

La configuración QR se activa exclusivamente en memoria dentro del proceso de demostración. El archivo `.env`, el valor por defecto `false` y el flujo presencial no se modifican. El código del arnés está bajo `tests/Visual`, fuera del directorio público, y exige CLI, Windows, la ruta local `C:\Dev\masa-mana-tienda-fotos` y una base aislada con marcador propio. Rechaza explícitamente el entorno de producción. El control de confirmación exige un token aleatorio del directorio local y solo acepta conexiones de loopback.

## Iniciar

Desde la raíz del proyecto, en PowerShell, usar PHP 8.4.24 de Herd:

```powershell
$demoDir = & 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe' tests/Visual/online-demo-init.php
$env:ONLINE_QR_DEMO_DIR = $demoDir.Trim()
& 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe' -S 127.0.0.1:18765 -t public tests/Visual/online-demo-router.php
```

Mantener esa consola abierta. Cada ejecución de `online-demo-init.php` crea una carpeta y base nuevas; no reutilizar una base con una compra anterior para ejecutar la prueba automática. Detener el servidor con Ctrl+C. No borrar las carpetas de demostración como parte de esta prueba.

## Compra manual

1. Abrir `http://127.0.0.1:18765/tienda/menu`, agregar el producto ficticio y pasar al checkout.
2. Seleccionar delivery, completar datos ficticios, elegir un punto cercano al centro del mapa y confirmarlo. La búsqueda de direcciones externa se bloquea; se puede seleccionar el punto manualmente.
3. Pulsar «Preparar pedido». Laravel crea la orden, la reserva y la intención del banco simulado; debe aparecer la imagen de prueba con importe y estado de espera.
4. En otra consola PowerShell, indicar la misma carpeta creada en el primer paso y activar la confirmación ficticia:

```powershell
$env:ONLINE_QR_DEMO_DIR = 'RUTA_DE_LA_CARPETA_DEMO_CREADA'
$demoInfo = Get-Content -Raw -LiteralPath (Join-Path $env:ONLINE_QR_DEMO_DIR 'info.json') | ConvertFrom-Json
Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:18765/__demo/paid' -Headers @{ 'X-Demo-Token' = $demoInfo.control_token }
```

5. En el checkout pulsar «Verificar estado del pago». En `/login`, entrar al panel con el usuario **ficticio** `demo@example.test` y la contraseña **ficticia** `Demo-local-12345`; abrir la solicitud en pedidos online. Deben aparecer el pago, la caja original, la reserva consumida y dos trabajos de impresión ficticios.

## Repetición automatizada

Para Playwright Core, instalarlo en una carpeta temporal aislada y ejecutar en otra consola con la misma variable `ONLINE_QR_DEMO_DIR` y el servidor activo:

```powershell
npm.cmd install --prefix tmp/sprint33-playwright-local --no-save --package-lock=false --ignore-scripts --no-audit --no-fund playwright-core@1.57.0
node tests/Visual/online-demo-browser.cjs
```

Para móvil, definir `$env:ONLINE_QR_DEMO_MOBILE = '1'` antes de ejecutar. El navegador bloquea las solicitudes externas y no intercepta ni sustituye las respuestas del servidor Laravel. El script verifica la intención persistida, una reserva, un pago, un consumo y dos impresiones, y guarda las capturas en el directorio de esa base. La prueba requiere Chrome instalado en su ruta habitual de Windows.
