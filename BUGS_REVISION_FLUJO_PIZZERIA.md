# REVISIÓN FUNCIONAL Y BUGS -- SISTEMA PIZZERÍA

## Objetivo

Corregir los problemas funcionales y de experiencia de usuario
encontrados durante la revisión manual del sistema. No avanzar a un
nuevo Sprint hasta terminar, probar y validar estos puntos.

IMPORTANTE: - Mantener la arquitectura existente. - No reemplazar
Actions por lógica de negocio dentro de Controllers. - Controllers
delgados. - No modificar migraciones históricas. - No usar migrate:fresh
ni operaciones destructivas. - Mantener aislamiento por
empresa/sucursal, Policies, permisos, transacciones e idempotencia
existentes. - Los cambios de interfaz no deben romper las reglas de
negocio ya implementadas. - Todos los mensajes visibles al usuario deben
estar en español. - Al finalizar ejecutar migrate:status, pruebas
específicas, suite completa, Pint y npm.cmd run build.

------------------------------------------------------------------------

# 1. USUARIOS Y ROLES

## Problema

La pantalla de Usuarios permite visualizar/modificar parcialmente una
membresía existente, pero no existe un flujo administrativo completo
para crear nuevos usuarios.

Actualmente incluso aparece el mensaje indicando que la creación de
usuarios y recuperación de contraseñas están pendientes.

## Se requiere

-   Permitir crear usuarios desde la interfaz.
-   Asignar rol al crear el usuario.
-   Poder activar/desactivar usuarios.
-   Poder cambiar el rol posteriormente.
-   Mantener protección del último propietario/owner.
-   Evitar que un usuario sin permisos administrativos gestione
    usuarios.
-   Mostrar roles con nombres comprensibles en español:
    -   Propietario
    -   Administrador
    -   Cajero
    -   Mesero
    -   Cocina
-   Validaciones y errores completamente en español.
-   Revisar si hace falta un flujo seguro para contraseña inicial/cambio
    obligatorio.

------------------------------------------------------------------------

# 2. FLUJO DE VENTA CONFUSO

## Problema

La separación actual entre Venta, Mesas y Pedidos no resulta
suficientemente clara para un trabajador.

## Se requiere

Revisar el flujo completo para que sea evidente cómo iniciar: - consumo
en mesa; - pedido para llevar; - y posteriormente cualquier otro
fulfillment ya soportado.

"Venta" debe funcionar como punto de entrada claro y no
duplicar/confundir las funciones de Mesas y Pedidos.

El trabajador no debería necesitar conocer conceptos internos del
dominio para registrar una venta.

------------------------------------------------------------------------

# 3. MESAS -- NO SE PODÍAN CREAR / NO APARECÍAN

## Problema

En la pantalla Mesas apareció: "No hay mesas activas para esta
sucursal."

No había una forma evidente de crear/configurar mesas desde esa misma
experiencia.

## Se requiere

-   Permitir al usuario autorizado crear mesas.
-   Editar nombre/número.
-   Activar/desactivar mesas.
-   Asociarlas correctamente a empresa y sucursal.
-   Mostrar inmediatamente las mesas activas.
-   Mantener la regla de una sola cuenta abierta por mesa.
-   Incluir estados visuales claros: Libre / Ocupada.
-   Evitar que el flujo dependa de seeders para poder utilizar el
    sistema normalmente.

------------------------------------------------------------------------

# 4. APERTURA DE CAJA -- SELECTOR VACÍO

## Problema

En "Abrir turno" el selector de Caja apareció vacío y el botón quedó
inutilizable.

## Se requiere

-   Revisar por qué no aparecen cajas disponibles para la sucursal
    activa.
-   Si no existe ninguna caja, mostrar una explicación clara y una
    acción para crear/configurar una caja cuando el rol tenga permiso.
-   No presentar un select vacío sin explicación.
-   Verificar el seeder de Caja Principal y el aislamiento por sucursal.
-   Verificar el flujo completo: crear caja -\> abrir turno -\>
    registrar pagos -\> movimientos -\> cerrar turno.

------------------------------------------------------------------------

# 5. INVENTARIO -- ERROR 500 / PARSEERROR

## Problema

Al gestionar un producto del inventario apareció un error 500:

resources/views/inventory/show.blade.php

ParseError: syntax error, unexpected token "endforeach", expecting
"elseif" or "else" or "endif"

## Se requiere

-   Corregir la estructura Blade de inventory/show.blade.php.
-   Revisar todos los @if, @can, @foreach, @endif, @endcan y @endforeach
    relacionados.
-   Compilar/verificar las vistas Blade.
-   Añadir una prueba HTTP que abra el detalle de inventario y evite que
    este error vuelva a aparecer.
