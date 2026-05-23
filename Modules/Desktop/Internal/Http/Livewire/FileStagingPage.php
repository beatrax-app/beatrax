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
 *     single emerald "Start import" button. The button routes the
 *     pending intent into the appropriate flow:
 *       - `extension === 'csv'` → the Import preview/confirm flow
 *         (`route('imports.new')`).
 *       - `extension === 'eml'` → the email-file step of the same
 *         upload wizard (the user-facing Receipts entry point — same
 *         wizard component handles both source formats).
 *   - EMPTY:  "We couldn't open that file" heading + a link to
 *     `route('imports.new')` so the user can recover by hand.
 *
 * Livewire constraint: NO constructor on a `Component` subclass
 * (`phpstan-strict-rules` ban). Collaborators arrive as method-param
 * DI on `render()` and the action.
 *
 * SC3 routing caveat (locked by the plan): `.csv` routes through
 * `Modules\Import` (the user-facing import flow), NOT
 * `Modules\Ingestion`. Both extensions land on the same staging page
 * here — the per-extension routing decision lives on `startImport()`.
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
     * "Start import" action. Reads the pending intent, clears it, and
     * navigates the user to the relevant entry point:
     *
     *   - .csv → route('imports.new')
     *   - .eml → route('imports.new') (the upload wizard accepts both;
     *     the email-file step path is selected by the wizard's own
     *     issuer/source-format selector — the user-facing import flow
     *     SC3 caveat says BOTH file types route through Import's
     *     user-facing flow).
     *
     * The actual emission of FileOpenedFromOs (the intake POST) is
     * elsewhere; this action consumes the resulting session-stored
     * intent.
     */
    public function startImport(
        UrlGenerator $urls,
    ): mixed {
        // Both extensions route to the user-facing import flow (SC3
        // caveat). The Import wizard's email-file step is the
        // .eml-specific landing; the wizard chooses internally based
        // on issuer. The `$pending` property has already been
        // consumed at mount time — this action is the navigation
        // step the user invoked by clicking the CTA.
        return $this->redirect($urls->route('imports.new'), navigate: true);
    }
}
