<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\EmailScan\Models\DiscoveredSender;
use Modules\EmailScan\Models\Inbox;
use Modules\EmailScan\Models\InboxMessage;
use Modules\EmailScan\Models\InboxScanState;
use Modules\EmailScan\Models\KnownSender;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;

final class DemoEmailScanSeeder
{
    private const GMAIL_EMAIL = 'demo-1+gmail@beatrax.local';

    private const MS_EMAIL = 'demo-1+microsoft@beatrax.local';

    private const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function __construct(
        private readonly SecretShield $shield,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary === null) {
            return 0;
        }

        $now = $this->clock->now();

        $gmail = $this->upsertInbox($primary, MailProvider::Gmail->value, self::GMAIL_EMAIL);
        $microsoft = $this->upsertInbox($primary, MailProvider::Microsoft->value, self::MS_EMAIL);

        $this->upsertScanState($primary, $gmail, $now, lastHistoryId: 'gmail-cursor-1234', lastDeltaLink: null);
        $this->upsertScanState($primary, $microsoft, $now, lastHistoryId: null, lastDeltaLink: 'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=demo-cursor');

        $this->upsertOAuthSecret($primary, $gmail, self::GMAIL_EMAIL, $now);
        $this->upsertOAuthSecret($primary, $microsoft, self::MS_EMAIL, $now);

        $this->upsertKnownSender($primary, 'subscriptions@spotify.com', 'Spotify subscriptions', $now);
        $this->upsertKnownSender($primary, 'noreply@bol.com', 'Bol.com receipts', $now);

        $this->upsertInboxMessage(
            $primary,
            $gmail,
            $now,
            new DemoInboxMessage(
                providerMessageId: 'gmail-msg-1',
                senderEmail: 'subscriptions@spotify.com',
                senderName: 'Spotify',
                subject: 'Your Spotify Premium receipt',
                ageHours: 6,
            ),
        );
        $this->upsertInboxMessage(
            $primary,
            $gmail,
            $now,
            new DemoInboxMessage(
                providerMessageId: 'gmail-msg-2',
                senderEmail: 'noreply@bol.com',
                senderName: 'Bol.com',
                subject: 'Order shipped — Bol.com',
                ageHours: 30,
            ),
        );
        $this->upsertInboxMessage(
            $primary,
            $microsoft,
            $now,
            new DemoInboxMessage(
                providerMessageId: 'microsoft-msg-1',
                senderEmail: 'noreply@booking.com',
                senderName: 'Booking.com',
                subject: 'Your booking confirmation',
                ageHours: 12,
            ),
        );

        $this->upsertDiscoveredSender(
            $primary,
            $gmail,
            $now,
            'mailings@hema.nl',
            'HEMA Mailings',
            state: DiscoveredSenderState::Candidate->value,
            occurrenceCount: 3,
        );
        $this->upsertDiscoveredSender(
            $primary,
            $gmail,
            $now,
            'support@coolblue.nl',
            'Coolblue Support',
            state: DiscoveredSenderState::Added->value,
            occurrenceCount: 7,
        );
        $this->upsertDiscoveredSender(
            $primary,
            $microsoft,
            $now,
            'noreply@aliexpress.com',
            'AliExpress',
            state: DiscoveredSenderState::Dismissed->value,
            occurrenceCount: 1,
        );

        return Inbox::query()
            ->where('user_id', $primary->id)
            ->count();
    }

    private function upsertInbox(User $user, string $provider, string $email): Inbox
    {
        return Inbox::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'email' => $email,
            ],
            [
                'provider' => $provider,
                'backfill_window_months' => 6,
                'backfill_progress' => ['fetched_count' => 42, 'total_estimated' => 100],
            ],
        );
    }

    private function upsertScanState(
        User $user,
        Inbox $inbox,
        CarbonImmutable $now,
        ?string $lastHistoryId,
        ?string $lastDeltaLink,
    ): void {
        InboxScanState::query()->updateOrCreate(
            [
                'inbox_id' => $inbox->id,
                'folder' => 'INBOX',
            ],
            [
                'user_id' => $user->id,
                'last_history_id' => $lastHistoryId,
                'last_delta_link' => $lastDeltaLink,
                'last_scan_at' => $now->subHours(2),
                'status' => InboxScanStatus::Idle->value,
                'error_message' => null,
                'retry_attempts' => 0,
            ],
        );
    }

    // tokens_blob is a map keyed by inbox id, shielded, the shape
    // OAuthSecretsRepository::loadInbox reads back. A flat token pair decodes
    // to no inbox at all, and every scan job then fails as unconfigured.
    private function upsertOAuthSecret(User $user, Inbox $inbox, string $email, CarbonImmutable $now): void
    {
        $provider = $inbox->provider;
        $inboxId = $inbox->id;

        $tokens = [
            (string) $inboxId => [
                'id' => $inboxId,
                'provider' => $provider,
                'email' => $email,
                'refresh_token' => 'demo-refresh-'.$provider,
                'access_token' => 'demo-access-'.$provider,
                'scope' => self::SCOPE,
                'expires_at' => $now->addYearNoOverflow()->format(DateTimeInterface::ATOM),
            ],
        ];

        OAuthSecret::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'client_id' => 'demo-client-id-'.$provider,
                'client_secret' => $this->shield->protect('demo-client-secret-'.$provider),
                'redirect_uri' => 'https://beatrax.test/oauth/callback/'.$provider,
                'tokens_blob' => $this->shield->protect(
                    (string) json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ),
            ],
        );
    }

    private function upsertKnownSender(User $user, string $emailPattern, string $label, CarbonImmutable $now): void
    {
        KnownSender::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'email_pattern' => $emailPattern,
            ],
            [
                'label' => $label,
                'source' => 'user',
                'added_at' => $now->subDays(3),
            ],
        );
    }

    private function upsertInboxMessage(
        User $user,
        Inbox $inbox,
        CarbonImmutable $now,
        DemoInboxMessage $message,
    ): InboxMessage {
        return InboxMessage::query()->updateOrCreate(
            [
                'inbox_id' => $inbox->id,
                'provider_message_id' => $message->providerMessageId,
            ],
            [
                'user_id' => $user->id,
                'internal_date' => $now->subHours($message->ageHours),
                'sender_email' => $message->senderEmail,
                'sender_name' => $message->senderName,
                'subject' => $message->subject,
                'status' => 'fetched',
                'fetched_at' => $now->subHours($message->ageHours - 1),
            ],
        );
    }

    private function upsertDiscoveredSender(
        User $user,
        Inbox $inbox,
        CarbonImmutable $now,
        string $senderEmail,
        string $senderName,
        string $state,
        int $occurrenceCount,
    ): void {
        DiscoveredSender::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'inbox_id' => $inbox->id,
                'sender_email' => $senderEmail,
            ],
            [
                'sender_name' => $senderName,
                'occurrence_count' => $occurrenceCount,
                'last_seen_at' => $now->subHours(8),
                'state' => $state,
            ],
        );
    }
}
