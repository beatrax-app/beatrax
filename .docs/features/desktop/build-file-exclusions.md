# What the desktop build leaves behind

`php artisan native:build` does not build from a manifest of files it needs. It
**copies the working tree** and then removes the entries listed in
`cleanup_exclude_files` in `config/nativephp.php`. Every entry in that list is
there because something concrete went wrong without it — a build that hung, a
codesign that was refused, an app that shipped with no styling. This page says
which failure each one prevents, and — just as important — which entries look
missing but must stay out of the list.

Related: [the prebuild hooks](build-prebuild-hooks.md) that patch the toolchain
during the same build, and [SQLite file pre-creation](../../architecture/sqlite-file-precreation.md),
which exists partly because this list strips `database/*.sqlite` from the copy.

## The one thing to understand first: fnmatch has no `FNM_PATHNAME`

The patterns are evaluated with PHP's `fnmatch()` in its default mode. Two
consequences drive most of the surprises below:

- **`*` matches across `/`.** `*/.claude` matches `vendor/.claude` and
  `vendor/nativephp/desktop/resources/build/app/.claude` alike. This is useful
  for catching nested copies — and dangerous, because a pattern aimed at one
  directory can silently strip an unrelated one several levels down.
- **A pattern containing `/` needs a real `/` to match.** `*/tests` does *not*
  match a top-level `tests` directory, because there is no separator to the left
  of it. That is why `tests` appears in the list on its own line, alongside
  `*/tests`.

Get either of these wrong and the failure is not a validation error. It is a
build that succeeds and ships something broken.

## Size: why the caches are excluded

`.phpstan-cache`, `.phpunit.cache`, `.pint.cache` are the toolchain's own
scratch space: **194 MB across 8,110 files**, about a third of everything that
would otherwise land in the bundle.

The disk cost is not the point. `codesign` runs **per file** with `--timestamp`,
and every one of those 8,110 files buys its own network round-trip to Apple's
timestamp server. Before this exclusion the build spent the bulk of half an hour
notarising static-analysis caches.

`.docs` and `.github` are excluded for the plainer reason: they are
contributor-facing material with no runtime role. `.docs` is the tree the
`@link` docblocks throughout the codebase point at; it is read from a checkout,
never from a shipped app.

## Time: why the previous build is excluded

`nativephp/` at the project root holds the output of **every prior**
`php artisan native:build` — the full Electron distribution under
`nativephp/electron/dist/<arch>/beatrax.app/`. Without the exclusion the copy
walker descends into the previous build's own app bundle, which is a
near-complete recursive copy of the project. The symptom is not an error: the
build simply appears to hang while it shuffles roughly **6 GB** of stale
Electron output into the new build directory. The directory is gitignored at the
repo level for the same reason; the exclusion mirrors that for the copy step.

`.claude` is the same shape of problem from a different source. Agent worktrees
under `.claude/worktrees/` are independent git worktrees of the full repo, each
carrying its **own** `vendor/`, `nativephp/` and `node_modules/`. Six concurrent
worktrees reach roughly 3 GB. `*/.claude` is the belt-and-braces companion: it
catches a `.claude/` that ended up nested inside
`vendor/nativephp/desktop/resources/build/app/` from an earlier build that ran
before the exclusion existed.

## Correctness: `public/hot`

`public/hot` is Vite's dev-server marker file, written whenever `npm run dev` is
running. Bundling it makes Laravel emit **every asset URL against
`http://localhost:5173`** — so the shipped app has no styling and no JavaScript
on whichever machine happens to open it. Nothing about the build fails; the
artifact is simply broken.

## The mobile shell, and the exclusion you must not generalise

`mobile-app/` is the NativePHP **mobile** root — a separate application shell
that symlinks the shared `Modules/` tree. It is tracked inside this repo, but
the desktop bundle never needs it, and it carries roughly **1.1 GB** of native
build artifacts under `mobile-app/nativephp/{ios,android}/build/` (Xcode
DerivedData, SwiftPM checkouts).

The top-level `nativephp` exclusion does not catch `mobile-app/nativephp` —
there is no wildcard in front of it. Without an explicit `mobile-app` entry the
copy walker folds the whole mobile shell into the desktop bundle, and `codesign`
then aborts on a locked-permissions SwiftPM test fixture (ZIPFoundation's
read-only `.zip`).

**Do not "fix" this by adding `*/nativephp`.** Because `*` spans slashes, that
pattern also matches `vendor/nativephp` and strips the NativePHP desktop package
itself out of the bundle. The app then fails at boot with:

```
Class Native\Desktop\NativeServiceProvider not found
```

Exclude the shell by name instead.

## What is deliberately NOT excluded: `bootstrap/cache/*.php`

The compiled package and config caches copy into the bundle, and they should.

Laravel's `PackageManifest` **requires `bootstrap/cache/` to already exist and be
writable — it does not create the directory.** The desktop build copies the
working tree, then runs `composer install --no-dev` with scripts enabled; the
`post-autoload-dump` script in `composer.json` runs `package:discover`, which
overwrites the copied caches with manifests built against the bundle's own
`--no-dev` vendor tree.

So the stale caches are never used — but letting them copy in normally is what
guarantees the directory exists for the regeneration step that replaces them.
Excluding them turns a working build into a failing one.
