<script setup lang="ts">
import { ChevronRight, FileText, Loader2, Paperclip, Send, Sparkles, Trash2, X } from 'lucide-vue-next'
import type { ComposeMode } from '~/types/api'
import type { DraftContext } from '~/composables/useAi'

const props = defineProps<{ mode: ComposeMode }>()
const emit = defineEmits<{ close: [] }>()

const mailbox = useMailbox()
const { activeAccount } = useAccounts()
const ai = useAi()

const drafting = ref(false)
/** The AI draft's second pass (proofreading) is running: the text is corrected in place when it ends. */
const proofreading = ref(false)
/** A reasoning model is thinking before it writes (nothing to show yet). */
const thinking = ref(false)
const aiError = ref<string | null>(null)
const briefOpen = ref(false)
const brief = ref('')
const briefEl = ref<HTMLTextAreaElement | null>(null)
const BRIEF_PLACEHOLDER = `What should this e-mail say? For example:

Ask Ana whether Thursday at 10 works for the meeting, remind her to bring the signed contract, and mention that parking is behind the building.`
const aiContext = ref<DraftContext[] | null>(null)
// The AI draft the current text started from; sent with the mail so the API can learn from the edits.
const aiSuggestionId = ref<number | null>(null)
const aiContextOpen = ref(false) // the list of similar conversations is collapsed to one line by default

async function composeWithAi() {
  if (drafting.value) return
  if (!ai.ready.value) {
    await navigateTo('/ai')
    return
  }
  // New message: ask what the mail should say first.
  if (isNew.value && !briefOpen.value) {
    briefOpen.value = true
    aiError.value = null
    await nextTick()
    briefEl.value?.focus()
    return
  }
  await runAi()
}

// The running AI draft belongs to one compose session: switching reply/forward or
// closing the panel aborts it, so its text can never land in another mail.
let aiAbort: AbortController | null = null

async function runAi() {
  briefOpen.value = false
  drafting.value = true
  aiError.value = null
  aiAbort?.abort()
  const ctl = (aiAbort = new AbortController())
  const seed = body.value
  try {
    const onDelta = (chunk: string) => {
      if (ctl.signal.aborted) return
      thinking.value = false
      body.value += chunk
      bodyEl.value?.scrollTo({ top: bodyEl.value.scrollHeight })
    }
    const onContext = (ctx: DraftContext[]) => {
      if (!ctl.signal.aborted) aiContext.value = ctx
    }
    const onStage = (stage: string) => {
      if (ctl.signal.aborted) return
      proofreading.value = stage === 'proofreading'
      thinking.value = stage === 'thinking'
    }
    proofreading.value = false
    thinking.value = false
    const streaming = !!ai.settings.value?.streaming

    let result: Awaited<ReturnType<typeof ai.draft>> | null = null
    if (isNew.value) {
      const accountId = activeAccount.value?.id
      if (!accountId) throw new Error('No account selected.')
      const opts = { to: splitAddresses(to.value), subject: subject.value, currentDraft: seed, instructions: brief.value.trim() || undefined, signal: ctl.signal }
      if (streaming) body.value = ''
      result = streaming
        ? await ai.draftNewStream(accountId, opts, onDelta, onContext, onStage)
        : await ai.draftNew(accountId, opts)
    } else if (props.mode.kind !== 'new') {
      const { kind, original: orig } = props.mode
      if (streaming) body.value = ''
      result = streaming
        ? await ai.draftStream(orig.id, kind, { currentDraft: seed, signal: ctl.signal }, onDelta, onContext, onStage)
        : await ai.draft(orig.id, kind, { currentDraft: seed })
    }
    if (ctl.signal.aborted || !result) return
    body.value = result.text || body.value || seed
    aiContext.value = result.context
    aiSuggestionId.value = result.suggestion_id ?? null
    await nextTick()
    bodyEl.value?.focus()
  } catch (e) {
    if (ctl.signal.aborted) return
    // Never lose what the user typed: a failed (streamed) draft puts it back,
    // otherwise the emptied body would also delete the saved local draft.
    body.value = seed
    const err = e as { message: string; data?: { ai_unavailable?: boolean } }
    aiError.value = err.message
    if (err.data?.ai_unavailable) await ai.load()
  } finally {
    if (aiAbort === ctl) {
      aiAbort = null
      drafting.value = false
      proofreading.value = false
      thinking.value = false
    }
  }
}

