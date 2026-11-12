<script setup lang="ts">
import { ArrowLeft, CheckCircle2, Loader2, RefreshCw, Sparkles, XCircle } from 'lucide-vue-next'
import type { AiModel, IndexStatus } from '~/composables/useAi'

const ai = useAi()
const { accounts } = useAccounts()

// ---------------------------------------------------------------- index status

const index = ref<IndexStatus | null>(null)
const indexing = ref(false)
let indexTimer: ReturnType<typeof setInterval> | null = null

async function refreshIndex() {
  try {
    index.value = await ai.indexStatus()
  } catch {
    index.value = null
  }
}

async function runIndex() {
  indexing.value = true
  try {
    await ai.runIndex()
    await refreshIndex()
  } catch (e) {
    saveError.value = (e as Error).message
  } finally {
    indexing.value = false
  }
}

const indexTotals = computed(() => {
  const acc = index.value?.accounts ?? []
  return acc.reduce((t, a) => ({ indexed: t.indexed + a.indexed, total: t.total + a.total }), { indexed: 0, total: 0 })
})
const indexPercent = computed(() => (indexTotals.value.total ? Math.round((indexTotals.value.indexed / indexTotals.value.total) * 100) : 0))

// ---------------------------------------------------------------- model server

const baseUrl = ref('')
const model = ref('')
const embeddingModel = ref('')
const enabled = ref(true)
const allowRemote = ref(false)
const streaming = ref(true)
const thinking = ref(false)

const chatModels = computed(() => models.value.filter(m => !m.capabilities.includes('embedding') || m.capabilities.includes('completion')))
const embeddingModels = computed(() => models.value.filter(m => m.capabilities.includes('embedding')))
const modelCanThink = computed(() => models.value.find(m => m.name === model.value)?.capabilities.includes('thinking') ?? false)

const probing = ref(false)
const probeOk = ref<boolean | null>(null)
const probeMessage = ref('')
const version = ref<string | null>(null)
const models = ref<AiModel[]>([])

const saving = ref(false)
const saved = ref(false)
const saveError = ref<string | null>(null)

async function testConnection() {
  probing.value = true
  probeOk.value = null
  probeMessage.value = ''
  const res = await ai.probe(baseUrl.value, allowRemote.value)
  probing.value = false
  probeOk.value = res.ok
  if (res.ok) {
    version.value = res.version ?? null
    models.value = res.models ?? []
    baseUrl.value = res.base_url
    if (!model.value && chatModels.value.length) model.value = chatModels.value[0]!.name
    if (!embeddingModel.value && embeddingModels.value.length) embeddingModel.value = embeddingModels.value[0]!.name
    if (model.value && !models.value.some(m => m.name === model.value)) {
      probeMessage.value = `Model "${model.value}" is not installed on this server.`
    }
  } else {
    models.value = []
    probeMessage.value = res.message || 'Cannot reach Ollama.'
  }
}

async function saveSettings() {
  saving.value = true
  saved.value = false
  saveError.value = null
  try {
    await ai.save({ base_url: baseUrl.value, model: model.value, embedding_model: embeddingModel.value, enabled: enabled.value, allow_remote: allowRemote.value, streaming: streaming.value, thinking: thinking.value })
    saved.value = true
    await refreshIndex()
    setTimeout(() => (saved.value = false), 2500)
  } catch (e) {
    saveError.value = (e as Error).message
  } finally {
    saving.value = false
  }
}

const twoColumns = computed(() => ai.ready.value)
const canSave = computed(() => !saving.value && baseUrl.value.trim() !== '' && (!enabled.value || model.value !== ''))

function formatBytes(b: number): string {
  if (!b) return ''
  const gb = b / 1024 ** 3
  return gb >= 1 ? `${gb.toFixed(1)} GB` : `${Math.round(b / 1024 ** 2)} MB`
}

// ---------------------------------------------------------------- lifecycle

onMounted(async () => {
  await ai.load()
  const s = ai.settings.value
  baseUrl.value = s?.base_url ?? 'http://host.docker.internal:11434'
  model.value = s?.model ?? ''
  embeddingModel.value = s?.embedding_model ?? ''
  allowRemote.value = s?.allow_remote ?? false
  streaming.value = s?.streaming ?? true
  thinking.value = s?.thinking ?? false
  enabled.value = s?.model ? s.enabled : true
  await refreshIndex()
  indexTimer = setInterval(refreshIndex, 5000)
  await testConnection()
})

