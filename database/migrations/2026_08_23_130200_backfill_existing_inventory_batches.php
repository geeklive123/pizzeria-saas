<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_stocks')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->get()
            ->each(function (object $stock): void {
                DB::table('inventory_batches')->insertOrIgnore([
                    'ulid' => (string) Str::ulid(),
                    'company_id' => $stock->company_id,
                    'branch_id' => $stock->branch_id,
                    'inventory_item_id' => $stock->inventory_item_id,
                    'transition_stock_id' => $stock->id,
                    'quantity_received' => $stock->quantity,
                    'quantity_remaining' => $stock->quantity,
                    'unit_cost' => $stock->average_cost,
                    'received_at' => $stock->updated_at ?? now(),
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('inventory_batches')->whereNotNull('transition_stock_id')->delete();
    }
};
