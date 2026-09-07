# KaTeX — vendored

Renders the Wiki editor's **Equation** node (`docs/features/wiki-lexical-editor.md`).

Vendored rather than fetched, like every other dependency here: nothing comes from a CDN at
runtime. `katex.min.js` is the browser global build, so unlike Lexical it is a copy, not a
build.

**No stylesheet and no fonts are vendored, on purpose.** The editor renders with
`output: 'mathml'`, which hands the browser MathML and lets it typeset with its own maths
fonts. KaTeX's default HTML output is what needs `katex.min.css` and the ~60 woff2 files in
`dist/fonts` — a large amount of vendored binary for a node most pages will never contain.
If the HTML output is ever wanted, both have to come with it.

Regenerate after `npm update` with:

    cp node_modules/katex/dist/katex.min.js public/assets/vendor/katex/

Source: https://github.com/KaTeX/KaTeX (MIT)
