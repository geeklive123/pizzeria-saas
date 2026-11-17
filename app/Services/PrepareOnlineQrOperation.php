<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\OnlineOrderRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PrepareOnlineQrOperation
{
    public function __construct(
        private readonly OnlineSystemIdentityService $identities,
        private readonly OnlinePaymentPreparationService $preparations,
    ) {}

    public function execute(Company $company, Branch $branch, OnlineOrderRequest $preparation): object
    {
        $actor = $this->identities->forBranch($company, $branch);

        return $this->preparations->withUsable($company, $branch, $preparation,
            function (OnlineOrderRequest $locked, CashSession $session) use ($company, $branch, $actor): object {
                if ($locked->fulfillment !== 'delivery') {
                    throw new DomainException('La operación QR online requiere delivery.');
                }
                $operation = DB::table('online_qr_operations')->where('online_order_request_id', $locked->id)->lockForUpdate()->first();
                if ($operation) {
                    $this->assertContext($operation, $company, $branch, $session, $actor);

                    return $operation;
                }
                $id = DB::table('online_qr_operations')->insertGetId([
                    'company_id' => $company->id, 'branch_id' => $branch->id, 'online_order_request_id' => $locked->id,
                    'cash_session_id' => $session->id, 'original_cashier_id' => $session->opened_by,
                    'system_user_id' => $actor->id, 'status' => 'pending_emission', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return DB::table('online_qr_operations')->where('id', $id)->first();
            });
    }

    /** Local work only; bank calls must occur after this transaction commits. */
    public function withPendingOperation(Company $company, Branch $branch, OnlineOrderRequest $preparation, callable $operation): mixed
    {
        $actor = $this->identities->forBranch($company, $branch);

        return $this->preparations->withUsable($company, $branch, $preparation,
            function (OnlineOrderRequest $locked, CashSession $session) use ($company, $branch, $actor, $operation): mixed {
                if ($locked->fulfillment !== 'delivery') {
                    throw new DomainException('La operación QR online requiere delivery.');
                }
                $context = DB::table('online_qr_operations')->where('online_order_request_id', $locked->id)->lockForUpdate()->first();
                if (! $context) {
                    throw new DomainException('No existe un contexto de operación online.');
                }
                $this->assertContext($context, $company, $branch, $session, $actor);

                return $operation($locked, $session, $actor, $context);
            });
    }

    private function assertContext(object $operation, Company $company, Branch $branch, CashSession $session, User $actor): void
    {
        if ((int) $operation->company_id !== (int) $company->id || (int) $operation->branch_id !== (int) $branch->id
            || (int) $operation->cash_session_id !== (int) $session->id || (int) $operation->original_cashier_id !== (int) $session->opened_by
            || (int) $operation->system_user_id !== (int) $actor->id || $operation->status !== 'pending_emission') {
            throw new DomainException('El contexto de la operación online requiere revisión.');
        }
    }
}
