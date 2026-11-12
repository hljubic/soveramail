<script setup lang="ts">
import { ArrowLeft, CheckCircle2, ChevronRight, Fingerprint, Languages, Loader2, MessageSquareText, RefreshCw, RotateCcw } from 'lucide-vue-next'
import type { LanguageDetection, RagSettings, StyleProfile } from '~/composables/useAi'

const ai = useAi()
const { accounts, activeAccountId, activeAccount } = useAccounts()

const prompt = ref('')
const saving = ref(false)
const saved = ref(false)
const error = ref<string | null>(null)

const PLACEHOLDER = `Example — write your own standing instructions here, e.g.:

- Sign every mail as "Alex" and put "Example Ltd." under the name.
- Keep replies short: greeting, answer, one closing line. Max five sentences.
- Use "Dear Ms/Mr …" with people I have not written to before, first names with colleagues.
- Write in German; English only if the other side writes in English.
- Never promise a date or a price. Say I will check and get back.
- Mention that meetings are best on Tuesday or Thursday mornings.`

// ---- Retrieval (RAG) hyper-parameters ----
const RAG_DEFAULTS: RagSettings = {
  top_k: 5, style_examples: 3, thread_messages: 6, min_score: 0,
  exclude_thread: true, group_by_thread: true, include_replies: true,
  max_original_chars: 6000, max_context_chars: 1200, max_style_chars: 900, max_thread_chars: 600,
  temperature: 0.4,
  use_style_profile: true, max_profile_chars: 6000, context_tokens: 8192,
}
const rag = ref<RagSettings>({ ...RAG_DEFAULTS })

interface NumberField { key: keyof RagSettings; label: string; hint: string; min: number; max: number; step?: number }
const NUMBERS: NumberField[] = [
  { key: 'top_k', label: 'Similar conversations', hint: 'How many earlier conversations (other threads) are looked up by meaning and added to the prompt. 0 turns retrieval off.', min: 0, max: 20 },
  { key: 'style_examples', label: 'Style examples', hint: 'Your own earlier mails to the same person, shown to the model as "this is how I write". Taken from other threads only.', min: 0, max: 10 },
  { key: 'thread_messages', label: 'Thread history', hint: 'Earlier messages of the thread you are answering, given to the model as the conversation so far (oldest first). The mail you answer is always included on top of this.', min: 0, max: 30 },
  { key: 'min_score', label: 'Minimum similarity', hint: 'Cosine score (0–1) a conversation needs to count as similar. 0 keeps the best K whatever their score; 0.3–0.5 drops weak matches.', min: 0, max: 1, step: 0.05 },
  { key: 'temperature', label: 'Temperature', hint: 'Creativity of the drafting model. 0.2 = predictable and literal, 0.4 = default, 0.8+ = looser wording.', min: 0, max: 2, step: 0.1 },
  { key: 'max_original_chars', label: 'Max characters: mail you answer', hint: 'Longer mails are cut here.', min: 500, max: 30000, step: 500 },
  { key: 'max_thread_chars', label: 'Max characters: each thread message', hint: 'Per earlier message in the thread history.', min: 100, max: 10000, step: 100 },
  { key: 'max_context_chars', label: 'Max characters: each similar conversation', hint: 'Per retrieved mail (and per attached reply).', min: 200, max: 10000, step: 100 },
  { key: 'max_style_chars', label: 'Max characters: each style example', hint: 'Per example of your own writing.', min: 200, max: 10000, step: 100 },
  { key: 'max_profile_chars', label: 'Max characters: writing-style profile', hint: 'The learned profile (above) is cut here before it goes into the prompt.', min: 500, max: 20000, step: 500 },
  { key: 'context_tokens', label: 'Model context window (tokens)', hint: 'Passed to Ollama as num_ctx for every chat call. Ollama\'s default of 4096 silently drops the beginning of long prompts (profile + examples + thread). 8192 fits most drafts; raise it if you use a big profile, at the cost of memory.', min: 2048, max: 65536, step: 1024 },
]
interface SwitchField { key: keyof RagSettings; label: string; hint: string }
const SWITCHES: SwitchField[] = [
  { key: 'use_style_profile', label: 'Use my writing-style profile', hint: 'Prepend the profile learned from all your sent mail (see "Your writing style" above) to every draft. Examples to the same person still take precedence where they differ.' },
  { key: 'exclude_thread', label: 'Never use the current thread as an example', hint: 'The thread you are answering is the conversation, not a reference. With this off, your earlier replies in the same thread would also be fed back as style examples and "similar conversations" (circular).' },
  { key: 'group_by_thread', label: 'One result per thread', hint: 'Collapse several matching mails from the same exchange into one, so K different conversations are shown instead of K mails from one thread.' },
  { key: 'include_replies', label: 'Show how you answered', hint: 'For each similar conversation also include your reply from that thread (or, when the match is your own mail, what you were replying to).' },
]

