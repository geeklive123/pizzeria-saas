# BUGS — REVISIÓN DE FLUJO PIZZERÍA

## Alcance

Este archivo contiene ÚNICAMENTE los bugs y problemas detectados a partir del punto 7 de la revisión anterior.

No incluye como bugs:
- usuarios y roles;
- flujo general de Venta;
- creación de Mesas;
- selector vacío de Caja;
- ParseError de Inventario;
- mensaje de productos no servidos.

Esos puntos pueden revisarse por separado como ajustes operativos/hotfixes previos.

IMPORTANTE:
- No avanzar a un nuevo sprint.
- Mantener la arquitectura existente.
- Controllers delgados.
- Reglas de negocio en Actions/Services.
- No modificar migraciones históricas.
- No usar migrate:fresh.
- No borrar datos.
- No hacer deploy ni push.
- Todos los mensajes visibles al usuario deben estar en español.

---

# BUG 1 — RECETAS: NO SE PUEDEN CREAR NUEVAS DE FORMA NATURAL

## Problema

En "Nueva receta" aparece:

> "Todas las variantes preparadas activas ya tienen una receta configurada."

Esto deja al usuario sin una acción útil y no explica cómo crear una nueva receta/sabor.

## Problema conceptual

Producto, Variante y Receta están demasiado expuestos como conceptos separados.

Para una pizzería, el flujo debería sentirse así:

1. Crear sabor/producto.
2. Crear tamaños.
3. Configurar receta por tamaño.

## Se requiere

Revisar el flujo:

Producto/Sabor → Tamaños → Receta por tamaño.

Ejemplo:

Pizza Hawaiana
- Personal → configurar receta
- Mediana → configurar receta
- Familiar → configurar receta

Si todas las variantes existentes ya tienen receta:
- explicar que debe crearse un nuevo producto/sabor o tamaño;
- mostrar acceso directo a Productos;
- permitir crear nueva versión de receta si el dominio ya soporta versionado.

No dejar una pantalla vacía sin acción.

---

# BUG 2 — PRODUCTOS: SIZE_KEY EXPUESTO AL USUARIO

## Problema

Al crear una pizza con variantes aparecen errores como:

> "The variants.0.size_key field format is invalid."
> "The variants.1.size_key field format is invalid."

Ejemplo:

Producto:
- Pizza Prueba

Variantes:
- Pequeña — Bs 25
- Grande — Bs 45

## Problema UX

La interfaz obliga al usuario a escribir "Clave de tamaño", un dato técnico.

Además:
- "Pequeña" puede fallar por ñ/mayúsculas/tildes;
- el usuario no debería saber qué es `size_key`.

## Se requiere

El usuario solo debe gestionar el nombre visible:

- Pequeña
- Mediana
- Grande
- Familiar

Generar internamente:

- pequeña → `pequena`
- mediana → `mediana`
- grande → `grande`
- familiar → `familiar`

Normalizar:
- minúsculas;
- espacios;
- tildes;
- ñ;
- caracteres especiales.

Ocultar `size_key` de la interfaz normal si no es estrictamente necesario.

Mantenerlo internamente para compatibilidad entre sabores/tamaños.

Los mensajes deben estar en español y asociados al campo correcto.

---

# BUG 3 — NOMBRES DE VARIANTES CONFUSOS / DUPLICADOS

## Problema

Anteriormente aparecieron errores como:

> "The variants.0.name field has a duplicate value."
> "The variants.1.name field has a duplicate value."

Esto ocurre porque el formulario lleva al usuario a repetir el nombre del producto dentro de cada variante.

## Se requiere

El nombre de variante debe representar el tamaño/presentación:

- Personal
- Mediana
- Familiar

No obligar a repetir:

- Pizza Pepperoni
- Pizza Pepperoni
- Pizza Pepperoni

El producto ya define el sabor/nombre principal.

---

# BUG 4 — FLUJO PRODUCTO → TAMAÑOS → RECETAS

## Problema

El flujo actual obliga al usuario a comprender demasiados detalles internos.

