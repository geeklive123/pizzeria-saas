# Correcciones de UX y lógica - Venta, Pizzas y Caja

## Objetivo

Revisar y corregir tres problemas del flujo operativo del sistema de pizzería:

1. Filtrado correcto de sabores según el tamaño de pizza.
2. Mejorar el flujo visual entre catálogo/filtros y "Armar pizza".
3. Implementar una experiencia clara y completa para pagos simples y mixtos en Caja.

No hacer cambios destructivos. No usar `migrate:fresh`. No borrar pedidos, pagos, inventario, recetas ni catálogo existente. No hacer push ni deploy.

---

## 1. Constructor de pizza - filtrar sabores estrictamente por tamaño

### Problema actual

Al seleccionar un tamaño, por ejemplo `Personal`, el selector de sabores muestra inicialmente pizzas compatibles, pero también aparecen variantes correspondientes a otros tamaños.

Esto hace que el desplegable sea demasiado largo y confuso.

### Comportamiento esperado

Cada selector de sabor debe contener EXCLUSIVAMENTE variantes compatibles con el tamaño seleccionado:

- Personal -> únicamente variantes Personal.
- Mediana -> únicamente variantes Mediana.
- Familiar -> únicamente variantes Familiar.

No resolverlo simplemente ocultando opciones mediante CSS.

La fuente de datos entregada/renderizada al selector debe estar correctamente filtrada.

### Cambio de tamaño

Si el usuario cambia el tamaño después de haber seleccionado uno o varios sabores:

- Limpiar cualquier selección incompatible.
- Recargar únicamente sabores del nuevo tamaño.
- No conservar silenciosamente variantes pertenecientes al tamaño anterior.
- Recalcular correctamente el precio estimado.

### Multisabor

Verificar que continúe funcionando:

- 1 sabor.
- 2 sabores.
- 3 sabores.
- 4 sabores.

Todos los sabores seleccionados deben corresponder al mismo tamaño.

Mantener las reglas existentes de composición, precio multisabor y recetas pendientes, salvo que exista un bug demostrado que impida este comportamiento.

---

## 2. Mejorar flujo de catálogo y "Armar pizza"

### Problema actual

Los filtros superiores de categorías funcionan, pero el bloque grande `Armar pizza` permanece visible de forma permanente.

Esto provoca que:

- El usuario filtre productos pero los resultados aparezcan más abajo.
- Se genere scroll innecesario.
- Parezca que el filtro no afecta realmente la pantalla.
- El constructor ocupe espacio incluso cuando el usuario busca bebidas u otros productos.

### Flujo esperado

Al entrar a `Venta`, priorizar:

1. Buscador.
2. Categorías/filtros.
3. Productos disponibles.

No mostrar permanentemente un constructor enorme antes de los resultados del catálogo.

### Para pizzas

Cuando el usuario elija una pizza o inicie la venta de una pizza:

- Mostrar/habilitar el bloque `Armar pizza`.
- Permitir seleccionar tamaño.
- Permitir seleccionar 1, 2, 3 o 4 sabores.
- Mostrar únicamente sabores compatibles con ese tamaño.
- Mantener observación, entrega y demás personalizaciones existentes.

El constructor debe aparecer como parte natural de la acción de vender una pizza, no como un bloque permanente que desplace el catálogo.

### Para bebidas y venta directa

Las bebidas y productos de venta directa deben poder agregarse directamente sin obligar al usuario a pasar por el constructor de pizza.

### UX

Mantener:

- Búsqueda.
- Categorías.
- Filtros.
- Pedido actual.
- Edición de productos ya agregados.
- Flujo de cocina.

Reducir scroll y movimientos innecesarios.

Debe continuar siendo usable en escritorio, tablet y móvil.

No cambiar las reglas actuales de recetas pendientes ni crear consumos ficticios.

---

## 3. Rediseñar Caja para pagos simples y mixtos

### Problema actual

La pantalla actual muestra:

> "Registra un método a la vez. Cada pago reduce el saldo restante."

Aunque internamente pueda existir soporte para pagos parciales, esta explicación resulta confusa para la cajera y no comunica claramente que una cuenta puede pagarse combinando métodos.

Necesitamos un flujo similar al sistema de florería: una misma cuenta puede tener varios pagos hasta completar el total.

### Métodos requeridos

Debe soportar claramente:

- 100% efectivo.
- 100% QR.
- Efectivo + QR.
- QR + efectivo.
- Varios pagos parciales sucesivos.

Cada pago debe mantenerse como un registro individual.

---

## Ejemplo obligatorio 1 - pago mixto

Total:

`Bs 100`

Cliente paga:

- Efectivo: Bs 30
- QR: Bs 70

Resultado:

- Total: Bs 100
- Pagado: Bs 100
- Saldo: Bs 0

La cuenta queda completamente pagada.

---

## Ejemplo obligatorio 2 - varios pagos

Total:

`Bs 100`

Primer pago:

`QR Bs 20`

Resultado:

- Pagado: Bs 20
- Saldo: Bs 80

Segundo pago:

`Efectivo Bs 50`

Resultado:

- Pagado: Bs 70
- Saldo: Bs 30

Tercer pago:

`QR Bs 30`

Resultado:

- Pagado: Bs 100
- Saldo: Bs 0

---

## Interfaz esperada de Caja

La pantalla debe mostrar claramente:

- Total.
- Pagado.
- Saldo restante.

Debajo debe existir una sección entendible para registrar pagos.