// ---- Writing-style profile (learned from all sent mail of the active account) ----
const profile = ref<StyleProfile | null>(null)
const profileError = ref<string | null>(null)
const profileBusy = ref(false)
const summaryDraft = ref('')
const showStats = ref(false)
const showPreview = ref(false)
const showFeedback = ref(false)
const feedback = computed(() => profile.value?.stats?.feedback ?? null)
let profilePoll: ReturnType<typeof setTimeout> | null = null

async function loadProfile(quiet = false) {
  const id = activeAccountId.value
  if (!id) {
    profile.value = null
    return
  }
  try {
    const p = await ai.styleProfile(id)
    // The user switched account while this was loading: it is not this page's profile.
    if (id !== activeAccountId.value) return
    // keep an unsaved edit; otherwise mirror what the server has (compare against the OLD profile)
    const wasDirty = profile.value !== null && summaryDraft.value !== (profile.value.summary ?? '')
    profile.value = p
    if (!wasDirty) summaryDraft.value = p.summary ?? ''
    profileError.value = null
    // keep polling while a rebuild is queued
    if (profilePoll) clearTimeout(profilePoll)
    if (p.queued) profilePoll = setTimeout(() => loadProfile(true), 4000)
  } catch (e) {
    if (!quiet) profileError.value = (e as Error).message
  }
}

const summaryDirty = computed(() => (profile.value?.summary ?? '') !== summaryDraft.value)

async function rebuildProfile() {
  const id = activeAccountId.value
  if (!id || profileBusy.value) return
  profileBusy.value = true
  try {
    await ai.rebuildStyleProfile(id)
    await loadProfile()
  } catch (e) {
    profileError.value = (e as Error).message
  } finally {
    profileBusy.value = false
  }
}

async function patchProfile(patch: { enabled?: boolean; summary?: string; reset_summary?: boolean }) {
  const id = activeAccountId.value
  if (!id || profileBusy.value) return
  profileBusy.value = true
  try {
    profile.value = await ai.updateStyleProfile(id, patch)
    summaryDraft.value = profile.value.summary ?? ''
    profileError.value = null
    if (patch.reset_summary) await loadProfile()
  } catch (e) {
    profileError.value = (e as Error).message
  } finally {
    profileBusy.value = false
  }
}

const profileStatus = computed(() => {
  const p = profile.value
  if (!p) return ''
  if (p.queued) return 'Learning from your sent mail…'
  if (!p.built) return p.sample_count === 0 && p.built_at ? 'No sent mail to learn from yet.' : 'Not built yet.'
  const when = p.built_at ? new Date(p.built_at).toLocaleString() : ''
  return `Learned from ${p.sample_count} sent e-mails · ${when}` + (p.summary_model && !p.summary_edited ? ` · style guide by ${p.summary_model}` : '')
})

const pct = (v: number | undefined | null) => `${Math.round((v ?? 0) * 100)}%`
const habitsText = computed(() => {
  const p = profile.value?.stats?.punctuation
  if (!p) return ''
  const out: string[] = []
  out.push((p.exclamations_per_mail ?? 0) >= 1.5 ? 'exclamation marks: often' : (p.exclamations_per_mail ?? 0) >= 0.4 ? 'exclamation marks: sometimes' : 'exclamation marks: rarely')
  const emo = Math.max(p.emoji_share ?? 0, p.smiley_share ?? 0)
  out.push(emo >= 0.3 ? 'emoji / smileys: often' : emo >= 0.08 ? 'emoji / smileys: occasionally' : 'emoji / smileys: none')
  if ((p.ellipsis_share ?? 0) >= 0.2) out.push('likes “…”')
  if ((p.list_share ?? 0) >= 0.2) out.push('uses lists')
  if ((p.lowercase_start_share ?? 0) >= 0.3) out.push('often starts lower-case')
  return out.join(' · ')
})

