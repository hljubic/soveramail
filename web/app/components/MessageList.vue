<script setup lang="ts">
import { ArrowDownWideNarrow, ArrowUpNarrowWide, ChevronLeft, ChevronRight, Loader2, Mail, MailOpen, RefreshCw, X } from 'lucide-vue-next'

/**
 * The conversation list, full width (the search box lives in the top bar):
 * a thin toolbar, then one line per conversation.
 */
const { activeAccount, activeAccountId, syncAccount } = useAccounts()
const mailbox = useMailbox()
const cats = useCategories()

const refreshing = ref(false)
async function refresh() {
  if (!activeAccount.value || refreshing.value) return
  refreshing.value = true
  try {
    if (activeAccount.value.sync_status !== 'needs_reauth') await syncAccount(activeAccount.value.id)
    await mailbox.refreshMessages(activeAccountId.value)
  } catch (e) {
    mailbox.showNotice('error', (e as Error).message)
  } finally {
    setTimeout(() => (refreshing.value = false), 800)
  }
}

const smartActive = computed(() => mailbox.smartQuery.value !== null)

// ---- Multi-select: tick conversations, then move them to a category at once ----
const checked = ref<Set<number>>(new Set())
let lastChecked: number | null = null
const rowIds = computed(() => mailbox.messages.value.map(m => m.id))
// Another folder / page / search: start over (only rows on screen can be ticked).
watch(rowIds, (ids) => {
  const keep = new Set(ids.filter(id => checked.value.has(id)))
  if (keep.size !== checked.value.size) checked.value = keep
})
const allChecked = computed(() => rowIds.value.length > 0 && rowIds.value.every(id => checked.value.has(id)))
const someChecked = computed(() => checked.value.size > 0 && !allChecked.value)

function check(id: number, shift: boolean) {
  const next = new Set(checked.value)
  const on = !next.has(id)
  if (shift && lastChecked !== null && lastChecked !== id) {
    const ids = rowIds.value
    const i = ids.indexOf(lastChecked)
    const j = ids.indexOf(id)
    if (i >= 0 && j >= 0) ids.slice(Math.min(i, j), Math.max(i, j) + 1).forEach(x => (on ? next.add(x) : next.delete(x)))
  } else {
    on ? next.add(id) : next.delete(id)
  }
  lastChecked = id
  checked.value = next
}
function toggleAll() {
  checked.value = allChecked.value ? new Set() : new Set(rowIds.value)
  lastChecked = null
}
function clearChecked() {
  checked.value = new Set()
  lastChecked = null
}

// Mark the ticked conversations read / unread (the selection stays, like the classic clients).
const marking = ref(false)
async function markChecked(unread: boolean) {
  if (!checked.value.size || marking.value) return
  marking.value = true
  try {
    await mailbox.setUnreadMany([...checked.value], unread)
    // In "Unread", conversations just read leave the view.
    if (mailbox.folder.value === 'unread' && !mailbox.category.value && !mailbox.label.value) {
      clearChecked()
      await mailbox.reloadPage(activeAccountId.value)
    }
  } catch (err) {
    mailbox.showNotice('error', (err as Error).message)
    await mailbox.reloadPage(activeAccountId.value)
  } finally {
    marking.value = false
  }
}

const moving = ref(false)
async function moveChecked(e: Event) {
  const select = e.target as HTMLSelectElement
  const categoryId = Number(select.value)
  select.value = ''
  if (!categoryId || !checked.value.size || moving.value) return
  moving.value = true
  try {
    const ids = [...checked.value]
    const n = await cats.setForMessages(ids, categoryId)
    const name = cats.byId(categoryId)?.name ?? 'category'
    mailbox.showNotice('info', `${n} ${n === 1 ? 'conversation' : 'conversations'} moved to ${name}`)
    clearChecked()
    // Right away on screen: viewing another category, they leave the list; elsewhere they get the new label.
    const moved = new Set(ids)
    const threads = new Set(mailbox.messages.value.filter(m => moved.has(m.id)).map(m => m.thread_id))
    mailbox.messages.value = mailbox.category.value && mailbox.category.value !== categoryId
      ? mailbox.messages.value.filter(m => !moved.has(m.id))
      : mailbox.messages.value.map(m => (threads.has(m.thread_id) ? { ...m, category_id: categoryId } : m))
    // Then the page as the server sees it now (fills the gap from the next page).
    await mailbox.reloadPage(activeAccountId.value)
  } catch (err) {
    mailbox.showNotice('error', (err as Error).message)
  } finally {
    moving.value = false
  }
}

