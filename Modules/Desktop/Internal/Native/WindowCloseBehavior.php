<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;

// users.close_behavior: null shows the prompt, 'quit' quits, 'tray' hides to the
// menu bar keeping the bundled worker + scheduler alive. The quit/hide calls
// themselves live in ApplyCloseWindowChoice.
final readonly class WindowCloseBehavior
{
    public const string CHOICE_QUIT = 'quit';

    public const string CHOICE_TRAY = 'tray';

    private const array ALLOWED_CHOICES = [self::CHOICE_QUIT, self::CHOICE_TRAY];

    public function __construct(
        private WriteUserPreference $writeUserPreference,
    ) {}

    public function shouldPromptFor(User $user): bool
    {
        return $user->close_behavior === null;
    }

    public function choiceFor(User $user): ?string
    {
        return $user->close_behavior;
    }

    /**
     * @throws InvalidArgumentException when $choice is outside the {quit, tray} allow-list.
     */
    public function persistChoice(User $user, string $choice): void
    {
        if (! in_array($choice, self::ALLOWED_CHOICES, true)) {
            throw new InvalidArgumentException(
                "Invalid close_behavior value [{$choice}]. Expected 'quit' or 'tray'."
            );
        }

        ($this->writeUserPreference)($user->id, ['close_behavior' => $choice]);
    }
}
