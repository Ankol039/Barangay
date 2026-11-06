/** Read-only browser states. Opens dialogs and edits unsaved controls; never submits. */
export function visualScenarios(state) {
  const cases = []
  const add = (name, role, source, action, query = '') => cases.push({ name, role, source, route: source.replaceAll('_','-').replace('.php',''), query, action, viewportOnly: true })
  const open = (id, name) => async (page, version) => {
    if (version === 'php') await page.locator(`[data-modal-open="${id}"]`).first().click()
    else await page.getByRole('button',{name, exact:true}).first().click()
    await page.locator(`#${id}.active`).waitFor()
  }
  const row = (page, account) => page.locator('tr').filter({hasText:state.accounts[account].number})
  for (const role of ['resident','staff','admin']) {
    const source = `${role==='resident'?'resident':'staff'}/dashboard.php`
    add(`${role}-account-menu`,role,source,async page=>page.locator('#account-chip').click())
    add(`${role}-nav-hover`,role,source,async(page)=>{if(await page.locator('#mobile-nav-toggle').isVisible())await page.locator('#mobile-nav-toggle').click();await page.locator('.nav-item').first().hover()})
    add(`${role}-nav-focus`,role,source,async(page)=>{
      if(await page.locator('#mobile-nav-toggle').isVisible()) {await page.locator('#mobile-nav-toggle').click();await page.waitForTimeout(300)}
      await page.keyboard.press('Tab');await page.locator('.nav-item').first().focus()
      await page.waitForFunction(()=>document.activeElement?.matches('.nav-item'),null,{polling:50})
    })
  }
  for (const [id, name] of [['edit-contact-modal','Edit Contact'],['request-change-modal','Request Change'],['request-birthdate-modal','Request Change'],['family-request-modal','Request Change']]) {
    add(`profile-${id}`,'temporary','resident/profile.php',async(page,version)=>{
      if(version==='php') await page.locator(`[data-modal-open="${id}"]`).first().click()
      else if(id==='edit-contact-modal') await page.getByRole('button',{name,exact:true}).first().click()
      else await page.getByRole('button',{name,exact:true}).nth(id==='request-change-modal'?0:id==='request-birthdate-modal'?1:2).click()
      await page.locator(`#${id}.active`).waitFor()
    })
  }
  for(const mode of ['hover','focus','selected']) add(`survey-options-${mode}`,'resident','resident/survey_form.php',async(page)=>{
    if(mode==='hover')await page.locator('.option-row').first().hover()
    else if(mode==='focus'){await page.keyboard.press('Tab');await page.locator('.option-input').first().focus()}
    else await page.locator('.option-row').first().click()
  },`?id=${state.available}`)
  add('resident-submitted-modal','resident','resident/surveys.php',async page=>page.locator('#submitted-modal.active').waitFor(),`?submitted=${state.answered}`)
  for(const role of ['staff','admin']) {
    add(`${role}-add-resident-modal`,role,'staff/resident_management.php',open('add-resident-modal','+ Add Resident'))
    add(`${role}-resident-chooser`,role,'staff/resident_management.php',open('edit-resident-chooser-modal','Edit Resident'))
    add(`${role}-edit-resident-modal`,role,'staff/resident_management.php',async page=>{await row(page,'temporary').locator('.edit-resident-row').click();await page.locator('#edit-resident-info-modal.active').waitFor()})
    add(`${role}-resident-no-match`,role,'staff/resident_management.php',async page=>page.locator('#resident-search').fill('NO-VISUAL-MATCH'))
    add(`${role}-reset-confirm`,role,'staff/resident_management.php',async page=>{await row(page,'resident').getByRole('button',{name:'Reset password',exact:true}).or(row(page,'resident').getByRole('link',{name:'Reset password',exact:true})).click()})
  }
  add('staff-request-deletion','staff','staff/resident_management.php',async(page,version)=>{
    await row(page,'empty').getByRole('button',{name:'Request deletion',exact:true}).click();await page.locator('#request-delete-modal.active').waitFor()
  })
  for(const target of ['temporary','resident']) add(`admin-residency-${target}`,'admin','staff/resident_management.php',async(page)=>{
    await row(page,target).locator('.edit-resident-row').click();await page.locator('#edit-resident-info-modal.active').waitFor();await page.locator('#residency-switch-btn').click();await page.locator(target==='temporary'?'#promote-resident-modal.active':'#revert-resident-modal.active').waitFor()
  })
  add('admin-add-staff','admin','staff/user_management.php',open('add-staff-modal','+ Add Staff Account'))
  for(const action of ['approve','reject']) {
    add(`admin-staff-address-${action}`,'admin','staff/address_requests.php',async(page,version)=>{
      if(version==='php')await page.locator(`[data-staff-review][data-action="${action}"]`).first().click()
      else await page.locator('[data-staff-request-id]').first().getByRole('button',{name:action==='approve'?'Approve':'Reject',exact:true}).click()
    })
    add(`admin-deletion-${action}`,'admin','staff/deletion_requests.php',async(page,version)=>{
      if(version==='php')await page.locator(`[data-review-open][data-action="${action}"]`).first().click()
      else await page.locator('[data-deletion-request-id]').first().getByRole('button',{name:action==='approve'?'Approve':'Reject',exact:true}).click()
    })
  }
  add('admin-purge-dialog','admin','staff/deleted_accounts.php',async(page,version)=>{
    if(version==='php')await page.locator('[data-purge-open]').first().click()
    else await page.getByRole('button',{name:'Delete Forever',exact:true}).first().click()
  })
  add('add-question-dialog','staff','staff/question_management.php',open('add-question-modal','+ Add Question'),`?survey_id=${state.available}`)
  add('edit-question-dialog','staff','staff/question_management.php',async page=>page.getByRole('button',{name:'Edit question',exact:true}).first().click(),`?survey_id=${state.available}`)
  add('survey-preview','staff','staff/add_survey.php',async page=>page.getByRole('button',{name:'Preview Survey',exact:true}).click())
  for(const source of ['staff/resident_management.php','staff/user_management.php','staff/survey_management.php','staff/residency_records.php']) for(const mode of ['hover','focus']) {
    add(`${source.split('/')[1].replace('.php','')}-action-${mode}`,'admin',source,async page=>{
      const target=page.locator(source.includes('residency_records')?'.stat-card-link':'.btn-action').first()
      if(mode==='hover')await target.hover();else {await page.keyboard.press('Tab');await target.focus()}
    })
  }
  add('results-no-match','staff','staff/results_dashboard.php',async page=>page.locator('[data-answer-search]').first().fill('NO-VISUAL-MATCH'),`?survey_id=${state.answered}`)
  for(const source of ['resident/change_password.php','staff/change_password.php']) for(const mode of ['input-focus','eye-hover','rules-match']) add(`${source.split('/')[0]}-password-${mode}`,source.startsWith('resident')?'resident':'staff',source,async page=>{
    if(mode==='input-focus') {await page.keyboard.press('Tab');await page.locator('#new_password').focus()}
    else if(mode==='eye-hover')await page.locator('.password-toggle').first().hover()
    else {await page.locator('#new_password').fill('VisualPasswordA1');await page.locator('#confirm_password').fill('VisualPasswordA1')}
  })
  for(const source of ['staff/survey_management.php','staff/user_management.php','staff/residency_records.php']) add(`${source.split('/')[1].replace('.php','')}-no-match`,'admin',source,async page=>page.locator('input[type="search"]').first().fill('NO-VISUAL-MATCH'))
  for(const mode of ['family','temporary','custom-title']) add(`add-resident-${mode}`,'admin','staff/resident_management.php',async(page,version)=>{
    await open('add-resident-modal','+ Add Resident')(page,version)
    const modal=page.locator('#add-resident-modal')
    if(mode==='family') {
      await modal.locator('[name="is_married"][value="1"]').check();await modal.locator('[name="has_children"][value="1"]').check()
      await modal.getByRole('button',{name:'+ Add another reference',exact:true}).click()
    } else if(mode==='temporary')await modal.locator('[name="residency_status"]').selectOption('temporary')
    else await modal.locator('[name="courtesy_title"]').first().selectOption('Others')
    if(mode!=='custom-title')await modal.locator('.modal-box').evaluate(el=>el.scrollTop=el.scrollHeight)
  })
  for(const [role,source] of [['temporary','resident/profile.php'],['staff','staff/complete_profile.php']]) add(`${role}-photo-crop`,role,source,async page=>{
    await page.locator('#profile_photo').setInputFiles({name:'visual-crop.png',mimeType:'image/png',buffer:state.cropImage})
    await page.locator('#photo-confirm-modal.active').waitFor()
    await page.waitForFunction(()=>document.querySelector('#photo-preview-image')?.getAttribute('src')?.startsWith('data:'),null,{polling:50})
  })
  return cases
}
