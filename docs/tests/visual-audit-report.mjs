import { readFile, writeFile, copyFile, mkdir, rm } from 'node:fs/promises'
import { resolve } from 'node:path'
import assert from 'node:assert/strict'
import { publicRoutes, residentRoutes, staffRoutes } from '../../frontend/src/routes/routeDefinitions.js'

const root=resolve(import.meta.dirname,'../verification/visual-audit')
const read=async stage=>JSON.parse(await readFile(resolve(root,stage,'report.json'),'utf8'))
const base=await read('final'),states=await read('states-final')
let recaptures=[]
try { recaptures=JSON.parse(await readFile(resolve(root,'summary.json'),'utf8')).recaptures||[] } catch(error) {if(error.code!=='ENOENT')throw error}
for(const [stage,target,reason] of [
  ['pages-patch',base,'Recaptured with stable fixture login-history data; native-select raster recheck.'],
  ['password-patch',base,'Verified the corrected contiguous staff onboarding reminder text.'],
  ['focus-patch',states,'Waited for the drawer transition and asserted that a navigation link actually received keyboard focus.'],
]) {
  let patch
  try {patch=await read(stage)} catch(error) {if(error.code==='ENOENT')continue;throw error}
  assert.equal(patch.errors.length,0,`${stage} must complete without errors`)
  for(const item of patch.report) {
    const index=target.report.findIndex(r=>r.name===item.name&&r.width===item.width)
    assert.ok(index>=0,'Recapture belongs to the full matrix')
    target.report[index]=item
    const dest=target===base?'final':'states-final'
    for(const version of ['php','react']) await copyFile(resolve(root,stage,item[version].screenshot),resolve(root,dest,item[version].screenshot))
    const diff=`${item.name}-${item.width}-diff.png`
    if(item.pixels)await copyFile(resolve(root,stage,diff),resolve(root,dest,diff))
    else await rm(resolve(root,dest,diff),{force:true})
    if(!recaptures.some(r=>r.name===item.name&&r.width===item.width&&r.reason===reason))recaptures.push({name:item.name,width:item.width,reason})
  }
}
const all=[...base.report.map(r=>({...r,stage:'final'})),...states.report.map(r=>({...r,stage:'states-final'}))]
const intentional=r=>r.name==='login'||/survey_management/.test(r.name)
const blocked=r=>r.name==='admin-family_requests'
const unexpected=all.filter(r=>r.pixels>0&&!intentional(r)&&!blocked(r))
const stateStyles=all.filter(r=>r.stage==='states-final'&&/-(focus|hover)$/.test(r.name)).map(r=>{
  const type=r.name.endsWith('focus')?'focused':'hovered',a=r.php[type],b=r.react[type]
  return {name:r.name,width:r.width,type,matched:!!a&&!!b&&JSON.stringify(a.styles)===JSON.stringify(b.styles),php:a,react:b}
})
const routes=[...publicRoutes,...residentRoutes,...staffRoutes]
const coverage=routes.map(route=>({source:route.source,route:route.path,widths:[1440,1024,390].filter(width=>base.report.some(r=>r.width===width&&(r.php.url.split('?')[0].endsWith('/'+route.source)||(route.source==='staff/family_requests.php'&&blocked(r))))),status:route.source==='staff/family_requests.php'?'Blocked: PHP HTTP 500':route.source==='login.php'||route.source==='staff/survey_management.php'?'Intentional earlier additions retained':'Matched'}))
for(const page of coverage)assert.equal(page.widths.length,3,`Missing width for ${page.source}`)
for(const group of new Set(all.map(r=>r.stage+':'+r.name)))assert.equal(all.filter(r=>r.stage+':'+r.name===group).length,3,`Incomplete scenario ${group}`)
const summary={date:'2026-11-06',engine:'Playwright Chromium (installed Google Chrome)',viewports:[{width:1440,height:900},{width:1024,height:900},{width:390,height:844}],threshold:0.15,antialiasing:'Pixelmatch default antialias exclusion; raw PNG byte equality is not asserted.',routes:coverage.length,scenarios:new Set(all.map(r=>r.stage+':'+r.name)).size,pairs:all.length,screenshots:all.length*2,matched:all.filter(r=>r.pixels===0).length,intentional:all.filter(r=>intentional(r)).length,blocked:all.filter(r=>blocked(r)).length,unexpected,interactionStyleChecks:stateStyles.length,interactionStyleDifferences:stateStyles.filter(r=>!r.matched),coverage,recaptures,errors:[...base.errors,...states.errors]}
await writeFile(resolve(root,'summary.json'),JSON.stringify(summary,null,2))
await writeFile(resolve(root,'interaction-styles.json'),JSON.stringify(stateStyles,null,2))
for(const [dir,data] of [['final',base],['states-final',states]])await writeFile(resolve(root,dir,'report.json'),JSON.stringify(data,null,2))
assert.equal(unexpected.length,0,'Unexplained screenshot differences remain')
assert.equal(summary.interactionStyleDifferences.length,0,'Hover/focus style differences remain')
assert.equal(states.errors.length,0,'Interaction scenarios must all finish')
assert.ok(base.errors.every(e=>e.kind==='baseline-unavailable'&&e.httpStatus===500&&e.case==='admin-family_requests'))

