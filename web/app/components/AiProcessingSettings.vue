<script setup lang="ts">
import type { AiModel, AiProcessingKey, AiSettings } from '~/composables/useAi'

/**
 * AI settings: what the "Process new" pass writes, which model summarizes, the
 * proofreading pass, and how long a model stays in RAM.
 */
const ai = useAi()
const models = ref<AiModel[]>([])
const chatModels = computed(() => models.value.filter(m => m.capabilities.includes('completion')))
const saved = ref(false)
const error = ref<string | null>(null)

const s = computed(() => ai.settings.value)

async function save(patch: Partial<Pick<AiSettings, AiProcessingKey | 'summary_model'>>) {
  error.value = null
  try {
    await ai.save(patch)
    saved.value = true
    setTimeout(() => (saved.value = false), 1500)
    void useAiRuntime().refresh()
  } catch (e) {
    error.value = (e as Error).message
  }
}

const num = (e: Event) => Math.max(0, Number.parseInt((e.target as HTMLInputElement).value, 10) || 0)

onMounted(async () => {
  if (!ai.settings.value) await ai.load()
  try {
    models.value = (await ai.probe()).models ?? []
  } catch {
    /* the model list stays empty; the select still shows the current choice */
  }
})

const box = 'rounded-xl border border-neutral-200 bg-surface p-4 text-sm dark:border-neutral-800'
const field = 'rounded-md border border-neutral-200 bg-transparent px-2 py-1 dark:border-neutral-700'
</script>

<template>
  <div v-if="s" class="space-y-4">
    <div class="flex h-4 items-center justify-end gap-2 text-xs">
      <span v-if="saved" class="text-emerald-600">Saved</span>
      <span v-if="error" class="text-red-600">{{ error }}</span>
    </div>

    <!-- When the AI works -->
    <section :class="box">
      <p class="font-medium">When the AI works</p>
      <p class="mt-1 text-xs text-neutral-500">
        Only when you click <span class="font-medium text-ink">“Process new”</span> in the top bar. One pass sorts new mail into categories,
        rates its urgency, writes summaries and updates your writing style, what the AI knows about you and the search index;
        each model is loaded once. Nothing runs on its own.
      </p>
    </section>

    <!-- Summaries -->
    <section :class="box">
      <p class="font-medium">Summaries</p>
      <div class="mt-3 space-y-2 text-xs">
        <label class="flex items-center gap-2">
          <input type="checkbox" :checked="s.summaries_in_pass" @change="save({ summaries_in_pass: ($event.target as HTMLInputElement).checked })" />
          <span>The pass also writes summaries (“Summarize” then shows them at once)</span>
        </label>
        <div v-if="s.summaries_in_pass" class="ml-6 space-y-2">
          <label class="flex flex-wrap items-center gap-2">
            <span>for conversations and messages longer than</span>
            <input type="number" min="0" max="5000" :value="s.summary_min_words" :class="field" class="w-20" @change="save({ summary_min_words: num($event) })" />
            <span>words</span>
          </label>
          <label class="flex items-center gap-2">
            <input type="checkbox" :checked="s.summary_skip_bulk" @change="save({ summary_skip_bulk: ($event.target as HTMLInputElement).checked })" />
            <span>skip newsletters, mailing lists and automated mail</span>
          </label>
        </div>
        <label class="flex flex-col gap-1 pt-1">
          <span class="text-neutral-500">Summary model</span>
          <select :value="s.summary_model ?? ''" :class="field" @change="save({ summary_model: ($event.target as HTMLSelectElement).value || null })">
            <option value="">Same as the chat model ({{ s.model ?? '—' }})</option>
            <option v-for="m in chatModels" :key="m.name" :value="m.name">{{ m.name }}<template v-if="m.parameters"> · {{ m.parameters }}</template></option>
          </select>
          <span v-if="s.summary_model && s.summary_model !== s.model" class="text-neutral-500">A different model means a second call per conversation (and a second model in memory).</span>
        </label>
      </div>
    </section>

    <!-- Quality -->
    <section :class="box">
      <p class="font-medium">Text quality</p>
      <label class="mt-3 flex items-start gap-2 text-xs">
        <input type="checkbox" class="mt-0.5" :checked="s.proofread" @change="save({ proofread: ($event.target as HTMLInputElement).checked })" />
        <span>
          <span class="font-medium text-ink">Proofreading pass</span><br />
          <span class="text-neutral-500">The model reads its drafts and summaries once more and fixes made-up words, wrong word forms and missing accents without changing the content. Helps small models in languages other than English; takes a few seconds longer.</span>
        </span>
      </label>
    </section>

    <!-- Memory -->
    <section :class="box">
      <p class="font-medium">Memory</p>
      <p class="mt-2 text-xs text-neutral-500">
        A model stays in memory until you free it with “Unload” at the bottom left, so the next AI answer is ready at once.
        Sovera Mail never touches models used by other apps.
      </p>
    </section>
  </div>
</template>
