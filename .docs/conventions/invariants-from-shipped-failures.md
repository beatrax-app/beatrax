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

## An `aria-label` that hides the chosen value

`Modules/Core/tests/Feature/DateTimeInputAccessibleNameTest.php`

The sibling of the rule above, one step further down the same algorithm. A
control whose visible content IS its value — `x-core::date-input` and
`x-core::time-input` render the chosen date inside the `<button>` — cannot also
carry a static `aria-label`, because the accessible name is computed in a fixed
order and stops at the first source that answers:

1. `aria-labelledby`
2. `aria-label`
3. the host language's own label — for a `<button>`, a `<label for="…">`
4. the element's own text

An `aria-label` of "Choose a date" therefore announced "Choose a date" over a
field reading 31-12-2026, on the phone, in the goal form. Measured in Chrome on
the shipped markup: the value never reaches the name, and neither does the
`<label for="goal-date">Target date</label>` above it — step 2 answers first.

Step 3 is the trap in the obvious fix. Dropping the `aria-label` so the button
names itself from its own text does not work wherever a caller points a label at
it: step 3 answers before step 4, and the name becomes the label's words with
the value still missing. Only `aria-labelledby` outranks a label, so the name is
stitched from two `sr-only` spans inside the button — what the field is, then
what it holds — and the visible value and glyph are `aria-hidden`. With no field
id there is no label to outrank, and the same two spans become the name by step
4 unchanged.

The empty state is a word, not the `—` the field draws: an em dash has no
spoken reading, so an empty field announced its name and then nothing.

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
- **A floor a native control ignores.** The 44px floor was extended to `select`
  after the pre-auth language picker measured 29px, and the picker stayed 29pt on
  iOS with the new rule applying — its padding, radius and colours all landing.
  WebKit sizes a select that keeps its NATIVE APPEARANCE from the font it renders
  and ignores height on it entirely, so the declaration was inert rather than
  lost, and every select in the app was the same 29pt. Dropping the appearance is
  what lets the height land, and it takes the platform's own arrow with it — a
  select cannot carry a pseudo-element, so the mark has to be redrawn as the
  element's background, which is why the guard requires all three together and
  why the chevron is a token defined once per colour scheme. It is scoped to
  coarse pointers like every rule in that block, which is also what keeps a drawn
  chevron from ever appearing beside a native one on desktop.

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

## A bare `env(safe-area-inset-*)` on Android

`tests/Contracts/SafeAreaReadsTheSeamArchTest.php`

iOS populates `env(safe-area-inset-*)` once the viewport is `viewport-fit=cover`.
The Android shell does not populate it at all — it measures the system bars and
injects `--inset-top`, `--inset-bottom`, `--inset-left` and `--inset-right` onto
`:root` instead. A template reading `env()` directly therefore pads by zero on
Android, and only on Android.

Nothing reports it. The declaration is valid CSS, `env()` falls back to its
second argument or to `0px`, the page returns 200, and the screenshot looks
plausible unless you know where the status bar ends. It surfaced as a lock
screen whose title sat under the clock and a pairing screen whose action sat
under the gesture bar — on one platform, while the same code was correct on the
other.

`resources/css/app.css` defines `--safe-*` as `max()` of the two sources, so a
template that reads the variable is right on both. The first arm scans every
Blade template for a direct `env(safe-area-inset-` outside that definition,
case-insensitively and tolerant of inner whitespace — CSS function names are
ASCII case-insensitive, so a literal lowercase search let `ENV(` and
`env( safe-area-inset-top )` through. It ignores Blade comments, so the layout
may still explain why `viewport-fit=cover` is on the viewport tag. Five
templates have ever carried the shape and all five are fixed; this arm is what
keeps them from coming back.

The second arm keeps the seam those templates depend on: all four `--safe-*`
must still be defined as the `max()` of both sources. Whitespace is collapsed
before the comparison, so reformatting the declaration no longer reports the
seam as lost when it is intact.

The third arm is a forward guard rather than a detector of those five — it
matches the post-migration spelling, `px-[var(--safe-left)]` or
`py-[var(--safe-top)]`, which has never appeared in this repository. `px-*`
writes *both* horizontal paddings and `py-*` both vertical ones, so one edge's
inset is mirrored onto the opposite edge and then overwritten — correct only
because Tailwind emits the single-edge utility after the pair, and wrong on a
notched phone, where the two edges genuinely differ. The vertical form is the
more reachable of the two, since top and bottom differ in portrait. `pl-*` with
`pr-*`, and `pt-*` with `pb-*`, say what was meant. It reads the same
comment-stripped body the first arm does, so documenting the anti-pattern does
not fail the build.

The fourth arm is the invariant the other three only spell. `layouts.lock`
yields straight into `<body>` under `viewport-fit=cover` and draws no chrome of
its own, so every component that extends it is the only thing between its
content and the system bars. The arm resolves each `extends('layouts.lock')`
component to the view it renders and fails when any of the four edges is
unpadded — including when it cannot resolve the view at all, because an
unreadable consumer is an unchecked one. A new lock-layout screen with a bare
`min-h-screen` root passes all three other arms and still sits under the status
bar on both platforms. An edge counts as padded when the template spells the
token in its own markup **or** wears a class `app.css` defines as padding that
edge from the seam, which is how `.safe-screen` satisfies the arm. The lookup
reads the stylesheet rather than the class name, so a class that quietly stops
padding an edge fails every screen wearing it; only a lone class selector is
credited, because in `.a .b` neither half pads anything on its own.

The fifth arm is what keeps the string from being retyped. `.safe-screen` in
`resources/css/app.css` is the single definition of *this screen owns the whole
viewport*: all four paddings, each read from the seam. The arm fails any element
whose class attribute carries all four single-edge inset utilities at once —
which is what six screens did before the class existed, five of them
identically and the sixth in a different token order, which is the only reason
the set read as several spellings rather than one.

A screen rendered inside `layouts.app`'s `<main>` is the case that arm
deliberately does not cover, and the reason `.safe-screen` is not simply applied
everywhere. `.top-bar` is `position: sticky`, so it stands in the flow and
`<main>` already begins below it, and it pads the top inset itself. A screen
under it that pads the top inset again reserves the status bar twice.
`recovery-codes-display` did exactly that: on a 59px inset its content box
started 107px into `<main>` where the design asked for 48px, a whole second
status bar of empty space above the heading, invisible anywhere but a device.

