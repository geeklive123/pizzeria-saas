# Candidatos para el commit local del Sprint 3.3

Inventario de trabajo propuesto, sin preparar archivos ni crear un commit. Verificar el diff antes de incluirlos.

## Codigo y configuracion

- `.env.example`
- `app/Actions/AddConfiguredPizzaAction.php`
- `app/Actions/AddOrderItemAction.php`
- `app/Actions/ApplyInventoryMovementAction.php`
- `app/Actions/BuildStorefrontWhatsAppOrderAction.php`
- `app/Actions/CancelOrderAction.php`
- `app/Actions/ClaimPreparedDynamicQrAction.php`
- `app/Actions/ConfirmOnlineQrDeliveryAction.php`
- `app/Actions/ConsumeInventoryReservationAction.php`
- `app/Actions/ConvertOnlineOrderRequestAction.php`
- `app/Actions/CreateTakeawayOrderAction.php`
- `app/Actions/DispatchOrderToKitchenAction.php`
- `app/Actions/DynamicQrAction.php`
- `app/Actions/RegisterPaymentAction.php`
- `app/Actions/ResolveOnlineQrDispositionAction.php`
- `app/Actions/SettleKitchenDispatchAction.php`
- `app/Http/Controllers/CashController.php`
- `app/Http/Controllers/OnlineOrderRequestController.php`
- `app/Http/Controllers/Storefront/PaymentPreparationController.php`
- `app/Models/OnlineQrDisposition.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/BankQrEmitter.php`
- `app/Services/CreatePendingOnlineOrder.php`
- `app/Services/EmitOnlineDynamicQr.php`
- `app/Services/OnlineOrderActionContext.php`
- `app/Services/OnlineOrderReservationVerifier.php`
- `app/Services/OnlinePaymentPreparationService.php`
- `app/Services/OnlineSystemIdentityService.php`
- `app/Services/PrepareOnlineQrOperation.php`
- `config/auth.php`
- `config/storefront.php`
- `routes/web.php`

## Migraciones

- `database/migrations/2026_11_17_120000_create_online_system_identities.php`
- `database/migrations/2026_11_17_130000_create_online_qr_operations.php`
- `database/migrations/2026_11_17_140000_link_online_qr_operations_to_orders.php`
- `database/migrations/2026_11_17_150000_link_online_qr_operations_to_intents.php`
- `database/migrations/2026_11_17_160000_create_online_qr_resolutions.php`
- `database/migrations/2026_11_17_170000_create_online_qr_dispositions.php`
- `database/migrations/2026_11_17_180000_protect_online_qr_dispositions.php`
- `database/migrations/2026_11_17_190000_create_online_qr_inventory_outcomes.php`

## Interfaz y assets

- `public/build/assets/storefront-Bt7WIJNz.js`
- `public/build/manifest.json`
- `resources/js/delivery.js`
- `resources/js/storefront.js`
- `resources/views/cash/index.blade.php`
- `resources/views/online-orders/index.blade.php`
- `resources/views/online-orders/show.blade.php`
- `resources/views/storefront/checkout.blade.php`

## Pruebas y arnes local

- `tests/Concurrency/online_preparation_mariadb.php`
- `tests/Feature/CashAndPaymentsTest.php`
- `tests/Feature/DynamicQrTest.php`
- `tests/Feature/OnlinePaymentPreparationTest.php`
- `tests/Feature/OnlineQrHttpTest.php`
- `tests/Feature/OnlineSystemIdentityTest.php`
- `tests/Feature/SimulatedOnlineBankTest.php`
- `tests/Frontend/OnlineCheckout.test.mjs`
- `tests/Support/SimulatedOnlineBank.php`
- `tests/Visual/RenderCheckoutFixtureTest.php`
- `tests/Visual/checkout-playwright.cjs`
- `tests/Visual/online-demo-bootstrap.php`
- `tests/Visual/online-demo-browser.cjs`
- `tests/Visual/online-demo-init.php`
- `tests/Visual/online-demo-router.php`

## Documentacion

- `docs/sprint-3-3-auditoria-qr-online.md`
- `docs/sprint-3-3-candidatos-commit.md`
- `docs/sprint-3-3-demo-laravel.md`
- `docs/sprint-3-3-efectos-cierre.md`
- `docs/sprint-3-3-validacion-manual.md`

## Excluidos

- `tmp/`, bases de prueba, capturas, respaldos, logs y credenciales.
- Archivos `public/build/assets/storefront-*` no referenciados por el manifiesto vigente; se conservan en disco.

El arnes utiliza datos y credenciales ficticias. Confirmar la version y permisos de MariaDB del destino antes de cualquier migracion fuera de pruebas.
