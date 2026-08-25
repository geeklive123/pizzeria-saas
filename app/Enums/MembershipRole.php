<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';

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
                Permission::ReversePayments,
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
                Permission::ViewOrders,
                Permission::ManageOrders,
                Permission::CancelOrders,
                Permission::ViewTables,
                Permission::ViewCash,
                Permission::OpenCash,
                Permission::CloseCash,
                Permission::CreatePayments,
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