const status=r=>blocked(r)?'PHP unavailable':intentional(r)?'Earlier requested additions retained':'Matched'
const link=(r,version)=>`${r.stage}/${r[version].screenshot}`
const htmlEscape=s=>String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;')
const rows=all.sort((a,b)=>a.stage.localeCompare(b.stage)||a.name.localeCompare(b.name)||b.width-a.width)
const gallery=`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>PHP / React visual audit</title><style>body{font:15px system-ui;margin:24px;color:#223028;background:#f5f8f7}main{max-width:1500px;margin:auto}h1{font-size:26px}details{background:white;border:1px solid #dbe5e1;border-radius:8px;margin:10px 0;padding:12px}summary{cursor:pointer}summary small{float:right}.pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pair img{max-width:100%;height:auto;border:1px solid #ddd}a{color:#155d50}.warning{color:#9c3c2c}label{margin-right:16px}input,select{padding:8px}figure{margin:14px 0}figcaption{font-weight:600;margin-bottom:6px}@media(max-width:700px){.pair{grid-template-columns:1fr}}</style><main><h1>PHP / React visual audit — 6 November 2026</h1><p>${summary.routes} route entries, ${summary.scenarios} scenarios, ${summary.pairs} pairs / ${summary.screenshots} screenshots. Viewports: 1440×900, 1024×900, 390×844. Open a row to compare the unchanged PHP rendering and React.</p><p>Matching uses an antialias-aware Pixelmatch threshold of 0.15. The login portal tabs and survey status filters were explicitly requested earlier and remain visible. <strong class="warning">Legacy Family &amp; Household Requests returns HTTP 500; its React placeholder remains unresolved.</strong></p><p><a href="matrix.md">Route and scenario matrix</a> · <a href="summary.json">Machine-readable results</a> · <a href="interaction-styles.json">Computed hover/focus styles</a></p><label>Find <input id="find" type="search" placeholder="Page or state..."></label><label>Width <select id="width"><option value="">All</option><option>1440</option><option>1024</option><option>390</option></select></label><label>Group <select id="group"><option value="">All</option><option value="final">Pages</option><option value="states-final">Interactions</option></select></label>${rows.map(r=>`<details data-name="${htmlEscape(r.name)}" data-width="${r.width}" data-group="${r.stage}"><summary>${htmlEscape(r.name)} — ${r.width}px <small>${status(r)} · ${r.percent}%</small></summary><div class="pair">${['php','react'].map(v=>`<figure><figcaption>${v==='php'?'Original PHP':'React'} · <a href="${link(r,v)}">Full screenshot</a></figcaption><img loading="lazy" src="${link(r,v)}" alt="${htmlEscape(r.name)} ${v} at ${r.width}px"></figure>`).join('')}</div>${r.pixels?`<a href="${r.stage}/${r.name}-${r.width}-diff.png">Difference image</a>`:''}</details>`).join('')}<script>const f=document.getElementById('find'),w=document.getElementById('width'),g=document.getElementById('group');function filter(){document.querySelectorAll('details').forEach(d=>d.hidden=!d.dataset.name.includes(f.value.toLowerCase())||(w.value&&d.dataset.width!==w.value)||(g.value&&d.dataset.group!==g.value))}f.oninput=w.onchange=g.onchange=filter;</script></main></html>`
await writeFile(resolve(root,'index.html'),gallery)
const matrix=`# Screenshot matrix\n\nSee the [comparison gallery](index.html) and [results](summary.json). All widths are CSS viewport widths; device scale factor is 1. Desktop/tablet height is 900px and phone height is 844px. Main page captures are full-page; interaction captures show the viewport (including open scrolling dialogs).\n\n| PHP original | React route | Widths | Result |\n| --- | --- | --- | --- |\n${coverage.map(p=>`| ${p.source} | ${p.route} | ${p.widths.join(', ')} | ${p.status} |`).join('\n')}\n\n| Scenario | Width | PHP | React | Difference | Result |\n| --- | --- | --- | --- | --- | --- |\n${rows.map(r=>`| ${r.name} | ${r.width} | [PNG](${link(r,'php')}) | [PNG](${link(r,'react')}) | ${r.percent}% | ${status(r)} |`).join('\n')}\n`
await writeFile(resolve(root,'matrix.md'),matrix)
console.log(JSON.stringify({routes:summary.routes,scenarios:summary.scenarios,pairs:summary.pairs,matched:summary.matched,intentional:summary.intentional,blocked:summary.blocked,styleChecks:summary.interactionStyleChecks,unexpected:unexpected.length}))
