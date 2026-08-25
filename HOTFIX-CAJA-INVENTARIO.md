# HOTFIX — CAJA E INVENTARIO

Corrige únicamente estas dos incidencias actuales del sistema.
No avances a ningún sprint nuevo.

## PROBLEMA 1 — INVENTARIO

Al abrir el detalle de un artículo de inventario aparece:

ParseError
resources/views/inventory/show.blade.php:9

syntax error, unexpected token "endforeach",
expecting "elseif" or "else" or "endif"

### Objetivo

1. Inspecciona completamente:
   `resources/views/inventory/show.blade.php`

2. Localiza el bloque Blade mal cerrado.

3. Revisa especialmente:
   - `@if / @endif`
   - `@can / @endcan`
   - `@foreach / @endforeach`
   - formularios condicionales
   - bloques de lotes
   - stock mínimo
   - operaciones de inventario

4. No arregles solo la línea 9 a ciegas.
   Verifica toda la estructura Blade del archivo.

5. Mantén las funcionalidades actuales:
   - stock físico
   - vencido
   - reservado
   - disponible
   - stock mínimo
   - lotes
   - movimientos
   - retiro de vencidos
   - operaciones autorizadas

6. Ejecuta compilación de vistas Blade después de corregir.

7. Añade o ajusta una prueba HTTP que abra `/inventory/{item}` y confirme respuesta 200 para un owner autorizado.

---

## PROBLEMA 2 — CAJA

En `/cash/open` el select "Caja" aparece vacío y no permite abrir turno.

### Objetivo

1. Inspecciona:
   - CashRegister
   - CashSession
   - CashController
   - CashSessionService/Actions
   - seeder de Caja Principal
   - vista cash/open
   - scopes por `company_id` y `branch_id`

2. Determina si:
   - no existe CashRegister para la sucursal Principal;
   - existe pero el query no la devuelve;
   - está inactiva;
   - el seeder no se ejecutó correctamente;
   - existe un problema de company/branch context.

3. NO hardcodear una caja en la vista.

4. El owner/admin debe ver las cajas activas de la sucursal actual.

5. Si no existe ninguna caja:
   - mostrar un mensaje claro;
   - permitir a owner/admin crear/configurar una caja; o
   - asegurar que el seeder demo cree idempotentemente "Caja Principal" para Mi Pizzería / Sucursal Principal.

6. Si Caja Principal ya existe en BD, corregir el query/contexto y NO duplicarla.

7. No crear varias "Caja Principal" al ejecutar seed nuevamente.

8. Verificar que al seleccionar Caja Principal y colocar monto inicial pueda abrirse una CashSession correctamente.

9. Añadir tests para:
   - owner ve caja activa de su sucursal;
   - caja de otra sucursal no aparece;
   - no se duplica Caja Principal;
   - se puede abrir turno;
   - no se pueden abrir dos sesiones simultáneas para la misma caja.

---

## VALIDACIÓN FINAL

Ejecuta:

```bash
php artisan migrate:status
php artisan test
php vendor/bin/pint --test
npm.cmd run build
```

Además:

- compilar Blade;
- verificar rutas de inventory detail y cash/open;
- NO ejecutar `migrate:fresh`;
- NO borrar datos;
- NO deploy;
- NO push;
- NO avanzar de sprint.

## INFORME FINAL

Al finalizar dime:

1. causa exacta del ParseError;
2. archivo/línea corregida;
3. causa exacta del select vacío de Caja;
4. si Caja Principal existía o fue creada;
5. archivos modificados;
6. tests añadidos;
7. total tests/assertions;
8. resultado Pint;
9. resultado build.