const rankingText = computed(() => {
  switch (mailbox.smartMeta.value?.ranking) {
    case 'semantic': return 'ranked by meaning (local index)'
    case 'fulltext': return 'keyword match (index not built yet)'
    case 'recent': return 'newest first'
    default: return ''
  }
})

const title = computed(() => {
  if (smartActive.value) return 'Smart search'
  if (mailbox.search.value) return `Search: ${mailbox.search.value}`
  if (mailbox.category.value) return cats.byId(mailbox.category.value)?.name ?? 'Category'
  if (mailbox.label.value) return mailbox.labels.value.find(l => l.provider_label_id === mailbox.label.value)?.name ?? 'Label'
  return mailbox.FOLDERS.find(f => f.key === mailbox.folder.value)?.label ?? 'Mailbox'
})

async function backFromSmart() {
  mailbox.smartMode.value = false
  await mailbox.clearSmartSearch(activeAccountId.value)
}

const listEl = ref<HTMLElement | null>(null)

/** "1–50 of 4,607" and the ‹ › page buttons. */
const range = computed(() => {
  const n = mailbox.messages.value.length
  if (!n) return null
  const start = mailbox.page.value * mailbox.perPage + 1
  return { start, end: start + n - 1, total: mailbox.total.value }
})
async function goPage(step: -1 | 1) {
  await mailbox.goToPage(activeAccountId.value, step)
  listEl.value?.scrollTo({ top: 0 })
}

const emptyText = computed(() => {
  if (!activeAccount.value) return 'Connect a Gmail or IMAP account to see your mail here.'
  if (smartActive.value) return 'No mail matches that description. Try fewer conditions or different words.'
  if (mailbox.search.value) return 'Nothing matches your search.'
  if (!activeAccount.value.initial_sync_done) return 'First sync in progress. Messages will appear shortly.'
  return 'No conversations here.'
})
</script>

