<!--
  Template for `.docs/features/<module-slug>/architecture.md`. Fill in
  per-module in a follow-up plan; the four template files in this
  `_template/` directory form the canonical shape every module's
  feature deep-dive should match.

  Keep each section short — half a page total. The detail belongs in
  `code.md` (file references) and `how-to-test.md` (test recipes and
  the behavioural contracts each test holds).
-->

# `<ModuleName>` — architecture

One-paragraph summary: what this module is responsible for, in the
voice of "the system as it is today". Past-tense planning context does
not belong here.

## What this module is for

Two or three paragraphs describing the module's role in the system.
Answers:

- What problem does the user (or another module) have that this module
  solves?
- Why does this module exist as a separate module rather than as a
  surface inside another module?
- What does it explicitly NOT do? (Often the cleanest way to set the
  boundary.)

Cross-link to the relevant ADR(s) when the existence of the module
maps to a recorded decision (e.g. `Modules/Desktop/` exists because of
[ADR 0006](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0006-nativephp-desktop-shell.md)).

## Module boundary

Describe the `Public/` surface — what other modules MAY import from
this module:

- **Contracts** — interfaces declared in `Public/Contracts/` that the
  module's owners promise to keep stable.
- **DTOs** — value objects in `Public/Dto/` other modules consume.
- **Events** — events in `Public/Events/` other modules MAY listen for.
- **Services** — concrete services in `Public/Services/` other modules
  MAY inject.

Describe the `Internal/` surface — what is intentionally hidden:

- One-line summary of each major subdirectory under `Internal/`.
- Any arch invariant that specifically guards this module (e.g.
  `noOtherCardStatementStateMutator` for the Chains module).

## Key services + events

A bulleted list naming the load-bearing services and the
events they raise or listen to. One line each:

- `ServiceClassName` — what it does, in one line.
- `EventName` — when it's raised, and which module listens.

## Data flow

If the module is part of a pipeline or has a natural request/response
flow, describe it in three or four steps. An ASCII diagram is welcome
when the flow has branches.

Cross-link to [architecture/](../../architecture/) topics that describe
the cross-cutting flows this module participates in
(e.g. [ingestion-pipeline.md](../../architecture/ingestion-pipeline.md)
or [chain-resolution.md](../../architecture/chain-resolution.md)).
