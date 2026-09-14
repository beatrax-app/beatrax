# Cutting a release

The operational procedure for shipping a new Beatrax release. Everything happens by
pushing a git tag — the release pipeline is the only path that produces a published
build.

For the underlying mechanics of what the workflow does after you push the tag, and for
the version policy and channel semantics, see
[`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).

## Before pushing the tag

1. Confirm `main` is green. The PR-gate workflow (`ci.yml`) runs on PHP 8.5 only —
   three test shards plus a static-analysis job, collapsed into the single
   `quality (PHP 8.5)` check — and that check must be passing on the latest commit.
   There is no 8.4 axis to wait for. If it is not green, the release workflow's gate
   job will fail in the same way — fix it on `main` first rather than chasing it
   through the release.
2. Confirm the change set is what you mean to ship. `git log --oneline <last-tag>..HEAD`
   is the raw material — and `<last-tag>` is the last **release** tag, which is not
   what `git describe` answers. Ten `2.0.0-probe.N` tags sit on `main`'s own history
   and are ancestors of it, so `git describe --tags --abbrev=0` names one of those and
   the range reads a fortnight instead of the span since `v1.3.0`. Use
   `git tag --list 'v*' --sort=-v:refname | head -1`. The notes themselves are no
   longer exposed to this — `cliff.toml` pins `tag_pattern` to the same `v*` shape the
   workflow triggers on — but the command above is yours to scope; if any commit looks unfinished, land the fix before tagging.
   The published notes are narrower than that log — git-cliff builds them from
   `cliff.toml`, which skips `docs`, `ci`, `build`, `test`, `style` and `chore` commits
   and groups the rest by conventional-commit type.
3. **Cutting a stable tag that followed release candidates? Delete them first.**
   `cliff.toml`'s `tag_pattern` is `^v[0-9]`, which the probe tags do not match and
   `v2.0.0-rc.3` does. git-cliff `--latest` bounds the notes at the previous matching
   tag, so a `v2.0.0` pushed while its own candidates still exist announces the span
   since the last candidate — measured on the v2 line, **7 commits where the span since
   `v1.3.0` holds 1,651**. Nobody upgrading from the last release reads the candidate
   notes, so that body describes the release to no one.

   Delete the tag and its prerelease on the remote, and locally, before pushing the
   stable tag:

   ```sh
   for t in $(git tag --list 'v2.0.0-rc.*'); do
       gh release delete "$t" --yes --cleanup-tag 2>/dev/null || git push origin ":refs/tags/$t"
       git tag -d "$t"
   done
   ```

   `--cleanup-tag` removes the tag with the release; the fallback covers a candidate
   that was tagged but never published, which is what a candidate whose build failed
   leaves behind. This is deliberate rather than tidy-mindedness: the candidates have
   served their purpose the moment the stable tag is cut, and leaving them changes what
   the release says about itself.

4. Pick the version. The version follows semver — bug fixes bump the patch, feature
   additions bump the minor, and a breaking change bumps the major. The spec owns the
   policy behind that; see
   [`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md).
