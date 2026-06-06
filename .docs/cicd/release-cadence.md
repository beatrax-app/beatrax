# Release Cadence — `nightworksio/beatrax`

This document defines the version policy, tag patterns, publish semantics, and
auto-update channels that the release pipeline expects. Every reference to a
version string in the codebase, in `/health`, in `latest.yml`, or in a GitHub
Release title resolves through the contract described here.

## Version series

beatrax uses the `v0.x` series for every release that precedes the first
public-shippable build. `v0.x` is the entire pre-public series, not a single
preview tier — it covers everything from the first end-to-end release-pipeline
dry run through the last hardening pass before going public.

The first tag the release pipeline ever cuts is `v0.1.0` (or `v0.1.0-rc.1`
if the operator wants to validate the workflow against the preview channel
first). Subsequent dev increments follow standard semver inside the `v0.x`
namespace: bug fixes bump the patch (`v0.1.1`), feature additions bump the
minor (`v0.2.0`), and the major stays at `0` until the explicit graduation
moment.

`v1.0.0` is reserved as the explicit graduation tag. A human operator pulls
that trigger by name when the release is judged ready for the public-facing
GitHub repo. No automation jumps the version from `v0.x` to `v1.0.0` — the
graduation is a deliberate, named step at the end of the closeout series.

## Tag patterns

The release workflow watches for two distinct tag shapes pushed to the
repository:

- `v*.*.*` — stable tags (e.g., `v0.1.0`, `v0.2.0`, `v1.0.0`). These route to
  the stable channel.
- `v*-rc.*` — release-candidate tags (e.g., `v0.1.0-rc.1`, `v0.1.0-rc.2`).
  These route to the preview channel.

There is no alpha tier. Anything that needs to ship to a tester before going
stable rides the preview channel as an RC.

## Asymmetric publish mode

The two tag patterns have deliberately different publish semantics so a
mistaken `git push --tags` on a stable tag cannot ship straight to users.

Stable tags (`v*.*.*`) create a **DRAFT** GitHub Release. The release artifacts
build, the smoke tests run, and everything uploads — but the Release sits in
draft state until a human reviews it and clicks Publish. This is the
human-eyeballs-before-anyone-downloads guarantee for the stable channel.

RC tags (`v*-rc.*`) publish immediately to the preview channel. The
expectation is that anyone subscribed to the preview channel has opted into
the early-update tradeoff, and there is no draft holding pattern between the
build and the channel update.

## electron-updater channels

Two channels are active from the very first release:

- `stable` — fed exclusively by stable tags after a human clicks Publish on
  the DRAFT Release.
- `preview` — fed exclusively by RC tags as they are published.

The desktop bundle defaults to the `stable` channel. Users who want earlier
builds can opt into `preview` from inside the app settings; the auto-updater
then reads its `latest.yml` from the preview channel rather than the stable
one.

Having both channels live from day one means a v0.1.0 stable release and a
v0.1.1-rc.1 preview release can coexist without channel-ordering surprises
later.

## Source of truth

The pushed git tag is the single source of truth for what version string a
shipped bundle reports. The release workflow strips the leading `v` from the
tag (so `v0.1.0` becomes `0.1.0`) and exports `NATIVEPHP_APP_VERSION=0.1.0`
before invoking `php artisan native:build`. The build picks that value up
through the `version` key in `config/nativephp.php`, which reads
`env('NATIVEPHP_APP_VERSION', '0.0.0-dev')`.

The default of `0.0.0-dev` exists so that any build produced outside the
release pipeline — a local dev build, a developer dry-run, anything that
does not set the env var — self-identifies as a dev build. There is no
ambiguity between "this came off the release line" and "this came off my
laptop" because the version string itself encodes which path produced the
binary.

No other mechanism participates in version selection. The default in the
config is intentionally not a real version; the env override is the only
path that produces a real version; and the tag is the only thing that
produces an env override.
