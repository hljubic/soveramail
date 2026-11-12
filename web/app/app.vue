<script setup lang="ts">
useHead({
  htmlAttrs: { class: 'h-full' },
  bodyAttrs: { class: 'h-full' },
})

// Theme: light by default, dark or "follow the system" from the preferences
// (the dark variant is class-based, see main.css).
const { prefs } = usePreferences()
onMounted(() => {
  const mq = window.matchMedia('(prefers-color-scheme: dark)')
  const apply = () => {
    const t = prefs.value.theme
    document.documentElement.classList.toggle('dark', t === 'dark' || (t === 'system' && mq.matches))
  }
  apply()
  mq.addEventListener('change', apply)
  watch(() => prefs.value.theme, apply)
})
</script>

<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
