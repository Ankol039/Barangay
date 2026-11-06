import { spawnSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(import.meta.dirname, '../..')
const output = resolve(root, 'docs/verification/parity')
mkdirSync(output, { recursive: true })
const php = '/Applications/XAMPP/xamppfiles/bin/php'
const suites = [
  ['shared', 'node', ['--test', ...['shared-ui', 'react-auth', 'landing'].map(n => `docs/tests/${n}.test.mjs`)]],
  ['authentication', php, ['docs/tests/authentication-api.php']],
  ...['resident-dashboard', 'resident-survey', 'resident-profile', 'staff-dashboard', 'staff-surveys',
    'staff-results', 'staff-residents', 'staff-resident-actions', 'request-review', 'staff-users']
    .map(n => [n, php, [`docs/tests/${n}-api.php`, '--react']]),
]
const results = []
// Fixtures use shared registries. Each suite owns its full create/test/finally-clean lifecycle.
for (const [name, executable, args] of suites) {
  const started = Date.now()
  const run = spawnSync(executable, args, { cwd: root, encoding: 'utf8', timeout: 240000, maxBuffer: 8e6 })
  const log = `${run.stdout || ''}${run.stderr || ''}`
  writeFileSync(resolve(output, `${name}.log`), log)
  const result = { name, pass: run.status === 0 && !/(?:#|ℹ) skipped [1-9]|Warning: Failed prop type:|Each child in a list should have a unique/.test(log), exit: run.status,
    seconds: +((Date.now() - started) / 1000).toFixed(2),
    reactTests: Number(log.match(/(?:#|ℹ) tests (\d+)/)?.[1] || 0),
    apiAssertions: Number(log.match(/(?:PASS: |API: |Passed )(\d+) (?:assertions|live|resident|staff)/)?.[1] || 0),
    error: run.error?.message }
  results.push(result)
  writeFileSync(resolve(output, 'regression.json'), JSON.stringify({ date: '2026-11-06', results }, null, 2))
  console.log(`${result.pass ? 'PASS' : 'FAIL'} ${name} (${result.seconds}s)`)
}
if (results.some(result => !result.pass)) process.exitCode = 1
