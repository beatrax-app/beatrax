<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Support;

// `preg_replace` answers null when PCRE gives up, and a redactor has two
// opposite ways to read that: keep the subject and the secret ships, or empty
// it and the detail is lost. Losing the detail is the survivable one, so this
// is the one place that chooses it — written, not left to a silent cast.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-replace-that-never-ran-blanks-the-subject
 */
final class RedactedText
{
    public static function orEmpty(string $pattern, string $replacement, string $subject): string
    {
        $redacted = preg_replace($pattern, $replacement, $subject);

        return $redacted === null ? '' : $redacted;
    }
}
