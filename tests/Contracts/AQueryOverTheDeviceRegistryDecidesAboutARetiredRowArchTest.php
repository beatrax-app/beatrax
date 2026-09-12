<?php

declare(strict_types=1);

use Tests\Contracts\Support\DeviceRegistryQueries;

// A restore carries the old machine's self row and never its key-file. The
// repair takes is_self off that row and leaves confirmed_at alone, because the
// restored op log is signed by that device_id and confirmed_at is what a
// rebuild verifies it against. So one row has to read two opposite ways, and
// neither reading can be the default: filter the stamp out of a history check
// and a restored ledger is unverifiable; leave it in a peer list and a machine
// that is gone still admits a session, still collects a key epoch, still gets
// dialled.
//
// The stamp shipped with three readers taught about it and fifty queries that
// were not, which is how the gap it exists to close stayed open. This is the
// guard that makes the next device_registry query say which reading it wants.
// @link ../../.docs/features/sync/device-identity-key-files.md#what-the-repair-retires-and-what-it-must-not

// A scan that matched nothing reports exactly what a clean tree reports, and
// this one walks a table named across three modules. Measured at 55.
const RETIRED_ROW_QUERY_FLOOR = 50;

// The set the stamp must never narrow. A rebuild admits an op on a confirmed
// key and nothing else, so a filter written into any of these is the far worse
// half of this bug: every op the restored machine ever signed quarantines.
const RETIRED_ROW_VERIFIES_HISTORY_HERE = [
    'Modules/Sync/Public/Services/DeviceRegistryService.php::deviceKeys' => [
        'queries' => 1,
        'reason' => 'the confirmed signing map, and the one this whole separation exists for. It is also what an introduction offer is composed from, so the retired machine stays vouchable to a peer holding history only it can verify',
    ],
    'Modules/Sync/Public/Services/DeviceRegistryService.php::signatureVerificationKeys' => [
        'queries' => 1,
        'reason' => 'names every device this registry holds a row for, in any state, so an introduction cannot shadow one. A retired row IS such a row, and hiding it here would re-admit a second grant beside the one the repair left standing',
    ],
    'Modules/Sync/Public/Services/DeviceRegistryService.php::authorIdsWithAKeyOnFile' => [
        'queries' => 1,
        'reason' => 'the authors this device may CARRY ops for. Dropping the retired machine strands its history on whichever peer is holding it',
    ],
    'Modules/Sync/Public/Services/DeviceRegistryService.php::retainedDeviceKeys' => [
        'queries' => 1,
        'reason' => 'confirmed or revoked, the map that verifies history and never the one that admits a peer. The stamp is the same distinction spelled a second way',
    ],
    'Modules/Sync/Internal/Http/Livewire/IntroducedDevicesSection.php::reload' => [
        'queries' => 1,
        'reason' => 'names the device that vouched for an introduction. The retired machine can be that voucher in a restored database, and a reader asked who to trust is owed the name rather than a bare id',
    ],
    'Modules/Sync/Internal/Transport/IntroductionOffers.php::introductionsFor' => [
        'queries' => 1,
        'reason' => 'the name beside a vouched-for key, read for exactly the ids deviceKeys() already admitted. Narrowing it composes an offer with no name and drops it',
    ],
    'Modules/Auth/Public/Actions/PurgeUserDataAction.php::deviceIdsOf' => [
        'queries' => 2,
        'reason' => 'account deletion, which is the one sweep that must reach every id this account ever held: relay_mailbox is addressed by device id, and a row skipped here is a row nothing later can name',
    ],
];

