import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => ({
  base: mode === 'production' ? '/front/dist/' : '/',
  plugins: [react()],
  server: {
    port: 5174,
    proxy: {
      '/api': 'http://127.0.0.1:8899',
      '/uploads': 'http://127.0.0.1:8899',
      '/login': 'http://127.0.0.1:8899',
      '/logout': 'http://127.0.0.1:8899',
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
  },
}));
