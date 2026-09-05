# Force a password reset

When a user has lost both their password and their recovery codes, the
`beatrax:reset-password` artisan command is the CLI fallback. It is the last of three
roads back in: the recovery-code-then-self-serve flow on the login page, the owner
resetting a partner's password from `/settings/users/{username}`, and then this one,
which is the only road that works when the account with nothing left is the owner's.

The command is destructive well beyond the password hash. It also stamps
`force_password_change_at_next_login`, revokes every session the user holds, and marks
the app-lock recovery wrap stale — that wrap opens only under the password it was built
from, and this road holds no old password to re-wrap with. It is stamped rather than
cleared, deliberately: clearing would foreclose the one password that still opens it.
OAuth tokens and other per-user secrets are untouched.

## When to use this

- A user has forgotten their password and exhausted (or lost) their recovery codes.
- An owner needs to reset a partner's password after the partner asked for one.
- The owner has lost their own credentials and there is no other owner to perform the
  reset through the in-app owner-resets-partner flow.

It is **not** the right tool for:

- Routine password change. Use the in-app Settings → Password flow instead.
- "I forgot my password but I have a recovery code." Use the recovery-code flow on the
  login page.

## Prerequisites

- An **interactive** shell on the machine running Beatrax (the developer's local dev
  machine, or the host where the desktop bundle's data directory lives). The command
  refuses to run without a TTY.
- The username (not the email) of the account to reset.
- A new password of at least twelve characters (`PasswordPolicy::MINIMUM_LENGTH`).
  The command always prompts for it; there is no flag to pass one in.

## The command

```sh
php artisan beatrax:reset-password <username>
```

It prompts twice and echoes nothing:

```text
$ php artisan beatrax:reset-password owner
 New password:
 ****************
 Confirm new password:
 ****************
 Password updated for owner.
```

**There is no scripted mode.** The command has no `--password` option, and it checks
`isInteractive()` before it does anything else — a non-interactive invocation exits
non-zero with *"beatrax:reset-password must be run interactively; there is no password
flag."* That is the point: a scripted run on an unattended machine must not be able to
rewrite a password. A run under `docker compose run` or in CI is non-interactive by
default and will be refused.

If the supplied `<username>` does not match any user, the command exits non-zero and
prints `No user with that username.` — no fuzzy matching, no creation-on-miss. It also
refuses when the two prompts disagree, and when the password is shorter than twelve
characters.

## What the command does

Internally it:

1. Loads the user by exact username match, after normalising it through `Username`.
2. Hashes the new password with the application's configured `Hasher`. No
   `config/hashing.php` is published and nothing rebinds the driver, so that is
   Laravel's default: bcrypt. The Argon2id this codebase does use is for the app-lock
   PIN and for encrypted backups, not for account passwords — see [the Argon2id
   cost](../architecture/argon2id-cost.md).
3. Writes the hash to `users.password` and sets
   `users.force_password_change_at_next_login`.
4. Marks the app-lock recovery wrap stale through `AppLockProvisioner`.
5. Revokes every session the user holds through `SessionRevoker`.

It writes **no audit row**. The Dev Console's audit log records commands spawned from
the console; this one runs at the CLI and leaves no trace beyond the changed columns.
Nor does it issue an email notification — Beatrax does not run an SMTP relay, and the
local-only privacy posture rules out a third-party transactional-email service.
Coordinate the reset with the user out of band.

## Recovery for the owner who lost their own credentials

The same command works when run as the system user that owns the SQLite file. The
recipe:

```sh
# 1. Stop the running app (any web request would otherwise hold a read lock briefly).
php artisan down

# 2. Reset the password.
php artisan beatrax:reset-password owner

# 3. Bring the app back up.
php artisan up
```

A shipped desktop bundle has no terminal of its own, so the reset is run from a source
checkout of the same version, pointed at the installed app's database file. The `sqlite`
connection reads `DB_DATABASE` before it falls back to
`UserDataPathService::databaseFile()`, so that variable is the override to use:

```sh
DB_DATABASE=<the installed app's database.sqlite> \
  php artisan beatrax:reset-password owner
```

The installed app prints its own paths on **Help → Data locations**, which is the one
place guaranteed to name the file the running bundle actually has open; the per-OS
shapes are in [the database page](../local_development/database.md). Quit the app before
running this — the bundle keeps its own handle on the file, and the checkout has to be
on the same migration state or the reset lands on a schema it does not fit.

The Dev Console does **not** offer this command. Its destructive tier is
`db:restore`, `beatrax:regenerate-recovery-codes`, `beatrax:grant-dev` and
`beatrax:install`; anything outside the safe and destructive allow-lists is refused by
`CommandRegistry` before a process is spawned (see
[`../local_development/dev-mode.md`](../local_development/dev-mode.md)). That is
consistent with the shape of the problem anyway: an operator who has lost their own
credentials cannot reach the Dev Console either. The CLI is the only path.

## After the reset

The user signs in with the new password, and is required to change it again on that
first sign-in because of the `force_password_change_at_next_login` stamp. Every session
they held is already gone, so any other device they were signed in on returns to the
login screen.

If they had an app-lock PIN, the stale wrap raises its own banner: *"The account
password changed without the app-lock recovery wrap being re-wrapped, so that password
no longer opens the app lock. The PIN still does."* Tell them to re-link the account
password from the app-lock settings **while the PIN is still known** — after a
forgotten PIN there is nothing left to re-link with.

If the user wants to regenerate fresh recovery codes after the reset (recommended —
the codes that existed before the reset may have been compromised), they can do so
from Settings → Security → Recovery codes once logged in.
