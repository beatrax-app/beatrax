<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

final class GdkEpochWrapSignature
{
    // Domain-separation context prefixed onto every GDK_EPOCH_WRAP signing
    // message, so a signature minted here can never be replayed as valid input
    // to another signing domain even when the same Ed25519 device key signs
    // both (mirrors PairingFrame::SIG_CONTEXT).
    public const string SIG_CONTEXT = 'beatrax-gdk-epoch-wrap:v1';

    // What the sealed bytes are. Only the default is omitted from the signing
    // message, so an epoch wrap signs byte-identically to one a build without
    // roles produced, and that build rejects a role-bearing wrap rather than
    // adopting its key as an epoch.
    public const string ROLE_EPOCH = 'epoch';

    public const string ROLE_BLIND_INDEX = 'blind_index';

    // What a wrap carries, read from the envelope rather than inferred from
    // `epoch_id`. A blind-index wrap sends `epoch_id: 0` because the field is
    // required by the shape, and a reader that treated that as an epoch id
    // would count one epoch too many.
    /**
     * @param  array<string, mixed>  $wrap
     */
    public static function roleOf(array $wrap): string
    {
        $role = $wrap['key_role'] ?? self::ROLE_EPOCH;

        return is_string($role) ? $role : self::ROLE_EPOCH;
    }

    /**
     * @param  array<string, mixed>  $wrap
     */
    public static function carriesEpoch(array $wrap): bool
    {
        return self::roleOf($wrap) === self::ROLE_EPOCH;
    }

    // The canonical message a wrap's Ed25519 signature covers: the sealed key
    // bytes, epoch id, and both device ids — binding all of them makes a
    // signature non-replayable into another epoch, recipient, or sender. Each
    // variable-length field is length-prefixed so a '|' in one cannot shift it.
    public static function signingMessage(
        int $epochId,
        string $sealedKeyBin,
        string $recipientDeviceId,
        string $senderDeviceId,
        string $role = self::ROLE_EPOCH,
        bool $senderHoldsKeyedRows = false,
    ): string {
        $parts = [
            self::SIG_CONTEXT,
            'epoch:'.$epochId,
            strlen($sealedKeyBin).':'.$sealedKeyBin,
            strlen($recipientDeviceId).':'.$recipientDeviceId,
            strlen($senderDeviceId).':'.$senderDeviceId,
        ];

        // Both terms, in both states, but only for a non-default role: an epoch
        // wrap signs byte-identically to one a build without roles produced,
        // while flipping either term on a blind-index wrap breaks the signature
        // rather than changing which key the recipient decides to keep.
        if ($role !== self::ROLE_EPOCH) {
            $parts[] = 'role:'.$role;
            $parts[] = 'keyed:'.($senderHoldsKeyedRows ? '1' : '0');
        }

        return implode('|', $parts);
    }
}
