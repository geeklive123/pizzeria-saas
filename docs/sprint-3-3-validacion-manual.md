# Validación visual del checkout Sprint 3.3

## Arnés local aislado

El renderizador usa únicamente PHPUnit, SQLite en memoria y datos ficticios. El adaptador bancario de `tests/Support` rechaza producción y bloquea HTTP no simulado. La configuración QR se cambia solo en memoria durante el render de prueba; no se modifica `.env`. El navegador intercepta todas las solicitudes, bloquea las que no son del arnés y presenta un mosaico ficticio y un QR no pagable. Las respuestas del navegador son simulaciones de interfaz: la integración PHP con base aislada se valida por separado.

Desde la raíz del proyecto, usando PHP 8.4.24 y Chrome instalado en su ruta habitual:

```powershell
npm.cmd install --prefix tmp/sprint33-playwright-local --no-save --package-lock=false --ignore-scripts --no-audit --no-fund playwright-core@1.57.0
npm.cmd run build
& 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe' artisan test tests/Visual/RenderCheckoutFixtureTest.php tests/Feature/SimulatedOnlineBankTest.php tests/Feature/OnlineQrHttpTest.php
node tests/Visual/checkout-playwright.cjs
```

El último comando crea las capturas y `resultados.json` en `tmp/sprint33-visual/`. Se revisaron los estados en 1440 × 900 y 390 × 844. Consultar `resultados.json` para identificar las capturas de la ejecución vigente: se conservan imágenes anteriores, incluidas `*-confirmado-tardio.png`, que no representan correctamente la incidencia y no deben usarse. Las capturas son una revisión local sin banco real; no validan mosaicos geográficos reales, una aplicación servida de extremo a extremo ni el panel administrativo. El estado 9 sigue sin demostrar no pagabilidad y no autoriza liberar reservas.

## Comprobaciones manuales complementarias

Para cada paso capturar escritorio a 1440 × 900 y móvil a 390 × 844; incluir la pantalla completa y, si hay desplazamiento, una captura adicional del contenido inferior. Registrar resolución, navegador, fecha, estado y resultado. Comprobar legibilidad, foco, botones accesibles, ausencia de superposición y desplazamiento horizontal.

1. **Carrito:** agregar dos productos ficticios, cambiar cantidad y revisar nombre, precio, subtotal y total. Capturas `01-carrito-escritorio` y `01-carrito-movil`.
2. **Datos personales:** abrir checkout, completar nombre y teléfono ficticios; borrar un campo obligatorio para verificar el mensaje y la conservación del carrito. Capturas `02-datos-*` y `02-error-datos-*`.
3. **Dirección y mapa:** seleccionar delivery, introducir dirección y referencia ficticias, mover el marcador y confirmar la ubicación. Editar la dirección y comprobar que se exige confirmar de nuevo. Capturas `03-mapa-*` y `03-confirmacion-ubicacion-*`.
4. **Cobertura:** probar un punto dentro y otro fuera de la cobertura configurada; comprobar el mensaje y la disponibilidad de continuar. Capturas `04-dentro-*` y `04-fuera-*`.
5. **Caja cerrada:** con una caja de prueba cerrada, comprobar que delivery no permite iniciar el pago y muestra el aviso; comprobar que recojo conserva su comportamiento previsto. Capturas `05-caja-cerrada-*`.
6. **QR simulado:** con caja de prueba abierta y arnés bancario simulado, generar un QR ficticio, verificar importe y estado de espera y pulsar dos veces para comprobar que no aparece una segunda operación. Capturas `06-qr-*` y `06-espera-*`.
7. **Confirmación simulada:** responder como pago confirmado, comprobar mensaje y resumen, y verificar en la base aislada una sola orden, intención, pago, comanda y ticket, sin consumos duplicados por reserva. Capturas `07-confirmado-*`.
8. **Errores e incertidumbre:** simular desconexión, respuesta incierta, reserva inválida y cierre de caja antes de confirmar. Comprobar mensajes, conservación del carrito o identidad según corresponda y que no se ofrezca otro QR mientras el anterior pueda seguir vigente. Capturas `08-desconexion-*`, `08-incertidumbre-*`, `08-reserva-*` y `08-cierre-*`.

No se debe escanear un QR real, ejecutar un reembolso ni usar credenciales bancarias reales para estas comprobaciones.
