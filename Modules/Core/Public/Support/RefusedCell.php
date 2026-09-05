<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The three things a refusal built around one cell knows: the file it was read
// from, the column it sat under, and what was in it. The value is a figure out
// of the reader's own export and a log is a 0644 file kept forever, so it
// travels as a bounded excerpt rather than whole.
final readonly class RefusedCell
{
    // Wide enough for a date, an amount, or a figure with its currency written
    // beside it; narrow enough that a memo shifted into the column by a quoting
    // fault reaches the log as evidence of the shift rather than as the memo.
    public const int MAX_VALUE_BYTES = 64;

    public function __construct(
        public string $file,
        public string $column,
        public string $value,
    ) {}

    /**
     * @return array{refused_file: string, refused_column: string, refused_value: string, refused_value_bytes: int}
     */
    public function toLogContext(): array
    {
        return [
            'refused_file' => $this->file,
            'refused_column' => $this->column,
            'refused_value' => self::excerpt($this->value),
            'refused_value_bytes' => strlen($this->value),
        ];
    }

    // A cell that will not parse is as likely to be bytes as text: control
    // characters would break the entry into lines nothing reads back together,
    // and an invalid sequence — including the one the cap can cut a glyph in
    // half to make — would break the encoder that writes it.
    private static function excerpt(string $value): string
    {
        // Capped before the scan rather than after, so the pattern is never
        // handed a subject larger than the answer can be. The true length is
        // read off the raw value, so a 70-byte cell stays legible from a 40KB
        // one whatever this returns.
        $capped = strlen($value) <= self::MAX_VALUE_BYTES
            ? $value
            : substr($value, 0, self::MAX_VALUE_BYTES).'…';

        return mb_scrub(PatternScan::replace('/[[:cntrl:]]+/', ' ', $capped), 'UTF-8');
    }
}
