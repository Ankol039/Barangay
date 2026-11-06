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
const fixtures = await readFile(join(tmpdir(), 'barangay-staff-dashboard-fixtures.json'), 'utf8').then(JSON.parse).catch(() => null)
const originalFetch = globalThis.fetch
const base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/' })
const charts = new Map()
let React, createRoot, MemoryRouter, server, root, Dashboard, cookie
let failStats = false, failFilter = false, substitute = null, requests = []
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  if (!fixtures) return
  for (const key of ['window', 'document', 'HTMLElement']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  globalThis.__staffCharts = charts
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ MemoryRouter } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-staff-dashboard-vite-tests'),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom', ssr: { noExternal: ['react-chartjs-2'] },
    plugins: [{ name: 'staff-record-charts', enforce: 'pre',
      resolveId(id) { if (id === 'react-chartjs-2') return '\0staff-charts' },
      load(id) { if (id === '\0staff-charts') return `
        import * as React from 'react';
        function Chart(props) { globalThis.__staffCharts.set(props.id, props); return React.createElement('canvas', {id: props.id}); }
        export const Doughnut = Chart; export const Bar = Chart; export const Line = Chart;
      ` },
    }],
  })
  ;({ default: Dashboard } = await server.ssrLoadModule('/src/pages/staff/Dashboard.jsx'))
  globalThis.fetch = async (path, options) => {
    requests.push(path)
    if (path.includes('/staff/dashboard') && substitute) return Response.json({ ok: true, data: substitute })
    if ((path.endsWith('/staff/stats') && failStats) || (path.includes('/staff/stats?') && failFilter)) return Response.json({ ok: false, message: 'Synthetic failure.' }, { status: 500 })
    return originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
  }
})
after(async () => {
  if (React) await React.act(async () => root?.unmount())
  await server?.close(); globalThis.fetch = originalFetch; delete globalThis.__staffCharts; dom.window.close()
})
async function until(check) {
  const deadline = Date.now() + 8000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(check(), document.body.textContent)
}
async function mount(role = 'staff') {
  await React.act(async () => root?.unmount())
  charts.clear(); requests = []
  const result = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures[role].number, password: 'DashboardFixtureA1' }) })
  assert.equal(result.status, 200)
  cookie = result.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  document.body.innerHTML = '<div id="root"></div>'
  root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(e(React.StrictMode, null, e(MemoryRouter, null, e(Dashboard)))))
  await until(() => document.querySelector('#question-mix') && !document.querySelector('#question-mix').textContent.includes('Loading'))
}
async function reference(role) {
  const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./staff-dashboard-reference.php', import.meta.url)), 'dashboard', String(fixtures[role].id), 'html'])
  return new JSDOM(stdout, { runScripts: 'outside-only' })
}
async function sourceCharts(ref) {
  const result = await originalFetch(base + '/staff/stats', { headers: { Cookie: cookie } }).then(response => response.json())
  const recorded = new Map()
  ref.window.Chart = class { constructor(canvas, config) { recorded.set(canvas.id, config); this.data = config.data } update() {} }
  ref.window.fetch = async () => ({ json: async () => result.data })
  const source = [...ref.window.document.querySelectorAll('script')].find(script => script.textContent.includes('AJAX-loaded dashboard statistics')).textContent
  ref.window.eval(source)
  await new Promise(resolve => setTimeout(resolve, 0))
  return recorded
}
const plain = value => JSON.parse(JSON.stringify(value, (_key, item) => typeof item === 'function' ? '[callback]' : item))
for (const role of ['staff', 'admin']) {
  test(`${role}: dashboard sections, labels, figures, tables, all eleven chart configurations match PHP`, { skip: !fixtures }, async () => {
    substitute = null; failStats = false
    await mount(role)
    const ref = await reference(role)
    const configs = await sourceCharts(ref)
    const expected = ref.window.document
    for (const selector of ['.page-head', '.page-actions-row', '.db-cell', '.db-panel-title', '.db-panel-note', '.db-panel-meta', '.meter-note', '.db-kv li', '.db-rank li', '.db-empty', '.db-table-wrap tr', '.db-section-head', '.db-admin-head', '.db-bar-row']) {
      assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text), selector)
    }
    assert.deepEqual([...document.querySelectorAll('canvas')].map(node => node.id), [...expected.querySelectorAll('canvas')].map(node => node.id))
    assert.equal(Boolean(document.querySelector('.db-admin')), role === 'admin')
    assert.equal(configs.size, 11)
    for (const [id, config] of configs) {
      const actual = charts.get(id)
      assert.ok(actual, id)
      assert.deepEqual(plain(actual.data), plain(config.data), `${id} datasets`)
      const options = plain(actual.options)
      delete options.plugins.centreText
      assert.deepEqual(options, plain(config.options), `${id} options`)
    }
    assert.ok(document.querySelector('a[title="View Results"]').getAttribute('href').startsWith('/staff/results-dashboard?survey_id='))
    ref.window.close()
  })
}
test('completion View All expands and returns to source top-three state', { skip: !fixtures }, async () => {
  await mount()
  const button = document.querySelector('[data-toggle-more]')
  assert.equal(button.textContent, 'View All (6)')
  assert.equal(document.querySelectorAll('.meter-row.is-hidden').length, 3)
  await React.act(async () => button.click())
  assert.equal(button.textContent, 'Show top 3 only')
  assert.equal(button.getAttribute('data-expanded'), '1')
  assert.equal(document.querySelectorAll('.meter-row.is-shown').length, 3)
  await React.act(async () => button.click())
  assert.equal(button.textContent, 'View All (6)')
  assert.equal(document.querySelectorAll('.meter-row.is-hidden').length, 3)
})
async function input(selector, value) {
  await React.act(async () => {
    const node = document.querySelector(selector)
    Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(node, value)
    node.dispatchEvent(new window.Event('input', { bubbles: true }))
  })
}
test('date filter changes only trend; reset, missing dates, failed fetch preserve source behavior', { skip: !fixtures }, async () => {
  await mount()
  const other = [...charts].filter(([id]) => id !== 'chart-trend').map(([id, props]) => [id, plain(props.data)])
  const cells = [...document.querySelectorAll('.db-cell')].map(text)
  await input('#trend-from', '2026-01-05'); await input('#trend-to', '2026-01-07')
  await React.act(async () => document.querySelector('#trend-apply').click())
  await until(() => charts.get('chart-trend').data.labels.length === 3)
  assert.deepEqual(charts.get('chart-trend').data.labels, ['Jan 5', 'Jan 6', 'Jan 7'])
  assert.deepEqual([...document.querySelectorAll('.db-cell')].map(text), cells)
  for (const [id, data] of other) assert.deepEqual(plain(charts.get(id).data), data)
  failFilter = true
  await input('#trend-from', '2026-01-04')
  await React.act(async () => document.querySelector('#trend-apply').click())
  assert.deepEqual(charts.get('chart-trend').data.labels, ['Jan 5', 'Jan 6', 'Jan 7'])
  failFilter = false
  await React.act(async () => document.querySelector('#trend-reset').click())
  await until(() => charts.get('chart-trend').data.labels.length === 30)
  const count = requests.length
  await input('#trend-from', '')
  await React.act(async () => document.querySelector('#trend-apply').click())
  assert.equal(requests.length, count)
})
test('empty dashboard hides demographics and preserves all PHP empty messages', { skip: !fixtures }, async () => {
  const result = await originalFetch(base + '/staff/dashboard', { headers: { Cookie: cookie } }).then(response => response.json())
  substitute = { ...result.data, is_admin_view: false, total_surveys: 0, total_responses: 0, total_residents: 0, demographics_ready: false, completion_rates: [], surveys_overview: [], top_responders: [] }
  await mount()
  assert.equal(document.querySelector('.db-section-head'), null)
  for (const message of ['No responses have been submitted yet.', 'Create a survey to start collecting responses.', 'No residents registered yet.', 'No surveys are open for answers right now.', 'No one has answered a survey yet.']) assert.ok(document.body.textContent.includes(message), message)
  assert.equal(document.querySelectorAll('#chart-age, #chart-gender, #chart-priority, #chart-occupation, #chart-purok, #chart-household').length, 0)
  assert.equal(document.querySelector('.db-admin'), null)
  substitute = null
})
test('chart loading failure uses original question-mix error while dashboard figures remain', { skip: !fixtures }, async () => {
  failStats = true
  await mount()
  assert.equal(document.querySelector('#question-mix').textContent, 'Chart data could not be loaded. Refresh the page to try again.')
  assert.equal(document.querySelectorAll('.db-strip .db-cell').length, 5)
  failStats = false
})
test('staff gauge centre uses arc anchor, 26px type and original half-ring offsets', { skip: !fixtures }, async () => {
  const { default: centreText } = await server.ssrLoadModule('/src/components/charts/centreText.js')
  const draws = []
  const ctx = { save() {}, restore() {}, fillText(...args) { draws.push([...args, this.font, this.textBaseline]) } }
  centreText().afterDraw({ ctx, chartArea: { left: 10, right: 210, top: 20, bottom: 180 }, getDatasetMeta: () => ({ data: [{ y: 170 }] }), options: { circumference: 180 } }, {}, { main: '50%', sub: 'of residents', fontSize: 26, arcAnchor: true })
  assert.deepEqual(draws.map(row => row.slice(0, 3)), [['50%', 110, 166], ['of residents', 110, 172]])
  assert.ok(draws[0][3].startsWith('700 26px'))
  assert.equal(draws[0][4], 'bottom')
})
