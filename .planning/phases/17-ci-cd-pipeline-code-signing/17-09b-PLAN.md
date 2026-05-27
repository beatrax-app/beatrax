---
phase: 17-ci-cd-pipeline-code-signing
plan: 09b
type: execute
wave: 2
depends_on:
  - 17-08
files_modified:
  - .docs/00-index.md
  - .docs/adr/00-index.md
  - .docs/architecture/00-index.md
  - .docs/features/00-index.md
  - .docs/cicd/00-index.md
  - .docs/cicd/overview.md
  - .docs/cicd/release-workflow.md
  - .docs/local_development/00-index.md
  - .docs/local_development/setup.md
  - .docs/local_development/database.md
  - .docs/local_development/troubleshooting.md
  - .docs/local_development/dev-mode.md
  - .docs/runbooks/00-index.md
  - .docs/runbooks/release-cut.md
  - .docs/runbooks/verify-release.md
  - .docs/runbooks/force-password-reset.md
  - .docs/legal/00-index.md
  - .docs/legal/data-retention.md
autonomous: true
requirements:
  - gap-docs-folder-skeleton
requirements_addressed:
  - gap-docs-folder-skeleton
must_haves:
  truths:
    - ".docs/ tree mirrors happklaar/happklaar structure: 00-index.md at top + adr/ + architecture/ + features/ + cicd/ + local_development/ + runbooks/ + legal/ subtrees each with their own 00-index.md"
    - "Exactly 8 00-index.md files exist (top-level + 7 subtrees)"
    - ".docs/cicd/ ships overview.md + release-workflow.md (paired with already-extant branch-protection.md from Plan 17-12 stub + release-cadence.md from Plan 17-01)"
    - ".docs/local_development/ ships 4 topic files (setup, database, troubleshooting, dev-mode)"
    - ".docs/runbooks/ ships release-cut, verify-release, force-password-reset (and repo-security-setup stub already extant from Plan 17-12 placeholder)"
    - ".docs/legal/ ships data-retention.md (paired with already-extant license-rationale.md from Plan 17-07)"
    - "Every .docs/ file passes the noGsdLeakage arch invariant (Plan 17-08's .docs/ scan with the narrower pattern set)"
  artifacts:
    - path: ".docs/00-index.md"
      provides: "Top-level navigation table to subtrees per D-31"
    - path: ".docs/cicd/00-index.md + overview.md + release-workflow.md"
      provides: "CI/CD documentation skeleton"
    - path: ".docs/local_development/00-index.md + 4 topic files"
      provides: "Local development guide"
    - path: ".docs/runbooks/00-index.md + 3 runbooks"
      provides: "Operational procedures"
    - path: ".docs/legal/data-retention.md"
      provides: "Local-only data-retention prose"
  key_links:
    - from: ".docs/00-index.md"
      to: "Every subtree's 00-index.md"
      via: "Navigation links"
---

<objective>
Ship the `.docs/` tree skeleton: top-level + 7 subtree 00-index.md files + cicd / local_development / runbooks / legal content. ADRs + architecture topics + features template land in 17-09c.

Purpose: D-31..D-32 — the `.docs/` tree replaces `.planning/` as the public-facing documentation source. Splitting from 17-09a (the user-facing /help page) and 17-09c (ADR + architecture content) gives each plan a focused scope: skeleton + ops docs here, narrative + design docs in 17-09c.

