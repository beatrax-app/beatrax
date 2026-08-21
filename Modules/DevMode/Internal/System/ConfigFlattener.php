<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\System;

// Redaction here is key-name-driven and therefore a denylist: a secret whose
// key matches none of the markers below renders in the clear on /dev/system.
final class ConfigFlattener
{
    private const SENSITIVE_SUBSTRINGS = ['password', 'passphrase', 'secret', 'token', 'credential'];

    // `keys` is not decoration on `key`: str_ends_with('app.previous_keys',
    // 'key') is false, so dropping it would expose Laravel's retired-APP_KEY
    // list, which still decrypts data at rest.
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

        // Suffix, not substring, so benign names like app.kind survive. No
        // separate '_key' clause: anything ending '_key' already ends 'key'.
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
