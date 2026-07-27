# NOTICE

beatrax is licensed under the [Hippocratic License 3.0](LICENSE), an
ethical source-available license stewarded by the Organization for Ethical
Source. The full license text lives at the repo root in `LICENSE` and is
the canonical legal document; this file exists only to explain the choice
in plain language.

## Source-available, not OSI-approved

The Hippocratic License is a **source-available** license, not an
[OSI-approved open source license](https://opensource.org/licenses). The
distinction matters and deserves to be stated explicitly:

- **You can read the code.** The full source is here, in this repository,
  for anyone to audit, learn from, or fork.
- **You can run the code.** Build it, install it on your own machine, use
  it for your own personal finances. No fees, no telemetry, no callbacks.
- **You can modify the code.** Fix bugs, add features, change behavior to
  suit your own setup.
- **You cannot use this code in ways that violate human rights.** The
  Hippocratic License binds licensees to a set of conduct standards drawn
  from international human-rights frameworks. That clause is what
  disqualifies it from OSI approval — the Open Source Definition forbids
  any restriction on the fields of endeavor in which a program is used,
  and ethical-use clauses are restrictions.

If you need an OSI-approved license for some reason (corporate
procurement, downstream relicensing under permissive terms,
redistribution as part of a permissive-license bundle), beatrax is not
the right project for you.

## Why we chose the Hippocratic License 3.0

The product is a personal-finance dashboard. It handles a person's full
banking history, their email receipts, and the funding chains between
their accounts. That kind of code earns trust by being auditable — by
shipping its full source so anyone can read it and verify what it does.
Closing the source would have been the simpler legal choice; it would
have been the wrong product choice.

At the same time, software that ingests financial and personal data has
a baseline duty not to be a tool for harm. The Hippocratic License lets
us say that out loud in the license itself: the code is yours to read,
run, and modify — but not as an instrument for human-rights abuse. We
did not invent that obligation; we adopted a license that names it
clearly.

For the longer-form reasoning — including why we didn't pay for code-signing
certificates and what that means for install-time security warnings — see
[`.docs/legal/license-rationale.md`](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md).

## composer.json `license` field

The `composer.json` `license` field is `"Hippocratic-3.0"` with
`config.license-validation` set to `false`. The SPDX license list does
not yet carry an entry for Hippocratic-3.0 (the v2.1 release is
registered; v3.0 sits behind an open identifier-registration request).
Composer's bundled SPDX validator rejects the v3.0 identifier as
unknown; the `license-validation: false` flag bypasses that check
without changing the declared license string. The intent is to use the
identifier the wider ecosystem will recognize once the SPDX registration
lands; this NOTICE is the canonical attribution until then.

This is "Path A" of the two paths the planning notes document. If the
SPDX validator change ever forces a switch to "Path B" (declaring the
license as `"proprietary"` and pointing at this file for the real
attribution), this notice will be updated to reflect the change.

## Attribution

- **Hippocratic License 3.0** — Organization for Ethical Source.
  License text: https://firstdonoharm.dev/version/3/0/full.txt
- **Code of Conduct** — Contributor Covenant 2.1.
  https://www.contributor-covenant.org/version/2/1/code_of_conduct/
