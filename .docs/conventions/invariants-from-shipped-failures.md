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

This file is declared `merge=union` in `.gitattributes`, because several
branches append to it at once and none of them ever removes a line — so every
pair conflicted and every resolution was "keep both". Union does that
automatically, and it concatenates the two sides *verbatim*: if your section's
last line is not blank, it ends up touching the next branch's heading and
`hygiene / markdown` fails on MD022. **End every new section with a blank
line**, and re-run `npx markdownlint-cli2 ".docs/**/*.md"` after the last
rebase rather than when you wrote it — a rebase re-runs the union driver too.

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

  **Measuring it.** `getBoundingClientRect()` answers about the paint, and the
  paint is deliberately smaller than the reach. A device walk that measures
  boxes will report the welcome screen's links at 327x36, the report chips at
  29px and the search toggles at 20px, and every one of those is the design
  working: `.tap-chip`, `.chip`, `.srch-chip-toggle` and the rest carry a
  `::after` sized `max(100%, 44px)` under `@media (pointer: coarse)`. Three
  device rounds have now filed them as defects. The measurement that answers
  the actual question is `document.elementFromPoint()` at the corners of the
  44px band — and the failure it *can* find is a real one, because an ancestor
  with `overflow: hidden` (a `truncate` utility, most often) clips the halo
  away while leaving the box exactly where it was.
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
and left five providers bound to its name. They sat inert. Two were harmless
duplicates of counts the sidebar already had, but three held badges the product
then did not have at all — Inboxes, Chains and Forecast shipped with no count on
them, and nothing anywhere said so. The tests over them were parked as
`->todo()` rather than failing, so the suite stayed green over the gap.

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

## A count the browser glued a translated fragment onto

`tests/Contracts/CountedNounDeclaresItsPluralArchTest.php`

The command palette on a real iPhone, one transaction matched, rendered "**See
all 1 results**". Not a missed `|`: the lang file held no counted line at all.
It held `see_all_prefix` = `'See all '` and `see_all_suffix` = `' results →'`,
and Alpine put the number between them with `+`. The count never passed through
PHP, so there was no placeholder for the rule above to find and no plural line
for `TranslationParityArchTest` to measure. Every rule that had been running
over these files for months was reading the wrong side of the seam.

The split is the larger damage. A prefix and a suffix pin the word order —
numeral in the middle, noun after it — for all twenty-six locales at once, and
two of the translators had already said so in the only way the shape allowed:
Slovak and Ukrainian abandoned the sentence and wrote `Zobraziť všetky výsledky
(` … `) →`, moving the count into brackets because their grammar could not sit
where the template had left the hole.

Four sites had the shape. Three in the palette — the "see all" row, the footer
count, and a query echoed between `No transactions match "` and `"` — and one
the extended rule found that no one had reported: the mobile lock screen's
`aria-label`, which announced "1 digits entered" to a screen reader. Its twin in
`Modules/Auth` had already been written correctly, so the two lock screens had
disagreed about this in production. Two more sites sat one layer out, printing
an Alpine number beside a translated word in the template rather than
concatenating it, both in the log tailer's totals strip.

`Lang::choice()` cannot be the fix on its own, because the number does not exist
while PHP renders. `Lang::arms()` ships the arms and the reader locale's own
index table — both built from Laravel's `MessageSelector`, so nothing
re-implements a plural rule — and the `$plural` Alpine magic reads that table.
A JavaScript `n === 1 ? a : b` is the shape to refuse: it is English's two forms
written where no locale rule can reach them, and it passes an English test while
answering wrongly for Slovenian at 3, Polish at 5 and Latvian at 0.

Two rules were added and one habit with them. The rules read Alpine expression
attributes: one fails on a translated line joined by `+` to anything, the other
on an Alpine-rendered count standing beside a translated line. The habit is that
every `preg_match_all` in that file now throws on `false` rather than reading it
as "nothing matched" — a guard that stops reading used to report a clean tree.

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

`pairing_tokens.expires_at` had earned `Instant::zulu()` already,
because it is compared in SQL and a stretched or refused TTL is visible. What
that fix left behind was the more dangerous half: the columns beside it kept
`->toIso8601String()`, so a single row carried a Zulu expiry next to a `+02:00`
`created_at`, and a reader could not tell which form it had. `device_registry`
was the same shape and worse placed — `confirmedDevices()` orders by
`paired_at`, so the Devices & sync list was already sorting on it, and the
registry is long-lived: the rows sat on both of the user's devices for as long
as the pairing lasted, where a pairing token expires in ten minutes.

Nothing caught it because nothing was *wrong* while one format held the column.
The bug arrives the moment a second one does — a device that crosses a DST
boundary or a timezone, or the fix itself landing without rewriting the rows it
found. That is why the accompanying migration rewrites rather than deletes, and
why the guard exists at all: the failure mode is a column with two conventions
in it, and the guard is what keeps a column to one.

The list is derived, not written down. It walks the migrated schema for columns
whose name ends `_at`, `_date` or `_time` and whose storage type is TEXT, which
is the precise definition of "a timestamp SQL will sort as a string", then scans
the production writers of those tables. A table added tomorrow is covered by its
own migration. The pin is compared with `toBe()` so it can only shrink, and it
is now empty: the two `Modules\Mobile` writers that sat on it were pinned only
because the seam lived in `Modules\Sync\Internal`, which Mobile may not import.
Moving it to `Modules\Core\Public\Support\Instant` retired both pins.

The same guard carries a second half, because the first selector could not see
the columns that actually shipped a defect. `internal_date` on `inbox_messages`,
`file_imports` and `discovered_senders` ends in neither `_at` nor TEXT — it is a
DATETIME — so it was invisible to a rule keyed on both. Those columns are read
back with `CarbonImmutable::parse` or an `immutable_datetime` cast, which apply
the app's offset, so the digits written have to be in the app's frame whatever
frame the instant arrived in. See the entry below.

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

Found on a real Android phone. The onboarding starting-balance card's amount
field had no box around it — all eleven of its `balance-card-*` classes were
used in the blade and defined nowhere. The same shape existed across six
modules, Onboarding and DevMode holding most of it, which is why the check is
tree-wide rather than a fix in one template.

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
Found on an iPhone, where a PIN set this way locked the reader out of the app
entirely: recovery took a sign-out and a full password reset.

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

Found on a Samsung SM-S928B, after "Resume later" left nothing for
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

## `wire:model.blur` never reaches the server

Two shipped fixes were inert in the browser while their tests passed. Both had
the same shape: a refusal shown after a rejected submit, and an `updated()` hook
whose job was to re-test that refusal as the reader corrected the box under it.
Pots printed "Amount exceeds unallocated balance." and kept printing it after the
amount was corrected; signup left "Passwords do not match." standing over a
ticked "Both passwords match".

Livewire 4 changed what `wire:model`'s modifiers mean. A modifier is EPHEMERAL
unless `.live` appears before it: `wire:model.blur` syncs the client-side `$wire`
proxy when the field loses focus and sends no request at all. The value is not
lost — it rides along with the next commit — but no `updated()` hook runs at the
moment of the blur, which is the moment the whole feature is about.

Measured on the device: with a network probe installed over `fetch` and
`XMLHttpRequest`, a real focus, a real edit and a real blur on a
`wire:model.blur` field produced zero requests, both bound inputs showed the new
value, and the server's snapshot still held the old one.

`Livewire::test()` does not catch it. It calls `set()` on the server, which
triggers the hook directly, so the hook is exercised and the binding that can
never invoke it is not.

The invariant pairs the two sides: a component that declares an `updated`
lifecycle hook may not have a view binding a field with `.blur` unless `.live`
comes first. A component with no such hook is left alone — there the modifier
only delays a client-side sync, which is a real if rare intent.

## Asking whether text wrapped, and being told about its box

Three rounds of device sweeps have now filed the same false positive under
three different measurements, and each one measured something adjacent to the
question.

The question is "did this string break across lines". The wrong instruments,
in the order they were tried:

- **Element height ÷ line-height.** A 44px tap row holding one 19.7px line
  reports 2.2, so every amount on `/uncategorized` came back as wrapped. This is
  the touch-floor mistake again in another costume: the box is not the glyphs.
- **Subtracting padding first.** Better, and still wrong — the 44px came from
  the row's own layout, not from padding, so there was nothing to subtract.
- **`Range.getClientRects().length` on the text node.** Exact about fragments,
  not about lines. A text node with leading whitespace yields a 4px rect
  *beside* the glyphs on the same line, which read as two lines for every
  amount on `/drift/watch`.

What answers the question is the number of **distinct tops** among the rects
wider than a hairline:

```js
const r = document.createRange();
r.selectNodeContents(textNode);
const lines = new Set([...r.getClientRects()]
    .filter((x) => x.width > 1 && x.height > 0)
    .map((x) => Math.round(x.top))).size;
```

The same shape settles the touch floor: `elementFromPoint` across the 44px band
rather than `getBoundingClientRect()`, because the halo is a pseudo-element
larger than the control it extends. Both are the one rule — ask the browser
about the thing you are asking about, not about the box that contains it.

## A halo that a wrapped link never gets

`.tap-link` extends a short control to the 44px touch floor with an absolutely
positioned `::after` band, and on an inline element that wrapped it does
nothing at all. An inline box split across two lines has no single containing
block for an absolutely positioned child, so the band is generated, computes to
`height: 44px`, and lands on neither fragment. Probed on an iPhone 12 mini, the
dashboard's "Voeg je eerste doel toe" answered a finger over 18px — the bare
line box — with the class present and correct in the markup.

The class is still worth carrying, because the same link is one line in another
language and gets its band there: Dutch and English fail on different sites.
What it means is that "the class is on it" cannot be read as "the reader can
hit it". A source-level check answers about the markup; only the device answers
about the target.

The floor itself does not apply to every one of these. WCAG 2.5.5 and 2.5.8
both exempt a target "in a sentence or block of text", which is exactly what a
link finishing an empty-state sentence is. The ones that are standalone — a
link beside a page title — are not exempt, and those are what
`TouchTargetFloorReachesLinkButtonsArchTest` pins.

## A heading offered a hyphen takes one it did not need

`hyphens: auto` was added to headings so a single long word would break at a
syllable with the hyphen shown, rather than at whatever character ran out of
room. It also changed headings that had a space to break at. Line breaking is
greedy: it fills each line as far as it can, and a mid-word break is one more
place it can fill to. The Dutch `/drift?type=anomaly` empty state went from
"Geen ongewone" / "afschrijvingen" at 165px and 142px to "Geen ongewone
afschrijvin-" / "gen" at 283px and 38px.

`text-wrap: balance` replaces the greedy fill with an even-lines choice, and it
prefers the space. The two are complementary and both load-bearing: measured on
device, removing `balance` brings back the needless hyphen, and removing
`hyphens` brings back a 16px orphan "n" on the word that has no space in it.

`balance` is a decision the block makes about its own lines, so the selector
list is not only the six heading elements. A page heading that carries a help
mark is an `inline` h1 inside `.heading-with-tip`, and an inline box cannot hold
that decision: the block around it has to be named too, or the Czech recurring
title fills its first line greedily and drops the mark alone onto a second.
`AHeadingBreaksAtASyllableNotAtWhateverFitsArchTest` pins the rule and its
position after the `overflow-wrap: anywhere` reflow rule it narrows. It matches
on the opening selectors rather than on the closing brace, because a brace
pinned after `h6` reports a list that legitimately grew as a rule that was
deleted — which is how it read that addition.

## A translation that is present is not a translation

Parity checks answer whether a key exists and carries a non-empty string. Both
of the Dutch defects below passed it. `/community` headed the shared list
"Gedeelde merchantlijst" and asked the reader to "Help merchants herkennen",
while Anomaly, Counterparties and Core settings had all settled on "winkelier"
— Core settings headed the same list "Gedeelde winkelierslijst". The sidebar
labelled the nav item "Imports" three lines above a label reading "Importeren
uit YNAB / Actual", and its own badge said ":count import|:count imports" beside
":count abonnement|:count abonnementen".

What made them provable rather than a matter of taste was comparison, in two
directions. Across locales: all twenty-five others translate "merchant", and
twenty-three of twenty-five give "imports" a native plural. Within the locale:
the same Dutch file already had the word.
`ALocaleKeepsItsOwnWordForATermArchTest` pins the terms a locale has settled on,
because that is the half a cross-locale count cannot express.

The same count has to be allowed to say no. `Internet & Phone` looked like the
one title-cased category name until `Rent / Mortgage` and `Cloud / Software`
showed it was two against one, and `DefaultCategoryNamesStayInSyncTest` added
the reason not to reword it anyway: a seeded name is on disk in every install,
so changing the wording costs a migration. That one was put back.

The aggregate is worth keeping in mind before reaching for the same instrument
again: measured over all 3505 translated strings, the rate of English-identical
values runs 0.5% (bg) to 4.2% (fr), tracking script and loanword overlap. There
is no lazy locale to find, so identity alone is a review lead, not a rule.

## A number stops being a number when it leaves the formatter

`Fmt::number` exists so a count picks up the locale's grouping, and three
surfaces reached the reader without passing through it.

`Lang::choice` handed the count to Laravel, which fills `:count` with the raw
integer it selected the plural form from, so a finished import read "1200
transacties geimporteerd" on a screen whose money read "5.701,66". Filling it at
the seam fixes all ninety-seven call sites at once; the selection still runs on
the integer, so grouping marks cannot reach it.

The nav badge and the log tailer shortened large counts with `round($n / 1000,
1)` and `toFixed(1)`. Neither is a rounding bug — both are correct, and both
then cast the float with a dot, which is the character Dutch groups thousands
with. "1.2k" arrives as twelve hundred thousand. The onboarding chip sized a
file with bare `number_format`, which put an English comma in "1,023 KB".

`ANumberAReaderSeesCarriesTheirOwnMarksArchTest` draws the line at arity:
`number_format($v, 4, '.', '')` names both marks and is a deliberate machine
string — a cursor, a rate, an attribute — while the call that leans on the
defaults is the one that ends up on screen in the wrong language.

## A page that sizes its own title becomes a lesser page

Thirty-three pages take the h1 from the type scale at `text-2xl`. Six set it in
a style attribute instead, and two of those chose `--text-xl`: on the phone,
`/reports` and `/counterparties` wore a heading visibly smaller than every page
either side of them. Nothing was broken and no test could fail — the pages were
simply not part of the decision the other thirty-three were making.

The English copy drifted the same way and for the same reason. A hundred and
forty-five headings are written as sentences; six were written as titles, two of
them on one screen, where "Data & Devices" sat above "App lock". Only the source
language can drift like this, because every other locale capitalises by its own
rules rather than by copying.

The nav labels are in the same rule and not by symmetry: `DriftPageTest` already
required `/drift`'s title and its sidebar item to be the same string, so a rule
covering the title and not the label would let the pair drift apart while both
halves of the product still passed.

## A fallback on a name that was never defined

`var(--color-muted, oklch(60% 0 0))` appeared in fourteen rules and
`var(--color-accent, …)` in nine. Neither token has ever been declared — the
real names are `--color-text-muted` and `--color-blue` — so all twenty-three
rules silently took their fallback and rendered the same unthemed grey and blue
in light and dark alike. That is how the `/reports` filter labels came to sit at
3.77:1: nothing was broken enough to notice.

Three more had no fallback at all. `border-bottom: 1px solid
var(--color-border-faint)` is invalid at computed-value time when the token does
not exist, which throws away the whole shorthand, so two table rules had been
drawing no border and a pressed emoji-action no background.

`EveryColourVariableNamesATokenThatExistsArchTest` compares the declared set
against the referenced set. It found `--color-border-faint`, `--color-danger`
and `--color-primary` on its first run, none of which the contrast sweep could
have surfaced, because a rule that renders nothing has nothing to measure.

## Contrast is not visible to a structural probe, and not a matter of eye either

A structural sweep can report every route clean and near-perfect tap targets
while, measured against WCAG 1.4.3, the same build carries **349 low-contrast
text nodes in light and 315 in dark**, worst 2.34:1 against a 4.5:1 floor,
including every primary button in the product at 3.65:1 and 2.47:1.

Two things make it measurable rather than a judgement call. Colours resolve
through a 1×1 canvas — Tailwind v4 emits `oklch()`, and a regex over
`oklch(0.514 0.222 16.935)` reads `0.514` as a red channel and produces
confident nonsense. And the floor depends on the type: 14px at weight 500 is normal text, so a
button needs 4.5:1, not the 3:1 that applies at 18.66px bold.

The fix has a shape worth keeping. Fixing the light value alone moved nine nodes
*below* the floor at night, because a class list carrying `text-slate-400` with
no `dark:` sibling was relying on one colour being tolerable in both themes.
A light-mode contrast fix needs its dark half in the same breath, which is what
the third assertion in `AMutedTextColourStaysAboveTheContrastFloorArchTest`
pins. The other two pin the pairings that fail by construction rather than by
route: slate-400 on any light surface, and slate-500 on an element that also
carries slate-100.

## A page that declares its own layout leaves the decision its neighbours made

Two axes drifted the same way in the same round, and neither could fail a test.

The `h1` had seven spellings. Five pages set the size in a `style` attribute and
two of those chose `--text-xl`, so `/reports` and `/counterparties` wore a
heading visibly smaller than the thirty-three pages either side of them;
`/data-devices` sat at `text-lg` because a survey had grouped it with the
pairing-wizard steps rather than with the nav destinations it sits among.

The container was worse, because the cause was structural rather than a typo.
The app routes pages two ways: a route view that wraps `x-core::page-shell`
around `@livewire(...)`, and a full-page Livewire component that is the page.
The shell already owns the column and the rhythm. `/counterparties` sat inside
one **and** carried a second container of its own, so it ran 48px + 24px deep;
`/counterparties/triage` did the same with `.triage-shell` in CSS; `/reports`
was mounted bare and had only its own 24px. Measured on the phone, the same
title sat at 119, 129, 138, 146 or 170 pixels from the top depending on which
page you had navigated to. Each page looked self-consistent, which is why
nobody saw it.

Unwrapping is not symmetric with wrapping. `/reports/library` is a full-page
Livewire route with nothing above it, so removing its container left no column
at all — it takes the shell itself. Check which of the two shapes a page is
before you take anything away from it.

`APageTakesItsShapeFromTheSharedComponentsArchTest` pins both: no page writes
its own `h1`, and every page container says `py-6`. The exemptions are surfaces
that are not pages — the dev console is deliberately denser, a wizard step is a
step inside a page, and the tax PDF is a printed document — and each is listed
with the reason rather than as a bare path.

The step was `py-12` first, because nineteen containers already said it. That is
a majority, not a decision: at the 17px coarse-pointer root `py-12` is 51px of
empty band above and below every page in the product, and the product owner read
it as the header standing away from its own content. Halved, it is 25.5px. The
lesson is narrower than "12 was wrong" — consolidating on the majority spelling
settles *which* value everything shares without anyone ever having judged the
value itself, so the number is worth measuring once the drift is gone.

A page can also draw the band somewhere the column rule cannot see. `/tax` opens
`<div class="py-12">` and puts its `mx-auto max-w-4xl` column inside it, so the
rule that reads `mx-auto` class attributes found no rhythm to check and the page
kept the old band through the sweep. A second assertion reads the rhythm off
each page's **root element**, whatever that element is; both assertions carry a
floor on how much they matched, so a pattern that stops reading fails instead of
reporting a clean tree.

The near miss worth recording: the first move was to write a new `x-core::page`
component, and `x-core::page-shell` already existed, used by twenty-four files,
with a docblock describing this exact problem. Read the component directory
before adding to it; a second component for one job is the duplication the
extraction was meant to end.

## A rule that selects files by path cannot see the file everything routes through

`PagePaddingArchTest` forbids a bare `px-6`/`px-8` on a page container, because
on a coarse pointer `app.css` redefines `.px-8` to 32px against `.px-4`'s 16px.
It selects the files to scan two ways: the path contains
`/Resources/views/livewire/`, or the source contains `@extends('layouts.app'`.

`x-core::page-shell` is neither. It lives in `components/` and extends nothing,
so the rule never opened it — and it rendered `px-8`. Its nineteen callers hold
no `mx-auto` of their own, so every one of them passed trivially. The rule read
green while the component shipped the exact value the rule exists to forbid, to
every page routed through it. Measured on the phone: eight routes at a 32px
gutter against fifteen at 16px, and moving `/reports` into the shell moved it
from the majority to the minority without changing a number the vertical check
was watching.

Two things follow. A path-based selector is a claim about where a category of
file lives, and it goes stale the moment the category gets a component — so the
selector now includes `/Resources/views/components/`. And an extraction inherits
the guard debt of whatever it absorbs: converting a call site to a component
moves that site out of the rule's reach unless the component is in it.

The same rule went blind a second way, and this one had nothing to do with
paths. It matched `class="mx-auto[^"]*"` — anchored, so a column is only a
column when `mx-auto` is written first. Seven were not: `max-w-5xl mx-auto px-6`
is the same element with the width in front, and `/settings/aliases`,
`/signup`, the recovery-code screen and four mobile bootstrap screens all
shipped the 24px phone gutter this rule exists to forbid while it reported
green. A pattern that describes an *unordered* set — and a class attribute is
one — must not be anchored to one member of it.

## A constraint is applied after the value, so no utility can beat it

`min-height: 44px` from the coarse-pointer block is a **constraint**. CSS
resolves it after the used value of `height`, which means specificity, layer
order and source order never enter into it: a `height` declaration cannot win,
however it is written.

The transactions phone card carried `[&>*]:h-5` on its chip row, with a comment
explaining that three primitives had each set their own height and the row read
as lumpy. On every phone since the touch floor landed, that utility did nothing.
The cleared badge — the one chip in the row that is a `<button>` — stood at 44px
while the tax badge beside it, which the floor had been told to skip, stayed at
20. Its `padding: 2px 8px` had been drawn for a 23px pill, so at 44px with a
`9999px` radius the label sat inside its own end cap. Measured at 384px, fine
and coarse pointers rendered the same markup 20px and 44px tall.

The fix is not a bigger number on the utility; it is releasing the control from
the floor and moving its reach to the halo, which is the pattern its own row
mates were already using. `AChipRowKeepsOneHeightUnderAFingerArchTest` names the
four chips that share that row and requires each to be in both the release list
and the halo list — release without a halo shrinks the picture *and* the target.

## An element selector loses to the utility class on the same element

The touch rule drops a select's native appearance, which takes its arrow with
it, and reserves a 36px column in `padding-right` for the one it draws in its
place. `select` is an element selector. Almost every call site carries `px-3`,
which is a class, so the reservation lost and the arrow was painted over the
last of the selected option's own text. Ten of ten visible selects at 384px
reserved 12px against a 36px column.

Repeating the declaration as `select[class]` costs one specificity point and
wins. Repeating **only** the padding matters: 12 of the 30 selects in the tree
carry no class at all — `x-core::form-field`'s among them — and moving the whole
rule behind `[class]` would take the floor, the appearance reset and the chevron
itself away from every one of them. A select with no class also has no utility
to lose to.

## Two halos closer together than 44px take from each other

A halo extends a control's reach with an `::after` that no layout can see. Two
of them within 44px overlap, and the later one in the DOM paints on top — so the
overlap answers for the wrong control. On the chains index the settlement's
counterparty name and the transaction date sit 20px apart; giving both a halo
made a tap 16px left of the name's centre open the transaction.

The stylesheet already recorded half of this for `.side-item`: a flush-stacked
list has no slack, so real height is the only honest 44px there. The other half
is that the halo needs *pitch* as much as height. Chain legs got it as 16px
between rows, which takes a 28px row to a 44px pitch. Where the pitch cannot be
had, only one of the two controls can carry a halo, and it has to be the primary
one.

## A gradient is invisible to a contrast probe

Every contrast probe in this repo walks `getComputedStyle(...).backgroundColor`
up the ancestors to find what a colour sits on. A `linear-gradient` is a
background-**image**: `backgroundColor` reads `rgba(0,0,0,0)`, the walk sails
past the element, and the ratio comes back computed against whatever opaque
ancestor is behind it.

The sidebar avatar is a 26px circle with the account's initial in white on an
emerald-to-blue ramp. A 664-node sweep, two device rounds and an Android agent
all reported it clean. Sampled from the rendered pixels it ran **3.37:1 to
4.16:1** at 11px/600, against a 4.5 floor.

Pixel sampling has no such blind spot, and it is also the only method with no
colour model in the middle. Two hand-rolled probes in one session returned
confident nonsense — one painted its sample onto an opaque black undercoat, so
every transparent ancestor read back at alpha 1 and the backdrop walk called the
first parent black; the other divided by alpha a second time on values
`getImageData` had already returned un-premultiplied. Copy the validated probe
forward rather than writing a new one, and settle any disagreement against the
PNG.

## A pair of colours declared together is measurable without a browser

`background: var(--color-text)` is the inverted treatment and is correct — the
pinned-report chip on `/reports` and the counterparty support chips both draw
themselves that way. What shipped beside it in the Tax popover was
`color: #fff`, hardcoded, so the pair worked by day and vanished at night:
`--color-text` is `#0f172a` in light and `#f1f5f9` in dark, and white on
`#f1f5f9` is **1.10:1**. The product owner found the Save button on a phone.

Five sites carried the same defect and none could fail a test. The muted-colour
rule reads *class attributes*; these pairings are declared in a `style`
attribute or in a bespoke rule, where there is no class list to read. The
rendered-node sweeps could not see them either — a popover, a phone filter
sheet and a batch banner are all closed on the routes a probe walks.

The pairing needs no browser, because a background and a text colour **declared
in the same block** are known to apply together: an inline style wins the
cascade outright, and a rule that sets both sets both on whatever it matches.
`ColourPairs` reads every such block — inline `style` attributes, a style a
template holds in a PHP variable, and every rule in `app.css` — and measures
the pair in both themes. It found `.srch-sheet-apply`, `.srch-filter-badge` and
the batch-tag button, all near-white on `--color-blue`, all fine in light and
2.47:1 and 2.54:1 in dark.

**Resolve the value; never read the text of it.** `.srch-sheet-apply` wrote
`color: oklch(99% 0 0)`. A regex taking 99, 0 and 0 for channels calls that
pair 5.40:1 and passes it; converted through Oklab it is 2.47:1 and fails. The
verdict flips, not merely the number. `ThemeColour` does the conversion in PHP
— `oklch`, `oklab`, `hsl`, `color-mix`, `#rgba` and `var()` with its fallback
chain — which is what lets the guard run in the suite instead of behind a
headless browser. Gradient stops are resolved the same way, so the avatar ramp
the section above describes is measured against its worst stop rather than
skipped.

**Both themes, every time.** Light values come from the `@theme` block, dark
from `.dark`. All five defects passed in light. A guard that measured one theme
would have passed the bug it was written for.

Three readings the guard deliberately does not take, because each would be a
false positive rather than a finding:

- **A background with no colour beside it, or a colour with no background.**
  `.fee-bar-fill` is a progress bar, not a label — there is no text to measure.
- **A colour over `transparent` or `none`.** The real ground is somewhere up
  the DOM, and source alone cannot follow it there. 25 rules and 12 attributes
  are skipped and counted rather than guessed at.
- **A theme-scoped rule read against the other theme.** `.dark .chip` never
  renders against the light tokens, and a base rule read in dark has to be read
  through its `.dark` override first, or the guard reports a pairing the dark
  theme already replaced. Dropping this alone produced three false findings.

Four `style` attributes hand over a value the source cannot resolve at all —
`color: {{ $balanceColor }}`, and the chip whose entire style is a PHP
variable. Those are counted, never guessed. Where the value *is* a Blade
condition the branches are read one at a time and paired by position: a style
that picks its background with a condition picks its text colour with the same
condition, and crossing them would invent a pairing no render produces.

## The bars belong to the window, and the window listens to the OS

An edge-to-edge Android window paints the pixels behind the status and
navigation bars, but Android draws the clock, the signal glyphs and the nav
chevrons on top of them, choosing light or dark from the OS night-mode setting.
A reader whose app theme disagrees with their phone's therefore had white glyphs
on the app's own `#f8fafc`: **1.05:1**, an invisible clock on every screen.

`theme-color` does not reach this. It colours a *browser's* chrome, and a
WebView has none. The page has to tell the window, through the JS bridge the
activity already installs.

Three things that each looked like the whole fix and were not:

- **Applying the flags from the bridge is not enough.** `configureStatusBar()`
  sets the same two from `resources.configuration.uiMode`, at startup and again
  on every night-mode change. The bridge held until the reader touched their
  phone's theme, at which point the activity overwrote the page's answer. The
  reported value has to be *stored* and that function made to prefer it.
- **`classList.contains('dark')` is not the theme.** After a config change the
  root carried neither class and the page was dark from
  `@media (prefers-color-scheme: dark)` alone, so the class said light while the
  reader looked at a dark screen. Read the resolved background colour instead:
  it is the question the bars are asking and it cannot drift from the
  stylesheet.
- **localStorage does not survive.** Read over the DevTools protocol on a
  Galaxy S24 Ultra, the WebView came back from a night-mode change with
  `localStorage.length === 0`. Flux keeps its copy of the theme choice there,
  fell back to `system`, and repainted the page dark under a Theme toggle still
  reading Light — a separate, worse bug than the bars. The choice is now also
  published on the root by `x-core::head-assets`, where it lasts as long as the
  document, and re-asserted a frame after the media query fires.

Measured after all three, across a full flip cycle: status bar **8.37:1** with
the app on Light and the phone on dark, and the page still Light after the OS
went light and dark again. The navigation bar reaches **4.17:1** and stops
there — One UI draws its light-mode nav glyphs at `rgb(124,124,124)`, which
against pure white is 4.17, so no backdrop the app can paint lifts it further.

Two lessons that outlive this fix. Attach the DevTools protocol
(`adb forward tcp:9223 localabstract:webview_devtools_remote_<pid>`) rather than
inferring DOM state from screenshots — one `Runtime.evaluate` replaced four
rebuild-and-look cycles. And **pull the `-wal` with the database**: the theme
read back as `system` from the `.sqlite` alone and as `light` with its WAL, and
the first reading sent the diagnosis down a blind alley.

## An enum-typed Livewire property hydrated before its lock

`#[Locked]` is enforced by an `update` hook that runs inside
`HandleComponents::updateProperty()`. `updateProperties()` calls
`HandleSynths::hydrateForUpdate()` first, and for an enum-typed property that
means `EnumSynth` reaches `$class::from($value)` before the lock is ever
consulted. A crafted `updates{claimedTier:"-1"}` therefore answered `ValueError:
"-1" is not a valid backing value for enum CommandTier` — a 500 — on a property
carrying the attribute that exists to refuse exactly that write. An array in the
same slot answered `TypeError` from the same line. Locking more properties does
not help; the fatal happens upstream of the lock.

Nothing caught it because the lock genuinely works for every other type. A
locked string, int or array rejects a hostile update and the property is never
touched, so a suite that proves "locked properties cannot be written" is green
while the enum ones are the only reachable 500s on the page.

