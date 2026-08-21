# Invariants written after a shipped failure

Most of the rules in `tests/Contracts/` are not style preferences. Each one was
written after something reached a device, did nothing, and reported no error.
That is the pattern they share: a shape that is *silently* wrong — the page
returns 200, the console is clean, the suite is green, and a control on screen
simply does not work.

[Writing an arch invariant](arch-invariants.md) covers the mechanics, and says
where a rule's rationale belongs: **in the failure message**, so the contributor
who trips the rule reads it at the moment they trip it. This page is the other
half — the field history, for a reader deciding whether a rule is worth keeping,
widening, or removing. A test does not need to carry its own obituary.

Every entry below is: what the shape looks like, what it cost, and why nothing
caught it.

## A Blade directive inside a component tag

`tests/Contracts/ComponentTagDirectiveArchTest.php`

`<x-foo @if ($bad) aria-invalid="true" @endif />` does not error and does not
warn. Blade's component-tag compiler matches the tag with a regular expression
over its attributes; the directive defeats the match, and the tag is emitted
into the page **verbatim** as an unknown HTML element — which renders as
nothing at all.

The goal edit form's target-date input was written that way, and the modal
shipped with no date control in it: a label, then empty space, then the next
label. Found by reading the device's DOM and seeing a literal
`<x-core::date-input …>` element sitting in it.

The fix is always the same shape — branch around the whole tag rather than
inside it.

## A second root element in a Livewire view

`tests/Contracts/LivewireSingleRootArchTest.php`

Livewire binds `wire:id` to the **first** top-level element of a component
view. A second root is not a layout quirk; it silently unbinds everything
after it.

`/pots` had a `<style>` block beside its wrapper `div`, so `<style>` became the
component. Measured on a Galaxy: `button.closest('[wire:id]')` returned `null`,
zero requests to `/livewire/update` across a whole deposit flow, and the
component still read `{"potId":0,"amt":""}` after typing. Every write on the
page did nothing, with no error — while the sheets still opened, because
`$dispatch` is a plain window event and needs no binding.

That shape is indistinguishable from a working page until you check the
database, which is why it survived a device pass that "used" the feature.

## A `//` comment leading an Alpine expression

`tests/Contracts/AlpineExpressionArchTest.php`

Alpine compiles an attribute value as an **expression**. A leading `//` pushes
the real code onto the next line, so a statement there — `if`, `let`, `for` —
raises `Alpine Expression Error: Unexpected token 'if'` and the directive never
runs.

The biometric-capability probe on the app-lock screen threw on every render, so
the capability was never reported and the biometric option stayed hidden on
hardware that supports it. Nothing failed server-side and no test noticed.

Only the **leading** position is rejected. A comment inside a nested function
body (`x-data="{ init() { // … } }"`) compiles fine and is common in these
views, so flagging every `//` would be wrong.

## A browser global in a `wire:click` expression

`tests/Contracts/WireClickScopeArchTest.php`

Livewire evaluates a `wire:click` expression against the `$wire` proxy, so a
bare `document` in it resolves to `$wire.document` — undefined — and the call
throws before the method is reached.

`/counterparties/triage` did exactly that: "Label opslaan" threw
`$wire.document.getElementById is not a function` on every click, with no toast
and no on-screen error, and the counterparties table stayed at 35 rows. The two
inputs carried no `wire:model`, which is why the Blade reached into the DOM in
the first place.

Browser globals belong in an Alpine expression (`x-on:click`), where normal JS
scope applies. The whole `wire:*` family shares the `$wire` scope, so the rule
covers `wire:submit` and `wire:change` too.

## A native POST form in the mobile shell

`tests/Contracts/NativePostFormArchTest.php`

A native form POST does not survive the mobile shell. NativePHP intercepts
WebView requests and replays them into the embedded runtime; its form path
builds the body with `new FormData(form)` — which omits the submitter button's
own name and value — and the replayed request loses the POST method, so Laravel
answers 405 with `Allow: POST`. The app then follows the redirect back to the
same URL and loops on the error page.

No exception, no console error, just a control that does nothing. Sign-out and
the pre-auth language switch were both dead on device before this guard
existed, and nothing in the suite noticed. Every plain POST form in a Blade view
therefore submits through `beatraxSubmitPostForm`.

## A page that renders the shell and nothing else

