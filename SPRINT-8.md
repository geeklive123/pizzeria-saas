# SPRINT 8 --- Caja, cobros, pagos y cierre de mesa

## Proyecto

Directorio: raíz del proyecto.

Implementa ÚNICAMENTE el Sprint 8: **CAJA + COBROS + PAGOS + CIERRE DE
MESA**.

## 0. Antes de modificar

1.  Lee `AGENTS.md`.
2.  Lee `agents/`.
3.  Lee `.codex/project.md`.
4.  Revisa el estado real de:
    -   orders
    -   order_items
    -   restaurant_tables
    -   kitchen dispatches
    -   inventory reservations
    -   payments si ya existe algo
    -   permisos
5.  Ejecuta:
    -   `php artisan migrate:status`
    -   `php artisan test`
6.  Mantener todos los tests anteriores.
7.  No usar `float/double`.
8.  No modificar migraciones históricas aplicadas.
9.  Mantener compatibilidad con SiteGround/Linux.

NO implementar todavía: - impresión térmica; - facturación fiscal; -
promociones avanzadas; - reservas de clientes; - offline/PWA; - reportes
completos.

## 1. Objetivo

Completar el flujo económico:

Order abierto → productos servidos → cobrar → uno o varios Payments →
saldo 0 → cerrar Order → liberar mesa.

También debe funcionar para TAKEAWAY.

## 2. Order vs venta

`Order` sigue siendo la cuenta operativa.

No duplicar todo en una segunda entidad si no es necesario.

Si se crea `Sale`, debe ser únicamente un snapshot/registro financiero
justificado, no una copia completa del Order.

## 3. Estados

Revisar enum actual.

Necesitamos distinguir conceptualmente: - OPEN - PAID/CLOSED - CANCELLED

No cerrar Order solamente porque cocina terminó.

La mesa se libera al cerrar económicamente la cuenta.

## 4. CashRegister

Crear `CashRegister`.

Campos conceptuales: - id - ulid - company_id - branch_id - name -
is_active - timestamps

Seeder idempotente: `Caja Principal`

## 5. CashSession

Crear `CashSession`.

Campos: - id - ulid - company_id - branch_id - cash_register_id -
opened_by - closed_by nullable - opening_amount - expected_cash_amount -
counted_cash_amount nullable - difference_amount nullable - status -
opened_at - closed_at nullable - notes nullable - timestamps

Estados: - OPEN - CLOSED

Una caja no puede tener dos sesiones abiertas simultáneas. Usar
integridad y transacción.

## 6. Apertura

Formulario: - monto inicial; - observación opcional.

Ejemplo: Bs 200.

No tratar el monto inicial como venta.

## 7. Payment Methods

Enum inicial: - CASH - QR

Preparar arquitectura futura para: - CARD - TRANSFER - OTHER

No strings libres.

## 8. Payment

Crear `Payment`.

Campos conceptuales: - id - ulid - company_id - branch_id - order_id -
cash_session_id - method - amount - reference nullable - received_amount
nullable - change_amount nullable - paid_at - received_by - status -
reversal_of_id nullable - idempotency_key - timestamps

Estados: - COMPLETED - REVERSED

No borrar pagos históricos.

## 9. Pago mixto

NO crear `method=MIXED`.

Mixto significa varios Payments.

Ejemplo: - Total Bs 180 - QR Bs 100 - CASH Bs 80 - Saldo 0

## 10. Pago parcial

Permitir múltiples pagos antes del cierre.

Ejemplo: - Total Bs 200 - Pagado QR Bs 100 - Saldo Bs 100

Order sigue abierto. Mesa sigue ocupada.

## 11. Efectivo y vuelto

Ejemplo: - Cuenta Bs 85 - Cliente entrega Bs 100

Payment: - amount = 85 - received_amount = 100 - change_amount = 15

`CashMovement` debe registrar Bs 85.

El vuelto NO es gasto.

## 12. QR

