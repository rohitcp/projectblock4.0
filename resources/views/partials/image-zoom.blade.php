{{-- Image zoom — the shared posted-image component.

     Gives every image inside a `.wi-rich` or `.wk-read` body its 50–75% width, a zoom control
     and a lightbox. Include it on any screen that renders stored rich text, wherever an editor
     writes it: work items, wiki, project pages, drafts, help centre.

     Nothing to order it against. The script is self-contained, delegates its clicks from the
     document and watches for bodies that arrive later, so it does not care whether it runs
     before or after Vue, Quill, Lexical or Jodit. Its styles ship in assets/css/styles.css,
     which every screen already loads — so this tag is the whole dependency. --}}
<script defer src="{{ pb_asset('assets/js/image-zoom.js') }}"></script>
