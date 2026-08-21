<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @link ../../../../.docs/features/notifications/reader-language-copy.md
 */
final readonly class CopyParam
{
    private const KIND_DAY = 'day';

    private const KIND_DATE = 'date';

    private const KIND_LANG = 'lang';

    private const KIND_MONEY = 'money';

    private const KIND_CATEGORY = 'category';

    private const VALUE_SEPARATOR = '|';

    private const MONEY_PATTERN = '/^-?\d+\|[A-Za-z]{3}$/';

    // A 0-or-1 flag, the slug, then the stored name — the name last so one
    // holding a separator still decodes. A slug is generated, so it holds none.
    private const CATEGORY_PATTERN = '/^[01]\|[^|]*\|/';

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

    public static function money(int $minor, string $currencyCode): self
    {
        return new self(self::KIND_MONEY, $minor.self::VALUE_SEPARATOR.$currencyCode);
    }

    // A category name is the one param that is not the reader's own words: an
    // untouched default is a slug the reader's language has its own wording
    // for, so it travels as provenance and is resolved at render.
    public static function category(string $storedName, string $slug, bool $nameIsDefault): self
    {
        return new self(self::KIND_CATEGORY, implode(self::VALUE_SEPARATOR, [
            $nameIsDefault ? '1' : '0',
            $slug,
            $storedName,
        ]));
    }

    public function render(): string
    {
        return match ($this->kind) {
            self::KIND_DAY => CarbonImmutable::parse($this->value)->dayName,
            self::KIND_DATE => CarbonImmutable::parse($this->value)->translatedFormat(self::SHORT_DATE_FORMAT),
            self::KIND_MONEY => self::renderMoney($this->value),
            self::KIND_CATEGORY => self::renderCategory($this->value),
            default => Lang::get($this->value),
        };
    }

    // fromArray() has already pinned the shape, so an unknown currency code is
    // all that can still fail here — the stored amount is shown as it is
    // rather than dropping the number out of the sentence entirely.
    private static function renderMoney(string $stored): string
    {
        [$minor, $currencyCode] = array_pad(explode(self::VALUE_SEPARATOR, $stored, 2), 2, '');

        return Money::tryOfMinor((int) $minor, $currencyCode)?->format() ?? $stored;
    }

    // Through the one seam that owns the rule, so a slug with no wording in
    // this language falls back to the stored name rather than printing a
    // translation key into a notification body.
    private static function renderCategory(string $stored): string
    {
        [$isDefault, $slug, $storedName] = array_pad(explode(self::VALUE_SEPARATOR, $stored, 3), 3, '');

        return CategoryDisplayName::resolve($storedName, $slug, $isDefault === '1');
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

        if ($kind === self::KIND_MONEY) {
            return preg_match(self::MONEY_PATTERN, $value) === 1 ? new self($kind, $value) : null;
        }

        if ($kind === self::KIND_CATEGORY) {
            return preg_match(self::CATEGORY_PATTERN, $value) === 1 ? new self($kind, $value) : null;
        }

        return in_array($kind, [self::KIND_DAY, self::KIND_DATE, self::KIND_LANG], true)
            ? new self($kind, $value)
            : null;
    }
}
