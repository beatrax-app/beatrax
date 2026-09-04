<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\RestoreRefusal;
use Modules\Core\Public\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Support\Lang;
use Tests\Helpers\CheapKdfCost;

beforeEach(function (): void {
    Storage::fake('livewire-tmp');
    $this->user = User::query()->create([
        'username' => 'restore-locale-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function restoreErrorIn(string $locale, UploadedFile $file, string $passphrase): string
{
    App::setLocale($locale);

    $component = Livewire::test(EncryptedBackupRestore::class)
        ->set('backup', $file)
        ->set('passphrase', $passphrase)
        ->set('confirmation', EncryptedBackupRestore::CONFIRM_PHRASE)
        ->call('restore');

    /** @var EncryptedBackupRestore $instance */
    $instance = $component->instance();

    return $instance->error;
}

it('says an unsupported build in the language the reader is reading', function (): void {
    $file = UploadedFile::fake()->create('backup.enc', 4);

    $dutch = restoreErrorIn('nl', $file, 'secret');
    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('core::backup.errors.restore_not_supported'));

    expect(restoreErrorIn('en', $file, 'secret'))->not->toBe($dutch)
        ->and($dutch)->not->toContain('SQLite build');
});

it('says a wrong passphrase in the language the reader is reading, and never touches the live file', function (): void {
    $base = sys_get_temp_dir().'/rl-'.bin2hex(random_bytes(5));
    $live = $base.'-live.sqlite';
    $plain = $base.'-backup.sqlite';
    $enc = $base.'-backup.sqlite.enc';

    $pdo = new PDO('sqlite:'.$live);
    $pdo->exec('CREATE TABLE marker (val TEXT)');
    $pdo->exec("INSERT INTO marker (val) VALUES ('ORIGINAL')");
    (new PDO('sqlite:'.$plain))->exec('CREATE TABLE marker (val TEXT)');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($plain, $enc, 'right-pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        $upload = UploadedFile::fake()->createWithContent('backup.enc', (string) file_get_contents($enc));
        $dutch = restoreErrorIn('nl', $upload, 'wrong-pw');

        $upload = UploadedFile::fake()->createWithContent('backup.enc', (string) file_get_contents($enc));
        $english = restoreErrorIn('en', $upload, 'wrong-pw');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    App::setLocale('nl');
    expect($dutch)->toBe(Lang::get('core::backup.errors.restore_wrong_passphrase'))
        ->and($english)->not->toBe($dutch)
        ->and($dutch)->not->toContain('Decryption failed');

    expect((string) (new PDO('sqlite:'.$live))->query('SELECT val FROM marker')->fetchColumn())->toBe('ORIGINAL');

    foreach ([$live, $plain, $enc] as $f) {
        @unlink($f);
    }
});

it('resolves a refusal line in all twenty-six shipped locales', function (): void {
    $unresolved = [];

    foreach (glob(base_path('Modules/Core/Resources/lang/*/backup.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        App::setLocale($locale);

        foreach (RestoreRefusal::cases() as $refusal) {
            $line = $refusal->sentence();
            if ($line === '' || str_contains($line, 'core::backup')) {
                $unresolved[] = $locale.'/'.$refusal->value;
            }
        }
    }

    expect($unresolved)->toBe([]);
});