5. Give any breaking change its prominence, below.
6. Run **release-preflight** against the version you picked — the Actions tab, "Run
   workflow", the version without its leading `v`. It is the pre-tag checklist run as
   a check rather than remembered, and it asks three things this page cannot: that the
   spec marks the version `releasable`, that the tag does not already exist, and that
   the ruleset's required checks still name jobs that actually run
   ([OPS-R18](https://github.com/beatrax-app/spec/blob/main/70-operations/README.md) —
   a renamed job stops being required without anything going red to say so). It
   deliberately does not push the tag: a bot-pushed tag would be unsigned, and one
   pushed with the default token would not trigger the build at all.

   Skipping it does not skip the question. The release workflow's spec gate asks the
   same one the moment the tag lands, and a tag is never moved — so the answer arrives
   when the only remedy left is a new version number.

## A breaking change

One subject line among hundreds is exactly what
[OPS-R12](https://github.com/beatrax-app/spec/blob/main/70-operations/README.md)
refuses, and nothing here can be edited to fix it afterwards: the release body is
generated from the commit history and no hand-maintained file may be its source
([OPS-R11](https://github.com/beatrax-app/spec/blob/main/70-operations/README.md)).
The prominence is won in a commit, before the tag.

Mark the commit breaking and put the whole note in a `BREAKING CHANGE:` footer:

```text
docs(pots)!: category-linked pots are retired on upgrade

BREAKING CHANGE: Category-linked savings pots are retired, and money they
held is now unallocated.

The first time v2.0 opens, every pot that was linked to a budget category is
archived and its balance released back to the unallocated pool of the account
it sat on.

Spec: D3-R16
Signed-off-by: A Maintainer <maintainer@example.com>
```

Two things follow from `cliff.toml`. The `!` puts the entry in **Breaking changes**,
which sorts above every other group, and it outranks the `docs`, `test`, `chore`,
`ci`, `build` and `style` skips — so a note-only commit still appears whatever type
it carries. The footer is then rendered in full beneath the entry, flush left, as its
own paragraphs; `Spec:` and `Signed-off-by:` are separate trailers and do not appear.

Write the footer for the person the change happens to rather than the person who made
it: what changed, what it means for data they already hold, and what they now have to
do by hand. Where the change moves the user's money, the app has to say so as well —
a release body reaches a reader who goes looking for one, and the desktop updater
renders no notes of its own. See
[the category-link retirement](../features/pots/category-link-retirement.md) for the
shape that took.

Read it back before tagging, without pushing anything:

```sh
git-cliff --unreleased --strip header
```

## Push the tag

```sh
# Stable release on the stable channel — produces a DRAFT GitHub Release
git tag -s v1.4.0 -m 'Beatrax v1.4.0'
git push origin v1.4.0

# Release candidate on the preview channel — published immediately as a prerelease
git tag -s v1.4.0-rc.1 -m 'Beatrax v1.4.0-rc.1'
git push origin v1.4.0-rc.1
```

Signed, and with a message, because the bare `git tag v1.4.0` this page used to show
does not produce an unsigned tag — it produces no tag at all. `tag.gpgsign` is on for
this repository, so that form stops with `fatal: no tag message?`, at the one point in
the process where a command that does not work costs the most. The signed form above is
the one release-preflight prints when it passes.

The tag itself is the trigger — `push: tags: ['v*']` and nothing else. There is no
`workflow_dispatch` button, no second-step "start build" action. As soon as the push
completes, GitHub Actions runs the spec gate and the quality gate within seconds; the
spec gate fails the run outright if the canonical spec does not consider the tagged
version releasable.

## Watching the build

Follow the run live:

```sh
gh run watch
```

Or browse to the Actions tab on GitHub. Five things to confirm during the run:

- The spec gate and the quality gate (a single PHP 8.5 axis) complete green. About
  five minutes.
- All four build jobs — macOS, Windows, Linux, Android — complete green. About fifteen
  to twenty minutes wall-clock once they kick off; they run in parallel. The macOS and
  Windows jobs refuse to build at all when a signing credential is missing, and then
  interrogate the artifact they produced rather than trusting the build's exit code.
- The `smoke (self-host server)` job launches the shipped self-host recipe and asks it
  for its health endpoint. It is not a platform build and it runs beside them rather
  than after them, but `publish` names it in `needs` alongside the four: the shape it
  covers is the one that shipped answering 404 to everything.
- The publish job runs, writes a SHA-256 checksum file over every artifact, signs that
  and each auto-update manifest with Ed25519, and uploads every artifact plus the
  detached signatures. About two minutes.
- The `verify published` job re-downloads the manifests and the checksum file from the
  release page, re-verifies every signature against the publisher key committed in
  `config/auto_update.php`, and asserts that every asset on the page appears in the
  checksum file. If it fails, the assets on the page are not what the pipeline signed —
  or one of them is something the pipeline never vouched for.

If any platform job fails, the workflow stops and the publish job is skipped — and so
it does when the self-host smoke job fails, which is the one cause a green board across
the four builds does not rule out. Fix the underlying cause on `main`, then either
delete and re-push the same tag (acceptable for an RC that has not been distributed) or
bump to the next patch version (the safe choice for a stable tag that has been seen by
anyone).

## After the run completes

### For an RC tag (`v*-rc.*`)

The release is already published as a prerelease, but the preview channel does not read
the tagged release. It reads a rolling release that the `move the preview feed` job
repoints onto this build once `verify published` is green, and that job runs only for a
tag carrying a prerelease suffix — a hyphen in the tag is the whole test. It fails
outright when the tag published no `beta*.yml` manifest, because a feed pointed at a
tag without one answers 404 to every reader on preview.

Confirm it completed. When it did, subscribers receive the update on their next
auto-update poll (within four hours) and no further action is required. When it did
not, the release is published and no preview reader is ever offered it.

### For a stable tag (`v*.*.*`)

The release exists as a DRAFT. The artifacts are uploaded and visible to anyone with
repo write, but no end user can see or download the release. To promote:

1. Open the release in the GitHub UI under Releases.
2. Verify the auto-generated release notes read correctly. Edit if needed.
3. Confirm the asset list. `beatrax-<version>-checksums.txt` covers every other asset
   on the page and has its own `.sig`, so the fastest read is that file: the `verify
   published` job already failed the run if anything published is missing from it. Then
   check that each of `latest.yml`, `latest-mac.yml`, `latest-linux.yml` and
   `latest-linux-arm64.yml` is present with a `.sig` sibling, and that the installer each
   one names in its `path:` field is on the page too. Linux has two because it ships an
   AppImage per architecture and electron-updater resolves `latest-linux.yml` on x64 and
   `latest-linux-arm64.yml` elsewhere. A stable page carries `beta.yml`, `beta-mac.yml`,
   `beta-linux.yml` and `beta-linux-arm64.yml` beside them: the build that is newest on stable is newest on preview as well, so the
   preview set is written for every tag shape and only the `latest` set is withheld from
   a prerelease. The Windows `.exe`, the macOS `.dmg`, both Linux `.AppImage` files and the
   Android `.apk` are the artifacts the four build jobs upload; `.msi` and `.deb` appear
   when `electron-builder` produced them.
4. Click Publish release.

Once published, the stable channel sees the new version on its next auto-update poll.

## Re-running a failed publish

If only the publish job fails (platform builds succeeded, manifest signing or release
upload failed), the workflow can be re-run from the GitHub UI without re-building. The
platform artifacts remain attached to the run for the standard retention window and
the publish job downloads them again.

If a platform job fails, the artifacts are not produced; the only recovery is to fix
the failure cause on `main`, then push a new tag — even a re-pushed tag of the same
name does not re-trigger the workflow reliably because GitHub Actions deduplicates by
tag SHA, and the SHA changes only with new commits.

Prove the fix before spending a version on it. `release-build.yml` runs the same
per-platform matrix against an existing tag and uploads the installers as workflow
artifacts — no smoke test, no signing, no publish, and no release created or touched.
That is what to iterate a pipeline fix against, so the new tag becomes the step that
publishes a fix already known to build rather than the step that finds out.

## Rolling back

There is no "unpublish" path that preserves user trust. A published stable release that
turns out to be broken gets superseded by a new patch release, not retracted. The
correct procedure:

1. Fix the issue on `main`.
2. Tag a new patch version (e.g., `v1.4.1` after a broken `v1.4.0`).
3. Let the new release publish as DRAFT, promote it, and let auto-update pull it down
   on subscribed installs.

The corrupt release can be edited in the GitHub UI to add a warning note in its
description, but the binaries themselves should not be deleted — anyone who downloaded
them already has them, and the auto-update path needs the older `latest.yml` references
to stay reachable for at least one cycle.