`tests/Contracts/PageRoutesRenderContentArchTest.php`

`layouts.app` is a `@yield('content')` layout, reached by extending it at render
time — 41 pages do exactly that. `AliasesSettingsPage` was the only page that
*also* declared `#[Layout('layouts.app')]`, and `OpenBankingSettingsPage`
declared no layout at all. Both rendered the shell and nothing else: the page
component never mounted, `<main>` was empty, and the tab said only "Beatrax".
One of them is linked from `/settings` as "Aliassen beheren", so it was a
reachable dead end.

Measuring rendered *text* does not catch this — Alpine expressions inside
attributes survive tag-stripping and read as content. What is actually missing
is the component, so that is what the test asserts on.

## A middleware registered on one root only

`tests/Contracts/SharedCoreMiddlewareArchTest.php`

The sibling-root topology gives `mobile-app/` its own `bootstrap/app.php` — a
real file, never a symlink, because `dirname(__DIR__)` has to resolve to the
mobile root. Nothing keeps the two middleware stacks in step, and a middleware
added to one root is invisible in the other.

`SetLocale` was never registered on mobile, so the translator stayed on
`config('app.locale')`: the pre-auth switcher wrote `session('locale')` on every
tap and nothing ever read it back. The route, the negotiator and the session all
worked, every test passed, and the control was simply dead on device.

Module-owned gates are deliberately **not** compared — `EnsureDatabaseReady` and
`MobileEnsureDatabaseReady` are different middleware for different first-launch
surfaces. Only `Modules\Core\Internal\Http\Middleware` is shared surface, so
only that namespace is held in common.

## The upload signal missing from the wizard layout

`tests/Contracts/UploadSignalArchTest.php`

Every layout that can host a file input has to carry the upload-transport
signal, because the client reads it to decide whether multipart can cross at all
— and on the mobile runtimes it cannot.

The signal was added to the two shared layouts and the wizard's own layout was
missed. The wizard is where the *first* import happens, so the one screen that
most needed the encoded transport was the one screen that did not get it, and
every check short of driving a real device said the fix was in.

A layout is judged to host posting UI by the presence of a CSRF token, which is
the same set that can host a file input.

## The log tailer reading a file Monolog never wrote

`tests/Contracts/LogViewerReadsTheFileMonologWritesArchTest.php`

The Dev Console's log tailer reads `dailyLogFile()`, which resolves
`laravel-YYYY-MM-DD.log` — the name Laravel's `RotatingFileHandler` gives the
path handed to it. That only holds on the `daily` channel.

Both roots shipped `LOG_STACK=single`, so Monolog wrote `laravel.log` and the
tailer read a file that never existed. Measured on an iPhone mid-500: the Logs
panel reported "0 lines today · 0 B across 0 daily files" while the app was
serving an unhandled exception — the one surface built for reading errors,
blind to them.

## `APP_DEBUG=true` in the shipped bundle

`tests/Contracts/ReleaseShipsProductionEnvArchTest.php`

`.env.example` carries `APP_DEBUG=true` so local work has a usable default, and
the release workflow stages the shipped bundle's `.env` by copying it. Every
release artefact therefore went out with debug on.

Found on a device: `/user/confirm-password` answered with Laravel's debug page,
disclosing the Laravel and PHP versions, the full exception trace and the file
paths. The `.env` pulled off that build read `APP_DEBUG=true`.

The guard reads the workflow file itself and requires the copy step to be
followed by lines that neutralise the template's debug default. Two details
matter: the suite runs from **both** composer roots, and from `mobile-app/` the
workflow sits one level up — resolving only the desktop path made
`file_get_contents` return `false` there, the loop find nothing, and the guard
pass by reading an empty string. And the Larastan/Pest step builds nothing that
ships, so it is allowed to run on the template as-is.

## A decimal column read as a float

`tests/Contracts/NoFloatMoneyArchTest.php`

The migration guard stops money being **stored** as a float. It does not stop
one being **read** as a float, which is the half that actually shipped.

SQLite hands `decimal`/`numeric` columns back as PHP floats, and `brick/math`
0.18 accepts only `BigNumber|int|string` — a float argument is coerced to `int`,
so `0.92917629` silently becomes `0`. That is how the transaction detail page
came to render "€0.000 / USD" against a correctly stored rate, with nothing but
a deprecation notice to say so.

