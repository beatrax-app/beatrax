<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

function crqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function crqAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'crq '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function crqImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/crq.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function crqTx(
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $type,
    string $counterpartyName,
    string $postedAt,
    string $fingerprintSeed,
    int $rowIndex,
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => strtolower($counterpartyName),
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function crqSeedLink(
    DatabaseManager $db,
    User $user,
    int $fromId,
    int $toId,
    string $kind,
    string $state,
    string $confidence,
    string $resolver,
    array $evidence,
): int {
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => $state,
        'confidence' => $confidence,
        'resolver' => $resolver,
        'evidence' => json_encode($evidence),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $db->connection()->table('chain_links')->max('id');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = crqUser('chain-review-queue');
    $this->paypal = crqAccount($this->user, 'crq-paypal', 'paypal', 'PAYPAL');
    $this->asn = crqAccount($this->user, 'crq-asn', 'asn', 'NL93ASNB1111111111');
    $this->run = crqImportRun($this->user, str_repeat('a', 64));

    // One candidate to act on across the suite.
    $from = crqTx($this->user, $this->paypal, $this->run, -2500, 'expense', 'Spotify', '2026-05-10', 'crq1a', 1);
    $to = crqTx($this->user, $this->asn, $this->run, 2500, 'transfer_in', 'PayPal', '2026-05-10', 'crq1b', 2);
    $this->candidateId = crqSeedLink(
        $this->db, $this->user, (int) $from->id, (int) $to->id,
        'paypal_funding', 'candidate', '0.850', 'auto',
        ['signature_hash' => 'sig-1'],
    );
});

it('requires auth — GET /chains/review redirects to /login when unauthenticated', function (): void {
    $this->get(route('chains.review'))->assertRedirect('/login');
});

it('GET /chains/review for an authenticated user renders the page with candidates', function (): void {
    $this->actingAs($this->user)
        ->get(route('chains.review'))
        ->assertOk()
        ->assertSeeText('Review chains')
        ->assertSeeText('Confirm or reject candidate links');
});

it('renders the empty-state copy when no candidates exist', function (): void {
    ChainLink::query()->where('user_id', $this->user->id)->delete();

    $this->actingAs($this->user)
        ->get(route('chains.review'))
        ->assertOk()
        ->assertSeeText('Nothing to review')
        ->assertSeeText('Every link the resolver could pair is confirmed or rejected. New candidates appear here as imports land.');
});

it('renders each candidate row with the from/to counterparties + kind label + Confirm/Reject buttons', function (): void {
    $response = $this->actingAs($this->user)->get(route('chains.review'));

    $response->assertOk()
        ->assertSeeText('Spotify')
        ->assertSeeText('PayPal')
        ->assertSeeText('PayPal funding')
        ->assertSee('wire:click="confirm(\''.$this->candidateId.'\')"', false)
        ->assertSee('wire:click="reject(\''.$this->candidateId.'\')"', false);
});

it('Confirm button invokes ConfirmChainLink — chain_link.state becomes confirmed', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainReviewQueue::class)
        ->call('confirm', $this->candidateId);

    $link = ChainLink::query()->find($this->candidateId);
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('confirmed');
});

it('Reject button invokes RejectChainLink — chain_link.state becomes rejected', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainReviewQueue::class)
        ->call('reject', $this->candidateId);

    $link = ChainLink::query()->find($this->candidateId);
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('rejected');
});

it('renders the auto-promotion hint when confirmsRemaining === 1', function (): void {
    // Seed 2 confirmed links sharing the candidate's signature_hash so
    // the auto-promotion threshold computes confirmsRemaining === 1.
    $signature = 'sig-2';

    for ($i = 1; $i <= 2; $i++) {
        $f = crqTx($this->user, $this->paypal, $this->run, -1000 * $i, 'expense', 'Hint A'.$i, '2026-05-0'.$i, 'hint'.$i.'a', $i * 10);
        $t = crqTx($this->user, $this->asn, $this->run, 1000 * $i, 'transfer_in', 'Hint B'.$i, '2026-05-0'.$i, 'hint'.$i.'b', $i * 10 + 1);
        crqSeedLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'paypal_funding', 'confirmed', '1.000', 'auto', ['signature_hash' => $signature]);
    }

    $f3 = crqTx($this->user, $this->paypal, $this->run, -3000, 'expense', 'Hint A3', '2026-05-03', 'hint3a', 30);
    $t3 = crqTx($this->user, $this->asn, $this->run, 3000, 'transfer_in', 'Hint B3', '2026-05-03', 'hint3b', 31);
    crqSeedLink($this->db, $this->user, (int) $f3->id, (int) $t3->id, 'paypal_funding', 'candidate', '0.700', 'auto', ['signature_hash' => $signature]);

    $this->actingAs($this->user)
        ->get(route('chains.review'))
        ->assertSeeText('One more confirm and similar links auto-confirm.');
});

