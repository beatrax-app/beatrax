<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Fmt;
use Modules\Shell\Internal\Http\Livewire\AppSidebar;

uses(RefreshDatabase::class);

// Thirteen of the rail's fourteen badges go through Fmt::compactCount, which
// shortens a four-digit count so it cannot stretch the rail and writes the
// tenth with the reader's own decimal mark. Triage printed the integer.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rail-badge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

function railBadgeUnknownCounterparties(int $userId, int $count): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'type' => 'unknown',
            'slug' => 'rail-badge-'.$i,
            'display_name' => 'Rail Badge '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('counterparties')->insert($chunk);
    }
}

// The Triage row's own badge, not whichever badge on the rail happens to
// carry the same number: the Counterparties row counts the same rows.
function railBadgeTriageText(string $html): string
{
    $row = strstr($html, '<a href="'.htmlspecialchars(Destination::Triage->url(), ENT_QUOTES).'"');
    $badge = strstr((string) $row, 'class="side-badge"');
    $text = strstr((string) $badge, '>');

    return trim(strtok(substr((string) $text, 1), '<') ?: '');
}

it('shortens the triage badge the way it shortens every other one', function (): void {
    railBadgeUnknownCounterparties($this->user->id, 1234);

    $html = (string) Livewire::actingAs($this->user)->test(AppSidebar::class)->html();

    expect(Fmt::compactCount(1234))->toBe('1.2K')
        ->and(railBadgeTriageText($html))->toBe('1.2K');
});

// The same badge in a locale whose decimal mark is the comma: compactCount is
// where that is known, and an integer echoed past it carries no locale at all.
// The abbreviation is the locale's as much as the decimal mark is. Polish
// shortens a thousand to "tys." and German to nothing at all, and a badge that
// wrote "k" at both was showing an English abbreviation to twenty-five readers.
it('shortens the badge with the word the reader language uses', function (): void {
    railBadgeUnknownCounterparties($this->user->id, 1234);

    app()->setLocale('pl');

    $html = (string) Livewire::actingAs($this->user)->test(AppSidebar::class)->html();

    expect(railBadgeTriageText($html))->toBe("1,2\u{00A0}tys.");
});

// CLDR gives German no short form below a million, so the figure is written
// out. A shortening that invented one would be putting a word in front of a
// reader that their language does not use.
it('writes the count out where the reader language has no short form', function (): void {
    railBadgeUnknownCounterparties($this->user->id, 1234);

    app()->setLocale('de');

    $html = (string) Livewire::actingAs($this->user)->test(AppSidebar::class)->html();

    expect(railBadgeTriageText($html))->toBe('1.234');
});

it('writes the shortened triage count in the reader own mark', function (): void {
    railBadgeUnknownCounterparties($this->user->id, 1234);

    app()->setLocale('nl');

    $html = (string) Livewire::actingAs($this->user)->test(AppSidebar::class)->html();

    expect(Fmt::compactCount(1234))->toBe('1,2K')
        ->and(railBadgeTriageText($html))->toBe('1,2K');
});
