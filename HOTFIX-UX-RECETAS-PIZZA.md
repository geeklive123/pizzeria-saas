# HOTFIX UX — RECETAS, CONSTRUCTOR DE PIZZA Y MENSAJES OPERATIVOS

## PROYECTO
Laravel 13 — pizzeria-saas

## OBJETIVO

Corregir problemas de usabilidad detectados durante la prueba manual sin avanzar a un nuevo sprint y sin reconstruir la arquitectura existente.

Problemas observados:

1. La pantalla Recetas no deja claro cómo crear y administrar recetas.
2. Productos de venta directa como Coca-Cola aparecen mezclados visualmente con recetas de cocina.
3. El constructor de pizza expone `Numerador` y `Denominador`, conceptos internos que el trabajador no debería manejar.
4. Al seleccionar el mismo sabor en dos secciones aparece un error técnico:
   `The sections.0.variant field has a duplicate value.`
5. Existen mensajes operativos en inglés, por ejemplo:
   `All active order items must be served before closing.`
6. Debe revisarse la diferencia entre COBRAR un pedido y CERRAR/FINALIZAR un pedido, sin romper las reglas actuales de cocina, caja, pagos e inventario.

NO avanzar a otro sprint.

---

# REGLAS PREVIAS

Antes de modificar:

1. Leer completamente:
   - AGENTS.md
   - .codex/project.md
   - agents/backend.md
   - agents/database.md
   - agents/frontend.md
   - agents/testing.md

2. Inspeccionar la implementación REAL de:
   - Product
   - ProductVariant
   - Ingredient
   - Recipe
   - recetas activas/versionadas si existen
   - pizza compositions
   - sections
   - modifiers
   - toppings
   - removals
   - Order
   - OrderItem
   - KitchenDispatch
   - checkout
   - Payment
   - CashSession
   - InventoryReservation

3. Ejecutar baseline:
   - `php artisan migrate:status`
   - `php artisan test`
   - `php vendor/bin/pint --test`

4. Reutilizar arquitectura existente.

5. Controllers delgados.
6. Mutaciones/reglas de negocio en Actions.
7. Validación en FormRequests.
8. Autorización en Policies.
9. Consultas/cálculos complejos en Services cuando corresponda.

PROHIBIDO:
- migrate:fresh
- borrar datos
- modificar migraciones históricas
- duplicar entidades existentes
- deploy
- push

---

# PARTE A — REORGANIZAR PANTALLA DE RECETAS

## Problema

La pantalla actual mezcla visualmente:

- productos con receta real;
- productos de venta directa;
- variantes/tamaños;
- acciones como `Crear` y `Editar`.

Esto resulta ambiguo.

## Objetivo

La pantalla debe comunicar claramente:

> Recetas  
> Configura qué ingredientes consume cada producto preparado.

Agregar una acción visible para usuarios autorizados:

`+ Nueva receta`

Pero antes de crear cualquier flujo nuevo, inspeccionar cómo se crean actualmente Product, ProductVariant y Recipe.

NO duplicar el catálogo.

## Diferenciar tipos

Separar visualmente como mínimo:

### Productos preparados
Productos/variantes que requieren receta.

Ejemplo:
- Pizza Pepperoni
  - Personal
  - Mediana
  - Familiar

Mostrar:
- precio;
- disponibilidad estimada;
- ingrediente limitante si ya existe ese cálculo;
- acción `Editar receta`.

### Venta directa
Productos que salen directamente del inventario y NO necesitan receta de cocina.

Ejemplo:
- Coca-Cola 500 ml

Debe quedar explícitamente identificado como:

`Venta directa — no requiere receta`

NO mostrar un botón ambiguo `Crear` si realmente significa otra operación.

Si el producto no necesita receta, no debe parecer que falta crearle una receta.

## Nueva receta

El flujo debe permitir seleccionar un producto/variante elegible que todavía no tenga receta activa y configurar:

- ingrediente;
- cantidad;
- unidad compatible según arquitectura existente.

Permitir agregar varias líneas.

