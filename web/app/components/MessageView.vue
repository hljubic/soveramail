<script setup lang="ts">
import { AlertTriangle, ArrowLeft, Ban, ChevronLeft, ChevronRight, Download, Forward, Loader2, Mail, MailOpen, Paperclip, Reply, ReplyAll, Sparkles, Star } from 'lucide-vue-next'
import type { AttachmentMeta, MessageSummary } from '~/types/api'

/**
 * The open conversation, in place of the list: toolbar, subject, every message of
 * the thread (the open one expanded, the others folded), then Reply / Forward with
 * the composer right below, like the classic mail clients.
 */
const mailbox = useMailbox()
const { activeAccount, activeAccountId } = useAccounts()
const message = computed(() => mailbox.selected.value)

const { prefs } = usePreferences()
const showDetails = ref(false)
const loadRemoteImages = ref(prefs.value.autoLoadImages)
const preview = ref<AttachmentMeta | null>(null)
const scroller = ref<HTMLElement | null>(null)

watch(() => mailbox.selectedId.value, () => {
  showDetails.value = false
  loadRemoteImages.value = prefs.value.autoLoadImages
  preview.value = null
  // Opening another message closes a reply/forward; a new message (floating window) stays open.
  if (mailbox.compose.value && mailbox.compose.value.kind !== 'new') mailbox.closeCompose()
})

const replying = computed(() => !!mailbox.compose.value && mailbox.compose.value.kind !== 'new')

// "Summarize": the local model lists what matters, for the whole conversation or one message.
type SummaryScope = 'thread' | 'message'
interface SummaryState { text: string | null; model: string | null; error: string | null; loading: boolean }
const blank = (): SummaryState => ({ text: null, model: null, error: null, loading: false })
const summaries = reactive<Record<SummaryScope, SummaryState>>({ thread: blank(), message: blank() })
watch(() => mailbox.selected.value?.thread_id ?? mailbox.selectedId.value, () => {
  summaries.thread = blank()
})
watch(() => mailbox.selectedId.value, () => {
  summaries.message = blank()
})
async function summarize(scope: SummaryScope) {
  const s = summaries[scope]
  if (!message.value || s.loading) return
  if (s.text || s.error) {
    summaries[scope] = blank()
    return
  }
  s.loading = true
  const id = message.value.id
  try {
    const res = await api.post<{ data: { summary: string; model: string } }>(`/messages/${id}/ai/summary`, { scope })
    if (mailbox.selectedId.value === id) Object.assign(summaries[scope], { text: res.data.summary, model: res.data.model })
  } catch (e) {
    if (mailbox.selectedId.value === id) summaries[scope].error = (e as Error).message
  } finally {
    summaries[scope].loading = false
  }
}
const linesOf = (text: string | null) => (text ?? '').split('\n').map(l => l.replace(/^\s*[-*•]\s*/, '').trim()).filter(Boolean)
watch(replying, async (on) => {
  if (!on) return
  await nextTick()
  scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' })
})

/** The conversation, oldest first; the open message always in it. */
const conversation = computed<MessageSummary[]>(() => {
  const open = message.value
  if (!open) return []
  const t = mailbox.thread.value.filter(m => m.thread_id === open.thread_id)
  return t.length && t.some(m => m.id === open.id) ? t : [...t.filter(m => m.id !== open.id), open].sort((a, b) => a.received_at.localeCompare(b.received_at))
})

const visibleAttachments = computed(() => (message.value?.attachments ?? []).filter(a => !a.is_inline))
const api = useApi()
const canModify = computed(() => activeAccount.value?.can_modify ?? false)

function back() {
  void mailbox.select(null)
}

async function toggleUnread() {
  if (!message.value) return
  const id = message.value.id
  const makeUnread = !message.value.is_unread
  if (makeUnread) {
    // "I will come back to this": keep whatever was typed as a local draft and go back to the list.
    await mailbox.flushDraftSave()
    await mailbox.select(null)
  }
  void mailbox.setUnread(id, makeUnread)
}

const hasDraft = computed(() => !!message.value?.has_draft || (!!mailbox.draftBuffer.value && mailbox.draftBuffer.value.messageId === message.value?.id && mailbox.draftBuffer.value.body.trim().length > 0))

