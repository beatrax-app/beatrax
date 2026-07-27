# beatrax — repo-local documentation

Repo-local technical documentation: the **PHP-specific how**. Product decisions,
requirements, and architecture contracts live in the specification, which is
canonical:

**[github.com/beatrax-app/spec](https://github.com/beatrax-app/spec)**

Code links into this tree (`@link`, never a requirement identifier — `GOV-R6`).
These pages, in turn, cite the spec. That is the middle layer of the
[three-layer model](https://github.com/beatrax-app/spec/blob/main/40-quality/code-comments.md).

## The split

| This tree owns | The spec owns |
|---|---|
| Which class, which file, which table | Behaviour and requirements |
| Local development setup and troubleshooting | The quality standards it satisfies |
| Operational runbooks with real commands | The operations requirements they satisfy |
| Per-module implementation maps | The component model |

**Where the two disagree, the spec wins** and the page here is the one that gets
corrected ([REPO-R36](https://github.com/beatrax-app/spec/blob/main/30-repos/beatrax.md#requirements),
[canonical-spec.md](https://github.com/beatrax-app/spec/blob/main/50-governance/canonical-spec.md)).

## Subtrees

| Subtree | What it covers |
|---|---|
| [Architecture](architecture/00-index.md) | How subsystems are built — the ingestion pipeline, chain resolution, categorisation, the module boundary as enforced |
| [Conventions](conventions/00-index.md) | How code is written here; the comment policy is canonical in the spec |
| [Features](features/00-index.md) | Per-module implementation maps — what the code does and where it lives |
| [Local development](local_development/00-index.md) | Setup, database, dev mode, troubleshooting |
| [Runbooks](runbooks/00-index.md) | Operational procedures with real commands |
| [Design](design/) | Design notes for subsystems the code links to |

## What moved to the spec

The decision records, the licence and data-retention rationale, the CI/CD and
release-cadence pages, the stack and hazard research, and the milestone history
are **no longer here**. They are in the spec:

| Was | Now |
|---|---|
| `.docs/adr/` | [00-overview/decisions/](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/) |
| `.docs/legal/` | [90-appendix/license-rationale.md](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md) · [data-retention.md](https://github.com/beatrax-app/spec/blob/main/90-appendix/data-retention.md) |
| `.docs/cicd/` | [40-quality/ci-cd.md](https://github.com/beatrax-app/spec/blob/main/40-quality/ci-cd.md) · [70-operations/releasing.md](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md) |
| `.docs/research/` | The decisions those findings produced, in [00-overview/decisions/](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/) |
| `.docs/history/` | [00-overview/roadmap.md](https://github.com/beatrax-app/spec/blob/main/00-overview/roadmap.md) · [90-appendix/provenance.md](https://github.com/beatrax-app/spec/blob/main/90-appendix/provenance.md) |
| `.docs/features/*/specs.md` | Behaviour and requirements in [10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/); the test each contract maps to folded into the module's `how-to-test.md` |

## Conventions

- **Present tense.** Every file describes the system as it is today. History
  belongs in the spec's decision records, not in narrative prose here.
- **Cross-links are relative within this tree**, and absolute to the spec — so a
  reader can always tell which layer they are being sent to.
- **One topic per file.** A topic that outgrows a few screens gets split.
- **No workflow vocabulary.** Phase numbers, plan identifiers, and planning-tool
  references are not reference documentation; the `noGsdLeakage` invariant fails
  the build on them.
