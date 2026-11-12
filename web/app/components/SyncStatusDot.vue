<script setup lang="ts">
import type { SyncStatus } from '~/types/api'

const props = defineProps<{ status: SyncStatus }>()

const meta = computed(() => {
  switch (props.status) {
    case 'syncing':
    case 'queued':
      return { cls: 'bg-sovera-500 animate-pulse', title: props.status === 'queued' ? 'Sync queued' : 'Syncing…' }
    case 'error':
      return { cls: 'bg-amber-500', title: 'Last sync failed' }
    case 'needs_reauth':
      return { cls: 'bg-red-500', title: 'Reconnect required' }
    case 'disconnected':
      return { cls: 'bg-neutral-400', title: 'Disconnected · mail kept locally' }
    default:
      return { cls: 'bg-emerald-500', title: 'Up to date' }
  }
})
</script>

<template>
  <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="meta.cls" :title="meta.title" />
</template>
