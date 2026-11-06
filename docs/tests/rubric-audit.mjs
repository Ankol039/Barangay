/** Structural rubric checks: run from the project root with Node. */
import assert from 'node:assert/strict'
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve, relative } from 'node:path'
import { parseSync } from '../../frontend/node_modules/rolldown/dist/experimental-index.mjs'
import { residentRoutes, staffRoutes } from '../../frontend/src/routes/routeDefinitions.js'
const root = resolve(import.meta.dirname, '../..'), src = resolve(root, 'frontend/src')
mkdirSync(resolve(root, 'docs/verification/rubric'), { recursive: true })
const walk = dir => readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory() ? walk(resolve(dir, entry.name)) : [resolve(dir, entry.name)])
const files = walk(src).filter(file => /\.(jsx|js)$/.test(file))
function visit(node, callback) {
  if (!node || typeof node !== 'object') return
  if (node.type) callback(node)
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach(child => visit(child, callback))
    else if (value && typeof value === 'object') visit(value, callback)
  }
}
const dependencies = new Map(), components = [], maps = [], failures = [], duplicates = new Map()
const stripLocations = node => JSON.parse(JSON.stringify(node, (key, value) => ['start', 'end', 'raw'].includes(key) ? undefined : value))
for (const file of files) {
  const source = readFileSync(file, 'utf8'), parsed = parseSync(file, source)
  assert.equal(parsed.errors.length, 0, file)
  const imports = [], contracts = new Map()
  visit(parsed.program, node => {
    if (node.type === 'VariableDeclarator' && ['residentScreens', 'staffScreens'].includes(node.id.name)) {
      const routes = node.id.name === 'residentScreens' ? residentRoutes : staffRoutes
      assert.deepEqual(node.init.properties.map(prop => prop.key.value).sort(), routes.map(route => route.path).sort(), 'Every portal route must have a concrete screen')
    }
    if (node.type === 'AssignmentExpression' && node.left.type === 'MemberExpression' && node.left.property.name === 'propTypes') {
      contracts.set(node.left.object.name, node.right.type === 'ObjectExpression' ? node.right.properties.map(prop => prop.key.name) : '*')
    }
    const path = node.type === 'ImportDeclaration' ? node.source.value : node.type === 'ImportExpression' && node.source.type === 'Literal' ? node.source.value : ''
    if (path.startsWith('.')) imports.push(resolve(dirname(file), path))
    if ((node.type === 'FunctionDeclaration' || node.type === 'VariableDeclarator' && node.init?.type === 'ArrowFunctionExpression') && /^[A-Z]/.test(node.id?.name)) {
      const fn = node.init || node, lines = source.slice(fn.start, fn.end).split('\n').length
      const props = fn.params[0]?.type === 'ObjectPattern' ? fn.params[0].properties.filter(prop => prop.type === 'Property').map(prop => prop.key.name) : fn.params.length ? ['*'] : []
      components.push({ file: relative(root, file), name: node.id.name, lines, props })
    }
    if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression' && node.callee.property.name === 'map') {
      const callback = node.arguments[0], returned = []
      if (['ArrowFunctionExpression', 'FunctionExpression'].includes(callback?.type)) {
        if (callback.body.type === 'BlockStatement') {
          for (const statement of callback.body.body) if (statement.type === 'ReturnStatement') returned.push(statement.argument)
        } else returned.push(callback.body)
      }
      for (const jsx of returned) {
        const roots = jsx?.type === 'ConditionalExpression' ? [jsx.consequent, jsx.alternate] : [jsx]
        for (const element of roots) if (['JSXElement', 'JSXFragment'].includes(element?.type)) {
          const keyed = element.type === 'JSXElement' && element.openingElement.attributes.some(attr => attr.type === 'JSXAttribute' && attr.name.name === 'key')
          maps.push({ file: relative(root, file), line: source.slice(0, element.start).split('\n').length, keyed })
          if (!keyed) failures.push(`Missing list key: ${relative(root, file)}:${maps.at(-1).line}`)
        }
      }
    }
    if (node.type === 'JSXElement') {
      const normalized = JSON.stringify(stripLocations(node))
      if ((normalized.match(/"type":"JSXElement"/g) || []).length >= 5) {
        const entries = duplicates.get(normalized) || []
        entries.push({ file: relative(root, file), line: source.slice(0, node.start).split('\n').length })
        duplicates.set(normalized, entries)
      }
    }
  })
  dependencies.set(file, imports)
  for (const component of components.filter(component => resolve(root, component.file) === file)) {
    if (component.lines > 200) failures.push(`Over 200 lines: ${component.file} ${component.name}`)
    const contract = contracts.get(component.name)
    for (const prop of component.props) if (contract !== '*' && !contract?.includes(prop)) failures.push(`Missing prop validation: ${component.file} ${component.name}.${prop}`)
  }
}
const reachable = new Set()
function follow(file) { if (reachable.has(file)) return; reachable.add(file); (dependencies.get(file) || []).forEach(follow) }
follow(resolve(src, 'main.jsx'))
const unused = files.filter(file => !reachable.has(file)).map(file => relative(root, file))
failures.push(...unused.map(file => `Unreachable module: ${file}`))
const repeatedMarkup = [...duplicates.values()].filter(entries => entries.length > 1)
const report = { date: '2026-11-06', modules: files.length, components: components.length, propContracts: components.filter(component => component.props.length).length,
  largestComponent: components.reduce((a, b) => a.lines > b.lines ? a : b), keyedLists: maps.length, unused, repeatedMarkup, failures }
writeFileSync(resolve(root, 'docs/verification/rubric/structure.json'), JSON.stringify(report, null, 2) + '\n')
console.log(JSON.stringify(report, null, 2))
assert.deepEqual(failures, [])
assert.deepEqual(repeatedMarkup, [], 'Review and extract duplicated composed JSX')
