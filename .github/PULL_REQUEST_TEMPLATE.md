<!--
Thanks for opening a pull request against beatrax. Fill out the sections
below to help the maintainer review quickly. Keep the description
focused on what changed and why; the diff itself shows how.
-->

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
- [ ] Documentation updated where behaviour changed (`.docs/` or in-repo READMEs)
- [ ] An ADR was added under `.docs/adr/` if this PR makes an architectural decision
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
