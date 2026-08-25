<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateTakeawayOrderAction
{
    public function __construct(private readonly NextOrderNumberAction $numbers, private readonly CompanyAccessService $access) {}

    public function execute(Company $company, Branch $branch, User $user, array $data = []): Order
    {
        $this->access->ensure($user, $company, Permission::ManageOrders);

        if ((int) $branch->company_id !== (int) $company->getKey()) {
            throw new DomainException('The branch does not belong to the active company.');
        }

        return DB::transaction(fn (): Order => Order::query()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'order_number' => $this->numbers->execute($company, $branch),
            'type' => OrderType::Takeaway, 'status' => OrderStatus::Open,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'notes' => $data['notes'] ?? null,
            'subtotal' => '0.00', 'discount_total' => '0.00', 'total' => '0.00',
            'opened_at' => now(), 'created_by' => $user->id,
        ]));
    }
}
