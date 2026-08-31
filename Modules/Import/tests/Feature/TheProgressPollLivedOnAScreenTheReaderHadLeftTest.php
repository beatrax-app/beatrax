<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Ledger\Models\ImportRun;

// Chain resolution is dispatched by the confirm, and the confirm redirects to
// the results page. The progress surface used to live on the wizard the reader
// had just left, behind an `@if` on a property nothing ever set before the
// first render — so it could not appear, and its poll could never start.
function progressPollRunFor(User $user, string $suffix = ''): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/progress-poll'.$suffix.'.csv',
        'sha256' => hash('sha256', 'progress-poll-'.$suffix.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'status' => 'confirmed',
    ]);
}

function progressPollResolutionRow(User $user, JobRunStatus $status, ?string $lastError = null): void
{
    ChainResolutionRun::query()->create([
        'user_id' => $user->id,
        'status' => $status->value,
        'last_error' => $lastError,
    ]);
}

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importRun = progressPollRunFor($this->fixtureUser);
});

it('draws the progress surface on the results page while the resolver still has work', function (JobRunStatus $status): void {
    progressPollResolutionRow($this->fixtureUser, $status);

    $html = Livewire::test(ImportResults::class, ['id' => $this->importRun->id])
        ->assertSee('Resolving chains')
        ->html();

    expect($html)->toContain('wire:poll');
})->with([
    'pending' => JobRunStatus::Pending,
    'running' => JobRunStatus::Running,
]);

it('takes the progress surface away once chain resolution is done', function (): void {
    progressPollResolutionRow($this->fixtureUser, JobRunStatus::Complete);

    $html = Livewire::test(ImportResults::class, ['id' => $this->importRun->id])
        ->assertDontSee('Resolving chains')
        ->html();

    expect($html)->not->toContain('wire:poll');
});

it('draws no progress surface when the confirm dispatched no resolution at all', function (): void {
    $html = Livewire::test(ImportResults::class, ['id' => $this->importRun->id])
        ->assertDontSee('Resolving chains')
        ->html();

    expect($html)->not->toContain('wire:poll');
});

// The dashboard already banners a failed chain resolution across the whole app.
// A second notice here would compete with it, and would have to name the stored
// last_error — written as "<JobClass>: <first line of the message>", which for
// the crypto layer names an internal class and the reader's own user id.
it('leaves a failed resolution to the dashboard, and carries no job error to this page', function (): void {
    progressPollResolutionRow(
        $this->fixtureUser,
        JobRunStatus::Failed,
        'ResolveChainLinksJob: BlindIndexCodec: encryption is enabled for user 1',
    );

    $html = Livewire::test(ImportResults::class, ['id' => $this->importRun->id])
        ->assertDontSee('Resolving chains')
        ->html();

    expect($html)->not->toContain('ResolveChainLinksJob')
        ->and($html)->not->toContain('BlindIndexCodec')
        ->and($html)->not->toContain('wire:poll');
});

// A `failed_jobs.payload LIKE '%userId:N%'` lookup matches every user whose id
// carries this one as a digit prefix, so user 1 would read user 12's run.
it('reads the run by exact user_id, so one reader never observes another\'s', function (): void {
    $other = User::query()->create([
        'username' => 'progress-poll-other',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    progressPollResolutionRow($other, JobRunStatus::Running);

    Livewire::test(ImportResults::class, ['id' => $this->importRun->id])
        ->assertDontSee('Resolving chains');

    $this->actingAs($other);
    Livewire::test(ImportResults::class, ['id' => progressPollRunFor($other, '-other')->id])
        ->assertSee('Resolving chains');
});

it('keeps the substring payload lookup out of the component that polls', function (): void {
    $path = base_path('Modules/Import/Internal/Http/Livewire/ImportResults.php');
    expect(file_exists($path))->toBeTrue();

    $contents = (string) file_get_contents($path);
    // Comments stripped so a docblock or a WHY note may still name the pattern
    // it exists to keep out.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

    expect($stripped)->not->toMatch('/payload.*?like.*?userId/i')
        ->and($stripped)->not->toMatch("/'%userId:/")
        ->and($contents)->toContain('chain_resolution_runs');
});

it('does not leave a second, unreachable copy of the surface on the wizard', function (): void {
    foreach ([
        'Modules/Import/Internal/Http/Livewire/PreviewWizard.php',
        'Modules/Import/Resources/views/livewire/preview-wizard.blade.php',
    ] as $relative) {
        $contents = (string) file_get_contents(base_path($relative));
        expect($contents)->not->toContain('chainResolutionStatus')
            ->and($contents)->not->toContain('chain_resolution_runs');
    }
});
