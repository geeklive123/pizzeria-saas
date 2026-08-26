# Agente de impresión SiteGround → Windows → Epson

## Arquitectura

Laravel en SiteGround es la fuente de verdad. Al enviar una tanda crea el `KitchenDispatch` y, si la impresión automática de cocina está activa, encola un único `PrintAttempt` original. Los tickets de cliente se renderizan con los pedidos, snapshots y pagos reales. La falla o ausencia del agente no revierte pedidos, cocina, inventario, pagos ni caja.

La PC Windows consulta por HTTPS una cola limitada por el token a una empresa y sucursal, reclama un trabajo dentro de una transacción, imprime el ESC/POS y confirma el resultado. SiteGround nunca accede al USB ni al spooler de Windows.

## 1. Preparación en SiteGround

Antes de migrar, respalda la base y revisa `database/migrations/2026_08_26_120000_add_remote_print_agent_queue.php`. La migración crea `print_agents` y agrega a `print_attempts` payload, idempotencia, claim, vencimiento, intentos y fechas. No ejecuta borrados de datos.

Desde la raíz de la aplicación desplegada:

```bash
php artisan migrate --force
php artisan print-agent:provision --company="ULID_EMPRESA" --branch="ULID_SUCURSAL" --name="PC Caja" --force
```

El segundo comando muestra el token una sola vez. Cópialo directamente al `.env` de la PC; no lo pegues en tickets, navegador, chat, repositorio ni logs.

En Configuración de la sucursal:

1. Activa Cocina y/o Ticket cliente.
2. Configura copias.
3. Activa impresión automática de cocina si corresponde.
4. El nombre guardado allí es lógico/auditable; el agente resuelve el nombre físico local.

## 2. Instalación en la PC Windows

Requisitos:

- PHP 8.4 CLI.
- El código de esta misma versión y su carpeta `vendor` (ejecuta `composer install --no-dev --optimize-autoloader` si hace falta).
- Acceso HTTPS saliente al dominio de SiteGround.
- Para impresión física, driver Epson instalado y una cola visible en Windows.

Crea un `.env` local no versionado. Conserva las demás variables necesarias para arrancar Laravel y añade:

```dotenv
APP_ENV=production
APP_DEBUG=false
PRINT_AGENT_URL=https://kenkystores27.sg-host.com
PRINT_AGENT_TOKEN=PEGA_AQUI_EL_TOKEN_DE_80_CARACTERES
PRINT_AGENT_TRANSPORT=file
KITCHEN_PRINTER="EPSON TM-T20II Receipt"
CUSTOMER_PRINTER="EPSON TM-T20II Receipt"
POLL_INTERVAL_SECONDS=3
PRINT_AGENT_REQUEST_TIMEOUT=20
PRINT_AGENT_REQUIRE_HTTPS=true
```

Opcionalmente configura rutas absolutas:

```dotenv
PRINT_AGENT_OUTPUT_DIRECTORY=C:/pizzeria-agent/output
PRINT_AGENT_STATE_FILE=C:/pizzeria-agent/private/print-agent-state.json
```

No copies el token a `.env.example`. Después de modificar el `.env`:

```powershell
php artisan config:clear
```

El token ya determina empresa y sucursal; el agente no confía en un `branch_id` enviado por la PC.

## 3. Prueba nube → PC sin impresora

Mantén `PRINT_AGENT_TRANSPORT=file`.

1. En SiteGround provisiona el agente y configura/activa la impresora lógica de la sucursal.
2. En la web crea un pedido con un producto nuevo y pulsa **Enviar nuevos a cocina**. Para cliente, abre **Cobrar** y pulsa **Imprimir ticket**.
3. En la PC ejecuta una sola consulta:

```powershell
php artisan print-agent:work --once
```

