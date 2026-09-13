<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;

uses(RefreshDatabase::class);

// Three of the summary line's four values went through Lang and the fourth was
// the enum's stored spelling, so a Dutch reader read
// "herinneringen aan · hints aan · overzicht weekly · besparingen uit".
// The word is not compared to a literal here: the cadence select directly above
// this panel renders its options from the same keys, so the assertion is that
// the two agree -- a rewrite of either takes the other with it.

function cadenceLineReaderUser(string $locale): User
{
    return User::query()->create([
        'username' => 'cadence-line-'.$locale,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

function cadenceLineDeviceRow(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'The other one',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

function cadenceLinePreferenceRow(DatabaseManager $db, int $userId, string $deviceId, DigestCadence $cadence): void
{
    $db->connection()->table('notification_preferences')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'reminders_enabled' => 1,
        'budget_nudges_enabled' => 1,
        'digest_cadence' => $cadence->value,
        'savings_prompts_enabled' => 0,
        'reminder_lead_days' => 3,
        'quiet_hours_enabled' => 0,
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
        'hide_details' => 0,
        'created_at' => '2026-07-01 10:00:00',
        'updated_at' => '2026-07-01 10:00:00',
    ]);
}

it('names another device\'s cadence with the word the cadence control shows, in every locale', function (string $locale, DigestCadence $cadence): void {
    $user = cadenceLineReaderUser($locale);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    cadenceLineDeviceRow($db, (int) $user->id, 'self-device', isSelf: true);
    cadenceLineDeviceRow($db, (int) $user->id, 'peer-device', isSelf: false);
    cadenceLinePreferenceRow($db, (int) $user->id, 'peer-device', $cadence);

    $this->actingAs($user);
    $this->app->setLocale($locale);

    $html = Livewire::test(NotificationsSettingsSection::class)->html();
    $panel = substr($html, (int) strpos($html, 'other-device-preferences'));

    $chosenWord = Lang::get('notifications::settings.digest.'.$cadence->value);

    expect($chosenWord)->not->toBe($cadence->value);
    expect($panel)->toContain($chosenWord);
    expect($panel)->not->toContain(' '.$cadence->value.' ');
})->with([
    ['nl', DigestCadence::Weekly],
    ['nl', DigestCadence::Daily],
    ['de', DigestCadence::Weekly],
    ['fr', DigestCadence::Off],
]);
