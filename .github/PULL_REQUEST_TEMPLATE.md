<!--
Thanks for opening a pull request against beatrax. Fill out the sections
below to help the maintainer review quickly. Keep the description
focused on what changed and why; the diff itself shows how.
-->

## Specification citation

<!--
Required. The governance gate reads this body AND your commit trailers,
and fails if an identifier does not resolve on beatrax-app/spec.
Routine maintenance (dependency bumps, formatting, pipeline mechanics)
cites GOV-R12. Changing behaviour the spec does not describe? Open a PR
there first — that is a spec gap, not a gate problem.
-->

Spec:

## Summary

- (1-3 bullets describing what this PR changes)

## Why

Link to the issue this resolves (or describe the motivation if there is
no issue):

- Fixes #
- Refs #

## Test plan

- [ ] (manual or automated step a reviewer can run to verify the change)
- [ ] (edge case covered)
- [ ] (regression check — nothing previously working now broken)

## Checklist

- [ ] `vendor/bin/pint --test` is clean (code style)
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G` is green at Larastan level 10 strict
- [ ] `php artisan test --parallel` is green (Pest)
- [ ] Every commit is signed off (`git commit -s`) and carries a `Spec:` trailer
- [ ] Implementation detail updated in `.docs/`; behaviour and requirements
      updated in [the spec](https://github.com/beatrax-app/spec) — and the spec
      PR merged first if this changes behaviour
- [ ] A decision record was added to the spec's `00-overview/decisions/` if this
      PR makes an architectural decision
- [ ] No `.env`, secrets, or large binary fixtures were committed by accident

## Hippocratic License 3.0 — contribution acknowledgement

beatrax is released under the [Hippocratic License 3.0](../LICENSE). By
opening this pull request you confirm that:

- [ ] Your contribution is yours to give (no copy-pasted code from
  incompatibly licensed sources, no employer-owned work without
  permission).
- [ ] You agree your contribution is licensed under Hippocratic-3.0 as
  part of beatrax.
- [ ] You will not use beatrax — or this contribution — in ways
  prohibited by Hippocratic-3.0 (the "do no harm" clauses around human
  rights, climate, surveillance, and military use; see `LICENSE` for the
  full list).

## Anything else

Notes for reviewers, screenshots of UI changes, performance numbers,
follow-up work, etc.
