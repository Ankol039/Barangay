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
const fixtures = await readFile(join(tmpdir(), 'barangay-survey-management-fixtures.json'), 'utf8').then(JSON.parse).catch(() => null)
assert.ok(fixtures, 'Run the survey management API suite with --keep first.')
const originalFetch = globalThis.fetch
const base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/' })
let React, createRoot, createMemoryRouter, RouterProvider, server, root, router, cookie, screens, DirtyWarningProvider
let requests = [], surveyId, choiceIds, fail = false
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  for (const key of ['window', 'document', 'HTMLElement', 'location']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  window.confirm = () => true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ createMemoryRouter, RouterProvider } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-survey-management-vite-tests'), server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  screens = {}
  for (const [route, name] of [['survey-management', 'SurveyManagement'], ['add-survey', 'SurveyEditor'], ['edit-survey', 'SurveyEditor'], ['question-management', 'QuestionManagement']]) screens[route] = (await server.ssrLoadModule(`/src/pages/staff/${name}.jsx`)).default
  DirtyWarningProvider = (await server.ssrLoadModule('/src/components/common/DirtyWarningProvider.jsx')).default
  const result = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures.staff.number, password: 'SurveyFixtureA1' }) })
  assert.equal(result.status, 200)
  cookie = result.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  globalThis.fetch = async (path, options) => {
    const body = options?.body ? JSON.parse(options.body) : null
    if (fail && options?.method === 'POST') return Response.json({ ok: false, message: 'Synthetic save failure.' }, { status: 500 })
    const response = await originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
    const result = await response.clone().json()
    requests.push({ path, body, result })
    return response
  }
})
after(async () => {
  await React?.act(async () => root?.unmount()); router?.dispose(); await server?.close()
  globalThis.fetch = originalFetch; dom.window.close()
})
async function until(check) {
  const deadline = Date.now() + 10000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(check(), document.body.textContent)
}
async function mount(route) {
  await React.act(async () => root?.unmount()); router?.dispose()
  document.body.innerHTML = '<div id="root"></div>'
  router = createMemoryRouter([{ element: e(DirtyWarningProvider, null, e((await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default.Outlet)), children:
    Object.entries(screens).map(([path, Screen]) => ({ path: `/staff/${path}`, element: e(Screen) })) }], { initialEntries: [route] })
  root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(e(React.StrictMode, null, e(RouterProvider, { router }))))
  await until(() => document.querySelector('.card h2'))
}
async function input(selector, value) {
  await React.act(async () => {
    const node = typeof selector === 'string' ? document.querySelector(selector) : selector
    assert.ok(node, selector)
    const prototype = node.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : node.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype
    Object.getOwnPropertyDescriptor(prototype, 'value').set.call(node, value)
    node.dispatchEvent(new window.Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
  })
}
async function click(selector) { await React.act(async () => (typeof selector === 'string' ? document.querySelector(selector) : selector).click()) }
async function submit(selector) { await React.act(async () => document.querySelector(selector).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))) }
async function source(mode, id = 0, variant = '') {
  const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./staff-surveys-reference.php', import.meta.url)), mode, String(fixtures.staff.id), String(id), variant])
  return new JSDOM(stdout, { runScripts: 'outside-only' })
}
async function done() { await click('#question-success-modal .btn'); assert.ok(!document.querySelector('#question-success-modal.active')) }
const visibleRows = () => [...document.querySelectorAll('#surveys-table tbody tr[data-search-text]')].filter(row => row.style.display !== 'none')

