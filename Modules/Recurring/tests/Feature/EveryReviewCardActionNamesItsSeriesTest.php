<?php

declare(strict_types=1);

// Read off an iPhone 12 mini from the tree VoiceOver uses, on a review queue
// with two pending cards: 13 buttons, of which "Snooze" appeared twice and
// "Edit name" twice, announced identically. Select, Approve and Reject on the
// same card each carry the series id; these two were left off that convention,
// so a screen reader could not tell which card's Snooze it had landed on.

// select_aria is gone: the checkbox is a labelled row now and takes the
// merchant as its accessible name, which disambiguates the card better than
// its row id and matches the words on screen. The four buttons have no visible
// name of their own, so they keep the id.
$actions = ['approve_aria', 'reject_aria', 'snooze_aria', 'edit_name_aria'];

it('gives every action on a review card an aria-label naming its series', function () use ($actions): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php')
    );

    // Collected rather than asserted one by one: toContain takes a LIST of
    // needles, so a second argument is another needle, never a message.
    $unbound = [];

    foreach ($actions as $key) {
        if (! str_contains($blade, "Lang::get('recurring::review.{$key}', ['id' => \$row->seriesId])")) {
            $unbound[] = $key;
        }
    }

    expect($unbound)->toBe([], 'Not bound to the row series id: '.implode(', ', $unbound));
});

it('carries those labels in every language the app offers', function () use ($actions): void {
    $locales = array_values(array_filter(
        scandir(base_path('Modules/Recurring/Resources/lang')) ?: [],
        static fn (string $entry): bool => ! str_starts_with($entry, '.'),
    ));

    expect($locales)->toHaveCount(26);

    $missing = [];

    foreach ($locales as $locale) {
        /** @var array<string, string> $strings */
        $strings = require base_path("Modules/Recurring/Resources/lang/{$locale}/review.php");

        foreach ($actions as $key) {
            if (! isset($strings[$key]) || ! str_contains($strings[$key], ':id')) {
                $missing[] = "{$locale}.{$key}";
            }
        }
    }

    expect($missing)->toBe([], 'Missing or unplaceheld aria strings: '.implode(', ', $missing));
});
