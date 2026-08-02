<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\System;

// Recursively flattens a config tree into a dot-keyed array AND masks
// secret-suffix-matching values with [REDACTED]. The denylist
// (*password*, *secret*, *token*, *key/*keys suffix) is case-insensitive and
// applies to env keys, so BEATRAX_DEV_MODE renders plainly while BEATRAX_OAUTH_SECRET masks.
final class ConfigFlattener
{
    // Substrings that make a config key sensitive wherever in the key they
    // appear, as one list rather than a chain of str_contains calls.
    private const SENSITIVE_SUBSTRINGS = ['password', 'passphrase', 'secret', 'token', 'credential'];

    // Suffixes that make a config key sensitive. `keys` is not decoration on
    // `key`: str_ends_with('app.previous_keys', 'key') is false, so without
    // it Laravel's retired-APP_KEY list — which still decrypts data at rest —
    // renders in the clear on /dev/system.
    private const SENSITIVE_SUFFIXES = ['key', 'keys'];

    public const string REDACTED_MARKER = '[REDACTED]';

    /**
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

        foreach (self::SENSITIVE_SUBSTRINGS as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        // Trailing `key`/`keys` covers app.key, APP_KEY, auth.key and
        // app.previous_keys while rejecting benign names like app.kind. No
        // '_key' clause is needed — the needle is already lowercased, so
        // anything ending '_key' ends 'key'.
        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($needle, $suffix)) {
                return true;
            }
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
