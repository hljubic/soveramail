<script setup lang="ts">
import { AlertTriangle, Cpu, Loader2 } from 'lucide-vue-next'

/**
 * Sidebar, bottom: a quiet line while the local AI works or a model sits in RAM, with
 * "Unload" for the models Sovera uses. Models never leave RAM on their own.
 */
defineProps<{ collapsed?: boolean }>()
const { runtime, running, error, unload } = useAiRuntime()

const models = computed(() => runtime.value?.models ?? [])
const pass = computed(() => runtime.value?.pass ?? null)
const failed = computed(() => (pass.value?.state === 'error' ? pass.value.error : null) ?? error.value)
const visible = computed(() => running.value || models.value.length > 0 || !!failed.value)

const PHASES: Record<string, string> = { threads: 'Conversations', style: 'Writing style', knowledge: 'Knowledge about you', index: 'Search index' }
const progress = computed(() => {
  const p = pass.value
  if (!running.value || !p) return null
  return {
    text: p.phase === 'threads' && p.total ? `Processing ${p.done} / ${p.total}` : `${PHASES[p.phase ?? ''] ?? 'Starting'}…`,
    pct: p.phase === 'threads' && p.total ? Math.min(100, Math.round((p.done / p.total) * 100)) : null,
  }
})

const gb = (bytes: number) => `${(bytes / 1e9).toFixed(bytes >= 10e9 ? 0 : 1)} GB`
</script>

<template>
  <div v-if="visible" class="mx-2 mb-2 space-y-1.5 rounded-xl bg-surface px-3 py-2 text-xs text-ink-muted" :class="collapsed ? 'flex justify-center px-0' : ''">
    <template v-if="collapsed">
      <Loader2 v-if="running" class="h-4 w-4 animate-spin text-primary" />
      <Cpu v-else class="h-4 w-4" :title="models.map(m => m.name).join(', ')" />
    </template>
    <template v-else>
      <div v-if="progress">
        <div class="flex items-center gap-1.5 text-ink">
          <Loader2 class="h-3.5 w-3.5 shrink-0 animate-spin text-primary" />
          <span class="truncate">{{ progress.text }}</span>
        </div>
        <div v-if="progress.pct !== null" class="mt-1 h-1 overflow-hidden rounded-full bg-line">
          <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${progress.pct}%` }" />
        </div>
      </div>
      <div v-for="m in models" :key="m.name" class="flex items-center gap-1.5" :title="`${m.name} · ${gb(m.size)} in memory`">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="m.ours ? 'bg-emerald-500' : 'bg-neutral-400'" />
        <span class="min-w-0 flex-1 truncate">
          <span class="text-ink">{{ m.name.replace(/:latest$/, '') }}</span> · {{ gb(m.size) }}
          <span class="block truncate">{{ m.ours ? 'in memory until you unload it' : 'another app' }}</span>
        </span>
        <button
          v-if="m.ours && !running"
          class="shrink-0 rounded-full px-2 py-0.5 font-medium text-primary hover:bg-hover"
          title="Remove the model from memory now (the next AI task loads it again)"
          @click="unload(m.name)"
        >
          Unload
        </button>
      </div>
      <p v-if="failed && !running" class="flex items-start gap-1 text-red-600" :title="failed">
        <AlertTriangle class="mt-px h-3.5 w-3.5 shrink-0" />
        <span class="line-clamp-2">{{ failed }}</span>
      </p>
    </template>
  </div>
</template>
