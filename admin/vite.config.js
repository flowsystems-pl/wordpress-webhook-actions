import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: 'dist',
    manifest: true,
    cssCodeSplit: false, // Bundle all CSS into single file
    rollupOptions: {
      input: resolve(__dirname, 'src/main.js'),
      output: {
        format: 'iife', // Wrap in IIFE to avoid global scope pollution
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]',
        // Disable code splitting - bundle everything into single file
        manualChunks: undefined,
        inlineDynamicImports: true,
      },
    },
    // Extract CSS to separate file instead of injecting into JS
    cssMinify: true,
  },
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
  },
})
