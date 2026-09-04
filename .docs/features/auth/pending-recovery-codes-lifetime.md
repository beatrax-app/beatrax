# The pending recovery codes live one request at a time

Signup mints ten recovery codes, hashes them, and hands the plaintext to the
session so the next screen can show them. They are the only way back into an
account, and they are shown exactly once — so the interesting question is not
where they are written but when they stop being readable.

`Modules\Auth\Public\Recovery\PendingRecoveryCodes` is the one home for that
answer. Everything that touches the plaintext goes through it: `SignupAction`
stores, the two ceremonies read and renew, and finishing forgets.

## Why ordinary session data was the wrong shape

Ordinary session data lives until something deletes it. That works when the
screen holding it has an exit the server sees — the desktop ceremony's
"Continue" is a `wire:click`, and it forgets the codes on the way past.

The mobile import ceremony has no such exit. Its way onward is a plain `href`
into pairing, because the Livewire round-trip it replaced returned 419 on
device and took the codes with it, and because `wire:navigate` wedges the
Android WebView (see
[../mobile/architecture.md](../mobile/architecture.md)). A plain link means no
call of that component's ever runs again. Nothing was left to do the
forgetting, so the codes stayed in the session for the rest of its life —
readable by every later request, long after the reader had moved on.

Wiring the button was not the fix either: it would cover the reader who taps
it and no one else. A reader who navigates away, or who taps nothing at all,
takes a path no button can see.

## The shape that survives a full page navigation

The codes go into the session's **flash bag**. Laravel ages that bag on every
`Store::save()`: what was marked "old" is forgotten, and what was marked "new"
becomes "old". A value therefore survives exactly one request past the last
one that asked to keep it.

So the ceremony renews, and everything else does nothing:

| Request | What renews | State after it |
|---|---|---|
| The Livewire submit that signs up and renders the codes | `renew()` in `render()` | pending |
| A reload of `/mobile/import` showing the codes again | `renew()` in `render()` | pending |
| `GET /mobile/recovery-codes/export` (the share sheet) | `renew()` | pending |
| A sub-resource the ceremony page fetched for itself | nothing, but exempt | pending |
| Anything else — pairing, the welcome screen, a stray poll | nothing | **forgotten** |

The sub-resource row was not there to begin with, and it cost the desktop
ceremony everything. A page is not one request: `x-core::pwa-head` asks for the
manifest, the icon, a PWA icon and the splash, and the layout registers a
service worker on `load`, which fetches `/sw.js`. All five are routed, all five
carry the session, and each one aged the flash bag. So the codes were gone a
moment after the screen finished painting — and the reader who then ticked "I
have saved these codes somewhere safe" got a 404 and a redirect into the wizard
instead of the "Continue" they had earned.

The exemption is the same one the Livewire update already had, for the same
reason written down there: none of these is a reader going somewhere. What made
it invisible is that no test asks for a page the way a browser does. Every test
of this ceremony drove the component directly, where sub-resources do not exist,
and every one of them passed.

The renewal is opt-in, so a new screen that forgets to opt in loses the codes
early — visibly, on the screen that needed them. The other way round is the
failure this replaced: a new exit that forgets to clean up, silently.

## What this does not claim

Between the display and the next request the codes are still in the store. No
request can read them without renewing them, and the session copy is
encrypted at rest, but the bytes are there until something asks for a page.
Bounding it further would mean consuming the codes inside the display request,
which is exactly what breaks the share-sheet export: that is a second request,
and a failed export must not cost the reader their account.