onBeforeUnmount(() => {
  if (indexTimer) clearInterval(indexTimer)
})
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex h-12 shrink-0 items-center gap-3 border-b border-neutral-200 px-5 dark:border-neutral-800">
      <NuxtLink v-if="accounts.length" to="/" class="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
        <ArrowLeft class="h-4 w-4" />
      </NuxtLink>
      <Sparkles class="h-4 w-4 text-sovera-500" />
      <h1 class="text-sm font-semibold">Configure AI</h1>
    </header>

    <div class="flex-1 overflow-y-auto">
      <!-- Two columns once AI is set up: the model on the left, how it works on the right. -->
      <div class="mx-auto px-6 py-8" :class="twoColumns ? 'grid max-w-6xl items-start gap-x-8 gap-y-6 xl:grid-cols-2' : 'max-w-2xl'">
        <div class="min-w-0">
          <p class="text-sm text-neutral-500">
            Sovera Mail talks to a model server running on <strong>this computer</strong>. Nothing leaves your machine.
            Install <a class="underline" href="https://ollama.com" target="_blank" rel="noreferrer">Ollama</a>, pull a model
            (for example <code>ollama pull qwen3:8b</code>) and pick it below.
          </p>

          <div class="mt-6 space-y-5 rounded-xl border border-neutral-200 p-5 dark:border-neutral-800">
            <!-- Provider -->
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Provider</label>
              <div class="mt-1 flex items-center gap-2 text-sm">
                <span class="rounded-md bg-neutral-100 px-2 py-1 dark:bg-neutral-800">Ollama</span>
                <span class="text-xs text-neutral-400">llama.cpp / MLX / OpenAI-compatible servers come later</span>
              </div>
            </div>

            <!-- Base URL + test -->
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Server URL</label>
              <div class="mt-1 flex gap-2">
                <input
                  v-model="baseUrl"
                  type="text"
                  spellcheck="false"
                  placeholder="http://host.docker.internal:11434"
                  class="min-w-0 flex-1 rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700"
                  @keydown.enter="testConnection"
                />
                <button
                  class="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 text-sm hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                  :disabled="probing"
                  @click="testConnection"
                >
                  <Loader2 v-if="probing" class="h-4 w-4 animate-spin" />
                  <RefreshCw v-else class="h-4 w-4" />
                  Test connection
                </button>
              </div>
              <p class="mt-1 text-xs text-neutral-500">
                The API runs in Docker, so <code>localhost</code> is rewritten to <code>host.docker.internal</code> (this computer). Ollama's default port is 11434.
              </p>
              <label class="mt-2 flex items-start gap-2 text-sm">
                <input v-model="allowRemote" type="checkbox" class="mt-0.5 accent-sovera-600" />
                <span>
                  Allow a remote model server
                  <span class="block text-xs" :class="allowRemote ? 'text-amber-600' : 'text-neutral-500'">
                    {{ allowRemote
                      ? 'Heads-up: the e-mail you are answering, related mails from your mailbox and your instructions are sent to that server for every draft. Use it only for a server you control or trust (e.g. your own GPU box).'
                      : 'Off: only this computer or your private network (localhost, *.local, 10.x, 192.168.x). Nothing leaves the device.' }}
                  </span>
                </span>
              </label>
              <p v-if="probeOk === true" class="mt-2 flex items-center gap-1.5 text-xs text-emerald-600">
                <CheckCircle2 class="h-3.5 w-3.5" /> Connected<span v-if="version"> · Ollama {{ version }}</span> · {{ models.length }} model{{ models.length === 1 ? '' : 's' }} installed
              </p>
              <p v-else-if="probeOk === false" class="mt-2 flex items-start gap-1.5 text-xs text-red-600">
                <XCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> <span>{{ probeMessage }}</span>
              </p>
              <p v-if="probeOk && probeMessage" class="mt-1 text-xs text-amber-600">{{ probeMessage }}</p>
            </div>

            <!-- Model -->
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Model</label>
              <select
                v-if="chatModels.length"
                v-model="model"
                class="mt-1 w-full rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option v-for="m in chatModels" :key="m.name" :value="m.name">
                  {{ m.name }}<template v-if="m.parameters"> · {{ m.parameters }}</template><template v-if="m.quantization"> · {{ m.quantization }}</template> · {{ formatBytes(m.size) }}
                </option>
              </select>
              <input
                v-else
                v-model="model"
                type="text"
                placeholder="qwen3:8b"
                class="mt-1 w-full rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700"
              />
              <p v-if="!models.length" class="mt-1 text-xs text-neutral-500">Connect first to pick from installed models, or type a model name.</p>

              <label class="mt-3 flex items-start gap-2 text-sm">
                <input v-model="streaming" type="checkbox" class="mt-0.5 accent-sovera-600" />
                <span>
                  Streaming
                  <span class="block text-xs text-neutral-500">Show the draft word by word while the model writes it, instead of waiting for the whole text.</span>
                </span>
              </label>

              <label v-if="modelCanThink" class="mt-3 flex items-start gap-2 text-sm">
                <input v-model="thinking" type="checkbox" class="mt-0.5 accent-sovera-600" />
                <span>
                  Think before writing
                  <span class="block text-xs text-neutral-500">
                    This is a reasoning model: it can plan before it drafts a reply or reads a smart search. Thinking makes both several
                    times slower and is rarely better for short e-mails. Turn it off for fast drafts. The reasoning never ends up in the
                    mail; some thinking-only models ignore this switch and keep thinking.
                  </span>
                </span>
              </label>
            </div>

            <!-- Embedding model (retrieval / RAG) -->
            <div>
              <label class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Embedding model (mailbox memory)</label>
              <select
                v-if="embeddingModels.length"
                v-model="embeddingModel"
                class="mt-1 w-full rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700 dark:bg-neutral-950"
              >
                <option value="">— off —</option>
                <option v-for="m in embeddingModels" :key="m.name" :value="m.name">
                  {{ m.name }}<template v-if="m.parameters"> · {{ m.parameters }}</template> · {{ formatBytes(m.size) }}
                </option>
              </select>
              <input
                v-else
                v-model="embeddingModel"
                type="text"
                placeholder="nomic-embed-text"
                class="mt-1 w-full rounded-lg border border-neutral-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-sovera-500 dark:border-neutral-700"
              />
              <p class="mt-1 text-xs text-neutral-500">
                Used to index your mail locally for smart search and "similar conversations" in AI compose
                (same account only, never mixed across mailboxes).
                <template v-if="probeOk && !embeddingModels.length">No embedding model installed yet: run <code>ollama pull bge-m3</code>, then Test connection again.</template>
              </p>
            </div>

            <!-- Index status -->
            <div v-if="index" class="rounded-lg border border-neutral-100 bg-neutral-50 p-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
              <div class="flex items-center gap-2">
                <span class="font-medium">Mailbox index</span>
                <span v-if="index.enabled" class="text-neutral-500">{{ indexTotals.indexed }} / {{ indexTotals.total }} messages · {{ index.embedding_model }}</span>
                <span v-else class="text-neutral-500">off (pick an embedding model and save)</span>
                <span class="flex-1" />
                <button
                  class="rounded-md border border-neutral-200 px-2 py-1 hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                  :disabled="!index.enabled || indexing"
                  @click="runIndex"
                >
                  {{ indexing ? 'Queued…' : 'Index now' }}
                </button>
              </div>
              <div v-if="index.enabled && indexTotals.total" class="mt-2 h-1.5 w-full overflow-hidden rounded bg-neutral-200 dark:bg-neutral-800">
                <div class="h-full bg-sovera-500 transition-all" :style="{ width: `${indexPercent}%` }" />
              </div>
              <ul v-if="index.accounts.length > 1" class="mt-2 space-y-0.5 text-neutral-500">
                <li v-for="a in index.accounts" :key="a.account_id">{{ a.email }}: {{ a.indexed }} / {{ a.total }}</li>
              </ul>
            </div>

            <label class="flex items-center gap-2 text-sm">
              <input v-model="enabled" type="checkbox" class="accent-sovera-600" />
              Enable local AI features (AI compose, smart search)
            </label>

            <div class="flex items-center gap-3 border-t border-neutral-100 pt-4 dark:border-neutral-900">
              <button
                class="flex items-center gap-2 rounded-lg bg-sovera-600 px-4 py-2 text-sm font-medium text-white hover:bg-sovera-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="!canSave"
                @click="saveSettings"
              >
                <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                Save
              </button>
              <span v-if="saved" class="flex items-center gap-1 text-xs text-emerald-600"><CheckCircle2 class="h-3.5 w-3.5" /> Saved</span>
              <span v-if="saveError" class="text-xs text-red-600">{{ saveError }}</span>
              <span class="flex-1" />
              <span class="text-xs text-neutral-500">
                Status:
                <span :class="ai.ready.value ? 'text-emerald-600' : 'text-neutral-400'">{{ ai.ready.value ? 'Ready' : 'Not configured' }}</span>
              </span>
            </div>
          </div>
        </div>

        <aside v-if="twoColumns" class="min-w-0 space-y-4 xl:sticky xl:top-0">
          <AiProcessingSettings />
          <RebuildAiButton />
        </aside>
      </div>
    </div>
  </div>
</template>
