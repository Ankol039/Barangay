/** Check the compiled build through Apache; no fixture accounts or stored data. */
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { writeFile } from 'node:fs/promises'
const require = createRequire('/tmp/barangay-visual-tools/package.json')
const { chromium } = require('playwright')
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const page = await browser.newPage(), diagnostics = [], responses = [], validationMessages = []
page.on('pageerror', error => diagnostics.push(error.message))
page.on('console', message => {
  if (!['warning', 'error'].includes(message.type())) return
  // The empty login deliberately requests the original 422 validation outcome.
  if (/server responded with a status of 422/.test(message.text())) validationMessages.push(message.text())
  else diagnostics.push(message.text())
})
page.on('requestfailed', request => diagnostics.push(request.url()))
page.on('response', response => {
  if (response.url().includes('barangay_survey_system')) responses.push({ url: response.url(), status: response.status() })
})
try {
  const base = 'http://localhost/barangay_survey_system/app/'
  await page.goto(base, { waitUntil: 'networkidle' })
  await page.getByRole('link', { name: 'Sign In', exact: true }).click()
  await page.waitForURL(base + 'login')
  const validation = page.waitForResponse(response => response.url().endsWith('/api/login') && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'Log In', exact: true }).click()
  assert.equal((await validation).status(), 422)
  await page.getByRole('alert').filter({ hasText: 'Please enter your username or Resident Number and password.' }).waitFor()
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('heading', { name: 'Sign in', exact: true }).waitFor()
  await page.goto(base + 'staff/dashboard', { waitUntil: 'networkidle' })
  assert.equal(page.url(), base + 'landing')
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(base + 'login', { waitUntil: 'networkidle' })
  await page.getByRole('tab', { name: 'Admin & Staff', exact: true }).click()
  await page.getByLabel('Username / Staff Number').waitFor()
  await page.screenshot({ path: 'docs/verification/rubric/apache-login-mobile.png', fullPage: true })
  assert.deepEqual(diagnostics, [])
  assert.equal(responses.filter(response => response.status >= 400).length, 1)
  assert(responses.some(response => response.url.endsWith('/api/session') && response.status === 200))
  assert(responses.some(response => response.url.includes('/app/assets/')))
  await writeFile('docs/verification/rubric/apache-public.json', JSON.stringify({ url: base, checks: 7, diagnostics, expectedValidationStatus: 422, validationMessages, responses }, null, 2) + '\n')
  console.log('PASS: built landing, production session API, sign-in/validation, direct refresh, guard, mobile tabs and assets; no unexpected diagnostics.')
} finally { await browser.close() }
