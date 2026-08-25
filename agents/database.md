# Guía del agente de base de datos

Aplica esta guía a migraciones, modelos persistentes, índices, consultas, inventario y concurrencia.

## Esquema multiempresa

- Toda tabla de negocio debe incluir `company_id`; agrega `branch_id` cuando el dato pertenezca a una sucursal.
- Usa foreign keys, índices útiles y restricciones `unique` para invariantes que la base pueda garantizar.
- Usa claves foráneas compuestas cuando eviten mezclar empresa, sucursal, artículo inventariable, compra o receta.
- Conserva el patrón `id` interno más `ulid` público en entidades expuestas.
- Usa nombres explícitos para índices cuyo identificador generado pueda superar el límite de MySQL/MariaDB.
- No uses global scopes; proporciona scopes explícitos y calificables como `forCompany()`.

## Migraciones

- Inspecciona todas las migraciones relacionadas antes de crear una nueva.
- No edites una migración histórica para cambiar un entorno existente; crea una migración incremental.
- Implementa `down()` seguro y coherente con el orden de foreign keys.
- Mantén compatibilidad razonable entre MySQL/MariaDB de producción local y SQLite en memoria para pruebas.
- No ejecutes migraciones MySQL/MariaDB sin autorización explícita.
- Nunca ejecutes `migrate:fresh`, `db:wipe` ni comandos destructivos equivalentes.

## Decimales y costos

- Usa `decimal`, nunca `float` o `double`, para dinero, precios, costos, stock y cantidades.
- Inventario: cantidad con al menos tres decimales y costo promedio con al menos seis.
- Realiza cálculos con `BigDecimal`; no confíes en conversiones numéricas implícitas de PHP.
- Todos los movimientos se persisten en `InventoryItem.unit`; los ítems originados por ingredientes deben usar la unidad base del ingrediente.

## Ledger y concurrencia

- `inventory_movements` es el historial auditable y confirmado; debe ser inmutable.
- `inventory_stocks` es un saldo materializado, no la fuente única de verdad.
- Cada cambio de saldo debe crear un movimiento dentro de la misma transacción.
- Bloquea el saldo con `lockForUpdate()` antes de calcular la nueva cantidad o costo.
- Ordena bloqueos por `inventory_item_id` en operaciones de varios ítems.
- No permitas que una salida deje cantidad negativa.
- No borres movimientos para corregir errores; crea un movimiento `reversal` enlazado al original.
- Las reservas de pedidos son compromisos temporales: no crean movimientos ni alteran `inventory_stocks` o lotes.
- Calcula disponibilidad como físico menos vencido menos reservas activas y bloquea stocks/reservas en orden de `inventory_item_id`.
- Conserva la unicidad de una cuenta abierta por mesa mediante una restricción de base de datos, además del bloqueo en la Action.
- Conserva una sola `CashSession` abierta por caja mediante `active_cash_register_id` nullable y unique, además del bloqueo transaccional.
- `cash_movements` es un ledger inmutable. Las salidas e inversiones de signo dependen del tipo, pero el monto almacenado siempre es positivo.
- Los pagos y movimientos de caja se revierten mediante registros compensatorios enlazados; nunca se borran.
- QR pertenece al resumen del turno mediante `payments`, pero no crea `cash_movements`.
- El efectivo esperado es apertura + ventas CASH + ingresos manuales - salidas manuales, ajustado por reversiones; el cierre persiste ese snapshot y su diferencia contra lo contado.
- `expenses` no sustituye `purchases`: un gasto no genera inventario, lotes ni movimientos de inventario.
- Un gasto CASH referencia la `CashSession` de su sucursal y crea `expense_out` en el ledger existente; la reversión usa `expense_reversal` y nunca elimina historia.
- Categorías, proveedores y gastos se aíslan por empresa; los gastos además por sucursal y sus relaciones usan claves compuestas.
- Las fracciones de pizza se persisten como numerador/denominador enteros y se validan racionalmente.
- `recipe_items.component_type` distingue BASE y TOPPING; no dupliques la base al fusionar sabores.
- Las reglas de packaging se aíslan por empresa y `size_key`, y solo alimentan las reservas del fulfillment aplicable.
- Secciones y modificadores guardan snapshots suficientes para que el histórico no dependa del catálogo vivo.
- Los reportes consultan las fuentes de verdad existentes; no agregues tablas materializadas salvo necesidad demostrada y migración incremental justificada.
- `Purchase` y `Expense` permanecen separados en agregaciones. La rentabilidad usa consumo real del ledger y no vuelve a descontar compras inventariables.
- Excluye registros originales revertidos y sus compensaciones según la semántica de cada ledger; mantén filtros por empresa, sucursal y rango en consultas y exportaciones.
- `memberships` sigue siendo la única relación usuario-empresa; Sprint 9C no asigna membresías por sucursal porque el esquema actual no contempla esa relación.
- Las mesas usan el esquema existente. Libre/Ocupada se deriva de la existencia de `orders.active_restaurant_table_id`, cuya unicidad impide dos cuentas abiertas.
- No se agregó `DiningArea`: nombre, capacidad, orden y sucursal cubren la operatividad mínima; las áreas quedan para el rediseño UX final si se justifican.

## Revisión

- Revisa precisión, nulabilidad, índices, constraints, orden de migración y comportamiento de borrado.
- Añade pruebas para restricciones de empresa, duplicados, precisión, idempotencia y rollback transaccional.
