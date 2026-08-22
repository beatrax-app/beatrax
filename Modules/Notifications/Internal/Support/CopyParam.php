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
    private const VALUE_SEPARATOR = '|';

    private const SHORT_DATE_FORMAT = 'd M';

    private function __construct(
        private CopyParamKind $kind,
        private string $value,
    ) {}

    public static function dayName(CarbonInterface $date): self
    {
        return new self(CopyParamKind::Day, $date->toDateString());
    }

    public static function shortDate(CarbonInterface $date): self
    {
        return new self(CopyParamKind::Date, $date->toDateString());
    }

    public static function line(string $key): self
    {
        return new self(CopyParamKind::Lang, $key);
    }

    public static function money(int $minor, string $currencyCode): self
    {
        return new self(CopyParamKind::Money, $minor.self::VALUE_SEPARATOR.$currencyCode);
    }

    // A category name is the one param that is not the reader's own words: an
    // untouched default is a slug the reader's language has its own wording
    // for, so it travels as provenance and is resolved at render.
    public static function category(string $storedName, string $slug, bool $nameIsDefault): self
    {
        return new self(CopyParamKind::Category, implode(self::VALUE_SEPARATOR, [
            $nameIsDefault ? '1' : '0',
            $slug,
            $storedName,
        ]));
    }

    public function render(): string
    {
        return match ($this->kind) {
            CopyParamKind::Day => CarbonImmutable::parse($this->value)->dayName,
            CopyParamKind::Date => CarbonImmutable::parse($this->value)->translatedFormat(self::SHORT_DATE_FORMAT),
            CopyParamKind::Money => self::renderMoney($this->value),
            CopyParamKind::Category => self::renderCategory($this->value),
            CopyParamKind::Lang => Lang::get($this->value),
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
        return ['kind' => $this->kind->value, 'value' => $this->value];
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

        $parsed = self::readableKind($kind, $value);

        return $parsed === null ? null : new self($parsed, $value);
    }

    // A kind this release has no case for, or a stored value that does not
    // carry the shape that kind packs into it, is a row written by a version
    // that knew more. Refused here rather than half-parsed, so the reader is
    // handed the sentence the row was written with.
    private static function readableKind(string $kind, string $value): ?CopyParamKind
    {
        $parsed = CopyParamKind::tryFrom($kind);
        if ($parsed === null) {
            return null;
        }

        $pattern = $parsed->storedValuePattern();

        return $pattern !== null && preg_match($pattern, $value) !== 1 ? null : $parsed;
    }
}
