# The file the log viewer opens

The Dev Console's `/dev/logs` page reads a file off disk. Which file that is is
not a constant: it depends on the log channel the running install is configured
with, and that configuration is different on a developer's machine, on a
shipped desktop bundle, and on a phone. For four months the viewer assumed one
of the three, and on the other two it showed an empty page while the log sat
beside it.

`ActiveLogFile` is the one place that answers the question. `LogFileStats`,
`RecentLogEntriesReader` and `LogStreamController` all resolve through it, so
there is no second opinion to drift.

## What each build actually writes

| Install | `LOG_CHANNEL` / `LOG_STACK` | File written |
|---|---|---|
| Developer checkout | whatever the local `.env` says — currently `stack` / `single` | `laravel.log` |
| Desktop bundle | forced to `stack` / `daily` by the packager | `laravel-YYYY-MM-DD.log` |
| Mobile bundle | copied from `mobile-app/.env` unchanged | whatever that file says |

The desktop row is not a convention this repository can choose. NativePHP
Desktop's env-cleaning step strips `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`
and `LOG_LEVEL` out of the bundled `.env` and appends its own values —
`LOG_STACK=daily` among them. The mobile packager has no such step: it strips
only the secret-hygiene keys, so the phone ships whatever the build machine's
`.env` held.

So a viewer pinned to the dated filename is correct on desktop and wrong
everywhere else, and a viewer pinned to `laravel.log` is the reverse. Neither
constant is a fix. Measured on a Galaxy S24: `laravel.log`, 70,741 bytes, 387
lines including a stack trace, while the page reported `0 lines today · 0 B
across 0 daily files`.

## How the channel is resolved

`ActiveLogFile::path()` walks the configured channel chain and returns the
filename that chain's first file-writing channel produces:

1. Start at `logging.default`.
2. A `stack` channel is expanded into the channels it names, breadth-first. A
   stack may name another stack, and a stack may name itself — a visited set
   and a depth ceiling are what stop a self-referential channel spinning.
3. The first channel whose driver is `single` or `daily` decides the shape:
   `single` means the plain file, `daily` means Monolog's dated sibling.

Only the *shape* comes from the channel. The directory and base filename come
from `UserDataPathService::logsFile()`, which is also the value `config/logging.php`
gives every file channel its `path` — a test pins that, by re-reading the config
file and asserting each `single`/`daily` channel resolves to exactly that path.

When the configured chain writes to no file at all — the test suite runs with
`LOG_CHANNEL=null` — the resolver answers with the daily shape. That is this
application's own declared default channel, so it is the shape the log takes on
any install that logs at all.

`ActiveLogFile::siblingGlob()` answers the companion question for the totals
strip: for a rotating channel the `laravel-*.log` pattern, and for a single-file
channel the literal path, which `glob()` returns as one entry when the file
exists and none when it does not.

## What proves the two agree

`Modules/DevMode/tests/Feature/TheLogViewerOpensTheFileTheChannelWritesTest.php`
configures each realistic shape in turn — `single`, `daily`, a stack of
`single`, a stack of `daily` — writes a real line through a real Monolog stack,
then asserts that the file which received it is the file the resolver names,
that `/dev/logs/stats` counts it, and that `/dev/logs/poll` streams it.

A round trip, not an assertion about a constant: change `LOG_STACK` and the test
still passes, because the viewer follows. Change the resolver so it stops
following, and it fails.

## What the copy still assumes

The totals strip says "lines today", "today" and "daily files". Those words are
right for a rotating channel — the shipped desktop build, and any install
following `.env.example`. Under a single-file channel they describe the whole
file rather than a day of it. The numbers are correct in both cases; only the
noun is loose.

Every figure in the strip is counted in the browser, so each phrase is now a
whole line — `totals.showing`, `totals.lines_today`, `totals.all_files` — handed
to Alpine with its arms and the reader locale's index table by `Lang::arms()`.
Rewording the noun is therefore an edit to lines that already carry every
locale's plural forms, not the writing of new ones. See
[copy that carries a count](../../conventions/counted-nouns-in-copy.md#a-count-the-browser-works-out).
