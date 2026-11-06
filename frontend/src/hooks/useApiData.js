import { useEffect, useState } from 'react'
import { apiRequest } from '../api/client.js'

export default function useApiData(path) {
  const [state, setState] = useState({ path: null, data: null, error: '', redirect: null })
  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      try {
        const result = await apiRequest(path, { signal: controller.signal })
        if (!controller.signal.aborted) setState({ path, data: result.data, error: '', redirect: result.redirect })
      } catch (error) {
        if (!controller.signal.aborted) setState({ path, data: null, error: error.message, redirect: null })
      }
    })
    return () => controller.abort()
  }, [path])
  return state.path === path ? { ...state, loading: false } : { data: null, error: '', loading: true }
}