-   Comprobar manualmente operaciones de inventario después de
    corregirlo.

------------------------------------------------------------------------

# 6. COBRO BLOQUEADO POR PRODUCTOS NO SERVIDOS + MENSAJE EN INGLÉS

## Problema

Al intentar cobrar apareció:

"All active order items must be served before closing."

Además de estar en inglés, el usuario no entiende qué debe hacer para
continuar.

## Se requiere

-   Mantener la regla de negocio si realmente es necesaria.
-   Traducir el mensaje al español.
-   Mostrar una explicación accionable, por ejemplo: "Aún hay productos
    pendientes de servir. Marca todos los productos como servidos desde
    Cocina antes de cerrar la cuenta."
-   Idealmente ofrecer acceso directo a Cocina o indicar cuáles líneas
    siguen pendientes.
-   Revisar que el flujo Cocina -\> Servido -\> Cobrar sea evidente.

------------------------------------------------------------------------

# 7. RECETAS -- NO SE PUEDEN CREAR NUEVAS

## Problema

En "Nueva receta" apareció:

"Todas las variantes preparadas activas ya tienen una receta
configurada."

Esto impide crear una nueva receta y no queda claro cómo crear un nuevo
sabor/receta.

## Problema conceptual

Actualmente Producto, Variante y Receta están demasiado expuestos al
usuario como conceptos separados. Para una pizzería, el flujo debería
ser mucho más natural.

## Se requiere

Revisar el flujo: Producto/Sabor -\> Tamaños -\> Receta por tamaño.

Ejemplo: Pizza Hawaiana - Personal -\> configurar receta - Mediana -\>
configurar receta - Familiar -\> configurar receta

Si todas las variantes existentes ya tienen receta, la pantalla debe
explicar: - que primero debe crearse un nuevo producto/sabor o un nuevo
tamaño; - y ofrecer un botón directo para hacerlo.

No dejar una pantalla vacía sin una acción útil.

------------------------------------------------------------------------

# 8. PRODUCTOS -- SIZE_KEY EXPUESTO Y VALIDACIÓN INCORRECTA

## Problema

Al crear una pizza con variantes aparecieron errores como:

"The variants.0.size_key field format is invalid." "The
variants.1.size_key field format is invalid."

También anteriormente aparecieron errores por nombres duplicados de
variantes.

Ejemplo ingresado: Producto: Pizza Prueba Variantes: - Pequeña -- Bs
25 - Grande -- Bs 45

## Problema de UX

La interfaz obliga al usuario a introducir "Clave de tamaño", que es un
dato técnico.

"Pequeña" además falla por caracteres como ñ/mayúsculas dependiendo de
la validación.

## Se requiere

-   El usuario solo debe escribir/seleccionar el nombre visible del
    tamaño: Pequeña, Mediana, Grande, Familiar, etc.
-   Generar automáticamente size_key internamente: pequeña -\> pequena
    mediana -\> mediana grande -\> grande familiar -\> familiar
-   Normalizar minúsculas, espacios, tildes, ñ y caracteres especiales.
-   Preferiblemente ocultar "Clave de tamaño" de la interfaz normal.
-   Mantener size_key internamente porque se necesita para
    compatibilidad entre sabores/tamaños.
-   Los nombres de las variantes deben representar el
    tamaño/presentación, no repetir obligatoriamente el nombre completo
    del producto.
-   Todos los errores deben mostrarse en español y asociados al campo
    concreto.

------------------------------------------------------------------------

# 9. RELACIÓN PRODUCTO -\> TAMAÑOS -\> RECETAS

## Problema

El flujo actual obliga a entender demasiados detalles internos.

## Flujo esperado

1.  Crear producto/sabor: Pizza Pepperoni.
2.  Añadir tamaños y precios: Personal Bs 25 Mediana Bs 45 Familiar Bs
    70
3.  Guardar.
4.  Mostrar: "Pizza creada correctamente. ¿Quieres configurar sus
    recetas?"
5.  Entrar a recetas del producto.
6.  Configurar ingredientes de cada tamaño.

Debe ser posible identificar claramente qué tamaños tienen receta y
cuáles están pendientes.

------------------------------------------------------------------------

# 10. CONSTRUCTOR DE PIZZA -- SABORES NO FUNCIONAN COMO SE ESPERA

## Problema

Al armar una pizza, al seleccionar tamaño y cantidad de sabores, solo
aparece Pizza Pepperoni o no aparecen correctamente otros sabores
compatibles.

Anteriormente también aparecieron errores como: "The sections.0.variant
field has a duplicate value." "The sections.1.variant field has a
duplicate value."

## Se requiere

