<script setup lang="ts">
import type { Account } from '~/types/api'

const props = withDefaults(defineProps<{ account: Account; size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' })

const sizeClass = computed(() => ({ sm: 'h-5 w-5 text-[10px]', md: 'h-8 w-8 text-xs', lg: 'h-12 w-12 text-base' })[props.size])
const failed = ref(false)
</script>

<template>
  <img
    v-if="account.avatar_url && !failed"
    :src="account.avatar_url"
    :alt="account.email"
    referrerpolicy="no-referrer"
    class="shrink-0 rounded-full object-cover"
    :class="sizeClass"
    @error="failed = true"
  />
  <span
    v-else
    class="flex shrink-0 items-center justify-center rounded-full font-semibold text-white"
    :class="sizeClass"
    :style="{ background: avatarColor(account.email) }"
  >
    {{ initials(account.display_name, account.email) }}
  </span>
</template>
