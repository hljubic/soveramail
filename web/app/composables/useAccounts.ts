import { isSyncBusy, type Account, type Health, type ImapConnectPayload } from '~/types/api'

const ACTIVE_KEY = 'sovera:active-account'

/**
 * Global account state (Nuxt `useState` = shared across components).
 */
export function useAccounts() {
  const api = useApi()

  const accounts = useState<Account[]>('accounts', () => [])
  const activeAccountId = useState<number | null>('active-account-id', () => null)
  const loaded = useState<boolean>('accounts-loaded', () => false)
  const health = useState<Health | null>('health', () => null)

  const activeAccount = computed(() => accounts.value.find(a => a.id === activeAccountId.value) ?? null)
  const anySyncing = computed(() => accounts.value.some(a => isSyncBusy(a.sync_status)))

  function pickActive() {
    const stored = import.meta.client ? Number(localStorage.getItem(ACTIVE_KEY)) : 0
    const candidate = accounts.value.find(a => a.id === stored) ?? accounts.value[0] ?? null
    if (candidate && candidate.id !== activeAccountId.value) activeAccountId.value = candidate.id
    if (!candidate) activeAccountId.value = null
  }

  function setActive(id: number) {
    activeAccountId.value = id
    if (import.meta.client) localStorage.setItem(ACTIVE_KEY, String(id))
  }

  async function fetchHealth() {
    try {
      health.value = await api.get<Health>('/health')
    } catch {
      health.value = null
    }
  }

  async function fetchAccounts() {
    try {
      const res = await api.get<{ data: Account[] }>('/accounts')
      accounts.value = res.data
      loaded.value = true
      pickActive()
    } catch {
      /* the accounts page shows the health banner when the API is unreachable */
    }
  }

  /** The Google consent URL; the accounts page opens it in a new tab. */
  async function startOauthConnect(provider: 'google'): Promise<string> {
    const res = await api.get<{ url: string }>(`/auth/${provider}/url`)
    return res.url
  }

  async function syncAccount(id: number, opts: { mode?: 'incremental' | 'full' | 'backfill' | 'range'; limit?: number; since_days?: number } = {}) {
    const res = await api.post<{ queued: boolean; account: Account }>(`/accounts/${id}/sync`, opts)
    accounts.value = accounts.value.map(a => (a.id === id ? { ...a, ...res.account } : a))
    return res
  }

  /**
   * Remove a mailbox. `keepMail: true` only forgets the Google grant (the local
   * copy stays and a later reconnect continues from it); `false` deletes the
   * local copy. Gmail itself is never touched either way.
   */
  /** Connect (or reconnect the same address) over IMAP/SMTP; the API verifies both servers first. */
  async function addImapAccount(payload: ImapConnectPayload): Promise<Account> {
    const res = await api.post<{ data: Account }>('/accounts/imap', payload as unknown as Record<string, unknown>)
    accounts.value = [...accounts.value.filter(a => a.id !== res.data.id), res.data]
    setActive(res.data.id)
    return res.data
  }

  async function updateImapAccount(id: number, payload: ImapConnectPayload): Promise<Account> {
    const res = await api.put<{ data: Account }>(`/accounts/${id}/imap`, payload as unknown as Record<string, unknown>)
    accounts.value = accounts.value.map(a => (a.id === id ? { ...a, ...res.data } : a))
    return res.data
  }

  async function removeAccount(id: number, keepMail: boolean) {
    const res = await api.del<{ deleted?: boolean; disconnected?: boolean; account?: Account }>(`/accounts/${id}`, { keep_mail: keepMail })
    if (res.disconnected && res.account) {
      accounts.value = accounts.value.map(a => (a.id === id ? { ...a, ...res.account } : a))
    } else {
      accounts.value = accounts.value.filter(a => a.id !== id)
      pickActive()
    }
  }

  return {
    accounts,
    activeAccount,
    activeAccountId,
    anySyncing,
    loaded,
    health,
    fetchHealth,
    fetchAccounts,
    setActive,
    startOauthConnect,
    syncAccount,
    addImapAccount,
    updateImapAccount,
    removeAccount,
  }
}
