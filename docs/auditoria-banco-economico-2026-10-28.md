# Auditoría Banco Económico — 28 de octubre de 2026

Inventario de solo lectura de `pizzeria_saas`; los conteos son una fotografía y pueden cambiar. Todas las tablas usan InnoDB. No se consultaron credenciales ni imágenes.

| Tabla | Filas | Propósito y consumidores | Migración de creación | Clasificación |
| --- | ---: | --- | --- | --- |
| `bank_qr_settings` | 1 | Credenciales cifradas, versión activa y configuración por empresa/sucursal; `BankQrSetting`, `SaveBancoEconomicoSettingAction`, gateway y controladores. | `2026_10_27_120000_create_dynamic_qr_intents` | Necesaria |
| `dynamic_qr_intents` | 2 | Intentos de venta, idempotencia, estado bancario, conciliación y enlace a pago; `DynamicQrAction`, pagos, controladores y conciliación. | `2026_10_27_120000_create_dynamic_qr_intents` | Necesaria |
| `bank_qr_diagnostics` | 3 | Pruebas administrativas, referencia, estado y respuesta original cifrada; `BankQrDiagnosticAction`, gateway y controlador. | `2026_10_28_120000_create_bank_qr_diagnostics_table` | Necesaria; dos columnas históricas candidatas a revisión |
| `bank_qr_diagnostic_events` | 6 | Auditoría de operaciones administrativas; `BankQrDiagnosticAction`. | `2026_10_28_120000_create_bank_qr_diagnostics_table` | Necesaria |
| `bank_qr_reconciliation_evidence` | 0 | Evidencia cifrada y verificaciones de incidencias; `BankQrDiagnosticAction` y controlador. | `2026_10_28_140000_create_bank_qr_reconciliation_evidence` | Necesaria |
| `bank_qr_setting_audits` | 0 | Auditoría de cambios de configuración; `SaveBancoEconomicoSettingAction`. | `2026_10_28_150000_add_bank_qr_configuration_metadata` | Necesaria |

## Relaciones e índices reales

- `bank_qr_settings`: FK a `companies` y FK compuesta `(branch_id, company_id)` a `branches`; índices PRIMARY, `bank_qr_settings_company_id_foreign`, `bank_setting_branch_fk`, unique `bank_setting_context_uq (id, company_id)` y unique `bank_qr_settings_active_scope_unique`.
- `dynamic_qr_intents`: FK compuestas de pedido, pedido activo, caja, tanda y pago por empresa/sucursal; FK compuesta de configuración por empresa y FK de usuario. Índices PRIMARY, `dqr_order_id_fk`, `dqr_active_order_id_fk`, `dqr_cash_session_id_fk`, `dqr_kitchen_dispatch_id_fk`, `dqr_payment_id_fk`, `dqr_setting_fk`, `dynamic_qr_intents_user_id_foreign`, `dynamic_qr_intents_status_index`, `dynamic_qr_listing_ix`; unique `dynamic_qr_idempotency_uq`, `dynamic_qr_intents_active_order_id_unique`, `dynamic_qr_intents_bank_qr_id_unique`, `dynamic_qr_intents_commercial_reference_unique`, `dynamic_qr_intents_payment_id_unique` y `dynamic_qr_intents_ulid_unique`.
- `bank_qr_diagnostics`: FK a empresa, usuario, empresa activa y configuración/empresa; índices PRIMARY, `bank_qr_diagnostics_company_id_created_at_index`, `bank_qr_diagnostics_user_id_foreign`, `bqd_active_company_idx`, `bqd_setting_company_fk`; unique `bqd_id_company_uq`, `bank_qr_diagnostics_commercial_reference_unique` y `bank_qr_diagnostics_ulid_unique`.
- `bank_qr_diagnostic_events`: FK a empresa, usuario y diagnóstico/empresa; índices PRIMARY, `bank_qr_diagnostic_events_company_id_foreign`, `bank_qr_diagnostic_events_user_id_foreign`, `bqde_diagnostic_company_fk`.
- `bank_qr_reconciliation_evidence`: FK a empresa, usuario y diagnóstico/empresa; índices PRIMARY, `bank_qr_reconciliation_evidence_company_id_foreign`, `bank_qr_reconciliation_evidence_user_id_foreign`, `bqre_diagnostic_company_fk`, `bank_qr_reconciliation_evidence_diagnostic_id_kind_index`.
- `bank_qr_setting_audits`: FK a empresa, usuario y configuración/empresa; índices PRIMARY, `bank_qr_setting_audits_company_id_foreign`, `bank_qr_setting_audits_user_id_foreign`, `bqsa_setting_company_fk`.

Las seis migraciones bancarias hasta `2026_10_28_160000_allow_independent_bank_qr_diagnostics` figuran ejecutadas. Esta última eliminó el unique `bank_qr_diagnostics_active_company_id_unique`; el índice ordinario actual sostiene también la FK. Las restricciones de referencia e idempotencia siguen vigentes.

## Candidatos y límites

- `bank_qr_diagnostics.qr_image` es legado: la generación administrativa actual escribe `original_qr_image`; la columna antigua no tiene valores no nulos en esta base y sigue oculta en el modelo y referida por una prueba. Otras instalaciones podrían contener datos. No se propone eliminarla sin inventario de todos los entornos y plan de conservación.
- `active_company_id` dejó de ser un bloqueo único global, pero aún alimenta avisos y se actualiza según el estado; además tiene FK e índice. No es eliminable sin sustituir esas dependencias y evaluar registros históricos.
- `original_qr_image`, `generation_response_code` y `generation_http_status` mantienen evidencia y datos del panel, no son columnas desechables de depuración. El registro sanitizado de forma de respuesta está configurado, documentado y probado: se conserva para diagnóstico.
- No se identificó ninguna tabla bancaria obsoleta ni ruta o servicio que pueda eliminarse con seguridad. El código de selección de configuración por pedido y sucursal compartía la misma consulta y se consolidó.
- No se prepara ni ejecuta ninguna migración destructiva. Antes de proponer una eliminación, verificar respaldos, usos y datos históricos de todos los entornos; requerirá aprobación explícita.

La auditoría de índices es de metadatos; no ejecuta una carrera InnoDB. La suite SQLite y el DOM simulado no sustituyen una prueba de concurrencia en una base aislada ni una validación visual/autorizada del banco. Antes de producción validar contrato y ejemplos reales de estados y pagos, credenciales y canal seguro, conciliación de incidencias, scheduler/worker y el almacenamiento compartido requerido para los bloqueos de caché.
