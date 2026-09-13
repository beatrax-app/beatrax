# A requirement written after the work is cited by nothing

A requirement minted to *describe* code that already merged can never be cited
by the change that implemented it. There was no identifier to put in the
trailer when that commit was written. Nothing is wrong with the code, nothing
is wrong with the requirement, and the governance gate will read the
requirement as uncited for the rest of its life — because the gate reads
trailers, and trailers are written before merge.

Eleven of the thirty-four requirements added to the v2.0.0 release manifest
after its own 2026-09-05 audit are in exactly this state. This page records
which, what implements each, and what a later citation does and does not fix.

## What counts as a citation

`spec_check.py` in the spec repository is the only mechanical definition, and
it is narrower than it reads. It concatenates the pull-request body with
`git log --format=%B base..head`, then keeps **only** identifiers that appear
on a line matching `^\s*Spec:\s*(\S.*)$`. Everything else in the text is
invisible to it.

Three consequences follow, and each of them has already bitten:

- **Prose naming an identifier is not a citation.** A body that says
  *"Cites **A3-R22** and **A3-R23**"* cites neither as far as the gate is
  concerned. The gate passed that pull request on the `Spec: A3-R22` trailer
  its commit carried, and `A3-R23` went by unread.
- **One `Spec:` line may carry several identifiers**, because the whole line
  is scanned — and a squash can keep one of them. `21797d4ef` (#359) carries
  `Spec: E3-R8` where the pull-request body carries
  `Spec: E3-R8, E3-R9, E3-R22, E3-R23`. `E3-R22` was later picked up by
  `47eee5c54`; `E3-R9` and `E3-R23` are still cited by that body and by
  nothing in the commit trail.
- **An audit that accepts "a commit *or* a pull request names the id"** grades
  more identifiers as cited than the gate does. Measured over 818 pull-request
  bodies and every commit on `main`: `A3-R23`, `E5-R27`, `E6-R14` and `E6-R16`
  are named only in prose, and read as uncited to the gate.

## The two ways an identifier ends up cited by nothing

**The inversion.** The specification change merges *after* the implementation,
so the trailer could not have named it. This is
[GOV-R4](https://github.com/beatrax-app/spec/blob/main/50-governance/change-lifecycle.md)
run backwards, and the pull-request body usually shows it happening: it
proposes the requirement in full rather than citing it.

**The slip.** The specification change merged first, exactly as it should, and
the trailer named the sibling identifier and not this one. Nothing about the
process failed; one word was left out of one line.

## The eleven, and what implements each

Every commit below is on `main`; every identifier below exists on `spec@main`.
The implementing commit was re-derived with `git log -S` against the seam the
requirement is about, not taken from the audit's attribution — and two of them
came back different from it.

| id | the seam that implements it | commit / PR | identifier minted | shape |
|---|---|---|---|---|
| `A5-R18` | `RestatedRowMatch::matching()` asked before `FingerprintStage`'s receipt-band arm | `f3154bcf3` (#711), 2026-09-12 20:43 | spec `dc8e9de`, 21:47 | inversion by 63 min |
| `C1-R16` | `NetWorthQuery::forUser()` excluding `AccountKind::mirrorValues()` | `b4906a98a`, 2026-06-07; consolidated to one decision point in `c9fc9c388` (#274) | spec `98e98e7`, 2026-07-27 | pre-specification |
| `C1-R17` | `PeriodPresetResolver`'s round-trip on `?from=`/`?to=`, now `SafeDate::dayOrNull()` | `7fe579efc`, 2026-07-07; moved onto the shared seam in `c9fc9c388` (#274) | spec `98e98e7`, 2026-07-27 | pre-specification |
| `C1-R18` | `PinCap::MAX_PINS`, enforced in the write transaction and again on the dashboard read | `d3b0354b1` + `ec70f2766`, 2026-07-07; one spelling in `cf99ce4ab` (#268) | spec `98e98e7`, 2026-07-27 | pre-specification |
| `D1-R30` | `CarryoverQuery::foldPeriod()` folding each reduce-to-budget shortfall into the pool it carries | `cf06d567c`, 2026-07-04 | spec `d9060c4b`, 2026-09-05 | inversion by two months |
| `E2-R22` | `IntroductionOffers::carriedAuthorsFor()` over the keys on file, narrowed by what the asker can verify | `aec5d7014` (#406), 2026-09-05 20:31:49 | spec `d9d26f1`, 21:02:33 | inversion by 30 min |
| `E5-R26` | `SecureStorageKeyCustodian::store()` throwing rather than returning the raw key | `4c954a7f5` (#237), 2026-08-17 22:02 CEST | spec `74304e7`, 23:01 | inversion by 59 min |
| `E5-R27` | `InitialSyncPuller::pull()` adding the declared hold to the expected count | `f10336ff3` (#407), 2026-09-05 21:02:59 | spec `d9d26f1`, 21:02:33 | merged 26 s after the identifier existed |
| `E6-R14` | `SyncStatusService::settledStatus()` ranking withheld above behind, below offline | `f10336ff3` (#407); ranking finished in `b756c6a12` (#433) | spec `d9d26f1`, 2026-09-05 | both — inversion at #407, missed trailer at #433 |
| `E6-R16` | `sync::devices.withheld_explainer` and a withheld block that renders no action control | `a4a9a4fb3` (#397), 2026-09-05 19:26 CEST | spec `d9d26f1`, 21:02 | inversion by 1 h 36 min |
| `A3-R23` | `TransactionBooking::of()` carried onto the disposition, so the restated row adopts the dates unasked | `f299fdfab` (#717), 2026-09-12 21:57:54 | spec `dc8e9de`, 21:47:15 | slip — the specification merged first and the trailer named only `A3-R22` |

Two of those attributions differ from the audit that found the gaps. `C1-R16`
was credited to the pull request that gave the exclusion a single decision
point; the exclusion itself shipped with the net-worth roll-up eleven weeks
earlier. `C1-R17` was credited to the same pull request; the shape-plus-round-trip
refusal was already in `PeriodPresetResolver` and that pull request moved it
onto `SafeDate`. Both corrections point at the same thing: the commit that
*touched* a seam last is not the commit that *implemented* the requirement.

## What a later citation does, and what it does not

Citing an identifier in a subsequent pull request makes the gate pass for
**that** pull request. It does not reach backwards. `spec_check.py` runs per
pull request against that pull request's own text, so the original change stays
exactly as uncited as it was, and a query of the form *"which change cites
`C1-R16`?"* answers with the records page rather than with the net-worth
roll-up.

So the honest reading is: **a later citation records the identifier; it does
not cite the work.** What it buys is that the identifier stops being absent
from the repository altogether — a search now finds the seam, the commit and
the reason the trailer is missing, instead of finding nothing and leaving the
next reader to conclude the requirement was never implemented. That is worth
having, and it is not the same thing as the definition of done being met.

The one gap it genuinely closes is the audit's: an identifier that appears
nowhere cannot be told apart from an identifier nobody implemented, and every
future audit would have to re-derive all eleven seams from scratch to tell the
difference. This page is that derivation, written down once.

## Not doing it again

Open the specification change first and let it merge, then write the trailer
([GOV-R4](https://github.com/beatrax-app/spec/blob/main/50-governance/change-lifecycle.md)).
Where a requirement is being minted *from* work already done — a legitimate
thing to do, and the reason nine of the eleven above exist — the implementing
pull request cannot cite it, and the record belongs in a table like this one
rather than in a trailer that would have to be back-dated. `A3-R23` is the
one pure slip; `E6-R14` is both, an inversion when the arm landed and a missed
trailer every time after.

When a squash collapses several commits, check that the `Spec:` line survived
with every identifier it carried. #359's did not, and only its pull-request
body kept the other three; `A3-R23`'s never reached a `Spec:` line at all.
