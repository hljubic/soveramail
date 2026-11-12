<script setup lang="ts">
import { Maximize2, Minus, X } from 'lucide-vue-next'
import { isSyncBusy } from '~/types/api'

const { activeAccountId, activeAccount, accounts, loaded } = useAccounts()
const mailbox = useMailbox()
const route = useRoute()
const router = useRouter()

// The address says which view this is (/, /unread, /category/3, /label/…): set it before
// the list loads, and follow it on every navigation (sidebar, Back / Forward, links).
function syncFromRoute() {
  const view = viewOf(route)
  if (view === 'invalid') void navigateTo('/', { replace: true })
  else if (view) applyMailView(view)
}
syncFromRoute()
watch(() => route.path, syncFromRoute)

// Reload the list whenever the account / folder / label / category / search / order changes.
watch(
  () => [activeAccountId.value, mailbox.folder.value, mailbox.label.value, mailbox.category.value, mailbox.search.value, mailbox.sort.value] as const,
  async ([accountId], old) => {
    const accountChanged = !old || old[0] !== accountId
    if (accountChanged) {
      // Only a real switch closes the open conversation; the first load (a refresh on ?m=) keeps it.
      if (old && old[0] != null) {
        mailbox.selectedId.value = null
        mailbox.selected.value = null
      }
      await mailbox.loadLabels(accountId)
    }
    await mailbox.loadMessages(accountId)
  },
  { immediate: true },
)

// While a sync is running, refresh the first page quietly so new mail shows up.
let pollTimer: ReturnType<typeof setInterval> | null = null
function pollWhileSyncing() {
  const a = activeAccount.value
  if (!a) return
  if (isSyncBusy(a.sync_status) || !a.last_synced_at) void mailbox.refreshMessages(a.id)
}

/** The conversation replaces the list (like the classic mail clients); Esc goes back. */
const reading = computed(() => mailbox.selectedId.value !== null)

// ---- Browser history: an open conversation is a step (?m=<id>), so the browser's
// Back (button, ⌘[, two-finger swipe) returns to the list and Forward reopens it.
const routeId = () => {
  const v = Number(Array.isArray(route.query.m) ? route.query.m[0] : route.query.m)
  return Number.isFinite(v) && v > 0 ? v : null
}
/** True when we pushed the current ?m= entry ourselves (then closing = going back in history). */
let pushedFromList = false

watch(() => mailbox.selectedId.value, (id, old) => {
  if (id === routeId()) return
  const query = { ...route.query }
  if (id === null) {
    delete query.m
    if (pushedFromList) {
      pushedFromList = false
      router.back()
    } else {
      void router.replace({ query })
    }
    return
  }
  query.m = String(id)
  if (old === null) {
    // From the list into a conversation: a new step.
    pushedFromList = true
    void router.push({ query })
  } else {
    // ‹ › or a folded message in the same view: same step, new address.
    void router.replace({ query })
  }
})

watch(() => route.query.m, () => {
  const id = routeId()
  if (id === mailbox.selectedId.value) return
  if (id === null) {
    // Back from a conversation (gesture / button): show the list.
    pushedFromList = false
    void mailbox.select(null)
  } else {
    // Forward, or a link with ?m=: open that conversation.
    void mailbox.select(id)
  }
})

onMounted(() => {
  const id = routeId()
  if (id !== null && mailbox.selectedId.value !== id) void mailbox.select(id)
})

// ---- New message: floating window, bottom right ----
const newCompose = computed(() => (mailbox.compose.value?.kind === 'new' ? mailbox.compose.value : null))
const minimized = ref(false)
const maximized = ref(false)
watch(newCompose, (c) => {
  if (c) minimized.value = false
})

function onKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement | null
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return
  if (e.metaKey || e.ctrlKey || e.altKey) return
  if (e.key === 'c') {
    mailbox.openCompose('new')
    e.preventDefault()
    return
  }
  if (mailbox.compose.value && mailbox.compose.value.kind !== 'new') return
  if (!mailbox.selected.value) return
  if (e.key === 'r') mailbox.openCompose('reply')
  else if (e.key === 'f') mailbox.openCompose('forward')
  else if (e.key === 'a') mailbox.openCompose('reply-all')
  else if (e.key === 'Escape') void mailbox.select(null)
  else if (e.key === 'j') void mailbox.openNeighbour(1)
  else if (e.key === 'k') void mailbox.openNeighbour(-1)
  else if (e.key === 'u') {
    const id = mailbox.selected.value.id
    const makeUnread = !mailbox.selected.value.is_unread
    if (makeUnread) void mailbox.select(null)
    void mailbox.setUnread(id, makeUnread)
  } else return
  e.preventDefault() // don't let the shortcut key land in the composer that just got focus
}

onMounted(() => {
  pollTimer = setInterval(pollWhileSyncing, 4000)
  window.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
  window.removeEventListener('keydown', onKey)
})

// Re-check after every finished sync as well.
watch(
  () => activeAccount.value?.last_synced_at,
  (v, old) => {
    if (v && v !== old) void mailbox.refreshMessages(activeAccountId.value)
  },
)

// Nothing connected yet: go straight to onboarding.
watch(
  () => [loaded.value, accounts.value.length] as const,
  ([isLoaded, count]) => {
    if (isLoaded && count === 0) void navigateTo('/accounts')
  },
  { immediate: true },
)
</script>

<template>
  <div class="relative flex h-full min-w-0 flex-col">
    <MessageView v-if="reading" />
    <MessageList v-else />

    <!-- New message: floating window -->
    <div
      v-if="newCompose"
      class="fixed bottom-0 z-40 flex flex-col overflow-hidden rounded-t-xl bg-surface shadow-[0_8px_10px_1px_rgba(0,0,0,.14),0_3px_14px_2px_rgba(0,0,0,.12),0_5px_5px_-3px_rgba(0,0,0,.2)]"
      :class="maximized ? 'left-[10%] right-[10%] top-[8%]' : minimized ? 'right-6 h-10 w-72' : 'right-6 h-[min(640px,85vh)] w-[min(560px,95vw)]'"
    >
      <div v-if="minimized" class="flex h-10 cursor-pointer items-center gap-2 bg-surface-2 px-4 text-sm font-medium text-ink" @click="minimized = false">
        <span class="flex-1 truncate">New Message</span>
        <button class="rounded-full p-1 text-ink-muted hover:bg-hover" title="Close" @click.stop="mailbox.flushDraftSave(); mailbox.closeCompose()"><X class="h-4 w-4" /></button>
      </div>
      <template v-else>
        <div class="absolute right-11 top-1.5 z-10 flex gap-0.5">
          <button class="rounded-full p-1.5 text-ink-muted hover:bg-hover" title="Minimize" @click="minimized = true; maximized = false"><Minus class="h-4 w-4" /></button>
          <button class="rounded-full p-1.5 text-ink-muted hover:bg-hover" :title="maximized ? 'Exit full screen' : 'Full screen'" @click="maximized = !maximized"><Maximize2 class="h-4 w-4" /></button>
        </div>
        <ComposePanel :mode="newCompose" class="min-h-0 flex-1" @close="mailbox.closeCompose()" />
      </template>
    </div>

    <!-- Toast -->
    <Transition name="fade">
      <div
        v-if="mailbox.notice.value"
        class="pointer-events-none fixed bottom-6 left-6 z-50 rounded-md px-4 py-3 text-sm shadow-lg"
        :class="mailbox.notice.value.kind === 'error' ? 'bg-red-600 text-white' : 'bg-[#323232] text-white'"
      >
        {{ mailbox.notice.value.text }}
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