Every decimal-shaped column therefore needs a string cast on its model, so the
value stays a decimal string from the database all the way to the arithmetic.
The test keeps a hand-maintained column-to-model map honest against the real
schema: a new decimal column fails until it is either cast or consciously
exempted. The two standing exemptions are the confidence score (compared against
thresholds, never money, never fed to `BigDecimal`) and the rate read through
`ExchangeRateService::toString()`, which is the same guarantee by another route.

## `Number::currency()` on the mobile ICU build

`tests/Contracts/CurrencyRendersThroughMoneyArchTest.php`

`Illuminate\Support\Number::currency()` builds an intl `NumberFormatter` for the
locale it is handed, and throws when the runtime has no data for it. The mobile
PHP build ships ICU with English-only locale data, so every one of these calls —
all of them passing `'nl'` — was a 500 on device while the same page rendered
fine on desktop.

`Money::format()` is the seam that survives both runtimes: it asks ICU first and
renders the same string from marks the repo carries itself when ICU cannot
answer. Currency belongs there, not in a view.

## Framework translations missing from every locale

`tests/Contracts/FrameworkTranslationArchTest.php`

The app carried 26 locales of its own copy and none of Laravel's, so every
validation error rendered in English regardless of the chosen language — "The
naam field is required." on a Dutch screen.

Nothing caught it because the translation-parity test beside it compares the
app's module lang files against **each other**, and the framework's live outside
that set entirely. English is the framework's own default and needs no override.

## Month-first dates in a Dutch UI

`tests/Contracts/EuropeanDateOrderArchTest.php`

The app renders dates day-first. A census of the tree found 32 call sites
already using `d M Y`, `d M`, `j M Y` or `d M Y · H:i`, and exactly four
month-first ones — all on the calendar, all reaching a Dutch UI:

> "Saldo daalt onder € 0 op 18 dagen — eerste: aug. 1."
> `aria-label` "augustus 18, 2026: 0 betalingen"

The month name translated and the order did not, which reads as a bug in the
translation rather than in the format string.

## An inline `font-size` below 16px

`tests/Contracts/FormControlsNeverInlineBelowSixteenArchTest.php`

iOS zooms the page in when a focused form control renders below 16px, and does
not zoom back out. `app.css` carries a coarse-pointer floor for exactly this,
but an inline style beats any stylesheet rule — so a control that sets its own
`font-size` below 16px defeats the floor silently.

Measured on an iPhone after the floor landed: the tax-tag note textarea still
computed 15px, because its size is written inline on the element.

## `px-8` page padding on a phone

`tests/Contracts/PagePaddingArchTest.php`

Page shells agree on their phone-width padding. Most use `px-4`; three had
`px-8`, which at 411px spends 64px of a 411px screen on empty margin instead of
32px and reads as cramped next to every other page.

Wider padding above the `sm` breakpoint is fine and encouraged — the rule is
only that a page may not *start* wider than `px-4` on a phone.

## `grid-cols-2` recovery codes at 411px

`tests/Contracts/RecoveryCodeLayoutArchTest.php`

Recovery codes are the only way back into an account, and every screen that
shows them says to write them down. At 411px a two-column grid leaves ~180px for
a 24-character code, and `break-all` then orphans the last character onto a line
of its own: `X9NN-4CTG-CTRX-HPPP-PCS` / `4`.

That was fixed once, on one of the three screens. The other two kept an
unconditional `grid-cols-2` and kept orphaning — measured on a Galaxy S24 as two
line boxes, the second 7px wide. A bare `grid-cols-2` with no single-column base
is the defect: it applies at every width, phone included.

## A `display` override on the bottom-sheet scrim

`tests/Contracts/BottomSheetScrimArchTest.php`

The bottom-sheet scrim is a fixed, full-viewport, `z-50` layer whose visibility
belongs to Alpine's `x-show`. A `display` declaration in the phone media query
overrides `x-show`, and `!important` beat even the inline style — so every
phone-width page carrying a sheet sat under an invisible overlay that swallowed
taps while scrolling still worked. It reads as a frozen app.

## An `aria-label` that hides the visible label

`tests/Contracts/VisibleLabelInAccessibleNameArchTest.php`

