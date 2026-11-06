import PropTypes from 'prop-types'
import { record } from '../../propTypes.js'
import { useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../../api/client.js'
import useApiData from '../../hooks/useApiData.js'
import usePageTitle from '../../hooks/usePageTitle.js'
import Card from '../../components/common/Card.jsx'
import Alert from '../../components/common/Alert.jsx'
import QuestionField from '../../components/resident/QuestionField.jsx'

const messages = {
  empty: 'Nothing was submitted, so your answers were not saved and this survey is still open to you. Please answer at least one question before submitting.',
  failed: 'Something went wrong while saving your answers. Nothing was recorded, so you can safely try again.',
}
export default function SurveyForm() {
  const [params] = useSearchParams()
  const id = params.get('id') || '0'
  const result = useApiData(`/resident/survey-form?id=${encodeURIComponent(id)}`)
  usePageTitle(result.data?.survey.title || 'Survey')
  if (result.redirect) return <Navigate to={result.redirect} replace />
  if (result.error) return <Alert type="error" inline>{result.error}</Alert>
  if (result.loading) return <p role="status">Loading…</p>
  return <SurveyAnswerForm key={id} data={result.data} queryError={params.get('error')} />
}

function SurveyAnswerForm({ data: { survey, questions }, queryError }) {
  const navigate = useNavigate()
  const [answers, setAnswers] = useState({})
  const [missing, setMissing] = useState([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  async function submit(event) {
    event.preventDefault()
    if (pending.current) return
    const required = questions.filter(q => ['multiple_choice', 'yesno', 'rating'].includes(q.question_type) && !answers[`q${q.question_id}`])
      .map(q => `q${q.question_id}`)
    setMissing(required)
    if (required.length) {
      window.alert('Please answer all required questions before submitting.')
      return
    }
    pending.current = true
    setBusy(true)
    setError('')
    try {
      const result = await apiRequest('/resident/survey-submissions', { method: 'POST', body: { survey_id: String(survey.survey_id), ...answers } })
      navigate(result.redirect, { replace: true })
    } catch (failure) { setError(failure.message) }
    finally { pending.current = false; setBusy(false) }
  }
  return <Card>
    <h2>{survey.title}</h2>
    <p style={{ color: '#6b7c76', whiteSpace: 'pre-line' }}>{survey.description}</p>
    {(error || messages[queryError]) && <Alert type="error" inline>{error || messages[queryError]}</Alert>}
    <form id="survey-answer-form" onSubmit={submit}>
      <input type="hidden" name="survey_id" value={survey.survey_id} />
      {questions.map((question, index) => <QuestionField key={question.question_id} question={question} index={index}
        value={answers[`q${question.question_id}`]} missing={missing.includes(`q${question.question_id}`)}
        onChange={(field, value) => setAnswers(current => ({ ...current, [field]: value }))} />)}
      <button type="submit" disabled={busy}>Submit Survey</button>{' '}
      <Link className="btn btn-secondary" to="/resident/surveys">Cancel</Link>
    </form>
  </Card>
}

if (import.meta.env.DEV) {
  SurveyAnswerForm.propTypes = {
    data: record.isRequired,
    queryError: PropTypes.string,
  }
}
