/**
 * "Process new mail" and the AI status line: what waits, the running pass, and the
 * models in RAM (with "Unload" for the ones Sovera loaded). Polled while the app is
 * open; the poll never loads a model.
 */
export interface AiRuntimeModel {
  name: string
  size: number
  size_vram: number
  expires_at: string | null
  /** Used by Sovera Mail (else another app's model, left alone). It stays in RAM until "Unload". */
  ours: boolean
}

export interface AiPassState {
  state: 'idle' | 'queued' | 'running' | 'done' | 'error'
  phase: 'threads' | 'style' | 'knowledge' | 'index' | null
  total: number
  done: number
  started_at: string | null
  finished_at: string | null
  error: string | null
  running: boolean
}

export interface AiRuntime {
  ready: boolean
  pending: { threads: number; index: number; style: boolean; knowledge: number }
  has_work: boolean
  pass: AiPassState | null
  models: AiRuntimeModel[]
}

export function useAiRuntime() {
  const api = useApi()
  const runtime = useState<AiRuntime | null>('ai-runtime', () => null)
  const starting = useState('ai-runtime-starting', () => false)
  const error = useState<string | null>('ai-runtime-error', () => null)

  const running = computed(() => starting.value || !!runtime.value?.pass?.running)

  async function refresh() {
    try {
      runtime.value = (await api.get<{ data: AiRuntime }>('/ai/runtime')).data
    } catch {
      /* the API is unreachable: the accounts banner says so */
    }
  }

  async function process() {
    if (running.value) return
    starting.value = true
    error.value = null
    try {
      await api.post('/ai/process')
      await refresh()
    } catch (e) {
      error.value = (e as Error).message
    } finally {
      starting.value = false
    }
  }

  async function unload(model: string) {
    error.value = null
    try {
      await api.post('/ai/runtime/unload', { model })
    } catch (e) {
      error.value = (e as Error).message
    }
    await refresh()
  }

  return { runtime, running, error, refresh, process, unload }
}