The sixth arm is the half none of the five covered: `.safe-screen` *reserves*
the top strip and nothing was *painting* it. Under `viewport-fit=cover` the
system bars are drawn over the page, so the reserve is only ever correct at
scroll 0 — a screen taller than the viewport scrolls its own heading up under
the clock, and the two render on top of each other. `.top-bar` never had this
problem because it is `sticky` and opaque: it covers the strip as well as
standing in it. `.safe-screen` now generates a fixed strip of `var(--safe-top)`
painted in `var(--color-bg)`, and the arm pins that declaration the way the
second arm pins the seam. Losing it fails nothing else, and the resting
screenshot stays correct.

That cover is switched off, along with the top reserve, by
`body:has(.top-bar) .safe-screen` — a document carrying a bar has already
reserved and painted the strip, and this is what keeps a screen from doing
either a second time. The question is asked of the *document* rather than of
the screen for the reason below.

The seventh arm bans a bar's height standing in for the status bar's. The import
bootstrap reserved `var(--top-bar-h)` plus its own padding at the top of the
page, and that number is wrong in both directions: a screen under `.top-bar`
needs no top reserve at all, because the bar is sticky and already stands in the
flow, and a screen with no bar over it needs the inset, which is a device
measurement and not 48px. It was only ever right by coincidence, on the phone it
was checked on.

The eighth arm is the fourth arm's other half, and it is the reason the import
bootstrap had a bar's height in it at all. `layouts.app` renders the drawer and
`.top-bar` under `@auth`; under `@guest` it yields straight into `<body>` with no
chrome whatsoever, which puts a signed-out screen in exactly the position a
`layouts.lock` consumer is in. Five of the six screens a signed-out reader can
reach reserved nothing at all.

The trap underneath it is that the screen cannot decide this for itself.
Livewire re-renders the component and never the layout, so the chrome a document
carries is settled at page load and stays settled: the mobile import bootstrap
creates the account *mid-flow*, and every step after that — the recovery codes
among them — still renders inside the markup the signed-out branch produced,
with `auth()->check()` now true and no bar anywhere on the page. A conditional
in the view reads the wrong state by construction.

So the arm asks the rendered document instead of the template: it walks every
parameterless `web` GET route that is not behind `Authenticate`, fetches it, and
requires any 200 that came back with a full-screen root to carry `.safe-screen`.
It sweeps twice, once on a fresh install and once with an account present,
because the first-run gate answers `/login` with a redirect to `/welcome` until
a user exists and `/signup` and `/welcome` stop answering once one does —
sweeping either state alone leaves half of them unvisited. `/mobile/welcome`
answers under the phone shell only and is out of this root's reach.

One caveat about this page itself: Tailwind v4 scans the project root, `.docs/`
included, so a utility name written in a sentence here is a real candidate for
the bundle. `px-[…left]` in an earlier draft of this section shipped as
`padding-inline:…left` in `public/build`. `app.css` now carries
`@source not "../../.docs"` so that prose about a class can never emit it.

## An encrypted column rendered to the reader as ciphertext

`Modules/Sync/tests/Feature/RenderedCiphertextGuardTest.php`

A read of a `SensitiveFieldRegistry` column that never reaches
`SensitiveColumnCodec` returns the stored bytes: XChaCha20-Poly1305 ciphertext,
base64 of a nonce and a tag. Nothing raises. The page returns 200, the column is
a non-empty string, every formatter downstream accepts it, and the card prints
it.

One branch fixed this three times in an afternoon, each time because a human
looked at a phone. `/counterparties/triage` read the queue through the raw
builder and hydrated models from the rows, so no cast ran; the card put the
stored `counterparties.iban` through the IBAN mask and read
`7F · ·· HUX5 ···· ···· ==` — on the screen whose entire job is to ask the
reader which IBAN this is. `/community/mystery-merchants` listed nineteen base64
blobs and offered a button to publish one of them to a shared corpus, and the
same action logged the composed URL, so the description reached
`storage/logs` as well. Then the alias match preview: no codec injected, so both
columns came back as base64, the substring match ran against the ciphertext and
reported the wrong count, and the values reached a public Livewire property,
which puts them in the browser payload as well as on the screen.

Three static designs were tried against it and all three fail, which is why the
rule is behavioural:

- **Scan Blade for the column names.** The registry lists `title`, `body`,
  `note`, `description` and `params`. A Blade `$title` is a page title; 28 files
  match on that word alone.
- **Flag a file that names a sensitive column and carries no codec marker.** The
  pre-fix triage queue already carried three markers — it decrypted transaction
  descriptions a few lines below the counterparty columns it missed. A file-level
  marker gives a false negative on the exact defect.
- **Diff the columns a file selects against the columns it decrypts.** The
  offending query selects `*` and calls `Model::hydrate()`, so the leaking
  columns are not named in the source at all.

So the guard enables encryption for real through `EncryptionMigrationService`,
renders twenty-two surfaces over the ciphertext that produces, and asserts the
exact bytes sitting in the database do not appear in what reaches the browser —
which for a Livewire component means the `wire:snapshot` payload as well as the
HTML, because a public property ships either way. Asserting on
the stored value rather than on "does this look like base64" is what keeps it
precise: there is nothing to tune and no shape to argue about.

The absence half cannot see a value a view masks, truncates or reformats first,
because only a few characters of it survive — the triage card printed six. Each
screen therefore also asserts the plaintext it exists to show, in whatever form
it shows it, and for the triage card that is the mask over the *plaintext* IBAN.
That half doubles as the check that the fixture still reaches the screen: an
empty state passes an absence assertion perfectly.

The precondition is not optional. Decrypting a plaintext value is a documented
no-op, so a fixture that quietly failed to encrypt would let a completely broken
read path pass on every screen at once — green, silent, and worth nothing. The
first case in the file reads every registered column back and requires
`decrypted: true` before a single screen is rendered.

