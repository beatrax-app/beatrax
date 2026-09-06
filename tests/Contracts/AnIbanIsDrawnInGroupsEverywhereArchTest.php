<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// An IBAN has no break opportunity of its own, so any column narrow enough
// splits it mid-identifier. Core::Iban is the one place that decides the
// presentation, and the reason this rule exists is that routing four render
// sites through it was not enough: a fifth sat in the onboarding preview and a
// sixth in an import partial, and both were found by eye on a device rather
// than by the change that was supposed to have covered them.

/**
 * Every echo of a whole IBAN value in one template, with the offset it sits at.
 *
 * Echoes only, and only where the IBAN is the WHOLE value. An IBAN passed to
 * in_array() or to a mask is an argument, not something the reader sees, and
 * grouping it there would break the comparison.
 *
 * @return list<array{expression: string, offset: int}>
 */
function ibanEchoesIn(string $source): array
{
    $found = [];

    foreach (PatternScan::allWithOffsets('/\{\{(?!--)\s*(\$[\w>\-\[\]\']*?[Ii]ban)\s*\}\}/', $source)[1] as $match) {
        $found[] = ['expression' => $match[0], 'offset' => (int) $match[1]];
    }

    return $found;
}

it('draws every IBAN it echoes through the one seam that groups it', function (): void {
    // The roots come from RepoTree rather than from a Modules-only walk of this
    // rule's own: "everywhere" was a claim about every view a reader is shown,
    // and resources/ was outside the walk that stated it.
    $files = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    expect(count($files))->toBeGreaterThan(100, 'No blades were read, so this rule proved nothing.');

    $raw = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach (ibanEchoesIn($source) as $echo) {
            $raw[] = str_replace(RepoTree::root().'/', '', $path)
                .':'.(substr_count(substr($source, 0, $echo['offset']), "\n") + 1)
                .' {{ '.$echo['expression'].' }}';
        }
    }

    sort($raw);

    expect($raw)->toBe(
        [],
        "These render an IBAN as one unbroken run; pass it through Iban::grouped():\n  ".implode("\n  ", $raw)
    );
});

it('reads a bare IBAN echo, and leaves the grouped seam and an argument alone', function (): void {
    expect(array_column(ibanEchoesIn('<p>{{ $account->iban }}</p>'), 'expression'))
        ->toBe(['$account->iban'], 'the echo this rule exists for is the one the scan must find');

    expect(ibanEchoesIn('<p>{{ Iban::grouped($account->iban) }}</p>'))
        ->toBe([], 'the seam that groups it is what the rule asks for, not an offence');

    expect(ibanEchoesIn('@if (in_array($account->iban, $known, true))'))
        ->toBe([], 'an IBAN handed to a comparison is an argument, and grouping it there would break the comparison');
});
