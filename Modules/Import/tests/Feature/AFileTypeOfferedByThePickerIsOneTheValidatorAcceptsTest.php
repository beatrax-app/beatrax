<?php

declare(strict_types=1);

use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Migration\Internal\Http\Livewire\NewMigration;

/**
 * @return list<string> the extensions a file input's accept attribute offers
 */
function offeredUploadExtensions(string $bladePath): array
{
    $matched = preg_match('/accept="([^"]+)"/', (string) file_get_contents(base_path($bladePath)), $found);
    expect($matched)->toBe(1, "No accept attribute in {$bladePath}.");

    return array_values(array_map(
        static fn (string $extension): string => ltrim(trim($extension), '.'),
        explode(',', $found[1]),
    ));
}

/**
 * @param  array<string, mixed>  $rules
 * @return list<string> the extensions the validator will actually accept
 */
function acceptedUploadExtensions(array $rules): array
{
    /** @var list<string> $fileRules */
    $fileRules = $rules['file'];
    foreach ($fileRules as $rule) {
        if (is_string($rule) && str_starts_with($rule, 'extensions:')) {
            return explode(',', substr($rule, strlen('extensions:')));
        }
    }

    throw new RuntimeException('The upload rule set declares no extensions: rule.');
}

// A picker that offers a type its own validator refuses is a dead affordance:
// the file chooser greys out everything else, the reader picks the one thing it
// was invited to pick, and the form answers that the file is the wrong kind.
it('offers no upload type in the statement picker that the wizard then refuses', function (): void {
    $offered = offeredUploadExtensions('Modules/Import/Resources/views/livewire/upload-wizard.blade.php');
    $accepted = acceptedUploadExtensions((new UploadWizard)->rules());

    expect(array_values(array_diff($offered, $accepted)))->toBe([]);
});

it('offers no upload type in the migration picker that the wizard then refuses', function (): void {
    $offered = offeredUploadExtensions('Modules/Migration/Resources/views/livewire/new-migration.blade.php');
    $accepted = acceptedUploadExtensions((new NewMigration)->rules());

    expect(array_values(array_diff($offered, $accepted)))->toBe([]);
});
