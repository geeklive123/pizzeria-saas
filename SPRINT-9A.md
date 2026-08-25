# SPRINT 9A --- Gastos, egresos y proveedores

## Proyecto

Directorio: raíz del proyecto.

Implementa ÚNICAMENTE el Sprint 9A: **GASTOS + EGRESOS + PROVEEDORES +
INTEGRACIÓN CON CAJA**.

Sprint 8 está validado: - 28 migraciones aplicadas. - Suite completa
previa: 144 tests, 395 assertions. - Pint correcto. - Build Vite
correcto mediante `npm.cmd run build`. - No hay deploy ni push.

## 0. Antes de modificar

1.  Lee `AGENTS.md`.
2.  Lee `agents/`.
3.  Lee `.codex/project.md`.
4.  Inspecciona la arquitectura real de:
    -   Purchase / PurchaseItem;
    -   CashRegister / CashSession / CashMovement;
    -   Payment;
    -   Company / Branch;
    -   usuarios/memberships;
    -   InventoryItem e inventario;
    -   permisos y Policies.
5.  Ejecuta:
    -   `php artisan migrate:status`
    -   `php artisan test`
6.  Reutiliza arquitectura existente.
7.  No crear sistemas financieros paralelos.
8.  No usar `float/double`.
9.  Mantener aislamiento `company_id + branch_id`.
10. Crear únicamente migraciones nuevas.
11. Mantener compatibilidad con SiteGround/Linux.

NO implementar todavía: - reportes completos del Sprint 9B; - impresión
térmica; - facturación fiscal; - cuentas por pagar avanzadas; -
promociones; - offline/PWA.

# 1. Objetivo

Registrar gastos operativos reales de la pizzería y distinguirlos
claramente de las compras de inventario.

Ejemplos de GASTO: - luz; - agua; - Internet; - alquiler; - limpieza; -
mantenimiento; - transporte; - publicidad; - compra urgente no
inventariable.

Ejemplos que NO deben registrarse como gasto común: - mozzarella; -
harina; - pepperoni; - cajas; - Coca-Cola vendible; - cualquier compra
que ingrese existencias al inventario.

Esos siguen pasando por `Purchase`.

# 2. Categorías de gasto

Crear `ExpenseCategory` o equivalente.

Campos conceptuales: - id; - ulid; - company_id; - name; - description
nullable; - is_active; - timestamps.

Categorías iniciales idempotentes: - Servicios básicos - Alquiler -
Limpieza - Mantenimiento - Transporte - Publicidad - Personal -
Impuestos y tasas - Otros

No duplicar categorías al ejecutar seed nuevamente.

# 3. Proveedores

Crear/evolucionar `Supplier` si todavía no existe.

Campos conceptuales: - id; - ulid; - company_id; - name; - tax_id / NIT
nullable; - phone nullable; - email nullable; - address nullable; -
notes nullable; - is_active; - timestamps.

El proveedor debe ser opcional en un gasto.

Diseñar `Supplier` para que pueda reutilizarse posteriormente también en
compras de inventario, sin duplicar tablas de proveedores.

# 4. Expense

Crear `Expense`.

Campos conceptuales: - id; - ulid; - company_id; - branch_id; -
expense_category_id; - supplier_id nullable; - description; - amount; -
expense_date; - document_type; - document_number nullable; -
payment_method; - cash_session_id nullable; - status; - notes
nullable; - created_by; - approved_by nullable si la arquitectura/roles
lo justifican; - reversal_of_id nullable; - timestamps.

No borrar gastos históricos.

# 5. Documento / factura

Necesitamos distinguir al menos: - WITH_INVOICE - WITHOUT_INVOICE -
RECEIPT - OTHER

Si `WITH_INVOICE`: - `document_number` obligatorio.

Si no tiene factura: - document_number puede ser nullable.

No implementar facturación fiscal electrónica.

Esto es solamente registro administrativo del documento recibido.

# 6. Método de pago del gasto

Enum inicial: - CASH - QR - TRANSFER - OTHER

No usar strings libres.

IMPORTANTE: `CASH` puede afectar caja física. QR/TRANSFER no deben
disminuir el efectivo físico de CashSession.

# 7. Integración con caja

Si un gasto se paga en CASH:

-   debe existir CashSession OPEN en la sucursal;
-   generar `CashMovement` tipo `MANUAL_OUT` o un nuevo tipo
    `EXPENSE_OUT` si arquitectónicamente es más limpio;
