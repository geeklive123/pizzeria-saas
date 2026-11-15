# Delivery con mapa: preparación local

La tienda registra solicitudes de recojo y delivery. Para delivery guarda la dirección, referencia, coordenadas confirmadas, origen y radio utilizados, distancia en línea recta y resultado de cobertura. Laravel recalcula la distancia; los importes y distancias enviados por el navegador no son autoritativos. Hasta el radio configurado el envío es gratuito; fuera de él se rechaza la solicitud y el cliente puede mover el punto o elegir recojo.

Al confirmar desde administración un delivery gratuito, se crea una orden POS Para llevar con la dirección, referencia y coordenadas en sus notas, sin recargo. El despacho y el cobro siguen siendo acciones separadas. Las promociones sin composición histórica verificable siguen sujetas a las restricciones del sprint anterior. Las solicitudes históricas pendientes de cotización permanecen bloqueadas para su conversión; este sprint no implementa delivery con costo.

## Configuración y privacidad

La sucursal `1:1` usa inicialmente `DELIVERY_LATITUDE=-17.3769946`, `DELIVERY_LONGITUDE=-66.1771762` y `DELIVERY_FREE_RADIUS_M=800` en `config/storefront.php`. La ubicación es propuesta: verificarla con el restaurante antes de publicar. Agregar un origen explícito por empresa y sucursal; una sucursal sin configuración no acepta delivery. `DELIVERY_TILE_URL` permite cambiar de servidor de teselas y `DELIVERY_PHOTON_URL` cambiar la instalación de Photon.

Las búsquedas se envían a Photon; no incluir datos personales adicionales en el texto de búsqueda ni registrar sus URL o solicitudes en herramientas de observabilidad. Las respuestas normalizadas se guardan en caché durante cinco minutos usando una clave hash, y las rutas públicas tienen límites de frecuencia. Verificar que el proveedor y las políticas de privacidad del restaurante permitan el tratamiento de direcciones. El servicio público de Photon y las teselas de OpenStreetMap son de mejor esfuerzo: para tráfico sostenido se debe evaluar un proveedor apropiado. El mapa conserva la atribución visible y no precarga teselas.

La migración `2026_11_15_100000_add_delivery_to_online_order_requests` es aditiva; aplicarla antes de publicar el código que lee sus columnas, tras un respaldo y ensayo en una copia equivalente. No ejecutar su método `down` sobre solicitudes que deban conservarse. Mantener la conversión POS desactivada hasta completar las verificaciones operativas. No es necesario activar ni modificar Banco Económico para esta funcionalidad.
