import { choosePlural } from '../../../../resources/js/lang.js';

// Runs the shipped browser-side chooser against an arms payload handed in on
// stdin and prints what it would render, per asked-for count. The PHP test that
// drives this owns the assertions; this only crosses the language boundary.

const readStdin = () => new Promise((resolve, reject) => {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => { raw += chunk; });
    process.stdin.on('end', () => resolve(raw));
    process.stdin.on('error', reject);
});

const payload = JSON.parse(await readStdin());

const answers = {};
for (const ask of payload.asks) {
    answers[ask.label] = choosePlural(payload.arms, ask.key, ask.number, ask.replace ?? {});
}

process.stdout.write(JSON.stringify(answers));
