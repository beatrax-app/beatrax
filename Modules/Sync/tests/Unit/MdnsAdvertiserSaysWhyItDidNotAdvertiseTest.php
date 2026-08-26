<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Psr\Log\AbstractLogger;

function advertiserSpyLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->messages[] = $level.': '.$message;
        }

        public function said(string $needle): bool
        {
            foreach ($this->messages as $message) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }

            return false;
        }
    };
}

// A daemon spawned before the app was unlocked reaches advertise() with an empty
// device id. The UUID guard returned on it in silence, so the desktop was absent
// from `dns-sd -B` with nothing anywhere saying why — four rounds of a pairing
// code that "nothing on this network answered".
it('says so when it refuses a device id that is not a UUID', function (): void {
    $logger = advertiserSpyLogger();

    (new MdnsAdvertiser($logger))->advertise('', 51337);

    expect($logger->said('warning: mDNS advertise: refusing a device id that is not a UUID'))->toBeTrue();
});

it('announces the advertisement it does publish, so a silent LAN is distinguishable from an unpublished one', function (): void {
    $logger = advertiserSpyLogger();
    $advertiser = new MdnsAdvertiser($logger);

    $advertiser->advertise('11111111-2222-4333-8444-555555555555', 51337);

    try {
        // Hosts without dns-sd or avahi say the other half of the same thing:
        // either branch leaves a line, which is the whole point.
        expect($logger->said('mDNS advertise: publishing this device on the LAN')
            || $logger->said('mDNS advertise: no dns-sd or avahi-publish-service'))->toBeTrue();
    } finally {
        $advertiser->stop();
    }
});
