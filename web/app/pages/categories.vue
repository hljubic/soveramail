<script setup lang="ts">
import { ArrowLeft, Loader2, Plus, Shapes, Trash2 } from 'lucide-vue-next'
import type { AiModel } from '~/composables/useAi'

/**
 * Local categories: name, colour and the description the local model reads when
 * it sorts conversations. Exists only in Sovera Mail; the mail server never sees it.
 */
const { accounts, activeAccountId } = useAccounts()
const cats = useCategories()
const ai = useAi()

interface Draft { name: string; description: string; color: string }
const drafts = ref<Record<number, Draft>>({})
const savingId = ref<number | null>(null)
const error = ref<string | null>(null)
const changedSinceSort = ref(false)

/** Editable copies: new categories get one, saved ones are refreshed, unsaved edits are kept. */
function resetDrafts(onlyId?: number) {
  const next: Record<number, Draft> = {}
  for (const c of cats.categories.value) {
    const keep = drafts.value[c.id] && c.id !== onlyId && dirty(c.id)
    next[c.id] = keep ? drafts.value[c.id]! : { name: c.name, description: c.description ?? '', color: c.color }
  }
  drafts.value = next
}

const dirty = (id: number) => {
  const c = cats.byId(id)
  const d = drafts.value[id]
  return !!c && !!d && (d.name !== c.name || d.description !== (c.description ?? '') || d.color !== c.color)
}

// Categories arrive whenever the account is known (also after this page mounted).
watch(() => cats.categories.value.map(c => `${c.id}:${c.name}:${c.color}:${c.description}`).join('|'), () => resetDrafts(), { immediate: true })

async function save(id: number) {
  const d = drafts.value[id]
  if (!d) return
  savingId.value = id
  error.value = null
  try {
    const c = cats.byId(id)
    await cats.update(id, { name: d.name.trim(), description: d.description.trim(), color: d.color })
    if (c && (d.name !== c.name || d.description !== (c.description ?? ''))) changedSinceSort.value = true
    resetDrafts(id)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    savingId.value = null
  }
}

async function remove(id: number) {
  const c = cats.byId(id)
  if (!c || !window.confirm(`Delete the category "${c.name}"?\n\nIts ${c.threads} conversations are sorted again into the remaining categories. Your mail is not touched.`)) return
  error.value = null
  try {
    await cats.remove(id)
    resetDrafts()
  } catch (e) {
    error.value = (e as Error).message
  }
}

const COLORS = ['#2563eb', '#16a34a', '#9333ea', '#d97706', '#dc2626', '#0891b2', '#db2777', '#4f46e5']
const fresh = ref<Draft>({ name: '', description: '', color: COLORS[0]! })
async function add() {
  if (!fresh.value.name.trim()) return
  error.value = null
  try {
    await cats.create({ name: fresh.value.name.trim(), description: fresh.value.description.trim(), color: fresh.value.color })
    fresh.value = { name: '', description: '', color: COLORS[(cats.categories.value.length) % COLORS.length]! }
    changedSinceSort.value = true
    resetDrafts()
  } catch (e) {
    error.value = (e as Error).message
  }
}

const sorting = ref(false)
async function resort() {
  if (!window.confirm('Sort all conversations again with the current descriptions?\n\nCategories you picked by hand stay.')) return
  sorting.value = true
  try {
    await cats.reclassify()
    changedSinceSort.value = false
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    sorting.value = false
  }
}

// Which installed model sorts into categories (urgency always uses the chat model).
const models = ref<AiModel[]>([])
const classifyModel = ref('')
const chatModels = computed(() => models.value.filter(m => m.capabilities.includes('completion')))
async function saveClassifyModel() {
  await ai.save({ classify_model: classifyModel.value || null })
  await cats.load()
}

// How far back sorting goes (e.g. 14 days while testing; empty = the whole mailbox).
const sinceDays = ref<string>('')
async function saveSinceDays() {
  const n = Number.parseInt(sinceDays.value, 10)
  await ai.save({ classify_since_days: Number.isFinite(n) && n > 0 ? n : null })
  await cats.load()
}

