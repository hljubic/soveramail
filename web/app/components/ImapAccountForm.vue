<script setup lang="ts">
/**
 * Connect a mailbox over IMAP/SMTP. Presets fill in the servers of the common
 * providers; the password is an app password wherever the provider requires one.
 * Nothing is stored until the API has logged in to both servers.
 */
import { Loader2, Server } from 'lucide-vue-next'
import type { Account, Encryption, ImapConnectPayload } from '~/types/api'

const props = defineProps<{ account?: Account | null }>()
const emit = defineEmits<{ done: [account: Account]; cancel: [] }>()

const { addImapAccount, updateImapAccount } = useAccounts()

interface Preset { key: string; label: string; imap: { host: string; port: number; encryption: Encryption }; smtp: { host: string; port: number; encryption: Encryption }; note: string }
const PRESETS: Preset[] = [
  { key: 'icloud', label: 'iCloud Mail', imap: { host: 'imap.mail.me.com', port: 993, encryption: 'ssl' }, smtp: { host: 'smtp.mail.me.com', port: 587, encryption: 'starttls' }, note: 'Needs an app-specific password: appleid.apple.com → Sign-In and Security → App-Specific Passwords. Two-factor authentication must be on.' },
  { key: 'yahoo', label: 'Yahoo Mail', imap: { host: 'imap.mail.yahoo.com', port: 993, encryption: 'ssl' }, smtp: { host: 'smtp.mail.yahoo.com', port: 465, encryption: 'ssl' }, note: 'Needs an app password: Yahoo account security → Generate app password.' },
  { key: 'fastmail', label: 'Fastmail', imap: { host: 'imap.fastmail.com', port: 993, encryption: 'ssl' }, smtp: { host: 'smtp.fastmail.com', port: 465, encryption: 'ssl' }, note: 'Needs an app password with "Mail (IMAP/POP/SMTP)" access: Settings → Privacy & Security → Integrations.' },
  { key: 'zoho', label: 'Zoho Mail (EU)', imap: { host: 'imap.zoho.eu', port: 993, encryption: 'ssl' }, smtp: { host: 'smtp.zoho.eu', port: 465, encryption: 'ssl' }, note: 'Enable IMAP access in Zoho Mail settings; with two-factor on, use an application-specific password. US accounts use imap.zoho.com / smtp.zoho.com.' },
  { key: 'gmx', label: 'GMX', imap: { host: 'imap.gmx.net', port: 993, encryption: 'ssl' }, smtp: { host: 'mail.gmx.net', port: 465, encryption: 'ssl' }, note: 'Enable "POP3/IMAP access" in GMX settings first.' },
  { key: 'gmail-app', label: 'Gmail via app password', imap: { host: 'imap.gmail.com', port: 993, encryption: 'ssl' }, smtp: { host: 'smtp.gmail.com', port: 465, encryption: 'ssl' }, note: 'Prefer "Connect Gmail" above (OAuth). This works with a Google app password (2-step verification required) when OAuth is not an option.' },
  { key: 'proton', label: 'Proton Mail (Bridge)', imap: { host: '127.0.0.1', port: 1143, encryption: 'starttls' }, smtp: { host: '127.0.0.1', port: 1025, encryption: 'starttls' }, note: 'Requires the Proton Mail Bridge app running on this computer; use the username and password Bridge shows.' },
  { key: 'custom', label: 'Other (hosting, university, ISP)', imap: { host: '', port: 993, encryption: 'ssl' }, smtp: { host: '', port: 587, encryption: 'starttls' }, note: 'Ask your provider for the IMAP and SMTP server names. Typical: imap.yourdomain.com 993 (SSL) and smtp.yourdomain.com 587 (STARTTLS) or 465 (SSL).' },
]

const preset = ref<string>(props.account?.imap ? 'custom' : 'icloud')
const email = ref(props.account?.email ?? '')
const displayName = ref(props.account?.display_name ?? '')
const password = ref('')
const username = ref(props.account?.imap?.username ?? '')
const imapHost = ref(props.account?.imap?.host ?? PRESETS[0]!.imap.host)
const imapPort = ref(props.account?.imap?.port ?? PRESETS[0]!.imap.port)
const imapEnc = ref<Encryption>(props.account?.imap?.encryption ?? PRESETS[0]!.imap.encryption)
const smtpHost = ref(props.account?.imap?.smtp_host ?? PRESETS[0]!.smtp.host)
const smtpPort = ref(props.account?.imap?.smtp_port ?? PRESETS[0]!.smtp.port)
const smtpEnc = ref<Encryption>(props.account?.imap?.smtp_encryption ?? PRESETS[0]!.smtp.encryption)
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const current = computed(() => PRESETS.find(p => p.key === preset.value) ?? PRESETS[PRESETS.length - 1]!)

function applyPreset(key: string) {
  preset.value = key
  const p = PRESETS.find(x => x.key === key)
  if (!p || key === 'custom') return
  imapHost.value = p.imap.host
  imapPort.value = p.imap.port
  imapEnc.value = p.imap.encryption
  smtpHost.value = p.smtp.host
  smtpPort.value = p.smtp.port
  smtpEnc.value = p.smtp.encryption
}

const canSubmit = computed(() => !!email.value.trim() && !!password.value && !!imapHost.value.trim() && !!smtpHost.value.trim() && !submitting.value)

