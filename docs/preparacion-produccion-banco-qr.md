# Preparación de producción: Banco Económico QR

Revisión estática del código local; no se inspeccionó ni modificó la base operativa o SiteGround. El estado real de las migraciones del destino es desconocido.

## Migraciones y dependencias

Aplicar únicamente las pendientes, en orden y después de respaldo verificado y autorización:

1. `2026_10_27_120000_create_dynamic_qr_intents`: configuración e intenciones; requiere las tablas de empresas, sucursales, usuarios, pedidos, tandas, caja y pagos.
2. `2026_10_28_120000_create_bank_qr_diagnostics_table`: diagnósticos y eventos; requiere configuración bancaria y empresa.
3. `2026_10_28_130000_add_original_image_to_bank_qr_diagnostics`: imagen original y código de respuesta; requiere diagnósticos.
4. `2026_10_28_140000_create_bank_qr_reconciliation_evidence`: evidencia de conciliación; requiere diagnósticos.
5. `2026_10_28_150000_add_bank_qr_configuration_metadata`: metadatos y auditoría de configuración; requiere configuración.
6. `2026_10_28_160000_allow_independent_bank_qr_diagnostics`: índice no único, elimina el índice único global y añade HTTP de generación; requiere diagnósticos y el índice histórico.
7. `2026_10_28_170000_remove_dynamic_qr_expiry_auto_update`: modifica la definición de `expires_at`; requiere intenciones.
8. `2026_10_28_180000_create_bank_qr_deposit_evidence`: evidencia independiente de abono; requiere intenciones, empresa y sucursal.
9. `2026_10_28_190000_add_generation_phase_to_dynamic_qr_intents`: evidencia de fase nullable; requiere intenciones. No clasifica registros históricos.

La aplicación escribe `generation_phase`: sin la última migración falla la creación de intentos. Además, `RegisterPaymentAction`, `CashController` y la anulación de productos consultan `dynamic_qr_intents` incluso sin habilitar nuevas generaciones; desplegar el código sin esa tabla puede afectar efectivo, caja y anulaciones. Sin la tabla de evidencia de abono, el historial se puede cargar, pero su registro devuelve 503. Otras tablas y columnas son dependencias directas de los módulos; comprobarlas y comprobar la tabla de migraciones del destino antes de activar el código.

Riesgos: MySQL DDL puede bloquear tablas y efectuar commits implícitos; validar versión, estructura real, índices y tamaño en una copia aislada. La migración 160000 presupone el índice por su nombre; su rollback no puede restaurar unicidad si hay duplicados y elimina una columna. La 170000 cambia definición y no restaura su comportamiento anterior; comparar con el esquema real. La 180000 conserva evidencia en `down`; la 190000 rechaza `down`. Las migraciones anteriores tienen `down` que eliminan tablas o columnas, por lo que no debe usarse rollback automático sobre datos históricos.

## Respaldo y despliegue propuesto para aprobación posterior

1. Identificar destino, versión web y CLI PHP, base, extensiones y estado real de migraciones. El proyecto exige PHP compatible con Composer; confirmar también el lockfile. No usar datos ni configuraciones locales como producción.
2. Restringir nuevas operaciones QR durante la ventana. Acordar cómo supervisar y consultar los intentos pendientes sin ejecutar dos recuperadores incompatibles.
3. Hacer respaldo consistente de la base del destino, del código desplegado y de los archivos necesarios; conservar `APP_KEY` y credenciales existentes sin exponerlos. Comprobar restauración en un entorno aislado y reconciliar la ventana entre respaldo y corte.
4. Revisar la lista de cambios Git, incluyendo archivos no rastreados y el manifest de Vite. No ejecutar `composer setup`: incluye generación de clave y migración automática.
5. Tras aprobación explícita, aplicar solo migraciones pendientes con el procedimiento revisado y desplegar el código compatible. Mantener `BANECO_ENABLED=false` hasta completar las verificaciones y recibir autorización de activación.
6. Comprobar arranque, permisos, configuración, logs sanitizados, migraciones, locks compartidos, cola y cron. La evidencia de abono debe distinguirse de `paid` de la API. Cualquier prueba bancaria real requiere interacción y autorización explícitas; no genera un pago solo por mostrar el QR.
7. Ante una incidencia, detener nuevas generaciones y conservar consultas/reconciliación bajo un procedimiento acordado. Revertir código únicamente si se ha verificado su compatibilidad con el esquema y los datos nuevos. No ejecutar `migrate:rollback` ni restaurar una copia antigua sobre pagos posteriores: preservar y conciliar esos movimientos antes de cualquier recuperación. Escalar a revisión financiera y técnica.

