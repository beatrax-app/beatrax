<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Notifications;

use Modules\Core\Public\Services\UserDataPathService;

// The device half. `LocalNotification.CheckPermission` is added to the plugin
// by scripts/nativephp_notification_grant_is_read_back.php; nativephp_can()
// is what separates a shell that cannot be asked from one that answered no.
final class BridgeNotificationSwitch implements PlatformNotificationSwitch
{
    public const string FUNCTION = 'LocalNotification.CheckPermission';

    public function enabled(): ?bool
    {
        return $this->reachable() ? self::readAnswer(nativephp_call(self::FUNCTION, '{}')) : null;
    }

    // Typed mixed rather than ?string: off a device the answer comes from a C
    // extension, and anything that is not the shape below is "nobody answered"
    // rather than a refusal — a bridge error envelope carries no granted key.
    public static function readAnswer(mixed $answer): ?bool
    {
        $decoded = is_string($answer) && $answer !== '' ? json_decode($answer, true) : null;
        $granted = is_array($decoded) ? ($decoded['granted'] ?? null) : null;

        return is_bool($granted) ? $granted : null;
    }

    private function reachable(): bool
    {
        return UserDataPathService::isMobileRuntime()
            && function_exists('nativephp_can')
            && function_exists('nativephp_call')
            && nativephp_can(self::FUNCTION);
    }
}
