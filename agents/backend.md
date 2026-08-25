# Guía del agente backend

Aplica esta guía a cambios en `app/`, rutas, autorización y lógica de dominio.

## Diseño

- Trabaja dentro del monolito modular existente; organiza por responsabilidad y caso de uso, no por capas artificiales excesivas.
- Mantén controladores delgados: validación de entrada, autorización, invocación de una Action y construcción de la respuesta.
- Coloca operaciones de negocio en `app/Actions` y cálculos o capacidades reutilizables en `app/Services`.
- Usa enums respaldados por string para estados y tipos finitos.
- Usa excepciones de dominio claras para invariantes y `AuthorizationException` para accesos no permitidos.
- Evita observers o eventos con efectos ocultos cuando una Action explícita sea más fácil de probar.

## Multiempresa y autorización

- Toda lectura o escritura de negocio debe recibir una empresa explícita o utilizar el `CompanyContext` ya resuelto.
- Aplica `forCompany()` y filtros por sucursal de forma visible; nunca añadas un global scope.
- Valida que modelos relacionados pertenezcan a la misma empresa antes de escribir.
- Autoriza mediante las Policies y el enum `Permission` existentes.
- El owner tiene acceso total dentro de su empresa, nunca fuera de ella.
- Recibe el `User` explícitamente para auditoría y autorización en el dominio; no ocultes esa dependencia con `auth()`.

## Operaciones transaccionales

- Envuelve en `DB::transaction()` las operaciones que creen o actualicen varias entidades.
- Ordena y bloquea filas con `lockForUpdate()` cuando varias operaciones puedan tocar el mismo saldo.
- Usa `ApplyInventoryMovementAction` como único camino normal para cambiar inventario.
- No actualices ni elimines `InventoryMovement`; usa las Actions de reversión.
- Una compra `draft` no afecta inventario. Una compra `posted` no se edita libremente.

## Precisión

- Representa entradas decimales como strings o enteros.
- Usa `Brick\Math\BigDecimal` y `RoundingMode` para cálculos.
- No conviertas a `float`, ni siquiera temporalmente, cuando intervengan dinero, costos o cantidades.

## Entrega

- No crees endpoints o controladores artificiales solo para demostrar una Action.
- Toda mutación de pedidos, caja o pagos pasa por Actions; el controller no calcula totales, reservas, saldos ni vuelto.
- Un pedido `open` admite agregados hasta solicitar cuenta o registrar el primer pago.
- El cobro usa el total persistido del pedido y nunca genera consumo de inventario.
- `Payment` no se elimina. Una corrección usa `ReversePaymentAction` y, para efectivo, una compensación en `CashMovement`.
- `mixed` no es método de pago: surge de múltiples pagos completados con métodos diferentes.
- Requiere una sesión abierta para cualquier pago. QR no crea movimientos de efectivo y el vuelto no es un egreso.
- Mantén `Purchase` para compras inventariables y `Expense` para costos operativos; no crees un sistema financiero paralelo ni consumas inventario al registrar gastos.
- Un gasto CASH genera su salida mediante `RegisterExpenseAction`; su corrección usa `ReverseExpenseAction`. QR y transferencia no crean movimientos físicos.
- Los gastos publicados no se actualizan ni eliminan, y `Supplier` permanece reutilizable por empresa.
- En reportes, deja los Controllers limitados a HTTP/autorización/respuesta y delega consultas, agregaciones y cálculos en Services o Query Objects.
- Deriva reportes de `Order`, `Payment`, caja, gastos, compras e inventario; excluye reversiones y conserva scopes explícitos de empresa/sucursal/rango también en CSV.
- La creación y edición administrativa de usuarios pasa por Actions transaccionales sobre `User` y `Membership`; no asumas pertenencia exclusiva de un usuario a una empresa.
- La creación/edición de mesas pasa por `SaveRestaurantTableAction`; nunca elimines mesas con historial ni permitas desactivar una mesa con cuenta abierta.
- `OpenTableOrderAction` reutiliza la cuenta activa de una mesa ocupada y conserva la restricción de una sola cuenta.
- No avances al Sprint 10 sin instrucción explícita.
- Las pizzas fusionadas pertenecen al `OrderItem` mediante secciones; no generes productos combinados en catálogo.
- Valida fracciones con aritmética racional exacta, máximo 4 secciones y un solo `size_key` compatible.
- Calcula precio e inventario configurado en Actions/Services y conserva snapshots; una línea enviada no se edita.
- Reutiliza `InventoryReservation` para BASE + TOPPING fraccionado + extras - removidos + packaging.
- Si modificas PHP, aplica la guía de [testing.md](testing.md).
