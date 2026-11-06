import { useState } from 'react'
import { Link } from 'react-router-dom'
import PageActionsRow from '../../components/common/PageActionsRow.jsx'
import Card from '../../components/common/Card.jsx'
import Alert from '../../components/common/Alert.jsx'
import Toast from '../../components/common/Toast.jsx'
import Badge from '../../components/common/Badge.jsx'
import EmptyState from '../../components/common/EmptyState.jsx'
import DataTable from '../../components/common/DataTable.jsx'
import PasswordField from '../../components/auth/PasswordField.jsx'
import DemoModal from './DemoModal.jsx'
import { residents, residentFilters } from './demoData.js'

export default function DemoControls() {
  const [modal, setModal] = useState('')
  const [toast, setToast] = useState(0)
  const [submitted, setSubmitted] = useState('')
  return <>
    <PageActionsRow><button className="btn" onClick={() => setModal('clear')}>Request Change</button>
      <button className="btn btn-secondary" onClick={() => setModal('keep')}>Keep on close</button>
      <button className="btn btn-secondary" onClick={() => setToast(toast + 1)}>Show toast</button>
      <Link className="btn btn-outline" to="/dev/components?portal=resident">Resident Portal</Link>
      <Link className="btn btn-outline" to="/dev/components?portal=staff">Staff Portal</Link>
      <Link className="btn btn-outline" to="/dev/components">Admin Portal</Link>
    </PageActionsRow>
    <Card title="Registered Residents (3)"><DataTable id="residents-table" rows={residents} filters={residentFilters}
      searchPlaceholder="Search Resident..." searchLabel="Search residents by name or resident number" emptyMessage="No residents match your search."
      columns={[{ key: 'number', label: 'Resident No.' }, { key: 'name', label: 'Name' },
        { key: 'residency', label: 'Residency Status', render: (row) => <Badge variant={row.residency}>{row.residency}</Badge> }]} /></Card>
    <Card title="Messages">
      <Alert type="info">Your record stays unchanged until staff approve the request.</Alert>
      <Alert type="success" inline>Resident updated successfully.</Alert>
      <Alert type="error" inline>Current password is incorrect.</Alert>
      <form onSubmit={(event) => { event.preventDefault(); setSubmitted(event.nativeEvent.submitter?.name || '') }}>
        <button type="submit" className="btn btn-danger" name="remove_photo" value="1" data-confirm="Remove your profile photo?">Remove Photo</button>
        <output aria-live="polite">{submitted}</output>
      </form>
    </Card>
    <Card title="Change Password"><label htmlFor="demo-password">Current Password</label><PasswordField id="demo-password" autoComplete="off" /></Card>
    <Card><EmptyState>No surveys available at the moment.</EmptyState></Card>
    <Card><EmptyState title="No Report Selected">Choose a survey from the dropdown above to generate its printable report.</EmptyState></Card>
    <DemoModal open={modal === 'clear'} onClose={() => setModal('')} />
    <DemoModal open={modal === 'keep'} onClose={() => setModal('')} keepOnClose />
    {toast > 0 && <Toast key={toast}>Resident updated successfully.</Toast>}
  </>
}