watch(activeAccountId, () => {
  // Forget the previous account's profile too, otherwise its summary makes the
  // empty editor look "dirty" and the new account's guide is never shown (and
  // "Save guide" would store an empty one).
  profile.value = null
  summaryDraft.value = ''
  if (profilePoll) clearTimeout(profilePoll)
  void loadProfile()
})
onBeforeUnmount(() => {
  if (profilePoll) clearTimeout(profilePoll)
})

// ---- Reply language ----
const replyLanguage = ref<string>('')   // '' = same as the incoming mail
const detecting = ref(false)
const detection = ref<LanguageDetection | null>(null)
const languageName = (code: string | null) => ai.settings.value?.languages.find(l => l.code === code)?.name ?? code ?? ''
const languageStatus = computed(() => {
  const s = ai.settings.value
  if (!s) return ''
  if (s.reply_language && s.reply_language_source === 'auto') return `Set automatically: your local model judged from your sent mail that you write ${languageName(s.reply_language)}${s.reply_language_detected_at ? ` (${new Date(s.reply_language_detected_at).toLocaleDateString()})` : ''}. Change it here any time.`
  if (s.reply_language && s.reply_language_source === 'user') return 'Chosen by you. Automatic detection will not touch it.'
  if (s.reply_language_source === 'user') return 'You chose to follow the incoming mail. Automatic detection will not touch it.'
  if (s.reply_language_checked_at) return 'The model was asked but was not sure which language you write; drafts follow the incoming mail. It will try again later, or you can pick a language.'
  return s.ready
    ? 'Not set yet: once about 30 of your sent mails are synced, your local model is asked once which language you write and sets this for you.'
    : 'Not set yet: configure your local AI and it will be detected from your sent mail, or pick one here.'
})

async function detectNow() {
  const id = activeAccountId.value
  if (!id || detecting.value) return
  detecting.value = true
  error.value = null
  try {
    detection.value = await ai.detectLanguage(id)
    replyLanguage.value = ai.settings.value?.reply_language ?? ''
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    detecting.value = false
  }
}

function loadFromSettings() {
  prompt.value = ai.settings.value?.user_prompt ?? ''
  rag.value = { ...RAG_DEFAULTS, ...(ai.settings.value?.rag ?? {}) }
  replyLanguage.value = ai.settings.value?.reply_language ?? ''
}

onMounted(async () => {
  await ai.load()
  loadFromSettings()
  await loadProfile()
})

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    await ai.save({ user_prompt: prompt.value, rag: rag.value, ...(replyLanguage.value !== (ai.settings.value?.reply_language ?? '') ? { reply_language: replyLanguage.value || null } : {}) })
    loadFromSettings()
    saved.value = true
    setTimeout(() => (saved.value = false), 2500)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    saving.value = false
  }
}

function resetRag() {
  rag.value = { ...RAG_DEFAULTS }
}

