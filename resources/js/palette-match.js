import Fuse from 'fuse.js';

/**
 * Ranking for the command palette's navigation, action and command rows.
 *
 * Fuse.js alone answers "is this roughly like what you typed", which is the
 * right question for a typo and the wrong one for a prefix: typing `go` scored
 * Categorization (the `go` inside "cate-go-rization") above Goals, and `un`
 * scored a dev command's hint above Unusual charges. So the deterministic
 * matches are collected first, in tiers, and Fuse only supplies what is left.
 *
 *   0  the label starts with what was typed        Budgets   ← "budget"
 *   1  a later word of the label starts with it    Unusual charges ← "charges"
 *   2  it appears somewhere inside the label       Reconcile ← "co"
 *   3  a keyword starts with it                    Email     ← "receipts"
 *   4  the hint carries it                         Chains    ← "funding"
 *   5  Fuse, for a genuine misspelling
 *
 * Within a tier the shorter label wins, then registry order — which is the
 * order the sidebar lists its rows in, so ties break the way the rail reads.
 *
 * Comparison runs on a normalised form: accents folded, lower-cased, and a
 * trailing plural `s` trimmed off both sides. That is what lets "pot" find
 * Pots and "budgets" find Budgets in one rule instead of two, and what lets a
 * Czech reader type "rozpocty" for Rozpočty.
 *
 * Fuse's weights and threshold are LOCKED per UI-SPEC § Component inventory.
 */

const FUSE_OPTIONS = {
    keys: [
        { name: 'label', weight: 0.65 },
        { name: 'hint', weight: 0.20 },
        { name: 'keywords', weight: 0.15 },
    ],
    threshold: 0.35,
    ignoreLocation: true,
};

const TIER_LABEL_PREFIX = 0;
const TIER_LABEL_WORD = 1;
const TIER_LABEL_INSIDE = 2;
const TIER_KEYWORD = 3;
const TIER_HINT = 4;

export const normaliseTerm = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

// "ss" is left alone so a German label ending in one keeps its last letter.
const singular = (term) => (term.length > 3 && term.endsWith('s') && !term.endsWith('ss')
    ? term.slice(0, -1)
    : term);

const wordsOf = (value) => normaliseTerm(value).split(/[^\p{L}\p{N}]+/u).filter(Boolean);

const startsWithTerm = (value, stem) => wordsOf(value).some((word) => singular(word).startsWith(stem));

const carriesTerm = (value, query, stem) => startsWithTerm(value, stem) || normaliseTerm(value).includes(query);

const tierOf = (item, query, stem) => {
    const label = normaliseTerm(item.label);

    if (label.startsWith(query) || singular(label).startsWith(stem)) {
        return TIER_LABEL_PREFIX;
    }
    if (startsWithTerm(item.label, stem)) {
        return TIER_LABEL_WORD;
    }
    if (label.includes(query)) {
        return TIER_LABEL_INSIDE;
    }

    const keywords = Array.isArray(item.keywords) ? item.keywords : [];
    if (keywords.some((keyword) => carriesTerm(keyword, query, stem))) {
        return TIER_KEYWORD;
    }
    if (carriesTerm(item.hint, query, stem)) {
        return TIER_HINT;
    }

    return null;
};

/**
 * Build a matcher over a palette registry.
 *
 * Returns a function of the query that yields Fuse-shaped `{ item }` rows, so
 * the template that walks the results does not care which tier produced one.
 * An empty query yields the whole registry, in registry order.
 */
export const createPaletteMatcher = (registry) => {
    const rows = Array.isArray(registry) ? registry : [];
    const fuse = new Fuse(rows, FUSE_OPTIONS);

    return (rawQuery) => {
        const query = normaliseTerm(rawQuery);
        if (query === '') {
            return rows.map((item) => ({ item }));
        }

        const stem = singular(query);
        const ranked = [];
        const claimed = new Set();

        rows.forEach((item, index) => {
            const tier = tierOf(item, query, stem);
            if (tier === null) {
                return;
            }
            ranked.push({ item, tier, index, width: normaliseTerm(item.label).length });
            claimed.add(item.id);
        });

        ranked.sort((a, b) => a.tier - b.tier || a.width - b.width || a.index - b.index);

        const results = ranked.map(({ item }) => ({ item }));

        // The raw query, not the normalised one: Fuse is the typo pass, and
        // folding the query before handing it over would change what counts
        // as a typo.
        for (const hit of fuse.search(String(rawQuery ?? ''))) {
            if (!claimed.has(hit.item.id)) {
                results.push({ item: hit.item });
            }
        }

        return results;
    };
};
