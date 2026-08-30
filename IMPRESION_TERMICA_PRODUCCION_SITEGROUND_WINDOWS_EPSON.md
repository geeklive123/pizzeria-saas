# IMPRESIÓN TÉRMICA EN PRODUCCIÓN — SITEGROUND → WINDOWS → EPSON TM-T20II

## Objetivo

Adaptar la impresión térmica para el entorno real de producción.

Contexto confirmado:

- Laravel estará alojado en SiteGround/Linux.
- La impresora Epson TM-T20II está físicamente conectada por USB a una PC Windows en el restaurante.
- La impresora ya fue validada físicamente desde Windows.
- La impresión RAW/ESC-POS ya fue probada localmente cuando Laravel corría en la misma PC.
- SiteGround NO puede acceder directamente al USB ni al spooler de Windows.

Por tanto, la impresión debe separarse en dos partes:

1. Laravel/SiteGround crea y gestiona los trabajos de impresión.
2. Un agente local en Windows consulta esos trabajos y los imprime en la Epson.

No modificar la lógica de negocio de pedidos, KitchenDispatch, inventario, pagos o caja.

---

# 1. Arquitectura objetivo

```text
Laravel / SiteGround
        |
        | HTTPS
        v
Trabajos de impresión pendientes
        |
        | consulta segura
        v
Agente local Windows
        |
        | Windows Spooler / RAW ESC-POS
        v
EPSON TM-T20II
```

La PC Windows del restaurante es la única que debe acceder físicamente a la impresora USB.

---

# 2. Fuente de verdad

Laravel continúa siendo la fuente de verdad.

Para cocina:

```text
Order
→ KitchenDispatch
→ PrintJob
```

Para caja/cliente:

```text
Order / Payments
→ CustomerTicket PrintJob
```

No crear otra lógica paralela de pedidos.

KitchenDispatch debe seguir representando la tanda enviada a cocina.

---

# 3. Trabajo de impresión

Crear una entidad/estructura de trabajo de impresión compatible con la arquitectura existente.

Debe contener como mínimo:

- id / ULID
- company_id
- branch_id
- tipo de impresión
- referencia al recurso original
- estado
- cantidad de intentos
- fecha de creación
- fecha de claim
- fecha de impresión
- último error
- impresora lógica/configurada
- payload o datos suficientes para renderizar de forma segura

Tipos mínimos:

- `KITCHEN_DISPATCH`
- `CUSTOMER_TICKET`

Estados equivalentes a:

- `pending`
- `claimed` / `processing`
- `printed`
- `failed`

Usar enums existentes si hay una arquitectura equivalente.

---

# 4. Comanda de cocina

Cuando se ejecuta:

`Enviar nuevos a cocina`

debe mantenerse el flujo actual:

1. Crear/confirmar KitchenDispatch.
2. No volver a reservar inventario.
3. No volver a descontar ingredientes.
4. Crear un único trabajo de impresión asociado a ese KitchenDispatch.

La comanda debe contener exclusivamente la tanda nueva.

NO volver a imprimir productos de tandas anteriores.

---

# 5. Reimpresión de comanda

La acción:

`Reimprimir última comanda`

debe:

- reutilizar un KitchenDispatch existente;
- crear un nuevo intento/trabajo físico si la arquitectura lo requiere;
- NO crear otro KitchenDispatch;
- NO reservar inventario;
- NO descontar ingredientes;
- NO modificar cantidad vendida;
- NO modificar estados de cocina.

La reimpresión debe ser auditable.

---

# 6. Ticket de cliente/caja

Debe generarse desde los datos financieros reales ya guardados:

- Order
- Payments
- CashSession
- snapshots existentes

Debe soportar:

- 100% efectivo
- 100% QR
- pago mixto
- pago parcial
- recibido
- cambio
- saldo pendiente

No crear un método ficticio `MIXED` si el dominio mantiene Payments separados.

---

# 7. Agente local Windows

Crear un agente pequeño, simple y mantenible para Windows.

No construir una aplicación compleja si no es necesaria.

Debe:

1. Arrancar en la PC del restaurante.
2. Leer su configuración local.
3. Consultar periódicamente SiteGround mediante HTTPS.
4. Solicitar trabajos pendientes de su sucursal.
5. Reclamar un trabajo de forma atómica.
6. Imprimir usando el transporte RAW/ESC-POS ya validado.
7. Confirmar éxito.
8. Reportar error si falla.
9. Continuar con el siguiente trabajo.
10. Mantener logs locales.

No depender de una IP fija.

---

# 8. Configuración local del agente

La configuración debe ser local y NO versionarse con secretos.

Ejemplo conceptual:

```env
PRINT_AGENT_URL=https://kenkystores27.sg-host.com
PRINT_AGENT_TOKEN=...
PRINT_AGENT_BRANCH=...
KITCHEN_PRINTER="EPSON TM-T20II Receipt"
CUSTOMER_PRINTER="EPSON TM-T20II Receipt"
POLL_INTERVAL_SECONDS=3
```