Three columns are covered by the same census and are **not** in
`SensitiveFieldRegistry::columns()`: `transactions.counterparty_normalized`,
`merchants.normalized_name` and `recurring_series.cluster_counterparty_key`.
They hold a keyed HMAC rather than AEAD, for reasons argued in
[sensitive columns at rest](../features/sync/sensitive-columns-at-rest.md), and
putting them on that list would encrypt the columns the database has to match on.
They live behind `blindIndexColumns()` instead, and the guard reads both
accessors and pins that they stay disjoint — a guard driven by the AEAD list
alone has no opinion about a digest on a screen, which is the same defect wearing
a different mechanism.

The `_no_counterparty` sentinel, from `blindIndexSentinel()`, is in the census
beside them, and it is the case a shape rule misses: it is stored verbatim on
purpose, so `looksDerived()` answers `false` for it and any rule written as
"reject what looks derived" lets a machine token through to the reader.

The census also covers the crypto layer's own vocabulary — an exception message
caught and printed as if it were copy. That is a different shape from a leaked
value, and it has a live instance: the import preview prints
`BlindIndexKeyUnavailableException`'s message once per row, naming an internal
class and the reader's own user id.

## A comment rule written down and never enforced

`tests/Contracts/CommentPolicyArchTest.php`

The comment policy has six mechanical rules. The test implemented five of them.
M1 — no lone single-line comment — had no test at all, and nobody noticed for
as long as the file has existed, because the other five passing is
indistinguishable from all six passing.

What that cost is not one defect but a habit. One branch added ten lone
one-liners; the tree as a whole had **476**, across 298 files and every module.
Each one individually looks harmless, which is exactly why the count reached
476: there was never a moment where the next one was obviously the problem.
The sibling rule made it worse rather than better — M2's failure message read
*"a comment worth only one line should BE one line"*, so the file that should
have been enforcing the floor was arguing against it in prose.

Three things fell out of writing the rule that were not visible before it:

- **The directive allow-list matched a word, not a directive.** The pattern
  accepted any comment whose first token was `phpstan`, `psalm`, `phpcs`, `var`
  or `codeCoverage`, so five ordinary sentences that happened to open with
  "PHPStan" were exempt — from M2, M3 and M4 as well. An allow-list hit is
  silent by construction: those lines were not passing the rules, they were
  invisible to them. A real directive is `@var`, `@codeCoverageIgnore`, or a
  tool name followed by `-` or `:`, and the pattern now says so.
- **`mobile-app/` was outside every file root.** The second Composer root's own
  bootstrap carried a 54-line block comment, nine `//` blocks over the M2 cap,
  and requirement identifiers M5 bans — all of it green, because nothing looked
  there. The tree is mostly symlinks back to this root, so the walk prunes
  symlinked directories at the branch rather than resolving them: following one
  reports every shared file a second time under a second spelling.
- **A rule with no failing state proves nothing.** M1 was verified by
  reintroducing a lone comment, watching the suite go red on it, removing it,
  and watching it go green. That step is cheap and it is the only thing that
  separates a rule from a comment about a rule.

## A `.docs` page linking a file that was never written

`tests/Contracts/CommentPolicyArchTest.php`

M6 proves that a documentation link *out of the source* resolves. Nothing proved
that a link *between two pages* resolves, and the two failure modes are not the
same size. A stale page describes a real past. A page that names a class, a
method and a test which have never existed describes nothing at all — and reads
identically to a page describing shipped code, because prose carries no version.

Both pages added on one branch did exactly that. One documented a notification
copy spec whose methods had not landed, and asserted on that basis that a
separate open defect was already fixed. The other named a query method and a
test file where `git grep` returned exactly one hit each: the page itself.

The rule walks every `.md` under `.docs`, takes the inline and reference-style
link targets, drops schemes and bare anchors, and resolves what is left against
the page's own directory. Fenced blocks and inline code are stripped first — a
page teaching a syntax, or quoting a path deliberately, is illustrating rather
than pointing. A directory target passes; only a path resolving to nothing fails.

It catches the invented-ahead-of-the-code page only where that page links what
it invented, which is the common case and not the whole of it. Prose naming a
class without linking it still passes.

## A count beside a noun that never declared itself plural

`tests/Contracts/CountedNounDeclaresItsPluralArchTest.php`

An import finished on a phone and reported "**1 errors**". The key behind it was
`' · :count errors'` — a number interpolated straight against a bare plural, with
no `|` to select on. A sweep of the English lang files found 56 more of the same
shape, three of them with the branch moved out of the lang file and into PHP:
`count($missing) === 1 ? Lang::get('…arg_singular') : Lang::get('…arg_plural')`.

The English defect is the small half. These strings ship in 26 locales. English
selects between two forms; Polish, Czech, Slovak, Croatian, Serbian, Ukrainian
and Lithuanian select between three, Slovenian between four, and several of them
choose on the final digit rather than the magnitude — Croatian's first form
covers 21 as well as 1. A line with no `|` hands the translator a sentence with
nowhere to put their own grammar, so those translations are wrong by
construction rather than by mistake. A PHP ternary is worse again: it pins the
choice to English's two forms somewhere no locale rule can reach.

`TranslationParityArchTest` already checks that a pluralised line carries as many
segments as its locale selects between — but it can only see a line that
declares itself plural with a `|`. All 57 bypassed it by never being pluralised
at all, which is why a rule that had been running for months over these exact
files reported nothing.

The rule reads a count-shaped placeholder followed within three words by a word
that reads as a plural noun. Both halves are lists in the test: the count words
are its vocabulary, and the not-a-plural words are its only exception —
`:name dips to`, `:version fixes :summary` and `:username takes that` are
third-person verbs, and every one of them was a live false positive. A
`:min..:max` range is excluded by shape rather than by key: a range is never one
of anything. There is no per-key allow-list; a flagged string is either
pluralised or reworded so the count stops governing the noun, and both are
cheap. A count that cannot be one today is still worth pluralising, because the
constant behind it moves and 22 takes a different Polish form from 8.

Two companion arms close the ways the fix comes undone. Reading a pluralised
line with `Lang::get()` renders `1 transaction|1 transactions` verbatim at the
reader and throws nothing, because the line is a valid string. And the ternary
shape returns the moment someone needs a noun in the middle of a sentence.

## A query that is correct for one row, on a page that has thousands

