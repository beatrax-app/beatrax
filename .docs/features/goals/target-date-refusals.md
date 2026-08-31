# Why a goal's target date can be refused, and how the two refusals differ

`GoalWriter` refuses a target date for two unrelated reasons, and the reader has
to be told which one applies. They were one exception and one sentence, so a
date that was perfectly real came back as *"Choose a real date."* — an answer to
a question the reader had not asked.

## Not a calendar date

`InvalidGoalTargetDateException`.

Carbon normalises `2026-02-30` to `2026-03-02`, so a string that was never a
date does not fail to parse — it parses into a *different* date and stores
cleanly. The check is therefore a round-trip, and it is not written here: the
writer asks `SafeDate::dayOrNull()`, the one reading of "is this the day
somebody meant" that every supplied date in this app goes through. It formats
the parsed value back and compares it to what arrived, and it catches both a
string that reads as a different date and one that cannot be read at all.

The column used to take whatever the form sent, and every reader of it — the
projection, the card, the sort order — then worked from a date the goal's owner
never chose.

## A real date the goal starts after

`GoalTargetDateBeforeStartException`, which narrows the first.

`start_date` is stamped once, at creation, from the same single read of today
that the validation uses — taken twice across midnight, the row is checked
against one day and written with another. A target date earlier than that date
describes a goal that ended before it began, so it is refused.

The bound is the goal's **own** `start_date`, not today:

- On **create**, `start_date` is today, so a target date in the past is refused.
  A goal that was already missed cannot be recorded this way, because its start
  would still be stamped today and the row would be incoherent rather than
  historic.
- On **update**, `start_date` is whatever the goal was created with, so an
  existing goal *can* be given a target date that has already passed. That is
  the supported way to have a goal read as overdue, and
  `GoalProgressState::Overdue` is the state it lands in.

The two therefore need two sentences, `goals::messages.errors.date_invalid` and
`goals::messages.errors.date_before_start`, and `GoalsPage::applyWriteFailure`
tests the narrower type first — the subclass would otherwise be caught by its
parent's arm and print the wrong one again.
