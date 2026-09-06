<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Core\Public\Support\BladePhpSource;

// A config file's code with every comment removed. The two blocks these guards
// read both spend a paragraph explaining what they must not do, and they
// explain it by naming the thing — `env(`, `NATIVEPHP_ANDROID_TARGET_SDK` —
// which a search of the raw text then finds in the prose that forbids it.
final class ConfigFileCode
{
    public static function at(string $path): string
    {
        $code = '';

        // Through the seam even though a config file is never a template: a
        // `.php` walk holds Blade too, and token_get_all reads one as a single
        // T_INLINE_HTML, so a scanner that skips the seam reports every
        // template clean without having read it.
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
