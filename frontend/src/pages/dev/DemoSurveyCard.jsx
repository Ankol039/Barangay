import { Link } from 'react-router-dom'
import Card from '../../components/common/Card.jsx'
import DataTable from '../../components/common/DataTable.jsx'
import Badge from '../../components/common/Badge.jsx'
import Icon from '../../components/common/Icon.jsx'
import { surveys } from './demoData.js'

const actions = [
  ['edit', 'Edit survey', 'edit-survey', ''], ['surveys', 'Manage questions', 'question-management', ''],
  ['results', 'View results', 'results-dashboard', ' btn-action-primary'],
]
export default function DemoSurveyCard() {
  const columns = [
    { key: 'title', label: 'Title' },
    { key: 'period', label: 'Period', render: (row) => <>{row.start_date} &ndash; {row.end_date}</> },
    { key: 'status', label: 'Status', render: (row) => <Badge variant={row.status}>{row.status === 'active' ? 'Active' : 'Inactive'}</Badge> },
    { key: 'response_count', label: 'Responses' },
    { key: 'actions', label: 'Actions', render: (row) => <div className="action-buttons">
      {actions.map(([icon, label, path, variant]) => <Link key={icon} className={`btn-action${variant}`} title={label} aria-label={label}
        to={`/dev/components?action=${path}&id=${row.id}`}><Icon name={icon} /></Link>)}
      <Link className={`btn-action btn-action-${row.status === 'active' ? 'danger' : 'success'}`}
        title={row.status === 'active' ? 'Deactivate survey' : 'Activate survey'} aria-label={row.status === 'active' ? 'Deactivate survey' : 'Activate survey'}
        to="/dev/components?confirmed=toggle" data-confirm={row.status === 'active' ? 'Deactivate this survey?' : 'Activate this survey?'}><Icon name="power" /></Link>
      <Link className="btn-action btn-action-danger" title="Delete survey" aria-label="Delete survey" to="/dev/components?confirmed=delete"
        data-confirm={`Delete “${row.title}”? This will also delete its questions, choices, responses, and results. This action cannot be undone.`}><Icon name="trash" /></Link>
    </div> },
  ]
  return <Card><DataTable id="surveys-table" title="All Surveys" columns={columns} rows={surveys}
    searchId="survey-search" searchPlaceholder="Search surveys..." searchLabel="Search surveys" emptyMessage="No surveys match your search." /></Card>
}
