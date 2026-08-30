# Bloque 1 — Corregir “Necesitan atención”

## Objetivo
Eliminar el código Blade que actualmente aparece como texto en el dashboard.

## Requisitos
- Localizar la vista/componente responsable.
- Renderizar mensajes humanos: Agotado, Stock bajo, Próximo a vencer, Sin vencimiento próximo y Receta incompleta cuando corresponda.
- Mantener “Ver inventario”.
- Nunca mostrar `@if`, namespaces, enums ni código técnico.
- Revisar desktop/móvil.

## No tocar
Ledger, FEFO, reservas, descuentos de stock ni capacidad de recetas.

## Validación
Agregar prueba de regresión y ejecutar pruebas relacionadas.
