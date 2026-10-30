/** Verify scanner effectiveness against non-executed negative fixtures; receives Semgrep JSON path, requires three exact findings and no parse/config errors. */
import fs from 'node:fs';
import assert from 'node:assert/strict';
const report=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
assert.deepEqual(report.errors,[]);
assert.deepEqual(report.results.map(item=>[item.check_id.split('.').at(-1),item.start.line]).sort(),[['php-request-to-command',4],['php-request-to-include',6],['php-request-to-raw-output',8]].sort());
console.log('PHP security rules detected all three unsafe examples and accepted the escaped example.');