QR: - amount = pago - reference opcional

No aumenta efectivo físico esperado. Sí aumenta ventas/pagos del turno.

## 13. Cash Movements

Crear ledger de caja.

`CashMovement`: - id - ulid - company_id - branch_id - cash_session_id -
type - amount - reference_type nullable - reference_id nullable - reason
nullable - created_by - occurred_at - reversal_of_id nullable -
timestamps

Tipos: - OPENING - SALE_CASH - MANUAL_IN - MANUAL_OUT - REVERSAL

QR no genera movimiento de efectivo físico.

CashMovement debe ser inmutable. Correcciones mediante reversión.

## 14. Ingreso / egreso manual

Permitir: - ingreso manual; - egreso manual.

Motivo obligatorio.

Ejemplos: - ingreso: cambio adicional; - egreso: compra urgente pequeña.

Esto NO reemplaza el futuro módulo formal de gastos.

## 15. Checkout

Crear pantalla clara, por ejemplo:

    Mesa 4
    Pedido #128

    Subtotal      Bs 230
    Descuento     Bs 0
    Total         Bs 230

    Pagado        Bs 100
    Saldo         Bs 130

    [EFECTIVO]
    [QR]

Para efectivo: - monto a pagar; - recibido; - vuelto; - confirmar pago.

## 16. Cierre del Order

Solo cerrar normalmente si:

`SUM(Payments COMPLETED) >= Order.total`

Usar precisión decimal segura.

Al cerrar: - status PAID/CLOSED; - closed_at; - mesa disponible; - items
permanecen; - cocina permanece histórica; - pagos permanecen.

## 17. Inventario

MUY IMPORTANTE.

Inspecciona Sprint anterior.

NO descontar inventario nuevamente al cobrar.

Si cocina ya consumió `InventoryReservations` y creó
`InventoryMovements`, Caja NO debe tocar consumo de receta.

Agregar regresión para doble descuento.

Si quedan reservas `RESERVED` al cerrar, manejar explícitamente la
inconsistencia y no cerrar silenciosamente.

## 18. Reversión de Payment

Crear `ReversePaymentAction`.

Debe: - no borrar Payment; - crear compensación/reversión; - revertir
CashMovement si era CASH; - mantener auditoría; - no permitir doble
reversión.

Para V1 puede requerir `CashSession OPEN`.

Documentar.

## 19. Order ya cobrado

No permitir:

`Order paid → CANCELLED`

de forma simple.

Si tiene pagos, requiere reversión/refund auditado.

No implementar refund completo todavía.

## 20. Cierre de caja

Calcular:

opening + SALE_CASH + MANUAL_IN - MANUAL_OUT ± REVERSALS = expected_cash

Usuario ingresa `counted_cash`.

Sistema calcula:

`difference = counted - expected`

Ejemplo: - Inicial: 200 - Ventas cash: 900 - Ingresos: 50 - Egresos:
100 - Esperado: 1050 - Contado: 1040 - Diferencia: -10

Guardar snapshot.

## 21. Resumen de turno

Mostrar: - ventas totales; - efectivo; - QR; - órdenes cobradas; -
ingresos manuales; - egresos manuales; - efectivo esperado.

No reportes históricos completos todavía.

## 22. Caja obligatoria

Para V1:

No permitir cobros si no existe `CashSession OPEN`.

Mensaje: `Debes abrir caja antes de cobrar.`

## 23. Permisos

Revisar permisos actuales.

Agregar solo si hace falta: - cash.view - cash.open - cash.close -
cash.manage - payments.create - payments.reverse

Roles: - OWNER: todo. - ADMIN: todo operativo. - CASHIER: cobrar,
abrir/cerrar caja, ver turno. - WAITER: puede solicitar cuenta/ver total
según política, pero no cobrar salvo permiso. - KITCHEN: sin acceso a
caja/pagos.

## 24. UI

Activar Caja en sidebar.