No hardcodear:

- nombre de PC
- puerto USB
- IP local
- token
- URL
- credenciales

La impresora puede configurarse por nombre de Windows.

---

# 9. Seguridad

El agente NO debe usar endpoints públicos sin autenticación.

Implementar autenticación segura para máquina/agente.

Requisitos:

- token aleatorio y suficientemente largo;
- token almacenado de forma segura;
- HTTPS obligatorio;
- token asociado a empresa/sucursal/agente;
- revocable;
- no exponerlo al navegador/frontend;
- no escribirlo en logs;
- no subirlo a Git.

Si existe una arquitectura de API tokens segura, reutilizarla.

No inventar autenticación débil basada únicamente en `branch_id`.

---

# 10. Claim atómico

Evitar doble impresión cuando:

- hay dos agentes;
- el agente reintenta;
- la conexión se corta;
- Laravel responde tarde;
- el proceso reinicia.

El claim debe hacerse de forma transaccional/atómica.

Un trabajo reclamado por un agente no puede ser reclamado simultáneamente por otro.

Usar:

- transacción;
- lock apropiado;
- condición de estado;
- idempotencia;

según arquitectura existente.

---

# 11. Idempotencia

Cada trabajo debe tener una identidad estable.

Ejemplo:

```text
KITCHEN_DISPATCH:<dispatch_ulid>:original
```

Una nueva comanda no debe producir múltiples trabajos originales por doble click.

Las reimpresiones sí pueden ser intentos explícitos separados, pero deben estar marcadas como reimpresión.

Un trabajo en estado `printed` NO debe volver a imprimirse automáticamente.

---

# 12. Offline / sin Internet

Si la PC Windows está apagada, sin Internet o la Epson está desconectada:

- Laravel sigue guardando el pedido.
- KitchenDispatch sigue siendo válido.
- El trabajo de impresión permanece `pending` o pasa a `failed` según corresponda.
- No se pierde.
- No se revierte la venta.
- No se revierte inventario.
- No se duplica.

Cuando el agente vuelva:

- consultar pendientes;
- procesarlos en orden razonable;
- imprimir;
- marcar como completados.

---

# 13. Trabajo reclamado y agente caído

Evitar que un trabajo quede bloqueado para siempre en `claimed`.

Definir timeout razonable.

Ejemplo conceptual:

Si un trabajo permanece reclamado más de X minutos sin confirmación:

- permitir recuperación/reintento seguro.

No inventar un tiempo arbitrario si ya existe configuración equivalente.
Si se introduce, hacerlo configurable.

---

# 14. Manejo de errores

Si Windows/impresora devuelve error:

- registrar error local;
- informar al servidor;
- incrementar intentos;
- estado `failed` o equivalente;
- permitir retry.

No mostrar al usuario excepciones técnicas crudas.

En Laravel mostrar algo como:

> La comanda está pendiente de impresión.

o:

> No se pudo imprimir. Puedes reintentar.

---

# 15. Formato cocina

Reutilizar el formato ya implementado/probado.

Debe mostrar:

- MASA & MAÑA
- MESA X o PARA LLEVAR
- N.º pedido
- fecha/hora
- mesera
- producto
- tamaño
- sabores
- cantidad
- observaciones
- extras/removidos cuando existan

NO precios.

---

# 16. Formato cliente/caja

Reutilizar el formato existente.

Debe incluir:

- MASA & MAÑA
- pedido
- mesa/para llevar
- cajera
- productos
- cantidades
- precios
- subtotal
- descuento
- total
- pagos
- efectivo
- QR
- recibido
- cambio
- saldo pendiente cuando aplique

---

# 17. Aislamiento por sucursal

El agente solo puede consultar trabajos de su empresa/sucursal autorizada.

No debe poder:

- consultar otras empresas;
- consultar otras sucursales;
- marcar como impreso un trabajo ajeno.

Agregar pruebas explícitas de aislamiento.

---

# 18. Configuración de impresoras en Laravel

Mantener la configuración existente por sucursal.

Laravel puede seguir sabiendo:

- impresora cocina habilitada
- impresora cliente habilitada
- copias
- impresión automática

Pero el nombre físico final puede ser resuelto por el agente local.

Evitar acoplar SiteGround a nombres de spooler de Windows.

---

# 19. Modo de simulación sin impresora física

Actualmente la Epson no está disponible físicamente durante desarrollo.

Crear un modo seguro de pruebas.

Ejemplo:

```env
PRINT_AGENT_TRANSPORT=file
```

En ese modo:

- el agente consulta SiteGround normalmente;
- reclama trabajos;
- genera el contenido ESC/POS o representación equivalente;
- lo guarda en una carpeta local;
- confirma el flujo sin enviar al spooler.

Ejemplo:

```text
storage/print-agent-output/
    kitchen-01.bin
    ticket-02.bin
```

