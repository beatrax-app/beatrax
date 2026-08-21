# Force a password reset

When a user has lost both their password and their recovery codes, the
`beatrax:reset-password` artisan command is the CLI fallback. It is the only supported
mechanism for restoring access without going through the in-app
recovery-code-then-self-serve flow.

The command is destructive in the sense that it overwrites the target user's password
hash. It does not invalidate sessions, OAuth tokens, or any other per-user secret — only
the password. The user re-authenticates with the new password on their next request.

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

- Shell access to the machine running Beatrax (the developer's local dev machine, or
  the host where the desktop bundle's data directory lives).
- The username (not the email) of the account to reset.
- A new password to set. The command prompts for one if you do not supply it.

## The command

```sh
php artisan beatrax:reset-password <username>
```

Interactive prompt for the password:

```
$ php artisan beatrax:reset-password owner
 New password:
 ****************
 Confirm new password:
 ****************
 Password updated for user 'owner'.
```

Non-interactive, for scripted use:

```sh
php artisan beatrax:reset-password owner --password='correct horse battery staple'
```

If the supplied `<username>` does not match any user, the command exits non-zero and
prints `User 'ghost' not found.` — no fuzzy matching, no creation-on-miss.

## What the command does

Internally it:

1. Loads the user by exact username match.
2. Hashes the new password with the project's standard hasher (argon2id).
3. Writes the hash to `users.password`.
4. Logs an audit row that the password was reset via CLI, including the username and
   timestamp (no password material logged).

It does **not** issue an email notification — Beatrax does not run an SMTP relay, and
the local-only privacy posture rules out a third-party transactional-email service.
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

Inside the shipped desktop bundle, the same command is available via the in-bundle PHP
binary. The exact invocation depends on the bundle layout; on macOS:

```sh
"/Applications/beatrax.app/Contents/Resources/php/php" \
  "/Applications/beatrax.app/Contents/Resources/app/artisan" \
  beatrax:reset-password owner
```

The Dev Console's destructive artisan tier also exposes the command for users who have
developer mode on (see [`../local_development/dev-mode.md`](../local_development/dev-mode.md)),
but if the operator has lost their own credentials they cannot reach the Dev Console
either — the CLI fallback is the only path.

## After the reset

The user signs in with the new password on their next request. The owner-side reset
audit row is visible in the system alerts list and in the failed-jobs / audit pages
under the Dev Console.

If the user wants to regenerate fresh recovery codes after the reset (recommended —
the codes that existed before the reset may have been compromised), they can do so
from Settings → Security → Recovery codes once logged in.
