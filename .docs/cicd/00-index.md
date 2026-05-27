# CI/CD

The build, test, and release automation that runs on every PR and every pushed tag, plus
the branch and repository posture that surrounds it.

The PR gate, release pipeline, and security workflows live in `.github/workflows/`. The
files in this subtree describe what those workflows do, why they are shaped the way they
are, and how a release flows from `git tag` to a published GitHub Release.

## Topics

| File | What it covers |
|---|---|
| [overview.md](overview.md) | High-level CI architecture, SHA-pinning rule, CODEOWNERS posture, the `.env.bundled` + APP_KEY sentinel pattern |
| [release-workflow.md](release-workflow.md) | Step-by-step description of what happens when a `v*` tag is pushed |
| [branch-protection.md](branch-protection.md) | Default-branch ruleset, posture, and update procedure |
| [release-cadence.md](release-cadence.md) | Version policy, tag patterns, stable vs. preview channels, asymmetric publish |

## Related runbooks

- [Cutting a release](../runbooks/release-cut.md) — operator procedure when you push a tag.
- [Verifying a release](../runbooks/verify-release.md) — user-facing checksum and
  Ed25519-signature verification recipe.