The shape is a read that belongs to a single record, placed where the screen
holds a list of them. `CounterpartyIndexQuery::buildRow()` asked `transactions`
three questions per counterparty — a twelve-month total, the most recent line,
and twelve monthly buckets for the sparkline — so the index page cost `1 + 3N`
statements and then sorted the whole materialised set in PHP. The ledger screen
did it the other way round: `transactions-list.blade.php` mounts an
`InlineCategoryPicker` per row, and that component runs an uncached `categories`
self-join in `render()` rather than `mount()`, so every row re-read the entire
category tree on every Livewire round-trip.

Neither is wrong for one row, and that is the whole difficulty. Each query is
individually well written, indexed, and scoped to the user. The defect is only
in the arithmetic between the query and its caller, which no single file shows.

Measured on a fixture of sixteen counterparties, the index page issued 49
statements where four answer the same question; twenty-five pickers issued
twenty-five identical category reads where one does. Both grow with the ledger
and neither ever stops: a counterparty row is added for every distinct merchant
a user ever pays, and the transactions list accumulates up to 500 rows before it
pages. On the single-threaded desktop server every one of those statements is
also a request no other page can be served during.

Nothing caught it because a test seeds two rows. At N=2 the fast shape and the
slow shape return byte-identical results and differ by six statements, so every
assertion about totals, ordering and formatting passes on both. The page returns
200, the console is clean, and the suite is green — the count is the only thing
that was ever different, and nothing was counting.

So the pinning assertion is a **statement count, not a value**.
`CounterpartyIndexQuerySetShapeTest` seeds enough counterparties that `1 + 3N`
and `1 + 3` separate, then asserts the query log stays under a constant; its
sibling assertions — a counterparty with no transactions at all, one whose only
activity predates the twelve-month window, and two transactions sharing a date
so the recent-line tie-break has to be resolved — are what prove the set-based
rewrite returns the same answers rather than merely fewer rows. A window
function replaced the per-row `->first()`, and the tie-break inside
`ROW_NUMBER() OVER (… ORDER BY posted_at DESC, id DESC)` has to spell out the
ordering the per-row query got from its own `orderByDesc` pair, or one
counterparty in a hundred quietly shows the wrong line.

Two follow-on rules come out of it. A Livewire child's `render()` runs on every
round-trip and not only on mount, so a query there is per-render per-instance
and belongs in the parent or behind a memo. And a memo on a container-shared
service has to be keyed by reader: `CategoryOptionsQuery` is bound `scoped()`
rather than `singleton()` and keys its cache by user id and locale, because a
category cache that outlives the request hands one household member another's
tree, and one that ignores the locale freezes the reader's language at whoever
looked first.

## A chunked read with an unchunked write

The shape is a loop that pages its **read** correctly and then issues a
statement per row on the **write**, so the bound everyone reads in the code —
`chunkById(500)` — describes only half of what the pass does.
`EncryptionMigrationService` had it in both of its passes: the op-log sweep and
the projection sweep each paged 500 rows and then wrote them back one
`->where('id', $row->id)->update(…)` at a time, inside the one transaction that
spans the whole enable. `PreMigrationSnapshot::restoreFromSnapshot()` had the
same shape on the rollback path. Beside them sat a third: a `$cache->put()` of
a 0-99 integer per row, and `CACHE_STORE=file` makes that a filesystem write
per ledger line, also inside the transaction.

The snapshot had the matching read-side shape. It `->get()` every sensitive
column of six whole tables — `transactions.raw_payload` is the entire original
bank line — into one array, then `json_encode`d that array into one string, so
peak memory held the ledger twice at once with no chunking anywhere.

Measured on a 520-transaction fixture with counterparties, notifications and an
op log beside it: 520 statements to sweep the transactions where 8 do, 1242
cache writes where 7 do, and 525 statements to restore where 8 do. The snapshot
of 1200 rows carrying 8 KB payloads each grew peak memory by 20.4 MB where the
streamed writer stays under 12. None of that is a constant factor — every count
is one per row of a ledger a user keeps for life, and a phone is where it runs
out first.

Nothing caught it because the read really was bounded, and that is what a
reviewer checks. Correctness never wavered either: a statement per row and one
statement per chunk write identical rows, so every assertion about values,
epochs and idempotency passes on both. Only the counts differed, and nothing
was counting.

So the pinning assertions in `EncryptionMigrationBatchedWritesTest` are
statement counts, cache-write counts and a peak-memory ceiling, seeded past one
`CHUNK_SIZE` so the two shapes separate — each paired with a full before/after
value comparison, because a fast pass that moves financial data wrongly is
worse than a slow one. Three rules come out of the rewrite. Batch through
`CASE id WHEN … ELSE <column> END` rather than `upsert()`: SQLite and Postgres
both check `NOT NULL` on the proposed row *before* the conflict resolves, so an
upsert would have to rewrite every non-defaulted column to update one, and any
column left off the update list is overwritten on conflict. Size the batch by
bound parameters rather than by rows, since a build compiled before SQLite
raised its ceiling stops at 999. And a snapshot of user data is written as one
line per row and read back a line at a time, so neither side ever holds the
whole of it.

## A column that is in an index but does not lead one

The shape is a `WHERE` on a column a reviewer can find in a `CREATE INDEX` line,
sitting in a position no lookup can seek on. `transactions.counterparty_normalized`
appears exactly once in the schema: seventh, in the fingerprint composite
`(user_id, account_id, posted_at, booked_at, amount_minor, currency,
counterparty_normalized)`. A read filtering on `user_id` and
`counterparty_normalized` and nothing between them cannot use it, so SQLite scans
the user's whole `transactions` partition and sorts what survives.

`MerchantDisplayName::fromTransactions()` is that read — newest
`counterparty_name` for a merchant — and `ExpenseSeriesDetector` calls it once
per distinct merchant on every sweep. The cost is the product of the two things a
ledger grows in: merchants seen, times transactions kept. Beside it the same
sweep asked `recurring_series` three separate questions per cluster —
`cluster_key`, `cluster_counterparty_key`, and the stored variance tolerance keyed
on the second of those. Measured on twelve merchants with a year of history each:
178 `recurring_series` reads for a sweep that changes nothing, against one.

