# Ajustes de Usuarios, Permisos y Flujo de Caja

## Objetivo
Ajustar el sistema actual de la pizzería según la validación real con cajeras. Inspecciona primero la arquitectura existente y reutiliza Actions, Policies, enums, memberships y ledger actuales. No dupliques fuentes de verdad.

## Reglas generales
- No ejecutar `migrate:fresh`.
- No borrar historial.
- No hacer deploy ni push.
- Mantener multiempresa/multisucursal, ULID y ledger inmutable.
- Migraciones solo incrementales.
- Compatible con MySQL y SQLite de pruebas.
- No tocar KitchenDispatch, impresión Epson, inventario o pedidos salvo permisos estrictamente necesarios.

## 1. Roles y permisos personalizables
Mantener roles base: `owner`, `admin`, `cashier`, `waiter`, `kitchen`.

Actualmente Editar usuario solo permite elegir rol. Agregar una sección clara de permisos agrupados por módulo. Los roles aportan permisos predeterminados y debe existir una forma limpia de manejar excepciones por usuario dentro de la empresa activa, reutilizando la arquitectura existente.

Ejemplo para Cajera:
- Ver caja
- Abrir turno
- Cobrar
- Pagos parciales
- Pagos mixtos efectivo/QR
- Ver movimientos de efectivo
- Cerrar turno
- Imprimir/reimprimir ticket
- Movimiento manual: solo con permiso explícito
- Retiro propietario: NO por defecto
- Usuarios/configuración/recetas/inventario: NO por defecto

La UI debe distinguir permisos heredados del rol y excepciones del usuario.

No dispersar comprobaciones manuales por rol si ya existen Policies/Permission enum. Backend/Policies son la fuente de verdad; ocultar botones no basta.

Proteger al último owner y evitar que un usuario conceda permisos que no está autorizado a administrar.

## 2. Registrar otro movimiento
La sección actual puede confundir.

Caja NO debe ser otra pantalla para registrar:
- compras;
- gastos;
- ventas.

Compra se registra en Compras. Gasto en Gastos. Venta/pago en pedidos/cobro. Si afectan efectivo, deben reflejarse automáticamente en el ledger de caja.

Los movimientos manuales quedan solo para operaciones reales de caja no representadas por esos módulos, por ejemplo:
- ingreso adicional de fondo/cambio;
- salida operativa excepcional;
- ajuste físico autorizado;
- depósito/retiro autorizado.

Exigir monto, motivo obligatorio, usuario, fecha/hora, sesión, tipo y autorización cuando corresponda.

Para Cajera:
- historial visible;
- no editar/eliminar movimientos;
- formulario manual oculto si no tiene permiso;
- retiro propietario no permitido sin autorización.

## 3. Retiro del propietario
Mantenerlo separado y auditable. NO es gasto.

Ejemplo: caja Bs 1.500, propietario retira Bs 500 => efectivo esperado Bs 1.000.

Guardar monto, motivo, registra, autoriza, fecha/hora, sesión y saldo resultante. No modificar ventas. No editar/eliminar posteriormente. Requerir owner/admin según autorización existente.

## 4. Movimientos de efectivo visibles para Cajera
Esta sección debe permanecer visible durante el turno y ser principalmente de solo lectura.

Mostrar:
- fecha/hora;
- tipo;
- motivo/observación;
- registra;
- autoriza;
- entrada;
- salida;
- saldo acumulado.

Debe permitir entender de dónde sale el efectivo esperado.

No permitir a la cajera editar, borrar o alterar movimientos históricos.

## 5. Cierre de turno
Mostrar claramente:
- Fondo inicial
- Ventas efectivo
- Ventas QR
- Ventas totales
- Ingresos manuales
- Egresos manuales
- Gastos en efectivo
- Retiros propietario
- Efectivo esperado
- Efectivo contado
- Diferencia
- Estado

QR NO aumenta efectivo físico esperado.

Ejemplos:
- esperado 450, contado 430 => diferencia -20, FALTANTE.
- esperado 450, contado 470 => diferencia +20, SOBRANTE.
- esperado 450, contado 450 => diferencia 0, CUADRADA.

Si diferencia = 0, cierre normal.
Si diferencia != 0:
- permitir registrar el cierre;
- NO alterar ventas ni movimientos para cuadrarlo;
- observación obligatoria;
- guardar esperado, contado, diferencia, estado, observación, usuario y fecha/hora.

