<script setup lang="ts">
import { Download, ExternalLink, X } from 'lucide-vue-next'
import type { AttachmentMeta } from '~/types/api'

const props = defineProps<{ attachment: AttachmentMeta }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()

const viewUrl = computed(() => api.assetUrl(props.attachment.url))
const downloadUrl = computed(() => api.assetUrl(props.attachment.download_url))

const kind = computed(() => {
  const m = (props.attachment.mime_type || '').toLowerCase()
  if (m === 'application/pdf') return 'pdf'
  if (m.startsWith('image/')) return 'image'
  if (m.startsWith('text/')) return 'text'
  return 'other'
})

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

function openExternally() {
  if (viewUrl.value) window.open(viewUrl.value, '_blank', 'noopener,noreferrer')
}
</script>

<template>
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-6" @click.self="emit('close')">
    <div class="flex h-full w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-neutral-950">
      <header class="flex items-center gap-3 border-b border-neutral-200 px-4 py-2.5 dark:border-neutral-800">
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium">{{ attachment.filename || 'attachment' }}</p>
          <p class="text-xs text-neutral-500">{{ attachment.mime_type }} · {{ formatBytes(attachment.size) }}</p>
        </div>
        <a
          v-if="downloadUrl"
          :href="downloadUrl"
          :download="attachment.filename || 'attachment'"
          class="flex items-center gap-1.5 rounded-lg bg-sovera-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sovera-700"
        >
          <Download class="h-4 w-4" /> Download
        </a>
        <button
          class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800"
          title="Open in system viewer"
          @click="openExternally"
        >
          <ExternalLink class="h-4 w-4" />
        </button>
        <button class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800" title="Close (Esc)" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </header>

      <div class="min-h-0 flex-1 bg-neutral-100 dark:bg-neutral-900">
        <iframe
          v-if="viewUrl && kind === 'pdf'"
          :src="viewUrl"
          class="h-full w-full border-0 bg-white"
          referrerpolicy="no-referrer"
          :title="attachment.filename || 'attachment'"
        />
        <iframe
          v-else-if="viewUrl && kind === 'text'"
          :src="viewUrl"
          class="h-full w-full border-0 bg-white"
          sandbox=""
          referrerpolicy="no-referrer"
          :title="attachment.filename || 'attachment'"
        />
        <div v-else-if="viewUrl && kind === 'image'" class="flex h-full items-center justify-center overflow-auto p-4">
          <img :src="viewUrl" :alt="attachment.filename || ''" class="max-h-full max-w-full object-contain" referrerpolicy="no-referrer" />
        </div>
        <div v-else class="flex h-full flex-col items-center justify-center gap-3 text-sm text-neutral-500">
          <p>No inline preview for this file type.</p>
          <a v-if="downloadUrl" :href="downloadUrl" :download="attachment.filename || 'attachment'" class="rounded-lg bg-sovera-600 px-3 py-1.5 font-medium text-white hover:bg-sovera-700">
            Download {{ attachment.filename }}
          </a>
        </div>
      </div>
    </div>
  </div>
</template>