`Modules\Core\Internal\Http\Livewire\SafeEnumSynth` is registered through
`Livewire::propertySynthesizer()` under the framework synth's own key, so it
replaces it for every component and for any enum property added later. It
resolves a case by comparing the wire value against `cases()` as strings, which
never throws whatever arrives. A value naming no case is dropped in favour of
what the component already holds, so a tampered update is a no-op and the lock
below it still answers [its own refusal](#a-refused-write-answering-as-a-server-fault).
The one deliberate asymmetry is the empty value: on
a nullable property it still clears, because that is how a cleared `<select>`
nulls an enum; on a property that cannot hold null it does not, because the
framework's response to an impossible assignment is `unset()`, and an
uninitialised typed property is fatal to the next method that reads it rather
than to the request that caused it.

## A refused write answering as a server fault

Two `/livewire/update` payloads were correctly **refused** and answered 500:
`updates{userId:999}` against a `#[Locked]` property, and an `updates` key
naming no property on the component at all. The guard worked both times; the
status did not, and a 500 is the one answer that says the server was at fault
for what the client sent.

Nothing caught it because a refusal is what the tests assert. `Livewire::test()`
runs with exception handling off except for `HttpException`, so a test that
proves the write is rejected sees the exception itself and never the status a
browser would get. Only a real POST to the update endpoint shows it — and that
endpoint carries a per-install prefix derived from `APP_KEY`
(`EndpointResolver::prefix()`, so `route('default-livewire.update')`, never a
literal `/livewire/update`) and 404s without an `X-Livewire` header and a JSON
content type.

`CannotUpdateLockedPropertyException` now maps to 403 and
`PublicPropertyNotFoundException` to 400, through
`Modules\Core\Public\Support\LivewireClientRefusal`, which **both**
`bootstrap/app.php` files read. The mapping was written on the desktop root
alone, and nothing keeps the two roots' handlers in step: the phone's handler
had an empty exception map, so both payloads reached the renderer unmapped —
the state this whole section exists to end — while the desktop answered 403 and
400. Three details decide the shape:

- **`map()`, not `render()`.** The locked-property exception renders *itself* —
  419 with `app.debug` off, a full error page with it on — and `Handler::render()`
  consults that before any registered render callback. Mapping runs first, so
  the answer is one answer however the bundle was built.
- **`PublicPropertyNotFoundException`, not `PropertyNotFoundException`.** They
  read alike and only the first is reachable from a payload: it is thrown solely
  by `updateProperty()`. The second comes from `Component::__get`, which is a
  Blade template naming a property the component does not have — a server-side
  defect, and mapping it to a 4xx would hide it.
- **One mapper, not one per exception.** The third member below is thrown as a
  bare `\Exception`, so the mapper that catches it has to be keyed on that type
  — and `Handler::mapException()` returns on the first `is_a` hit, which makes
  any second `map()` call on either root dead code. The whole family answers
  from one seam for that reason, and
  `tests/Contracts/RefusedLivewireWritesMapThroughOneSeamArchTest.php` fails a
  second one rather than letting it sit there doing nothing.

### An update path that descends into a scalar

The third member is not an exception Livewire wrote for the purpose. A payload
whose path descends INTO a leaf — `updates{"filterAccounts.0.0": true}` against
`public array $filterAccounts = []` holding account ids — makes
`HandleComponents::recursivelySetValue()` resolve a synthesizer for the
*container* at every segment, and at `0` the container is the int `5`.
`IntSynth::match()` and `FloatSynth::match()` both return `false` by design and
every other synthesizer is an `instanceof` test, so nothing matches a scalar and
`HandleSynths::findByTarget()` throws a bare `\Exception` reading `Property type
not supported in Livewire for property: [5]`. Measured on `/transactions`: 500
with `app.debug` on and 500 with it off.

`#[Locked]` cannot reach this one. It is generic to every array property whose
elements are scalars, including the ones the browser is *supposed* to write —
`filterAccounts` is `#[Url]` and `wire:model.live`-bound, and locking it would
break the filter it exists for.

Two ways of fixing it upstream of the handler were measured and rejected:

- **A catch-all property synthesizer.** `LivewireManager::propertySynthesizer()`
  is `array_unshift`, so anything registered is *prepended* and matches before
  every real synth. `SafeEnumSynth` is registered the same way and is fine only
  because it is narrow.
- **Swapping the `HandleSynths` mechanism.** `Mechanism::register()` binds the
  instance under its own class name, so a subclass looks bindable — but
  `HandleComponents` takes `HandleSynths` by constructor injection when Livewire
  registers it, and holds that object. Binding a subclass under the parent name
  afterwards leaves `recursivelySetValue()` calling the original, which is the
  only path that matters. The synthesizer list does not survive either: the live
  list is 15 entries and a subclass starts from the class default of 8, because
  `Flux\DateRangeSynth`, `SafeEnumSynth`, the model, form-object, file-upload
  and Wireable synths are all registered during `boot()`. Making the swap work
  needs two reflective writes into Livewire internals.

So it is recognised at the boundary instead, on three things together: the
exception is exactly `\Exception` and not a subclass, its message opens with
`LivewireClientRefusal::UNSUPPORTED_TYPE_MESSAGE`, and its trace carries a
`HandleComponents::recursivelySetValue` frame. The frame is the load-bearing
one. The same throw also answers a component genuinely holding a property type
Livewire cannot dehydrate — that is the server at fault, it happens at render
rather than on an update, and it has to stay a 500.

This one refusal does not carry Livewire's message forward, unlike the two
above it. `Handler::convertExceptionToArray()` returns an `HttpException`'s
message verbatim in production, and Livewire's `json_encode`s the value it
stopped on — for a deep path, whatever the property holds. The other two name a
property and a component; this one would name a search term or a decrypted
column, so it says what was refused instead.

Message matching is brittle across upgrades, so the guard is written to fail
loudly rather than quietly revert:
`Modules/Ledger/tests/Feature/AnUpdatePathThatDescendsIntoAScalarIsRefusedNotA500Test.php`
lifts the page's own `wire:snapshot`, POSTs it back, and asserts 400 with
`app.debug` both on and off — then asserts the three keys **separately**, each
with a failure message naming what to change. A further case drives a real
dehydration failure and requires the seam to decline it.

### A type-mismatched update, deliberately not mapped

The fourth payload in the same family is `updates{"searchQuery": ["zzz"]}` —
right property, wrong type. It reaches
`HandleComponents::setComponentPropertyAwareOfTypes()`, which catches the
`TypeError`, unsets the property when the value is `''` or `null`, and otherwise
rethrows when `app.debug` is on and calls `abort(419)` when it is off. Measured
on `/transactions`: 500 with debug on, 419 with debug off.

Production therefore already answers a refusal rather than a server fault, which
is the half that ships, and the debug-build rethrow is upstream Livewire's
deliberate choice — its own comment says a payload of this shape is almost
certainly a scanner, and swallowing it in development would hide the genuine
type bugs that raise the same error. That is why this one is left alone rather
than joining the three above.

Blanket-mapping `TypeError` is what the asymmetry buys off. The three mapped
members are all raised *only* from the update path, so mapping them cannot
silence anything else. A `TypeError` is the ordinary shape of a real server
fault — a method handed the wrong type, anywhere in the app — and mapping the
type would answer 4xx for every one of them.

### The `calls` half, which had no mapping at all

Everything above is the `updates` half of a payload. The `calls` half was
unmapped, and two of its refusals are neither a `TypeError` nor thrown from the
update path, so Livewire's own catch never sees them and both answered **500 in
production as well as in debug**:

- **A method the component does not have.** `calls[{method:"acknowledgeEverything"}]`
  raises `Livewire\Exceptions\MethodNotFoundException`, which is the exact twin
  of the already-mapped `PublicPropertyNotFoundException` — a name in the payload
  that the component cannot answer to — and now maps to 400 beside it.
- **A required argument the call left out.** `calls[{method:"acknowledge",
  params:[]}]` never reaches the method: the container throws
  `BindingResolutionException` from `addDependencyForCallParameter()`, a site
  reachable only for a parameter with no class and no default. The container
  raises that class for every unresolvable binding in the app, so the mapping is
  keyed on two trace frames — `HandleComponents::callMethods` and
  `Livewire\ImplicitlyBoundMethod::resolveMethodDependencies` — which together
  say Livewire was assembling a call an update payload named. Without them the
  seam would answer 4xx for the app's own missing bindings.

The message is the container's own for the first and a fixed string for the
second, because `BindingResolutionException` spells out the whole reflected
parameter signature and an `HttpException` message is the entire body a
production build returns.

### The argument types the `calls` half splats, and the reader it assumed

`HandleComponents::callMethods()` reaches the method as
`wrap($root)->{$method}(...$params)` — the payload's own `params`, splatted with
no coercion of any kind. Livewire's `TypeError` catch sits on
`setComponentPropertyAwareOfTypes()`, which is the *property assignment* path,
so nothing on the call path narrowed anything. `calls[{method:"search",
params:[["zzz"]]}]` against `PaletteSearchEndpoint::search(string $q, …)`, which
the shared layout mounts on every authenticated page, therefore raised a raw
`TypeError`. `NotificationsPage::markRead`, `SyncStatusSection::dismissPeer`,
`DevicesAndSyncSettingsSection::startRemove`, `MobileLockScreen::submit` and
`RulesPage::deleteRule` are the same one call away.

Production was already refusing it and the debug build was not, which is the
asymmetry rather than the defect. `HandleRequests::handleUpdate()` catches every
`\TypeError` out of `Livewire::update()`, `report()`s it, and then rethrows with
`app.debug` on and `abort(419)`s with it off. Measured on `/transactions`: 500
with debug on, 419 with it off. The 500 is the half this section exists to end,
and `report()` runs first either way, so the trace a real type bug needs is in
the log before anything decides a status.

The mapping is keyed on three things together and never on the class. The
FIRST frame of the trace is a `Livewire\Component` subclass method; the frame
that called it is `Illuminate\Container\BoundMethod`, which is what
`ImplicitlyBoundMethod::call()` reaches a component method through; and
`HandleComponents::callMethods` is somewhere below. Together they say the throw
landed ON the boundary the payload chose the arguments for. A `TypeError` from
inside the method body has app code in that second frame instead and stays a
500 — `TriageInbox::save()` handing a wire-supplied string to
`AssignsCategory::__invoke(int, ?int, User)` is a component that never narrowed
its own state, and answering 400 for it would have hidden the missing
`#[Locked]` rather than adding it.

`NotAuthenticatedException` joins it on the same frame. `/mobile/import` is
deliberately outside the auth group, `MobileImportBootstrap::mount()` opens with
`if (! $currentUser->isAuthenticated()) return;` for that reason, and
`retryProvisioning()` — reachable by a `calls` entry without going through
`mount()` at all — did not repeat it. The accessor throws a bare
`RuntimeException` no handler named, so an unauthenticated call answered 500 on
both bundles. The guard is back on the action, and the seam answers 401 for the
next method that forgets it. The `callMethods` frame is what keeps a scheduled
task or a console command resolving the same accessor a server fault: only a
payload can name a method there.

`Brick\Money\Exception\UnknownCurrencyException` is the one deliberately left
out. Five components handed a wire-supplied currency code or minor-unit map to
`Money::ofMinor()` from the *render* path — `AccountCurrencyEditor`'s relabel
banner, `TransactionsList`'s accumulated rows, `StartingBalanceCard`,
`FileStagingPage`'s pending intent and `DevicesAndSyncSettingsSection`'s device
list. Each answered 500 with `app.debug` on and with it off, because a
render-path throw is wrapped in `Illuminate\View\ViewException` long before the
`TypeError` catch in `handleUpdate()` could see it — and every one of them is a
property [`#[Locked]` reaches](#a-denomination-the-browser-could-choose). Two
things argue against a sixth arm. Unwrapping that `ViewException` at the
boundary means unwrapping every render fault there; and an unknown currency code
reaching a formatter is just as likely to be a bad row as a bad payload, which
is the server at fault and has to stay a 500. Refusing the write is what stops it, and there is nothing
left for a mapper to answer.

Two of those five could not be fixed by locking the property the code first
looks at. `TransactionsList::$accumulatedRows` is only reachable while
`$appendedCursorIds` already holds the current `cursorId` — `accumulate()`
replaces the rows when the cursor is 0 and appends when the cursor is new, so a
payload that forges all three is the only shape where a row survives to the
view. Both are locked, not just the row list. And `FileStagingPage::$pending` is
typed `?array`, which accepts `[]`: the type held and `basename($this->pending['path'])`
still reached a key that was not there.

## A destructive write reached by a GET, held off by a cookie attribute

`SetupWizard::mount()` reset every `wizard_progress` row for the current user to
`pending` when the URL carried `?force=1` — inside `mount()`, on a GET, with no
token and no confirmation. Nine done steps, from a bookmarkable URL.

It was not cross-site exploitable, and that is the finding rather than the
defence: the only thing standing in the way was `same_site => 'strict'` in
`config/session.php`, which reads from `SESSION_SAME_SITE`. The handler had no
guard of its own, so one env var was the whole boundary — and re-opened
onboarding lets `FirstImportStep::commitEverything` overwrite
`accounts.starting_balance_minor`.

The Settings "re-run the setup tour" link is now `URL::signedRoute('setup',
['force' => 1], absolute: false)`, and `mount()` honours `force` only for a
request whose relative signature validates; anything else is logged and ignored.
Relative and not absolute on purpose: an absolute signature covers the host, and
the desktop and mobile bundles serve on a loopback port that is not fixed
between runs, so a link generated on one port would stop working on the next.

## A denomination the browser could choose

`#[Locked]` is the only thing separating a public property the server writes
from one the client writes, and four money editors carried the account's or the
series' own currency code without it. `ScenarioEditorSidebar::$availableSeries`,
`ModelWhatIfDropdown::$currency`, `AccountBufferEditor::$currency` and
`OpeningBalanceEditor::$currency` were each assigned once — in `mount()` or
`render()` — and then read back as the scale a typed figure is parsed at.

Replaying a page's own `wire:snapshot` with `updates{currency:"JPY"}` and a
typed `150` therefore stored **150 minor** against a EUR account: a hundredth of
what the screen said, answered 200, with no error anywhere. A yen has no minor
unit, so the parser was doing exactly what it was told.

Nothing caught it because the PHPDoc read as a guarantee. `@var list<array{id:
int, name: string, currency: string}>` is a claim about what the server puts
there, and every test that set the property up did so through `mount()`. The
value only differs on a request no test was making.

Two things decide whether a property is the server's:

- **`render()` is not late.** An action method runs *before* `render()`, so a
  property assigned only there is still whatever the payload said for the whole
  of the action that reads it.
- **The Blade is the evidence.** A property no `wire:model`, `$wire.set` or
  `entangle` names is server-owned and belongs under `#[Locked]`. The reverse is
  just as load-bearing: `AppLockSettingsSection::$biometricCapable` is written by
  `x-init="…$wire.set('biometricCapable', true)"` and locking it would blind the
  screen to a browser that has WebAuthn.

The same read applies past money. `AppLockSettingsSection::$lockEnabled` gated
`setPin()` — which re-provisions where `changePin()` re-wraps — so a payload
naming it `false` rotated the KDF salt, replaced the PIN hash and dropped every
biometric enrolment on a lock that was already on.

And a shape a Blade dereferences is a third case: `HandlesTaxTagging::
$pickerCategories` is `list<stdClass>` and the popover reads `$cat->id`, while
`RuleFormModal::$conditionErrors` is `array<int, string>` echoed straight out.
A string in the first and an array in the second each turned the *render* into a
500 — the rule form modal from every authenticated page, since the shared layout
mounts it.

Where a lock is not available the shape has to be narrowed instead.
`WithPagination::$paginators` is public and untyped, and the browser's Back
button legitimately writes `paginators.page`, so `CashBookPage` narrows the map
on every write rather than refusing it.

Two further things came out of the round that generalised this into
`tests/Contracts/AServerOwnedLivewirePropertyCarriesTheLockArchTest.php`.

**`updates` land before `calls`.** One payload carries both, and Livewire
applies every update before it invokes a single call. So a comparison against a
property the same payload just wrote is not a gate — it is the payload agreeing
with itself. `CashBookPage::delete()` refused any id that was not the one
`confirmDelete()` had been asked about, and said so in a comment: *"A delete
arriving for anything else is a client that skipped the question."* Naming id 42
in `updates` and calling `delete(42)` satisfied it every time, and the entry —
whose only other control is an amount, with no undo behind it — went with the
confirm strip never rendered. The false comment was worth as much as the code:
it told every later reader that the question had been asked.

That is what separates the shape from the `$confirming…` flags on `RulesPage`,
`ReportsIndex` and `CategorizationProvenancePanel`, which are correct as they
stand. Those mutations take the row id as a **call parameter** and never consult
the flag, so the flag decides what is drawn and nothing else.

**A route segment is outside the URL contract's reach.**
`TamperedUrlParameterContractTest` drives `#[Url]` properties, because those are
the ones the address bar writes. A property mounted from a route *segment* —
`/recurring/series/{seriesId}`, `/transactions/{transactionId}` — is reader-supplied
in exactly the same way and is in neither test's scope: `mount()` proves the id
once, and every later request carries whatever the snapshot says. Forged, the
detail page wrote series 9 while the address bar still read
`/recurring/series/5`.

The guard is the census, not the nine fixes. It walks every component under
`Modules/`, and reports a public property that carries neither `#[Locked]` nor
`#[Url]`, is named by no `wire:model`, `$wire.set`, `$set`, `entangle`,
`$toggle` or `x-model` anywhere in `Modules/` or `resources/`, and is **read**
inside a method a payload can call. Reading is the half that keeps it precise: a
property an action only assigns — every error line, every screen flag — is an
output and never appears. `serverOwnedPropertyExemptions()` is the only way out
and each entry states why the client's value is harmless, in the shape
`userIdExemptTables()` uses. A second arm fails an entry naming a property that
no longer exists, because a reasoned list that outlives its properties quietly
stops being one.

## A query parameter a reader can retype

`#[Url]` binds a property to the address bar, which makes it **reader-supplied**:
whatever arrives there is what the component mounts with. A bookmark, a shared
link, a typo and a hand-edited URL all reach the same property, and none of them
are obliged to carry a value from the vocabulary the page expects.

Three separate things follow, and the first does not imply the others.

**An unknown value is a bad link, not a bad request.** A page reached from a
stale bookmark should render its default, not a stack trace and not a 4xx. The
coercion belongs at the boundary — `tryFrom() ?? default()`, a numeric filter
over an id list, a shape check before a date parse. Rejecting a bad *stored*
value is a different decision and stays where it is.

**Refusing is allowed, but only on purpose.** A page that starts refusing a value
it used to render is a dead end reached from a bookmark; one that stops refusing
has given up a check somebody wrote. Both directions are regressions, so the set
of parameters answered with a 4xx is pinned rather than left to drift.

**Coercion does not cover ownership.** Where the value is a row id, coercing it
into the right *shape* still leaves it naming a row. The query behind it has to
re-check who owns that row and answer with nothing rather than with a
neighbouring reader's — a validated id is not an authorised one.

`tests/Contracts/TamperedUrlParameterContractTest.php` finds the components by
walking the tree rather than from a list, drives every one of them with values
from a neighbouring vocabulary, a list where a scalar goes, and bytes no
keyboard sends, and asserts a name belonging to another reader never reaches the
screen. `.docs/features/forecasting/url-parameters.md` holds the per-parameter
rules.

## A gate registered against a route, asked on a Livewire update

Livewire's update endpoint runs outside the route middleware stack. Two gates in
this repository answer for that, and both got it wrong in a different direction.

`ForcePasswordChangeMiddleware` *is* registered as persistent middleware — the
provider says so, and says why: a flagged account must not keep driving every
component whose snapshot it already holds. It then read its exemption off the
**route name** the snapshot carries. Its one exempt page, `/change-password`,
renders inside `layouts.app`, and that shell mounts nine further components. So
the one screen a flagged account is allowed on handed it the ledger search
endpoint, the categorisation rule form, the community mapping publisher and the
OAuth client wizard, all over the wire, all answering 200. Measured by lifting
`search.palette-search-endpoint`'s own `wire:snapshot` off `/change-password`
and POSTing it back: `entityHits` came back populated.

The app lock is the same middleware shape and does not have the defect, which is
what makes the cause legible: every route in `AppLockMiddleware`'s exempt list —
`auth.lock`, `mobile.lock`, `mobile.pair`, `mobile.setup` — renders under
`layouts.lock`, which mounts nothing at all. The exemption was safe by
construction rather than by decision. So the fix is not to widen or narrow the
route list but to ask the question of the **component**: on a Livewire update
the exemption is taken from the names in the payload, and a batch is exempt only
if every component in it is. `core.app-sidebar` is on that list beside the
password form because it owns no action and carries a developer poll that would
otherwise reload the screen every five seconds.

`EnsureDeveloperMode` had the other half of the problem: it was never registered
as persistent middleware at all. Once the developer flag came off, `GET /dev/sql`
answered 404 while the same page's SQL panel, driven from a snapshot the browser
was already holding, answered 200 — re-rendering the schema sidebar and running
the statement box. One `addPersistentMiddleware()` call covers every `/dev` page
at once, because the machinery filters by the middleware the *original* route
gathered and no other route carries it.

The general read: a middleware registered as persistent is asked about a
synthesised request wearing the original page's route, so a route-name exemption
there is really an exemption for everything that page mounts. Either the exempt
page carries nothing else, or the exemption has to name components.

## A stack trace that carries the data it failed on

`tests/Contracts/ATraceThatReachesALogCarriesNoFrameArgumentsArchTest.php`

`Throwable::getTraceAsString()` renders the first fifteen characters of every
string argument, and `zend.exception_ignore_args` is Off by default in every
runtime this app ships in. So a frame like

```text
#0 Command line code(4): parseLine('2026-08-01,-42....')
```

is not a diagnostic with a bit of context attached — it is a row of the reader's
bank statement, written into the 0644 daily log, on the same line as the
`SafeExceptionContext` that exists to keep the statement and its bindings out.
Three catch blocks logged one directly (`ImportPipeline`'s parse failure,
`UploadWizard`, `NewMigration`), and `SafeTrace::cap()` — the class written for
exactly this log field — was rendering the same string underneath.

Nothing catches it because the trace is *correct*. It names the right frames in
the right order, it is what a developer expects to read, and the argument
fragment looks like helpful context until you notice whose data it is. The
suite is green, the log is well-formed, and the leak is legible only to somebody
reading a real log line from a real import.

`SafeTrace::cap()` now assembles the frames from `getTrace()` — file, line,
class, function — and never touches the renderer, so there is no argument to
escape or truncate. The directive is set in the bundled inis too, but the ini is
the second lock: desktop, mobile and CI do not share one `php.ini`, and a
defence that depends on one being applied everywhere is not a defence.

## A figure shown to the reader in the unit it is stored in

`tests/Contracts/AFieldStoringMinorUnitsNamesNoHundredthArchTest.php`

Money is held as an integer count of minor units and shown through
`Money::ofMinor(…)->format()`. A line that reaches the reader with the stored
integer still in it is not a formatting slip — it is a different number, and it
is a plausible one, so there is nothing on screen to read as wrong.

`PromoteStagingToDomain` built its split-mismatch reason with `%d` over
`settled_amount_minor` and wrote it to
`migration_staging_unmapped_items.reason`. Both `preview-migration` and
`migration-results` render that column verbatim, so a €123.45 transaction whose
legs came to €120.00 and €4.00 told the reader "the split legs add up to 12400
but the transaction is 12345" — on the same row as a label the same class had
already formatted as `Transaction: … · -€123.45`. One list, contradicting
itself, in two adjacent columns.

The same claim also reaches the reader as a *unit* rather than as a number.
`users.anomaly_min_amount_minor` and `users.recurring_income_min_amount_minor`
each hold minor units of the reader's base currency, and each field said
"Stored in cents" beside a worked example formatted through `Money`. Since
`base_currency` is validated `exists:currencies,code` and JPY is seeded into
that table, the field and its own example disagreed on screen: "Stored in cents
(¥) — 1000 means ¥1,000", under a label reading "Income minimum (cents)". A
yen has no cent, so the scale the copy promised was one the column never had.

Neither survived because of a subtle mistake; both survived because the euro
hides them. At two decimals the raw integer and the formatted amount differ
only in punctuation, so `12345` reads as the amount to anybody skimming, and
"cents" is true. Only a zero-decimal currency separates the two readings, and
neither surface had one to read: every migration fixture stages EUR, and the
settings copy was written against the base currency its author banks in.

The worked example is the other half. Every one of the twenty-six locales wrote
`200000` into the recurring-income line as a literal while the Blade was
interpolating `:minor` beside it from
`User::DEFAULT_RECURRING_INCOME_MIN_AMOUNT_MINOR`. The parameter was dead in all
of them: the copy could not follow the constant, and changing the default would
have left every language quoting the old one with nothing to say so.

So a figure a reader sees goes through `Money` with the currency the row itself
carries, and a field storing minor units names them as minor units — the wording
`onboarding::starting_balance.minor_units` already uses in each language —
rather than as some currency's hundredth.
[Minor units and zero-decimal currencies](../features/ledger/minor-units-and-zero-decimal-currencies.md)
holds the rest of the rule, including which currency each amount field is
denominated in.

## A month step off a day the next month does not have

Carbon's `addMonth()`, `subMonth()`, `addMonths()`, `subMonths()`, `addYears()`
and `subYear()` overflow. Stepping a whole month off 31 January does not land on
28 February — it lands on **3 March**, because 31 February is normalised
forward. Nothing throws, nothing warns, and the result is a real date in the
wrong month. Carbon ships `addMonthNoOverflow()` and its family for the other
answer, and which one a call site wanted is invisible from the call site.

The second half is what makes it survive review: a `startOfMonth()` written
after the step does not undo it. `now()->subMonth()->startOfMonth()` on 31 March
is `2026-03-01`, not `2026-02-01` — the overflow has already happened and
`startOfMonth()` is now flattening the wrong month. The line reads like it
normalises, and on twenty-four days out of thirty-one it does.

Eight sites shipped with it, and each failed in a way that looks like success:

- The "Last month" preset on `/transactions` selected **this** month on the
  29th, 30th and 31st. The button highlighted, the two date inputs filled, the
  list re-queried, and the range was the one already on screen. Both surfaces
  that draw the presets — the desktop popover and the phone bottom sheet —
  spelled the four ranges out for themselves, so it was one defect in two
  places; `DateRangePreset::rangesFrom()` now owns all four.
- `Counterparties\Internal\Support\RollingTwelveMonths::months()` — then a
  private `sparklineMonths()` on `CounterpartyIndexQuery` — ran a month late on
  a month-end day. On 31 January the twelve buckets were March 2025 through
  February 2026: the last bucket a month that had not happened, the oldest real
  month dropped off the front, and a docblock underneath promising "the last is
  the current month".
- `SnoozeWindow::targetFrom()` returned 3 March for a "one month" snooze taken
  on 31 January, and 1 May for "three months". Its neighbours — `PeriodQuery`,
  `SeriesCadence`, `PeriodComparison` — were already on the NoOverflow variants.
- `SnoozeUntil::from()`, the accept side of that same pair, drew its ceiling the
  same way. Asked on 31 August it accepted targets out to 3 March — three days
  past the six months the refusal underneath it names as the bound.
- The recurring detectors opened their detection window three days late on
  31 August, so the first days of the boundary month never reached the
  clustering. A dropped occurrence changes the inferred cadence, which changes
  every `next_expected_at` the series projects. Their anomaly siblings,
  `FirstTimeMerchantDetector` and `LargeVsTypicalDetector`, take the same kind
  of window and already clamped.
- `CalendarQuery::hasProjectableEntries()` stepped its horizon off *today* while
  `CalendarMonthWindow::ceilingMonth()` steps the month-nav ceiling off the **first** of
  the month. On 29 February those two disagree, so the empty state answered for
  a month the grid refuses to open — a reader with nothing on screen, nothing
  they could page to, and no empty state.
- The twelve-month roll-up on the counterparty index and profile used
  `subYear()`, which on 29 February starts on 1 March and drops the last day of
  the reader's own year out of every figure on both screens at once.
- `TimeBucketGenerator` clamps its monthly and quarterly steps and did not clamp
  the yearly one it widens to, so a long range opening on 29 February walked
  every later bucket edge into March.

A bare-month bound typed into search is the same family through a different
door. The `before:` bound was built with `createFromFormat('Y-m', …)`, and a
format string that names no day leaves the day **unset**, which PHP then fills
from today. Typed on 29 August, `before:2026-02` became 31 March:
`createFromFormat` had already rolled into the month after, and `endOfMonth()`
faithfully answered for it. The `after:` branch beside it pinned the day to the
1st and was correct. Both bounds now go through `SearchQuery::boundDay()`, which
anchors a month on its 1st before reading either end of it. So the rule is not
only about arithmetic — any month-granular value has to say which day it means
before anything else reads it.

The shape to look for is a whole-month or whole-year step whose result is then
normalised, and the reading to take from all nine is that the normalisation is
not the guard it looks like.

Three more arrived after those were written down, which is what turned this from
a rule into a test. `DemoGoalsSeeder` dated the Ryokan goal six months out from
a 29 August seed run and got **1 March** instead of 28 February — a demo install
shipping a deadline in the wrong month. `GoalFactory::definition()` stepped its
default `target_date` with `addYear()`, so on 29 February every `Goal::factory()`
row in the tree would date to 1 March. `DemoEmailScanSeeder` drew a demo token's
`expires_at` the same way. A written-down invariant with eight instances under it
did not stop the ninth, tenth or eleventh.

`tests/Contracts/AWholeMonthStepNeverLandsInTheNextMonthArchTest.php` is what now
holds it, and the rule it enforces is absolute: no bare `addMonth`, `addMonths`,
`subMonth`, `subMonths`, `addYear`, `addYears`, `subYear`, `subYears`, or their
quarter, decade and century siblings, anywhere in `Modules/`, `app/` **or the
test tree**. The generic steppers — `add`, `sub`, `addUnit`, `subUnit` — are
refused too when their argument names one of those units, because otherwise the
same arithmetic just moves through a different door.

Fixtures are in scope on purpose, and they were the larger half: ninety-six of
the hundred and thirty-six whole-month steps this tree takes are in tests, and
eighty-one of them were bare across thirty-nine files. A fixture dated
`now()->subMonths(3)->startOfMonth()` — the line eleven Budgets feature tests
each spelled for themselves — seeds `envelope_activated_at` at 1 February when
the suite runs on 15 May and at
**1 March** when it runs on the 30th or 31st, because the step overflows before
`startOfMonth()` ever sees it. The same line moves from September to October on
31 December. Nothing was red, because CI does not usually run on the 31st. A test
that passes twenty-eight days a month is not a test that passes, and a
date-dependent fixture is the kind of flake that gets re-run rather than read.

The second half of the old advice, "or step off a day every target month has", is
deliberately no longer an option. On a first-of-month anchor `addMonthNoOverflow()`
and `addMonth()` return the same date, so clamping costs a day-1 call site nothing
— and letting it be a choice means the guard has to prove each anchor is day-1,
which needs dataflow through loop variables and helper methods. A guard that
mis-reads an anchor reports a clean tree, which is the one failure mode worse than
no guard. Nine of the eleven sites were already day-1 anchored and are clamped now
regardless. A helper wrapping "advance a schedule by N months" was considered and
rejected: it would be a rename of `addMonthsNoOverflow`, and the sites are three
different shapes — a half-open period edge, a bounded walk, and a schedule step —
that one signature would not fit.

The guard carries **no exemptions**. `CalendarMonthWindow` was pinned for a day
while its five first-of-month steps were converted, and the pin was deleted with
them; the empty pin table is still asserted, so nought re-proved of nought is the
rule holding absolutely rather than a test quietly doing nothing.

What it does carry is a handover table, which is a different claim. Three steps in
two Calendar test files were held by work in flight when the rule was written, and
they are recorded there with an owner and an exact count rather than a
justification — the entry leaves by being converted, and when that happens the
count stops matching and the guard goes red until the entry is deleted. A pin says
"this is correct". A handover says "this is wrong and is queued". They must not be
spelled the same way, or a debt with a deadline decays into an exemption without
one.

## A retention cutoff read off a different clock than the column

`PruneNotificationsJob::handle()` asked SQLite for its own cutoff —
`datetime('now', '-365 days')` — and compared it against `notifications.created_at`.
`datetime('now')` is UTC. `NotificationWriter` stamps that column through the
app clock, in the app's configured timezone, which is `Europe/Amsterdam`. So the
two sides of the comparison were in different frames and the retention edge sat
one or two hours off, in whichever direction the offset pointed. Nothing is
visibly wrong: rows still expire, roughly a year later, and the drift is smaller
than the interval the sweep runs on.

The general rule is the one it implies: whoever writes the column decides the
frame, and the cutoff has to be built in that frame, not in whichever one was
nearest to hand.

`tests/Contracts/ACutoffIsBuiltOnTheClockThatWroteTheColumnArchTest.php` is what
now holds it. The fix reached `PruneNotificationsJob` and stopped there, even
though that job's own comment named a second daily sweep as sharing its
retention number — the two spelled the same 365-day rule separately, and one
of the two spellings asked the wrong clock. What is left of the pair reads it
from `Modules\Core\Public\Support\RetentionWindow`, which is one expression of
the number *and* one expression of the frame. The guard walks the backend plus
the migrations, and refuses any `datetime('now')`, `date('now')` or
`CURRENT_TIMESTAMP` outside two pinned schema defaults that nothing orders or
ranges.

`HealthCheckListener` and `BackupFreshnessProbe` were the mirror of it, and by
the time this was written they were the same defect. Both converted their
one-hour dedup cutoff to UTC, each under a comment explaining that SQLite's
`CURRENT_TIMESTAMP` default writes `system_alerts.created_at` in UTC. That had
stopped being true: every writer of the column goes through the `SystemAlert`
model, whose `$timestamps` stamp it off the app clock precisely so an alert
raised at 01:38 CEST stops being shown as 23:38 the day before. The writer was
fixed and the two readers were not, so the dedup window silently ran at three
hours instead of one — the third site of a fix that had reached one.

## An instant rendered in a frame it was not produced in

`tests/Contracts/AZuluSuffixIsOnlyEverWrittenInUtcArchTest.php`,
`tests/Contracts/TextTimestampsAreZuluArchTest.php`

A `Z` suffix is not decoration. It tells whoever reads the string that the digits
in front of it are UTC, and nothing in PHP checks that the instant handed to
`->format('Y-m-d\TH:i:s\Z')` ever was. `Clock::now()` runs at `APP_TIMEZONE`,
which ships as `Europe/Amsterdam`, so the app's own instants are exactly the ones
that come out wrong.

`GraphApiClient` built both of its `receivedDateTime` window filters that way. A
real instant of `2026-05-30T16:15:22+02:00` went to Microsoft Graph as
`2026-05-30T16:15:22Z` — a window opening **7200 seconds late**. The sharpest
consequence was not the backfill window but the delta baseline beside it:
`BackfillInboxJob` anchors `$walkStartedAt` *before* the walk precisely so the
post-walk delta cannot skip messages that arrive mid-walk, and the skew pushed
that anchor two hours into the future. The delta then excluded exactly the
messages the anchor exists to catch, and no later delta saw them either, because
they had not changed since.

The mirror of it is an instant that arrives in somebody else's frame and is
stored in ours without moving. `MimeHeaderParser` reads the RFC 822 `Date:`
header at the **sender's** offset, and the writers rendered it with a bare
`->format('Y-m-d H:i:s')` into a DATETIME column that is read back at the app's
offset. `Date: Thu, 14 May 2026 23:40:00 -0700` is `2026-05-15` here; it stored
`2026-05-14`. That is a receipt filed under the wrong day, an `.eml` blob in the
wrong `Y/m` folder when the month turns, and a notification keying its
`occurrence` on a day the message did not arrive on.

Both halves are one seam now. `Modules\Core\Public\Support\Instant` converts
first and asserts after — `zulu()` for anything wearing a UTC label, `appLocal()`
for anything a DATETIME column will read back, `inAppZone()` where a foreign
instant enters and everything downstream of it needs the app's frame. It lives in
`Public` rather than a module's `Internal` because the writers are spread across
EmailScan, Receipts, Counterparties, Ingestion, Mobile and Sync, and a seam only
one module can reach is how the previous version of this rule ended up with
pinned exemptions instead of conversions.

## A DATE column carrying a time

`tests/Contracts/ADateColumnNeverStoresATimeArchTest.php`

SQLite has no date type. A `date` column is TEXT, and TEXT compares as a string,
so `'2026-09-16 00:00:00' <= '2026-09-16'` is **false** — the shorter string is a
prefix of the longer one and sorts below it. A DATE column therefore has exactly
one storable shape, and a column holding two of them silently drops the longer
rows out of any range the shorter ones stay in.

Neither Eloquent date cast delivers that shape:

- `immutable_date` writes nineteen characters whatever it is handed. A Carbon, a
  `DateTimeImmutable` and the string `2026-01-31` all persist as
  `2026-01-31 00:00:00`.
- `immutable_date:Y-m-d` **looks** like the fix and is not. The suffix makes it an
  `immutable_custom_datetime`, a cast `HasAttributes::isDateCastable()` does not
  match, so `setAttribute()` leaves a `DateTimeInterface` untouched and the query
  grammar binds it with `Y-m-d H:i:s`. It normalises a *string* and nothing else.
  `Modules\Ledger\Models\Transaction` carried that pin and was correct only
  because every writer reaching it passed `toDateString()` first.

The schema declares fifteen DATE columns and a model owns ten of them. Measured
on a live install, five of those ten were in the wrong shape and one column held
both at once:

```text
goals.start_date                      19 chars x 6
goals.target_date                     19 chars x 6
recurring_series.next_expected_at     19 chars x 7
forecast_shortfall_windows.starts_at  10 chars x 11, 19 chars x 1
forecast_shortfall_windows.ends_at    10 chars x 11, 19 chars x 1
```

The mixed column is the demonstration. `ForecastHighlightsQuery::activeShortfallCountForUser()`
bounds `starts_at` on a bare `Y-m-d` horizon, so on the day the 30-day horizon
lands on that window's first day the tile and the sidebar badge count one
shortfall short — while the eleven bare rows beside it are counted correctly.
Production wrote the bare rows; the long one came from the demo seeder handing
`ForecastShortfallWindow::create()` a Carbon. Any writer passing a Carbon reopens
it on any column a model owns.

The second defect is invisible until a model is serialised. Eloquent runs
`serializeDate()` over a bare `immutable_date`, which converts to UTC, so east of
UTC a DATE column reported the **day before** in `toArray()` and every JSON form
built on it — `2026-09-16` came back as `2026-09-15T22:00:00.000000Z`.

`Modules\Core\Public\Casts\DateOnlyCast` is the one mechanism now: it formats a
`DateTimeInterface` and parses a string on the way in, so the stored value is ten
characters whatever a writer holds; it disables Eloquent's object cache so a
column read in the same request as it was written still reads back at midnight;
and it serialises from the stored attribute rather than from the value Eloquent
already pushed through UTC. The arch test above walks the migrated schema rather
than naming columns, drives every input shape through each cast, and requires a
written reason for a DATE column that has none —
`envelope_assignments.period_start`, declared `string` on purpose and written
only through `->toDateString()`, is on that list.

See also [Reconcile needs an anchor](../features/ledger/reconcile-needs-an-anchor.md#two-columns-two-shapes),
where the same comparison first cost a reader the whole of the anchor day.

## A stand-in for an IBAN, drawn as one

`Modules/Core/Public/Support/Iban.php` ·
`Modules/Import/Internal/Services/StandInAccountName.php`

`Iban::grouped()` exists because an IBAN carries no break opportunity of its own
and a narrow column split one mid-identifier. It broke the value into fours the
way ISO 13616 prints one, unconditionally — every value reaching it was an IBAN
on the day it was written.

Not every account has one. A card statement and a wallet export carry no IBAN of
the reader's own, so the source writes a stand-in in its place: `SyntheticIban`
spells `ICS-CARD`, `PAYPAL` and `GOOGLE-PLAY`, and a fourth kind is derived
rather than spelled — `CsvPreset::ownAccountIdentifier()` upper-cases the
preset's own format id, so `revolut-csv` yields `REVOLUT`. Through the grouping
those reach the reader as `ICS- CARD`, `PAYP AL`, `GOOG LE-P LAY` and
`REVO LUT`. A real Revolut import found the last one on the naming prompt, under
the words "We found an unfamiliar account".

Two rules sat next to this and neither could see it:

- `AnIbanIsDrawnInGroupsEverywhereArchTest` requires every rendered IBAN to go
  through the seam. It made the mangling universal rather than catching it —
  eight call sites, every one of them correct by its measure.
- The prompt's caption already asked
  `CsvPresetRegistry::issuesOwnAccountIdentifier()`, so a preset's stand-in was
  not *called* an IBAN. That changed the words in front of the value and left
  the value itself formatted as one, and it knew only about presets — `PAYPAL`
  was still announced as an unfamiliar IBAN, on the path where a `.eml` drop
  carries a PayPal receipt and the bespoke prompt keyed on `source_format` does
  not fire.

The fix is in the seam, not at the call sites. `Iban::isIban()` is the one place
that decides, and `grouped()` groups only what passes it; anything else comes
back exactly as it arrived. Every current call site and every future one is
covered without being edited, which is the property eight call sites across four
modules could not have been trusted to keep by hand.

Formatting is half of it. A stand-in is not something a reader can act on, so
the one screen that asks them to act resolves it to the name of the thing it
stands for, through `StandInAccountName`: a preset's own `label` (`Revolut`,
`N26`), or a translated line per `SyntheticIban` case. That match takes no
default arm, so a fourth sentinel has to name the line a reader will see before
the analyser will pass it.

The trap left behind is what `isIban()` is allowed to mean. It answers a display
question about a value that may arrive spaced (its own output, read back) or
lower-cased (straight off a parsed file), so it tolerates both. `AccountNamer`
asks a narrower question about a value it is about to persist as an account's
identity and refuses both, pinned deliberately in `AccountNamerTest`. The two
share the ISO 13616 bound and nothing else: folding them into one rule would
widen a write gate in order to fix a rendering.

Correcting the words was half the screen. A PayPal receipt dropped as `.eml` now
read "We found an unfamiliar account: **PayPal**", which is true, and the Save
button under it still could not deliver — that prompt hands the identifier to
`AccountNamer`, which refuses `PAYPAL` for the same reason it always did. The
reader who followed the instruction was at a dead end, and could not confirm the
run either, because an account left unnamed blocks the confirm.

The routing was the defect, not the guard. `needsPaypalAccountName()` and
`needsIcsAccountName()` asked the run's `source_format`, which on a receipt drop
says `eml` — the transport, shared by all three providers — so the bespoke prompt
that CAN mint a wallet stayed shut and the generic one that cannot took the
question. `needsGooglePlayAccountName()` had already been written the other way
round, off the preview's unknown-IBAN list, because Google Play has no statement
export for a format to name. The fix was to give the other two that same second
witness. Widening the namer was the tempting alternative and the wrong one: it
mints `kind = bank`, so letting `PAYPAL` through would have answered the prompt
by filing a wallet as a bank account — worse than the dead end, and silent.

The general shape: **a screen that asks for a name and the gate that writes it
have to agree on which identifiers exist.** Two prompts keyed on the format, a
third keyed on the preview, and a namer recognising a fourth set — presets only —
left one combination with a form on screen and no writer behind it. Every
identifier a naming prompt can draw needs exactly one prompt that can persist it,
and the way to check is to press the button, not to read the caption.

## A refusal decided in the local database about a row that lives on the peer

`Modules/Sync/Internal/Http/Livewire/PairingFlowModal.php` ·
`Modules/Sync/Internal/Http/Livewire/Concerns/AcceptsPairingCode.php`

An iPhone showed a pairing code with `8:15` left on the clock. A desktop on the
same wifi, at the same moment, typed that code and was told *"This code is
invalid or has expired. Ask the other device to generate a new one."* Both
claims were false, and the advice was the one action that could not help.

`PairingTokenService::accept()` binds a responder onto a row **this** database
already holds. On a typed code there is no such row: the word code carries the
16-byte token and nothing else, so the initiator's keys have to be fetched from
the initiator. `AcceptsPairingCode` did that — LAN lookup, then
`seedResponderToken()` — and its own comment named the exact failure that
skipping it produces. `PairingFlowModal::submitCode()` skipped it. So the
desktop arm could accept only a token this same device had issued, which no
real pairing ever produces, and desktop-to-desktop typed pairing had never
worked in any release.

Three things kept it quiet:

- **The message was confident.** A local miss and an expired token are the same
  `false` from `accept()`, so one sentence covered a state the code had not
  distinguished. `PairingOfferLookup` had already been built for exactly this on
  the mobile side, with four endings and four different pieces of advice, and
  the desktop reached for none of them.
- **The cross-device test hand-seeded the missing step.**
  `PairingFlowModalCrossDeviceTest` ran desktop-shows / phone-accepts and called
  `seedFromInitiator()` itself in the fixture. A fixture that supplies what the
  field never supplies proves the half of the flow that was already working.
- **The only path anyone exercised went the other way.** Phone-scans-desktop
  needs no lookup at all — the QR carries the identity inline — so the arm that
  needed the network was the arm nobody typed into.

The general shape: **a screen may only refuse on grounds it has actually
established.** Where the fact being judged lives on another device, the refusal
has to name what this device observed — that a peer answered and said no, that
nothing answered, that the search never ran — and never a state it has no way to
read. The same rule had already been written once for this seam, in
`no_peer_answered` versus `no_peer_answered_ios`, and the desktop copy needed
its own `no_peer_answered` versus `no_peer_search` for the same reason.

## A surface offering a road the platform behind it cannot walk

`Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php`

The same modal renders on both clients, and on a phone its "Show my code" step
minted a word code and printed *"Enter this code on the other device, or let it
scan the QR."* Half of that is true. Typing it is not, on any peer: a typed code
is resolved by browsing for `_beatrax-sync._tcp` and asking the answering peer
for its pairing offer, and both of those live inside `sync:serve`, which only
the desktop shell ever starts. A phone advertises nothing and serves nothing, so
the row that code names is unfindable from a desktop and from another phone
alike — and fixing the desktop's lookup does not reach it.

The sibling arm on the same screen already branched: `enterACode()` sends a
phone to the camera-first screen because this modal has no scanner. `showMyCode()`
had no such branch, so the capability check stopped one method short of the
place the promise was printed.

The general shape: **when one surface serves two platforms, every promise it
prints needs the same platform test the promises next to it got.** A branch that
covers one direction of a two-way flow is evidence the question was asked, not
that it was answered — the other direction is where to look next.

## A panel that steps its own balance over "No payments on this day."

`Modules/Calendar/Internal/Services/BookedEntryPlacer.php` ·
`Modules/Calendar/Internal/Services/CalendarQuery.php`

The `/calendar` day panel prints a start-of-day figure, the payments on the day,
and an end-of-day figure. Two independent passes fill the middle: `SeriesEntryPlacer`
draws what a cadence expects, `BookedEntryPlacer` draws what the ledger holds.
The two figures come from a third — `DailyBalanceAggregator`, which behind today
is the plain cumulative sum of `transactions.settled_amount_minor`.

`BookedEntryPlacer` ran from **yesterday** forward. The reasoning written beside
that bound was that a past day "lists them through the paid/missed pass", and
that pass reaches `recurring_series_occurrences` — series only. A plain imported
row belonging to no series was therefore drawn by neither pass, while the sum
underneath counted it like every other row. Read on an iPhone, on a fresh account
holding one 18-row ASN import:

```text
23 Aug  €2,747.92 → €2,706.72   "No payments on this day."   Jumbo Supermarkten −€41.20
25 Aug  €2,706.72 → €5,906.72   "No payments on this day."   Nordwind Media BV +€3,200.00
27 Aug  €5,906.72 → €5,821.72   "No payments on this day."   Belastingdienst −€85.00
```

Every figure on those three cells is correct. The sentence between them is not,
and it is the sentence a reader believes, because a day panel that says a day was
quiet has just made a positive claim about it. Nothing errors, nothing is missing
from the page, and the same panel one week forward — 1 and 5 September — listed
its payments in full, which is what made it look like a working screen.

Two shapes converge here and both are the same mistake:

- **Two passes that between them do not cover the domain.** "Ahead of today" and
  "series occurrences behind today" are not a partition of "days with money on
  them". Whenever one surface enumerates and another aggregates, the enumeration
  has to be *derivable from* the aggregation, not merely adjacent to it — the
  balance line was built from `transactions`, so the entry list has to be built
  from `transactions` too.
- **An estimate drawn on the day a payment was due rather than the day it moved.**
  Withholding past days from the booked/expected pairing left a rent expected on
  1 June and paid on 3 June showing a paid ✓ on the 1st, over a balance that did
  not move, and nothing at all on the 3rd, where it did. A verdict is not a
  movement, and only a movement may sit on a day that stepped.

The invariant, and the assertion worth keeping: **on any day whose start-of-day
and end-of-day differ, the entry list is non-empty and its amounts sum to the
difference.** It is cheap to assert over a whole grid and it fails loudly on
exactly this class of bug, where a per-day expectation would not — the test that
covered this seam asserted a past day lists *nothing*, and was green.

## A restore that reports success over a write-ahead log it did not remove

`db:restore` closed the one connection its config named, copied the source
`.sqlite` over the live file, ran `PRAGMA integrity_check` on the result, printed
"Restore complete" and exited 0. On a database still held open by any other
process — and `php artisan down` closes none: not the desktop server's own
handle, not the sync daemon, not the queue worker — the `-wal` sidecar survives
that copy. The next reader recovers it on top of the pages that were just
written. Measured on a held WAL fixture: 501 rows of the OLD database, exit code
0, and a post-swap integrity check of `ok`, because it read through the same
replayed log.

The encrypted restore had already learned this once and written it down —
"replacing the file left a new connection running `PRAGMA journal_mode = WAL`
reporting code 11" — and fixed only itself. Two restore paths, one of them
finished.

The invariant: **a restore writes the source's pages INTO the live database, via
SQLite's own backup API, after dropping every connection that names that file —
it never copies a file over it.** The file-copy fallback exists only for a
runtime with no `sqlite3` extension, and there it must unlink `-wal` and `-shm`
itself. Both paths reach it through one collaborator,
`Modules\Core\Internal\Backup\LiveDatabaseTransplant`, so neither can learn
it alone again.

The assertion worth keeping is the one that catches it: hold a second connection
to the live file open across the whole restore, close it only afterwards, and
read the markers back from a fresh connection. A test that restores over a
database nobody else has open cannot see this at all — the sidecar is
checkpointed away by the close, and every path looks correct.

## An archive of the rows without the key that opens them

Settings → Data & backup offers "a passphrase-encrypted copy of your whole
database". `VACUUM INTO` copies the database and nothing else, and for a user
with encryption at rest the keys are not in it: the GDK keyring is a file beside
the database. Restored anywhere that file is not — and the mobile
restore-from-backup screen is gated to a **fresh install**, which is by
definition such a place — `sync_encryption_state.current_epoch` names an epoch no
keyring holds, every sealed column blanks, and the restore reports success.

This is the same outcome as the update that once relocated the database to the
persisted store and left `storage/app` behind: a database full of ciphertext with
no key, nothing errored, and the loss surfaced on the next read. The road was
different; the destination was not.

The invariant: **anything that moves a database somewhere else moves the key
material that opens it, or it has not moved the data.** The set of files that
must travel is named once, in
`Modules\Sync\Public\Services\PortableKeyMaterial`, rather than re-spelled by
each mover — three string copies of `sync/gdk/{userId}.enc` existed before it,
and a keyring written where nothing looks for it is indistinguishable from no
keyring. What deliberately does not travel is stated in the same place and for a
reason: a device identity restored onto a second device would put two peers on
the network claiming to be the same one.

## An error that names a cause the code had already ruled out

Four producers wrote `system_alerts(kind=backup_corrupt)`, and the banner chose
between two sentences on whether a `.suspect` file existed. Three of them landed
on the branch that reads *"aborted before any file was produced — source DB
failed integrity check."* One was the sidecar-write failure, which is reached
only after the source has been opened, vacuumed, and passed `PRAGMA
integrity_check` — with the verified backup sitting on disk. Another was a failed
`db:restore`, which had not attempted a backup at all.

The reader was sent to hunt corruption in the one thing that had just been proven
sound, and away from the disk that was actually full.

The invariant: **a raiser records what happened; a reader never infers it from
the absence of an artefact.** The four causes are an enum
(`Modules\Core\Internal\Enums\BackupFailureCause`) written into
`metadata.cause`, and the banner switches on it. A row written before the field
existed carries none and keeps its old sentence, which is the only reading of a
missing cause that does not invent one.

## A screen that formats a figure it has not computed

`/reconcile` bounds every number it shows by `posted_at <= statementDate`, and
its date field is `x-core::date-input`, whose calendar carries a Clear button.
Clearing it writes the empty string back, and the page then reported the
cleared balance as **€0.00** for an account holding money, and handed the
`null` difference to a view closure typed `$fmt(int $minor)` — a `TypeError`,
so the whole page 500'd on a control the screen itself draws.

Zero and "no answer" are different facts and only one of them can be printed.
The invariant: **a figure the page could not compute is passed to the view as
`null` and drawn as `—`, never defaulted to zero on the way out.** The
component distinguishes the three unanswerable states — no account, no
statement date, no target — and the panel names the one that applies rather
than the first one on the form; a muted pill reading "enter a statement
balance" over a balance the reader had already entered would name a cause the
code has ruled out.

## A refusal that outlives the state it was refusing

`confirmReconcile()` writes its mismatch sentence into a component property
and the panel beside it recomputes on every keystroke. Nothing cleared the
sentence, so a reader who fixed the balance read *"does not match the cleared
balance yet"* directly above a pill reading **matched** and a Complete button
that had just re-enabled itself. The screen contradicted itself in one
viewport, and the older half was the louder one.

The invariant: **a message about form state is cleared by the same round trip
that can invalidate it** — a component-wide `updated()` hook, not a per-field
one, because every field on that form feeds the same computation. A "Check"
button that clears it is not the fix: it makes the reader responsible for the
screen's honesty.

Server-owned display strings are `#[Locked]` for the other half of this. No
control binds `$error`, so a payload that could set it would wrap the app's own
error styling around a sentence the app never wrote.

## A notification title that only holds for one of the states that raise it

A cash entry typed by hand on `/cash` was announced in the inbox as
`⊕ Import — Import finished — 1 transaction imported.` The ledger design behind
it is right and stays: a manual entry travels the same canonical pipeline an
import does and opens an `import_runs` row of its own, with
`source_format = 'manual'`. `PersistCoalescedImport` listened to
`TransactionBatchImported`, branched once — receipts or not — and told the
reader their own typing was an import. The row carried the word to tell them
apart and nothing read it.

Four more of the same shape were in the same nine triggers, and each one is a
title written against the single state its author had in mind:

- **`budget_nudge`** — *"Budget nearly spent"*. `EmitBudgetNudgesJob` fires on
  `spent >= threshold%` with no ceiling, the reader can set that threshold as
  high as 200%, and the occurrence key is the period, so the one nudge a period
  carries is whatever the first run after the crossing saw. One large charge put
  *"Budget nearly spent"* over a body reading *"€250.00 of €100.00 spent."*
- **`forecast`** — *"Your projected balance dips below zero within the next 30
  days."* `ShortfallDetector` judges against `BufferFloor`, which is the
  reader's own minimum buffer wherever they set one, and
  `ProjectForecastsCommand` runs every `ForecastHorizon` case. A balance that
  never leaves the black was announced as dipping below zero, and a dip the
  365-day run found was announced as one inside thirty days.
- **`payment_reminder_*`** — *"Payment due Tuesday"*. The lead time is the
  reader's, up to 30 days, and `EmitPaymentRemindersJob` admits everything in
  `[today, today + leadDays]`. Four Tuesdays fit in that window; the row is
  written on the first day of it and read whenever the reader opens the inbox,
  by which time the weekday it names can be in the past.
- **`savings_prompt`** — *"A cheaper plan exists"*. `SavingsInsightKind` has
  three cases and only `Cheaper` found one; the other two are a price that went
  up and a charge worth a second look. The body under it was
  `:message (:monthly/mo)` where every one of the three messages already carries
  the monthly figure, so the amount printed twice in a row.

The invariant: **a notification's title and body have to hold for every state
that can raise their trigger, not for the one the author pictured.** The test
for a new line is not "is this true of the case I am writing" but "what is the
full range of the values the event can carry, and does the sentence survive all
of it". Every one of the five had the discriminating value already riding on the
event — `sourceFormats`, `spentMinor` against `budgetMinor`, `bufferUsedMinor`
and `startsAt`, `dueDate`, `insightKey` — and none of the five read it.

Where the range is genuinely open, the honest line drops the claim rather than
guessing: the savings title now names a place to save, which holds for all three
kinds, because Notifications cannot import `SavingsInsightKind` to tell them
apart and a title that names a kind it cannot see is the same defect again.

Nothing caught any of them because a lang file is a set of strings with no state
beside it, and every test that read one asserted the sentence an author already
believed. The guards are per-trigger feature tests driven through the real
emitter — `EmitBudgetNudgesJob`, `ShortfallDetector`, `EmitPaymentRemindersJob`,
`CashBookPage` — set to the state the copy did not cover.

## An expense that settled as income, because one leg was read verbatim

A PayPal activity export is an event log, and it books each leg of a currency
conversion in the direction *its own* balance moved. An outgoing `USD -22,50`
payment funded from a euro balance therefore ships as three rows: the dollar
parent, a `EUR 20,80` conversion leg, and a `USD -22,50` one. The euro leg is a
credit in PayPal's ledger and is genuinely positive there.

`PaypalTransactionRollup` took that leg's amount verbatim as the settled leg, so
the canonical row read `amount_minor -2250 USD / settled_amount_minor +2080 EUR`.
`NormalizeStage` then derived `fx_rate_used = settled / native` from the pair it
was handed and stored **−0.92444444**, a negative exchange rate.

It was one row in a 265-row ledger and nothing rejected it. Balances sum
`settled_amount_minor`, so the wallet's settled total came out at `2861` where
it should have been `-1299`, and `/forecast` drew the line
`PayPal · Baseline €28.61 today → €28.61 – €28.61 on day 30` over an account
that was €12.99 overdrawn — a €41.60 error with the sign inverted, on a screen
whose whole job is to say whether the money runs out.

The invariant: **the two legs of one transaction carry one direction.** A
settled leg lends its magnitude; the sign belongs to the transaction and is
taken from the native leg, and the rate between them is therefore a positive
magnitude. It is enforced in `Ledger::TransactionAmount::relate()` rather than
in each adapter, because that value object is the only place the settled
columns and their rate are constructed — `NormalizeStage` is the single seam
every import format and the receipts path reach them through, and it now
derives no money of its own. `PaypalTransactionRollup` also takes the parent
payment's direction for both legs, because the mirrored shape (a euro parent
whose foreign leg is promoted to the native pair) puts the wrong sign on the
**native** leg, where no downstream guard can tell which of the two was right.

The rule stops at a conversion. Two legs in the *same* currency are net-of-fee
arithmetic, not one movement written twice, and a settled figure that crosses
zero there is the fee doing what a fee does — so that pair keeps its own signs
and carries no rate to invert.

Nothing caught it because every fixture in the suite happened to agree: the
redacted `paypal-sample-1.csv` carries the euro leg as a debit, which matches
the parent by luck rather than by rule, so the assertion on it passed either
way. The regression drives the real upload path from
`Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv`, whose legs carry
the opposite signs, and asserts the wallet balance the reader is shown as well
as the column.

## A file called unreadable after the app had read, dated and filed it

`Modules/Import/tests/Feature/AnEmailThatWasReadWasCalledUnreadableTest.php`

An `.eml` receipt uploaded on an iPhone answered:

> This file could not be read
> No transactions were found in this file, so there is nothing to import.

The database said otherwise. `file_imports` held the row: `source_kind
eml`, `sender_email service@paypal.com`, the RFC 2047 encoded-word subject
decoded, `internal_date` matching the `Date` header, the raw bytes stored
under `eml_path`, and `status unmatched` — the correct waiting state for a
receipt no matcher could read a payment out of. The file had been read
completely and filed correctly.

Both sentences were separately defensible. The body was true: a receipt
legitimately yields no transactions. The heading came from
`nothingToImport`, which the preview screen computes as "zero importable
rows" — the only thing it could see, because `PreviewWizard` had no
notion of a receipt at all and the receipt arm of `ParseStage` yields a
source row only for a message that parsed. Zero rows from a corrupt CSV
and zero rows from a receipt in a wording the matcher does not cover
reached the screen as the same number.

What it cost: the reader is told their capture failed when it succeeded,
is shown nothing about the receipt they just handed over, and is given no
route to it. The rational responses are to upload it again — which dedups
to the same row and the same sentence — or to give up. Nothing in the app
would ever have mentioned that message again.

The invariant: **a screen may only call a file unreadable on evidence
that nothing was read from it.** Row count is not that evidence wherever a
successful read can legitimately produce no rows. The receipt arm now
carries a second witness — `RecordReceipt` records a `CapturedReceipt` per
message into a `ReceiptCaptureLog` that rides the `PreviewHead` — and the
failure copy is suppressed only when one of them is identified, meaning a
sender or a subject came out of the file. Bytes that are not a message
still get their audit row, and still say so.

Nothing caught it because every test on this path drove a fixture that
parses. `Modules/Receipts/tests/fixtures/paypal/current-receipt.eml`
reaches a transaction end to end, so the wizard tests asserted the good
arm and the receipt tests asserted the database, and no test asked what
the screen says when the two disagree. The regression drives the real
upload path from the three committed
`Modules/EmailScan/tests/fixtures/eml/` samples, none of which any matcher
can read, and asserts the sentences on screen.

## A count of zero standing in for two different answers, one screen later

`Modules/Import/tests/Feature/AReceiptWaitingForItsAccountNameWasCalledEmptyTest.php`

The screen above was fixed and the same number lied again, one branch
across. A Google Play receipt imported on an iPhone drew, in this order:

> This file was read as email
> What it carried is listed below, and every message has been saved.
> **Nothing here became a transaction, so nothing was added to your ledger.**
> Your Google Play Order Receipt · `googleplay-noreply@google.com` · 17/05/2026
> **Read as a payment — confirm this import to add it to your ledger.**
> Name your Google Play account. This is the first time you've imported a
> Google Play receipt.

Two sentences four lines apart, contradicting each other, with the form
asking for the one thing that would settle it underneath. Typing a name
and pressing Save made the first sentence vanish and the row appear;
confirming imported the transaction. The receipt was readable and
ledger-bound the whole time.

`$nothingImported` was `$importableRowCount === 0`, and
`importableRows()` is documented as *what confirming would actually
write*. That is a true statement about this instant and it was read as a
statement about the file. Every row a first receipt produces resolves to
`UnknownAccount` — the account does not exist yet, and this screen is
what creates it — so the pipeline files them all as failed rows and the
count is zero for the length of one question.

The invariant: **a screen may not report an outcome while it is still
asking for the input that decides it.** A count of zero has two readings
wherever the screen itself holds the missing piece — nothing was read,
and nothing has been staged yet — and the state asking the question is
the one that tells them apart. The Blade now names both:
`$nothingImportableYet` for the count, and `$nothingImported` for the
count with every open naming question subtracted. The header subtitle
keeps the first, because it points at a rows table that the ICS, PayPal
and Google Play branches do not render; the receipts copy and the
file-failure card take the second.

This is the first import of every receipt provider and invisible on the
second, which is where the twin defect came from too: the fixture that
proves the path — `Modules/Receipts/tests/fixtures/googleplay/current-receipt.eml`
end to end — was driven by tests that had already seeded the account, or
that asserted the naming prompt without reading the rest of the page. The
regression drives the real upload with no account seeded and asserts both
sentences at once: the capture's "confirm this import to add it to your
ledger" present, and "nothing was added to your ledger" absent.

## A capability refused instead of supplied, on the platform that cannot supply it

`Modules/Import/tests/Feature/ACardStatementImportsWithoutPdftotextTest.php`

A PDF card statement uploaded on an iPhone answered:

> This file could not be read
> Nothing in this file could be read as a transaction, so there is nothing to import.
> PDF statements need the pdftotext program, which is not installed here.
> Import this file on a desktop that has it, or use a CSV export from your bank instead.

Every sentence was true and none of it was usable. `pdftotext` is a
poppler binary; iOS and Android do not run a second binary at all, so the
advice named a fix the platform forbids. The issuer publishes no CSV
either — PDF is the only export it has — so the alternative offered did
not exist for this bank. The result was that PDF import, one of four
supported formats, was dead on a first-class platform and reported as the
reader's problem to solve.

What made it survivable in review is that the refusal was well written.
It named the missing component, explained the consequence, and offered
two routes onward; it reads as a considered limitation rather than a gap.
A refusal that sounds finished is harder to notice than a crash.

The invariant: **a platform difference in how a capability is obtained is
not a platform difference in whether the app has it.** Extraction was the
only missing piece — the ICS adapter, the pipeline and every assertion
downstream already worked, and worked identically once text arrived. The
answer was a second reader (`PdfTextLayoutReader`, pure PHP over
`smalot/pdfparser`), chosen at one seam, with poppler still preferred
where it exists. Where a capability genuinely cannot be supplied, the
refusal must name the cause that actually applies: a scan has no words in
it and an encrypted file opens for nobody, and neither of those is a
missing program.

Nothing caught it because the test written for this path asserted the
refusal. `IcsPdfImportTest` bound an extractor to a non-existent binary
and checked that the screen said `pdftotext` and did not blame the header
row — a careful test of the wrong outcome, green for as long as the
capability stayed missing. The regression drives the committed
`Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` through the
real import pipeline with the binary absent and asserts the statement's
own contract: 23 rows, settling to 84732 cents.

## A valid file called unreadable by an app that had crashed

`tests/Contracts/AClassThePhoneDoesNotHaveIsReachedOnlyThroughItsCapabilitySeamArchTest.php`

`ZipExtractor::extract()` opened every migration export with `new ZipArchive`,
with nothing in front of it. The NativePHP mobile PHP build has no `ext-zip` —
its `php_config.h` says `#undef HAVE_ZIP` on both iOS and Android — so on a
phone that line raises `Error: Class "ZipArchive" not found`. All three source
products upload a ZIP, so the whole of `Migration` was dead on a phone, and the
`catch (Throwable)` in `NewMigration::submit()` answered every one of them with
the same sentence: *this doesn't look like a YNAB4, nYNAB, or Actual export we
can read. Check the file and try again.* The file it said that about was the
repo's own committed golden fixture.

Two rules come out of it, and they are independent. The first is that a class an
extension provides is a platform capability, not a language feature: it is
reached through a seam that asks whether the running build has it, and the
answer has a branch on both sides. `ArchiveReaderFactory` is that seam, and the
arch test above holds `ZipArchive` to the two files allowed to name it. The
extension being in the root `composer.json` proves nothing — `mobile-app/`
cannot require it, and `ComposerRootsAgreeArchTest` only compares packages
present in both roots, so a requirement one target cannot meet is invisible to
it by construction.

The second is that "we could not read your file" and "we failed" are different
sentences and the reader is owed the right one. A screen that catches
`Throwable` and prints one line prints that line for a corrupt upload, for a
capability the device does not have, and for a crash — and only the first of the
three is answered by choosing another file. `NewMigration::messageFor()` now
maps the three endings, the way `ImportPipeline` already named the missing
`pdftotext` rather than blaming the PDF.

Nothing caught it because the suite runs on a desktop, where the extension is
present and every test of that path is green. The reproduction that made it
visible is the phone's own runtime: PHP 8.5.9 with no `ext-zip`, running the
real file against the real fixture. The tests that pin it force the branch
instead — `ArchiveReaderFactory` constructed with the answer a phone gives —
and they compare the built-in reader's output to the extension's byte-for-byte,
because a fallback that is only exercised on a device is the same blind spot
one layer down.

## A start and an end printed around rows that cannot reach either

`Modules/Calendar/Internal/Services/CalendarQuery.php` ·
`Modules/Calendar/Internal/Services/AccountResolver.php`

The `/calendar` day panel prints a start-of-day figure, the day's payments, and
an end-of-day figure. Those three lines are built from **two different account
sets**: the entry list from the visible set, which defaults to every account,
and the two figures from the balance set, which defaults to the spendable kinds
only. Wherever the two disagree the panel states an arithmetic the rows on it
cannot close. Read on an iPhone, May 2026:

```text
 8 May  €1,910.00 → €1,897.01   Adobe Systems Software   PayPal Wallet    −€12.99
 5 May  €1,910.00 → €1,910.00   KLM ROYAL DUTCH AIR      iPhone ICS Card  −€80.00
17 May  €1,897.01 → €1,897.01   Spotify Premium          Google Play      −$12.99
```

The first cell closes. The second and third do not, and each reads as an
end-of-day that is wrong by the charge above it. Every figure is correct: an
`ics_card` and a `google_play` account are outside the balance set on purpose,
because a charge on either is settled from an account the balance already sums
and counting it twice is the defect the exclusion exists to prevent. Nothing on
the panel said so, so the exclusion was indistinguishable from an error.

The module had already solved this exact shape once, for a different cause. A
currency the rate table cannot reach is left off the balance and
`DayBalanceDto::$unconvertedCurrencies` names the codes, which the panel and the
month grid both render. That is the same defect one input earlier — a partial
figure presented as a whole one — and it had one answer where it needed two.

The invariant: **a surface that draws an enumeration and an aggregate together
names every member of the enumeration the aggregate does not reach.** It does
not matter whether the omission is a missing rate, a deliberate kind exclusion
or a checkbox the reader turned off; what makes the screen wrong is silence
about the difference, not the difference. `CalendarDayDto::$uncountedAccounts`
is the second answer, drawn beside the first: the day names its own accounts,
the grid names them once above itself, and a day that states no balance at all
names nothing, because it has made no claim to disown.

The reason the exclusion also has to be *stated in code* is the same failure a
layer down. The constant's comment justified credit cards alone —
`google_play` was excluded too, by a rule the comment did not describe, so
reading it left the second exclusion looking like an oversight. A comment that
covers a subset of what the code does is a second silence.

Nothing caught it because every test of the seam supplied one account kind. The
regression is the 5 May cell: a bank balance, an `ics_card` charge on the same
day, and assertions that the two figures are equal, that the charge is listed,
and that the panel names the account between them.

## A list sorted by a column it does not show

`Modules/Ledger/Public/Services/TransactionCursor.php` ·
`Modules/Ledger/Public/Services/TransactionListQuery.php` ·
`Modules/Ledger/Resources/views/livewire/transactions-list.blade.php`

`transactions` carries two days. `posted_at` is a DATE and the column every
ordered read in the app is built on: `TransactionCursor` sorts
`(posted_at, id)` descending and pages on the same pair, `recent()` bounds its
window on it, six indexes cover it. `booked_at` is a DATETIME with no general
index at all. Every adapter but one writes the two equal — `booked_at` is
`posted_at`'s day plus a time-of-day whose only job is to keep two same-day
same-merchant fingerprints apart. The exception is `IcsPdfAdapter`, which reads
a card statement's two columns and means them: `posted_at` is the day the card
was used, `booked_at` the day the issuer booked it, and on a real ICS statement
that is a different day on every row.

The list ordered on `posted_at` and printed `booked_at`. Read on an iPhone,
May 2026:

```text
Adobe Systems Software    08/05/2026
ZEEMAN ALPHEN             08/05/2026
Bankstorting              05/05/2026
Google Cloud EMEA         05/05/2026
KLM ROYAL DUTCH AIR       06/05/2026
AH TO GO AMSTERDAM        04/05/2026
```

The three middle rows share a `posted_at` of 5 May, so the sort breaks their tie
on `id` — and their `booked_at` values, one carried over from the card and two
written flat by PayPal, come out 05, 05, 06. Interleaving two sources at one
posted day is all it takes; a statement from a single source stays accidentally
monotonic and hides it.

The defect is not which of the two days is right. Both are real and the app is
right to store both. The defect is that **the column a list orders on and the
column it prints have to be the same column**, and a second surface reading the
other one — `/transactions/19` printed `posted_at`, so opening a row changed its
date from 6 May to 5 May — is the same failure across two screens instead of
down one.

The blast radius was the shared cursor: `TransactionListQuery`,
`UncategorizedTriageQuery` and `SearchQuery` all sort through
`TransactionCursor` and all printed `booked_at`, so the triage inbox, the search
results, the command palette and the dashboard's recent-transactions table
inherited it. Search was the worst of them — `before:2026-03-31` filters
`posted_at` and then listed April dates.

The fix is the cheap direction. Sorting on `booked_at` instead would have needed
a new `(user_id, booked_at, id)` index plus mirrors of the four existing
`posted_at` composites, and would have put the keyset cursor on a DATETIME that
the day-trimming migration deliberately does not normalise — two shapes in one
column under a row-value comparison is [A DATE column carrying a
time](#a-date-column-carrying-a-time) with pagination on top. Printing
`posted_at` costs nothing and makes four surfaces agree with the detail page
that was already right. The DTO field is named `postedAt` on all three row
types, because a field called `bookedAt` fed from `booked_at` beside a sort on
`posted_at` is how this got written in the first place.

Where the two days genuinely differ the second one is still worth saying, so the
detail page draws a `Booked :date` line — and only there, and only when the days
differ, because on every other source it would repeat the date above it.

A fifth surface was found later, on the other side of the app.
`/chains` draws each settlement and its legs from `posted_at`, and the chain
drawer opened over the same rows drew `ChainTreeNode::$bookedAt` — so tapping a
card moved every ICS charge's date by the days the issuer took to book it. The
resolvers do match settlement legs by `booked_at` proximity, and that is left
exactly as it was: the node is a display DTO, nothing reads its day to decide
anything, and threading a second date onto it so the drawer could keep printing
the one it does not sort by would have preserved the defect under a longer name.
The field is `postedAt` now, fed from `transactions.posted_at` in both builders
that make a node — `ChainTreeWalker` for the tree and
`ChainDrawer::makeChildNode` for the fan-out children it collapses under their
settlement — because a node built two ways is a node that can disagree with
itself.

Nothing caught it because the fixture cannot produce it. `Modules/Ledger/tests/TestCase.php`
defaults `booked_at` to `$postedAt.' 12:00:00'`, and every hand-written fixture
in the tree follows that shape, so the two columns name the same day in every
test that has ever run. The regression is a list holding rows from two sources
at one `posted_at`, asserted on the rendered markup rather than the row DTO, and
walked past its first page.

A sixth surface was missed on the first sweep because it is not a transactions
list. The alias preview on `/settings/aliases`
(`Modules/Import/Public/Services/AliasMatchPreviewQuery.php` ·
`Modules/Import/Internal/Http/Livewire/AliasesSettingsPage.php`) shows five rows
an alias would rename, and printed `booked_at` **raw** — so the reader met
`2026-05-06 12:00:00`, the synthetic time-of-day the dedup fingerprint carries,
in a column every other screen renders through `Fmt::shortDate()` in one of
twenty-six locales. It also ordered its 500-row scan on `booked_at`, which is
the same defect one layer down: the rows it keeps were not the rows the reader
would call most recent. Both now read `posted_at`, and the scan breaks its tie
on `id`, because a DATE leaves ties that a DATETIME did not.

### The seventh surface, and why there were seven

`Modules/Import/Public/Dto/PreviewRowDto.php` ·
`Modules/Import/Internal/Pipeline/ImportPipeline.php` ·
`Modules/Import/Resources/views/livewire/preview-wizard.blade.php` ·
`Modules/Onboarding/Resources/views/components/consolidated-preview-section.blade.php`

The import preview — the screen that asks the reader to confirm a file before
anything is written — carried `PreviewRowDto::$bookedAt`, fed from
`$source->bookedAt`, under a column headed "Date". So the six converted
surfaces all agreed with each other and disagreed with the one screen a row
passes through *first*. Driven over the committed ICS statement
(`Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt`), 36 of its 38 rows
previewed a day the commit does not write, and one of them previewed a month:

```text
KOSTEN KASOPNAME          preview 16/01/2026   ledger 2026-01-15
AUGMENT CODE              preview 24/01/2026   ledger 2026-01-23
Prime Video *ZP3254365    preview 01/02/2026   ledger 2026-01-31
```

Three of the six already-converted files carried a comment explaining the rule
this one broke — `TransactionRowDto`, `TriageRow` and `ChainTreeNode` each
restated it in their own words, and a fourth restatement sits on
`TaggedRowScope` for the tax year. **A rule stated in N comments is a rule
enforced nowhere**: every restatement is evidence that the last person to meet
it had to rediscover it, and the file that never got one is the file that
breaks it. The comments have been reduced to what is local to each type, and
the rule now lives in one place with a walk under it:

- `Modules/Ledger/Public/Support/LedgerDay.php` is the seam. Every reader-facing
  day is formatted through `LedgerDay::shown()`, which is what the four string-day
  row builders now call in place of `Fmt::shortDate()`. `Fmt::shortDate()` will
  format whatever date it is handed; the seam is what says which date a row is
  allowed to be handed.
- `tests/Contracts/AReaderFacingRowNamesTheDayTheLedgerStoresArchTest.php` walks
  four ways: no row type *declares* the booking stamp, no view *prints* it, no
  `postedAt:` argument is *fed* from it, and every row built with a formatted day
  reaches the seam. The third and fourth exist because renaming the field and
  leaving `Fmt::shortDate($source->bookedAt)` behind satisfies the first two and
  changes not one date on screen.

Seven pins carry the real exceptions, each with a `proves` pattern re-run
against its file: `SourceTransactionDto` and `CanonicalTransaction` carry both
days because one reads a file and the other writes the columns; `ParsedReceiptDto`
and the three single-day adapters have no second day to choose from; and the
transaction detail page prints the booking stamp deliberately, labelled, and
only when the two days differ.

The preview's day is now taken off the `CanonicalTransaction` the commit is
about to write rather than off the `SourceTransactionDto` it was parsed from,
which is the difference between a preview that agrees with the ledger and a
preview that agrees with it on every statement anyone happened to test.

The same row carried an empty slot for the other thing the commit decides.
`PreviewRowDto::$categoryName` was passed `null` at both construction sites and
copied through `PreviewCache::renamed()`, while the commit writes `category_id`
from `AppliesAutoCategory` — a preview row whose entire job is to show what the
commit will do, declaring a field for the one fact it withheld. The id *is*
reachable at `acceptedRow()` (`$normalized->categoryId`), so "it cannot know"
was false. What it cannot do is **show** it: neither preview table has a
Category column, and adding one needs a `col_category` header across
twenty-six locale files. Resolving a category *name* per row would also put a
lookup inside a pipeline that is deliberately chunked so a statement can exceed
the memory the device will give the interpreter. So the field was removed rather
than filled: when the column is added, the field is added populated, in the same
change.

A fifth walk enforces that pairing — a row type may not declare a field that
every construction site passes `null`. A copy of the same field off another
instance is discarded first, or one with-er would launder a field that is
`null` everywhere real. Across 22 row types and 21 with production construction
sites, `$categoryName` was the only instance, so the walk carries no pins.

## A guard that reads one branch of a choice and answers "absent" for the rest

`Modules/Ingestion/Internal/Adapters/Banking/Camt053Adapter.php` ·
`Modules/Ingestion/Internal/Adapters/Banking/Camt053HeaderProfile.php` ·
`Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php`

ISO 20022 defines `CdtrAcct/Id` as a CHOICE of `IBAN` or `Othr`, and
`genkgo/camt` models that with siblings of `IbanAccount` — `OtherAccount`,
`BBANAccount`, `UPICAccount`, `ProprietaryAccount` — every one of which exposes
`getIdentification()`. The adapter narrowed on `IbanAccount` and returned `null`
otherwise:

```php
private function relatedPartyIban(RelatedParty $party): ?string
{
    $account = $party->getAccount();
    if ($account instanceof IbanAccount) {
        return $account->getIban()->getIban();
    }

    return null;
}
```

Ten lines above it, the same file already solved the same question correctly for
the statement's own account, by falling through to `getIdentification()`. The
counterparty side did not, so every `Othr`, `BBAN`, `UPIC` and `Proprietary`
counterparty was discarded — including the one shape a Dutch reader meets every
month, a credit-card settlement:

```xml
<CdtrAcct><Id><Othr><Id>ICS-CARD</Id></Othr></Id></CdtrAcct>
```

On an iPhone, after importing a card statement, a PayPal export and the ASN
CAMT.053 that pays the card: the settling transaction sat in the ledger with
`counterparty_name = 'ICS Cards Nederland'` and `counterparty_iban` empty.
`IcsSettlementResolver` predicates its candidate query on
`whereNotNull('transactions.counterparty_iban')`, so the payment never entered
the pass. Four `chain_resolution_runs` completed with `linked_count = 0`,
`chain_links` held nothing, `/chains` said "No chains yet", and the dashboard
reported `ICS settlement overdue: €847.32, due 19 May 2026` on the day the
payment for it was imported. A dropped identifier is not a missing one, and the
screen told the reader a bill they had paid was unpaid.

The same shape ran a second time, one module downstream. A card answers to two
names: an alias row in `known_counterparty_ibans` maps the institution's real
IBAN onto the card's kind, and a card whose statement arrives as a PDF carries a
synthetic literal in its own `accounts.iban` column. `ClassifyTransactionType`
and `TransferPairer` both read both. `IcsSettlementResolver` read only the
alias, so even once the identifier survived the adapter, a PDF-imported card
could never be settled. Two guards, one defect: each read one branch of a choice
and reported the other branch absent.

Three more of the same family were in the same two files. `RmtInf` is a choice
of `Ustrd` and `Strd`, and only `Ustrd` was read — a structured-only entry, the
shape an e-invoice is paid against, lost its whole remittance. The namespace
sniffer's regex pinned a double-quoted `xmlns`, so a conformant export using
single quotes was refused with a message blaming its namespace. `BkTxCd/Domn`
without `<Fmly>` was not a silent drop but a crash: genkgo leaves its typed
`$family` uninitialised, reading it raises `Error`, and XSD validation is off by
design, so one non-conformant statement aborted the whole import.

The rule is that a CHOICE has to be read as a choice. When a library models the
branches as sibling types with a shared accessor, narrowing on one of them and
returning `null` is a claim about the data that the data does not support —
"this entry has no counterparty account" when what happened is "this reader only
knows one spelling". Where the value's meaning widens with the fix, the name has
to widen with it: `relatedPartyIban()` became `relatedPartyAccountIdentifier()`
and its sibling `extractOwnIban()` became `extractOwnAccountIdentifier()`,
because `accounts.iban` has held synthetic identifiers since
`Modules\Ingestion\Public\Enums\SyntheticIban` was written and a method still
called `…Iban` would be the only thing in the path still claiming otherwise.

A third site of the pinned-kind half turned up in
`Modules/Chains/Public/Services/CardStatementQuery.php`. When no chain link yet
names the account that paid a card, `nextSettlementForUser()` falls back to the
reader's own accounts to say who will pay the next statement — and pinned that
fallback to `kind = 'bank'`. A reader whose payer is a PayPal balance, a cash
account or anything else was answered "nothing is due": no settlement tile, no
overdue banner, on a statement that was open. `candidateTransferIds()` in the
resolver had already had the identical line corrected, with the comment saying
why — only the card being settled is excluded, because every other kind can be
the one that paid — and the read side was left behind. A guard copied without
its correction is the correction not having happened.

Nothing caught it because the fixtures never carried the other branch.
`tests/fixtures/asn-camt053-sample-1.xml` — 229 entries, the drift snapshot the
adapter is pinned against — contains no `Othr` element at all, and every ICS
fixture in `Modules/Chains/tests/` seeds its ASN leg with the alias IBAN rather
than the synthetic literal a real PDF import produces. The regressions drive the
committed `Modules/Chains/tests/fixtures/scenario-1/` set through the real
import pipeline and assert the identifier survives to the ledger and the
settlement reaches its statement.

## One vocabulary, spelled once per roll-up

`Modules/Ledger/Public/Enums/AccountKind.php` ·
`Modules/Calendar/Internal/Services/AccountResolver.php` ·
`Modules/Forecasting/Public/Services/NetWorthQuery.php` ·
`Modules/Reports/Internal/Aggregation/NetWorthSeriesQuery.php`

Three surfaces sum a reader's accounts: the calendar's balance line, the
dashboard net-worth card and the reports net-worth series. Each held its own
list of which `accounts.kind` values to count, and the three lists disagreed
about two kinds.

`paypal_funding` was in the calendar's list and in neither of the others.
Nothing in the app writes an account of that kind at all — the resolver that
owns the name writes `chain_links.kind = 'paypal_funding'`, which is a
relationship between two transactions — and the calendar's list had inherited
it from a design note that named "provider-code account kinds" and mixed the
chain-link kinds `ics` and `ics_bulk_settle` in among real ones. On a
bank-funds-wallet fixture the balance line read €2,000.00 where net worth read
€1,445.92: the €554.08 of funding handed back to a reader whose bank had already
paid it out.

`google_play` ran the other way. The calendar excluded it; both net-worth reads
counted it. A Play receipt lands on a synthetic account because Google publishes
receipts and no statement, and the purchase itself was charged to a card or a
wallet that carries it as `GOOGLE*WORKSPACE` or `Google Payment Ireland Ltd.` —
both shapes are in this repo's own fixtures, and `ChainLinkKind::FundedByCardHint`
exists to name the pairing. Two rows, one purchase, and a total that summed both
accounts subtracted it twice: €1,988.02 for a position of €1,994.01. Worse in
the case where the funding leg is *not* imported, because `GooglePlayReceiptMatcher`
negates every amount and skips refunds rather than crediting them, so the account
is only ever debited — net worth was reading a cumulative spend tally as a debt
that grows for as long as the reader keeps buying.

`ics_card` is the control. Net worth counts it and the calendar does not, and
both are right: a debt is part of the position and not part of the cash, and a
forward balance line that summed the card would subtract the settlement once
when the charge posted and again when the bank paid it.

The invariant: **when two surfaces ask different questions of the same
vocabulary, the vocabulary owns the answers and each surface names which one it
is asking for.** A per-surface copy of the list cannot record that
`ics_card`'s divergence is deliberate and `paypal_funding`'s was a mistake —
both look identical from inside any one file. `AccountKind` now answers
`mirrorsAnotherAccount()`, `isLiability()` and `holdsSpendableBalance()`, the
four consumers ask one of them, and the reason each kind falls where it does is
written once, in
[which kinds hold money](../features/ledger/architecture.md#accountkind--which-kinds-hold-money).

The exclusions are `whereNotIn`, never `whereIn`, on both net-worth reads. A
`kind` string a build has never heard of is far likelier to be an account the
reader holds than a mirror of one, so the unknown case has to fall inside the
total rather than vanish from it.

Nothing caught it because every test of a kind list tested that one list against
itself — the calendar's unit test reflected over the constant and asserted it
contained `paypal_funding`, which is exactly what it did. The regressions
compare the surfaces to each other instead: one seeds a bank, a wallet and a
routing account and asserts the balance line equals net worth for the same
instant; the other seeds both legs of one Play purchase and asserts the position
is short by the charge once.

## A whole archive read as its first message, under a claim of completeness

`Modules/Import/Internal/Http/Livewire/UploadWizard.php` ·
`Modules/Import/Internal/Pipeline/Stages/ParseStage.php` ·
`Modules/Receipts/Public/Pipeline/ReceiptFileShape.php`

Import type "Email receipt file" offers two formats, `eml` and `mbox`, and
`updatedImportType()` opens the select on the first one — `eml`. A reader who
picked that type, dropped a `.mbox` export and did not touch the format select
got exactly one message imported. The other messages were not skipped, not
errored and not counted: `ParseStage`'s eml arm reads the file as a single
RFC 822 message, so the parser stopped at the first blank line and the rest of
the archive was never looked at. `import_runs.source_format` recorded `eml`,
`file_imports` gained one row stamped `eml`, and the preview panel said

> This file was read as email
> What it carried is listed below, and every message has been saved.

A three-message fixture lost two. A real Gmail export is thousands.

Nothing in the path could have caught it. `HeaderSniffer` has an `eml` arm and
an `mbox` arm, both tested — and neither has a production call site, because the
receipt formats bypass `SourceAdapterRegistry` and every `sniff()` call lives
inside an adapter. Wiring the sniffer in as it stands would not have worked
either: its arms open with an extension check, and the extension proves nothing
here. `RunImport::copyToStableLocation()` names the staged copy after the
*declared* format, so a file declared `eml` is always sitting at a path ending
`.eml` by the time anything looks. The extension check is tautological for every
format on the upload path; only the content checks do any work.

The eml content check would not have saved it on its own. Its signature is a
canonical header token at the start of a line, and every message inside an
archive carries those, so an mbox matches the eml signature. Only the mboxrd
"From " line at the very start of the file separates the two, and reading it in
that order — archive first, message second — is the whole discrimination.

Two fixes, at two altitudes:

- The screen stops offering a default the file contradicts.
  `matchFormatToFile()` reads the dropped file and sets the format from it,
  within the email pair only, and writes a line under the select saying it did.
  A silent correction is its own surprise, and one the reader cannot audit.
- The stage stops trusting the declaration. `ReceiptFileShape::of()` runs before
  either arm reads, and a file that is not the declared transport raises
  `ReceiptFormatMismatchException` rather than being partially read.

The invariant: **a completeness claim is a claim about a read that finished, so
the read has to be over the thing the file actually is.** "Every message has
been saved" is true of the eml arm's one message and false of the file; the
sentence was correct about the loop and wrong about the reader's data. Where a
screen cannot verify a claim it makes, the code underneath it has to refuse the
case the claim cannot cover.

The survey behind it, run as the ten declared formats against ten files, one
per format: every mismatch outside the receipt arm already failed loudly on a
content check — a preset CSV missing a required header, a positional CSV with
the wrong column count, a CAMT.053 without its namespace, an MT940 without
`:20:`, a PDF without `%PDF-`, a PayPal export whose header matches no language
profile. All eighteen mismatches *inside* the receipt arm were accepted.
Declared `eml`, the arm filed one `file_imports` row for a bank CSV, a CAMT.053
XML, an MT940 and a PDF alike. Declared `mbox`, `MboxIterator` found no "From "
line, yielded nothing, and the screen called a perfectly good file empty. The
loud half of the matrix was loud because a sniffer runs there; the silent half
was silent because none does.

## A ranking open to a runner that only ever descends

`Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php`

The dashboard's "Forecast highlights" tile prints one figure at display size —
the lowest balance any account is projected to reach inside thirty days — with
the account's name beneath it and, beneath that, a count of active shortfall
windows. The race behind the figure ran over every row in `accounts`, with no
filter of any kind.

Two kinds cannot lose it. An `ics_card` balance is what is **owed**, so it is
below zero on day one and stays there for the card's whole life; it wins on a
fresh install with a single statement imported. A `google_play` balance is a
cumulative spend tally — `GooglePlayReceiptMatcher` negates every amount and
skips a refund rather than crediting one — so it only ever descends, and takes
the race over permanently once the reader has bought enough. Neither is a
balance anyone can run short of, which is the only thing the tile is asking.

The tile was already carrying the answer three lines further down its own
render. `BufferFloor::forKind()` gives an `ics_card` no floor at all,
[deliberately](../features/forecasting/architecture.md#shortfall-detection), so
that a card cannot raise a shortfall notice — eight came off one card on the
shipped demo seed before it existed. The shortfall count printed under the
figure therefore refused the card while the figure above it named it.

The invariant: **a "lowest" or "highest" over a heterogeneous set is only
meaningful once the set is the one the question is about.** A minimum has no
partial credit — one member on a different scale does not skew the answer, it
*becomes* the answer, permanently and silently, and the reader has no way to
tell a real dip from a runner that was never in the same race.

Which set that is comes from `AccountKind`, not from a fresh private list here;
see [one vocabulary, spelled once per
roll-up](#one-vocabulary-spelled-once-per-roll-up). The tile is a forward-cash
line, so it asks `holdsSpendableBalance()` — the same predicate the calendar's
balance line asks, and deliberately not the one `ForecastChartView` asks four
inches away on the forecasts page, where the aggregate curve is net worth over
time and keeps the card.

Nothing caught it because every test of the tile seeded bank accounts. The
regression seeds a bank dipping to −€10.00 beside a card at −€2,500.00 and
asserts the tile names the bank, once per excluded kind, plus a control that a
wallet still beats a bank and a case where the card is the only account
projected and the line goes silent rather than naming it.

## A tax year taken off the day the bank filed it

`Modules/Tax/Internal/Support/TaggedRowScope.php` ·
`Modules/Tax/Internal/Services/TaxYearQuery.php` ·
`Modules/Tax/Public/Services/TaxTagQuery.php`

Every read in the tax module bucketed a tagged transaction into a year with
`CAST(strftime('%Y', t.booked_at) AS INTEGER)`, and sorted and printed the same
column — internally consistent, and the only module in the tree still bucketing
on `booked_at` at all.

`booked_at` is the day the **issuer** booked the charge. On an ICS statement
that is a different day from the swipe on every row, and it is later, by an
interval that depends on weekends and bank holidays. A card used on 31 December
books on 1 January. The cockpit, the year switcher, the dashboard card, the CSV
and the PDF all filed that purchase under the following year, while the
transactions list, triage, search and the detail page the reader tagged it from
all called it 31 December.

Every deduction corpus that ships here is a private individual's return, and a
private individual is a cash-basis taxpayer in all thirty-three jurisdictions:
the deduction falls in the year of **payment**. The swipe is the payment — the
debt to the merchant is discharged at that instant and replaced by one to the
issuer. `Wet IB 2001` art. 6.40 says *betaald*; US Rev. Rul. 78-38/78-39 puts a
third-party card charge in the year of the charge rather than the year the card
bill is settled; the German-family `Abflussprinzip` asks when the money left the
taxpayer's control. None of the thirty-three files anything on the acquirer's
clearing date, which is not a tax concept in any of them.

The invariant: **an escape hatch cannot cover for a default that is itself
surprising.** `tax_year_override` exists precisely for the December-invoice-paid-
in-January case, and it works — but the reader can only reach for it if they can
see which year the row is on, and they were being shown a year no other screen
agreed with. A default that is wrong in a way the reader cannot perceive removes
the very control that was meant to fix it.

The whole module moved to `posted_at`: the year expression, the sort, the
selected column, the row key (`postedAt`, never `bookedAt` beside a sort on
`posted_at` — the naming lesson from [a list sorted by a column it does not
show](#a-list-sorted-by-a-column-it-does-not-show)), the CSV header
(`posted_date`), and the tag picker's `postedYearFor()`. The two batch-tag reads
that had spelled the year expression out by hand — they count *untagged* rows,
so they have no `tax_year_override` to coalesce against — now share
`TaggedRowScope::TRANSACTION_YEAR` with the coalescing one, so the module cannot
drift back to two answers. The sort gained a `t.id` tiebreak, because
`posted_at` is a DATE and leaves ties the DATETIME did not, and an export an
accountant diffs must not reorder itself between runs.

Nothing caught it because no fixture in the module had ever set the two columns
to different days: the shared helpers default `posted_at` to the day inside
`booked_at`, and the tests that meant to move a transaction to another year
overrode `booked_at` alone, which used to be the only column that mattered. Both
helpers now move `posted_at` with it unless a test says otherwise. The regression
is a charge posted 2026-12-31 and booked 2027-01-01, asserted through all four
surfaces at once — cockpit, year switcher, dashboard card and CSV — plus a
control that an explicit override still beats the posted year.

## One answer standing in for two questions about the same value

`Modules/Ledger/Public/Enums/TransactionType.php` ·
`Modules/Anomaly/Internal/AnomalyEvaluator.php` ·
`Modules/Anomaly/Internal/Detectors/LargeVsTypicalDetector.php` ·
`Modules/Anomaly/Internal/Detectors/FirstTimeMerchantDetector.php` ·
`Modules/Anomaly/Internal/Detectors/DuplicateChargeDetector.php`

`TransactionType::direction()` maps a type onto `Direction::Expense` or
`Direction::Income`, and `transfer_out` lands on `Expense` because it genuinely
lowers a balance. That is the right answer to *which way did the money move*.
`valuesFor(Direction)` expanded it into a type list, and the three anomaly
detectors used that list to answer a different question — *is this something
the reader did, that could be unusual of them* — for which the same answer is
wrong. An internal transfer is the reader shifting their own money between
their own accounts.

It cost twice per row. The transfer itself became eligible: on the desktop
database, six of the twenty-nine open alerts were the two legs of three ICS card
settlements, each rendered `baseline EUR 225.00 -> actual EUR 225.00`, one of
them additionally reporting the reader's own card issuer as a `first_time`
merchant. The settlement is the same money as the twenty-three charges on that
statement, already judged individually — the reader was shown their card bill
as an unusual charge, having already been shown what was on it. And the
transfers sat in the baseline the real charges were judged against, so a month
of savings moves raised the bar a genuine anomaly had to clear.

The chain also survived a correction. Those rows were only typed `transfer_out`
because a separate defect — the dropped counterparty account identifier, two
entries above — had already been fixed. Getting the type *right* did not clear
the alert, because the mapping the alert depended on was right about direction
and wrong about behaviour.

The invariant is the sibling of
[one vocabulary, spelled once per roll-up](#one-vocabulary-spelled-once-per-roll-up):
**when one value is asked two different questions, it answers both by name.**
`isExternalMovement()` now sits beside `direction()`, `externalMovementValuesFor()`
derives the scan set from both, and no "every type facing this direction" helper
is left for a caller to reach for by mistake — the three that had one were all
asking the other question. The predicate is a `match` with no `default` arm, so
a type added later raises rather than inheriting an answer, and `direction()`'s
own `default` arm went with it: under that arm a new type would have joined the
expense side unannounced, exactly the way `transfer_out` was judged as spend.

Nothing caught it because every anomaly test seeded `expense` or `income`. The
corpus seeder cannot even express a transfer — it derives `type` from the
fixture's `direction`, so the two are the same field wearing two names, and no
fixture could have carried the case. The regressions drive `AnomalyEvaluator`
over a transfer large enough to trip each detector in turn, and over a
twenty-three-charge card statement plus the payment that settles it, asserting
in one pass that the settlement raises nothing **and** that the anomalous
charge on that statement still does.

## A period derived from one column and tested on another

`Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` ·
`Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` ·
`Modules/Chains/Database/Migrations/2026_08_30_000001_reopen_every_card_statement_on_the_day_it_starts_billing.php`

A card statement has one period and two candidate days to build it from.
`posted_at` is the day the card was used; `booked_at` is the day the issuer
booked the charge, and ICS books on or after the day of use — a lag of one to
four days on every row of a real statement. `IcsPdfAdapter` derived
`period_start` / `period_end` from the min and max **booked** day.
`IcsSettlementResolver::pullExpenses()` — and its refund-after-close pass, which
spells the same test in raw SQL — decides which charges the period contains by
**posted** day.

Because `min(booked) >= min(posted)` always holds, the period always opened
after the earliest charge it billed. Measured on an iPhone against the committed
`Modules/Chains/tests/fixtures/scenario-1/` card PDF:

```text
scenario-1.md documented period   15 April 2026 → 14 May 2026
card_statements.period_start      2026-04-17
min(posted_at) on the card        2026-04-15
min(booked_at) on the card        2026-04-17

id 1  SPOTIFY AB STOCKHOLM   posted 2026-04-15  booked 2026-04-17   -999
id 2  NETFLIX.COM AMSTERDAM  posted 2026-04-16  booked 2026-04-17  -1599
```

999 + 1599 = 2598, and 2598 is exactly the `unaccounted_delta_minor` the
settlement pass reported. Twenty-one of twenty-three charges were covered, the
delta exceeded the €5/2% tolerance, and the statement stayed `open` where the
fixture documents `settled` with a delta of zero — so the dashboard went on
reporting an overdue settlement for a bill that had been paid in full. Nothing
about that is fixture-specific: **no ICS statement could ever settle**, because
the arithmetic that decides settlement was run over a period that structurally
excludes its own opening days.

The fix is that a period and the membership test that reads it name the same
column, and the column is `posted_at` — the one this codebase already treats as
the transaction's day, per [a list sorted by a column it does not
show](#a-list-sorted-by-a-column-it-does-not-show). Both bounds move, not just
the one that was visibly wrong. Taking `min(posted)` and leaving
`max(booked)` would reproduce the fixture's printed `Periode` line exactly, and
was still the wrong answer twice over: it leaves the two ends drawn from
different columns, which is the defect restated, and `max(booked)` runs a day
past `max(posted)` — so the next statement's earliest charge, posted before its
own booking cutoff, falls inside the previous statement's period and is claimed
by whichever settles first. Bounding both ends on `posted_at` makes consecutive
statements tile the calendar instead of overlapping.

Parsing the period instead of deriving it was never available.
`Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` records that a genuine
Mijn ICS statement prints **no `Periode` field at all**; the earliest and latest
transaction dates are the only positional cues the document offers.

Changing a derivation that feeds a UNIQUE key is a data migration, not an edit.
`card_statements` is keyed `UNIQUE (user_id, account_id, period_start,
period_end)`, and that key is the whole of deciding whether a fresh read of a
statement matches the row already stored for it — so every statement written
before the change would be minted a second time under its new period, with the
stale row left `open` forever beside it. The migration recomputes the pair from
the rows each statement bills, and three things about it are load-bearing:

- **Both tables.** `CardStatementUpserter` copies the pair off
  `statement_summaries` verbatim, and `ResolveChainLinksJob` calls
  `upsertForUser()` over every summary on every pass. A statement repaired
  without its summary has the old period back on the next chain resolution.
- **The shape, not just the day.** The importer writes the day at midnight
  through `CarbonImmutable::toDateTimeString()`. A bare `2026-04-15` would fix
  every screen and still mint the second row, because the UNIQUE compares the
  stored spelling — the same string-comparison trap as [a DATE column carrying a
  time](#a-date-column-carrying-a-time), one layer up.
- **No DDL, and no invented values.** Every write is an independent row update,
  so a failure part-way leaves the statements already repaired repaired and the
  rest as found — SQLite cannot roll a schema change back inside a transaction,
  and the phone runs every migration from scratch with no way to recover a
  half-applied one. A statement whose transactions were since deleted has
  nothing to recompute from and keeps the days it has; both columns are NOT
  NULL, and a range invented to fill them is a worse answer than a stale one.
  A statement already opening on or before its earliest charge is skipped, which
  is also what a second pass meets.

Nothing caught it because the two columns agree in every fixture but one.
`Modules/Ledger/tests/TestCase.php` defaults `booked_at` to
`$postedAt.' 12:00:00'` and every hand-written fixture in the tree follows that
shape, so min and max come out on the same day whichever column is read. Only
the real ICS statement means them differently. The regressions drive the
committed `scenario-1` fixtures through the real import pipeline and assert the
documented contract — 23 charges covered, `unaccounted_delta_minor = 0`,
`state = 'settled'`, no credit carried — plus the migration's own two halves: a
statement stored at the booked-derived period is moved onto the days it bills,
and a second read of that statement then matches it instead of minting another.

## An exception message rendered as if it were copy

A `catch` that assigns `$e->getMessage()` to a rendered property turns whatever
sentence the thrower happened to write into user-facing copy. The thrower is
usually a service with no screen in mind, so the sentence is English in an app
that ships twenty-six languages, and it often names an internal class or an
absolute path as well.

The alias YAML import is the plain case. `AliasYamlImporter::parse()` threw
`InvalidArgumentException('The file is not a valid YAML document.')` and four
siblings; `AliasesSettingsPage::parseUpload()` put the message in
`$importError`, and the Blade printed it in an alert on a screen where every
other string goes through `Lang`. A reader in any of the other twenty-five
languages who uploaded a malformed file was answered in English.

Restoring an encrypted backup was the same shape carrying more. Both restore
screens caught `RuntimeException` and printed the message, so the reader met
`Decryption failed — wrong passphrase or the backup is corrupted.` on the
desktop and `Restore copy failed; the pre-restore snapshot is at …` with the
absolute path on a phone — a developer's diagnostic, in one language, on the
screen a reader reaches once they have already lost their data.

**The rule that separates the two kinds is where the message goes, not how it
reads.** A message that only ever reaches a log, a dev console or an audit row
is allowed to be English and is meant to be: `SafeExceptionContext` and
`MessageNamesNoUserData` exist so a broad catch can record which class failed
without recording what it failed on. A message a reader can see on an ordinary
screen is copy, and copy is translated.

The cost is a second thing for the exception to carry, and the tree already had
the pattern: `ImportFailureReason` and `ReceiptFormatMismatchException` name a
machine-readable **reason** and let the presentation layer pick the line.
`AliasFileRejection` and `RestoreRefusal` are the same shape — one enum case per
distinct piece of advice, `Lang::get` keyed on the case's value — and the
exception keeps its English message for the log, which is where it was right all
along.

Nothing caught it because every one of these is a valid, non-empty string on a
screen that renders it. `TranslationParityArchTest` compares locale files with
each other and never sees a sentence that is in none of them;
`EveryTranslatedLineReachesAReaderArchTest` asks the opposite question about
lines that already exist. A sentence that was never a key is invisible to both,
and so is a `getMessage()` call, which reads like ordinary error handling.

## A qualifier that runs out before the thing it qualifies is unique

`Modules/Ledger/Public/Support/CategoryPathName.php` ·
`Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php`

Two categories in a picker read `Groceries` and `Groceries`, and the fix was to
put the group in front of the leaf: `Frequent › Groceries` against `Groceries`.
That is right, and it stops one class of collision dead. It cannot stop the
next one, because the qualifier is drawn from the same tree as the thing it
qualifies — when the *groups* are named alike, or when both rows are top level,
the qualified path is byte-identical and walking further up adds nothing.

Read off an iPhone, `/cash`, 35 options:

```text
duplicate labels: ["Income", "Income › Salary"]

id | name      | parent  | user_id | name_is_default
 1 | Income    | —       |  NULL   | 1     <- seeded
32 | Income    | —       |   1     | 0     <- migrated
 2 | Salary    | 1       |  NULL   | 1     <- seeded
33 | Salary    | 32      |   1     | 0     <- migrated
```

Nothing about that is an edge case. Every budgeting app ships an Income →
Salary pair, so it is what the *first* import into a fresh install produces, and
the same two identical options are what the reader is asked to file money under.

The cause was a uniqueness check that measured the wrong thing on the wrong
scope. `PromoteStagingToDomain` walked `categories.slug` to uniqueness against
`user_id = <reader>` only — so the imported `income` never met the seeded global
`income`, took the slug unchallenged, and the **name** was never compared at
all. A unique key on a column nobody reads is not uniqueness.

Two rules come out of it, and both are needed:

- **A qualifier is only a fix while it can still differ.** Anything that
  disambiguates by adding context has to say what it does when the context is
  identical too. `CategoryPathName::distinct()` is that answer here: given
  `id => path` it returns labels no two of which are alike, numbering by id
  ascending so the row that was always called this keeps its bare name. It is
  applied wherever a *set* of categories is rendered, and every such site reads
  the reader's whole visible tree — an ordinal counted over a subset is a
  different number from the one the picker beside it shows, which is the same
  defect one screen along.
- **Identity is decided at the write, not at the render.** The promoter now
  matches a staged category against the ones the reader can already see, by
  resolved parent, `kind`, and name — the stored name *or* the reader's
  translation of it — and maps onto the existing row instead of creating a
  second. The line falls at the full path: a leaf sharing only its name with one
  under a different group is genuinely a different category and is still
  created. A leaf at a path that already exists is the category the reader
  already has.

Nothing caught it because the two halves each looked complete.
`TheCategoryPickerTellsTwoSameNamedCategoriesApartTest` and
`TwoEnvelopesWithTheSameLeafNameAreToldApartTest` pin the sibling case with
fixtures whose *parents differ*, so they pass over a tree that also holds a
byte-identical pair; and the migration's own tests assert counts and mappings,
never that two visible categories read differently. The assertion that finds it
is "no two labels in this list are equal", over a fixture where the whole path
repeats.

## A control labelled with a property of the data instead of its own effect

`Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` ·
`Modules/Ledger/Public/Services/TransactionListQuery.php`

The `/transactions` currency toggle read `:code only` — "EUR only" for a euro
reader, "USD only" for a dollar one. It selects neither a currency nor a subset
of rows: `TransactionListQuery::baseQuery()` uses its `$currency` argument to
pick a *projection* — `settled_amount_minor` / `settled_currency` against
`amount_minor` / `currency` — and applies no `where` to either. On an iPhone the
reader chose "EUR only" and the list printed **-$12.99**: a Google Play charge on
an account denominated in USD, settled in USD, and right to be shown in dollars.
The money was correct and the aggregates converting it reconciled to the cent.
The label was the only false thing on the screen.

**A control's label states what the control does, never a property of the data it
cannot guarantee.** "EUR only" is a promise about every row beneath it, and the
control has no power to keep it — one account denominated outside the base
currency falsifies it. "Settled amount" / "Original amount" name the two amounts
the control really chooses between, and no row can contradict either.

Nothing caught it because every fixture was euro end to end. The toggle's own
test asserted that a USD reader saw `USD only` — the label tracking
`base_currency`, which is exactly what a reader-scoped promise looks like while
the ledger holds no row that can break it. The assertion that finds it needs a
base currency and an account currency that *differ*, and it has to read the label
back out of the rendered control rather than out of the lang file.

## Two series named in English, inside a payload no checker opens

`Modules/Forecasting/Internal/Support/ForecastChartView.php` ·
`tests/Contracts/EveryTranslatedLineReachesAReaderArchTest.php`

The forecast chart's ApexCharts options named their two series with PHP string
literals: `'Range'` and `'Point estimate'`. The same options array sets
`legend.show => false`, so those words never appeared in a legend where anyone
would notice them, and `tooltip.shared => true`, so ApexCharts printed both of
them beside every value a reader hovered — in English, in all twenty-six
locales, for as long as `/forecast` has drawn a chart.

The right shape was one file away. `aggregate-line-chart.blade.php` names its
own line `Lang::get('forecasting::forecast.total_balance')`, and that key ships
in every locale. So did the vocabulary: `forecast.confidence_chip_aria` already
reads "projection range is :percent percent of the point estimate", which means
every language had *already* chosen its words for both ideas. Inventing a second
set would have left the tooltip and the aria description disagreeing about the
same two things in the same language, so the new keys take the aria line's
words.

Nothing caught it because **a string handed to a chart is not a line of copy in
any shape a checker recognises.** `TranslationParityArchTest` compares locales
to each other, and a key absent from all twenty-six is in parity by
construction. `EveryTranslatedLineReachesAReaderArchTest` walks the opposite
way — from a declared line to its call site — so a literal that never became a
key has nothing for it to start from. The Blade guards read markup, and this
text is a value in a PHP array that gets JSON-encoded into a `data-options`
attribute long after any of them have looked.

**Text handed to a chart or a JS payload is user-facing and invisible to every
rule that checks copy, so it is where untranslated English survives longest.**
The assertion that finds it has to read the built options back as a non-English
reader and demand *that locale's* words: a test that the series are named, or
that they are named `Range` and `Point estimate`, passes before the fix and
proves nothing.

## A user-facing word written into a column, beside the same word as a key

`Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php` ·
`Modules/Counterparties/Public/Support/CounterpartyDefaultName.php`

The counterparty resolver ended its chain with `$displayName = 'Unknown';` and
stored that word in `counterparties.display_name`. On an iPhone set to Dutch,
`/counterparties` rendered **"Onbekend" four times and "Unknown" once** — the
four from `counterparties::components.type_chip.unknown` and the fifth from the
column, on the counterparty row itself, directly under a correctly translated
"Deze tegenpartij labelen". Same word, same screen, four right and one wrong.

**A user-facing word written into the database is frozen in the language the
writer ran in, and a reader in another language then sees it beside the
correctly translated one.** A key is resolved per reader; a column is resolved
once, by whichever pass happened to write it — an import, a background job, a
peer's device — and never again. The two are not interchangeable, and the
difference only shows on a screen that carries both.

The fix already had a shape in the tree: `categories.name_is_default` marks a
stored name as the app's own so it can be re-resolved for whoever is reading,
and default category names *do* follow the reader on the same device. Counterparties
takes the same idea through `metadata.default_name`, which is where this table
already keeps its row flags. The English stays in the column — it is what the
slug derives from, and it is the fallback for a reader whose locale has no line —
and the flag says whose words they are, so the reader's own name for a row is
never translated out from under them.

Nothing caught it because **translation parity compares locales to each other**:
all twenty-six already carried `unknown`, so the dictionaries were in perfect
parity while the screen was not. The English was not a missing key, it was data,
and no rule that reads keys, blades or lang files can see a column. The
assertion that finds it has to render a stored row as a *non-English* reader; a
test in English passes before and after and proves nothing.

The migration that repairs rows already on disk has the same problem in reverse:
it must tell the app's own placeholder from a counterparty a reader genuinely
named "Unknown". It reads `type` and `slug`, the two plaintext columns on that
table — the slug is derived from the display name, and the triage picker cannot
leave a labelled row at `type='unknown'` — so it never rewrites a name a reader
chose, and it reads the same on an encrypted install as on a bare one.

## A screen that keeps its promise on one of the platforms it ships to

`Modules/EmailScan/tests/Feature/TheInboxScreenNamesTheDeviceThatScansTest.php`,
`Modules/Onboarding/tests/Feature/TheEmailStepsNameTheDeviceThatWatchesTest.php`

Read off a phone, `/inboxes` — a row in that device's own sidebar:

```text
Inboxes
Connect Gmail and Microsoft 365 inboxes so Beatrax can scan them for receipts.

Connect your email
Import receipts from PayPal, ICS Cards, Google Play, and other merchants by
giving Beatrax read-only access to one or more of your inboxes.
```

Every clause is true on a desktop and none of it happens on that device. All
five entries of the inbox pipeline are `Schedule::call()` closures whose
`$event->command` is null, so `SchedulerManifestGenerator::generate()` skips each
one, and `MobileBackgroundSchedule::desktopOnly()` names all five as decisions.
The setup wizard shipped the same promise twice more: "Let Beatrax watch for
purchase emails", and "Connect Gmail or Outlook to capture purchase
confirmations automatically" on the welcome screen a reader sees before anything
else.

This is [a cadence promised on a screen](#a-cadence-promised-on-a-screen-whose-device-never-registered-the-task)
one turn further on, and the turn is what is new. There the honest choices were
to run the task or to withdraw the control, and running it was available.
Here neither is: the fetch needs an OAuth client provisioned through a desktop
browser flow, and no inbox table is registered in `MergeRulesRegistry`, so a
mailbox connected on the desktop never appears in the phone's list at all.
**When a platform can neither do the thing nor be given it, the screen still
owes the reader the name of the platform that can.** The phone copy says inbox
scanning runs in the desktop app and that the receipts it finds arrive over
sync, which is a road the reader can walk — the failure mode this avoids is
[a capability refused instead of supplied](#a-capability-refused-instead-of-supplied-on-the-platform-that-cannot-supply-it).

Two smaller rules came out of the same read, and both generalise past this
screen.

**Say it at the top, not in a field's help text.** The notice sits above the
OAuth banners and above the empty-state hero, because the reader who learns it
under the Connect buttons has already tapped one.

**"Yet" is a promise.** `not scanned yet` is literally true on a phone and
implies a next tick that no schedule will deliver; the phone variant reads
`not scanned on this phone`. The same word appeared in the dashboard's
email-scan health tile, which painted an amber "stale" dot for exactly the state
a phone is permanently in — closed one turn later by
[a warning for a state the device is designed to be in](#a-warning-for-a-state-the-device-is-designed-to-be-in).

Nothing caught it because the whole family renders identically on both
platforms — there is no error, no empty screen and no failing assertion, only a
sentence. A test that renders the component once proves nothing here; the
assertion that finds it drives `NATIVEPHP_PLATFORM` and checks that the desktop
sentence is **absent**, which is why every case in both files above is paired
with its desktop twin.

## A screen that denied the button the next screen is built around

`Modules/Mobile/Resources/lang/en/sync_complete.php` ·
`Modules/Mobile/Internal/Http/Livewire/SyncCompleteScreen.php`

The last screen of phone setup, under the heading "From here on":

```text
It keeps itself up to date
Anything you change on either device shows up on the other. There is no sync
button to press.
```

The next screen the reader reaches is Data & devices, whose sync section is a
button labelled **Sync now** with a line under it reading "Syncing happens when
you tap Sync now. It cannot run in the background — the app lock holds the only
key." Both sentences shipped, one screen apart, and only one of them is true.
`MobileBackgroundSchedule::impossibleOnDevice()` names `mobile.sync-pull` with
the device measurement behind it, and `SyncScreen::syncNow()` is the only caller
of the burst once the initial pull has finished. The button is not a shortcut
past an automatic mechanism; it **is** the mechanism, and the screen before it
told the reader the mechanism did not exist.

The invariant: **where one screen describes what another screen does, the
description reads from the same string the other screen renders.** Prose that
paraphrases a control drifts the moment the control is relabelled, and nothing
fails when it does. The three lines about syncing on the setup screen now carry
a `:action` placeholder, filled once in `SyncCompleteScreen::mount()` from
`mobile::sync.sync_now` — the key that labels the button on the next screen. The
two screens cannot name different things because there is only one name.

A second invariant comes out of the same sweep, and it is separate.
`core::alerts.messages.backup_overdue` told the reader to run
`php artisan db:backup`. **An instruction is only an instruction if the reader
is holding the thing it names.** The desktop is an Electron window and the phone
is a WebView; neither shipped bundle contains a terminal, so in twenty-six
languages the banner spent its only sentence on a step nobody could take. The
half of it that was true — a daily run that really is scheduled — stays, and
what replaced the command is the reason the run can be missed: the app has to be
open when it comes round. Where the honest answer is "nothing for you to do
here", say that; an instruction the reader cannot follow is worse than none.

Nothing caught either one because every test asserted a single surface.
`TheSyncButtonSaysWhatHappenedTest` renders Data & devices and asserts its
background note; nothing rendered the two consecutive screens in one test, and a
per-file locale sweep passes over a file that is internally consistent and wrong.
The assertion that finds it renders both and requires the button's own label in
the other screen's copy, in every language rather than the one somebody reads.
This is the same family as
[a cadence promised on a screen](#a-cadence-promised-on-a-screen-whose-device-never-registered-the-task)
whose device never registered the task, and the four surfaces the sweep found
alongside it — the About-updates body, the shared-list update toggle, the relay
endpoint help and the notification background note — took that page's remedy:
branch on `UserDataPathService::platform()`, never average the two platforms into
one vaguer sentence.

## A confirmation spelled a fourth way

`tests/Contracts/AnIrreversibleActionAsksFirstArchTest.php`

[The convention](which-actions-ask-before-they-act.md) names three shapes a
question may take: the confirm strip, a `wire:confirm`, and a typed phrase or
password. The audit log's Clear all took a fourth —
`x-on:click="if (window.confirm(…)) { $wire.truncateAll() }"` — under a comment
arguing, correctly, that a full `TripleGateModal` was too heavy for a page that
deletes history but cannot corrupt live data. The reasoning about the *strength*
of the gate was sound. What it got wrong was the spelling: it compared against
the heaviest of the three and hand-rolled the lightest, never reaching the
documented middle one that delivers exactly that strength for one attribute.

Nothing caught it because the guard iterated a pinned list of four actions and
asked, of each, whether its file still carried the matching `wire:confirm`.
`truncateAll` was not on the list, and a list only sees what somebody remembered
to add. Worse, the check was blind to spelling by construction: every screen in
the product could have moved to `window.confirm` and the four pinned rows would
still have passed.

The pinned list stays, because whether a write can be taken back is a reading
and no scan performs it. Beside it now sit two rules that need no judgement at
all, and so cover every Blade rather than four: a browser `confirm()` fails
outright, and a `$wire.<method>()` reached from an Alpine handler fails where
the method is pinned or its name opens with a destructive verb. The second is
not a style preference — `wire:confirm` and the strip both hang off a
`wire:click`, so a call Alpine makes itself cannot be gated by either, whatever
else the element carries. Both rules assert a floor on their denominator — 273
Blade templates and 225 Alpine handlers when they were written — before they may
report clean, and both go red on a `purgeArchive` nobody has ever listed.

## A warning for a state the device is designed to be in

`Modules/Ledger/tests/Feature/TheHealthTileDoesNotWarnAboutAScanThePhoneNeverSchedulesTest.php`

The dashboard's email-scan health tile paints an amber dot beside an inbox whose
`last_scan_at` is null or older than 24 hours, and amber there means *stale*:
the figure is behind because a scan was due and did not happen. The rule read

```php
if ($lastScanAt === null || $lastScanAt->getTimestamp() < ($nowEpoch - self::STALE_THRESHOLD_SECONDS)) {
    return 'stale';
}
```

with a comment arguing that a never-scanned inbox "counts as stale alongside a
too-long-ago one: the figure cannot be trusted either way". True on a desktop,
where a mailbox that has never been scanned means something went wrong. On a
phone nothing scans at all — every email-scan schedule entry is a
`Schedule::call()` closure named in `MobileBackgroundSchedule::desktopOnly()`,
so `SchedulerManifestGenerator` drops it and `last_scan_at` stays null for the
life of the connection. The dot was amber permanently, for the ordinary
condition, with nothing on that device able to clear it.

**A warning names a fault. Where the state is the design, there is no fault to
name, and the ladder needs a rung that says so.** `'unscheduled'` is that rung:
`reauth` outranks `stale` outranks `unscheduled` outranks `healthy`, and the
tile draws it in the neutral slate a state nobody has to act on deserves.

The turn that makes it an invariant is that the contradiction was **on the same
screen**. [The screen that keeps its promise on one of the platforms it ships
to](#a-screen-that-keeps-its-promise-on-one-of-the-platforms-it-ships-to) had
just landed copy telling the phone reader that not scanning is expected — "This
phone does not scan mailboxes", and in the tile itself "not scanned on this
phone". A screen that explains a state is normal while its own status
computation flags it as a problem is
[an error naming a cause already ruled out](#an-error-that-names-a-cause-the-code-had-already-ruled-out)
with the two halves rendered a centimetre apart. Copy and computation are one
statement to a reader, and a fix to one is half a fix.

Two things the repair had to get right, both easy to miss:

**Key it off the declaration, not the platform.**
`InboxScanSchedule::runsOnThisDevice()` asks whether `email-scan.incremental` is
still listed in `desktopOnly()`, so a phone that one day gains the scan retires
this by moving that line. A bare `platform() !== null` would have to be
remembered and hunted down instead.

**A status string is a contract, and a new value needs a rank.** Every consumer
was found before the vocabulary widened — the producer, the tile's dot `match`,
and the assertions in two modules' tests — because a value nothing ranks silently
takes the `default` arm of whatever it reaches.

The proof has to be a pair. A test that only shows the phone case going neutral
would also pass if staleness had been deleted outright; the file drives
`NATIVEPHP_PLATFORM` in both directions and pins the desktop stale case, dot and
all, beside every phone case.

## A seam fixes the module that owns the column, not the modules that read it

`Modules/Tax/Internal/Services/TaxYearQuery.php` ·
`Modules/Tax/Public/Services/TaxTagQuery.php` ·
`Modules/Search/Internal/Services/EntityNameSearch.php`

`CounterpartyDefaultName` closed
[a user-facing word written into a column](#a-user-facing-word-written-into-a-column-beside-the-same-word-as-a-key)
and every read site *inside* `Modules/Counterparties` went through it. Three
outside it did not: the tax cockpit row (and the CSV and PDF built from it), the
batch-tag banner, and the ⌘K palette all still selected
`counterparties.display_name` straight. The counterparty screen said "Onbekend"
and the tax export said "Unknown", for the same row, in the same session.

**A read seam is only as wide as the list of call sites that use it, and a
column is readable from any module that can name the table.** The fix is not
finished when the owning module is clean; it is finished when `grep` for the
column across the tree returns only sites that route through the seam. That
grep is the deliverable, not an afterthought — three of the seven call sites
here lived in two other modules.

**Translating a stored word on the way out is half a fix for anything
searchable.** A Dutch reader who reads "Onbekend" on one screen types
"Onbekend" into the palette, and a palette that matches only the stored English
answers nothing — the word the app itself put on the screen is the one word
that cannot find the row. The round trip is the assertion: type the reader's
word, get the row, and get it labelled in the reader's word. Either half alone
passes while the feature is broken.

The predicate question answered itself once the existing read was measured. The
category equivalent needs a SQL predicate (`name_is_default` AND the slugs the
term translates to) because that read is capped at three rows in SQL, so
matching in PHP would mean fetching every category. The counterparty read is the
opposite shape: `display_name` is ciphertext at rest, so there is no SQL name
predicate at all — the reader's own counterparties are already fetched whole and
matched in PHP. Resolving the token in that same pass costs no statement and no
row, and a `metadata->default_name` predicate would have bought nothing, because
SQL still cannot match the name it would have to be ANDed with. **Measure the
read you are about to widen before choosing how to widen it; the shape of the
existing bound decides whether the predicate belongs in SQL or in PHP.**

The proof has to pin the cost, not just the answer, and the two numbers catch
different regressions: rows catch a scope that widened past the reader,
statements catch a per-row lookup. Inverting the fix into a `metadata` read per
row took the measurement from 1 statement / 601 rows to 602 / 1202 while every
behavioural assertion stayed green, which is what a boundedness assertion is
for. One trap in writing it: the statements must be counted *before* the
captured SQL is replayed to count its rows, because the replay runs through the
same listener and otherwise doubles the reading.

## One preference, two surfaces, and only one of them was fixed

`Modules/Core/Resources/lang/en/settings.php` ·
`Modules/Shell/Resources/views/livewire/settings-page.blade.php`

The `/transactions` toggle stopped promising a currency it does not filter by —
`:code only` became "Settled amount". The Settings page writes the *same*
`users.default_currency_view` column from a select two clicks away, and it went
on offering "EUR only" and "Original currency" in all twenty-six languages. For
the reader the two controls are one setting, so the app now stated the choice
two incompatible ways depending on which screen was open, and the screen that
still lied was the one that names the default.

**When one stored value is settable from more than one surface, the wording is
one string per locale that every surface reads — not a copy per surface.** The
Settings keys now resolve to `ledger::list.currency_eur` /
`currency_original` verbatim, which is also what makes the fix durable: the next
edit to either label moves both. Two locales had already drifted before this
change (`hu` and `lt` translated "original" as a property of the currency in
Core and of the amount in Ledger), which is the drift arriving on its own with
nobody editing anything.

The stored value keeps the `eur_only` spelling. It is what the column holds and
what `?currency=` carries, so renaming the enum case to match the new label
would silently reset every reader's saved choice — **display text and persisted
value are renamed independently, and a label change is never a reason to touch
the value.**

Nothing caught it because the surfaces were tested apart.
`TheAmountToggleLabelCannotPromiseACurrencyTest` reads `ledger::list.*` in every
locale and passes over a Settings page saying the opposite; the settings tests
assert the preference *saves*, which the wrong label does perfectly well. An
English-only assertion proves nothing here either — `:code only` and "Settled
amount" are both English. The assertion that finds it compares the two surfaces'
rendered strings to each other, per locale, and fails on the pair that differs.

## A question that asks for a mood instead of naming what it takes

`Modules/Ledger/tests/Feature/TheDeleteQuestionNamesWhatItTakesTest.php`

[The convention](which-actions-ask-before-they-act.md) forbids one sentence by
name: a write that cannot be made reversible has to say what will happen and
what is lost, *never "Are you sure?"*. The transaction detail page asked exactly
that over a delete that cascades — the row, its split legs, its tax tag and the
plaintext search shadow of its description, with the transfer partner on the
other side retyped on the way out — and it asked it in all 26 locales, each one
a faithful translation of the one sentence the convention rules out.

Nothing caught it because nothing reads copy. The two rules beside the pinned
list decide the *shape* of a confirmation, and this confirmation is the right
shape: a `wire:click` behind an Alpine flag, cancel first and confirm last,
reordered by an earlier pass. Translation parity is no help either — it compares
the locales to each other, so a phrase that is wrong in every one of them is in
perfect parity, the blind spot
[a translation that is present is not a translation](#a-translation-that-is-present-is-not-a-translation)
names from the other direction.

**A question the reader answers from memory is not a question.** The repair had
to add what the reader could not otherwise know rather than repeat the paragraph
directly above the button, which already says the delete is permanent and cannot
be undone: the question now names the note, the split and the tax tags that go
with the row. Every locale takes its nouns for those from its own
`unreconcile.help`, the sentence one section up that lists the same things
staying put, so no locale invents a register the page does not already use.

The guard is a reading of vocabulary, not a blacklist of phrasings: the question
must share a word stem with its own heading, and at least two with the inventory
beside it. A blacklist of the 26 bare questions would pass the moment a 27th
mood was invented, and a test that only asserted *some* prompt renders would
have passed before the change and proved nothing.

One thing the copy change dragged in with it: a sentence longer than a mood, in
a hand-rolled strip, meets the coarse-pointer 44px floor that replaces
`min-width: auto` and squeezes a shrinkable answer to 44px with its label broken
one word per line. The two classes `x-core::confirm-strip` took for that —
`flex-wrap` on the row, `shrink-0` on both answers — are on this strip now, and
the same test file holds them.

## A guard that lists element names misses the one nobody listed

`tests/Contracts/PhoneUiConstraintsArchTest.php`

The coarse-pointer floor in `resources/css/app.css` names `button`, `summary`,
`[role='button']` and the three input types, and the guard over it read the
markup with `/<(button|summary)\b([^>]*)>/`. Both lists are the same list, and
neither has `a` in it. Measured in the WebView on `/chains`, a link to a
transaction detail was 80x17 with `getComputedStyle(el, '::after').content`
answering `"none"` — no halo at all, against a 44px floor. Three of its four
siblings in the same file carried `.tap-link` and it did not.

Two separate blind spots put it there. The first is the enumeration: an `<a>`
that is a destination is an action, and the guard could not see it at all, so
144 links across 273 views were never read. The second is inside the pattern —
`[^>]*` ends a tag at the first `>` it meets, and a Blade attribute is full of
them (`['id' => $x]` in an href, `$attributes->merge(...)` in a component).
113 of the 424 button and summary tags in the tree were being read half-way,
class attribute included, by the guard that claimed to check them.

The invariant: **an action drawn as a link is still an action, and a guard that
enumerates element names will miss the one element nobody listed.** The rule now
reads links too, and states what it claims rather than what it excuses: a link
whose whole height is one line of text must carry a reach. It excuses a link
only for a reason it can compute — a class app.css itself sizes at or past 44px,
padding arithmetic that puts one text line there, a block the link wraps, a
table cell (app.css gives `td > a:only-child` a halo and its cell the height),
or a sentence the link is set inside, which the floor and the guideline both
exempt. It prints its denominator on every run, so a walk that stopped early
cannot read as a clean tree.

The other half is that a halo is not free. Two 44px bands centred 20px apart do
not both fit, and the later one in the markup wins the tap: the same `/chains`
card had already lost the counterparty name to the date's band once, which is
why the date was left bare. 62px of header cannot hold two bands, so the pitch
between the two lines is what gives — the same remedy `.chain-leg`, the wrapped
filter chips and `td:has(> a:only-child)` already take. **Before adding a halo,
find what is stacked within 44px of it.**

## Seeded copy freezes the language the seeder was written in

`Modules/Forecasting/Database/Seeders/Demo/DemoForecastSeeder.php` ·
`Modules/Core/Public/Support/DemoNames.php`

The demo forecast seeder wrote `Base Case`, `What-If: Summer holiday`, both
scenario descriptions and the what-if mutation's note into the database as
column values. A Dutch reader on a fresh install got Dutch goals, Dutch pots,
Dutch saved reports and Dutch counterparties — and two English scenarios, with
an English sentence under each in the scenario editor sidebar. **A string that
is written into a row is translated at seed time or it is never translated at
all**, because nothing re-reads a stored value through the translator.

The interesting part is which remedy it does *not* need. Two seams already
exist for this class: `CounterpartyDefaultName` with a provenance mark in
existing JSON, and `CategoryDisplayName` with `categories.name_is_default`.
Both re-render at read time, and both exist because user-authored rows and
default rows share one table, so the reader's row has to say which kind it is.
Demo rows carry no such ambiguity — they are regenerated by re-seeding, never
migrated, and a demo user who renames a scenario has simply taken ownership of
it. **The remedy is sized to the ambiguity, not to the symptom**: no column, no
provenance mark, just `Lang::get()` at seed time and `DemoNames::everyRendering()`
for the dedupe, exactly as the goals, pots and saved-report seeders already do.

Two consequences of that choice are deliberate. A re-seed under a second
language leaves the first language's rows alone rather than renaming them,
because the row may have been renamed by the reader and re-seeding is not a
request to overwrite that. And the English strings stay byte-identical to the
literals they replaced — `everyRendering()` matches an already-seeded install
only if the old literal is still one of the twenty-six renderings, so
*re-wording the English is what duplicates the row*, not translating it.

Nothing caught it because `DemoDataSpeaksTheInterfaceLanguageTest` asserted
goals, pots, reports and counterparties and stopped there: a per-seeder test
passes over every seeder it does not name. An English-only assertion is no help
either — the seeded literal and the correct translation are the same string in
English. The assertion has to seed under a non-English locale and read the
column back.

## A label the eye reads, declared hidden because a screen reader skips it

`Modules/Notifications/Public/NotificationCopy.php` ·
`Modules/Notifications/Resources/views/livewire/partials/notification-row.blade.php`

`NotificationCopy` held a table of eleven English words — `Import`, `Receipt`,
`Cash`, `Migration`, `Drift`, `Shortfall`, `Reminder`, `Digest`, `Budget`,
`Savings`, `Statement` — and `NotificationQuery` handed each one to the row
partial as `typeWord`. On an iPhone set to Dutch, `document.documentElement.lang`
was `nl` and every title was correctly translated, and every chip beside those
titles was English: **`€ Cash` next to "Kasboek bijgewerkt", `✉️ Receipt` next to
"Nieuwe bonnen gevonden", `⇥ Migration` next to "Migratie voltooid"**. Eleven
words, one per row, each sitting beside its own translation.

The chip carries `aria-hidden="true"`, and that is what let the words sit there.

**`aria-hidden` hides an element from a screen reader, not from eyes. Anything
inside one is still user-facing text and still needs a translation.** The
attribute is a statement about redundancy for assistive technology — the chip
repeats the trigger the title already names — and says nothing at all about
whether a sighted reader can read it. A literal behind `aria-hidden` is exactly
as visible as a literal without it.

The table now holds a glyph and a **lang key**, and `typeChip()` resolves the
word through `Lang::get()` on every call. Per call, not per process: a word
resolved once and kept would be right for whoever read first and wrong for
everyone after — the same trap [a stored user-facing
word](#a-user-facing-word-written-into-a-column-beside-the-same-word-as-a-key)
falls into, one layer up. The glyphs are untouched, including the invisible
U+FE0F the envelope ends in ([presentation
selector](emoji-presentation-selector.md)).

The same row carried a second, worse copy of the defect. `dead_link` read
`This :kind no longer exists.` / `Deze :kind bestaat niet meer.`, and
`DeepLinkResolver` filled `:kind` with `series`, `budget`, `counterparty`,
`transaction` or `item` — app-invented English **interpolated into a translated
sentence**. Translating the noun alone does not fix it: nine locales inflect the
demonstrative to agree with it, so Dutch would have read "Deze budget", and the
eighteen that had already worked around the problem with an appositive ("Dieses
Element (:kind)") would have rendered "Dieses Element (Element)" for the neutral
kind. So `dead_link` became five whole sentences keyed by kind, each written in
the locale's own grammar. **A noun substituted into a sentence has to agree with
it; where the agreement cannot be guaranteed, the unit of translation is the
sentence, not the word.**

`DeepLinkResolver::renderedKind()` is what keeps that safe: the blade builds a
translation key on the kind, so a `target_kind` a newer release writes has to be
folded to `item` before it gets there, or the reader sees a raw key.

Nothing caught it because every rule in reach reads keys, and there was no key.
Translation parity compares locales to each other and cannot see a literal in a
PHP const; `EveryTranslatedLineReachesAReader` asks whether a declared line is
rendered, not whether a rendered word is declared. The existing chip tests
asserted `typeWord` was `'Cash'` and not `'Import'` — true before and after,
because they read in English. The assertion that finds it has to render as a
**non-English** reader and cover every arm: nine of the eleven Dutch chips differ
from English, but `Import` and `Budget` are Dutch loanwords, so a rule that
"no chip equals its English word" is only answerable in a language that
translates all eleven.

## A second writer of a canonical row, and the word it invented on the way

`Modules/CashBook/Internal/Actions/RecordManualTransaction.php` ·
`Modules/CashBook/Internal/Services/ManualEntryAnchors.php`

Typing a cash entry on a phone — amount `12.34`, counterparty
`Cash Test Merchant` — wrote a transaction whose `counterparty_name` held the
typed name and whose `counterparty_id` was `NULL`. The ledger row and the cash
book's own list both printed the name; `/reports` grouped by counterparty filed
the money under "No counterparty", because `CounterpartySpendQuery` groups on
`counterparty_id` and nothing else. Every imported row carried one, because
`ImportPipeline` runs `ResolvesCounterparties`; this path built its
`CanonicalTransaction` by hand and ran none of it.

**A second writer of a canonical row owes the row every enrichment seam the
first writer runs, or it is writing a row analysis cannot see.** The DTO is not
the contract — the stages between the DTO and the recorder are, and a hand-built
row satisfies the type while failing the contract. `PromoteStagingToDomain` had
already learned this and calls the same stage; the cash book was the third
writer and the one that had not. The tell is cheap to look for: a column every
other producer fills and this one leaves null.

The same action also wrote the English word `Cash` twice — into `accounts.name`
for the account it mints, and into `counterparty_name` when the reader named
nobody. The second one is not a translation problem, it is an invention: an
entry the reader named no counterparty on **has** no counterparty, and a
stand-in name would have minted a counterparty row that triage and merchant
matching then treat as somewhere the reader shops. It is now `null`, and the
list draws the same em dash the ledger already draws.

The account name could not take either seam this repository already ships.
`CounterpartyDefaultName` and `CategoryDisplayName` both resolve at read time,
and both work because one module renders the column. `accounts.name` is read
straight off the row by a dozen modules, so a read seam would have had to reach
all of them, and the flag it needs would have had to be registered in the sync
merge rules to survive a peer. **Where the reader's word cannot be resolved on
the way out, it is kept current on the way in, by the module that owns the
row** — the cash book rewrites the name on every entry it records, from the line
the app already ships for this money on the payment-type chip its own rows
carry, so no new register and no twenty-sixth translation was opened.

That rewrite needs to know the name is the app's own and not a person's, and
`accounts` has no rename UI, no metadata column and no spare flag. The evidence
is the synthetic IBAN the action itself writes — `CASH` followed by the
zero-padded reader id — which no other writer produces: an account a reader
called "Cash" was named in the import wizard and carries the statement's own
IBAN, and the demo cash wallet carries the demo's. **Provenance can be a value
the app alone writes, and a mechanism that needs no column cannot desynchronise
from one.** The slug moved off the name for the same reason it stays `cash` in
every language: nothing looks an account up by it, and re-slugging on every
language change would churn `unique(user_id, slug)` for no reader.

Nothing caught either. A test asserting the entry was written passed both
before and after, because the row was always written — the missing column is
what had to be asserted, and the report's own `GROUP BY counterparty_id` is
what makes it visible. And a test that adds an entry in English proves nothing
about the account name at all: the stored literal and the correct answer are
the same string in English, so the assertion has to record as a non-English
reader, and then change language and record again.

## Two lines on one screen, each ordering the road the other ruled out

`Modules/Mobile/Resources/views/livewire/mobile-pairing-scan.blade.php` ·
`Modules/Mobile/Internal/Http/Livewire/Concerns/ChoosesCodeEntryArm.php`

The mobile pairing screen keeps two slots on its typed-code step deliberately
apart, so a code error is never overwritten by the amber camera notice. On an
iPhone whose camera permission is denied both fill, and their copy disagrees:

```text
Camera access is off. Enter the code from the other device instead.
…
Nothing on this network answered that code. Searching the network for the other
device does not work on iPhone yet, so scan its code with the camera instead.
```

Each sentence is true alone. Together they are a loop, and there is no third
affordance on the blade to escape into — the relay arm is reachable only from a
scanned QR, which is the road the first line has just ruled out. Every line
passed review because every line was reviewed alone.

The two were chosen from signals neither one shared. The notice branched on
whether the camera was usable; the error branched on
`PairingGateway::lanDiscoveryReach()->silenceMeansNoPeers()`. Neither knew what
the other was about to print. The fix is not extra copy for the collision but
one resolver per slot reading **both** signals —
`ChoosesCodeEntryArm::entryArmNotice()` and
`AcceptsPairingCode::nothingAnsweredKey()` — each carrying a line for the case
where both roads are shut that names the one thing which reopens either.

The invariant: **an instruction is only valid against the whole screen.** Where
two slots can both be filled, the state that fills them is one state and each
has to be resolved from all of it. A slot reading half the state is right only
by accident.

The second half of the same defect is cheaper to state.
`MulticastMdnsQuery::runtimeReach()` is a platform check and a config read — no
socket, no network — so the verdict was available before the screen was drawn,
and the screen still rendered a bare thirty-two-character input and waited for a
submit to admit there was nothing to find. **A dead end known at render time is
disclosed at render time.** Making the reader do the work first buys nothing and
spends their minute.

Nothing caught it because `PairingManualCodeArmTest` asserted each message in
isolation, never rendered the two together, and read no HTML from the step at
all.

## A cadence promised on a screen whose device never registered the task

`Modules/Receipts/tests/Feature/TheDropFolderScanThePhoneWasPromisedTest.php`,
`Modules/Core/tests/Feature/TheDropFolderCopyPromisesOnlyTheCadenceTheDeviceKeepsTest.php`

Read verbatim off an iPhone's Data & devices screen:

```text
Auto-import from drop folder — When on, Beatrax scans
storage/app/inbox-drop/1/ every 5 minutes for .eml and .mbox files and
imports them through the same matcher pipeline as the wizard.
```

The toggle beside it was enabled. Nothing on the phone was going to scan
anything. `receipts.scan-drop-folder` was a `Schedule::call()` closure, so
`$event->command` was null; `SchedulerManifestGenerator::generate()` reads
exactly that property and `continue`s past the entry, and
`ScanInboxDropFolderJob` had one dispatcher in the whole tree — that closure.
A reader could switch the setting on, drop files where the sentence told them
to, and wait forever. No error, no log line a reader sees, and the switch stayed
green.

This is the same family as the twenty tasks the phone silently dropped, seen
from the other end —
[the runner takes an artisan name](../features/mobile/architecture.md#the-phone-runs-an-artisan-name-on-an-interval-and-nothing-else)
and nothing else. That round fixed the tasks a phone *must* run and wrote
`MobileBackgroundSchedule::desktopOnly()` to state the ones it deliberately does
not. Nothing asked the reciprocal question: **the UI still offers this — does
the device run it?** `receipts.scan-drop-folder` sat in `desktopOnly()` with a
sound-looking reason while the screen that turns it on shipped to phones
unchanged, which is how a stated decision and a live control disagreed for a
release.

Two invariants come out of it, and they are separate.

**A phone cannot run a scheduled closure, so any copy asserting an automatic
cadence has to be checked against what the device's scheduler actually
accepts.** Three filters stand between a schedule entry and a device: the entry
must be a `Schedule::command()` with an artisan name, its expression must be one
of `MobileBackgroundSchedule::RUNNER_INTERVALS`, and anything faster than
fifteen minutes is clamped up to fifteen. Past all three, iOS BGTaskScheduler is
still opportunistic — a registered interval is a floor, never a promise. A
sentence naming a period is therefore a claim about all four, and a sentence
naming a wall-clock hour cannot be true on a device at all.

**Where a claim is true on one platform and false on another, the copy branches
on the platform, it does not average the two.** Five minutes is what the desktop
scheduler really does, and deleting it to make one sentence fit both would cost
the desktop reader a fact that holds. `AutoImportSettingsSection` reads
`UserDataPathService::platform()` once in `mount()` and the blade picks
`auto_import.active_phone_html` / `auto_import.inactive_phone_html` over their
desktop twins — the same seam `MulticastMdnsQuery::runtimeReach()` uses to
decide what iOS can be told about LAN discovery.

Nothing caught it because every test in reach asserted presence, not
reachability. `AutoImportSettingsSectionTest` asserted the string
`every 5 minutes` rendered, which was true on both platforms and meaningful on
one. `TheBackgroundManifestCarriesEveryTaskThePhoneMustRunTest` asserted that
every scheduled entry is *declared* — as phone work or as a desktop-only
decision — and `receipts.scan-drop-folder` was declared, so it passed. A test
that asserts an entry is registered would have passed before this fix and proves
nothing; the property the device reads is `$event->command`, and that is the one
to assert.

The same sweep turned up `settings.exchange_rates.next_refresh`, which read
`Next auto-refresh: daily at 09:00` in all twenty-six languages. `fx.daily-refresh`
had already moved to `->daily()` when the runner turned out to have no wall
clock; the copy was never followed. That one was false on the desktop too, which
is the ordinary way this defect arrives: **a schedule changes and the sentence
describing it is not part of the change.**

## Two aggregations in one directory, one translated and one not

`Modules/Reports/Internal/Aggregation/CounterpartySpendQuery.php`

A cash entry typed on a phone set to Dutch listed on `/reports` under
`No counterparty  €12.34` — an English label beside an amount formatted in
Dutch. Two lines of that file carried user-facing English as literals: the
label for the `NULL` counterparty bucket, and the fallback for an id
`CounterpartyProfileQuery::identitiesForIds()` came back without. Its sibling
`CategorySpendQuery`, in the same directory and built the same way, had already
routed both of its equivalents through `Lang` — and had already learned that
they are two different facts, so a row with no counterparty and a row this
device cannot name never collapse into one word.

**Where two files in one directory do the same job, the one that was written
second is the one to read for what the first forgot.** The pair is the evidence:
one translated label and one literal, side by side, is an oversight and not a
decision, and the sibling also settles what the key should say — the counterparty
lines are the category lines' sentence with the noun swapped, in all twenty-six.

Nothing caught it because every rule in reach reads keys. Translation parity
compares locales to each other and cannot see a literal in a PHP expression;
`EveryTranslatedLineReachesAReader` asks whether a declared line renders, not
whether a rendered word is declared. `CounterpartySpendQueryTest` asserted
`groupLabel` was `'No counterparty'` — true before and after, because it read
in English.

**A missing locale line renders exactly what the literal did, so the assertion
that finds either one has to hold the reading against the English line, not
against its own key.** Laravel falls back to the fallback locale, so a test that
reads as a Dutch reader and asserts `groupLabel === Lang::get($key)` passes with
English on screen when the Dutch line is absent: both sides resolve to the
English. Deleting the two Dutch lines took only one of three tests red until
each compared the Dutch reading to the same key resolved under `en`.

## Money that left its seam

`tests/Contracts/TheFourAmountColumnsMoveAsASetArchTest.php`
`tests/Contracts/AMoneyAggregateNamesTheCurrencyItCountsArchTest.php`
`tests/Contracts/AMoneyShareIsCutByTheAllocatorArchTest.php`

Four failures in three modules turned out to be one shape: money mutated,
aggregated or divided without going through the seam that already existed for
it. None of the four raised anything. Each produced a number that renders,
sums and exports like every other number on the page.

**A transaction's amount is four columns and one fact.** `amount_minor` and
`currency` are the native pair the fingerprint is composed over;
`settled_amount_minor` and `settled_currency` are the pair every balance,
budget, forecast and report sums; `fx_rate_used` is the ratio between them.
`Modules/Ledger/Public/ValueObjects/TransactionAmount` relates the four and
gives the pair one sign. Two writers had left it:

- `EntityChangeApplier::applyTransactionAmount()` wrote the native leg alone.
  A migration reconciliation corrected a transaction from −125000 to −126000,
  `settled_amount_minor` stayed at −125000, and **the account balance moved by
  zero** — the correction reached nothing that sums the settled leg. The detail
  page then read "Native −€1,260.00" above "Settled −€1,250.00", one currency,
  no rate row.
- `CanonicalTransaction::toAttributes()` — the payload *every* insert into
  `transactions` is made from — emitted the four straight from its constructor
  arguments. `PromoteStagingToDomain` hands it a native pair, a settled pair and
  a hardcoded `fxRateUsed: null`, so a converted staged row promoted as a
  −$30.00 expense whose settled leg was **+€27.23**: income to every surface
  that sums it, under no rate at all.

The fix is that `toAttributes()` calls `TransactionAmount::relate()`, so the
invariant holds for whatever the DTO was handed rather than for whoever
remembered. `CanonicalTransaction` is normalised on the way out, not on the way
in, because the fingerprint is composed over the *native* leg only —
`relate()` never moves it, so the columns cannot drift from the dedup key that
was computed from them.

**A money aggregate answers for exactly one currency.** 2 700 euro-cents and
2 700 000 yen are integers of the same order and different money.
The pots reconciliation summed `pot_movements.amount_minor` across every pot on
an account with no currency predicate at all, while the `real` half of the same
line was scoped one currency at a time through `AccountBalance::in()`.
`accounts.default_currency` is mutable and `pots.currency` is frozen at
creation, so relabelling an account EUR→JPY left it genuinely holding pots in a
currency it no longer reports: the header read **allocated ¥270.000** for pots
holding EUR 2.700,00, and the ceiling every fund was weighed against became
¥15.000 where the euro line had EUR 105.714 left. Both halves are now
`AccountBalance` — per-currency lines picked with `in()` — and the reconciliation
answers one row per currency the account denominates or holds pots in.

**A whole cut into parts is cut once, and the remainder is handed back.**
`TaxYearQuery` derived each split leg's native share by rounding it on its own,
so three legs of a $30.00 charge printed $10.00 + $10.00 + $9.99 in the column
an accountant sums, on `/tax` and in `TaxCsvExporter`. A 200 000-case fuzz
drifted on 24.7% of random splits. `CrossCurrencyTotal::apportion()` is the
counterpart to `distribute()`: `distribute()` converts a whole derived from the
parts, `apportion()` splits a whole the record already carries, and both spread
what the rounding lost back over the same set, largest magnitude first with ties
broken by position.

Nothing caught any of them because each is arithmetic that succeeds. The
guards therefore read the tree rather than the behaviour: no array literal
outside the seam may name two or more of the four amount columns; every `SUM`
must sit in a function that names a currency column; and integer-truncating
arithmetic on a minor-unit figure belongs to the allocator. Each carries an
explicit exemption list where every entry states why that site answers for one
currency, or is not a share — and a pattern re-checked against the code, so an
exemption that stops being true fails rather than waving the site on.

## A window recomputed instead of derived

Two windows that must agree were each computed from their own rule. One draws,
another supplies the data, a third tests emptiness — and every one of them was
correct in isolation, so nothing failed. They part at the edges, and the edge
is where the reader is standing.

It has now recurred in eight places, and a previous fix in this same family
closed one door and left the next one open: `hasProjectableEntries()` was
aligned to the *month* the calendar's nav ceiling stops at, and went on
disagreeing about how far *into* that month the grid runs.

- **The calendar's reach against the projection behind it.** `HORIZON_MONTHS`
  counted twelve months off the first of the month and then extended to a whole
  Mon–Sun strip, while the balance line is supplied by `ForecastHorizon::OneYear`
  — 365 days. Swept over 365 today-values, the grid ended past the last forecast
  point on **364 of them**, worst case 37 cells. Those cells have no bucket, fall
  to `isComputing: true`, render `—` and hold the aria-live strip on "Projection
  updating…" with nothing in flight — which is the very thing `ForecastQuery`
  refuses to claim for a device that never computed a run.
- **The calendar's empty state against the grid it describes.**
  `hasProjectableEntries()` bounded at `endOfMonth()`; the grid draws to
  `endOfWeek()`. On **304 of 365** today-values 1–6 lead-out cells were invisible
  to it, so with one booked row on 2027-09-03 the grid drew the charge under a
  banner reading "No upcoming payments".
- **"This year" on two pages.** `/transactions` answered 2026-01-01 … 2026-12-31
  and `/reports`, under a heading reading "2026", answered 2026-01-01 … today.
  One booked-ahead expense: **157 rows / −1444082 against 156 / −1431582**. The
  reports side was documented, and its premise — *"a future date never carries
  transactions"* — is false in a codebase that ships `BookedFutureRowQuery`.
- **"This month" on the same two pages**, which is what the `this_year` fix left
  behind. A calendar month on one and the reader's `period_start_day` window on
  the other: on start day 25 they overlap by 7 days and disagree by 24 at each
  end, while a report row's drill-through hands its own bounds into that list.
- **The dashboard's two due-lists.** The fixed-payments card filtered "This
  month" by calendar month; the position summary's "upcoming" list directly
  above it used the reader's period. Same screen, same question, 24 days apart.
- **A counterparty's 12-month total against the twelve bars under it.** The
  total took a rolling year and the sparkline took twelve calendar months, so
  spend inside the headline figure — and inside the per-month average it is
  divided by — had no bar to appear in. Worst on the 1st of a month: a whole
  month of it.
- **A comparison offered against data that does not exist.** The spending-trend
  card took its previous period from `PeriodQuery::previous()` unconditionally
  and asked nothing of the ledger, while `SpendTrend::hasComparison()` answered
  yes as soon as the *current* total was non-zero. A reader whose earliest row
  was four days old saw "vs July 2026", their whole EUR 250,00 as **+EUR
  250,00**, and every category marked risen — in the rose colour that means
  "worth noticing" — against a month the ledger never covered. This is the
  calendar defect one layer up: a window reaching past the data behind it, with
  the gap presented as content. The rule is the ledger's reach, deliberately
  **not** "the previous period has no rows": a month a reader genuinely spent
  nothing in, and a gap between two months of records, are both real
  comparisons and both keep drawing.
- **The demo dataset.** The budgets grid is drawn over `PeriodQuery` windows and
  the ledger rows were seeded over calendar months. For the persona shipping
  `period_start_day = 25` the page opened on **EUR 2,490.00 assigned against EUR
  0.00 spent**, with 7 rows worth EUR 1,092.03 — a third of that persona's spend,
  including a EUR 895 rent — in a period `prevPeriod()` refuses to open. The test
  that guards this grid loaded only the persona whose period starts on the 1st,
  for whom the two rules coincide exactly.

The rule is that **one window is the definition and the other derives from it**.
Recomputing the dependent side to a value that happens to match today is what
left the second instance open after the first was fixed, and it is not a fix.
Each pair now names its authority: `ForecastHorizon` over the calendar's reach
(`CalendarMonthWindow::PROJECTION`, whose ceiling is walked forward only while
the *whole* next strip still lands inside the projection); `CalendarMonthWindow`
over both the grid and the empty state; `CalendarSpan` over both spellings of a
calendar year; `PeriodQuery` over both spellings of a month; `SeriesDueWindow`
over both dashboard lists; `RollingTwelveMonths` over the total, the average,
the bars and the profile breakdown; `DemoPeriodWindow` over all three demo
seeders that write into the grid's span;
and `WeekStart` over the calendar strip, its column headings and the date
picker's own grid. The trend card's authority is the ledger itself, asked
through `PopulatedPeriodQuery::reachesBackInto()`, and its comparison figures —
the "vs" label, the signed total and the per-category deltas — are held behind
`SpendTrend::hasComparison()` while the card still draws the notice naming a
currency left out of the total.

The guard cannot be a pinned date, because the size of every one of these
defects swings with the day of the month — the calendar pair is wrong on 364
days of 365 and *right* on one. `APairOfWindowsThatMustAgreeHasOneDefinitionArchTest`
therefore sweeps a full year of today-values per pair, including the 1st of a
month, a 31st, and a 29 February, and reports the days that disagreed rather
than the first one. Beside it a source walk holds the definitions to one site
each: `startOfYear()`/`endOfYear()` may be spelled only in `CalendarSpan`,
`startOfWeek()`/`endOfWeek()` only in `WeekStart`, and a forward horizon may not
be counted in whole months anywhere. Every exemption is pinned with the reason
that site answers a different question, and a stale exemption fails rather than
waving the site on.

## A one-directional figure ranked on a signed sum

`tests/Contracts/AOneDirectionalFigureIsNarrowedBeforeItIsSharedArchTest.php`
`Modules/Shell/tests/Feature/ARefundedCategoryIsNotRankedAsSpendingTest.php`

Spend is signed on purpose. A refund reverses an expense, so `MoneyFlow::Spend`
counts it beside the expense with the sign it already carries and
`SpendByCategoryQuery` applies no sign filter of its own — that is exactly what
makes a refund reduce spend rather than add to income. A category's net for a
period can therefore come out **below zero**, and everything downstream has to
have decided what that means.

`TopCategoriesByPeriodQuery` had not decided once; it had decided three times,
and each answer contradicted the other two. With Groceries −EUR 80.00,
Electronics −EUR 50.00 and an Electronics refund of +EUR 400.00 in one period:

- **The cutoff.** `if ($total <= 0) { return []; }` over the signed sum of the
  top five. The card printed "No categorized expenses yet." while the recent-
  transactions list immediately below it showed all three categorised rows.
- **The share.** `percentageOfTotal: $spendMinor / $total` on the same signed
  sum. With a EUR 125.00 refund instead, the denominator came to EUR 5.00 and
  Groceries' EUR 80.00 was **1600%** of it. The view's
  `max(2, min(100, $rawPct))` turned that into a full bar and
  `aria-valuenow="100"`, so a screen reader was told a category worth EUR 80.00
  of EUR 130.00 gross spend was the whole of it.
- **The ranking.** `arsort()` over the signed map, so a category whose refunds
  outran its spending was still ranked *as spending*: `Electronics −EUR 75.00`
  under `aria-valuenow="2"`, because `max(2, min(100, -1500))` is 2.

The clamp is what hid all three. Every one of those numbers is arithmetic that
succeeds, renders and exports like any other, and the only thing standing
between the reader and it was a `max()` in a template.

**The narrowing is the donut's.** `ChartAmount::positionsTowards()` already
answers this question for a report ring: a ring is built from sizes, so it draws
only the rows moving the way the total does, and `ChartSeries` carries the rest
out as `undrawnMinor` for the page to name through
`reports::builder.chart.undrawn`. Ring plus disclosure reconciles to the
headline. `Modules/Ledger/Public/Support/OutwardSpend` is that same rule for a
spend map: it keeps the keys running outward, ranks and limits those, sums those
for the whole, and hands back what it left as `inwardMinor` so the card says
"Not ranked — :amount came back" instead of losing it. The cutoff, the ordering,
the limit, the share and the empty state are then one decision rather than five.

`OutwardSpend::share()` refuses both ends — a part running the other way is not
a fraction of this whole, and a whole at or below nought has no parts — so the
same definition also answers the two fractions that had guarded only their
denominator: `EnvelopeProgressQuery` (a net-refunded envelope read as a negative
`fractionUsed`) and `GoalProgressQuery` (a net withdrawal read as a negative
`fractionComplete`). `SpendTrend::hasComparison()` tested `> 0` on the same
signed totals and hid the whole trend card for a refund-dominant period; it now
tests `!== 0`, which is the choice `ThisPeriodAtAGlanceQuery` already made with
`havingRaw(... <> 0)`.

**A bar announces what it draws.** `x-core::progress-bar` clamped the fill into
its track and printed `aria-valuenow="{{ $value }}"` raw beside it, so an
out-of-range value announced a number the bar contradicted and that its own
`aria-valuemin`/`aria-valuemax` ruled out. Both now come from one clamped local.
The sliver rule moved off the call site and onto `TopCategoryRow::barWidth()`,
where `GoalProgressRow` already keeps its own — a row answers for its bar,
because a template that computes one is a template that can clamp away the
arithmetic behind it.

The guards are therefore two. A source walk holds every money-by-money share to
the seam, and holds every bar value in every template to something the template
did not compute itself; each exemption states why that site is not a share of a
one-directional whole, and carries a pattern re-checked against the file so a
stale exemption fails rather than waving the site on. Beside it a rendered guard
asserts the three invariants against real markup: the card never says "no
categorized expenses" while categorised expenses are on screen, no bar announces
a value the figure beside it contradicts, and no negative figure is ranked as
spending.

## English written into a column a screen reads back

`Modules/Core/Public/Support/StoredCopy.php` ·
`Modules/Migration/Internal/Pipeline/UnmappedItemReporter.php` ·
`Modules/Pots/Public/Services/PotWriter.php` ·
`tests/Contracts/AColumnAScreenReadsBackHoldsNoSentenceArchTest.php`

The migration preview stored its own sentences. `display_label` held
`'Goal: '.$name` and `sprintf('Transaction: %s · %s · %s', …)`; `reason` held
`$count.' budget rows were not imported: your budgets are kept in '.$currency`
and nine more like it. A pot archived with a balance wrote the memo
`'Released on archive'`. A savings prompt resolved its sentence inside an hourly
job and stored the result. Every one of them reached a screen unchanged.

**A column is resolved once, by whoever wrote the row. A key is resolved on
every read, by whoever is looking.** The two are not interchangeable, and the
difference only shows to a reader in another language — which is why it survived
26 locales, a parity test and a call-site test. There is no lang file to compare
against, because there is no lang file: the sentence arrives at the screen as
data.

The seam already existed one table over. A notification row has kept its copy as
a key plus its values since [copy that follows the
reader](../features/notifications/reader-language-copy.md), because a
notification is written once and read for a year. `CopyLine` and `CopyParam` were
`Notifications\Internal`, so nothing else could reach them; they now live in
`Modules/Core/Public/Support/`, and `StoredCopy` packs one into a single string
column:

- `StoredCopy::of(CopyLine::of($key, [...]))` on the way in, `::plural($key, $n)`
  where a count governs a word, so the *reader's* rule table picks the arm rather
  than the writer's.
- `StoredCopy::read($stored)` on the way out.
- A value that renders differently per reader is a `CopyParam`, not a string: a
  date, an amount, a nested line, a category name. `'Transaction: Weekly shop ·
  4 Mar 2026 · -€30.00'` had frozen all three at once — the month name in the
  writer's language, the amount under the writer's grouping marks.
- Anything that is **not** a spec comes back verbatim. The same columns hold a
  memo the user typed and rows written before the seam existed, and both have to
  keep rendering.
- `StoredCopy::keyOf()` and `::names()` let a caller recognise a stored line
  without rendering it, so no query and no test learns the envelope's shape.
  That mattered immediately: seven predicates — one in Pots, six in Migration —
  narrowed on the English text and matched nothing the moment it moved.

The guard walks every file that writes to a table and reads the values under the
names a screen prints back — `display_label`, `reason`, `memo`, `message`,
`title`, `body`, `label`, `note`. The gate is the *file* rather than the
statement, because a writer usually hands its sentence to a private recorder one
hop before the insert, which is exactly where `recordDriftAlert(message: …)`
lives. Demo seeders are out of scope: what they write is the demo user's own
data, the same text in every language, like the merchant names beside it.

### A synced column cannot hold the spec

`pot_movements.memo` was the exception that had to be answered differently.
`system_alerts` and `pot_movements` are both **synced** tables
(`MergeRulesRegistry`), and two devices in one household are never upgraded at
the same instant — [a peer may be on a newer
version](../features/sync/a-peer-may-be-on-a-newer-version.md) makes forward
compatibility a standing requirement rather than a migration concern. A build
that has never heard of `StoredCopy` echoes the column, so an envelope written
by a newer device renders as raw JSON at a reader who did nothing wrong.

`system_alerts` had somewhere else to put it: the spec rides in the `metadata`
JSON column that every one of its writers already fills, `message` keeps the
rendered sentence, and an older peer renders exactly what it renders today.
That is the notifications design again — the row keeps a written sentence
*because* an older reader needs one.

`pot_movements` has no such column, and adding one is worse than the defect: an
op naming a column the peer's schema does not have is quarantined whole
(`QuarantineReason::UnknownColumn`), so the movement would not land at all. So
the release stopped being a sentence and became a **kind**.
`PotMovementKind::ReleasedOnArchive` is named from the lang file by the same
`match` that names a fund and a transfer, the memo is null, and the older peer
falls into the `null` arm that already exists for a kind it cannot read — "this
row was written by a newer version of Beatrax", which is the degradation that
column's forward-compatibility rule was already designed around.

The rule that comes out of it: **a spec may only be stored where an older build
would not have rendered it.** A column that is not synced, or one with a
sibling the reader ignores, can hold the envelope. A synced free-text column a
screen echoes has to keep holding text.

## English a template typed for itself

`Modules/Categorization/Resources/views/livewire/rules-page.blade.php` ·
`tests/Contracts/ABladeNeverSpeaksEnglishOfItsOwnArchTest.php`

`/rules` drew a chip reading **ALL** between "Prioriteit 1" and *Omschrijving
bevat "Albert"* on a Dutch screen, and had for as long as the screen existed.
The two words were typed into a ternary:
`{{ $rule->combinator === RuleCombinator::Any->value ? 'ANY' : 'ALL' }}`. The
same concept was translated one screen away the whole time, in
`categorization::rule_form.match_all`.

**Every translation guard in this tree starts from a lang file.**
`EveryTranslatedLineReachesAReader` walks keys looking for call sites,
`EveryKeyACallSiteNamesResolvesToALine` walks call sites looking for keys, and
`TranslationParityArchTest` compares dictionaries. A word that never had a key
is in none of the three. It took a screenshot to find.

The rule that does see it reads a blade's echoes and asks whether a quoted
literal is **capitalised**. That is what separates copy from the machine words a
template is full of — an array key, a CSS class, a wire method, an `aria-*`
value, an `inputmode` — none of which reaches a reader as a word. A camelCase
identifier is excluded by the hump that makes it one. A date pattern is letters
and spaces like copy is, and is told apart by what it is an argument *to*.
Four literals on the tree survive it, and each is a name the app does not own or
a database token: `Beatrax`, `Gmail`, `Microsoft 365`, `NULL`. A second rule
covers the attributes a reader is shown the value of — `title`, `alt`,
`placeholder`, `aria-label` — where nothing is echoed at all.

A ternary picking between two words picks between two **keys** instead. The chip
and the form field that sets it then say the same thing in every language, which
they did not before: the chip said ALL and the field said *Voldoe aan alle
voorwaarden*.

The same shape one layer out is a word typed into **PHP**, which no blade rule
reaches. The command palette listed `Run import`, `Scan email now`, `Open
profile` and `Toggle theme` in English for every reader — those four rows are
outside the `is_developer` gate the dev rows sit behind, so the palette every
user opens from the sidebar was a quarter English. `Scan email now` was already
translated in twenty-six locales one module over, in the desktop tray menu.
Behind the gate, the developer console's own rail and its whitelisted-command
list were English beside pages this app ships in twenty-six languages.

Where those labels live decides how they are fixed. `AppAction`, the dev rail
and the command specs are all built inside a container **singleton**, so a
resolved word there is whichever language first built the registry and every
later reader inherits it. They carry a `labelKey` instead, resolved where the
value is consumed — the same read-time rule `AppNavigation::destinations()`
already follows for the sidebar it feeds.

## A formatted number standing next to a translated noun

`tests/Contracts/CountedNounDeclaresItsPluralArchTest.php`

`Fmt::number($mappingsCount)` beside `Lang::get('community::settings.mappings')`
rendered **`1 Mappings`** at one, and `0 Mappings`, `2 Mappings` either side of
it. `count($importDiff['new'])` beside three sentence fragments rendered
`1 new, 1 unchanged, 1 conflicts.` A prefix, a number and a suffix rendered
`Matches 1 transactions in your recent history.` A backfill strip rendered
`1 / ~1 messages`.

The rules that already forbade this read the count off a **variable name** —
`$openCount`, `$n`, `$preview->dedupedTotalCount`. None of them could see these:
the count is inside `Fmt::number(…)`, `number_format(…)` or `count(…)`, and what
stands beside the line is a call. A call is matched rather than a name because a
formatted number is unambiguous — nothing formats a value that way except to
show a reader a quantity.

The fix is the fix for every other counted noun: the numeral moves inside the
line and the call site reads `Lang::choice($key, $n)`, which fills `:count`
through `Fmt::number()` itself, so the grouping marks the template was reaching
for come with it. Where the phrase carries a second number, that one stays a
replacement — `Lang::choice($key, $total, ['fetched' => Fmt::number($n)])`.

Two of the four were also sentences **assembled from fragments**, which is the
larger half of the defect and invisible to a count test. `'Matches'` + numeral +
`'transactions in your recent history.'` pins the word order for twenty-six
languages at once; a translator handed the two halves can move neither, and
several of these languages put the numeral somewhere else. Collapsing all three
into one line is the same fix, and the rule catches the shape because the
numeral is still standing next to a translated line.

## A tolerance calibrated on a synthesised fixture while a real one disagrees

`STATEMENT_DUE_GRACE_DAYS = 5` and `PERIOD_WINDOW_DAYS = 10` were both measured
against `Modules/Chains/tests/fixtures/scenario-1/`, which is generated by
`scripts/synthesise_scenario_1_fixtures.php` and says so on its own first line:
*"NOT anonymised from real user data."* Its settlement lands six days past the
period the app derives, so a five-day grace and a ten-day window fit it exactly.

The repo also commits one real statement. `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt`
line 57 states, in the issuer's own words:

```
Het minimaal te betalen bedrag ad EUR 1.416,50 verwachten wij voor 8 maart 2026
```

against a period the app derives as 2026-01-15 → 2026-02-12 from the min and max
transactiedatum. That is **24 days**, not five. Measured before the fix:

```
app dueDate = 2026-02-17    printed due = 2026-03-08    (19 days early)
settlement posted 2026-03-08, candidate window 2026-02-26..2026-03-18 -> 0 statements
settlement posted 2026-02-17, candidate window 2026-02-07..2026-02-27 -> 1 statement
```

A payment made on the day the issuer asked for matched no statement, wrote no
`chain_link`, and left the statement `open` forever — the same end state as the
booked/posted period defect above, reached through a different door. On
2026-02-18 the dashboard additionally called it overdue and
`ChainAwareForecastRouter` dropped the settlement out of the projection, so the
curve showed money that was about to leave.

Two rules follow.

**A printed value beats a derived one.** The statement prints its deadline once,
unambiguously, and the extraction map was already stripping that very line as
page noise. `IcsStatementHeader::paymentDueDate()` now reads it off the same
named anchor the noise pass keys on, `statement_summaries.payment_due_date`
carries it, and `card_statements.due_date` is filled from it on promotion. The
constant is the fallback for a statement that prints nothing — which is every
MT940, CAMT.053 and CSV statement there is, so it stays load-bearing.

**A tuning number is named once, in a seam its consumers share.**
`Modules/Chains/Public/Support/StatementDueDate` holds the grace, the matching
window and the rule that combines them with the printed day, the same shape
`RetentionWindow` and `WeekStart` already use. Both numbers previously sat on
the classes that happened to need them first — one on a `Public` query, one on
an `Internal` resolver — where a second consumer's only options were to reach
across a module boundary or to restate the number.

What made this invisible for a whole release is that the synthesised fixture was
*derived from* the real one, so it looked like evidence. A fixture record that
says it is synthetic is saying the numbers taken off it are conventions, not
measurements. `tests/Contracts/ATuningNumberIsNamedOnceAndAnswersToTheRealStatementArchTest.php`
holds every one of these constants against the committed real statement, and
fails a second declaration of any of them.

## A per-user initialisation only a migration performs

`tests/Contracts/AMigrationIsNotTheOnlyPathToPerUserStateArchTest.php`

A cutover sweep and the ongoing runtime path are two ways of establishing the
same state. When only the sweep exists, the feature works for everyone who
existed at cutover and silently does nothing for everyone since — and the
failure reports nothing, because the migration genuinely ran and genuinely
succeeded.

`envelope_activated_at` is the carryover fold's genesis anchor: `CarryoverQuery`
walks periods forward from it, and with no anchor it returns the pre-genesis
shape, where `netMovedMinor` is hard-coded to `0`. The only production caller of
`EnvelopeActivationService::activate()` was the cutover migration
`2026_07_05_000010`, and `beatrax:install` runs `migrate` at `InstallCommand:114`
**before** `User::create` at `:153`. The sweep therefore walked an empty `users`
table on every fresh install, and `SignupAction` never called it at all. Every
reader created since held the column null.

Measured through the real signup path, on the same four `envelope_moves` rows:

```
before  envelope_activated_at=NULL          transport-fuel moved      0  available      0
                                            groceries      moved      0  available      0
after   envelope_activated_at=2026-08-30…   transport-fuel moved  -5000  available  -5000
                                            groceries      moved   5000  available   5000
```

So a first-run reader moved EUR 50 between two envelopes, got a success toast, a
history line and a working Undo, and both envelopes still read EUR 0.00 moved.

Two halves, and both were needed. The anchor is now established on
`UserInstalled`, the seam **every** user-creation path already dispatches
(`InstallCommand`, `SignupAction`, `DemoUsersSeeder`, the pairing screen's
re-dispatch), by calling the same `EnvelopeActivationService` the migration
calls rather than a second copy of its logic. The listener declines when
`seedsStarterData` is false: the column is synced last-writer-wins, so a joining
device stamping today would carry a genesis newer than the peer's back over sync
and drop every month of their history below it.

The other half is the fallback for the readers who already exist. It read
`MIN(envelope_assignments.period_start)` and nothing else, so a reader whose
whole envelope history was moves had no genesis at all. It now takes the
earliest of assignments *and* moves: the true anchor is the earliest evidence
the reader used envelopes, and moving money is using them.

The sweep for the shape found a second instance one door along. `AddUserAction`
mints the household partner and dispatched nothing at all, so a partner had no
categorization rules, no onboarding wizard rows, no tax corpus and no envelope
genesis of their own — every one of those is written by a listener on
`UserInstalled`, and nothing on any screen says they are missing. Establishing
state on a seam is worth nothing to a reader created by a path that never
reaches the seam.

The rule is therefore written three times, because the shape has three faces.
Structurally: **a class a migration executes must also be reachable from the
running app** — if the only thing that ever runs it is a migration, it
establishes state for the rows that existed and for nothing created afterwards.
By outcome: **a user created the way readers are created must satisfy every "is
this user done yet?" predicate a migration-time sweep gates itself on** — the
columns are read out of the sweeps themselves rather than listed, so a sweep
added tomorrow is covered without anyone remembering the file. And at the other
end: **every production path that writes a `users` row must dispatch
`UserInstalled`**, with the model factories pinned, because a fixture user is
the one reader whose state the test decides.

Two classes are pinned rather than converted, and the discriminator is whether a
fresh install gets the state anyway. `SeedBundledExchangeRates` and
`IcsStatementSenderSeeder` both write app-wide rows with no `user_id`, from
migrations dated after the squashed schema — so every install runs them,
including the fresh one, and there is no per-row initialisation for a later row
to miss. The platform asymmetry matters for reading any of these: mobile has no
`sqlite3` binary and runs every migration from the first, while desktop loads
`database/schema/sqlite-schema.sql` and starts after `2026_06_13`, so a backfill
living in an earlier migration is code only a phone ever executes.

## A date from outside, normalised instead of refused

Every date parser PHP has answers a *different* date rather than refusing a bad
one. Against the live ledger — 187 rows, every one dated 2026 — the transactions
list answered four different questions to four values the address bar can carry:

```
?before=2026-12-31  (well formed)     187 rows
?before=2026        (year only)         0 rows   ← empty list, no message
?after=2026         (the mirror)      187 rows
?before=2026-1-5    (unpadded)        187 rows   ← all 187 post after 5 January
?before=tomorrow    (free text)       187 rows
```

Three mechanisms, and each is silent:

- **`createFromFormat` rolls an out-of-range component forward.** `2027-02-29`
  parses — into 1 March. `2026-11-31` into 1 December. The roll is reported as a
  parse *warning*, which nothing was reading.
- **`parse()` and `strtotime()` read relative English.** `'tomorrow'` is a
  boundary that moves every day it is evaluated; `'2026'` is *today*.
  `StartingBalanceRule` accepted `'yesterday'` and `'last friday'` this way.
- **A string bound against a `DATE` column is compared one character at a
  time.** `'2026-06-01' <= '2026-1-5'` is true, because `'0' < '1'`. Nothing
  throws; the list is simply a different list.

### The seam

`SafeDate::dayOrNull()` is a **shape check, not a parse**: it formats the parsed
value back with `Y-m-d` and compares it to what arrived, so `2027-02-29`,
`2026-1-5`, `2026`, `2026-06` and `tomorrow` all come back null. Five files each
held their own copy of that round-trip before this — `GoalWriter`,
`PeriodPresetResolver`, `PeriodQuery`, `SetAccountOpeningBalance` and the
framework's own `date_format` rule — and they did not all agree.

**Normalising is still right, but only for a machine.** A MIME `Date:` header
and a stored timestamp whose time half is an artefact have no `Y-m-d` shape to
check, so the lenient reader survives under a name that says what it does:
`SafeDate::normalisedDayOrNull()`. Renaming it is the load-bearing half of the
split — every existing caller now has to state that normalising is what it
wanted, and a reviewer can see at the call site which question was asked.

### What refusing means depends on who supplied it

- **A `#[Url]` parameter is a bad link**, so it is coerced to the default at the
  boundary and the page renders as though it were absent. `TransactionsList`
  coerces on the *property*, not on the way to the query: the chip and the
  active-filter badge read the same property the rows do, so a bound the query
  dropped stopped being counted as a filter at the same moment.
- **A typed `before:` token, or a `SearchFilters` handed to the public
  `SearchQuery`, matches nothing** rather than widening to the whole history —
  the rule `SearchTokenFilters` already applies to a name it cannot resolve. A
  bare `Y-m` stays legal there; it is a month, read from opposite ends by the
  two bounds.
- **A write refuses loudly**, because the reader is present to be told: the goal
  target date, the cash-book entry, the reconcile window, the opening balance.
- **A row arriving from a peer is refused where both paths meet.** A check in a
  form action does not cover sync, so the scenario what-if dates are refused in
  the payload DTO's constructor — which `Data::from()` runs, so the mutation
  cannot be rehydrated from a synced blob either. `DateOnlyCast` does the same
  for a day-shaped string in any `DATE` column, on the way in and on the way
  back out.

`RuleEngine` is the one place that *raises* rather than answering "no match": a
rule condition is stored and synced, and `ReapplyRulesJob` counts a row it
cannot judge as errored. That is how a malformed rule reaches the operator
instead of quietly matching nothing for the rest of its life — the behaviour
`'not-a-real-date'` already had, now extended to `'tomorrow'`, which used to
match a different set of rows every day.

### Still open

The op-log applier writes a wire-supplied value straight into a column, so a
peer can still land an impossible day in one of the seven synced `DATE` columns.
The model cast turns that into a loud failure rather than a quiet one, which is
not the same as refusing the op; the fix belongs beside the applier's other
per-column gates, quarantining it the way a cross-user reference already is.
That is carried as a handover in the guard, with a count, and it clears itself
the moment the file names the seam.

### The guards

`tests/Contracts/ADateFromOutsideIsRefusedNotNormalisedArchTest.php` walks the
Blade tree for every `<x-core::date-input>` — stripping Blade comments first,
because the goals modal explains the component inside one — and requires each
bound property to name the file that refuses it, with a pattern re-run against
that file and a site count re-checked against the walk. Beside it, one arm bans
a second spelling of the whole-day round-trip and any `strtotime()` reading a
date, and another carries the sync debt as a debt.

`TamperedUrlParameterContractTest` gained the arm that catches the quiet 200:
for every `#[Url]` property a date picker also binds, a malformed value must
leave the page naming the reader's own rows exactly as often as the page with no
parameter at all. A 500 and a neighbour's row were already covered; a list that
is simply *wrong* was not.

## A catch body that says nothing

`tests/Contracts/AnEmptyCatchIsOneSomebodyChoseArchTest.php`

The shape behind most of what this tree has shipped and had to fix. A capture
listener caught a binding failure, logged it at debug and dropped the mutation —
4,925 user changes never reached the other device. A merge strategy skipped null
entries and returned an empty set, so two devices disagreed permanently. A CLI
password reset stranded the app-lock recovery wrap and raised no alert, closing
the recovery road the lock screen advertises. In each one the catch body carried
no throw, no report, no log above debug and nothing a reader could see.

Tolerating a failure is frequently the correct answer here, and the twenty-odd
sites the guard lists are all cases where it is: a row that vanished between
render and click is already in the state the click asked for, an FTS-freshness
hiccup must never break merge determinism, and a `SystemAlert` write failing
must not re-break a passphrase change that has already committed. What none of
those excuse is a *new* one arriving unnoticed, which is why the list is pinned
with a reason per entry rather than left to review.

Three details decide whether the guard works at all. It strips comments before
scanning, because every body it finds holds a comment and nothing else — a scan
that counted prose as a statement would report a clean tree. It lifts the PHP
out of a Blade `@php` block, because a Blade file carries no opening tag and
`token_get_all` reads all of it as inline HTML, which makes any guard built on
that helper blind to the PHP inside one. And a pinned entry that no longer names
an empty catch fails too: a list that may rot into names nobody checks is the
same silence one level up.

## A regex that never ran, read as no match

`preg_match()` and `preg_match_all()` return `false` when PCRE stops part-way —
a JIT stack, backtrack or recursion limit, or a subject whose encoding the
pattern cannot read — and they leave the `$matches` array **empty** when they
do. So the shape is a call whose return is thrown away, followed by a read of
`$matches`:

```php
preg_match_all($pattern, $subject, $matches);
foreach ($matches[0] as $hit) { … }
```

Both halves of that are silent. `false` is not an exception, `$matches[0]` is
`[]` either way, and nothing anywhere records that the scan was abandoned. The
call site has no way left to tell "the tree is clean" from "the reader gave up
before it got there". Measured rather than assumed: at
`pcre.backtrack_limit=1000`, `preg_match_all('/(a+)+$/', str_repeat('a', 40).'b',
$m)` returns `false`, `preg_last_error_msg()` says `Backtrack limit exhausted`,
and `$m[0]` is `[]` — the same value a clean scan of a clean file produces.

It has cost twice here. An architecture guard hit the JIT stack limit on a large
subject and reported the wrong answer; separately, a `<[^>]*>` tag strip spilled
every Alpine `x-data` body into "visible text" and produced 75 false positives,
which is the same lesson from the other side — a regex over structured input is
often the wrong instrument, and its failures do not announce themselves.

The false-green direction is the one that matters. A guard that finds nothing
because it never looked is indistinguishable, in CI, from a guard that looked and
found nothing — and every *other* defect that guard exists to catch rides through
behind it.

Nothing caught it because there was nothing to catch. Static analysis sees a
legal call; the formatter sees well-formed code; the suite sees a passing test.
`preg_match` is one of very few functions in the language whose failure value is
also a plausible success value, and PHP has no unused-return-value diagnostic
that would have asked.

Counted across `Modules/`, `app/`, `tests/`, `database/` and `scripts/`: 996
calls to the two functions, of which 412 read the answer in a position where
`false` cannot be told from no-match, and 199 of those threw the return away
entirely and then read `$matches`. Only 13 were production code — the rest were
tests and architecture guards, which is exactly the wrong place for it. The
remaining 542 already compared identically against `1`, which is why the shape
survived review for so long: the correct form was the common one.

So the reading has one home, `Modules\Core\Public\Support\PatternScan`, whose
every method runs the scan and raises `PatternScanFailedException` naming the
pattern and what PCRE said. `ARegexThatNeverRanIsNotNoMatchArchTest` tokenises
the tree — with `token_get_all()`, not a regex, since a pattern inside a string,
a name inside a comment and a method that happens to share the name all read
alike to `grep` — and accepts exactly two written forms beside the seam:
`=== 1` / `!== 1`, and `=== false` / `!== false`. Both separate the failure from
the empty answer. `> 0`, `=== 0`, `(bool)`, `!` and a bare `if` all fold `false`
into the answer, and a discarded return folds it into `$matches`.

Three sites keep the tolerant reading deliberately, and say so where they sit.
`CorpusPatternMatcher` runs corpus-supplied patterns under a lowered backtrack
budget and logs a failure as a non-match, because the corpus is data and one
pathological row must not raise on a scan of every description.
`RelayClient::backendHonorsPinning()` reads `=== 1` so an unreadable
`ssl_version` string fails closed rather than throwing inside a TLS decision.
`CorpusPatternMatcher::compiles()` uses `=== false` to mean "this pattern does
not compile", which is the question it is asking.

## A replace that never ran blanks the subject

`preg_replace()` and `preg_replace_callback()` return `null`, and `preg_split()`
returns `false`, on the same limits that make `preg_match` return `false` — a
JIT stack, backtrack or recursion limit, or a subject the pattern's encoding
cannot read. Unlike the matchers, the failure value here is *not* a plausible
success value, so the give-up is knowable at every call site. The cost is that
the two shortest ways to read it throw that knowledge away:

```php
$clean = (string) preg_replace('/<script.*?<\/script>/s', '', $html);
$parts = preg_split('/\r?\n/', $text) ?: [];
```

`(string) null` is `''`. So the first line does not fail to clean the subject —
**it deletes it**, and every reader downstream is handed an empty document that
looks exactly like a document with nothing in it. The second reads a give-up as
"this input had no parts". Both are silent, and both are the same false-green
shape as the matcher case: a step that gave up reports the tidiest possible
answer.

Counted across `Modules/`, `app/`, `database/`, `routes/`, `config/`,
`bootstrap/`, `tests/`, `scripts/` and the `mobile-app/` Composer root by
parsing every file and classifying each call by what its parent AST node does
with the return: **294** calls. 94 written `(string) preg_replace(…)`, 19
written `?? ''`, 27 written `?: []`, 1 written `(array) preg_split(…)`, 42
assigned or passed on with nothing testing them — and 111 already separating the
failure, of which **87 fall back to their own subject**.

That last number is the distinction worth keeping. `preg_replace(…) ?? $subject`
degrades to the text *uncleaned*, which a scan downstream reads as a false
positive somebody investigates. `(string)` degrades to no text at all, which
reads as a clean answer nobody looks at twice. The two are not variants of one
mistake; one of them is the safe direction.

The reading has the same home as the matchers, `PatternScan::replace()`,
`::replaceCallback()` and `::split()`, each raising `PatternScanFailedException`
naming the pattern and what PCRE said.
`AReplaceThatNeverRanBlanksTheSubjectArchTest` tokenises product code and
refuses a `(string)` or `(array)` cast on the call, and `?? ''` / `?? []` /
`?? null` / `?: []` behind it. It accepts a fallback that names a real value,
`=== null` / `=== false`, `is_string()` / `is_array()`, and `??=`.

Three readings keep the tolerant one on purpose and say so where they sit.
`RedactedText::orEmpty()` is the one place in `DevMode` that reads a give-up as
"emptied": a redactor must neither raise nor hand back what it was asked to
remove, and losing the excerpt is survivable where shipping the secret is not.
`RelayTlsMaterial::pemToDer()` returns an empty DER so an unreadable pin fails
the comparison closed rather than throwing out of a TLS handshake.
`Mt940Rebaser` raises `StatementRebaseFailed` instead, because the command that
calls it turns that into a message naming the fixture.

## A guard that reads HTML with a regex

`<form\b[^>]*method="POST"[^>]*>` cannot cross a `>`, and an attribute value is
full of them: `x-show="count > 3"`, `@class(['on' => $active])`,
`{{ $attributes->merge([...]) }}`, `wire:model="rows.{{ $row->id }}"`. The tag is
read half-way, the pattern either fails to match or matches a different span, and
the guard reports nothing wrong with a file it never finished reading.

Measured over the 274 Blade views in this tree: **325 of 1966** `button`, `a`,
`form`, `div`, `input` and `select` start tags across **116 files** end somewhere
other than where `[^>]*` puts them. Three guards were answering the wrong
question because of it:

- `VisibleLabelInAccessibleNameArchTest` saw **45** of the **105** aria-labelled
  buttons and links in the tree. It had a `continue` for the case, saying so:
  "an attribute holding inline JS spills its tail into `$inner`". Everything that
  spilled was skipped, silently.
- `ComponentTagDirectiveArchTest` exists to catch `@if` inside a component tag,
  which Blade emits as raw HTML so the component never renders. A tag holding
  both `:tone="$level > 2 ? …"` and `@if(...)` was read up to the first `>` and
  flagged **nothing**.
- `AnIconOnlyActionSaysItsVerbOnTouchArchTest` matched
  `<x-core::emoji-action …>…</x-core::emoji-action>`, so a self-closing
  `<x-core::emoji-action … />` with an unresolvable label was invisible to it.

The same shape appears in rendered-response assertions, where a lazy or greedy
run reaches past the element it named: `data-testid="queue-tile-pending"[\s\S]*?`
followed by a digit is satisfied by that digit anywhere further down the page,
and `<fieldset>.*name="unsplit-survivor".*</fieldset>` only asks whether both
strings exist between the first `<fieldset>` and the last `</fieldset>`.

### Two readings, because Blade is not a document

An HTML5 parser is the right instrument for a **rendered response** and the wrong
one for **template source**, and the difference is measurable rather than
stylistic. Given

```blade
<table><thead><tr>@foreach ($cols as $c)<x-core::th>{{ $c }}</x-core::th>@endforeach</tr></thead></table>
<input type="text" @class(['a' => $x]) value="{{ $v }}">
```

`Dom\HTMLDocument` foster-parents the `<x-core::th>` **out of the table** — the
tree-construction rules move anything that is not `<th>`/`<td>`/`<tr>` out of a
row — and loses the `<input>` entirely, spilling the rest of `@class([...])` into
text. A table guard run over that tree would report a table with no header cells,
which is a false red on markup that is correct.

So the seam has two faces:

- `Modules\Core\Public\Support\MarkupSource` reads **template source**. It is a
  character walk, not a pattern: quotes, `{{ }}` and `{!! !!}` echoes, `{{-- --}}`
  and `<!-- -->` comments, `@directive(...)` arguments, `@php … @endphp` bodies and
  `<script>`/`<style>` content are all stepped over, and a closing tag is matched
  through nesting rather than taken as the first one that appears.
- `Modules\Core\Public\Support\RenderedMarkup` reads a **response body** with
  `Dom\HTMLDocument` and answers CSS selectors, so containment is a tree question
  and `<tbody>` exists whether or not the template wrote it.

### Both fail loudly

`MarkupSource` raises `MarkupParseFailedException` on a start tag with no `>` and
on an attribute value with no closing quote, and `MarkupElement::inner` is
**null**, never `''`, when the closing tag never arrived — an element whose
content is unknown must not read as an element with no content. `RenderedMarkup`
raises on an empty document, on bytes that are not UTF-8 (an HTML5 parse never
reports failure; it guesses an encoding and yields `html`/`head`/`body` for any
input at all), and `firstOrFail()` raises rather than returning null.

### What is deliberately still a regex

A guard asking whether an exact literal appears in a file is not asking a
structural question and gains nothing from a tree: `'<legend class="sr-only">…'`,
`window.beatraxSubmitPostForm`, `@media (pointer: coarse)`, a `wire:model.blur`
spelling, a CSS rule body. Neither is the reading of a PHP expression a markup
attribute happens to hold — `:label="Lang::get('…')"` is parsed out of the
attribute *value* the walk returns, which is where a pattern belongs.

## A flag set but never read back

`Modules/Mobile/tests/Unit/ExcludeDataFromBackupPatchTest.php`

iOS backs up Application Support to iCloud by default, and that is where the
database, the sync keyring and the staged secrets live. There is no manifest
flag for it; the exclusion is a per-URL resource value. The generated shell set
it like this:

```swift
try FileManager.default.createDirectory(at: destination, …)

var excluded = destination
var values = URLResourceValues()
values.isExcludedFromBackup = true
try excluded.setResourceValues(values)
} catch {
    // Handle the error
}
```

Two independent failures, and each one alone is enough. The `try` shares the
`createDirectory` call's catch, so a throw from `setResourceValues` lands in a
handler written for a failed directory creation — and that handler is the
vendor's, which handles nothing. And nothing reads the value back, so the code
only ever establishes that the exclusion was *asked for*.

The distinction is the point: a write that returns without raising has not been
observed to have taken effect, and a setting whose whole purpose is to keep a
file out of somebody else's storage is exactly the kind that must be. The cost
of being wrong is not a degraded feature — it is the entire financial history of
the device in an iCloud account, with no log line, no failed build and no way
for the reader to find out. The exclusion now has its own `do`/`catch`, reads
the value back, and reports either failure through `NSLog`, which is the only
channel available that early in launch.

## A skip that ended the run instead of its own half

The same script patches two platforms. Its Android half opened by checking for a
manifest and, not finding one, said so and exited:

```php
if (! is_file($manifest)) {
    fwrite(STDOUT, "… no Android scaffold yet — skipping.\n");
    exit(0);
}
```

That `exit(0)` ends the process, not the Android section. A checkout with only
the iOS scaffold generated therefore received no iCloud exclusion at all, and
the single line it printed on the way out talked about Android.

What makes this invisible rather than loud is the pair: a zero exit status and a
plausible message. A build that stops early and says why reads exactly like a
build that had nothing to do — the log is honest about the half it describes and
silent about the half it skipped. Neither the exit code nor the message is
wrong on its own, which is why nothing downstream can catch it.

So the rule is narrower than "prefer early returns". A conditional guarding one
of several independent units of work must end that unit and no more, which in a
straight-line script means the units have to be functions before the guard can
be written correctly. The two halves each answer for themselves now, and the
test asserts the iOS exclusion still lands when no Android scaffold exists —
the case that had no coverage precisely because it produced a passing run.

It was not the only one. `nativephp_grant_webview_camera.php` resolves the
generated `WebViewManager.kt` and, separately, `WebviewRenderer.kt` under
`vendor/`, and its opening guard exited on the first one's absence. The vendor
half is the one that matters — every build re-copies `vendor/` over the
generated tree, so a patch applied only to the generated file survives until
the next build — and it is the half that was skipped. The visible symptom was
the in-page QR scanner falling back to the plugin's full-screen activity on
every attempt, which is `E5-R14`'s camera-first pairing quietly not happening.

Removing that exit exposed a second defect underneath it, which is worth
recording because the first was hiding it: the vendor anchor *survives its own
patch*, so with the early exit gone a re-run appended a second
`onPermissionRequest` and Kotlin would have refused the duplicate method. An
exit that skips a half also skips that half's idempotence, and neither had ever
been exercised.

`ScaffoldPatchesFindEitherRootTest` now fails any `scripts/nativephp_*.php`
that calls `exit(0)` between resolving one target and resolving a different
one. Run against the tree before either fix, it names both scripts; the
detector is what found the second instance.

## What bounds a build is not what bounds the repository

`tests/Contracts/ADurableDataDirectoryIsNeverShippedInTheBundleArchTest.php`

Both packagers copy the working tree. The desktop walks it with a
`RecursiveCallbackFilterIterator` and the mobile one shells out to
`rsync -a --copy-links`, and in each case the only thing standing between a
developer's working directory and a shipped binary is that shell's
`cleanup_exclude_files`. `.gitignore` has no part in it. That is what makes the
failure invisible: the directories are absent from `git status`, so every habit
built around reading the repository says they are not there.

An earlier round found `storage/app` this way and excluded it. Naming one
directory is not the same as fixing the rule, and four more were sitting
outside it:

| shell | directory | what is in it |
|---|---|---|
| mobile | `credentials/` | the Android release signing keystore |
| mobile | `build-secrets/` | iOS signing artifacts |
| desktop | `.device-test/` | 4,024 files, 1.6 GB of captured application screens |
| desktop | `.playwright-mcp/` | page snapshots, console logs, screenshots |
| desktop | `local/` | a manual drop directory for PayPal exports |

The signing material is the serious one, and it is not a hypothetical arriving
from a developer's machine: `release.yml` decodes the keystore from a
repository secret into `mobile-app/credentials/app-release-key.jks` and only
*then* runs the packager, so it is present in the tree at the exact moment the
bundle is copied.

The packager's own defaults do exclude `*.jks` — but `BundleExclusions::PROJECT`
is mapped through `fn ($p) => '/'.$p` before it reaches rsync, and a leading
slash anchors a pattern to the transfer root. `/*.jks` matches a keystore lying
in the project root and never matched one a single directory down. Verified by
calling the vendor's own `BundleFileManager::excludes()` and running rsync with
exactly those 46 patterns over a fixture: a root-level `.jks` was dropped,
`credentials/app-release-key.jks` was copied.

Two details decide whether a guard for this works. It must ask whether a
directory's **contents** are in the repository rather than whether the
directory is ignored — `build-secrets/` tracks a `.gitignore` that ignores
everything beside it, so it is not an ignored path, while every file that ever
appears in it is. And a directory that survives must be *classified* rather than
merely absent from the failure list: `storage/` holds no source either, and the
honest answer is that the framework needs the tree while `storage/app` is
excluded by name, which is a sentence someone can check.

## An expected condition answering as a server fault

`tests/Contracts/AnExpectedConditionIsNotAServerFaultArchTest.php`

Two conditions a client or the environment triggers in the ordinary course of
things answered **500**:

- `GET /oauth/callback/gmail?state=forged` — and the same URL replayed after a
  back button, and the same URL with no query at all. All three measured at 500.
  A state that matches no issued one is what the CSRF check on an OAuth callback
  *exists to find*, so the control fired correctly and the reader was shown a
  crash page in the middle of connecting a mailbox.
- A `/livewire/update` payload calling `TransactionDetail::reclassify()` with a
  transaction type outside `TransactionType`. Measured at 500 over a real POST
  to the update endpoint, not through `Livewire::test()`.

Nothing caught either one, for the same reason the refused-write family above
went unnoticed: **the tests asserted the exception**. Both OAuth callback tests
called `withoutExceptionHandling()` and then `expect(...)->toThrow(...)`, which
is a test that can only ever agree with the throw. The status a browser gets was
never named in either file, so the suite was green on the exact behaviour that
was wrong. `Livewire::test()` hides the second one the same way: it runs with
exception handling off except for `HttpException`, so an assertion on the throw
passes and the status a browser would get never appears.

The answer is not one answer. A callback reached by a browser navigation has a
screen to go back to, so it flashes a line and redirects — which is what the
`OpenBankingCallbackController` beside it already did, and what this controller
already did for a consent the reader cancelled. A payload naming a type the
picker never offers has no screen and no reader behind it, so it is a 400 that
names the shape of the refusal and not the value it stopped on.

What generalises is not the answer but the obligation: **an exception an HTTP
entry point lets out must carry its own answer.** Three families do —
`HttpExceptionInterface` carries a status, `HttpResponseException` carries a
whole response, `ValidationException` carries 422 — and anything else reaches
the generic handler, which has exactly one thing to say. The guard reads every
entry point and refuses a throw outside those three. It measures both halves
through the live handler rather than trusting the class names: each trusted
family is rendered and asserted under 500, and a bare `RuntimeException` is
rendered and asserted at 500, so a framework release that stops mapping one of
them turns the guard red rather than quietly widening it.

Three details decide the scope:

- **The router is only half the boundary.** A component a layout mounts has no
  route of its own and is still reachable from an update payload, so the guard
  reads the live router's classes *union* every file under an `Http/` directory
  — 202 files, against 60 the router names.
- **A lexical guard reads a catch that is not there.** The first version flagged
  three throws in `Forecasting`'s `BuildsMutationForms`, all of them correct:
  the coercion helpers raise for a caller several frames up, and the `try` is
  around the call rather than around the `throw`. Containment by token range
  says "unguarded" and is wrong. The guard now walks the intra-file call graph
  from each `try` block and treats a throw inside a method that block reaches as
  covered.
- **What it does not see.** It reads one file at a time, so a throw deep in an
  action that escapes through a controller is outside it, as is a catch in a
  class that uses a trait declaring the throw. It guards the boundary, which is
  where both of these landed, and not the whole call graph behind it.

## A condition the screen could have named, answered as a server fault

`AnExpectedConditionIsNotAServerFaultArchTest` guards the *boundary* — a throw
written in a file under `Http/` — and says plainly that it reads one file at a
time. Five conditions sat behind that boundary, raised in a pipeline, a cache,
a secrets file and a codec, and reached the generic handler through a page
render. Four answered **500**, measured as real GET requests before and after:

| Request | Before | After |
|---|---|---|
| `/migrations/{id}/preview` for a discarded run | 500 | 200, naming the discard and linking to a new import |
| `/imports/{id}/preview` over a malformed cache head | 500 | 200, saying the preview cannot be read |
| `/calendar` on a request holding no blind-index key | 500 | 200, that one row left unlinked |
| `/settings/open-banking` over an unparseable secrets file | 500 | 200, naming which credentials to replace |

**The asymmetry is the tell.** One builder, two throws twelve lines apart:
a run this reader does not have raises `ModelNotFoundException` and Laravel
answers 404, and a run they discarded raises a bare `RuntimeException` and the
handler answers 500. The same screen said "gone" for a run that never existed
and "the server is at fault" for one the reader had thrown away themselves a
moment earlier. `MigrationResults` had caught it since it was written;
`PreviewMigration` never had. **A catch present on one of two callers of the
same builder is the shape to go looking for** — it is a design decision that
only got made once.

**A lookup has an answer without the key; a write does not.**
`BlindIndexCodec::derive()` refuses to key when the app-lock key is not held,
and must: falling back to the plaintext would put a second form of one merchant
inside the UNIQUE index that decides whether a statement row is a duplicate.
But the calendar was not filing a payer, it was *looking one up*, and a lookup
that cannot be keyed matches nothing — which is exactly the answer the same
loop already gives for a sealed IBAN it cannot open. Only a row whose IBAN sits
in the column **in the clear** ever reached the digest: `SensitiveColumnCodec`
blanks anything that looks like ciphertext, and the caller skips an empty
value. So the two spellings of one unreadable row behaved differently, and the
plaintext one took the whole month down. `deriveOrNull()` is the read-side
twin, written beside the `keyHexOrNull()` that had already drawn the same line
one layer below it.

**The premise named a cause the middleware had ruled out.** The escape analysis
described that path as "the app lock is engaged, so no blind-index key is held
while a page renders". It cannot be: `AppLockMiddleware` redirects a locked
session to the lock screen before any page renders. What produces the 500 is an
*unlocked* session holding no key. Sending that reader to `/lock` would have
been the worst answer available — with the lock off, `disable()` has nulled
`pin_hash`, so the lock screen offers a PIN pad that scores every attempt as a
failure and signs them out after ten. **Reproduce the state before trusting the
sentence that describes it.**

**One of the five was not reachable at all.** `CurrencyMismatchException` was
reported escaping `/recurring` through `Money::plus()` on the last line of
`monthlyEquivalentTotals()`. It cannot: `CrossCurrencyTotal::withRates()`
stamps its own `$targetCurrency` on every `ConvertedTotal` it builds, both
halves are handed the one base currency the method resolved once, and a bucket
with no rate is left out and named rather than carried at one to one. Measured
over a real request with JPY, USD and two unrated currencies, `/recurring`
answers 200 and all three totals come back in EUR. A call-graph edge between a
throw and a root is a *candidate*, not a defect; what is left behind here is a
test pinning the invariant, because the day a second currency is threaded into
either half the page starts answering 500 with nothing else in the way.

**A catch is not an answer until every read is behind it.** The first attempt
at the import wizard caught the malformed-cache throw around the head read, and
the page still 500ed: `OwnAccountPrompt` opens the same entry again through
`getPreview()`, three prompts deep. The escape report named one frame because
it reports the *shortest* path; the reader meets all of them as one screen. The
catch belongs around every read that reaches the cache, not around the one the
trace happened to name.

Two smaller rules earned their keep here. The discarded-run exception's message
said the run "has not been parsed yet, or parsing failed before staging
completed" — two causes the `if` above it had already excluded, since it fires
only where the status *is* `discarded`. And the malformed preview had to get a
line of its own rather than reuse the expired one: the entry is present and
will not decode, so "the preview has expired" would have named the one thing
already ruled out. Both end at the same re-upload, and they are still not the
same sentence.

## A pre-setup screen renders the application shell

`tests/Contracts/APreSetupScreenOffersNoWayIntoTheAppArchTest.php`

`layouts.app` drew the menubar, the sidebar search box and the command palette
behind `@auth`. Being signed in is not the same question as having an
application to navigate, and the whole of first run happens signed in: signup
creates the account and logs the reader straight in, so the recovery-code
hand-over, the setup wizard, the desktop migration splash and the phone's
import bootstrap are all authenticated pages. Every one of them that named
`layouts.app` got the full shell.

Four did. The one that mattered was `/recovery-codes` — the screen the ten
codes are shown on, once, ever. It rendered a sidebar with twenty-five
destinations, a search box, a `⌘K` palette and a phone top bar with a hamburger
and a magnifier, all beside a page whose own copy says the codes will not be
shown again. Every one of those controls is a way off that screen, and taking
any of them loses the codes for good. The other three were `/setup` (the
pending-migrations splash, where the sidebar's nav counts query tables the
migrations have not created yet), `/mobile/import` and `/change-password` —
the last of which is only ever reached because the forced-change guard sends a
partner there on their first sign-in.

Three things kept it invisible. The page returns 200 and looks plausible.
A route walk that visits `/recovery-codes` without codes in the session is
redirected onward to the wizard, so the walk records the wizard's chrome and
files the page as clean. And the drawer and the top bar are a `md:`/`lg:` pair
— the drawer *is* the static sidebar from 1024px up and the top bar is
`display: none` there — so a check that looked for one of them passed at the
width that draws the other.

The seam is `Modules\Core\Public\Support\AppShellVisibility`, asked once by the
layout, answering from `Modules\Core\Public\Navigation\PreSetupSurface` — the
roster of routes that are a first-run ceremony rather than a page of the
application. `Destination` is the roster of places a reader may be sent; this
is the roster that sends nowhere. The pages keep naming `'layouts.app'`
verbatim, which matters: five separate rules read that literal out of source to
decide which pages they apply to, and moving a page onto a different layout to
strip its chrome would take it out of all five — including the one that checks
it reserves the notch.

Which is the second half. `.top-bar` reserves and paints `var(--safe-top)` and
stands in the flow, so a page under one must not pad the top again; a page
without one must. Taking the bar away turned two of the four into screens with
no seam reserved at all. `app.css` already had the answer — `.safe-screen`
pads all four edges and `body:has(.top-bar) .safe-screen` zeroes the top one
again — so a page whose chrome depends on the route it was reached by can wear
the class unconditionally and be right in both shapes.

Two smaller things went with the menubar. The palette keybind was left bound
after the palette stopped being mounted, so `⌘K` dispatched into nothing while
`⌘.` still navigated to `/dev` — a keyboard way out of a ceremony whose visible
ways out had just been removed. And the layout mounted five of the
application's own modals inside `<main>`: a `wire:snapshot` is a bearer token
for the component it names, so those endpoints were reachable from a screen
that drew no control for any of them. That last one already had a guard —
`ForcePasswordChangeMiddleware` exempts by payload rather than by route
precisely because the exempt page mounted nine components beside the password
form — which is the tell worth keeping. When a guard has to reason about what a
page *happens* to mount, the page is mounting the wrong things.

The rule renders every surface the enum names, in the state that reaches it,
and reads the result with an HTML parser rather than a pattern. It carries
three defences against going quietly inert: every enum case must resolve to a
registered route, every case must have a row saying how a test reaches it, and
an ordinary page must still draw all seven markers — without that last one the
rule would pass loudest on the day the shell broke everywhere.

## A sweep decided "nothing points at this" on a quarter of the ledger

`counterparties.gc` ran daily on every device, including the phone. It deleted
a counterparty when no transaction had pointed at it for 365 days, and NULLed
`transactions.counterparty_id` on every ledger row that named one it dropped.

The predicate is unanswerable on a local-first device, because each device
holds a partial replica. "No transaction points at this row" and "the
transactions that point at this row have not arrived yet" are the same
observation, and the sweep resolved both towards deleting.

Measured on a paired Mac and iPhone sharing one household ledger: the Mac had
received 35 of the household's 140 transactions. Against that quarter of the
ledger 17 counterparties looked unreferenced, and it deleted all 17. On the
phone 16 of those 17 were referenced — by 52 transactions between them, one
payee carrying 10. Neither device's op log holds a single counterparty delete,
because the sweep announced nothing until #274, four days before this was
written and after every tagged release.

Widening the window would not have helped and neither would narrowing the
predicate to "no transaction references it at all": on the Mac, none did. The
window was never the defect. A delete decided on one replica is a delete of
what another replica is still using, and adding the announcement would have
propagated it — turning a local divergence into replicated data loss.

The job is gone rather than tightened, and
`NoScheduledTaskPrunesUserDataArchTest` walks every scheduled command into the
jobs it dispatches, failing on a `->delete()` against a table of user data or
an `->update()` that sets a column of one back to `null`. It asserts the table
names still exist and that the scheduler resolved before it asserts a clean
result, because a guard that finds nothing must not read as a guard that found
nothing wrong.

Writing it reproduced that failure mode twice. Its first version exempted
`notifications` from a list that never contained it, so the one exemption it
carried was a no-op reading as a decision; and its scan stopped at the first
`->table('x')` in a file, which reported the notification sweep clean because
that sweep plucks the ids in one chain and deletes them in the next. Both are
now asserted against: an exemption has to name a table the guard would
otherwise have caught, and some scheduled task has to actually prune it.

## A test fixture shaped like a secret

`tests/Contracts/ATestFixtureIsNeverShapedLikeASecretArchTest.php`

A test that proves a credential is scrubbed, redacted or consumed needs a
credential to prove it with, and the obvious way to write one is to spell it
out. Three fixtures did: a passphrase and a six-digit PIN in the mobile
credentials setup test, a live-key prefix in the dev-console redaction test, and
a hex digest beside the word `key` in a counterparty provenance test. Each one
matched a rule in the secret scanner's default set — `generic-api-key` twice and
`stripe-access-token` once.

None of them failed the branch that wrote it. The shared security workflow
checks out with `fetch-depth: 0`, which fetches **every ref**, and runs
`gitleaks git .` with no `--log-opts`, so the scan walks the whole repository
rather than the pull request under review. The consequence is the part worth
remembering: the check went red on four to nine *other* contributors' open pull
requests, naming a file and a line their diffs did not contain. Whoever was
reading that failure had no way to reach the cause from it, and a scan of their
own branch reproduced nothing — only `--log-opts="--all"` sees what CI sees.

The remedy that shipped each time is a **runtime-assembled literal** plus an
assertion on the value it produces, never an allowlist entry and never an inline
suppression:

```php
$key = 'sk_'.'test_'.'51H8xQ2Kj3nRtYuIoP0aSdFgHjKlZ';
$hex = str_repeat('a1b2c3d4', 8);
$phrase = implode('-', ['correct', 'horse', 'battery', 'staple']);
```

The value is unchanged, so what the test proved it still proves; the *source
text* no longer carries a contiguous run any rule matches. Which fragment to
split is not arbitrary. A vendor rule keys on its prefix, so `sk_` splits from
`test_`. `generic-api-key` matches a long high-entropy run near a name that says
key, so the run has to be built rather than written — and note that renaming the
variable does not help, because `$expectedKey = '…'` reads to that rule exactly
as `api_key: '…'` does.

The guard has to agree with the gate or it is worse than nothing, so it does not
invent a notion of "secret-shaped". `.gitleaks.toml` here declares
`useDefault = true` and no rules of its own, which means the ruleset CI applies
is the one compiled into the gitleaks binary the workflow downloads — a Go
binary no PHP test can read. So that ruleset is vendored, verbatim and at the
pinned version, at `tests/Contracts/Fixtures/gitleaks/v8.30.1/gitleaks.toml`,
and read at run time by `Tests\Contracts\Support\GitleaksRuleset`;
`Tests\Contracts\Support\SecretShapedValues` reproduces the detection order the
scanner runs, down to the keyword prefilter, the capture group entropy is
measured on, the two allowlist passes, and the rule that drops a generic finding
when a named vendor rule covered the same line. Both halves of the exemption set
are honoured — upstream's own, which is why the vendored file keeps its upstream
basename and is skipped exactly as the configuration at the repository root is,
and the handful this repository adds.

That agreement is measured rather than asserted. Run against the whole working
tree, gitleaks 8.30.1 and the PHP reading returned the same verdict file for
file — including on the one fixture that was carrying a match, which they agreed
on down to the line and the rule id. Over a 45-probe corpus of vendor keys,
generic keys, PEM blocks, JWTs, and the assembled forms that are the remedy, the
two verdict sets are identical, rule id by rule id. Where they can still diverge is written down rather than hoped away:
the scanner runs up to five base64 decoding passes over a fragment and this
reading runs none, it counts entropy per rune where PCRE counts bytes, and the
guard walks the test roots rather than every path in git. All three directions
are false negatives — the guard is quieter than the gate, never louder — which
is the safe way round for a rule whose false positives would be argued with.

Four things keep it from going quietly inert. Every pattern in the vendored
ruleset must compile under PCRE, so a rule that cannot be read is named rather
than left scanning nothing. `.gitleaks.toml` must still extend the defaults and
declare no rules of its own, because the moment it does not, the vendored copy
has stopped describing what CI runs. A dataset of assembled probes asserts both
directions — the shapes that must be found and the remedies that must not be —
against the reader rather than against the tree. And every path exempted in
`.gitleaks.toml` is re-scanned with that exemption withheld: an entry whose file
would no longer fail without it has outlived what earned it, and an exclusion
nobody is auditing is the thing this rule exists to avoid needing.

It found one on the way in. `OpenBankingWizardModalTest` carried the same PEM
header three times, and the private-key rule spans from a `BEGIN` line to a
later `KEY-----` once at least sixty-four characters sit between them — so one
occurrence of that header was inert, and the third made the file a finding.
That is the quiet shape of this defect: nothing about the line that finally
trips it is different from the two that did not.

## A gate with no configuration refused a shipped deployment shape

`Modules/Core/tests/Feature/TheBoundaryWidensOnlyByRecordTest.php`

`LoopbackOnly` threw not-found on every non-loopback `SERVER_ADDR`, with no
configuration, no environment flag and no carve-out of any kind. The platform
matrix lists **Self-hosted** as shipped and describes it as reached over the
household's own network, and `TrustedHostGuard` — prepended on the very next
line — allow-lists `APP_URL`'s host with a comment saying a self-hoster reaches
it under that name. One middleware refused the request the next one was written
to admit, and the disagreement sat in the tree unnoticed because the half that
refused runs first, so the half that would have allowed it never saw a request
to judge.

The shape was not merely awkward, it was unreachable except by accident: a
reverse proxy bound to loopback on the same machine passes, because the proxy's
own connection reports a loopback bind address. Every documented path that binds
a real interface 404'd.

The second half is the one no amount of reading the gate would have found.
FrankenPHP — the runtime `deploy/server/Dockerfile` ships — registers
`REMOTE_ADDR`, `SERVER_NAME`, `SERVER_PORT` and `HTTP_HOST`, and **no
`SERVER_ADDR` at all**. So the documented Docker recipe did not fail the address
comparison; it fell past it into the branch for a SAPI that never advertised
where it bound, and failed closed there. `migrate` succeeded, three containers
stayed up, and the app answered nothing. Matching interfaces alone would have
left it exactly as broken, which is why the fix had to name the runtime
(`PhpSapi::FrankenPhp`) as well as widen the rule.

Two invariants came out of it. **The widening names interfaces and cannot spell
"everything"**: literal addresses only, and a wildcard, a CIDR range or a
hostname is dropped and named back by `beatrax:doctor` rather than expanded or
resolved — all three wildcard spellings (`0.0.0.0`, `::`, `::ffff:0.0.0.0`)
collapse to one all-zero key, so one test covers them. **Where the runtime
publishes no bind address there is no interface to check**, so the recorded
`APP_URL` host stands in for one — and only where it names something past
loopback, because a caller on the LAN writes its own `Host` header and
`localhost` is the one it would write.

Both halves of the boundary now read a single `NetworkBoundary`. Two rules about
one boundary, held in two classes, will disagree; the only question is how long
before anyone notices.

## A guard that reads before the write it guards

`Modules/Core/tests/Feature/OneOpenAlertCannotBeWrittenTwiceTest.php`

Two `oauth_scrub_set_failed` rows, byte-identical down to the metadata, written
at the same second on a desktop that had just restarted. `OAuthScrubSet` has a
guard for exactly this — `alertAlreadyOpen()` asks whether a row of the kind is
already open and returns without writing if one is. It held on every pass it was
ever tested on, because a test runs one process.

A desktop boot is not one process. NativePHP spawns the sync listener and the
relay listener as `ChildProcess::artisan` children and supervises a `queue:work`
alongside the server, and each of them boots the whole application, installs the
Monolog tap, and loads the redaction set from the same SQLite file. Two of them
ran the SELECT before either ran the INSERT, and a SELECT cannot see an INSERT
that has not happened yet.

The evidence names the process count without anyone having to watch it. The
install held two `oauth_secrets` rows, gmail and microsoft, both encrypted under
a superseded key. Both duplicate alerts carry `"provider":"gmail"` — the SAME
row — and `OAuthScrubSet` keeps a per-row in-process flag that lets one process
report one row exactly once. Two alerts for one row is therefore two processes,
not one process going round twice. The microsoft row raised nothing at all in
either process, which is what the guard looks like when it works: by the time it
was reached, the gmail row's alert was committed and visible.

Nothing about that is specific to redaction. Every "raise this one only once"
in the tree was a read followed by a write with no constraint between them:

| Writer | Kind | Rule it wanted |
|---|---|---|
| `OAuthScrubSet` | `oauth_scrub_set_failed` | one open row |
| `SurfaceWorkerCrashAlert` | `worker.crashed` | one open row |
| `HealthCheckListener` | `wal_mode_missing`, `synchronous_misconfigured` | one an hour |
| `BackupFreshnessProbe` | `backup_overdue` | one an hour |
| `SystemAlertWriter::raiseOnceForUser` | 4 owned kinds | one open row per reader |
| `AcknowledgeSystemAlert` | the acknowledgement row | one per (alert, reader) |

The last one is the same shape with the opposite ending: it HAD a unique index,
and the second write threw. Two taps on a phone are two requests, both pass the
"has this reader dismissed it?" read, and the second answered 500 on a button
whose entire job is to make a warning go away.

**The read is the policy; only a constraint is the guarantee.** `system_alerts`
now carries a nullable `dedup_key` under a UNIQUE index, and a writer that means
"once" names one. NULL is the other half of the rule and not an oversight —
SQLite counts NULLs as distinct, so leaving the key unset is how a writer says
this row is meant to repeat. Each failed recovery attempt and each corrupt
backup is its own row, and none of them is keyed.

Two things the constraint is deliberately not allowed to do. It must not
**throw**: `OAuthScrubSet` writes from inside a Monolog processor, so an
exception there would crash every request that emits a log line — a worse
outcome than the duplicate. `SystemAlertWriter` catches
`UniqueConstraintViolationException` and answers `null`, the same answer its
pre-check already gives. And the key must not **travel**: an owned alert reaches
the peer through the op log, and a key both devices computed the same way would
be a collision the receiver could only quarantine, so `storedRow()` strips it
beside the `id`. `RecordUpdateAvailableAlert` had already found the shape of the
answer from the other end — a derived id plus `insertOrIgnore` — for the one
kind where the release version made a natural key.

A key that names the OPEN row has to be released when the row closes, and that
release cannot live in the acknowledge action. Four writers stamp
`acknowledged_at`: the action, both reconnect paths in `ConnectInboxFromGrant`,
and the applier — where a peer's dismissal arrives as a raw UPDATE and reaches
no PHP of ours at all. Miss that one and the local row stays keyed after a
remote acknowledgement, and the device silently never raises that kind again.
It is a trigger on the column instead, beside the severity rails the table
already carries.

Adding the index moved a neighbouring guard without touching it.
`ATableTakingIdsFromTheSequenceIsNeverCapturedTest` treats a non-pk UNIQUE index
as proof that a travelling table has a natural key both devices compute the
same, so its pk need not come from the sequence — and `system_alerts` silently
dropped out of the at-risk list the moment it carried one. It is exactly as
at-risk as before: this key is NULL on most rows and never crosses. **A UNIQUE
index is not automatically a cross-device identity**, and the ones that are not
are now named in that guard with the reason, so the table stays under it.

Three test fixtures hand-write `system_alerts` rather than migrate it, and all
three went on describing the table as it was. The write then failed on `no
column named dedup_key` inside the very `catch (Throwable)` that exists to keep
an alert-write from taking down the thing it reports on — so seven tests read
"no alert was raised" and none of them said why. A fixture that spells out a
production table owes it every column the production writer sends.

The reader is the last stop. Rows written before the key existed are still in
the field, so the banner drops a row that is indistinguishable from one above it
— same kind, same severity, same stored sentence, same copy line, same minute.
A duplicate that reaches a reader costs more than its own row: a critical
sentence said twice is one nobody believes the third time.
## A skip that no job could answer

`tests/Contracts/EverySkipNamesAJobThatRunsItArchTest.php`

A skipped test is printed in the same summary line as a passing one, in a colour
nobody reads, and counted in the same total. So a test whose capability gate no
job can answer reports, in every run the project has ever looked at, exactly
like a test that holds — and it holds nothing. It is worse than deleting it,
because deleting it lowers a number and this does not.

The pipeline had already met the shape once and fixed it in one place. The
docs-symbol rule reads both Composer roots' classmaps, and no job installed both,
so it skipped in 100% of runs while its file reported green; the fix was to
install the repo root inside the `mobile-app quality` job and fail its step if
the word `skipped` appears at all. That is a correct guard for one rule and
tells nobody about the next one.

Measured across the whole tree, the gate reported 32 skips over 52 markers, and
seven of the 32 ran nowhere. Four of the seven were the launchd plist tests,
retired by `PHP_OS_FAMILY !== 'Darwin'` while every job runs on ubuntu — so the
plist substitutions, the `--without-redis` branch and the `launchctl bootstrap`
call had never been asserted anywhere, and neither had the refusal that every
non-macOS host actually takes. One was a Redis ping with no Redis in the
pipeline and none coming, Horizon being a dev-only dependency of the Dev Console.
One looked for an mDNS publish binary the runner did not carry. The last is the
sharpest: `HostPipeWatchTest` asked the **runner** whether its own stdin was a
pipe, and a paratest worker is spawned with pipes on every descriptor — so under
`--parallel`, which is how every job runs, the one assertion that the watch stays
silent when there is no host never ran once.

Each of the four needed a different answer, and the choice is the point. Give the
job the capability (`avahi-utils` on the runner). Build the condition instead of
inheriting it (a child handed `/dev/null` on stdin). Make the environment a seam
the test drives (the host OS, beside the two overrides the launchd test already
had). Or delete the test and say why, where the capability is out of scope by
decision rather than by omission.

The pin is `.github/test-skip-budget.json`: every file carrying a marker, its
reason, and the job that runs the tests it retires. `runs_in` has deliberately no
value meaning "nowhere", so a gate no job can answer cannot be written down — it
has to be fixed. `.github/scripts/skip-budget.py` then holds the claim in the job
it names, reading the JUnit report each test job writes: the file has to be
collected there and skip nothing there, and a count that moved *down* is as much
a stale pin as one that moved up. The arch test holds the other end, because a
marker that fires on nobody's runner is invisible to a report and still has to be
accounted for.

## A health page nobody here wrote that fetched from two CDNs

`tests/Contracts/NothingShippedFetchesFromAThirdPartyHostArchTest.php`

`bootstrap/app.php` passed `health: '/up'` to `withRouting()` — Laravel's
one-line way of getting a liveness route. The route it registers renders the
framework's own `health-up.blade.php`, and that page opens a preconnect to
`fonts.bunny.net`, pulls a stylesheet from it, and loads Tailwind from
`cdn.jsdelivr.net`. A product whose claim is a zero-by-default outbound surface
shipped a page that talks to two strangers, and both learn the reader's IP
address and the moment they looked.

Nothing was misconfigured. The line is correct Laravel and had been in the tree
since the application was generated. What it opted into was never read, because
the thing it opted into is not in this repository.

Three separate reasons nothing saw it:

- **Every markup guard skips `vendor/`.** `BladeHrefResolvesArchTest` walks
  `Modules/` and `resources/` and drops any path containing `/vendor/`, because
  a hand-written href inside a package is not ours to fix. That is right for
  that rule, and it means a first-party scan can never see a page this
  application nonetheless serves.
- **No rule looked for an external host at all.** The nearest neighbour,
  `ThirdPartyContainmentArchTest`, contains third-party *namespaces* — PHP
  imports — and has nothing to say about a hostname in an attribute.
- **The CSP made it look handled.** `default-src 'self'` does refuse the
  stylesheet and the script in a browser enforcing it. But a `preconnect` is a
  resource hint rather than a fetch, so the fetch directives do not govern it
  and the DNS lookup and TLS handshake are not prevented; and the header is
  withheld entirely while the Vite dev server is hot. A backstop in the reader's
  browser is not the same claim as not shipping the address.

The second half of the same page is quieter. It prints `Response rendered in
{{ round(…) }}ms`, so its body differs on every call — and a probe endpoint's
whole job is to answer something a caller can compare against a known value.

The resolution was to stop routing to it rather than to fix it. This application
already answers `/health`: JSON, five keys, no timestamp, auth-free, and since
the boundary widening it reports whether that boundary is open as a single word
and never as an interface list. Two health endpoints is two answers to one
question and only one of them was ours, so `health:` is gone from both bootstrap
roots and `/health` is the one that remains.

The rule that replaced it has four parts, because the failure had two halves and
the blind spot was a third thing again. It reads *attributes off parsed
elements* — the ones a browser fetches without being asked — rather than hunting
`https://` through template text, which is why the wizards' links to the Google,
Entra and Enable Banking consoles stay green and an `xmlns` is never mistaken
for an address. It scans bundled CSS and JS on the same terms. It asserts that
neither bootstrap root asks the framework for a health page, which is the only
way to reach a template the first two parts cannot see. And it asserts the route
table holds exactly one probe URI, answered by a controller in this repository.

## A test that pinned the contradiction it should have caught

`tests/Feature/ServerDeploymentConfigTest.php`

ADR-0022 settled it in July: SQLite is the only supported database, in every
deployment shape, and the PostgreSQL and MySQL options in the deployment guide
were withdrawn because they described something that could not work — thirty-two
migrations use `RAISE(ABORT)` enum-guard triggers and search is an FTS5 virtual
table, so `migrate` against a server database fails on the first substantive
table.

The guide's callout was updated. Five other places were not:

- `.env.example` carried a commented block headed "Server deployment (Postgres /
  MySQL / MariaDB)" telling the reader to uncomment it.
- The configuration reference two hundred lines further down the same guide
  still listed `pgsql`, `mysql` and `mariadb` as values for `DB_CONNECTION`.
- `config/database.php` still defined all three connections.
- `deploy/server/Dockerfile` still installed `pdo_pgsql` and `pdo_mysql` into
  the shipped image — two runtime extensions for a shape the schema refuses.
- `beatrax:setup`, the interactive command `.env.example` points the operator
  at, still offered **"PostgreSQL (recommended for a server)"**, wrote
  `DB_CONNECTION=pgsql`, opened a connection, reported `Database connection OK
  (pgsql 16.4)` — and then handed off to `beatrax:install`, which died on the
  first table. The operator got a confident green from the step that could not
  detect the problem and a syntax error from the step that could.

And the reason none of it was noticed: a test asserted it. `it('defines the
server database connections so DB_CONNECTION can select them')` walked all three
drivers and passed, because they *were* defined. It tested that the option
existed, never that choosing it worked, so the suite stayed green over a
contradiction the ADR had already resolved.

That test is now the guard, inverted. It asserts that the configured engines are
`['sqlite']` and nothing else, that no `.env` an operator copies offers a key
that only means something to a networked database — commented lines included,
because a commented `# DB_HOST=` under "uncomment this" is an offer — that no
string literal in the setup command names a withdrawn engine, and that the
server image installs no PDO driver but `pdo_sqlite`.

It reads values rather than text, which matters: the deployment guide and the
compose header both name PostgreSQL in order to say it does not work, and a
rule that grepped for the word would have to be switched off for the two files
that explain the rule.

And then the guard went red on the fix, which is the part worth keeping. Deleting
the three connections from `config/database.php` did not remove them. The running
application still had `pgsql`, `mysql` and `mariadb` — plus `sqlsrv`, which this
repository never defined at all.

`LoadConfiguration` merges the framework's own `config/database.php` over ours
key by key, and `connections` is one of the options it merges a second time at
the inner level, so a connection deleted from our file is handed straight back by
the framework default. `DB_CONNECTION=pgsql` still resolved to a working
configuration, which then died thirty-two migrations later on a trigger
PostgreSQL cannot parse — the same failure the ADR had already described, reached
by a route the ADR's own remedy did not close.

Null is what removes a connection. `DatabaseManager::configuration()` reads a
null as "not configured" and says so before anything opens, which turns a
mid-migration syntax error into one sentence at boot. All four engines the
framework ships a default for are withdrawn that way, and the rule pins them by
name so a framework upgrade adding a fifth goes red here rather than quietly
reopening the option.

The general shape: **a config file is not the configuration.** Anything that
asserts on what an application is configured to do has to read the merged result
the framework hands back, not the file this repository happens to own. Asserting
on the file would have agreed with the deletion and been wrong.
Its first run found two defects in the reader itself, and how it found them is
the property worth keeping. Pest writes the JUnit `file` attribute as
`<path>::<test description>` and the path half is relative to whichever root the
run started from, so a reader cutting an absolute path at `/Modules/` produced a
distinct key per test — and the check failed on "the budget has a count and the
job collected the file not at all" rather than quietly comparing nothing. The
second was the shard split: a shard collects only its own testsuites, so the
three reports have to meet in the `quality` job that already collapses them
before "ran elsewhere" can be told from "ran nowhere". A guard that cannot read
its input has to say so, which is the same rule it enforces on everyone else.

## Related

- [Writing an arch invariant](arch-invariants.md) — the mechanics every rule in
  `tests/Contracts/` shares, and why the rationale belongs in the failure message
- [Module boundaries](../architecture/module-boundaries.md) — the largest single
  group of invariants
