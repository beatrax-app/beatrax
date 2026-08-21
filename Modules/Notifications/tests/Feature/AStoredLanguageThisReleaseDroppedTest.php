<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;

// `users.locale` can hold a code this release does not ship: a row merged from
// a device on a newer version, a restored backup, or a language dropped since
// it was chosen. Everywhere else that code is filtered out and the reader gets
// English. This is the one seam that used to take it at face value.

function droppedLanguageUser(string $storedLocale): User
{
    $user = User::query()->create([
        'username' => 'dropped-language-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('dropped-language-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => null,
    ]);

    // Written past the model, the way a sync merge or a restore writes it —
    // every UI path that reaches this column validates against Locale::codes()
    // first, so a code outside it can only arrive from somewhere else.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('users')->where('id', $user->id)->update(['locale' => $storedLocale]);

    return $user;
}

function droppedLanguageRender(User $user): string
{
    /** @var NotificationCopyRenderer $renderer */
    $renderer = app(NotificationCopyRenderer::class);

    return $renderer->forUser(
        $user->id,
        static fn (): string => CarbonImmutable::create(2026, 3, 4)->translatedFormat('l j F'),
    );
}

it('renders a notification date in English when the stored language is not one we ship', function (): void {
    app()->setLocale(Locale::DEFAULT);
    $user = droppedLanguageUser('ru');

    expect(droppedLanguageRender($user))->toBe('Wednesday 4 March');
});

it('still renders a notification date in a stored language we do ship', function (): void {
    app()->setLocale(Locale::DEFAULT);
    $user = droppedLanguageUser('nl');

    expect(droppedLanguageRender($user))->toBe('woensdag 4 maart');
});

// The dropped code used to reach Carbon unfiltered, where an unknown locale is
// a silent no-op: the dates kept whatever language the caller was already in
// while the sentence around them fell back to English.
it('does not leave the reading language on the dates of a row it cannot read', function (): void {
    app()->setLocale('nl');
    $user = droppedLanguageUser('ru');

    expect(droppedLanguageRender($user))->toBe('Wednesday 4 March');
});

it('puts the caller s own language back on the way out', function (): void {
    app()->setLocale('nl');
    $user = droppedLanguageUser('ru');

    droppedLanguageRender($user);

    expect(CarbonImmutable::create(2026, 3, 4)->translatedFormat('F'))->toBe('maart')
        ->and(app('translator')->getLocale())->toBe('nl');
});