Rutas conceptuales: - `/cash` - `/cash/open` - `/cash/current` -
`/orders/{order}/checkout`

Mantener ULID público.

## 25. Idempotencia

CRÍTICO.

Doble click en Confirmar pago NO debe duplicar Payment.

Usar `idempotency_key` o mecanismo equivalente.

Pensar también en futuros reintentos/offline.

## 26. Concurrencia

Proteger: - dos cajeros cobrando misma cuenta; - doble pago; - cierre de
caja mientras entra pago; - reversión concurrente; - doble cierre.

Usar: - `DB::transaction()` - `lockForUpdate()`

## 27. Dinero

NO float.

Todos los cálculos de: - subtotal; - total; - paid; - balance; -
received; - change; - expected; - counted; - difference;

deben ser decimal-safe.

## 28. Seeder

Crear idempotentemente: `Caja Principal`

No crear sesión abierta demo.

## 29. Tests

Agregar como mínimo: 1. crear caja; 2. una sola CashSession OPEN; 3.
abrir caja; 4. opening amount; 5. impedir cobro sin caja abierta; 6.
CASH; 7. QR; 8. pago mixto; 9. pago parcial; 10. saldo pendiente; 11.
vuelto; 12. cash genera CashMovement; 13. QR no aumenta efectivo; 14.
doble click no duplica pago; 15. cerrar Order solo con saldo 0; 16.
liberar mesa con pago completo; 17. no liberar con parcial; 18.
inventario no se descuenta otra vez; 19. manual in; 20. manual out; 21.
expected cash; 22. cerrar caja; 23. diferencia positiva; 24. diferencia
negativa; 25. no cerrar dos veces; 26. reverse Payment; 27. reverse
CashMovement; 28. no doble reverse; 29. Order cobrado no se cancela
simple; 30. aislamiento company/branch; 31. permisos; 32. no floats; 33.
seeder idempotente; 34. todos los tests anteriores pasan.

## 30. MySQL

Crear migraciones NUEVAS.

Usar nombres explícitos y cortos para FK/índices. Evitar MySQL 1059.

NO `migrate:fresh`. NO modificar migraciones aplicadas.

## 31. Validación final

Ejecutar: - `php artisan migrate` - `php artisan migrate:status` -
`php artisan test` - `php vendor/bin/pint --test` - `npm run build`

Revisar manualmente: - `/cash` - `/tables` - `/orders` - checkout

No deploy. No push. No migrate:fresh.

## 32. Documentación

Actualizar: - `AGENTS.md` - `agents/` - `.codex/project.md`

Documentar: - Payment no se borra; - MIXED = varios Payments; - QR no es
efectivo físico; - vuelto no es gasto; - cobrar no vuelve a consumir
inventario; - caja debe estar abierta; - mesa se libera con pago
completo; - Order pagado no se cancela sin reversión; - siguiente sprint
NO es impresión todavía.

## 33. Informe final

Al terminar informar: 1. Arquitectura de caja/pagos. 2. Migraciones. 3.
Models. 4. Enums. 5. Actions. 6. Services. 7. Policies/permisos. 8.
Controllers/FormRequests. 9. Rutas. 10. UI. 11. Apertura de caja. 12.
Pago efectivo. 13. QR. 14. Mixto. 15. Parcial. 16. Vuelto. 17.
Liberación de mesa. 18. Cómo evita doble descuento de inventario. 19.
Idempotencia. 20. Reversión. 21. Efectivo esperado. 22. Cierre caja. 23.
Tests nuevos. 24. Total tests/assertions. 25. Pint. 26. npm build. 27.
Estado migraciones. 28. Riesgos pendientes.

## RESTRICCIÓN FINAL

NO avanzar al siguiente sprint.

Si la arquitectura real del proyecto difiere de algún nombre o entidad
conceptual de este documento, adapta la implementación a la arquitectura
existente en vez de crear sistemas paralelos. Explica el ajuste en el
informe final.
