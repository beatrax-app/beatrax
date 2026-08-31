# What the demo envelope grid has to show

`DemoEnvelopeBudgetsSeeder` exists so the budgets page can be judged on a fresh
install. It once wrote assignments for **one** period and stamped
`users.envelope_activated_at` with the moment of the seed run. That made the
genesis period and the period on screen the same month, and four features had
no data at all:

| Feature | Why it was invisible |
|---|---|
| Carry-in | `CarryoverQuery` folds from genesis to the target. With one period the walk is one step and every `carriedInMinor` is 0. |
| Overspend modes | `ReduceToBudget` and `CarryNegative` differ only where a period ends below zero *and* a later period exists to receive the deficit. |
| Envelope moves | `envelope_moves` had no demo rows, so the moved column, the memo and the undo path rendered empty. |
| Previous period | `BudgetsPage::prevPeriod()` clamps at genesis. On the first press it returned without moving, which reads as a dead button. |

## What the seeder guarantees

- **Three periods**, taken from `DemoPeriodWindow::SPAN`, which the ledger's own
  demo rows are seeded over as well: the two seeders read one window rather than
  counting months apiece, so the fold has two prior periods to carry forward and
  `prevPeriod` moves twice before it clamps. Counted separately, the persona
  keeping `period_start_day = 25` opened this grid on EUR 2,490.00 assigned
  against EUR 0.00 spent.
- **`envelope_activated_at` stamped at the oldest seeded period**, on every run.
  It used to be written only when null, so a `--reset` months later left genesis
  pointing before the oldest assignment the fold could find.
- **Both overspend modes present** in `envelope_settings`, with `eating-out`
  deliberately over-spent in the two older periods so `CarryNegative` has a
  deficit to roll and `groceries` has one that `ReduceToBudget` absorbs.
- **Notify thresholds off the 90 default**, so that column reads stored values.
- **Two move pairs** in the current period, each two rows sharing a
  `move_group_id` — the shape `EnvelopeWriter::move()` writes, which is what
  `undoMove()` matches on.

## Keeping the amounts honest

`BUDGETS` is tuned against the spend `DemoTransactionsSeeder` produces. If the
transaction amounts change, re-derive it:

```sql
select c.slug, strftime('%Y-%m', t.posted_at) ym, -sum(t.settled_amount_minor)
from transactions t
join categories c on c.id = t.category_id
where t.user_id = ? and t.settled_currency = 'EUR' and t.settled_amount_minor < 0
group by c.slug, ym order by c.slug, ym;
```

An assignment far above its category's spend turns the grid into unspent green;
far below turns it into a wall of red. Neither shows the reader what the page is
for.
