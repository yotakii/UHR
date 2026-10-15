import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// داخل Docker: الـ API على http://api:8000 — محلياً بدون Docker: http://localhost:8180
const apiTarget = process.env.VITE_API_PROXY ?? 'http://localhost:8180'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // ويندوز + Docker: مراقبة التغييرات بالـ polling حتى يشتغل الـ hot reload
    watch: process.env.CHOKIDAR_USEPOLLING === 'true' ? { usePolling: true, interval: 300 } : undefined,
    proxy: {
      '/api': { target: apiTarget, changeOrigin: true },
      // خريطة الموقع لمحركات البحث (SRS §10.15)
      '/sitemap.xml': { target: apiTarget, changeOrigin: true, rewrite: () => '/api/public/sitemap.xml' },
    },
  },
})
