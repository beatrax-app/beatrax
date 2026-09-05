<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\PairingAcceptRefusal;
use Modules\Sync\Public\Enums\PairingOfferLookup;

// The lines the desktop pairing screen shows when it refuses, kept together
// because the rule behind them is one rule: no line may name a cause this
// device did not observe. Each ending gets the sentence that is true of it,
// and two of them are only distinguishable by asking something else first.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-phone-can-only-be-scanned
 */
final readonly class PairingRefusalCopy
{
    // "Unlock and try again" is a lie for a key-file no unlock can open, and
    // the pairing screen is exactly where a user would keep trying. The copy
    // for that state belongs to the settings section, which is where the way
    // out of it is offered.
    private const string IDENTITY_UNREADABLE_MESSAGE = 'sync::devices.identity_unreadable';

    // Translation key rather than literal copy, so the const stays free of the
    // banned container call.
    private const string IDENTITY_LOCKED_MESSAGE = 'sync::pairing.identity_locked';

    public function __construct(
        private DeviceIdentityLoader $identityLoader,
        private PeerDiscovery $discovery,
    ) {}

    // Asked only once a load() already came back empty, so the extra read is
    // paid on the refusal path alone.
    public function identityUnavailable(int $userId, Session $session): string
    {
        return $this->identityLoader->state($userId, $session) === DeviceIdentityState::Unreadable
            ? Lang::get(self::IDENTITY_UNREADABLE_MESSAGE)
            : Lang::get(self::IDENTITY_LOCKED_MESSAGE);
    }

    // The one line saying a code is unknown or expired, and the two endings
    // that must never reach it: this device is already past accept for that
    // code, or the device that minted it answered for it moments ago. Sending
    // either reader off for a fresh code abandons a ceremony that was live.
    public function acceptRefusal(PairingAcceptRefusal $refusal): string
    {
        return Lang::get(match ($refusal) {
            PairingAcceptRefusal::AlreadyUnderWay => 'sync::pairing.already_under_way',
            PairingAcceptRefusal::VouchedByIssuer => 'sync::pairing.vouched_but_refused',
            PairingAcceptRefusal::NotLiveHere => 'sync::pairing.invalid_code',
        });
    }

    // A typed code names no device, so a peer that answered and refused may
    // simply be the wrong one on this network. A silence where the question
    // reached the network is a silence; a silence where it never left the
    // device is an unasked question, which is a different sentence.
    public function offerLookupRefusal(PairingOfferLookup $lookup): string
    {
        return Lang::get(match ($lookup) {
            PairingOfferLookup::CodeNotAccepted => 'sync::pairing.code_not_accepted',
            PairingOfferLookup::CodeMalformed => 'sync::pairing.code_incomplete',
            PairingOfferLookup::NoPeerReached => $this->discovery->reach()->silenceMeansNoPeers()
                ? 'sync::pairing.no_peer_answered'
                : 'sync::pairing.no_peer_search',
            PairingOfferLookup::RateLimited => 'sync::pairing.rate_limited',
        });
    }
}