Ejemplo conceptual:

Pizza Hawaiana — Mediana

- Masa: 320 g
- Salsa: 110 g
- Queso: 180 g
- Jamón: 100 g
- Piña: 80 g

[ + Agregar ingrediente ]

[ Guardar receta ]

No asumir cantidades ni ingredientes reales.

## Evitar duplicados

No permitir dos recetas activas simultáneas para el mismo slot si el dominio actual ya maneja `active_slot` o mecanismo equivalente.

Respetar versionado/inmutabilidad existente.

Si editar una receta activa actualmente genera una nueva versión, conservar esa arquitectura.

---

# PARTE B — SIMPLIFICAR CONSTRUCTOR DE PIZZA

## Problema

Actualmente el POS muestra al trabajador:

- Numerador
- Denominador

Esto representa internamente fracciones como 1/2, 1/3 o 1/4, pero NO debe ser una decisión manual del usuario operativo.

## Objetivo

Eliminar de la interfaz los inputs editables de numerador y denominador.

NO eliminar el modelo racional/fraccionario del dominio.

La UI debe generar automáticamente las fracciones.

### 1 sabor
- sección 1 = 1/1

### 2 sabores
- sección 1 = 1/2
- sección 2 = 1/2

### 3 sabores
- cada sección = 1/3

### 4 sabores
- cada sección = 1/4

Estas fracciones deben enviarse/calcularse de forma segura en backend.

NO confiar exclusivamente en valores hidden manipulables enviados por navegador.

El servidor debe validar/calcular la composición esperada según cantidad de sabores.

---

# PARTE C — UX DEL CONSTRUCTOR

La interfaz debe quedar aproximadamente:

## Armar pizza

### Tamaño
[ Personal ▼ ]

### ¿Cuántos sabores?
[ 1 sabor ] [ 2 sabores ] [ 3 sabores ] [ 4 sabores ]

Después mostrar únicamente la cantidad necesaria de selectores:

Con 1:
- Sabor

Con 2:
- Mitad 1
- Mitad 2

Con 3:
- Parte 1 de 3
- Parte 2 de 3
- Parte 3 de 3

Con 4:
- Cuarto 1
- Cuarto 2
- Cuarto 3
- Cuarto 4

No mostrar Numerador/Denominador.

Mantener:
- tamaño;
- fulfillment/entrega;
- observaciones;
- toppings;
- extras;
- ingredientes removidos;
- demás personalizaciones existentes.

No reconstruir el POS.

---

# PARTE D — SABORES DUPLICADOS

## Problema actual

Seleccionar Pepperoni + Pepperoni produce:

`The sections.0.variant field has a duplicate value.`
`The sections.1.variant field has a duplicate value.`

Ese mensaje no es aceptable para un usuario operativo.

## Regla UX

Si se seleccionan múltiples sabores, cada selector debe representar un sabor diferente.

Si el usuario intenta repetir el mismo sabor:

- impedirlo en la interfaz cuando sea razonable;
- mantener validación obligatoria en backend.

Mostrar mensaje en español:

`Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción "1 sabor".`

NO depender solamente de JavaScript.

Backend debe seguir rechazando una composición inválida.

## Selectores

Cuando un sabor ya está seleccionado en otra sección, idealmente deshabilitarlo/ocultarlo en los demás selectores mediante JS ligero.

Si cambia la selección, actualizar las opciones disponibles.

Sin framework JS nuevo.

---

# PARTE E — MENSAJES DE VALIDACIÓN

No mostrar mensajes internos Laravel en inglés al usuario.

Revisar específicamente validaciones relacionadas con:

- sections
- variant
- duplicate/distinct
- cocina
- checkout
- cierre de pedido
- pagos

Traducir los mensajes operativos relevantes al español.

Ejemplo:

NO:
`The sections.0.variant field has a duplicate value.`

SÍ:
`Ese sabor ya está seleccionado. Elige otro sabor.`

NO:
`All active order items must be served before closing.`

SÍ:
`Aún hay productos pendientes en cocina.`

