<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Decides what happens when the user clicks the window-close (X) button.
 *
 * Three outcomes, derived from the current user's `close_behavior`
 * column:
 *
 *   - `null`  → show the D-08 prompt (CloseWindowPrompt) so the user
 *               chooses between "Quit beatrax" and "Keep running in
 *               the tray". The choice optionally persists via
 *               `persistChoice()`.
 *   - `quit`  → quit the app on every subsequent close.
 *   - `tray`  → hide the window on every subsequent close, keeping the
 *               D-05 bundled worker + D-06 scheduler alive so
 *               background work continues.
 *
 * The service does NOT call any `Native\Desktop\Facades\*` itself —
 * the actual `App::quit()` / `Window::current()->hide()` calls live in
 * `NativeAppServiceProvider` (the single sanctioned facade-bearing
 * file) so the boundary arch invariant stays intact. This service is
 * pure decision-making + persistence.
 *
 * The allow-listed choices (`quit`, `tray`) are enforced at the
 * persistence boundary: `persistChoice()` rejects any other value with
 * `InvalidArgumentException` so a malformed request-supplied value
 * never reaches the users row or the close-time mapping.
 */
final class WindowCloseBehavior
{
    /** users.close_behavior value meaning "quit on close". */
    public const CHOICE_QUIT = 'quit';

    /** users.close_behavior value meaning "hide to tray on close". */
    public const CHOICE_TRAY = 'tray';

    /** @var list<string> Allow-listed close_behavior values. */
    private const ALLOWED_CHOICES = [self::CHOICE_QUIT, self::CHOICE_TRAY];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Returns true when the user has not yet recorded a close-behavior
     * choice and the prompt should be shown on the next close.
     */
    public function shouldPromptFor(User $user): bool
    {
        return $user->close_behavior === null;
    }

    /**
     * Returns the user's recorded choice, or `null` when the prompt
     * is still required.
     */
    public function choiceFor(User $user): ?string
    {
        return $user->close_behavior;
    }

    /**
     * Persists a user's "remember my choice" decision via the same
     * instant-apply raw query-builder write pattern SettingsPage uses
     * for the theme + auto-import toggles.
     *
     * @throws InvalidArgumentException when $choice is outside the {quit, tray} allow-list.
     */
    public function persistChoice(User $user, string $choice): void
    {
        if (! in_array($choice, self::ALLOWED_CHOICES, true)) {
            throw new InvalidArgumentException(
                "Invalid close_behavior value [{$choice}]. Expected 'quit' or 'tray'."
            );
        }

        $this->db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'close_behavior' => $choice,
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);
    }
}
