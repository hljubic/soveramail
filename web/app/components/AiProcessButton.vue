<script setup lang="ts">
import { Loader2, Sparkles } from 'lucide-vue-next'

/**
 * Top bar: "Process new" runs the local AI over new mail in one pass. Shown only when
 * something waits or a pass runs; nothing starts the AI without it.
 */
const { runtime, running, process } = useAiRuntime()

const pending = computed(() => runtime.value?.pending ?? null)
const pass = computed(() => runtime.value?.pass ?? null)
const visible = computed(() => !!runtime.value?.ready && (running.value || !!runtime.value?.has_work))

const PHASES: Record<string, string> = { threads: 'Conversations', style: 'Writing style', knowledge: 'Knowledge', index: 'Index' }

const label = computed(() => {
  if (running.value) {
    const p = pass.value
    if (!p || p.state === 'queued') return 'Starting…'
    if (p.phase === 'threads' && p.total) return `Processing ${p.done} / ${p.total}`
    return `${PHASES[p.phase ?? ''] ?? 'Processing'}…`
  }
  return pending.value?.threads ? `Process new · ${pending.value.threads}` : 'Process new'
})

const title = computed(() => {
  const p = pending.value
  if (!p) return ''
  const parts = [
    p.threads ? `${p.threads} conversations (category, urgency, summary)` : null,
    p.style ? 'writing style' : null,
    p.knowledge ? `${p.knowledge} knowledge items` : null,
    p.index ? `${p.index.toLocaleString()} messages for smart search` : null,
  ].filter(Boolean)
  return running.value ? 'The local AI is working' : `The local AI processes in one pass: ${parts.join(', ')}`
})
</script>

<template>
  <button
    v-if="visible"
    class="flex shrink-0 items-center gap-1.5 rounded-full border border-line px-3.5 py-1.5 text-sm font-medium text-ink-muted transition hover:bg-hover disabled:cursor-default disabled:hover:bg-transparent"
    :disabled="running"
    :title="title"
    @click="process"
  >
    <Loader2 v-if="running" class="h-4 w-4 animate-spin text-primary" />
    <Sparkles v-else class="h-4 w-4 text-primary" />
    <span class="whitespace-nowrap">{{ label }}</span>
  </button>
</template>
