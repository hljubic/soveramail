import type { MessageSummary } from '~/types/api'
/** Retrieval ("RAG") hyper-parameters, editable on the AI instructions page. */
export interface RagSettings {
  top_k: number
  style_examples: number
  thread_messages: number
  min_score: number
  exclude_thread: boolean
  group_by_thread: boolean
  include_replies: boolean
  max_original_chars: number
  max_context_chars: number
  max_style_chars: number
  max_thread_chars: number
  temperature: number
  /** Prepend the learned writing-style profile (from all sent mail) to every draft. */
  use_style_profile: boolean
  max_profile_chars: number
  /** Ollama context window for chat calls (its default 4096 silently truncates long prompts). */
  context_tokens: number
}

export interface AiSettings {
  provider: 'ollama'
  base_url: string
  model: string | null
  embedding_model: string | null
  user_prompt: string
  streaming: boolean
  /** Reasoning models may think before drafts / smart search (slower, sometimes better). */
  thinking: boolean
  /** Model that sorts mail into categories; null = the chat model. Urgency always uses the chat model. */
  classify_model: string | null
  /** Model that writes "Summarize" summaries; null = the chat model. */
  summary_model: string | null
  /** Only conversations from the last N days are sorted; null = the whole mailbox. */
  classify_since_days: number | null
  allow_remote: boolean
  enabled: boolean
  ready: boolean
  rag: RagSettings
  /** Default language of AI drafts (ISO 639-1); null = follow the incoming mail. */
  reply_language: string | null
  /** 'auto' = judged by the local model from your sent mail, 'user' = chosen here, null = never set. */
  reply_language_source: 'auto' | 'user' | null
  reply_language_detected_at: string | null
  reply_language_checked_at: string | null
  languages: { code: string; name: string; native: string }[]
  /** Processing also writes "Summarize" summaries, above summary_min_words, never for bulk mail when summary_skip_bulk. */
  summaries_in_pass: boolean
  summary_min_words: number
  summary_skip_bulk: boolean
  /** Second pass over AI drafts and summaries that fixes invented words and wrong forms. */
  proofread: boolean
}

export type AiProcessingKey = 'summaries_in_pass' | 'summary_min_words' | 'summary_skip_bulk' | 'proofread'

export interface LanguageDetection {
  ready: boolean
  code: string | null
  name: string | null
  confidence: number
  reason: string | null
  samples: number
}

export interface DraftContext {
  id: number
  subject: string | null
  from: string | null
  date: string | null
  score: number
  mine: boolean
}

export interface SmartSearchInterpretation {
  topic: string | null
  attachment_types: string[]
  attachments_mode: 'any' | 'all'
  has_attachments: boolean | null
  from: string | null
  to: string | null
  date_from: string | null
  date_to: string | null
  unread: boolean | null
  starred: boolean | null
  folder: string
}

export interface SmartSearchMeta {
  query: string
  interpretation: SmartSearchInterpretation
  /** Human-readable chips: "About: silabusi", "Attachments: PDF + Word", … */
  filters: string[]
  ranking: 'semantic' | 'fulltext' | 'recent'
  /** false = the model produced no usable JSON; the whole sentence was used as the topic. */
  parsed: boolean
  total: number
}

export interface StyleStats {
  mails: number
  period?: { from: string | null; to: string | null }
  language?: Record<string, number>
  formality?: { formal: number; informal: number; neutral: number }
  length?: { median_words: number; avg_words: number; avg_sentences: number; avg_words_per_sentence: number; avg_paragraphs: number; short_share: number; long_share: number }
  greetings?: { text: string; mails: number; share: number }[]
  no_greeting_share?: number
  closings?: { text: string; mails: number; share: number }[]
  no_closing_share?: number
  sign_name?: { text: string; mails: number; share: number } | null
  signature?: { lines: string[]; share: number }
  frequent_words?: { word: string; mails: number }[]
  distinctive_words?: { word: string; mails: number; score: number }[]
  phrases?: { text: string; mails: number }[]
  punctuation?: Record<string, number>
  inline_greeting_share?: number
  openers?: { text: string; count: number }[]
  sentence_starters?: { text: string; count: number }[]
  short_replies?: { text: string; count: number }[]
  requests?: string[]
  questions?: string[]
  example_sentences?: string[]
  by_register?: Record<'formal' | 'informal', { mails: number; greetings: { text: string; mails: number; share: number }[]; closings: { text: string; mails: number; share: number }[] }>
  orthography?: Record<string, number>
  time_formats?: Record<string, number>
  language_by_domain?: { domain: string; mails: number; language: string; share: number }[]
  /** What the user changed in AI drafts before sending them (null until the first such send). */
  feedback?: StyleFeedback | null
}

