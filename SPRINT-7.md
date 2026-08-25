# SPRINT 7 --- Pizzas fusionadas, personalizaciones, precio e inventario

## Proyecto

Directorio: raíz del proyecto.

Baseline validado: - `php artisan migrate:status`: correcto. - 27
migraciones aplicadas. - `php artisan test`: 116 tests, 345 assertions,
todos aprobados. - `vendor\bin\pint --test`: aprobado. - MySQL/MariaDB
operativo. - No hay cambios parciales del intento anterior.

Implementa ÚNICAMENTE este sprint.

## 0. Antes de modificar

1.  Lee `AGENTS.md`.
2.  Lee `agents/`.
3.  Lee `.codex/project.md`.
4.  Inspecciona Product, ProductVariant, Recipe, RecipeItem, Order,
    OrderItem, InventoryReservation, KitchenDispatch y servicios
    relacionados.
5.  Ejecuta nuevamente `php artisan test` como baseline.
6.  No dupliques lógica existente.
7.  No usar `float/double`.
8.  Mantener aislamiento `company_id + branch_id`.
9.  Mantener compatibilidad futura con SiteGround/Linux.
10. Crear únicamente migraciones nuevas si hacen falta.

NO implementar todavía: impresión térmica, reservas de mesas/clientes,
gastos, reportes finales, promociones avanzadas ni offline/PWA.

## 1. Objetivo

Permitir pizzas de 1 sabor, mitad y mitad, 3 sabores y 4 sabores.

Ejemplos: - 50% Hawaiana + 50% Pepperoni. - 25% Hawaiana + 25%
Pepperoni + 25% Primavera + 25% Tocino. - 1/2 + 1/4 + 1/4.

La suma de fracciones debe ser exactamente una pizza.

## 2. Combinaciones

No crear productos nuevos como `Mitad Hawaiana / Mitad Pepperoni`. La
combinación pertenece al `OrderItem`.

## 3. Tamaño compatible

Todas las secciones deben usar variantes compatibles del mismo tamaño.
No depender solamente del nombre textual. Si el catálogo no modela
tamaño estructuralmente, crear la mínima abstracción limpia y segura.

## 4. OrderItemSection

Crear entidad equivalente a `OrderItemSection` con: - id - company_id -
order_item_id - product_variant_id - fraction_numerator -
fraction_denominator - position - unit_price_snapshot - timestamps

Usar numerador/denominador enteros, nunca floats.

Validar exactamente: - 1/2 + 1/2 = 1 - 1/4 + 1/4 + 1/4 + 1/4 = 1 - 1/3 +
1/3 + 1/3 = 1 - 1/2 + 1/4 + 1/4 = 1

Rechazar sumas incompletas o mayores a 1. Máximo 4 secciones.

## 5. Receta BASE + TOPPING

Evolucionar las recetas de pizza para distinguir conceptualmente BASE y
TOPPING sin romper productos no-pizza ni destruir recetas existentes.

Ejemplo Familiar:

BASE: - Masa 450 g - Salsa 150 g - Mozzarella 300 g

HAWAIANA: - Jamón 100 g - Piña 120 g

PEPPERONI: - Pepperoni 120 g

Una 50/50 debe reservar:
`BASE completa + 50% toppings Hawaiana + 50% toppings Pepperoni`.

## 6. Precio

Política V1: PRECIO DEL SABOR MÁS CARO.

Ejemplo: - Hawaiana Familiar Bs 75 - Pepperoni Familiar Bs 80 -
mitad/mitad = Bs 80

Cuatro sabores 75/80/78/85 = Bs 85.

Guardar snapshot final en OrderItem. No recalcular históricos.

## 7. Personalizaciones

Permitir: - quitar ingrediente: sin cebolla, sin aceituna; - agregar
extra: extra queso, pepperoni, tocino; - observación libre: bien cocida,
cortar en 8.

Extras/removidos que afectan inventario deben estar estructurados, no
solamente como texto.

## 8. Modificadores

Crear estructura limpia equivalente a ProductModifier/ModifierOption.

Un ADD puede tener nombre, price_delta, inventory_item_id o
ingredient_id compatible, quantity y unit.

Ejemplo: Extra queso = +Bs 5 y +80 g mozzarella.

Debe aumentar precio y reserva de inventario.

## 9. Remover ingrediente

Ejemplo Hawaiana SIN PIÑA: - reduce reserva/consumo correspondiente; -
mantiene historial; - NO reduce precio en V1; - nunca produce consumo
negativo.

## 10. Modificador por sección

Debe poder aplicarse al OrderItem completo o a una OrderItemSection
específica.

Ejemplo: 1/2 Hawaiana + 1/2 Pepperoni, sin piña solo en Hawaiana.

## 11. Inventario

Reutilizar InventoryReservation existente.

Cálculo:
`BASE completa + toppings por sección × fracción + extras - removidos`.

Ejemplo: BASE: Masa 450 g, Salsa 150 g, Mozzarella 300 g. 50% Hawaiana:
Jamón 50 g, Piña 60 g. 50% Pepperoni: Pepperoni 60 g. Extra queso: +80
g. Mozzarella final: 380 g.

No crear un segundo sistema de inventario.

## 12. Precisión

Usar BigDecimal o la abstracción decimal exacta ya usada por el
proyecto.

Caso: `100 g × 1/3`.

Definir y documentar precisión/redondeo consistente. Nunca float/double.
Validar la suma de fracciones racionalmente.

## 13. Disponibilidad

