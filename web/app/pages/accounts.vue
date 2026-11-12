<script setup lang="ts">
import { AlertTriangle, ArrowLeft, CheckCircle2, ExternalLink, Loader2, RefreshCw, Server, Trash2, Unplug } from 'lucide-vue-next'
import { isSyncBusy, type Account } from '~/types/api'

const { accounts, health, fetchAccounts, fetchHealth, startOauthConnect, syncAccount, removeAccount, setActive } = useAccounts()
const { prefs, set: setPref } = usePreferences()
const config = useRuntimeConfig()

const connecting = ref(false)
const connectError = ref<string | null>(null)
const busy = ref<Record<number, boolean>>({})
const actionError = ref<string | null>(null)
const RANGES = [
  { days: 30, label: 'Last month' },
  { days: 91, label: 'Last 3 months' },
  { days: 182, label: 'Last 6 months' },
  { days: 365, label: 'Last year' },
  { days: 730, label: 'Last 2 years' },
  { days: 1826, label: 'Last 5 years' },
  { days: 0, label: 'Everything' },
]
const LIMITS = [10, 50, 100, 500, 1000]

// The fetch pickers and the "what is running" labels live in shared state and in
// localStorage, so leaving the page and coming back does not silently reset them
// to "Last month" while a much bigger fetch is still downloading in the background.
const PREFS_KEY = 'sovera:fetch-prefs'
const backfillLimit = useState<number>('fetch-backfill-limit', () => 100)
const rangeDays = useState<number>('fetch-range-days', () => 30)
/** Per account: what it was last asked to fetch, kept while that sync is busy. */
const pendingFetch = useState<Record<number, string>>('fetch-pending', () => ({}))

const describeRange = (days: number) =>
  days === 0 ? 'the entire mailbox' : (RANGES.find(r => r.days === days)?.label.toLowerCase() ?? `the last ${days} days`)

function loadPrefs() {
  try {
    const raw = JSON.parse(localStorage.getItem(PREFS_KEY) ?? '{}') as Record<string, unknown>
    if (RANGES.some(r => r.days === raw.rangeDays)) rangeDays.value = raw.rangeDays as number
    if (LIMITS.includes(raw.backfillLimit as number)) backfillLimit.value = raw.backfillLimit as number
    if (raw.pending && typeof raw.pending === 'object') pendingFetch.value = raw.pending as Record<number, string>
  } catch {
    /* unreadable prefs just fall back to the defaults */
  }
}

watch([rangeDays, backfillLimit, pendingFetch], () => {
  if (!import.meta.client) return
  localStorage.setItem(PREFS_KEY, JSON.stringify({
    rangeDays: rangeDays.value,
    backfillLimit: backfillLimit.value,
    pending: pendingFetch.value,
  }))
}, { deep: true })

/** Forget labels for accounts that finished (or never started) their fetch. */
function prunePending() {
  const next: Record<number, string> = {}
  for (const a of accounts.value) {
    const label = pendingFetch.value[a.id]
    if (label && isSyncBusy(a.sync_status)) next[a.id] = label
  }
  if (JSON.stringify(next) !== JSON.stringify(pendingFetch.value)) pendingFetch.value = next
}

watch(accounts, prunePending, { deep: true })

let waitTimer: ReturnType<typeof setInterval> | null = null
let waitDeadline = 0

function stopWaiting() {
  if (waitTimer) clearInterval(waitTimer)
  waitTimer = null
  connecting.value = false
  connectingProvider.value = null
}

/** Which OAuth provider we are waiting for, so only its button spins. */
const connectingProvider = ref<'google' | null>(null)

async function connectOauth(provider: 'google') {
  connectError.value = null
  connecting.value = true
  connectingProvider.value = provider
  try {
    const url = await startOauthConnect(provider)
    const before = new Map(accounts.value.map(a => [a.id, { status: a.sync_status, scopes: [...a.scopes].sort().join(' ') }]))
    window.open(url, '_blank', 'noopener,noreferrer')

    // Poll until a new or re-authorised account shows up (or 5 minutes pass).
    // "Re-authorised" = scopes changed, left needs_reauth, or a sync got queued by the callback.
    waitDeadline = Date.now() + 5 * 60_000
    if (waitTimer) clearInterval(waitTimer)
    waitTimer = setInterval(async () => {
      await fetchAccounts()
      const fresh = accounts.value.find((a) => {
        const prev = before.get(a.id)
        if (!prev) return true
        if (prev.scopes !== [...a.scopes].sort().join(' ')) return true
        if (prev.status === 'needs_reauth' && a.sync_status !== 'needs_reauth') return true
        return isSyncBusy(a.sync_status) && !isSyncBusy(prev.status)
      })
      if (fresh) {
        setActive(fresh.id)
        stopWaiting()
        await navigateTo('/')
      } else if (Date.now() > waitDeadline) {
        stopWaiting()
      }
    }, 2000)
  } catch (e) {
    connectError.value = (e as Error).message
    connecting.value = false
    connectingProvider.value = null
  }
}

