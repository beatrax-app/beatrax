<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Modules\Community\Internal\Support\RecipientAddress;
use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Spatie\LaravelData\Data;

final class SupportResource extends Data
{
    /**
     * @param  string|null  $notesLang  BCP-47 tag of the language $notes is written in — the provider's, not the reader's
     * @param  array<string, ExternalUrlRefusal>  $withheld  corpus field key => the refusal that stopped it becoming a link
     */
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
        public readonly ?string $notesLang = null,
        public readonly array $withheld = [],
    ) {}

    // A withheld link counts: the card exists to say what this merchant offers,
    // and "we hold a cancellation route we will not send you to" is something
    // the reader can act on, where a card that never renders is not.
    public function hasAny(): bool
    {
        return $this->cancelUrl !== null
            || $this->supportUrl !== null
            || $this->cheaperUrl !== null
            || $this->helpUrl !== null
            || $this->applyUrl !== null
            || $this->rightsUrl !== null
            || $this->phone !== null
            || $this->cancelEmailTo !== null
            || $this->withheld !== [];
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
