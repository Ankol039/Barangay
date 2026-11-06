import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'

let server, React, createRoot, routerAPI, Provider, ConfirmProvider, DemoModal, DataTable, Alert, Toast, root
const dom = new JSDOM('<!doctype html><body></body>', { url: 'http://localhost/barangay_survey_system/app/dev/components' })
before(async () => {
  for (const key of ['window', 'document', 'HTMLElement', 'HTMLInputElement', 'Event', 'MouseEvent', 'location']) globalThis[key] = dom.window[key]
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  routerAPI = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default
  ;({ default: Provider } = await server.ssrLoadModule('/src/components/common/DirtyWarningProvider.jsx'))
  ;({ default: ConfirmProvider } = await server.ssrLoadModule('/src/components/common/ConfirmProvider.jsx'))
  ;({ default: DemoModal } = await server.ssrLoadModule('/src/pages/dev/DemoModal.jsx'))
  ;({ default: DataTable } = await server.ssrLoadModule('/src/components/common/DataTable.jsx'))
  ;({ default: Alert } = await server.ssrLoadModule('/src/components/common/Alert.jsx'))
  ;({ default: Toast } = await server.ssrLoadModule('/src/components/common/Toast.jsx'))
})
after(async () => { await React.act(async () => root?.unmount()); await server?.close(); dom.window.close() })
const e = (...args) => React.createElement(...args)
const click = async (node) => React.act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })))
const fill = async (node, value) => React.act(async () => {
  Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(node, value)
  node.dispatchEvent(new Event('input', { bubbles: true }))
})
async function mount(content) {
  await React.act(async () => root?.unmount())
  document.body.innerHTML = '<div id="root"></div>'
  root = createRoot(document.getElementById('root'))
  const router = routerAPI.createMemoryRouter([{ path: '*', element: e(Provider, null, e(ConfirmProvider, null, content)) }],
    { initialEntries: ['/dev/components'] })
  await React.act(async () => root.render(e(routerAPI.RouterProvider, { router })))
  return router
}
const button = (text, scope = document) => [...scope.querySelectorAll('button')].find((node) => node.textContent === text)

test('table search trims/case-folds and combines token filters; empty state and tables are independent', async () => {
  const props = { columns: [{ key: 'name', label: 'Name' }], rows: [
    { id: 1, name: 'Alpha', searchText: 'R-1 Alpha', filter: 'permanent' },
    { id: 2, name: 'Beta', searchText: 'R-2 Beta', filter: 'temporary pending-login' },
  ], filters: [{ value: 'all', label: 'All' }, { value: 'pending-login', label: 'Pending Login' }], emptyMessage: 'No match.' }
  await mount(e(React.Fragment, null, e(DataTable, { ...props, id: 'one' }), e(DataTable, { ...props, id: 'two' })))
  const shown = (id) => [...document.querySelectorAll(`#${id} tr[data-search-text]`)].filter((node) => node.style.display !== 'none').map((node) => node.textContent)
  await fill(document.querySelector('[data-table-search=one]'), '  BETA  ')
  await click(document.querySelector('[data-filter-group=one][data-filter=pending-login]'))
  assert.deepEqual(shown('one'), ['Beta'])
  assert.deepEqual(shown('two'), ['Alpha', 'Beta'])
  await fill(document.querySelector('[data-table-search=one]'), 'Alpha')
  assert.deepEqual(shown('one'), [])
  assert.equal(document.querySelector('#one .no-match-row').style.display, '')
  await fill(document.querySelector('[data-table-search=one]'), '')
  assert.deepEqual(shown('one'), ['Beta'])
})

