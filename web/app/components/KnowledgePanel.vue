<script setup lang="ts">
import { BookUser, ChevronRight, Loader2, RefreshCw, RotateCcw } from 'lucide-vue-next'

/**
 * What the local AI knows about the user beyond the writing style: about you,
 * how you write in each category, recurring questions and your usual answers,
 * and a card per frequent correspondent. Built locally from the mailbox; every
 * item can be corrected by hand (then it is kept across rebuilds).
 */
interface KnowledgeItem {
  id: number
  kind: 'about' | 'audience' | 'faq' | 'contact'
  subject: string
  title: string | null
  content: string
  source: 'ai' | 'user'
  model: string | null
  evidence: number
  built_at: string | null
}

const props = defineProps<{ accountId: number | null }>()
const api = useApi()

const items = ref<KnowledgeItem[]>([])
const progress = ref<{ total: number; done: number } | null>(null)
const ready = ref(false)
const drafts = ref<Record<number, string>>({})
const open = ref<Record<string, boolean>>({ about: true })
const savingId = ref<number | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const contactFilter = ref('')

async function load() {
  if (!props.accountId) return
  try {
    const res = await api.get<{ data: KnowledgeItem[]; meta: { progress: { total: number; done: number }; ready: boolean } }>(`/accounts/${props.accountId}/ai/knowledge`)
    items.value = res.data
    progress.value = res.meta.progress
    ready.value = res.meta.ready
    for (const i of res.data) {
      // Keep what the user is typing; refresh everything else.
      if (drafts.value[i.id] === undefined || drafts.value[i.id] === (prevContent[i.id] ?? '')) drafts.value[i.id] = i.content
      prevContent[i.id] = i.content
    }
  } catch (e) {
    error.value = (e as Error).message
  }
}
const prevContent: Record<number, string> = {}

const byKind = (k: KnowledgeItem['kind']) => items.value.filter(i => i.kind === k)
const about = computed(() => byKind('about')[0] ?? null)
const contacts = computed(() => {
  const f = contactFilter.value.trim().toLowerCase()
  return byKind('contact').filter(i => !f || `${i.title} ${i.subject}`.toLowerCase().includes(f))
})
const building = computed(() => !!progress.value && progress.value.done < progress.value.total)
const dirty = (i: KnowledgeItem) => (drafts.value[i.id] ?? '') !== i.content

async function save(i: KnowledgeItem) {
  savingId.value = i.id
  error.value = null
  try {
    await api.put(`/ai/knowledge/${i.id}`, { content: drafts.value[i.id] ?? '' })
    await load()
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    savingId.value = null
  }
}

async function reset(i: KnowledgeItem) {
  if (!window.confirm('Throw away your version and let the local model write this again?')) return
  await api.post(`/ai/knowledge/${i.id}/reset`)
  delete drafts.value[i.id]
  await load()
}

async function rebuild(all: boolean) {
  if (all && !window.confirm('Rebuild everything the AI knows about you from your mail?\n\nTakes a while in the background (the large local model reads a lot of mail). Items you edited by hand stay.')) return
  busy.value = true
  error.value = null
  try {
    await api.post(`/accounts/${props.accountId}/ai/knowledge/rebuild`, { all })
    await load()
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}

const meta = (i: KnowledgeItem) =>
  i.source === 'user' ? 'edited by you · kept across rebuilds' : i.content ? `written by ${i.model} from ${i.evidence} mails` : 'nothing found in your mail yet'

let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  void load()
  timer = setInterval(() => building.value && void load(), 8000)
})
onBeforeUnmount(() => timer && clearInterval(timer))
watch(() => props.accountId, () => {
  items.value = []
  drafts.value = {}
  void load()
})
</script>

