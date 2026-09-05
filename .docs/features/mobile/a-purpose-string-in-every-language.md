# A purpose string in every language

Three sentences decide whether a reader grants Beatrax the camera, Face ID and
the local network. iOS shows them verbatim, before any application code runs, and
they are the only thing the reader has to go on. Until recently all three were
English, in an interface that ships in twenty-six languages — including the
Face ID prompt, which is what releases the key the ledger is encrypted with.

## Where they live, and why not in the lang tree

`Modules/Mobile/Resources/ios/purpose-strings.php`, keyed by locale and then by
Info.plist key.

They are not translated *lines* in this codebase's sense. Nothing renders them,
they never pass through `Modules\Core\Public\Support\Lang`, and the reader is a
build script running with no framework loaded. Putting them under
`Modules/Mobile/Resources/lang/` would put twenty-six locale files in front of
`EveryTranslatedLineReachesAReaderArchTest`, which would correctly report every
one of them as a line nothing renders.

So they sit beside the lang tree rather than inside it, and
`APurposeStringReachesEveryReaderTest` does the work parity would otherwise do:
it reads the locale list off `Modules/Mobile/Resources/lang/*` rather than
carrying a second copy, so adding a language to the interface fails until the
purpose strings follow.

## One home for the base language

`mobile-app/config/nativephp.php` reads the `en` entry out of the same file.
That array reaches the Info.plist through `IOSPluginCompiler`, which applies
app-level entries last and is therefore the only place that wins a key a plugin
also declares — `mobile-scanner` and `mobile-biometrics` both declare one, and
one of theirs says the app scans barcodes, which it has never done.

Two copies of the same sentence would drift the moment one was reworded, and the
drift would show only as a prompt whose English and Dutch say different things.

The config resolves the file through two candidate paths, because `Modules/` is
not at one offset: here it is a symlink to `../Modules`, while in a materialized
Bifrost tree the mobile root *is* the repo root and `Modules/` sits beside
`config/`.

## How they reach the bundle

`scripts/nativephp_ios_purpose_string_localisations.php` writes
`NativePHP/<locale>.lproj/InfoPlist.strings` — C string literal syntax, one
`"key" = "value";` per line.

It can be a plain file drop because the Xcode project declares `NativePHP/` as a
`PBXFileSystemSynchronizedRootGroup`: every file under that directory is a target
member automatically. The script asserts that rather than assuming it, for the
same reason the privacy-manifest script does. A project regenerated with an
ordinary group would leave the folders on disk, in no build phase, and every
prompt would fall back to English with nothing anywhere reporting it.

It is in `NativeBuildPatches::REQUIRED_SCRIPTS`. The other three there are
required because their absence is invisible until App Store Connect rejects the
upload; this one is invisible until somebody is asked for their face in a
language they do not read.

## What this cannot prove

That the built `.app` carries the folders. The script verifies what it wrote to
the generated project; the question of whether Xcode copied them is a fact about
an archive. On a real build:

```bash
ls NativePHP.app | grep lproj
plutil -p NativePHP.app/nl.lproj/InfoPlist.strings
```

Twenty-six `.lproj` directories, and the Dutch file printing three keys, is the
whole check. Running the app with the device language set to one of them and
triggering the pairing scan is the end-to-end version.

## Related

- [The LAN discovery entitlement](ios-lan-discovery-entitlement.md) — the other
  half of what the local-network prompt needs
- [Translations awaiting a native reader](../../conventions/translations-awaiting-a-native-reader.md)
  — the marker a line carries while it is still open