Evitar textos que hagan pensar que solo se permite elegir un único método para toda la venta.

El usuario debe entender visualmente que puede registrar varios pagos.

Después de registrar un pago parcial:

1. Guardar el pago.
2. Actualizar `Pagado`.
3. Actualizar `Saldo`.
4. Mantener la cuenta abierta si todavía existe saldo.
5. Sugerir el saldo restante como monto del siguiente pago.
6. Permitir seleccionar nuevamente Efectivo o QR.

---

## Pago en efectivo y cambio

Diferenciar claramente:

- Monto aplicado a la cuenta.
- Efectivo recibido.
- Cambio.

Ejemplo:

Saldo:

`Bs 70`

Cliente entrega:

`Bs 100`

Resultado:

- Monto aplicado: Bs 70
- Efectivo recibido: Bs 100
- Cambio: Bs 30
- Nuevo saldo: Bs 0

El cambio:

- NO debe registrarse como gasto.
- NO debe registrarse como otro pago.
- NO debe incrementar artificialmente la venta.
- Debe ser únicamente información operativa del cobro.

---

## Validaciones de Caja

No permitir:

- Un pago QR superior al saldo restante.
- Cerrar una cuenta mientras exista saldo pendiente.
- Registrar montos negativos o cero.
- Duplicar accidentalmente un pago por doble envío.
- Consumir inventario nuevamente al registrar un pago.

El efectivo recibido sí puede superar el monto aplicado porque puede existir cambio.

Una vez que:

`Saldo = 0`

la cuenta podrá considerarse completamente pagada y continuar con el cierre correspondiente según las reglas actuales del sistema.

Mantener la liberación de mesa únicamente cuando corresponda según el flujo existente de pedido/cocina/pago.

---

## Auditoría de pagos

Cada pago debe conservarse individualmente.

Ejemplo:

| Método | Monto |
|---|---:|
| Efectivo | Bs 30 |
| QR | Bs 70 |
| **Total pagado** | **Bs 100** |

Esto debe quedar disponible para:

- Caja.
- Cierre de turno.
- Reportes.
- Auditoría.
- Historial del pedido.

No convertir un pago mixto en un único registro genérico.

---

## Antes de modificar

Inspeccionar la implementación actual de:

- Venta.
- Constructor/composición de pizzas.
- Compatibilidad por tamaño.
- Disponibilidad.
- Orders.
- OrderItems.
- Payments.
- Caja/CashSession.
- Flujo de cierre de cuenta.
- JavaScript del constructor.
- Validaciones.
- Pruebas existentes.

Reutilizar la arquitectura actual.

No duplicar lógica si ya existe un servicio/action responsable.

Corregir la causa raíz de los problemas.

---

## Pruebas obligatorias

Al terminar, verificar como mínimo:

### Pizzas

1. Personal muestra únicamente sabores Personal.
2. Mediana muestra únicamente sabores Mediana.
3. Familiar muestra únicamente sabores Familiar.
4. No aparecen variantes de otros tamaños dentro del selector.
5. Funciona pizza de 1 sabor.
6. Funciona pizza de 2 sabores.
7. Funciona pizza de 3 sabores.
8. Funciona pizza de 4 sabores.
9. Cambiar el tamaño limpia selecciones incompatibles.
10. El precio se recalcula correctamente.

### Catálogo / Venta

11. Los filtros continúan funcionando.
12. El catálogo tiene prioridad visual al entrar.
13. `Armar pizza` no desplaza innecesariamente los resultados.
14. El constructor aparece cuando corresponde vender/configurar una pizza.
15. Bebidas y venta directa pueden agregarse sin usar el constructor.
16. El pedido actual continúa funcionando.

### Caja

17. Pago 100% efectivo funciona.
18. Pago 100% QR funciona.
19. Pago Bs 30 efectivo + Bs 70 QR sobre Bs 100 funciona.
20. Pago Bs 20 QR + Bs 50 efectivo + Bs 30 QR funciona.
21. Cada pago reduce inmediatamente el saldo.
22. El siguiente pago propone el saldo restante.
23. El cambio de efectivo se calcula correctamente.
24. QR no permite superar el saldo.
25. No se puede cerrar con saldo pendiente.
26. Al llegar a saldo cero se reconoce la cuenta como pagada.
27. Cada pago queda registrado individualmente.
28. No existe doble consumo de inventario al cobrar.
29. No se rompe el seguimiento de cocina.
30. No se libera una mesa incorrectamente.

---

## Validación técnica final

Ejecutar:

- Suite completa de pruebas.
- Pruebas específicas nuevas para estos casos.
- Pint en modo verificación.
- Build de Vite.

Reportar al finalizar:

- Archivos modificados.
- Causa raíz encontrada para cada problema.
- Solución aplicada.
- Pruebas nuevas agregadas.
- Resultado total de PHPUnit.
- Resultado de Pint.
- Resultado de Vite.
- Confirmación explícita de que no se utilizó `migrate:fresh`.
- Confirmación de que no se hizo deploy.
- Confirmación de que no se hizo push.

## Restricciones

- NO usar `migrate:fresh`.
- NO borrar catálogo real.
- NO borrar pedidos.
- NO borrar pagos.
- NO borrar inventario.
- NO borrar historial.
- NO crear stock ficticio.
- NO crear consumos ficticios.
- NO hacer deploy.
- NO hacer push.
- Evitar cambios fuera del alcance de estos tres problemas.
