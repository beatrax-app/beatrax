<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The soft keyboard offsets the visual viewport and leaves the layout viewport
// alone, so a sticky header sits above the visible area while the reader types.
// The wizard takes typed amounts on its starting-balance cards and was the one
// shell still declaring the default.

// Read from the tag, never from the file: the prose above the tag names the
// token too, so a whole-file search passes on a shell that never declares it.
function wizardLayoutViewportTag(): string
{
    $layout = (string) file_get_contents(base_path('Modules/Onboarding/Resources/views/layouts/app-wizard.blade.php'));

    $tag = PatternScan::first('/<meta name="viewport"[^>]*>/', $layout);

    return $tag[0] ?? '';
}

it('asks the browser to resize the layout viewport rather than only the visual one', function (): void {
    expect(wizardLayoutViewportTag())->toContain('interactive-widget=resizes-content');
});

it('still reserves the notch it already reserved', function (): void {
    expect(wizardLayoutViewportTag())->toContain('viewport-fit=cover');
});