<template>
  <section class="flex h-full w-full min-w-0 flex-col">
    <!-- Toolbar -->
    <div class="flex h-12 shrink-0 items-center gap-1 px-4">
      <label class="ml-1 flex cursor-pointer items-center rounded-full p-2 hover:bg-hover" :title="allChecked ? 'Deselect all' : 'Select all on this page'">
        <input
          type="checkbox"
          class="h-4 w-4 cursor-pointer accent-primary"
          :checked="allChecked"
          :indeterminate="someChecked"
          :disabled="!mailbox.messages.value.length"
          aria-label="Select all"
          @change="toggleAll"
        />
      </label>
      <!-- With conversations ticked: what to do with them -->
      <template v-if="checked.size">
        <span class="ml-1 text-sm font-medium text-ink">{{ checked.size }} selected</span>
        <label
          v-if="cats.categories.value.length > 1"
          class="ml-3 flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-sm text-ink-muted hover:bg-hover"
          title="Move the selected conversations to a category (picked by hand: the AI never moves them back)"
        >
          <Loader2 v-if="moving" class="h-4 w-4 animate-spin" />
          <span>Move to</span>
          <select class="bg-transparent text-ink outline-none" aria-label="Move selected to category" :disabled="moving" @change="moveChecked">
            <option value="" selected disabled>category…</option>
            <option v-for="c in cats.categories.value" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <button class="ml-1 rounded-full p-2 text-ink-muted hover:bg-hover disabled:opacity-40" :disabled="marking" title="Mark as read" @click="markChecked(false)">
          <MailOpen class="h-5 w-5" />
        </button>
        <button class="rounded-full p-2 text-ink-muted hover:bg-hover disabled:opacity-40" :disabled="marking" title="Mark as unread" @click="markChecked(true)">
          <Mail class="h-5 w-5" />
        </button>
        <button class="rounded-full p-2 text-ink-muted hover:bg-hover" title="Clear selection" @click="clearChecked"><X class="h-4 w-4" /></button>
      </template>
      <template v-else>
      <button
        class="rounded-full p-2 text-ink-muted hover:bg-hover disabled:opacity-40"
        :disabled="!activeAccount"
        title="Refresh"
        @click="refresh"
      >
        <RefreshCw class="h-5 w-5" :class="refreshing ? 'animate-spin' : ''" />
      </button>
      <button
        v-if="!smartActive"
        class="flex items-center gap-1 rounded-full px-2.5 py-1.5 text-sm text-ink-muted hover:bg-hover"
        :title="mailbox.sort.value === 'newest' ? 'Newest first · click for oldest first' : 'Oldest first · click for newest first'"
        @click="mailbox.sort.value = mailbox.sort.value === 'newest' ? 'oldest' : 'newest'"
      >
        <ArrowDownWideNarrow v-if="mailbox.sort.value === 'newest'" class="h-4 w-4" />
        <ArrowUpNarrowWide v-else class="h-4 w-4" />
        {{ mailbox.sort.value === 'newest' ? 'Newest' : 'Oldest' }}
      </button>
      <span v-if="title !== 'Inbox'" class="ml-2 truncate text-sm font-medium text-ink">{{ title }}</span>
      </template>
      <span class="flex-1" />
      <Loader2 v-if="mailbox.listLoading.value" class="mr-2 h-4 w-4 animate-spin text-ink-faint" />
      <template v-if="range && !smartActive">
        <span class="mr-2 text-xs text-ink-muted">{{ range.start.toLocaleString() }}–{{ range.end.toLocaleString() }}<template v-if="range.total"> of {{ range.total.toLocaleString() }}</template></span>
        <button class="rounded-full p-2 text-ink-muted hover:bg-hover disabled:opacity-30" :disabled="mailbox.page.value === 0" title="Newer" @click="goPage(-1)">
          <ChevronLeft class="h-5 w-5" />
        </button>
        <button class="rounded-full p-2 text-ink-muted hover:bg-hover disabled:opacity-30" :disabled="!mailbox.nextCursor.value" title="Older" @click="goPage(1)">
          <ChevronRight class="h-5 w-5" />
        </button>
      </template>
    </div>

    <!-- What the AI understood (smart search) -->
    <div v-if="smartActive && mailbox.smartMeta.value" class="space-y-1 border-b border-line px-6 pb-2 text-xs">
      <div class="flex flex-wrap gap-1">
        <span v-for="chip in mailbox.smartMeta.value.filters" :key="chip" class="rounded-full bg-nav-active px-2.5 py-0.5 text-nav-active-ink">{{ chip }}</span>
      </div>
      <div class="flex items-center gap-2 text-ink-muted">
        <span class="truncate">{{ mailbox.smartMeta.value.total }} result{{ mailbox.smartMeta.value.total === 1 ? '' : 's' }} · {{ rankingText }}</span>
        <span v-if="!mailbox.smartMeta.value.parsed" class="truncate text-amber-600" title="The model returned no usable filters; the sentence was used as the topic.">· could not extract filters</span>
        <span class="flex-1" />
        <button class="shrink-0 underline hover:text-ink" @click="backFromSmart">Back to {{ mailbox.FOLDERS.find(f => f.key === mailbox.folder.value)?.label ?? 'folder' }}</button>
      </div>
    </div>

    <div v-if="mailbox.listError.value" class="border-b border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
      {{ mailbox.listError.value }}
    </div>

    <div ref="listEl" class="min-h-0 flex-1 overflow-y-auto border-t border-line">
      <ul v-if="mailbox.messages.value.length">
        <MessageListItem
          v-for="m in mailbox.messages.value"
          :key="m.id"
          :message="m"
          :checked="checked.has(m.id)"
          @select="mailbox.select(m.id)"
          @check="(shift) => check(m.id, shift)"
        />
      </ul>
      <div v-else-if="!mailbox.listLoading.value" class="flex h-full flex-col items-center justify-center gap-2 px-8 text-center text-sm text-ink-muted">
        <p>{{ emptyText }}</p>
      </div>
    </div>
  </section>
</template>