## Configuración para comprobar en destino

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` existente y URL de aplicación correcta; las credenciales bancarias se almacenan cifradas y dependen de esa clave.
- `BANECO_ENABLED`, `BANECO_DIAGNOSTICS_ENABLED`, `BANECO_WEBHOOK_ENABLED` y `BANECO_BASE_URL`: revisar sin revelar valores sensibles; activar solo lo expresamente aprobado.
- `CACHE_STORE` compartido y locks atómicos entre procesos; si se usa database, disponer de las tablas correspondientes. No usar caché por proceso para exclusión.
- `QUEUE_CONNECTION`, tablas de jobs y fallidos, worker o ejecución acotada, `DB_QUEUE_RETRY_AFTER` superior al timeout de 90 s y al lock de 120 s. Verificar el funcionamiento real del plan sin asumir workers permanentes.
- `routes/console.php` programa `baneco:reconcile` cada minuto con `withoutOverlapping`; requiere ejecutar `php artisan schedule:run` mediante cron y procesar la cola. La alternativa `php artisan baneco:reconcile --inline` debe revisarse con exclusión y límites adecuados, no habilitar ambas rutas sin diseño. La recuperación sin navegador depende de esa infraestructura.
- SiteGround recomienda al menos 30 minutos entre ejecuciones de scripts en hosting compartido; confirmar las condiciones reales del plan y la capacidad antes de fijar frecuencia o latencia. Sin un mecanismo de recuperación operativo no se debe activar QR.

## Riesgos abiertos

- Los intentos `prepared` recuperados no se reanudan automáticamente: el scheduler puede clasificarlos como inciertos. Se requiere un procedimiento administrativo y una latencia de recuperación aceptada.
- No se demostró pérdida física de respuesta durante COMMIT ni el mutex real de `schedule:run`; la evidencia disponible usa inyecciones y carreras acotadas.
- La API `paid` no demuestra abono en cuenta. La diferencia de Bs 5 frente a Bs 3 observada sigue pendiente de contraste con el banco.
- La reversión de tandas pagadas mientras la mesa está abierta está bloqueada. Una reversión interna QR no efectúa reembolso bancario; exigir comprobante independiente y procedimiento autorizado.

## Inventario Git observado antes de crear este documento

```text
 M .env.example
 M .gitignore
 M app/Actions/CancelKitchenDispatchItemAction.php
 M app/Actions/CloseCashSessionAction.php
 M app/Actions/RegisterPaymentAction.php
 M app/Http/Controllers/CashController.php
 M app/Services/SalesReportService.php
 M phpunit.xml
 D public/build/assets/app-BBPgbXCS.js
 D public/build/assets/app-DCkiQDhx.css
 M public/build/manifest.json
 M resources/js/app.js
 M resources/views/cash/index.blade.php
 M resources/views/orders/_at_end_payment.blade.php
 M resources/views/orders/_per_batch_payment.blade.php
 M resources/views/orders/checkout.blade.php
 M resources/views/partials/sidebar-navigation.blade.php
 M routes/api.php
 M routes/console.php
 M routes/web.php
 M tests/Feature/CashAndPaymentsTest.php
 M tests/Feature/CashSessionTransferTest.php
 M tests/Feature/LegacyCancelledPaidOrderRecoveryTest.php
 M tests/Feature/NaturalJuiceFlavorStockTest.php
 M tests/Feature/PaidOrderCancellationTest.php
 M tests/Feature/PartialKitchenDispatchCancellationTest.php
 M tests/Feature/PromotionPizzaSelectionTest.php
 M tests/Feature/ReportsTest.php
 M tests/Feature/SalesFlowRedesignTest.php
 M tests/Feature/SalesUxAndMixedPaymentsTest.php
 M tests/Feature/SettledKitchenDispatchReversalTest.php
 M tests/Feature/ThermalPrintingTest.php
 M tests/Feature/WaitstaffPizzaCashShiftFeedbackTest.php
