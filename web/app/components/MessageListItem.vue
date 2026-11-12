<script setup lang="ts">
import { AlertTriangle, Mail, MailOpen, Paperclip, Star } from 'lucide-vue-next'
import type { MessageSummary } from '~/types/api'

/**
 * One conversation in the list, on one line: star, sender (with the number of
 * messages), category, subject — snippet, attachment, date. Unread = bold on white;
 * the dot before the sender is the urgency the local AI judged.
 */
const props = defineProps<{ message: MessageSummary; active?: boolean; checked?: boolean }>()
/** check: the row's checkbox (shift = select the range from the last one ticked). */
defineEmits<{ select: []; check: [shift: boolean] }>()

const mailbox = useMailbox()
const cats = useCategories()

const m = computed(() => props.message)
const unread = computed(() => m.value.is_unread || (m.value.thread_unread ?? 0) > 0)
const urgency = computed(() => m.value.thread_urgency ?? m.value.urgency ?? null)
const isSent = computed(() => m.value.label_ids.includes('SENT') && !m.value.label_ids.includes('INBOX'))
const who = computed(() => {
  if (isSent.value) {
    const to = m.value.to?.[0]
    return to ? `To: ${to.name || to.email}` : 'To: (no recipient)'
  }
  return m.value.from?.name || m.value.from?.email || '(unknown sender)'
})
const count = computed(() => ((m.value.thread_count ?? 1) > 1 ? m.value.thread_count : null))
const hasDraft = computed(() => !!(m.value.has_draft || m.value.thread_has_draft))
const category = computed(() => {
  const c = cats.categories.value.find(x => x.id === m.value.category_id)
  return mailbox.category.value ? null : c ?? null
})

// The dot shows urgency (judged by the local model): red / blue / green; grey = not judged yet.
const URGENCY_DOT: Record<string, string> = { high: 'bg-red-500', medium: 'bg-blue-500', low: 'bg-emerald-500', none: 'bg-neutral-400' }
const URGENCY_TITLE: Record<string, string> = { high: 'Unread · urgent', medium: 'Unread · medium urgency', low: 'Unread · low urgency', none: 'Unread · urgency not judged yet' }

const chips = computed(() => m.value.attachment_chips ?? [])

/** A small coloured file-type mark, like the chips in the classic clients. */
type Chip = { filename: string | null; mime_type: string | null }
function kind(a: Chip): string {
  const n = (a.filename ?? '').toLowerCase()
  const t = a.mime_type ?? ''
  if (t.includes('pdf') || n.endsWith('.pdf')) return 'pdf'
  if (t.startsWith('image/')) return 'img'
  if (/\.(docx?|odt|rtf)$/.test(n) || t.includes('word')) return 'doc'
  if (/\.(xlsx?|ods|csv)$/.test(n) || t.includes('sheet') || t.includes('excel')) return 'xls'
  if (/\.(pptx?|odp)$/.test(n) || t.includes('presentation') || t.includes('powerpoint')) return 'ppt'
  if (/\.(zip|rar|7z|gz|tar)$/.test(n)) return 'zip'
  return 'file'
}
const COLORS: Record<string, string> = { pdf: '#d93025', img: '#d93025', doc: '#1a73e8', xls: '#188038', ppt: '#e37400', zip: '#5f6368', file: '#5f6368' }
const BADGES: Record<string, string> = { pdf: 'PDF', img: 'IMG', doc: 'W', xls: 'X', ppt: 'P', zip: 'ZIP', file: '•' }
const fileColor = (a: Chip) => COLORS[kind(a)]
const fileBadge = (a: Chip) => BADGES[kind(a)]

function toggleStar() {
  void mailbox.setStarred(m.value.id, !m.value.is_starred)
}
function toggleRead() {
  void mailbox.setUnread(m.value.id, !unread.value)
}
</script>