Y explicación:

`Antes de finalizar el pedido, todos los productos deben estar servidos.`

No traducir a ciegas excepciones internas que deban permanecer en logs.
La UI debe recibir mensajes amigables.

---

# PARTE F — COBRAR VS FINALIZAR PEDIDO

Este punto requiere inspección antes de modificar comportamiento.

Actualmente aparece:

`All active order items must be served before closing.`

Determinar exactamente en qué Action/Service ocurre y qué operación está bloqueando.

Distinguir conceptualmente:

1. REGISTRAR PAGO
2. COMPLETAR EL PAGO
3. CERRAR/FINALIZAR PEDIDO
4. LIBERAR MESA

No asumir que son necesariamente el mismo evento.

## Requisito

Primero documentar la regla actual.

Si actualmente la Action de pago intenta cerrar automáticamente el pedido y por eso exige que todo esté servido, evaluar si puede separarse SIN romper invariantes.

Comportamiento deseado:

- Debe ser posible registrar pagos parciales según Sprint 8.
- El estado financiero debe depender de Payment.
- El estado de cocina debe depender de KitchenDispatch/OrderItem según arquitectura existente.
- Una mesa NO debe liberarse prematuramente.
- Un pedido NO debe considerarse finalizado mientras existan líneas activas pendientes si esa es la regla del dominio.

### Caso importante: pago anticipado

La arquitectura debe soportar, si es coherente con las reglas existentes:

Pedido para llevar:
1. crear pedido;
2. agregar productos;
3. enviar a cocina;
4. cobrar;
5. cocina todavía puede estar preparando;
6. pedido queda pagado pero NO necesariamente finalizado/servido;
7. cuando cocina termina y se cumple la condición existente, puede finalizarse.

Mesa:
El pago puede registrarse, pero NO liberar la mesa si todavía corresponde mantener la cuenta/pedido operativo según reglas existentes.

NO hacer este cambio si requiere una reescritura grande.

Si la separación ya está soportada por los estados existentes, utilizarla.

Si no está soportada de manera segura, conservar la regla actual y únicamente mejorar el mensaje, documentando la limitación.

No inventar estados duplicados.

---

# PARTE G — PRECIO DE PIZZA MULTISABOR

No cambiar arbitrariamente la regla financiera.

Inspeccionar la regla implementada en Sprint 7.

Mantener exactamente la regla existente para calcular el precio de una pizza con varios sabores.

Si actualmente utiliza el sabor/variante de mayor precio, conservarlo.

La UI debe mostrar claramente el precio resultante ANTES de agregar la pizza si el cálculo existente permite hacerlo sin duplicar lógica.

El backend sigue siendo la autoridad final.

No realizar cálculos monetarios críticos únicamente con JavaScript.

---

# PARTE H — DISPONIBILIDAD

Conservar el cálculo actual de disponibilidad estimada basado en inventario/receta.

En el constructor de pizza:

Si es posible reutilizando servicios existentes, mostrar disponibilidad del sabor/tamaño.

No implementar un segundo cálculo.

Si una combinación no tiene disponibilidad suficiente, usar la validación existente y mostrar un mensaje amigable.

No permitir que UX cree reglas diferentes a InventoryReservation.

---

# PARTE I — PRUEBAS

Agregar/regresar pruebas para:

## Recetas

1. Usuario autorizado puede acceder a administración de recetas.
2. Producto preparado puede tener receta.
3. Producto de venta directa queda identificado sin exigir receta.
4. No se crean dos recetas activas para el mismo slot.
5. Edición conserva mecanismo de versionado existente.
6. Usuario no autorizado no administra recetas.
7. Aislamiento por empresa.

## Constructor

8. 1 sabor genera 1/1.
9. 2 sabores generan 1/2 + 1/2.
10. 3 sabores generan tres secciones 1/3.
11. 4 sabores generan cuatro secciones 1/4.
12. Backend no confía en fracciones manipuladas desde cliente.
13. Sabores duplicados son rechazados.
14. Mensaje de duplicado es amigable/en español.
15. Dos sabores distintos se agregan correctamente.
16. Precio conserva regla existente.
17. InventoryReservation conserva cantidades correctas.
18. KitchenDispatch conserva composición correcta.
19. Snapshots continúan funcionando.

