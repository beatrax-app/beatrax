<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Core\Public\Support\Lang;

// A replacement whose rendering depends on the reader's language, so it is
// stored as raw data and resolved at read time. Plain strings and numbers go
// into a CopyLine verbatim; money does too, because Money::format() is
// anchored to the currency rather than the locale.
final readonly class CopyParam
{
    private const KIND_DAY = 'day';

    private const KIND_DATE = 'date';

    private const KIND_LANG = 'lang';

    private const SHORT_DATE_FORMAT = 'd M';

    private function __construct(
        private string $kind,
        private string $value,
    ) {}

    public static function dayName(CarbonInterface $date): self
    {
        return new self(self::KIND_DAY, $date->toDateString());
    }

    public static function shortDate(CarbonInterface $date): self
    {
        return new self(self::KIND_DATE, $date->toDateString());
    }

    public static function line(string $key): self
    {
        return new self(self::KIND_LANG, $key);
    }

    public function render(): string
    {
        return match ($this->kind) {
            self::KIND_DAY => CarbonImmutable::parse($this->value)->dayName,
            self::KIND_DATE => CarbonImmutable::parse($this->value)->translatedFormat(self::SHORT_DATE_FORMAT),
            default => Lang::get($this->value),
        };
    }

    /**
     * @return array{kind: string, value: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'value' => $this->value];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $kind = $raw['kind'] ?? null;
        $value = $raw['value'] ?? null;

        if (! is_string($kind) || ! is_string($value) || $value === '') {
            return null;
        }

        return in_array($kind, [self::KIND_DAY, self::KIND_DATE, self::KIND_LANG], true)
            ? new self($kind, $value)
            : null;
    }
}
