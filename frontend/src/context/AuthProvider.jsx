import PropTypes from 'prop-types'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { apiRequest } from '../api/client.js'
import { AuthContext } from './authContext.js'

export default function AuthProvider({ children }) {
  const { key } = useLocation()
  const [session, setSession] = useState(null)
  const [checkedKey, setCheckedKey] = useState(null)
  const [error, setError] = useState('')
  const generation = useRef(0)

  const refresh = useCallback(async (signal) => {
    const request = ++generation.current
    try {
      const result = await apiRequest('/session', { signal })
      if (request === generation.current) {
        setSession(result.data)
        setError('')
      }
    } catch (failure) {
      if (failure.name === 'AbortError' || request !== generation.current) return
      if ([401, 403].includes(failure.status)) {
        setSession({ authenticated: false, user: null })
        setError('')
      }
      else {
        setSession(null)
        setError(failure.message)
      }
    } finally {
      if (!signal?.aborted && request === generation.current) setCheckedKey(key)
    }
  }, [key])

  useEffect(() => {
    const controller = new AbortController()
    // Skip the discarded StrictMode effect before starting the network read.
    queueMicrotask(() => { if (!controller.signal.aborted) refresh(controller.signal) })
    const revalidate = () => { if (document.visibilityState === 'visible') refresh(controller.signal) }
    window.addEventListener('focus', revalidate)
    document.addEventListener('visibilitychange', revalidate)
    return () => {
      controller.abort()
      window.removeEventListener('focus', revalidate)
      document.removeEventListener('visibilitychange', revalidate)
    }
  }, [refresh])

  async function mutate(path, body) {
    ++generation.current // A pre-mutation session read must never overwrite this result.
    try {
      const result = await apiRequest(path, { method: 'POST', body })
      setSession(result.data)
      setCheckedKey(key)
      setError('')
      return result
    } catch (failure) {
      if (path !== '/login' && [401, 403].includes(failure.status)) {
        setSession({ authenticated: false, user: null })
      }
      throw failure
    }
  }

  return (
    <AuthContext.Provider value={{
      user: session?.user, passwordRules: session?.password_rules || [],
      loading: checkedKey !== key, error, refresh,
      login: (credentials) => mutate('/login', credentials),
      logout: () => mutate('/logout'),
      changePassword: (passwords) => mutate('/change-password', passwords),
    }}>
      {children}
    </AuthContext.Provider>
  )
}

if (import.meta.env.DEV) {
  AuthProvider.propTypes = {
    children: PropTypes.node,
  }
}
