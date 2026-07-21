<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\EmailScan\Models\DiscoveredSender;
use Modules\EmailScan\Models\Inbox;
use Modules\EmailScan\Models\InboxMessage;
use Modules\EmailScan\Models\InboxScanState;
use Modules\EmailScan\Models\KnownSender;
use Modules\EmailScan\Models\OAuthSecret;

/**
 * @link ../../../../../.docs/features/email-scan/architecture.md
 */
final class DemoEmailScanSeeder
{
    private const GMAIL_EMAIL = 'demo-1+gmail@beatrax.local';

    private const MS_EMAIL = 'demo-1+microsoft@beatrax.local';

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary === null) {
            return 0;
        }

        $gmail = $this->upsertInbox($primary, 'gmail', self::GMAIL_EMAIL);
        $microsoft = $this->upsertInbox($primary, 'microsoft', self::MS_EMAIL);

        $this->upsertScanState($primary, $gmail, lastHistoryId: 'gmail-cursor-1234', lastDeltaLink: null);
        $this->upsertScanState($primary, $microsoft, lastHistoryId: null, lastDeltaLink: 'https://graph.microsoft.com/v1.0/me/messages/delta?$skiptoken=demo-cursor');

        $this->upsertOAuthSecret($primary, 'gmail');
        $this->upsertOAuthSecret($primary, 'microsoft');

        $this->upsertKnownSender($primary, 'subscriptions@spotify.com', 'Spotify subscriptions');
        $this->upsertKnownSender($primary, 'noreply@bol.com', 'Bol.com receipts');

        $messageGmail1 = $this->upsertInboxMessage(
            $primary,
            $gmail,
            providerMessageId: 'gmail-msg-1',
            senderEmail: 'subscriptions@spotify.com',
            senderName: 'Spotify',
            subject: 'Your Spotify Premium receipt',
            ageHours: 6,
        );
        $messageGmail2 = $this->upsertInboxMessage(
            $primary,
            $gmail,
            providerMessageId: 'gmail-msg-2',
            senderEmail: 'noreply@bol.com',
            senderName: 'Bol.com',
            subject: 'Order shipped — Bol.com',
            ageHours: 30,
        );
        $messageMs1 = $this->upsertInboxMessage(
            $primary,
            $microsoft,
            providerMessageId: 'microsoft-msg-1',
            senderEmail: 'noreply@booking.com',
            senderName: 'Booking.com',
            subject: 'Your booking confirmation',
            ageHours: 12,
        );

        $this->upsertDiscoveredSender(
            $primary,
            $gmail,
            'mailings@hema.nl',
            'HEMA Mailings',
            sampleMessageId: $messageGmail1->id,
            state: 'candidate',
            occurrenceCount: 3,
        );
        $this->upsertDiscoveredSender(
            $primary,
            $gmail,
            'support@coolblue.nl',
            'Coolblue Support',
            sampleMessageId: $messageGmail2->id,
            state: 'added',
            occurrenceCount: 7,
        );
        $this->upsertDiscoveredSender(
            $primary,
            $microsoft,
            'noreply@aliexpress.com',
            'AliExpress',
            sampleMessageId: $messageMs1->id,
            state: 'dismissed',
            occurrenceCount: 1,
        );

        return Inbox::query()
            ->where('user_id', $primary->id)
            ->count();
    }

    private function upsertInbox(User $user, string $provider, string $email): Inbox
    {
        /** @var Inbox $inbox */
        $inbox = Inbox::query()->updateOrCreate(
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

        return $inbox;
    }

    private function upsertScanState(
        User $user,
        Inbox $inbox,
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
                'last_scan_at' => CarbonImmutable::now()->subHours(2),
                'status' => 'idle',
                'error_message' => null,
                'retry_attempts' => 0,
            ],
        );
    }

    private function upsertOAuthSecret(User $user, string $provider): void
    {
        OAuthSecret::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'client_id' => 'demo-client-id-'.$provider,
                'client_secret' => 'demo-client-secret-'.$provider,
                'redirect_uri' => 'https://beatrax.test/oauth/callback/'.$provider,
                'tokens_blob' => '{"access_token":"demo-access","refresh_token":"demo-refresh"}',
            ],
        );
    }

    private function upsertKnownSender(User $user, string $emailPattern, string $label): void
    {
        KnownSender::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'email_pattern' => $emailPattern,
            ],
            [
                'label' => $label,
                'source' => 'user',
                'added_at' => CarbonImmutable::now()->subDays(3),
            ],
        );
    }

    private function upsertInboxMessage(
        User $user,
        Inbox $inbox,
        string $providerMessageId,
        string $senderEmail,
        string $senderName,
        string $subject,
        int $ageHours,
    ): InboxMessage {
        $now = CarbonImmutable::now();

        /** @var InboxMessage $message */
        $message = InboxMessage::query()->updateOrCreate(
            [
                'inbox_id' => $inbox->id,
                'provider_message_id' => $providerMessageId,
            ],
            [
                'user_id' => $user->id,
                'internal_date' => $now->subHours($ageHours),
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'subject' => $subject,
                'status' => 'fetched',
                'fetched_at' => $now->subHours($ageHours - 1),
            ],
        );

        return $message;
    }

    private function upsertDiscoveredSender(
        User $user,
        Inbox $inbox,
        string $senderEmail,
        string $senderName,
        int $sampleMessageId,
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
                'last_seen_at' => CarbonImmutable::now()->subHours(8),
                'sample_message_id' => $sampleMessageId,
                'state' => $state,
            ],
        );
    }
}