onBeforeUnmount(() => aiAbort?.abort())

const isReply = computed(() => props.mode.kind === 'reply')
const isNew = computed(() => props.mode.kind === 'new')
const title = computed(() => (isNew.value ? 'New message' : isReply.value ? (props.mode.kind === 'reply' && props.mode.all ? 'Reply all' : 'Reply') : 'Forward'))

function prefixSubject(prefix: string, subject: string | null) {
  const s = (subject || '').trim()
  return new RegExp(`^${prefix.replace(':', '')}:`, 'i').test(s) ? s : `${prefix} ${s}`.trim()
}

const to = ref<string[]>([])
const cc = ref<string[]>([])
const bcc = ref<string[]>([])
const subject = ref('')
const body = ref('')
const showCc = ref(false)
const includeAttachments = ref(true)
const sending = ref(false)
const savingDraft = ref(false)
const error = ref<string | null>(null)
const needsReauth = ref(false)
// Set when the API refused to send because this compose session's key was already
// used (double request, or an earlier attempt with an unknown outcome). The only
// safe way forward is a new compose session with a fresh key, never a retry.
const sendLocked = ref(false)
const sendAttemptId = ref(crypto.randomUUID())

const forwardable = computed(() => (props.mode.original?.attachments ?? []).filter(a => !a.is_inline))

watch(
  () => props.mode,
  (m) => {
    // One key per compose session. It survives a network error/retry so the
    // backend can return/recover the original send instead of sending twice.
    sendAttemptId.value = crypto.randomUUID()
    aiAbort?.abort()
    aiAbort = null
    drafting.value = false
    sendLocked.value = false
    error.value = null
    aiError.value = null
    aiContext.value = null
    aiContextOpen.value = false
    aiSuggestionId.value = null
    briefOpen.value = false
    brief.value = ''
    needsReauth.value = false
    includeAttachments.value = true

    if (m.kind !== 'new' && m.draft) {
      // Resume an unfinished reply/forward.
      to.value = [...m.draft.to]
      cc.value = [...m.draft.cc]
      bcc.value = [...m.draft.bcc]
      subject.value = m.draft.subject ?? ''
      body.value = m.draft.body
      showCc.value = m.draft.cc.length > 0 || m.draft.bcc.length > 0
      nextTick(() => bodyEl.value?.focus())
      return
    }
    if (m.kind === 'new') {
      to.value = []
      subject.value = ''
    } else if (m.kind === 'reply') {
      const target = m.original.reply_to?.[0] ?? m.original.from
      to.value = target ? [formatAddressFull(target)] : []
      subject.value = prefixSubject('Re:', m.original.subject)
    } else {
      to.value = []
      subject.value = prefixSubject('Fwd:', m.original.subject)
    }
    // Reply all: everyone else who got the mail goes to Cc (not me, not the one in To).
    const me = (activeAccount.value?.email ?? '').toLowerCase()
    const taken = new Set([me, ...(m.kind === 'reply' ? [(m.original.reply_to?.[0] ?? m.original.from)?.email?.toLowerCase()] : [])])
    const others = m.kind === 'reply' && m.all
      ? [...m.original.to, ...m.original.cc].filter(a => a.email && !taken.has(a.email.toLowerCase()) && (taken.add(a.email.toLowerCase()), true))
      : []
    cc.value = others.map(a => formatAddressFull(a))
    bcc.value = []
    body.value = ''
    showCc.value = others.length > 0
    nextTick(() => (m.kind === 'reply' ? bodyEl.value?.focus() : toEl.value?.focus()))
  },
  { immediate: true },
)

const toEl = ref<{ focus: () => void } | null>(null)