Output: A navigable `.docs/` tree with all 8 indexes + the operational subtrees (cicd, local_development, runbooks, legal) populated. ADRs + architecture topics + features template remain to be filled by 17-09c.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-RESEARCH.md
@.planning/PROJECT.md
@CLAUDE.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Top-level + 7 subtree 00-index.md files + cicd/local_development/runbooks/legal content</name>
  <files>.docs/00-index.md, .docs/adr/00-index.md, .docs/architecture/00-index.md, .docs/features/00-index.md, .docs/cicd/00-index.md, .docs/cicd/overview.md, .docs/cicd/release-workflow.md, .docs/local_development/00-index.md, .docs/local_development/setup.md, .docs/local_development/database.md, .docs/local_development/troubleshooting.md, .docs/local_development/dev-mode.md, .docs/runbooks/00-index.md, .docs/runbooks/release-cut.md, .docs/runbooks/verify-release.md, .docs/runbooks/force-password-reset.md, .docs/legal/00-index.md, .docs/legal/data-retention.md</files>
  <read_first>
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-RESEARCH.md (entire — source for cicd/overview.md + cicd/release-workflow.md)
    - CLAUDE.md tech-stack section (source for local_development/setup.md + database.md)
    - .docs/cicd/branch-protection.md + .docs/runbooks/repo-security-setup.md (existing stubs if present from Plan 17-12 walkthrough — confirm; this plan does NOT fill them, but links to them)
    - .docs/cicd/release-cadence.md (created in Plan 17-01 — link from cicd/00-index.md + overview.md)
    - .docs/legal/license-rationale.md (created in Plan 17-07 — link from legal/00-index.md)
    - External reference: skim `https://github.com/happklaar/happklaar/tree/main/.docs` — mirror the navigation table format
  </read_first>
  <action>This is documentation prose work. Each file uses present-tense narrative, no GSD vocabulary in runtime patterns (the `.docs/` scan in Plan 17-08 has a narrower pattern set so phase number mentions ARE allowed in `.docs/`).

    Step A — Top-level navigation: `.docs/00-index.md`. Brief intro (`# beatrax documentation` + 1-2 paragraphs explaining this tree is the published documentation, mirroring happklaar's structure; `.planning/` is local-only and gets graduated here when artifacts mature into long-lived references). Then a navigation table:
    | Subtree | What it covers |
    |---|---|
    | [Architecture Decision Records](adr/) | Why we chose what we chose |
    | [Architecture](architecture/) | Module boundaries, pipelines, data model |
    | [Features](features/) | Per-module deep dives |
    | [CI/CD](cicd/) | Quality gate, release pipeline, branch protection |
    | [Local Development](local_development/) | Setup, database, troubleshooting, dev mode |
    | [Runbooks](runbooks/) | Operational procedures |
    | [Legal](legal/) | License rationale, data retention |

    Step B — Subtree 00-index.md (skeleton only for adr/, architecture/, features/ — content lands in 17-09c):
    - `.docs/adr/00-index.md`: brief intro ("Each ADR follows Status / Context / Decision / Consequences."); empty list placeholder ("ADRs land here in 17-09c."). 17-09c rewrites this with the actual ADR table.
    - `.docs/architecture/00-index.md`: brief intro ("Architecture topics describe how the system fits together at the module/data/pipeline level."); empty list placeholder.
    - `.docs/features/00-index.md`: brief intro ("Per-module deep dives following the _template/ shape."); empty list placeholder.

    Step C — CI/CD docs:
    - `.docs/cicd/00-index.md`: navigation table listing overview, branch-protection (Plan 17-12), release-cadence (Plan 17-01), release-workflow (this plan)
    - `.docs/cicd/overview.md` — high-level CI architecture: PR gate (ci.yml) on 8.4+8.5 axes + tag-triggered release.yml + gitleaks security.yml; SHA-pinning rule; CODEOWNERS gating; .env.bundled + APP_KEY sentinel pattern
    - `.docs/cicd/release-workflow.md` — step-by-step what happens when a tag is pushed (the Pattern-2 sequence from RESEARCH.md, paraphrased; gate → 3 platform builds → publish with Ed25519 manifest signing → verify-published)

    Step D — Local development:
    - `.docs/local_development/00-index.md`: navigation table listing setup, database, troubleshooting, dev-mode
    - `setup.md` — Laravel Herd install, composer install --no-dev, npm ci, php artisan migrate, php artisan native:build for testing the bundle
    - `database.md` — SQLite location, WAL mode, how to use TablePlus / DBNGIN / sqlite3 CLI
    - `troubleshooting.md` — common gotchas: PHP version, sodium extension, missing nativephp/php-bin builds for 8.5
    - `dev-mode.md` — flipping is_developer to true; the Dev Console surfaces; Horizon iframe under DIEDERIK_RUNTIME=herd

    Step E — Runbooks:
    - `.docs/runbooks/00-index.md`: navigation table listing release-cut, verify-release, repo-security-setup (Plan 17-12 fills), force-password-reset
    - `release-cut.md` — the operational procedure for cutting a release: bump version intent, push tag, watch release.yml, manually promote DRAFT to published for stable channels
    - `verify-release.md` — user-facing verification one-liner (referenced from README install-bypass Verification section): `sha256sum beatrax-{version}-{platform}.{ext}` + how to verify the Ed25519 manifest signature (provide the public-key hex + the sodium-cli command, OR a small `verify.sh` script users can download)
    - `force-password-reset.md` — the diederik:reset-password CLI fallback (from Phase 12 MULTI-04); operator runs it when recovery codes are exhausted

    Step F — Legal:
    - `.docs/legal/00-index.md`: navigation table listing license-rationale (Plan 17-07 extant), data-retention
    - `data-retention.md`: short prose explaining the local-only contract + how long data lives (forever, per the project's full-history retention rule) + how users export + delete (link to /help/data-locations).

    Throughout: present-tense prose. Cross-links use relative paths. Each `00-index.md` has a one-line description for each child file. Phase number mentions (`Phase 17`, `D-23`) ARE allowed in `.docs/` per RESEARCH Q4 RESOLVED — but `.planning/` / `PLAN.md` / `RESEARCH.md` / `CONTEXT.md` / `gsd[-_]` references are NOT.</action>
  <verify>
    <automated>test -f .docs/00-index.md && test -d .docs/adr && test -d .docs/architecture && test -d .docs/features && test -d .docs/cicd && test -d .docs/local_development && test -d .docs/runbooks && test -d .docs/legal && find .docs -maxdepth 2 -name "00-index.md" | wc -l | grep -q "^[[:space:]]*8$" && test -f .docs/cicd/overview.md && test -f .docs/cicd/release-workflow.md && test -f .docs/local_development/setup.md && test -f .docs/local_development/database.md && test -f .docs/local_development/troubleshooting.md && test -f .docs/local_development/dev-mode.md && test -f .docs/runbooks/release-cut.md && test -f .docs/runbooks/verify-release.md && test -f .docs/runbooks/force-password-reset.md && test -f .docs/legal/data-retention.md && vendor/bin/pest tests/Contracts/GsdLeakageTest.php --stop-on-failure</automated>
  </verify>
  <done>Exactly 8 00-index.md files materialize (top-level + 7 subtrees); cicd / local_development / runbooks / legal content all present; noGsdLeakage arch invariant STILL green for BOTH runtime AND .docs/ corpora; cross-links resolve.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| .docs/ committed to public repo | Anything here is permanently public; tampering with operational docs could mislead users into insecure bypasses |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-09b-01 | Information disclosure | GSD vocabulary leaking into .docs/ committed to public repo | mitigate | Plan 17-08's noGsdLeakage .docs/ scan catches `.planning/`, `PLAN.md`, `RESEARCH.md`, `CONTEXT.md`, `gsd[-_]` — verify step asserts green |
| T-17-09b-02 | Tampering | runbook `verify-release.md` shipping with a stale PUBLIC key | mitigate | The runbook references the same public key committed in `config/auto_update.php` (Plan 17-04); future key rotations land as paired config + docs edits |
</threat_model>

<verification>
After Task 1:

1. All 8 00-index.md files exist
2. cicd / local_development / runbooks / legal content present
3. noGsdLeakage green (both runtime + .docs/ scans)
4. Cross-links resolve
</verification>

<success_criteria>
- All 7 must_haves true
- .docs/ skeleton publishable as-is
- Operational content (ops docs + runbooks) substantive (not just headings)
- ADR + architecture + features content explicitly deferred to 17-09c
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-09b-SUMMARY.md` capturing: the final file count per subtree, any deviations from happklaar's structure, and the cross-link graph from .docs/00-index.md.
</output>
