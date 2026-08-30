# Bloque 5 — Toppings en venta, precio e inventario

## Dependencia
Solo después del Bloque 4 validado.

## UX
Debajo del sabor/combinación mostrar selección múltiple:
- + Tocino · Bs 5
- + Champiñones · Bs 4
- + Aceitunas · Bs 3

Permitir seleccionar/desmarcar y mostrar Precio pizza + Extras + Total estimado. El servidor es autoridad del total.

## Históricos
Guardar snapshots de nombre, precio aplicado y datos necesarios de consumo para que cambios futuros no alteren órdenes antiguas.

## Inventario
Si el topping consume stock, integrarlo a los servicios centrales existentes respetando FEFO, vencidos, locks, stock negativo off, idempotencia y momento actual de reserva/consumo. Nunca consumir dos veces.

## Cancelación
Usar la política existente de reversión/compensación.

## Fusión
En primera versión el topping aplica una vez a toda la pizza; no implementar toppings por mitad/cuarto.

## Pruebas
Uno/varios toppings, quitar, total, manipulación de precio, disponibilidad, reserva/consumo, reversión, fusión e idempotencia.
