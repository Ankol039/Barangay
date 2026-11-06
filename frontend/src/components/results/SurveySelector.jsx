import PropTypes from 'prop-types'
import { identifier, records } from '../../propTypes.js'
import Card from '../common/Card.jsx'
import { apiUrl } from '../../api/client.js'

export default function SurveySelector({ surveys, surveyId, onChange, report = false }) {
  return <Card className="no-print">
    <form method="GET" onSubmit={event => event.preventDefault()}>
      <label htmlFor="survey_id">{report ? 'Select a Survey' : 'Select a Survey to View Results'}</label>
      <select id="survey_id" name="survey_id" value={surveyId || ''} onChange={event => onChange(event.target.value)} style={report ? undefined : { maxWidth: 400 }}>
        <option value="">-- Choose a survey --</option>
        {surveys.map(survey => <option key={survey.survey_id} value={survey.survey_id}>{survey.title}</option>)}
      </select>
    </form>
    {report && surveyId !== 0 && <div style={{ marginTop: 14 }}>
      <button type="button" className="btn" onClick={() => window.print()}>Print Report</button>{' '}
      <a className="btn btn-secondary" href={apiUrl(`/staff/report-export?survey_id=${surveyId}`)}>Export to CSV (Excel)</a>
    </div>}
  </Card>
}

if (import.meta.env.DEV) {
  SurveySelector.propTypes = {
    surveys: records.isRequired,
    surveyId: identifier.isRequired,
    onChange: PropTypes.func.isRequired,
    report: PropTypes.bool,
  }
}
