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
const fixtures = await readFile(join(tmpdir(), 'barangay-survey-fixtures.json'), 'utf8').then(JSON.parse).catch(error => {
  if (error.code === 'ENOENT') return null
  throw error
})
const test = (name, run) => nodeTest(name, { skip: fixtures ? false : 'Run resident-survey-api.php --keep first.' }, run)
const originalFetch = globalThis.fetch
const base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<!doctype html><body></body>', { url: 'http://localhost/' })
let React, createRoot, MemoryRouter, Routes, Route, useLocation, server, root, cookie, pages, AuthProvider, ProtectedRoute
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
let requests = 0, alerts = [], prints = 0

before(async () => {
  if (!fixtures) return
  for (const key of ['window', 'document', 'HTMLElement']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  window.alert = message => alerts.push(message)
  window.print = () => prints++
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ MemoryRouter, Routes, Route, useLocation } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-survey-vite-tests'),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  pages = Object.fromEntries(await Promise.all(['Surveys', 'SurveyForm', 'History', 'ViewResponse'].map(async name =>
    [name, (await server.ssrLoadModule(`/src/pages/resident/${name}.jsx`)).default])))
  ;({ default: AuthProvider } = await server.ssrLoadModule('/src/context/AuthProvider.jsx'))
  ;({ default: ProtectedRoute } = await server.ssrLoadModule('/src/routes/ProtectedRoute.jsx'))
  const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ identifier: fixtures.residents.main.number, password: 'SurveyFixtureA1' }) })
  assert.equal(response.status, 200)
  cookie = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  globalThis.fetch = (path, options) => {
    if (path.includes('survey-submissions')) requests++
    return originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
  }
})
after(async () => {
  if (React) await React.act(async () => root?.unmount())
  await server?.close()
  globalThis.fetch = originalFetch
  dom.window.close()
})
function Location() {
  const location = useLocation()
  React.useLayoutEffect(() => {
    window.history.replaceState(window.history.state, '', location.pathname + location.search)
  }, [location])
  return e('output', { id: 'location' }, location.pathname + location.search)
}
async function until(check) {
  const deadline = Date.now() + 8000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(check(), document.body.textContent)
}
async function mount(path) {
  await React.act(async () => root?.unmount())
  document.body.innerHTML = '<div id="root"></div>'
  window.history.replaceState({ key: 'fixture', idx: 0 }, '', path)
  root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(e(React.StrictMode, null, e(MemoryRouter, { initialEntries: [path] },
    e(Location), e(AuthProvider, null, e(Routes, null, e(Route, { element: e(ProtectedRoute, { portal: 'resident' }) },
      ...Object.entries({ '/resident/surveys': pages.Surveys, '/resident/survey-form': pages.SurveyForm,
        '/resident/history': pages.History, '/resident/view-response': pages.ViewResponse }).map(([path, Page]) => e(Route, { key: path, path, element: e(Page) })))))))))
}
async function reference(page, id = 0) {
  const result = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./resident-survey-reference.php', import.meta.url)),
    page, String(fixtures.residents.main.id), String(id)])
  return new JSDOM(result.stdout).window.document
}
async function click(node) { await React.act(async () => node.click()) }
async function submit() { await React.act(async () => document.querySelector('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))) }

test('survey tables match original PHP text, truncation, dates, states and actions', async () => {
  const expected = await reference('surveys')
  await mount('/resident/surveys')
  await until(() => document.querySelector('.card-head'))
  for (const selector of ['.page-head', '.card-head', 'tr']) assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text), selector)
})
test('single QuestionField matches all four original controls and required-group validation', async () => {
  const id = fixtures.surveys.browser
  const expected = await reference('survey_form', id)
  await mount(`/resident/survey-form?id=${id}`)
  await until(() => document.querySelector('#survey-answer-form'))
  for (const selector of ['[data-required-group] > label', '.option-row', '.rating-btn', '.rating-legend']) {
    assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text))
  }
  const controls = doc => [...doc.querySelectorAll('#survey-answer-form input, #survey-answer-form textarea')].map(node => [node.tagName, node.name, node.getAttribute('value'), node.id, node.getAttribute('placeholder')])
  assert.deepEqual(controls(document), controls(expected))
  const before = requests
  await submit()
  assert.equal(requests, before)
  assert.equal(document.querySelectorAll('.missing').length, 3)
  assert.equal(alerts.at(-1), 'Please answer all required questions before submitting.')
})
test('React submits end to end, consumes the confirmation URL and clears modal content', async () => {
  const id = fixtures.surveys.text, qid = fixtures.questions.text[0]
  await mount(`/resident/survey-form?id=${id}`)
  await until(() => document.querySelector('textarea'))
  // Input through React's native value setter so this verifies controlled state and serialization.
  await React.act(async () => {
    const input = document.querySelector('textarea')
    Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set.call(input, 'React end-to-end answer')
    input.dispatchEvent(new window.Event('input', { bubbles: true }))
  })
  // jsdom React event support is environment-dependent; verify the controlled event via radio
  // below if needed, rather than bypassing the submit API with a test-only payload.
  await submit()
  await until(() => document.querySelector('#submitted-modal.active') || document.querySelector('.alert-error'))
  assert.ok(document.querySelector('#submitted-modal.active'), `Controlled q${qid} submitted`)
  await until(() => window.location.search === '')
  assert.equal(window.location.pathname, '/resident/surveys')
  assert.equal(window.history.state.key, 'fixture', 'History/router metadata is preserved')
  assert.match(document.querySelector('#submitted-modal').textContent, /Survey Fixture text/)
  await click(document.querySelector('#submitted-modal .modal-actions button'))
  assert.equal(document.querySelector('#submitted-modal').classList.contains('active'), false)
  assert.equal(document.querySelector('#submitted-modal').textContent.includes('Survey Fixture text'), false)
  await mount('/resident/surveys')
  await until(() => document.querySelector('.card-head'))
  assert.equal(document.querySelector('#submitted-modal').classList.contains('active'), false)
  await mount('/resident/history')
  await until(() => document.querySelector('table'))
  assert.match(document.querySelector('table').textContent, /Survey Fixture text/)
  await mount(`/resident/survey-form?id=${id}`)
  await until(() => document.querySelector('.card-head'))
  assert.equal(document.querySelector('#location').textContent, '/resident/surveys')
})
test('history, response values, links and print mode match source; auto-print waits for data', async () => {
  let expected = await reference('history')
  await mount('/resident/history')
  await until(() => document.querySelector('table'))
  for (const selector of ['.page-head', 'tr']) assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text))
  const href = document.querySelector('a[title="View submitted answers"]').getAttribute('href')
  const id = new URL(href, 'http://local').searchParams.get('id')
  expected = await reference('view_response', id)
  const before = prints
  await mount(`/resident/view-response?id=${id}&print`)
  await until(() => document.querySelector('.submitted-value'))
  for (const selector of ['.page-head', '.response-header', '.response-description', '.submitted-answer']) {
    assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...expected.querySelectorAll(selector)].map(text), selector)
  }
  assert.equal(prints, before + 1)
  assert.equal(document.querySelector('a.btn[href*="response-export"]').getAttribute('href'), `/api/resident/response-export?id=${id}`)
  await click(document.querySelector('button[aria-label="Print response"]'))
  assert.equal(prints, before + 2)
})
test('missing response redirects and empty/failed messages retain source wording', async () => {
  await mount('/resident/view-response?id=0')
  await until(() => document.querySelector('table'))
  assert.equal(document.querySelector('#location').textContent, '/resident/history')
  for (const [error, phrase] of [['empty', 'Nothing was submitted'], ['failed', 'Something went wrong while saving your answers']]) {
    await mount(`/resident/survey-form?id=${fixtures.surveys.browser}&error=${error}`)
    await until(() => document.querySelector('.alert-error'))
    assert.match(document.querySelector('.alert-error').textContent, new RegExp(phrase))
  }
})
