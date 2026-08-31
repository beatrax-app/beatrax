# Analyser rules enforced locally

The hosted analysis runs after a branch is pushed. Its findings arrive on a
dashboard, which is the wrong place and the wrong time: the author has moved on,
and the build that introduced them was green. The guards described here move
three of those rules into the test suite, so a finding fails on the commit that
creates it.

They are ports, not opinions. Each one implements what the analyser's own check
does — including the exceptions that make it report far less than a naive
reading would — and each was checked against what the hosted analysis actually
publishes for this project before it was written.

## Prove the rule reports before guarding it

The rule that made this necessary is the one this page exists to warn about.

The active PHP profile is the built-in **Sonar way**, 195 rules, and every rule
below is in it with its default parameters. That is not enough to know a rule
reports. On the default branch there are **122 functions declaring more than
seven parameters**, the parameter rule is active with its maximum set to seven,
and the hosted analysis reports **zero**. Both facts are true, and a guard built
on the obvious reading of the rule would have failed the build 122 times over
findings the dashboard is never going to raise.

The answer was in the check's own source, not in the rule description: it
excludes promoted constructor properties from the count, and it stays silent
unless it can *prove* the method is not an override. So a data class with
fourteen promoted properties counts as zero, and a public method on a class
extending anything the analysis cannot resolve — a framework base class, a
package interface — is left alone because inheritance cannot be ruled out.

Both exclusions are implemented in the guard. With them, the local answer on the
default branch is zero, the same as the dashboard's.

The same check was done for the other two rules. Each guard reproduces the
hosted result on the default branch exactly, and each carries an empty pinned
list for the same reason: there is nothing to pin.

## The scope every guard reads

`sonar.sources` and nothing wider: `app`, `Modules`, `config`, `routes` and
`database`, minus the exclusions in `sonar-project.properties`, minus the test
roots. A guard reading a wider tree fails on files the dashboard will never
mention, which is the failure mode that gets a guard switched off.

Test files are excluded on evidence rather than on the rule's declared scope:
across this project's whole issue history, not one finding of any rule has ever
been raised in a test file. The fakes and spies living there would be failures
nothing else agrees with.

[`SonarSourceFiles`](../../tests/Contracts/Support/SonarSourceFiles.php) is that
scope, and the tokeniser the three guards share. It drops comments as well as
whitespace, because every reader below decides on what sits *directly* beside a
token — the name after `function`, the `::` before `class`, the `:` before a
`?` — and one docblock left in the stream separates those pairs and the reader
quietly stops recognising the construct. That mistake cost 276 files' worth of
wrong answers before it was found.

## S3776 — cognitive complexity

[`AFunctionStaysWithinTheComplexityTheAnalyserAllowsArchTest`](../../tests/Contracts/AFunctionStaysWithinTheComplexityTheAnalyserAllowsArchTest.php)
· threshold **15**, the profile's own

The largest source of findings this project has had: 116 of them. Cognitive
complexity is not a line count and not a branch count. A branch costs 1, plus 1
more for every level of nesting it sits under; `else` and `elseif` cost 1 flat;
a run of one logical operator costs 1 however long it is, and 1 more at every
switch between `&&` and `||`; a nested closure adds a level to everything inside
it. Its scoring is specific enough that an approximation would disagree with the
dashboard, so
[`SonarCognitiveComplexity`](../../tests/Contracts/Support/SonarCognitiveComplexity.php)
implements the algorithm rather than resembling it.

It is calibrated rather than trusted. The hosted analysis publishes a
`cognitive_complexity` measure per file, which is the sum of the same
per-function scores. Compared against all 2072 analysed files, the guard agrees
on **every one of them** — the three that differed at first were files that had
changed between the local checkout and the analysed commit, and all three agree
once read at the revision the analysis ran on.

Eight scoring rules are pinned as their own tests. They are the cases where the
obvious reading is wrong, and they exist so a later simplification cannot
quietly change the number while the tree still happens to be clean.

## S1448 — too many methods in a class