onBeforeUnmount(() => {
  stopWaiting()
})

onMounted(() => {
  loadPrefs()
  prunePending()
  void fetchHealth()
})

async function run(a: Account, fn: () => Promise<unknown>) {
  actionError.value = null
  busy.value = { ...busy.value, [a.id]: true }
  try {
    await fn()
  } catch (e) {
    actionError.value = (e as Error).message
  } finally {
    busy.value = { ...busy.value, [a.id]: false }
  }
}

/** Queue a fetch and remember what it was, so the row can say what is running. */
async function startFetch(a: Account, label: string, opts: { mode: 'full' | 'backfill' | 'range'; limit?: number; since_days?: number }) {
  pendingFetch.value = { ...pendingFetch.value, [a.id]: label }
  await run(a, () => syncAccount(a.id, opts))
  prunePending() // drops the label again if nothing got queued
}

// "Remove account" opens a choice per account: keep the local mail or delete it.
const removing = ref<number | null>(null)

function disconnectKeepMail(a: Account) {
  removing.value = null
  void run(a, () => removeAccount(a.id, true))
}

function deleteLocalCopy(a: Account) {
  const n = a.messages_count ?? 0
  if (!window.confirm(`Delete the local copy of ${a.email} (${n} messages, attachments and drafts on this computer)?\n\nNothing is removed from Gmail. Reconnecting later will download the mailbox again.`)) return
  removing.value = null
  void run(a, () => removeAccount(a.id, false))
}

const googleReady = computed(() => health.value?.google_configured ?? true)

// IMAP/SMTP: "Add" shows a blank form; "Reconnect" on an IMAP account shows it prefilled.
const imapForm = ref<false | 'new' | Account>(false)
async function imapDone(account: Account) {
  imapForm.value = false
  setActive(account.id)
  await fetchAccounts()
}
function reconnect(a: Account) {
  if (a.provider === 'imap') imapForm.value = a
  else void connectOauth('google')
}

const PROVIDER_LABEL: Record<Account['provider'], string> = { gmail: 'Gmail', imap: 'IMAP' }
const canFetch = (a: Account) => a.sync_status !== 'needs_reauth' && a.sync_status !== 'disconnected'
const fetchBusy = (a: Account) => !!busy.value[a.id] || isSyncBusy(a.sync_status)

