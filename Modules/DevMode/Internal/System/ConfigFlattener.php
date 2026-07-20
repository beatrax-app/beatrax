<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\System;

/**
 * Pure function class that recursively flattens a config tree into a
 * dot-keyed associative array AND masks secret-suffix-matching
 * values with `[REDACTED]`.
 *
 * The denylist is suffix/substring based and matches the dot-key
 * (case-insensitively):
 *
 *   - `*password*` — anything containing "password"
 *   - `*secret*`   — anything containing "secret"
 *   - `*key`       — anything ending in "key" (e.g. APP_KEY, app.key)
 *   - `*token*`    — anything containing "token"
 *
 * The same denylist applies to env keys, so BEATRAX_DEV_MODE renders
 * plainly while BEATRAX_OAUTH_SECRET masks.
 *
 * Final + no DI — a pure function holder consumed by
 * SystemSnapshotPage.
 */
final class ConfigFlattener
{
    /** Sentinel returned in place of a redacted value. */
    public const string REDACTED_MARKER = '[REDACTED]';

    /**
     * Recursively flatten an array tree into a dot-keyed shape. Non-
     * string keys are coerced to string. Sub-arrays preserve their
     * structure as a JSON-encoded leaf if they contain values that
     * are not plain arrays — but the typical case is a fully
     * recursive walk.
     *
     * @param  array<mixed, mixed>  $config
     * @param  string  $prefix  recursion accumulator
     * @return array<string, mixed>
     */
    public function flatten(array $config, string $prefix = ''): array
    {
        $flat = [];

        foreach ($config as $rawKey => $value) {
            $key = $prefix === ''
                ? $this->stringifyKey($rawKey)
                : $prefix.'.'.$this->stringifyKey($rawKey);

            if (is_array($value)) {
                if ($this->isScalarList($value)) {
                    // Integer-keyed list of scalars — JSON-encode for
                    // compact display. Associative arrays always
                    // recurse into dot-keyed shape.
                    $flat[$key] = json_encode($value);

                    continue;
                }
                $flat = array_merge($flat, $this->flatten($value, $key));

                continue;
            }

            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * Mask every value whose key matches the secret-suffix denylist.
     *
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    public function redactSecretSuffixes(array $flat): array
    {
        $redacted = [];
        foreach ($flat as $key => $value) {
            $redacted[$key] = $this->shouldRedact($key)
                ? self::REDACTED_MARKER
                : $value;
        }

        return $redacted;
    }

    private function shouldRedact(string $key): bool
    {
        $needle = strtolower($key);

        if (str_contains($needle, 'password')) {
            return true;
        }
        if (str_contains($needle, 'secret')) {
            return true;
        }
        if (str_contains($needle, 'token')) {
            return true;
        }
        // `*key` — must end in `key` (anywhere after a separator). The
        // segment-final test covers `app.key`, `APP_KEY`, `auth.key`,
        // and rejects benign matches like `app.kind` that the `*key`
        // suffix pattern would otherwise hit.
        if (str_ends_with($needle, 'key') || str_ends_with($needle, '_key')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    private function isScalarList(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $entry) {
            if (is_array($entry)) {
                return false;
            }
        }

        return true;
    }

    private function stringifyKey(mixed $key): string
    {
        if (is_string($key)) {
            return $key;
        }
        if (is_int($key)) {
            return (string) $key;
        }

        return (string) (is_scalar($key) ? $key : '');
    }
}
