# Lexical — vendored

The rich-text engine behind the **Wiki page editor**
(`docs/features/wiki-lexical-editor.md`). Wiki pages only: Project Pages still run Jodit and
work items, comments and status updates still run Quill.

Vendored rather than fetched, like every other dependency here: nothing comes from a CDN at
runtime, and this app links plain files with `pb_asset()`.

Unlike Echo, Quill, Jodit and Tabulator, **Lexical ships no browser global** — only ES modules.
So this file is not a copy; it is built. The entry
`resources/js/vendor/lexical-bundle.js` re-exports the subset the editor uses, and
`vite.lexical.config.js` compiles it to one IIFE exposing `window.PBLexical`.

Regenerate after `npm update`, or after adding an export to the entry:

    npx vite build --config vite.lexical.config.js

Adding a feature to the editor that needs a Lexical API it does not yet export is two steps:
export it from `resources/js/vendor/lexical-bundle.js`, then rebuild. The bundle deliberately
carries the subset and not the whole library.

Source: https://github.com/facebook/lexical (MIT) · playground: https://playground.lexical.dev/
