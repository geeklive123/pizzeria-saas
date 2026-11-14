# Solicitudes online: revisión de compatibilidad y reversión

Este documento prepara una revisión; no autoriza migrar ni desplegar. La tienda registra solicitudes de recojo separadas del POS. No crea ventas, pagos, reservas ni comandas.

## Compatibilidad y precondiciones

- Confirmar el commit aprobado, el entorno bancario desactivado para pruebas y los respaldos restaurables antes de cualquier despliegue.
- La migración `2026_11_14_120000_create_online_order_requests.php` crea únicamente `online_order_requests` y `online_order_request_items`. No modifica tablas existentes. La primera incluye un campo `is_fence`: los cercos son marcadores sin datos personales ni líneas que impiden aceptar tardíamente claves abandonadas; no se muestran en el panel.
- Este archivo de migración cambió respecto al checkpoint WIP `2a7768f`. Si una base ya ejecutó esa versión anterior, Laravel no la volverá a ejecutar y faltará `is_fence`. No desplegar ni editar esa base hasta preparar y probar una migración hacia adelante apropiada.
- La columna `is_fence` y la restricción única por empresa, sucursal y clave son indispensables para la recuperación segura. No borrar cercos por antigüedad sin una estrategia que impida definitivamente recibir intentos antiguos.
- El endpoint anterior `POST /tienda/checkout/whatsapp` responde 410. Clientes con código antiguo deben recargar; no deben volver a usarlo para evitar el registro.
- `STOREFRONT_DEMO_MODE=true` sigue bloqueando nuevas solicitudes; activar el flujo requiere `false` en el entorno autorizado y renovar la caché de configuración de forma controlada.
- Vite debe publicarse como un conjunto coherente: manifest más los archivos que referencia, conservando los assets anteriores mientras existan clientes con páginas cargadas.
- Verificar HTTPS, almacenamiento compartido para sesiones y límites de tráfico, backups, permisos de pedidos y zona horaria; supervisar el crecimiento de cercos y los errores del endpoint de resolución. El límite HTTP no sustituye controles de capacidad.
- Las carreras automatizadas se probaron en MariaDB 10.4 aislado. MySQL 8 tiene diferencias de motor, configuración y bloqueos: ejecutar el mismo tipo de prueba en una instancia MySQL 8 desechable con la configuración prevista antes de aprobar producción.

## Despliegue propuesto

1. Validar primero en un entorno aislado con una copia autorizada y anonimizada, sin conectarse a servicios bancarios reales.
2. Confirmar que la migración no está registrada en su versión WIP y que no existen tablas homónimas. Respaldar y verificar restauración.
3. Aplicar la migración aprobada antes del código que requiere las tablas y publicar los assets antes de cambiar el manifest. Mantener nuevas solicitudes desactivadas durante la transición.
4. Verificar permisos y aislamiento de empresa/sucursal, lista y detalle, confirmación y cancelación, recuperación tras pérdida de respuesta y ausencia de operaciones POS. Activar solamente tras aprobación expresa.

## Reversión

1. Desactivar la creación de nuevas solicitudes mediante el modo demostración; mantener disponible la resolución de claves pendientes mientras se investigan intentos inciertos.
2. Informar al personal y preservar las solicitudes ya recibidas y todos los cercos. Una reversión de interfaz o código debe seguir permitiendo su consulta y resolución; no redirigir automáticamente al endpoint antiguo que omite el registro.
3. Restituir código y assets compatibles con la situación efectiva de los datos, conservando las tablas. No ejecutar `down`, borrar cercos ni restaurar una copia que descarte solicitudes recibidas sin un plan de conciliación y aprobación explícita.
4. Conciliar solicitudes e incidencias con el personal antes de cualquier retirada definitiva del esquema.