export interface StyleCorrection { from: string; to: string; kind: string; count: number; last_at: string | null }

export interface StyleFeedback {
  drafts: number
  unchanged_share: number
  avg_similarity: number
  avg_length_ratio: number
  shortened_share: number
  lengthened_share: number
  edit_kinds: Record<string, number>
  replacements: StyleCorrection[]
  spelling: StyleCorrection[]
  removed: StyleCorrection[]
  added: StyleCorrection[]
  greetings: StyleCorrection[]
  closings: StyleCorrection[]
  last_sent_at: string | null
}

/** How the owner of a mailbox writes, learned from their sent mail (GET /accounts/:id/ai/style-profile). */
export interface StyleProfile {
  account_id: number
  email: string
  exists: boolean
  enabled: boolean
  built: boolean
  built_at: string | null
  analysed_until: string | null
  sample_count: number
  queued: boolean
  stale: boolean
  summary: string | null
  summary_model: string | null
  summary_built_at: string | null
  summary_edited: boolean
  last_error: string | null
  stats: StyleStats | null
  prompt_preview: string | null
}

export interface IndexStatus {
  enabled: boolean
  embedding_model: string | null
  accounts: { account_id: number; email: string; indexed: number; total: number }[]
}

export interface AiModel {
  name: string
  size: number
  family: string | null
  parameters: string | null
  quantization: string | null
  capabilities: string[]
}

export interface AiProbe {
  ok: boolean
  base_url: string
  version?: string | null
  models?: AiModel[]
  message?: string
}

/**
 * Local AI (Ollama) settings + "Compose with AI" drafts.
 */