## Flujo esperado

1. Crear producto/sabor:
   - Pizza Pepperoni.

2. Añadir tamaños y precios:
   - Personal Bs 25
   - Mediana Bs 45
   - Familiar Bs 70

3. Guardar.

4. Mostrar:
   > "Pizza creada correctamente. ¿Quieres configurar sus recetas?"

5. Entrar a Recetas del producto.

6. Configurar ingredientes de cada tamaño.

Debe distinguirse claramente:
- receta configurada;
- receta pendiente;
- venta directa sin receta.

---

# BUG 5 — RECETAS: VENTA DIRECTA SE CONFUNDE CON RECETA

## Problema

Productos como Coca-Cola aparecen dentro de Recetas y pueden dar la impresión de que necesitan receta de cocina.

## Se requiere

Diferenciar claramente:

### Producto preparado
- requiere receta;
- muestra Editar receta.

### Venta directa
- no requiere receta;
- disponibilidad sale del inventario directo.

Mostrar por ejemplo:

> "Venta directa — no requiere receta"

No mostrar botones ambiguos como "Crear" si no representan una receta real.

---

# BUG 6 — CONSTRUCTOR DE PIZZA: SABORES COMPATIBLES NO APARECEN

## Problema

Al seleccionar un tamaño, en el selector de sabores solo aparece Pizza Pepperoni o no aparecen otros sabores esperados.

## Posible causa

Las variantes de distintos sabores no comparten correctamente la misma compatibilidad interna de tamaño (`size_key`).

## Se requiere

Todos los sabores con una variante activa del mismo tamaño deben aparecer.

Ejemplo:

Si existen:

Pepperoni:
- Personal
- Mediana
- Familiar

Hawaiana:
- Personal
- Mediana
- Familiar

Primavera:
- Personal
- Mediana
- Familiar

Entonces una pizza Familiar debe permitir seleccionar:
- Pepperoni
- Hawaiana
- Primavera

El trabajador no debe conocer `size_key`.

Si solo hay un sabor compatible, mostrar:

> "Solo hay 1 sabor disponible para este tamaño. Configura el mismo tamaño en otros sabores para poder combinarlos."

---

# BUG 7 — CONSTRUCTOR DE PIZZA: FRACCIONES TÉCNICAS EN UI

## Problema

La interfaz mostró anteriormente:

- Numerador
- Denominador

Esto es técnico y confuso.

## Se requiere

El usuario solo debe elegir:

- 1 sabor
- 2 sabores
- 3 sabores
- 4 sabores

El sistema calcula internamente:

- 1 sabor → 1/1
- 2 sabores → 1/2 + 1/2
- 3 sabores → tres partes equivalentes
- 4 sabores → 1/4 cada uno

No exponer Numerador/Denominador.

Usar etiquetas naturales:

### 1 sabor
- Sabor

### 2 sabores
- Mitad 1
- Mitad 2

### 3 sabores
- Parte 1
- Parte 2
- Parte 3

### 4 sabores
- Cuarto 1
- Cuarto 2
- Cuarto 3
- Cuarto 4

---

# BUG 8 — SABORES DUPLICADOS MUESTRAN ERROR TÉCNICO

## Problema

Al elegir el mismo sabor en dos secciones aparece:

> "The sections.0.variant field has a duplicate value."
> "The sections.1.variant field has a duplicate value."

## Se requiere

No mostrar nombres internos ni índices.

Mensaje esperado:

> "Ese sabor ya está seleccionado. Elige otro sabor o utiliza la opción 1 sabor."

Además:
- mantener validación backend;
- evitar duplicados desde UI con JavaScript ligero cuando sea posible;
- no depender solo de JS.

---

# BUG 9 — PEDIDO ACTUAL: EDICIÓN DEMASIADO TÉCNICA

## Problema

En el panel "Pedido actual" aparecen:
- selectores internos;
- cantidades como `1.000`;
- entrega;
- observación;
- Guardar cambios;
- estructuras poco claras.

Esto hace difícil entender qué se está editando.

