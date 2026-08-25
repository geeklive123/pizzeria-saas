# SPRINT 9C — OPERATIVIDAD BÁSICA: USUARIOS, MESAS Y FLUJO DE ENTRADA A VENTAS/POS

## PROYECTO
Laravel 13 — pizzeria-saas

## ESTADO ACTUAL CONFIRMADO
- Sprints 1–9B completados.
- 29 migraciones aplicadas, todas Ran.
- 166 tests, 515 assertions, PASS.
- Pint PASS.
- npm.cmd run build PASS.
- No hay cambios parciales pendientes conocidos.

## OBJETIVO
Corregir tres problemas operativos detectados al probar manualmente el sistema:

1. Usuarios permite modificar membresías existentes, pero no crear usuarios de forma operativa.
2. Mesas puede indicar que no existen mesas activas, pero no ofrece administración clara para crearlas/configurarlas.
3. Venta / Mesas / Pedidos resulta confuso como punto de entrada y contiene textos desactualizados sobre el cobro.

Este sprint NO es el rediseño UX/UI final. Debe realizar únicamente los cambios mínimos necesarios para probar una pizzería real de principio a fin.

## REGLAS OBLIGATORIAS ANTES DE MODIFICAR
1. Leer completamente AGENTS.md, .codex/project.md y las guías agents/backend.md, agents/database.md, agents/frontend.md y agents/testing.md.
2. Inspeccionar el estado REAL del repositorio.
3. Ejecutar antes de modificar: `php artisan migrate:status`, `php artisan test`, `php vendor/bin/pint --test`.
4. Inspeccionar User, Company, Branch, Membership, roles, permisos, Policies, mesas, pedidos, POS/Venta, KitchenDispatch, caja, pagos y checkout.
5. REUTILIZAR arquitectura existente. No duplicar usuarios, membresías, roles, mesas, pedidos, pagos, caja, inventario ni KitchenDispatch.
6. Si una entidad ya existe, extenderla incrementalmente.
7. No modificar migraciones históricas. Si el esquema necesita cambios, crear solo migraciones incrementales.
8. PROHIBIDO: migrate:fresh, borrar datos, recrear tablas existentes, deploy y push.

## PRINCIPIO DE ARQUITECTURA
Mantener Controllers DELGADOS.
- Mutaciones/reglas de negocio → Actions.
- Validación HTTP → FormRequests.
- Autorización → Policies.
- Consultas/cálculos → Services/Query Objects cuando corresponda.
- Controllers → recibir petición, autorizar, validar, invocar Action/Service y responder.

No introducir lógica de negocio compleja directamente en Controllers.

# PARTE A — ADMINISTRACIÓN DE USUARIOS
Owner/Admin autorizado debe poder crear usuarios con nombre, correo, contraseña inicial + confirmación, rol, sucursal si la arquitectura lo contempla y estado activo/inactivo.

Reutilizar exclusivamente roles existentes: owner, admin, cashier, waiter, kitchen. Mostrar traducciones Propietario, Administrador, Cajero, Mesero y Cocina. No crear roles personalizados ni otro sistema de roles.

Implementar creación mediante Action transaccional (nombre coherente con arquitectura). Debe crear/reutilizar User, crear Membership, asociar solo a empresa activa, asignar rol, respetar multiempresa, evitar duplicados, validar correo, usar Hash y respetar reglas existentes. No asumir que User pertenece a una sola empresa si Membership es la relación real.

Mantener protección del último owner: imposible desactivar al último propietario activo o quitarle owner. No eliminación física; usar desactivación.

Permitir editar nombre cuando corresponda, rol, estado y sucursal/asignación si el modelo lo soporta.

Permitir a owner/admin establecer manualmente una nueva contraseña. No implementar recuperación/invitaciones/verificación por email. Nunca mostrar contraseña existente.

Cashier/waiter/kitchen no administran usuarios. Policies son autoridad final.

# PARTE B — ADMINISTRACIÓN DE MESAS
Inspeccionar primero la entidad existente. No crear otra si ya existe.

Owner/Admin deben poder crear, editar y activar/desactivar mesas. Campos mínimos: nombre o número, capacidad, sucursal y estado activo/inactivo. Reutilizar código/número existente si ya existe. No eliminación destructiva de mesas con historial.

## Áreas de mesas
Si ya existe concepto equivalente, reutilizarlo. Si no existe, agregar DiningArea/TableArea solo si es una extensión pequeña y coherente, aislada por company_id y branch_id. Ejemplos: Salón principal, Terraza, Segundo piso. No crear plano gráfico. Si aumenta demasiado el alcance, documentar y dejar para UX final. Prioridad: crear y usar mesas.

## Estado de mesa
No crear segunda fuente de verdad. Derivar LIBRE/OCUPADA del modelo existente de pedidos/cuentas. No guardar booleano occupied si puede derivarse. Mantener: una mesa ocupada mantiene una sola cuenta abierta.

# PARTE C — FLUJO PRINCIPAL DE VENTA
Corregir textos obsoletos, especialmente “El cobro se implementará posteriormente”, porque caja/pagos ya existen.

VENTA debe ser el punto de entrada operativo para iniciar pedidos. Mantener Mesas y Pedidos:
- Venta → iniciar nueva operación.
- Mesas → visualizar/administrar situación de mesas.
- Pedidos → consultar pedidos/cuentas existentes.
- Cocina → producción.
- Caja → cobro y sesiones.

## Nueva Venta
Mostrar dos opciones claras:
- EN MESA
- PARA LLEVAR

No implementar Delivery.

