<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;

/*
 * `/inboxes?reconnect={inbox_id}` query-param hand-off.
 *
 * The SystemAlertsBanner Reconnect link routes the user back to
 * /inboxes with a `?reconnect={id}` query param. InboxesPage::mount
 * reads the param, looks the inbox up via InboxQuery::findForUser
 * (which scopes by current user), and dispatches the
 * `oauth-client-wizard:open` Livewire event with the provider + inbox
 * id so the modal opens against the existing inbox row. Cross-user
 * attempts and missing inbox ids are silently ignored (404-not-403
 * contract; the dispatch never fires).
 *
 * Successful re-consent acknowledges the active
 * oauth_reconsent_required system_alerts row for that inbox; the test
 * simulates the modal firing `oauth-client-wizard:reconsented` and
 * asserts the row is acknowledged.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'iprp-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userB = User::query()->create([
        'username' => 'iprp-b',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $now = CarbonImmutable::parse('2026-05-20 09:00:00')->toDateTimeString();
    $this->inboxAId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->userA->id,
        'provider' => 'gmail',
        'email' => 'iprp-a@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('dispatches oauth-client-wizard:open with provider+inboxId when the page mounts with ?reconnect={id} owned by the current user', function (): void {
    Livewire::actingAs($this->userA)
        ->withQueryParams(['reconnect' => (string) $this->inboxAId])
        ->test(InboxesPage::class)
        ->assertDispatched(
            'oauth-client-wizard:open',
            provider: 'gmail',
            inboxId: $this->inboxAId,
        );
});

it('does NOT dispatch when the ?reconnect id belongs to a different user (cross-user 404)', function (): void {
    Livewire::actingAs($this->userB)
        ->withQueryParams(['reconnect' => (string) $this->inboxAId])
        ->test(InboxesPage::class)
        ->assertNotDispatched('oauth-client-wizard:open');
});

it('does NOT dispatch when the ?reconnect id does not exist at all', function (): void {
    Livewire::actingAs($this->userA)
        ->withQueryParams(['reconnect' => '99999'])
        ->test(InboxesPage::class)
        ->assertNotDispatched('oauth-client-wizard:open');
});

it('does NOT dispatch when no ?reconnect query param is supplied', function (): void {
    Livewire::actingAs($this->userA)
        ->test(InboxesPage::class)
        ->assertNotDispatched('oauth-client-wizard:open');
});

it('acknowledges the active oauth_reconsent_required alert when the modal signals oauth-client-wizard:reconsented', function (): void {
    // Seed an active re-consent alert for inbox A.
    $alertId = (int) $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userA->id,
        'kind' => 'oauth_reconsent_required',
        'severity' => 'warning',
        'message' => 'Reconnect your Gmail',
        'metadata' => json_encode(['inbox_id' => $this->inboxAId, 'provider' => 'gmail']),
        'created_at' => '2026-05-20 09:00:00',
        'acknowledged_at' => null,
    ]);

    Livewire::actingAs($this->userA)
        ->test(InboxesPage::class)
        ->dispatch('oauth-client-wizard:reconsented', inboxId: $this->inboxAId);

    /** @var SystemAlert $row */
    $row = SystemAlert::query()->findOrFail($alertId);
    expect($row->acknowledged_at)->not->toBeNull();
});

it('OAuthClientWizardModal::open() stores the inbox id when supplied (backward-compatible signature)', function (): void {
    Livewire::actingAs($this->userA)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail', $this->inboxAId)
        ->assertSet('provider', 'gmail')
        ->assertSet('reconnectInboxId', $this->inboxAId);
});

it('OAuthClientWizardModal::open() leaves reconnectInboxId null when no inbox id is supplied (Plan 03b call-site stays working)', function (): void {
    Livewire::actingAs($this->userA)
        ->test(OAuthClientWizardModal::class)
        ->call('open', 'gmail')
        ->assertSet('provider', 'gmail')
        ->assertSet('reconnectInboxId', null);
});

it('does not acknowledge alerts for a different inbox owned by the same user', function (): void {
    $otherInboxId = (int) $this->db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->userA->id,
        'provider' => 'microsoft',
        'email' => 'iprp-a-other@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => '2026-05-20 09:00:00',
        'updated_at' => '2026-05-20 09:00:00',
    ]);
    $otherAlertId = (int) $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userA->id,
        'kind' => 'oauth_reconsent_required',
        'severity' => 'warning',
        'message' => 'Reconnect your Outlook',
        'metadata' => json_encode(['inbox_id' => $otherInboxId, 'provider' => 'microsoft']),
        'created_at' => '2026-05-20 09:00:00',
        'acknowledged_at' => null,
    ]);

    Livewire::actingAs($this->userA)
        ->test(InboxesPage::class)
        ->dispatch('oauth-client-wizard:reconsented', inboxId: $this->inboxAId);

    /** @var SystemAlert $row */
    $row = SystemAlert::query()->findOrFail($otherAlertId);
    expect($row->acknowledged_at)->toBeNull();
});
