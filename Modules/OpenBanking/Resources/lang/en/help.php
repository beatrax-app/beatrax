<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'The permission your bank gave Beatrax to read this account. Banks are required to make one expire: this consent lasts 180 days, the status turns to “:expiring” a fortnight before that, and while it has lapsed nothing is fetched — “:reconnect” signs you in at your bank again and starts a fresh 180 days. Your bank can also end it early from its own app, and nothing already imported into Beatrax is lost either way.',
];
