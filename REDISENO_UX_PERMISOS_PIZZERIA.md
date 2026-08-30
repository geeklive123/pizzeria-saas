# REDISEÑO UX DE USUARIOS, ROLES Y PERMISOS

## Objetivo

Rediseñar la experiencia visual de permisos para que un dueño de
pizzería pueda configurar trabajadores fácilmente, sin conocer términos
técnicos.

## Principios

-   No reemplazar la arquitectura actual de permisos.
-   Conservar roles, Policies, Permission enum, membership overrides y
    auditoría.
-   No cambiar autorización salvo para corregir un bug demostrado.
-   Trabajar principalmente sobre UX/UI.

## Problema actual

Ocultar al cliente códigos y términos como `company.view`,
`branches.manage`, `memberships.manage`, "Heredado: permitido",
"Heredado: no incluido" y "Heredar del rol".

## Flujo principal

Al crear o editar un usuario, elegir primero una función: -
Propietario - Administrador - Cajero - Mesero - Cocina

Mostrar una descripción sencilla de cada rol. El flujo normal debe ser
elegir rol y guardar. La configuración detallada estará detrás de
**Personalizar permisos**.

## Resumen visual

Agrupar el acceso por: - Ventas - Mesas y pedidos - Cocina - Caja -
Productos y recetas - Inventario - Compras - Gastos - Reportes -
Usuarios - Empresa y configuración

Estados visibles: - Permitido - Solo lectura - Sin acceso -
Personalizado

## Permisos avanzados

Cada módulo será expandible. Mostrar nombres humanos, nunca códigos
internos. Mantener internamente heredar/permitir/denegar, pero
presentarlo como: - Usar permisos del perfil - Permitir - Bloquear

Agregar acciones: - Restaurar permisos del rol - Permitir todo el
módulo - Bloquear todo el módulo

Confirmar cambios masivos.

## Roles

**Propietario:** mostrar "Acceso completo" y conservar protección del
último owner.

**Administrador:** acceso administrativo conforme a las reglas actuales.

**Cajero:** "Puede realizar cobros, trabajar con su turno de caja y
consultar los movimientos necesarios para cuadrar su caja."

**Mesero:** "Puede tomar pedidos, trabajar con mesas, agregar productos
y enviar pedidos a cocina."

**Cocina:** "Puede consultar las comandas y actualizar el estado de
preparación."

No otorgar permisos adicionales que no existan actualmente en cada rol.

## Diseño

Mantener Blade + Tailwind + JS mínimo. Usar cards, accordions,
interruptores, botones segmentados o checkboxes claros. Reducir
considerablemente el alto de la pantalla y hacerla responsive.

Debe parecer una herramienta operativa de restaurante, no una pantalla
técnica.

## Backend

Antes de modificar, inspeccionar Permission enum, roles, overrides,
Actions, Policies, Requests, Membership y vistas existentes.

Reutilizar la fuente de verdad actual. No crear una segunda
arquitectura. Si hace falta metadata visual, centralizar etiqueta,
descripción, módulo y orden en un único lugar.

## Protecciones

Conservar: - último owner; - Policies; - prevención de escalamiento de
privilegios; - aislamiento por company_id; - auditoría; - overrides
existentes.

## Pruebas

Verificar: 1. Herencia del rol sin personalización. 2. Override
permitir. 3. Override bloquear. 4. Restaurar al rol. 5. UI no altera
autorización. 6. Cajero no obtiene administración accidentalmente. 7.
Mesero no obtiene caja accidentalmente. 8. Cocina mantiene
restricciones. 9. Owner mantiene protecciones. 10. Aislamiento entre
empresas. 11. Overrides existentes se conservan. 12. Cambio de rol no
destruye excepciones indebidamente.

Ejecutar pruebas dirigidas, suite completa, `vendor/bin/pint --test`,
`npm run build`, compilación Blade y `git diff --check`.

## Restricciones

-   No migraciones salvo necesidad demostrada.
-   No `migrate:fresh`.
-   No borrar usuarios ni overrides.
-   No tocar producción.
-   No commit.
-   No push.
-   No deploy.

## Entrega

Informar UX anterior vs nueva, archivos modificados, mapeo de módulos,
cambios de autorización si los hubo, pruebas, riesgos y flujo final para
owner, cajero y mesero.
