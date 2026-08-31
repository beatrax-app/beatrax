<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// ?force=1 is what the Settings "re-run setup tour" link uses.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rerun-wizard',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);

    // All done, so the resolver returns its empty-string sentinel.
    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->update(['status' => 'done']);
});

it('renders the terminal step when every wizard step is already done and no force flag is set', function (): void {
    // It used to redirect. $this->redirect() from mount() skips the render, and
    // on the phone runtime that left the layout painted around an empty slot.
    // @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-livewire-redirect-from-mount
    $response = $this->get(route('setup'));

    $response->assertOk();

    expect((string) $response->getContent())->toContain('onboarding.steps.done-step');
});

function reRunNonPendingRows(int $userId): int
{
    return DB::table('wizard_progress')
        ->where('user_id', $userId)
        ->where('status', '!=', 'pending')
        ->count();
}

it('resets every wizard_progress row and re-enters from welcome from the signed Settings link', function (): void {
    expect(reRunNonPendingRows($this->user->id))->toBe(9);

    $response = $this->get(URL::signedRoute('setup', ['force' => 1], absolute: false));

    $response->assertOk();
    expect((string) $response->getContent())->toContain('get Beatrax to know your money');

    expect(reRunNonPendingRows($this->user->id))->toBe(0);
});

// Nine rows wiped by a bookmarkable GET with no token and no confirmation: the
// only thing standing between that and a cross-site page was one env-overridable
// cookie attribute.
it('ignores an unsigned ?force=1 and leaves every wizard_progress row alone', function (): void {
    expect(reRunNonPendingRows($this->user->id))->toBe(9);

    $this->get(route('setup', ['force' => 1]))->assertOk();

    expect(reRunNonPendingRows($this->user->id))->toBe(9);
});

it('ignores a ?force=1 carrying a signature minted for a different URL', function (): void {
    parse_str((string) parse_url(URL::signedRoute('setup', ['force' => 0], absolute: false), PHP_URL_QUERY), $query);
    $signature = $query['signature'] ?? '';

    $this->get(route('setup', ['force' => 1, 'signature' => is_string($signature) ? $signature : '']))->assertOk();

    expect(reRunNonPendingRows($this->user->id))->toBe(9);
});