-   Revisar la compatibilidad mediante size_key.
-   Todos los sabores que tengan una variante activa del mismo tamaño
    deben aparecer.
-   Ejemplo: si Pepperoni, Hawaiana y Primavera tienen variante
    "Familiar", las tres deben aparecer al armar una Familiar.
-   El trabajador no debe conocer size_key.
-   Si solo existe un sabor compatible, mostrar un mensaje claro: "Solo
    hay 1 sabor disponible para este tamaño. Configura el mismo tamaño
    en otros sabores para poder combinarlos."
-   No mostrar errores técnicos como sections.0.variant.
-   Traducir validaciones a mensajes de negocio.

------------------------------------------------------------------------

# 11. CONSTRUCTOR DE PIZZA -- FRACCIONES CONFUSAS

## Problema

Anteriormente la interfaz mostraba campos técnicos: - Numerador -
Denominador

Esto es confuso para un cajero/mesero.

## Se requiere

El usuario debe elegir simplemente: - 1 sabor - 2 sabores - 3 sabores -
4 sabores

El sistema debe calcular internamente: - 1 sabor = 1/1 - 2 sabores =
1/2 + 1/2 - 3 sabores = fracciones correspondientes - 4 sabores = 1/4
cada uno

No exponer numeradores ni denominadores en la interfaz operativa.

Usar etiquetas naturales como: - Sabor 1 - Sabor 2 o, cuando
corresponda: - Mitad 1 - Mitad 2

------------------------------------------------------------------------

# 12. PEDIDO ACTUAL -- EDICIÓN DE SABORES POCO CLARA

## Problema

En el panel "Pedido actual" aparecen selectores, cantidades decimales
como "1.000", entrega, observación y "Guardar cambios", haciendo difícil
entender qué se está modificando.

También se observaron pizzas repetidas en el pedido durante las pruebas.

## Se requiere

-   Simplificar la tarjeta de cada línea.
-   Mostrar claramente: Pizza Familiar Sabores: Pepperoni / Hawaiana
    Cantidad: 1 Precio: Bs XX Entrega: Comer aquí
-   Usar cantidad visual "1", no "1.000" para unidades enteras.
-   Botones claros para Editar y Quitar.
-   Al editar, abrir una experiencia comprensible y no exponer
    estructuras internas.
-   Verificar que "Agregar pizza" no duplique accidentalmente líneas por
    errores de interacción.

------------------------------------------------------------------------

# 13. CAJA -- PAGO MIXTO MUY CONFUSO

## Problema

Para una cuenta de Bs 140 la pantalla muestra simultáneamente:

Efectivo: Monto a pagar: 140 Recibido: 140

QR: Monto: 140

Esto visualmente parece que se cobrarán Bs 280.

Los botones "Confirmar efectivo" y "Confirmar QR" tampoco hacen evidente
que cada operación registra un pago parcial.

## Se requiere

Cambiar a un flujo secuencial.

Ejemplo:

TOTAL: Bs 140 PAGADO: Bs 0 RESTANTE: Bs 140

¿Cómo paga? \[ Efectivo \] \[ QR \]

Si selecciona Efectivo: Monto a pagar: \[100\] Recibido: \[100\] Vuelto:
Bs 0

\[ Registrar pago en efectivo \]

Después de registrar:

TOTAL: Bs 140 PAGADO: Bs 100 RESTANTE: Bs 40

Pagos realizados: ✓ Efectivo Bs 100

¿Cómo paga el restante? \[ Efectivo \] \[ QR \]

Si selecciona QR, el sistema debe precargar automáticamente: Monto QR:
Bs 40

\[ Registrar pago QR \]

Después:

TOTAL: Bs 140 PAGADO: Bs 140 RESTANTE: Bs 0

Pagos: ✓ Efectivo Bs 100 ✓ QR Bs 40

\[ Finalizar cuenta \]

## Importante

No cambiar el modelo financiero ya implementado: - Un pago mixto
continúa siendo varios Payment. - No crear un método MIXED artificial. -
QR no aumenta el efectivo físico esperado. - El vuelto continúa siendo
informativo y no un gasto. - Mantener idempotencia, transacciones y
bloqueos existentes.

------------------------------------------------------------------------

# 14. VUELTO EN EFECTIVO

## Se requiere

Si el saldo es Bs 40 y el cliente entrega Bs 50, mostrar antes de
registrar:

Monto a pagar: Bs 40 Recibido: Bs 50 Vuelto: Bs 10

El vuelto debe ser visible y fácil de entender antes de confirmar el
pago.

------------------------------------------------------------------------

# 15. ERRORES Y MENSAJES EN INGLÉS

## Problema

Se encontraron mensajes técnicos visibles como: - The variants.0.name
field has a duplicate value. - The variants.0.size_key field format is
invalid. - The sections.0.variant field has a duplicate value. - All
active order items must be served before closing.

