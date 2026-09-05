<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SystemClock;
use Modules\Sync\Internal\Pairing\DeviceIntroductionService;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

// Both ends of an introduction, kept together because they are one decision
// read from two sides: the authors a device tells a peer it can verify, and the
// identities that peer offers back for the authors it therefore withheld.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class IntroductionOffers
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $log,
        private DeviceRegistryService $registry,
        private DeviceIntroductionService $introductions,
    ) {}

    public static function forDatabase(DatabaseManager $db, LoggerInterface $log): self
    {
        $registry = new DeviceRegistryService($db);

        return new self($db, $log, $registry, new DeviceIntroductionService($db, $registry, new SystemClock));
    }

    // Exactly the map the op-log verifier admits on, so this device cannot
    // advertise an author it will then refuse. Read through the registry rather
    // than assembled here for that reason and no other.
    public function verifiableAuthorsFor(int $userId): VerifiableAuthors
    {
        return VerifiableAuthors::of(array_keys($this->registry->signatureVerificationKeys($userId)));
    }

    // What the filter took out, with an introduction for each author this
    // device has itself confirmed. Nothing is offered for a device this device
    // merely retains a key for: vouching for one nothing here confirms is not
    // this side's to do, and E2-R18 says a CONFIRMED device may be relayed.
    /**
     * @param  array<string, int>  $counts  author device id => entries withheld.
     * @param  string  $peerDeviceId  Named so the log line says who was refused what.
     */
    public function forWithheld(int $userId, array $counts, string $peerDeviceId): WithheldHistory
    {
        if ($counts === []) {
            return WithheldHistory::none();
        }

        $withheld = WithheldHistory::of($counts, $this->introductionsFor($userId, array_keys($counts)));

        // Error, not warning, for the reason PeerCatchUpExchanger's unframable
        // report is one: a withholding nothing announces reads as an ordinary
        // clean sync from every surface above it. The peer is told the counts
        // too — this line is for the reader of THIS device's log.
        $this->log->error('IntroductionOffers: op-log entries withheld from a peer that cannot verify their author.', [
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
            'withheld' => $withheld->total(),
            'authors' => $withheld->authors(),
            'introduced' => count($withheld->introductions),
        ]);

        return $withheld;
    }

    /**
     * @param  list<string>  $deviceIds
     * @return list<array{device_id: string, name: string, ed25519_public_key_hex: string}>
     */
    private function introductionsFor(int $userId, array $deviceIds): array
    {
        $confirmed = $this->registry->deviceKeys($userId);

        $names = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->whereIn('device_id', $deviceIds)
            ->pluck('name', 'device_id')
            ->all();

        $offers = [];

        foreach ($deviceIds as $deviceId) {
            $keyHex = $confirmed[$deviceId] ?? null;
            $name = $names[$deviceId] ?? null;

            if (is_string($keyHex) && $keyHex !== '' && is_string($name)) {
                $offers[] = ['device_id' => $deviceId, 'name' => $name, 'ed25519_public_key_hex' => $keyHex];
            }
        }

        return $offers;
    }

    // Stores what the answering peer withheld and offered, as an unconfirmed
    // introduction each. Nothing here verifies anything: the reader confirms it
    // on the device list, having seen the fingerprint and who vouched for it.
    /**
     * @param  array<string, mixed>  $response  A parsed CATCH_UP_RESPONSE.
     * @return int How many introductions were stored or refreshed.
     */
    public function record(int $userId, array $response, string $peerDeviceId): int
    {
        $withheld = WithheldHistory::fromWire($response);

        if ($withheld->introductions === []) {
            return 0;
        }

        $this->reportRefusedForRemovedDevices($userId, $withheld);

        return $this->introductions->record($userId, $peerDeviceId, $withheld->introductions, $withheld->counts);
    }

    // The one withholding no screen can offer to end. A peer still confirms an
    // author this install removed, so it vouches; the removal stands and the
    // service refuses the row. Said out loud because the alternative is the
    // silence this whole exchange exists to remove.
    private function reportRefusedForRemovedDevices(int $userId, WithheldHistory $withheld): void
    {
        $revokedHere = array_diff_key(
            $this->registry->retainedDeviceKeys($userId),
            $this->registry->deviceKeys($userId),
        );

        $refused = array_values(array_intersect(
            array_column($withheld->introductions, 'device_id'),
            array_keys($revokedHere),
        ));

        if ($refused === []) {
            return;
        }

        $this->log->warning('IntroductionOffers: a peer vouched for a device this install removed; the removal stands.', [
            'user_id' => $userId,
            'device_ids' => $refused,
            'withheld' => array_sum(array_intersect_key($withheld->counts, array_flip($refused))),
        ]);
    }
}
