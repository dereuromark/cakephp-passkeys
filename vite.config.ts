import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';
import { resolve } from 'path';

const esmEntries = ['core', 'attributes', 'errors', 'react', 'vue', 'alpine', 'stimulus'];

// Two separate builds; vite picks one based on VITE_BUILD_MODE.
const mode = process.env.VITE_BUILD_MODE ?? 'esm';

export default defineConfig(mode === 'iife' ? {
    // IIFE bundle for the no-build / PHP-helper script tag path.
    build: {
        outDir: 'dist',
        emptyOutDir: false,
        lib: {
            entry: resolve(__dirname, 'js/src/bundle.ts'),
            formats: ['iife'],
            name: 'Passkeys',
            fileName: () => 'bundle.iife.js',
        },
        minify: 'esbuild',
        sourcemap: true,
    },
} : {
    plugins: [dts({ rollupTypes: false, entryRoot: 'js/src' })],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        lib: {
            entry: Object.fromEntries(esmEntries.map((e) => [e, resolve(__dirname, `js/src/${e}.ts`)])),
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
