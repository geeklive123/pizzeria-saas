<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propietario',
            self::Admin => 'Administrador',
            self::Cashier => 'Cajero',
            self::Waiter => 'Mesero',
            self::Kitchen => 'Cocina',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Acceso completo a la empresa y a su configuración.',
            self::Admin => 'Puede gestionar la operación, productos, inventario, usuarios y reportes según las reglas actuales.',
            self::Cashier => 'Puede realizar cobros, trabajar con su turno de caja y consultar los movimientos necesarios para cuadrar su caja.',
            self::Waiter => 'Puede tomar pedidos, trabajar con mesas, agregar productos y enviar pedidos a cocina.',
            self::Kitchen => 'Puede consultar las comandas y actualizar el estado de preparación.',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => [
                Permission::ViewCompany,
                Permission::ManageCompany,
                Permission::ViewBranches,
                Permission::ManageBranches,
                Permission::ViewMemberships,
                Permission::ManageMemberships,
                Permission::ViewCatalog,
                Permission::ManageCatalog,
                Permission::ViewRecipes,
                Permission::ManageRecipes,
                Permission::ViewInventory,
                Permission::ManageInventory,
                Permission::ViewPurchases,
                Permission::ManagePurchases,
                Permission::ViewOrders,
                Permission::ManageOrders,
                Permission::CancelOrders,
                Permission::ViewTables,
                Permission::ManageTables,
                Permission::ViewKitchen,
                Permission::ManageKitchen,
                Permission::ViewCash,
                Permission::OpenCash,
                Permission::CloseCash,
                Permission::ManageCash,
                Permission::RegisterManualCashMovements,
                Permission::AuthorizeCashWithdrawals,
                Permission::CreatePayments,
                Permission::ApplyOrderDiscounts,
                Permission::ReversePayments,
                Permission::TransferCashSessionOperations,
                Permission::ViewExpenses,
                Permission::CreateExpenses,
                Permission::ReverseExpenses,
                Permission::ManageExpenseCategories,
                Permission::ViewSuppliers,
                Permission::ManageSuppliers,
                Permission::ViewReports,
                Permission::ViewFinancialReports,
                Permission::ExportReports,
            ],
            self::Cashier => [
                Permission::ViewCompany,
                Permission::ViewBranches,
                Permission::ViewCatalog,
                Permission::ViewInventory,
                Permission::ViewOrders,
                Permission::ManageOrders,
                Permission::CancelOrders,
                Permission::ViewTables,
                Permission::ViewCash,
                Permission::OpenCash,
                Permission::CloseCash,
                Permission::CreatePayments,
                Permission::ApplyOrderDiscounts,
                Permission::ViewExpenses,
                Permission::CreateExpenses,
                Permission::ViewReports,
            ],
            self::Waiter => [
                Permission::ViewCompany,
                Permission::ViewBranches,
                Permission::ViewCatalog,
                Permission::ViewOrders,
                Permission::ManageOrders,
                Permission::ViewTables,
            ],
            self::Kitchen => [
                Permission::ViewCompany,
                Permission::ViewBranches,
                Permission::ViewCatalog,
                Permission::ViewRecipes,
                Permission::ViewInventory,
                Permission::ViewKitchen,
                Permission::ManageKitchen,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
