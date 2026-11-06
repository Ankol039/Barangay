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
let React, createRoot, router, server, root, Screen, cookie, reference, fields, redirect = false
let role = 'staff', target, detail
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  for (const key of ['window','document','HTMLElement','Event','KeyboardEvent']) globalThis[key] = dom.window[key]
  globalThis.requestAnimationFrame = callback => callback()
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  router = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-resident-add-tests'), server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  ;({ default: Screen } = await server.ssrLoadModule('/src/pages/staff/ResidentManagement.jsx'))
  const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures.staff.number, password: 'SurveyFixtureA1' }) })
  assert.equal(response.status, 200)
  cookie = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  globalThis.fetch = async (path, options) => redirect ? Response.json({ ok: true, data: null, redirect: '/staff/change-password' }) : originalFetch(base + path.replace(/^.*?\/api/, ''), { ...options, headers: { ...options?.headers, Cookie: cookie } })
  const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./staff-residents-reference.php', import.meta.url)), String(fixtures.staff.id), 'html'])
  reference = new JSDOM(stdout, { runScripts: 'outside-only' })
  fields = (await (await originalFetch(base + '/staff/residents', { headers: { Cookie: cookie } })).json()).data.fields
})
after(async () => {
  if (React) await React.act(async () => root?.unmount())
  await server?.close(); globalThis.fetch = originalFetch; dom.window.close(); reference?.window.close()
})
async function until(check) {
  const deadline = Date.now() + 8000
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 15)))
  assert.ok(check(), 'Expected resident UI state')
}
async function mount() {
  await React.act(async () => root?.unmount())
  document.body.innerHTML = '<div id="root"></div>'; root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(e(React.StrictMode, null, e(router.MemoryRouter, null, e(router.Routes, null,
    e(router.Route, { path: '/', element: e(Screen) }), e(router.Route, { path: '/staff/change-password', element: e('p', null, 'Onboarding destination') }))))))
  await until(() => !document.querySelector('[role="status"]'))
}
async function change(name, value) {
  await React.act(async () => {
    const control = document.querySelector(`.modal-overlay.active [name="${name}"]`)
    assert.ok(control, `Field exists: ${name}`)
    const prototype = control.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : dom.window.HTMLInputElement.prototype
    Object.getOwnPropertyDescriptor(prototype,'value').set.call(control,value)
    control.dispatchEvent(new dom.window.Event(control.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
  })
}
const click = async selector => React.act(async () => document.querySelector(selector).click())
const visible = doc => [...doc.querySelectorAll('#residents-table tr[data-search-text]')].filter(node => node.style.display !== 'none').map(node => text(node.cells[0]))
async function asRole(next) {
  role = next
  const response = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: fixtures[role].number, password: 'SurveyFixtureA1' }) })
  cookie = response.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  target = fixtures[`${role}_target`]
  detail = (await (await originalFetch(`${base}/staff/resident?id=${target}`, { headers: { Cookie: cookie } })).json()).data
  await mount()
}
const row = () => [...document.querySelectorAll('#residents-table tr')].find(node => node.cells[0]?.textContent === detail.resident.resident_number)
async function rowClick(label) { await React.act(async () => row().querySelector(`[aria-label="${label}"]`).click()) }
async function edit() { await rowClick('Edit resident'); await until(() => document.querySelector('#edit-resident-info-modal.active')) }
async function submit(selector) { await React.act(async () => document.querySelector(selector).dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }))) }
async function done(expected) {
  await until(() => document.querySelector('#resident-success-modal.active .modal-text'))
  assert.equal(text(document.querySelector('#resident-success-modal .modal-text')), expected)
  await click('#resident-success-modal .btn')
}
for (const next of ['staff', 'admin']) {
  test(`${next}: edit chooser, record prefill, custom titles and rebuilt children/references match the PHP loader`, async () => {
    await asRole(next)
    await click('.btn-pair .btn-accent')
    let alert = ''
    window.alert = message => { alert = message }
    await click('#load-edit-resident-btn'); assert.equal(alert, 'Please choose a resident ID first.')
    await change('edit_resident_select', String(target)); await click('#load-edit-resident-btn')
    await until(() => document.querySelector('#edit-resident-info-modal.active'))
    const modal = document.querySelector('#edit-resident-info-modal')
    assert.equal(modal.querySelector('[name=residency_status]'), null)
    assert.equal(text(modal.querySelector('#edit-residency-badge')), 'Temporary')
    for (const key of ['first_name','middle_name','last_name','email','birthdate','birthplace','occupation','household_number','temp_household_number','residence_type'])
      assert.equal(modal.querySelector(`[name="${key}"]`).value, detail.resident[key] || '')
    assert.equal(modal.querySelector('[name=courtesy_title]').value, 'Others')
    assert.equal(modal.querySelector('[name=courtesy_title_other]').value, 'Engr.')
    assert.equal(modal.querySelector('[name=spouse_courtesy_title_other]').value, 'Dr.')
    assert.equal(modal.querySelector('[name="children[0][courtesy_title_other]"]').value, 'Atty.')
    assert.equal(modal.querySelector('[name="references[1][courtesy_title_other]"]').value, 'Engr.')
    assert.equal(modal.querySelectorAll('[data-person-rows=children] .person-row').length, 2)
    assert.equal(modal.querySelectorAll('[data-person-rows=references] .person-row').length, 2)
    assert.equal(modal.querySelector('[name=temp_household_number]').required, true)
    assert.equal(!!modal.querySelector('#residency-switch'), next === 'admin')
    // Execute the unchanged PHP edit loader against an identical sanitized record in its original markup.
    const { stdout } = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./staff-residents-reference.php', import.meta.url)), String(fixtures[next].id), 'html'])
    const oracle = new JSDOM(stdout, { runScripts: 'outside-only' })
    const php = await readFile(new URL('../../staff/resident_management.php', import.meta.url), 'utf8')
    const start = php.indexOf('(function () {\n    var residentLookup')
    const loader = php.slice(start, php.indexOf('</script>', start)).replace(/var residentLookup = <\?php.*?\?>;/s, `var residentLookup = ${JSON.stringify({ [target]: detail.resident })};`)
    oracle.window.eval(loader)
    oracle.window.document.querySelector(`[data-resident-edit="${target}"]`).click()
    const original = oracle.window.document.querySelector('#edit-resident-info-modal')
    for (const control of modal.querySelectorAll('input[name],select[name]')) {
      const expected = original.querySelector(`[name="${control.name}"]`)
      assert.ok(expected, control.name)
      if (control.type === 'radio') continue
      assert.equal(control.value, expected.value, `PHP edit loader: ${control.name}`)
    }
    oracle.window.close()
    await click('#edit-resident-info-modal .modal-close')
    // Opening a second record must rebuild arrays, never leave the previous household in place.
    const otherId = fixtures[`${next === 'staff' ? 'admin' : 'staff'}_target`]
    const otherDetail = (await (await originalFetch(`${base}/staff/resident?id=${otherId}`, { headers: { Cookie: cookie } })).json()).data
    const otherRow = [...document.querySelectorAll('#residents-table tr')].find(node => node.cells[0]?.textContent === otherDetail.resident.resident_number)
    await React.act(async () => otherRow.querySelector('[aria-label="Edit resident"]').click())
    await until(() => document.querySelector('#edit-resident-info-modal.active'))
    assert.equal(document.querySelector('#edit-resident-info-modal [name=resident_id]').value, String(otherId))
  })
  test(`${next}: edit retains validation values and saves updated family rows without changing residency`, async () => {
    await asRole(next); await edit()
    await change('occupation', 'React saved occupation')
    await change('middle_name', '')
    await submit('#edit-resident-info-modal form')
    await until(() => document.querySelector('#edit-resident-info-modal .alert-error'))
    assert.equal(text(document.querySelector('#edit-resident-info-modal .alert-error')), 'Please fill in all required fields (marked with *).')
    assert.equal(document.querySelector('#edit-resident-info-modal [name=occupation]').value, 'React saved occupation')
    assert.equal(document.querySelectorAll('#edit-resident-info-modal [data-person-rows=references] .person-row').length, 2)
    await change('middle_name', 'Updated')
    await change('references[1][first_name]', 'Updated reference')
    await submit('#edit-resident-info-modal form'); await done('Resident updated successfully.')
    const actual = (await (await originalFetch(`${base}/staff/resident?id=${target}`, { headers: { Cookie: cookie } })).json()).data.resident
    assert.equal(actual.occupation, 'React saved occupation'); assert.equal(actual.references[1].first_name, 'Updated reference')
    assert.equal(actual.children.length, 2); assert.equal(actual.residency_status, 'temporary')
    // Next edit reflects saved values and clears the former error.
    await edit(); assert.equal(document.querySelector('#edit-resident-info-modal .alert-error'), null)
    assert.equal(document.querySelector('#edit-resident-info-modal [name=middle_name]').value, 'Updated')
  })
  test(`${next}: reset and deactivate/reactivate use PHP confirmations, cancel safely and refresh badges`, async () => {
    await asRole(next)
    await rowClick('Reset password')
    assert.equal(text(document.querySelector('#resident-action-confirm-message')), "Reset this resident's password to their Resident Number?")
    await click('#resident-action-confirm [data-modal-close].btn')
    assert.equal(document.querySelector('#resident-success-modal.active'), null)
    await rowClick('Reset password'); await click('#resident-action-confirm-proceed')
    await done('Password reset to default (resident number).')
    for (const active of [true, false]) {
      await until(() => row().querySelector(`[aria-label="${active ? 'Deactivate' : 'Reactivate'} account"]`))
      await rowClick(active ? 'Deactivate account' : 'Reactivate account')
      const expected = active ? `Deactivate ${detail.resident.first_name} ${detail.resident.last_name}? They will not be able to log in, but their survey history is kept.` : `Reactivate ${detail.resident.first_name} ${detail.resident.last_name}?`
      assert.equal(text(document.querySelector('#resident-action-confirm-message')), expected)
      await click('#resident-action-confirm-proceed')
      await done(active ? 'Resident account deactivated. Their survey history has been kept.' : 'Resident account reactivated.')
    }
  })
}
test('staff: reason request keeps the account, refreshes pending icon and hides admin controls', async () => {
  await asRole('staff')
  assert.equal(row().querySelector('[aria-label="Delete resident"]'), null)
  await rowClick('Request deletion')
  assert.equal(document.querySelector('#reason').required, true)
  assert.equal(document.querySelector('#reason').maxLength, 255)
  await change('reason', 'Duplicate record'); await submit('#request-delete-modal form')
  await done(`Deletion request sent to the administrator for ${detail.resident.first_name} ${detail.resident.last_name}. The account is untouched until they decide.`)
  await until(() => row().querySelector('[title="Deletion request awaiting the administrator"]'))
  assert.equal(row().querySelector('[aria-label="Request deletion"]'), null)
})
test('admin: promotion and reversion preview saved addresses, preserve records, require reason and refresh status', async () => {
  await asRole('admin'); await edit(); await click('#residency-switch-btn')
  assert.equal(document.querySelector('#edit-resident-info-modal.active'), null)
  assert.equal(text(document.getElementById('promote-old-address')), detail.resident.address)
  assert.equal(text(document.getElementById('promote-new-address')), detail.resident.temporary_address)
  assert.equal(document.querySelector('#promote_reason').required, false)
  await change('reason', 'Verified documents'); await submit('#promote-resident-modal form')
  await done(`${detail.resident.first_name} ${detail.resident.last_name} is now a permanent resident. Their previous address is kept in Residency Records.`)
  await until(() => row().querySelector('.badge-permanent')); await edit()
  assert.equal(document.querySelector('#edit-resident-info-modal [name=temp_household_number]'), null)
  await click('#residency-switch-btn')
  assert.equal(text(document.getElementById('revert-restore-address')), detail.resident.address)
  assert.equal(document.querySelector('#revert_reason').required, true)
  await change('reason', 'Claim disproved'); await submit('#revert-resident-modal form')
  await done(`${detail.resident.first_name} ${detail.resident.last_name} has been returned to temporary resident status.`)
  await until(() => row().querySelector('.badge-temporary'))
})
test('admin: Delete archives, uses exact legacy warning, and removes the resident from the list', async () => {
  await asRole('admin')
  assert.equal(row().querySelector('[aria-label="Request deletion"]'), null)
  await rowClick('Delete resident')
  assert.equal(text(document.querySelector('#resident-action-confirm-message')), `Delete ${detail.resident.first_name} ${detail.resident.last_name}? Their submitted survey responses will also be deleted. This cannot be undone.`)
  await click('#resident-action-confirm-proceed')
  await done('Resident moved to Deleted Accounts. Their survey responses were kept, and you can restore the account from there.')
  await until(() => !row())
})
