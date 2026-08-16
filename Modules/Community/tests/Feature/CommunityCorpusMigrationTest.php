<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

it('creates the community_merchant_mappings table with the documented schema', function (): void {
    expect(Schema::hasTable('community_merchant_mappings'))->toBeTrue();
    expect(Schema::hasColumns('community_merchant_mappings', [
        'id', 'user_id', 'pattern', 'generalized_pattern', 'name',
        'category', 'region', 'contributor', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('carries the optional per-merchant contact columns', function (): void {
    expect(Schema::hasColumns('community_merchant_mappings', [
        'website', 'cancel_url', 'support_url', 'support_phone', 'support_email',
    ]))->toBeTrue();
});

it('accepts a corpus row that carries no contact data at all', function (): void {
    DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'NO-CONTACT-PATTERN',
        'generalized_pattern' => 'no contact pattern',
        'name' => 'Merchant Without Contact Data',
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $row = DB::table('community_merchant_mappings')->where('pattern', 'NO-CONTACT-PATTERN')->first();

    expect($row?->website)->toBeNull();
    expect($row?->cancel_url)->toBeNull();
    expect($row?->support_url)->toBeNull();
    expect($row?->support_phone)->toBeNull();
    expect($row?->support_email)->toBeNull();
});

it('accepts a global corpus row with user_id null', function (): void {
    DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'GLOBAL-PATTERN-A',
        'generalized_pattern' => 'global pattern a',
        'name' => 'Global Merchant A',
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(DB::table('community_merchant_mappings')->whereNull('user_id')->count())->toBe(1);
});

it('rejects duplicate (null, pattern) entries via the UNIQUE index', function (): void {
    DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'PATTERN-X',
        'generalized_pattern' => null,
        'name' => 'Merchant X',
        'category' => null,
        'region' => null,
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(fn () => DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'PATTERN-X',
        'generalized_pattern' => null,
        'name' => 'Different Name Same Pattern',
        'category' => null,
        'region' => null,
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]))->toThrow(QueryException::class);
});

it('allows the same pattern under two different user_id values', function (): void {
    $userA = User::create([
        'username' => 'corpus-user-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $userB = User::create([
        'username' => 'corpus-user-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    DB::table('community_merchant_mappings')->insert([
        'user_id' => $userA->id,
        'pattern' => 'OVERRIDE-PATTERN',
        'generalized_pattern' => null,
        'name' => 'User A override',
        'category' => null,
        'region' => null,
        'contributor' => 'user-a',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    DB::table('community_merchant_mappings')->insert([
        'user_id' => $userB->id,
        'pattern' => 'OVERRIDE-PATTERN',
        'generalized_pattern' => null,
        'name' => 'User B override',
        'category' => null,
        'region' => null,
        'contributor' => 'user-b',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(DB::table('community_merchant_mappings')->where('pattern', 'OVERRIDE-PATTERN')->count())->toBe(2);
});
