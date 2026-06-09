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
      // Provided by WordPress (wp-i18n script) so wp_set_script_translations works.
      external: ['@wordpress/i18n'],
      output: {
        format: 'iife', // Wrap in IIFE to avoid global scope pollution
        globals: { '@wordpress/i18n': 'wp.i18n' },
        // Stable (no content hash) so the md5-of-path used by
        // wp_set_script_translations stays constant across builds; cache-busting
        // is handled by the ?ver=VERSION query arg added in PHP.
        entryFileNames: 'assets/fswa-admin.js',
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
