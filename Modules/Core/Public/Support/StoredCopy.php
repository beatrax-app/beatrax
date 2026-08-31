<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use JsonException;

// A notification row keeps its copy as a key plus values because the reader may
// not read in the language it was written in. A `display_label` column is the
// same row one table over: the migration preview stored "Goal: Holiday" and
// handed it to a Dutch reader unchanged.
/**
 * @link ../../../../.docs/features/notifications/reader-language-copy.md
 */
final class StoredCopy
{
    // No sentence a person writes starts this way, so a column holding the
    // user's own words — or a row written before this seam existed — is
    // recognised by its absence and handed back exactly as it was found.
    private const string MARK = '{"@copy":';

    private const string SPEC_KEY = '@copy';

    // The sentence as it read when the row was written, kept for the reason
    // notifications keep their title and body columns: a key renamed in a
    // later release must degrade to a stale sentence, never to a raw key.
    private const string WRITTEN_KEY = '@said';

    public static function of(CopyLine $line): string
    {
        try {
            return json_encode([
                self::SPEC_KEY => $line->toArray(),
                self::WRITTEN_KEY => $line->sentence(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $line->sentence();
        }
    }

    // Verbatim for anything that is not a spec, which is the user's own words
    // and the same text in every language. A spec whose key this release can
    // no longer resolve falls to the sentence stored beside it.
    public static function read(string $stored): string
    {
        $envelope = self::envelope($stored);
        if ($envelope === null) {
            return $stored;
        }

        $written = $envelope[self::WRITTEN_KEY] ?? null;
        $fallback = is_string($written) ? $written : $stored;

        return CopyLine::fromArray($envelope[self::SPEC_KEY] ?? null)?->render() ?? $fallback;
    }

    // The other shape: a spec that rides in a JSON column BESIDE the sentence
    // rather than packed into the column itself. A synced table needs that one,
    // because a peer on an older build has to find a written sentence where it
    // has always looked. `system_alerts` carries it in `metadata`.
    public const string IN_PARAMS_KEY = 'copy';

    /** @return array<string, mixed> the spec, keyed for the JSON column that carries it */
    public static function inParams(CopyLine $line): array
    {
        return [self::IN_PARAMS_KEY => $line->toArray()];
    }

    // $written is the column an older build renders, and the answer whenever
    // this one cannot resolve the spec — a key renamed since, or a row from
    // before the spec rode along at all.
    public static function readFromParams(mixed $params, string $written): string
    {
        $spec = is_array($params) ? ($params[self::IN_PARAMS_KEY] ?? null) : null;

        return CopyLine::fromArray($spec)?->render() ?? $written;
    }

    public static function isSpec(string $stored): bool
    {
        return str_starts_with($stored, self::MARK);
    }

    // Which line a stored value names, for a caller that has to RECOGNISE one
    // rather than render it — a query narrowing to the rows the app itself
    // wrote, a test naming the row it means. Null for the user's own words,
    // so no caller outside this class has to know the envelope's shape.
    public static function keyOf(string $stored): ?string
    {
        $envelope = self::envelope($stored);
        $spec = is_array($envelope) ? ($envelope[self::SPEC_KEY] ?? null) : null;
        $key = is_array($spec) ? ($spec['key'] ?? null) : null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function names(?string $stored, string $key): bool
    {
        return $stored !== null && self::keyOf($stored) === $key;
    }

    /** @return array<array-key, mixed>|null the decoded envelope, or null when this is not a spec */
    private static function envelope(string $stored): ?array
    {
        if (! self::isSpec($stored)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