<template>
  <li
    class="group relative cursor-pointer border-b border-line pr-4 text-sm transition-shadow hover:z-10 hover:shadow-[inset_1px_0_0_#dadce0,inset_-1px_0_0_#dadce0,0_1px_2px_0_rgba(60,64,67,.3),0_1px_3px_1px_rgba(60,64,67,.15)]"
    :class="[
      message.is_risky ? 'bg-red-50 dark:bg-red-950/40' : message.has_caution ? 'bg-amber-50 dark:bg-amber-950/30' : unread ? 'bg-surface' : 'bg-row-read',
      active ? 'ring-1 ring-inset ring-primary' : '',
      checked ? '!bg-nav-active' : '',
    ]"
    @click="$emit('select')"
  >
   <div class="flex h-10 items-center">
    <!-- urgency / unread -->
    <span class="flex w-8 shrink-0 justify-center">
      <span v-if="unread" class="h-2 w-2 rounded-full" :class="URGENCY_DOT[urgency ?? 'none']" :title="URGENCY_TITLE[urgency ?? 'none']" />
    </span>
    <label class="flex shrink-0 cursor-pointer items-center rounded-full p-1.5 hover:bg-hover" title="Select (shift-click selects a range)" @click.stop>
      <input
        type="checkbox"
        class="h-4 w-4 cursor-pointer accent-primary"
        :checked="checked"
        aria-label="Select conversation"
        @click="$emit('check', ($event as MouseEvent).shiftKey)"
      />
    </label>
    <button class="shrink-0 rounded-full p-1.5 hover:bg-hover" :title="message.is_starred ? 'Starred' : 'Not starred'" @click.stop="toggleStar">
      <Star class="h-[18px] w-[18px]" :class="message.is_starred ? 'fill-amber-400 text-amber-400' : 'text-ink-faint'" />
    </button>

    <!-- sender -->
    <span class="ml-2 flex w-52 shrink-0 items-center gap-1 truncate pr-4" :class="unread ? 'font-bold text-ink' : 'text-ink'">
      <AlertTriangle v-if="message.is_risky" class="h-4 w-4 shrink-0 text-red-600" />
      <span class="truncate">{{ who }}</span>
      <span v-if="count" class="shrink-0 text-xs font-normal text-ink-muted">{{ count }}</span>
      <span v-if="hasDraft" class="shrink-0 text-xs font-normal text-red-600">Draft</span>
    </span>

    <!-- category · subject — snippet -->
    <span class="flex min-w-0 flex-1 items-center gap-1.5">
      <span v-if="category" class="shrink-0 rounded px-1.5 py-px text-xs" :style="{ background: `${category.color}22`, color: category.color }">{{ category.name }}</span>
      <span class="truncate">
        <span :class="unread ? 'font-bold text-ink' : 'text-ink'">{{ message.subject || '(no subject)' }}</span>
        <span class="text-ink-muted"> — {{ message.is_risky ? 'Dangerous attachment — blocked' : message.snippet }}</span>
      </span>
    </span>

    <Paperclip v-if="message.has_attachments && !chips.length" class="ml-3 h-4 w-4 shrink-0 text-ink-faint" />

    <!-- date, replaced by quick actions on hover -->
    <span class="ml-3 w-16 shrink-0 text-right text-xs group-hover:hidden" :class="unread ? 'font-bold text-ink' : 'text-ink-muted'">{{ formatListDate(message.received_at) }}</span>
    <span class="ml-3 hidden w-16 shrink-0 justify-end group-hover:flex">
      <button class="rounded-full p-1.5 text-ink-muted hover:bg-hover" :title="unread ? 'Mark as read' : 'Mark as unread'" @click.stop="toggleRead">
        <MailOpen v-if="unread" class="h-[18px] w-[18px]" />
        <Mail v-else class="h-[18px] w-[18px]" />
      </button>
    </span>
   </div>

    <!-- Attachment chips under the subject, like the classic clients -->
    <div v-if="chips.length" class="-mt-1 flex items-center gap-2 pb-2 pl-[314px]">
      <span
        v-for="(a, i) in chips"
        :key="i"
        class="flex max-w-[180px] items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
        :class="a.risk_level === 'danger' ? 'border-red-300 text-red-700 dark:border-red-800 dark:text-red-300' : 'border-line text-ink'"
        :title="a.filename || 'attachment'"
      >
        <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-sm text-[8px] font-bold text-white" :style="{ background: fileColor(a) }">{{ fileBadge(a) }}</span>
        <span class="truncate">{{ a.filename || 'attachment' }}</span>
      </span>
      <span v-if="message.attachments_more" class="rounded-full border border-line px-2 py-1 text-xs text-ink-muted">+{{ message.attachments_more }}</span>
    </div>
  </li>
</template>
