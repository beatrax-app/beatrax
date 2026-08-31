<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The rule form modal is mounted by the shared layout, so it answers on every
// authenticated page. Its two per-row error maps are written only by the
// validator and echoed straight out by the Blade; unlocked, a snapshot replay
// putting an array in a row's slot reached htmlspecialchars() and 500'd.

function ruleErrorsSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"categorization.rule-form-modal"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the rule form modal on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function ruleErrorsTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rule-row-errors',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('refuses a condition row error replaced by an array', function (): void {
    $snapshot = ruleErrorsSnapshot($this->get('/rules')->assertOk()->getContent());

    ruleErrorsTamper($snapshot, ['conditionErrors.0' => ['zzz']])->assertForbidden();
});

it('refuses an action row error replaced by an array', function (): void {
    $snapshot = ruleErrorsSnapshot($this->get('/rules')->assertOk()->getContent());

    ruleErrorsTamper($snapshot, ['actionErrors.0' => ['zzz']])->assertForbidden();
});

it('throws rather than accepting a write to either row error map', function (): void {
    Livewire::test(RuleFormModal::class)->set('conditionErrors', [0 => ['zzz']]);
})->throws(CannotUpdateLockedPropertyException::class);
