/** Real-browser PHP/React navigation and control audit. Only registered synthetic records are mutated. */
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { tmpdir } from 'node:os'
import { visualScenarios } from './visual-audit-scenarios.mjs'
import { residentRoutes, staffRoutes } from '../../frontend/src/routes/routeDefinitions.js'
const require = createRequire('/tmp/barangay-visual-tools/package.json')
const { chromium } = require('playwright'), { PNG } = require('pngjs')
const root = resolve(import.meta.dirname, '../..'), output = resolve(root, 'docs/verification/parity')
const php = '/Applications/XAMPP/xamppfiles/bin/php', fixture = resolve(root, 'docs/tests/visual-audit-fixtures.php')
const origins = { php: 'http://localhost/barangay_survey_system/', react: process.env.PARITY_REACT_URL || 'http://localhost:5173/barangay_survey_system/app/' }
const results = [], diagnostics = [], inventory = []
let browser, state
await mkdir(output, { recursive: true })
async function check(name, run) {
  if (process.env.PARITY_ONLY && !new RegExp(process.env.PARITY_ONLY).test(name)) return
  try { await run(); results.push({ name, status: 'PASS' }) }
  catch (error) { results.push({ name, status: 'FAIL', error: error.message }); console.log(`FAIL ${name}: ${error.message.split('\n')[0]}`) }
}
try {
  execFileSync(php, [fixture, 'create'], { stdio: 'pipe' })
  state = JSON.parse(await readFile(resolve(tmpdir(), 'barangay-visual-audit.json'), 'utf8'))
  const png = new PNG({ width: 480, height: 320 }); png.data.fill(160); state.cropImage = PNG.sync.write(png)
  browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
  const contexts = {}
  for (const [role, account] of Object.entries(state.accounts)) {
    const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 900 } })
    const login = await context.request.post(origins.php + 'api/login', { data: { identifier: account.number, password: 'VisualFixtureA1' } })
    assert.equal(login.status(), 200, `Login ${role}`)
    // PHP depends on a remote CDN. Use the exact installed 4.4.4 distribution for reproducibility.
    await context.route(/cdn\.jsdelivr\.net\/npm\/chart\.js/, route => route.fulfill({ path: resolve(root, 'frontend/node_modules/chart.js/dist/chart.umd.js'), contentType: 'application/javascript' }))
    contexts[role] = context
  }
  contexts.public = await browser.newContext({ acceptDownloads: true })
  async function pageFor(role, name, version) {
    const page = await contexts[role].newPage(); page.setDefaultTimeout(12000)
    page.on('pageerror', error => diagnostics.push({ name, version, kind: 'exception', message: error.message }))
    page.on('console', msg => { if (['error', 'warning'].includes(msg.type())) diagnostics.push({ name, version, kind: msg.type(), message: msg.text() }) })
    page.on('response', response => { if (response.status() >= 400) diagnostics.push({ name, version, kind: 'http', status: response.status(), url: response.url() }) })
    page.on('requestfailed', request => { if (!request.failure()?.errorText.includes('ERR_ABORTED')) diagnostics.push({ name, version, kind: 'network', url: request.url(), message: request.failure()?.errorText }) })
    return page
  }
  async function ready(page, url) {
    const response = await page.goto(url, { waitUntil: 'networkidle' }); assert.equal(response.status(), 200)
    await page.waitForFunction(() => ![...document.querySelectorAll('[role="status"]')].some(el => el.textContent === 'Loading…'))
    await page.locator('h1, .container h2').first().waitFor()
    assert.equal(await page.getByText('This page is awaiting migration.', { exact: true }).count(), 0)
  }
  const routeCases = [['public', 'landing.php', '/landing', ''], ['public', 'login.php', '/login', '']]
  for (const role of ['resident', 'staff', 'admin']) for (const entry of role === 'resident' ? residentRoutes : staffRoutes) {
    if (entry.adminOnly && role !== 'admin') continue
    if (entry.path === '/staff/family-requests') continue
    let query = ''
    if (entry.path.endsWith('/stat-detail')) query = '?view=' + (role === 'resident' ? 'available' : 'total_surveys')
    if (entry.path.endsWith('/survey-form') || entry.path.endsWith('/edit-survey')) query = `?id=${state.available}`
    if (entry.path.endsWith('/question-management')) query = `?survey_id=${state.available}`
    if (entry.path.endsWith('/confirmation') || entry.path.endsWith('/results-dashboard') || entry.path.endsWith('/reports')) query = `?survey_id=${state.answered}`
    if (entry.path.endsWith('/view-response')) query = `?id=${state.response}`
    routeCases.push([role, entry.source, entry.path, query])
  }
  const navigationChecked = new Set(), linkChecks = new Set()
  for (const [role, source, route, query] of routeCases) await check(`${role} route ${route}`, async () => {
    for (const version of ['php', 'react']) {
      const page = await pageFor(role, route, version)
      try {
        await ready(page, origins[version] + (version === 'php' ? source : route.slice(1)) + query)
        const controls = await page.evaluate(() => {
          const visible = el => el.getBoundingClientRect().height > 0 && getComputedStyle(el).visibility !== 'hidden'
          const label = el => (el.getAttribute('aria-label') || el.textContent || el.value || '').replace(/\s+/g, ' ').trim()
          return [...document.querySelectorAll('a,button,form,.modal-overlay')].map(el => ({ type: el.matches('.modal-overlay') ? 'modal' : el.tagName.toLowerCase(), id: el.id, label: el.tagName === 'FORM' || el.matches('.modal-overlay') ? '' : label(el), href: el.getAttribute('href'), method: el.getAttribute('method'), visible: visible(el) }))
        })
        inventory.push({ role, source, route, version, controls })
        if (version === 'react') {
          const links = await page.locator('a[href]').evaluateAll(els => els.filter(el => el.getBoundingClientRect().height && !el.closest('#app-sidebar,#account-menu')).map(el => ({ href: el.href, label: el.textContent.trim() })))
          const initialURL = page.url()
          for (const link of links) {
            const target = new URL(link.href)
            if (!link.href.startsWith(origins.react) || target.hash || target.pathname.endsWith('/login')) continue
            const key = role + ':' + route + ':' + target.pathname + '?' + [...target.searchParams.keys()].join(',') + (target.searchParams.get('view') || '') + (target.searchParams.get('filter') || '')
            if (linkChecks.has(key)) continue
            linkChecks.add(key)
            const locator = page.locator(`a[href="${target.pathname}${target.search}"]`).filter({ visible: true }).first()
            await locator.click(); await page.waitForLoadState('networkidle')
            await page.waitForFunction(() => ![...document.querySelectorAll('[role="status"]')].some(el => el.textContent === 'Loading…'))
            assert.equal(await page.getByText('This page is awaiting migration.', { exact: true }).count(), 0, link.label)
            assert.equal(await page.getByRole('heading', { name: 'Page Not Found', exact: true }).count(), 0, link.label)
            await ready(page, initialURL)
          }
        }
        if (version === 'react' && !navigationChecked.has(role)) {
          const nav = await page.locator('.nav-item').evaluateAll(els => els.map(el => ({ href: el.href, label: el.textContent.trim() })))
          // Every sidebar destination is exercised by actual link activation.
          for (const link of nav) {
            await page.locator(`.nav-item[href="${new URL(link.href).pathname}"]`).click()
            await page.waitForURL(link.href); await page.waitForLoadState('networkidle')
            assert.equal(await page.getByText('This page is awaiting migration.', { exact: true }).count(), 0)
          }
          if (nav.length) navigationChecked.add(role)
        }
      } finally { await page.close() }
    }
  })
  for (const scenario of visualScenarios(state)) await check(`control ${scenario.name}`, async () => {
    for (const version of ['php', 'react']) {
      const page = await pageFor(scenario.role, scenario.name, version)
      try {
        if (scenario.name.includes('nav-')) await page.setViewportSize({ width: 390, height: 844 })
        await ready(page, origins[version] + (version === 'php' ? scenario.source : scenario.route) + (scenario.query || ''))
        await scenario.action(page, version)
        const active = page.locator('.modal-overlay.active')
        if (await active.count()) {
          const id = await active.first().getAttribute('id')
          await page.screenshot({ path: resolve(output, `${scenario.name}-${version}.png`) })
          // Shared close behavior must work in the actual browser, including dirty guards.
          page.on('dialog', dialog => dialog.accept())
          await page.keyboard.press('Escape')
          await page.locator(`#${id}.active`).waitFor({ state: 'hidden' })
        }
      } finally { await page.close() }
    }
  })
  for (const role of ['resident', 'staff', 'admin']) await check(`${role} menu/drawer dismissal`, async () => {
    for (const version of ['php', 'react']) {
      const page = await pageFor(role, 'menu-drawer', version)
      try {
        await page.setViewportSize({ width: 390, height: 844 })
        await ready(page, origins[version] + (role === 'resident' ? 'resident/' : 'staff/') + (version === 'php' ? 'dashboard.php' : 'dashboard'))
        for (const close of ['Escape', 'button', 'backdrop', 'resize', 'link']) {
          await page.locator('#mobile-nav-toggle').click(); await page.locator('#app-sidebar.mobile-open').waitFor()
          if (close === 'Escape') await page.keyboard.press('Escape')
          if (close === 'button') await page.locator('.mobile-nav-close').click()
          if (close === 'backdrop') await page.locator('#nav-drawer-backdrop').click({ position: { x: 380, y: 600 } })
          if (close === 'resize') await page.setViewportSize({ width: 1440, height: 900 })
          if (close === 'link') await page.locator('.nav-item').first().click()
          await page.locator('#app-sidebar.mobile-open').waitFor({ state: 'hidden' })
          if (close === 'Escape' || close === 'button') assert.equal(await page.locator('#mobile-nav-toggle').evaluate(el => el === document.activeElement), true)
          await page.setViewportSize({ width: 390, height: 844 })
        }
        await page.locator('#account-chip').click(); await page.locator('#account-menu.open').waitFor()
        await page.keyboard.press('Escape'); await page.locator('#account-menu.open').waitFor({ state: 'hidden' })
        await page.locator('#account-chip').click(); await page.mouse.click(10, 800); await page.locator('#account-menu.open').waitFor({ state: 'hidden' })
      } finally { await page.close() }
    }
  })
  for (const role of ['resident', 'staff', 'admin']) await check(`${role} CSV download and print`, async () => {
    for (const version of ['php', 'react']) {
      const page = await pageFor(role, 'download-print', version)
      try {
        await page.addInitScript(() => { window.__prints = 0; window.print = () => window.__prints++ })
        const path = role === 'resident' ? `resident/${version === 'php' ? 'view_response.php' : 'view-response'}?id=${state.response}` : `staff/${version === 'php' ? 'reports.php' : 'reports'}?survey_id=${state.answered}`
        await ready(page, origins[version] + path)
        await page.getByRole('button', { name: /Print/i }).click(); assert.equal(await page.evaluate(() => window.__prints), 1)
        await page.emulateMedia({ media: 'print' }); assert.equal(await page.locator('.sidebar').isVisible(), false); await page.emulateMedia({ media: 'screen' })
        const downloadPromise = page.waitForEvent('download')
        await page.getByRole('link', { name: /CSV/i }).click()
        const download = await downloadPromise; assert.equal(await download.failure(), null)
        const text = await readFile(await download.path(), 'utf8'); assert.ok(text.includes('Visual Audit Health Services')); assert.ok(text.includes('Question') || text.includes('Option'))
        inventory.push({ role, version, download: download.suggestedFilename(), csv: text })
      } finally { await page.close() }
    }
  })
  for (const role of ['resident', 'staff', 'admin']) await check(`${role} login/password/logout form`, async () => {
    for (const version of ['php', 'react']) {
      const context = await browser.newContext()
      await context.route(/cdn\.jsdelivr\.net\/npm\/chart\.js/, route => route.fulfill({ path: resolve(root, 'frontend/node_modules/chart.js/dist/chart.umd.js'), contentType: 'application/javascript' }))
      const page = await context.newPage(); page.setDefaultTimeout(12000)
      page.on('pageerror', error => diagnostics.push({ name: `${role}-login-password-logout`, version, kind: 'exception', message: error.message }))
      page.on('console', msg => { if (['error', 'warning'].includes(msg.type())) diagnostics.push({ name: `${role}-login-password-logout`, version, kind: msg.type(), message: msg.text() }) })
      page.on('response', response => { if (response.status() >= 400) diagnostics.push({ name: `${role}-login-password-logout`, version, kind: 'http', status: response.status(), url: response.url() }) })
      page.on('requestfailed', request => { if (!request.failure()?.errorText.includes('ERR_ABORTED')) diagnostics.push({ name: `${role}-login-password-logout`, version, kind: 'network', url: request.url(), message: request.failure()?.errorText }) })
      const account = state.accounts[role]
      try {
        await page.goto(origins[version] + (version === 'php' ? 'login.php' : 'login'), { waitUntil: 'networkidle' })
        if (version === 'react' && role !== 'resident') await page.getByRole('tab', { name: 'Admin & Staff' }).click()
        await page.locator('#identifier').fill(account.number)
        await page.locator('#password').fill('VisualFixtureA1')
        await page.getByRole('button', { name: 'Show password', exact: true }).click()
        assert.equal(await page.locator('#password').getAttribute('type'), 'text')
        await page.getByRole('button', { name: 'Hide password', exact: true }).click()
        await page.getByRole('button', { name: 'Log In', exact: true }).click()
        await page.waitForURL(/dashboard/); await page.waitForLoadState('networkidle')
        await page.locator('#account-chip').click()
        await page.locator('#account-menu').getByRole('link', { name: 'My Profile', exact: true }).click()
        await page.waitForURL(/profile/); await page.waitForLoadState('networkidle')
        await page.goto(origins[version] + (role === 'resident' ? 'resident/' : 'staff/') + (version === 'php' ? 'change_password.php' : 'change-password'), { waitUntil: 'networkidle' })
        await page.locator('#current_password').fill('VisualFixtureA1')
        await page.locator('#new_password').fill('BrowserChangedA1')
        await page.locator('#confirm_password').fill('BrowserChangedA1')
        await page.getByRole('button', { name: 'Update Password', exact: true }).click()
        await page.locator('#password-success-modal.active').waitFor()
        await page.getByRole('button', { name: 'Stay Here', exact: true }).click()
        assert.equal(await page.locator('#password-success-modal.active').count(), 0)
        const reset = await context.request.post(origins.php + 'api/change-password', { data: { current_password: 'BrowserChangedA1', new_password: 'VisualFixtureA1', confirm_password: 'VisualFixtureA1' } })
        assert.equal(reset.status(), 200)
        await page.locator('#account-chip').click()
        await page.getByRole('link', { name: 'Logout', exact: true }).click()
        await page.waitForURL(/landing/)
        const session = await context.request.get(origins.php + 'api/session')
        assert.equal((await session.json()).data.authenticated, false)
      } finally { await context.close() }
    }
  })
  await check('landing anchors, Sign In and contact links', async () => {
    for (const version of ['php', 'react']) {
      const page = await pageFor('public', 'landing-links', version)
      try {
        await ready(page, origins[version] + (version === 'php' ? 'landing.php' : 'landing'))
        const anchors = page.locator('header a, footer a')
        const links = await anchors.evaluateAll(els => els.map((el, index) => ({ index, hash: new URL(el.href).hash })).filter(link => link.hash.length > 1))
        assert.equal(links.length, 7, 'All header/footer section links are included')
        for (const link of links) {
          await anchors.nth(link.index).click()
          assert.equal(new URL(page.url()).hash, link.hash)
          assert.equal(await page.locator(link.hash).count(), 1)
        }
        assert.deepEqual(await page.locator('a[href^="mailto:"],a[href^="tel:"]').evaluateAll(els => els.map(el => el.getAttribute('href'))), ['mailto:jobs@barangayhealth.example', 'tel:+63255550123', 'mailto:info@barangayhealth.example'])
        await page.getByRole('link', { name: 'Sign In', exact: true }).click(); await page.waitForURL(/login/)
      } finally { await page.close() }
    }
  })
  for (const role of ['staff', 'admin']) {
    const response = await contexts[role].request.get(origins.php + 'staff/family_requests.php')
    await check(`${role} legacy family redirect`, async () => {
      assert.equal(response.status(), 500, 'Record the unavailable legacy PHP baseline')
      const page = await pageFor(role, 'legacy-family-redirect', 'react')
      try {
        await page.goto(origins.react + 'staff/family-requests', { waitUntil: 'networkidle' })
        await page.waitForURL(origins.react + 'staff/address-requests')
        await page.getByRole('heading', { name: 'Address Requests', exact: true }).waitFor()
        assert.equal(await page.getByText('This page is awaiting migration.', { exact: true }).count(), 0)
        if (role === 'staff') assert.equal(await page.getByRole('heading', { name: /Family & Household Requests/ }).count(), 0)
        else assert.equal(await page.getByRole('heading', { name: /Family & Household Requests/ }).count(), 1)
      } finally { await page.close() }
    })
  }
  for (const role of ['public', 'resident', 'staff', 'admin']) await check(`${role} compatibility entries`, async () => {
    const page = await pageFor(role, 'compatibility-entries', 'react')
    try {
      for (const alias of ['', 'admin', 'admin/index.php', 'resident/login', 'resident/login.php', 'staff/login', 'staff/login.php', 'staff/statistics', 'staff/statistics.php']) {
        await page.goto(origins.react + alias, { waitUntil: 'networkidle' })
        const expected = alias.startsWith('staff/statistics') ? role === 'public' ? '/landing' : role === 'resident' ? '/resident/dashboard' : '/staff/dashboard' : '/'
        assert.equal(new URL(page.url()).pathname.replace('/barangay_survey_system/app', ''), expected)
        assert.equal(await page.getByRole('heading', { name: 'Page Not Found', exact: true }).count(), 0)
      }
    } finally { await page.close() }
  })
  for (const role of ['resident', 'staff', 'admin']) await check(`${role} all stat detail links and invalid redirects`, async () => {
    const page = await pageFor(role, 'stat-details', 'react'), portal = role === 'resident' ? 'resident' : 'staff'
    try {
      const views = portal === 'resident' ? ['available', 'completed', 'assigned'] : ['total_surveys', 'active_surveys', 'total_residents', 'total_responses', ...(role === 'admin' ? ['staff_accounts', 'deactivated_residents', 'deactivated_staff', 'recent_logins'] : [])]
      for (const view of views) {
        await ready(page, origins.react + `${portal}/stat-detail?view=${view}`)
        assert.equal(new URL(page.url()).searchParams.get('view'), view)
        await page.getByRole('link', { name: /Back to Dashboard/ }).click()
        await page.waitForURL(origins.react + `${portal}/dashboard`)
      }
      for (const view of ['unknown', ...(portal === 'staff' ? ['recent_logouts', ...(role === 'staff' ? ['staff_accounts', 'deactivated_staff', 'recent_logins'] : [])] : [])]) {
        await page.goto(origins.react + `${portal}/stat-detail?view=${view}`, { waitUntil: 'networkidle' })
        assert.equal(page.url(), origins.react + `${portal}/dashboard`)
      }
    } finally { await page.close() }
  })
  await check('resident automatic print query', async () => {
    const page = await pageFor('resident', 'auto-print', 'react')
    try {
      await page.addInitScript(() => { window.__prints = 0; window.print = () => window.__prints++ })
      await ready(page, origins.react + `resident/view-response?id=${state.response}&print`)
      assert.equal(await page.evaluate(() => window.__prints), 1)
    } finally { await page.close() }
  })
  await check('console and network diagnostics', async () => {
    assert.deepEqual(diagnostics, [], 'No unexpected console warnings/errors, page exceptions, failed requests or HTTP errors')
  })
} finally {
  await browser?.close()
  if (state) execFileSync(php, [fixture, 'clean'], { stdio: 'pipe' })
  await writeFile(resolve(output, process.env.PARITY_OUTPUT || 'browser.json'), JSON.stringify({ date: '2026-11-06', results, diagnostics, inventory }, null, 2))
  console.log(`${results.filter(r => r.status === 'PASS').length} browser checks passed; ${results.filter(r => r.status === 'FAIL').length} failed; ${diagnostics.length} diagnostics.`)
}
if (results.some(r => r.status === 'FAIL')) process.exitCode = 1