test('dirty modal dismissal, route/back navigation, unload, reset, and keep-on-close', async () => {
  let open, keep, confirmations = 0, allow = false
  window.confirm = (message) => { assert.equal(message, 'Discard the details you entered?'); confirmations++; return allow }
  function Harness() {
    const [visible, setVisible] = React.useState(false)
    const [preserve, setPreserve] = React.useState(false)
    open = setVisible; keep = setPreserve
    return e(React.Fragment, null,
      e(DemoModal, { open: visible && !preserve, onClose: () => setVisible(false) }),
      e(DemoModal, { open: visible && preserve, keepOnClose: true, onClose: () => setVisible(false) }))
  }
  const router = await mount(e(Harness))
  await React.act(async () => open(true))
  await fill(document.querySelector('#demo-modal input[name=first_name]'), 'Unsaved')
  const unload = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(unload)
  assert.equal(unload.defaultPrevented, true)
  await click(button('Cancel', document.querySelector('#demo-modal')))
  assert.ok(document.querySelector('#demo-modal.active'))
  assert.equal(document.querySelector('#demo-modal input[name=first_name]').value, 'Unsaved')
  await React.act(async () => router.navigate('/dev/elsewhere'))
  assert.equal(router.state.location.pathname, '/dev/components')
  allow = true
  await click(button('Cancel', document.querySelector('#demo-modal')))
  assert.ok(!document.querySelector('#demo-modal.active'))
  await React.act(async () => open(true))
  assert.equal(document.querySelector('#demo-modal input[name=first_name]').value, '')
  assert.equal(document.querySelector('#demo-modal input[type=hidden]').value, 'request_name_address')
  assert.equal(document.querySelector('#demo-modal input[type=checkbox]').checked, true)
  await React.act(async () => { document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })) })
  assert.ok(!document.querySelector('#demo-modal.active'))
  await React.act(async () => { keep(true); open(true) })
  await fill(document.querySelector('#keep-demo-modal input[name=first_name]'), 'Retained')
  await click(button('Cancel', document.querySelector('#keep-demo-modal')))
  await React.act(async () => open(true))
  assert.equal(document.querySelector('#keep-demo-modal input[name=first_name]').value, 'Retained')
  await React.act(async () => router.navigate('/dev/forward'))
  await fill(document.querySelector('#keep-demo-modal input[name=first_name]'), 'History')
  allow = false
  await React.act(async () => router.navigate(-1))
  assert.equal(router.state.location.pathname, '/dev/forward')
  allow = true
  await React.act(async () => router.navigate(-1))
  assert.equal(router.state.location.pathname, '/dev/components')
  assert.ok(confirmations >= 6)
})

test('confirmation cancellation is inert; proceed preserves form submitter and link URL', async () => {
  let submitted = null
  const router = await mount(e(React.Fragment, null,
    e('form', { onSubmit: (event) => { event.preventDefault(); submitted = event.nativeEvent.submitter } },
      e('button', { type: 'submit', name: 'remove_photo', value: '1', 'data-confirm': 'Remove your profile photo?' }, 'Remove Photo')),
    e('a', { href: '/barangay_survey_system/app/dev/confirmed?kind=demo', 'data-confirm': 'Continue?' }, 'Confirmed link')))
  await click(button('Remove Photo'))
  assert.equal(document.querySelector('#confirm-modal-message').textContent, 'Remove your profile photo?')
  await click(button('Cancel'))
  assert.equal(submitted, null)
  await click(button('Remove Photo'))
  await click(document.querySelector('#confirm-modal-proceed'))
  assert.equal(submitted.name, 'remove_photo')
  assert.equal(submitted.value, '1')
  await click([...document.querySelectorAll('a')].find((node) => node.textContent === 'Confirmed link'))
  await click(document.querySelector('#confirm-modal-proceed'))
  assert.equal(router.state.location.pathname + router.state.location.search, '/dev/confirmed?kind=demo')
})

test('eligible alerts become toasts at 6000ms, remove after 220ms; modal/info alerts remain inline', async () => {
  const callbacks = new Map(), previousSet = window.setTimeout, previousClear = window.clearTimeout
  window.setTimeout = (callback, delay) => { callbacks.set(delay, [...(callbacks.get(delay) || []), callback]); return delay }
  window.clearTimeout = () => {}
  try {
    await mount(e('div', { className: 'container' }, e(Alert, { type: 'success' }, e('strong', null, 'Saved')),
      e(Alert, { type: 'info' }, 'Info'), e('div', { className: 'modal-overlay' }, e(Alert, { type: 'error' }, 'Validation')),
      e(Toast, { type: 'error' }, 'Another toast')))
    assert.equal(document.querySelectorAll('.toast-container').length, 1)
    assert.equal(document.querySelectorAll('.toast').length, 2)
    assert.equal(document.querySelector('.toast-message strong').textContent, 'Saved')
    assert.equal(document.querySelectorAll('.alert').length, 2)
    assert.ok(callbacks.has(6000) && callbacks.has(6220))
    await React.act(async () => callbacks.get(6000).forEach((callback) => callback()))
    assert.equal(document.querySelector('.toast-error').style.opacity, '0')
    await React.act(async () => callbacks.get(6220).forEach((callback) => callback()))
    assert.equal(document.querySelectorAll('.toast-error').length, 0)
  } finally { window.setTimeout = previousSet; window.clearTimeout = previousClear }
})

