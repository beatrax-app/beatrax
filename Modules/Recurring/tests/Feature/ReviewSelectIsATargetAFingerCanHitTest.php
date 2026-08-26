<?php

declare(strict_types=1);

// Measured on an iPhone 12 mini: the five select boxes on the review queue were
// the only controls left in the app whose effective tap target was the 16px box
// itself — /transactions and /budgets have none under 44, because their
// checkboxes are label-wrapped. x-core::checkbox-field exists for this and
// guarantees the row clears the phone minimum.

it('renders the review queue select through the labelled checkbox component', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php')
    );

    expect($blade)->toContain('<x-core::checkbox-field');
    expect($blade)->not->toContain('type="checkbox"');
});

it('names the select after the row it selects, which the buttons beside it cannot do', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php')
    );

    expect($blade)->toContain(':label="$row->displayName()"');
    expect($blade)->not->toContain('review.select_aria');
});

it('leaves no locale carrying the label nothing reads any more', function (): void {
    $locales = array_values(array_filter(
        scandir(base_path('Modules/Recurring/Resources/lang')) ?: [],
        static fn (string $entry): bool => ! str_starts_with($entry, '.'),
    ));

    expect($locales)->toHaveCount(26);

    $stale = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $strings */
        $strings = require base_path("Modules/Recurring/Resources/lang/{$locale}/review.php");
        if (isset($strings['select_aria'])) {
            $stale[] = $locale;
        }
    }

    expect($stale)->toBe([], 'Still carrying select_aria: '.implode(', ', $stale));
});