WCAG 2.5.3 "Label in Name": when a control shows text and also carries an
`aria-label`, the announced name must contain the visible words. Speech input
matches on what the user can read, so a button reading "Install on next launch"
that announces "Mark system alert #3 as resolved" is visible and unusable by
voice.

This replaces Sonar's `Web:S7927`, which is switched off for Blade in
`sonar-project.properties`. That rule reads the raw template, so it cannot
evaluate `{{ }}` and reported every interpolated name as a mismatch — 28 of its
32 residual findings were unevaluable rather than wrong. The replacement looks
only at cases that can actually be decided: a static `aria-label` on an element
whose visible text is also static. Anything interpolated, and anything whose
visible text is a glyph rather than a word, is left unchecked rather than
wrongly reported.

## A public/ file with no route behind it

`tests/Feature/AppIconRoutesTest.php`

On a phone there is no web server in front of Laravel — every request is
answered by PHP — so a file that only exists in `public/` is a 404 unless a
route serves it.

Measured on an iPhone: `GET /icon.png` returned 404 with the styled error page,
and the veil's `<img>` reported `naturalWidth` 0. `GET /icons/icon-192.png` did
worse: 200, `Content-Length: 23548`, and 10 bytes of body — the PNG signature
and nothing after it, because a streamed `BinaryFileResponse` does not survive
the bridge. A header promising an image, over a body that is not one.

That is why the test asserts on the delivered byte count rather than on the
status code alone.

## Two phone constraints and three dialog-naming failures

`tests/Contracts/PhoneUiConstraintsArchTest.php`

Four separate device failures, all cheap to satisfy and expensive to notice
having lost:

- **The 44px touch floor.** 29 of 30 routes measured under Apple's minimum
  before the rule existed. The floor has to be **unlayered** or `h-10` outranks
  it. It also inflates the border box, so a fixed-size control with a pill
  radius becomes a circle and its positioned children strand — the "Enable sync"
  switch shipped as a small circle inside a bigger one. Anything the floor would
  deform opts out and takes its touch reach from a pseudo-element instead, which
  costs no layout.
- **A duplicated dialog name.** The same sheet is both "create" and "edit". A
  duplicated string went stale on the Livewire round-trip that updated the
  heading, so the dialog announced "create" while the form was editing.
- **A conditionally-empty dialog title.** A title can be conditional at the call
  site — the calendar's evaluates to `''` before a day is picked — so the empty
  branch has to name the dialog too, or `role="dialog"` ships anonymous.
- **A sheet name nothing opens.** A phone row's Withdraw button dispatched
  `open-sheet {name:'pot-withdraw'}` when the only thing carrying that name was
  a `flux:modal`, which the phone never opens. The button was inert and the page
  looked complete, so money could go into a pot and not come out.

## Plaintext staged in the shared temp dir

`tests/Contracts/PrivateTempDirArchTest.php`

Four places in the codebase already stated the rule in a comment — the backup
download, the GDK keyring, and both device-identity writers all said never
`sys_get_temp_dir()`, because `/tmp` is mode 1777 and anyone on the machine can
traverse it. Nothing checked, and the restore path did exactly that: it
decrypted the whole database — every transaction the user has — to
`/tmp/beatrax-restore-*.sqlite` through a plain `fopen`, which lands at 0644.

Even 0600 is not enough there. `tempnam()` protects the *content* but not the
name or the size, and an uploaded bank statement leaks something by both. So
there is no allow-list in production code: a 0700 directory under the app's own
storage costs three lines, which is cheaper than deciding case by case which
secrets may sit in a world-readable directory.

Test files are out of **scope** rather than exempt. The rule protects user data
at rest on a real machine; a fixture mbox a test writes and deletes carries
nobody's money, and holding tests to it would only teach people the rule has
exceptions.

## An unbound `false` is the string "false"

`tests/Contracts/FluxBooleanAttributeArchTest.php`

`flux/modal` reads `if ($dismissible === false)` before merging
`disable-click-outside`, and the same for `$escapable` / `disable-escape`.
Written without the colon, Blade hands it the **string** `"false"`, which is not
identical to `false`, so the guard is skipped in silence: the modal renders,
looks right, and dismisses on an outside click anyway.

Three modals shipped that way — the desktop's save-before-quit prompt and the
two credential wizards — so a stray click discarded work the modal existed to
protect. Nothing failed, because nothing was checking.

