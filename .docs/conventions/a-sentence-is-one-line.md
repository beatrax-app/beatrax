# A sentence is one line

A sentence that a reader sees whole is **one translation key**, with the parts
that vary written as placeholders. It is never assembled at the call site from a
prefix key, a value, and a suffix key. This page is the *how*;
`tests/Contracts/ASentenceIsNotAssembledFromTranslatedFragmentsArchTest.php` is
what fails when it is not.

## Why a split sentence cannot be translated

Concatenation happens in the order the code writes it, and that order is the
grammar of whoever wrote the code. A translator receives two fragments and no
way to move the value between them, so every language has to accept English
word order or say something else entirely.

The doctor panel's empty state was three keys with the command name appended
after the last of them:

```blade
{{ Lang::get('dev::doctor.empty_prefix') }}
<span class="font-semibold">{{ Lang::get('dev::doctor.empty_rerun') }}</span>
{{ Lang::get('dev::doctor.empty_suffix') }} <code>{{ $commandName }}</code>.
```

Twenty-six locales, and four of them could not be written correctly in that
shape at all:

| | rendered | why |
|---|---|---|
| `nl` | om aan te roepen `beatrax:doctor` | the verb belongs after the object |
| `et` | et see käivitada `beatrax:doctor` | `see` is already the object |
| `fi` | käynnistääksesi sen `beatrax:doctor` | `sen` is already the object |
| `lv` | lai to izsauktu `beatrax:doctor` | `to` is already the object |

The other twenty-two were correct only because their grammar happens to put the
object last, which is not a property anyone chose.

## The shape

One key, placeholders for everything variable, and the markup supplied by the
call site rather than by the translation:

```php
'empty_html' => 'No probe output captured yet. Press :action to invoke :command.',
```

```blade
{!! Lang::get('dev::doctor.empty_html', [
    'action' => '<span class="font-semibold">'.e(Lang::get('dev::doctor.rerun')).'</span>',
    'command' => '<code class="font-mono">'.e($commandName).'</code>',
]) !!}
```

Three things are load-bearing:

- **`:command` can move.** Dutch reads `om :command aan te roepen`; Turkish
  opens on it. Neither is expressible when the value is appended.
- **The classes stay in the Blade file.** Tailwind's content scan does not read
  `Resources/lang`, and a utility class that only appears there is a class the
  build can drop. It is also a class nobody would find when the family changes.
- **Every substituted value is `e()`-escaped at the call site**, because the
  line is rendered unescaped. The `_html` suffix on the key is the marker that
  it is: the translation itself carries no markup, but its rendering trusts the
  substitutions.

## The fragment that was also a duplicate

`empty_rerun` held the same word as `rerun`, the button's own label, in all
twenty-six files — so the sentence telling the reader which button to press and
the button itself were two keys that could drift. The call site now passes the
button's key, and a case pins that it does.

## Related

- [Copy that carries a count](counted-nouns-in-copy.md) — the other shape a
  sentence takes when part of it varies
- [A translated line has a call site](a-translated-line-has-a-call-site.md)
- [Field history](invariants-from-shipped-failures.md)
