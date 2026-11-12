<script setup lang="ts">
import { Sparkles, X } from 'lucide-vue-next'

/** A "Summarize" result: one line per fact, as many as the model found. */
defineProps<{ title: string; lines: string[]; model: string | null; error: string | null }>()
defineEmits<{ close: [] }>()
</script>

<template>
  <div class="rounded-xl bg-surface-2 p-4 text-sm text-ink">
    <div class="mb-1.5 flex items-center gap-2 text-xs text-ink-muted">
      <Sparkles class="h-3.5 w-3.5 text-primary" />
      <span class="flex-1">{{ error ? 'Summary failed' : `${title} · ${model}, local` }}</span>
      <button class="rounded-full p-1 hover:bg-hover" title="Close" @click="$emit('close')"><X class="h-3.5 w-3.5" /></button>
    </div>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    <ul v-else class="list-disc space-y-1 pl-5 leading-relaxed">
      <li v-for="(l, i) in lines" :key="i">{{ l }}</li>
    </ul>
  </div>
</template>
