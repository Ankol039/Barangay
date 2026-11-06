import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'

let server, React, createRoot, root, Landing, useReveal, MemoryRouter
const observers = []
const dom = new JSDOM('<!doctype html><body><div id="root"></div></body>', { url: 'http://localhost/' })
class Observer {
  observed = new Set()
  disconnected = false
  constructor(callback, options) { this.callback = callback; this.options = options; observers.push(this) }
  observe(element) { this.observed.add(element) }
  unobserve(element) { this.observed.delete(element) }
  disconnect() { this.disconnected = true; this.observed.clear() }
}
before(async () => {
  for (const key of ['window', 'document', 'HTMLElement']) globalThis[key] = dom.window[key]
  globalThis.IntersectionObserver = Observer
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  ;({ MemoryRouter } = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default)
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)),
    server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  ;({ default: Landing } = await server.ssrLoadModule('/src/pages/public/Landing.jsx'))
  ;({ default: useReveal } = await server.ssrLoadModule('/src/hooks/useReveal.js'))
})
after(async () => { await React.act(async () => root?.unmount()); await server?.close(); dom.window.close() })
const e = (...args) => React.createElement(...args)
async function mount(content) {
  await React.act(async () => root?.unmount())
  document.body.innerHTML = '<div id="root"></div>'
  root = createRoot(document.getElementById('root'))
  await React.act(async () => root.render(content))
}

test('landing preserves PHP text, anchors, SVG geometry and reveal delays; styling cleans up', async () => {
  await mount(e(MemoryRouter, null, e(Landing)))
  // DOM comparison does not need the PHP's malformed inline CSS parsed by jsdom.
  const source = await readFile(new URL('../../landing.php', import.meta.url), 'utf8')
  const php = new JSDOM(source.replace(/<style>[\s\S]*?<\/style>/, '')).window.document
  const texts = (scope) => [...scope.querySelectorAll('h1,h2,h3,h4,h5,p,.stat-number,.stat-label')]
    .map(node => node.textContent.replace(/\s+/g, ' ').trim())
  assert.deepEqual(texts(document), texts(php))
  const links = (scope) => [...scope.querySelectorAll('a')].map(node => [node.textContent, node.getAttribute('href').replace(/^\/#/, '#')])
  assert.deepEqual(links(document), links(php).map(([label, href]) => [label, href === 'login.php' ? '/login' : href]))
  const geometry = (scope) => [...scope.querySelectorAll('svg')].map(svg => [...svg.children]
    .map(node => [node.tagName, [...node.attributes].map(attr => [attr.name, attr.value])]))
  assert.deepEqual(geometry(document), geometry(php))
  const delays = (scope) => [...scope.querySelectorAll('.reveal')].map(node => node.dataset.delay)
  assert.deepEqual(delays(document), delays(php))
  assert.equal(document.querySelectorAll('.bg-icon').length, 49)
  assert.equal(document.body.className, 'landing-page')
  await React.act(async () => root.unmount())
  root = null
  assert.equal(document.body.className, '')
  assert.equal(observers.at(-1).disconnected, true)
})

test('reveal observes once, ignores offscreen entries, preserves delays and survives StrictMode cleanup', async () => {
  function Harness() {
    const scope = React.useRef(null)
    useReveal(scope)
    return e('div', { ref: scope }, e('div', { className: 'reveal', 'data-delay': '160' }))
  }
  await mount(e(React.StrictMode, null, e(Harness)))
  assert.equal(observers.at(-2).disconnected, true)
  const observer = observers.at(-1)
  assert.deepEqual(observer.options, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' })
  const target = document.querySelector('.reveal')
  observer.callback([{ target, isIntersecting: false }])
  assert.equal(target.classList.contains('is-visible'), false)
  assert.equal(observer.observed.has(target), true)
  observer.callback([{ target, isIntersecting: true }])
  assert.equal(target.classList.contains('is-visible'), true)
  assert.equal(target.style.transitionDelay, '160ms')
  assert.equal(observer.observed.has(target), false)
  await React.act(async () => root.unmount())
  root = null
  assert.equal(observer.disconnected, true)
})
