/**
 * The browser half of `Modules\Core\Public\Support\Lang`.
 *
 * A count Alpine works out — rows matched, digits typed, files on disk — does
 * not exist while PHP renders, so `Lang::choice()` cannot be called for it.
 * `Lang::arms()` ships the arms and the reader locale's index table instead,
 * both taken from Laravel's own `MessageSelector`, and `choosePlural()` reads
 * that table. The language's rule picks the arm; nothing here knows a plural
 * rule of its own, which is the point — `n === 1 ? a : b` is right for English
 * and wrong for most of the twenty-six locales this app ships.
 *
 * Registered as the `$plural` and `$line` Alpine magics in ./app.js.
 */

/** Longest first, so `:counts` cannot be eaten by `:count`. */
const fillLine = (line, replace = {}) => Object.keys(replace)
    .sort((a, b) => b.length - a.length)
    .reduce((filled, name) => filled.split(':' + name).join(String(replace[name])), String(line));

/**
 * The reader's own grouping marks, from `<html lang>` — the same source the
 * chart month names take, and the counterpart to `Fmt::number()` on the server.
 */
const formatCount = (number) => {
    try {
        return number.toLocaleString(document.documentElement.lang || 'en');
    } catch {
        return String(number);
    }
};

/**
 * `arms.index` holds `span` exact entries followed by `span` more addressed by
 * `n % span`, which is exact for every larger number: no rule in
 * `MessageSelector` compares the number itself to anything as large as `span`.
 * The fallback to the first arm is `MessageSelector::choose()`'s own — a locale
 * selecting an index the line has no segment for reads its first.
 */
const choosePlural = (arms, key, number, replace = {}) => {
    const forms = arms?.forms?.[key];

    if (!Array.isArray(forms) || forms.length === 0) {
        return key;
    }

    const count = Number.isFinite(number) ? Math.max(0, Math.trunc(number)) : 0;
    const span = arms.span > 0 ? arms.span : 1;
    const slot = count < span ? count : span + (count % span);
    const index = arms.index?.[slot] ?? 0;
    const form = forms[index] ?? forms[0];

    return fillLine(form, { count: formatCount(count), ...replace });
};

export { choosePlural, fillLine };
