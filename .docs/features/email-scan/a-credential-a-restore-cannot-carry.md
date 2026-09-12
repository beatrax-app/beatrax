# A credential a restore cannot carry

`oauth_secrets.client_secret` and `.tokens_blob` are the only columns in this
tree encrypted under the application key. That key lives in configuration beside
the database rather than inside it, so it travels in no backup — and a restore
lands rows no key on this install opens.

Consent at the provider is untouched. The local copy of it is bytes nobody can
read.

## Why it is repaired at restore time

Left alone, the first thing to notice is a scan, and a scan is a background
pass. So the reader met a decryption failure at whatever hour the schedule ran,
in a place that could only report it — rather than an answer they can act on, at
the moment they were doing the thing that caused it.

`ClearCredentialsNoKeyHereOpens` listens for `Core`'s `DatabaseRestored`, which
both restore paths raise after the swap and its verification. A row it cannot
read is cleared and every inbox of that provider moves to the same
re-authorisation state a revoked grant reaches — which already carries the
alert, the badge and the reconnect link, so nothing new had to be built to
receive it.

## Clearing means removing the row

Not blanking the columns. Every surface that asks whether a mailbox is connected
asks whether a credential row **exists** —
`EmitOAuthReauthRequiredAlert::userAlreadyHandled()` treats the mere presence of
one for the user as "already handled". A row left holding unreadable bytes
therefore reads as a live connection and suppresses the prompt the reader needs.

## What it must not do

Clear a credential this install *can* read. That is a working mailbox, and
disconnecting one because a restore happened to run would be the same defect
pointing the other way. The decision is the decrypt itself — the cast decrypts
on read, so touching the attribute is the whole test — and
`ACredentialARestoreCouldNotCarry` holds the readable case as its positive
control.

Every row is examined, not the signed-in reader's: a restore replaces every
account's rows at once, and on a household install the account whose mailbox
this is may not be the one at the keyboard. Each answers for itself, so a reset
that throws does not leave the mailboxes after it holding credentials the reader
is never prompted to replace.

## See also

- [A backup from another build](../core/a-backup-from-another-build.md) — the
  other thing a restore has to refuse or repair.
- [EmailScan architecture](architecture.md) — the scan states and the reconnect
  flow this hands to.