[`AClassStaysUnderTheMethodCountTheAnalyserAllowsArchTest`](../../tests/Contracts/AClassStaysUnderTheMethodCountTheAnalyserAllowsArchTest.php)
· ceiling **20**, non-public methods counted

Eighteen findings. The ceiling counts private and protected methods too, so a
class sitting on the line goes over the moment a private helper is extracted —
which is how most of the eighteen arrived. Eight classes on the default branch
declare exactly twenty methods, which is what a codebase refactored down to a
threshold looks like, and is the second reason to believe the rule is live.

What it counts is *declarations in the body*. Methods a `use`d trait brings in
are not declarations here, and neither traits nor enums are checked at all. So
moving methods into a trait makes the number go down without making the class
smaller. The guard says so in its failure message, because that is the fix a
reader reaches for first and it is the wrong one.

The getters-and-setters exemption and the entity-annotation exemption are both
carried over. Nothing in this tree is an entity; the pattern is there so the
guard cannot be stricter than the rule it stands in for.

## S107 — too many parameters

[`AParameterListStaysUnderWhatTheAnalyserCountsArchTest`](../../tests/Contracts/AParameterListStaysUnderWhatTheAnalyserCountsArchTest.php)
· ceiling **7**, for constructors and everything else alike

Twenty findings, and the rule whose behaviour is explained at the top of this
page. Two exclusions carry the whole difference between 122 and 0:

- **A promoted constructor property is not a parameter.** It is a field
  declaration wearing a parameter's syntax, and the check filters it out before
  counting. Ninety-eight of the 122 are promoted-property constructors.
- **A method that might be inherited is not reported.** The check raises only
  when it can prove the method is not an override. A non-public method can never
  be one, and neither can a method on a type with nothing above it; everything
  else on a type extending or implementing something unresolvable is left alone.
  That is why a Livewire `mount()` and a queued job's `handle()` never appear.

The second is the one place the guard is knowingly narrower than the analyser.
Where a class extends another class *in this project*, the analysis can resolve
the hierarchy and will report a method the parent does not declare; the guard
cannot, and stays quiet. No such case exists on the default branch — all 24
non-constructor over-length signatures there sit on framework base classes — so
nothing is lost today, and the direction of the gap is stated here rather than
discovered later.

## What is not guarded here, and why

**Empty methods** are already covered, and more precisely than a new guard
would be. [`EmptyBodyExplainsItselfWhereSonarLooksArchTest`](../../tests/Contracts/EmptyBodyExplainsItselfWhereSonarLooksArchTest.php)
enforces the same rule and goes further: it requires the explanation to sit in
one of the two places the analyser actually reads.

**Unused private fields** are covered twice over already. PHPStan's own
unused-private-property and unused-private-constant rules are on from level 4,
this project runs level 10 over `Modules` and `app` with no baseline, and the
check blocks every pull request. It also resolves symbols properly, which the
hosted analyser does not — a private property read only from a trait the class
uses reads as unused to a check that sees one file at a time. This is the same
argument `sonar-project.properties` already makes for the sibling
unused-private-method rule. A third copy would add nothing; a scan of the whole
analysed scope for the shape finds nothing that PHPStan does not already reach.

**Copy-paste duplication** is deliberately left out. It raises no issues — it
publishes a density measure, and the gate condition is on duplication in *new*
code, which cannot be evaluated locally without the analysis's own definition of
new code. Reproducing its clone detection closely enough to agree with the
published block count would be a research exercise, and a duplication guard that
disagrees with the dashboard is worse than none: it either blocks work the
dashboard permits or passes work it rejects. The narrow, high-value case is
handled instead by rules like
[`OneHomePerRepeatedShapeArchTest`](../../tests/Contracts/OneHomePerRepeatedShapeArchTest.php),
which name one repeated shape and one home for it.

## Related

- [Invariants written after a shipped failure](invariants-from-shipped-failures.md)
  — the field history behind the rest of `tests/Contracts/`
- [Writing an arch invariant](arch-invariants.md) — the mechanics, and why a
  rule's rationale belongs in its failure message
