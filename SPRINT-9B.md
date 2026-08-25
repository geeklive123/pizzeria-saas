# SPRINT 9B --- Reportes, métricas y análisis operativo/financiero

## Proyecto

Directorio: raíz del proyecto.

Implementa ÚNICAMENTE el Sprint 9B: **REPORTES + MÉTRICAS + ANÁLISIS
OPERATIVO/FINANCIERO**.

Sprint 9A debe estar implementado antes de este sprint.

## 0. Antes de modificar

1.  Lee `AGENTS.md`.
2.  Lee `agents/`.
3.  Lee `.codex/project.md`.
4.  Inspecciona la arquitectura real de:
    -   orders;
    -   order_items;
    -   payments;
    -   cash_sessions;
    -   cash_movements;
    -   expenses;
    -   purchases;
    -   inventory_movements;
    -   inventory_stocks;
    -   inventory_batches;
    -   products / variants;
    -   recipes;
    -   users / memberships / roles;
    -   branches / companies.
5.  Ejecuta:
    -   `php artisan migrate:status`
    -   `php artisan test`
6.  Reutiliza los datos y servicios existentes.
7.  No duplicar información histórica en tablas de "reportes" salvo que
    una agregación materializada esté claramente justificada.
8.  No usar `float/double`.
9.  Mantener aislamiento `company_id + branch_id`.
10. Mantener compatibilidad con SiteGround/Linux.

NO implementar todavía: - impresión térmica; - facturación fiscal
boliviana; - promociones avanzadas; - offline/PWA; - BI externo; - cubos
OLAP; - exportaciones contables oficiales.

# 1. Objetivo

Crear un módulo de reportes claro y útil para el dueño/administrador de
la pizzería.

Debe responder preguntas reales como:

-   ¿Cuánto vendimos hoy?
-   ¿Cuánto vendimos esta semana/mes?
-   ¿Cuántos pedidos se atendieron?
-   ¿Cuál fue el ticket promedio?
-   ¿Qué pizzas/productos se venden más?
-   ¿Qué método de pago se usa más?
-   ¿Cuánto gastamos?
-   ¿Cuánto compramos para inventario?
-   ¿Cuánto se perdió por mermas?
-   ¿Cuál es el valor actual del inventario?
-   ¿Qué ingredientes se consumen más?
-   ¿Qué productos dejan mejor margen estimado?
-   ¿Qué días/horas venden más?
-   ¿Qué sucursal rinde mejor en el futuro?
-   ¿Qué usuarios/cajeros atendieron más operaciones cuando sea
    permitido?

# 2. Principio de datos

Los reportes deben derivarse de las fuentes de verdad existentes:

-   ventas/pedidos: `Order` + `Payment`;
-   caja: `CashSession` + `CashMovement`;
-   gastos: `Expense`;
-   compras: `Purchase`;
-   inventario: `InventoryMovement` + `InventoryStock` + lotes;
-   catálogo: `Product` + `ProductVariant`;
-   recetas/costos: servicios existentes.

No crear una segunda fuente de verdad.

No recalcular ventas históricas usando precios actuales del catálogo.
Usar snapshots históricos de Order/OrderItem/Payment.

# 3. Rango de fechas

Todos los reportes principales deben soportar:

-   Hoy
-   Ayer
-   Esta semana
-   Este mes
-   Mes anterior
-   Rango personalizado

Usar zona horaria de la empresa/sucursal y presentación consistente.

Evitar errores de límites de día.

# 4. Filtros globales

Cuando corresponda:

-   branch;
-   rango de fechas;
-   método de pago;
-   categoría;
-   producto;
-   estado;
-   usuario/cajero si el rol puede verlo.

Respetar siempre company/branch context.

# 5. Dashboard de reportes

Crear una vista principal `/reports`.

Resumen inicial:

-   Ventas totales
-   Pedidos cobrados
-   Ticket promedio
-   Efectivo
-   QR
-   Gastos
-   Compras de inventario
-   Mermas
-   Resultado estimado
-   Valor de inventario actual

No mezclar conceptos.

Ejemplo:

    Ventas                Bs 4.850
    Pedidos                    53
    Ticket promedio        Bs 91,51

    Gastos                  Bs 450
    Mermas                  Bs 120

    Resultado estimado    Bs X.XXX

El resultado debe etiquetarse "estimado" si depende de costos
actuales/promedios y no de contabilidad formal.

# 6. Ventas

Crear reporte de ventas con:

-   total vendido;
-   pedidos cobrados;
-   ticket promedio;
-   ventas por día;
-   ventas por hora;
-   ventas por tipo de pedido:
    -   dine-in
    -   takeaway
-   ventas por categoría;
-   ventas por producto;
-   ventas por variante/tamaño.

No contar pedidos parciales como completamente cobrados si el dominio
actual distingue ese estado.

# 7. Métodos de pago

Reporte separado:

-   CASH
-   QR
-   otros futuros métodos

Mostrar: - monto; - número de pagos; - porcentaje del total.

Los pagos reversados no deben contar como ingresos activos.

Pago mixto se refleja naturalmente por sus Payments individuales.

# 8. Productos más vendidos

Ranking por:

-   cantidad vendida;
-   ingreso generado.

Separar: - Product - ProductVariant

Ejemplo:

    1. Pepperoni Familiar   83 u   Bs 6.557
    2. Hawaiana Mediana    72 u   Bs 4.968

Para pizzas fusionadas: - definir claramente cómo atribuir ventas. - El
OrderItem completo cuenta como 1 pizza vendida. - Para análisis de
sabores, las secciones pueden contribuir proporcionalmente o como
participación separada.

Documentar la regla elegida.

# 9. Sabores / pizzas fusionadas

Agregar análisis específico:

-   sabores más solicitados;
-   combinaciones más comunes;
-   porcentaje de pizzas de 1, 2, 3 y 4 sabores;
-   extras más usados;
-   ingredientes removidos más frecuentes.

No es obligatorio meter todo en la primera pantalla; puede ser pestaña o
subsección.

# 10. Gastos

Reporte de Expense:

-   total de gastos;
-   por categoría;
-   por método de pago;
-   con factura / sin factura;
-   por proveedor;
-   evolución diaria/mensual.

No mezclar Purchases de inventario con Expenses.

# 11. Compras de inventario

Reporte separado:

-   total comprado;
-   proveedores;
-   artículos comprados;
-   costo promedio de compra;
-   volumen por periodo;
-   compras por sucursal;
-   compras revertidas excluidas del total activo.

# 12. Mermas

Reporte de desperdicio/merma:

-   cantidad;
-   costo estimado;
-   artículo;
-   motivo;
-   fecha;
-   usuario;
-   lote si aplica;
-   vencimiento si aplica.

Mostrar: `Costo estimado de merma`

No presentarlo como dato contable formal si usa average_cost.

# 13. Inventario

Reporte de inventario actual:

-   stock físico;
-   vencido;
-   reservado;
-   disponible;
-   stock mínimo;
-   costo promedio;
-   valor en stock;
-   próximo vencimiento.

Permitir ordenar por: - valor; - stock bajo; - vencimiento; - nombre.

# 14. Consumo de ingredientes

A partir del ledger real:

-   ingrediente;
-   cantidad consumida;
-   unidad base;
-   costo estimado;
-   periodo.

Separar cuando sea posible: - consumo por producción; - merma; -
ajuste; - otros movimientos.

No inferir consumo solo desde recetas si el ledger ya tiene movimientos
reales.

# 15. Rentabilidad estimada

Crear reporte de margen estimado por producto/variante.

Conceptualmente:

## Ingreso histórico

# Costo histórico/estimado de ingredientes consumidos

Margen estimado