// Mirror the reply/forward text into the shared buffer so it survives "mark as unread" etc.
watch([to, cc, bcc, subject, body, drafting], () => {
  if (props.mode.kind === 'new') return
  // While the AI writes, the body is empty / half-done: saving that would replace
  // (or delete) the stored draft. The final text is saved once drafting ends.
  if (drafting.value) return
  mailbox.updateDraftBuffer({
    messageId: props.mode.original.id,
    kind: props.mode.kind,
    to: [...to.value],
    cc: [...cc.value],
    bcc: [...bcc.value],
    subject: subject.value,
    body: body.value,
  })
}, { deep: true })

async function discard() {
  if (props.mode.kind !== 'new') {
    const hasText = body.value.trim().length > 0
    if (hasText && !window.confirm('Discard this draft? The text will be deleted.')) return
    await mailbox.discardLocalDraft(props.mode.original.id)
  }
  emit('close')
}
const bodyEl = ref<HTMLTextAreaElement | null>(null)

function splitAddresses(v: string[]): string[] {
  return v.map(s => s.trim()).filter(Boolean)
}

const canSend = computed(() => splitAddresses(to.value).length > 0 && !sending.value && !sendLocked.value)

/** What both Send and Save-as-draft submit. */
function payload() {
  return {
    to: splitAddresses(to.value),
    cc: splitAddresses(cc.value),
    bcc: splitAddresses(bcc.value),
    subject: subject.value,
    body: body.value,
    include_attachments: includeAttachments.value,
    ai_suggestion_id: aiSuggestionId.value,
  }
}

async function submit() {
  if (!canSend.value) return

  const p = payload()
  const recipients = [
    `To: ${p.to.join(', ')}`,
    ...(p.cc.length ? [`Cc: ${p.cc.join(', ')}`] : []),
    ...(p.bcc.length ? [`Bcc: ${p.bcc.join(', ')}`] : []),
  ]
  const warnings = [
    ...(!body.value.trim() ? [isNew.value ? 'Warning: the message body is empty.' : 'Warning: only the quoted original will be sent.'] : []),
    ...(isNew.value && !subject.value.trim() ? ['Warning: the subject is empty.'] : []),
    ...(!isReply.value && includeAttachments.value && forwardable.value.length ? [`Attachments: ${forwardable.value.length} original attachment(s) will be included.`] : []),
  ]
  const confirmation = [
    `Send this e-mail now from ${activeAccount.value?.email ?? 'this account'}?`,
    '',
    ...recipients,
    ...(warnings.length ? ['', ...warnings] : []),
    '',
    'This action sends immediately and cannot be undone.',
  ].join('\n')
  if (!window.confirm(confirmation)) return

  sending.value = true
  error.value = null
  needsReauth.value = false
  try {
    await mailbox.send(p, sendAttemptId.value)
    emit('close')
  } catch (e) {
    const err = e as { message: string; status?: number; data?: { needs_reauth?: boolean; duplicate_prevented?: boolean } }
    error.value = err.message
    needsReauth.value = !!err.data?.needs_reauth
    if (err.data?.duplicate_prevented) sendLocked.value = true
  } finally {
    sending.value = false
  }
}

async function saveAsDraft() {
  if (savingDraft.value || sending.value) return
  savingDraft.value = true
  error.value = null
  needsReauth.value = false
  try {
    await mailbox.saveDraft(payload())
    emit('close')
  } catch (e) {
    const err = e as { message: string; status?: number; data?: { needs_reauth?: boolean } }
    error.value = err.message
    needsReauth.value = !!err.data?.needs_reauth || err.status === 409
  } finally {
    savingDraft.value = false
  }
}

// ---- Resizable reply/forward panel (drag the top edge) ----
const PANEL_HEIGHT_KEY = 'sovera:compose-height'
const MIN_PANEL = 220
const panelHeight = ref<number | null>(null)   // null = automatic (content height, max 70%)
const resizing = ref(false)

const MIN_MESSAGE = 120 // keep at least this much of the message visible above the composer

function maxPanel(panel?: HTMLElement | null) {
  const container = panel?.parentElement
  const available = container ? container.clientHeight - MIN_MESSAGE : window.innerHeight * 0.85
  return Math.max(MIN_PANEL, Math.floor(available))
}

