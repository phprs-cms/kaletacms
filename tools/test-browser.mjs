// Kaleta – browser test (called by tools/test-browser.sh): walks the admin, the builder, the news editor, the menu
// editor and the public site in Chrome and fails on any uncaught script error or console error.
// Env: BASE, PASSWORD (admin), CHROME (browser binary), NODE_PATH (folder with playwright-core), SHOTS (optional folder
// for screenshots of new screens, to look at them), MYSQL_JSON and DB_NAME (the mysql command as a JSON list and the
// database – 3.18: a step changes the state of the Claude connection).
// 3.49 P1: the steps live in tools/tests/browser – lib.mjs (the browser, the page, step(), proof(), visit(), sql() and the
// shared helpers) and NNNN-<topic>.mjs files, each `export default async (t) => { … }`. This runner takes them in number order
// on one site (the order is the contract – a step may use what an earlier one left), refuses a file with another name or a
// number used twice, and prints the total. STEPS_FILE=<path> lists every step with its number of proof() calls.
import { readdirSync } from 'node:fs';

const dir = new URL('./tests/browser/', import.meta.url);
const files = readdirSync(dir).filter((f) => f !== 'lib.mjs').sort();
const numbers = new Set();
for (const f of files) {
  if (!/^\d{4}-[a-z0-9.-]+\.mjs$/.test(f)) { console.log(`  CHYBA  tools/tests/browser/${f} is not NNNN-topic.mjs – it would never run`); process.exit(1); }
  if (numbers.has(f.slice(0, 4))) { console.log(`  CHYBA  tools/tests/browser: two files with the number ${f.slice(0, 4)} – the order would not be clear`); process.exit(1); }
  numbers.add(f.slice(0, 4));
}
const t = await import(new URL('lib.mjs', dir));
for (const f of files) {
  await (await import(new URL(f, dir))).default(t);
}

await t.browser.close();
if (t.errors.length) {
  console.log(`\nSCRIPT ERRORS: ${t.errors.length}\n  ` + [...new Set(t.errors)].join('\n  '));
  process.exit(1);
}
console.log(`\nBROWSER TEST OK (${t.state.steps} steps)`);