## Checkout/cocina

20. Mensaje de productos pendientes está en español.
21. Pago parcial continúa funcionando.
22. Pago mixto continúa funcionando.
23. QR continúa sin afectar efectivo físico.
24. Mesa no se libera prematuramente.
25. Pedido no se cierra violando reglas de cocina.
26. Si se implementa pago anticipado separado del cierre, probar explícitamente:
    - pedido puede quedar pagado;
    - cocina sigue pendiente;
    - no se libera/finaliza prematuramente;
    - finalización posterior funciona correctamente.

---

# PARTE J — VALIDACIÓN FINAL

Ejecutar:

```bash
php artisan migrate
php artisan migrate:status
php artisan test
php vendor/bin/pint --test
npm.cmd run build
```

Además:

- compilar vistas Blade;
- revisar rutas afectadas;
- revisar FormRequests;
- revisar Policies;
- revisar Actions/Services;
- buscar accidentalmente:
  - float
  - double
  - floatval
  - doubleval
  - parseFloat

No introducir punto flotante binario en dinero ni cantidades críticas.

---

# PARTE K — REVISIÓN MANUAL

Si hay navegador conectado, probar:

1. Recetas.
2. Crear receta.
3. Editar receta.
4. Producto de venta directa.
5. Abrir una venta.
6. Armar pizza de 1 sabor.
7. Armar pizza de 2 sabores diferentes.
8. Intentar repetir sabor.
9. Armar pizza de 3 sabores.
10. Armar pizza de 4 sabores.
11. Enviar a cocina.
12. Intentar cobrar con cocina pendiente.
13. Completar flujo de cocina.
14. Cobrar.
15. Verificar liberación/finalización.

Si no hay navegador conectado, no bloquear el hotfix.
Usar tests HTTP, Blade compilation y build.

---

# FUERA DE ALCANCE

NO implementar:

- rediseño completo del sistema;
- nuevo POS;
- nuevas reglas de inventario;
- nuevas reglas de caja;
- delivery;
- impresión;
- PWA/offline;
- mapa de mesas;
- dashboards;
- reportes;
- roles nuevos;
- frameworks JS;
- dependencias visuales grandes;
- deploy;
- push.

---

# CRITERIO DE ACEPTACIÓN

El trabajador debe poder entender el constructor sin conocer fracciones matemáticas.

Debe poder:

1. elegir tamaño;
2. elegir cantidad de sabores;
3. elegir sabores distintos;
4. personalizar;
5. agregar pizza;
6. enviarla a cocina.

Nunca debe escribir manualmente numerador o denominador.

Recetas debe diferenciar claramente productos preparados de productos de venta directa.

Los errores operativos visibles deben estar en español y explicar qué hacer.

La arquitectura existente de:
- recetas;
- inventario;
- reservas;
- KitchenDispatch;
- precios;
- pagos;
- caja

debe seguir siendo la autoridad.

---

# INFORME FINAL

Al terminar informa:

1. Causa del problema de recetas.
2. Cómo quedó el flujo Crear/Editar receta.
3. Cómo se diferencian productos preparados y venta directa.
4. Cómo se eliminó Numerador/Denominador de la UI.
5. Dónde se calculan ahora las fracciones.
6. Cómo se manejan sabores duplicados.
7. Mensajes traducidos/mejorados.
8. Qué determinaste sobre cobrar vs finalizar.
9. Si se permitió pago anticipado o se conservó la restricción y por qué.
10. Archivos creados.
11. Archivos modificados.
12. Migraciones creadas, si fueron realmente necesarias.
13. Tests agregados.
14. Total tests/assertions.
15. Resultado migrate:status.
16. Resultado Pint.
17. Resultado Vite.
18. Riesgos pendientes.

NO avances a otro sprint.