const fmt = (n: number) => n.toLocaleString()
/** Progress bar data while a fetch with a known size is running. */
function progressOf(a: Account) {
  const p = a.sync_progress
  if (!p || !isSyncBusy(a.sync_status) || p.total <= 0) return null
  return { done: p.done, total: p.total, pct: Math.min(100, Math.floor((p.done / p.total) * 100)) }
}
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-neutral-200 px-5 dark:border-neutral-800">
      <NuxtLink v-if="accounts.length" to="/" class="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
        <ArrowLeft class="h-4 w-4" />
      </NuxtLink>
      <h1 class="text-sm font-semibold">Accounts</h1>
    </header>

    <div class="flex-1 overflow-y-auto">
      <div class="mx-auto max-w-2xl px-6 py-8">
        <!-- Onboarding hero -->
        <div v-if="!accounts.length" class="mb-8 text-center">
          <img src="/icon.svg" alt="" class="mx-auto mb-4 h-16 w-16" />
          <h2 class="text-2xl font-semibold tracking-tight">Your mail. Your data. Your AI.</h2>
          <p class="mx-auto mt-2 max-w-md text-sm text-neutral-500">
            Sovera Mail keeps your mailbox on this computer. Connect Gmail or any IMAP mailbox to pull in your latest messages.
          </p>
        </div>

        <!-- Local API / config status -->
        <div
          v-if="health && !health.google_configured"
          class="mb-6 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-100"
        >
          <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p class="font-medium">Google OAuth is not configured.</p>
            <p class="mt-1 text-xs opacity-80">
              Set <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in the project <code>.env</code> and restart the API containers. See README for the Google Cloud setup.
            </p>
          </div>
        </div>
        <div
          v-else-if="!health"
          class="mb-6 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/50 dark:text-red-100"
        >
          <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p class="font-medium">Cannot reach the local API at {{ config.public.apiBase }}.</p>
            <p class="mt-1 text-xs opacity-80">Run <code>docker compose up</code> in the project folder and try again.</p>
          </div>
        </div>

        <!-- Connect button -->
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-800">
          <div class="flex items-center gap-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white shadow ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-700">
              <svg viewBox="0 0 24 24" class="h-5 w-5"><path fill="#EA4335" d="M12 10.2v3.9h5.4c-.2 1.3-1.6 3.8-5.4 3.8-3.3 0-5.9-2.7-5.9-6s2.6-6 5.9-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.3 14.6 2.4 12 2.4 6.7 2.4 2.4 6.7 2.4 12s4.3 9.6 9.6 9.6c5.5 0 9.2-3.9 9.2-9.4 0-.6-.1-1.1-.2-1.6H12z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">Gmail / Google Workspace</p>
              <p class="text-xs text-neutral-500">Sign in with Google in your browser. Sovera Mail asks to read, mark and send mail.</p>
            </div>
            <button
              class="flex items-center gap-2 rounded-lg bg-sovera-600 px-3.5 py-2 text-sm font-medium text-white transition hover:bg-sovera-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="connecting || !googleReady || !health"
              @click="connectOauth('google')"
            >
              <Loader2 v-if="connectingProvider === 'google'" class="h-4 w-4 animate-spin" />
              <ExternalLink v-else class="h-4 w-4" />
              {{ connectingProvider === 'google' ? 'Waiting for Google…' : accounts.length ? 'Add Gmail account' : 'Connect Gmail' }}
            </button>
          </div>
          <p v-if="connecting" class="mt-3 text-xs text-neutral-500">
            Finish signing in in your browser. This window updates automatically.
            <button class="ml-1 underline" @click="stopWaiting">Cancel</button>
          </p>
          <p v-if="connectError" class="mt-3 text-xs text-red-600">{{ connectError }}</p>
        </div>

        <!-- IMAP / SMTP -->
        <ImapAccountForm v-if="imapForm === 'new'" class="mt-4" @done="imapDone" @cancel="imapForm = false" />
        <div v-else class="mt-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-800">
          <div class="flex items-center gap-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white shadow ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-700">
              <Server class="h-5 w-5 text-neutral-600 dark:text-neutral-300" />
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">Other mailbox (IMAP/SMTP)</p>
              <p class="text-xs text-neutral-500">iCloud, Yahoo, Fastmail, Zoho, GMX, Proton (Bridge), hosting or university mail. Usually with an app password.</p>
            </div>
            <button
              class="flex items-center gap-2 rounded-lg border border-neutral-200 px-3.5 py-2 text-sm font-medium transition hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
              :disabled="!health"
              @click="imapForm = 'new'"
            >
              Add IMAP account
            </button>
          </div>
        </div>

        <!-- Connected accounts -->
        <div v-if="accounts.length" class="mt-8">
          <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-neutral-500">Connected</h3>
          <p v-if="actionError" class="mb-2 text-xs text-red-600">{{ actionError }}</p>
          <ul class="space-y-3">
            <li v-for="a in accounts" :key="a.id" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
              <div class="flex items-center gap-3">
                <AccountAvatar :account="a" size="lg" />
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ a.display_name || a.email }}</p>
                  <p class="truncate text-xs text-neutral-500">
                    {{ a.email }}
                    <span class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-neutral-500 dark:bg-neutral-800" :title="a.provider === 'imap' ? `${a.imap?.host} / ${a.imap?.smtp_host}` : 'OAuth'">{{ PROVIDER_LABEL[a.provider] }}</span>
                  </p>
                  <p class="mt-1 flex items-center gap-1.5 text-xs">
                    <SyncStatusDot :status="a.sync_status" />
                    <span v-if="a.sync_status === 'syncing'">Syncing{{ pendingFetch[a.id] ? ` ${pendingFetch[a.id]}` : '' }}…</span>
                    <span v-else-if="a.sync_status === 'queued'">{{ pendingFetch[a.id] ? `Queued: ${pendingFetch[a.id]}` : 'Sync queued' }}</span>
                    <span v-else-if="a.sync_status === 'needs_reauth'" class="text-red-600">Reconnect required</span>
                    <span v-else-if="a.sync_status === 'disconnected'" class="text-neutral-500">Disconnected · {{ a.messages_count ?? 0 }} messages kept on this computer</span>
                    <span v-else-if="a.sync_status === 'error'" class="text-amber-600">Last sync failed</span>
                    <span v-else class="flex items-center gap-1 text-neutral-500">
                      <CheckCircle2 class="h-3 w-3 text-emerald-500" />
                      {{ fmt(a.messages_count ?? 0) }}<template v-if="a.mailbox_total"> of {{ fmt(a.mailbox_total) }}</template> messages · synced {{ formatRelative(a.last_synced_at) }}
                    </span>
                  </p>
                  <div v-if="progressOf(a)" class="mt-2 max-w-sm">
                    <div class="h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                      <div class="h-full rounded-full bg-sovera-600 transition-all duration-500" :style="{ width: `${progressOf(a)!.pct}%` }" />
                    </div>
                    <p class="mt-1 text-xs text-neutral-500">
                      {{ fmt(progressOf(a)!.done) }} / {{ fmt(progressOf(a)!.total) }} messages ({{ progressOf(a)!.pct }}%)
                      <template v-if="a.mailbox_total"> · {{ fmt(a.mailbox_total) }} in Gmail</template>
                    </p>
                  </div>
                  <p v-else-if="isSyncBusy(a.sync_status)" class="mt-1 text-xs text-neutral-500">
                    {{ fmt(a.messages_count ?? 0) }} messages on this computer<template v-if="a.mailbox_total"> · {{ fmt(a.mailbox_total) }} in Gmail</template> · listing what to fetch…
                  </p>
                  <p v-if="a.sync_error" class="mt-1 line-clamp-2 text-xs text-neutral-500" :title="a.sync_error">{{ a.sync_error }}</p>
                  <p v-if="!a.can_modify && a.sync_status !== 'needs_reauth' && a.sync_status !== 'disconnected'" class="mt-1 text-xs text-amber-600">
                    Connected read-only. Reconnect to enable marking read/unread and sending replies.
                  </p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                  <button
                    v-if="a.sync_status === 'needs_reauth' || a.sync_status === 'disconnected' || !a.can_modify"
                    class="rounded-lg bg-sovera-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sovera-700"
                    @click="reconnect(a)"
                  >
                    Reconnect
                  </button>
                  <button
                    v-if="a.sync_status !== 'disconnected'"
                    class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 dark:hover:bg-neutral-800"
                    title="Fetch new mail"
                    :disabled="busy[a.id] || a.sync_status === 'needs_reauth'"
                    @click="run(a, () => syncAccount(a.id))"
                  >
                    <RefreshCw class="h-4 w-4" :class="isSyncBusy(a.sync_status) ? 'animate-spin' : ''" />
                  </button>
                  <button
                    class="rounded-lg p-2 text-neutral-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-950"
                    :title="a.sync_status === 'disconnected' ? 'Delete local copy' : 'Remove account'"
                    :disabled="busy[a.id]"
                    @click="a.sync_status === 'disconnected' ? deleteLocalCopy(a) : (removing = removing === a.id ? null : a.id)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </div>

              <ImapAccountForm v-if="imapForm !== false && imapForm !== 'new' && imapForm.id === a.id" class="mt-3" :account="a" @done="imapDone" @cancel="imapForm = false" />

              <!-- Remove: the user decides what happens to the local mail -->
              <div v-if="removing === a.id" class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                <p class="font-medium">Remove {{ a.email }}?</p>
                <p class="mt-1 text-neutral-500">Nothing is ever removed from Gmail. Choose what happens to the copy on this computer:</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button
                    class="flex items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-2.5 py-1.5 font-medium hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-950 dark:hover:bg-neutral-800"
                    :disabled="busy[a.id]"
                    @click="disconnectKeepMail(a)"
                  >
                    <Unplug class="h-3.5 w-3.5" /> Disconnect, keep mail
                  </button>
                  <button
                    class="flex items-center gap-1.5 rounded-md bg-red-600 px-2.5 py-1.5 font-medium text-white hover:bg-red-700"
                    :disabled="busy[a.id]"
                    @click="deleteLocalCopy(a)"
                  >
                    <Trash2 class="h-3.5 w-3.5" /> Delete local copy
                  </button>
                  <button class="px-2 py-1.5 text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="removing = null">Cancel</button>
                </div>
                <p class="mt-2 text-neutral-500">
                  <strong>Disconnect</strong> forgets the Google sign-in but keeps {{ a.messages_count ?? 0 }} messages here, so reconnecting later continues without downloading again.
                  <strong>Delete</strong> removes them from this computer.
                </p>
              </div>

              <div v-if="canFetch(a)" class="mt-3 flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-3 text-xs dark:border-neutral-800">
                <span class="text-neutral-500">Fetch history:</span>
                <select v-model.number="rangeDays" class="rounded-md border border-neutral-200 bg-transparent px-2 py-1 dark:border-neutral-700">
                  <option v-for="r in RANGES" :key="r.days" :value="r.days">{{ r.label }}</option>
                </select>
                <button
                  class="rounded-md bg-sovera-600 px-2.5 py-1 font-medium text-white hover:bg-sovera-700 disabled:opacity-40"
                  :disabled="fetchBusy(a)"
                  title="Download every message newer than this from Gmail (runs in the background)"
                  @click="startFetch(a, describeRange(rangeDays), { mode: 'range', since_days: rangeDays })"
                >
                  {{ rangeDays === 0 ? 'Fetch the entire mailbox' : `Fetch everything from ${describeRange(rangeDays)}` }}
                </button>
              </div>
              <div v-if="canFetch(a)" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-neutral-500">Or by count:</span>
                <select v-model.number="backfillLimit" class="rounded-md border border-neutral-200 bg-transparent px-2 py-1 dark:border-neutral-700">
                  <option v-for="n in LIMITS" :key="n" :value="n">{{ n }}</option>
                </select>
                <button
                  class="rounded-md border border-neutral-200 px-2.5 py-1 hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800"
                  :disabled="fetchBusy(a)"
                  @click="startFetch(a, `${backfillLimit} older messages`, { mode: 'backfill', limit: backfillLimit })"
                >
                  Fetch {{ backfillLimit }} older messages
                </button>
                <button
                  class="rounded-md border border-neutral-200 px-2.5 py-1 hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800"
                  :disabled="fetchBusy(a)"
                  title="Re-download the newest messages"
                  @click="startFetch(a, `the newest ${backfillLimit} messages`, { mode: 'full', limit: backfillLimit })"
                >
                  Full resync (newest {{ backfillLimit }})
                </button>
              </div>
            </li>
          </ul>
          <p class="mt-3 text-xs text-neutral-500">
            Sovera Mail never deletes or moves mail in Gmail. Removing an account here only affects the copy on this computer, and you choose whether it stays.
          </p>
        </div>

        <!-- Privacy preferences (this device only) -->
        <div class="mt-8">
          <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-neutral-500">Privacy</h3>
          <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
            <label class="flex items-start gap-3 text-sm">
              <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 accent-sovera-600"
                :checked="prefs.autoLoadImages"
                @change="setPref('autoLoadImages', ($event.target as HTMLInputElement).checked)"
              />
              <span>
                <span class="block">Always load remote images in HTML mail</span>
                <span class="mt-1 block text-xs text-neutral-500">
                  By default remote images stay blocked until you press <em>Load images</em> for a message.
                  Turning this on is convenient but has a cost: many mails contain invisible tracking pixels, so the sender
                  (newsletters, marketing tools, some individuals) learns <strong>when</strong> you opened the mail, how often,
                  and roughly <strong>where</strong> from, via your IP address. Images also load from the sender's servers
                  before you see the mail. Recommended: leave it off and load images per message when you trust the sender.
                </span>
              </span>
            </label>
          </div>
        </div>

        <p class="mt-10 text-center text-[11px] text-neutral-400">
          Sovera Mail {{ config.public.appVersion }} · local API {{ config.public.apiBase }}<span v-if="health"> · v{{ health.version }}</span>
        </p>
      </div>
    </div>
  </div>
</template>
