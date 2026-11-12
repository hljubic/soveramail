/**
 * Per-device UI preferences (kept in localStorage, never sent anywhere).
 */
export interface Preferences {
  /** Load remote images in HTML mail without asking. Off by default: tracking pixels reveal when/where you read. */
  autoLoadImages: boolean
  /** Colour theme: light (default, like the classic mail look), dark, or follow the system. */
  theme: 'light' | 'dark' | 'system'
}

const KEY = 'sovera:prefs'
const DEFAULTS: Preferences = { autoLoadImages: false, theme: 'light' }

function read(): Preferences {
  try {
    return { ...DEFAULTS, ...(JSON.parse(localStorage.getItem(KEY) || '{}') as Partial<Preferences>) }
  } catch {
    return { ...DEFAULTS }
  }
}

export function usePreferences() {
  const prefs = useState<Preferences>('preferences', () => (import.meta.client ? read() : { ...DEFAULTS }))

  function set<K extends keyof Preferences>(key: K, value: Preferences[K]) {
    prefs.value = { ...prefs.value, [key]: value }
    try {
      localStorage.setItem(KEY, JSON.stringify(prefs.value))
    } catch {
      /* storage unavailable */
    }
  }

  return { prefs, set }
}