test('builder matches original labels/limits/default dates; choice toggle, minimum, preview, dirty discard', async () => {
  await mount('/staff/add-survey')
  const ref = await source('add')
  for (const selector of ['.card > h2', '.card > .field-hint', '#survey-builder-form > label', '#survey-builder-form > .field-hint', '.grid-2 label', '.form-actions']) assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...ref.window.document.querySelectorAll(selector)].map(text), selector)
  for (const id of ['title', 'description', 'start_date', 'end_date']) {
    const node = document.getElementById(id), expected = ref.window.document.getElementById(id)
    for (const attribute of ['type', 'required', 'minlength', 'maxlength', 'placeholder']) assert.equal(node.getAttribute(attribute), expected.getAttribute(attribute), `${id} ${attribute}`)
    assert.equal(node.value, expected.value)
  }
  const editor = document.querySelector('.question-row')
  assert.equal(editor.querySelectorAll('select option').length, 4)
  await input('.choice-row input', 'First option')
  await click('.remove-choice-btn'); assert.equal(editor.querySelectorAll('.choice-row').length, 2); assert.equal(editor.querySelector('input[placeholder="Choice 1"]').value, '')
  await click('.add-choice-btn'); assert.equal(editor.querySelectorAll('.choice-row').length, 3)
  await click('.choice-row:last-child button'); assert.equal(editor.querySelectorAll('.choice-row').length, 2)
  await input('.question-type-select', 'rating'); assert.equal(editor.querySelector('.choices-box').style.display, 'none')
  await input('.question-type-select', 'multiple_choice'); assert.equal(editor.querySelector('.choices-box').style.display, 'block')
  await input('#title', 'Community <script> Survey'); await input('.question-row input[type=text]', 'Question <b> literal')
  await click('#preview-survey-btn')
  assert.ok(document.querySelector('#survey-preview-modal.active'))
  assert.equal(document.querySelector('#survey-preview-body h2').textContent, 'Community <script> Survey')
  assert.equal(document.querySelectorAll('#survey-preview-body script, #survey-preview-body b').length, 0)
  assert.ok(document.querySelector('#survey-preview-body').textContent.includes('No choices entered - this question cannot be saved yet.'))
  await click('#survey-preview-modal .modal-actions button')
  assert.equal(document.querySelector('#title').value, 'Community <script> Survey')
  window.confirm = () => false
  await click('.form-actions a'); assert.equal(router.state.location.pathname, '/staff/add-survey')
  window.confirm = () => true
  ref.window.close()
})
test('rejected builder POST preserves metadata and rebuilds one blank question as PHP does', async () => {
  await mount('/staff/add-survey')
  await input('#title', 'bad')
  await input('#description', 'Retain this description after validation.')
  await input('.question-row input[type=text]', 'Discard this unsaved question')
  await click('#add-question-btn')
  await input('.question-row:last-child input[type=text]', 'Discard the second question')
  await submit('#survey-builder-form')
  await until(() => document.querySelector('.alert-error'))
  const original = await source('add', 0, 'invalid-builder')
  const script = [...original.window.document.scripts].find(node => node.textContent.includes('var container = document.getElementById'))
  original.window.eval(script.textContent)
  assert.equal(document.querySelector('.alert-error').textContent, original.window.document.querySelector('.alert-error').textContent)
  assert.equal(document.querySelectorAll('.question-row').length, original.window.document.querySelectorAll('.question-row').length)
  assert.equal(document.querySelector('#title').value, 'bad')
  assert.equal(document.querySelector('#description').value, 'Retain this description after validation.')
  assert.equal(document.querySelectorAll('.question-row').length, 1)
  assert.equal(document.querySelector('.question-row input[type=text]').value, '')
  assert.equal(document.querySelector('.question-type-select').value, 'multiple_choice')
  assert.deepEqual([...document.querySelectorAll('.choice-row input')].map(el => el.value), ['', ''])
  original.window.close()
})
test('React creates a survey with all four types and original list destination', async () => {
  await mount('/staff/add-survey')
  await input('#title', 'React Survey Workflow Fixture')
  await input('#description', 'Synthetic React survey workflow verification.')
  await input('.question-row input[type=text]', 'Choose your service')
  await input('input[placeholder="Choice 1"]', 'Consultation'); await input('input[placeholder="Choice 2"]', 'Vaccination')
  for (const [type, question] of [['yesno', 'Were you satisfied?'], ['rating', 'Rate your experience'], ['short_answer', 'Share your feedback']]) {
    await click('#add-question-btn')
    const row = document.querySelector('.question-row:last-child')
    await input(row.querySelector('input[type=text]'), question); await input(row.querySelector('select'), type)
  }
  await submit('#survey-builder-form')
  await until(() => router.state.location.pathname === '/staff/survey-management' && document.querySelector('#surveys-table'))
  const result = requests.filter(request => request.path.endsWith('/staff/surveys') && request.body).at(-1)
  surveyId = result.result.data.survey_id
  assert.equal(result.body.questions.length, 4)
  assert.ok(visibleRows().some(row => row.textContent.includes('React Survey Workflow Fixture')))
})
test('list source rows, search + status filters, toggle confirmation cancel and continue', async () => {
  const ref = await source('list')
  assert.deepEqual([...document.querySelectorAll('#surveys-table tr[data-search-text]')].map(text), [...ref.window.document.querySelectorAll('#surveys-table tr[data-search-text]')].map(text))
  await input('#survey-search', '  React Survey Workflow  ')
  assert.equal(visibleRows().length, 1)
  await click('.filter-tabs [data-filter="inactive"]'); assert.equal(visibleRows().length, 0)
  assert.equal(document.querySelector('.no-match-row').style.display, '')
  await click('.filter-tabs [data-filter="active"]'); assert.equal(visibleRows().length, 1)
  const row = visibleRows()[0]
  await click(row.querySelector('[aria-label="Deactivate survey"]'))
  assert.equal(document.querySelector('#survey-confirm-modal-message').textContent, 'Deactivate this survey?')
  const before = requests.length; await click('#survey-confirm-modal .btn-secondary'); assert.equal(requests.length, before)
  await click(row.querySelector('[aria-label="Deactivate survey"]')); await click('#survey-confirm-modal-proceed')
  await until(() => [...document.querySelectorAll('#surveys-table tr[data-search-text]')].find(row => row.textContent.includes('React Survey Workflow')).querySelector('[aria-label="Activate survey"]'))
  assert.equal(visibleRows().length, 0)
  await click('.filter-tabs [data-filter="inactive"]'); assert.equal(visibleRows().length, 1)
  ref.window.close()
})
test('shared edit form matches PHP, keeps validation message and saves metadata', async () => {
  await mount(`/staff/edit-survey?id=${surveyId}`)
  const ref = await source('edit', surveyId)
  for (const selector of ['.back-link', '.card h2', 'form label', '.form-actions', '#status option']) assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...ref.window.document.querySelectorAll(selector)].map(text), selector)
  await input('#title', 'bad'); await submit('form')
  await until(() => document.querySelector('.alert-error'))
  assert.equal(document.querySelector('.alert-error').textContent, 'Give the survey a real title - at least eight characters that describe what it is about.')
  await input('#title', 'React Updated Survey Workflow Fixture'); await submit('form')
  await until(() => document.querySelector('.alert-success'))
  assert.equal(document.querySelector('.alert-success').textContent, 'Survey updated successfully.')
  await click('.form-actions a'); await until(() => document.querySelector('.edit-question-btn'))
  assert.equal(router.state.location.search, `?survey_id=${surveyId}`)
  ref.window.close()
})
test('question table matches original, moves adjacent rows and shows source success modal', async () => {
  const ref = await source('questions', surveyId)
  for (const selector of ['.back-link', '.card-head', '.table-spaced tr']) assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...ref.window.document.querySelectorAll(selector)].map(text), selector)
  const before = [...document.querySelectorAll('tbody tr')].map(row => row.children[1].textContent)
  await click('tbody tr:first-child [aria-label="Move down"]')
  await until(() => document.querySelector('#question-success-modal.active'))
  assert.equal(document.querySelector('#question-success-modal p').textContent, 'Question order updated.')
  assert.deepEqual([...document.querySelectorAll('tbody tr')].map(row => row.children[1].textContent), [before[1], before[0], ...before.slice(2)])
  await done()
  await click('tbody tr:nth-child(2) [aria-label="Move up"]'); await until(() => document.querySelector('#question-success-modal.active')); await done()
  ref.window.close()
})
test('question edit retains IDs, explicit removed choices, failed saves and dirty modal state', async () => {
  const sourceData = await originalFetch(`${base}/staff/questions?survey_id=${surveyId}`, { headers: { Cookie: cookie } }).then(response => response.json())
  choiceIds = sourceData.data.questions[0].choices.map(choice => choice.choice_id)
  await click('tbody tr:first-child [aria-label="Edit question"]')
  assert.equal(document.querySelector('#edit_question_text').value, 'Choose your service')
  await input('#edit-choices-inputs input', 'Consultation corrected')
  await click('#edit-question-modal .add-choice-btn')
  await input('#edit-choices-inputs .choice-row:last-child input', 'New choice')
  await click('#edit-choices-inputs .choice-row:nth-child(2) button')
  window.confirm = () => false; await click('#edit-question-modal [data-modal-close]')
  assert.ok(document.querySelector('#edit-question-modal.active')); assert.equal(document.querySelector('#edit-choices-inputs input').value, 'Consultation corrected')
  window.confirm = () => true
  fail = true; await submit('#edit-question-form'); await until(() => document.querySelector('#edit-question-modal .alert-error'))
  assert.equal(document.querySelector('#edit-question-modal .alert-error').textContent, 'Synthetic save failure.')
  fail = false; await submit('#edit-question-form'); await until(() => document.querySelector('#question-success-modal.active'))
  const request = requests.filter(request => request.path.endsWith('/staff/question/update')).at(-1)
  assert.equal(request.body.existing_choices[choiceIds[0]], 'Consultation corrected')
  assert.equal(request.body.existing_choices[choiceIds[1]], '')
  assert.deepEqual(request.body.new_choices, ['New choice'])
  const choices = request.result.data.questions[0].choices
  assert.equal(choices[0].choice_id, choiceIds[0]); assert.ok(!choices.some(choice => choice.choice_id === choiceIds[1]))
  await done()
})
test('add errors retain modal values, successful add and question delete resequence', async () => {
  await click('.card-head .btn')
  await input('#question_text', 'A new question')
  await submit('#add-question-form'); await until(() => document.querySelector('#add-question-modal .alert-error'))
  assert.equal(document.querySelector('#add-question-modal .alert-error').textContent, 'A multiple-choice question needs at least two choices. Blank boxes are ignored.')
  assert.equal(document.querySelector('#question_text').value, 'A new question')
  await input('#question_type', 'short_answer'); assert.equal(document.querySelector('#add-question-modal .choices-box').style.display, 'none')
  await submit('#add-question-form'); await until(() => document.querySelector('#question-success-modal.active')); assert.equal(document.querySelectorAll('tbody tr').length, 5); await done()
  await click('tbody tr:nth-child(2) [aria-label="Delete question"]')
  assert.equal(document.querySelector('#question-confirm-modal-message').textContent, 'Delete this question and all related answers?')
  await click('#question-confirm-modal .btn-secondary'); assert.equal(document.querySelectorAll('tbody tr').length, 5)
  await click('tbody tr:nth-child(2) [aria-label="Delete question"]'); await click('#question-confirm-modal-proceed')
  await until(() => document.querySelector('#question-success-modal.active'))
  assert.equal(document.querySelector('#question-success-modal p').textContent, 'Question deleted.')
  assert.deepEqual([...document.querySelectorAll('tbody tr')].map(row => row.children[0].textContent), ['1', '2', '3', '4']); await done()
})
test('survey deletion uses original warning, cancel is inert, deletion clears survey and missing IDs redirect', async () => {
  await mount('/staff/survey-management')
  await input('#survey-search', 'React Updated Survey')
  await click(visibleRows()[0].querySelector('[aria-label="Delete survey"]'))
  assert.equal(document.querySelector('#survey-confirm-modal-message').textContent, 'Delete “React Updated Survey Workflow Fixture”? This will also delete its questions, choices, responses, and results. This action cannot be undone.')
  const before = requests.length; await click('#survey-confirm-modal .btn-secondary'); assert.equal(requests.length, before)
  await click(visibleRows()[0].querySelector('[aria-label="Delete survey"]')); await click('#survey-confirm-modal-proceed')
  await until(() => visibleRows().length === 0)
  assert.equal(requests.filter(request => request.path.endsWith('/staff/survey/delete')).at(-1).result.message, 'Survey “React Updated Survey Workflow Fixture” was deleted successfully.')
  await mount(`/staff/edit-survey?id=${surveyId}`)
  await until(() => router.state.location.pathname === '/staff/survey-management')
  await mount('/staff/question-management?survey_id=0')
  await until(() => router.state.location.pathname === '/staff/survey-management')
})
