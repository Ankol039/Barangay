import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  base: '/barangay_survey_system/app/',
  plugins: [react()],
  server: {
    proxy: {
      // Header photos stay in the existing PHP uploads directory. Preserve the
      // production URL during development rather than copying account photos.
      '^/barangay_survey_system/(?:uploads|assets)/': {
        target: 'http://localhost',
        changeOrigin: true,
      },
      '^/api(?:/|\\?|$)': {
        target: 'http://localhost',
        changeOrigin: true,
        rewrite: (path) => `/barangay_survey_system${path}`,
      },
    },
  },
})
