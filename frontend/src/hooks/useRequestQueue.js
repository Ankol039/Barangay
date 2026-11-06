import { useContext, useState } from 'react'
import useApiData from './useApiData.js'
import { apiRequest } from '../api/client.js'
import { AuthContext } from '../context/authContext.js'

export default function useRequestQueue(path) {
  const initial = useApiData(path)
  const [latest, setLatest] = useState(null)
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState('')
  const [message, setMessage] = useState('')
  const [redirect, setRedirect] = useState(null)
  const auth = useContext(AuthContext)
  async function run(endpoint, body) {
    if (busy) return
    setBusy(true); setFailure(''); setMessage('')
    try {
      const result = await apiRequest(endpoint, { method: 'POST', body })
      if (result.redirect && !result.data) { setRedirect(result.redirect); return }
      setDialog(null)
      setMessage(result.ui?.show_success === false ? '' : result.message || '')
      try {
        const refreshed = await apiRequest(path)
        if (refreshed.redirect) setRedirect(refreshed.redirect)
        else setLatest(refreshed.data)
        await auth?.refresh()
      } catch (error) { setFailure(error.message) }
    } catch (error) { setFailure(error.message) }
    finally { setBusy(false) }
  }
  function review(kind, request, action, comment) {
    const prefix = { resident: 'address', staff: 'staff-address', family: 'family', deletion: 'deletion' }[kind]
    return run(`/staff/${prefix}-request/review`, { request_id: String(request.request_id), action, review_comment: comment })
  }
  function open(value) { setFailure(''); setDialog(value) }
  return { data: latest || initial.data, redirect: redirect || initial.redirect, error: failure || initial.error,
    dialog, open, close: () => { if (!busy) { setDialog(null); setFailure('') } }, busy, review, run, message, clearMessage: () => setMessage('') }
}
