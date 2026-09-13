<?php

declare(strict_types=1);

// The hook is the fast path to this file's rules, and it said so: "the same set
// CommentPolicyArchTest calls backend files". It read Modules/ alone while the
// authority had grown to four roots, so a violation written into bootstrap/,
// tools/ or database/seeders/ was silent until the gate.
// @link ../../.docs/conventions/analyser-rules-enforced-locally.md#the-scope-every-guard-reads

/**
 * @return list<string>
 */
function hookScopeMatches(string $pattern, string $subject, string $what): array
{
    $matches = [];
    $count = preg_match_all($pattern, $subject, $matches);

    // preg_match_all answers false on a backtrack limit rather than raising, and
    // a reader that stopped reading would report an empty set as agreement.
    expect($count)->toBeInt("Reading $what stopped before it finished, so the comparison below is about nothing.");
    expect($count)->toBeGreaterThan(0, "Reading $what matched nothing, so its shape has moved.");

    return $matches[1];
}

it('reads the same roots its authority reads', function (): void {
    $authority = (string) file_get_contents(base_path('tests/Contracts/CommentPolicyArchTest.php'));
    $hook = (string) file_get_contents(base_path('.claude/hooks/comment-policy.php'));

    $rootsBlock = hookScopeMatches('/\$roots = \[(.*?)\];/s', $authority, "the authority's \$roots block");
    $authorityRoots = hookScopeMatches("/base_path\('([^']+)'\)/", $rootsBlock[0], "the authority's root list");

    $hookGroup = hookScopeMatches("/preg_match\('#\/\(([^)]+)\)\/#', \\\$p\) === 1/", $hook, "the hook's root pattern");
    $hookRoots = explode('|', $hookGroup[0]);

    sort($authorityRoots);
    sort($hookRoots);

    expect($hookRoots)->toBe($authorityRoots, sprintf(
        "The edit-time hook and its authority disagree about which trees carry the comment rules.\n".
        "  authority: %s\n  hook:      %s\n".
        'A root the hook does not read is one whose violations are silent until the gate; a root only '.
        'the hook reads invents failures the gate will not have.',
        implode(', ', $authorityRoots),
        implode(', ', $hookRoots),
    ));
});

it('skips the same subtrees its authority skips', function (): void {
    $authority = (string) file_get_contents(base_path('tests/Contracts/CommentPolicyArchTest.php'));
    $hook = (string) file_get_contents(base_path('.claude/hooks/comment-policy.php'));

    // Read off the authority's own `continue` guard rather than a list kept here,
    // so a subtree it stops excluding cannot leave this rule asserting the past.
    $skipBlock = hookScopeMatches('/if \(str_contains\(\$path, \'\/tests\/\'\)(.*?)\) \{\s*continue;/s', $authority, "the authority's skip block");
    $authoritySkips = hookScopeMatches("/'(\/[^']+\/)'/", "'/tests/'".$skipBlock[0], "the authority's skipped subtrees");

    $hookSkips = hookScopeMatches("/! str_contains\(\\\$p, '(\/[^']+\/)'\)/", $hook, "the hook's skipped subtrees");

    sort($authoritySkips);
    sort($hookSkips);

    expect($hookSkips)->toBe($authoritySkips, sprintf(
        "The edit-time hook and its authority disagree about which subtrees are exempt.\n".
        "  authority: %s\n  hook:      %s",
        implode(', ', $authoritySkips),
        implode(', ', $hookSkips),
    ));
});
