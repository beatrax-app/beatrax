<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Tests\Helpers\UploadIsolation;

// Symfony's YAML ParseException quotes the offending source line into its own
// message, and the file it was reading is the reader's upload.
const ALIAS_QUOTED_BACK_IBAN = 'NL91ABNA0417164300';

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->reader = User::query()->create([
        'username' => 'alias-quoted-back',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('does not write the line of the alias file that would not parse into the log', function (): void {
    $records = [];
    Log::listen(static function (object $record) use (&$records): void {
        $records[] = $record;
    });

    // Symfony reports this one as `Indentation problem at line 4 (near " …")`,
    // and the near-clause is the file's own bytes.
    $body = "entries:\n  - pattern: ALBERT HEIJN\n    name: Albert Heijn\n   iban: ".ALIAS_QUOTED_BACK_IBAN."\n";

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('aliases.yaml', $body))
        ->call('parseUpload');

    $written = '';
    foreach ($records as $record) {
        $written .= json_encode([$record->message, $record->context], JSON_THROW_ON_ERROR);
    }

    expect($written)->not->toBe('', 'nothing was logged at all, so this asserts about a silence rather than a redaction');
    expect($written)->not->toContain(ALIAS_QUOTED_BACK_IBAN)
        ->and($written)->not->toContain('Indentation problem');
});
