# Revisión de clasificación de tickets de tanda

La auditoría detectó el caso con una configuración sintética sin impresora de tickets. Las impresoras físicas actuales funcionan según la información facilitada por el usuario; esta revisión no constata una incidencia en producción y no inspeccionó ni cambió su configuración.

## Causa y alcance

`ThermalPrintingService::dispatchTicket()` buscaba una impresora `customer_ticket` y enviaba el trabajo a `enqueue()` sin indicar el propósito alternativo. Si la impresora no existía, `enqueue()` infería `kitchen` porque el ticket estaba asociado a una tanda. El intento quedaba fallido, con clave de ticket, pero clasificado como cocina. `retryNeverQueuedTicket()` solo recupera intentos `customer_ticket`, por lo que el mismo registro no se reencolaba de forma segura al configurar posteriormente una impresora de tickets.

Una reproducción en SQLite en memoria confirmó ese comportamiento antes de editar el servicio: el intento era `kitchen` y `failed`, y continuaba fallido después de agregar una configuración sintética de ticket y solicitar el reintento. La prueba de regresión esperada también falló antes del cambio por la clasificación incorrecta.

## Cambio local y límites

Se pasa explícitamente `PrinterPurpose::CustomerTicket` a `enqueue()` desde `dispatchTicket()`. Las rutas con impresora configurada continúan obteniendo el propósito desde esa configuración; no se cambian el transporte, las claves de idempotencia, el agente ni las configuraciones reales. La regresión comprueba que un nuevo intento sin impresora sea `customer_ticket` y fallido, y que, al habilitar una impresora sintética, se recupere el mismo ID como pendiente sin crear duplicados.

El cambio actúa sobre intentos **nuevos**. Un registro histórico ya clasificado como `kitchen` conserva su clasificación y no queda reparado automáticamente; no se modificaron datos históricos. No hay evidencia aquí de que exista tal registro en producción. Si se identifica alguno, su diagnóstico y eventual corrección requieren un procedimiento específico y autorizado.

El defecto afecta una configuración ausente, no demuestra un fallo en las impresoras actualmente operativas. Se recomienda incorporar la corrección en el paquete que se valide antes de una actualización controlada, sin intervenir los dispositivos actuales. Las pruebas sintéticas no sustituyen la aceptación en el entorno real.

## Validación

La prueba específica pasó con 10 aserciones. Las clases de regresión de impresión y QR pasaron en SQLite en memoria: 118 pruebas y 944 aserciones, sin fallos ni omisiones; sus transportes y respuestas bancarias son simulados.

La suite completa posterior pasó con 671 pruebas aprobadas, una omitida, ninguna fallida y 5.397 aserciones. Se ejecutó con SQLite `:memory:` y con un archivo PHP temporal que carga únicamente el controlador PDO SQLite, sin `pdo_mysql`; el chequeo previo confirmó que la base SQLite no tenía archivo asociado. El `migrate:fresh` interno de las pruebas se limitó a ese entorno, conforme a la autorización expresa del cierre. La omisión corresponde a una prueba de bloqueos MySQL que no se ejecuta en SQLite.

En MariaDB local `pizzeria_saas`, una empresa, pedido y tanda sintéticos, con impresora de cocina sintética y sin impresora de ticket, generaron un intento `customer_ticket` fallido. Al agregar una impresora de ticket sintética, el reintento conservó el mismo ID y quedó pendiente, sin duplicados. La transacción se revirtió; se compararon las huellas y conteos de las 74 tablas originales y todos coincidieron, sin intentos financieros activos. No se enviaron trabajos físicos ni se modificó la configuración histórica de impresoras.

JavaScript: 27 pruebas aprobadas. Pint: aprobado. Vite compiló en un directorio temporal y sus 11 recursos y dos manifests coincidieron por SHA-256 con `public/build`. `git diff --check` pasó. Estas verificaciones son locales y no autorizan un despliegue.
