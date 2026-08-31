# A public Livewire method is a public endpoint

Livewire dispatches `/livewire/update` calls by method name against the mounted
component. It checks that the method is public and that it is not one of the
framework's own — nothing else. So every public method on a component is
callable by anything that can forge that request, whether or not a control on
the page ever calls it.

That gives an unreachable public method two costs at once, and the second is
the one that gets missed:

1. **A feature no reader can use.** The code implements something; no control
   reaches it. It looks maintained, it passes review, and it does nothing.
2. **An endpoint nobody meant to publish.** A category mutator, a redirect, a
   session clear — reachable by a crafted request from a page that offers no
   such button.

`tests/Contracts/EveryWireCallableMethodIsReachableFromTheUiArchTest.php` holds
this closed. The scan is in
`tests/Contracts/Support/WireCallableMethods.php`.

## What counts as a caller

The guard is deliberately generous, because a false accusation of dead code is
worse than a missed one: a reader who has seen the guard cry wolf once stops
reading it. A method is reachable if its name appears

- anywhere in a Blade template or a shipped script — `wire:click`, an
  `x-on:click="$wire.markRead()"`, an Alpine expression, a `@js()` payload;
- anywhere in production PHP as a call **or as a string literal** — a
  `protected $listeners` array and a `toastWithUndo(undoAction: …)` payload
  both reach a method through a string that does not look like a call;
- or on the component itself, called by one of its own methods.

Livewire's own protocol is exempt: `mount`, `render`, the `boot`/`hydrate`
family, the per-property `updatedFoo()` hooks, and anything carrying `#[On]`
or `#[Computed]`.

## The callers a grep cannot see

These six were the trap set the guard was tuned against. Each is reached by
something that does not read like a call, and a guard that reports any of them
is wrong:

| Method | Reached by |
|---|---|
| `NotificationsPage::markRead` | `x-on:click="$wire.markRead()"` on the row anchor |
| `DriftPage::undoAnomalySuppression` | a `toastWithUndo(undoAction: …)` payload the toast host calls back |
| `ForecastPage::onBufferSaved` and its two siblings | a `protected $listeners` array, not `#[On]` |
| `TransactionsList::isSearchActive` | the component's own `render()` |
| `OpenBankingSettingsPage::requestEnable` and `OpenBankingSettingsPage::enableOpenBanking` | its own `toggleClicked` / `reconfirmEnable` |
| `SharedListSettingsPanel::toggleUpdateOnAppUpdates` | nothing — and that is the point |

The last one is the shape the allow-list exists for. The checkbox is disabled
in the markup, and this deliberate no-op is what stops a forged call writing
the column: the method has to stay public and has to stay callerless. An
allow-list entry says *why*, or it does not go in.

## Not covered

A name generic enough to appear somewhere unrelated — `save`, `open`, `close` —
is reachable to this guard whether or not a control calls it. That is the
chosen direction of the error. Narrowing it means modelling Blade, and a
model of Blade misses the one spelling nobody thought of.