Nothing caught it because the column is indexed, in the sense a `git grep` can
confirm. A composite index reads as coverage for every column named in it, and
the position is the whole of the difference. Nothing failed, either: the query
is correct, scoped to the user, and returns in microseconds on the fixtures —
which hold two merchants, where a scan and a seek are the same instruction.

So the pinning assertion is an `EXPLAIN QUERY PLAN` over the exact query, in
`ExpenseSeriesDetectorQueryShapeTest`, asserting the index by name and asserting
the plan contains no `SCAN transactions` and no `TEMP B-TREE` — a plan, not a
duration, because a duration on a fixture measures nothing. The trailing
`posted_at` is part of it: it turns the `ORDER BY posted_at DESC` into a
backwards walk of the index instead of a sort. Its sibling assertion is a full
before/after dump of every `recurring_series` and occurrence row the sweep
writes, seeded with two merchants sharing a `posted_at`, a merchant whose rows
carry no `counterparty_name`, rows older than the detection window, a second
currency, and a second user — because a lookup that got faster and picks a
different merchant name is worse than the slow one.

One rule comes out of it. A read is indexed when its equality columns are the
index's leading columns, in order; anything else is a scan wearing an index's
name. Write the index the read needs, and prove which one the planner picked.

## A memo on a singleton that never asked whose data it holds

`MerchantNameResolver::regionFor()` was memoised, with a comment above it
saying why: it runs once per transaction across a whole import. The two
`merchant_aliases` reads directly beneath that comment were not. `resolve()`
cost an exact-match query, a 500-row candidate fetch and a `usort` of that
fetch **per row** — a five-year backfill of 40,000 rows paid 80,000 statements
and 40,000 sorts for a list that changes when the reader renames a merchant,
and the Mystery Merchants page paid 4,000 statements and 2,000 sorts on every
Livewire round-trip because its scan is 2,000 transactions wide.

Nothing caught it because it is correct. Every answer is right, the suite is
green, and a per-row query is invisible in a test that resolves one
description. The cost only exists at the size of a real ledger, which no
fixture has.

The memo that fixes it carries the danger. `MerchantNameResolver` is a
`singleton()`, so a container-wide memo would answer one household member out
of another's aliases — a wrong name on someone else's money, arriving silently,
and passing every test written for one user. The key is the user id, and the
test that proves it resolves for two readers in both orders against one warmed
instance; mutating the key to a constant turns it red. The second duty is the
mirror of the first: a memo held for the life of the container is stale from
the moment anything writes the table, so `CreateMerchantAlias` calls
`forget($userId)` and the rename popover's save-then-render round-trip is the
test that fails without it.

## A row set read whole to answer a bounded question

Three shapes of the same mistake, all in the import path, all correct.

`BuildConsolidatedPreviewQuery` accumulated every row of every contributing run
into `$allRows` to ask three things of it — is it empty, did every row fail,
which reason did the first failure carry — and accumulated every non-error row
into `$sampleCandidates` to take five. It runs inside a Livewire `render()`, so
that is per round-trip of the onboarding step: three runs of 2,000 rows cost
794 ms a render, against 0.9 ms once the counts, the first reason and the five
rows are computed where the preview is written and read back as a small entry
beside it. `RecordTransactions` had the write-side twin: a `firstOrFail()` by
fingerprint per inserted row, inside the chunk transaction that had just
written all of them — 40 statements for a 40-row chunk where one
`whereIn('fingerprint', …)` per owner does it, and 500 per chunk on a real
import.

None of it was ever wrong, which is why review passed it and why the tests that
pin it now are paired: a statement count or a millisecond figure beside a
full value comparison on the same fixture, seeded for zero rows, all-failed,
part-failed, exactly-the-limit and past-the-limit. A fast wrong answer about
money is worse than a slow one, so "fewer queries" is never the whole
assertion.

Two rules come out of it. A read-back after a batch write is scoped by the
owner as well as the key — `transactions.fingerprint` is unique per user and
not globally — and it keeps the write order, because the listeners downstream
run in it. And a cache entry that a render reads is shaped to what the render
asks: `PreviewCache` writes the section summary with the preview payload,
refreshes it with the payload, drops it with the payload, and anything writing
the preview key directly drops the summary key beside it.

## An explanation the analyser cannot see

`tests/Contracts/EmptyBodyExplainsItselfWhereSonarLooksArchTest.php`

An empty method body is allowed here — a null object, a Livewire poll target, a
test seam — provided it says why it is empty, and sixteen of them do. The rule
is not "write a comment". It is "write it in one of the two places `S1186`
actually reads", and nothing in this repository knew where those were.

The check takes the *last* comment between the braces, or the *last* comment
above the declaration ending on the line directly before it, and asks only
whether it holds three consecutive word characters. Every other thing about the
comment is invisible to it. Three consequences follow, and not one of them is
guessable from outside:

- **A blank line between the comment and the signature turns the comment off.**
  The distance is measured to the declaration's first token — the attribute,
  where there is one, not `function` — so a single blank line puts a correct
  explanation out of range and the method reads as unexplained.
- **Only the final `//` line counts.** Each line is a separate comment to the
  analyser, so a four-line explanation ending on an em dash, a closing bracket
  or a bare URL is read as no explanation at all. That is the one that shipped:
  `NullKeyCustodian::forget()` carried a careful three-sentence paragraph and
  was reported empty because the last sentence happened to end on `it.`
- **The rule reports main sources only.** Forty-six empty fakes and spies live
  in the test roots. None is a finding, and a guard sweeping them would have
  failed on its first run against work that was never wrong.

What it cost was a round-trip per occurrence, and the round-trip is slow in the
worst way: the failure arrives from the hosted analysis after the branch is
pushed, naming a file and a line with a message about a *nested comment* that
reads — to anyone looking at a thoroughly commented method — as though the
analyser is simply mistaken. The guard replicates the check so that failure
lands on the machine that caused it, and pins all three behaviours above against
fixtures, because a replication of someone else's rule is the kind of thing that
drifts into quietly agreeing with itself.

## A poll that stops when the reader looks away

`tests/Contracts/PollSurvivesABackgroundedWindowArchTest.php`

Livewire throttles a poll whose tab is hidden to roughly one tick in twenty — a
mean interval of a minute against a stated two or three seconds. It is a
deliberate and sensible default, it is undocumented at the call site, and
`wire:poll.3s` reads on the page as a promise of three seconds.