También puedes usar el lanzador:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-print-agent.ps1 -PhpExecutable "C:\ruta\a\php.exe" -Once
```

4. Debe aparecer un archivo `.bin` por copia en `storage/print-agent-output` o en `PRINT_AGENT_OUTPUT_DIRECTORY`.
5. En Configuración, confirma que el agente figure **En línea**, que bajen los pendientes y que el trabajo aparezca **Impreso** en el pedido.
6. Abre el `.bin` solo con un visor hexadecimal o conserva el archivo como evidencia; contiene ESC/POS y no es texto UTF-8 puro.

Para escuchar continuamente:

```powershell
php artisan print-agent:work
```

## 4. Impresión física Epson

Obtén el nombre exacto de la cola:

```powershell
Get-Printer | Select-Object Name, PrinterStatus
```

Actualiza la PC:

```dotenv
PRINT_AGENT_TRANSPORT=windows_raw
KITCHEN_PRINTER="NOMBRE EXACTO DE GET-PRINTER"
CUSTOMER_PRINTER="NOMBRE EXACTO DE GET-PRINTER"
```

Ejecuta `php artisan config:clear`, reinicia el agente y en la web usa **Imprimir prueba**. El transporte llama al spooler con RAW/ESC-POS mediante `scripts/windows-raw-print.ps1`.

## 5. Inicio automático

### Programador de tareas (recomendado)

1. Abre **Programador de tareas** → **Crear tarea**.
2. Cuenta: usuario de Windows que tiene acceso a la Epson; marca **Ejecutar con los privilegios más altos**.
3. Desencadenador: **Al iniciar el sistema** o **Al iniciar sesión**.
4. Programa: `powershell.exe`.
5. Argumentos:

```text
-NoProfile -ExecutionPolicy Bypass -File "C:\ruta\pizzeria-saas\scripts\start-print-agent.ps1" -PhpExecutable "C:\ruta\php.exe"
```

6. Iniciar en: `C:\ruta\pizzeria-saas`.
7. Configura reinicio tras fallo (por ejemplo, cada minuto, hasta tres veces).

La ejecución manual usa el mismo script. No es necesario instalar un servicio adicional.

## 6. Logs, reinicio y fallos

- Logs: `storage/logs/laravel.log`. Registran fecha, ULID del job, tipo, impresora, copias, duración y error; nunca el token ni el payload.
- Reinicio: termina el proceso con Ctrl+C si es manual y vuelve a ejecutar el worker. En Programador de tareas, usa **Finalizar** y **Ejecutar**.
- Sin Internet/PC apagada: los trabajos quedan pendientes en SiteGround. Al volver, se procesan por fecha e ID.
- Epson desconectada: el agente reporta `failed`; el servidor vuelve a ofrecer el job después del retraso configurado. También puede usarse **Reintentar impresión**.
- Claim abandonado: vence después de `PRINT_AGENT_CLAIM_TIMEOUT` (120 segundos por defecto) y puede reclamarlo otro agente.
- ACK perdido después de imprimir: la PC guarda hasta 500 ULID impresos en el archivo de estado y, al recuperar el mismo job, confirma sin volver a enviarlo al spooler.

No borres el archivo de estado mientras existan claims pendientes. Para minimizar la pequeña ventana inevitable entre impresión física y persistencia local, usa un solo agente activo por sucursal salvo contingencia.

## 7. Token: rotación y revocación

Rotar crea un token nuevo e invalida inmediatamente el anterior:

```bash
php artisan print-agent:provision --company="ULID_EMPRESA" --branch="ULID_SUCURSAL" --name="PC Caja" --rotate --force
```

Actualiza `PRINT_AGENT_TOKEN` en la PC, ejecuta `php artisan config:clear` y reinicia el worker.

Revocar:

```bash
php artisan print-agent:revoke ULID_AGENTE --force
```

Una respuesta 401 indica token ausente, inválido, rotado o revocado. Una respuesta que exige HTTPS indica URL/proxy mal configurados; no desactives HTTPS en producción.

## 8. Comprobaciones rápidas

```powershell
php artisan about
php artisan route:list --path=api/print-agent
php artisan print-agent:work --once
Get-ChildItem .\storage\print-agent-output
Get-Content .\storage\logs\laravel.log -Tail 50
```

Estados: **Pendiente** aún no fue reclamado; **Enviando** tiene claim vigente; **Impresa** fue confirmada; **Error** espera retry o revisión. “Enviado a cocina” y “impreso” son estados independientes.
