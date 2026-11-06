import { Link, useSearchParams } from 'react-router-dom'
import StaffLayout from '../../components/layout/StaffLayout.jsx'
import ResidentLayout from '../../components/layout/ResidentLayout.jsx'
import PageHead from '../../components/common/PageHead.jsx'
import PageActionsRow from '../../components/common/PageActionsRow.jsx'
import Alert from '../../components/common/Alert.jsx'
import DemoSurveyCard from './DemoSurveyCard.jsx'
import DemoControls from './DemoControls.jsx'
import { demoUser } from './demoData.js'
import usePageTitle from '../../hooks/usePageTitle.js'

export default function ComponentDemo() {
  usePageTitle('Survey Management')
  const [params] = useSearchParams()
  const portal = params.get('portal') || 'admin'
  const resident = portal === 'resident'
  const compare = params.has('compare')
  const user = { ...demoUser, role: portal, account_type: resident ? 'resident' : 'staff',
    portal_label: resident ? 'Resident Portal' : portal === 'staff' ? 'Staff Portal' : 'Admin Portal',
    pending_deletions: compare ? 0 : 3, archived_accounts: compare ? 0 : 2,
    residency_status: resident && !compare ? 'temporary' : '', residence_type: 'AR',
    residence_type_label: resident && !compare ? 'Apartment Renter' : '',
    is_first_login: params.has('onboarding'), photo: params.has('broken-photo') ? 'uploads/missing-demo-photo.png' : params.has('photo') ? 'assets/css/Logo.png' : '',
  }
  const Layout = resident ? ResidentLayout : StaffLayout
  return <Layout user={user} activePath={resident ? 'surveys' : 'survey-management'}>
    <PageHead title="Surveys" subtitle="Create, edit and publish the surveys residents can answer." />
    <PageActionsRow><Link className="btn" to="/dev/components?action=create">+ Create New Survey</Link></PageActionsRow>
    <DemoSurveyCard />
    {!compare && <>
      <Alert type="success">Resident updated successfully.</Alert>
      <DemoControls />
    </>}
  </Layout>
}
