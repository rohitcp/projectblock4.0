/* The Lexical vendor bundle.
   ------------------------------------------------------------------
   Separate from vite.config.js on purpose: that config builds the app's own Vite entries, and
   this one produces a VENDORED FILE that is committed and linked with pb_asset(), exactly like
   echo.iife.js or jodit.fat.min.js. It is run by hand after `npm update`, not as part of a
   normal build — see public/assets/vendor/lexical/README.md.

       npx vite build --config vite.lexical.config.js
   ------------------------------------------------------------------ */
import { defineConfig } from 'vite';

export default defineConfig({
  // The output lives INSIDE public/, so Vite's own public-directory copy would try to copy
  // public/ into itself, forever. There is nothing to copy here anyway — this build emits one
  // file.
  publicDir: false,
  build: {
    lib: {
      entry: 'resources/js/vendor/lexical-bundle.js',
      // The global the Wiki editor reads. Namespaced under the app rather than called
      // `Lexical`, because it is OUR subset of Lexical, not the library's own surface.
      name: 'PBLexical',
      formats: ['iife'],
      fileName: () => 'lexical.iife.js'
    },
    outDir: 'public/assets/vendor/lexical',
    emptyOutDir: false,
    minify: true,
    target: 'es2019'
  }
});
