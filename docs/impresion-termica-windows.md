# Impresión térmica local en Windows

## Diferencia entre CLI y HTTP comprobada

La impresión CLI y la impresión iniciada desde Ajustes usan el mismo recorrido:

`SettingsController::printTest()` → `PrintTestPageAction` → `ThermalPrintingService::testPage()` → `WindowsRawPrinterTransport::send()`.

La configuración comprobada usa la impresora exacta `EPSON TM-T20II Receipt`. En tiempo de ejecución Laravel resuelve el script con `base_path('scripts/windows-raw-print.ps1')` y los documentos temporales con `storage_path('app/private/thermal-printing')`, por lo que el repositorio no depende de una ruta fija de la computadora de desarrollo.

El fallo observado no estaba en el controlador, el transporte, PowerShell ni el spooler. El servidor PHP de `localhost:8000` había sido iniciado con un entorno sin `TEMP` y `TMP`. En ese contexto, `sys_get_temp_dir()` apuntaba a un directorio del sistema sin permisos de escritura; Symfony Process no podía crear allí `sf_proc_*.out.lock` para capturar `stdout` y `stderr`, y nunca alcanzaba a iniciar PowerShell.

En CLI, el mismo usuario de Windows tenía un temporal de usuario escribible, por lo que Symfony podía iniciar el mismo comando. CLI y HTTP pueden tener directorios de trabajo distintos; esa diferencia no afecta la impresión porque Laravel entrega al proceso rutas absolutas resueltas desde `base_path()` y `storage_path()`.

## Inicio seguro del servidor local

El proceso web debe heredar `TEMP` y `TMP` válidos o recibir explícitamente un `sys_temp_dir` escribible. Desde la raíz del proyecto puede iniciarse de forma explícita con:

```powershell
$projectRoot = (Resolve-Path '.').Path
$php = (Get-Command php).Source
$temp = Join-Path $projectRoot 'storage\app\private\thermal-printing'
$router = Join-Path $projectRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'

& $php -d ('sys_temp_dir=' + $temp) -S '127.0.0.1:8000' $router
```

También es válido iniciar Laravel desde una terminal normal de Windows que conserve un `TEMP/TMP` escribible.

## Resultado esperado en el log

Un envío correcto deja en `storage/logs/laravel.log` el comando resuelto a PowerShell, `started: true`, `exit_code: 0`, `stdout` con `RAW_OK` y `stderr` vacío. Si el proceso no logra iniciarse, el transporte conserva ahora la excepción original y registra además el directorio de trabajo, el temporal de PHP y si este es escribible.

La PC Windows del restaurante debe tener acceso al spooler y la impresora instalada con el mismo nombre configurado por sucursal.

## Límite para producción remota

`WindowsRawPrinterTransport` solo funciona donde PHP puede ejecutar PowerShell y acceder al spooler de Windows. SiteGround no puede acceder directamente al puerto USB de la impresora ubicada en el restaurante. Antes del despliegue operativo deberá definirse y configurar un agente o puente seguro en la PC Windows que reciba trabajos del servidor remoto. Ese enlace no forma parte de la implementación actual.