Antes de agregar la pizza fusionada validar conjuntamente: - base; -
toppings fraccionados; - extras; - removidos; - stock no vencido; -
reservas activas.

Si falta algo, rechazar sin reservas parciales y hacer rollback
completo. Mensaje amigable: `No disponible: falta piña.`

## 14. POS

Actualizar UI Blade/Tailwind existente, sin React/Vue.

Flujo: 1. elegir pizza; 2. elegir tamaño; 3. elegir 1, 2, 3 o 4 sabores;
4. elegir sabores; 5. extras/removidos; 6. observación; 7. fulfillment;
8. agregar.

Mostrar botones `[1 sabor] [2 sabores] [3 sabores] [4 sabores]`.

## 15. Pedido actual

Mostrar, por ejemplo:

    1 × Pizza Familiar
        1/2 Hawaiana
        1/2 Pepperoni
        + Extra queso
        - Sin piña
        Para llevar
    Bs 85,00

## 16. Cocina / KDS

Integrarse al KitchenDispatch existente. No crear otro flujo de cocina.
No mostrar precio.

Ejemplo:

    1 × FAMILIAR
    1/2 HAWAIANA
       - SIN PIÑA
    1/2 PEPPERONI
    + EXTRA QUESO
    PARA LLEVAR

## 17. Packaging

Una pizza DINE_IN no debe consumir caja obligatoriamente.

Si las cajas están dentro de recetas, evolucionar de forma segura hacia
reglas equivalentes a PackagingRule: - company_id - variante/tamaño
compatible - fulfillment_type - inventory_item_id - quantity

Ejemplo: - Familiar TAKEAWAY -\> Caja familiar x1 - Familiar DINE_IN -\>
sin caja

No destruir movimientos históricos.

## 18. Cambio de fulfillment

Antes de cocina: - DINE_IN -\> TAKEAWAY: reservar caja. - TAKEAWAY -\>
DINE_IN: liberar caja.

Todo transaccional.

## 19. Edición DRAFT

Mientras OrderItem sea editable/draft permitir cambiar sabores,
fracciones, extras, removidos y fulfillment.

Recalcular precio, reservas y disponibilidad atómicamente. Si la nueva
composición no tiene disponibilidad, rollback completo conservando
estado anterior.

## 20. Línea enviada

Después de enviar a cocina, NO modificar silenciosamente. Usar
cancelación + nueva línea o flujo auditado equivalente.

## 21. Auditoría / snapshots

Guardar suficiente snapshot para entender históricos aunque cambien
nombres, recetas, precios, modificadores o composición. No depender
únicamente del catálogo vivo.

## 22. Tests mínimos

Agregar pruebas para: 1. 1 sabor. 2. mitad/mitad. 3. 3 sabores. 4. 4
sabores. 5. fracción inválida. 6. máximo 4. 7. tamaños incompatibles. 8.
precio más alto. 9. snapshot precio. 10. base no duplicada. 11. toppings
fraccionados. 12. 1/3 con precisión segura. 13. extra aumenta precio.
14. extra aumenta reserva. 15. remover reduce reserva. 16. remover no
reduce precio. 17. modificador por sección. 18. agotado rechaza. 19.
rollback reservas. 20. editar draft recalcula. 21. enviado no se edita.
22. KDS muestra secciones. 23. TAKEAWAY reserva caja. 24. DINE_IN no
reserva caja. 25. cambio fulfillment actualiza caja. 26. aislamiento
multiempresa. 27. tests anteriores siguen pasando.

## 23. MySQL / migraciones

Antes ejecutar `php artisan migrate:status`.

Crear solo migraciones nuevas. Usar nombres explícitos y CORTOS para
foreign keys, índices y unique constraints para evitar MySQL 1059.

NO `migrate:fresh`. NO `migrate:reset`. NO modificar migraciones
históricas aplicadas.

## 24. Validación final

Ejecutar: - `php artisan migrate` - `php artisan migrate:status` -
`php artisan test` - `php vendor/bin/pint --test` - `npm run build`

Revisar manualmente POS, pedido y KDS.

NO deploy. NO push. NO migrate:fresh.

## 25. Documentación

Actualizar donde corresponda: - AGENTS.md - agents/ - .codex/project.md

Documentar: - máximo 4 sabores; - fracciones suman exactamente 1; -
precio V1 = sabor más caro; - base completa + toppings fraccionados; -
extras afectan precio/inventario; - removidos afectan inventario, no
precio; - packaging depende de fulfillment; - línea enviada no se
modifica libremente; - estrategia de precisión.

## 26. Informe final

Al terminar informar: 1. Arquitectura implementada. 2. Migraciones. 3.
Models. 4. Enums. 5. Actions/Services. 6. Representación de fracciones.
7. Compatibilidad de tamaños. 8. Estrategia base/toppings. 9. Estrategia
de precio. 10. Cálculo de inventario. 11. Ejemplo 50/50. 12. Ejemplo 4
sabores. 13. Extras/removidos. 14. Packaging. 15. POS. 16. KDS. 17.
Snapshots. 18. Tests nuevos. 19. Total tests/assertions. 20. Pint. 21.
npm build. 22. Estado de migraciones. 23. Riesgos pendientes.

## Restricción final

NO avanzar al siguiente sprint.

Si encuentras incompatibilidad importante con la arquitectura real, no
inventes un sistema paralelo. Adapta el diseño a las entidades
existentes y explica en el informe final qué ajuste arquitectónico
realizaste y por qué.
