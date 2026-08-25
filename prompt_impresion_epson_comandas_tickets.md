# Implementación de impresión — Epson térmica 80 mm

## Objetivo
Integrar impresión de comandas de cocina y tickets de cliente/caja en el sistema de pizzería, usando la impresora instalada en Windows.

Datos confirmados:
- Epson térmica de la familia TM-T20.
- Windows la reconoce como `EPSON TM-T20IIIL Receipt`.
- Puerto actual: `TMUSB001`.
- Conexión: USB.
- Papel térmico: 80 mm.
- Driver Epson instalado.

IMPORTANTE: no hardcodear permanentemente el nombre de la impresora ni el puerto. Debe ser configurable.

---

## 1. Principio general
Flujo:

Pedido -> agregar productos -> enviar novedades a cocina -> KitchenDispatch -> imprimir comanda -> cocina -> cobro -> ticket cliente/caja.

Una falla de impresión NO debe:
- cancelar pedido;
- revertir KitchenDispatch;
- duplicar reservas o consumos;
- duplicar pagos;
- crear otro pedido.

---

## 2. Reutilizar KitchenDispatch
Inspeccionar la arquitectura existente:
- KitchenDispatch;
- Order / OrderItem;
- InventoryReservation;
- Actions de envío a cocina;
- estados/tandas.

NO crear una segunda fuente de verdad de comandas si KitchenDispatch ya representa la tanda enviada.

La impresión de cocina debe usar exactamente el KitchenDispatch creado.

---

## 3. Imprimir solo novedades
Al pulsar `Enviar nuevos a cocina`, imprimir solo las líneas nuevas de esa tanda.

Ejemplo:
Primera tanda:
- 1 Pizza Mediana.

Luego agregan:
- 1 Coca-Cola;
- 1 Pizza Familiar.

La segunda comanda debe imprimir SOLO Coca-Cola + Pizza Familiar.

---

## 4. Contenido de comanda de cocina
Optimizar para 80 mm. Sin precios.

Mostrar:
- MASA & MAÑA;
- MESA X o PARA LLEVAR grande;
- N.º pedido;
- fecha/hora;
- mesera/cajera/usuario;
- cliente/teléfono cuando corresponda;
- cantidad;
- producto;
- tamaño;
- sabores;
- observaciones;
- entrega;
- indicador de nueva tanda.

Ejemplo:

```text
          MASA & MAÑA

            MESA 2
         PEDIDO #000010

24/08/2026                 20:15
Mesera: Daniela

--------------------------------

1 x PIZZA FAMILIAR
    1/2 CACHETONA FINA
    1/2 CHAQUEÑA

    COMER AQUÍ

    OBS:
    BIEN COCIDA
    SIN CEBOLLA

--------------------------------
1 x COCA-COLA 500 ML
--------------------------------

         NUEVA COMANDA
```

Para llevar:

```text
          MASA & MAÑA

         PARA LLEVAR
         PEDIDO #000011

24/08/2026                 20:18
Cliente: Javier
Tel: 74818256

--------------------------------
1 x PIZZA MEDIANA
    LA CHURA
    PARA LLEVAR
--------------------------------

         NUEVA COMANDA
```

---

## 5. Pizzas multisabor
Mostrar composición en términos operativos.

2 sabores:
- 1/2 sabor A
- 1/2 sabor B

3 sabores:
- tres partes legibles.

4 sabores:
- cuatro cuartos.

NO mostrar:
- numerator;
- denominator;
- variant_id;
- section index;
- size_key.

---

## 6. Extras, removidos y observaciones
Imprimir de forma clara:

```text
EXTRAS:
+ QUESO

QUITAR:
- CEBOLLA

OBS:
BIEN COCIDA
```

---

## 7. Impresión automática
Agregar configuración:

`Imprimir automáticamente al enviar a cocina`

Flujo seguro:
1. crear/confirmar KitchenDispatch;
2. confirmar transacción de base de datos;
3. intentar imprimir;
4. registrar éxito/error.

NO mantener una transacción abierta mientras Windows/spooler imprime.

---

## 8. Reimpresión
Agregar:
- `Reimprimir última comanda`
- `Reintentar impresión` cuando falle.

La reimpresión:
- usa un KitchenDispatch existente;
- NO crea otro KitchenDispatch;
- NO reserva inventario;
- NO descuenta ingredientes;
- NO cambia estados;
- NO vuelve a enviar a cocina.

