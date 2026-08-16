<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Symfony\Component\Process\Process;

/*
 * The mDNS advertiser must not be able to hang the process that owns it.
 *
 * Symfony's Process::__destruct() calls stop(), which calls close(), which
 * reads the child's pipes via stream_select() with NO timeout. A pipe reaches
 * EOF only once every holder of its write end is gone, and dns-sd is
 * long-lived by design — so a destructor running at worker shutdown could sit
 * in select() forever.
 *
 * That is what capped this suite's parallelism: above four workers `composer
 * test` stopped returning, workers alive and silent with no summary ever
 * printed (see the cap in composer.json's history). A sampled survivor of one
 * of those hangs was parked in exactly this stack —
 * zend_objects_destroy_object → … → stream_select → __select.
 *
 * Nothing reads this process's output, so disabling it removes the pipes and
 * with them the only thing the destructor could block on.
 */
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
