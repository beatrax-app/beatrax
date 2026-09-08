<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Modules\Core\Public\Contracts\SystemLanguageSource;
use Native\Mobile\Facades\Device;
use Throwable;

// The language the phone is set to. Neither mobile shell puts it in a request
// header — measured on a Galaxy A51 whose OS was Dutch while every screen came
// back English — so without this the setting labelled "System" means English
// on a phone, whatever the phone says.
final readonly class NativeSystemLanguage implements SystemLanguageSource
{
    public function tag(): ?string
    {
        try {
            $info = Device::getInfo();
        } catch (Throwable) {
            return null;
        }

        $decoded = is_string($info) && $info !== '' ? json_decode($info, true) : null;
        $tag = is_array($decoded) ? $decoded['language'] ?? null : null;

        return is_string($tag) && trim($tag) !== '' ? trim($tag) : null;
    }
}
