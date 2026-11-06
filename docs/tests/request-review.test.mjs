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
const fixtures = await readFile(join(tmpdir(), 'barangay-survey-management-fixtures.json'), 'utf8').then(JSON.parse)
const originalFetch = globalThis.fetch, base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/' })
let React, createRoot, routerLib, server, root, router, screens = {}, cookie, DirtyWarningProvider, ConfirmProvider
let calls = 0, refuse = false, refreshes = 0, AuthContext, redirect = false
const cookies = {}
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  for (const key of ['window','document','HTMLElement','Event','KeyboardEvent','location']) globalThis[key] = dom.window[key]
  globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  window.confirm = () => true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  routerLib = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-review-tests'), server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  for (const screen of ['AddressRequests','DeletionRequests','DeletedAccounts','ResidencyRecords']) screens[screen] = (await server.ssrLoadModule(`/src/pages/staff/${screen}.jsx`)).default
  screens.Profile = (await server.ssrLoadModule('/src/pages/resident/Profile.jsx')).default
  ;({ default: DirtyWarningProvider } = await server.ssrLoadModule('/src/components/common/DirtyWarningProvider.jsx'))
  ;({ default: ConfirmProvider } = await server.ssrLoadModule('/src/components/common/ConfirmProvider.jsx'))
  ;({ AuthContext } = await server.ssrLoadModule('/src/context/authContext.js'))
  for (const role of ['resident','staff','admin']) {
    const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures[role].number, password: 'SurveyFixtureA1' }) })
    assert.equal(response.status, 200)
    cookies[role] = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  }
  globalThis.fetch = async (path, options) => {
    if (redirect) return Response.json({ ok: true, data: null, redirect: '/staff/change-password' })
    if (options?.method === 'POST') {
      calls++
      if (refuse) return Response.json({ ok: false, message: 'Synthetic save failure.' }, { status: 500 })
    }
    return originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
  }
})
after(async () => {
  if (React) await React.act(async () => { root?.unmount(); router?.dispose() })
  await server?.close(); globalThis.fetch = originalFetch; dom.window.close()
})
async function until(check) {
  const deadline = Date.now() + 8000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 15)))
  assert.ok(check(), 'Expected UI state: ' + document.body.textContent.slice(0, 1000))
}
async function request(path, body, role = 'resident') {
  const response = await originalFetch(base + path, { method: body ? 'POST' : 'GET', headers: { Cookie: cookies[role], ...(body ? { 'Content-Type': 'application/json' } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
  const result = await response.json()
  assert.equal(response.status, 200, result.message)
  return result.data
}
async function mount(name, role = 'admin', path = '/') {
  await React.act(async () => { root?.unmount(); router?.dispose() })
  cookie = cookies[role]
  document.body.innerHTML = '<div id="root"></div>'; root = createRoot(document.getElementById('root'))
  router = routerLib.createMemoryRouter([{ path: '*', element: e(AuthContext.Provider, { value: { refresh: async () => { refreshes++ } } },
    e(DirtyWarningProvider, null, e(ConfirmProvider, null, e(screens[name])))) }, { path: '/staff/change-password', element: e('p', null, 'Onboarding destination') }], { initialEntries: [path] })
  await React.act(async () => root.render(e(React.StrictMode, null, e(routerLib.RouterProvider, { router }))))
  await until(() => !document.querySelector('[role="status"]'))
}
async function click(node) { assert.ok(node); await React.act(async () => node.click()) }
async function input(selector, value) {
  await React.act(async () => {
    const node = document.querySelector(selector); assert.ok(node, selector)
    Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set.call(node,value)
    node.dispatchEvent(new window.Event('input', { bubbles: true }))
  })
}
async function submit(selector) { await React.act(async () => document.querySelector(selector).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))) }
const button = (label, scope = document) => [...scope.querySelectorAll('button')].find(node => node.textContent.trim() === label)
async function oracle(page, role = 'admin', get = {}) {
  const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./request-review-reference.php', import.meta.url)), String(fixtures[role].id), page, 'html'])
  // GET-filter parity is exercised in the API runner. This oracle compares the full source screen.
  assert.deepEqual(get, {})
  return new JSDOM(stdout).window.document
}
for (const role of ['staff','admin']) test(`${role}: address queues preserve original PHP text, rows and role visibility`, async () => {
  await mount('AddressRequests', role)
  const reference = await oracle('address_requests', role)
  for (const selector of ['.page-head','.container .card']) {
    const actual = selector.includes('.container') ? [...document.querySelectorAll('#root .card')] : [...document.querySelectorAll(selector)]
    assert.deepEqual(actual.map(text), [...reference.querySelectorAll(selector)].map(text))
  }
  assert.equal([...document.querySelectorAll('.card-head-title')].some(node => node.textContent.startsWith('Family & Household')), role === 'admin')
})
test('resident React form submits name/address proposal; staff rejection leaves the full profile unchanged', async () => {
  await mount('Profile', 'resident')
  const before = await request('/resident/profile')
  const overview = [...document.querySelectorAll('.card')].find(node => node.querySelector('h2')?.textContent === 'Profile Overview')
  await click(button('Request Change', overview))
  await input('#request-change-modal [name="first_name"]', 'RejectedReact')
  await submit('#request-change-modal form')
  await until(() => !document.querySelector('#request-change-modal'))
  assert.deepEqual((await request('/resident/profile')).resident, before.resident)
  const data = await request('/staff/address-requests', null, 'staff')
  const pending = data.resident.find(row => row.name.startsWith('RejectedReact') && row.status === 'pending')
  assert.ok(pending)
  await mount('AddressRequests', 'staff')
  await click(button('Reject', document.querySelector(`[data-request-id="${pending.request_id}"]`)))
  await until(() => document.querySelector('#address-request-success-modal.active') && !document.querySelector('#root button[disabled]'))
  assert.equal(text(document.querySelector('#address-request-success-modal .modal-text')), 'Request has been rejected.')
  assert.deepEqual((await request('/resident/profile')).resident, before.resident)
})
test('admin React approval applies a pending resident proposal, refreshes status/session, and keeps unrelated fields', async () => {
  const before = await request('/resident/profile')
  await request('/resident/name-address-requests', { ...fixtures.review_proposal, first_name: 'ApprovedReact' })
  const pending = (await request('/staff/address-requests', null, 'admin')).resident.find(row => row.name.startsWith('ApprovedReact') && row.status === 'pending')
  await mount('AddressRequests')
  const count = refreshes
  await click(button('Approve', document.querySelector(`[data-request-id="${pending.request_id}"]`)))
  await until(() => document.querySelector(`[data-request-id="${pending.request_id}"] .badge-approved`) && !document.querySelector('#root button[disabled]'))
  assert.ok(refreshes > count)
  const after = (await request('/resident/profile')).resident
  assert.equal(after.first_name, 'ApprovedReact'); assert.equal(after.birthdate, before.resident.birthdate)
  assert.equal(after.temporary_address, before.resident.temporary_address)
  await click(button('Done', document.querySelector('#address-request-success-modal')))
  assert.equal(document.querySelector('#address-request-success-modal.active'), null)
})
test('family shared inline review shows complete proposal and leaves no invented success dialog', async () => {
  await request('/resident/family-requests', { occupation: 'React occupation', family_reason: 'Correct household', is_married: '0', has_children: '0', references: [{ first_name: 'React', last_name: 'Reference' }] })
  const pending = (await request('/staff/address-requests', null, 'admin')).family.find(row => row.resident_number === fixtures.resident.number && row.status === 'pending')
  await mount('AddressRequests')
  const row = document.querySelector(`[data-family-request-id="${pending.request_id}"]`)
  assert.match(row.textContent, /React Reference/)
  await input(`[data-family-request-id="${pending.request_id}"] input`, 'Family approved')
  await click(button('Approve', row))
  await until(() => document.querySelector(`[data-family-request-id="${pending.request_id}"] .badge-approved`) && !document.querySelector('#root button[disabled]'))
  assert.equal((await request('/resident/profile')).resident.occupation, 'React occupation')
  assert.equal(document.querySelector('#address-request-success-modal.active'), null)
})
test('staff update uses shared dialog with required rejection note, optional approval, and reset on reopen', async () => {
  await mount('AddressRequests')
  const selector = `[data-staff-request-id="${fixtures.ui_staff_request}"]`
  await click(button('Reject', document.querySelector(selector)))
  assert.equal(document.querySelector('#staff-review-modal input').required, true)
  await input('#staff-review-modal input', 'Discard this note')
  await click(button('Cancel', document.querySelector('#staff-review-modal')))
  await click(button('Approve', document.querySelector(selector)))
  assert.equal(document.querySelector('#staff-review-modal input').required, false)
  assert.equal(document.querySelector('#staff-review-modal input').value, '')
  assert.match(document.querySelector('#staff-review-modal .modal-text').textContent, /to: 24 Staff Fixture Lane/)
  await submit('#staff-review-modal form')
  await until(() => document.querySelector(`${selector} .badge-approved`) && !document.querySelector('#root button[disabled]'))
  assert.equal(text(document.querySelector('#address-request-success-modal .modal-text')), 'Staff address request has been approved.')
})
test('staff deletion table is read-only; admin reject requires a note, preserves failed input, and can cancel safely', async () => {
  await request('/staff/resident/request-delete', { resident_id: String(fixtures.resident.id), reason: 'React deletion request' }, 'staff')
  const pending = (await request('/staff/deletion-requests', null, 'admin')).requests.find(row => row.resident_number === fixtures.resident.number && row.status === 'pending')
  fixtures.uiDeletion = pending
  await mount('DeletionRequests', 'staff')
  assert.equal(document.querySelector(`[data-deletion-request-id="${pending.request_id}"] button`), null)
  const reference = await oracle('deletion_requests', 'staff')
  assert.deepEqual([...document.querySelectorAll('#root table')].map(text), [...reference.querySelectorAll('.container table')].map(text))
  await mount('DeletionRequests')
  await click(button('Reject', document.querySelector(`[data-deletion-request-id="${pending.request_id}"]`)))
  assert.equal(document.querySelector('#review-request-modal input').required, true)
  assert.equal(document.querySelector('#review-request-modal form').checkValidity(), false)
  await input('#review-request-modal input', 'Reject note')
  refuse = true
  await submit('#review-request-modal form'); await until(() => document.querySelector('#review-request-modal .alert-error'))
  assert.equal(document.querySelector('#review-request-modal input').value, 'Reject note')
  refuse = false
  await click(button('Cancel', document.querySelector('#review-request-modal')))
  assert.equal(document.querySelector('#review-request-modal.active'), null)
  await click(button('Reject', document.querySelector(`[data-deletion-request-id="${pending.request_id}"]`)))
  assert.equal(document.querySelector('#review-request-modal input').value, '')
  await input('#review-request-modal input', 'Resident kept')
  await submit('#review-request-modal form'); await until(() => document.querySelector(`[data-deletion-request-id="${pending.request_id}"] .badge-rejected`))
  assert.match(document.querySelector(`[data-deletion-request-id="${pending.request_id}"]`).textContent, /Resident kept/)
})
test('admin approval archives responses; archive restore and purge confirmation preserve original UI', async () => {
  await request('/staff/resident/request-delete', { resident_id: String(fixtures.resident.id), reason: 'React archive request' }, 'staff')
  const pending = (await request('/staff/deletion-requests', null, 'admin')).requests.find(row => row.resident_number === fixtures.resident.number && row.status === 'pending')
  await mount('DeletionRequests')
  await click(button('Approve', document.querySelector(`[data-deletion-request-id="${pending.request_id}"]`)))
  assert.equal(document.querySelector('#review-request-modal input').required, false)
  assert.match(document.querySelector('#review-request-modal .alert-info').textContent, /survey responses are kept/)
  await submit('#review-request-modal form'); await until(() => document.querySelector(`[data-deletion-request-id="${pending.request_id}"] .badge-approved`))
  assert.match(document.querySelector(`[data-deletion-request-id="${pending.request_id}"] a`).textContent, /In Deleted Accounts/)
  await mount('DeletedAccounts')
  const reference = await oracle('deleted_accounts')
  assert.deepEqual([...document.querySelectorAll('#root table')].map(text), [...reference.querySelectorAll('.container table')].map(text))
  const row = document.querySelector(`[data-archive-id="${fixtures.resident.id}"][data-kind="residents"]`)
  assert.equal(row.cells[3].textContent, '1')
  const count = calls
  await click(button('Delete Forever', row))
  assert.match(document.querySelector('#purge-modal .alert-error').textContent, /1 survey response will also be destroyed/)
  await click(button('Cancel', document.querySelector('#purge-modal')))
  assert.equal(calls, count, 'Cancel never performs a destructive request')
  await click(button('Restore', row))
  await until(() => !document.querySelector(`[data-archive-id="${fixtures.resident.id}"][data-kind="residents"]`))
  await mount('DeletionRequests')
  assert.match(document.querySelector(`[data-deletion-request-id="${pending.request_id}"]`).textContent, /Account restored/)
})
test('residency log matches PHP, filters through history, searches, and exposes no mutation', async () => {
  await mount('ResidencyRecords', 'admin', '/staff/residency-records')
  const reference = await oracle('residency_records')
  assert.deepEqual([...document.querySelectorAll('#residency-log')].map(text), [...reference.querySelectorAll('#residency-log')].map(text))
  assert.equal(document.querySelector('#root button'), null)
  await input('.table-search', 'no-such-fixture-xyz')
  assert.notEqual(document.querySelector('.no-match-row').style.display, 'none')
  const counts = [...document.querySelectorAll('.stat-number')].map(node => node.textContent)
  await click(document.querySelector('a[href="/staff/residency-records?filter=permanent"]'))
  await until(() => document.querySelector('.back-link'))
  assert.deepEqual([...document.querySelectorAll('.stat-number')].map(node => node.textContent), counts)
  assert.equal(document.querySelector('.table-search').value, '')
  await React.act(async () => router.navigate(-1))
  await until(() => document.querySelector('.card-head-title')?.textContent === 'Change History')
})
test('all four screens honor onboarding redirects', async () => {
  redirect = true
  for (const screen of ['AddressRequests','DeletionRequests','DeletedAccounts','ResidencyRecords']) {
    await mount(screen)
    await until(() => document.body.textContent.includes('Onboarding destination'))
  }
  redirect = false
})