IMPORTANTE: No usar recetas actuales para reescribir el costo histórico
si existen snapshots/movimientos de costo.

Si no existe costo histórico exacto suficiente: - usar la mejor
aproximación disponible; - etiquetar claramente como `estimado`.

No presentar utilidad contable formal.

# 16. Resultado estimado del periodo

Concepto:

Ventas cobradas - costo estimado de producción - gastos operativos -
mermas = resultado estimado

Compras de inventario NO deben restarse doble si el costo de producción
ya se reconoce al consumir inventario.

Este punto debe estar documentado cuidadosamente para evitar doble
conteo.

# 17. Caja

Reporte por CashSession:

-   apertura;
-   cierre;
-   efectivo esperado;
-   efectivo contado;
-   diferencia;
-   ventas cash;
-   QR asociado al turno;
-   ingresos manuales;
-   egresos manuales;
-   gastos cash;
-   usuario que abrió/cerró;
-   duración del turno.

Permitir entrar al detalle de una sesión.

# 18. Diferencias de caja

Reporte:

-   fecha;
-   caja;
-   cajero;
-   esperado;
-   contado;
-   diferencia.

Resaltar diferencias relevantes.

No acusar ni inferir fraude.

Solo mostrar datos.

# 19. Horarios de mayor venta

Crear agregación por hora:

Ejemplo:

    12:00–13:00   12 pedidos
    13:00–14:00   18 pedidos
    19:00–20:00   31 pedidos

También considerar día de semana si es fácil hacerlo limpiamente.

No forecasting todavía.

# 20. Usuarios / personal

Solo para OWNER/ADMIN autorizado:

-   órdenes gestionadas;
-   pagos registrados;
-   sesiones de caja;
-   gastos registrados;
-   acciones operativas relevantes.

No convertirlo en monitoreo invasivo.

No mostrar métricas de rendimiento personal a roles sin permiso.

# 21. Exportación

Agregar exportación práctica de reportes principales.

Preferencia: - CSV - Excel/XLSX si ya existe dependencia compatible o si
se puede añadir una solución liviana y mantenible.

PDF puede dejarse para después si requiere una dependencia pesada.

Como mínimo soportar exportación de: - ventas; - gastos; - compras; -
inventario; - caja.

Los filtros activos deben aplicarse también al archivo exportado.

Usar nombres de archivo legibles con fecha.

# 22. Rendimiento

Evitar N+1.

Usar: - agregaciones SQL; - eager loading; - Services/Queries
especializados.

No cargar miles de filas en memoria si puede agregarse en BD.

Agregar paginación en tablas de detalle.

No optimizar prematuramente con cache persistente compleja.

# 23. Arquitectura de reportes

Preferir Services/Query objects como:

-   SalesReportService
-   PaymentsReportService
-   ExpenseReportService
-   InventoryReportService
-   CashReportService
-   ProfitabilityReportService

o equivalentes.

Controladores delgados.

Evitar consultas enormes dentro de Blade.

# 24. UI/UX

Sidebar: activar `Reportes`.

Vista con pestañas o navegación:

-   Resumen
-   Ventas
-   Productos
-   Caja
-   Gastos
-   Compras
-   Inventario
-   Mermas
-   Rentabilidad

No saturar una sola página.

Usar cards, tablas y gráficos simples.

# 25. Gráficos

Usar una solución ligera ya existente o JavaScript mínimo.

No introducir un framework frontend completo.

Gráficos útiles: - ventas por día; - ventas por hora; - métodos de
pago; - gastos por categoría; - productos más vendidos.

Si se añade librería de charts, justificarla y mantenerla ligera.

No depender de servicios externos.

# 26. Formato Bolivia

-   moneda `Bs`;
-   separadores/decimales consistentes;
-   fechas legibles en español;
-   timezone configurada;
-   cantidades con unidad.

Reutilizar `UiFormatter` u otra abstracción existente.

