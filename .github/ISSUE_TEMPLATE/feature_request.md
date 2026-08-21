---
name: Feature request
about: Suggest an improvement or a new capability for Beatrax.
title: "[feature] "
labels: ["enhancement", "triage"]
assignees: []
---

> **Security vulnerability?** Do not open a public issue. Use GitHub's
> private vulnerability reporting:
> <https://github.com/beatrax-app/beatrax/security/advisories/new>.
> See [SECURITY.md](../../SECURITY.md) for the full disclosure policy.

## The use case

Describe the situation you are in and the outcome you want. Focus on the
problem first — the solution is easier to design when the problem is
clear.

> Example: "When I import an ASN CAMT.053 file with a refunded PayPal
> chain, I have to manually reconcile the funding direction because the
> existing chain resolver only handles outgoing flows."

## Proposed UX

What would the experience look like if this feature existed? Sketches,
mockups, or even a short text walkthrough of the screens / commands /
keyboard shortcuts is enough.

## Why existing features don't cover this

Beatrax already has ⌘K, the chain resolver, Dev Mode, recurring rules,
counterparty profiles, etc. Briefly explain what you tried and where it
fell short.

## Alternatives considered

Other ways the same outcome could be reached — separate tool, manual
workaround, configuration change, etc. Helps reviewers weigh the value
of adding the feature vs. documenting the workaround.

## Impact

- **Who benefits:** (solo users / shared-household users / power users / ...)
- **Frequency:** (every import / monthly / one-off setup / ...)
- **Privacy implications:** Beatrax is local-first by design. If the
  feature requires any network calls, call that out explicitly so
  reviewers can weigh it against the Hippocratic-3.0 + privacy posture.