No modificar el dominio para simular.

Cuando se vuelva al restaurante:

```env
PRINT_AGENT_TRANSPORT=windows_raw
```

y utilizar la Epson física.

---

# 20. Logs locales

El agente debe generar logs útiles:

- fecha/hora;
- job id;
- tipo;
- impresora;
- claim;
- éxito/error;
- duración.

NO registrar:

- tokens;
- contraseñas;
- datos financieros sensibles innecesarios.

Rotar/limitar logs si es razonable.

---

# 21. Ejecución automática en Windows

Documentar dos formas:

## Manual

Ejemplo conceptual:

```powershell
php artisan print-agent:work
```

o el ejecutable/script correspondiente.

## Automática

Preparar instrucciones para:

- Programador de tareas de Windows;
- servicio Windows si es razonable;
- ejecución al iniciar sesión/arrancar PC.

No exigir que la cajera abra una consola manualmente cada día si puede evitarse.

Priorizar solución simple y estable.

---

# 22. Health check

El sistema debe poder mostrar al owner/admin información operativa como:

- último contacto del agente;
- agente online/offline;
- última impresión;
- impresiones pendientes;
- impresiones fallidas.

No convertir esto en un dashboard complejo.

Una tarjeta pequeña de estado sería suficiente.

---

# 23. UI de impresión

En pedido:

- Enviar nuevos a cocina
- estado de impresión
- Reimprimir última comanda
- Reintentar impresión si falló

Ejemplos de estado:

- Pendiente
- Enviando
- Impresa
- Error

No confundir “enviado a cocina” con “impreso”.

Son dos estados distintos.

---

# 24. No bloquear cocina por impresora

La falla de impresión NO debe impedir que el pedido sea enviado a cocina a nivel de dominio.

Es decir:

```text
KitchenDispatch = creado
PrintJob = pending/failed
```

No:

```text
impresora falla
→ rollback KitchenDispatch
```

---

# 25. Pruebas obligatorias

Agregar pruebas para:

1. Nueva tanda crea un solo trabajo.
2. Doble petición no crea dos trabajos originales.
3. Reimpresión no crea otro KitchenDispatch.
4. Reimpresión no toca inventario.
5. Agente solo ve su sucursal.
6. Token inválido es rechazado.
7. Claim es atómico.
8. Dos agentes no imprimen el mismo job.
9. Job printed no vuelve a salir.
10. Failed puede reintentarse.
11. Agente offline deja trabajo pendiente.
12. Trabajo stale/claimed puede recuperarse según regla definida.
13. Kitchen ticket no contiene precios.
14. Customer ticket contiene información financiera correcta.
15. Pago mixto se representa correctamente.
16. File transport guarda el trabajo localmente.
17. Confirmación de impresión actualiza el job correcto.
18. Agente no puede confirmar jobs ajenos.
19. Reimpresión queda auditada.
20. KitchenDispatch continúa válido aunque impresión falle.

---

# 26. Validación

Ejecutar:

- pruebas específicas de impresión;
- pruebas API/agente;
- suite completa;
- `vendor/bin/pint --test`;
- `npm run build`;
- `git diff --check`.

---

# 27. Documentación obligatoria

Crear una guía clara:

```text
docs/print-agent-windows.md
```

Debe explicar:

1. Arquitectura.
2. Instalación en PC Windows.
3. Configuración.
4. Token.
5. Nombre de Epson.
6. Modo file/simulación.
7. Modo impresión física.
8. Cómo iniciarlo.
9. Cómo automatizar inicio.
10. Cómo ver logs.
11. Cómo reiniciar.
12. Qué hacer si Internet cae.
13. Qué hacer si la impresora se desconecta.
14. Cómo rotar/revocar token.
15. Cómo probar una comanda.

---

# 28. Restricciones

- NO cambiar reglas de caja.
- NO cambiar reglas de inventario.
- NO cambiar KitchenDispatch salvo integración necesaria.
- NO duplicar reservas.
- NO duplicar consumo.
- NO crear pagos ficticios.
- NO exponer token en frontend.
- NO hardcodear IP.
- NO hardcodear nombre PC.
- NO hardcodear puerto USB.
- NO depender de acceso directo SiteGround → USB.
- NO `migrate:fresh`.
- NO borrar datos.
- NO tocar producción.
- NO commit.
- NO push.
- NO deploy.

---

# 29. Informe final

Entregar:

1. Arquitectura final.
2. Archivos creados/modificados.
3. Migraciones creadas.
4. Endpoints creados.
5. Estrategia de autenticación.
6. Estrategia de idempotencia/claim.
7. Flujo offline.
8. Flujo de retry.
9. Instalación del agente.
10. Modo simulación.
11. Modo Epson física.
12. Tests.
13. Pint.
14. Build.
15. Riesgos pendientes.
16. Comandos exactos para probar desde una PC Windows antes de deploy.

No hacer commit, push ni deploy todavía.
