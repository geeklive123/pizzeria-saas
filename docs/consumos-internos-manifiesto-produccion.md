# Manifiesto de integración: Consumos internos

Baseline de producción: `e6cbf207f426d7c663c844684087db281345730f` (`main`).

Worktree de preparación: `C:\Dev\pizzeria-saas-consumos-integration`.

Este manifiesto no autoriza commit, push, migración ni deploy.

## Archivos existentes modificados

- `app/Enums/InventoryMovementType.php`
- `app/Enums/MembershipRole.php`
- `app/Enums/Permission.php`
- `app/Enums/PermissionModule.php`
- `app/Http/Controllers/ReportController.php`
- `app/Models/PrintAttempt.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/ProfitabilityReportService.php`
- `app/Services/ReportsOverviewService.php`
- `app/Services/ThermalPrintingService.php`
- `resources/js/app.js`
- `resources/views/dashboard.blade.php`
- `resources/views/inventory/movements.blade.php`
- `resources/views/inventory/show.blade.php`
- `resources/views/partials/sidebar-navigation.blade.php`
- `resources/views/reports/index.blade.php`
- `routes/web.php`
- `tests/Feature/ThermalPrintingTest.php` (solo la aserción autorizada)

## Archivos nuevos

- `app/Actions/CancelInternalConsumptionAction.php`
- `app/Actions/CreateInternalConsumptionAction.php`
- `app/Enums/InternalConsumptionReason.php`
- `app/Enums/InternalConsumptionStatus.php`
- `app/Http/Controllers/InternalConsumptionController.php`
- `app/Http/Requests/CancelInternalConsumptionRequest.php`
- `app/Http/Requests/PreviewInternalConsumptionRequest.php`
- `app/Http/Requests/PrintInternalConsumptionRequest.php`
- `app/Http/Requests/StoreInternalConsumptionRequest.php`
- `app/Models/InternalConsumption.php`
- `app/Models/InternalConsumptionCancellation.php`
- `app/Models/InternalConsumptionComponent.php`
- `app/Models/InternalConsumptionItem.php`
- `app/Policies/InternalConsumptionPolicy.php`
- `app/Printing/Renderers/InternalConsumptionCommandRenderer.php`
- `app/Services/InternalConsumptionPreparationService.php`
- `app/Services/InternalConsumptionReportService.php`
- `database/migrations/2026_10_10_120000_create_internal_consumptions.php`
- `database/migrations/2026_10_11_120000_remove_automatic_updates_from_internal_consumption_timestamps.php`
- `docs/consumos-internos-despliegue-siteground.md`
- `docs/consumos-internos-manifiesto-produccion.md`
- `docs/verificar-fecha-consumo-interno.md`
- `resources/views/internal-consumptions/confirm.blade.php`
- `resources/views/internal-consumptions/create.blade.php`
- `resources/views/internal-consumptions/index.blade.php`
- `resources/views/internal-consumptions/show.blade.php`
- `tests/Feature/InternalConsumptionReportsTest.php`
- `tests/Feature/InternalConsumptionTest.php`

## Assets compilados del artefacto

- `public/build/manifest.json`
- `public/build/fonts-manifest.json`
- `public/build/assets/app-BBPgbXCS.js`
- `public/build/assets/app-DCkiQDhx.css`
- `public/build/assets/fonts-C9MNnjVw.css`
- `public/build/assets/instrument-sans-400-normal-D1W7dsQl.woff`
- `public/build/assets/instrument-sans-400-normal-DRC__1Mx.woff2`
- `public/build/assets/instrument-sans-500-normal-Dk9ku72i.woff2`
- `public/build/assets/instrument-sans-500-normal-Z6ESRlEs.woff`
- `public/build/assets/instrument-sans-600-normal-B7fBEWYG.woff2`
- `public/build/assets/instrument-sans-600-normal-B9e8oLYv.woff`

Los assets tienen hash de contenido y sustituyen el conjunto referenciado por los manifests como una unidad. No mezclar manifests nuevos con assets anteriores.

## Exclusiones y garantías

- No hay archivos eliminados.
- No se modifica `app/Services/PrintAgentQueueService.php`.
- No se modifica `app/Actions/ConsumeInventoryReservationAction.php`.
- No se modifica `app/Support/UiFormatter.php`.
- No hay migraciones `down` ni rollback autorizados para producción.
- No hay dependencias nuevas de Composer o npm.
- Las promociones, descuentos, jugos naturales y demás funciones del baseline permanecen en el árbol integrado.
