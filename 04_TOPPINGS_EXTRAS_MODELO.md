# Bloque 4 — Toppings/extras: dominio y administración

## Objetivo
Crear toppings administrables: Tocino, Champiñones, Aceitunas, Extra queso, etc., con precio extra y consumo de inventario opcional.

## Antes de crear
Revisar modelos/tablas/servicios existentes para no duplicar conceptos.

## Datos
Mantener convenciones del proyecto: ULID, `company_id`, decimal monetario, activo/inactivo y orden. Incluir nombre, descripción opcional y precio. Diseñar para poder manejar precio/cantidad por tamaño si corresponde.

## Inventario opcional
Un topping puede ser solo cargo o consumir un `InventoryItem`/ingrediente. Si consume, permitir cantidad por tamaño (ej. Personal 20 g, Mediana 30 g, Familiar 40 g). Reutilizar ledger existente.

## Administración
CRUD no destructivo siguiendo policies/permisos existentes: listar, crear, editar, activar/desactivar, precio y consumo.

## Pruebas
Multiempresa, permisos, precio, topping con/sin inventario y cantidades por tamaño.