// Queries a retired row cannot arrive at, or arrives at past a gate that has
// already turned it away. Each says which, because "it cannot happen" is the
// claim that rots.
const RETIRED_ROW_CANNOT_ARRIVE_HERE = [
    'Modules/Sync/Public/Services/DeviceRegistryService.php::holdsRevokedDeviceWithKeyAgreementKey' => [
        'queries' => 1,
        'reason' => 'asks for a row with the confirmation taken OFF. The retirement deliberately leaves it on, so a retired row matches nothing here whatever the stamp says',
    ],
    'Modules/Sync/Public/Services/DeviceRegistryService.php::purge' => [
        'queries' => 1,
        'reason' => 'the sweep runs only for a device id the lookup above resolved, and that lookup passes through stillADevice(). A retired row resolves to nothing and this clearing never runs',
    ],
    'Modules/Sync/Internal/Crypto/GdkRotationService.php::rotateAndRevoke' => [
        'queries' => 1,
        'reason' => 'the revoke write, reached only past refuseAnIdNoListOffers() — which turns away the acting device and the retired row whose confirmation a rebuild needs',
    ],
    'Modules/Sync/Internal/Crypto/GdkRotationService.php::markEpochsDelivered' => [
        'queries' => 1,
        'reason' => 'stamps the peer a fan-out just reached. resolveFanOutRecipient() and deviceX25519Keys() are the two doors into that fan-out and both leave a retired row out',
    ],
    'Modules/Sync/Public/Http/Livewire/DevicesAndSyncSettingsSection.php::removeDevice' => [
        'queries' => 1,
        'reason' => 'the screen-level self-removal flash. Both ids no list offers are refused authoritatively in rotateAndRevoke(), which a crafted call cannot skip and this read is not relied on for',
    ],
    'Modules/Mobile/Internal/Sync/InitialSyncPuller.php::peerRevokedUs' => [
        'queries' => 1,
        'reason' => 'asks for paired-then-unconfirmed, which is revocation. A retired row keeps its confirmation and so reads as neither',
    ],
    'Modules/Sync/Internal/Transport/SyncSession.php::touchLastSeen' => [
        'queries' => 2,
        'reason' => 'bookkeeping for the peer of an open session, and deviceX25519Keys() is what opened it. A retired row never reaches a handshake, so it never reaches this stamp',
    ],
    'Modules/Sync/Internal/Identity/DeviceIdentityService.php::restoreSelfRow' => [
        'queries' => 2,
        'reason' => 'both narrow to the device_id of the key-file in hand. A key-file that answers for a retired row is the machine itself back, and the row it is updated with clears the stamp rather than stepping over it',
    ],
    'Modules/Sync/Internal/Pairing/PairedDeviceAdmitter.php::admitDevice' => [
        'queries' => 2,
        'reason' => 'a lookup narrowed to the peer of a completed ceremony and the insert that mints a row for a new one. A ceremony naming a retired row says that machine is here and answering, and the update between them clears the stamp',
    ],
    'Modules/Sync/Internal/Http/Livewire/Concerns/ManagesDeviceRenaming.php::renameDevice' => [
        'queries' => 1,
        'reason' => 'renames by an id the device list handed the reader, and that list leaves a retired row out. A name decides nothing about trust either way',
    ],
    'Modules/Sync/Public/Services/PeerLanAddressBook.php::recall' => [
        'queries' => 1,
        'reason' => 'one explicit device id, supplied by a caller that already chose a live peer. An address is where a device was reached, never whether it may be',
    ],
    'Modules/Sync/Public/Services/PeerLanAddressBook.php::remember' => [
        'queries' => 1,
        'reason' => 'writes back the address a dial that already succeeded used, for that one device id',
    ],
    'Modules/Sync/Public/Services/PeerLanAddressBook.php::manual' => [
        'queries' => 1,
        'reason' => 'what a reader typed for one device the settings screen named, and that screen names no retired row',
    ],
    'Modules/Sync/Public/Services/PeerLanAddressBook.php::setManual' => [
        'queries' => 1,
        'reason' => 'stores what a reader typed against the device id the same screen chose',
    ],
    'Modules/Sync/Public/Services/PeerLanAddressBook.php::forget' => [
        'queries' => 1,
        'reason' => 'clears the remembered address of a dial that failed, for the device id it was dialling',
    ],
];

it('makes every device_registry query answer for a retired row', function (): void {
    $queries = DeviceRegistryQueries::all();

    expect(count($queries))->toBeGreaterThanOrEqual(RETIRED_ROW_QUERY_FLOOR, sprintf(
        'the walk found %d statements naming device_registry, under a floor of %d. A scan that stopped reading '
        .'reports exactly what a tree with nothing wrong reports.',
        count($queries),
        RETIRED_ROW_QUERY_FLOOR,
    ));

    $pinned = [...RETIRED_ROW_VERIFIES_HISTORY_HERE, ...RETIRED_ROW_CANNOT_ARRIVE_HERE];
    $undecided = [];

    foreach ($queries as $query) {
        $key = DeviceRegistryQueries::keyFor($query['path'], $query['function']);

        if (! $query['decides'] && ! array_key_exists($key, $pinned)) {
            $undecided[] = sprintf('%s — %s', $key, $query['statement']);
        }
    }

    expect($undecided)->toBe([], 'A row the restore repair retired is confirmed and is not a device, and a query '
        .'over device_registry has to take one of those readings. Either narrow it — stillADevice($query), '
        ."whereNull('self_retired_at'), or a where on is_self 1, which the retirement takes off — or pin it here "
        .'with the reason the retired row belongs in the answer. A blanket filter would make a restored history '
        .'unverifiable; a blanket skip leaves a machine that is gone holding a session. Undecided: '
        .implode(' | ', $undecided));
});

