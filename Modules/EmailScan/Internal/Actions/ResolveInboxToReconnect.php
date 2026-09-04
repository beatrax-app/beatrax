<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Actions;

use Modules\Core\Models\User;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\InboxQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ResolveInboxToReconnect
{
    public function __construct(
        private InboxQuery $inboxes,
    ) {}

    public function __invoke(MailProvider $provider, User $user, mixed $requestedInboxId): ?int
    {
        if (! is_string($requestedInboxId) || ! ctype_digit($requestedInboxId)) {
            return null;
        }

        $candidate = (int) $requestedInboxId;
        if ($candidate <= 0) {
            return null;
        }

        $inbox = $this->inboxes->findForUser($candidate, $user);

        // An inbox the reader does not own and a cross-provider reconnect are
        // refused the same way: the second would permanently break the
        // inbox's next scan, so neither may reach the authorization dance.
        if ($inbox === null || $inbox->provider !== $provider->value) {
            throw new NotFoundHttpException('Inbox not found.');
        }

        return $candidate;
    }
}
