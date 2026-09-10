# Secrets and the Livewire wire snapshot

Livewire keeps a component's state on the server and ships a serialised copy of
it — the *wire snapshot* — to the browser with every render and every round
trip. Three surfaces go into that snapshot: every **public property**, every
**`$listeners` key**, and every **`$queryString` entry**.

Anyone with browser devtools can read the snapshot. A secret that reaches it is
published, whatever the Blade template does with the value. Marking an input
`type="password"` changes nothing: the DOM hides the characters, the snapshot
does not.

This matters here because the app is a household finance tool holding OAuth
tokens for a user's mailbox and the password hashes of everyone on the device.
A component that innocently binds `wire:model="oauthSecret.access_token"` to
render a "connected as…" panel would leak a live mailbox token to anyone who
opened devtools on a shared laptop.

## The registry

`Modules\Core\Public\Services\SecretsColumnRegistry` is the single list of
secret-bearing columns, in `table.column` form:

- `oauth_secrets.access_token`
- `oauth_secrets.refresh_token`
- `oauth_secrets.client_secret`
- `users.password`
- `users.remember_token`
- `user_recovery_codes.code_hash`

It exposes both a static accessor (`SecretsColumnRegistry::columns()`) and an
instance method (`->all()`) so callers that resolve it through the container get
the same list; a contract test asserts the two stay in step.

Adding a secret-bearing column anywhere in the schema means adding it here. The
registry is what the enforcement below reads, so a column absent from it is a
column nothing is watching.

## The invariant

`tests/Contracts/SecretsInLivewireSnapshotTest.php` walks every concrete
`Livewire\Component` subclass under `Modules/*/…/Http/Livewire/` — Internal and
Public alike — with `ReflectionClass`, and fails the build when a component
names a registry column on any of the three snapshot surfaces.

Two different matchers, because the surfaces differ in shape:

- **Public properties** match on *substring*, against both the bare column name
  and its camelCase form. `access_token` and `accessToken` both match, so
  `$oauthAccessToken` is caught. Only properties whose declaring class sits under
  `Modules\` are considered — reflection enumerates the full inheritance chain,
  and Livewire's own base classes declare nothing relevant.
- **`$listeners` keys and `$queryString` entries** match on *exact equality*
  with a full `table.column` registry entry, and only when the component itself
  declares the property rather than inheriting a framework default.

Three deliberately-violating fixtures under
`tests/Contracts/Fixtures/Livewire/` — `SyntheticPublicPropertyViolator`,
`SyntheticListenerViolator`, `SyntheticQueryStringViolator` — prove each matcher
still fires. They live outside `Modules/` so the production walk never sees them.

## The allow-list, and the one thing that justifies an entry

An entry is justified when **the value in the snapshot is what the user just
typed into the form**, not stored data the app would otherwise have kept private.

A sign-in form's password field is already in the browser: the user's own
keystrokes put it there. Its presence in the snapshot is isomorphic to the form
existing at all, and no stored credential is disclosed. That is the whole
argument, and it is the only argument that works. Echoing a *stored* value —
rendering the saved `client_secret` back into the wizard so the user can see what
is configured — is the case the invariant exists to stop, and no wording of the
allow-list makes it safe.

The components currently allow-listed are all of that first kind: the sign-in,
sign-up, change-password, reset-password, add-user and admin-sets-partner-password
forms; the app-lock and delete-account confirmations, which re-type the account
password to authorise a security downgrade or an irreversible delete; the
BYO-OAuth wizard, where the user pastes their own `client_secret`; and the mobile
import bootstrap, which creates the first account on a new device. Ten components
hold a password or passphrase this way, and that is the pattern, not an oversight:
the argument above is what each of them rests on.

### The app-lock code is not on that list, and the argument is why

The argument above is that the value in the snapshot is what the user just
typed, so its presence discloses nothing the browser did not already hold. It is
sound for a password and it does not reach the app-lock code, which is a
*second* gate: the whole point of the lock is that it is not the account
password, so a screen that puts the code on the wire hands over the thing the
lock exists to keep back.

The lock screen never did. Digits accumulate in the pad's Alpine scope, the pad
renders bullets rather than a control anything reads back, and the code crosses
once as a `submit()` argument. The settings screen bound five of them with
`wire:model` — `newPin`, `confirmPin`, `currentPin`, `enrollPin`, `deenrollPin`
— and now does the same as the pad: each panel carries its own Alpine scope, the
inputs are `x-model` and `autocomplete="off"`, and the action takes the code as a
method argument. The account password beside them keeps its `wire:model`, on the
argument above, which is the argument it actually fits.

`tests/Contracts/ACodeIsNeverAComponentPropertyArchTest.php` reads the templates
rather than the components, because a `wire:model` target IS a property and
Livewire can bind to nothing else. It judges the element the binding sits on: a
name cannot separate a code from a panel named after one, and
`confirmingChangePin` is the boolean that opens a modal. It admits no exceptions.

`MobileImportBootstrap` was the one site argued for keeping, on the grounds that
the code there is being *chosen* on a device with no lock yet. What defeated the
argument was the branch: `reportBrokenFieldRules()` returns above every line that
empties a box, so a mistyped confirm field — the everyday event on a five-box
phone form — rendered the snapshot with the chosen code still in it. Its two code
boxes are now an Alpine scope, and unlike the settings panels they are *not*
blanked on submit, because leaving a rejected form to be retyped is the cost that
screen was built to avoid.

Several of those go further than the argument requires, and the extra step is
worth knowing about when editing them:

- `ResetPasswordPage` clears its fields after any error, so a failed attempt does
  not leave plaintext in the next snapshot.
- `DeleteAccountSection::cancel()` zeroes the password when the confirmation is
  abandoned.
- `MobileImportBootstrap` zeroes the password and its confirmation the moment
  `submit()` consumes them; its retry path re-reads from a server-side session
  stash rather than from those properties. The stash holds plaintext, which is
  what `config/session.php` encrypting the session at rest is carrying.
- `OAuthClientWizardModal` hands the pasted plaintext to the file sink only after
  `submit()` validates — the wizard never reads an existing secret back.

## Related

- [Module boundaries](module-boundaries.md) — the `Public`/`Internal` split the
  component walk relies on
- [`auth` module](../features/auth/architecture.md) — password, recovery-code and
  app-lock flows
- [`email-scan` module](../features/email-scan/architecture.md) — where the OAuth
  secrets come from and how they are stored
