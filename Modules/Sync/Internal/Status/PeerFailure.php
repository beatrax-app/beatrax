<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Status;

// One reading of a session's error_message, shared by the two surfaces that
// need it. Read apart, the banner said "Sync error on one or more devices"
// above a row this same table had already labelled "Can't reach peer".
final class PeerFailure
{
    // Ordered most specific first: a message naming both the relay and a
    // timeout is reported as a relay problem. Each row carries a set of
    // needles, since the same failure reaches us phrased several ways.
    /**
     * @var list<array{needles: list<string>, label: string, kind: PeerFailureKind}>
     */
    private const array READINGS = [
        [
            'needles' => ['relay'],
            'label' => 'sync::status.labels.relay_unreachable',
            'kind' => PeerFailureKind::Unreachable,
        ],
        // 'authentication' is deliberately absent: it contains 'auth', so a
        // needle for it could never match anything the shorter one missed.
        [
            'needles' => ['handshake', 'verify', 'auth'],
            'label' => 'sync::status.labels.handshake_failed',
            'kind' => PeerFailureKind::Verification,
        ],
        [
            'needles' => ['connection', 'connect', 'reach', 'timeout'],
            'label' => 'sync::status.labels.cannot_reach_peer',
            'kind' => PeerFailureKind::Unreachable,
        ],
    ];

    private const string UNRECOGNISED = 'sync::status.labels.connection_failed';

    // Unreachable for a message this build has no reading of, and for no
    // message at all: a peer that cannot be reached is normal, and reporting a
    // failure nobody could classify as a fault is the reading to avoid.
    public static function kind(?string $message): PeerFailureKind
    {
        $reading = self::reading($message);

        return $reading === null ? PeerFailureKind::Unreachable : $reading['kind'];
    }

    public static function labelKey(?string $message): string
    {
        $reading = self::reading($message);

        return $reading === null ? self::UNRECOGNISED : $reading['label'];
    }

    /**
     * @return array{needles: list<string>, label: string, kind: PeerFailureKind}|null
     */
    private static function reading(?string $message): ?array
    {
        $lower = strtolower($message ?? '');

        foreach (self::READINGS as $candidate) {
            foreach ($candidate['needles'] as $needle) {
                if (str_contains($lower, $needle)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