Registrar quién/cuándo reimprimió si existe auditoría adecuada.

---

## 9. Fallo de impresora
Si está apagada, desconectada o Windows/spooler falla:

- el pedido sigue guardado;
- KitchenDispatch sigue válido;
- mostrar:
  `El pedido fue enviado a cocina, pero no se pudo imprimir la comanda.`
- ofrecer `Reintentar impresión`;
- registrar el error en logs.

Nunca mostrar excepción técnica cruda al usuario.

---

## 10. Configuración de impresoras
Agregar en Configuración una sección `Impresoras`.

### Cocina
- nombre de impresora Windows;
- activa/inactiva;
- impresión automática al enviar;
- copias;
- ancho 80 mm.

### Ticket cliente/caja
- nombre de impresora Windows;
- activa/inactiva;
- copias;
- ancho 80 mm.

Inicialmente ambas pueden usar:
`EPSON TM-T20IIIL Receipt`

pero debe poder cambiarse después.

No depender permanentemente de `TMUSB001`.

---

## 11. Imprimir prueba
Agregar botón `Imprimir prueba`.

Ejemplo:

```text
MASA & MAÑA
PRUEBA DE IMPRESIÓN

Impresora:
EPSON TM-T20IIIL Receipt

Fecha/Hora:
[actual]

IMPRESIÓN CORRECTA
```

---

## 12. Estrategia técnica
Antes de instalar dependencias:
- revisar stack actual;
- elegir una integración mantenible compatible con Laravel + Windows + Epson térmica + 80 mm.

Puede usar:
- spooler de Windows;
- RAW/ESC-POS;
- librería ESC/POS mantenida.

Justificar la elección.

Si se usa ESC/POS, verificar:
- corte automático;
- negrita;
- alineación;
- tamaño de fuente;
- avance de papel.

---

## 13. Corte automático
Después de cada comanda/ticket:
- alimentar papel;
- cortar automáticamente si driver/impresora lo soportan.

---

## 14. Ticket de cliente/caja
Debe ser diferente a la comanda de cocina y SÍ incluir precios.

Mostrar:
- MASA & MAÑA;
- N.º pedido;
- mesa o para llevar;
- fecha/hora;
- cajera;
- cliente si existe;
- productos;
- cantidad;
- precios;
- subtotal;
- descuento;
- total;
- pagos;
- efectivo;
- QR;
- recibido;
- cambio;
- saldo cuando corresponda.

Ejemplo efectivo:

```text
          MASA & MAÑA

         PEDIDO #000015
            MESA 1

24/08/2026                 21:10
Cajera: María

--------------------------------
1 CACHETONA FAMILIAR    Bs 87,00
1 COCA-COLA 500 ML      Bs 10,00
--------------------------------

SUBTOTAL                  Bs 97,00
DESCUENTO                  Bs 0,00
TOTAL                     Bs 97,00

EFECTIVO                  Bs 97,00
RECIBIDO                 Bs 100,00
CAMBIO                     Bs 3,00

       Gracias por su preferencia
```

Pago mixto:

```text
TOTAL                    Bs 100,00

PAGOS
Efectivo                  Bs 30,00
QR                        Bs 70,00

PAGADO                   Bs 100,00
SALDO                      Bs 0,00
```

Mantener cada Payment individual.

---

## 15. Ticket parcial
Si se imprime antes de completar el pago:
- mostrar total;
- pagado;
- saldo pendiente;
- no presentarlo como pagado por completo.

---

## 16. Reimprimir ticket
Agregar `Reimprimir ticket`.

No debe:
- crear Payment;
- crear CashMovement;
- cambiar saldo;
- tocar inventario;
- alterar cierre.

---

## 17. Fuente de datos financiera
El ticket debe leer Payments/CashSession y snapshots existentes.
No recalcular pagos de forma paralela en UI.

---

## 18. Permisos
Reutilizar roles actuales.

Sugerencia:
- waiter: enviar/reimprimir comanda;
- cashier: imprimir/reimprimir ticket cliente;
- owner/admin: configurar impresoras e imprimir prueba.

No crear roles nuevos.

---

## 19. Persistencia
Antes de crear tablas:
- revisar Settings/Configuration existente;
- revisar metadatos disponibles en KitchenDispatch.