## En Mesa
Mostrar mesas activas de la sucursal actual con estado y capacidad. Mesa libre → usar flujo existente para abrir pedido y entrar al POS. Mesa ocupada → abrir cuenta/pedido activo existente. NO crear otro pedido.

## Para Llevar
Reutilizar flujo existente con Cliente, Teléfono y Notas (opcionales salvo regla de dominio existente). Abrir pedido y entrar al POS. Debe continuar al checkout/cobro existente.

# PARTE D — POS
NO reconstruir el POS. Solo mejorar su entrada. Debe seguir funcionando productos, variantes, pizzas, tamaños, pizzas fusionadas, secciones, toppings, extras, ingredientes removidos, snapshots, precios, KitchenDispatch, reservas de inventario, pagos y caja.

Si una mesa ocupada pide algo adicional: abrir pedido existente, agregar líneas y enviar únicamente novedades mediante KitchenDispatch existente. No crear segunda cuenta.

# PARTE E — SIDEBAR
No hacer rediseño UX final. Mantener Inicio, Venta, Mesas, Pedidos, Cocina, Caja y módulos administrativos. Venta debe quedar clara como acción principal. No cambios masivos de CSS.

# PARTE F — ESTADOS VACÍOS
Si no existen mesas: owner/admin debe ver `[ Crear primera mesa ]`; waiter/cashier debe ver un mensaje indicando que un administrador debe configurarlas.

En Usuarios, si solo existe owner y tiene autorización, mostrar `[ + Nuevo usuario ]`.

# PARTE G — SEEDER DEMO
Inspeccionar seeders actuales y mantenerlos idempotentes. Para entorno DEMO/local, permitir un conjunto razonable de mesas (por ejemplo Mesa 1 a Mesa 6). No insertar demo automáticamente en producción. Reutilizar TableSeeder si existe.

# PARTE H — PRUEBAS OBLIGATORIAS
## Usuarios
1. Owner puede crear usuario.
2. Usuario asociado a empresa correcta.
3. Rol correcto.
4. Contraseña hasheada.
5. Sin membresía cruzada de otra empresa.
6. Waiter no crea usuarios.
7. Kitchen no crea usuarios.
8. Último owner no puede desactivarse.
9. Último owner no puede perder owner.
10. Owner/admin autorizado puede cambiar contraseña.
11. Usuario desactivado conserva historial.

## Mesas
12. Owner/admin puede crear mesa.
13. Mesa pertenece a sucursal correcta.
14. No se crea/edita mesa de otra empresa.
15. Waiter no administra mesas.
16. Mesa con historial no se elimina destructivamente.
17. Mesa desactivada no aparece para nueva venta.

## Venta
18. Venta muestra En mesa / Para llevar.
19. Mesa libre abre cuenta correctamente.
20. Mesa ocupada reutiliza cuenta existente.
21. No hay dos cuentas abiertas para una mesa.
22. Para llevar crea pedido con flujo existente.
23. Pedido para llevar llega al POS.
24. Agregar líneas conserva misma cuenta.
25. KitchenDispatch sigue funcionando.
26. No duplica consumo/reserva de inventario.
27. Flujo continúa al checkout existente.

Probar aislamiento de empresa, sucursal y roles.

# PARTE I — REGRESIONES
Al finalizar ejecutar:
- `php artisan migrate`
- `php artisan migrate:status`
- `php artisan test`
- `php vendor/bin/pint --test`
- `npm.cmd run build`

Verificar compilación Blade y rutas nuevas/modificadas. Buscar uso accidental de float, double, floatval, doubleval y parseFloat en lógica monetaria/cantidades.

# PARTE J — REVISIÓN MANUAL
Si hay navegador disponible, revisar Usuarios, crear/editar usuario, Mesas vacías, crear/listar mesas, Nueva Venta, En mesa, Para llevar y entrada al POS.

Si no hay navegador conectado, NO bloquear el sprint: validar mediante tests HTTP, compilación Blade, rutas, Policies y build; reportar inspección visual pendiente.

# FUERA DE ALCANCE
No implementar rediseño completo UX/UI, impresión térmica, PWA/offline, delivery, mapa gráfico/drag & drop de mesas, reservas futuras de mesas, roles personalizados, constructor de permisos, invitaciones/recuperación por email, dashboards/reportes nuevos, nuevas reglas financieras/inventario, deploy o push.

# CRITERIO DE ACEPTACIÓN
Debe ser posible manualmente:
1. Entrar como owner.
2. Crear cajero, mesero y usuario cocina.
3. Crear varias mesas.
4. Entrar a Venta → En mesa → mesa libre.
5. Crear pedido, agregar pizza/productos y enviar a cocina.
6. Volver a misma mesa, agregar otro producto sin nueva cuenta y enviar solo lo nuevo.
7. Continuar al checkout, cobrar y liberar mesa según reglas actuales.
8. Crear pedido Para llevar, agregar productos, enviar a cocina y cobrar mediante checkout existente.

# DOCUMENTACIÓN
Actualizar AGENTS.md, .codex/project.md, agents/backend.md, agents/database.md, agents/frontend.md y agents/testing.md, documentando solo lo realmente implementado.

# INFORME FINAL
Informar:
1. Archivos creados.
2. Archivos modificados.
3. Migraciones creadas.
4. Estado final migrate:status.
5. Actions creadas/reutilizadas.
6. Policies modificadas.
7. Cambios de Usuarios.
8. Cambios de Mesas.
9. Cambios de Venta/POS.
10. Tests agregados.
11. Total tests/assertions.
12. Resultado Pint.
13. Resultado Vite.
14. Riesgos pendientes.
15. Decisiones de reutilización de arquitectura.

## IMPORTANTE
No avances al Sprint 10. Detente al completar y validar Sprint 9C.