-   preferir un tipo explícito `EXPENSE_OUT` si no rompe compatibilidad;
-   vincular movimiento y Expense;
-   el egreso debe reducir `expected_cash`;
-   todo dentro de una transacción.

No crear un segundo ledger de efectivo.

# 8. Gasto no efectivo

Si el gasto se paga con: - QR; - TRANSFER; - OTHER;

NO generar salida de efectivo físico.

Sí debe quedar registrado financieramente como Expense.

# 9. Evitar doble registro

Un gasto CASH no debe: 1. crear Expense; 2. y además obligar al usuario
a crear manualmente otro egreso de caja.

El sistema genera automáticamente la salida de caja correspondiente.

Evitar duplicidad.

# 10. Compra de inventario vs gasto

La UI debe explicar claramente la diferencia.

Ejemplo:
`¿Compraste ingredientes o productos para stock? Regístralo en Compras.`

`Gastos` es para costos operativos que no ingresan stock.

No intentar fusionar Purchase y Expense en una sola tabla.

Pero Supplier debe poder reutilizarse.

# 11. Estado y reversión

Estados conceptuales: - POSTED - REVERSED

No necesitamos DRAFT en V1 salvo que la arquitectura actual haga muy
conveniente reutilizarlo.

Un Expense publicado: - no se elimina; - no se modifica silenciosamente
si afecta caja.

Crear `ReverseExpenseAction`.

Al revertir: - crear/registrar reversión auditada; - si fue CASH,
compensar CashMovement; - no permitir doble reversión; - conservar
Expense original.

# 12. Fecha real

Separar: - `expense_date`: fecha administrativa/real del gasto; -
`created_at`: momento de registro.

Permitir registrar hoy una factura de ayer.

No permitir inconsistencias absurdas si ya existe una convención
temporal en el proyecto.

# 13. Validaciones

-   amount \> 0;
-   categoría pertenece a la empresa;
-   supplier pertenece a la empresa;
-   branch pertenece a la empresa;
-   CashSession pertenece a company/branch;
-   document_number obligatorio para WITH_INVOICE;
-   usuario autorizado;
-   enums válidos;
-   no floats.

# 14. Permisos

Revisar matriz existente.

Agregar solo si hacen falta: - expenses.view - expenses.create -
expenses.reverse - expense_categories.manage - suppliers.view -
suppliers.manage

Propuesta: - OWNER: todo. - ADMIN: todo operativo. - CASHIER: ver/crear
gastos operativos si la política lo permite; NO administrar
categorías/proveedores salvo permiso. - WAITER: sin gastos. - KITCHEN:
sin gastos.

Mantener backend protegido por Policies, no solamente ocultar botones.

# 15. UI de Gastos

Activar/agregar `Gastos` en sidebar.

Vista principal con: - fecha; - descripción; - categoría; - proveedor; -
documento; - método de pago; - monto; - estado.

Filtros básicos: - hoy; - rango de fechas; - categoría; - método; -
estado.

No construir todavía dashboard/reportes históricos avanzados.

# 16. Registrar gasto

Formulario claro:

Fecha Categoría Descripción Proveedor (opcional) Monto Método de pago
Documento: - Con factura - Sin factura - Recibo - Otro Número de
documento Notas

Si selecciona CASH y no existe caja abierta:
`Debes abrir caja antes de registrar un gasto en efectivo.`

QR/TRANSFER pueden registrarse sin CashSession si la arquitectura
financiera lo permite.

# 17. UI de proveedores

CRUD básico: - listar; - crear; - editar; - activar/desactivar.

No borrar proveedores con historial.

Mantener ULID público.

# 18. UI de categorías

CRUD básico: - listar; - crear; - editar; - activar/desactivar.

No borrar categorías utilizadas históricamente.

# 19. CashSession Summary

Actualizar el resumen del turno para mostrar claramente: - ventas
CASH; - ingresos manuales; - egresos manuales; - gastos CASH; - efectivo
esperado.

No contar un Expense como venta.

Si se introduce `EXPENSE_OUT`, incluirlo correctamente en expected cash.

# 20. Dashboard

Agregar únicamente indicadores operativos simples si encajan sin
convertir esto en Sprint 9B.

Por ejemplo: - gastos de hoy; - gastos del mes.

Solo si puede hacerse reutilizando servicios limpios.

No implementar todavía reportes completos ni gráficos avanzados.

# 21. Inmutabilidad y auditoría

Una vez que Expense afecta caja: - no editar monto/método/sucursal
silenciosamente.

