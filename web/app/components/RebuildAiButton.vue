<script setup lang="ts">
import { Loader2, RotateCcw } from 'lucide-vue-next'

/**
 * "Rebuild everything": the vector index (knowledge), writing style, categories and
 * urgency of unread mail are thrown away and built again in the background.
 * Hand-picked categories, draft corrections and the chosen reply language stay.
 */
const api = useApi()
const cats = useCategories()
const running = ref(false)
const done = ref(false)
const error = ref<string | null>(null)

async function rebuild() {
  if (!window.confirm('Rebuild everything the local AI learned from your mail?\n\nThe search index, writing style, categories and urgency are rebuilt in the background with the current models (this can take a while). Categories you picked by hand stay.')) return
  running.value = true
  error.value = null
  try {
    await api.post('/ai/rebuild')
    done.value = true
    await cats.load()
    setTimeout(() => (done.value = false), 4000)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    running.value = false
  }
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-800">
    <div class="min-w-0 flex-1">
      <p class="font-medium">Rebuild everything</p>
      <p class="text-xs text-neutral-500">Search index, writing style, categories and urgency, built again from scratch in the background. Categories you picked by hand stay.</p>
      <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
      <p v-else-if="done" class="mt-1 text-xs text-emerald-600">Started. Progress shows in the sidebar.</p>
    </div>
    <button
      class="flex items-center gap-1.5 rounded-lg border border-neutral-200 px-3 py-1.5 text-sm hover:bg-neutral-100 disabled:opacity-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
      :disabled="running"
      @click="rebuild"
    >
      <Loader2 v-if="running" class="h-4 w-4 animate-spin" />
      <RotateCcw v-else class="h-4 w-4" />
      Rebuild
    </button>
  </div>
</template>
