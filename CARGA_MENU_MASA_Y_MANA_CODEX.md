# CARGA DE MENÚ REAL — MASA & MAÑA

## Fuente
Archivo revisado: `MENU 19 AGOSTO (1).xlsx`.

Este documento y `MENU_NORMALIZADO_PARA_CODEX.csv` son la fuente para cargar el menú de prueba.

## Qué contiene la carta
- 17 pizzas.
- Cada pizza tiene precios Personal, Mediana y Familiar.
- 10 gaseosas/aguas.
- 2 bebidas naturales.
- 4 bebidas alcohólicas listadas, pero una (`VINO DE ESPECIALIDAD...`) no tiene precio en la fuente.

## Regla crítica: NO inventar recetas
La carta incluye una descripción de ingredientes de cada pizza, PERO NO incluye cantidades.
Por tanto:
- crear productos/sabores y variantes/tamaños;
- guardar la descripción textual de ingredientes;
- NO crear RecipeItem con cantidades inventadas;
- NO alterar inventario ni stock;
- dejar las recetas cuantitativas como pendientes de configuración.

Esto es importante porque una receta real afecta disponibilidad, reservas y consumo de inventario.

## Categorías a crear/reutilizar
1. Pizzas con maña
2. Las de siempre - con maña
3. Gaseosas
4. Jugos naturales
5. Bebidas alcohólicas

Si el sistema necesita una categoría padre `Pizzas` o `Bebidas`, reutilizar la estructura existente sin duplicar categorías.

## Pizzas
Para TODAS las pizzas usar variantes estructuralmente compatibles:

- Personal -> `size_key = personal`
- Mediana -> `size_key = mediana`
- Familiar -> `size_key = familiar`

IMPORTANTE:
- Nunca usar el nombre del sabor como nombre de variante.
- La variante visible debe ser Personal / Mediana / Familiar.
- Todas las pizzas del mismo tamaño deben compartir exactamente el mismo `size_key`, para que el constructor de pizzas de 2, 3 y 4 sabores pueda encontrarlas.
- `goes_to_kitchen = true`.
- Producto activo.
- NO crear receta cuantitativa hasta tener gramajes.

## Venta directa
Gaseosas, agua y vinos con precio:
- cargarlos como productos vendibles directos;
- no requieren receta de cocina;
- no inventar stock inicial;
- el stock se cargará después mediante inventario/compras.

## Jugos naturales
La fuente solo aporta nombre y precio. No dice explícitamente:
- si se preparan en cocina;
- ingredientes;
- cantidades.

Por eso NO asumir automáticamente una receta. Cargarlos de forma que queden identificados para revisión operativa antes de decidir su tratamiento de inventario/cocina.

## Producto sin precio
`VINO DE ESPECIALIDAD(CONSULTAR LA ELECCION DE TEMPORADA)` no tiene precio en el Excel.

NO inventar precio.
Si el esquema exige precio > 0:
- no crear su variante vendible todavía;
- reportarlo como pendiente.
No usar 0 como precio vendible salvo que el dominio lo permita expresamente y quede inactivo.

## Importación
Crear un Seeder/Action de importación idempotente específico para desarrollo/pruebas, por ejemplo:
`MasaYManaMenuSeeder`

Requisitos:
- idempotente;
- buscar/reutilizar empresa y sucursal demo existentes;
- no duplicar Product al ejecutarlo dos veces;
- no duplicar ProductVariant;
- no duplicar Category;
- actualizar descripción/precio solo cuando sea seguro y quede documentado;
- no borrar productos existentes;
- no alterar pedidos históricos;
- no crear stock;
- no crear movimientos;
- no crear recetas cuantitativas inventadas.

La clave idempotente debe usar datos estables del catálogo (empresa + producto + variante), no IDs hardcodeados.

## Verificación funcional obligatoria
Después de importar, comprobar:

1. El catálogo contiene todos los productos con precio disponible.
2. Pizza Pepperoni existente/demo no debe producir duplicados accidentales. Si el producto demo ya existe, decidir explícitamente si se conserva, se actualiza o se desactiva; reportar la decisión.
3. Seleccionar tamaño Personal en el constructor debe devolver TODOS los sabores con variante `personal`.
4. Mediana debe devolver todos los sabores con `mediana`.
5. Familiar debe devolver todos los sabores con `familiar`.
6. Armar una pizza de 2 sabores distintos debe funcionar.
7. Armar 3 sabores debe funcionar.
8. Armar 4 sabores debe funcionar.
9. El precio multisabor conserva la regla de negocio existente (sabor de mayor precio, si esa es la implementación actual).
10. Las pizzas sin receta cuantitativa deben manejarse con un mensaje claro y NO descontar inventario ficticio.
11. Gaseosas no deben aparecer como recetas de cocina.
12. No crear stock ficticio.

## Comandos de validación
- `php artisan migrate:status`
- ejecutar el seeder/importador de menú de forma específica
- ejecutarlo una segunda vez y comprobar idempotencia
- `php artisan test`
- `php vendor/bin/pint --test`
- `npm.cmd run build`

NO:
- migrate:fresh
- borrar datos
- deploy
- push

## Informe final
Reportar:
1. productos creados;
2. variantes creadas;
3. categorías creadas/reutilizadas;
4. productos reutilizados;
5. elementos omitidos por falta de precio;
6. cómo se manejaron las pizzas sin cantidades de receta;
7. resultado de prueba de 2/3/4 sabores;
8. resultado de idempotencia;
9. tests;
10. Pint;
11. build.