Cross-device pairing is where it surfaced, because that ceremony *instructs* the
reader to pick up the other device: the window that has to notice the peer is
the one guaranteed not to be in front. The desktop's own daemon log shows the
poll running on the dot every three seconds for twenty-one consecutive ticks,
then gaps of **87 and 110 seconds** — and the phone's acceptance landed in one
of them. Three rounds of device testing had blamed the handshake.

The rule is tree-wide rather than ceremony-specific, and the second case is the
one that argues for it. A pairing ceremony is watched by someone standing
between two devices; a progress strip — an import, a rules re-apply, a mailbox
backfill — is watched by someone who started it and *went to do something else*.
That reader is the normal case, not the exception, and a frozen bar is what they
come back to.

Two things the guard has to get right, and both were wrong in the first draft:

- **A poll described in prose is not a poll written in markup.** Seven of these
  views explain the poll in a `{{-- --}}` block directly above it, and eight of
  the twenty-five matches in the tree are that prose. The scan blanks comment
  spans while preserving their newlines, so the offenders it reports still carry
  the line number the contributor has to open.
- **A comment that quotes an attribute goes stale when the attribute changes.**
  Two of them did — one backticked the full attribute, one claimed to reuse
  another page's idiom *verbatim* — and both were corrected in the same pass.
  A comment naming exact markup is a copy, and the copy is what goes stale.

## A timestamp stored as text, written at the local offset

`tests/Contracts/TextTimestampsAreZuluArchTest.php`

The sync tables store their timestamps as TEXT, so SQL compares them byte-wise
rather than as instants. `->toIso8601String()` renders the *writer's* local
offset, and `2026-06-15T20:30:00+02:00` reads as "20:30" against a
`2026-06-15T19:00:00Z` sibling it actually predates by half an hour. Two forms
in one column therefore sort wrongly, and neither one looks wrong on its own.

`pairing_tokens.expires_at` had earned `ZuluTimestamp::stamp()` already,
because it is compared in SQL and a stretched or refused TTL is visible. What
that fix left behind was the more dangerous half: the columns beside it kept
`->toIso8601String()`, so a single row carried a Zulu expiry next to a `+02:00`
`created_at`, and a reader could not tell which form it had. `device_registry`
was the same shape and worse placed — `confirmedDevices()` orders by
`paired_at`, so the Devices & Sync list was already sorting on it, and the
registry is long-lived: the rows sat on both of the user's devices for as long
as the pairing lasted, where a pairing token expires in ten minutes.

Nothing caught it because nothing was *wrong* while one format held the column.
The bug arrives the moment a second one does — a device that crosses a DST
boundary or a timezone, or the fix itself landing without rewriting the rows it
found. That is why the accompanying migration rewrites rather than deletes, and
why the guard exists at all: the failure mode is a column with two conventions
in it, and the guard is what keeps a column to one.

The list is derived, not written down. It walks the migrated schema for columns
named `*_at` whose storage type is TEXT, which is the precise definition of "a
timestamp SQL will sort as a string", then scans the production writers of those
tables. A table added tomorrow is covered by its own migration. Three writers
are pinned rather than converted, each with the reason it could not be: two live
in `Modules\Mobile`, which may not import `Modules\Sync\Internal` where
`ZuluTimestamp` sits, and the pin is compared with `toBe()` so it can only
shrink.

## A step change announced under a name nothing listens for

`tests/Contracts/StepChangeEventNameArchTest.php`

A wizard's steps share one page, so advancing is a re-render and never a
navigation: the browser keeps the offset the previous step was left at, and the
new step opens with its top already above the viewport. It has now been measured
twice — the setup wizard handed step 3 a `scrollY` of 424, and the mobile import
bootstrap handed its recovery codes 107, far enough under `viewport-fit=cover` to
put a heading behind the status-bar clock.

Nothing reports it. The page returns 200, the step renders completely, every test
that asserts on the rendered step passes, and scrolling up once makes the screen
correct — which is exactly why the first fix was written for one screen and the
identical defect went on shipping on two others.

The fix is a re-render that says so: the component announces through
`Modules\Core\Public\Http\Livewire\Concerns\AnnouncesStepChanges`, and one
listener in `resources/js/app.js` returns the page to the top. The listener is
registered before any component mounts and lives outside the DOM Livewire morphs,
so it cannot be lost, duplicated or re-bound by a morph — which the per-screen
Alpine handler it replaced could be. It also has to be announced *only* where the
step actually changes: the listener moves the viewport, so announcing an ordinary
re-render would take a half-filled form away from the reader mid-typing, a worse
defect than the one it fixes.

What the guard checks is the seam between the two halves, because that is the
part nothing else can see. A Livewire dispatch names a browser event and no
compiler checks that anything is listening — the first arm scans every string
literal in backend PHP and Blade for a *second spelling* of "a step changed"
(`wizard-step-changed` was one), rather than scanning the dispatch call sites,
which name a constant and would report nothing. The second arm keeps the other
half honest: the bundle must still bind that exact name and still move the
viewport when it arrives. The third pins the name to one home, since a second
literal is how a pair like this drifts — one of them gets renamed.

A modal wizard is deliberately outside this. Its steps change inside an overlay
the page behind does not scroll with, so scrolling the document under it would
move something the reader is not looking at.

## A service graph frozen at the moment it was registered

`tests/Contracts/RegistrationPointsResolveOnDemandArchTest.php`

This is the only entry on this page written after the *fourth* time. The other
rules here each answer one shipped screen; this one answers a shape that has
been fixed, class by class, four separate times, each fix written as if it were
about that class.

The shape is ordinary constructor injection at a place that is registered once
and kept. A console command is not built when it runs — Artisan builds **every**
registered command merely to assemble its list, which is what happens on every
artisan invocation. An event listener is built on the first dispatch of its
event, which can be arbitrarily earlier than the work it does: an app unlock
comes long before a pairing. Whatever that construction reaches is built at that
moment and held for the life of the process, along with every singleton created
on the way down. Configuration written afterwards is invisible to it.

It has cost, in order:

- **`SyncServeCommand` and the WebSocket handler.** Building the handler at
  command registration reached the encrypted search writer, so every artisan
  call needed an application key — including the `key:generate` that mints one.
  The handler became a `Closure` the command calls from `handle()`.
- **`StartSyncListenerOnEnable` and the identity reader.** Taking
  `SyncDaemonIdentity` in the constructor pulled `DeviceIdentityLoader` into the
  container the moment sync was enabled, freezing whichever `AppLockKeyService`
  was bound at that instant. The daemon handoff then read the sealed identity
  through a key service from before the unlock. Fixed on both sides: the
  listener resolves the reader on demand, and the provider stopped binding
  `DeviceIdentityLoader` as a singleton.
- **`HoldPairingCeremonyOpenOnUnlock` and `PairingGateway`.** The gateway
  reaches `PairingPeerLink`, `PairingFrameCourier` and `RelayClient`, and those
  are singletons: the first unlock of the process built all of them. A relay
  configured later in the same run — which is exactly what scanning a pairing QR
  does — was invisible to the courier that had already captured the previous
  transport. `PairingFrameCourierTest` went from 5 failed / 2 passed to 7 passed
  once the listener resolved the gateway on demand.
- **`SyncServeCommand` and `PendingPairingCourier`.** The same command, a
  different parameter, the same freeze at Artisan registration — which is before
  almost anything. It became a `Closure` too, matching how the command already
  handled its handler.

Nothing caught any of them because none of them looks wrong. Constructor
injection is the recommended shape everywhere else in this tree, the object the
container hands over is real and fully built, and no exception is raised: the
first symptom was a hard failure at a moment nothing should have been built, and
the other three were a correct-looking object that was merely old. Unit tests
resolve the collaborator themselves and get a fresh one, so they pass. Only a
test that reconfigures the container mid-run — or a device — sees it, and the
four sat far enough apart in the tree that each was read as a local mistake.

The guard's discriminator is the container's own answer rather than a list of
class names. A provider that writes `bind(SomeConcreteClass::class, …)` has
stated, in a reviewable line, that a shared instance of that class is wrong —
each caller must get one built from the bindings and the configuration in force
when the call happens. Capturing one at a registration point contradicts exactly
that. An *interface* binding says something else entirely (which implementation),
so only concrete abstracts seed the scan; without that split,
`bind(CurrentUser::class, CurrentUserService::class)` would make every listener
that reads the current user an offender. The registration points come from the
framework's own registries — the console kernel's command list and the event
dispatcher's raw listeners — so a module added tomorrow is in scope with no list
to edit. Two alternatives were tried and dropped: a hand-written "pairing/relay
graph", which catches the four and nothing else, and "any class with a setter",
which reads `RelayConfig` correctly but misses `DeviceIdentityLoader` and
`OpLogWriter`.

Against the whole tree this reports three constructor parameters, all pinned:
two accepted (a per-resolve service whose staleness cannot matter, reached from
a one-shot command) and one that is **not** accepted — `MobilePullCommand` holds
`MobileSyncTriggerService`, which holds `DeviceIdentityLoader`, `RelayClient` and
`RelayConfig`, on a phone, in a background process. It is pinned only so the fix
can be sequenced with the rest of the work in `Modules/Sync`; the pin is compared
with `toBe()` in both directions, so the line has to be deleted in the same
commit as the fix.

The guard is deliberately narrower than the hazard, and says so in its own
failure message rather than here, because that is where a contributor reads it.
It reads constructor parameter types only, so a graph reached through `make()` in
a constructor body, a captured `Closure`, or a facade is invisible — which is
also, deliberately, the shape of every fix. It knows a class is late-configured
only where a provider said so; a hazardous class nobody bound at all looks
neutral and is followed only as a path to one that was. And it covers console
commands and class-name event listeners: a closure listener, a middleware, a
queue worker, a Livewire component and a provider closure that captures a
resolved object have the same hazard with no coverage at all.

`SyncCaptureListener` is the one place that had the shape right from the start —
it resolves `OpLogWriter` inside each handler and treats a
`BindingResolutionException` as "no writer yet, skip" — and it is asserted clean
by the guard for that reason.

## A class in a Blade template that matches no rule

`tests/Contracts/BladeClassesResolveToARuleArchTest.php`

A class name written in a template that no stylesheet defines renders
**unstyled**. Nothing reports it: the element is in the DOM, the route answers
200, and every assertion over the markup passes, because the markup is exactly
what the template said. Only a reader looking at the screen can tell.

Found on a real Android phone during round 5. The onboarding starting-balance
card's amount field had no box around it — all eleven of its `balance-card-*`
classes were used in the blade and defined nowhere. Sweeping the tree for the
same shape turned up 56 classes across six modules, Onboarding and DevMode
holding most of them.

The check reads the **compiled** stylesheet under `public/build/assets/`, which
carries every Tailwind utility actually generated for this tree alongside every
custom rule, so a class absent from it is inert rather than merely absent from
source. A class defined in the template's own `<style>` block counts as styled —
the tax PDF is rendered by dompdf against its own sheet and legitimately does
this. It fails loudly when `public/build` is missing rather than skipping,
because an invariant that quietly does nothing is worse than none.

It is deliberately narrow: only fully static `class="..."` attributes are read,
since a Blade expression cannot be evaluated by a scan, and only lowercase
hyphenated tokens are considered, which leaves single-word classes and Tailwind's
own variant syntax out of scope. That narrowness has a cost worth knowing —
`per-file-chip` is composed through `$attributes->class([$stateClass])`, so no
scan of `class="..."` reaches it, and the connector chip was found unstyled by
reading the component rather than by the guard. A class a test selects on but
nothing paints is allowlisted in the test with its reason.

The compiled sheet is read as-is, so the build must be current: a utility added
to a template since the last `npm run build` has not been generated yet and
reads here as though it matched nothing. The failure message says so, because
that is where somebody meets it.

## A validation rule declared but never run

`tests/Contracts/DeclaredValidationIsEnforcedArchTest.php`

Livewire enforces a `#[Validate]` rule only when the component actually calls
`validate()`. The attribute alone does nothing. A component that declares a rule
and never runs it reads as validated in review — the rule is right there above
the property — and accepts anything at runtime.