Reutilizar arquitectura.
Si hace falta persistencia nueva, usar migración incremental.
NO modificar migraciones históricas.

---

## 20. UI del pedido
Mantener:
- `Enviar nuevos a cocina`

Si impresión automática está activa:
- envía + intenta imprimir.

Agregar secundariamente:
- `Reimprimir última comanda`
- `Reintentar impresión` si falló.

No llenar la cabecera de botones innecesarios.

---

## 21. UI de caja
Agregar:
- `Imprimir ticket`
- `Reimprimir ticket`

No imprimir automáticamente salvo configuración explícita.

---

## 22. Pruebas obligatorias — cocina
1. Primera tanda crea KitchenDispatch una sola vez.
2. Impresión usa solo líneas de esa tanda.
3. Segunda tanda imprime solo novedades.
4. Reimpresión no crea otro dispatch.
5. Reimpresión no reserva inventario.
6. Reimpresión no descuenta inventario.
7. Reimpresión no cambia estados de cocina.
8. Mesa se imprime correctamente.
9. Para llevar se imprime correctamente.
10. Pizza 1 sabor correcta.
11. Pizza 2 sabores correcta.
12. Pizza 3 sabores correcta.
13. Pizza 4 sabores correcta.
14. Observaciones correctas.
15. Extras/removidos correctos.
16. Cocina no imprime precios.
17. Error de impresora no revierte pedido.
18. Error muestra mensaje amigable.
19. Reintento no duplica dominio.

---

## 23. Pruebas obligatorias — ticket cliente
20. 100% efectivo.
21. 100% QR.
22. Pago mixto.
23. Pago parcial muestra saldo.
24. Cambio correcto.
25. Reimpresión no crea Payment.
26. Reimpresión no crea CashMovement.
27. Reimpresión no cambia saldo.
28. Productos/precios coinciden con snapshots.
29. Totales/descuento correctos.

---

## 24. Pruebas de configuración
30. Owner/Admin puede configurar impresora.
31. Usuario sin permiso no puede.
32. Nombre de impresora configurable.
33. Misma impresora puede usarse para cocina y cliente.
34. Se pueden configurar impresoras diferentes.
35. `Imprimir prueba` usa la impresora elegida.
36. Copias configuradas se respetan.

---

## 25. Prueba manual física en Windows
Preparar instrucciones para:

1. Confirmar Windows: `EPSON TM-T20IIIL Receipt`.
2. Imprimir página de prueba desde Windows.
3. Imprimir prueba desde el sistema.
4. Crear pedido de mesa.
5. Enviar pizza.
6. Confirmar comanda cocina.
7. Agregar producto nuevo.
8. Enviar novedades.
9. Confirmar que segunda comanda trae solo novedades.
10. Reimprimir última comanda.
11. Confirmar que no duplica inventario/pedido.
12. Cobrar.
13. Imprimir ticket cliente.
14. Probar pago mixto.
15. Verificar corte automático.

---

## 26. Validación final
Ejecutar:
- `php artisan migrate`
- `php artisan migrate:status`
- pruebas específicas;
- suite completa PHPUnit;
- Pint;
- `npm.cmd run build`

---

## 27. Informe final
Reportar:
1. estrategia de impresión;
2. dependencias nuevas y justificación;
3. archivos creados/modificados;
4. migraciones;
5. configuración;
6. flujo de comanda;
7. reimpresión;
8. ticket cliente;
9. manejo de errores;
10. permisos;
11. tests agregados;
12. total tests/assertions;
13. Pint;
14. Vite;
15. pasos exactos para prueba física;
16. riesgos pendientes.

---

# Restricciones
- NO usar `migrate:fresh`.
- NO borrar pedidos.
- NO borrar KitchenDispatch.
- NO borrar pagos.
- NO borrar movimientos de caja.
- NO borrar inventario.
- NO duplicar consumos/reservas.
- NO hardcodear permanentemente `EPSON TM-T20IIIL Receipt`.
- NO depender de `TMUSB001` como identificador fijo.
- NO hacer deploy.
- NO hacer push.
- Mantener multiempresa/sucursal.
- Mantener Controllers delgados.
- Lógica de negocio en Actions/Services.
- Una falla de impresión nunca debe corromper el pedido.
