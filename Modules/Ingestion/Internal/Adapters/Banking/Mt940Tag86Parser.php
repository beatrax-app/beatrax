<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940Narrative;

/**
 * Parses a single `:86:` narrative body into a typed `Mt940Narrative`
 * value object.
 *
 * Two shapes are accepted:
 *
 *  1. Structured: starts with a 3-digit GVC posting code, followed by
 *     `?NN`-prefixed subfields. Counterparty name comes from `?32` (with
 *     `?33` appended), counterparty IBAN comes from `?31` (or the IBAN
 *     GVC keyword as a fallback), and the purpose subfields `?20–?29`
 *     plus `?60–?65` are concatenated and scanned for SEPA GVC keywords.
 *
 *  2. Unstructured: free text with no leading GVC code or `?NN` markers.
 *     The full body becomes `description`; every other field stays null.
 *
 * GVC keywords are paired delimiters of the form `KEYWORD+value` (the
 * value runs until the next `+KEYWORD+` boundary or end of buffer). `BIC`
 * is the one exception — it uses a trailing space convention, so its
 * value comes between `BIC ` and the next `+`.
 */
final class Mt940Tag86Parser
{
    /**
     * SEPA GVC keywords the parser surfaces from the purpose subfields.
     * The order matters only for the scan regex.
     *
     * @var list<string>
     */
    private const GVC_KEYWORDS = [
        'EREF',
        'MREF',
        'CRED',
        'SVWZ',
        'KREF',
        'PURP',
        'IBAN',
        'BIC',
        'ABWA',
        'MDAT',
        'COAM',
        'OAMT',
    ];

    public function parse(string $content): Mt940Narrative
    {
        $rawText = $content;
        $trimmed = trim($content);

        if (preg_match('/^(\d{3})(.*)$/s', $trimmed, $m) !== 1 || ! str_contains($m[2], '?')) {
            return new Mt940Narrative(
                gvcCode: null,
                gvcKeywords: array_fill_keys(self::GVC_KEYWORDS, null),
                counterpartyName: null,
                counterpartyIban: null,
                description: $trimmed !== '' ? $trimmed : null,
                rawText: $rawText,
            );
        }

        $gvcCode = $m[1];
        $body = $m[2];

        $subfields = $this->splitSubfields($body);

        $counterpartyName = $this->concat($subfields, [32, 33]);
        $counterpartyIban = $this->nullIfEmpty($subfields[31] ?? null);

        $purposeRaw = $this->concat($subfields, [20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 60, 61, 62, 63, 64, 65]);
        $keywords = $this->extractGvcKeywords($purposeRaw);

        if ($counterpartyIban === null && isset($keywords['IBAN'])) {
            $counterpartyIban = $keywords['IBAN'];
        }

        $description = $this->nullIfEmpty($keywords['SVWZ'] ?? null)
            ?? $this->nullIfEmpty($this->stripGvcMarkers($purposeRaw));

        return new Mt940Narrative(
            gvcCode: $gvcCode,
            gvcKeywords: array_merge(array_fill_keys(self::GVC_KEYWORDS, null), $keywords),
            counterpartyName: $this->nullIfEmpty($counterpartyName),
            counterpartyIban: $counterpartyIban,
            description: $description,
            rawText: $rawText,
        );
    }

    /**
     * Splits the `?NN`-delimited body into a map keyed by integer subfield
     * code. The lexer may have joined continuation lines with `\n`; those
     * are preserved when they sit inside a subfield value and treated as
     * boundary-transparent when they sit between subfields.
     *
     * @return array<int, string>
     */
    private function splitSubfields(string $body): array
    {
        // Strip in-buffer newlines so a continuation line between two
        // `?NN` subfields doesn't break the regex anchor.
        $normalised = str_replace(["\r\n", "\r", "\n"], '', $body);

        /** @var array<int, string> $out */
        $out = [];

        if (preg_match_all('/\?(\d{2})([^?]*)/', $normalised, $matches, PREG_SET_ORDER) === false) {
            return $out;
        }

        foreach ($matches as $row) {
            $code = (int) $row[1];
            $value = $row[2];
            // Multiple occurrences of the same subfield code (rare in
            // practice) concatenate in stream order.
            $out[$code] = ($out[$code] ?? '').$value;
        }

        return $out;
    }

    /**
     * Concatenates the subfield values in declaration order.
     *
     * @param  array<int, string>  $subfields
     * @param  list<int>  $codes
     */
    private function concat(array $subfields, array $codes): ?string
    {
        $pieces = [];
        foreach ($codes as $code) {
            if (isset($subfields[$code]) && $subfields[$code] !== '') {
                $pieces[] = $subfields[$code];
            }
        }
        if ($pieces === []) {
            return null;
        }

        return implode('', $pieces);
    }

    /**
     * Scans a buffer for SEPA GVC keywords. The general form is
     * `KEYWORD+value` separated by `+`; `BIC` uses `BIC<SPACE>value`.
     *
     * @return array<string, ?string>
     */
    private function extractGvcKeywords(?string $buffer): array
    {
        /** @var array<string, ?string> $out */
        $out = [];
        if ($buffer === null || $buffer === '') {
            return $out;
        }

        $keywordAlternation = implode('|', array_filter(self::GVC_KEYWORDS, static fn (string $kw): bool => $kw !== 'BIC'));
        // A keyword value stops at the next `+KEYWORD+` boundary OR at a
        // bare `BIC ` (the one keyword that uses the trailing-space form
        // and may abut its predecessor without an intervening `+`).
        $generalRegex = '/(?<keyword>'.$keywordAlternation.')\+(?<value>.*?)(?=\+(?:'.$keywordAlternation.')\+|BIC |$)/';

        if (preg_match_all($generalRegex, $buffer, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $row) {
                $out[$row['keyword']] = $row['value'] === '' ? null : $row['value'];
            }
        }

        // BIC uses the trailing-space convention.
        if (preg_match('/BIC (.+?)(?=\+(?:'.$keywordAlternation.')\+|BIC |$)/', $buffer, $bicMatch) === 1) {
            $out['BIC'] = trim($bicMatch[1]) === '' ? null : trim($bicMatch[1]);
        }

        return $out;
    }

    /**
     * Strips known GVC keyword markers from a description buffer so the
     * resulting free text is read-only narrative.
     */
    private function stripGvcMarkers(?string $buffer): ?string
    {
        if ($buffer === null) {
            return null;
        }

        $stripped = preg_replace('/(EREF|MREF|CRED|SVWZ|KREF|PURP|IBAN|ABWA|MDAT|COAM|OAMT)\+[^+]*/', '', $buffer);
        $stripped ??= $buffer;
        $stripped = preg_replace('/BIC [^+]+/', '', $stripped);
        $stripped ??= $buffer;
        $collapsed = preg_replace('/\s+/u', ' ', $stripped);

        $result = trim(is_string($collapsed) ? $collapsed : $stripped);

        return $result === '' ? null : $result;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
