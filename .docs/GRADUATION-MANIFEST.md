# Graduation Manifest

This manifest records the final pre-purge walk over the local-only working
tree that informed the public documentation. It enumerates every directory
and every notable artefact that was considered for promotion into `.docs/`,
along with the explicit disposition: **graduated**, **already-covered**, or
**intentionally-local**.

The manifest exists so the subsequent history-rewrite step can be audited
— anything not graduated at the moment of the rewrite is gone from public
history forever. Reviewers should walk this manifest end-to-end before the
rewrite dispatches.

## Disposition legend

| Disposition | Meaning |
| --- | --- |
| **graduated** | Promoted to `.docs/`; named target file listed |
| **already-covered** | Content's load-bearing substance was already promoted by a prior closeout plan; entry is a sanity check, not a new promotion |
| **intentionally-local** | Workflow artefact, transient debug note, or future-milestone working material; intentionally not promoted; will be lost when the local working tree is purged from history |

## Promoted artefacts (this plan)

| New `.docs/` file | Promoted from | Disposition |
| --- | --- | --- |
| `.docs/history/00-index.md` | (subtree intro) | **graduated** (new) |
| `.docs/history/milestones.md` | v1.0 milestone-close roster | **graduated** |
| `.docs/history/lessons-learned.md` | v1.0 retrospective lessons + cross-milestone trends | **graduated** |
| `.docs/research/00-index.md` | (subtree intro) | **graduated** (new) |
| `.docs/research/known-hazards.md` | v1.0 pitfall catalogue (15 pitfalls distilled) | **graduated** |
| `.docs/research/stack-rationale.md` | v1.0 stack research + v0.x closeout stack flips | **graduated** |
| `.docs/research/packaging-hazards.md` | v0.x closeout desktop-bundle pitfall catalogue | **graduated** |
| `.docs/00-index.md` (modified) | (subtree index updated to surface History + Research) | **graduated** |

## Top-level walk

| Source | Disposition | Notes |
| --- | --- | --- |
| Project charter (constraints, value statement, key decisions) | **already-covered** | Charter content informed README + ADRs 0001–0010 in the prior closeout pass; constraints surface in the local development setup guide |
| v1 requirements traceability | **intentionally-local** | Internal traceability table for in-flight requirements; not user-facing |
| Phase roster + roadmap | **already-covered** | Shipped scope captured in `.docs/history/milestones.md`; in-flight roadmap is not user-facing |
| Workflow state (current phase, current plan, recent metrics) | **intentionally-local** | Ephemeral state of the local workflow tooling; never load-bearing for the runtime |
| Retrospective | **graduated** | Promoted to `.docs/history/lessons-learned.md` (this plan) |
| Milestone summary roster | **graduated** | Promoted to `.docs/history/milestones.md` (this plan) |
| Workflow config | **intentionally-local** | Tooling configuration for the local-only workflow runner |

## v0.x closeout research

| Source | Disposition | Notes |
| --- | --- | --- |
| Closeout-research executive summary | **already-covered** | Substance distilled into `.docs/research/stack-rationale.md` (this plan) and the existing ADRs |
| Closeout-research stack file | **graduated** | Promoted to `.docs/research/stack-rationale.md` (this plan); also reinforces existing ADRs 0006 (NativePHP), 0007 (queue driver), 0009 (brick/money), 0010 (recovery codes) |
| Closeout-research features inventory | **already-covered** | Feature substance is in `.docs/features/*/`; the inventory was a planning aid that has no after-life |
| Closeout-research architecture decomposition | **already-covered** | Architectural substance is in `.docs/architecture/*` (5 topics created in the prior closeout) and `.docs/features/*/architecture.md` |
| Closeout-research pitfalls catalogue | **graduated** | Promoted to `.docs/research/packaging-hazards.md` (this plan) |

## v1.0 milestone-research archive

| Source | Disposition | Notes |
| --- | --- | --- |
| v1.0 research summary | **already-covered** | Substance is in `.docs/history/milestones.md` (delivered scope) and `.docs/architecture/*` |
| v1.0 research stack file | **graduated** | Promoted into `.docs/research/stack-rationale.md` (this plan) — the rationale was load-bearing and worth keeping |
| v1.0 research architecture | **already-covered** | The five architecture topics in `.docs/architecture/` materialised these patterns |
| v1.0 research features inventory | **already-covered** | The per-module feature surface is in `.docs/features/*/` |
| v1.0 research pitfalls catalogue | **graduated** | Promoted to `.docs/research/known-hazards.md` (this plan) |

## Per-phase walk

