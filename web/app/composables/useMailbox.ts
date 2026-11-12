import type { ComposeMode, ComposePayload, CursorPage, Folder, Label, LocalDraft, MessageDetail, MessageSummary, RemoteResult } from '~/types/api'
import type { SmartSearchMeta } from '~/composables/useAi'

export const FOLDERS: { key: Folder; label: string }[] = [
  { key: 'inbox', label: 'Inbox' },
  { key: 'starred', label: 'Starred' },
  { key: 'unread', label: 'Unread' },
  { key: 'sent', label: 'Sent' },
  { key: 'drafts', label: 'Drafts' },
  { key: 'archive', label: 'Archive' },
  { key: 'all', label: 'All mail' },
]

// Shared by every component that calls useMailbox() (list, sidebar, reader,
// composer, page). They used to be per-call closures, so a folder change in one
// component could not cancel a smart search or poll started from another one,
// and a late response replaced the newer list. ssr is off, so module scope is
// per window.
const shared = {
  requestSeq: 0,
  smartAbort: null as AbortController | null,
  noticeTimer: null as ReturnType<typeof setTimeout> | null,
  draftTimer: null as ReturnType<typeof setTimeout> | null,
}

/**
 * Message list + selected message for the active account.
 */
export function useMailbox() {
  const api = useApi()

  const folder = useState<Folder>('mailbox-folder', () => 'inbox')
  const label = useState<string | null>('mailbox-label', () => null)
  const search = useState<string>('mailbox-search', () => '')
  /** A local category: all folders, only conversations sorted into it (replaces folder / label). */
  const category = useState<number | null>('mailbox-category', () => null)
  /** List order by date received. */
  const sort = useState<'newest' | 'oldest'>('mailbox-sort', () => 'newest')

  // Smart (natural-language) search: while active the list shows its results instead of the folder.
  const smartQuery = useState<string | null>('mailbox-smart-query', () => null)
  const smartMeta = useState<SmartSearchMeta | null>('mailbox-smart-meta', () => null)
  const smartLoading = useState<boolean>('mailbox-smart-loading', () => false)
  /** The search box (top bar) sends the sentence to the local AI instead of filtering by keyword. */
  const smartMode = useState<boolean>('mailbox-smart-mode', () => false)
  /** Every message of the open conversation, oldest first (the reading view). */
  const thread = useState<MessageSummary[]>('mailbox-thread', () => [])

  const messages = useState<MessageSummary[]>('mailbox-messages', () => [])
  const nextCursor = useState<string | null>('mailbox-next-cursor', () => null)
  const listLoading = useState<boolean>('mailbox-list-loading', () => false)
  const listError = useState<string | null>('mailbox-list-error', () => null)

  const labels = useState<Label[]>('mailbox-labels', () => [])

  const selectedId = useState<number | null>('mailbox-selected-id', () => null)
  const selected = useState<MessageDetail | null>('mailbox-selected', () => null)
  const detailLoading = useState<boolean>('mailbox-detail-loading', () => false)
  const detailError = useState<string | null>('mailbox-detail-error', () => null)

  const compose = useState<ComposeMode | null>('mailbox-compose', () => null)

  // Unsent reply/forward text, mirrored here by the composer so it can be saved
  // even when the panel is being torn down (mark as unread, switching messages).
  const draftBuffer = useState<({ messageId: number } & LocalDraft) | null>('mailbox-draft-buffer', () => null)
  const draftSaved = useState<string>('mailbox-draft-saved', () => '')   // JSON of last persisted buffer
  const notice = useState<{ kind: 'info' | 'error'; text: string } | null>('mailbox-notice', () => null)

  const perPage = 50
  /** Pages of 50 conversations, like the classic clients: page index, cursor of each page, total. */
  const page = useState<number>('mailbox-page', () => 0)
  const pageCursors = useState<(string | null)[]>('mailbox-page-cursors', () => [null])
  const total = useState<number | null>('mailbox-total', () => null)

  /** One page of the current folder/label/search for an account. */
  function fetchPage(accountId: number, cursor?: string | null) {
    // One row per conversation, like a classic mail client.
    const q: Record<string, unknown> = { per_page: perPage, conversations: 1 }
    if (category.value) q.category = category.value
    else if (label.value) q.label = label.value
    else q.folder = folder.value
    if (search.value.trim()) q.q = search.value.trim()
    if (sort.value !== 'newest') q.sort = sort.value
    if (cursor) q.cursor = cursor
    return api.get<CursorPage<MessageSummary>>(`/accounts/${accountId}/messages`, q)
  }

  async function loadMessages(accountId: number | null) {
    // Any folder / label / search / account change leaves smart-search mode.
    smartQuery.value = null
    smartMeta.value = null
    shared.smartAbort?.abort()
    shared.smartAbort = null
    smartLoading.value = false
    if (!accountId) {
      messages.value = []
      nextCursor.value = null
      return
    }
    const seq = ++shared.requestSeq
    listLoading.value = true
    listError.value = null
    try {
      const res = await fetchPage(accountId)
      if (seq !== shared.requestSeq) return
      messages.value = res.data
      nextCursor.value = res.meta.next_cursor
      page.value = 0
      pageCursors.value = [null]
      total.value = res.meta.total ?? null
      // Keep the open conversation if it is still in the list, else go back to the list.
      const openThread = selected.value?.thread_id
      if (!messages.value.some(m => m.id === selectedId.value || (openThread && m.thread_id === openThread))) {
        selectedId.value = null
        selected.value = null
      }
    } catch (e) {
      if (seq === shared.requestSeq) listError.value = (e as Error).message
    } finally {
      if (seq === shared.requestSeq) listLoading.value = false
    }
  }

  /** Silent refresh (polling) of the first page, without flashing the list. */
  async function refreshMessages(accountId: number | null) {
    if (!accountId || listLoading.value || smartQuery.value !== null || page.value !== 0) return
    const seq = shared.requestSeq
    try {
      const res = await fetchPage(accountId)
      // The user switched account / folder / search / page meanwhile: this page is stale.
      if (seq !== shared.requestSeq || listLoading.value || smartQuery.value !== null || page.value !== 0) return
      messages.value = res.data
      nextCursor.value = res.meta.next_cursor
      if (res.meta.total !== undefined) total.value = res.meta.total
    } catch {
      /* polling errors are not surfaced */
    }
  }

  /** ‹ › in the list toolbar: the previous / next 50 conversations. */
  async function goToPage(accountId: number | null, step: -1 | 1) {
    if (!accountId || listLoading.value) return
    const target = page.value + step
    const cursor = step === 1 ? nextCursor.value : pageCursors.value[target]
    if (target < 0 || (step === 1 && !cursor)) return
    listLoading.value = true
    const seq = ++shared.requestSeq
    try {
      const res = await fetchPage(accountId, cursor)
      if (seq !== shared.requestSeq) return
      if (step === 1) pageCursors.value = [...pageCursors.value.slice(0, target), cursor ?? null]
      page.value = target
      messages.value = res.data
      nextCursor.value = res.meta.next_cursor
    } catch (e) {
      listError.value = (e as Error).message
    } finally {
      if (seq === shared.requestSeq) listLoading.value = false
    }
  }

  /**
   * The page on screen again, whatever page it is (refreshMessages only touches the first
   * page, and skips while anything else loads): after moving conversations away.
   */
  async function reloadPage(accountId: number | null) {
    if (!accountId || smartQuery.value !== null) return
    const seq = ++shared.requestSeq
    const cursor = page.value === 0 ? null : (pageCursors.value[page.value] ?? null)
    try {
      const res = await fetchPage(accountId, cursor ?? undefined)
      if (seq !== shared.requestSeq) return
      messages.value = res.data
      nextCursor.value = res.meta.next_cursor
      if (res.meta.total !== undefined) total.value = res.meta.total
    } catch (e) {
      listError.value = (e as Error).message
    }
  }

  async function loadMore(accountId: number | null) {
    if (!accountId || !nextCursor.value || listLoading.value) return
    listLoading.value = true
    const seq = shared.requestSeq
    try {
      const page = await fetchPage(accountId, nextCursor.value)
      if (seq !== shared.requestSeq) return
      const known = new Set(messages.value.map(m => m.id))
      messages.value = [...messages.value, ...page.data.filter(m => !known.has(m.id))]
      nextCursor.value = page.meta.next_cursor
    } catch (e) {
      listError.value = (e as Error).message
    } finally {
      listLoading.value = false
    }
  }


  /** Run a natural-language search; the result replaces the list until cleared. */
  async function runSmartSearch(accountId: number | null, q: string) {
    q = q.trim()
    if (!accountId || !q) return
    shared.smartAbort?.abort()
    const ctl = (shared.smartAbort = new AbortController())
    const seq = ++shared.requestSeq
    smartQuery.value = q
    smartLoading.value = true
    listLoading.value = true
    listError.value = null
    try {
      const res = await useAi().smartSearch(accountId, q, ctl.signal)
      if (seq !== shared.requestSeq) return
      messages.value = res.data
      smartMeta.value = res.meta
      nextCursor.value = null
      if (!messages.value.some(m => m.id === selectedId.value)) {
        selectedId.value = null
        selected.value = null
      }
    } catch (e) {
      if (ctl.signal.aborted || seq !== shared.requestSeq) return
      listError.value = (e as Error).message
      throw e
    } finally {
      if (seq === shared.requestSeq) {
        listLoading.value = false
        smartLoading.value = false
      }
    }
  }

  /** Back to the normal folder list. */
  async function clearSmartSearch(accountId: number | null) {
    shared.smartAbort?.abort()
    if (smartQuery.value === null) return
    await loadMessages(accountId)
  }

  async function loadLabels(accountId: number | null) {
    if (!accountId) {
      labels.value = []
      return
    }
    try {
      const res = await api.get<{ data: Label[] }>(`/accounts/${accountId}/labels`)
      labels.value = res.data
    } catch {
      labels.value = []
    }
  }

  /** Patch the list row + reading pane in place (no refetch). */
  function applyLocal(id: number, patch: Partial<MessageSummary>) {
    messages.value = messages.value.map(m => (m.id === id ? { ...m, ...patch } : m))
    thread.value = thread.value.map(m => (m.id === id ? { ...m, ...patch } : m))
    if (selected.value?.id === id) selected.value = { ...selected.value, ...patch }
  }

  function showNotice(kind: 'info' | 'error', text: string, ms = 4000) {
    notice.value = { kind, text }
    if (shared.noticeTimer) clearTimeout(shared.noticeTimer)
    shared.noticeTimer = setTimeout(() => (notice.value = null), ms)
  }

  /**
   * Read/unread and starred share one flow: optimistic local patch, PATCH the
   * API, apply what the server stored, roll back on a network error.
   */
  async function setFlag(id: number, field: 'is_unread' | 'is_starred', label: 'UNREAD' | 'STARRED', on: boolean): Promise<RemoteResult> {
    const current = messages.value.find(m => m.id === id) ?? selected.value
    const was = current?.[field] ?? !on
    const labels = (current?.label_ids ?? []).filter(l => l !== label)
    applyLocal(id, { [field]: on, label_ids: on ? [...labels, label] : labels })
    try {
      const res = await api.patch<{ data: MessageDetail; remote: RemoteResult; remote_error: string | null }>(`/messages/${id}`, { [field]: on })
      applyLocal(id, { [field]: res.data[field], label_ids: res.data.label_ids })
      if (res.remote === 'failed') showNotice('error', res.remote_error || 'Could not update the mail server.')
      if (field === 'is_unread') void useAccounts().fetchAccounts() // sidebar unread badge
      return res.remote
    } catch (e) {
      applyLocal(id, { [field]: was })
      showNotice('error', (e as Error).message)
      return 'failed'
    }
  }

  const setUnread = (id: number, unread: boolean) => setFlag(id, 'is_unread', 'UNREAD', unread)

  /**
   * Bulk "Mark as read / unread" (list checkboxes): read = the whole conversations, unread =
   * their newest message. Shown at once; the server mirrors it to Gmail in the background.
   */
  async function setUnreadMany(ids: number[], unread: boolean) {
    const picked = new Set(ids)
    const threads = new Set(messages.value.filter(m => picked.has(m.id)).map(m => m.thread_id))
    messages.value = messages.value.map(m => (picked.has(m.id) || (!unread && threads.has(m.thread_id))
      ? { ...m, is_unread: unread, thread_unread: unread ? Math.max(1, m.thread_unread ?? 0) : 0 }
      : m))
    await api.put('/messages/read-state', { message_ids: ids, unread })
    void useAccounts().fetchAccounts() // sidebar unread badge
  }
  const setStarred = (id: number, starred: boolean) => setFlag(id, 'is_starred', 'STARRED', starred)

  function openCompose(kind: 'reply' | 'reply-all' | 'forward' | 'new', draft: LocalDraft | null = null) {
    if (kind === 'new') {
      compose.value = { kind: 'new' }
      return
    }
    if (!selected.value) return
    compose.value = kind === 'reply-all'
      ? { kind: 'reply', original: selected.value, draft, all: true }
      : { kind, original: selected.value, draft }
  }

  function closeCompose() {
    compose.value = null
    draftBuffer.value = null
  }

  // ---- local drafts ----

  /** Called by the composer on every change; persists after a short pause. */
  function updateDraftBuffer(d: ({ messageId: number } & LocalDraft) | null) {
    draftBuffer.value = d
    if (shared.draftTimer) clearTimeout(shared.draftTimer)
    shared.draftTimer = setTimeout(() => void flushDraftSave(), 1200)
  }

  /** Persist the buffered draft now (or delete it when it is empty). */
  async function flushDraftSave(): Promise<void> {
    if (shared.draftTimer) {
      clearTimeout(shared.draftTimer)
      shared.draftTimer = null
    }
    const d = draftBuffer.value
    if (!d) return
    const key = JSON.stringify(d)
    if (key === draftSaved.value) return
    try {
      if (!d.body.trim()) {
        if (messages.value.find(m => m.id === d.messageId)?.has_draft || selected.value?.has_draft) {
          await api.del(`/messages/${d.messageId}/local-draft`)
          applyLocal(d.messageId, { has_draft: false })
        }
      } else {
        await api.put(`/messages/${d.messageId}/local-draft`, { kind: d.kind, to: d.to, cc: d.cc, bcc: d.bcc, subject: d.subject, body: d.body })
        applyLocal(d.messageId, { has_draft: true })
      }
      draftSaved.value = key
    } catch {
      /* keep the buffer; next change retries */
    }
  }

  async function discardLocalDraft(messageId: number) {
    if (shared.draftTimer) clearTimeout(shared.draftTimer)
    draftBuffer.value = null
    draftSaved.value = ''
    try {
      await api.del(`/messages/${messageId}/local-draft`)
    } catch {
      /* nothing to delete */
    }
    applyLocal(messageId, { has_draft: false })
    if (selected.value?.id === messageId) selected.value = { ...selected.value, local_draft: null, has_draft: false }
  }

  function composeTarget(action: 'send' | 'draft'): { url: string; body: Record<string, unknown>; label: string } {
    const c = compose.value
    if (!c) throw new Error('Nothing to send.')
    if (c.kind === 'new') {
      const accountId = useAccounts().activeAccountId.value
      if (!accountId) throw new Error('No account selected.')
      return { url: `/accounts/${accountId}/messages/${action}`, body: {}, label: 'Message sent.' }
    }
    return {
      url: action === 'send' ? `/messages/${c.original.id}/${c.kind}` : `/messages/${c.original.id}/draft`,
      body: action === 'draft' ? { kind: c.kind } : {},
      label: c.kind === 'reply' ? 'Reply sent.' : 'Message forwarded.',
    }
  }

  async function send(payload: ComposePayload, idempotencyKey: string): Promise<MessageDetail> {
    const { url, body, label } = composeTarget('send')
    const originalId = compose.value?.kind !== 'new' ? compose.value?.original.id : undefined
    const res = await api.post<{ data: MessageDetail }>(url, {
      ...body,
      ...payload,
      confirm_send: true,
      idempotency_key: idempotencyKey,
    } as unknown as Record<string, unknown>)
    if (shared.draftTimer) clearTimeout(shared.draftTimer)
    draftBuffer.value = null
    draftSaved.value = ''
    if (originalId) applyLocal(originalId, { has_draft: false })
    finishCompose(label)
    return res.data
  }

  async function saveDraft(payload: ComposePayload): Promise<MessageDetail> {
    const { url, body } = composeTarget('draft')
    const res = await api.post<{ data: MessageDetail }>(url, { ...body, ...payload } as unknown as Record<string, unknown>)
    finishCompose('Saved to Drafts.')
    return res.data
  }

  /** Close the composer and go back to the start view, like closing the conversation. */
  function finishCompose(notice: string) {
    compose.value = null
    showNotice('info', notice)
    selectedId.value = null
    selected.value = null
  }

  const RISK_WARNING = [
    'DANGEROUS ATTACHMENT',
    '',
    'This message carries an attachment that was screened as dangerous (an executable, script, macro document, archive or a disguised file).',
    'You can read the text, but the attachment(s) will stay blocked: no preview, no download, no forwarding. Nothing from this message is fetched automatically.',
    '',
    'Do you understand the risk and want to open the message text?',
  ].join('\n')

  /** Before a risky message is opened once: the user has to say they know. */
  async function acknowledgeRisk(id: number): Promise<boolean> {
    // Not in the list (a link or a refresh on ?m=): ask the message itself instead of assuming the worst.
    const summary = messages.value.find(m => m.id === id) ?? (await api.get<{ data: MessageDetail }>(`/messages/${id}`)).data
    if (!summary.is_risky || summary.risk_acknowledged) return true
    if (!window.confirm(RISK_WARNING)) return false
    const res = await api.post<{ data: MessageDetail }>(`/messages/${id}/acknowledge-risk`)
    applyLocal(id, { risk_acknowledged: res.data.risk_acknowledged })
    return true
  }

  async function select(id: number | null, { markRead = true } = {}) {
    await flushDraftSave()
    if (id !== null) {
      try {
        if (!(await acknowledgeRisk(id))) return
      } catch (e) {
        showNotice('error', (e as Error).message)
        return
      }
    }
    selectedId.value = id
    selected.value = null
    detailError.value = null
    if (id === null) return
    detailLoading.value = true
    try {
      const res = await api.get<{ data: MessageDetail }>(`/messages/${id}`)
      if (selectedId.value === id) {
        selected.value = res.data
        // Opening a message marks it read, like every mail client.
        if (markRead && res.data.is_unread) void setUnread(id, false)
        void loadThread(res.data, markRead)
        // An unfinished reply/forward reopens right away.
        if (res.data.local_draft) {
          draftSaved.value = JSON.stringify({ messageId: id, ...res.data.local_draft, updated_at: undefined })
          compose.value = { kind: res.data.local_draft.kind, original: res.data, draft: res.data.local_draft }
        }
      }
    } catch (e) {
      if (selectedId.value === id) detailError.value = (e as Error).message
    } finally {
      if (selectedId.value === id) detailLoading.value = false
    }
  }

  /**
   * The whole conversation of the open message (oldest first). Opening a
   * conversation marks all of it read, and its row in the list follows.
   */
  async function loadThread(open: MessageDetail, markRead: boolean) {
    if (!open.thread_id) {
      thread.value = [open]
      return
    }
    if (!thread.value.some(m => m.thread_id === open.thread_id)) thread.value = [open]
    try {
      const res = await api.get<CursorPage<MessageSummary>>(`/accounts/${open.account_id}/messages`, { thread: open.thread_id, per_page: 200 })
      if (selected.value?.thread_id !== open.thread_id) return
      thread.value = res.data.length ? res.data : [open]
      if (markRead) {
        for (const m of thread.value) if (m.is_unread && m.id !== open.id) void setUnread(m.id, false)
        messages.value = messages.value.map(r => (r.thread_id === open.thread_id ? { ...r, is_unread: false, thread_unread: 0 } : r))
      }
    } catch {
      /* the open message alone is still shown */
    }
  }

  /** The list row of the open conversation, and its neighbours (‹ ›). */
  function rowIndexOfOpen(): number {
    const t = selected.value?.thread_id
    return messages.value.findIndex(m => m.id === selectedId.value || (t !== undefined && t !== null && m.thread_id === t))
  }

  async function openNeighbour(step: -1 | 1) {
    const i = rowIndexOfOpen()
    const next = i < 0 ? undefined : messages.value[i + step]
    if (next) await select(next.id)
  }

  function setFolder(f: Folder) {
    folder.value = f
    label.value = null
    category.value = null
  }

  function setLabel(providerLabelId: string) {
    label.value = providerLabelId
    category.value = null
  }

  function setCategory(id: number) {
    category.value = id
    label.value = null
  }

  return {
    FOLDERS,
    folder,
    label,
    search,
    sort,
    messages,
    nextCursor,
    listLoading,
    listError,
    labels,
    selectedId,
    selected,
    detailLoading,
    detailError,
    compose,
    notice,
    showNotice,
    setUnread,
    setUnreadMany,
    setStarred,
    openCompose,
    closeCompose,
    draftBuffer,
    updateDraftBuffer,
    flushDraftSave,
    discardLocalDraft,
    send,
    saveDraft,
    loadMessages,
    refreshMessages,
    reloadPage,
    smartQuery,
    smartMeta,
    smartLoading,
    runSmartSearch,
    clearSmartSearch,
    loadMore,
    loadLabels,
    select,
    setFolder,
    setLabel,
    category,
    setCategory,
    smartMode,
    thread,
    page,
    perPage,
    total,
    goToPage,
    rowIndexOfOpen,
    openNeighbour,
  }
}