<template>
  <div class="rounded-xl border border-neutral-200 dark:border-neutral-800">
    <div class="flex flex-wrap items-center gap-2 border-b border-neutral-100 px-4 py-3 dark:border-neutral-900">
      <BookUser class="h-4 w-4 text-sovera-500" />
      <div class="min-w-0 flex-1">
        <p class="text-sm font-medium">What the AI knows about you</p>
        <p class="text-xs text-neutral-500">
          Who you are, how you write in each area, the questions that keep coming and your usual answers, and the people you write to most.
          Read by the local model from your mail; used in every draft. Correct anything: your version is kept.
        </p>
      </div>
      <button class="flex items-center gap-1 rounded-md border border-neutral-200 px-2.5 py-1 text-xs hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800" :disabled="busy || !ready" title="Rebuild everything from your mail (hand-edited items stay)" @click="rebuild(true)">
        <Loader2 v-if="busy" class="h-3.5 w-3.5 animate-spin" />
        <RefreshCw v-else class="h-3.5 w-3.5" />
        Rebuild
      </button>
    </div>

    <div v-if="progress" class="px-4 pt-3 text-xs text-neutral-500">
      <template v-if="building">
        Building… {{ progress.done }} of {{ progress.total }} parts
        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
          <div class="h-full rounded-full bg-sovera-600 transition-all duration-500" :style="{ width: `${Math.floor((progress.done / Math.max(1, progress.total)) * 100)}%` }" />
        </div>
      </template>
      <template v-else-if="progress.total">{{ progress.done }} parts built</template>
      <button v-if="building && !busy" class="ml-2 underline" @click="rebuild(false)">continue now</button>
    </div>
    <p v-if="!ready" class="px-4 pt-3 text-xs text-amber-600">Needs a local chat model (Configure AI).</p>
    <p v-if="error" class="px-4 pt-3 text-xs text-red-600">{{ error }}</p>

    <div class="space-y-1 p-3">
      <template v-for="section in [
        { key: 'about', label: 'About you', hint: 'roles, organisations, teaching, projects, people, standing facts', list: about ? [about] : [] },
        { key: 'audience', label: 'How you write, per category', hint: 'register, greetings, language, length', list: byKind('audience') },
        { key: 'faq', label: 'Recurring questions & your answers', hint: 'per category', list: byKind('faq') },
        { key: 'contact', label: 'People you write to', hint: `${byKind('contact').length} cards`, list: contacts },
      ]" :key="section.key">
        <button class="flex w-full items-center gap-1 rounded-md px-1 py-1.5 text-left text-sm font-medium hover:bg-neutral-50 dark:hover:bg-neutral-900" @click="open[section.key] = !open[section.key]">
          <ChevronRight class="h-4 w-4 transition-transform" :class="open[section.key] ? 'rotate-90' : ''" />
          {{ section.label }}
          <span class="text-xs font-normal text-neutral-500">· {{ section.hint }}</span>
        </button>
        <div v-if="open[section.key]" class="space-y-3 pb-2 pl-6">
          <input
            v-if="section.key === 'contact' && byKind('contact').length > 6"
            v-model="contactFilter"
            placeholder="Find a person…"
            class="w-full rounded-md border border-neutral-200 bg-transparent px-2 py-1 text-xs outline-none focus:border-sovera-500 dark:border-neutral-700"
          />
          <p v-if="!section.list.length" class="text-xs text-neutral-500">Not built yet.</p>
          <div v-for="i in section.list" :key="i.id">
            <div class="mb-1 flex items-center gap-2 text-xs">
              <span v-if="i.kind !== 'about'" class="font-medium text-neutral-700 dark:text-neutral-200">{{ i.title }}</span>
              <span v-if="i.kind === 'contact'" class="text-neutral-400">{{ i.subject }}</span>
              <span class="text-neutral-500">{{ meta(i) }}</span>
            </div>
            <textarea
              v-model="drafts[i.id]"
              :rows="i.kind === 'about' ? 12 : i.kind === 'contact' ? 4 : 7"
              spellcheck="true"
              :placeholder="i.content ? '' : 'Nothing found yet. You can write it yourself.'"
              class="w-full resize-y rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm leading-relaxed outline-none focus:border-sovera-500 dark:border-neutral-700"
            />
            <div class="mt-1 flex items-center gap-3 text-xs">
              <button class="rounded-md bg-sovera-600 px-2.5 py-1 font-medium text-white hover:bg-sovera-700 disabled:opacity-50" :disabled="!dirty(i) || savingId === i.id" @click="save(i)">Save</button>
              <button v-if="i.source === 'user'" class="flex items-center gap-1 text-neutral-500 underline hover:text-neutral-800 dark:hover:text-neutral-200" @click="reset(i)">
                <RotateCcw class="h-3 w-3" /> Let the model write it again
              </button>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
