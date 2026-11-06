import assert from 'node:assert/strict'
import { before, after, test } from 'node:test'
import { readFile, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { fileURLToPath } from 'node:url'
import { JSDOM } from '../../frontend/node_modules/jsdom/lib/api.js'
const exec = promisify(execFile), fixtureFile = join(tmpdir(), 'barangay-survey-management-fixtures.json')
const fixtures = JSON.parse(await readFile(fixtureFile, 'utf8'))
const originalFetch = globalThis.fetch, base = 'http://localhost/barangay_survey_system/api'
const dom = new JSDOM('<div id="root"></div>', { url: 'http://localhost/' })
let React, createRoot, routerLib, server, root, router, cookie = '', screens = {}, AuthProvider, ConfirmProvider, ProtectedRoute, StaffLayout
let printCount = 0, newNumber, newId
const cookies = {}, e = (...args) => React.createElement(...args)
const text = node => [...node.childNodes].map(child => child.nodeType === 3 ? child.textContent : ` ${text(child)} `).join('').replace(/\s+/g, ' ').trim()
before(async () => {
  for (const key of ['window','document','HTMLElement','Event','KeyboardEvent','location']) globalThis[key] = dom.window[key]
  globalThis.requestAnimationFrame = callback => setTimeout(callback, 0)
  globalThis.IS_REACT_ACT_ENVIRONMENT = true
  window.print = () => { printCount++ }
  React = await import('../../frontend/node_modules/react/index.js')
  ;({ createRoot } = await import('../../frontend/node_modules/react-dom/client.js'))
  routerLib = (await import('../../frontend/node_modules/react-router-dom/dist/index.js')).default
  const { createServer } = await import('../../frontend/node_modules/vite/dist/node/index.js')
  server = await createServer({ root: fileURLToPath(new URL('../../frontend', import.meta.url)), cacheDir: join(tmpdir(),'barangay-user-tests'), server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' })
  for (const [name,path] of Object.entries({ Users: 'pages/staff/UserManagement', Profile: 'pages/staff/CompleteProfile', Password: 'pages/ChangePassword', Login: 'pages/public/Login' })) screens[name] = (await server.ssrLoadModule(`/src/${path}.jsx`)).default
  ;({ default: AuthProvider } = await server.ssrLoadModule('/src/context/AuthProvider.jsx'))
  ;({ default: ConfirmProvider } = await server.ssrLoadModule('/src/components/common/ConfirmProvider.jsx'))
  ;({ default: ProtectedRoute } = await server.ssrLoadModule('/src/routes/ProtectedRoute.jsx'))
  ;({ default: StaffLayout } = await server.ssrLoadModule('/src/components/layout/StaffLayout.jsx'))
  for (const role of ['staff','admin']) {
    const response = await originalFetch(base+'/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({identifier:fixtures[role].number,password:'SurveyFixtureA1'})})
    assert.equal(response.status,200)
    cookies[role]=response.headers.getSetCookie().filter(v=>v.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  }
  globalThis.fetch = async (path,options) => {
    const endpoint = path.replace(/^.*?\/api/,'')
    const response = await originalFetch(base+endpoint,{...options,headers:{...options?.headers,...(cookie?{Cookie:cookie}:{})}})
    const setCookie=response.headers.getSetCookie().filter(v=>v.startsWith('PHPSESSID=')).at(-1)
    if(setCookie) cookie=setCookie.split(';')[0]
    if(endpoint==='/staff/users' && options?.method==='POST' && response.ok) {
      const result=await response.clone().json();newNumber=result.data.new_staff.staff_number
      const list=await originalFetch(base+'/staff/users',{headers:{Cookie:cookie}}).then(r=>r.json())
      newId=list.data.staff.find(row=>row.staff_number===newNumber).staff_id
      const state=JSON.parse(await readFile(fixtureFile,'utf8'));state.created_ids.push(Number(newId));await writeFile(fixtureFile,JSON.stringify(state),{mode:0o600})
    }
    return response
  }
})
after(async()=>{ if(React) await React.act(async()=>{root?.unmount();router?.dispose()});await server?.close();globalThis.fetch=originalFetch;dom.window.close() })
async function until(check) {
  const deadline=Date.now()+10000
  while(!check()&&Date.now()<deadline) await React.act(async()=>new Promise(resolve=>setTimeout(resolve,25)))
  assert.ok(check(),'Expected UI state: '+document.body.textContent.slice(0,1200))
}
async function mount(path,role='admin') {
  await React.act(async()=>{root?.unmount();router?.dispose()})
  cookie=cookies[role]||'';document.body.innerHTML='<div id="root"></div>';root=createRoot(document.getElementById('root'))
  router=routerLib.createMemoryRouter([{element:e(AuthProvider,null,e(ConfirmProvider,null,e(routerLib.Outlet))),children:[
    {path:'/login',element:e(screens.Login)},
    {element:e(ProtectedRoute,{portal:'staff'}),children:[{element:e(StaffLayout),children:[
      {path:'/staff/user-management',element:e(screens.Users)}, {path:'/staff/complete-profile',element:e(screens.Profile)},
      {path:'/staff/change-password',element:e(screens.Password)}, {path:'/staff/dashboard',element:e('h1',null,'Dashboard')},
    ]}]},
  ]}],{initialEntries:[path]})
  await React.act(async()=>root.render(e(React.StrictMode,null,e(routerLib.RouterProvider,{router}))))
  await until(()=>document.querySelector('h1,h2')&&!document.querySelector('.container > p[role="status"]'))
}
async function click(node) {assert.ok(node);await React.act(async()=>node.click())}
async function input(selector,value) {
  await React.act(async()=>{const node=document.querySelector(selector);assert.ok(node,selector)
    const prototype=node.tagName==='SELECT'?window.HTMLSelectElement.prototype:window.HTMLInputElement.prototype
    Object.getOwnPropertyDescriptor(prototype,'value').set.call(node,value)
    node.dispatchEvent(new window.Event(node.tagName==='SELECT'?'change':'input',{bubbles:true}))
  })
}
async function submit(selector) {await React.act(async()=>document.querySelector(selector).dispatchEvent(new window.Event('submit',{bubbles:true,cancelable:true})))}
const button=(label,scope=document)=>[...scope.querySelectorAll('button')].find(node=>node.textContent.trim()===label)
async function oracle(page,role='admin',id=fixtures[role]?.id) {
  const {stdout}=await exec('/Applications/XAMPP/xamppfiles/bin/php',[fileURLToPath(new URL('./staff-users-reference.php',import.meta.url)),String(id),page,'html'])
  return new JSDOM(stdout).window.document
}
for(const role of ['admin','staff']) test(`${role}: user page tables and labels match original PHP`,async()=>{
  await mount('/staff/user-management',role);await until(()=>document.querySelector('#resident-accounts-table'))
  const reference=await oracle('user_management',role)
  for(const selector of ['.page-head','#resident-accounts-table',...(role==='admin'?['#staff-accounts-table']:[])]) assert.equal(text(document.querySelector(selector)),text(reference.querySelector(selector)),selector)
  assert.equal(!!document.querySelector('#staff-accounts-table'),role==='admin')
  if(role==='admin') {
    await input('#staff-account-search','does not exist');assert.ok([...document.querySelectorAll('#staff-accounts-table tbody tr[data-search-text]')].every(row=>row.style.display==='none'))
    assert.ok([...document.querySelectorAll('#resident-accounts-table tbody tr[data-search-text]')].some(row=>row.style.display!== 'none'))
  }
})
test('admin profile is read-only and preserves original employment record/photo content',async()=>{
  await mount('/staff/complete-profile');await until(()=>document.querySelector('.photo-card'))
  const reference=await oracle('complete_profile')
  assert.equal(text(document.querySelector('.page-head')),text(reference.querySelector('.page-head')))
  assert.equal(text(document.querySelector('.credential-slip')),text(reference.querySelector('.credential-slip')))
  assert.equal(document.querySelector('.card-form'),null)
  assert.ok(document.querySelector('input[type="file"]'))
})
test('create staff retains validation values and shows a one-time printable hand-over slip',async()=>{
  await mount('/staff/user-management');await until(()=>button('+ Add Staff Account'))
  await click(button('+ Add Staff Account'));await input('#first_name','Step15React');await input('#last_name','New Hire')
  await input('#courtesy_title','Others');assert.equal(document.querySelector('#courtesy_title_other').required,true)
  await input('#courtesy_title_other','Dr.');await input('#date_hired','2999-01-01');await submit('#add-staff-modal form')
  await until(()=>document.querySelector('#add-staff-modal .alert-error'))
  assert.equal(document.querySelector('#first_name').value,'Step15React')
  await input('#date_hired',new Date().toISOString().slice(0,10));await submit('#add-staff-modal form')
  await until(()=>document.querySelector('#new-staff-modal.active'))
  assert.ok(newNumber && newId)
  assert.match(document.querySelector('#new-staff-modal').textContent,/Dr\. Step15React New Hire/)
  assert.equal(document.querySelector('.staff-slip-print-area dd').textContent,newNumber)
  await click(button('Print'));assert.equal(printCount,1)
  await click(button('Done',document.querySelector('#new-staff-modal')));assert.equal(document.querySelector('.staff-slip-print-area'),null)
  await mount('/staff/user-management');await until(()=>document.querySelector('#staff-accounts-table'));assert.equal(document.querySelector('#new-staff-modal'),null)
})
test('shared cropper posts to staff photo endpoint, refreshes the avatar, resets the form, and removes the photo',async()=>{
  const login=await originalFetch(base+'/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({identifier:newNumber,password:newNumber})})
  cookies.photo=login.headers.getSetCookie().filter(v=>v.startsWith('PHPSESSID=')).at(-1).split(';')[0]
  await mount('/staff/complete-profile','photo');await until(()=>document.querySelector('#profile_photo'))
  await input('#contact_number','Unsaved contact')
  const oldImage=globalThis.Image,oldCreate=URL.createObjectURL,oldRevoke=URL.revokeObjectURL
  const proto=window.HTMLCanvasElement.prototype,oldContext=proto.getContext,oldData=proto.toDataURL,oldBlob=proto.toBlob
  const bytes=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS0AAAAASUVORK5CYII=','base64')
  const draws=[],codecs=[]
  try {
    globalThis.Image=class {naturalWidth=640;naturalHeight=400;set src(_url){queueMicrotask(()=>this.onload?.())}}
    URL.createObjectURL=()=> 'blob:synthetic';URL.revokeObjectURL=()=>{}
    proto.getContext=()=>({clearRect(){},fillRect(){},drawImage(...args){draws.push(args.slice(1))}})
    proto.toDataURL=()=> 'data:image/png;base64,'+bytes.toString('base64')
    proto.toBlob=(callback,type,quality)=>{codecs.push([type,quality]);callback(new Blob([bytes],{type}))}
    const file=new File([bytes],'fixture.png',{type:'image/png'})
    await React.act(async()=>{const node=document.querySelector('#profile_photo');Object.defineProperty(node,'files',{value:[file],configurable:true});node.dispatchEvent(new window.Event('change',{bubbles:true}))})
    await until(()=>document.querySelector('#photo-confirm-modal.active button.btn:not(.btn-secondary):not([disabled])'))
    // The original modal reset clears the 1–3 range to its native midpoint (2×).
    assert.deepEqual(draws.at(-1),[-352,-160,1024,640])
    await input('#photo-zoom','1');assert.deepEqual(draws.at(-1),[-96,0,512,320])
    await click(button('Confirm & Upload'));await until(()=>document.querySelector('#profile-success-modal.active'))
    assert.deepEqual(codecs,[['image/jpeg',0.9]])
    assert.equal(document.querySelector('#profile-success-modal h2').textContent,'Photo Updated')
    assert.equal(document.querySelector('#contact_number').value,'')
    assert.ok(document.querySelector('.account-avatar').getAttribute('src').includes('/uploads/staff/'))
    assert.ok(document.querySelector('#app-sidebar'),'PHP exposes navigation for the saved-photo response during onboarding')
    await click(button('Stay Here'));await click(button('Remove Photo'));await until(()=>document.querySelector('#confirm-modal.active'))
    await click(document.querySelector('#confirm-modal-proceed'));await until(()=>!button('Remove Photo'))
    assert.equal(document.querySelector('.profile-photo-preview'),null)
  } finally {
    globalThis.Image=oldImage;URL.createObjectURL=oldCreate;URL.revokeObjectURL=oldRevoke;proto.getContext=oldContext;proto.toDataURL=oldData;proto.toBlob=oldBlob
  }
})
test('new staff logs in, changes password, completes onboarding, and gains navigation',async()=>{
  await mount('/login','anonymous');await until(()=>document.querySelector('#staff-tab'))
  await click(document.querySelector('#staff-tab'));await input('#identifier',newNumber);await input('#password',newNumber);await submit('.auth-form-panel form')
  await until(()=>document.querySelector('#current_password'))
  assert.equal(router.state.location.pathname,'/staff/change-password');assert.equal(document.querySelector('#app-sidebar'),null)
  await input('#current_password',newNumber);await input('#new_password','ReactOnboardA1');await input('#confirm_password','ReactOnboardA1');await submit('.card-form form')
  await until(()=>document.querySelector('#contact_number'))
  assert.equal(router.state.location.pathname,'/staff/complete-profile');assert.match(document.querySelector('h1').textContent,/Complete Your Profile/)
  assert.equal(document.querySelector('#app-sidebar'),null)
  const reference=await oracle('complete_profile','new',newId)
  assert.equal(text(document.querySelector('.card-form')),text(reference.querySelector('.card-form')))
  const controls=scope=>[...scope.querySelectorAll('input,select,button')].map(node=>Object.fromEntries(['id','name','type','placeholder','required','readonly','tabindex','maxlength','max','inputmode'].map(key=>[key,node.getAttribute(key)])))
  assert.deepEqual(controls(document.querySelector('.card-form')),controls(reference.querySelector('.card-form')))
  await input('#contact_number','0917 123 4567');await input('#birthdate','1995-01-01');assert.match(document.querySelector('#age_display').value,/years old/)
  for(const [key,value] of Object.entries({household_number:'101',barangay:'Sapang Puke',municipality:'Malolos',province:'Bulacan'})) await input('#'+key,value)
  await submit('.card-form form');await until(()=>document.querySelector('#profile-success-modal.active'))
  assert.equal(document.querySelector('#profile-success-modal h2').textContent,'You are all set')
  await until(()=>document.querySelector('#app-sidebar'));assert.equal(document.querySelector('.btn-wide').textContent,'Save Changes')
  await click(button('Stay Here'));assert.ok(!document.querySelector('#profile-success-modal.active'))
  cookies.new=cookie
})
test('post-onboarding address changes request review, lock address inputs, and permit contact saves',async()=>{
  await mount('/staff/complete-profile','new');await until(()=>document.querySelector('#reason'))
  await input('#household_number','102');await submit('.card-form form');await until(()=>document.querySelector('.toast-error'))
  assert.match(document.querySelector('.toast-error').textContent,/Give a short reason/)
  await input('#reason','Moved to another household');await submit('.card-form form');await until(()=>document.querySelector('#profile-success-modal.active'))
  assert.equal(document.querySelector('#profile-success-modal h2').textContent,'Request Sent')
  assert.equal(document.querySelector('#household_number'),null);assert.match(document.querySelector('.card-form').textContent,/You asked to change this to: 102/)
  assert.match(document.querySelector('.card-form .credential-slip').textContent,/101/)
  await click(button('Stay Here'));await input('#contact_number','0919 123 4567');await submit('.card-form form')
  await until(()=>document.querySelector('#profile-success-modal.active'));assert.equal(document.querySelector('#profile-success-modal h2').textContent,'Profile Saved')
  assert.match(document.querySelector('.card-form .credential-slip').textContent,/101/)
})
test('admin toggle, reset and archive actions confirm, refresh the roster, and report source outcomes',async()=>{
  await mount('/staff/user-management');await until(()=>document.querySelector('#staff-accounts-table'))
  const row=()=>[...document.querySelectorAll('#staff-accounts-table tbody tr')].find(node=>node.textContent.includes(newNumber))
  for(const label of ['Deactivate account','Reactivate account','Reset password','Delete account']) {
    await click(row().querySelector(`[aria-label="${label}"]`));await until(()=>document.querySelector('#user-action-confirm.active'))
    await click(document.querySelector('#user-action-confirm-proceed'));await until(()=>document.querySelector('#user-success-modal.active'))
    await click(button('Done',document.querySelector('#user-success-modal')))
    if(label==='Deactivate account') await until(()=>/Deactivated/.test(row()?.textContent||''))
    if(label==='Reactivate account') await until(()=>row()?.querySelector('[aria-label="Deactivate account"]'))
    if(label==='Reset password') await until(()=>/Password not set/.test(row()?.textContent||''))
  }
  await until(()=>!row())
})