const dirty = computed(() => {
  const s = ai.settings.value
  if (!s) return false
  if ((s.user_prompt ?? '') !== prompt.value) return true
  if ((s.reply_language ?? '') !== replyLanguage.value) return true
  const saved = { ...RAG_DEFAULTS, ...(s.rag ?? {}) }
  return (Object.keys(RAG_DEFAULTS) as (keyof RagSettings)[]).some(k => saved[k] !== rag.value[k])
})
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-neutral-200 px-5 dark:border-neutral-800">
      <NuxtLink v-if="accounts.length" to="/" class="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
        <ArrowLeft class="h-4 w-4" />
      </NuxtLink>
      <MessageSquareText class="h-4 w-4 text-sovera-500" />
      <h1 class="text-sm font-semibold">AI instructions</h1>
    </header>

    <div class="flex-1 overflow-y-auto">
      <div class="mx-auto flex max-w-2xl flex-col gap-5 px-6 py-8">
        <div class="text-sm text-neutral-500">
          <p>
            These instructions are added to every draft that <strong>AI compose</strong> writes for you.
            Tone, signature, things to always or never say, preferred language.
          </p>
          <p class="mt-2">
            Built in and always on, without you writing anything: the draft imitates how you actually write (your earlier replies to
            the same person are used as examples), answers in the language of the incoming mail, and keeps closely related languages (e.g. Croatian, Serbian and Bosnian)
            strictly apart.
          </p>
        </div>

        <div class="rounded-xl border border-neutral-200 dark:border-neutral-800">
          <textarea
            v-model="prompt"
            :placeholder="PLACEHOLDER"
            rows="14"
            spellcheck="true"
            class="w-full resize-y rounded-xl bg-transparent px-4 py-3 text-sm leading-relaxed outline-none placeholder:text-neutral-400 placeholder:whitespace-pre-line dark:placeholder:text-neutral-600"
            @keydown.meta.enter.prevent="save"
            @keydown.ctrl.enter.prevent="save"
          />
          <div class="flex items-center gap-3 border-t border-neutral-100 px-4 py-2.5 text-xs dark:border-neutral-900">
            <span class="text-neutral-400">{{ prompt.length }} / 4000</span>
          </div>
        </div>

        <!-- Reply language -->
        <section class="rounded-xl border border-neutral-200 dark:border-neutral-800">
          <div class="flex items-start gap-4 px-4 py-3">
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-2 text-sm font-semibold"><Languages class="h-4 w-4 text-sovera-500" /> Reply language</span>
              <span class="mt-1 block text-xs text-neutral-500">
                The language AI compose writes in, always in its <strong>standard literary form</strong> (no dialect, no slang). If the mail you answer is clearly in another language and you have written to
                that person in it before, the draft follows that language instead.
              </span>
              <span class="mt-1.5 block text-xs" :class="ai.settings.value?.reply_language_source === 'auto' ? 'text-emerald-700 dark:text-emerald-400' : 'text-neutral-500'">{{ languageStatus }}</span>
              <span v-if="detection && !detection.code" class="mt-1 block text-xs text-amber-600 dark:text-amber-400">
                {{ detection.ready ? `The model could not decide (confidence ${Math.round(detection.confidence * 100)}%${detection.reason ? `: ${detection.reason}` : ''}). Nothing was changed.` : `Not enough sent mail yet (${detection.samples} of 30).` }}
              </span>
              <span v-else-if="detection?.code" class="mt-1 block text-xs text-emerald-700 dark:text-emerald-400">
                Detected {{ detection.name }} ({{ Math.round(detection.confidence * 100) }}% sure{{ detection.reason ? `: ${detection.reason}` : '' }}).
              </span>
            </span>
            <span class="flex shrink-0 flex-col items-end gap-2">
              <select
                v-model="replyLanguage"
                class="w-56 rounded-md border border-neutral-200 bg-transparent px-2 py-1 text-sm dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option value="">Same as the incoming mail (auto)</option>
                <option v-for="l in ai.settings.value?.languages ?? []" :key="l.code" :value="l.code">{{ l.name }}{{ l.native !== l.name ? ` · ${l.native}` : '' }}</option>
              </select>
              <button
                class="flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                :disabled="detecting || !activeAccountId || !ai.ready.value"
                :title="ai.ready.value ? 'Ask your local model now (needs about 30 sent mails)' : 'Configure your local AI first'"
                @click="detectNow"
              >
                <Loader2 v-if="detecting" class="h-3.5 w-3.5 animate-spin" />
                <RefreshCw v-else class="h-3.5 w-3.5" />
                Detect with the model
              </button>
            </span>
          </div>
        </section>

        <!-- Writing-style profile -->
        <section class="rounded-xl border border-neutral-200 dark:border-neutral-800">
          <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-900">
            <div class="flex items-center gap-2">
              <Fingerprint class="h-4 w-4 text-sovera-500" />
              <h2 class="text-sm font-semibold">Your writing style</h2>
              <span v-if="activeAccount" class="truncate text-xs text-neutral-500">{{ activeAccount.email }}</span>
              <span class="flex-1" />
              <label v-if="profile?.exists || profile?.built" class="flex items-center gap-1.5 text-xs text-neutral-600 dark:text-neutral-300" title="Off = drafts ignore this profile for this mailbox">
                <input type="checkbox" :checked="profile?.enabled" :disabled="profileBusy" class="h-3.5 w-3.5 accent-sovera-600" @change="patchProfile({ enabled: ($event.target as HTMLInputElement).checked })" />
                Use in drafts
              </label>
              <button
                class="flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                :disabled="profileBusy || !activeAccountId || profile?.queued"
                title="Re-read all your sent mail now (statistics in seconds; the style guide takes a minute with the local model)"
                @click="rebuildProfile"
              >
                <RefreshCw class="h-3.5 w-3.5" :class="profile?.queued ? 'animate-spin' : ''" />
                {{ profile?.built ? 'Rebuild' : 'Build now' }}
              </button>
            </div>
            <p class="mt-1 text-xs text-neutral-500">
              Learned automatically from <strong>every e-mail you sent</strong> (quoted replies removed): greetings, sign-offs, signature,
              typical phrases, length, formality, language and habits. Your local model turns that into a short style guide. The profile is
              refreshed by “Process new” as you keep sending mail, and the whole block is given to <strong>AI compose</strong> before the examples.
            </p>
            <p class="mt-1.5 flex items-center gap-2 text-xs" :class="profile?.last_error ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-500'">
              <Loader2 v-if="profile?.queued" class="h-3.5 w-3.5 animate-spin" />
              <span>{{ profileStatus }}</span>
              <span v-if="profile?.stale && !profile?.queued" class="rounded-full bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300" title="New sent mail since the last build; it will be refreshed automatically">new mail since</span>
            </p>
            <p v-if="profile?.last_error" class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ profile.last_error }}</p>
            <p v-if="profileError" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ profileError }}</p>
          </div>

          <template v-if="profile?.built && profile.stats">
            <!-- Style guide (model-written, editable) -->
            <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-900">
              <div class="mb-1 flex items-center gap-2 text-xs text-neutral-500">
                <span class="font-medium text-neutral-700 dark:text-neutral-200">Style guide</span>
                <span v-if="profile.summary_edited">edited by you · kept across rebuilds</span>
                <span v-else-if="profile.summary_model">written by {{ profile.summary_model }}</span>
                <span v-else>not written yet (needs a configured local model)</span>
              </div>
              <textarea
                v-model="summaryDraft"
                rows="8"
                spellcheck="true"
                placeholder="The local model writes this from your sent mail. You can also type or correct it yourself."
                class="w-full resize-y rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm leading-relaxed outline-none placeholder:text-neutral-400 focus:border-sovera-500 dark:border-neutral-700"
              />
              <div class="mt-1.5 flex items-center gap-3 text-xs">
                <button class="rounded-md bg-sovera-600 px-2.5 py-1 font-medium text-white hover:bg-sovera-700 disabled:opacity-50" :disabled="!summaryDirty || profileBusy" @click="patchProfile({ summary: summaryDraft })">Save guide</button>
                <button v-if="profile.summary_edited" class="text-neutral-500 underline hover:text-neutral-800 dark:hover:text-neutral-200" :disabled="profileBusy" @click="patchProfile({ reset_summary: true })">Let the model write it again</button>
                <span class="text-neutral-400">{{ summaryDraft.length }} / 7000</span>
              </div>
            </div>

            <!-- Measured habits -->
            <div class="border-b border-neutral-100 px-4 py-3 text-xs dark:border-neutral-900">
              <button class="flex w-full items-center gap-1 text-left font-medium text-neutral-700 hover:underline dark:text-neutral-200" @click="showStats = !showStats">
                <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="showStats ? 'rotate-90' : ''" />
                What was measured
                <span class="font-normal text-neutral-500">· greetings, sign-offs, signature, phrases, length, language</span>
              </button>
              <div v-if="showStats" class="mt-2 space-y-2 text-neutral-600 dark:text-neutral-300">
                <div v-if="profile.stats.greetings?.length">
                  <span class="text-neutral-500">Greetings:</span>
                  <span v-for="g in profile.stats.greetings.slice(0, 5)" :key="g.text" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">{{ g.text }} <span class="opacity-60">{{ pct(g.share) }}</span></span>
                  <span v-if="(profile.stats.no_greeting_share ?? 0) >= 0.2" class="ml-1 opacity-60">none {{ pct(profile.stats.no_greeting_share) }}</span>
                </div>
                <div v-if="profile.stats.closings?.length">
                  <span class="text-neutral-500">Sign-offs:</span>
                  <span v-for="c in profile.stats.closings.slice(0, 5)" :key="c.text" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">{{ c.text }} <span class="opacity-60">{{ pct(c.share) }}</span></span>
                  <span v-if="profile.stats.sign_name" class="ml-1 opacity-60">signs as “{{ profile.stats.sign_name.text }}” {{ pct(profile.stats.sign_name.share) }}</span>
                </div>
                <div v-if="profile.stats.signature?.lines?.length">
                  <span class="text-neutral-500">Signature ({{ pct(profile.stats.signature.share) }} of mails):</span>
                  <pre class="mt-1 whitespace-pre-wrap rounded-md bg-neutral-50 px-2.5 py-1.5 font-sans dark:bg-neutral-900">{{ profile.stats.signature.lines.join('\n') }}</pre>
                </div>
                <div v-if="profile.stats.language && profile.stats.formality && profile.stats.length">
                  <span class="text-neutral-500">Language:</span>
                  <span v-for="(v, k) in profile.stats.language" :key="k" class="ml-1" :class="v < 0.05 ? 'hidden' : ''">{{ k }} {{ pct(v) }}</span>
                  <span class="mx-1 opacity-40">·</span>
                  <span class="text-neutral-500">Register:</span> formal {{ pct(profile.stats.formality.formal) }}, informal {{ pct(profile.stats.formality.informal) }}
                  <span class="mx-1 opacity-40">·</span>
                  <span class="text-neutral-500">Length:</span> ~{{ profile.stats.length.median_words }} words, {{ profile.stats.length.avg_sentences }} sentences, {{ profile.stats.length.avg_words_per_sentence }} words/sentence
                </div>
                <div v-if="profile.stats.phrases?.length">
                  <span class="text-neutral-500">Recurring phrases:</span>
                  <span v-for="ph in profile.stats.phrases.slice(0, 15)" :key="ph.text" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">{{ ph.text }} <span class="opacity-60">{{ ph.mails }}</span></span>
                </div>
                <div v-if="profile.stats.distinctive_words?.length">
                  <span class="text-neutral-500">Words you use more than others do:</span>
                  <span class="ml-1">{{ profile.stats.distinctive_words.slice(0, 20).map(w => w.word).join(', ') }}</span>
                </div>
                <div v-if="habitsText"><span class="text-neutral-500">Habits:</span> {{ habitsText }}</div>
                <div v-if="profile.stats.by_register && profile.stats.by_register.formal.mails >= 3 && profile.stats.by_register.informal.mails >= 3">
                  <span class="text-neutral-500">Formal contacts:</span>
                  {{ [...profile.stats.by_register.formal.greetings.slice(0, 2), ...profile.stats.by_register.formal.closings.slice(0, 2)].map(x => `“${x.text}”`).join(', ') || 'no greeting' }}
                  <span class="mx-1 opacity-40">·</span>
                  <span class="text-neutral-500">Colleagues / friends:</span>
                  {{ [...profile.stats.by_register.informal.greetings.slice(0, 2), ...profile.stats.by_register.informal.closings.slice(0, 2)].map(x => `“${x.text}”`).join(', ') || 'no greeting' }}
                </div>
                <div v-if="profile.stats.openers?.length">
                  <span class="text-neutral-500">First sentence starts with:</span>
                  <span v-for="o in profile.stats.openers.slice(0, 8)" :key="o.text" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">{{ o.text }} <span class="opacity-60">{{ o.count }}</span></span>
                </div>
                <div v-if="profile.stats.short_replies?.length">
                  <span class="text-neutral-500">Short replies you send:</span>
                  <span v-for="r in profile.stats.short_replies.slice(0, 8)" :key="r.text" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">“{{ r.text }}” <span class="opacity-60">{{ r.count }}</span></span>
                </div>
                <div v-if="profile.stats.requests?.length">
                  <span class="text-neutral-500">How you ask:</span>
                  <ul class="mt-0.5 list-disc pl-5"><li v-for="r in profile.stats.requests.slice(0, 4)" :key="r">“{{ r }}”</li></ul>
                </div>
                <div v-if="profile.stats.example_sentences?.length">
                  <span class="text-neutral-500">Characteristic sentences:</span>
                  <ul class="mt-0.5 list-disc pl-5"><li v-for="r in profile.stats.example_sentences.slice(0, 6)" :key="r">“{{ r }}”</li></ul>
                </div>
                <div v-if="profile.stats.orthography || profile.stats.time_formats">
                  <span class="text-neutral-500">Orthography:</span>
                  <span v-if="profile.stats.orthography">diacritics in {{ pct(1 - (profile.stats.orthography.ascii_croatian_share ?? 0)) }} of Croatian mails · lower case after greeting {{ pct(profile.stats.orthography.lowercase_after_greeting_share) }} · greeting + first sentence on one line {{ pct(profile.stats.inline_greeting_share) }}</span>
                  <span v-if="profile.stats.time_formats && Object.keys(profile.stats.time_formats).length" class="ml-1">· times like {{ Object.entries(profile.stats.time_formats).sort((a, b) => b[1] - a[1]).map(([k, c]) => `“${k}” ×${c}`).join(', ') }}</span>
                </div>
                <div v-if="profile.stats.language_by_domain?.length">
                  <span class="text-neutral-500">Language by recipient domain:</span>
                  {{ profile.stats.language_by_domain.slice(0, 8).map(d => `${d.domain}: ${d.language}`).join(', ') }}
                </div>
              </div>
            </div>

            <!-- What the user changed in AI drafts -->
            <div class="border-b border-neutral-100 px-4 py-3 text-xs dark:border-neutral-900">
              <button class="flex w-full items-center gap-1 text-left font-medium text-neutral-700 hover:underline dark:text-neutral-200" @click="showFeedback = !showFeedback">
                <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="showFeedback ? 'rotate-90' : ''" />
                What you changed in AI drafts
                <span class="font-normal text-neutral-500">
                  · {{ feedback ? `${feedback.drafts} sent from an AI draft, kept ${pct(feedback.avg_similarity)} of the wording on average` : 'nothing sent from an AI draft yet' }}
                </span>
              </button>
              <div v-if="showFeedback" class="mt-2 space-y-2 text-neutral-600 dark:text-neutral-300">
                <p v-if="!feedback" class="text-neutral-500">
                  Every time you send a mail that started as an AI draft, the sent text is compared with the draft word by word.
                  Words you swap, delete or add, and greeting or sign-off changes, are collected here and handed to the model as the strongest hint.
                </p>
                <template v-else>
                  <div>
                    <span class="text-neutral-500">Sent unchanged:</span> {{ pct(feedback.unchanged_share) }}
                    <span class="mx-1 opacity-40">·</span>
                    <span class="text-neutral-500">Shortened:</span> {{ pct(feedback.shortened_share) }}
                    <span class="mx-1 opacity-40">·</span>
                    <span class="text-neutral-500">Lengthened:</span> {{ pct(feedback.lengthened_share) }}
                  </div>
                  <div v-if="feedback.greetings.length || feedback.closings.length">
                    <span class="text-neutral-500">Greeting / sign-off:</span>
                    <span v-for="g in [...feedback.greetings, ...feedback.closings].slice(0, 6)" :key="g.kind + g.from + g.to" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700">“{{ g.from }}” → “{{ g.to }}” <span class="opacity-60">×{{ g.count }}</span></span>
                  </div>
                  <div v-if="feedback.replacements.length">
                    <span class="text-neutral-500">Replaced:</span>
                    <span v-for="r in feedback.replacements.slice(0, 15)" :key="r.from + r.to" class="ml-1 inline-block rounded-full border border-neutral-200 px-2 py-0.5 dark:border-neutral-700" :title="r.kind">“{{ r.from }}” → “{{ r.to }}” <span class="opacity-60">×{{ r.count }}</span></span>
                  </div>
                  <div v-if="feedback.removed.length">
                    <span class="text-neutral-500">Removed:</span>
                    <span v-for="r in feedback.removed.slice(0, 10)" :key="r.from" class="ml-1 inline-block rounded-full border border-red-200 px-2 py-0.5 text-red-800 dark:border-red-900 dark:text-red-300">“{{ r.from }}” <span class="opacity-60">×{{ r.count }}</span></span>
                  </div>
                  <div v-if="feedback.added.length">
                    <span class="text-neutral-500">Added:</span>
                    <span v-for="r in feedback.added.slice(0, 10)" :key="r.to" class="ml-1 inline-block rounded-full border border-emerald-200 px-2 py-0.5 text-emerald-800 dark:border-emerald-900 dark:text-emerald-300">“{{ r.to }}” <span class="opacity-60">×{{ r.count }}</span></span>
                  </div>
                </template>
              </div>
            </div>

            <!-- Exact prompt block -->
            <div class="px-4 py-3 text-xs">
              <button class="flex w-full items-center gap-1 text-left font-medium text-neutral-700 hover:underline dark:text-neutral-200" @click="showPreview = !showPreview">
                <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="showPreview ? 'rotate-90' : ''" />
                What the model receives
                <span class="font-normal text-neutral-500">· {{ profile.prompt_preview?.length ?? 0 }} characters</span>
              </button>
              <pre v-if="showPreview" class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-md bg-neutral-50 px-3 py-2 font-sans text-[12px] leading-relaxed text-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">{{ profile.prompt_preview || '(profile switched off or empty)' }}</pre>
            </div>
          </template>
        </section>

        <!-- Retrieval (RAG) settings -->
        <section class="rounded-xl border border-neutral-200 dark:border-neutral-800">
          <div class="border-b border-neutral-100 px-4 py-3 dark:border-neutral-900">
            <h2 class="text-sm font-semibold">Retrieval settings (RAG)</h2>
            <p class="mt-1 text-xs text-neutral-500">
              What the model gets to see besides the mail you are answering. In order: your style examples, similar earlier
              conversations from this mailbox (found by meaning, other threads only), the thread so far, then the mail itself.
              Everything is looked up locally; nothing leaves your computer except the prompt to your local model.
            </p>
          </div>

          <div class="divide-y divide-neutral-100 dark:divide-neutral-900">
            <label v-for="f in NUMBERS" :key="f.key" class="flex items-start gap-4 px-4 py-3">
              <span class="min-w-0 flex-1">
                <span class="block text-sm">{{ f.label }}</span>
                <span class="mt-0.5 block text-xs text-neutral-500">{{ f.hint }}</span>
              </span>
              <input
                v-model.number="rag[f.key]"
                type="number"
                :min="f.min"
                :max="f.max"
                :step="f.step ?? 1"
                class="w-24 shrink-0 rounded-md border border-neutral-200 bg-transparent px-2 py-1 text-right text-sm dark:border-neutral-700"
              />
            </label>
            <label v-for="f in SWITCHES" :key="f.key" class="flex items-start gap-4 px-4 py-3">
              <span class="min-w-0 flex-1">
                <span class="block text-sm">{{ f.label }}</span>
                <span class="mt-0.5 block text-xs text-neutral-500">{{ f.hint }}</span>
              </span>
              <input v-model="rag[f.key]" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-sovera-600" />
            </label>
          </div>

          <div class="flex items-center gap-3 border-t border-neutral-100 px-4 py-2.5 text-xs dark:border-neutral-900">
            <button class="flex items-center gap-1.5 text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200" @click="resetRag">
              <RotateCcw class="h-3.5 w-3.5" /> Reset to defaults
            </button>
            <span class="text-neutral-400">The embedding text per mail (3000 characters) is fixed: changing it would need a full re-index.</span>
          </div>
        </section>

        <div class="flex items-center gap-3 text-xs">
          <button
            class="flex items-center gap-2 rounded-lg bg-sovera-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-sovera-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="saving || !dirty"
            @click="save"
          >
            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
            Save
          </button>
          <span v-if="saved" class="flex items-center gap-1 text-emerald-600"><CheckCircle2 class="h-3.5 w-3.5" /> Saved</span>
          <span v-if="error" class="text-red-600">{{ error }}</span>
          <span class="flex-1" />
          <span class="text-neutral-400">⌘/Ctrl + Enter in the text box also saves</span>
        </div>

        <KnowledgePanel :account-id="activeAccountId" />

        <p class="text-xs text-neutral-400">
          Stored locally in your Sovera Mail database and sent only to your local model together with the e-mail you are answering.
          Empty instructions mean "use the built-in behaviour only".
        </p>
      </div>
    </div>
  </div>
</template>