| Per-phase artefact class | Disposition | Notes |
| --- | --- | --- |
| Per-plan workflow files (one per executed plan) | **intentionally-local** | Workflow scaffolding only; superseded by their corresponding summary file at plan close |
| Per-plan summary files | **already-covered** | Load-bearing module-level decisions surfaced in `.docs/features/*/architecture.md` and `.docs/architecture/*` via the prior closeout passes; the per-plan files themselves are workflow artefacts |
| Per-phase context files | **intentionally-local** | Plan-scoping aids; not user-facing reference |
| Per-phase discussion logs | **intentionally-local** | Transient context-gathering output |
| Per-phase pattern maps | **intentionally-local** | Planner ↔ executor hand-off scaffolding that quotes in-repo source by file + line range; obsolete the moment the referenced source changes; the patterns themselves are documented as house style in feature-level `code.md` files |
| Per-phase review / validation / verification files | **intentionally-local** | Quality-gate transcripts for the in-flight workflow; the closure state is captured in the corresponding summary |
| Per-phase UI specs / UAT scripts | **intentionally-local** | Implementation aids; the resulting UI lives in the code and the resulting acceptance criteria live in feature-level `specs.md` files |
| Per-phase deferred-items lists | **intentionally-local** | Deferred items either re-emerge in a later plan or surface in the next milestone's working tree; carrying them in public docs would freeze stale snapshots |
| Phase-15 security file | **already-covered** | Substance materialised in the runtime hardening (entitlements, codesign) and `.docs/research/packaging-hazards.md` (this plan) |
| Phase-16.1.2.1 review-fix / security / seed-rules files | **intentionally-local** | Plan-specific quality-gate notes; structural fixes are in the resulting code |

## Other working areas

| Source | Disposition | Notes |
| --- | --- | --- |
| Seeds directory | **intentionally-local** | Dormant ideas for future milestones; the surviving seed (the public-release / desktop-packaging / deep-modules-review capstone) became the v0.x closeout series and is now shipped |
| Debug investigations directory | **intentionally-local** | Per-incident debug notes — workflow-tooling output; the resulting fixes are in the code |
| Resolved debug archive | **intentionally-local** | Same as above for resolved investigations |
| Quick-fix working areas | **intentionally-local** | Per-ticket scratch space for the local quick-fix workflow |
| Sketches directory (10 UI sketches across 6 sessions) | **intentionally-local** | Design exploration that was already distilled into the project-level UI skill (`./.claude/skills/sketch-findings-<project>/`), which is a separate concern that lives outside this graduation pass |
| Sketches wrap-up summary | **intentionally-local** | Already informs the sketch-findings skill |
| Closeout v0.x phase HUMAN-UAT / UI-SPEC / VALIDATION files | **intentionally-local** | Workflow artefacts; UI surfaces materialised in code |

## Deep review document

| Source | Disposition | Notes |
| --- | --- | --- |
| Deep modules review | **already-committed** | Committed at the repo root during the prior cleanup plan (Plan 17-11); not graduated under `.docs/` because it is a one-shot quality-gate audit, not ongoing reference documentation. Stays at repo root for the immediate post-launch period; future maintainers can decide whether to move it under `.docs/history/audits/` |

## What this plan deliberately did NOT do

- **Did not modify `.gitignore`.** That is the next plan's scope — this plan
  only adds graduated content under `.docs/`.
- **Did not delete anything from the local working tree.** The history
  rewrite is the next plan's mechanism; until then, every local file remains
  available for the operator to consult.
- **Did not graduate any per-plan workflow files.** Those are scaffolding
  for the in-flight workflow tooling, not reference documentation. Any
  load-bearing decision they captured surfaces in the corresponding ADR or
  feature deep dive.
- **Did not graduate the per-phase discussion logs, context files, pattern
  maps, review / validation / verification files, UI specs, or UAT scripts.**
  These are workflow artefacts; the substance they captured surfaces in the
  feature deep dives, the architecture topics, or the ADRs.
- **Did not graduate the in-flight v1.1 working area.** The next-milestone
  backlog is captured separately by the post-cut milestone-setup plan; it
  has no after-life under `.docs/`.

## Audit checklist (for the operator before the history rewrite dispatches)

- [ ] Walked this manifest end-to-end; every disposition makes sense.
- [ ] Each graduated file reads as authoritative present-tense reference,
      not as a workflow artefact.
- [ ] No file under `.docs/` carries the forbidden vocabulary surface
      (the `noGsdLeakage` arch invariant must pass clean).
- [ ] The shipping ADR count under `.docs/adr/` is at least 10.
- [ ] The architecture topic count under `.docs/architecture/` covers
      every load-bearing system shape.
- [ ] Runbook coverage under `.docs/runbooks/` includes every operational
      procedure a future maintainer needs.
- [ ] The deep modules review at the repo root is committed and stays
      at the repo root for the immediate post-launch period.
- [ ] Confirmed: anything remaining in the local-only working tree after
      this audit is intentionally local-only and will disappear from public
      commit history after the next plan's filter-repo run.

When all boxes check out, the operator approves the next plan to proceed.