## Se requiere

Cada línea debe verse de forma simple:

Pizza Familiar
- Pepperoni / Hawaiana
- Cantidad: 1
- Comer aquí
- Bs XX

Acciones:
- Editar
- Quitar

No mostrar `1.000` cuando la unidad visual es una pieza.

Si se edita la pizza:
- abrir un editor claro;
- no exponer estructuras internas.

Verificar que agregar/editar no duplique accidentalmente líneas.

---

# BUG 10 — PAGO MIXTO CONFUSO

## Problema

Para una cuenta de Bs 140 se muestran simultáneamente:

### Efectivo
- Monto a pagar: 140
- Recibido: 140

### QR
- Monto: 140

Visualmente parece que se van a cobrar Bs 280.

Los botones:
- "Confirmar efectivo"
- "Confirmar QR"

no explican bien que cada operación registra un pago parcial.

## Se requiere

Usar un flujo secuencial.

Ejemplo inicial:

TOTAL: Bs 140
PAGADO: Bs 0
RESTANTE: Bs 140

¿Cómo paga?

[ Efectivo ]
[ QR ]

### Si selecciona efectivo

Monto a pagar:
[ 100 ]

Recibido:
[ 100 ]

Vuelto:
Bs 0

[ Registrar pago en efectivo ]

Después:

TOTAL: Bs 140
PAGADO: Bs 100
RESTANTE: Bs 40

Pagos realizados:
✓ Efectivo Bs 100

¿Cómo paga el restante?

[ Efectivo ]
[ QR ]

### Si selecciona QR

Precargar:

Monto QR:
Bs 40

Referencia:
[ opcional ]

[ Registrar pago QR ]

Después:

TOTAL: Bs 140
PAGADO: Bs 140
RESTANTE: Bs 0

Pagos:
✓ Efectivo Bs 100
✓ QR Bs 40

[ Finalizar cuenta ]

## Regla técnica

NO cambiar el modelo financiero:

- mixto = varios Payment;
- NO crear method=MIXED;
- QR no aumenta efectivo físico esperado;
- efectivo sí;
- mantener idempotencia;
- mantener transacciones;
- mantener locks existentes.

---

# BUG 11 — MONTO RESTANTE NO SE PRECARGA

## Problema

Después de registrar un pago parcial, el siguiente método de pago no se adapta automáticamente al saldo pendiente.

## Se requiere

Si:

Total = Bs 140
Efectivo pagado = Bs 100

El sistema debe actualizar:

Restante = Bs 40

Si luego selecciona QR:

Monto sugerido = Bs 40

No dejar nuevamente Bs 140 como valor por defecto.

---

# BUG 12 — VUELTO POCO VISIBLE

## Se requiere

Si:

Restante: Bs 40
Recibido: Bs 50

Mostrar ANTES de registrar:

Monto a pagar: Bs 40
Recibido: Bs 50
Vuelto: Bs 10

El vuelto debe ser visible y fácil de entender.

No tratar el vuelto como gasto.

---

# BUG 13 — MENSAJES EN INGLÉS Y CAMPOS INTERNOS

## Problema

Se encontraron mensajes como:

- The variants.0.name field has a duplicate value.
- The variants.0.size_key field format is invalid.
- The sections.0.variant field has a duplicate value.
- All active order items must be served before closing.

## Se requiere

Ningún error operativo debe mostrar:
- índices;
- nombres de arrays;
- campos internos;
- inglés.

Ejemplos:

> "El tamaño Pequeña ya está agregado."

> "Selecciona sabores diferentes."

> "Aún hay productos pendientes de servir."

> "El tamaño seleccionado no está disponible para este sabor."

---

# BUG 14 — COBRAR VS FINALIZAR PEDIDO

## Problema

Actualmente aparece:

> "All active order items must be served before closing."

Hay que revisar si la Action de pago está intentando cerrar/finalizar el pedido automáticamente.

## Se requiere

Distinguir:

1. Registrar pago.
2. Completar pago.
3. Estado de cocina.
4. Finalizar pedido.
5. Liberar mesa.

