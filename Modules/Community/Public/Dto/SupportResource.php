<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Modules\Community\Internal\Support\RecipientAddress;
use Spatie\LaravelData\Data;

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

    public function mailtoHref(): ?string
    {
        if ($this->cancelEmailTo === null) {
            return null;
        }

        // The last gate before a mailto: href: a comma is RFC 6068's recipient
        // separator, and `%2C` reaches the mail client as one.
        if (! RecipientAddress::isSingle($this->cancelEmailTo)) {
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
