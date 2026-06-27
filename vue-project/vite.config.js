import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    port: 3000,
    proxy: {
      '/api.php': {
        target: 'http://localhost',
        changeOrigin: true
      },
      '/Captcha.php': {
        target: 'http://localhost',
        changeOrigin: true
      },
      '/uploads': {
        target: 'http://localhost',
        changeOrigin: true
      },
      '/attachments': {
        target: 'http://localhost',
        changeOrigin: true
      }
    }
  },
  build: {
    outDir: '../assets',
    emptyOutDir: true,
    assetsDir: '.'
  }
})