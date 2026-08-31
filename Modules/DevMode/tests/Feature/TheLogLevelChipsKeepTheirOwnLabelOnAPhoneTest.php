<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\CssRule;

// Measured on a Galaxy S24 at a 411px viewport: every one of the eight chips
// was exactly 44px wide and 73-152px tall, one character per line, and
// EMERGENCY ran off the right edge. 44px is the coarse-pointer touch floor,
// and it is a floor that REPLACES the min-width: auto a flex item relies on to
// refuse to shrink below its own label — so with the row out of room it became
// the width instead.

const LOG_LEVEL_CHIP_LEVELS = [
    'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY',
];

function logChipPage(): string
{
    $user = User::query()->create([
        'username' => 'log-chip-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    return test()->actingAs($user)->get('/dev/logs')->assertOk()->getContent();
}

/**
 * @return array<string, string>
 */
function logChipClasses(string $html): array
{
    preg_match_all(
        '/<button\b(?=[^>]*\bdata-severity-chip="([A-Z]+)")[^>]*\bclass="([^"]*)"/',
        $html,
        $matches,
        PREG_SET_ORDER,
    );

    $out = [];
    foreach ($matches as $match) {
        $out[$match[1]] = $match[2];
    }

    return $out;
}

it('draws a chip for every severity the counters report', function (): void {
    expect(array_keys(logChipClasses(logChipPage())))->toBe(LOG_LEVEL_CHIP_LEVELS);
});

it('refuses to shrink a level chip below its own label', function (): void {
    $classes = logChipClasses(logChipPage());

    foreach (LOG_LEVEL_CHIP_LEVELS as $level) {
        expect($classes[$level] ?? '')->toContain('shrink-0');
    }
});

it('keeps a level name on one line', function (): void {
    $classes = logChipClasses(logChipPage());

    foreach (LOG_LEVEL_CHIP_LEVELS as $level) {
        expect($classes[$level] ?? '')->toContain('whitespace-nowrap');
    }
});

it('wraps the level row rather than running it off the screen', function (): void {
    $html = logChipPage();

    $group = preg_match('/<div\b[^>]*\brole="group"[^>]*\bclass="([^"]*)"/', $html, $match) === 1
        ? $match[1]
        : (preg_match('/<div\b[^>]*\bclass="([^"]*)"[^>]*\brole="group"/', $html, $match) === 1 ? $match[1] : '');

    expect($group)->toContain('flex-wrap');
});

// The chip keeps its label because it stops shrinking, not because the touch
// floor was lifted: a finger still gets the whole 44px box.
it('leaves every button standing on the coarse-pointer touch floor', function (): void {
    $css = (string) file_get_contents(UserDataPathService::projectPath('resources/css/app.css'));

    $selectors = CssRule::selectorListFor($css, "label:has(> input[type='file']),");
    $block = CssRule::blockFor($css, "label:has(> input[type='file']),");

    expect($selectors)->toContain('button,')
        ->and($block)->toContain('min-height: 44px;')
        ->and(CssRule::atRuleEnclosing($css, "label:has(> input[type='file']),"))->toContain('pointer: coarse');
});