The app lock declared its PIN as `^[0-9]{6,10}$` on four properties and never
validated any of them; the only validation call in the component was a
`validateOnly()` for an unrelated timeout field. The real gate checked two
things, that the PIN met a minimum length and that both boxes matched. So a PIN
could contain letters and could exceed the declared maximum, while the unlock
surface is a numeric keypad — a PIN that can be set and then never typed again.
Found on an iPhone in round 5 by an agent that locked itself out and recovered
through sign-out and a password reset.

The rule that broke was declared in one place and enforced in another, which is
how the two came to disagree; the fix shape is a single definition of what a PIN
is, rather than a second copy kept in step by hand.

Any `validate()` call at all exempts a component, including one passed explicit
rules, so only a component that never validates anything is reported. That
narrowness is deliberate — the guard exists to catch a rule nobody runs, not to
adjudicate which rules a call covers.

## A Livewire redirect from `mount()`

`$this->redirect()` calls `skipRender()`. On a Livewire *update* that is
harmless — the client is handed a redirect effect and navigates. On the
**initial full-page render** of a component registered directly as a route
action, the reader gets whatever the layout draws around a slot the component
never filled.

Both Composer roots answer that with a real 302: a probe against
`/setup-wizard` with nothing left to resume returned `302 -> /` from the repo
root and from `mobile-app/`. The NativePHP runtime on Android does not. There
the same route answered **200 with the default layout painted around an empty
slot** — no exception, no log line, `BRIDGE_TOTAL [/setup-wizard] 73ms` in
logcat, and a blank body under the app header with no way back into the
wizard. Redirects as such do reach the reader on that runtime: `/login` for a
signed-in reader answers 200 carrying the dashboard's body, because the bridge
follows the middleware redirect server-side. It is the Livewire-mount one that
does not.

Found on a Samsung SM-S928B in round 5, after "Resume later" left nothing for
`ResumeStepResolver` to resolve and `SetupWizard::mount()` took its
`$resumeKey === ''` branch.

The fix shape is to render a coherent terminal state instead — the wizard now
mounts `WizardStepRegistry::lastStep()` with `allComplete` set. A `mount()`
that cannot proceed has to answer with a page, because on one of the two
runtimes the redirect it would rather send never becomes one.

`MobilePairingScan::mount()` carries the same shape on the pairing entry and
has not been exercised in that state on a device.

## A stale `X-Livewire` header on a page load

`Modules/Mobile/tests/Feature/StaleLivewireHeaderDoesNotEatTheQueryStringTest.php`

Livewire asks one question — `request()->hasHeader('X-Livewire')` — and
branches two behaviours on the answer:

- `BaseUrl::getFromUrlQueryString()` reads a `#[Url]` property from the URL's
  query string when it is false, and from the **Referer** header when it is
  true.
- `SupportRedirects::dehydrate()` turns a `$this->redirect()` into a real
  `abort(redirect(...))` when it is false, and into a client-side effect when
  it is true.

The Android runtime keeps one PHP worker alive across every request, and once
that worker has served a single component update, an ordinary page load
arrives with the header still on it. Both behaviours then invert.

Measured on a Samsung SM-S928B, same device and same data, cold worker versus
warm:

| request | cold worker | after one component update |
|---|---|---|
| `GET /drift?type=anomaly` | Unusual charges tab | Subscription drift (the default) |
| `GET /tax?year=2025` | — | `<title>Tax 2026</title>`, `year` 2026 in the snapshot |
| `GET /setup-wizard` with nothing to resume | 131,604 bytes, `Dashboard · Beatrax` | 105,316 bytes, no page component, **blank body** |

So every deep link carrying a query parameter lands on the default view within
seconds of launch — the sidebar's own "Unusual charges" entry points at
`?type=anomaly` and answered "No open drift alerts" while the dashboard
counted seven. And a redirect from `mount()` paints nothing at all.

The query string itself reaches PHP intact — logcat shows
`persistent_dispatch: GET /drift?type=anomaly&tab=dismissed` — and a plain
`$request->boolean('force')` still reads it on a warm worker. Only the two
branches above are affected, and both through that one header.

Reproduced at a desk by sending the header on an ordinary GET, which is what
the test does. The fix strips it from every request that is not Livewire's own
update endpoint, alongside the three other middlewares that exist to undo what
this runtime carries between requests.

## A picker offering a format nothing can parse

`tests/Contracts/OfferedFormatsResolveToAParserArchTest.php`

`SourceAdapterRegistry` is keyed by format id. A picker offers a list of format
ids. Nothing held the two together, so a format could sit on a chip with no
adapter bound to it, and the only report was an import that produced nothing.

ING existed twice under two ids. The upload wizard offered `ing-nl-csv`, a CSV
preset that binds a real `GenericCsvAdapter`. First-run onboarding offered
`ing-csv`, a `SourceFormat` case nothing ever bound. Worse, the ING chip only
ever set the *hint*; the format stayed on the CSV row's landing default,
`asn-csv`. So the file that reached the pipeline was an ING statement declared
as ASN. Measured on the ING fixture, both spellings previewed **zero rows and
zero errors** — the wizard reported the step complete and imported nothing.
Every new user who banked with ING and used the wizard as designed hit that.

Nothing caught it because each half was individually well-formed: the enum case
was valid, the chip rendered, the validator's `in:` rule admitted the value, and
the pipeline's own hint guard was satisfied. The two lists simply never met.

The rule reads each picker's format list out of its source — a Livewire
component's `SUPPORTED_FORMATS` and its `$selectedFormat`/`$sourceFormat`
default — resolves constants through the file's own imports, and checks every
value against the live `SourceAdapterRegistry` plus `ParseStage`'s receipt arm.
It covers binding drift as well as picker drift: the provider's adapter map now
keys off `SourceFormat` cases rather than bare strings, so renaming a case moves
both sides together, and a map that stops tracking the enum fails here.

A second assertion runs over `SourceFormat::cases()` directly. That is the one
that would have caught `IngCsv` on the day it was added, before any picker
offered it.

## Related

- [Writing an arch invariant](arch-invariants.md) — the mechanics every rule in
  `tests/Contracts/` shares, and why the rationale belongs in the failure message
- [Module boundaries](../architecture/module-boundaries.md) — the largest single
  group of invariants
