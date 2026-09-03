<?php

declare(strict_types=1);

// Mirrors tests/Contracts/CommentPolicyArchTest.php (M1-M4) for ONE file, with
// no framework boot, so an edit is judged the moment it lands instead of at
// the gate. Keep the four rules below in step with that test: it stays the
// authority, this is only the fast path to it.

$payload = json_decode((string) file_get_contents('php://stdin'), true);
$input = is_array($payload) && is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
$named = $input['file_path'] ?? null;

// A shell command that never mentions a PHP file cannot have written one, and
// this hook runs on every one of them. Skipping those keeps the common case
// free rather than paying a `git status` for it.
if (! is_string($named) && ! str_contains((string) ($input['command'] ?? ''), '.php')) {
    exit(0);
}

// A tool that names its file is judged on that file alone. A tool that does
// not — an edit driven through the shell — is judged on whatever the working
// tree currently has uncommitted, which is the same set a moment later.
$paths = is_string($named) ? [$named] : commentPolicyDirtyPhpFiles();

// The same set CommentPolicyArchTest calls backend files: Modules/ and app/,
// never a test or a migration. Judging more than the authority does would
// invent failures the gate will not have.
$paths = array_values(array_filter(
    $paths,
    static fn (string $p): bool => str_ends_with($p, '.php')
        && is_file($p)
        && preg_match('#/(Modules|app)/#', $p) === 1
        && ! str_contains($p, '/tests/')
        && ! str_contains($p, '/Database/Migrations/')
        && preg_match('#/(vendor|node_modules|storage|bootstrap/cache)/#', $p) !== 1,
));

if ($paths === []) {
    exit(0);
}

/** @return list<string> */
function commentPolicyDirtyPhpFiles(): array
{
    $root = getenv('CLAUDE_PROJECT_DIR') ?: getcwd();
    $command = 'git -C '.escapeshellarg((string) $root).' status --porcelain --untracked-files=all 2>/dev/null';
    $output = [];
    exec($command, $output);

    $files = [];

    foreach ($output as $line) {
        $name = trim(substr($line, 2));

        if ($name === '' || ! str_ends_with($name, '.php')) {
            continue;
        }

        $files[] = $root.'/'.$name;
    }

    return $files;
}

$isDirective = static fn (string $text): bool => preg_match(
    '#^\s*(?://|/\*\*?)\s*(?:@(?:var\b|codeCoverageIgnore)|@?(?:phpstan|psalm|phpcs)[-:])#i',
    $text,
) === 1;

$hits = [];

foreach ($paths as $path) {
$lineCommentLines = [];

foreach (token_get_all((string) file_get_contents($path)) as $token) {
    if (! is_array($token)) {
        continue;
    }

    if ($token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '/*')) {
        $hits[] = "{$path}:{$token[2]} M3: informative /* */ block — use /** */ PHPDoc.";
    }

    if ($token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '//') && ! $isDirective($token[1])) {
        $lineCommentLines[] = $token[2];
    }

    if ($token[0] === T_DOC_COMMENT) {
        $seenTag = false;
        $hasContent = false;

        foreach (explode("\n", $token[1]) as $raw) {
            $line = trim(ltrim(trim($raw), '/*'));

            if ($line === '') {
                continue;
            }

            $hasContent = true;

            if (str_starts_with($line, '@')) {
                $seenTag = true;
            } elseif (! $seenTag) {
                $hits[] = "{$path}:{$token[2]} M4: docblock carries prose — @-tags only, move the why to a // block above.";
                break;
            }
        }

        if ($hasContent && ! $seenTag) {
            $hits[] = "{$path}:{$token[2]} M4: docblock with no @-tags at all — delete it or make it a // block.";
        }
    }
}

sort($lineCommentLines);

$blocks = [];
$block = [];

foreach ($lineCommentLines as $line) {
    if ($block !== [] && $line !== end($block) + 1) {
        $blocks[] = $block;
        $block = [];
    }
    $block[] = $line;
}

if ($block !== []) {
    $blocks[] = $block;
}

foreach ($blocks as $lines) {
    $n = count($lines);

    if ($n === 1) {
        $hits[] = "{$path}:{$lines[0]} M1: lone one-line // comment. DELETE it, or let a rename / extracted method / named constant carry it. NEVER pad it to two lines.";
    }

    if ($n > 4) {
        $hits[] = "{$path}:{$lines[0]} M2: {$n}-line // block, max is 4. Cut it to 4 lines or fewer.";
    }
}
}

if ($hits === []) {
    exit(0);
}

fwrite(STDERR, "Comment policy violations:\n  ".implode("\n  ", $hits)."\n\n".
    "House rule: a comment is load-bearing or it is deleted. It explains a constraint, an\n".
    "ordering trap, or an edge case being defended — never what the code already says.\n".
    "Fix these now, before continuing. tests/Contracts/CommentPolicyArchTest.php is the authority.\n");

exit(2);
