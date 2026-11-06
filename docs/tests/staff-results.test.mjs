import assert from 'node:assert/strict'
import { before, after, test } from 'node:test'
import { readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { fileURLToPath } from 'node:url'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'

const exec = promisify(execFile)
const fixtures = await readFile(join(tmpdir(), 'barangay-staff-results-fixtures.json'), 'utf8').then(JSON.parse).catch(() => null)
const originalFetch = globalThis.fetch
const base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/' })
let React, createRoot, router, server, root, Results, Reports, StatDetail, cookie, navigation, failure = false
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  if (!fixtures) return
  for (const key of ['window', 'document', 'HTMLElement']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  router = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-staff-results-vite-tests'), server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  ;({ default: Results, Reports } = await server.ssrLoadModule('/src/pages/staff/Results.jsx'))
  ;({ default: StatDetail } = await server.ssrLoadModule('/src/pages/staff/StatDetail.jsx'))
  globalThis.fetch = async (path, options) => failure ? Response.json({ ok: false, message: 'Synthetic failure.' }, { status: 500 }) : originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
})
after(async () => {
  if (React) await React.act(async () => root?.unmount())
  await server?.close(); globalThis.fetch = originalFetch; dom.window.close()
})
async function until(check) {
  const deadline = Date.now() + 8000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(check(), document.body.textContent)
}
function Location() { navigation = router.useNavigate(); const location = router.useLocation(); return e('output', { id: 'location' }, location.pathname + location.search) }
async function mount(Screen, path, role = 'staff') {
  await React.act(async () => root?.unmount())
  const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures[role].number, password: 'SurveyFixtureA1' }) })
  assert.equal(response.status, 200)
  cookie = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  document.body.innerHTML = '<div id="root"></div>'; root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(e(React.StrictMode, null, e(router.MemoryRouter, { initialEntries: [path] }, e(Location), e(Screen)))))
  await until(() => !document.querySelector('[role="status"]'))
}
async function reference(mode, role, query) {
  const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./staff-results-reference.php', import.meta.url)), mode, String(fixtures[role].id), query, 'html'])
  return new JSDOM(stdout, { runScripts: 'outside-only' })
}
async function input(element, value) {
  await React.act(async () => {
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(element, value)
    element.dispatchEvent(new dom.window.Event('input', { bubbles: true }))
  })
}
const styleProperties = ['width', 'height', 'backgroundColor', 'marginBottom', 'fontWeight', 'fontSize', 'paddingLeft']
test('All result, report, CSV, stat detail and existing chart SQL remains identical to PHP', async () => {
  const read = path => readFile(new URL('../../' + path, import.meta.url), 'utf8')
  const sql = source => [...source.matchAll(/->(?:prepare|query)\((["'])([\s\S]*?)\1\)/g)].map(match => match[2])
  for (const [original, migrated] of [['staff/results_dashboard.php', 'api/lib/staff-results.php'], ['staff/reports.php', 'api/lib/staff-results.php'], ['staff/export_csv.php', 'api/lib/staff-report-export.php'], ['staff/ajax_stats.php', 'api/lib/staff-stats.php'], ['resident/ajax_stats.php', 'api/lib/resident-stats.php']]) {
    const source = sql(await read(original)), actual = sql(await read(migrated))
    assert.ok(source.length > 0)
    for (const query of source) assert.ok(actual.includes(query), `${original}: ${query}`)
  }
  const views = source => [...source.matchAll(/'sql'\s*=>\s*"([\s\S]*?)"/g)].map(match => match[1])
  const original = views(await read('staff/stat_detail.php'))
  assert.equal(original.length, 8)
  assert.deepEqual(views(await read('api/lib/staff-stat-detail.php')), original)
})

for (const kind of ['populated', 'empty']) test(`Results ${kind}: every figure, option, rating, text/date, bar width/color and source card matches PHP`, { skip: !fixtures }, async () => {
  const query = `survey_id=${fixtures[kind]}`
  await mount(Results, '/staff/results-dashboard?' + query)
  const ref = await reference('results', 'staff', query)
  assert.deepEqual([...document.querySelectorAll('.card, .stat-card')].map(text), [...ref.window.document.querySelectorAll('.card, .stat-card')].map(text))
  for (const selector of ['.bar-label', '.bar-fill', '.bar-track', '.bar-row', '.bar-value']) {
    const actual = [...document.querySelectorAll(selector)], expected = [...ref.window.document.querySelectorAll(selector)]
    assert.equal(actual.length, expected.length)
    actual.forEach((node, i) => styleProperties.forEach(property => assert.equal(node.style[property], expected[i].style[property], `${selector} ${i} ${property}`)))
  }
  assert.equal(document.querySelectorAll('[data-answer-text]').length, kind === 'empty' ? 0 : 200)
  assert.equal(document.querySelectorAll('[data-answer-text] script').length, 0)
  assert.equal(document.querySelector('a.btn').getAttribute('href'), `/staff/reports?survey_id=${fixtures[kind]}`)
  ref.window.close()
})
for (const kind of ['populated', 'empty']) test(`Report ${kind}: all source metadata, counts, zero rows and uncapped text match PHP`, { skip: !fixtures }, async () => {
  const query = `survey_id=${fixtures[kind]}`
  await mount(Reports, '/staff/reports?' + query)
  const ref = await reference('report', 'staff', query)
  const cards = [...document.querySelectorAll('.card')], expected = [...ref.window.document.querySelectorAll('.card')]
  const normalized = nodes => nodes.map(node => text(node).replace(/Generated on:.*$/, 'Generated on: [server time]'))
  assert.deepEqual(normalized(cards), normalized(expected))
  assert.equal(document.querySelector('.page-head').className, 'page-head no-print')
  assert.equal(document.querySelector('.card').className, 'card no-print')
  let prints = 0; window.print = () => prints++
  await React.act(async () => document.querySelector('button').click())
  assert.equal(prints, 1)
  assert.match(document.querySelector('a.btn-secondary').href, new RegExp(`/api/staff/report-export\\?survey_id=${fixtures[kind]}$`))
  if (kind === 'populated') assert.equal(document.querySelectorAll('.card li').length, 201)
  ref.window.close()
})
test('Live answer search follows source case-insensitive, untrimmed matching, no-match and clear behavior', { skip: !fixtures }, async () => {
  await mount(Results, `/staff/results-dashboard?survey_id=${fixtures.populated}`)
  const ref = await reference('results', 'staff', `survey_id=${fixtures.populated}`)
  const source = await readFile(fileURLToPath(new URL('../../assets/js/script.js', import.meta.url)), 'utf8')
  const searchCode = source.slice(source.indexOf("    document.querySelectorAll('[data-answer-search]')"), source.indexOf("    console.log('script.js v7 loaded')"))
  ref.window.eval(searchCode)
  const actual = document.querySelector('input[type=search]'), expected = ref.window.document.querySelector('input[type=search]')
  for (const query of ['NURSES', ' nurse ', 'unmatched xyz', 'Feedback answer 100', '']) {
    await input(actual, query); expected.value = query; expected.dispatchEvent(new ref.window.Event('input'))
    const visible = doc => [...doc.querySelectorAll('[data-answer-text]')].filter(node => node.style.display !== 'none').map(text)
    assert.deepEqual(visible(document), visible(ref.window.document))
    assert.equal(document.querySelector('.answer-no-match').style.display, ref.window.document.querySelector('.answer-no-match').style.display)
  }
  ref.window.close()
})
test('Selector auto-loads a different survey, updates URL, supports back/forward through router, and resets question search', { skip: !fixtures }, async () => {
  await mount(Results, `/staff/results-dashboard?survey_id=${fixtures.populated}`)
  await input(document.querySelector('input[type=search]'), 'nurses')
  const change = async id => {
    await React.act(async () => { const select = document.querySelector('select'); select.value = id; select.dispatchEvent(new dom.window.Event('change', { bubbles: true })) })
    await until(() => document.querySelector('select') && !document.querySelector('[role=status]'))
  }
  await change(String(fixtures.empty)); assert.equal(document.querySelector('.stat-number').textContent, '0')
  assert.equal(document.getElementById('location').textContent, `/staff/results-dashboard?survey_id=${fixtures.empty}`)
  await change(String(fixtures.populated)); assert.equal(document.querySelector('input[type=search]').value, '')
  await change(''); assert.match(document.body.textContent, /No Result Selected/)
  await React.act(async () => navigation(-1))
  await until(() => document.querySelector('[data-answer-text]'))
  assert.equal(document.querySelector('.stat-number').textContent, '3')
  await React.act(async () => navigation(1))
  await until(() => document.querySelector('.empty-panel h3'))
  assert.match(document.body.textContent, /No Result Selected/)
})
for (const key of ['total_surveys', 'active_surveys', 'total_residents', 'total_responses', 'staff_accounts', 'deactivated_residents', 'deactivated_staff', 'recent_logins']) test(`Stat detail ${key}: source heading, count, table and badge text/classes match`, { skip: !fixtures }, async () => {
  await mount(StatDetail, `/staff/stat-detail?view=${key}`, 'admin')
  const ref = await reference('detail', 'admin', `view=${key}`)
  for (const selector of ['.page-head', '.detail-count', '.card']) assert.equal(text(document.querySelector(selector)), text(ref.window.document.querySelector(selector)))
  assert.deepEqual([...document.querySelectorAll('.badge')].map(node => node.className), [...ref.window.document.querySelectorAll('.card .badge')].map(node => node.className))
  for (const link of document.querySelectorAll('.card a')) assert.match(link.getAttribute('href'), /^\/staff\/results-dashboard\?survey_id=\d+$/)
  ref.window.close()
})
test('Invalid views, missing survey, admin restriction, empty selector and failed transport are handled', { skip: !fixtures }, async () => {
  for (const key of ['recent_logouts', 'unknown', 'toString']) {
    await mount(StatDetail, `/staff/stat-detail?view=${key}`)
    await until(() => document.getElementById('location').textContent === '/staff/dashboard')
  }
  await mount(StatDetail, '/staff/stat-detail?view=staff_accounts')
  await until(() => document.getElementById('location').textContent === '/staff/dashboard')
  for (const Screen of [Results, Reports]) {
    await mount(Screen, '/staff/reports'); assert.match(document.body.textContent, /No (Result|Report) Selected/)
    await mount(Screen, '/staff/reports?survey_id=-1'); assert.match(document.body.textContent, /Survey not found\./)
  }
  failure = true
  try { await mount(Results, '/staff/results-dashboard'); assert.equal(document.querySelector('[role=alert]').textContent, 'Synthetic failure.') } finally { failure = false }
})
