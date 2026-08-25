<?php

namespace App\Enums;

enum Permission: string
{
    case ViewCompany = 'company.view';
    case ManageCompany = 'company.manage';
    case ViewBranches = 'branches.view';
    case ManageBranches = 'branches.manage';
    case ViewMemberships = 'memberships.view';
    case ManageMemberships = 'memberships.manage';
    case ViewCatalog = 'catalog.view';
    case ManageCatalog = 'catalog.manage';
    case ViewRecipes = 'recipes.view';
    case ManageRecipes = 'recipes.manage';
    case ViewInventory = 'inventory.view';
    case ManageInventory = 'inventory.manage';
    case ViewPurchases = 'purchases.view';
    case ManagePurchases = 'purchases.manage';
    case ViewOrders = 'orders.view';
    case ManageOrders = 'orders.manage';
    case CancelOrders = 'orders.cancel';
    case ViewTables = 'tables.view';
    case ManageTables = 'tables.manage';
    case ViewKitchen = 'kitchen.view';
    case ManageKitchen = 'kitchen.manage';
    case ViewCash = 'cash.view';
    case OpenCash = 'cash.open';
    case CloseCash = 'cash.close';
    case ManageCash = 'cash.manage';
    case RegisterManualCashMovements = 'cash.manual_movements.create';
    case AuthorizeCashWithdrawals = 'cash.withdrawals.authorize';
    case CreatePayments = 'payments.create';
    case ReversePayments = 'payments.reverse';
    case ViewExpenses = 'expenses.view';
    case CreateExpenses = 'expenses.create';
    case ReverseExpenses = 'expenses.reverse';
    case ManageExpenseCategories = 'expense_categories.manage';
    case ViewSuppliers = 'suppliers.view';
    case ManageSuppliers = 'suppliers.manage';
    case ViewReports = 'reports.view';
    case ViewFinancialReports = 'reports.financial';
    case ExportReports = 'reports.export';

    public function module(): string
    {
        return match ($this) {
            self::ViewCompany, self::ManageCompany, self::ViewBranches, self::ManageBranches => 'Empresa y configuración',
            self::ViewMemberships, self::ManageMemberships => 'Usuarios',
            self::ViewCatalog, self::ManageCatalog, self::ViewRecipes, self::ManageRecipes => 'Catálogo y recetas',
            self::ViewInventory, self::ManageInventory => 'Inventario',
            self::ViewPurchases, self::ManagePurchases => 'Compras',
            self::ViewOrders, self::ManageOrders, self::CancelOrders, self::ViewTables, self::ManageTables => 'Venta, pedidos y mesas',
            self::ViewKitchen, self::ManageKitchen => 'Cocina',
            self::ViewCash, self::OpenCash, self::CloseCash, self::ManageCash, self::RegisterManualCashMovements,
            self::AuthorizeCashWithdrawals, self::CreatePayments, self::ReversePayments => 'Caja y pagos',
            self::ViewExpenses, self::CreateExpenses, self::ReverseExpenses, self::ManageExpenseCategories,
            self::ViewSuppliers, self::ManageSuppliers => 'Gastos y proveedores',
            self::ViewReports, self::ViewFinancialReports, self::ExportReports => 'Reportes',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ViewCompany => 'Ver empresa',
            self::ManageCompany => 'Administrar configuración',
            self::ViewBranches => 'Ver sucursales',
            self::ManageBranches => 'Administrar sucursales',
            self::ViewMemberships => 'Ver usuarios',
            self::ManageMemberships => 'Administrar usuarios y permisos',
            self::ViewCatalog => 'Ver catálogo',
            self::ManageCatalog => 'Administrar catálogo',
            self::ViewRecipes => 'Ver recetas',
            self::ManageRecipes => 'Administrar recetas',
            self::ViewInventory => 'Ver inventario',
            self::ManageInventory => 'Administrar inventario',
            self::ViewPurchases => 'Ver compras',
            self::ManagePurchases => 'Administrar compras',
            self::ViewOrders => 'Ver pedidos',
            self::ManageOrders => 'Crear y operar pedidos',
            self::CancelOrders => 'Cancelar pedidos',
            self::ViewTables => 'Ver mesas',
            self::ManageTables => 'Administrar mesas',
            self::ViewKitchen => 'Ver cocina',
            self::ManageKitchen => 'Operar cocina',
            self::ViewCash => 'Ver caja y movimientos',
            self::OpenCash => 'Abrir turno',
            self::CloseCash => 'Cerrar turno',
            self::ManageCash => 'Administrar caja (legado)',
            self::RegisterManualCashMovements => 'Registrar movimientos manuales',
            self::AuthorizeCashWithdrawals => 'Autorizar retiros de propietario',
            self::CreatePayments => 'Cobrar, pagos parciales/mixtos e imprimir tickets',
            self::ReversePayments => 'Revertir pagos',
            self::ViewExpenses => 'Ver gastos',
            self::CreateExpenses => 'Registrar gastos',
            self::ReverseExpenses => 'Revertir gastos',
            self::ManageExpenseCategories => 'Administrar categorías de gasto',
            self::ViewSuppliers => 'Ver proveedores',
            self::ManageSuppliers => 'Administrar proveedores',
            self::ViewReports => 'Ver reportes operativos',
            self::ViewFinancialReports => 'Ver reportes financieros',
            self::ExportReports => 'Exportar reportes',
        };
    }
}
