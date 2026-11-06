import assert from 'node:assert/strict'
import { before, after, test as nodeTest } from 'node:test'
import { readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { fileURLToPath } from 'node:url'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'

const exec = promisify(execFile)
const php = '/Applications/XAMPP/xamppfiles/bin/php'
const base = 'http://localhost/barangay_survey_system/api'
const fixtureState = await readFile(join(tmpdir(), 'barangay-dashboard-fixtures.json'), 'utf8').then(JSON.parse).catch(error => {
  if (error.code === 'ENOENT') return null
  throw error
})
const test = (name, run) => nodeTest(name, { skip: fixtureState ? false : 'Run resident-dashboard-api.php --keep first.' }, run)
const originalFetch = globalThis.fetch
const dom = new JSDOM('<!doctype html><body><div id="root"></div></body>', { url: 'http://localhost/' })
let React, root, createRoot, MemoryRouter, Routes, Route, Dashboard, StatDetail, server, fixtures, cookie
const chartProps = new Map()
const e = (...args) => React.createElement(...args)

before(async () => {
  fixtures = fixtureState
  if (!fixtures) return
  for (const key of ['window', 'document', 'HTMLElement']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  globalThis.__dashboardChartProps = chartProps
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ MemoryRouter, Routes, Route } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ cacheDir: join(tmpdir(), 'barangay-dashboard-vite-tests'), root: fileURLToPath(new URL('../../frontend', import.meta.url)),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom',
    ssr: { noExternal: ['react-chartjs-2'] },
    plugins: [{ name: 'record-chart-boundary', enforce: 'pre',
      resolveId(id) { if (id === 'react-chartjs-2') return '\0record-chart-boundary' },
      load(id) { if (id === '\0record-chart-boundary') return `
        import * as React from 'react';
        function Chart(props) {
          globalThis.__dashboardChartProps.set(props.id, props);
          return React.createElement('canvas', {id: props.id});
        }
        export const Doughnut = Chart; export const Bar = Chart;
      ` },
    }],
  })
  ;({ default: Dashboard } = await server.ssrLoadModule('/src/pages/resident/Dashboard.jsx'))
  ;({ default: StatDetail } = await server.ssrLoadModule('/src/pages/resident/StatDetail.jsx'))
  globalThis.fetch = (path, options) => originalFetch(base + path.replace(/^.*?\/api/, ''), {
    ...options, headers: { ...options?.headers, Cookie: cookie },
  })
})
after(async () => {
  if (React) await React.act(async () => root?.unmount())
  await server?.close()
  globalThis.fetch = originalFetch
  delete globalThis.__dashboardChartProps
  dom.window.close()
})
async function login(type) {
  const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ identifier: fixtures.residents[type].number, password: 'DashboardFixtureA1' }) })
  assert.equal(response.status, 200)
  cookie = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
}
async function mount(content, until) {
  await React.act(async () => root?.unmount())
  chartProps.clear()
  document.body.innerHTML = '<div id="root"></div>'
  root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(content))
  const deadline = Date.now() + 5000
  while (!until() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(until(), `Page finished its data request: ${document.body.textContent}`)
}
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
async function reference(type, mode = 'dashboard') {
  const result = await exec(php, [fileURLToPath(new URL('./resident-dashboard-reference.php', import.meta.url)), mode, String(fixtures.residents[type].id), 'html'])
  return new JSDOM(result.stdout).window.document
}
const dataset = (source, name) => JSON.parse(source.match(new RegExp(`var ${name} = (\\[[^;]*\\]);`))[1])