## Se requiere

Ningún mensaje de validación operativo debe exponer: - nombres internos
de arrays; - índices; - nombres de columnas/campos técnicos; - mensajes
en inglés.

Ejemplos esperados: - "El tamaño Pequeña ya está agregado." -
"Selecciona sabores diferentes para cada sección." - "Aún hay productos
pendientes de servir." - "El tamaño seleccionado no está disponible para
este sabor."

------------------------------------------------------------------------

# 16. CRITERIO GENERAL DE UX

El sistema será utilizado por propietarios, administradores, cajeros,
meseros y personal de cocina.

La interfaz debe hablar en términos de pizzería, no en términos de
programación.

Evitar en UI operativa términos como: - variant - size_key -
sections.0 - numerator - denominator - fulfillment - inventory_item_id

Los conceptos técnicos pueden permanecer internamente en dominio/base de
datos.

------------------------------------------------------------------------

# ORDEN RECOMENDADO DE CORRECCIÓN

1.  Corregir errores 500/Blade de Inventario.
2.  Revisar Usuarios y creación/asignación de roles.
3.  Corregir creación/configuración de Mesas.
4.  Corregir creación/disponibilidad de Caja y apertura de turno.
5.  Corregir creación de Productos y normalización automática de
    size_key.
6.  Rediseñar flujo Producto -\> Tamaños -\> Recetas.
7.  Corregir creación de nuevas recetas.
8.  Corregir compatibilidad de sabores por tamaño.
9.  Simplificar constructor de pizzas y eliminar fracciones técnicas de
    UI.
10. Simplificar edición del pedido actual.
11. Rediseñar pago parcial/mixto como flujo secuencial.
12. Traducir y humanizar todas las validaciones.
13. Hacer regresión completa del flujo real.

------------------------------------------------------------------------

# PRUEBA FUNCIONAL FINAL OBLIGATORIA

Probar manual y automáticamente, como mínimo, este escenario completo:

1.  Owner inicia sesión.
2.  Crea un usuario cajero y asigna su rol.
3.  Crea/configura mesas.
4.  Existe/configura una Caja Principal.
5.  Abre turno con monto inicial.
6.  Crea ingredientes.
7.  Registra inventario.
8.  Crea Pizza Pepperoni.
9.  Crea tamaños Personal, Mediana y Familiar.
10. Configura receta para cada tamaño.
11. Crea Pizza Hawaiana con los mismos tamaños.
12. Configura sus recetas.
13. Abre Mesa 1.
14. Agrega una pizza Familiar de 2 sabores: Pepperoni + Hawaiana.
15. Envía a cocina.
16. Cocina la marca en preparación y luego servida.
17. Abre cobro.
18. Cuenta ejemplo: Bs 140.
19. Registra Bs 100 en efectivo.
20. Sistema muestra automáticamente saldo Bs 40.
21. Registra Bs 40 por QR.
22. Saldo queda Bs 0.
23. Finaliza cuenta.
24. Mesa vuelve a Libre.
25. Inventario se descuenta una sola vez.
26. Caja refleja solamente Bs 100 de efectivo físico.
27. QR refleja Bs 40 sin aumentar efectivo esperado.
28. Reportes muestran correctamente la venta.
29. Cierra turno y verifica esperado/contado/diferencia.

También probar: - pedido para llevar; - pago 100% efectivo con vuelto; -
pago 100% QR; - pago parcial; - intento de cobro con productos
pendientes de servir; - usuario sin permiso intentando administrar
caja/usuarios; - tamaños con tildes y ñ; - varios sabores compatibles; -
receta inexistente; - inventario insuficiente.

------------------------------------------------------------------------

# VALIDACIÓN TÉCNICA FINAL

Al terminar:

-   php artisan migrate:status
-   No debe haber migraciones históricas modificadas.
-   No usar migrate:fresh.
-   Ejecutar pruebas específicas de cada corrección.
-   Ejecutar php artisan test completo.
-   Ejecutar vendor/bin/pint --test.
-   Ejecutar npm.cmd run build.
-   Verificar compilación de Blade.
-   Revisar rutas y Policies.
-   Mantener cálculos monetarios/quantities sin float binario.
-   No hacer deploy ni push salvo instrucción expresa.
-   Actualizar AGENTS.md, .codex/project.md y agents/ únicamente con el
    estado realmente implementado.

Entregar al final un resumen de: 1. bugs corregidos; 2. archivos
modificados; 3. migraciones creadas, si fueron realmente necesarias; 4.
pruebas añadidas; 5. resultado de suite completa; 6. resultado de build;
7. cualquier punto que siga pendiente.
