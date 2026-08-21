<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire\Concerns;

use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Core\Public\Support\ValidationMessages;

// The rejection a signup form gets back names fields the form knows, so it is
// placed on them rather than summarised. `FIELD_KEYS` is the using component's
// own box list, and a message under a key not in it has no box to sit under
// and falls through to the form-level line.
/**
 * @phpstan-require-extends Component
 */
trait ReportsFieldRejections
{
    use HoldsFlashMessage;

    // Field-scoped, so the message renders under the box it is about and the
    // control carries aria-invalid. One shared form-level line put the
    // username error below two other fields and the checklist, out of sight
    // behind a raised keyboard and unannounced on the field it described.
    protected function reportRejection(ValidationException $exception, string $fallbackKey): void
    {
        $placed = false;
        $errors = $exception->validator->errors()->messages();

        foreach (static::FIELD_KEYS as $field) {
            foreach ($errors[$field] ?? [] as $message) {
                $this->addError($field, $message);
                $placed = true;
            }
        }

        if (! $placed) {
            $this->flashMessage = ValidationMessages::first($exception, $fallbackKey);
        }
    }
}
