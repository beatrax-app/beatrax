<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-refused-write-answering-as-a-server-fault
 */

/** @return list<string> the bootstrap files that configure an exception handler */
function refusalSeamRoots(): array
{
    return ['bootstrap/app.php', 'mobile-app/bootstrap/app.php'];
}

it('maps the refused-write family through the shared seam on both roots', function (): void {
    foreach (refusalSeamRoots() as $root) {
        $contents = (string) file_get_contents(base_path($root));

        expect(str_contains($contents, 'LivewireClientRefusal::refusal('))->toBeTrue(
            "{$root} does not map a refused /livewire/update write. Without it a payload the component ".
            'correctly refused answers 500 on this bundle and 4xx on the other, which is two answers to one payload.',
        );
    }
});

// The seam is keyed on \Exception because one member of the family — the
// update path that descends into a scalar — is thrown as a bare one.
// Handler::mapException() returns on the FIRST `is_a` hit, so a second mapper
// registered beside it is never consulted and fails nothing.
it('registers exactly one exception mapper per root', function (): void {
    foreach (refusalSeamRoots() as $root) {
        $mappers = substr_count((string) file_get_contents(base_path($root)), '$exceptions->map(');

        expect($mappers)->toBe(
            1,
            "{$root} registers {$mappers} exception mappers. The one keyed on \\Exception shadows every other, ".
            'so a new refusal belongs inside LivewireClientRefusal::refusal() rather than in a second map() call.',
        );
    }
});