## A locale argument passed to `Money::format()`

`tests/Contracts/MoneyFormatPicksTheLocaleArchTest.php`

`Money::format()` picks the locale from the currency: EUR reads in the Dutch
convention, everything else in US English, which is how a card statement reads.
Thirty call sites passed `'nl_NL'` anyway, and in the ones not pinned to EUR
that renders a dollar or sterling amount with Dutch separators — `$1.234,56` —
for a user in any of the app's 26 locales.

Passing a locale you already have is never an improvement over letting the value
object choose, so the argument is gone: `format()` takes none. The rule guards
the signature as well as the call sites, because a parameter that exists is a
parameter somebody will pass. It also matches a *computed* locale —
`format($c === 'EUR' ? 'nl_NL' : 'en_US')` is the same hardcoding with a branch
in front of it, and that is the shape that survived the rule's first pass.

## The Android shell forwards no Host header

`tests/Feature/TrustedHostGuardTest.php`

`TrustedHostGuard` is the defence against a DNS-rebinding site reaching the
loopback server as a same-origin caller. The Android shell's bridge sends
Cookie, Accept, User-Agent, Referer and the `sec-ch-ua` set — captured from a
device — and nothing else, so `Request::getHost()` returns `''`.

Rejecting that took the entire app down: every route 404'd, including Laravel's
own `/up`, while the runtime booted cleanly in 469 ms and rendered the app's
styled 404 page.

Rebinding works by *naming* a domain, and a browser always sends `Host` on
HTTP/1.1, so an empty one cannot carry the attack this gate exists to stop. A
blank `Host` normalises to the same empty string as no `Host` at all, so the
guard cannot tell them apart and does not pretend to; `LoopbackOnly` still gates
the interface the request arrived on.

## A dependency reached from nine places

`tests/Contracts/ThirdPartyContainmentArchTest.php`

A third-party package is reached through one seam of ours, not from wherever it
happens to be convenient. The repo already believed this in places — `Money`
wraps `brick/money`, `EnableBankingHttpClient` wraps Guzzle, `Camt053Adapter`
wraps `genkgo/camt` — but nothing held it, so `brick/money` leaked into an FX
service, an import pipeline, a merge resolver and a Blade view. A dependency
reached from nine places cannot be upgraded, substituted or reasoned about;
reached from one, it can.

The map in the test is the whole rule: every package the shipped tree touches
names the seam that owns it. It is not an exemption list, and four assertions
stop it becoming one — a seam must exist, must be used, may never be a
view/Livewire component/model, and a package may have at most one seam per
module. Padding the map to excuse a violation fails one of them; the only way to
go green is to move the code.

Three carve-outs are deliberate. `moneyphp/money` is not a second money library
by choice — `genkgo/camt` returns its objects, so the CAMT adapter unwraps them
to minor units in the same file it parses in. `bootstrap/` and `app/Providers/`
are the application's composition root, whose whole job is naming the packages
the container assembles. And `Native\Desktop` already has a tighter, reviewed
rule in `BoundaryArchTest`; two rules over one namespace would drift apart, so
this one defers, and an assertion at the foot of the file fails if that rule
ever disappears.

## Money formatted through a float

`tests/Contracts/MoneyNeverPassesThroughFloatArchTest.php`

`NoFloatMoneyArchTest` holds the storage boundary — no `REAL` column, a string
cast on every decimal one. It says nothing about runtime, and runtime is where
the conversions actually were. `number_format($minor / 100, …)` had been copied
to twenty-six call sites and `(int) round((float) $typed * 100)` to five, which
is how a transaction filter for `1.234,56` came to search for €1.23: PHP reads
that string as the float `1.234`. The filter chip agreed with the query, so the
screen was self-consistent and wrong.

`MoneyInput` is the seam for both directions — `tryToMinor()` in,
`formatMinor()` out — and `Money::format()` renders an amount that already knows
its currency. Neither holds a float. `formatMinor()` groups thousands, matching
the nine hand-rolled sites it replaced, and a round-trip case pins
`tryToMinor(formatMinor($m)) === $m` so the group mark and the decimal mark
cannot be chosen independently.

The rule governs money that becomes a **string**. A chart plots money as a
coordinate, and no charting library takes an integer for a y value; that float
becomes a pixel, never digits, so it is outside the rule rather than excused by
it. The moment a coordinate is formatted back into an amount it is inside again.

