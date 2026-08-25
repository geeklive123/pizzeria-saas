# Guía del agente de testing

Aplica esta guía al crear, modificar o ejecutar validaciones automatizadas.

## Entorno

- Usa PHPUnit 12 mediante `php artisan test`.
- Mantén SQLite `:memory:` configurado en `phpunit.xml`; nunca conectes las pruebas a `pizzeria_saas` en MySQL/MariaDB.
- Usa `RefreshDatabase` en pruebas de persistencia y factories coherentes por empresa.
- No ejecutes tests ni Pint en tareas exclusivamente documentales cuando el usuario lo haya prohibido.

## Cobertura mínima

- Toda funcionalidad multiempresa debe incluir un caso permitido dentro de la empresa y uno rechazado desde otra empresa.
- Prueba Policies y también invariantes de dominio/constraints; una no sustituye a la otra.
- Compara dinero, costos y cantidades como strings decimales exactos.
- Prueba transacciones, rollback, idempotencia de seeders, estados inválidos y operaciones repetidas.
- Para inventario, cubre entradas, salidas, stock insuficiente, costo promedio, conversiones, inmutabilidad y reversión.
- Para pedidos, cubre aislamiento, una cuenta abierta por mesa, numeración, snapshot de precio, reservas y rollback por insuficiencia.
- Confirma que reservar o liberar no cree movimientos ni cambie el stock físico.
- Para caja, cubre turno único abierto, efectivo, QR, pagos parciales/mixtos, idempotencia, vuelto, ledger, reversión y cierre decimal.
- Confirma que pagar no cree movimientos de inventario ni consuma nuevamente reservas.
- Prueba que QR no modifique efectivo esperado, que un pago histórico no se borre y que una cuenta con pagos no se cancele directamente.
- Para gastos, cubre categorías y proveedores idempotentes/no destructivos, documento, monto decimal, CASH con caja, métodos no efectivos, reversión, ledger, roles y aislamiento.
- Confirma que `Expense` no genere inventario, que CASH cree una sola salida automática y que QR/transferencia no creen `CashMovement`.
- Para pizzas fusionadas cubre 1 a 4 sabores, suma racional exacta, tamaño compatible, precio mayor, snapshots, BASE/TOPPING, tercios, modificadores y rollback.
- Prueba packaging para `TAKEAWAY`, ausencia para `DINE_IN`, cambio de fulfillment, inmutabilidad después del envío, KDS y aislamiento multiempresa.
- Conserva y ejecuta todas las regresiones de los sprints completados al modificar Sprint 9A.
- Para reportes cubre rangos en zona horaria de Bolivia, pedidos pagados, pagos completados/reversados/mixtos, rankings, pizzas fusionadas, gastos, compras, ledger de inventario, caja, permisos y exports aislados.
- Verifica que los cálculos decimales no usen punto flotante, que las compras no se resten dos veces y que no existan N+1 críticos en listados paginados.
- Conserva y ejecuta todas las regresiones de los sprints completados al modificar Sprint 9B.
- Para Sprint 9C cubre alta/reutilización de usuarios, hash de contraseña, aislamiento de memberships, último owner, conservación histórica, CRUD no destructivo de mesas, roles y sucursal.
- Verifica que Venta diferencie mesa/para llevar, reutilice la cuenta ocupada, no duplique pedidos, reservas o consumo y continúe hacia cocina y checkout.
- Conserva y ejecuta todas las regresiones al modificar la operatividad de Sprint 9C.

## Concurrencia

- SQLite no reproduce el bloqueo de filas de MySQL/MariaDB. Prueba que toda mutación pase por la Action central, que operaciones secuenciales acumulen correctamente y que los fallos reviertan la transacción.
- Verifica en revisión de código la presencia de `lockForUpdate()` y un orden estable de bloqueo.
- Documenta explícitamente cuando una garantía de concurrencia solo pueda comprobarse plenamente en MySQL/MariaDB.

## Validación obligatoria para cambios de código

Ejecuta desde la raíz:

```powershell
php artisan test
php vendor/bin/pint --test
```

Si `php` no está en `PATH`, usa el ejecutable PHP 8.4 configurado por el usuario. No cambies XAMPP para resolverlo.

Reporta el número de pruebas y aserciones, el resultado de Pint y cualquier comando que no se haya podido ejecutar.
