# Ajustes operativos del restaurante — Pizzas y Caja por turnos

## Objetivo
Implementar el feedback validado directamente con las meseras del restaurante. Antes de modificar, inspeccionar la implementación actual y reutilizar Actions/Services, Policies, modelos y pruebas existentes. No hacer cambios destructivos.

# A. Venta de pizzas

## 1. Venta normal rápida
Al seleccionar una pizza y un tamaño, asumir inicialmente una pizza completa de un solo sabor. No desplegar automáticamente el constructor multisabor.

Mostrar: pizza seleccionada, tamaño, precio, Entrega, Observación y `Agregar pizza`.

## 2. Regla real de combinación
- Personal: NO permite combinación de sabores.
- Mediana: permite 1, 2, 3 o 4 sabores.
- Familiar: permite 1, 2, 3 o 4 sabores.

Validar también en backend; no depender solo de JavaScript/CSS.

## 3. Combinar sabores como opción
En Mediana y Familiar mostrar `+ Combinar sabores`, cerrado inicialmente. Si no se pulsa, agregar la pizza completa normalmente.

Al pulsarlo, desplegar opciones de 2, 3 y 4 sabores y permitir cerrar/cancelar la combinación.

En Personal no debe aparecer este botón.

## 4. Filtrado estricto por tamaño
Los selectores de sabores deben recibir EXCLUSIVAMENTE variantes compatibles con el tamaño elegido.

Mediana -> solo Mediana.
Familiar -> solo Familiar.
Personal -> sin multisabor.

No ocultar variantes incorrectas solo con CSS. Corregir la colección/fuente de datos.

Al cambiar tamaño, limpiar selecciones incompatibles, recalcular precio y reiniciar la combinación cuando corresponda.

## 5. Catálogo
Mantener búsqueda y categorías como elementos principales. No colocar un constructor grande permanentemente antes de los resultados.

Bebidas y artículos de venta directa deben agregarse directamente sin pasar por el constructor de pizzas.

# B. Caja por cajera y turno

## 6. Concepto
Caja debe trabajar por sucursal, caja física, usuario/cajera y turno.

Cada turno debe guardar:
- usuario que abre;
- fecha/hora de apertura;
- fondo inicial;
- ventas efectivo;
- ventas QR;
- ingresos/salidas manuales;
- retiros;
- efectivo esperado;
- efectivo contado;
- diferencia;
- usuario que cierra;
- fecha/hora de cierre.

## 7. Una sola sesión abierta por caja
Una caja física NO puede tener dos turnos abiertos simultáneamente.

Si María tiene Caja Principal abierta y Daniela intenta abrirla, bloquear desde backend y mostrar quién tiene el turno y desde qué hora.

Mantener protección transaccional/idempotencia para evitar dos aperturas simultáneas.

## 8. Primer turno
Ejemplo: primera cajera abre con Bs 1.000 de efectivo.

Ese monto es fondo operativo, NO una venta.

La apertura debe registrar efectivo inicial/esperado y efectivo contado por la cajera.

## 9. Separar efectivo y QR
Ejemplo:
- Fondo inicial: Bs 1.000 efectivo.
- Ventas efectivo: Bs 500.
- Ventas QR: Bs 500.

Resultado:
- Efectivo físico esperado: Bs 1.500.
- QR del turno: Bs 500.
- Ventas totales: Bs 1.000.

QR nunca debe aumentar el efectivo físico esperado.

# C. Retiro de efectivo

## 10. Retiro del propietario
Agregar una operación específica `Retirar efectivo`.

Ejemplo: hay Bs 1.500 esperados y el dueño retira Bs 500. Nuevo efectivo esperado: Bs 1.000.

Guardar:
- monto;
- motivo;
- observación;
- usuario que registra;
- usuario que autoriza cuando corresponda;
- fecha/hora;
- caja;
- turno.

## 11. No es un gasto
Un retiro del propietario NO debe crear un Expense ni afectar el cálculo de gastos del negocio.

Debe ser un movimiento específico de caja, por ejemplo `owner_withdrawal` o equivalente compatible con la arquitectura actual.

Reportes deben distinguir ventas, gastos, compras, retiros del propietario, otros movimientos y diferencias.

## 12. Permisos
No permitir a cualquier cajera registrar libremente un retiro del propietario.

Owner/Admin debe poder registrarlo/autorizarlo. Reutilizar Policies/Permissions y cualquier mecanismo de autorización existente. La seguridad debe estar en backend.

## 13. Historial
Todos los movimientos deben ser auditables con hora, tipo, usuario, entrada/salida y saldo esperado.

# D. Cierre de turno

## 14. Ejemplo obligatorio
Turno:
- Fondo inicial: Bs 1.000
- Ventas efectivo: Bs 500
- Ventas QR: Bs 500
- Retiro propietario: Bs 500

Cierre:
- Ventas totales: Bs 1.000
- QR turno: Bs 500
- Efectivo esperado: Bs 1.000

Mostrar claramente fondo inicial, ventas efectivo, QR, retiros, efectivo esperado, efectivo contado y diferencia.

## 15. Diferencia
`diferencia = efectivo contado - efectivo esperado`

Guardar faltantes y sobrantes sin alterar ventas ni movimientos históricos para hacerlos cuadrar artificialmente.

# E. Relevo entre cajeras

## 16. Efectivo heredado
Cuando un turno cierre, el efectivo físico que queda sirve como referencia para el siguiente turno.

Ejemplo:
Turno A cierra con Bs 1.500 efectivo y Bs 500 QR.
Turno B recibe referencia de Bs 1.500 efectivo y QR inicial Bs 0.

