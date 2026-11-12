<script setup lang="ts">
import { Archive, ChevronDown, FolderCog, Inbox, Mail, MailOpen, MessageSquareText, Pencil, RefreshCw, Send, Settings2, Sparkles, Star, Tag } from 'lucide-vue-next'
import type { Folder } from '~/types/api'

defineProps<{ collapsed?: boolean }>()

const { accounts, activeAccount, activeAccountId, setActive, syncAccount, anySyncing } = useAccounts()
const mailbox = useMailbox()
const ai = useAi()
onMounted(() => {
  void ai.load()
})
const route = useRoute()

const folderIcons: Record<Folder, unknown> = {
  inbox: Inbox,
  starred: Star,
  unread: MailOpen,
  sent: Send,
  drafts: Pencil,
  archive: Archive,
  all: Mail,
  important: Mail,
  spam: Mail,
  trash: Mail,
}

const userLabels = computed(() => mailbox.labels.value.filter(l => l.type === 'user' && l.visible).slice(0, 40))
const showLabels = ref(false)

const onMailPage = computed(() => isMailRoute(route))
const openMailPage = () => (onMailPage.value ? Promise.resolve() : navigateTo('/'))

async function newMessage() {
  await openMailPage()
  mailbox.openCompose('new')
}

// Each view has its own address (/unread, /category/3, …): navigating is all it takes;
// the mailbox follows the address, and leaving ?m= closes an open conversation.
async function chooseFolder(f: Folder) {
  await navigateTo(mailPath({ folder: f }))
}

async function chooseLabel(id: string) {
  await navigateTo(mailPath({ label: id }))
}

// Categories: counts follow the active account and refresh while the model sorts.
const cats = useCategories()
watch(activeAccountId, id => void cats.load(id), { immediate: true })
let catsTimer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  catsTimer = setInterval(() => void cats.load(), 15000)
})
onBeforeUnmount(() => {
  if (catsTimer) clearInterval(catsTimer)
})

/** Share of conversations already sorted, while sorting is still going on. */
const classifyPct = computed(() => {
  const m = cats.meta.value
  if (!m?.active || !m.progress.total || m.progress.done >= m.progress.total) return null
  return Math.floor((m.progress.done / m.progress.total) * 100)
})

async function chooseCategory(id: number) {
  await navigateTo(mailPath({ category: id }))
}

async function syncNow() {
  if (!activeAccount.value) return
  try {
    await syncAccount(activeAccount.value.id)
  } catch {
    /* status is shown on the account */
  }
}

const isFolderActive = (f: Folder) => onMailPage.value && !mailbox.label.value && !mailbox.category.value && mailbox.folder.value === f
const item = 'flex h-8 w-full items-center gap-4 rounded-r-full pl-6 pr-3 text-sm transition'
const itemIdle = 'text-ink hover:bg-hover'
const itemActive = 'bg-nav-active font-bold text-nav-active-ink'
</script>

