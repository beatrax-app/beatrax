<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Desktop\Internal\Native\PendingFileIntent;

/**
 * The .csv / .eml staging page (D-01 / D-02).
 *
 * Renders after the OS hands diederik a file path and the
 * `FileOpenedFromOs` Public event has been emitted. The page reads the
 * current pending intent (if any) from `PendingFileIntent` and shows
 * one of two states:
 *
 *   - PRESENT: brand mark → "File received: `<name>`" heading → a
 *     single emerald "Start import" button. Clicking the button
 *     navigates to `route('imports.new')` for BOTH `.csv` and `.eml`
 *     extensions — the user-facing Import wizard owns the per-format
 *     branch internally (its issuer-and-source-format selector picks
 *     the right step), so the staging page does not need to fork.
 *     The Blade copy still differentiates the helper text (a bank /
 *     PayPal export vs an email receipt) so the user sees an
 *     extension-appropriate message before they commit to the
 *     wizard.
 *   - EMPTY:  "We couldn't open that file" heading + a link to
 *     `route('imports.new')` so the user can recover by hand.
 *
 * Livewire constraint: NO constructor on a `Component` subclass
 * (`phpstan-strict-rules` ban). Collaborators arrive as method-param
 * DI on `render()` and the action.
 *
 * SC3 routing caveat (locked by the plan): `.csv` routes through
 * `Modules\Import` (the user-facing import flow), NOT
 * `Modules\Ingestion`. `.eml` lands on the SAME wizard — the wizard
 * owns the per-source-format branch.
 */
final class FileStagingPage extends Component
{
    /** Verbatim UI-SPEC heading prefix (Copywriting Contract). */
    public const HEADING_PREFIX = 'File received: ';

    /** Verbatim UI-SPEC "Start import" CTA label. */
    public const BUTTON_LABEL = 'Start import';

    /** Verbatim UI-SPEC empty-state heading. */
    public const EMPTY_HEADING = "We couldn't open that file";

    /** Verbatim UI-SPEC empty-state body. */
    public const EMPTY_BODY = "diederik couldn't read the file you opened. Try importing it from the Imports page instead.";

    /**
     * The pending intent the page is bound to, captured at mount time
     * so refreshing the page mid-flow doesn't lose the file binding —
     * the session-scoped store has been cleared on mount.
     *
     * @var array{path: string, extension: string}|null
     */
    public ?array $pending = null;

    public function mount(PendingFileIntent $intent): void
    {
        // Read-and-clear: the staging page is the consumer of the
        // pending intent. Visiting the page once consumes it; the
        // next visit shows the empty state. This pattern keeps the
        // "Start import" action stateless — it acts on the component's
        // own `$pending` property — and prevents a stale intent from
        // re-firing on every dashboard navigation.
        $this->pending = $intent->pending();
        if ($this->pending !== null) {
            $intent->clear();
        }
    }

    public function render(
        ViewFactory $views,
    ): View {
        $filename = null;
        if ($this->pending !== null) {
            $filename = basename($this->pending['path']);
        }

        $view = $views->make('desktop::staging', [
            'pending' => $this->pending,
            'filename' => $filename,
            'headingPrefix' => self::HEADING_PREFIX,
            'buttonLabel' => self::BUTTON_LABEL,
            'emptyHeading' => self::EMPTY_HEADING,
            'emptyBody' => self::EMPTY_BODY,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'File received · diederik']);

        return $view;
    }

    /**
     * "Start import" action. Navigates to the user-facing Import
     * wizard at `route('imports.new')` for BOTH `.csv` and `.eml`
     * intents — the wizard owns the per-source-format branch
     * internally (its issuer / source-format selector picks the right
     * step), so the staging page does not need to fork on extension
     * here. The `$pending` property has already been consumed at
     * mount time; this action is just the navigation step the user
     * invoked by clicking the CTA. SC3 caveat: both file types route
     * through the Import module's user-facing flow.
     *
     * The actual emission of FileOpenedFromOs (the intake POST) is
     * elsewhere; this action consumes the resulting session-stored
     * intent.
     */
    public function startImport(
        UrlGenerator $urls,
    ): mixed {
        return $this->redirect($urls->route('imports.new'), navigate: true);
    }
}
