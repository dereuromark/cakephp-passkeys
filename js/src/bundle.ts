/**
 * Single-file bundle entry for the zero-build path.
 *
 * Vendored to webroot/dist/passkeys.min.js. Re-exports the public core API
 * for callers that want to import it from inline <script type="module">,
 * and imports ./attributes for its DOMContentLoaded side effect so plain
 * data-attribute markup works without any glue code.
 */

export * from './core';
export * from './errors';
import './attributes'; // auto-binds on DOMContentLoaded
