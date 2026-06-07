<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Optional, fixed-value "where do I get help / cancel / save money" resources
 * for one counterparty (a merchant or a government agency), loaded from the
 * bundled `resources/corpus/support/<country>.yaml` corpus and rendered on the
 * counterparty profile.
 *
 * Every field is optional — a profile renders only the links that exist.
 * Merchant entries lean on cancel/support/cheaper + an optional pre-written
 * cancellation email (rendered as a one-click `mailto:`); government entries
 * lean on help/apply/rights + a phone number.
 */
final class SupportResource extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $supportUrl = null,
        public readonly ?string $cheaperUrl = null,
        public readonly ?string $helpUrl = null,
        public readonly ?string $applyUrl = null,
        public readonly ?string $rightsUrl = null,
        public readonly ?string $phone = null,
        public readonly ?string $cancelEmailTo = null,
        public readonly ?string $cancelEmailSubject = null,
        public readonly ?string $cancelEmailBody = null,
        public readonly ?string $notes = null,
    ) {}

    public function hasAny(): bool
    {
        return $this->cancelUrl !== null
            || $this->supportUrl !== null
            || $this->cheaperUrl !== null
            || $this->helpUrl !== null
            || $this->applyUrl !== null
            || $this->rightsUrl !== null
            || $this->phone !== null
            || $this->cancelEmailTo !== null;
    }

    /**
     * A `mailto:` href with the pre-written cancellation request pre-filled, or
     * null when this resource has no cancellation-by-email channel.
     */
    public function mailtoHref(): ?string
    {
        if ($this->cancelEmailTo === null) {
            return null;
        }

        // Defense-in-depth: a `to` carrying mailto control characters (?, &,
        // whitespace, CR/LF) could inject extra recipients or headers into the
        // pre-filled email. Refuse to build a mailto from a suspicious address
        // rather than emit a tampered one.
        if (preg_match('/[?&\s]/', $this->cancelEmailTo) === 1) {
            return null;
        }

        $query = [];
        if ($this->cancelEmailSubject !== null) {
            $query[] = 'subject='.rawurlencode($this->cancelEmailSubject);
        }
        if ($this->cancelEmailBody !== null) {
            $query[] = 'body='.rawurlencode($this->cancelEmailBody);
        }

        return 'mailto:'.$this->cancelEmailTo.($query === [] ? '' : '?'.implode('&', $query));
    }
}
