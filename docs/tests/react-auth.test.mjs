import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { authDestination, protectedDestination } from '../../frontend/src/routes/authRedirect.js'

const clientSource = await readFile(new URL('../../frontend/src/api/client.js', import.meta.url), 'utf8')
async function client(dev) {
  const source = clientSource.replace('import.meta.env.DEV', JSON.stringify(dev))
    .replace('import.meta.env.BASE_URL', JSON.stringify('/barangay_survey_system/app/'))
  return import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
}

test('API transport preserves cookies, JSON and server errors in dev and deployed paths', async () => {
  const original = globalThis.fetch
  try {
    for (const dev of [true, false]) {
      const { apiRequest, ApiError } = await client(dev)
      let url, options
      globalThis.fetch = async (...args) => {
        ;[url, options] = args
        return Response.json({ ok: true, data: { user: null } })
      }
      await apiRequest('/login', { method: 'POST', credentials: 'omit', body: { identifier: ' fixture ', password: ' synthetic ' } })
      assert.equal(url, `${dev ? '/api' : '/barangay_survey_system/api'}/login`)
      assert.equal(options.credentials, 'include')
      assert.equal(options.cache, 'no-store')
      assert.equal(options.headers['Content-Type'], 'application/json')
      assert.deepEqual(JSON.parse(options.body), { identifier: ' fixture ', password: ' synthetic ' })
      globalThis.fetch = async () => Response.json({ ok: false, message: 'Current password is incorrect.' }, { status: 422 })
      await assert.rejects(apiRequest('/change-password'), (error) => error instanceof ApiError && error.status === 422 && error.message === 'Current password is incorrect.')
      globalThis.fetch = async () => new Response('<html>outage</html>', { status: 503 })
      await assert.rejects(apiRequest('/session'), { status: 503, message: 'The server returned an unexpected response. Please try again.' })
      globalThis.fetch = async () => { throw new TypeError('private transport details') }
      await assert.rejects(apiRequest('/session'), { status: 0, message: 'Unable to connect. Please try again.' })
      globalThis.fetch = async () => { throw new DOMException('aborted', 'AbortError') }
      await assert.rejects(apiRequest('/session'), { name: 'AbortError' })
    }
  } finally { globalThis.fetch = original }
})

test('password checklist, match hint and success modal render the PHP states', async () => {
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  const React = await import('../../frontend/node_modules/react/index.js')
  const { renderToStaticMarkup: render } = await import('../../frontend/node_modules/react-dom/server.node.js')
  const { default: { StaticRouter } } = await import('../../frontend/node_modules/react-router-dom/dist/index.js')
  const server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  try {
    const { default: Rules } = await server.ssrLoadModule('/src/components/auth/PasswordRules.jsx')
    const { default: Hint } = await server.ssrLoadModule('/src/components/auth/PasswordMatchHint.jsx')
    const { default: Modal } = await server.ssrLoadModule('/src/components/auth/PasswordSuccessModal.jsx')
    const rules = [['length', 'At least 8 characters long'], ['upper', 'One uppercase letter (A-Z)'],
      ['lower', 'One lowercase letter (a-z)'], ['number', 'One number (0-9)'], ['space', 'No spaces']]
      .map(([key, label]) => ({ key, label }))
    for (const [value, count] of [['', 0], ['a', 2], ['SyntheticA1', 5], ['Synthetic A1', 4]]) {
      const html = render(React.createElement(Rules, { rules, value }))
      assert.equal((html.match(/class="rule-met"/g) || []).length, count)
      for (const { label } of rules) assert.ok(html.includes(label))
    }
    assert.ok(!render(React.createElement(Hint, { password: 'a', confirmation: '' })).includes('Passwords'))
    assert.ok(render(React.createElement(Hint, { password: 'a', confirmation: 'a' })).includes('Passwords match.'))
    assert.ok(render(React.createElement(Hint, { password: 'a', confirmation: 'b' })).includes('Passwords do not match yet.'))
    const html = render(React.createElement(StaticRouter, { location: '/resident/change-password' },
      React.createElement(Modal, { portal: 'resident', message: 'API supplied confirmation.', onClose() {} })))
    for (const text of ['Password Changed', 'API supplied confirmation.', 'Go to Dashboard', 'Stay Here', 'aria-label="Close"', 'href="/resident/dashboard"']) {
      assert.ok(html.includes(text))
    }
  } finally { await server.close() }
})

test('portal boundaries and onboarding precedence cover direct route entry', () => {
  const resident = { account_type: 'resident', role: 'resident', is_first_login: false, profile_completed: true }
  const staff = { ...resident, account_type: 'staff', role: 'staff' }
  for (const portal of ['resident', 'staff']) {
    assert.equal(protectedDestination(null, portal, `/${portal}/dashboard`), '/landing')
    assert.equal(protectedDestination(portal === 'resident' ? staff : resident, portal, `/${portal}/dashboard`), portal === 'resident' ? '/staff/dashboard' : '/resident/dashboard')
  }
  assert.equal(authDestination({ ...resident, is_first_login: true }), '/resident/change-password')
  assert.equal(protectedDestination({ ...resident, is_first_login: true }, 'resident', '/resident/surveys'), '/resident/change-password')
  assert.equal(protectedDestination({ ...resident, is_first_login: true }, 'resident', '/resident/change-password'), null)
  for (const role of ['staff', 'admin']) {
    const first = { ...staff, role, is_first_login: true, profile_completed: false }
    assert.equal(authDestination(first), '/staff/change-password')
    assert.equal(protectedDestination(first, 'staff', '/staff/deleted-accounts'), '/staff/change-password')
    const profile = { ...first, is_first_login: false }
    assert.equal(authDestination(profile), '/staff/complete-profile')
    assert.equal(protectedDestination(profile, 'staff', '/staff/dashboard'), '/staff/complete-profile')
    for (const path of ['/staff/change-password', '/staff/complete-profile', '/staff/logout']) {
      assert.equal(protectedDestination(first, 'staff', path), null)
      assert.equal(protectedDestination(profile, 'staff', path), null)
    }
    assert.equal(protectedDestination({ ...profile, profile_completed: true }, 'staff', '/staff/dashboard'), null)
  }
})
