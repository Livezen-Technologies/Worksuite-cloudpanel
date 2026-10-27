/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Dev proxy targets (overridable for parallel e2e stacks).
declare const process: { env: Record<string, string | undefined> }
const API_PROXY = process.env.DMP_API_PROXY ?? 'http://localhost:8000'
const RELAY_PROXY = process.env.DMP_RELAY_PROXY ?? 'http://localhost:8090'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: { chunkSizeWarningLimit: 800 },
  server: {
    proxy: {
      '/api': { target: API_PROXY, changeOrigin: true },
      // Terminal relay (agent/cmd/relay); Host is kept so the relay's same-origin check passes.
      '/relay': { target: RELAY_PROXY, ws: true, changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