# 27. Permisos

Agregar/reutilizar:

-   reports.view
-   reports.financial
-   reports.export

Si ya existe equivalente, reutilizar.

Propuesta: - OWNER: todos. - ADMIN: reportes operativos y financieros
según permisos. - CASHIER: caja/ventas limitadas si corresponde. -
WAITER: sin reportes financieros. - KITCHEN: sin reportes financieros.

Backend protegido por Policies/gates.

# 28. Seguridad

No permitir cambiar `company_id`/`branch_id` por parámetros para acceder
a datos ajenos.

Validar filtros.

Exportaciones deben respetar exactamente el mismo scope/autorización que
la pantalla.

# 29. Tests mínimos

Agregar pruebas para:

1.  ventas hoy;
2.  ventas por rango;
3.  pedido parcial no contado como cobrado completo;
4.  Payment reversed excluido;
5.  ventas CASH;
6.  ventas QR;
7.  pago mixto correctamente desglosado;
8.  ticket promedio;
9.  ranking productos;
10. variantes;
11. pizzas fusionadas sin duplicar unidades;
12. gastos por categoría;
13. compras separadas de gastos;
14. mermas;
15. stock físico/vencido/reservado/disponible;
16. valor de inventario;
17. consumo ingredientes;
18. resultado estimado sin doble restar compras;
19. cash sessions;
20. diferencias de caja;
21. rango por sucursal;
22. aislamiento empresa;
23. permisos financieros;
24. export respeta filtros;
25. export no filtra datos ajenos;
26. decimales sin float;
27. rendimiento básico/no N+1 crítico donde sea testeable;
28. todos los tests anteriores siguen pasando.

# 30. Migraciones

Evitar crear tablas de reportes si los datos pueden derivarse.

Si se necesita alguna tabla auxiliar/materializada: - justificar
explícitamente; - crear migración nueva; - no convertirla en fuente
principal de verdad.

NO `migrate:fresh`. NO modificar migraciones históricas aplicadas.

# 31. Validación final

Ejecutar: - `php artisan migrate` - `php artisan migrate:status` -
`php artisan test` - `php vendor/bin/pint --test` - `npm.cmd run build`
si PowerShell bloquea `npm.ps1`.

Revisar manualmente: - `/reports` - filtros - tablas - exports -
permisos

No deploy. No push. No migrate:fresh.

# 32. Documentación

Actualizar: - `AGENTS.md` - `agents/` - `.codex/project.md`

Documentar: - reportes derivados de fuentes de verdad; - Purchases ≠
Expenses; - pagos reversados no cuentan; - pago mixto = varios
Payments; - resultado/rentabilidad son estimados cuando corresponde; -
compras de inventario no se descuentan dos veces; - exportaciones
respetan scopes; - reportes financieros restringidos por permisos.

# 33. Informe final

Al terminar informar:

1.  Arquitectura de reportes.
2.  Services/Queries creados.
3.  Controllers/FormRequests.
4.  Rutas.
5.  UI/tabs.
6.  Resumen general.
7.  Ventas.
8.  Métodos de pago.
9.  Productos/variantes.
10. Pizzas fusionadas.
11. Gastos.
12. Compras.
13. Inventario.
14. Mermas.
15. Rentabilidad estimada.
16. Resultado estimado.
17. Caja.
18. Diferencias.
19. Horarios.
20. Exportaciones.
21. Permisos.
22. Migraciones si hubo.
23. Tests nuevos.
24. Total tests/assertions.
25. Pint.
26. npm build.
27. Estado migraciones.
28. Riesgos/limitaciones pendientes.

## RESTRICCIÓN FINAL

NO avanzar al siguiente sprint.

No implementar impresión térmica ni offline todavía.

Si alguna métrica no puede calcularse con precisión con la información
histórica existente, mostrarla como estimada y explicar la limitación en
el informe final. No inventar exactitud.