it('holds every pin to a query that is still there and still undecided', function (): void {
    $counted = [];

    foreach (DeviceRegistryQueries::all() as $query) {
        if (! $query['decides']) {
            $key = DeviceRegistryQueries::keyFor($query['path'], $query['function']);
            $counted[$key] = ($counted[$key] ?? 0) + 1;
        }
    }

    $wrong = [];

    foreach ([...RETIRED_ROW_VERIFIES_HISTORY_HERE, ...RETIRED_ROW_CANNOT_ARRIVE_HERE] as $key => $pin) {
        $found = $counted[$key] ?? 0;

        if ($found !== $pin['queries']) {
            $wrong[] = sprintf('%s pins %d, the tree holds %d', $key, $pin['queries'], $found);
        }
    }

    expect($wrong)->toBe([], 'A pin covers a counted number of queries so that a new one added beside it inherits '
        .'nothing. A count that has grown is a query nobody decided about; a count that has fallen, or fallen to '
        .'zero, is a pin that has outlived what earned it: '.implode(' | ', $wrong));
});

// The one disagreement between the two key maps, asserted from both sides. The
// handshake map has to narrow and the signing map has to not, and an edit that
// makes them agree breaks one of the two things this row is for.
it('keeps the handshake map narrowed and the signing map whole', function (): void {
    $decided = [];

    foreach (DeviceRegistryQueries::all() as $query) {
        if ($query['path'] === 'Modules/Sync/Public/Services/DeviceRegistryService.php') {
            $decided[$query['function']] = $query['decides'];
        }
    }

    // toHaveKey() takes an expected VALUE second, never a message, so the two
    // anchors are asserted as keys of their own before either is read.
    expect(array_keys($decided))
        ->toContain('deviceX25519Keys')
        ->toContain('deviceKeys');

    expect($decided['deviceX25519Keys'])->toBeTrue(
        'the Noise static key of a machine the database was RESTORED FROM admitted a session, because the row '
        .'kept the confirmation a rebuild needs and confirmed was all this map asked for',
    )
        ->and($decided['deviceKeys'])->toBeFalse(
            'the signing map is where the retired row has to stay. Narrowing it the way the handshake map is '
            .'narrowed quarantines every op the restored machine ever signed, which is a far worse fault than '
            .'the one the narrowing fixes',
        );
});

// Everything above is the reader's verdict, so the reader is driven over a
// planted source of each kind rather than trusted.
it('reads a decision written either side of the table name', function (): void {
    $read = static function (string $body): array {
        $found = DeviceRegistryQueries::in('Planted.php', '<?php class Planted { public function ask() { '.$body.' } }');

        expect($found)->toHaveCount(1, 'the planted source names the table once, so the reader has to find it once');

        return $found[0];
    };

    expect($read("return \$db->table('device_registry')->where('user_id', \$id)->get();")['decides'])
        ->toBeFalse('a query saying nothing about the stamp is the whole subject of this rule, and it read as decided');

    expect($read("return \$db->table('device_registry')->whereNull('self_retired_at')->get();")['decides'])
        ->toBeTrue('a filter written after the table name is a decision, and it was not read as one');

    expect($read("return \$this->stillADevice(\$db->table('device_registry')->where('user_id', \$id))->get();")['decides'])
        ->toBeTrue('the seam wraps the query, so a reader that only looks forward from the table name misses every use of it');

    expect($read("return \$db->table('device_registry')->where('is_self', 1)->value('device_id');")['decides'])
        ->toBeTrue('the retirement takes is_self off, so a query narrowed to the self row cannot reach a retired one');

    expect($read("return \$db->table('device_registry')->where('is_self', 0)->get();")['decides'])
        ->toBeFalse('a retired row IS is_self 0, so narrowing to the peers is the query that needs a decision most');

    expect($read("\$db->table('device_registry')->where('id', \$id)->update(['name' => \$name]);")['function'])
        ->toBe('ask', 'a query is pinned by the function it sits in, so a reader that cannot name that function pins nothing');

    // A chain carrying a closure, whose own statements must not end the read
    // and whose separators must not start one.
    $carried = $read(
        "\$db->table('device_registry')->where(function (\$q) { \$q->where('a', 1)->orWhere('b', 2); })"
        ."->whereNull('self_retired_at')->delete();"
    );

    expect($carried['decides'])->toBeTrue(
        'the filter sits past a closure, and a read that stopped at the closure\'s own semicolon would report '
        .'this query as having decided nothing',
    );
});
