# One way to build a string

A string that carries values is built with `sprintf`. Interpolating into a
double-quoted string is not used.

```php
// no
throw new InvalidArgumentException("Categorization rule: invalid field '{$field}'.");

// yes
throw new InvalidArgumentException(sprintf("Categorization rule: invalid field '%s'.", $field));
```

`AStringIsBuiltOneWayArchTest` enforces it over every PHP file the repository
walks.

## Why

The sentence and its values stop being interleaved. A reader checking the
wording reads the wording; a reader checking which values land where reads the
argument list. With interpolation both jobs are done at once, over a line
broken up by `{`, `$` and `}`.

It also makes the format the thing under review. A translator, a locale check
or a guard reading a literal sees one continuous string rather than fragments
either side of an expression.

## Heredoc and nowdoc are exempt

They are unchanged and stay as they are. A block of text with values in it is
the one place interpolation reads better than a format string, and turning a
twenty-line heredoc into a `sprintf` with twelve arguments would be worse by
every measure this convention is for.

The guard skips them properly rather than by pattern: PHP's tokenizer brackets
a heredoc body between `T_START_HEREDOC` and `T_END_HEREDOC`, so the rule walks
tokens and never sees inside one. A text scan could not tell the difference —
the same reason a Blade scan here uses a parser rather than a regular
expression.

## What this changed about guards that read literals

**Four** existing rules matched on the interpolated form, and every one had to
move with it. None of them loosened, and each was re-proved by inversion rather
than by going green. This is the part worth reading before writing a fifth:

- **`APrivateKeyNeverLeavesTheDeviceThatMintedItArchTest`** pinned the sealed
  key file's path as `{$userId}.enc` — the account id and nothing else. Under
  `sprintf` the literal is `%s`, which on its own names anything. The rule did
  not weaken, because it already checked the **signature** beside the literal
  (`function path(int $userId)`), and that is what fixes what `%s` can be. A
  guard that pins a value's shape should pin the type that produces it, not
  only the spelling at the call site.
- **`EveryTranslatedLineReachesAReaderArchTest`** finds which lang groups are
  read by scanning for `module::group` literals. A key assembled as
  `"core::settings.currency_display.{$key}"` became
  `sprintf('core::settings.currency_display.%s', $key)`. The literal still
  names the group, so the rule reads it the same way once it accepts `%s`
  where it accepted an interpolation.
- **`EveryRouteReflowsAtTheReadersAccessibilitySizesArchTest`** reads a
  control's class list to check a wrapping cap sits beside `shrink-0`. It knew
  two spellings of a class list — `class="…"` and `'class' => "…"` — and three
  components now build one with `sprintf`, where the **format string is the
  class list**. A third pattern, and the tokens it reads sit in the format
  either way.
- **`CrossUserIsolationTest`** is the instructive one, because it already
  walked *tokens* rather than matching a regex, with a comment saying
  interpolation and concatenation both defeat a pattern over source text. It
  assembles each probed path from the tokens inside `->get(…)`: literal runs
  append themselves, and a `T_VARIABLE` appends `"1"` as the placeholder. Under
  `sprintf` the dynamic segment stops being a variable *between* literals and
  becomes a specifier *inside* one — so the path assembled as
  `/migrations/%s/preview` **+ `1`**, putting the id after the whole path
  instead of in its slot. Being a parser rather than a regex did not save it;
  it had to learn that a specifier is a third way to spell a hole.

All four are the same lesson: **a rule written against one spelling of an
expression is a rule about the spelling.** Pin the property. And a guard that
reads source is worth inverting whenever the source's shape changes — three of
these four would have gone quietly wrong rather than red.

## The refactor

1271 strings across 521 files, converted by tokenizer rather than by hand or by
regular expression — literal runs became the format, interpolations became
`%s`, and any literal `%` was doubled. Nothing was refused. Two shapes that
would have needed care turned out to be absent from the tree: a literal `%`
beside an interpolation, and an escape sequence beside one.
