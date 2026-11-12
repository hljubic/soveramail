<script setup lang="ts">
/**
 * Renders HTML mail inside a sandboxed iframe.
 *  - no scripts, no forms, no plugins (sandbox attribute)
 *  - remote content blocked by CSP until the user opts in (privacy: no tracking pixels)
 *  - links open in a new browser tab
 */
import type { AttachmentMeta } from '~/types/api'

const props = defineProps<{ html: string; allowRemoteImages: boolean; attachments?: AttachmentMeta[] }>()
const emit = defineEmits<{ requestRemoteImages: [] }>()

const api = useApi()
const apiOrigin = new URL(api.base).origin

/**
 * Defence in depth on top of the sandbox + CSP: drop everything that could
 * navigate the frame (`<meta http-equiv="refresh">` works even without
 * scripts and would load an attacker's page), run code or submit data.
 * Head-level `<style>` blocks are kept, they are how HTML mail is laid out.
 */
const BLOCKED_ELEMENTS = 'script, meta, link, base, object, embed, applet, iframe, frame, frameset, form, input, button, select, textarea, template, noscript, area, map'
const URL_ATTRIBUTES = new Set(['href', 'src', 'xlink:href', 'action', 'formaction', 'poster', 'srcset', 'background', 'ping'])

function sanitize(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html')
  doc.querySelectorAll(BLOCKED_ELEMENTS).forEach(el => el.remove())
  doc.querySelectorAll('*').forEach((el) => {
    for (const attr of Array.from(el.attributes)) {
      const name = attr.name.toLowerCase()
      const value = attr.value.replace(/[\s\u0000-\u001f]/g, '').toLowerCase()
      if (name.startsWith('on') || (URL_ATTRIBUTES.has(name) && /^(javascript|vbscript|data:text\/html|data:image\/svg)/.test(value))) {
        el.removeAttribute(attr.name)
      }
    }
  })
  const styles = Array.from(doc.head.querySelectorAll('style')).map(s => s.outerHTML).join('')
  return styles + doc.body.innerHTML
}

/** Inline images: rewrite `cid:` references to the local attachment endpoint. */
const htmlWithInlineImages = computed(() => {
  const safe = sanitize(props.html)
  const byCid = new Map<string, string>()
  for (const a of props.attachments ?? []) {
    const u = api.assetUrl(a.url)
    if (a.content_id && u) byCid.set(a.content_id.toLowerCase(), u)
  }
  if (!byCid.size) return safe
  return safe.replace(/(src\s*=\s*["']?)cid:([^"'\s>]+)/gi, (whole, prefix: string, cid: string) => {
    const url = byCid.get(decodeURIComponent(cid).toLowerCase())
    return url ? `${prefix}${url}` : whole
  })
})
const frame = ref<HTMLIFrameElement | null>(null)
const height = ref(400)

const hasRemoteImages = computed(() => /<img[^>]+src\s*=\s*["']?\s*https?:/i.test(props.html) || /url\(\s*["']?https?:/i.test(props.html))

const isDark = () => document.documentElement.classList.contains('dark')

const srcdoc = computed(() => {
  const imgSrc = props.allowRemoteImages ? `img-src * data: cid: ${apiOrigin}; font-src *; ` : `img-src data: cid: ${apiOrigin}; `
  const csp = `default-src 'none'; ${imgSrc}style-src 'unsafe-inline'; frame-src 'none'; form-action 'none'; base-uri 'none';`
  const dark = isDark()
  return `<!doctype html><html><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="${csp}">
<base target="_blank">
<style>
  :root { color-scheme: ${dark ? 'dark' : 'light'}; }
  html, body { margin: 0; padding: 0; }
  body { padding: 4px 0 16px; font: 14px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: ${dark ? '#e5e5e5' : '#171717'}; background: transparent; word-wrap: break-word; overflow-wrap: anywhere; }
  img { max-width: 100%; height: auto; }
  table { max-width: 100%; }
  pre { white-space: pre-wrap; }
  blockquote { border-left: 3px solid ${dark ? '#404040' : '#d4d4d4'}; margin: 8px 0; padding-left: 12px; color: ${dark ? '#a3a3a3' : '#525252'}; }
  a { color: #3366ff; }
</style></head><body>${htmlWithInlineImages.value}</body></html>`
})

function resize() {
  const doc = frame.value?.contentDocument
  if (!doc?.body) return
  height.value = Math.max(200, Math.min(doc.documentElement.scrollHeight, doc.body.scrollHeight) + 8)
}

function onLoad() {
  const doc = frame.value?.contentDocument
  if (!doc) return
  resize()
  // Images loading later change the height.
  doc.querySelectorAll('img').forEach(img => img.addEventListener('load', resize))
  // Route link clicks to the system browser.
  doc.addEventListener('click', (ev) => {
    const a = (ev.target as HTMLElement | null)?.closest('a, area')
    if (!a) return
    ev.preventDefault()
    const href = a.getAttribute('href') || ''
    if (/^https?:\/\//i.test(href) || /^mailto:/i.test(href)) window.open(href, '_blank', 'noopener,noreferrer')
  })
}

onMounted(() => {
  const ro = new ResizeObserver(() => resize())
  if (frame.value) ro.observe(frame.value)
  onBeforeUnmount(() => ro.disconnect())
})
</script>

<template>
  <div class="flex h-full flex-col">
    <div
      v-if="hasRemoteImages && !allowRemoteImages"
      class="flex items-center gap-3 border-b border-amber-200 bg-amber-50 px-5 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/60 dark:text-amber-200"
    >
      <span class="flex-1">Remote images are blocked to protect your privacy.</span>
      <button class="rounded-md bg-amber-600 px-2.5 py-1 font-medium text-white hover:bg-amber-700" @click="emit('requestRemoteImages')">
        Load images
      </button>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto">
      <iframe
        ref="frame"
        :srcdoc="srcdoc"
        sandbox="allow-same-origin"
        referrerpolicy="no-referrer"
        title="Message body"
        class="block w-full border-0"
        :style="{ height: `${height}px` }"
        @load="onLoad"
      />
    </div>
  </div>
</template>