export function useAi() {
  const api = useApi()

  const settings = useState<AiSettings | null>('ai-settings', () => null)

  const ready = computed(() => settings.value?.ready ?? false)

  async function load() {
    try {
      const res = await api.get<{ data: AiSettings }>('/ai/settings')
      settings.value = res.data
    } catch {
      settings.value = null
    }
  }

  async function save(patch: Partial<Pick<AiSettings, 'base_url' | 'model' | 'embedding_model' | 'enabled' | 'user_prompt' | 'streaming' | 'thinking' | 'classify_model' | 'summary_model' | 'classify_since_days' | 'allow_remote' | 'reply_language' | AiProcessingKey>> & { rag?: Partial<RagSettings> }) {
    const res = await api.put<{ data: AiSettings }>('/ai/settings', patch)
    settings.value = res.data
    return res.data
  }

  async function probe(baseUrl?: string, allowRemote?: boolean): Promise<AiProbe> {
    try {
      return await api.post<AiProbe>('/ai/probe', { ...(baseUrl ? { base_url: baseUrl } : {}), ...(allowRemote !== undefined ? { allow_remote: allowRemote } : {}) })
    } catch (e) {
      const err = e as { message: string; data?: AiProbe }
      return err.data?.ok === false ? err.data : { ok: false, base_url: baseUrl ?? '', message: err.message }
    }
  }

  /** suggestion_id identifies this AI draft so the sent version can be compared with it later. */
  type Draft = { text: string; context: DraftContext[]; suggestion_id?: number | null }
  type ReplyOpts = { instructions?: string; currentDraft?: string; signal?: AbortSignal }
  type NewOpts = { to?: string[]; subject?: string; instructions?: string; currentDraft?: string; signal?: AbortSignal }

  // Request shapes shared by the plain and the streaming variant of each draft call.
  const replyPath = (messageId: number) => `/messages/${messageId}/ai/draft`
  const replyBody = (kind: 'reply' | 'forward', o: ReplyOpts) => ({ kind, instructions: o.instructions || undefined, current_draft: o.currentDraft || undefined })
  const newPath = (accountId: number) => `/accounts/${accountId}/ai/draft`
  const newBody = (o: NewOpts) => ({ to: o.to ?? [], subject: o.subject || undefined, instructions: o.instructions || undefined, current_draft: o.currentDraft || undefined })

  async function draft(messageId: number, kind: 'reply' | 'forward', opts: ReplyOpts = {}): Promise<Draft> {
    return (await api.post<{ data: Draft }>(replyPath(messageId), replyBody(kind, opts))).data
  }

  /**
   * Streaming variant: server-sent events (context → delta… → [stage: proofreading] → done|error).
   * onDelta gets each chunk; resolves with the full text + context.
   */
  async function draftStream(messageId: number, kind: 'reply' | 'forward', opts: ReplyOpts = {}, onDelta: (chunk: string) => void = () => {}, onContext: (ctx: DraftContext[]) => void = () => {}, onStage: (stage: string) => void = () => {}): Promise<Draft> {
    return streamRequest(replyPath(messageId), replyBody(kind, opts), onDelta, onContext, opts.signal, onStage)
  }

  /** New e-mail from notes (no original message). */
  async function draftNew(accountId: number, opts: NewOpts): Promise<Draft> {
    return (await api.post<{ data: Draft }>(newPath(accountId), newBody(opts))).data
  }

  async function draftNewStream(accountId: number, opts: NewOpts, onDelta: (chunk: string) => void = () => {}, onContext: (ctx: DraftContext[]) => void = () => {}, onStage: (stage: string) => void = () => {}): Promise<Draft> {
    return streamRequest(newPath(accountId), newBody(opts), onDelta, onContext, opts.signal, onStage)
  }

  async function streamRequest(
    path: string,
    payload: Record<string, unknown>,
    onDelta: (chunk: string) => void,
    onContext: (ctx: DraftContext[]) => void,
    signal?: AbortSignal,
    onStage: (stage: string) => void = () => {},
  ): Promise<Draft> {
    const res = await fetch(api.url(path), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', ...api.headers() },
      signal,
      body: JSON.stringify({ ...payload, stream: true }),
    })
    if (!res.ok || !res.body) {
      const body = (await res.json().catch(() => null)) as { message?: string } | null
      throw new ApiError(body?.message || `Request failed (${res.status})`, res.status, body ?? undefined)
    }

    const reader = res.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let text = ''
    let context: DraftContext[] = []
    let suggestionId: number | null = null

    const handle = (block: string) => {
      let event = 'message'
      const dataLines: string[] = []
      for (const line of block.split('\n')) {
        if (line.startsWith('event:')) event = line.slice(6).trim()
        else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim())
      }
      if (!dataLines.length) return
      const payload = JSON.parse(dataLines.join('\n')) as Record<string, unknown>
      if (event === 'context') {
        context = (payload.context as DraftContext[]) ?? []
        onContext(context)
      } else if (event === 'delta') {
        const chunk = String(payload.text ?? '')
        text += chunk
        onDelta(chunk)
      } else if (event === 'stage') {
        // Second pass (proofreading): the streamed text is replaced by the corrected one on "done".
        onStage(String(payload.stage ?? ''))
      } else if (event === 'done') {
        text = String(payload.text ?? text)
        suggestionId = typeof payload.suggestion_id === 'number' ? payload.suggestion_id : null
      } else if (event === 'error') {
        throw new ApiError(String(payload.message ?? 'AI error'), payload.ai_unavailable ? 503 : 502, payload)
      }
    }

    // eslint-disable-next-line no-constant-condition
    while (true) {
      const { value, done } = await reader.read()
      if (done) break
      buffer += decoder.decode(value, { stream: true })
      let idx: number
      while ((idx = buffer.indexOf('\n\n')) !== -1) {
        const block = buffer.slice(0, idx)
        buffer = buffer.slice(idx + 2)
        if (block.trim()) handle(block)
      }
    }
    if (buffer.trim()) handle(buffer)

    return { text, context, suggestion_id: suggestionId }
  }

  /** Natural-language mailbox search (filters extracted by the local model + local vector ranking). */
  async function smartSearch(accountId: number, q: string, signal?: AbortSignal): Promise<{ data: MessageSummary[]; meta: SmartSearchMeta }> {
    return api.post<{ data: MessageSummary[]; meta: SmartSearchMeta }>(`/accounts/${accountId}/ai/search`, { q }, { signal })
  }

  /** Ask the local model which language the mailbox owner writes in and make it the reply language. */
  async function detectLanguage(accountId: number): Promise<LanguageDetection> {
    const res = await api.post<{ detection: LanguageDetection; data: AiSettings }>('/ai/language/detect', { account_id: accountId })
    settings.value = res.data
    return res.detection
  }

  // ---- writing-style profile
  async function styleProfile(accountId: number): Promise<StyleProfile> {
    return (await api.get<{ data: StyleProfile }>(`/accounts/${accountId}/ai/style-profile`)).data
  }

  async function rebuildStyleProfile(accountId: number): Promise<void> {
    await api.post(`/accounts/${accountId}/ai/style-profile/rebuild`)
  }

  async function updateStyleProfile(accountId: number, patch: { enabled?: boolean; summary?: string; reset_summary?: boolean }): Promise<StyleProfile> {
    return (await api.put<{ data: StyleProfile }>(`/accounts/${accountId}/ai/style-profile`, patch)).data
  }

  async function indexStatus(): Promise<IndexStatus> {
    const res = await api.get<{ data: IndexStatus }>('/ai/index')
    return res.data
  }

  async function runIndex(): Promise<void> {
    await api.post('/ai/index')
  }

  return { settings, ready, load, save, probe, draft, draftStream, draftNew, draftNewStream, smartSearch, detectLanguage, styleProfile, rebuildStyleProfile, updateStyleProfile, indexStatus, runIndex }
}