for (const type of ['temporary', 'permanent', 'empty']) {
  test(`${type}: all dashboard figures, text, address facts and chart datasets match original PHP`, async () => {
    await login(type)
    const expected = await reference(type)
    await mount(e(MemoryRouter, null, e(Dashboard)), () => document.querySelector('.db-strip'))
    for (const selector of ['.page-head', '.page-actions-row', '.alert-info', '.db-cell', '.db-panel-title', '.db-panel-note', '.db-panel-meta',
      '.meter-note', '.overview-list li', '.db-kv li', '.db-address-line', '.db-fact', '.db-rank li', '.db-empty', '.db-table-wrap tr']) {
      assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text), selector)
    }
    const originalCanvases = [...expected.querySelectorAll('canvas')].map(node => node.id)
    assert.deepEqual([...document.querySelectorAll('canvas')].map(node => node.id), originalCanvases)
    const completed = Number(expected.querySelectorAll('.db-cell-value')[1].textContent)
    const available = Number(expected.querySelectorAll('.db-cell-value')[0].textContent)
    const completion = chartProps.get('chart-completion')
    assert.deepEqual(completion.data.labels, ['Completed', 'Available'])
    assert.deepEqual(completion.data.datasets[0].data, [completed, available])
    assert.equal(completion.options.plugins.centreText.main, `${completed + available ? Math.round(completed / (completed + available) * 100) : 0}%`)
    assert.equal(completion.options.cutout, '68%')
    assert.deepEqual(completion.data.datasets[0].backgroundColor, ['#1d6f5e', '#dbe5e1'])
    const source = expected.querySelectorAll('script')[1].textContent
    if (type !== 'empty') {
      const activity = dataset(source, 'activity')
      assert.deepEqual(chartProps.get('chart-activity').data.datasets[0].data, activity.map(row => row.value))
      assert.deepEqual(chartProps.get('chart-activity').data.labels, activity.map(row => row.label))
      const household = dataset(source, 'household')
      assert.deepEqual(chartProps.get('chart-household-mine').data.datasets[0].data, household.map(row => row.value))
      assert.deepEqual(chartProps.get('chart-household-mine').data.labels, household.map(row => row.label))
      const gauge = chartProps.get('chart-residency-progress')
      const pct = Number(source.match(/var pctDone = (\d+);/)[1])
      assert.deepEqual(gauge.data.datasets[0].data, [pct, Math.max(0, 100 - pct)])
      assert.equal(gauge.options.rotation, 270)
      assert.equal(gauge.options.circumference, 180)
      assert.equal(gauge.options.cutout, '72%')
      assert.equal(gauge.options.plugins.centreText.sub, type === 'temporary' ? 'of the minimum' : 'registered')
    }
    assert.equal(document.querySelectorAll('a.db-cell').length, 3)
    assert.ok(document.querySelector('.db-table-wrap a').getAttribute('href').startsWith('/resident/survey-form?id='))
  })
}
for (const view of ['available', 'completed', 'assigned']) {
  test(`${view}: detail title, unit, columns, dates, badges and rows match original PHP`, async () => {
    await login('temporary')
    const expected = await reference('temporary', view)
    await mount(e(MemoryRouter, { initialEntries: [`/resident/stat-detail?view=${view}`] }, e(StatDetail)), () => document.querySelector('.detail-count'))
    for (const selector of ['.page-head', '.detail-count', 'tr']) {
      assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text))
    }
    assert.equal(document.title, `${expected.querySelector('h1').textContent} - Barangay Health Center Survey System`)
  })
}
test('empty completion detail and invalid view redirect preserve source behavior', async () => {
  await login('empty')
  await mount(e(MemoryRouter, { initialEntries: ['/resident/stat-detail?view=completed'] }, e(StatDetail)), () => document.querySelector('.empty-state'))
  assert.equal(text(document.querySelector('.empty-state')), 'Nothing to show here yet.')
  await mount(e(MemoryRouter, { initialEntries: ['/resident/stat-detail?view=toString'] }, e(Routes, null,
    e(Route, { path: '/resident/stat-detail', element: e(StatDetail) }),
    e(Route, { path: '/resident/dashboard', element: e('p', { id: 'destination' }, 'Dashboard') }))), () => document.querySelector('#destination'))
})
test('centre text uses original positions and current per-chart options', async () => {
  const { default: centreText } = await server.ssrLoadModule('/src/components/charts/centreText.js')
  const draws = []
  const ctx = { save() {}, restore() {}, fillText(...args) { draws.push([...args, this.font, this.textBaseline, this.fillStyle]) } }
  const plugin = centreText('10%', 'completed')
  plugin.afterDraw({ ctx, chartArea: { left: 10, right: 210, top: 20, bottom: 180 } })
  assert.deepEqual(draws.map(row => row.slice(0, 3)), [['10%', 110, 104], ['completed', 110, 112]])
  plugin.afterDraw({ ctx, chartArea: { left: 10, right: 210, top: 20, bottom: 180 } }, {}, { main: '90%', sub: 'registered' })
  assert.equal(draws[2][0], '90%')
  assert.equal(draws[3][0], 'registered')
})
