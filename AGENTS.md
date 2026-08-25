# Pizzeria SaaS — instrucciones del repositorio

Estas reglas aplican a todo el repositorio. Antes de proponer o realizar cambios, inspecciona el estado real del código y lee [.codex/project.md](.codex/project.md).

## Guías especializadas

Lee además las guías que correspondan al alcance de la tarea:

- Backend, dominio, autorización, Actions o Services: [agents/backend.md](agents/backend.md).
- Esquema, migraciones, consultas, inventario o concurrencia: [agents/database.md](agents/database.md).
- Vistas, CSS, JavaScript o experiencia de usuario: [agents/frontend.md](agents/frontend.md).
- Pruebas, regresiones o validación: [agents/testing.md](agents/testing.md).

Para tareas transversales, combina todas las guías aplicables. Si una guía especializada contradice este archivo, prevalece este archivo.

## Estado y alcance

- Stack: Laravel 13, PHP 8.4 y MySQL/MariaDB mediante XAMPP.
- Arquitectura: monolito modular preparado para múltiples empresas y sucursales.
- Sprint 1/Core: completo.
- Sprint 2/Catálogo, ingredientes y recetas: completo.
- Sprint 3/Inventario, compras, mermas, ajustes y costo promedio: completo.
- Sprint 4/Inventario inteligente, lotes, caducidad y disponibilidad por receta: completo.
- Sprint 5/Mesas, cuentas abiertas, POS, pedidos y reservas: completo.
- Sprint 6/Cocina, tandas y estados operativos: completo.
- Sprint 7/Pizzas fusionadas, personalizaciones y packaging: completo.
- Sprint 8/Caja, pagos y cierre de cuentas: completo.
- Sprint 9A/Gastos, egresos y proveedores: completo.
- Sprint 9B/Reportes operativos y financieros: completo.
- Sprint 9C/Operatividad básica de usuarios, mesas y entrada a Venta: implementado y en revisión.
- No avances al siguiente sprint ni implementes módulos fuera del alcance solicitado expresamente.
- No introduzcas impresión térmica, facturación, delivery, reservas de mesas, promociones, PWA/offline o reportes finales sin una indicación explícita de sprint.

## Reglas de arquitectura

- La pertenencia multiempresa se modela mediante `memberships`; no agregues un `company_id` simple a `users`.
- Usa `CompanyContext` para el contexto activo o recibe `Company` explícitamente en servicios de dominio.
- Todas las entidades de negocio deben aislarse por `company_id`; las dependientes de ubicación también por `branch_id`.
- No uses global scopes para multiempresa. Usa scopes explícitos, claves compuestas, Policies y validaciones de dominio.
- Conserva el patrón de ID interno autoincremental más ULID público en entidades expuestas fuera del dominio.
- Coloca los casos de uso y la lógica transaccional en Actions o Services. Mantén los controladores delgados.
- No dependas de `auth()` dentro del dominio cuando el usuario pueda recibirse explícitamente.
- Reutiliza enums, Policies, `CompanyContext` y servicios existentes; no dupliques mecanismos de autorización.

## Persistencia, dinero e inventario