/** Archives: download/preview only after the user confirms they expected the file. */
function confirmCaution(a: AttachmentMeta, e?: Event): boolean {
  if (a.risk_level !== 'caution') return true
  const ok = window.confirm([
    `"${a.filename || 'attachment'}" is an archive.`,
    '',
    a.risk || 'Its contents cannot be checked before you download it.',
    'Archives are a common way to smuggle executables past filters. Only continue if you know the sender and expected this file.',
    '',
    'Download / open it anyway?',
  ].join('\n'))
  if (!ok) e?.preventDefault()
  return ok
}

// The conversation's local category: picked by hand it is final and teaches the model.
const cats = useCategories()
const currentCategory = computed(() => cats.byId(message.value?.category_id))

async function pickCategory(e: Event) {
  const id = Number((e.target as HTMLSelectElement).value)
  if (!message.value || !id) return
  try {
    await cats.setForMessage(message.value.id, id)
    if (mailbox.selected.value) mailbox.selected.value = { ...mailbox.selected.value, category_id: id, category_source: 'user' }
    mailbox.messages.value = mailbox.messages.value.map(r => (r.thread_id === message.value?.thread_id ? { ...r, category_id: id } : r))
    if (mailbox.category.value && mailbox.category.value !== id) await mailbox.loadMessages(activeAccountId.value)
  } catch (err) {
    mailbox.showNotice('error', (err as Error).message)
  }
}

function toggleStar() {
  if (!message.value) return
  void mailbox.setStarred(message.value.id, !message.value.is_starred)
}

const position = computed(() => {
  const i = mailbox.rowIndexOfOpen()
  return i < 0 ? null : { index: i + 1, total: mailbox.messages.value.length, hasPrev: i > 0, hasNext: i < mailbox.messages.value.length - 1 }
})

const sender = (m: { from: { name: string | null; email: string } }) => m.from?.name || m.from?.email || '(unknown sender)'
const btn = 'rounded-full p-2 text-ink-muted transition hover:bg-hover'
const bottomBtn = 'flex items-center gap-2 rounded-full border border-[#747775] px-5 py-2 text-sm font-medium text-ink-muted transition hover:bg-hover dark:border-[#8e918f]'

/** Reply all only when someone besides the sender and me got the mail. */
const canReplyAll = computed(() => {
  const m = message.value
  if (!m) return false
  const me = (activeAccount.value?.email ?? '').toLowerCase()
  const sender = (m.reply_to?.[0] ?? m.from)?.email?.toLowerCase()
  return [...m.to, ...m.cc].some(a => a.email && a.email.toLowerCase() !== me && a.email.toLowerCase() !== sender)
})
</script>