it('does NOT render the auto-promotion hint when confirmsRemaining > 1', function (): void {
    // The baseline candidate has no prior same-signature confirms, so
    // confirmsRemaining is 3.
    $this->actingAs($this->user)
        ->get(route('chains.review'))
        ->assertDontSeeText('One more confirm and similar links auto-confirm.');
});

it('sorts candidates by confidence DESC (highest confidence first)', function (): void {
    $f = crqTx($this->user, $this->paypal, $this->run, -5000, 'expense', 'HighConf', '2026-05-12', 'hc1', 50);
    $t = crqTx($this->user, $this->asn, $this->run, 5000, 'transfer_in', 'HighConfFunder', '2026-05-12', 'hc2', 51);
    crqSeedLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', '0.950', 'auto', ['signature_hash' => 'sig-hi']);

    $html = $this->actingAs($this->user)->get(route('chains.review'))->getContent();
    $highPos = strpos($html, 'HighConf');
    $lowPos = strpos($html, 'Spotify');

    expect($highPos)->not->toBeFalse();
    expect($lowPos)->not->toBeFalse();
    expect($highPos)->toBeLessThan($lowPos);
});

it('isolates by user — userA cannot see userB candidates', function (): void {
    $otherUser = crqUser('crq-other');
    $otherPaypal = crqAccount($otherUser, 'crq-other-pp', 'paypal', 'PAYPAL-OTHER');
    $otherRun = crqImportRun($otherUser, str_repeat('b', 64));

    $f = crqTx($otherUser, $otherPaypal, $otherRun, -7777, 'expense', 'OtherUserOnly', '2026-05-10', 'ou1', 1);
    $t = crqTx($otherUser, $otherPaypal, $otherRun, 7777, 'transfer_in', 'OtherUserFunder', '2026-05-10', 'ou2', 2);
    crqSeedLink($this->db, $otherUser, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', '0.900', 'auto', ['signature_hash' => 'sig-other']);

    $this->actingAs($this->user)
        ->get(route('chains.review'))
        ->assertOk()
        ->assertDontSeeText('OtherUserOnly');
});

it('ChainsServiceProvider uses View Factory contract — never the view() global helper', function (): void {
    $providerPath = base_path('Modules/Chains/Providers/ChainsServiceProvider.php');
    expect(file_exists($providerPath))->toBeTrue();
    $contents = (string) file_get_contents($providerPath);
    expect($contents)->toContain('Illuminate\Contracts\View\Factory');
    // Strip comments so legitimate PHPDoc references stay legal.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
    expect($stripped)->not->toMatch('/\bview\(\)/');
});

it('no view() global helper anywhere in Modules/Chains/ production code', function (): void {
    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Chains'), RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('/\.php$/', $path) !== 1) {
            continue;
        }
        // Skip test files (assertions reference view() pattern strings).
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/\bview\(\)/', $stripped) === 1) {
            $hits[] = $path;
        }
    }
    expect($hits)->toBe([], "view() global helper is forbidden in Modules/Chains production code. Offenders:\n  ".implode("\n  ", $hits));
});

it('loadMore advances the cursor for cursor-paginated review queue', function (): void {
    for ($i = 2; $i <= 6; $i++) {
        $f = crqTx($this->user, $this->paypal, $this->run, -1000 * $i, 'expense', 'Page'.$i, '2026-05-0'.$i, 'page'.$i.'a', $i * 20);
        $t = crqTx($this->user, $this->asn, $this->run, 1000 * $i, 'transfer_in', 'PageFn'.$i, '2026-05-0'.$i, 'page'.$i.'b', $i * 20 + 1);
        $confidence = number_format(0.7 + ($i * 0.01), 3, '.', '');
        crqSeedLink($this->db, $this->user, (int) $f->id, (int) $t->id, 'paypal_funding', 'candidate', $confidence, 'auto', ['signature_hash' => 'sig-pg-'.$i]);
    }

    Livewire::actingAs($this->user)
        ->test(ChainReviewQueue::class)
        ->call('loadMore', $this->candidateId, '0.850')
        ->assertSet('cursorId', $this->candidateId)
        ->assertSet('cursorConfidence', '0.850');
});
