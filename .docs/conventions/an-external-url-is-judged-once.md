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

## Why this is not a browser tab

The desktop shell does not call `suppressNewWindows()`, so `target="_blank"`
opens **another window of this application** — same preload, `sandbox: false` —
rather than handing the address to the user's browser. And a notification deep
link is applied with `Window::get('main')->url(...)`, which replaces the address
of the main window; no `preventLeaveDomain` handler is installed.

So an outside URL here is not "a page in a tab". It is a page inside the
application's own frame, on a machine that also serves the application over
loopback and answers on the LAN.

## What the gate refuses, and in what order

The order is load-bearing: each answer names a cause the ones before it have
ruled out, so a refusal never blames a scheme the check already accepted.

| Refusal | What it catches |
|---|---|
| `NotHttps` | anything that is not an absolute `https://` URL — `http:` downgrades the connection, and `javascript:`, `data:` and `file:` are not connections |
| `Malformed` | control characters (a bare CR ends the log line, not the URL), a length past 512, or a shape the parser refuses |
| `CarriesCredentials` | `https://github.com@example.test/` — reads as GitHub, resolves to example.test, and a general URL validator accepts it |
| `HostIsNotPublic` | an address literal, `localhost`, a `.local`/`.internal` name, or a name with no dot: all of them point back at the reader's own machine or network |
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
