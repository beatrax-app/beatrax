<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Tests\Helpers\UploadIsolation;

// The wizard opened on a bank: the first select listed ASN, ICS and PayPal and
// defaulted to ASN, so the app read as an ASN app that tolerated other banks.
// Which institutions are covered is a question for the website; the wizard asks
// only what shape of file the reader is holding.
/** @return list<string> the names the first step must never carry */
function firstStepVendorNames(): array
{
    return ['ASN', 'ICS', 'PayPal', 'N26', 'Revolut', 'ING'];
}

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('offers a file type on the first step, never the name of a bank or a card issuer', function (): void {
    $html = Livewire::test(UploadWizard::class)->html();

    $select = PatternScan::first('#<select[^>]*id="importType".*?</select>#s', $html);
    expect($select)->not->toBe([]);

    $options = PatternScan::sets('/<option value="([^"]*)"[^>]*>([^<]*)</', $select[0]);

    expect(array_column($options, 1))
        ->toBe(array_column(ImportType::cases(), 'value'));

    $named = [];
    foreach ($options as [, $value, $label]) {
        foreach (firstStepVendorNames() as $vendor) {
            if (preg_match('/\b'.preg_quote($vendor, '/').'\b/', $value.' '.$label) === 1) {
                $named[] = $vendor.' in "'.$label.'"';
            }
        }
    }

    expect($named)->toBe([], 'The first step names a vendor: '.implode(', ', $named));
});

it('says what shape a file is in every locale, rather than who issued it', function (): void {
    $offenders = [];

    foreach (glob(base_path('Modules/Import/Resources/lang/*/upload.php')) ?: [] as $file) {
        /** @var array{type_label: string, types: array<string, string>, subtitle: string} $lines */
        $lines = require $file;

        $copy = [$lines['type_label'], $lines['subtitle'], ...array_values($lines['types'])];
        foreach ($copy as $line) {
            foreach (firstStepVendorNames() as $vendor) {
                if (preg_match('/\b'.preg_quote($vendor, '/').'\b/', $line) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file).': "'.$line.'"';
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], "First-step copy naming a vendor:\n  ".implode("\n  ", $offenders));
});

it('accepts exactly the import types the enum names, and no leftover issuer', function (): void {
    /** @var UploadWizard $instance */
    $instance = Livewire::test(UploadWizard::class)->instance();
    $rule = $instance->rules()['importType'];

    /** @var ValidatorFactory $validators */
    $validators = app(ValidatorFactory::class);

    foreach (ImportType::cases() as $type) {
        expect($validators->make(['importType' => $type->value], ['importType' => $rule])->passes())
            ->toBeTrue($type->value.' is a case the enum names but the rule refuses.');
    }

    // The issuer that used to be an import type of its own; it is a CSV preset
    // now, and the rule has to have stopped naming it.
    expect($validators->make(['importType' => 'asn'], ['importType' => $rule])->fails())->toBeTrue();
});

it('reaches every format the wizard supports from one of its import types', function (): void {
    $reachable = [];
    foreach (ImportType::cases() as $type) {
        $reachable = [...$reachable, ...$type->formats()];
    }
    sort($reachable);

    $supported = UploadWizard::SUPPORTED_FORMATS;
    sort($supported);

    expect($reachable)->toBe($supported);
});
