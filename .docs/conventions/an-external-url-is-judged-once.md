# An external URL is judged once

A URL this codebase did not write is judged by
`Modules\Core\Public\Support\ExternalUrl` and by nothing else. Everything below
is why that gate is stricter than "must be https", and which sites deliberately
do not use it.

## What supplies one

Four channels, and only the first two end up as something a reader clicks:

| Channel | Reaches | Judged at |
|---|---|---|
| `resources/corpus/support/*.yaml` | the support card on a counterparty profile, and the action button on the savings-insights card | `SupportResourceProvider::url()` |
| `resources/corpus/merchants/*.yaml` | the `community_merchant_mappings` contact columns | `MerchantContactReader::url()` |
| `BEATRAX_GITHUB_ISSUES_URL` / `BEATRAX_GITHUB_COMPARE_BASE` | the onboarding help link and the corpus-contribution flow | `OpenExternalUrlAction`, with a host allow-list |
| A Microsoft Graph `deltaLink` | a background fetch, never a link | `ScanCursor`, which pins the exact vendor host |

The corpus files are contributed. A pull request against them is the supported
way to add a merchant, so the strings in them are written by people outside this
project and have to be treated that way.

## What counts as a public host

`Modules\Core\Public\Support\PublicHost` answers it, and it is the only place
the rule is written. It fails **closed**: a host is public when it positively
looks like one, never because nothing recognised it.

- An IP the platform can parse must pass `NO_PRIV_RANGE|NO_RES_RANGE`.
- Anything else must match a strict LDH name of at least two labels whose last
  label is alphabetic. That single rule rejects every numeric notation at once —
  `0177.0.0.1`, `127.1`, `0x7f.0x0.0x0.0x1`, `2130706433`,
  `[::ffff:127.0.0.1]` — and rejects a bare LAN name with no dot in it.
- The name must not end in a suffix a resolver answers from the local network:
  `.local`, `.localhost`, `.internal`, `.home.arpa`, `.invalid`, `.lan`,
  `.intranet`, `.corp`, `.private`. None of the last four was ever delegated.

The predicate lived in two files at once — here and in
`OpenBanking\Internal\Actions\StartBankConsent` — and only the second copy was
ever corrected. This one fell through to "contains a dot", which answered
*public* for all five notations above.

`ExternalUrl` adds one clause of its own on top: it refuses an address literal
even where it routes, because a merchant's contact page is never a bare address.
A bank's SCA host may be, so `StartBankConsent` does not add it. That is the
only point at which the two callers differ, and it is stated in both.

## Why this is not a browser tab

The desktop shell does not call `suppressNewWindows()`, so `target="_blank"`
opens **another window of this application** — same preload, `sandbox: false` —
rather than handing the address to the user's browser. And a notification deep
link is applied with `Window::get('main')->url(...)`, which replaces the address
of the main window; no `preventLeaveDomain` handler is installed.

So an outside URL here is not "a page in a tab". It is a page inside the
application's own frame, on a machine that also serves the application over
loopback and answers on the LAN.

## What the shell around it is

Reviewed 2026-09-11, because two things this page reasons about had never been
read. Both hold:

- **The renderer's web preferences.** NativePHP's `webPreferences.ts` puts
  `contextIsolation: true` and the preload path in `requiredWebPreferences`,
  which are spread **after** anything a caller passes — so no window this
  application opens can weaken either. `nodeIntegration` is false,
  `webSecurity` is left at its default of true, and
  `allowRunningInsecureContent` is never set. `sandbox: false` is the package's
  own fixed choice, and it is the reason the section above is written the way
  it is.
- **The desktop PHP server's bind.** `php -S 127.0.0.1:{port}`, and the
  NativePHP API server listens on `127.0.0.1` too. Loopback only, which is the
  premise `LoopbackOnly` is written against.

`rel` is the other half of `target="_blank"`, and
`OneGateJudgesAnExternalUrlArchTest` now holds it: without `noopener` the opened
window keeps `window.opener` on the one that opened it, which in this shell is
another window of this application; without `noreferrer` the third party is told
which screen the reader left from. Three links carried only `noopener`.

## What the gate refuses, and in what order

The order is load-bearing: each answer names a cause the ones before it have
ruled out, so a refusal never blames a scheme the check already accepted.

| Refusal | What it catches |
|---|---|
| `NotHttps` | anything that is not an absolute `https://` URL — `http:` downgrades the connection, and `javascript:`, `data:` and `file:` are not connections |
| `Malformed` | control characters (a bare CR ends the log line, not the URL), a length past 512, or a shape the parser refuses |
| `CarriesCredentials` | `https://github.com@example.test/` — reads as GitHub, resolves to example.test, and a general URL validator accepts it |
| `HostIsNotPublic` | an address literal, `localhost`, a reserved suffix, or a name with no dot: all of them point back at the reader's own machine or network |
| `NonDefaultPort` | any port but 443 — a contact page is served where the web is |
| `HostNotAllowListed` | only for callers that have a finite list. Opening has one (`github.com`); a rendered corpus link cannot |

## What happens to a refused URL

Never silently. Three things, in this order:

1. It is logged, naming the entry and the refusal.
2. `SupportResource::$withheld` records the corpus field it came from, and the
   support card renders a chip that does not link. A route the corpus holds and
   this application will not follow is something a reader can act on; a chip
   that simply disappears is indistinguishable from a merchant that never
   published one.
3. `BundledCorpusIntegrityTest` judges every URL the bundle ships. A contributed
   entry the gate would refuse fails the build rather than reaching a reader as
   a link that quietly is not there.

## The sites that deliberately do not use it

Two, and converting either would be wrong:

- **`Modules/Sync/Internal/Transport/Relay/RelayConfig`** accepts `http://` on a
  private or loopback host on purpose. That relay is the desktop's own, reachable
  only from this LAN, and it is the out-of-box pairing path. It refuses plaintext
  to a *public* host, which is the same judgement made for a different threat.
- **`Modules/EmailScan/Public/Dto/ScanCursor`** pins the literal prefix
  `https://graph.microsoft.com/`. That is stricter than the gate, not laxer, and
  the value is followed by an HTTP client rather than rendered.

## The guard

`tests/Contracts/OneGateJudgesAnExternalUrlArchTest.php` holds four invariants:
no Blade template tests a URL scheme for itself; `openExternal()` has one caller;
`Window::get(...)->url()` has one call site; and both corpus readers ask the gate.

Run against the tree as it stood before the gate existed, the first of those
names the two templates that admitted `http://`.

## Related

- [Invariants from shipped failures](invariants-from-shipped-failures.md)
- [Architecture](../architecture/00-index.md)
