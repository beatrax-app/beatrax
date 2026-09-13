<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Tests\Support\ActualFixtureBuilder;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// StoredCopy::read() decides "our words or theirs" by the value's first bytes.
// That test was written for a person typing into a form, and it holds for one.
// It does not hold for a column whose writer copies a string out of a file
// somebody else made: the envelope is public, unsigned, and an Actual export
// can name a schedule or a saved report with it. The preview then reads a
// shipped Beatrax sentence off the reader's own screen, in the reader's own
// language, chosen by whoever wrote the file.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'copy-shaped-name-user',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
    ]);

    $this->zipPath = sys_get_temp_dir().'/actual-copy-shaped-'.uniqid('', true).'.zip';
    ActualFixtureBuilder::build($this->zipPath, ActualFixtureBuilder::COPY_SHAPED_NAMES);
});

afterEach(function (): void {
    @unlink($this->zipPath);
});

/** @return list<string> */
function copyShapedNameLabels(User $user, string $zipPath): array
{
    $run = app(StartMigrationRun::class)->__invoke(
        $user,
        'actual',
        MigrationFixturePaths::extractZip($zipPath),
        'actual-export.zip',
    );

    $summary = app(PreviewSummaryBuilder::class)->forRun($run->id, $user);

    return array_values(array_map(
        static fn (array $item): string => (string) $item['label'],
        $summary->unmapped['extra']['items'] ?? [],
    ));
}

it('does not speak a shipped line an imported file asked it to speak', function (): void {
    $spoken = Lang::get('migration::unmapped.label.budget_file_currency');

    // The control: the key the fixture's name names really does resolve, so a
    // preview that does not print it is refusing rather than failing to find
    // it. Two rows carry the name — the schedule and the saved report — and
    // the currency line has one legitimate speaker of its own, which is why
    // the count below is asserted rather than mere absence.
    expect($spoken)->not->toBe('migration::unmapped.label.budget_file_currency');

    $labels = copyShapedNameLabels($this->user, $this->zipPath);

    expect(count(array_filter($labels, static fn (string $label): bool => $label === $spoken)))->toBe(0);
});

it('shows the name the file carried as the text it is', function (): void {
    $labels = copyShapedNameLabels($this->user, $this->zipPath);

    $carrying = array_values(array_filter(
        $labels,
        static fn (string $label): bool => str_contains($label, ActualFixtureBuilder::COPY_SHAPED_NAME),
    ));

    expect($carrying)->toHaveCount(2);
});
