/** Run with VISUAL_TOOLS pointing to a directory containing Playwright/pngjs/pixelmatch. */
import { createRequire } from 'node:module'
import { readFile, writeFile, mkdir } from 'node:fs/promises'
import { execFileSync, spawn } from 'node:child_process'
import { resolve } from 'node:path'
import { tmpdir } from 'node:os'
import assert from 'node:assert/strict'
import { visualScenarios } from './visual-audit-scenarios.mjs'
const require = createRequire(resolve(process.env.VISUAL_TOOLS || '/tmp/barangay-visual-tools', 'package.json'))
const { chromium } = require('playwright'), { PNG } = require('pngjs')
const pixelmatch = (await import(require.resolve('pixelmatch'))).default
const root = resolve(import.meta.dirname, '../..'), php = '/Applications/XAMPP/xamppfiles/bin/php'
const fixture = resolve(root, 'docs/tests/visual-audit-fixtures.php')
const output = resolve(root, 'docs/verification/visual-audit', process.env.AUDIT_STAGE || 'baseline')
await mkdir(output, { recursive: true })
const report = [], errors = []
let browser, companion
try {
  if(!process.env.AUDIT_REUSE_FIXTURES) execFileSync(php, [fixture, 'create'], { stdio: 'pipe' })
  const state = JSON.parse(await readFile(resolve(tmpdir(), 'barangay-visual-audit.json'), 'utf8'))
  const crop = new PNG({width:480,height:320})
  for(let y=0;y<320;y++) for(let x=0;x<480;x++) {const i=(y*480+x)*4;crop.data[i]=x%256;crop.data[i+1]=y%256;crop.data[i+2]=120;crop.data[i+3]=255}
  state.cropImage = PNG.sync.write(crop)
  browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
  const contexts = {}
  for (const [role, account] of Object.entries(state.accounts)) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' })
    const response = await context.request.post('http://localhost/barangay_survey_system/api/login', { data: { identifier: account.number, password: 'VisualFixtureA1' } })
    if (!response.ok()) throw Error(`Fixture login failed: ${role}`)
    // Load the installed, unchanged Chart.js 4.4.4 distribution for PHP's CDN URL.
    await context.route(/cdn\.jsdelivr\.net\/npm\/chart\.js/, route => route.fulfill({ path: resolve(root, 'frontend/node_modules/chart.js/dist/chart.umd.js'), contentType: 'application/javascript' }))
    contexts[role] = context
  }
  contexts.public = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' })
  const confirmation='http://localhost/barangay_survey_system/api/resident/confirmation'
  assert.equal((await contexts.public.request.get(confirmation)).status(),401)
  assert.equal((await contexts.staff.request.get(confirmation)).status(),403)
  assert.equal((await contexts.resident.request.post(confirmation)).status(),405)
  assert.equal((await (await contexts.resident.request.get(confirmation)).json()).data.title,'the survey')
  assert.equal((await (await contexts.resident.request.get(`${confirmation}?survey_id=${state.answered}`)).json()).data.title,'Visual Audit Health Services')
  if(process.env.AUDIT_COMBINED) {
    const child=spawn(process.execPath,[import.meta.filename],{cwd:root,env:{...process.env,AUDIT_COMBINED:'',AUDIT_REUSE_FIXTURES:'1',AUDIT_STATES:'1',AUDIT_STAGE:'states-final'},stdio:'inherit'})
    companion=new Promise((done,reject)=>{child.once('error',reject);child.once('exit',code=>code===0?done():reject(Error(`Interaction capture exited ${code}`)))})
  }
  const cases = []
  function add(name, role, source, route = source.replaceAll('_', '-').replace('.php', ''), query = '') { cases.push({ name, role, source, route, query }) }
  add('landing','public','landing.php'); add('login','public','login.php')
  for (const page of ['dashboard','surveys','history','profile','change_password']) add(`resident-${page}`, 'resident', `resident/${page}.php`)
  for (const view of ['available','completed','assigned']) add(`resident-stat-${view}`,'resident','resident/stat_detail.php',undefined,`?view=${view}`)
  add('resident-survey-form','resident','resident/survey_form.php',undefined,`?id=${state.available}`)
  add('resident-confirmation','resident','resident/confirmation.php',undefined,`?survey_id=${state.answered}`)
  add('resident-confirmation-fallback','resident','resident/confirmation.php')
  add('resident-view-response','resident','resident/view_response.php',undefined,`?id=${state.response}`)
  for (const role of ['temporary','empty']) for (const page of ['dashboard','profile','history']) add(`${role}-${page}`,role,`resident/${page}.php`)
  for (const role of ['staff','admin']) {
    for (const page of ['dashboard','survey_management','add_survey','resident_management','user_management','complete_profile','change_password','address_requests','deletion_requests']) add(`${role}-${page}`,role,`staff/${page}.php`)
    for (const view of ['total_surveys','active_surveys','total_residents','total_responses',...(role==='admin'?['staff_accounts','deactivated_residents','deactivated_staff','recent_logins']:[])]) add(`${role}-stat-${view}`,role,'staff/stat_detail.php',undefined,`?view=${view}`)
    for (const page of ['results_dashboard','reports']) {
      add(`${role}-${page}`,role,`staff/${page}.php`,undefined,`?survey_id=${state.answered}`)
      add(`${role}-${page}-unselected`,role,`staff/${page}.php`)
      add(`${role}-${page}-empty`,role,`staff/${page}.php`,undefined,`?survey_id=${state.available}`)
    }
    add(`${role}-edit-survey`,role,'staff/edit_survey.php',undefined,`?id=${state.available}`)
    add(`${role}-questions`,role,'staff/question_management.php',undefined,`?survey_id=${state.available}`)
  }
  for (const page of ['deleted_accounts','residency_records','family_requests']) add(`admin-${page}`,'admin',`staff/${page}.php`)
  for (const filter of ['permanent','temporary']) add(`admin-residency-${filter}`,'admin','staff/residency_records.php',undefined,`?filter=${filter}`)
  add('resident-first-password','residentFirst','resident/change_password.php')
  add('staff-first-password','staffFirst','staff/change_password.php')
  add('staff-first-profile','staffProfile','staff/complete_profile.php')
  const scenarios = process.env.AUDIT_STATES ? visualScenarios(state) : cases
  const selected = process.env.AUDIT_ONLY ? scenarios.filter(c=>new RegExp(process.env.AUDIT_ONLY).test(c.name)) : scenarios
  let writing = Promise.resolve()
  async function captureTest(test) {
    const page = await contexts[test.role].newPage()
    page.setDefaultTimeout(10000)
    await page.bringToFront()
    page.on('pageerror', error => errors.push({ case: test.name, message: error.message }))
    try {
    for (const width of [1440,1024,390]) {
      const height = width===390?844:900
      await page.setViewportSize({ width, height })
      const pair = {}
      for (const version of ['php','react']) {
        const url = version==='php' ? `http://localhost/barangay_survey_system/${test.source}${test.query}` : `${process.env.AUDIT_REACT_URL || 'http://localhost:5173/barangay_survey_system/app/'}${test.route}${test.query}`
        let httpStatus
        try { httpStatus = (await page.goto(url, { waitUntil: 'networkidle' }))?.status() }
        catch(error) {
          if(version!=='php' || !test.name.includes('family_requests')) throw error
          httpStatus = (await contexts[test.role].request.get(url)).status()
          errors.push({case:test.name,width,version,kind:'baseline-unavailable',httpStatus})
        }
        // Force the initial paint before awaiting React effects in background contexts.
        await page.screenshot({animations:'disabled'})
        await page.waitForFunction(()=>![...document.querySelectorAll('p[role="status"]')].some(el=>el.textContent==='Loading…' && el.getBoundingClientRect().height>0), null, {timeout:15000, polling:50}).catch(async error=>{
          console.error(await page.locator('[role="status"]').evaluateAll(els=>els.map(el=>el.outerHTML))); throw error
        })
        await page.evaluate(()=>document.fonts.ready)
        if(test.source.endsWith('/dashboard.php')) await page.waitForTimeout(1200)
        if(test.name==='landing') {
          await page.evaluate(async()=>{ for(let y=0;y<document.body.scrollHeight;y+=600){scrollTo(0,y);await new Promise(r=>setTimeout(r,50))}scrollTo(0,0) })
          await page.waitForTimeout(1000)
        }
        if(test.action) { await test.action(page,version); await page.waitForTimeout(250) }
        // Move away from controls so pointer position cannot create accidental hover.
        if(!test.name.endsWith('hover')) await page.mouse.move(0,0)
        pair[version] = await page.evaluate(()=>{
          const describe=el=>{
            if(!el)return null
            const s=getComputedStyle(el)
            const styles=Object.fromEntries(['backgroundColor','color','borderColor','borderWidth','borderRadius','outlineColor','outlineWidth','outlineStyle','outlineOffset','boxShadow','fontFamily','fontSize','fontWeight','lineHeight','textDecorationLine','transform','padding','gap'].map(key=>[key,s[key]]))
            return {tag:el.tagName,cls:el.className,text:el.textContent.trim(),styles}
          }
          const landmarks=[...document.querySelectorAll('h1,h2,h3,.page-head,.page-actions-row,.card,.db-panel,.db-strip,.stat-card,.table-toolbar,table,.site-footer,.auth-split,.hero,.checklist-section,.cta-band,.modal-overlay.active .modal-box')].filter(el=>el.getBoundingClientRect().height>0).map(el=>{
            const b=el.getBoundingClientRect(),s=getComputedStyle(el)
            return {tag:el.tagName,cls:el.className,text:el.matches('h1,h2,h3')?el.textContent.trim():undefined,x:b.x,y:b.y,w:b.width,h:b.height,color:s.color,background:s.backgroundColor,padding:s.padding,margin:s.margin}
          })
          return {url:location.href,height:document.documentElement.scrollHeight,width:document.documentElement.scrollWidth,title:document.title,landmarks,text:document.querySelector('.container')?.innerText||document.body.innerText,
            focused:describe(document.activeElement),hovered:describe([...document.querySelectorAll(':hover')].at(-1)?.closest('a,button,input,label'))}
        })
        pair[version].httpStatus = httpStatus
        const filename=`${test.name}-${width}-${version}.png`
        await page.screenshot({path:resolve(output,filename),fullPage:!test.viewportOnly,animations:'disabled'})
        pair[version].screenshot=filename
      }
      const a=PNG.sync.read(await readFile(resolve(output,pair.php.screenshot))),b=PNG.sync.read(await readFile(resolve(output,pair.react.screenshot)))
      const w=Math.max(a.width,b.width),h=Math.max(a.height,b.height),pa=new PNG({width:w,height:h}),pb=new PNG({width:w,height:h}),diff=new PNG({width:w,height:h})
      pa.data.fill(255);pb.data.fill(255);PNG.bitblt(a,pa,0,0,a.width,a.height,0,0);PNG.bitblt(b,pb,0,0,b.width,b.height,0,0)
      const pixels=pixelmatch(pa.data,pb.data,diff.data,w,h,{threshold:.15})
      if(pixels) await writeFile(resolve(output,`${test.name}-${width}-diff.png`),PNG.sync.write(diff))
      const comparison={name:test.name,role:test.role,width,pixels,percent:Number((100*pixels/(w*h)).toFixed(4)),...pair}
      report.push(comparison)
      writing = writing.then(()=>writeFile(resolve(output,'report.json'),JSON.stringify({report,errors},null,2)))
      await writing
      console.log(`${test.name} ${width}: ${comparison.percent}% (${pair.php.height}/${pair.react.height}px)`)
    }
    } catch(error) {
      errors.push({case:test.name,message:error.message})
      console.error(`FAILED ${test.name}: ${error.message.split('\n')[0]}`)
      await page.screenshot({path:resolve(output,`${test.name}-failure.png`)}).catch(()=>{})
      writing = writing.then(()=>writeFile(resolve(output,'report.json'),JSON.stringify({report,errors},null,2)))
      await writing
    } finally {await page.close()}
  }
  const concurrency = process.env.AUDIT_STATES ? 1 : Math.min(3, Number(process.env.AUDIT_CONCURRENCY || 3))
  for(let index=0;index<selected.length;index+=concurrency) await Promise.all(selected.slice(index,index+concurrency).map(captureTest))
  await writing
} finally {
  try { await companion }
  finally {
    await browser?.close()
    if(!process.env.AUDIT_REUSE_FIXTURES) execFileSync(php,[fixture,'clean'],{stdio:'inherit'})
  }
}
