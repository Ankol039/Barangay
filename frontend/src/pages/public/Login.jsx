import { useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import useAuth from '../../hooks/useAuth.js'
import usePageTitle from '../../hooks/usePageTitle.js'
import { authDestination } from '../../routes/authRedirect.js'
import AuthStatus from '../../components/auth/AuthStatus.jsx'
import AuthBrandPanel from '../../components/auth/AuthBrandPanel.jsx'
import PasswordField from '../../components/auth/PasswordField.jsx'
import '../../components/auth/auth.css'

export default function Login() {
  usePageTitle('Sign In')
  const auth = useAuth()
  const navigate = useNavigate()
  const [portal, setPortal] = useState('resident')
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)
  const resident = portal === 'resident'

  function switchPortal(next) {
    setPortal(next)
    setIdentifier('')
    setPassword('')
    setError('')
  }

  async function submit(event) {
    event.preventDefault()
    if (pending) return
    setPending(true)
    setError('')
    try {
      const result = await auth.login({ identifier: identifier.trim(), password })
      navigate(authDestination(result.data.user), { replace: true })
    } catch (failure) {
      setError(failure.message)
      setPassword('') // PHP never re-renders a submitted password.
    } finally {
      setPending(false)
    }
  }

  if (auth.loading || auth.error) return <AuthStatus />
  if (auth.user) return <Navigate to={authDestination(auth.user)} replace />

  return (
    <div className="auth-wrapper"><div className="auth-split">
      <AuthBrandPanel />
      <div className="auth-form-panel">
        <h2>Sign in</h2>
        <div className="auth-portals" role="tablist" aria-label="Account portal">
          {['resident', 'staff'].map((tab) => <button key={tab} type="button" role="tab"
            id={`${tab}-tab`} aria-selected={portal === tab} aria-controls="login-panel"
            tabIndex={portal === tab ? 0 : -1} disabled={pending}
            onKeyDown={(event) => {
              if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                event.preventDefault()
                const next = event.key === 'Home' ? 'resident' : event.key === 'End' ? 'staff' : resident ? 'staff' : 'resident'
                switchPortal(next)
                document.getElementById(`${next}-tab`).focus()
              }
            }} onClick={() => switchPortal(tab)}>{tab === 'resident' ? 'Resident Account' : 'Admin & Staff'}</button>)}
        </div>
        <div role="tabpanel" id="login-panel" aria-labelledby={`${portal}-tab`}>
          <p className="auth-form-hint">{resident ? 'Enter your Resident Number.' : 'Enter your Staff Number or staff username.'}</p>
          {error && <div className="alert alert-error" role="alert">{error}</div>}
          <form noValidate onSubmit={submit} aria-busy={pending}>
            <label htmlFor="identifier">{resident ? 'Resident Number' : 'Username / Staff Number'} <span className="req">*</span></label>
            <input type="text" id="identifier" name="identifier" placeholder={resident ? 'e.g. R-2026-0001' : 'e.g. S-2026-0001 or admin'}
              value={identifier} onChange={(event) => setIdentifier(event.target.value)} required autoFocus autoComplete="username" />
            <label htmlFor="password">Password <span className="req">*</span></label>
            <PasswordField key={portal} id="password" placeholder="Enter your password" required autoComplete="current-password"
              value={password} onChange={(event) => setPassword(event.target.value)} />
            <button type="submit" className="btn-block" disabled={pending}>Log In</button>
          </form>
          <p className="auth-switch-hint">{resident ? 'Admin or staff member? ' : 'Have a resident account? '}
            <a href={`#${resident ? 'staff' : 'resident'}-tab`} onClick={(event) => {
              event.preventDefault()
              if (!pending) switchPortal(resident ? 'staff' : 'resident')
            }}>Switch to {resident ? 'Admin & Staff' : 'Resident Account'}.</a>
          </p>
        </div>
        <p className="auth-recovery">Forgot your password? Visit the Barangay Health Center and ask staff to
          reset it. Residents bring a valid ID; staff accounts are reset by the administrator.</p>
        <p className="auth-footnote">Residents use the Resident Number issued by the health center. Staff use the
          username assigned by the administrator. New accounts start with a temporary
          password and are prompted to set their own on first login.</p>
      </div>
    </div></div>
  )
}
