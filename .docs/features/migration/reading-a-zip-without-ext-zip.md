# Reading a ZIP where there is no `ext-zip`

Every migration source is a ZIP. YNAB4 exports one, nYNAB's export is zipped by
the reader before upload, Actual Budget writes one from **Settings → Export
data**, and `/migrations/new` accepts nothing else. So the whole of `Migration`
stands on one call: `ZipExtractor::extract()`.

The desktop has `ext-zip`. **The phone does not, and cannot.**

## The evidence, not the assumption

The mobile PHP runtime is a prebuilt static library shipped by
`nativephp/mobile`, not something this repo compiles. Its own build header
answers the question outright — in `mobile-app/nativephp/ios/Include/php/main/php_config.h`
and in `include/php_config.h` inside
`mobile-app/nativephp/binaries/android-4.0.0-php8.5.9-icu.zip`:

```
/* Define to 1 if the PHP extension 'zip' is available. */
/* #undef HAVE_ZIP */

#define HAVE_ZLIB 1
```

The linked module table agrees: `nm -g libphp.a | grep _module_entry` lists 36
extensions on both platforms and `zip` is not among them, while `php_zlib` is.
iOS ships a `libzip.a` beside `libphp.a`, but nothing in PHP is linked against
it — the C library is present and the PHP extension that would expose it is not.

That is why `mobile-app/composer.json` cannot list `ext-zip` the way the repo
root does. The root's requirement is honest for the desktop and unmeetable on a
phone, and Composer has no vocabulary for "required on one target".

## What the reader saw

Uploading `Modules/Migration/tests/Fixtures/nynab/v1/nynab-export.zip` — a
committed 718-byte fixture the suite asserts the shape of — the phone answered:

> This doesn't look like a YNAB4, nYNAB, or Actual export we can read. Check the
> file and try again.

`new ZipArchive` against a build without the extension is not a caught failure.
It is `Error: Class "ZipArchive" not found`, and the `catch (Throwable)` above it
had exactly one line to say. A valid file, called unreadable, by an app that
already knew it had crashed.

## The seam

`ArchiveReaderFactory` is the one place that asks whether `ext-zip` is here. It
answers with an `ArchiveReader`:

- `ZipArchiveReader` — the extension, wherever it exists.
- `NativeZipReader` — a reader written in PHP for where it does not: it walks the
  end-of-central-directory record and the central directory itself, and inflates
  each entry through `inflate_init()`/`inflate_add()`, which is `ext-zlib` and is
  present on both phone platforms.

`ZipExtractor` holds every guard it always held — the entry-count cap, the total
uncompressed-size cap, the zip-slip path check and the symlink check — and now
applies them to whichever reader answered. Nothing is written to disk until all
four have passed, on both backends. `NativeZipReaderTest` pins that by extracting
the committed fixtures through both readers and comparing the results
byte-for-byte, and by driving each guard through the built-in one.

`NativeZipReader` verifies each entry's CRC32 and its inflated length against
what the entry's own header declares. `ZipArchive` does this for free; a reader
that skipped it would turn a truncated download into silently short CSVs.

## What the built-in reader refuses, and why that is a different sentence

Three endings, and the reader is told which one happened:

| Ending | Raised by | What the screen says |
|---|---|---|
| The file is not an export we read | `UnrecognizedMigrationFileException` | `migration::new.errors.unrecognised` |
| This build has no reader for it | `ArchiveReaderUnavailableException` | `migration::new.errors.archive_reader_unavailable` |
| We failed | anything else | `migration::new.errors.internal_detail`, carrying the exception's short name as a code |

Only the first is answered by choosing a different file. `NewMigration::messageFor()`
is the mapping, and `MigrationArchiveCapabilityTest` holds all three.

`ArchiveReaderUnavailableException` is raised for an archive that is well-formed
and that `ext-zip` would open: an entry compressed with anything other than
*stored* or *deflate* (bzip2, LZMA, Zstandard), an encrypted entry, or a ZIP64
archive. Those are refusals of a capability, not a verdict on the file, and the
line names the desktop as the route.

## What is still impossible on a phone

- **Writing** a ZIP. `NativeZipReader` reads; nothing here packs one. The
  disabled "Export everything as ZIP" stub on the Data locations help page will
  need a writer, and that writer will meet this same wall.
- The three refusals above. A bzip2-compressed or encrypted export has to be
  re-zipped, or opened on the desktop.

## The rule that came out of it

`tests/Contracts/AClassThePhoneDoesNotHaveIsReachedOnlyThroughItsCapabilitySeamArchTest.php`
holds `ZipArchive` to the two files allowed to name it. Anywhere else, on a
phone, that line is a bare `Error` under whatever catch happens to be above it —
and the last time that happened, the catch blamed the reader's file.
