import tailwindcss from '@tailwindcss/vite'

// Sovera Mail UI. Runs as a pure SPA in the browser, served by `nuxi dev`
// inside Docker (http://localhost:3000).
export default defineNuxtConfig({
  compatibilityDate: '2026-09-01',
  ssr: false,
  devtools: { enabled: false },
  telemetry: false,

  app: {
    baseURL: '/',
    head: {
      title: 'Sovera Mail',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'color-scheme', content: 'light dark' },
      ],
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/icon.svg' }],
    },
  },

  css: ['~/assets/css/main.css'],

  vite: {
    plugins: [tailwindcss()],
    server: {
      watch: process.env.CHOKIDAR_USEPOLLING === 'true' ? { usePolling: true, interval: 500 } : undefined,
    },
  },

  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8090',
      apiKey: '',
      appVersion: '1.0.0',
    },
  },

  nitro: {
    // `nuxi generate` writes a static build into .output/public.
    preset: 'static',
  },

  typescript: {
    strict: true,
    typeCheck: false,
  },
})
