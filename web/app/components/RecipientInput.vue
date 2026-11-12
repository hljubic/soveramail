<script setup lang="ts">
/**
 * Chip-style recipient field with autocomplete from the account's address book.
 * v-model is an array of "Name <email>" / "email" strings.
 */
import { X } from 'lucide-vue-next'

const props = withDefaults(defineProps<{ modelValue: string[]; accountId: number | null; placeholder?: string }>(), {
  placeholder: '',
})
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

interface Suggestion {
  email: string
  name: string | null
}

const api = useApi()
const input = ref('')
const inputEl = ref<HTMLInputElement | null>(null)
const suggestions = ref<Suggestion[]>([])
const open = ref(false)
const highlighted = ref(0)
let timer: ReturnType<typeof setTimeout> | null = null
let seq = 0

const chips = computed(() =>
  props.modelValue.map((raw) => {
    const m = raw.match(/^(.*?)<([^>]+)>\s*$/)
    const email = (m?.[2] ?? raw).trim()
    const name = (m?.[1] ?? '').trim().replace(/^"|"$/g, '')
    return { raw, email, name, valid: /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(email) }
  }),
)

function format(s: Suggestion) {
  return s.name ? `${s.name} <${s.email}>` : s.email
}

function add(raw: string) {
  const value = raw.trim().replace(/[,;]+$/, '').trim()
  if (!value) return
  const email = (value.match(/<([^>]+)>/)?.[1] ?? value).toLowerCase()
  if (chips.value.some(c => c.email.toLowerCase() === email)) {
    input.value = ''
    return
  }
  emit('update:modelValue', [...props.modelValue, value])
  input.value = ''
  suggestions.value = []
  open.value = false
}

function remove(index: number) {
  emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

async function fetchSuggestions(term: string) {
  if (!props.accountId) return
  const mySeq = ++seq
  try {
    const res = await api.get<{ data: Suggestion[] }>(`/accounts/${props.accountId}/contacts`, { q: term, limit: 8 })
    if (mySeq !== seq) return
    const taken = new Set(chips.value.map(c => c.email.toLowerCase()))
    suggestions.value = res.data.filter(s => !taken.has(s.email.toLowerCase()))
    highlighted.value = 0
    open.value = suggestions.value.length > 0
  } catch {
    suggestions.value = []
  }
}

watch(input, (v) => {
  if (timer) clearTimeout(timer)
  if (!v.trim()) {
    suggestions.value = []
    open.value = false
    return
  }
  timer = setTimeout(() => fetchSuggestions(v.trim()), 120)
})

function onKeydown(e: KeyboardEvent) {
  if (open.value && suggestions.value.length) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      highlighted.value = (highlighted.value + 1) % suggestions.value.length
      return
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault()
      highlighted.value = (highlighted.value - 1 + suggestions.value.length) % suggestions.value.length
      return
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault()
      add(format(suggestions.value[highlighted.value]!))
      return
    }
  }
  if (e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === 'Tab' && input.value.trim())) {
    if (input.value.trim()) {
      e.preventDefault()
      add(input.value)
    }
    return
  }
  if (e.key === 'Escape') {
    open.value = false
    return
  }
  if (e.key === 'Backspace' && !input.value && props.modelValue.length) {
    remove(props.modelValue.length - 1)
  }
}

function onBlur() {
  // Give a click on a suggestion time to register.
  setTimeout(() => {
    if (input.value.trim()) add(input.value)
    open.value = false
  }, 150)
}

function onPaste(e: ClipboardEvent) {
  const text = e.clipboardData?.getData('text') ?? ''
  if (/[,;\n]/.test(text)) {
    e.preventDefault()
    const next = [...props.modelValue]
    for (const part of text.split(/[,;\n]+/)) {
      const v = part.trim()
      if (v && !next.some(x => x.toLowerCase().includes(v.toLowerCase()))) next.push(v)
    }
    emit('update:modelValue', next)
  }
}

defineExpose({ focus: () => inputEl.value?.focus() })
</script>

<template>
  <div class="relative min-w-0 flex-1">
    <div class="flex min-h-[30px] flex-wrap items-center gap-1 py-0.5" @click="inputEl?.focus()">
      <span
        v-for="(c, i) in chips"
        :key="c.raw + i"
        class="flex max-w-full items-center gap-1 rounded-full border py-0.5 pl-2.5 pr-1 text-xs"
        :class="c.valid
          ? 'border-neutral-200 bg-neutral-100 text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'
          : 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300'"
        :title="c.valid ? c.email : 'Not a valid address'"
      >
        <span class="truncate">{{ c.name || c.email }}</span>
        <button type="button" class="rounded-full p-0.5 hover:bg-black/10 dark:hover:bg-white/10" :aria-label="`Remove ${c.email}`" @click.stop="remove(i)">
          <X class="h-3 w-3" />
        </button>
      </span>
      <input
        ref="inputEl"
        v-model="input"
        type="text"
        autocomplete="off"
        spellcheck="false"
        :placeholder="chips.length ? '' : placeholder"
        class="min-w-[160px] flex-1 bg-transparent py-1 text-sm outline-none"
        @keydown="onKeydown"
        @blur="onBlur"
        @paste="onPaste"
        @focus="input.trim() && fetchSuggestions(input.trim())"
      />
    </div>

    <ul
      v-if="open && suggestions.length"
      class="absolute left-0 top-full z-30 mt-1 max-h-64 w-full max-w-md overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1 shadow-xl dark:border-neutral-700 dark:bg-neutral-900"
    >
      <li
        v-for="(s, i) in suggestions"
        :key="s.email"
        class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm"
        :class="i === highlighted ? 'bg-sovera-100 dark:bg-sovera-950' : 'hover:bg-neutral-100 dark:hover:bg-neutral-800'"
        @mousedown.prevent="add(format(s))"
        @mouseenter="highlighted = i"
      >
        <span
          class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold text-white"
          :style="{ background: avatarColor(s.email) }"
        >
          {{ initials(s.name, s.email) }}
        </span>
        <span class="min-w-0 flex-1">
          <span v-if="s.name" class="block truncate">{{ s.name }}</span>
          <span class="block truncate text-xs text-neutral-500">{{ s.email }}</span>
        </span>
      </li>
    </ul>
  </div>
</template>