Si la arquitectura permite hacerlo limpiamente, preparar soporte para exigir autorización owner/admin cuando la diferencia supere un límite configurable. No crear una segunda arquitectura solo para esto.

En UI calcular la diferencia en tiempo real y mostrar claramente CUADRADA / FALTANTE / SOBRANTE.

## 6. Cambio de turno
Conservar la lógica acordada.

Ejemplo:
Turno 1 abre Bs 1.000.
Termina con efectivo físico Bs 1.500 y ventas QR Bs 500.
El turno 2 hereda Bs 1.500 de efectivo físico.
QR del turno 2 empieza en Bs 0.

Si hubo retiro propietario antes del cierre, el heredado debe reflejarlo.

No permitir más de una sesión abierta incompatible para la misma caja/sucursal.

## 7. Acceso de Cajera
Revisar sidebar y endpoints.

Una cajera debe ver como mínimo, según permisos:
- Venta
- Mesas
- Pedidos
- Caja

Los demás módulos dependen de permisos:
- Usuarios
- Configuración
- Recetas
- Inventario
- Compras
- Gastos
- Proveedores
- Categorías de gasto
- Reportes

No basta ocultarlos: acceso directo por URL debe ser rechazado por Policy/middleware.

## 8. Auditoría
Conservar trazabilidad de:
- apertura;
- cierre;
- ventas efectivo;
- QR separado;
- movimientos manuales;
- gastos que realmente afecten caja;
- retiro propietario;
- usuario ejecutor;
- autorizador;
- diferencia de cierre.

Ledger inmutable. Correcciones posteriores mediante movimientos compensatorios cuando corresponda.

## 9. No romper
Verificar:
- pagos parciales;
- pagos mixtos;
- efectivo + QR;
- vuelto;
- apertura/cierre;
- una sola sesión abierta;
- herencia de efectivo;
- retiros;
- gastos;
- reportes;
- permisos;
- multiempresa/multisucursal.

## 10. Pruebas obligatorias
Agregar como mínimo:
1. Cajera puede ver movimientos.
2. Cajera no puede editar/eliminar movimientos.
3. Cajera sin permiso no registra movimiento manual.
4. Cajera con permiso explícito sí puede.
5. Cajera no registra retiro propietario sin autorización.
6. Owner/Admin autorizado puede registrar retiro.
7. Retiro disminuye efectivo esperado.
8. QR no aumenta efectivo esperado.
9. Pago mixto divide efectivo/QR correctamente.
10. Cierre cuadrado => diferencia 0.
11. Faltante => diferencia negativa.
12. Sobrante => diferencia positiva.
13. Diferencia != 0 exige observación.
14. Diferencia no altera ventas.
15. Segundo turno hereda solo efectivo físico.
16. QR nuevo turno inicia en cero.
17. No coexistencia de sesiones incompatibles.
18. Acceso directo por URL respeta permisos.
19. Excepciones de usuario funcionan.
20. Último owner sigue protegido.

Al final ejecutar:
```bash
php artisan test
vendor/bin/pint --test
npm run build
```

## 11. Criterios de aceptación
Terminado cuando:
- roles base siguen funcionando;
- permisos son suficientemente granulares;
- cajera ve solo lo autorizado;
- Movimientos de efectivo permanece visible;
- movimientos manuales dependen de permiso;
- gastos/compras no se duplican;
- retiro propietario queda separado;
- cierre calcula esperado vs contado correctamente;
- faltante/sobrante queda auditado;
- observación obligatoria si hay diferencia;
- siguiente turno hereda solo efectivo;
- QR inicia en cero;
- pagos mixtos/parciales siguen funcionando;
- Policies protegen URLs;
- tests, Pint y Vite pasan.

## 12. Informe final
Informar:
1. Problema encontrado.
2. Archivos modificados.
3. Migraciones creadas.
4. Modelo final de permisos.
5. Fórmula/lógica del efectivo esperado.
6. Manejo de faltantes/sobrantes.
7. Herencia entre turnos.
8. Qué puede/no puede hacer Cajera.
9. Tests agregados.
10. Resultados de suite, Pint y Vite.
11. Pasos manuales exactos para probar desde la interfaz.

No hacer deploy ni push.
