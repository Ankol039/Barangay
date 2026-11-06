import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { before, after, test } from 'node:test'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'
const exec = promisify(execFile)
const fixtures = await readFile(join(tmpdir(), 'barangay-profile-fixtures.json'), 'utf8').then(JSON.parse).catch(() => null)
const base = 'http://localhost/barangay_survey_system/api'
const originalFetch = globalThis.fetch
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/'} )
let React, createRoot, createMemoryRouter, RouterProvider, server, root, router, cookie, Profile, AuthProvider, DirtyWarningProvider, ConfirmProvider, ProtectedRoute
let lastBody, refuse = false, calls = 0, confirmations = 0
const e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  if (!fixtures) return
  for (const key of ['window', 'document', 'HTMLElement', 'location']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  globalThis.requestAnimationFrame = fn => setTimeout(fn, 0)
  window.confirm = () => { confirmations++; return false }
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ createMemoryRouter, RouterProvider } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(), 'barangay-profile-vite-tests'),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  ;({ default: Profile } = await server.ssrLoadModule('/src/pages/resident/Profile.jsx'))
  ;({ default: AuthProvider } = await server.ssrLoadModule('/src/context/AuthProvider.jsx'))
  ;({ default: DirtyWarningProvider } = await server.ssrLoadModule('/src/components/common/DirtyWarningProvider.jsx'))
  ;({ default: ConfirmProvider } = await server.ssrLoadModule('/src/components/common/ConfirmProvider.jsx'))
  ;({ default: ProtectedRoute } = await server.ssrLoadModule('/src/routes/ProtectedRoute.jsx'))
  globalThis.fetch = async (path, options) => {
    if (options?.method === 'POST' && path.includes('/resident/')) {
      calls++; lastBody = options.body
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
  while (!check() && Date.now() < deadline) await React.act(async () => new Promise(resolve => setTimeout(resolve, 20)))
  assert.ok(check(), document.body.textContent)
}
async function mount(kind = 'permanent') {
  await React.act(async () => { root?.unmount(); router?.dispose() })
  const result = await originalFetch(base + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ identifier: fixtures.residents[kind].number, password: 'ProfileFixtureA1' }) })
  assert.equal(result.status, 200)
  cookie = result.headers.getSetCookie().filter(value => value.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  document.body.innerHTML = '<div id="root"></div>'
  window.history.replaceState({}, '', '/resident/profile')
  root = createRoot(document.getElementById('root'))
  router = createMemoryRouter([{ element: e(AuthProvider, null, e(DirtyWarningProvider, null, e(ConfirmProvider, null,
    e(ProtectedRoute, { portal: 'resident' })))), children: [{ path: '/resident/profile', element: e('div', { className: 'container' }, e(Profile)) }] }], { initialEntries: ['/resident/profile'] })
  await React.act(async () => root.render(e(React.StrictMode, null, e(RouterProvider, { router }))))
  await until(() => document.querySelector('.profile-card'))
}
async function click(node) { assert.ok(node); await React.act(async () => node.click()) }
async function input(selector, value) {
  await React.act(async () => {
    const node = document.querySelector(selector); assert.ok(node, selector)
    Object.getOwnPropertyDescriptor(node.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype, 'value').set.call(node, value)
    node.dispatchEvent(new window.Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }))
  })
}
async function submit(selector) {
  await React.act(async () => document.querySelector(selector).dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })))
}
function button(label, scope = document) { return [...scope.querySelectorAll('button')].find(node => node.textContent.trim() === label) }
function card(title) { return [...document.querySelectorAll('.container .card')].find(node => node.querySelector('h2')?.textContent === title) }
test('temporary, permanent and empty profiles match original PHP tables and section text', { skip: !fixtures }, async () => {
  for (const kind of ['temporary', 'permanent', 'empty']) {
    await mount(kind)
    const result = await exec('/Applications/XAMPP/xamppfiles/bin/php', [fileURLToPath(new URL('./resident-profile-reference.php', import.meta.url)), String(fixtures.residents[kind].id)])
    const reference = new JSDOM(result.stdout).window.document
    for (const selector of ['.container .page-head', '.container > .grid-2 .card-head-title', '.container > .grid-2 table', '.container > .grid-2 .muted-note']) {
      assert.deepEqual([...document.querySelectorAll(selector)].map(text), [...reference.querySelectorAll(selector)].map(text), kind + ': ' + selector)
    }
  }
})
test('contact form saves immediately, reports failures, retains values and protects dirty dismissal', { skip: !fixtures }, async () => {
  await mount()
  await click(button('Edit Contact'))
  await input('#email', 'react@example.test'); await input('#contact_number', '0917 123 4567')
  await click(document.querySelector('#edit-contact-modal [data-modal-close]'))
  assert.ok(document.querySelector('#edit-contact-modal.active')); assert.ok(confirmations > 0)
  refuse = true
  await submit('#edit-contact-modal form'); await until(() => document.querySelector('#edit-contact-modal .alert-error'))
  assert.equal(document.querySelector('#email').value, 'react@example.test')
  refuse = false
  await submit('#edit-contact-modal form'); await until(() => !document.querySelector('#edit-contact-modal'))
  assert.match(card('Contact Information').textContent, /react@example.test/)
  await mount(); assert.match(card('Contact Information').textContent, /react@example.test/)
})
test('birthdate submission creates pending state and name modal warns and refuses a second request', { skip: !fixtures }, async () => {
  await mount()
  await click(button('Request Change', card('Personal Details')))
  await input('#birthdate', '1989-03-04'); await submit('#request-birthdate-modal form')
  await until(() => !document.querySelector('#request-birthdate-modal'))
  assert.match(card('Personal Details').textContent, /February 10, 1990/)
  await click(button('Request Change', card('Profile Overview')))
  assert.match(document.querySelector('.request-pending-warning').textContent, /pending name\/address request/)
  await submit('#request-change-modal form'); await until(() => document.querySelectorAll('#request-change-modal .alert-error').length === 2)
})
test('family repeaters renumber, preserve Others, reveal rows, and submit reviewed JSON without changing the profile', { skip: !fixtures }, async () => {
  await mount()
  await click(button('Request Change', card('Family & Household')))
  assert.ok(document.querySelector('#family-request-modal .modal-wide'))
  assert.equal(document.querySelector('#req_occupation').value, '', 'Source clear-on-open behavior')
  await click(button('+ Add another child'))
  assert.equal(document.querySelectorAll('[data-person-rows="children"] .person-row').length, 3)
  await click(document.querySelector('[data-person-rows="children"] .person-row:nth-child(2) .btn-link-danger'))
  const rows = [...document.querySelectorAll('[data-person-rows="children"] .person-row')]
  assert.deepEqual(rows.map(node => node.querySelector('[data-row-number]').textContent), ['1', '2'])
  assert.equal(rows[1].querySelector('select').name, 'children[1][courtesy_title]')
  await input('[name="references[0][courtesy_title]"]', 'Mr.')
  assert.equal(document.querySelector('[name="references[0][courtesy_title_other]"]').value, '')
  await input('[name="references[0][courtesy_title]"]', 'Others')
  await input('[name="references[0][courtesy_title_other]"]', 'Prof.')
  await input('[name="references[0][first_name]"]', 'React')
  await input('[name="references[0][last_name]"]', 'Reference')
  await click(document.querySelector('[name="is_married"][value="0"]'))
  await click(document.querySelector('[name="has_children"][value="0"]'))
  assert.equal(document.querySelector('[name="children[0][first_name]"]'), null)
  await input('#family_reason', 'React synthetic correction')
  await submit('#family-request-modal form'); await until(() => !document.querySelector('#family-request-modal'))
  const body = JSON.parse(lastBody)
  assert.equal(body.references[0].courtesy_title_other, 'Prof.')
  assert.match(card('Family & Household').textContent, /change waiting for review/)
  assert.match(card('Family & Household').textContent, /Teacher/)
  await click(button('Request Change', card('Family & Household')))
  assert.equal(document.querySelector('#family-request-modal form'), null)
  assert.match(document.querySelector('#family-request-modal').textContent, /Staff will look at that one first/)
})
test('temporary request includes seven extra address parts and stored custom title; permanent omits temporary fields', { skip: !fixtures }, async () => {
  for (const kind of ['temporary', 'permanent']) {
    await mount(kind); await click(button('Request Change', card('Profile Overview')))
    assert.equal(document.querySelector('#request_courtesy_title').value, 'Others')
    assert.equal(document.querySelector('#request_courtesy_title_other').value, 'Dr.')
    assert.equal(document.querySelectorAll('#request-change-modal [name^="temp_"]').length, kind === 'temporary' ? 7 : 0)
  }
})
test('multipart client lets the browser set the boundary and does not JSON encode the JPEG', { skip: !fixtures }, async () => {
  const { apiRequest } = await server.ssrLoadModule('/src/api/client.js')
  const savedFetch = globalThis.fetch
  try {
    const body = new FormData(); body.append('profile_photo', new Blob(['jpeg'], { type: 'image/jpeg' }), 'profile-photo.jpg')
    globalThis.fetch = async (_url, options) => {
      assert.equal(options.body, body); assert.equal(options.headers['Content-Type'], undefined); assert.equal(options.credentials, 'include')
      return Response.json({ ok: true })
    }
    await apiRequest('/resident/photo', { method: 'POST', body })
  } finally { globalThis.fetch = savedFetch }
})
test('crop hook covers the canvas, zooms around its center, clamps scaled pointer dragging and exports JPEG', { skip: !fixtures }, async () => {
  const { default: usePhotoCropper } = await server.ssrLoadModule('/src/hooks/usePhotoCropper.js')
  await React.act(async () => { root?.unmount(); router?.dispose() })
  document.body.innerHTML = '<div id="root"></div>'
  const draws = [], revoked = [], codecs = [], created = []
  const oldImage = globalThis.Image, oldCreate = URL.createObjectURL, oldRevoke = URL.revokeObjectURL
  const proto = window.HTMLCanvasElement.prototype
  const oldContext = proto.getContext, oldData = proto.toDataURL, oldBlob = proto.toBlob
  let crop
  try {
    globalThis.Image = class { naturalWidth = 640; naturalHeight = 400; set src(_url) { queueMicrotask(() => this.onload?.()) } }
    URL.createObjectURL = () => { created.push('blob:synthetic'); return 'blob:synthetic' }; URL.revokeObjectURL = url => revoked.push(url)
    proto.getContext = () => ({ clearRect() {}, fillRect() {}, drawImage(...args) { draws.push(args.slice(1)) } })
    proto.toDataURL = () => 'data:image/jpeg;base64,synthetic'
    proto.toBlob = (callback, type, quality) => { codecs.push([type, quality]); callback(new Blob(['jpeg'], { type })) }
    const file = new File(['image'], 'landscape.png', { type: 'image/png' })
    function Harness() {
      crop = usePhotoCropper(file)
      return e('canvas', { ref: crop.canvas, width: 320, height: 320 })
    }
    root = createRoot(document.getElementById('root'))
    await React.act(async () => root.render(e(React.StrictMode, null, e(Harness))))
    await until(() => crop.ready)
    assert.deepEqual(created, ['blob:synthetic'], 'Discarded StrictMode setup must not start a revoked blob request')
    assert.deepEqual(revoked, [])
    assert.deepEqual(draws.at(-1), [-96, 0, 512, 320])
    await React.act(async () => crop.setZoom(2))
    assert.deepEqual(draws.at(-1), [-352, -160, 1024, 640])
    const canvas = document.querySelector('canvas')
    canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 160, height: 160 })
    canvas.setPointerCapture = () => {}; canvas.releasePointerCapture = () => {}
    await React.act(async () => crop.pointerProps.onPointerDown({ clientX: 80, clientY: 80, pointerId: 1 }))
    await React.act(async () => crop.pointerProps.onPointerMove({ clientX: 10000, clientY: -10000, pointerId: 1 }))
    assert.deepEqual(draws.at(-1), [0, -320, 1024, 640])
    await React.act(async () => crop.pointerProps.onPointerUp({ pointerId: 1 }))
    const jpeg = await crop.jpeg()
    assert.equal(jpeg.name, 'profile-photo.jpg'); assert.equal(jpeg.type, 'image/jpeg'); assert.deepEqual(codecs, [['image/jpeg', 0.9]])
    await React.act(async () => root.unmount()); root = null
    assert.deepEqual(revoked, ['blob:synthetic'])
  } finally {
    globalThis.Image = oldImage; URL.createObjectURL = oldCreate; URL.revokeObjectURL = oldRevoke
    proto.getContext = oldContext; proto.toDataURL = oldData; proto.toBlob = oldBlob
  }
})
