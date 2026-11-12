<script setup lang="ts">
import { Menu, Moon, Monitor, Settings, Sun } from 'lucide-vue-next'

const { accounts, activeAccount, fetchAccounts, fetchHealth, syncAccount } = useAccounts()
const { prefs, set } = usePreferences()

let pollTimer: ReturnType<typeof setInterval> | null = null

// Opening the app fetches new mail right away (once per app start, not per page).
// After that the server fetches every few minutes, but only while this window is
// open: the accounts poll below is what tells it the app is open.
const fetchedOnOpen = useState('fetched-on-open', () => false)

/** The left menu can be folded to icons (hamburger), like the big mail clients. */
const sidebarCollapsed = useState('sidebar-collapsed', () => false)

// The local AI: status poll (fast while a pass runs).
const aiRuntime = useAiRuntime()
let aiTimer: ReturnType<typeof setTimeout> | null = null
function pollAi() {
  void aiRuntime.refresh().finally(() => {
    aiTimer = setTimeout(pollAi, aiRuntime.running.value ? 3000 : 15000)
  })
}

onMounted(async () => {
  pollAi()

  await Promise.all([fetchHealth(), fetchAccounts()])

  if (!fetchedOnOpen.value) {
    fetchedOnOpen.value = true
    for (const a of accounts.value) {
      if (a.sync_status === 'needs_reauth' || a.sync_status === 'disconnected') continue
      void syncAccount(a.id).catch(() => { /* status shows on the account */ })
    }
  }

  // Keep account status fresh: poll and refresh on focus.
  pollTimer = setInterval(tick, 5000)
  window.addEventListener('focus', tick)
})

const tick = () => void fetchAccounts()

onBeforeUnmount(() => {
  if (aiTimer) clearTimeout(aiTimer)
  if (pollTimer) clearInterval(pollTimer)
  window.removeEventListener('focus', tick)
})

const THEMES = [
  { key: 'light', label: 'Light theme', icon: Sun },
  { key: 'dark', label: 'Dark theme', icon: Moon },
  { key: 'system', label: 'Follow the system', icon: Monitor },
] as const
const theme = computed(() => THEMES.find(t => t.key === prefs.value.theme) ?? THEMES[0])
function nextTheme() {
  const i = THEMES.findIndex(t => t.key === prefs.value.theme)
  set('theme', THEMES[(i + 1) % THEMES.length]!.key)
}
</script>

<template>
  <div class="flex h-full w-full flex-col overflow-hidden bg-page">
    <!-- Top bar: menu, logo, search, theme, settings, account -->
    <header class="flex h-16 shrink-0 items-center gap-2 pl-2 pr-4">
      <button class="rounded-full p-3 text-ink-muted hover:bg-hover" title="Main menu" @click="sidebarCollapsed = !sidebarCollapsed">
        <Menu class="h-5 w-5" />
      </button>
      <NuxtLink to="/" class="mr-6 flex shrink-0 items-center gap-2" :class="sidebarCollapsed ? '' : 'w-[166px]'">
        <img src="/icon.svg" alt="" class="h-8 w-8" />
        <span class="text-[22px] leading-none text-ink-muted">Sovera</span>
      </NuxtLink>
      <MailSearch class="min-w-0 flex-1" />
      <div class="ml-auto flex items-center gap-1 pl-2">
        <AiProcessButton class="mr-1" />
        <button class="rounded-full p-2.5 text-ink-muted hover:bg-hover" :title="`${theme.label} · click to switch`" @click="nextTheme">
          <component :is="theme.icon" class="h-5 w-5" />
        </button>
        <NuxtLink to="/accounts" class="rounded-full p-2.5 text-ink-muted hover:bg-hover" title="Accounts & settings">
          <Settings class="h-5 w-5" />
        </NuxtLink>
        <NuxtLink v-if="activeAccount" to="/accounts" class="ml-1 rounded-full p-1 hover:bg-hover" :title="activeAccount.email">
          <AccountAvatar :account="activeAccount" size="md" />
        </NuxtLink>
      </div>
    </header>

    <div class="flex min-h-0 flex-1">
      <AppSidebar :collapsed="sidebarCollapsed" />
      <main class="mb-4 mr-4 flex min-w-0 flex-1 flex-col overflow-hidden rounded-2xl bg-surface">
        <slot />
      </main>
    </div>
  </div>
</template>