onMounted(() => {
  try {
    const stored = Number(localStorage.getItem(PANEL_HEIGHT_KEY))
    if (stored) panelHeight.value = Math.min(Math.max(stored, MIN_PANEL), maxPanel())
  } catch {
    /* storage unavailable */
  }
})

function startResize(e: PointerEvent) {
  if (e.button !== 0) return
  e.preventDefault()
  const handle = e.currentTarget as HTMLElement
  const panel = handle.parentElement
  resizing.value = true
  const startY = e.clientY
  const startH = panel?.getBoundingClientRect().height ?? MIN_PANEL
  const max = maxPanel(panel)
  // Capture the pointer on the handle: the message above is a sandboxed iframe, and without
  // capture the parent document stops receiving pointermove once the cursor enters it
  // (which is exactly what happens when dragging upwards).
  try {
    handle.setPointerCapture(e.pointerId)
  } catch {
    /* unsupported */
  }
  const onMove = (ev: PointerEvent) => {
    panelHeight.value = Math.round(Math.min(Math.max(startH - (ev.clientY - startY), MIN_PANEL), max))
  }
  const onUp = () => {
    resizing.value = false
    handle.removeEventListener('pointermove', onMove)
    handle.removeEventListener('pointerup', onUp)
    handle.removeEventListener('pointercancel', onUp)
    handle.removeEventListener('lostpointercapture', onUp)
    try {
      handle.releasePointerCapture(e.pointerId)
    } catch {
      /* already released */
    }
    try {
      if (panelHeight.value) localStorage.setItem(PANEL_HEIGHT_KEY, String(panelHeight.value))
    } catch {
      /* ignore */
    }
  }
  handle.addEventListener('pointermove', onMove)
  handle.addEventListener('pointerup', onUp)
  handle.addEventListener('pointercancel', onUp)
  handle.addEventListener('lostpointercapture', onUp)
}

function resetPanelHeight() {
  panelHeight.value = null
  try {
    localStorage.removeItem(PANEL_HEIGHT_KEY)
  } catch {
    /* ignore */
  }
}

function onKeydown(e: KeyboardEvent) {
  if (briefOpen.value) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
      e.preventDefault()
      void runAi()
    } else if (e.key === 'Escape') {
      e.preventDefault()
      briefOpen.value = false
    }
    return
  }
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
    e.preventDefault()
    void submit()
  }
  if (e.key === 'Escape' && !sending.value) {
    void mailbox.flushDraftSave()
    emit('close')
  }
}
</script>