?? app/Actions/BankQrDiagnosticAction.php
?? app/Actions/ClaimPreparedDynamicQrAction.php
?? app/Actions/DynamicQrAction.php
?? app/Actions/SaveBancoEconomicoSettingAction.php
?? app/Console/Commands/ConfigureBancoEconomicoCommand.php
?? app/Console/Commands/ReconcileDynamicQrCommand.php
?? app/Enums/DynamicQrGenerationPhase.php
?? app/Enums/DynamicQrStatus.php
?? app/Http/Controllers/BancoEconomicoSettingsController.php
?? app/Http/Controllers/BancoEconomicoWebhookController.php
?? app/Http/Controllers/BankQrDiagnosticController.php
?? app/Http/Controllers/BankQrHistoryController.php
?? app/Http/Controllers/DynamicQrController.php
?? app/Jobs/
?? app/Models/BankQrDiagnostic.php
?? app/Models/BankQrSetting.php
?? app/Models/DynamicQrIntent.php
?? app/Services/BancoEconomicoGateway.php
?? app/Services/BankQrException.php
?? app/Services/BankQrHistory.php
?? app/Services/BankQrImageInspection.php
?? config/baneco.php
?? database/migrations/2026_10_27_120000_create_dynamic_qr_intents.php
?? database/migrations/2026_10_28_120000_create_bank_qr_diagnostics_table.php
?? database/migrations/2026_10_28_130000_add_original_image_to_bank_qr_diagnostics.php
?? database/migrations/2026_10_28_140000_create_bank_qr_reconciliation_evidence.php
?? database/migrations/2026_10_28_150000_add_bank_qr_configuration_metadata.php
?? database/migrations/2026_10_28_160000_allow_independent_bank_qr_diagnostics.php
?? database/migrations/2026_10_28_170000_remove_dynamic_qr_expiry_auto_update.php
?? database/migrations/2026_10_28_180000_create_bank_qr_deposit_evidence.php
?? database/migrations/2026_10_28_190000_add_generation_phase_to_dynamic_qr_intents.php
?? docs/auditoria-banco-economico-2026-10-28.md
?? docs/auditoria-commit-incierto-qr.md
?? docs/banco-economico-qr.md
?? docs/prueba-local-qr-ventas.md
?? docs/qr-concurrencia-y-despliegue.md
?? public/build/assets/app-B4GsHsCA.js
?? public/build/assets/app-DSzz6UzV.css
?? public/build/assets/storefront-BPgkh-YC.css
?? public/build/assets/storefront-FM1zHdmq.js
?? qr-test-diagnostic.xml
?? resources/js/bank-qr-diagnostic.js
?? resources/js/dynamic-qr.js
?? resources/views/bank-qr-diagnostics/
?? resources/views/bank-settings/
?? resources/views/orders/_dynamic_qr.blade.php
?? tests/Concerns/
?? tests/Concurrency/
?? tests/Feature/BancoEconomicoSettingsTest.php
?? tests/Feature/BankQrDiagnosticTest.php
?? tests/Feature/DynamicQrTest.php
?? tests/Frontend/
```

Todos los elementos anteriores deben preservarse para revisión. Las rutas `D` son borrados de assets versionados, no archivos que deban restaurarse automáticamente: revisar junto al manifest y los nuevos assets. `qr-test-diagnostic.xml` es una salida de diagnóstico de pruebas, no código de producto; decidir su conservación por separado.
