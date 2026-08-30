<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpenTableOrderAction
{
    public function __construct(private readonly NextOrderNumberAction $numbers, private readonly CompanyAccessService $access) {}

    public function execute(Company $company, Branch $branch, RestaurantTable $table, User $user, ?string $customerName = null): Order
    {
        $this->access->ensure($user, $company, Permission::ManageOrders);

        return DB::transaction(function () use ($company, $branch, $table, $user, $customerName): Order {
            $table = RestaurantTable::query()->where('company_id', $company->id)->where('branch_id', $branch->id)
                ->whereKey($table->id)->lockForUpdate()->firstOrFail();

            if (! $table->is_active) {
                throw new DomainException('La mesa está inactiva.');
            }

            $openOrder = Order::query()->where('active_restaurant_table_id', $table->id)->lockForUpdate()->first();
            if ($openOrder) {
                return $openOrder;
            }

            return Order::query()->create([
                'company_id' => $company->id, 'branch_id' => $branch->id,
                'restaurant_table_id' => $table->id, 'active_restaurant_table_id' => $table->id,
                'order_number' => $this->numbers->execute($company, $branch),
                'type' => OrderType::DineIn, 'charge_mode' => $company->table_charge_mode,
                'status' => OrderStatus::Open, 'customer_name' => blank($customerName) ? null : trim($customerName),
                'subtotal' => '0.00', 'discount_total' => '0.00', 'total' => '0.00',
                'opened_at' => now(), 'created_by' => $user->id,
            ]);
        });
    }
}