<template>
  <aside class="flex h-full shrink-0 flex-col overflow-hidden transition-[width]" :class="collapsed ? 'w-[72px]' : 'w-64'">
    <!-- Compose -->
    <div class="py-2 pl-2 pr-4">
      <button
        type="button"
        class="flex h-14 items-center gap-3 rounded-2xl bg-compose px-4 text-sm font-medium text-compose-ink shadow-sm transition hover:shadow-md disabled:opacity-50"
        :disabled="!activeAccount"
        title="Compose (c)"
        @click="newMessage"
      >
        <Pencil class="h-5 w-5" />
        <span v-if="!collapsed" class="pr-3">Compose</span>
      </button>
    </div>

    <nav class="min-h-0 flex-1 overflow-y-auto pb-2 pr-2">
      <ul>
        <li v-for="f in mailbox.FOLDERS" :key="f.key">
          <button :class="[item, isFolderActive(f.key) ? itemActive : itemIdle]" :title="f.label" @click="chooseFolder(f.key)">
            <component :is="folderIcons[f.key]" class="h-5 w-5 shrink-0" />
            <template v-if="!collapsed">
              <span class="flex-1 truncate text-left">{{ f.label }}</span>
              <span v-if="f.key === 'inbox' && activeAccount?.unread_count" class="text-xs font-bold">{{ activeAccount.unread_count }}</span>
            </template>
          </button>
        </li>
      </ul>

      <!-- Local categories (sorted by the local model; exist only in Sovera Mail) -->
      <div v-if="!collapsed" class="mt-4 flex items-center pl-6 pr-3 pb-1">
        <p class="flex-1 text-base text-ink">Categories</p>
        <NuxtLink to="/categories" class="rounded-full p-1.5 text-ink-muted hover:bg-hover" title="Edit categories">
          <Settings2 class="h-4 w-4" />
        </NuxtLink>
      </div>
      <ul v-if="cats.categories.value.length > 1">
        <li v-for="c in cats.categories.value" :key="c.id">
          <button
            :class="[item, onMailPage && mailbox.category.value === c.id ? itemActive : itemIdle]"
            :title="`${c.name} · ${c.threads} conversation${c.threads === 1 ? '' : 's'}`"
            @click="chooseCategory(c.id)"
          >
            <Tag class="h-5 w-5 shrink-0" :style="{ color: c.color, fill: c.color }" />
            <template v-if="!collapsed">
              <span class="flex-1 truncate text-left">{{ c.name }}</span>
              <span v-if="c.unread" class="text-xs font-bold">{{ c.unread }}</span>
            </template>
          </button>
        </li>
      </ul>
      <NuxtLink v-else-if="!collapsed" to="/categories" class="block pl-6 py-1 text-xs text-ink-muted hover:text-ink">Define categories…</NuxtLink>
      <p v-if="classifyPct !== null && !collapsed" class="pl-6 pt-1 text-[11px] text-ink-faint">Sorting with local AI… {{ classifyPct }}%</p>

      <!-- Gmail labels, folded by default -->
      <template v-if="userLabels.length && !collapsed">
        <button class="mt-4 flex w-full items-center gap-2 pl-6 pr-3 pb-1 text-left text-base text-ink" @click="showLabels = !showLabels">
          <span class="flex-1">Labels</span>
          <ChevronDown class="h-4 w-4 text-ink-muted transition-transform" :class="showLabels ? 'rotate-180' : ''" />
        </button>
        <ul v-if="showLabels">
          <li v-for="l in userLabels" :key="l.id">
            <button :class="[item, onMailPage && mailbox.label.value === l.provider_label_id ? itemActive : itemIdle]" @click="chooseLabel(l.provider_label_id)">
              <Tag class="h-5 w-5 shrink-0" :style="l.color_bg ? { color: l.color_bg } : undefined" />
              <span class="flex-1 truncate text-left">{{ l.name }}</span>
            </button>
          </li>
        </ul>
      </template>

      <!-- Local AI -->
      <ul class="mt-4">
        <li>
          <NuxtLink to="/categories" :class="[item, route.path === '/categories' ? itemActive : itemIdle]" title="Categories: names and descriptions">
            <FolderCog class="h-5 w-5 shrink-0" />
            <span v-if="!collapsed" class="flex-1 truncate text-left">Categories</span>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink to="/prompt" :class="[item, route.path === '/prompt' ? itemActive : itemIdle]" title="AI instructions">
            <MessageSquareText class="h-5 w-5 shrink-0" />
            <span v-if="!collapsed" class="flex-1 truncate text-left">AI instructions</span>
          </NuxtLink>
        </li>
        <li>
          <NuxtLink to="/ai" :class="[item, route.path === '/ai' ? itemActive : itemIdle]" :title="ai.ready.value ? `Local AI · ${ai.settings.value?.model}` : 'Configure AI'">
            <Sparkles class="h-5 w-5 shrink-0" />
            <template v-if="!collapsed">
              <span class="flex-1 truncate text-left">{{ ai.ready.value ? 'Local AI' : 'Configure AI' }}</span>
              <span class="inline-block h-2 w-2 rounded-full" :class="ai.ready.value ? 'bg-emerald-500' : 'bg-neutral-400'" />
            </template>
          </NuxtLink>
        </li>
      </ul>
    </nav>

    <!-- Local AI at work / models in RAM ("Unload") -->
    <AiStatusBar :collapsed="collapsed" />

    <!-- Accounts and sync -->
    <div class="space-y-0.5 pb-3 pr-2">
      <button
        v-for="a in accounts"
        :key="a.id"
        :class="[item, 'h-9', activeAccount?.id === a.id ? '' : 'opacity-70']"
        class="text-ink hover:bg-hover"
        :title="`${a.email} · ${accounts.length > 1 ? 'switch to this account' : 'account settings'}`"
        @click="accounts.length > 1 ? setActive(a.id) : navigateTo('/accounts')"
      >
        <AccountAvatar :account="a" size="sm" />
        <template v-if="!collapsed">
          <span class="min-w-0 flex-1 truncate text-left text-xs">{{ a.email }}</span>
          <SyncStatusDot :status="a.sync_status" />
        </template>
      </button>
      <button
        :class="[item, itemIdle]"
        :disabled="!activeAccount || activeAccount.sync_status === 'needs_reauth' || activeAccount.sync_status === 'disconnected'"
        title="Fetch new mail now"
        @click="syncNow"
      >
        <RefreshCw class="h-5 w-5 shrink-0" :class="anySyncing ? 'animate-spin' : ''" />
        <span v-if="!collapsed" class="text-left text-xs text-ink-muted">{{ anySyncing ? 'Syncing…' : 'Sync now' }}</span>
      </button>
    </div>
  </aside>
</template>
