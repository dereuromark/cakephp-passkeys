// Copies the IIFE build into the plugin's webroot, where CakePHP serves it as
// a plugin asset. The result is committed, so a composer install has it.
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const target = 'webroot/js';
mkdirSync(target, { recursive: true });

const bundle = readFileSync('dist/bundle.iife.js', 'utf8')
    .replace('sourceMappingURL=bundle.iife.js.map', 'sourceMappingURL=passkeys.min.js.map');
writeFileSync(`${target}/passkeys.min.js`, bundle);
copyFileSync('dist/bundle.iife.js.map', `${target}/passkeys.min.js.map`);