<template>
  <div
    class="relative flex shrink-0 flex-col bg-surface text-ink"
    :class="isNew ? 'h-full' : 'min-h-[280px] rounded-2xl border border-line shadow-[0_1px_3px_rgba(60,64,67,.3),0_4px_8px_3px_rgba(60,64,67,.15)]'"
    @keydown="onKeydown"
  >
    <!-- Drag the top edge to make the reply panel taller; double-click resets. -->
    <div
      v-if="false"
      class="absolute -top-1.5 left-0 right-0 z-10 h-3 cursor-row-resize touch-none"
      :class="resizing ? 'bg-sovera-500/40' : 'hover:bg-sovera-500/30'"
      title="Drag to resize (double-click to reset)"
      @pointerdown="startResize"
      @dblclick="resetPanelHeight"
    />
    <header class="flex items-center gap-2 px-4 py-2" :class="isNew ? 'rounded-t-xl bg-surface-2' : 'border-b border-line'">
      <span class="text-sm font-medium">{{ title }}</span>
      <span class="truncate text-xs text-ink-muted">from {{ activeAccount?.email }}</span>
      <span class="flex-1" />
      <button class="rounded-full p-1.5 text-ink-muted hover:bg-hover" title="Close and keep the draft (Esc)" @click="mailbox.flushDraftSave(); emit('close')">
        <X class="h-4 w-4" />
      </button>
    </header>

    <div class="flex min-h-0 flex-1 flex-col">
      <div class="space-y-1 border-b border-neutral-100 px-5 py-2 text-sm dark:border-neutral-900">
        <div class="flex items-start gap-2">
          <span class="w-12 shrink-0 pt-1.5 text-xs text-neutral-500">To</span>
          <RecipientInput ref="toEl" v-model="to" :account-id="activeAccount?.id ?? null" placeholder="Start typing a name or address…" />
          <button v-if="!showCc" class="shrink-0 pt-1.5 text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="showCc = true">Cc / Bcc</button>
        </div>
        <template v-if="showCc">
          <div class="flex items-start gap-2">
            <span class="w-12 shrink-0 pt-1.5 text-xs text-neutral-500">Cc</span>
            <RecipientInput v-model="cc" :account-id="activeAccount?.id ?? null" />
          </div>
          <div class="flex items-start gap-2">
            <span class="w-12 shrink-0 pt-1.5 text-xs text-neutral-500">Bcc</span>
            <RecipientInput v-model="bcc" :account-id="activeAccount?.id ?? null" />
          </div>
        </template>
        <label class="flex items-center gap-2">
          <span class="w-12 shrink-0 text-xs text-neutral-500">Subject</span>
          <input v-model="subject" type="text" class="min-w-0 flex-1 bg-transparent py-1 outline-none" />
        </label>
      </div>

      <textarea
        ref="bodyEl"
        v-model="body"
        :placeholder="isNew ? 'Write your message — or jot down a few points and press AI compose…' : isReply ? 'Write your reply…' : 'Add a note (optional)…'"
        class="min-h-[140px] flex-1 resize-none bg-transparent px-5 py-3 text-sm leading-relaxed outline-none"
      />

      <div v-if="!isNew" class="flex flex-wrap items-center gap-3 border-t border-neutral-100 px-5 py-2 text-xs text-neutral-500 dark:border-neutral-900">
        <span>{{ isReply ? 'The original message is quoted below your reply.' : 'The original message is included below your note.' }}</span>
        <label v-if="!isReply && forwardable.length" class="flex items-center gap-1.5">
          <input v-model="includeAttachments" type="checkbox" class="accent-sovera-600" />
          <Paperclip class="h-3.5 w-3.5" />
          <span>Include {{ forwardable.length }} attachment{{ forwardable.length === 1 ? '' : 's' }}</span>
        </label>
      </div>

      <div v-if="error" class="mx-5 mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
        {{ error }}
        <NuxtLink v-if="needsReauth" to="/accounts" class="ml-1 underline">Open Accounts to reconnect</NuxtLink>
        <span v-if="sendLocked" class="mt-1 block">
          Sending from this window is now locked so the message cannot go out twice. Check the Sent folder; if it is not there, close this window and write it again.
        </span>
      </div>
      <!-- AI brief (new message only) -->
      <div v-if="briefOpen" class="mx-5 mb-3 rounded-xl border border-sovera-200 bg-sovera-50 p-3 dark:border-sovera-900 dark:bg-sovera-950/50">
        <div class="mb-2 flex items-center gap-2 text-sm font-medium text-sovera-900 dark:text-sovera-100">
          <Sparkles class="h-4 w-4" /> Tell the AI what to write
        </div>
        <textarea
          ref="briefEl"
          v-model="brief"
          rows="4"
          :placeholder="BRIEF_PLACEHOLDER"
          class="w-full resize-y rounded-lg border border-sovera-200 bg-white px-3 py-2 text-sm leading-relaxed outline-none placeholder:whitespace-pre-line placeholder:text-neutral-400 focus:border-sovera-500 dark:border-sovera-900 dark:bg-neutral-950"
        />
        <p class="mt-1 text-xs text-sovera-900/70 dark:text-sovera-200/70">
          Written in your style, using how you previously wrote to {{ to.length ? 'this person' : 'people' }} and related mails from this mailbox.
          <template v-if="body.trim()">Whatever is already in the message body is kept as notes.</template>
        </p>
        <div class="mt-2 flex items-center gap-2">
          <button
            type="button"
            class="flex items-center gap-2 rounded-lg bg-gradient-to-r from-sovera-500 to-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:from-sovera-400 hover:to-violet-500 disabled:opacity-50"
            :disabled="!brief.trim() && !body.trim() && !subject.trim()"
            @click="runAi"
          >
            <Sparkles class="h-4 w-4" /> Write it
          </button>
          <span class="text-[11px] text-neutral-400">⌘/Ctrl + Enter</span>
          <span class="flex-1" />
          <button type="button" class="text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="briefOpen = false">Cancel</button>
        </div>
      </div>

      <div v-if="aiContext" class="mx-5 mb-2 rounded-lg border border-sovera-200 bg-sovera-50 px-3 py-1.5 text-xs text-sovera-900 dark:border-sovera-900 dark:bg-sovera-950/50 dark:text-sovera-100">
        <template v-if="aiContext.length">
          <button
            type="button"
            class="flex w-full items-center gap-1 text-left font-medium hover:underline"
            :aria-expanded="aiContextOpen"
            :title="aiContextOpen ? 'Hide the conversations' : 'Show which conversations'"
            @click="aiContextOpen = !aiContextOpen"
          >
            <ChevronRight class="h-3.5 w-3.5 shrink-0 transition-transform" :class="aiContextOpen ? 'rotate-90' : ''" />
            <span>Draft based on {{ aiContext.length }} similar conversation{{ aiContext.length === 1 ? '' : 's' }} from this mailbox</span>
          </button>
          <ul v-if="aiContextOpen" class="mt-1 space-y-0.5 pl-4.5">
            <li v-for="c in aiContext" :key="c.id" class="truncate">
              <span class="opacity-60">{{ formatListDate(c.date) }}</span> · {{ c.mine ? 'You' : c.from }} · {{ c.subject || '(no subject)' }}
            </li>
          </ul>
        </template>
        <span v-else>Draft written without related conversations (none found, or the mailbox index is not built yet).</span>
      </div>
      <div v-if="aiError" class="mx-5 mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
        {{ aiError }}
        <NuxtLink to="/ai" class="ml-1 underline">Configure AI</NuxtLink>
      </div>

      <footer class="flex items-center gap-2 px-4 py-3">
        <button
          class="flex items-center gap-2 rounded-full bg-primary px-6 py-2 text-sm font-medium text-on-primary shadow-sm transition hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!canSend"
          title="Send (⌘/Ctrl + Enter, then confirm)"
          @click="submit"
        >
          <Loader2 v-if="sending" class="h-4 w-4 animate-spin" />
          <Send v-else class="h-4 w-4" />
          {{ sending ? 'Sending…' : 'Send' }}
        </button>
        <button
          type="button"
          class="group flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-medium text-ink transition hover:bg-hover disabled:cursor-not-allowed disabled:opacity-60"
          :title="ai.ready.value ? `Draft with ${ai.settings.value?.model} (local)` : 'Configure your local AI first'"
          :disabled="drafting || sending"
          @click="composeWithAi"
        >
          <Loader2 v-if="drafting" class="h-4 w-4 animate-spin text-primary" />
          <Sparkles v-else class="h-4 w-4 text-primary transition group-hover:rotate-12" />
          {{ proofreading ? 'Proofreading…' : thinking ? 'Thinking…' : drafting ? 'Drafting…' : 'AI compose' }}
        </button>
        <button
          v-if="isNew"
          type="button"
          class="flex items-center gap-2 rounded-full px-3 py-2 text-sm text-ink-muted transition hover:bg-hover disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="savingDraft || sending || drafting"
          title="Save to the mailbox's Drafts without sending"
          @click="saveAsDraft"
        >
          <Loader2 v-if="savingDraft" class="h-4 w-4 animate-spin" />
          <FileText v-else class="h-4 w-4" />
          {{ savingDraft ? 'Saving…' : 'Save as draft' }}
        </button>
        <span class="flex-1" />
        <button class="rounded-full p-2 text-ink-muted hover:bg-hover" :disabled="sending" title="Discard draft" @click="discard">
          <Trash2 class="h-4 w-4" />
        </button>
      </footer>
    </div>
  </div>
</template>