async function submit() {
  if (!canSubmit.value) return
  submitting.value = true
  error.value = null
  fieldErrors.value = {}
  const payload: ImapConnectPayload = {
    email: email.value.trim(),
    display_name: displayName.value.trim() || undefined,
    password: password.value,
    imap: { host: imapHost.value.trim(), port: Number(imapPort.value), encryption: imapEnc.value, username: username.value.trim() || undefined },
    smtp: { host: smtpHost.value.trim(), port: Number(smtpPort.value), encryption: smtpEnc.value, username: username.value.trim() || undefined },
  }
  try {
    const account = props.account ? await updateImapAccount(props.account.id, payload) : await addImapAccount(payload)
    password.value = ''
    emit('done', account)
  } catch (e) {
    const err = e as { message: string; data?: { errors?: Record<string, string[]> } }
    error.value = err.message
    fieldErrors.value = err.data?.errors ?? {}
  } finally {
    submitting.value = false
  }
}

const fieldClass = 'w-full rounded-md border border-neutral-200 bg-transparent px-2.5 py-1.5 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700'
</script>

<template>
  <form class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-800" @submit.prevent="submit">
    <div class="mb-4 flex items-center gap-3">
      <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white shadow ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-700">
        <Server class="h-5 w-5 text-neutral-600 dark:text-neutral-300" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-medium">{{ account ? `Reconnect ${account.email}` : 'Add an IMAP/SMTP mailbox' }}</p>
        <p class="text-xs text-neutral-500">iCloud, Yahoo, Fastmail, Zoho, GMX, your hosting or university mail. Credentials are checked against both servers, then stored encrypted on this computer.</p>
      </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <label v-if="!account" class="text-xs text-neutral-500 sm:col-span-2">
        Provider
        <select :value="preset" class="mt-1 w-full rounded-md border border-neutral-200 bg-transparent px-2.5 py-1.5 text-sm dark:border-neutral-700" @change="applyPreset(($event.target as HTMLSelectElement).value)">
          <option v-for="p in PRESETS" :key="p.key" :value="p.key">{{ p.label }}</option>
        </select>
      </label>
      <p v-if="!account" class="text-xs text-neutral-500 sm:col-span-2">{{ current.note }}</p>

      <label class="text-xs text-neutral-500">
        E-mail address
        <input v-model="email" type="email" required :readonly="!!account" :class="fieldClass" class="mt-1" placeholder="you@example.com" />
      </label>
      <label class="text-xs text-neutral-500">
        Your name (shown to recipients)
        <input v-model="displayName" type="text" :class="fieldClass" class="mt-1" placeholder="Jane Doe" />
      </label>
      <label class="text-xs text-neutral-500">
        Password / app password
        <input v-model="password" type="password" required autocomplete="new-password" :class="fieldClass" class="mt-1" />
      </label>
      <label class="text-xs text-neutral-500">
        Username (only if different from the address)
        <input v-model="username" type="text" :class="fieldClass" class="mt-1" :placeholder="email || 'you@example.com'" />
      </label>

      <fieldset class="rounded-lg border border-neutral-100 p-3 dark:border-neutral-800 sm:col-span-2">
        <legend class="px-1 text-xs font-medium">Incoming (IMAP)</legend>
        <div class="grid grid-cols-[1fr_6rem_8rem] gap-2">
          <input v-model="imapHost" type="text" required :class="fieldClass" placeholder="imap.example.com" />
          <input v-model.number="imapPort" type="number" min="1" max="65535" required :class="fieldClass" />
          <select v-model="imapEnc" :class="fieldClass">
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
            <option value="none">None</option>
          </select>
        </div>
        <p v-if="fieldErrors['imap.host']" class="mt-1 text-xs text-red-600">{{ fieldErrors['imap.host'][0] }}</p>
      </fieldset>
      <fieldset class="rounded-lg border border-neutral-100 p-3 dark:border-neutral-800 sm:col-span-2">
        <legend class="px-1 text-xs font-medium">Outgoing (SMTP)</legend>
        <div class="grid grid-cols-[1fr_6rem_8rem] gap-2">
          <input v-model="smtpHost" type="text" required :class="fieldClass" placeholder="smtp.example.com" />
          <input v-model.number="smtpPort" type="number" min="1" max="65535" required :class="fieldClass" />
          <select v-model="smtpEnc" :class="fieldClass">
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
            <option value="none">None</option>
          </select>
        </div>
        <p v-if="fieldErrors['smtp.host']" class="mt-1 text-xs text-red-600">{{ fieldErrors['smtp.host'][0] }}</p>
      </fieldset>
    </div>

    <p v-if="error && !fieldErrors['imap.host'] && !fieldErrors['smtp.host']" class="mt-3 text-xs text-red-600">{{ error }}</p>

    <div class="mt-4 flex items-center gap-2">
      <button type="submit" class="flex items-center gap-2 rounded-lg bg-sovera-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-sovera-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canSubmit">
        <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
        {{ submitting ? 'Checking servers…' : account ? 'Save and reconnect' : 'Connect' }}
      </button>
      <button type="button" class="px-3 py-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="emit('cancel')">Cancel</button>
      <span class="ml-auto text-[11px] text-neutral-400">The password is sent only to your local API and stored encrypted.</span>
    </div>
  </form>
</template>
