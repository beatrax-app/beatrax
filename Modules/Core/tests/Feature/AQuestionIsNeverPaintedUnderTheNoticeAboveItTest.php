<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\CornerNotices;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Seen on the desktop app with both up at once: the inbox notice covered the
// receipt-conflict prompt's title and question, leaving "future conflicts?" and
// the two buttons showing. The press writes users.receipt_conflict_resolution,
// so the reader was configuring every future conflict from a sentence tail.

/**
 * @return list<RenderedMarkup> every element the page pins to the bottom-right corner
 */
function cornerPinnedIn(RenderedMarkup $page): array
{
    $pinned = [];

    foreach ($page->all('[class~="fixed"]') as $element) {
        if (CornerNotices::pinsToTheCorner($element->attribute('class'))) {
            $pinned[] = $element;
        }
    }

    return $pinned;
}

// An occupant rendered elsewhere in the page reaches the region through a
// teleport, and a <template>'s content is invisible to both querySelector and
// textContent — the lexer reads the source, so it can answer about one.
function cornerTeleportedText(string $response): string
{
    $carried = [];

    foreach (MarkupSource::elements($response, 'template') as $template) {
        if ($template->attribute('x-teleport') === CornerNotices::TELEPORT_TARGET) {
            $carried[] = $template->text();
        }
    }

    return implode(' ', $carried);
}

function cornerNoticeReauthInbox(User $owner): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'needs_reauth',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function cornerNoticeDescriptionConflict(User $owner, Account $account, string $stored, string $incoming): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $run = ImportRun::create([
        'user_id' => $owner->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/corner-notice-conflict.dat',
        'sha256' => str_pad('7', 64, 'c', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $transaction = Transaction::create([
        'user_id' => $owner->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Bol.com',
        'counterparty_normalized' => 'bol.com',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('7', 64, 'd', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $db->connection()->table('pending_enrichment_conflicts')->insert([
        'user_id' => $owner->id,
        'transaction_id' => $transaction->id,
        'field_name' => 'description',
        'stored_value' => json_encode($stored, JSON_THROW_ON_ERROR),
        'incoming_value' => json_encode($incoming, JSON_THROW_ON_ERROR),
        'incoming_source_format' => 'eml',
        'import_run_id' => $run->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 21:00:00'));

    $seeded = $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    cornerNoticeReauthInbox($this->fixtureUser);
    cornerNoticeDescriptionConflict(
        $this->fixtureUser,
        $seeded['account'],
        'Bol.com via PayPal',
        'Bol.com - Order #DEMO-1234 (PayPal)',
    );

    $this->response = (string) $this->get('/')->getContent();
    $this->page = RenderedMarkup::of($this->response);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('draws both the notice and the prompt when both are waiting', function (): void {
    $region = $this->page->firstOrFail('#'.CornerNotices::REGION_ID);

    expect($region->text())->toContain(Lang::get('receipts::messages.conflict.title'))
        ->and(cornerTeleportedText($this->response))->toContain(Lang::get('core::dashboard.reauth.title'));
});

// The whole defect in one number. Two boxes measuring their own gap from the
// same screen edge is not a stack; it is two things drawn at one address.
it('lets one element and no other pin itself to the corner they share', function (): void {
    $pinned = cornerPinnedIn($this->page);

    $addresses = array_map(
        static fn (RenderedMarkup $element): string => (string) $element->attribute('class'),
        $pinned,
    );

    expect($addresses)->toHaveCount(1, implode("\n", [
        'These elements each pin themselves to the bottom-right corner, so whichever the',
        'document draws last covers the one before it:',
        ...$addresses,
    ]));

    expect($pinned[0]->attribute('id'))->toBe(CornerNotices::REGION_ID);
});

// A decision control is only answerable beside the question it answers, and the
// press behind these two writes a preference every later conflict is resolved by.
it('keeps the question in the same region as the buttons that answer it', function (): void {
    $region = $this->page->firstOrFail('#'.CornerNotices::REGION_ID);

    expect($region->text())->toContain('Should Beatrax prefer receipts for future conflicts?')
        ->and($region->text())->toContain('Bol.com - Order #DEMO-1234 (PayPal)')
        ->and($region->text())->toContain('Bol.com via PayPal')
        ->and($region->has('[wire\:click="useReceipt"]'))->toBeTrue()
        ->and($region->has('[wire\:click="keepStatement"]'))->toBeTrue();
});

// The region stacks its occupants in flow, which is the only arrangement in
// which two of them cannot be drawn at one address.
it('lays the region out as a column with a gap between its occupants', function (): void {
    $region = $this->page->firstOrFail('#'.CornerNotices::REGION_ID);
    $classes = (string) $region->attribute('class');

    expect($classes)->toContain('flex')
        ->and($classes)->toContain('flex-col-reverse')
        ->and($classes)->toContain('gap-');
});

// A notice a reader may put away and a question they may not are different
// things, and the region must not quietly level them: the prompt offers no way
// out because answering it IS the way out, and the notice keeps its own.
it('keeps the notice dismissible and leaves the prompt without a way past it', function (): void {
    $region = $this->page->firstOrFail('#'.CornerNotices::REGION_ID);

    expect(cornerTeleportedText($this->response))->toContain(Lang::get('core::dashboard.reauth.dismiss'))
        ->and($region->has('[wire\:click="dismissReauthToast"]'))->toBeFalse()
        ->and($region->count('button'))->toBe(2);
});
