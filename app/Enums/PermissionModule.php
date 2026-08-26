<?php

namespace App\Enums;

enum PermissionModule: string
{
    case Sales = 'sales';
    case TablesAndOrders = 'tables_and_orders';
    case Kitchen = 'kitchen';
    case Cash = 'cash';
    case CatalogAndRecipes = 'catalog_and_recipes';
    case Inventory = 'inventory';
    case Purchases = 'purchases';
    case Expenses = 'expenses';
    case Reports = 'reports';
    case Users = 'users';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Ventas',
            self::TablesAndOrders => 'Mesas y pedidos',
            self::Kitchen => 'Cocina',
            self::Cash => 'Caja',
            self::CatalogAndRecipes => 'Productos y recetas',
            self::Inventory => 'Inventario',
            self::Purchases => 'Compras',
            self::Expenses => 'Gastos',
            self::Reports => 'Reportes',
            self::Users => 'Usuarios',
            self::Company => 'Empresa y configuración',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sales => 'Cobros y operación de ventas.',
            self::TablesAndOrders => 'Mesas, pedidos y atención al cliente.',
            self::Kitchen => 'Comandas y preparación.',
            self::Cash => 'Turnos, cajas y movimientos.',
            self::CatalogAndRecipes => 'Catálogo, categorías y recetas.',
            self::Inventory => 'Existencias, ajustes y mermas.',
            self::Purchases => 'Compras inventariables.',
            self::Expenses => 'Gastos, categorías y proveedores.',
            self::Reports => 'Información operativa y financiera.',
            self::Users => 'Trabajadores, funciones y permisos.',
            self::Company => 'Empresa, sucursales y configuración.',
        };
    }
}
