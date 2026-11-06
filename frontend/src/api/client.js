// Vite proxies /api; production lives beside the original PHP application.
const base = import.meta.env.DEV ? '/api' : `${import.meta.env.BASE_URL.replace(/app\/$/, '')}api`
export const apiUrl = path => `${base}${path}`

export class ApiError extends Error {
  constructor(message, status = 0) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

export async function apiRequest(path, { body, headers, ...options } = {}) {
  const multipart = typeof FormData !== 'undefined' && body instanceof FormData
  let response
  try {
    response = await fetch(`${base}${path}`, {
      ...options,
      credentials: 'include',
      cache: 'no-store',
      headers: { Accept: 'application/json', ...(body === undefined || multipart ? {} : { 'Content-Type': 'application/json' }), ...headers },
      ...(body === undefined ? {} : { body: multipart ? body : JSON.stringify(body) }),
    })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ApiError('Unable to connect. Please try again.')
  }
  let result
  try {
    result = await response.json()
  } catch {
    throw new ApiError('The server returned an unexpected response. Please try again.', response.status)
  }
  if (!response.ok || result?.ok !== true) {
    throw new ApiError(result?.message || 'The request could not be completed. Please try again.', response.status)
  }
  return result
}