<template>
  <section class="relative flex h-full min-w-0 flex-1 flex-col">
    <!-- Toolbar -->
    <div class="flex h-12 shrink-0 items-center gap-1 px-4">
      <button :class="btn" title="Back to the list (Esc)" @click="back"><ArrowLeft class="h-5 w-5" /></button>
      <template v-if="message">
        <span class="mx-1 h-5 w-px bg-line" />
        <button :class="btn" :title="message.is_unread ? 'Mark as read' : 'Mark as unread (u)'" @click="toggleUnread">
          <MailOpen v-if="message.is_unread" class="h-5 w-5" />
          <Mail v-else class="h-5 w-5" />
        </button>
        <button :class="btn" :title="message.is_starred ? 'Remove star' : 'Add star'" @click="toggleStar">
          <Star class="h-5 w-5" :class="message.is_starred ? 'fill-amber-400 text-amber-400' : ''" />
        </button>
        <label
          v-if="cats.categories.value.length > 1 && message.thread_id"
          class="ml-1 flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm text-ink-muted hover:bg-hover"
          :title="message.category_source === 'user' ? 'Category picked by you · the whole conversation moves' : message.category_id ? 'Sorted by the local AI · pick another to move the whole conversation' : 'Not sorted yet · pick a category for the whole conversation'"
        >
          <span>Move to</span>
          <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ background: currentCategory?.color ?? '#a3a3a3' }" />
          <select :value="message.category_id ?? ''" class="max-w-[10rem] truncate bg-transparent text-ink outline-none" aria-label="Move to category" @change="pickCategory">
            <option value="" disabled>category…</option>
            <option v-for="c in cats.categories.value" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
      </template>
      <span class="flex-1" />
      <template v-if="position">
        <span class="mr-1 text-xs text-ink-muted">{{ position.index }} of {{ position.total }}</span>
        <button :class="btn" :disabled="!position.hasPrev" class="disabled:opacity-30" title="Newer (k)" @click="mailbox.openNeighbour(-1)"><ChevronLeft class="h-5 w-5" /></button>
        <button :class="btn" :disabled="!position.hasNext" class="disabled:opacity-30" title="Older (j)" @click="mailbox.openNeighbour(1)"><ChevronRight class="h-5 w-5" /></button>
      </template>
    </div>

    <div v-if="mailbox.detailLoading.value && !message" class="flex flex-1 items-center justify-center">
      <Loader2 class="h-5 w-5 animate-spin text-ink-faint" />
    </div>

    <div v-else-if="mailbox.detailError.value" class="m-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
      {{ mailbox.detailError.value }}
    </div>

    <div v-else-if="message" ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
      <!-- Warnings -->
      <div v-if="message.is_risky" class="mx-6 mb-3 flex items-start gap-3 rounded-lg bg-red-600 px-4 py-2.5 text-sm text-white">
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
        <div class="min-w-0">
          <p class="font-semibold">This message carries a dangerous attachment.</p>
          <p class="text-xs text-red-100">
            The blocked file(s) below can never be previewed, downloaded or forwarded from Sovera Mail. Do not ask the sender to resend it in another form
            unless you know them and expected it. Links in the text can be just as dangerous.
          </p>
        </div>
      </div>
      <div v-else-if="message.has_caution" class="mx-6 mb-3 flex items-start gap-3 rounded-lg bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-950/60 dark:text-amber-200">
        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
        <span>This message contains an archive (zip, rar, …). Its contents cannot be checked before download; open it only if you expected it from this sender.</span>
      </div>
      <div v-if="!canModify" class="mx-6 mb-3 flex items-center gap-3 rounded-lg bg-amber-50 px-4 py-1.5 text-xs text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">
        <span class="flex-1">This account is connected read-only. Read state stays local and sending is disabled until you reconnect.</span>
        <NuxtLink to="/accounts" class="rounded-full bg-amber-600 px-3 py-1 font-medium text-white hover:bg-amber-700">Reconnect</NuxtLink>
      </div>

      <!-- Subject, with "Summarize" on the right -->
      <div class="flex items-start gap-3 pl-[72px] pr-6 pb-4 pt-2">
        <h1 class="min-w-0 text-[22px] leading-7 text-ink">{{ message.subject || '(no subject)' }}</h1>
        <span v-if="currentCategory" class="mt-1 shrink-0 rounded px-1.5 py-0.5 text-xs" :style="{ background: `${currentCategory.color}22`, color: currentCategory.color }">{{ currentCategory.name }}</span>
        <span v-if="hasDraft" class="mt-1.5 shrink-0 text-xs font-medium text-red-600" title="You have an unsent reply saved for this message">Draft</span>
        <span class="flex-1" />
        <button
          class="flex shrink-0 items-center gap-1.5 rounded-full border border-line px-3.5 py-1.5 text-sm font-medium text-ink-muted transition hover:bg-hover disabled:opacity-60"
          :disabled="summaries.thread.loading"
          :title="conversation.length > 1 ? `Summarize the whole conversation (${conversation.length} messages, local AI)` : 'Summarize this message (local AI)'"
          @click="summarize('thread')"
        >
          <Loader2 v-if="summaries.thread.loading" class="h-4 w-4 animate-spin text-primary" />
          <Sparkles v-else class="h-4 w-4 text-primary" />
          {{ summaries.thread.loading ? 'Summarizing…' : summaries.thread.text || summaries.thread.error ? 'Hide summary' : conversation.length > 1 ? 'Summarize conversation' : 'Summarize' }}
        </button>
      </div>
      <SummaryCard
        v-if="summaries.thread.text || summaries.thread.error"
        class="mb-4 ml-[72px] mr-6"
        :title="conversation.length > 1 ? `Conversation summary (${conversation.length} messages)` : 'Summary'"
        :lines="linesOf(summaries.thread.text)"
        :model="summaries.thread.model"
        :error="summaries.thread.error"
        @close="summaries.thread = blank()"
      />

      <!-- The conversation -->
      <div class="pr-6">
        <template v-for="m in conversation" :key="m.id">
          <!-- Folded message: click to open it -->
          <button
            v-if="m.id !== message.id"
            class="flex w-full items-center gap-4 border-t border-line py-3 pl-4 text-left hover:bg-hover"
            @click="mailbox.select(m.id)"
          >
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-medium text-white" :style="{ background: avatarColor(m.from?.email) }">
              {{ initials(m.from?.name, m.from?.email) }}
            </span>
            <span class="w-48 shrink-0 truncate text-sm" :class="m.is_unread ? 'font-bold text-ink' : 'text-ink'">{{ sender(m) }}</span>
            <span class="min-w-0 flex-1 truncate text-sm text-ink-muted">{{ m.snippet }}</span>
            <Paperclip v-if="m.has_attachments" class="h-4 w-4 shrink-0 text-ink-faint" />
            <span class="w-28 shrink-0 text-right text-xs text-ink-muted">{{ formatListDate(m.received_at) }}</span>
          </button>

          <!-- The open message -->
          <article v-else class="border-t border-line pt-4 first:border-t-0">
            <div class="flex items-start gap-4 pl-4">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-medium text-white" :style="{ background: avatarColor(message.from?.email) }">
                {{ initials(message.from?.name, message.from?.email) }}
              </span>
              <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1.5">
                  <span class="truncate text-sm font-bold text-ink">{{ sender(message) }}</span>
                  <span v-if="message.from?.name" class="truncate text-xs text-ink-muted">&lt;{{ message.from.email }}&gt;</span>
                </div>
                <button class="mt-0.5 truncate text-xs text-ink-muted hover:text-ink" @click="showDetails = !showDetails">
                  to {{ message.to.map(a => formatAddress(a)).join(', ') || 'me' }}
                  <span class="ml-0.5">{{ showDetails ? '▴' : '▾' }}</span>
                </button>
                <dl v-if="showDetails" class="mt-2 grid max-w-xl grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border border-line p-3 text-xs text-ink-muted shadow-sm">
                  <dt>from:</dt><dd class="break-all text-ink">{{ formatAddressFull(message.from) }}</dd>
                  <dt>to:</dt><dd class="break-all text-ink">{{ formatAddressList(message.to) || '—' }}</dd>
                  <template v-if="message.cc.length"><dt>cc:</dt><dd class="break-all text-ink">{{ formatAddressList(message.cc) }}</dd></template>
                  <template v-if="message.bcc.length"><dt>bcc:</dt><dd class="break-all text-ink">{{ formatAddressList(message.bcc) }}</dd></template>
                  <template v-if="message.reply_to.length"><dt>reply-to:</dt><dd class="break-all text-ink">{{ formatAddressList(message.reply_to) }}</dd></template>
                  <dt>date:</dt><dd class="text-ink">{{ formatFullDate(message.sent_at || message.received_at) }}</dd>
                  <dt>size:</dt><dd class="text-ink">{{ formatBytes(message.size_estimate) }}</dd>
                  <template v-if="message.message_id_header"><dt>message-id:</dt><dd class="break-all font-mono text-ink">{{ message.message_id_header }}</dd></template>
                </dl>
              </div>
              <time class="shrink-0 pt-0.5 text-xs text-ink-muted" :datetime="message.received_at" :title="formatFullDate(message.sent_at || message.received_at)">{{ formatMailDate(message.sent_at || message.received_at) }}</time>
              <button :class="btn" class="-mt-1.5" :title="message.is_starred ? 'Starred' : 'Not starred'" @click="toggleStar">
                <Star class="h-5 w-5" :class="message.is_starred ? 'fill-amber-400 text-amber-400' : ''" />
              </button>
              <button
                v-if="conversation.length > 1"
                :class="btn"
                class="-mt-1.5 disabled:opacity-60"
                :disabled="summaries.message.loading"
                :title="summaries.message.text ? 'Hide the summary of this message' : 'Summarize only this message (local AI)'"
                @click="summarize('message')"
              >
                <Loader2 v-if="summaries.message.loading" class="h-5 w-5 animate-spin text-primary" />
                <Sparkles v-else class="h-5 w-5" :class="summaries.message.text ? 'text-primary' : ''" />
              </button>
              <button :class="btn" class="-mt-1.5" title="Reply (r)" @click="mailbox.openCompose('reply')"><Reply class="h-5 w-5" /></button>
            </div>

            <SummaryCard
              v-if="summaries.message.text || summaries.message.error"
              class="ml-[72px] mt-3"
              title="Summary of this message"
              :lines="linesOf(summaries.message.text)"
              :model="summaries.message.model"
              :error="summaries.message.error"
              @close="summaries.message = blank()"
            />

            <!-- Body -->
            <div class="pl-[72px] pt-3">
              <HtmlMessageFrame
                v-if="message.body_html"
                :html="message.body_html"
                :attachments="message.attachments"
                :allow-remote-images="loadRemoteImages"
                @request-remote-images="loadRemoteImages = true"
              />
              <pre v-else class="whitespace-pre-wrap py-2 font-sans text-sm leading-relaxed text-ink">{{ message.body_text || '(empty message)' }}</pre>
            </div>

            <!-- Attachments -->
            <div v-if="visibleAttachments.length" class="pl-[72px] pt-4">
              <p class="mb-2 text-sm font-medium text-ink">{{ visibleAttachments.length }} attachment{{ visibleAttachments.length === 1 ? '' : 's' }}</p>
              <ul class="flex flex-wrap gap-2">
                <li
                  v-for="a in visibleAttachments"
                  :key="a.id"
                  class="flex w-56 items-center overflow-hidden rounded-lg border text-xs"
                  :class="a.risk_level === 'danger'
                    ? 'border-red-400 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/50 dark:text-red-300'
                    : a.risk_level === 'caution'
                      ? 'border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200'
                      : 'border-line text-ink'"
                  :title="a.risk || a.mime_type || ''"
                >
                  <span v-if="a.risk_level === 'danger'" class="flex min-w-0 flex-1 items-center gap-1.5 px-3 py-2.5" :title="a.risk || ''">
                    <Ban class="h-4 w-4 shrink-0" />
                    <span class="truncate line-through">{{ a.filename || 'attachment' }}</span>
                    <span class="shrink-0 font-semibold">Blocked</span>
                  </span>
                  <button
                    v-else
                    class="flex min-w-0 flex-1 items-center gap-2 px-3 py-2.5 text-left hover:bg-hover disabled:cursor-default"
                    :disabled="!a.url"
                    :title="a.previewable ? 'Preview' : 'Download'"
                    @click="confirmCaution(a) && (preview = a)"
                  >
                    <AlertTriangle v-if="a.risk_level === 'caution'" class="h-4 w-4 shrink-0 text-amber-500" />
                    <Paperclip v-else class="h-4 w-4 shrink-0 text-red-500" />
                    <span class="min-w-0 flex-1">
                      <span class="block truncate font-medium">{{ a.filename || 'attachment' }}</span>
                      <span class="block text-ink-muted">{{ formatBytes(a.size) }}</span>
                    </span>
                  </button>
                  <a
                    v-if="a.download_url"
                    :href="api.assetUrl(a.download_url) || undefined"
                    :download="a.filename || 'attachment'"
                    class="self-stretch border-l border-line px-2.5 py-2.5 text-ink-muted hover:bg-hover hover:text-ink"
                    title="Download"
                    @click.stop="confirmCaution(a, $event)"
                  >
                    <Download class="h-4 w-4" />
                  </a>
                </li>
              </ul>
            </div>
          </article>
        </template>
      </div>

      <!-- The composer right below the conversation -->
      <div v-if="replying && mailbox.compose.value" class="pl-[72px] pr-6 pt-6">
        <ComposePanel :mode="mailbox.compose.value" @close="mailbox.closeCompose()" />
      </div>
      <!-- Reply / Reply all / Forward: at the end of the conversation, kept in view while reading -->
      <div v-else class="sticky bottom-0 mt-6 flex gap-3 border-t border-line bg-surface py-4 pl-[72px] pr-6">
        <button :class="bottomBtn" title="Reply (r)" @click="mailbox.openCompose('reply')">
          <Reply class="h-[18px] w-[18px]" /> Reply
        </button>
        <button v-if="canReplyAll" :class="bottomBtn" title="Reply all (a)" @click="mailbox.openCompose('reply-all')">
          <ReplyAll class="h-[18px] w-[18px]" /> Reply all
        </button>
        <button :class="bottomBtn" title="Forward (f)" @click="mailbox.openCompose('forward')">
          <Forward class="h-[18px] w-[18px]" /> Forward
        </button>
      </div>
    </div>

    <AttachmentPreview v-if="preview" :attachment="preview" @close="preview = null" />
  </section>
</template>
