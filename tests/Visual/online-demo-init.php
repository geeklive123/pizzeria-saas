<?php

use App\Actions\ApplyInventoryMovementAction;
use App\Enums\InventoryMovementType;
use App\Enums\PrinterPurpose;
use App\Enums\ProductType;
use App\Models\BankQrSetting;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\Membership;
use App\Models\PrinterSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$tmp = realpath(dirname(__DIR__, 2).'/tmp');
if (! $tmp) {
    throw new RuntimeException('Existing tmp directory required');
}
$dir = $tmp.'/online-qr-demo-'.bin2hex(random_bytes(8));
if (! mkdir($dir, 0700) || ! mkdir($dir.'/sessions', 0700)) {
    throw new RuntimeException('Could not create demo directory');
}
file_put_contents($dir.'/demo.marker', bin2hex(random_bytes(32)), LOCK_EX);
$handle = fopen($dir.'/demo.sqlite', 'x');
if (! $handle) {
    throw new RuntimeException('Could not create demo database');
}
fclose($handle);
putenv('ONLINE_QR_DEMO_DIR='.$dir);
$app = require __DIR__.'/online-demo-bootstrap.php';
if (Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]) !== 0) {
    throw new RuntimeException('Demo migration failed');
}
$company = Company::factory()->create(['name' => 'Masa & Maña - DEMO']);
$branch = Branch::factory()->for($company)->create(['name' => 'Sucursal ficticia']);
$category = Category::factory()->for($company)->create(['name' => 'Gaseosas']);
$product = Product::factory()->for($category)->create(['name' => 'Producto de demostración', 'type' => ProductType::Beverage, 'is_active' => true]);
$variant = ProductVariant::factory()->for($product)->create(['price' => '12.00', 'requires_preparation' => false]);
$user = User::factory()->create(['name' => 'Administrador DEMO', 'email' => 'demo@example.test', 'password' => Hash::make('Demo-local-12345')]);
Membership::factory()->for($company)->for($user)->owner()->create();
$register = CashRegister::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Caja DEMO', 'is_active' => true]);
CashSession::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id,
    'active_cash_register_id' => $register->id, 'opened_by' => $user->id, 'opening_amount' => '0.00', 'status' => 'open', 'opened_at' => now()]);
$unit = Unit::factory()->for($company)->create();
$inventory = InventoryItem::query()->create(['company_id' => $company->id, 'unit_id' => $unit->id,
    'product_variant_id' => $variant->id, 'name' => 'Inventario DEMO', 'is_active' => true]);
app(ApplyInventoryMovementAction::class)->execute($company, $branch, $inventory, InventoryMovementType::AdjustmentIn,
    '20.000', '1.000000', $user, reason: 'Stock ficticio de demostración');
BankQrSetting::query()->create(['company_id' => $company->id, 'active_scope' => $company->id.':company',
    'credentials' => ['username' => 'fake', 'password' => 'fake', 'aes_key' => 'fake', 'account_credit' => 'fake']]);
foreach ([PrinterPurpose::Kitchen, PrinterPurpose::CustomerTicket] as $purpose) {
    PrinterSetting::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'purpose' => $purpose, 'windows_printer_name' => 'Impresora ficticia DEMO', 'is_active' => true,
        'auto_print' => true, 'copies' => 1, 'paper_width_mm' => 80]);
}
DB::statement('CREATE TABLE demo_bank_state (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
DB::table('demo_bank_state')->insert(['id' => 1, 'status' => 'pending']);
file_put_contents($dir.'/info.json', json_encode(['variant_ulid' => $variant->ulid, 'company_id' => $company->id,
    'branch_id' => $branch->id, 'control_token' => bin2hex(random_bytes(32))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
echo $dir.PHP_EOL;
