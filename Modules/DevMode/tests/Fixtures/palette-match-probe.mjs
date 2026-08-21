import { createPaletteMatcher } from '../../../../resources/js/palette-match.js';

// Runs the palette's real matcher against a registry handed in on stdin and
// prints, per query, the labels it ranks and in what order. The PHP test that
// drives this owns the assertions; this only crosses the language boundary.

const readStdin = () => new Promise((resolve, reject) => {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => { raw += chunk; });
    process.stdin.on('end', () => resolve(raw));
    process.stdin.on('error', reject);
});

const payload = JSON.parse(await readStdin());
const match = createPaletteMatcher(payload.registry);

const answers = {};
for (const query of payload.queries) {
    answers[query] = match(query).map((hit) => hit.item.label);
}

process.stdout.write(JSON.stringify(answers));
