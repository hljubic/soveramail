<script setup lang="ts">
import { Loader2, Search, Sparkles, X } from 'lucide-vue-next'

/**
 * The big search box in the top bar. By default it is plain keyword search: fast and
 * deterministic (the database's full-text index, newest first, no AI). The "Smart
 * pretraga" switch turns it into smart search: the sentence goes to the local AI on Enter.
 */
const { activeAccountId } = useAccounts()
const mailbox = useMailbox()
const ai = useAi()
const route = useRoute()

const searchInput = ref(mailbox.search.value)
const searchEl = ref<HTMLInputElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
const smartMode = mailbox.smartMode

const SMART_EXAMPLE = '“Word or PDF documents – find all invoices for electricity”'
const smartTooltip = computed(() => {
  if (smartMode.value) return 'Smart search is on: describe the mail in your own words and press Enter. Click (or Esc) for the plain, fast search again.'
  if (!ai.ready.value) return 'Smart search · set up the local AI first'
  return 'Plain search: keywords, fast and always the same results. Switch on for smart search (local AI, describe in your own words).'
})

const toMail = () => (isMailRoute(route) ? Promise.resolve() : navigateTo('/'))

watch(searchInput, (v) => {
  if (smartMode.value) return
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    void toMail()
    void mailbox.select(null)
    mailbox.search.value = v
  }, 250)
})

// Leaving smart search from the list ("Back to Inbox") clears the box too.
watch(smartMode, (on) => {
  if (!on) searchInput.value = ''
})

async function toggleSmart() {
  if (!smartMode.value && !ai.ready.value) {
    mailbox.showNotice('info', 'Smart search needs your local AI. Pick an Ollama model first.')
    await navigateTo('/ai')
    return
  }
  smartMode.value = !smartMode.value
  if (smartMode.value) {
    if (searchTimer) clearTimeout(searchTimer)
    mailbox.search.value = ''
  } else {
    await mailbox.clearSmartSearch(activeAccountId.value)
    searchInput.value = ''
  }
  await nextTick()
  searchEl.value?.focus()
}

async function submitSmart() {
  if (!smartMode.value || !searchInput.value.trim()) return
  await toMail()
  await mailbox.select(null)
  try {
    await mailbox.runSmartSearch(activeAccountId.value, searchInput.value)
  } catch (e) {
    const err = e as { data?: { ai_unavailable?: boolean }; message: string }
    if (err.data?.ai_unavailable) {
      mailbox.showNotice('error', err.message)
      await navigateTo('/ai')
    }
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && smartMode.value) {
    e.preventDefault()
    void submitSmart()
  } else if (e.key === 'Escape') {
    if (smartMode.value) void toggleSmart()
    else searchInput.value = ''
    searchEl.value?.blur()
  }
}

async function clear() {
  searchInput.value = ''
  if (smartMode.value) await mailbox.clearSmartSearch(activeAccountId.value)
  searchEl.value?.focus()
}

// "/" focuses the search, like the big mail clients.
function onGlobalKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement | null
  if (e.key !== '/' || (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable))) return
  e.preventDefault()
  searchEl.value?.focus()
}
onMounted(() => window.addEventListener('keydown', onGlobalKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<template>
  <div class="w-full max-w-3xl">
    <label
      class="flex h-12 items-center gap-3 rounded-full px-4 transition focus-within:shadow-md"
      :class="smartMode ? 'bg-surface ring-2 ring-primary' : 'bg-search focus-within:bg-search-focus'"
    >
      <Loader2 v-if="mailbox.smartLoading.value" class="h-5 w-5 shrink-0 animate-spin text-primary" />
      <Sparkles v-else-if="smartMode" class="h-5 w-5 shrink-0 text-primary" />
      <Search v-else class="h-5 w-5 shrink-0 text-ink-muted" />
      <input
        ref="searchEl"
        v-model="searchInput"
        type="search"
        :placeholder="smartMode ? `Describe the mail, e.g. ${SMART_EXAMPLE}` : 'Search mail'"
        class="min-w-0 flex-1 bg-transparent text-base text-ink outline-none placeholder:text-ink-muted"
        @keydown="onKeydown"
      />
      <button v-if="searchInput" class="rounded-full p-1 text-ink-muted hover:bg-hover" title="Clear search" @click.prevent="clear">
        <X class="h-5 w-5" />
      </button>
      <button
        type="button"
        role="switch"
        class="flex shrink-0 items-center gap-2 rounded-full py-1 pl-2.5 pr-1.5 text-xs font-medium transition"
        :class="smartMode ? 'bg-primary/10 text-primary' : 'text-ink-muted hover:bg-hover'"
        :title="smartTooltip"
        :aria-checked="smartMode"
        @click.prevent="toggleSmart"
      >
        <Sparkles class="h-4 w-4" />
        <span class="hidden whitespace-nowrap sm:inline">Smart search</span>
        <span class="relative inline-flex h-4 w-7 shrink-0 rounded-full transition" :class="smartMode ? 'bg-primary' : 'bg-ink-faint/40'">
          <span class="absolute top-0.5 h-3 w-3 rounded-full bg-white shadow transition-all" :class="smartMode ? 'left-3.5' : 'left-0.5'" />
        </span>
      </button>
    </label>
  </div>
</template>