const progress = computed(() => cats.meta.value?.progress ?? null)
const pct = computed(() => (progress.value?.total ? Math.floor((progress.value.done / progress.value.total) * 100) : 0))

let timer: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  await Promise.all([cats.load(activeAccountId.value), ai.load()])
  classifyModel.value = ai.settings.value?.classify_model ?? ''
  sinceDays.value = ai.settings.value?.classify_since_days ? String(ai.settings.value.classify_since_days) : ''
  const probe = await ai.probe()
  models.value = probe.models ?? []
  timer = setInterval(() => void cats.load(), 5000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
watch(activeAccountId, id => void cats.load(id))
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-neutral-200 px-5 dark:border-neutral-800">
      <NuxtLink v-if="accounts.length" to="/" class="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
        <ArrowLeft class="h-4 w-4" />
      </NuxtLink>
      <Shapes class="h-4 w-4 text-sovera-500" />
      <h1 class="text-sm font-semibold">Categories</h1>
    </header>

    <div class="flex-1 overflow-y-auto">
      <div class="mx-auto flex max-w-2xl flex-col gap-5 px-6 py-8">
        <p class="text-sm text-neutral-500">
          The local model reads every conversation (the whole thread) and puts it into one category, using the descriptions below.
          Name people, senders, project acronyms and typical subjects. Categories exist only in Sovera Mail; your mail server never sees them.
          Correct a conversation by picking its category above the message: your choice is final and teaches the model.
        </p>

        <!-- Progress -->
        <div v-if="progress" class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-800">
          <div class="flex items-center gap-3">
            <span class="flex-1">
              <strong>{{ progress.done.toLocaleString() }}</strong> of {{ progress.total.toLocaleString() }} conversations sorted
              <span v-if="progress.unread_pending" class="text-neutral-500"> · {{ progress.unread_pending }} unread waiting for urgency</span>
            </span>
            <span class="text-xs text-neutral-500">{{ pct }}%</span>
          </div>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
            <div class="h-full rounded-full bg-sovera-600 transition-all duration-500" :style="{ width: `${pct}%` }" />
          </div>
          <p v-if="!cats.meta.value?.active" class="mt-2 text-xs text-amber-600">Sorting is off: pick a local model (Configure AI) and keep at least one category besides the fallback.</p>
        </div>

        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

        <!-- Categories -->
        <div v-for="c in cats.categories.value" :key="c.id" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
          <template v-if="drafts[c.id]">
            <div class="flex items-center gap-2">
              <input v-model="drafts[c.id]!.color" type="color" class="h-7 w-7 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0" title="Colour" />
              <input
                v-model="drafts[c.id]!.name"
                class="min-w-0 flex-1 rounded-md bg-transparent px-1 py-0.5 text-sm font-semibold outline-none focus:ring-1 focus:ring-sovera-400"
                maxlength="100"
              />
              <span class="shrink-0 text-xs text-neutral-500">{{ c.threads }} conversations</span>
              <button
                v-if="!c.is_fallback"
                class="rounded-md p-1 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"
                title="Delete category"
                @click="remove(c.id)"
              >
                <Trash2 class="h-4 w-4" />
              </button>
            </div>
            <textarea
              v-model="drafts[c.id]!.description"
              rows="3"
              maxlength="2000"
              :placeholder="c.is_fallback ? 'Everything that fits nowhere else.' : 'Who writes, about what: people, senders, acronyms, typical subjects…'"
              class="mt-2 w-full resize-y rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm leading-relaxed outline-none focus:border-sovera-500 dark:border-neutral-800"
            />
            <div class="mt-1 flex items-center gap-2 text-xs">
              <span v-if="c.is_fallback" class="text-neutral-500">Fallback: conversations that fit no other category land here. It cannot be deleted.</span>
              <span class="flex-1" />
              <button
                v-if="dirty(c.id)"
                class="flex items-center gap-1 rounded-md bg-sovera-600 px-2.5 py-1 font-medium text-white hover:bg-sovera-700 disabled:opacity-50"
                :disabled="savingId === c.id || !drafts[c.id]!.name.trim()"
                @click="save(c.id)"
              >
                <Loader2 v-if="savingId === c.id" class="h-3 w-3 animate-spin" />
                Save
              </button>
            </div>
          </template>
        </div>

        <!-- New category -->
        <div class="rounded-xl border border-dashed border-neutral-300 p-4 dark:border-neutral-700">
          <div class="flex items-center gap-2">
            <input v-model="fresh.color" type="color" class="h-7 w-7 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0" title="Colour" />
            <input v-model="fresh.name" placeholder="New category" maxlength="100" class="min-w-0 flex-1 bg-transparent px-1 py-0.5 text-sm font-semibold outline-none" @keydown.enter="add" />
          </div>
          <textarea
            v-model="fresh.description"
            rows="2"
            maxlength="2000"
            placeholder="Who writes, about what: people, senders, acronyms, typical subjects…"
            class="mt-2 w-full resize-y rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-800"
          />
          <div class="mt-1 flex justify-end">
            <button class="flex items-center gap-1 rounded-md border border-neutral-200 px-2.5 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800" :disabled="!fresh.name.trim()" @click="add">
              <Plus class="h-3.5 w-3.5" /> Add category
            </button>
          </div>
        </div>

        <!-- Re-sort after changing descriptions -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border p-4 text-sm" :class="changedSinceSort ? 'border-sovera-300 bg-sovera-50 dark:border-sovera-800 dark:bg-sovera-950/40' : 'border-neutral-200 dark:border-neutral-800'">
          <div class="min-w-0 flex-1">
            <p class="font-medium">Sort all conversations again</p>
            <p class="text-xs text-neutral-500">
              {{ changedSinceSort ? 'You changed the categories: sorted conversations still follow the old descriptions until you re-sort.' : 'Use after changing descriptions. New mail is sorted when you click “Process new”.' }}
              Categories you picked by hand stay.
            </p>
          </div>
          <button class="flex items-center gap-1.5 rounded-lg bg-sovera-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sovera-700 disabled:opacity-50" :disabled="sorting" @click="resort">
            <Loader2 v-if="sorting" class="h-4 w-4 animate-spin" /> Re-sort
          </button>
        </div>

        <!-- Models -->
        <div class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-800">
          <p class="font-medium">Models</p>
          <label class="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <span class="w-28 text-neutral-500">Re-sort (bulk)</span>
            <select v-model="classifyModel" class="min-w-0 flex-1 rounded-md border border-neutral-200 bg-transparent px-2 py-1 dark:border-neutral-700" @change="saveClassifyModel">
              <option value="">Same as the chat model ({{ ai.settings.value?.model ?? 'none' }})</option>
              <option v-for="m in chatModels" :key="m.name" :value="m.name">{{ m.name }}<template v-if="m.parameters"> · {{ m.parameters }}</template></option>
            </select>
          </label>
          <label class="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <span class="w-28 text-neutral-500">Sort mail from</span>
            <span>the last</span>
            <input v-model="sinceDays" type="number" min="1" placeholder="all" class="w-20 rounded-md border border-neutral-200 bg-transparent px-2 py-1 dark:border-neutral-700" @change="saveSinceDays" />
            <span>days <span class="text-neutral-500">(empty = the whole mailbox)</span></span>
          </label>
          <p class="mt-2 flex gap-2 text-xs">
            <span class="w-28 text-neutral-500">Urgency</span>
            <span>{{ ai.settings.value?.model ?? '—' }} <span class="text-neutral-500">(the chat model: only unread mail, so the strongest model is worth it)</span></span>
          </p>
          <p class="mt-2 text-xs text-neutral-500">
            New mail is sorted by "Process new" with the chat model (one call per conversation: category, urgency and summary).
            The model above is used only by Re-sort and "Rebuild everything" over the whole mailbox, where a small model is many times faster.
          </p>
        </div>

        <RebuildAiButton />
      </div>
    </div>
  </div>
</template>
