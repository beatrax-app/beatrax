<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * CatchUpProtocolTest — XPORT-02: catch-up exchange delivers missing ops.
 *
 * RED until Wave 3 ships PeerCatchUpExchanger under
 * Modules\Sync\Internal\Transport\PeerCatchUpExchanger.
 *
 * Validates the catch-up protocol (RESEARCH Pattern 6):
 *   On session establishment, both peers exchange their HLC watermarks.
 *   Each peer then sends all op_log_entries with (hlc_l, hlc_c) greater
 *   than the other's watermark. After the exchange both peers are up to date.
 *
 * Protocol summary:
 *   Initiator → Responder: CATCH_UP_REQUEST { my_hlc_l, my_hlc_c, device_id, user_id }
 *   Responder → Initiator: CATCH_UP_RESPONSE { ops: [...] where hlc > request watermark }
 *   Initiator → Responder: CATCH_UP_REQUEST (symmetric)
 *   Responder → Initiator: CATCH_UP_RESPONSE (symmetric)
 *   Both: CATCH_UP_COMPLETE → switch to LIVE_STREAM mode
 *
 * Gap detection: ops with (hlc_l, hlc_c) lexicographically > watermark.
 * Op delivery: batched at ≤ 1024 ops or ≤ 64KB per frame.
 *
 * Database: uses op_log_entries table (from Phase 11) for HLC state queries.
 */

it('catch-up request correctly identifies ops missing from the peer (HLC watermark comparison)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger'))->toBeFalse(
        'Wave 0 guard: PeerCatchUpExchanger must not exist yet — implement in Wave 3.'
    );
})->todo('Wave 3: given peer watermark W, PeerCatchUpExchanger returns all op_log_entries with (hlc_l,hlc_c) > W for user');

it('catch-up delivers missing ops to the peer and both ends converge', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: after bilateral CATCH_UP_REQUEST exchange over a loopback WS session, both peers have identical op sets');

it('catch-up with no gap (peer already up to date) sends an empty CATCH_UP_RESPONSE', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: when peer watermark equals local max (hlc_l, hlc_c), CATCH_UP_RESPONSE is empty and exchange completes');

it('catch-up batches large op sets into ≤ 64KB frames (backpressure guard)', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: 2000 ops → PeerCatchUpExchanger splits into multiple ≤ 64KB / ≤ 1024-op frames (RESEARCH Pattern 6)');

it('catch-up is user-scoped: ops from other users are never included in the CATCH_UP_RESPONSE', function (): void {
    expect(class_exists('Modules\\Sync\\Internal\\Transport\\PeerCatchUpExchanger'))->toBeFalse(
        'Wave 0 guard: deferred to Wave 3.'
    );
})->todo('Wave 3: user_id scope guard — catch-up for user A never returns ops belonging to user B');
