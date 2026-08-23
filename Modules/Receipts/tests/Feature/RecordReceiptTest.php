<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
});

it('invokes the matcher and transitions status to parsed for a PayPal .eml', function (): void {
    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');
    /** @var RecordReceipt $record */
    $record = $this->app->make(RecordReceipt::class);

    $outcome = $record($bytes, $this->fixtureUser, 'paypal-receipt.eml');

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->merchantName)->toBe('Netflix BV');

    $row = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('parsed');
    expect($row->matcher_key)->toBe('paypal-receipt');
});
