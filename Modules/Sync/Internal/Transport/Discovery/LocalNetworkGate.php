<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Public\Enums\LocalNetworkAccess;

// iOS blocks every LAN connection an app makes until the reader answers "Allow
// Beatrax to find devices on local networks?", and raises that prompt on the
// first attempt. Measured on an iPhone: the flow to the desktop closed having
// carried nothing, and the app was told only that nobody answered.
/**
 * @link ../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md#the-second-gate-and-the-one-a-reader-can-open
 */
final class LocalNetworkGate
{
    public const string STATUS_FUNCTION = 'LocalNetwork.Status';

    public const string REQUEST_FUNCTION = 'LocalNetwork.Request';

    public function __construct(private readonly NativeBridge $bridge) {}

    // The capability, never a config key: a declared NSLocalNetworkUsageDescription
    // says the app may ask, which is what every build already carries, and says
    // nothing about whether the reader said yes.
    public function access(): LocalNetworkAccess
    {
        $answer = $this->bridge->call(self::STATUS_FUNCTION, []);

        // Same envelope rule the browse follows: a success answer is the
        // function's own dict, unwrapped, and only a failure carries `status`.
        if ($answer === null || array_key_exists('status', $answer)) {
            return self::platformGate();
        }

        return ($answer['granted'] ?? null) === true
            ? LocalNetworkAccess::Granted
            : LocalNetworkAccess::Unconfirmed;
    }

    // Asks the platform to put its question to the reader now. Where no shell
    // implements it there is still one way to raise it, and it is the same one
    // that raises it by accident today: iOS gates on the attempt, so any LAN
    // probe makes the prompt appear.
    public function askTheReader(): bool
    {
        if ($this->bridge->supports(self::REQUEST_FUNCTION)) {
            return $this->bridge->call(self::REQUEST_FUNCTION, []) !== null;
        }

        return false;
    }

    // No shell can be asked, so the platform is all that is left to read. Only
    // iOS is known to hold LAN traffic behind a prompt the reader may not have
    // answered; nothing else here is called unconfirmed on suspicion.
    private static function platformGate(): LocalNetworkAccess
    {
        return UserDataPathService::platform() === MobilePlatform::Ios
            ? LocalNetworkAccess::Unconfirmed
            : LocalNetworkAccess::Granted;
    }
}
