# Verificación manual de la fecha de un consumo interno

Esta comprobación debe realizarse manualmente en el entorno local, sin editar registros existentes.

1. Anota la hora local de Bolivia (`America/La_Paz`) inmediatamente antes de comenzar.
2. En **Consumos internos**, registra un segundo consumo pequeño con un ingrediente que tenga stock suficiente.
3. Abre el detalle recién creado y comprueba que la fecha y hora mostradas coincidan con la hora anotada, admitiendo solo la demora normal del proceso.
4. Abre **Inventario > Movimientos** y localiza los movimientos cuyo motivo contiene el código `CI-...` del consumo.
5. Comprueba que todos esos movimientos muestren la misma fecha y hora que el consumo.
6. Comprueba en **Reportes > Resumen**, **Inventario** y **Rentabilidad** que el consumo aparezca dentro del mismo periodo seleccionado y no incremente ventas, pagos, gastos ni caja.
7. Si se desea contrastar en MySQL, realiza únicamente consultas `SELECT` sobre `internal_consumptions`, `internal_consumption_components` e `inventory_movements`. No actualices el consumo ID 1 ni ningún otro registro histórico.

La interfaz convierte los instantes UTC a `America/La_Paz`. Por ello, el valor visible debe compararse con la hora local, no con la representación UTC almacenada.
