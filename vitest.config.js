import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    // Agent worktrees under .claude/ hold other checkouts with their own tests.
    include: ['tests/js/**/*.test.js'],
    environment: 'happy-dom',
    globals: true,
  },
});
