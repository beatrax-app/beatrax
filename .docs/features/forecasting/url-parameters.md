# Reader-supplied URL parameters on /forecast

`ForecastPage` binds three `#[Url]` properties — `?horizon=`, `?account=` and
`?scenarioId=`. Anything can arrive in the address bar: a stale bookmark, a
shared link, a hand-edited value. The rule below is what each one does with a
value it cannot honour, and `tests/Contracts/TamperedUrlParameterContractTest.php`
pins it in both directions.

## Soft-reset, don't refuse

A junk value falls back to the page's default view and renders. It does not
404 and it does not render blank.

| Parameter | Unhonourable value | Result |
|---|---|---|
| `horizon` | not in `ForecastHorizon::days()` | falls back to the default horizon |
| `account` | non-numeric, or an id that is not the reader's own | falls back to the aggregate tab |
| `scenarioId` | an id that is not the reader's own | dropped, page renders unscoped |

`?horizon=999` previously rendered a 999-day projection with no chip lit and no
way back to a horizon the rail offers. `?account=0`, a negative, and a value
past every column width previously 404'd — a dead end reached from a bookmark
whose account has since been closed.

## One answer for both cases

`resolveAccount()` used to distinguish *does not exist for anyone* from
*exists, but not for you*, soft-resetting the first and throwing
`NotFoundHttpException` for the second. **That was wrong.** The argument for
it ran that soft-resetting a foreign id would tell the reader their own
aggregate view is the answer to a question about another household's account,
and it named probeability as the *reason* for the split.

It is the other way round. Two different answers to "is this id yours?" and "is
this id anybody's?" *are* the oracle: a caller walking `?account=1,2,3…` reads a
404 as "this row exists somewhere" and a rendered page as "this row exists for
nobody", and learns exactly which ids another household owns without ever
seeing a figure. One answer for both cases is what closes it.

Both now soft-reset. The page renders the reader's own aggregate and carries
none of the neighbour's rows — `ForecastCrossUser404Test` asserts the two
answers are identical *and* that the rendered page names no account of the
other reader's, so closing the oracle does not open a leak in its place.
`?scenarioId=` takes the same rule, for the same reason.

## Why the pin is empty

The pinned set of 4xx answers is `[]`. That is a decision, not an absence:
every parameter above soft-resets, and there is no refusal left on this page to
pin. A page that starts refusing a junk parameter again has to show up as a diff
on that pin and be argued for.

Related: [scenario-isolation.md](scenario-isolation.md).
