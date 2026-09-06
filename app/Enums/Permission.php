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
    case ApplyOrderDiscounts = 'orders.discounts.apply';
    case ReversePayments = 'payments.reverse';
    case TransferCashSessionOperations = 'cash.session_operations.transfer';
    case ViewExpenses = 'expenses.view';
    case CreateExpenses = 'expenses.create';
    case ReverseExpenses = 'expenses.reverse';
    case ManageExpenseCategories = 'expense_categories.manage';
    case ViewSuppliers = 'suppliers.view';
    case ManageSuppliers = 'suppliers.manage';
    case ViewReports = 'reports.view';
    case ViewFinancialReports = 'reports.financial';
    case ExportReports = 'reports.export';

    public function module(): PermissionModule
    {
        return match ($this) {
            self::CreatePayments, self::ApplyOrderDiscounts, self::ReversePayments => PermissionModule::Sales,
            self::ViewOrders, self::ManageOrders, self::CancelOrders, self::ViewTables, self::ManageTables => PermissionModule::TablesAndOrders,
            self::ViewKitchen, self::ManageKitchen => PermissionModule::Kitchen,
            self::ViewCash, self::OpenCash, self::CloseCash, self::ManageCash, self::RegisterManualCashMovements,
            self::AuthorizeCashWithdrawals, self::TransferCashSessionOperations => PermissionModule::Cash,
            self::ViewCatalog, self::ManageCatalog, self::ViewRecipes, self::ManageRecipes => PermissionModule::CatalogAndRecipes,
            self::ViewInventory, self::ManageInventory => PermissionModule::Inventory,
            self::ViewPurchases, self::ManagePurchases => PermissionModule::Purchases,
            self::ViewExpenses, self::CreateExpenses, self::ReverseExpenses, self::ManageExpenseCategories,
            self::ViewSuppliers, self::ManageSuppliers => PermissionModule::Expenses,
            self::ViewReports, self::ViewFinancialReports, self::ExportReports => PermissionModule::Reports,
            self::ViewMemberships, self::ManageMemberships => PermissionModule::Users,
            self::ViewCompany, self::ManageCompany, self::ViewBranches, self::ManageBranches => PermissionModule::Company,
        };
    }

    public function isReadOnly(): bool
    {
        return match ($this) {
            self::ViewCompany, self::ViewBranches, self::ViewMemberships, self::ViewCatalog,
            self::ViewRecipes, self::ViewInventory, self::ViewPurchases, self::ViewOrders,
            self::ViewTables, self::ViewKitchen, self::ViewCash, self::ViewExpenses,
            self::ViewSuppliers, self::ViewReports, self::ViewFinancialReports => true,
            default => false,
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
            self::ManageMemberships => 'Crear y editar usuarios; administrar roles y permisos',
            self::ViewCatalog => 'Ver catálogo',
            self::ManageCatalog => 'Administrar productos y categorías',
            self::ViewRecipes => 'Ver recetas',
            self::ManageRecipes => 'Administrar recetas',
            self::ViewInventory => 'Ver inventario',
            self::ManageInventory => 'Administrar inventario, ajustes y mermas',
            self::ViewPurchases => 'Ver compras',
            self::ManagePurchases => 'Crear, publicar y corregir compras',
            self::ViewOrders => 'Ver pedidos',
            self::ManageOrders => 'Gestionar pedidos y enviar a cocina',
            self::CancelOrders => 'Cancelar pedidos',
            self::ViewTables => 'Ver mesas',
            self::ManageTables => 'Administrar mesas',
            self::ViewKitchen => 'Ver cocina',
            self::ManageKitchen => 'Actualizar preparación y marcar estados',
            self::ViewCash => 'Ver caja y movimientos',
            self::OpenCash => 'Abrir turno',
            self::CloseCash => 'Cerrar turno',
            self::ManageCash => 'Administrar cajas',
            self::RegisterManualCashMovements => 'Registrar movimientos manuales',
            self::AuthorizeCashWithdrawals => 'Autorizar retiros de propietario',
            self::CreatePayments => 'Registrar cobros',
            self::ApplyOrderDiscounts => 'Aplicar descuentos manuales a pizzas elegibles',
            self::ReversePayments => 'Revertir o corregir cobros',
            self::TransferCashSessionOperations => 'Transferir cobros entre sesiones de caja',
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
