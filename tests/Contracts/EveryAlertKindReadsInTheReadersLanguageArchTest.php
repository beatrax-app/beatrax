<?php

declare(strict_types=1);

use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\UpdateAlertKind;

// The banner picks its copy per alert kind and falls through to the row's own
// `message` column for a kind it does not know. That column holds whatever
// English the writer put there, so a missing case is not a missing case -- it
// is English on a Dutch screen, in a banner that at critical severity is
// telling the reader their tokens may be unredacted. All three OAuth kinds
// were missing, next to a backup alert that read correctly in Dutch.

/** @return array<string, string> kind value => how the blade must reference it */
function alertKindsTheBannerMustRender(): array
{
    $kinds = [];

    foreach ([BackupAlertKind::class, OAuthAlertKind::class, UpdateAlertKind::class] as $enum) {
        $short = substr((string) strrchr($enum, '\\'), 1);

        foreach ($enum::cases() as $case) {
            $kinds[(string) $case->value] = $short.'::'.$case->name.'->value';
        }
    }

    // HealthCheckListener writes these two as literals rather than through an
    // enum, so the blade matches them as literals too.
    $kinds['wal_mode_missing'] = "@case ('wal_mode_missing')";
    $kinds['synchronous_misconfigured'] = "@case ('synchronous_misconfigured')";

    return $kinds;
}

it('gives every alert kind the app can write its own localised case', function (): void {
    $blade = (string) file_get_contents(base_path(
        'Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php',
    ));

    $unhandled = [];
    foreach (alertKindsTheBannerMustRender() as $kind => $reference) {
        if (! str_contains($blade, $reference)) {
            $unhandled[] = $kind;
        }
    }

    expect($unhandled)->toBe([], 'These kinds fall through to the raw message column, so they '
        .'render in English whatever the reader chose: '.implode(', ', $unhandled));
});

// A case that resolves no copy is the same defect one step later, so the key
// each case names has to exist -- and in every locale, not only in English.
it('backs each of those cases with a key every locale carries', function (): void {
    $blade = (string) file_get_contents(base_path(
        'Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php',
    ));

    expect(preg_match_all("/core::alerts\.messages\.([a-z0-9_]+)/", $blade, $matches))->toBeGreaterThan(0);

    $keys = array_values(array_unique($matches[1]));
    sort($keys);

    $missing = [];
    foreach ((array) glob(base_path('Modules/Core/Resources/lang/*/alerts.php')) as $file) {
        /** @var array{messages?: array<string, string>} $translations */
        $translations = require $file;
        $locale = basename(dirname((string) $file));

        foreach ($keys as $key) {
            $value = $translations['messages'][$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $locale.'/'.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});
