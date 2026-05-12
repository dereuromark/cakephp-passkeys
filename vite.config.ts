import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';
import { resolve } from 'path';

const entries = ['core', 'attributes', 'errors', 'bundle', 'react', 'vue', 'alpine', 'stimulus'];

export default defineConfig({
    plugins: [dts({ rollupTypes: false, entryRoot: 'js/src' })],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        lib: {
            entry: Object.fromEntries(
                entries.map((e) => [e, resolve(__dirname, `js/src/${e}.ts`)])
            ),
            formats: ['es'],
        },
        rollupOptions: {
            external: ['react', 'vue', 'alpinejs', '@hotwired/stimulus'],
        },
        minify: 'esbuild',
        sourcemap: true,
    },
    test: {
        environment: 'happy-dom',
        globals: false,
        include: ['js/tests/**/*.test.ts'],
    },
});
