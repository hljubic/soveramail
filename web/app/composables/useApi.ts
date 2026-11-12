import type { FetchOptions } from 'ofetch'

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly data?: unknown,
  ) {
    super(message)
  }
}

/**
 * Thin fetch wrapper for the local Sovera Mail API.
 */
export function useApi() {
  const config = useRuntimeConfig()

  const base = (config.public.apiBase as string).replace(/\/+$/, '')
  const apiKey = config.public.apiKey as string

  // X-Sovera-Client is required by the API on every state-changing call: a custom
  // header forces a CORS preflight, so pages from other origins cannot drive the local API.
  const headers = (): Record<string, string> => ({ 'X-Sovera-Client': `web/${config.public.appVersion}`, ...(apiKey ? { 'X-Api-Key': apiKey } : {}) })

  const client = $fetch.create({
    baseURL: `${base}/api`,
    headers: headers(),
    retry: 0,
    onResponseError({ response }) {
      const body = response._data as { message?: string } | undefined
      throw new ApiError(body?.message || `Request failed (${response.status})`, response.status, body)
    },
  })

  async function request<T>(path: string, opts: FetchOptions<'json'> = {}): Promise<T> {
    try {
      return (await client<T>(path, opts as never)) as T
    } catch (e) {
      if (e instanceof ApiError) throw e
      const err = e as { status?: number; data?: { message?: string }; message?: string }
      throw new ApiError(err?.data?.message || err?.message || 'Cannot reach the local API', err?.status ?? 0, err?.data)
    }
  }

  /** For <img>/<iframe>/<a> URLs: adds the API key as a query param when one is configured. */
  function assetUrl(url: string | null | undefined): string | null {
    if (!url) return null
    if (!apiKey) return url
    const u = new URL(url)
    u.searchParams.set('api_key', apiKey)
    return u.toString()
  }

  return {
    base,
    /** Same base URL + headers as the ofetch client, for raw `fetch` (SSE). */
    url: (path: string) => `${base}/api${path}`,
    headers,
    assetUrl,
    get: <T>(path: string, query?: Record<string, unknown>, extraHeaders?: Record<string, string>) => request<T>(path, { method: 'GET', query, ...(extraHeaders ? { headers: { ...headers(), ...extraHeaders } } : {}) }),
    post: <T>(path: string, body?: Record<string, unknown>, opts: { signal?: AbortSignal } = {}) => request<T>(path, { method: 'POST', body, signal: opts.signal }),
    patch: <T>(path: string, body?: Record<string, unknown>) => request<T>(path, { method: 'PATCH', body }),
    put: <T>(path: string, body?: Record<string, unknown>) => request<T>(path, { method: 'PUT', body }),
    del: <T>(path: string, body?: Record<string, unknown>) => request<T>(path, { method: 'DELETE', body }),
  }
}