Usar reversión + nuevo registro cuando sea necesario.

Guardar: - created_by; - timestamps; - reversal relationship; -
referencia al CashMovement correspondiente cuando aplique.

# 22. Concurrencia

Para gasto CASH: - bloquear CashSession abierta; - validar que siga
OPEN; - registrar Expense + CashMovement atómicamente.

Usar: - `DB::transaction()` - `lockForUpdate()` donde corresponda.

# 23. Dinero

No usar float/double.

Todos los cálculos: - amount; - totales; - expected cash; - reversals;

deben utilizar decimales seguros siguiendo la convención actual del
proyecto.

# 24. SiteGround

Mantener: - PHP/Laravel compatible con el proyecto; - nombres de
archivos case-sensitive correctos; - sin dependencias del path local de
Windows; - sin procesos residentes obligatorios; - Vite compilable para
producción; - configuración mediante `.env`.

No hacer deploy todavía.

# 25. Tests mínimos

Agregar pruebas para:

1.  categorías seed idempotentes;
2.  crear categoría;
3.  aislamiento de categoría por empresa;
4.  crear proveedor;
5.  proveedor opcional;
6.  proveedor de otra empresa rechazado;
7.  gasto WITH_INVOICE exige número;
8.  WITHOUT_INVOICE permite número nullable;
9.  gasto amount \> 0;
10. gasto CASH requiere caja abierta;
11. gasto CASH crea CashMovement;
12. gasto CASH reduce expected cash;
13. gasto QR no crea salida física;
14. gasto TRANSFER no crea salida física;
15. gasto no se cuenta como venta;
16. no doble registro de egreso;
17. reversión de gasto;
18. reversión CASH compensa caja;
19. no doble reversión;
20. Expense histórico no se borra;
21. categoría usada no se elimina destructivamente;
22. proveedor usado no se elimina destructivamente;
23. permisos owner;
24. permisos admin;
25. restricciones waiter/kitchen;
26. aislamiento branch/company;
27. concurrencia/transaction según capacidad de tests;
28. no floats;
29. todos los tests anteriores siguen pasando.

Agregar regresiones adicionales si se detectan riesgos.

# 26. Migraciones

Crear únicamente migraciones NUEVAS.

Antes ejecutar: `php artisan migrate:status`

Usar nombres explícitos y CORTOS para: - FK; - índices; - unique
constraints.

Evitar MySQL error 1059.

NO: - `migrate:fresh`; - `migrate:reset`; - modificar migraciones
históricas aplicadas.

# 27. Validación final

Ejecutar: - `php artisan migrate` - `php artisan migrate:status` -
`php artisan test` - `php vendor/bin/pint --test` - `npm.cmd run build`
en Windows si `npm.ps1` está bloqueado.

Revisar manualmente: - Gastos; - Categorías; - Proveedores; - Caja
actual; - resumen de turno.

No deploy. No push. No migrate:fresh.

# 28. Documentación

Actualizar: - `AGENTS.md` - `agents/` - `.codex/project.md`

Documentar especialmente: - Purchase ≠ Expense; - compras inventariables
siguen en Purchase; - Expense CASH genera salida de caja
automáticamente; - QR/TRANSFER no alteran efectivo físico; - gastos
publicados son auditables/inmutables; - correcciones mediante
reversión; - Supplier es reutilizable; - reportes completos quedan para
Sprint 9B.

# 29. Informe final

Al terminar informar:

1.  Arquitectura implementada.
2.  Diferencia técnica Purchase vs Expense.
3.  Migraciones creadas.
4.  Models.
5.  Enums.
6.  Actions.
7.  Services.
8.  Policies/permisos.
9.  Controllers/FormRequests.
10. Rutas.
11. UI Gastos.
12. UI Proveedores.
13. UI Categorías.
14. Factura/sin factura.
15. CASH.
16. QR/TRANSFER.
17. Integración CashMovement.
18. Reversión.
19. Inmutabilidad.
20. Resumen de caja actualizado.
21. Tests nuevos.
22. Total tests/assertions.
23. Pint.
24. npm build.
25. Estado final de migraciones.
26. Riesgos pendientes.

## RESTRICCIÓN FINAL

NO avanzar a Sprint 9B.

Si el proyecto ya contiene alguna entidad equivalente a Supplier,
ExpenseCategory o Expense, reutilizar/evolucionar esa arquitectura en
lugar de duplicarla.

No inventar un sistema financiero paralelo.
