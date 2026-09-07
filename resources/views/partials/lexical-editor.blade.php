{{-- The app's Lexical editor: <wk-editor> (docs/features/wiki-lexical-editor.md).

     One partial rather than the same five lines on each screen, because the set has an ORDER:
     the vendored bundle must be parsed before nodes.js defines the node classes, nodes.js
     before editor.js defines the component, and all three before the screen script that names
     <wk-editor> in its `components`. Getting that wrong on one page is how a screen ships with
     no editor at all.

     Include AFTER assets/js/icons.js (the toolbar borrows the app's own icons) and BEFORE the
     screen script.

     Two consumers today — the Wiki page, which uses the whole document editor, and the work
     item drawer, which passes `minimal` for a field-sized one. Same component either way: a
     second Lexical editor would be a second set of the same bugs. --}}

<script src="{{ pb_asset('assets/vendor/lexical/lexical.iife.js') }}"></script>

{{-- KaTeX typesets the Equation node. Not deferred: a node class reads window.katex while it
     draws itself, which happens as soon as a document containing one is opened. --}}
<script src="{{ pb_asset('assets/vendor/katex/katex.min.js') }}"></script>

<script defer src="{{ pb_asset('assets/js/lexical/nodes.js') }}"></script>
<script defer src="{{ pb_asset('assets/js/lexical/editor.js') }}"></script>

<link rel="stylesheet" href="{{ pb_asset('assets/css/lexical-editor.css') }}" />