## A dropped user scope with no owner named

`tests/Contracts/UserScopeDropRequiresUserIdArchTest.php`

Dropping the user scope is legitimate — a writer takes its owner explicitly, a
seeder runs before a guard is bound, a system-wide alert has no owner at all.
What is never legitimate is dropping it and then not saying whose rows the query
may see, because the id being looked up came from the browser.

The update banner did that twice. `SystemAlert::withoutGlobalScopes()
->find($alertId)` on a client-supplied id, and both handlers acted on the row
**before** the ownership check further down could throw: `install()` dispatched
an install for a foreign row's `latestVersion`, and `skipVersion()` wrote a
foreign row's version into the caller's own preferences. Twenty-six of the
twenty-eight call sites already re-asserted `user_id`; these two did not, and
nothing said so.

The re-assertion is required in the same **method**, not the same statement: the
acknowledge action checked ownership with a raw predicate and then re-read the
row as a model, which is one decision written across two lines. A method is the
unit a reviewer reads, so it is the unit the rule uses.

## An exception message logged from a broad catch

`tests/Contracts/LoggedExceptionsDropThePayloadArchTest.php`

A `QueryException`'s message is the statement **and** its bindings, and here the
bindings are the data the encryption exists for: a counterparty, an amount, a
relay pairing frame. The daily log is 0644. `SafeExceptionContext::describe()`
was written for exactly this and returns the exception's class and its SQLSTATE
— enough to tell a lock timeout from a constraint violation, and carrying no
row. The relay daemon used it at three catch sites and logged the raw message at
a fourth; forty-nine sites tree-wide did the same, including a batch insert of
merchant mappings and a transaction-amount write.

"Broad" is not a list of type names. A catch is broad exactly when a
`QueryException` could arrive in it, which the runtime can answer: `Throwable`,
`Exception`, `RuntimeException` and `PDOException` all can, and
`SodiumException`, `UnexpectedValueException` and `LogicException` all cannot.
Narrowing the catch is therefore the way out of the rule, and narrowing where
the message is *read* — `$e instanceof ParseException ? $e->getMessage() : null`
— counts as the same guarantee.

`Modules\Core\Public\Support\MessageNamesNoUserData` is the marker for that
second form. An exception implements it to promise its message names the shape
of the failure and never a value read out of the file, so a broad catch can log
the message for those and drop it for everything else it might have caught. The
import pipeline needs the distinction in one catch: the same `Throwable` arm
receives `SniffMismatchException` ("this CSV doesn't match the ASN layout") and
a `QueryException` from the account lookup the loop also performs. Marking is a
claim about a message, so it is made per exception class and checked against
every `throw` — `InvalidAmountException` carries a raw MT940 `:61:` line and is
deliberately not marked.

A daemon's stdout is a log too: `relay:serve` and `sync:serve` run under a
supervisor that captures it to the same kind of file, so a `$this->error()`
carrying the message in a console command is the same disclosure.

## A view name nothing answers to

`tests/Contracts/ViewReferencesResolveArchTest.php`

`$factory->composer('core::livewire.top-nav', …)` against a view that no longer
exists does not throw. A composer is stored under its view name and consulted
when that name is rendered; a name nothing renders is simply never consulted.
The provider boots, the callback is registered, and it never runs.

The redesign that replaced the top navigation with the sidebar deleted the view
and left five providers bound to its name. They sat inert for a whole phase.
Two were harmless duplicates of counts the sidebar already had, but three held
badges the product then did not have at all — Inboxes, Chains and Forecast
shipped with no count on them, and nothing anywhere said so. Thirteen tests were
parked as `->todo()` rather than failing, so the suite stayed green over the
gap.

Rendering channels fail loudly by comparison — `@include`, `@extends`, `view()`
and `Route::view()` all throw `InvalidArgumentException` on the page — but they
are named as strings in exactly the same way, so the rule reads all of them and
asks the view finder whether anything answers. `x-<ns>::` component tags resolve
through the Blade component resolver rather than the finder and stay outside it.

## Related

- [Writing an arch invariant](arch-invariants.md) — the mechanics every rule in
  `tests/Contracts/` shares, and why the rationale belongs in the failure message
- [Module boundaries](../architecture/module-boundaries.md) — the largest single
  group of invariants
