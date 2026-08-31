<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// Install-on-next-launch and Skip-this-version both read metadata.latestVersion
// and fall back to a bare acknowledge when it is absent. The alert the demo
// seeder wrote carried only its seed key, so on the build anyone actually opens
// both buttons silently dismissed the banner and the release-notes link — which
// already guards on the version — never appeared at all.

beforeEach(function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $this->demoUser = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($this->demoUser);
});

function seededAvailableAlert(int $userId): SystemAlert
{
    return SystemAlert::query()
        ->where('user_id', $userId)
        ->where('kind', UpdateAlertKind::Available->value)
        ->whereNull('acknowledged_at')
        ->firstOrFail();
}

it('gives every seeded update alert the version its buttons act on', function (): void {
    $alerts = SystemAlert::query()
        ->where('user_id', $this->demoUser->id)
        ->where('kind', UpdateAlertKind::Available->value)
        ->get();

    expect($alerts)->not->toBeEmpty();

    foreach ($alerts as $alert) {
        $metadata = is_array($alert->metadata) ? $alert->metadata : [];
        expect($metadata['latestVersion'] ?? null)->toBeString()->not->toBe('');
    }
});

it('asks for the install the button offers', function (): void {
    Event::fake([UpdateInstallRequested::class]);
    $alert = seededAvailableAlert($this->demoUser->id);

    Livewire::test(SystemAlertsBanner::class)->call('install', $alert->id);

    Event::assertDispatched(UpdateInstallRequested::class);
});

it('remembers the version the skip button skipped', function (): void {
    $alert = seededAvailableAlert($this->demoUser->id);
    $metadata = is_array($alert->metadata) ? $alert->metadata : [];

    Livewire::test(SystemAlertsBanner::class)->call('skipVersion', $alert->id);

    $stored = DB::table('user_preferences')->where('user_id', $this->demoUser->id)->value('skipped_update_versions');

    expect(json_decode((string) $stored, true))->toContain($metadata['latestVersion']);
});