- Usa `DB::transaction()` para operaciones con varias escrituras relacionadas.
- Usa `lockForUpdate()` en toda operación que cambie saldos de inventario.
- El inventario es un ledger: los movimientos confirmados son inmutables y no se editan ni eliminan.
- Saldos, movimientos y compras referencian `InventoryItem`, que puede representar un ingrediente o una variante de venta directa.
- Todos los movimientos usan `InventoryItem.unit`; para un ítem de ingrediente debe coincidir con su unidad base.
- Corrige movimientos históricos mediante reversiones o nuevos movimientos compensatorios.
- Nunca actualices `inventory_stocks` como sustituto de un movimiento de inventario.
- No permitas stock negativo en la versión actual.
- El stock físico incluye lotes vencidos; la disponibilidad utilizable los excluye sin generar mermas automáticas.
- Las salidas normales no consumen lotes vencidos. Su retiro debe ser una operación administrativa explícita y auditable.
- Las reservas activas reducen disponibilidad, pero no alteran stock físico, lotes ni movimientos.
- Usa `BigDecimal` para costo promedio, valorización, dinero y cálculos de cantidades.
- Nunca uses `float` o `double` para dinero, precios, costos, cantidades o conversiones.
- Persiste cantidades y valores monetarios en columnas `decimal` con precisión explícita.
- `Payment` y `CashMovement` son históricos: no se borran; se corrigen mediante reversiones enlazadas.
- Un pago mixto son varios `Payment`; nunca un método artificial `mixed`.
- QR no aumenta efectivo físico y el vuelto no es un gasto.
- Cobrar nunca vuelve a consumir inventario ni modifica reservas, lotes o movimientos existentes.
- Todo cobro requiere una `CashSession` abierta; la mesa se libera únicamente cuando el pedido queda pagado.
- Un pedido con pagos no se cancela sin la reversión financiera correspondiente.
- `Purchase` registra entradas inventariables; `Expense` registra costos operativos que no ingresan stock. No los fusiones.
- Un gasto CASH crea automáticamente un `CashMovement` de egreso; QR y transferencia no alteran efectivo físico.
- Los gastos publicados son históricos e inmutables; se corrigen mediante una reversión enlazada y, si corresponde, un movimiento compensatorio de caja.
- `Supplier` es el directorio reutilizable por empresa para gastos y futuras integraciones con compras.
- Los reportes se derivan de las fuentes transaccionales existentes; no mantengas tablas paralelas como fuente de verdad.
- Los pagos reversados no cuentan y un pago mixto se desglosa como varios `Payment` completados.
- La rentabilidad y el resultado son estimaciones basadas en snapshots y ledger; las compras inventariables no se descuentan nuevamente como gasto.
- Toda exportación conserva los scopes explícitos de empresa, sucursal y rango; restringe métricas financieras mediante permisos.
- Una pizza configurada admite de 1 a 4 secciones; sus fracciones racionales deben sumar exactamente 1.
- El precio V1 de una pizza fusionada es el sabor más caro más extras; remover ingredientes no reduce el precio.
- El inventario de una pizza usa una BASE completa, TOPPING por fracción, extras y removidos; agrega racionalmente y redondea al final a 3 decimales con `HalfUp`.
- El packaging depende de `fulfillment_type`: `TAKEAWAY` aplica reglas configuradas y `DINE_IN` no consume caja por defecto.
- Una línea configurada solo se edita en `DRAFT`; después de enviarla se cancela y reemplaza con trazabilidad.
- Los usuarios se administran mediante `Membership`; crear una cuenta nunca agrega `company_id` a `users` ni reemplaza membresías de otras empresas.
- El último owner activo continúa protegido; la desactivación conserva usuarios y todo su historial.
- El estado Libre/Ocupada de una mesa se deriva de `active_restaurant_table_id`; nunca persistas un segundo indicador de ocupación.
- Entrar nuevamente a una mesa ocupada reutiliza su única cuenta abierta. Venta es el punto de entrada y el POS, cocina, inventario, caja y checkout existentes siguen siendo la autoridad.

## Migraciones y entorno

- No modifiques migraciones históricas ya creadas salvo autorización explícita. Crea una migración nueva para evolucionar el esquema.
- No ejecutes `migrate:fresh`, `db:wipe` ni operaciones destructivas equivalentes.
- No ejecutes migraciones contra MySQL/MariaDB local sin autorización explícita del usuario.
- No cambies la configuración de XAMPP, MySQL/MariaDB o la base `pizzeria_saas` sin autorización.
- Las pruebas deben usar SQLite en memoria según `phpunit.xml`; nunca apuntes la suite automatizada a la base MySQL local.

## Flujo de trabajo y validación

- Antes de editar, revisa archivos relacionados, migraciones existentes y cambios locales; conserva trabajo ajeno o del usuario.
- Realiza cambios mínimos y dentro del sprint indicado.
- Después de modificar código PHP, ejecuta obligatoriamente `php artisan test` y `php vendor/bin/pint --test`, salvo que el usuario prohíba ejecutar comandos.
- En tareas exclusivamente documentales o de configuración de agentes, no ejecutes tests ni Pint a menos que el usuario lo solicite.
- Si una limitación de SQLite impide validar bloqueos reales, prueba la lógica transaccional y documenta que `lockForUpdate()` se verifica en MySQL/MariaDB.
- Reporta archivos creados/modificados, pruebas ejecutadas y cualquier validación que no se haya podido realizar.

## Acciones prohibidas sin autorización explícita

- No hagas deploy.
- No hagas `git push` ni publiques ramas, tags o releases.
- No borres datos ni reescribas historial Git.
- No instales dependencias de producción ni cambies de framework o arquitectura.
