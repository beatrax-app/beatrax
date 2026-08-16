# Runbooks

Operational procedures the maintainer or a self-hosting operator runs on a real machine.
Each runbook is a recipe: prerequisites, the exact commands, what success looks like,
and what to do when something fails.

## Index

| Runbook | When you reach for it |
|---|---|
| [release-cut.md](release-cut.md) | Cutting a stable or RC release: bumping version intent, pushing the tag, promoting the DRAFT release |
| [verify-release.md](verify-release.md) | Verifying a downloaded release manually — SHA-256 checksum and Ed25519 manifest signature |
| [mobile-release.md](mobile-release.md) | Building, signing and distributing the Android and iOS apps — Bifrost, the public APK, and where each credential lives |
| [force-password-reset.md](force-password-reset.md) | Resetting a user's password from the CLI when recovery codes are exhausted |
| [repo-security-setup.md](repo-security-setup.md) | Reproducing the GitHub repo security posture from scratch on a fork or fresh clone |
| [operator-recovery.md](operator-recovery.md) | Backup, restore, corrupt-backup remediation, stuck-lock recovery, failed-jobs maintenance |
