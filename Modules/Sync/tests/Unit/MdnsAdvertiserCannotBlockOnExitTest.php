<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Symfony\Component\Process\Process;

// Symfony's Process::__destruct() calls stop() → close(), which reads the child's
// pipes via stream_select() with no timeout, and dns-sd is long-lived by design,
// so a destructor at worker shutdown could sit in select() forever — which is what
// capped this suite's parallelism. Disabling output removes the pipes entirely.
it('creates its advertising process with no pipes to block on', function (): void {
    $advertiser = new MdnsAdvertiser;

    $advertiser->advertise('11111111-2222-4333-8444-555555555555', 51337);

    $property = new ReflectionProperty(MdnsAdvertiser::class, 'process');
    $process = $property->getValue($advertiser);

    if (! $process instanceof Process) {
        // Neither dns-sd nor avahi-publish-service on this host, so there is
        // no process to make safe — the advertiser no-ops by design.
        $advertiser->stop();

        test()->markTestSkipped('no mDNS binary available on this host');
    }

    try {
        expect($process->isOutputDisabled())->toBeTrue();
    } finally {
        $advertiser->stop();
    }
});