test('portal shells preserve navigation, admin counts, menu/avatar fallback, and drawer dismissal', async () => {
  const { default: Layout } = await server.ssrLoadModule('/src/components/layout/PortalLayout.jsx')
  const { AuthContext } = await server.ssrLoadModule('/src/context/authContext.js')
  const user = { account_type: 'staff', role: 'admin', display_name: 'Demo Person', initial: 'D', photo: 'uploads/missing.png',
    portal_label: 'Admin Portal', show_sidebar: true, is_first_login: false, profile_completed: true, pending_deletions: 3, archived_accounts: 2 }
  const render = (identity) => e(AuthContext.Provider, { value: { user: identity } }, e(Layout, { activePath: 'survey-management' }, 'Demo content'))
  await mount(render(user))
  assert.deepEqual([...document.querySelectorAll('.nav-count')].map((node) => node.textContent), ['3', '2'])
  assert.deepEqual([...document.querySelectorAll('.sidebar-section-label')].map((node) => node.textContent), ['Surveys', 'People'])
  assert.equal(document.querySelector('.nav-item.active').textContent, 'Surveys')
  await React.act(async () => document.querySelector('img.account-avatar').dispatchEvent(new Event('error')))
  assert.equal(document.querySelector('.account-avatar').textContent, 'D')
  const chip = document.getElementById('account-chip')
  await click(chip)
  assert.ok(document.querySelector('#account-menu.open'))
  assert.deepEqual([...document.querySelectorAll('#account-menu a')].map((node) => node.textContent), ['My Profile', 'Change Password', 'Logout'])
  await click(document.body)
  assert.equal(chip.getAttribute('aria-expanded'), 'false')
  await click(document.getElementById('mobile-nav-toggle'))
  assert.ok(document.body.classList.contains('nav-open'))
  assert.ok(document.querySelector('#app-sidebar.mobile-open'))
  assert.equal(document.getElementById('nav-drawer-backdrop').getAttribute('aria-hidden'), 'false')
  await click(document.getElementById('nav-drawer-backdrop'))
  assert.ok(!document.body.classList.contains('nav-open'))
  await click(document.getElementById('mobile-nav-toggle'))
  await React.act(async () => document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' })))
  assert.equal(document.activeElement.id, 'mobile-nav-toggle')
  await click(document.getElementById('mobile-nav-toggle'))
  await React.act(async () => { window.innerWidth = 1200; window.dispatchEvent(new Event('resize')) })
  assert.ok(!document.body.classList.contains('nav-open'))
  await mount(render({ ...user, role: 'staff' }))
  assert.equal(document.querySelectorAll('.nav-count').length, 0)
  assert.ok(!document.querySelector('a[href$="deleted-accounts"]'))
  await mount(render({ ...user, account_type: 'resident', role: 'resident', residency_status: 'temporary', residence_type: 'AR', residence_type_label: 'Apartment Renter' }))
  assert.deepEqual([...document.querySelectorAll('nav .nav-item')].map((node) => node.textContent), ['Dashboard', 'Available Surveys', 'Submitted Surveys', 'My Profile', 'Change Password'])
  assert.equal(document.querySelector('.sidebar-role').textContent, 'Temporary Resident')
  assert.equal(document.querySelector('.sidebar-residence-type').textContent.trim(), 'AR · Apartment Renter')
  await mount(render({ ...user, account_type: 'resident', role: 'resident', is_first_login: true }))
  assert.ok(document.querySelector('.plain-topbar'))
  assert.equal(document.querySelector('.sidebar'), null)
})
