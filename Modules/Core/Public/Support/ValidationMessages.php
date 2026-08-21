<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Validation\ValidationException;

// A rule's own message is the one worth showing; the caller's fallback key
// only covers an exception that carries no readable message at all, which
// is why the key is a parameter rather than a shared generic line.
final class ValidationMessages
{
    public static function first(ValidationException $exception, string $fallbackKey): string
    {
        /** @var array<string, mixed> $errors */
        $errors = $exception->errors();

        foreach ($errors as $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return Lang::get($fallbackKey);
    }
}