El QR NO se hereda.

## 17. Confirmación de la nueva cajera
No abrir automáticamente usando el monto anterior.

La nueva cajera debe contar y confirmar el efectivo recibido.

Mostrar:
- efectivo esperado/heredado;
- efectivo contado;
- diferencia;
- QR inicial = Bs 0.

Si esperaba Bs 1.000 y cuenta Bs 980, registrar diferencia -Bs 20 en la apertura del nuevo turno sin modificar silenciosamente el cierre anterior.

# F. Historial para Owner/Admin

Debe poder consultar por turno:
- cajera;
- caja;
- sucursal;
- apertura/cierre;
- fondo inicial/heredado;
- ventas efectivo;
- ventas QR;
- ventas totales;
- retiros;
- otros movimientos;
- efectivo esperado;
- efectivo contado;
- diferencia;
- estado.

# G. Mantener pagos mixtos

Estos cambios NO deben romper pagos parciales/mixtos.

Debe funcionar:
- 100% efectivo;
- 100% QR;
- efectivo + QR;
- QR + efectivo;
- varios pagos parciales.

Ejemplo obligatorio: total Bs 100 -> Bs 30 efectivo + Bs 70 QR -> saldo Bs 0.

Solo Bs 30 incrementan efectivo físico y Bs 70 incrementan QR del turno.

Cada Payment debe permanecer individual para auditoría. Cobrar no debe consumir inventario nuevamente.

# H. Permisos

## Cajera
Puede abrir una caja disponible, cobrar, registrar pagos mixtos/parciales, consultar su turno y cerrarlo según permisos.

No puede abrir la misma caja si ya existe turno abierto, alterar historial cerrado, modificar ventas para cuadrar caja ni hacer retiros privilegiados sin autorización.

## Owner/Admin
Puede revisar todos los turnos/movimientos/diferencias y registrar o autorizar retiros.

Mantener aislamiento por `company_id`.

# I. Integridad y concurrencia

Mantener/implementar:
- una sola sesión abierta por caja/sucursal;
- transacciones;
- `lockForUpdate()` donde corresponda;
- idempotencia contra doble click/doble petición;
- turnos cerrados inmutables;
- QR separado del efectivo;
- movimientos históricos no destructivos.

# J. Pruebas obligatorias

## Pizzas
1. Personal no muestra ni acepta combinación.
2. Mediana funciona con 1, 2, 3 y 4 sabores.
3. Familiar funciona con 1, 2, 3 y 4 sabores.
4. Cada selector muestra solo variantes del tamaño correcto.
5. Cambiar tamaño limpia selecciones incompatibles.
6. Pizza normal se agrega sin abrir constructor.
7. Bebidas siguen siendo venta directa.

## Caja
8. Primer turno abre con Bs 1.000.
9. Segunda cajera no puede abrir la misma caja mientras esté ocupada.
10. Venta efectivo incrementa efectivo esperado.
11. Venta QR no incrementa efectivo físico.
12. QR aparece en resumen del turno.
13. Retiro reduce efectivo esperado.
14. Retiro propietario no crea Expense.
15. Cajera sin permiso no puede hacer retiro privilegiado.
16. Owner/Admin sí puede autorizarlo.
17. Cierre calcula efectivo esperado, contado y diferencia.
18. Turno cerrado no acepta movimientos.

## Relevo
19. Turno B recibe como referencia el efectivo dejado por A.
20. QR de A no se hereda.
21. QR de B comienza en cero.
22. Nueva cajera confirma el efectivo contado.
23. Diferencia de relevo queda registrada sin alterar el cierre anterior.

## Pagos mixtos
24. 100% efectivo.
25. 100% QR.
26. Bs 30 efectivo + Bs 70 QR sobre Bs 100.
27. Bs 20 QR + Bs 50 efectivo + Bs 30 QR.
28. Payments quedan separados.
29. Solo efectivo afecta efectivo físico.
30. Solo QR afecta QR del turno.
31. No hay doble consumo de inventario al cobrar.

# Antes de modificar

Inspeccionar:
- CashRegister/CashSession o equivalentes;
- CashMovement;
- Payment;
- Order;
- apertura/cierre actual;
- Actions/Services;
- Policies/Permissions;
- reportes de Caja;
- Venta;
- PizzaCompositionService;
- JavaScript relacionado;
- pruebas existentes.

No introducir lógica crítica de dominio únicamente en Blade/JavaScript.

# Validación final

Ejecutar:
- suite completa PHPUnit;
- pruebas nuevas específicas;
- Pint en modo verificación;
- build de Vite.

Reportar:
1. causa raíz de cada problema;
2. archivos modificados;
3. migraciones nuevas si fueron necesarias;
4. Actions/Services modificados;
5. permisos modificados;
6. pruebas agregadas;
7. resultado PHPUnit;
8. resultado Pint;
9. resultado Vite;
10. confirmación de pagos mixtos;
11. confirmación de separación QR/efectivo;
12. confirmación de que retiros no son gastos;
13. confirmación de que no se borró historial.

# Restricciones

- NO usar `migrate:fresh`.
- NO borrar catálogo real.
- NO borrar pedidos.
- NO borrar pagos.
- NO borrar movimientos de caja.
- NO borrar inventario.
- NO borrar historial.
- NO crear ventas/stock ficticios.
- NO modificar históricos para cuadrar caja.
- NO hacer deploy.
- NO hacer push.
- Mantener multiempresa y formato monetario boliviano.
- Evitar cambios fuera de alcance salvo causa raíz demostrada.
