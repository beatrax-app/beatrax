<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\ImportRun;
use Modules\Receipts\Database\Seeders\Demo\DemoReceiptsSeeder;

/*
 * The demo seeder materialises the two mock file-drop rows (a PayPal and
 * an ICS receipt) via DemoFileImportSpec so a fresh demo install renders
 * the receipts surface with data. It is idempotent on the production
 * UNIQUE key (provider_message_id per user) and no-ops without a primary
 * demo user or a 'demo' ImportRun to anchor to.
 */

function seedDemoPrimary(): User
{
    $user = User::query()->create([
        'username' => 'demo-primary',
        'password' => 'x',
        'period_start_day' => 1,
    ]);

    ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'demo',
        'raw_file_path' => '/demo/seed.demo',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    return $user;
}

it('inserts the two demo file_imports rows with source_kind derived from the filename', function (): void {
    $user = seedDemoPrimary();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $seeder = new DemoReceiptsSeeder($db);

    $count = $seeder->run(['demo-1@beatrax.local' => $user]);

    expect($count)->toBe(2);

    $rows = DB::table('file_imports')->where('user_id', $user->id)->orderBy('provider_message_id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('source_kind')->all())->toBe(['eml', 'eml']);
    expect($rows->firstWhere('provider_message_id', 'demo-paypal-receipt-001')->matcher_key)->toBe('paypal-receipt');
    expect($rows->firstWhere('provider_message_id', 'demo-ics-statement-001')->matcher_key)->toBe('ics-receipt');
});

it('is idempotent — a second run inserts no duplicate file_imports', function (): void {
    $user = seedDemoPrimary();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $seeder = new DemoReceiptsSeeder($db);

    $seeder->run(['demo-1@beatrax.local' => $user]);
    $seeder->run(['demo-1@beatrax.local' => $user]);

    expect(DB::table('file_imports')->where('user_id', $user->id)->count())->toBe(2);
});

it('no-ops when the primary demo user is absent', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    expect((new DemoReceiptsSeeder($db))->run([]))->toBe(0);
});