No cambiar reglas a ciegas.

### Caso a revisar

Para llevar:
- el cliente puede pagar antes de que cocina termine;
- el pedido puede quedar pagado pero todavía en preparación.

Mesa:
- puede existir pago, pero no liberar mesa antes de cumplir la regla operativa correspondiente.

Si la arquitectura ya permite separar estos estados:
- utilizarla.

Si no:
- conservar temporalmente la regla;
- mejorar el mensaje;
- documentar la limitación.

---

# BUG 15 — PRECIO DE PIZZA MULTISABOR DEBE SER CLARO

## Se requiere

Mantener la regla existente del Sprint 7.

Si actualmente:

Precio multisabor = sabor más caro

entonces conservarlo.

La UI debe mostrar antes de agregar:

> Precio estimado: Bs XX

El backend sigue siendo autoridad final.

No duplicar cálculo monetario crítico en JS.

---

# BUG 16 — DISPONIBILIDAD DE SABORES

## Se requiere

El constructor debe reutilizar los servicios existentes de disponibilidad.

No crear otro cálculo.

Mostrar de forma clara:
- disponible;
- no disponible;
- sabor/tamaño incompatible;
- ingrediente faltante cuando el servicio actual ya pueda determinarlo.

No mostrar conceptos técnicos.

---

# CRITERIO GENERAL DE UX

La interfaz debe hablar en términos de pizzería.

Evitar mostrar al usuario operativo:

- variant
- size_key
- sections.0
- numerator
- denominator
- fulfillment
- inventory_item_id

Estos conceptos pueden permanecer internamente.

---

# PRUEBA FUNCIONAL OBLIGATORIA

Probar este escenario:

1. Crear Pizza Pepperoni.
2. Agregar tamaños:
   - Personal
   - Mediana
   - Familiar.
3. Configurar receta para cada tamaño.
4. Crear Pizza Hawaiana.
5. Agregar los mismos tamaños.
6. Configurar recetas.
7. Abrir una venta.
8. Elegir pizza Familiar.
9. Elegir 2 sabores.
10. Deben aparecer Pepperoni y Hawaiana.
11. Seleccionar ambos.
12. No debe mostrarse Numerador/Denominador.
13. Ver precio estimado correcto.
14. Agregar pizza.
15. Ver línea de pedido clara.
16. Enviar a cocina.
17. Cobrar cuenta de ejemplo Bs 140.
18. Registrar Bs 100 efectivo.
19. Saldo debe cambiar a Bs 40.
20. Seleccionar QR.
21. QR debe sugerir Bs 40.
22. Registrar QR.
23. Saldo debe quedar Bs 0.
24. Finalizar cuenta según las reglas de cocina/mesa vigentes.

También probar:
- 1 sabor;
- 3 sabores;
- 4 sabores;
- sabor duplicado;
- tamaño con ñ/tildes;
- venta directa;
- receta faltante;
- inventario insuficiente;
- pago 100% efectivo con vuelto;
- pago 100% QR;
- pago parcial.

---

# VALIDACIÓN TÉCNICA

Al finalizar:

- `php artisan migrate:status`
- `php artisan test`
- `php vendor/bin/pint --test`
- `npm.cmd run build`

Además:
- compilar Blade;
- verificar rutas;
- revisar Policies;
- revisar FormRequests;
- revisar Actions/Services;
- no usar float binario;
- no modificar migraciones históricas;
- no migrate:fresh;
- no deploy;
- no push.

---

# INFORME FINAL

Entregar:

1. Bugs corregidos.
2. Archivos modificados.
3. Actions/Services reutilizados o creados.
4. Cambios en Producto/Tamaños.
5. Cambios en Recetas.
6. Cambios en constructor de pizza.
7. Cambios en Pedido actual.
8. Cambios en Caja/pago mixto.
9. Mensajes traducidos.
10. Tests añadidos.
11. Total tests/assertions.
12. Pint.
13. Build.
14. Riesgos pendientes.

NO avanzar a otro sprint.
