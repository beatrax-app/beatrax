<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Internal\Adapters\Banking\Dto\Mt940Narrative;

final class Mt940Tag86Parser
{
    /**
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
            $out[$code] = ($out[$code] ?? '').$value;
        }

        return $out;
    }

    /**
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

    // General GVC form is KEYWORD+value; BIC is the one exception, using a
    // trailing-space convention (BIC<SPACE>value) instead of a leading +.
    /**
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
        // A value runs to the next `+KEYWORD+` boundary or to a bare `BIC `,
        // which can abut its predecessor without an intervening `+`.
        $generalRegex = '/(?<keyword>'.$keywordAlternation.')\+(?<value>.*?)(?=\+(?:'.$keywordAlternation.')\+|BIC |$)/';

        if (preg_match_all($generalRegex, $buffer, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $row) {
                $out[$row['keyword']] = $row['value'] === '' ? null : $row['value'];
            }
        }

        if (preg_match('/BIC (.+?)(?=\+(?:'.$keywordAlternation.')\+|BIC |$)/', $buffer, $bicMatch) === 1) {
            $out['BIC'] = trim($bicMatch[1]) === '' ? null : trim($bicMatch[1]);
        }

        return $out;
    }

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
