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
    const control = document.querySelector(`#add-resident-modal [name="${name}"]`)
    assert.ok(control, `Field exists: ${name}`)
    const prototype = control.tagName === 'SELECT' ? dom.window.HTMLSelectElement.prototype : dom.window.HTMLInputElement.prototype
    Object.getOwnPropertyDescriptor(prototype,'value').set.call(control,value)
    control.dispatchEvent(new dom.window.Event(control.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
  })
}
const click = async selector => React.act(async () => document.querySelector(selector).click())
const visible = doc => [...doc.querySelectorAll('#residents-table tr[data-search-text]')].filter(node => node.style.display !== 'none').map(node => text(node.cells[0]))
test('Every list cell, filter/search token, heading and label matches original PHP', async () => {
  await mount()
  const expected = reference.window.document
  assert.equal(text(document.querySelector('.page-head')),text(expected.querySelector('.page-head')))
  assert.deepEqual([...document.querySelectorAll('#residents-table th')].map(text),[...expected.querySelectorAll('#residents-table th')].map(text))
  const cells = doc => [...doc.querySelectorAll('#residents-table tr[data-search-text]')].map(row => [...row.cells].slice(0,9).map(text))
  assert.deepEqual(cells(document), cells(expected))
  assert.deepEqual([...document.querySelectorAll('tr[data-search-text]')].map(n=>[n.dataset.searchText,n.dataset.filter]),[...expected.querySelectorAll('tr[data-search-text]')].map(n=>[n.dataset.searchText,n.dataset.filter]))
  assert.deepEqual([...document.querySelectorAll('.filter-tab')].map(text),[...expected.querySelectorAll('.filter-tab')].map(text))
})
test('Combined case-insensitive trimmed search and every filter follow original table script', async () => {
  await mount()
  const source = await readFile(new URL('../../assets/js/script.js', import.meta.url),'utf8')
  reference.window.eval(source.slice(source.indexOf('    function filterTable('), source.indexOf('    // ---------- "View All" toggle')))
  for (const filter of ['all','temporary','permanent','pending-login','deactivated']) {
    await click(`.filter-tab[data-filter="${filter}"]`)
    reference.window.document.querySelector(`.filter-tab[data-filter="${filter}"]`).click()
    for (const term of ['', '  RESIDENTSTEP12_  ', 'xyz no match']) {
      await React.act(async () => {
        const node=document.getElementById('resident-search')
        Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value').set.call(node,term)
        node.dispatchEvent(new dom.window.Event('input',{bubbles:true}))
      })
      const expected=reference.window.document.getElementById('resident-search'); expected.value=term; expected.dispatchEvent(new reference.window.Event('input'))
      assert.deepEqual(visible(document),visible(reference.window.document))
      assert.equal(document.querySelector('.no-match-row').style.display,reference.window.document.querySelector('.no-match-row').style.display)
    }
  }
})
test('Add form preserves source field labels, required addresses, temporary visibility, title/category and six-month hint', async () => {
  await mount(); await click('.btn-pair button')
  const actual = document.getElementById('add-resident-modal'), expected = reference.window.document.getElementById('add-resident-modal')
  const labels = doc => [...doc.querySelectorAll('label')].filter(n=>!n.closest('[hidden]') && !n.closest('[style*="display:none"]')).map(text)
  assert.deepEqual(labels(actual),labels(expected))
  assert.equal(actual.querySelector('[name=middle_name]').required,true)
  assert.equal(actual.querySelector('[name=household_number]').required,true)
  assert.equal(actual.querySelector('[name=purok]').required,false)
  assert.equal(actual.querySelector('[name=temp_household_number]'),null)
  await change('residency_status','temporary')
  for (const key of ['household_number','barangay','municipality','province']) assert.equal(actual.querySelector(`[name=temp_${key}]`).required,true)
  assert.equal(actual.querySelector('[name=residence_type]').required,true)
  await change('courtesy_title','Others'); assert.equal(actual.querySelector('[name=courtesy_title_other]').required,true)
  await change('courtesy_title_other','Engr.'); await change('courtesy_title','Mr.'); await change('courtesy_title','Others')
  assert.equal(actual.querySelector('[name=courtesy_title_other]').value,'')
  await change('special_category','pwd'); assert.equal(actual.querySelector('[name=category_reference]').pattern,fields.category_references.pwd.pattern)
  await change('category_reference','stale'); await change('special_category','none'); await change('special_category','pwd')
  assert.equal(actual.querySelector('[name=category_reference]').value,'')
  const today = new Date().toLocaleDateString('en-CA'); await change('residency_start_date',today); await change('residency_status','permanent')
  assert.match(actual.querySelector('[name=residency_start_date]').validationMessage,/at least 6 months/)
  await change('residency_status','temporary'); assert.equal(actual.querySelector('[name=residency_start_date]').validationMessage,'')
})
test('Family controls repeat names, remove rows, and modal reset restores a fresh registration', async () => {
  await mount(); await click('.btn-pair button')
  await click('[data-person-rows=references] + button')
  assert.equal(document.querySelectorAll('[data-person-rows=references] .person-row').length,2)
  await change('references[1][first_name]','Second')
  await click('[data-person-rows=references] .person-row:last-child button')
  assert.equal(document.querySelectorAll('[data-person-rows=references] .person-row').length,1)
  await change('first_name','Draft')
  await click('#add-resident-modal .modal-close'); await click('.btn-pair button')
  assert.equal(document.querySelector('[name=first_name]').value,'')
  assert.equal(document.querySelector('[name=is_married][value="0"]').checked,true)
})
for (const status of ['permanent','temporary']) test(`Live React adds ${status}, retains validation values, refreshes table and shows exact success hand-over`, async () => {
  await mount(); await click('.btn-pair button')
  const form = () => document.querySelector('#add-resident-modal form')
  await change('first_name',`ResidentStep12_${fixtures.staff.id}`)
  await React.act(async () => form().dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true})))
  await until(()=>document.querySelector('#add-resident-modal .alert-error'))
  assert.equal(document.querySelector('[name=first_name]').value,`ResidentStep12_${fixtures.staff.id}`)
  assert.equal(text(document.querySelector('#add-resident-modal .alert-error')),'Please fill in all required fields (marked with *).')
  for (const [key,value] of Object.entries({middle_name:'React',last_name:'Fixture',birthdate:'1990-01-02',birthplace:'Bulacan',gender:'man',household_number:'12',barangay:'Anyatam',municipality:'San Ildefonso',province:'Bulacan',residency_status:status,residency_start_date:status==='permanent'?'2020-01-01':new Date().toLocaleDateString('en-CA'),'references[0][first_name]':'Reference','references[0][last_name]':'Fixture'})) await change(key,value)
  if(status==='temporary') for (const [key,value] of Object.entries({residence_type:'BH',temp_household_number:'45',temp_barangay:'Anyatam',temp_municipality:'San Ildefonso',temp_province:'Bulacan'})) await change(key,value)
  await React.act(async()=>form().dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true})))
  await until(()=>document.getElementById('resident-success-modal').classList.contains('active'))
  const success=document.getElementById('resident-success-modal'),number=success.querySelector('strong').textContent
  assert.match(number,/^R-\d{4}-\d{4,}$/)
  assert.equal(text(success.querySelector('.modal-text')),`Resident added successfully. Their auto-generated Resident Number (and default password) is: ${number}`)
  await until(()=>[...document.querySelectorAll('td')].some(cell=>cell.textContent===number))
  await click('#resident-success-modal .btn')
  assert.equal(success.classList.contains('active'),false)
  await mount(); assert.equal(document.getElementById('resident-success-modal').classList.contains('active'),false)
})
test('Age display singular/plural and residency calendar hints match source examples',async()=>{
  const {ageLabel,residencyHint}=await server.ssrLoadModule('/src/components/residents/residentForm.js')
  const today=new Date(2026,10,6)
  assert.equal(ageLabel('2025-11-06',today),'1 year old'); assert.equal(ageLabel('2024-11-07',today),'1 year old')
  assert.equal(ageLabel('2024-11-06',today),'2 years old'); assert.equal(ageLabel('2027-01-01',today),'Invalid birthdate')
  assert.equal(residencyHint('2026-05-06','permanent',6,today).validity,'')
  assert.match(residencyHint('2026-05-07','permanent',6,today).validity,/at least 6 months/)
})
test('Onboarding redirect stays enforced before resident data loads',async()=>{
  redirect=true; await mount(); assert.match(document.body.textContent,/Onboarding destination/); redirect=false
})
