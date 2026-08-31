<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Enums\CommunitySetting;

// Public because the panel that writes these toggles and the consumers that
// gate on them sit in different modules: a gate parked inside the Livewire
// component that draws the switch is a gate no consumer can reach, and the
// switch it draws then decides nothing.
final readonly class CommunitySettings
{
    public function __construct(private DatabaseManager $db) {}

    public function usesSharedList(int $userId): bool
    {
        return $this->enabled(CommunitySetting::UseSharedList, $userId);
    }

    public function offersToContribute(int $userId): bool
    {
        return $this->enabled(CommunitySetting::OfferToContribute, $userId);
    }

    // Read per call, not memoised per reader the way the country beside it is:
    // an opt-out answered from a cache no sync write and no second process can
    // drop is an opt-out that keeps sharing after the reader switched it off.
    public function enabled(CommunitySetting $setting, int $userId): bool
    {
        return self::readFrom($this->settingsFor($userId), $setting);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function readFrom(array $settings, CommunitySetting $setting): bool
    {
        return array_key_exists($setting->value, $settings)
            ? (bool) $settings[$setting->value]
            : $setting->default();
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(int $userId): array
    {
        $stored = $this->db->connection()->table('users')->where('id', $userId)->value('community_settings');
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        if (! is_array($decoded)) {
            return [];
        }

        // json_decode gives array<mixed, mixed>; a JSON object's keys are always
        // strings, but nothing in the type says so and the callers index by name.
        $settings = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }
}
