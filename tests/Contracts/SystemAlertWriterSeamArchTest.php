<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

/**
 * system_alerts is two tables wearing one name. A row with an owner says
 * something about the account and must travel to the paired device, which only
 * happens through SystemAlertWriter; a row with a null owner is about the
 * machine that noticed, and the peer raises its own under its own id. So a
 * writer outside the seam may only raise the second kind, and must say so with
 * a literal `'user_id' => null` a reader and this guard can both see.
 *
 * @param  list<string>  $paths
 * @return list<string> one entry per owned alert written outside the seam
 */
function systemAlertWritesOutsideTheSeam(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        if (str_ends_with($path, 'Core/Public/Services/SystemAlertWriter.php')) {
            continue;
        }

        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $args = systemAlertRowArguments($tokens, $index);
            if ($args === null) {
                continue;
            }

            if (preg_match("/'user_id'\s*=>\s*null/", $args) !== 1) {
                $hits[] = "{$path}:{$token[2]}";
            }
        }
    }

    return $hits;
}

/**
 * The argument list of a call that creates a system_alerts row at $index, or
 * null when the call is something else.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function systemAlertRowArguments(array $tokens, int $index): ?string
{
    $name = $tokens[$index];
    if (! is_array($name)) {
        return null;
    }

    // `SystemAlert::create([...])` and `SystemAlert::query()->create([...])`.
    if (in_array(strtolower($name[1]), ['create', 'firstorcreate', 'updateorcreate'], true)) {
        return systemAlertReceiverIsTheModel($tokens, $index)
            ? BackendSourceFiles::callArguments($tokens, $index)
            : null;
    }

    // `->table('system_alerts')->insert([...])` / `->insertGetId([...])`.
    if (! in_array(strtolower($name[1]), ['insert', 'insertgetid', 'insertorignore', 'upsert'], true)) {
        return null;
    }

    for ($i = $index - 1; $i >= 0 && $i > $index - 12; $i--) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && trim($token[1], "'\"") === 'system_alerts') {
            return BackendSourceFiles::callArguments($tokens, $index);
        }
    }

    return null;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return bool whether the create() at $index is called on the SystemAlert model
 */
function systemAlertReceiverIsTheModel(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0 && $i > $index - 8; $i--) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        if ($token[1] === 'SystemAlert') {
            return true;
        }
        // Any other class or method name between here and the call means the
        // receiver is something else entirely.
        if (! in_array(strtolower($token[1]), ['query', 'newquery'], true)) {
            return false;
        }
    }

    return false;
}

it('raises every user-owned system alert through SystemAlertWriter', function (): void {
    $files = BackendSourceFiles::all();
    expect($files)->not->toBeEmpty();

    expect(systemAlertWritesOutsideTheSeam($files))->toBe(
        [],
        "An owned alert written outside SystemAlertWriter never reaches the op log,\n".
        "so it never reaches the paired device. Route it through the writer, or make\n".
        'it machine-local with a literal user_id => null. Offenders:',
    );
});

it('sees an owned alert written past the seam', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'alert-seam').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedAlertWrites
        {
            public function raise(int $userId): void
            {
                SystemAlert::create(['user_id' => $userId, 'kind' => 'a']);
                SystemAlert::query()->create(['user_id' => null, 'kind' => 'b']);
                $this->db->connection()->table('system_alerts')->insert(['user_id' => $userId, 'kind' => 'c']);
                $this->db->connection()->table('system_alerts')->insert(['user_id' => null, 'kind' => 'd']);
                $this->db->connection()->table('other_table')->insert(['user_id' => $userId]);
            }
        }
        PHP);

    try {
        $found = systemAlertWritesOutsideTheSeam([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(2, 'the null-owner writes and the unrelated table are not violations');
    expect($found[0])->toEndWith(':6');
    expect($found[1])->toEndWith(':8');
});
