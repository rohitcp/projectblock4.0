/* Wiki › the Lexical editor.
   ------------------------------------------------------------------
   The Wiki page editor, built on Lexical (playground.lexical.dev) rather than on Jodit. See
   docs/features/wiki-lexical-editor.md for why, and for what is in this slice and what is not.

   SCOPE: Wiki pages only. Project Pages still mount <pg-editor> (Jodit) and work items,
   comments and status updates still mount <wi-editor> (Quill). Nothing else moved, so nothing
   else can regress.

   The component contract is deliberately IDENTICAL to <pg-editor>'s — same props, same events,
   same flush() — so the Wiki page swaps one tag for the other and the screen script does not
   change shape. That is also what makes going back a one-line edit if this turns out wrong.

   Lexical is a MODEL, not a contenteditable with rules bolted on: the document is a tree of
   nodes, the DOM is a projection of it, and every edit goes through a command. So there is no
   HTML to sanitize on the way in and out of the toolbar — only at the two edges, where
   $generateNodesFromDOM and $generateHtmlFromNodes translate to and from the stored HTML.
   ------------------------------------------------------------------ */

/** Is the vendored Lexical bundle actually loaded? (public/assets/vendor/lexical/README.md) */
function wkLexicalReady() {
  return typeof window !== 'undefined' && typeof window.PBLexical !== 'undefined';
}

/**
 * The sheets a wiki page can be laid out on (docs/features/wiki-page-format.md).
 *
 * Widths and heights are CSS pixels at 96dpi: A4 210×297mm, Letter 8.5×11in, Legal 8.5×14in.
 * `print` is the name the same sheet goes by in an `@page` rule, so a printed page comes out
 * the size it was written on.
 *
 * `paperless` is the absence of a sheet — it carries no dimensions on purpose, and every
 * branch reads that as "no page, no breaks, just a document that scrolls".
 */
var WK_PAGE_FORMATS = {
  a4: { label: 'A4', width: 794, height: 1123, print: 'A4' },
  letter: { label: 'Letter', width: 816, height: 1056, print: 'letter' },
  legal: { label: 'Legal', width: 816, height: 1344, print: 'legal' },
  paperless: { label: 'Paperless', width: null, height: null, print: 'A4' }
};

/** The sheet a format name stands for, or null for paperless and for anything unknown. */
function wkSheet(format) {
  var sheet = WK_PAGE_FORMATS[format];

  return sheet && sheet.height ? sheet : null;
}

/**
 * The class names Lexical hangs on the DOM it renders.
 *
 * Lexical does not ship styling: a theme is a map from node type to class name, and the CSS
 * behind those names is lexical-editor.css. Naming them here rather than styling bare tags is
 * what keeps a pasted `<b>` and a typed bold looking the same — both become the same node,
 * and the node decides the class.
 */
var WK_THEME = {
  paragraph: 'wk-p',
  quote: 'wk-quote',
  heading: { h1: 'wk-h1', h2: 'wk-h2', h3: 'wk-h3', h4: 'wk-h4', h5: 'wk-h5', h6: 'wk-h6' },
  list: {
    ul: 'wk-ul',
    ol: 'wk-ol',
    checklist: 'wk-check',
    listitem: 'wk-li',
    listitemChecked: 'wk-li--done',
    listitemUnchecked: 'wk-li--todo',
    nested: { listitem: 'wk-li-nested' }
  },
  code: 'wk-code-block',
  /* Prism's token classes, mapped to ours: the highlighter emits these names and the
     stylesheet colours them. Token TYPES, not languages — so a colour is a colour per
     meaning rather than per file extension, and a new language needs nothing here. */
  codeHighlight: {
    atrule: 'wk-tok-key', attr: 'wk-tok-attr', boolean: 'wk-tok-num', builtin: 'wk-tok-key',
    cdata: 'wk-tok-note', char: 'wk-tok-str', class: 'wk-tok-key', 'class-name': 'wk-tok-attr',
    comment: 'wk-tok-note', constant: 'wk-tok-num', deleted: 'wk-tok-str',
    doctype: 'wk-tok-note', entity: 'wk-tok-op', function: 'wk-tok-fn',
    important: 'wk-tok-key', inserted: 'wk-tok-str', keyword: 'wk-tok-key',
    namespace: 'wk-tok-attr', number: 'wk-tok-num', operator: 'wk-tok-op',
    prolog: 'wk-tok-note', property: 'wk-tok-attr', punctuation: 'wk-tok-punct',
    regex: 'wk-tok-str', selector: 'wk-tok-str', string: 'wk-tok-str', symbol: 'wk-tok-num',
    tag: 'wk-tok-key', url: 'wk-tok-op', variable: 'wk-tok-attr'
  },
  link: 'wk-link',
  text: {
    bold: 'wk-bold',
    italic: 'wk-italic',
    underline: 'wk-underline',
    strikethrough: 'wk-strike',
    code: 'wk-code'
  }
};

/**
 * A minimal image node — ROUND-TRIP ONLY.
 *
 * Same reason the table nodes are registered: Lexical drops any DOM it has no node for, and
 * the autosave then writes the document back WITHOUT it. A page with an image would lose the
 * image simply by being opened. This node exists so that cannot happen; it is not an image
 * feature. There is no insert button, no resize, no upload — those come with the follow-up
 * slice (docs/features/wiki-lexical-editor.md).
 *
 * Built lazily rather than at file scope because it extends a class that only exists once the
 * vendored bundle has parsed.
 */
/**
 * The gap between two sheets, in CSS pixels.
 *
 * Stated HERE and handed to the stylesheet as a custom property rather than written in both:
 * the pagination pass does arithmetic with it, and a gap that the CSS and the JS disagree
 * about puts every page boundary out by exactly that disagreement.
 */
var WK_PAGE_GAP = 30;

/**
 * The type faces and sizes the toolbar offers.
 *
 * Applied as inline `font-family` / `font-size` on the selected text, which is what makes them
 * survive a round trip through stored HTML — a class would need the reader's stylesheet to
 * know about it, and the read-only view renders the same markup outside this editor.
 */
var WK_FONTS = [
  { label: 'Default', value: '' },
  { label: 'Inter', value: 'Inter, ui-sans-serif, system-ui, sans-serif' },
  { label: 'Georgia', value: 'Georgia, "Times New Roman", serif' },
  { label: 'Times', value: '"Times New Roman", Times, serif' },
  { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
  { label: 'Courier', value: '"Courier New", Courier, monospace' }
];

var WK_SIZES = ['', '11px', '12px', '13px', '15px', '17px', '20px', '24px', '32px', '40px'];

/**
 * The colour palette, for both text and highlight.
 *
 * A fixed palette rather than a native colour input: `<input type="color">` opens an operating
 * system dialog, which is a modal over the page and cannot be styled or previewed. Ten choices
 * and a Clear cover what a document needs.
 */
/**
 * The highlight colours (docs/features/wiki-comments.md).
 *
 * A named set rather than the text palette: a highlight sits BEHIND words and has to leave
 * them readable, which rules out most of the colours a piece of text can be.
 */
var WK_HIGHLIGHTS = [
  { key: 'yellow', label: 'Yellow', value: '#fef08a' },
  { key: 'green', label: 'Green', value: '#bbf7d0' },
  { key: 'blue', label: 'Blue', value: '#bfdbfe' },
  { key: 'pink', label: 'Pink', value: '#fbcfe8' },
  { key: 'purple', label: 'Purple', value: '#e9d5ff' }
];

var WK_COLORS = [
  '', '#1f2328', '#6b7280', '#b91c1c', '#c2410c', '#a16207',
  '#15803d', '#0f766e', '#1b5f8a', '#4338ca', '#a21caf'
];

var WkEditor = {
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Write…' },
    minHeight: { type: String, default: '420px' },
    disabled: { type: Boolean, default: false },
    /**
     * Where images go.
     *
     * Accepted and ignored in this slice — images, tables and `@` mentions are the follow-up
     * (docs/features/wiki-lexical-editor.md). The prop stays in the signature so the screen
     * that passes it does not change when they land.
     */
    mediaUpload: { type: String, default: '' },
    /**
     * A CSS selector for an element to render the toolbar into.
     *
     * Teleported rather than moved: unlike Jodit's toolbar, this one is Vue's own markup, so
     * Vue must keep owning it — a button's active state changes with every cursor move.
     */
    toolbarHost: { type: String, default: '' },
    /**
     * A FIELD, not a document (docs/features/wiki-lexical-editor.md).
     *
     * The work item drawer needs the same editor at a fraction of its size: bold, lists, a
     * link, an image, and nothing else. `minimal` is what turns the document furniture off —
     * page formats, the Insert menu, the block dropdown, comments, pagination — and renders
     * the toolbar inline above the field instead of teleporting it into a screen-wide bar.
     *
     * A PROP rather than a second component, because a second Lexical editor is a second copy
     * of the same mount, the same HTML in-and-out and the same node registration, free to
     * drift the first time one of them is fixed.
     */
    minimal: { type: Boolean, default: false },
    /** Lay the text out on a page rather than filling a panel. */
    documentView: { type: Boolean, default: true },
    /** Which sheet: 'a4' | 'letter' | 'legal' | 'paperless'. */
    pageFormat: { type: String, default: 'a4' },
    /**
     * The formats the picker offers, from the server's own list (WikiPage::PAGE_FORMATS).
     *
     * Passed in rather than read from WK_PAGE_FORMATS so the toolbar can only offer what the
     * server will accept — the two lists are allowed to differ, and when they do it is the
     * server's that is right.
     */
    pageFormats: { type: Array, default: function () { return ['a4', 'letter', 'legal', 'paperless']; } },
    /**
     * The comment threads on this page, from the server (docs/features/wiki-comments.md).
     *
     * Passed IN rather than fetched here: the sidebar owns them, the socket updates them, and
     * an editor that fetched its own copy would be a second version of the truth to keep in
     * step. All the editor needs from them is which uids are open and which one is selected.
     */
    threads: { type: Array, default: function () { return []; } },
    /** The thread whose passage should read as selected right now. */
    activeThread: { type: String, default: '' },
    /**
     * Where to look people up when somebody types `@`.
     *
     * Absent means no mentions at all rather than a popup that never fills — a screen without
     * an endpoint should not offer the feature.
     */
    mentionUrl: { type: String, default: '' },
    /** How wide the text runs when there is no sheet to hold it (paperless). */
    contentWidth: { type: String, default: '820px' }
  },
  emits: ['update:modelValue', 'update:pageFormat', 'blur', 'comment', 'open-thread', 'anchors'],
  data: function () {
    return {
      ready: false,
      empty: true,
      /* What the caret is currently sitting in, so the toolbar can say so. Rebuilt on every
         selection change — never toggled by the click that caused it, because the document is
         the source of truth and a button that lies about state is worse than no button. */
      active: { bold: false, italic: false, underline: false, strikethrough: false },
      block: 'paragraph',
      align: '',
      isLink: false,
      canUndo: false,
      canRedo: false,
      /* What the caret's own text is set to, so the two selects show the truth rather than a
         default. Empty means "whatever the document's own styling says". */
      font: '',
      size: '',
      color: '',
      highlight: '',
      link: { open: false, url: '' },
      /* One popover at a time — a colour grid and the insert-table form cannot both be open,
         and naming which is open is simpler than a flag each. */
      panel: '',
      menu: false,
      table: { rows: 3, cols: 3 },
      image: { url: '', alt: '' },
      embed: { provider: 'youtube', url: '' },
      poll: { question: '', options: 'Yes\nNo' },
      layout: { columns: 2 },
      collapsible: { title: '' },
      equation: { tex: '', inline: false },
      sticky: { color: 'yellow' },
      date: { value: '' },
      uploading: false,
      /* A file is being dragged over the editor — see dragOverEditor. */
      dragging: false,
      /* The floating toolbar that appears over a selection. `at` is where, in page
         coordinates; empty means there is no selection worth offering anything for. */
      float: { open: false, at: null },
      /* The `@` popup. `query` is what has been typed after the `@`, `at` is where the caret
         was when it was typed, and `index` is which row the keyboard is on. */
      mention: { open: false, query: '', users: [], index: 0, at: null, loading: false },
      /* A comment being written before its thread exists. `uid` is minted here so the mark and
         the thread can be created in one round trip — see the controller's note on why. */
      draft: { open: false, body: '', quote: '', uid: '' }
    };
  },
  computed: {
    /* The sheet, as CSS custom properties. lexical-editor.css reads these rather than carrying a
       rule per format — a new paper size is then a line in WK_PAGE_FORMATS and nothing else. */
    docStyle: function () {
      var sheet = wkSheet(this.pageFormat);
      var style = { '--wk-min-h': this.minHeight, '--wk-content-w': this.contentWidth };

      if (sheet) {
        style['--wk-page-w'] = sheet.width + 'px';
        style['--wk-page-h'] = sheet.height + 'px';
        style['--wk-page-gap'] = WK_PAGE_GAP + 'px';
      }

      return style;
    },
    docClass: function () {
      var base = !this.documentView
        ? 'wk-doc wk-doc--plain'
        : (wkSheet(this.pageFormat) ? 'wk-doc wk-doc--paged' : 'wk-doc wk-doc--paperless');

      return this.dragging ? base + ' wk-doc--dropping' : base;
    },
    /** The providers the embed panel offers, as a list the template can loop. */
    embedProviders: function () {
      return Object.keys(WK_EMBEDS).map(function (key) {
        return { key: key, label: WK_EMBEDS[key].label, placeholder: WK_EMBEDS[key].placeholder };
      });
    },
    embedPlaceholder: function () {
      var spec = WK_EMBEDS[this.embed.provider];

      return spec ? spec.placeholder : 'Link';
    },
    stickyColors: function () { return WK_STICKY_COLORS; },
    highlights: function () { return WK_HIGHLIGHTS; },
    /* Under the caret, in viewport coordinates — the caret rectangle is measured that way and
       converting it would have to be undone on every scroll. */
    mentionStyle: function () {
      if (!this.mention.at) return { display: 'none' };

      return {
        position: 'fixed',
        top: this.mention.at.top + 'px',
        left: this.mention.at.left + 'px',
        zIndex: 140
      };
    },
    /* Where the floating toolbar goes. `fixed`, because it is measured from the viewport —
       the selection rectangle is, and converting to page coordinates would have to be undone
       on every scroll. */
    floatStyle: function () {
      if (!this.float.at) return { display: 'none' };

      return {
        position: 'fixed',
        top: Math.max(8, this.float.at.top) + 'px',
        left: this.float.at.left + 'px',
        transform: 'translateX(-50%)',
        zIndex: 130
      };
    },
    /** The format in force, by name — what the toolbar button says. */
    formatLabel: function () {
      var sheet = WK_PAGE_FORMATS[this.pageFormat];

      return sheet ? sheet.label : 'A4';
    },
    formatOptions: function () {
      return this.pageFormats.map(function (key) {
        var sheet = WK_PAGE_FORMATS[key];

        return {
          key: key,
          label: sheet ? sheet.label : key,
          hint: key === 'paperless'
            ? 'One continuous document, no page breaks'
            : 'Pages with automatic page breaks'
        };
      });
    },
    /**
     * Everything the Insert menu offers.
     *
     * One list rather than a row of fifteen buttons: a document editor's insert set grows, and
     * a toolbar that grows with it stops being readable at about eight. `panel` means "opens a
     * form first"; the rest go straight in.
     *
     * Image is NOT here: it is the one people reach for constantly, so it has its own button
     * on the toolbar. It is not in both places — a second way to do the same thing is a second
     * thing to keep in step.
     */
    insertItems: function () {
      // A page break in a comment is a break in nothing: `minimal` has no sheet, no pagination
      // and nothing to print, so the one item that cannot mean anything there is left out
      // rather than left to do nothing when pressed.
      var minimal = this.minimal;

      return [
        { key: 'table', label: 'Table', icon: 'table', panel: 'table' },
        { key: 'embed', label: 'Embed — YouTube, Figma, Excalidraw, X', icon: 'play', panel: 'embed' },
        { key: 'poll', label: 'Poll', icon: 'list-ul', panel: 'poll' },
        { key: 'layout', label: 'Columns layout', icon: 'columns', panel: 'layout' },
        { key: 'collapsible', label: 'Collapsible container', icon: 'chevron-down', panel: 'collapsible' },
        { key: 'equation', label: 'Equation', icon: 'text-size', panel: 'equation' },
        { key: 'sticky', label: 'Sticky note', icon: 'note-sticky', panel: 'sticky' },
        { key: 'date', label: 'Date', icon: 'calendar', panel: 'date' },
        { key: 'rule', label: 'Horizontal rule', icon: 'sort', run: 'insertRule' },
        { key: 'pagebreak', label: 'Page break', icon: 'bars-thin', run: 'insertPageBreak' }
      ].filter(function (item) { return !(minimal && item.key === 'pagebreak'); });
    },
    fonts: function () { return WK_FONTS; },
    sizes: function () { return WK_SIZES; },
    colors: function () { return WK_COLORS; },
    /**
     * Every block a paragraph can be turned into.
     *
     * ONE list, read by the dropdown and by setBlock, so the two cannot drift. A dropdown
     * rather than a button each: eleven block types is more than a toolbar can show, and the
     * one in force is worth naming rather than leaving you to work out which button is lit.
     *
     * "Normal" IS the paragraph — the source list named it twice, once as Normal and once as
     * P, and two entries that do the same thing is a menu you have to stop and think about.
     */
    blockOptions: function () {
      return [
        { key: 'paragraph', label: 'Normal', glyph: '¶' },
        { key: 'h1', label: 'Heading 1', glyph: 'H1' },
        { key: 'h2', label: 'Heading 2', glyph: 'H2' },
        { key: 'h3', label: 'Heading 3', glyph: 'H3' },
        { key: 'h4', label: 'Heading 4', glyph: 'H4' },
        { key: 'h5', label: 'Heading 5', glyph: 'H5' },
        { key: 'ol', label: 'Numbered list', icon: 'list-ol' },
        { key: 'ul', label: 'Bullet list', icon: 'list-ul' },
        { key: 'check', label: 'Check list', icon: 'check' },
        { key: 'quote', label: 'Quote', glyph: '”' },
        { key: 'code', label: 'Code block', glyph: '</>' }
      ];
    },
    /** What the dropdown's button says — the block the caret is actually in. */
    blockLabel: function () {
      var self = this;
      var current = this.blockOptions.filter(function (b) { return b.key === self.block; });

      return current.length ? current[0].label : 'Normal';
    }
  },
  mounted: function () {
    if (!wkLexicalReady() || !this.$refs.doc) return;

    var L = window.PBLexical;
    var self = this;

    this.editor = L.createEditor({
      namespace: 'wiki-page',
      theme: WK_THEME,
      editable: this.disabled !== true,
      // Every node type the document can contain has to be declared up front, or Lexical
      // refuses to create one — which is how a missing node shows up as "the heading button
      // does nothing" rather than as an error.
      // Lexical's own nodes, then this editor's (lexical/nodes.js). Registration is not
      // only about what the toolbar can INSERT: an unregistered node type is dropped on import
      // and then written back missing by the autosave, so a page would lose a table, an image
      // or an equation simply by being opened.
      nodes: [
        L.HeadingNode, L.QuoteNode, L.ListNode, L.ListItemNode, L.LinkNode, L.AutoLinkNode,
        L.TableNode, L.TableRowNode, L.TableCellNode,
        // CodeHighlightNode is not optional next to CodeNode: the highlighter builds one per
        // token, and an unregistered node type takes the editor down the first time somebody
        // types inside a code block.
        L.CodeNode, L.CodeHighlightNode
      ].concat(wkNodeClasses()),
      // A node that fails to reconcile must not take the editor down with it: the page keeps
      // working, and the failure is in the console rather than in a blank screen.
      onError: function (error) { console.error('[wiki-editor]', error); }
    });

    this.editor.setRootElement(this.$refs.doc);

    // Capture phase, so the `@` popup gets Enter and the arrows before Lexical does.
    this._onKeys = function (event) { self.onMentionKeys(event); };
    this.$refs.doc.addEventListener('keydown', this._onKeys, true);

    /* Image files pasted or dropped in.
       ------------------------------------------------------------------
       A native CAPTURE listener on the root element, not a PASTE_COMMAND handler. Lexical's
       rich-text registration has its own file-paste path, and a command listener — even at
       CRITICAL — does not stop it: the upload lands AND a second, bare `<img>` with no `src`
       appears beside it. Capture phase on the element itself runs before Lexical's listener,
       so `stopImmediatePropagation()` is what actually keeps the paste to one image.

       Only when files were actually taken. An ordinary text or HTML paste falls straight
       through to the handler that knows how to deal with it. */
    this._onPaste = function (event) {
      if (self.pasteFiles(event)) event.stopImmediatePropagation();
    };
    this._onDrop = function (event) {
      self.dragging = false;
      if (self.pasteFiles(event)) event.stopImmediatePropagation();
    };
    /* Chrome only treats an element as a drop target when the drag is prevented on BOTH
       `dragenter` AND `dragover`. With only dragover the cursor says "no", the drop event
       never fires, and the browser navigates away to the file instead — which looks exactly
       like "drag and drop does not work in the editor". */
    this._onDragEnter = function (event) { self.dragOverEditor(event, true); };
    this._onDragOver = function (event) { self.dragOverEditor(event, true); };
    this._onDragLeave = function (event) {
      // Only when the pointer has actually left the editor, not on the way past a paragraph
      // inside it — dragleave fires for every child boundary crossed.
      if (!self.$refs.doc || self.$refs.doc.contains(event.relatedTarget)) return;
      self.dragging = false;
    };

    this.$refs.doc.addEventListener('paste', this._onPaste, true);
    this.$refs.doc.addEventListener('drop', this._onDrop, true);
    this.$refs.doc.addEventListener('dragenter', this._onDragEnter, true);
    this.$refs.doc.addEventListener('dragover', this._onDragOver, true);
    this.$refs.doc.addEventListener('dragleave', this._onDragLeave, true);

    // registerRichText is what makes this an editor rather than a text box: Enter, Backspace,
    // selection, drag, and the paste handling that keeps a pasted document's structure.
    this.unregister = L.mergeRegister(
      L.registerRichText(this.editor),
      L.registerList(this.editor),
      // A check list's boxes are drawn by CSS but ticked by this — without it they are
      // decoration.
      L.registerCheckList(this.editor),
      // Tokenises a code block's contents as you type. Without it a code block is a
      // monospaced box and nothing more.
      L.registerCodeHighlighting(this.editor),
      // Insert table needs both: the plugin handles the command and keeps a table's shape
      // valid, the observer handles selecting across cells with the mouse and the keyboard.
      L.registerTablePlugin(this.editor),
      L.registerTableSelectionObserver(this.editor),
      // Lexical's own registerLink is @internal in 0.50 and wants signal stores, not options —
      // so the command is registered here, around the public $toggleLink. Same behaviour, one
      // line, and nothing that depends on the library's private shape.
      this.editor.registerCommand(L.TOGGLE_LINK_COMMAND, function (payload) {
        L.$toggleLink(payload);

        return true;
      }, L.COMMAND_PRIORITY_EDITOR),
      L.registerHistory(this.editor, L.createEmptyHistoryState(), 300),
      this.editor.registerUpdateListener(function (payload) { self.onUpdate(payload); }),
      this.editor.registerCommand(L.CAN_UNDO_COMMAND, function (can) {
        self.canUndo = can;

        return false;
      }, L.COMMAND_PRIORITY_LOW),
      this.editor.registerCommand(L.CAN_REDO_COMMAND, function (can) {
        self.canRedo = can;

        return false;
      }, L.COMMAND_PRIORITY_LOW)
    );

    // The floating toolbar follows the browser's own selection, so it listens to the browser's
    // own event — Lexical's update listener fires for every keystroke too, which would mean
    // measuring a selection rectangle on each one.
    this._onSelect = function () {
      self.refreshFloat();
      // A caret moved by a click or an arrow key leaves an open popup pointing at nothing.
      self.refreshMention();
    };
    document.addEventListener('selectionchange', this._onSelect);

    // A zoom change re-lays-out the text without changing the document, and arrives as a
    // resize. Without this the margins stay where the old layout put them.
    this._onResize = function () { self.queuePaginate(); };
    window.addEventListener('resize', this._onResize);

    this.ready = true;

    // Content LAST, after the listeners — the same ordering lesson <pg-editor> records: a
    // throw while parsing must not cost the editor its wiring.
    if (this.modelValue) this.setHtml(this.modelValue);
  },
  beforeUnmount: function () {
    clearTimeout(this._syncTimer);
    clearTimeout(this._pageTimer);
    window.removeEventListener('resize', this._onResize);
    document.removeEventListener('selectionchange', this._onSelect);
    clearTimeout(this._mentionTimer);
    try {
      if (this.$refs.doc) {
        this.$refs.doc.removeEventListener('keydown', this._onKeys, true);
        this.$refs.doc.removeEventListener('paste', this._onPaste, true);
        this.$refs.doc.removeEventListener('drop', this._onDrop, true);
        this.$refs.doc.removeEventListener('dragenter', this._onDragEnter, true);
        this.$refs.doc.removeEventListener('dragover', this._onDragOver, true);
        this.$refs.doc.removeEventListener('dragleave', this._onDragLeave, true);
      }
    } catch (e) { /* the element is going away regardless */ }
    try {
      if (this.unregister) this.unregister();
      if (this.editor) this.editor.setRootElement(null);
    } catch (e) { /* the editor is going away regardless */ }
    this.unregister = null;
    this.editor = null;
  },
  watch: {
    // The page was replaced under us (a save refreshed it) — take the new value, but never
    // while the user is typing into it.
    modelValue: function (next) {
      if (!this.editor || this.isFocused()) return;
      if (next !== this.html()) this.setHtml(next || '');
    },
    // A new sheet is a new set of boundaries, so everything has to be measured again.
    pageFormat: function () { this.queuePaginate(); },
    activeThread: function () { this.paintActiveThread(); },
    threads: function () {
      // A resolved thread's mark is repainted, not removed: resolving hides a conversation
      // from the list, it does not rewrite the document underneath somebody.
      var self = this;
      this.$nextTick(function () { self.paintActiveThread(); });
    },
    contentWidth: function () { this.queuePaginate(); },
    disabled: function (next) {
      // Archiving a page makes it read-only without remounting the editor.
      try {
        if (this.editor) this.editor.setEditable(next !== true);
      } catch (e) { /* the editor stays as it was, which still reads */ }
    }
  },
  methods: {
    icon: function (name, size, cls) {
      return typeof wiIcon === 'function' ? wiIcon(name, size, cls) : '';
    },

    /* ---------- the document, in and out ---------- */

    /**
     * Read the document back as HTML.
     *
     * `read` rather than `update`: this must never be able to change the document it is
     * serialising, and Lexical enforces that by refusing writes inside a read.
     */
    html: function () {
      if (!this.editor) return '';

      var L = window.PBLexical;
      var self = this;
      var out = '';

      try {
        this.editor.getEditorState().read(function () {
          out = L.$generateHtmlFromNodes(self.editor, null);
        });
      } catch (e) { return ''; }

      // An empty document still serialises to an empty paragraph; normalise so an emptied
      // editor round-trips to "" rather than to markup the server would strip anyway.
      return out.replace(/<p[^>]*>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '').trim() === '' ? '' : out;
    },

    /**
     * Replace the document with the given HTML.
     *
     * Parsed with DOMParser into a detached document — never into this page — so nothing in
     * the stored markup can run, and no `<img>` or `<iframe>` in it can fetch anything while
     * it is being read. Lexical then takes only what its registered nodes recognise, which is
     * a second filter on top of the server's sanitizer.
     */
    setHtml: function (html) {
      if (!this.editor) return;

      var L = window.PBLexical;
      var self = this;

      try {
        this.editor.update(function () {
          var parsed = new DOMParser().parseFromString(html || '<p></p>', 'text/html');
          var nodes = L.$generateNodesFromDOM(self.editor, parsed);
          var root = L.$getRoot();

          root.clear();
          root.select();
          L.$insertNodes(nodes);
        }, { discrete: true });
      } catch (e) { /* a parse failure costs the pre-filled text, not the editor */ }

      this.refreshState();
      this.queuePaginate();
      this.reportAnchors();
    },

    /**
     * The document changed — tell the page, and re-read what the caret is in.
     *
     * Debounced like <pg-editor>, and for the same reason: a save clicked inside the window
     * would otherwise read the previous value. flush() closes that window.
     */
    onUpdate: function () {
      var self = this;

      this.refreshState();
      this.queuePaginate();
      clearTimeout(this._syncTimer);
      this._syncTimer = setTimeout(function () {
        self.emitValue();
        // In the SAME debounce as the sync, not on every keystroke: this walks the whole
        // document, and a comment anchor cannot change without the content changing too.
        self.reportAnchors();
        self.refreshMention();
        self.pruneRemoteImages();
      }, 120);
    },
    emitValue: function () {
      var html = this.html();
      if (html !== this.modelValue) this.$emit('update:modelValue', html);
    },
    /**
     * Push the current content to the model NOW.
     *
     * Typing syncs on a debounce, so a save inside that window reads the previous value and
     * concludes nothing changed — the edit looks discarded. Every save path calls this first.
     */
    flush: function () {
      if (!this.editor) return;
      clearTimeout(this._syncTimer);
      this.emitValue();
    },

    /* ---------- @mentions ---------- */

    /**
     * Is the caret sitting just after an `@` somebody is still typing?
     *
     * Read from the DOCUMENT rather than tracked from keystrokes: paste, undo, arrow keys and
     * a click all move the caret without a keypress, and a trigger remembered from typing goes
     * stale on every one of them.
     *
     * The `@` must start a word — `(^|\s)` — so an email address in the middle of a sentence
     * does not open a people picker.
     */
    mentionTrigger: function () {
      var L = window.PBLexical;
      var found = null;

      this.editor.getEditorState().read(function () {
        var sel = L.$getSelection();
        if (!L.$isRangeSelection(sel) || !sel.isCollapsed()) return;

        var node = sel.anchor.getNode();
        if (!L.$isTextNode(node) || node.getType() !== 'text') return;

        var before = node.getTextContent().slice(0, sel.anchor.offset);
        var match = /(?:^|\s)@([\p{L}\p{N}._-]{0,40})$/u.exec(before);

        if (match) found = { query: match[1], length: match[0].trimStart().length };
      });

      return found;
    },

    /**
     * Open, update or close the `@` popup, following the caret.
     *
     * Called from the same debounce as everything else that watches the document, so typing a
     * word does not mean a request per letter.
     */
    refreshMention: function () {
      if (!this.mentionUrl || !this.editor) return;

      var trigger = this.mentionTrigger();

      if (!trigger) {
        if (this.mention.open) this.closeMention();

        return;
      }

      // Positioned from the browser's own caret rectangle: the popup has to sit under where
      // the text is DRAWN, and only the DOM knows that.
      var at = this.mention.at;
      try {
        var sel = window.getSelection();
        if (sel && sel.rangeCount) {
          var rect = sel.getRangeAt(0).getBoundingClientRect();
          if (rect.height) at = { top: rect.bottom + 6, left: rect.left };
        }
      } catch (e) { /* keep the last position rather than jumping to the corner */ }

      var changed = !this.mention.open || this.mention.query !== trigger.query;

      this.mention = {
        open: true,
        query: trigger.query,
        users: this.mention.users,
        index: changed ? 0 : this.mention.index,
        at: at,
        loading: changed
      };

      if (changed) this.searchMentions(trigger.query);
    },

    closeMention: function () {
      this.mention = { open: false, query: '', users: [], index: 0, at: null, loading: false };
    },

    /**
     * Ask the server who matches, debounced.
     *
     * A lookup that fails must not stop somebody writing, so a failure closes the popup rather
     * than leaving it saying "Searching…" forever. `_mentionSeq` drops a slow answer that
     * arrives after a newer one — otherwise typing quickly shows the results for a prefix you
     * have already moved past.
     */
    searchMentions: function (query) {
      var self = this;
      var seq = (this._mentionSeq = (this._mentionSeq || 0) + 1);

      clearTimeout(this._mentionTimer);
      this._mentionTimer = setTimeout(function () {
        var url = self.mentionUrl + (self.mentionUrl.indexOf('?') > -1 ? '&' : '?')
          + 'search=' + encodeURIComponent(query || '');

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
          .then(function (data) {
            if (seq !== self._mentionSeq || !self.mention.open) return;
            self.mention = Object.assign({}, self.mention, {
              users: data.users || [], index: 0, loading: false
            });
          })
          .catch(function () {
            if (seq === self._mentionSeq) self.closeMention();
          });
      }, 180);
    },

    /**
     * Put the chosen person in, replacing the `@…` that was typed.
     *
     * The trigger text is SELECTED and then replaced, rather than spliced by index: splitting
     * a text node by offset gets the wrong characters the moment the text contains anything
     * outside the basic plane, and people's names routinely do.
     */
    pickMention: function (user) {
      if (!user || !this.editor) return;

      var L = window.PBLexical;
      var trigger = this.mentionTrigger();

      this.closeMention();
      if (!trigger) return;

      this.editor.update(function () {
        var sel = L.$getSelection();
        if (!L.$isRangeSelection(sel)) return;

        var node = sel.anchor.getNode();
        var end = sel.anchor.offset;
        var start = end - trigger.length;
        if (start < 0) return;

        sel.setTextNodeRange(node, start, node, end);
        // The trailing space is not decoration: without it the caret sits inside the chip and
        // the next character typed becomes part of somebody's name.
        sel.insertNodes([new WkNodes.mention(user.name, user.id), L.$createTextNode(' ')]);
      });

      this.editor.focus();
    },

    /**
     * The popup owns the arrow keys and Enter while it is open.
     *
     * Capture phase, ahead of Lexical's own handlers: Enter must choose a person rather than
     * break the paragraph, which is the one thing that makes a picker feel broken.
     */
    onMentionKeys: function (event) {
      if (!this.mention.open) return;

      var count = this.mention.users.length;

      if (event.key === 'Escape') {
        this.closeMention();
        event.preventDefault();
        event.stopPropagation();

        return;
      }

      if (!count) return;

      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        var step = event.key === 'ArrowDown' ? 1 : -1;
        this.mention.index = (this.mention.index + step + count) % count;
        event.preventDefault();
        event.stopPropagation();

        return;
      }

      if (event.key === 'Enter' || event.key === 'Tab') {
        this.pickMention(this.mention.users[this.mention.index]);
        event.preventDefault();
        event.stopPropagation();
      }
    },

    /* ---------- selection: the floating toolbar and comments ---------- */

    /**
     * Show or hide the floating toolbar over the current selection.
     *
     * Positioned from the BROWSER's own selection rectangle rather than from Lexical's model:
     * what the toolbar has to sit over is where the text is drawn, and only the DOM knows that.
     * Measured relative to the scroller so it travels with the document instead of detaching
     * the first time anybody scrolls.
     *
     * Hidden the moment the selection collapses. A toolbar that lingers over a caret is a
     * toolbar offering to highlight nothing.
     */
    refreshFloat: function () {
      var self = this;

      // A field has no comment layer and no highlight palette, so it has nothing to float.
      if (this.minimal) return;

      // After the browser has finished laying the selection out — reading it in the same tick
      // as the event that changed it gives you the previous one.
      setTimeout(function () {
        var sel = window.getSelection();
        var doc = self.$refs.doc;

        if (self.draft.open) return;

        if (!doc || !sel || sel.isCollapsed || sel.rangeCount === 0
          || !doc.contains(sel.anchorNode) || String(sel).trim() === '') {
          self.float = { open: false, at: null };

          return;
        }

        var rect = sel.getRangeAt(0).getBoundingClientRect();
        if (!rect.width && !rect.height) return;

        self.float = {
          open: true,
          at: { top: rect.top - 46, left: rect.left + (rect.width / 2) }
        };
      }, 0);
    },

    /**
     * Highlight the selection in a colour, or clear it.
     *
     * ORDINARY highlighting, which is `background-color` on the text and nothing more — no
     * thread, no anchor, no record. Deliberately a different mechanism from a comment mark, so
     * the two can sit on the same words without either erasing the other, and so removing a
     * highlight can never remove a conversation.
     */
    applyHighlight: function (color) {
      this.style('background-color', color);
      this.panel = '';
      this.float = { open: false, at: null };
    },

    /**
     * Begin a comment on the selection.
     *
     * The mark is NOT written yet. It goes in only once the comment is actually saved — a mark
     * written on "Add comment" and abandoned on Cancel is a highlight in the document
     * referencing a thread that never existed, and the next save would persist it.
     */
    startComment: function () {
      var L = window.PBLexical;
      var self = this;
      var quote = '';

      this.editor.getEditorState().read(function () {
        var sel = L.$getSelection();
        if (L.$isRangeSelection(sel)) quote = sel.getTextContent();
      });

      quote = quote.trim();
      if (!quote) return;

      // Kept so the mark can be applied to the same words after the comment box has taken
      // focus — the selection itself does not survive that.
      this._commentSelection = null;
      this.editor.getEditorState().read(function () {
        var sel = L.$getSelection();
        if (L.$isRangeSelection(sel)) self._commentSelection = sel.clone();
      });

      this.draft = {
        open: true,
        body: '',
        quote: quote,
        // Minted here, not by the server: the same id has to go into the document's <mark>,
        // and asking for one first would mean either a second round trip or a document saved
        // with a mark pointing at nothing.
        uid: 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8)
      };

      this.$nextTick(function () {
        if (self.$refs.draftBox) self.$refs.draftBox.focus();
      });
    },

    /** Hand the finished comment up to the page, which is what talks to the server. */
    submitComment: function () {
      var body = String(this.draft.body || '').trim();
      if (!body) return;

      this.$emit('comment', { uid: this.draft.uid, quote: this.draft.quote, body: body });
    },

    cancelComment: function () {
      this._commentSelection = null;
      this.draft = { open: false, body: '', quote: '', uid: '' };
      this.float = { open: false, at: null };
    },

    /**
     * Wrap the remembered selection in a comment mark.
     *
     * Called by the PAGE once the server has accepted the thread, so the document is only ever
     * marked for a thread that exists. Restoring the selection first is what makes the mark
     * land on the words the comment is about rather than wherever the caret drifted to.
     */
    applyCommentMark: function (uid) {
      if (!this.editor || !uid) return;

      var L = window.PBLexical;
      var saved = this._commentSelection;

      this._commentSelection = null;
      this.draft = { open: false, body: '', quote: '', uid: '' };
      this.float = { open: false, at: null };

      if (!saved) return;

      this.editor.update(function () {
        L.$setSelection(saved.clone());
        var sel = L.$getSelection();
        if (!L.$isRangeSelection(sel)) return;

        L.$wrapSelectionInMarkNode(sel, sel.isBackward(), uid, function (ids) {
          return new WkNodes.comment(ids);
        });
      });

      this.editor.focus();
    },

    /**
     * Take a thread's id out of the document.
     *
     * Only the ID is removed, not the mark: a passage can carry two threads, and unwrapping the
     * text because one of them was resolved would take the other's anchor with it.
     */
    removeCommentMark: function (uid) {
      if (!this.editor || !uid) return;

      var L = window.PBLexical;

      this.editor.update(function () {
        var walk = function (node) {
          if (L.$isMarkNode(node)) {
            var left = node.getIDs().filter(function (id) { return id !== uid; });

            if (left.length === 0) L.$unwrapMarkNode(node);
            else if (left.length !== node.getIDs().length) node.setIDs(left);

            return;
          }

          if (node.getChildren) node.getChildren().forEach(walk);
        };

        walk(L.$getRoot());
      });
    },

    /**
     * Which thread ids the document still carries, told to the page after every change.
     *
     * This is how "referenced text deleted" is discovered. The editor only REPORTS; the server
     * decides what that means, and nothing is ever deleted because of it.
     */
    reportAnchors: function () {
      if (!this.editor) return;

      var L = window.PBLexical;
      var found = {};

      this.editor.getEditorState().read(function () {
        var walk = function (node) {
          if (L.$isMarkNode(node)) {
            node.getIDs().forEach(function (id) { found[id] = true; });
          }
          if (node.getChildren) node.getChildren().forEach(walk);
        };

        walk(L.$getRoot());
      });

      this.$emit('anchors', Object.keys(found));
    },

    /**
     * A click landed in the document — if it was on a comment mark, say which thread.
     *
     * Read from the DOM rather than from the model: a click has coordinates, and the element
     * under them already carries the ids. The topmost thread wins when marks overlap, which is
     * the innermost `<mark>` and therefore the most specific passage.
     */
    onDocClick: function (event) {
      var mark = event.target && event.target.closest
        ? event.target.closest('mark[data-wk="comment"]')
        : null;

      if (!mark) return;

      var ids = String(mark.getAttribute('data-wk-threads') || '').split(/\s+/).filter(Boolean);
      if (ids.length) this.$emit('open-thread', ids[0]);
    },

    /** Paint the mark that belongs to the selected thread, and unpaint the rest. */
    paintActiveThread: function () {
      var doc = this.$refs.doc;
      if (!doc) return;

      var active = this.activeThread;

      Array.prototype.forEach.call(doc.querySelectorAll('mark[data-wk="comment"]'), function (el) {
        var ids = String(el.getAttribute('data-wk-threads') || '').split(/\s+/);
        el.classList.toggle('wk-comment--active', !!active && ids.indexOf(active) > -1);
      });
    },

    /* ---------- pagination ---------- */

    /**
     * Push blocks off the gutters, so no text sits in the gap between two sheets.
     *
     * The sheets are drawn as a repeating background (lexical-editor.css) because the document is
     * one continuous contenteditable — splitting it into an element per page is what costs you
     * the caret, the selection, and every table that spans a break. A background alone cannot
     * move text, though, so a paragraph landing on a boundary is drawn straight through the
     * gutter, and the gap reads as a stripe across the words.
     *
     * This closes that: measure each top-level block, and when one would cross the bottom of
     * the page it is on, give it a margin big enough to start the next one. Margins only —
     * nothing is restructured, nothing is re-parented, so the caret, the selection and the
     * undo history are untouched. It is pagination as far as the eye is concerned, and not one
     * step further.
     *
     * The margins live on the DOM, NOT in the Lexical model, so nothing here is saved: they
     * are cleared and recomputed on every pass, and a document that arrives on another screen
     * at another zoom is laid out for that screen.
     *
     * Deliberately NOT attempted for a block taller than a page: an image or a table that
     * cannot fit anywhere is pushed forever by a rule that says "move it down". Those straddle,
     * and that is the correct answer rather than an infinite loop.
     */
    paginate: function () {
      var doc = this.$refs.doc;
      if (!doc || this.minimal) return;

      var blocks = doc.children;
      var i;

      // Cleared FIRST and in one go: last pass's margins are part of what would be measured,
      // and a pass that measures its own output drifts a little further every time.
      for (i = 0; i < blocks.length; i++) blocks[i].style.marginTop = '';

      var sheet = this.documentView ? wkSheet(this.pageFormat) : null;
      if (!sheet) return;

      var box = window.getComputedStyle(doc);
      // Read rather than restated: the padding is the stylesheet's, and a second copy here is
      // a second thing to remember when the sheet's margins change.
      var padTop = parseFloat(box.paddingTop) || 0;
      var padBottom = parseFloat(box.paddingBottom) || 0;
      var unit = sheet.height + WK_PAGE_GAP;
      var usable = sheet.height - padTop - padBottom;
      var page = 0;

      for (i = 0; i < blocks.length; i++) {
        var el = blocks[i];
        var rect = el.getBoundingClientRect();

        // Measured against the SHEET's own box, not with offsetTop: offsetTop is relative to
        // whatever the nearest positioned ancestor happens to be, which is not this element,
        // so it carries the document's own position on the screen and puts every boundary out
        // by that much. The background tiles from the border-box top; so does this.
        var origin = doc.getBoundingClientRect().top;
        var top = rect.top - origin;
        var height = rect.height;

        // Catch up if the block already starts on a later page than the one being counted —
        // after a tall predecessor, or after an explicit break.
        while (top >= (page + 1) * unit) page++;

        var limit = (page * unit) + sheet.height - padBottom;

        if (height <= usable && top + height > limit) {
          page++;
          var push = (page * unit) + padTop - top;
          if (push > 0) el.style.marginTop = push + 'px';
        }

        // An explicit page break ends its page wherever it falls (lexical-nodes.js).
        if (el.classList && el.classList.contains('wk-pagebreak')) page++;
      }
    },

    /** Re-paginate soon, and only once however many changes arrive in the meantime. */
    queuePaginate: function () {
      var self = this;

      clearTimeout(this._pageTimer);
      this._pageTimer = setTimeout(function () { self.paginate(); }, 120);
    },

    /* ---------- what the caret is in ---------- */

    /**
     * Re-read the selection and restate the toolbar from it.
     *
     * Read from the DOCUMENT, never remembered from the click that changed it: a button that
     * says "bold" because you pressed bold, rather than because the caret is in bold text, is
     * wrong the moment you move the caret.
     */
    refreshState: function () {
      if (!this.editor) return;

      var L = window.PBLexical;
      var self = this;

      this.editor.getEditorState().read(function () {
        var root = L.$getRoot();
        self.empty = root.getTextContent().trim() === '' && root.getChildrenSize() <= 1;

        var sel = L.$getSelection();
        if (!L.$isRangeSelection(sel)) return;

        self.active = {
          bold: sel.hasFormat('bold'),
          italic: sel.hasFormat('italic'),
          underline: sel.hasFormat('underline'),
          strikethrough: sel.hasFormat('strikethrough'),
          subscript: sel.hasFormat('subscript'),
          superscript: sel.hasFormat('superscript'),
          code: sel.hasFormat('code')
        };

        // The style properties are read from the selection rather than remembered, so the two
        // selects and the two swatches say what the caret is actually sitting in.
        self.font = L.$getSelectionStyleValueForProperty(sel, 'font-family', '');
        self.size = L.$getSelectionStyleValueForProperty(sel, 'font-size', '');
        self.color = L.$getSelectionStyleValueForProperty(sel, 'color', '');
        self.highlight = L.$getSelectionStyleValueForProperty(sel, 'background-color', '');

        var node = sel.anchor.getNode();
        var top = node.getKey() === 'root' ? node : node.getTopLevelElementOrThrow();

        self.block = self.blockNameOf(node, top);
        self.align = (top.getFormatType && top.getFormatType()) || '';
        self.isLink = !!L.$findMatchingParent(node, function (n) { return L.$isLinkNode(n); });
      });
    },
    /** Which entry of blockOptions the caret is sitting in. */
    blockNameOf: function (node, top) {
      var L = window.PBLexical;
      var listName = function (list) {
        var type = list.getListType();

        return type === 'number' ? 'ol' : (type === 'check' ? 'check' : 'ul');
      };

      if (L.$isCodeNode(top)) return 'code';
      if (L.$isListNode(top)) return listName(top);

      // A list item's top-level element is the list, but a nested list sits inside an item —
      // so ask the item's own parent rather than trusting the top.
      var item = L.$getNearestNodeOfType(node, L.ListItemNode);
      if (item) {
        var list = item.getParent();
        if (list && L.$isListNode(list)) return listName(list);
      }

      if (L.$isHeadingNode(top)) return top.getTag();
      if (L.$isQuoteNode(top)) return 'quote';

      return 'paragraph';
    },
    isFocused: function () {
      try {
        return !!(this.$refs.doc && document.activeElement
          && (this.$refs.doc === document.activeElement || this.$refs.doc.contains(document.activeElement)));
      } catch (e) { return false; }
    },

    /* ---------- the toolbar ---------- */

    /** Inline formatting — bold, italic, underline, strikethrough. */
    format: function (which) {
      if (!this.editor) return;
      this.editor.dispatchCommand(window.PBLexical.FORMAT_TEXT_COMMAND, which);
      this.editor.focus();
    },
    /** Alignment. Passing the alignment already in force clears it, so the button toggles. */
    alignAs: function (which) {
      if (!this.editor) return;
      this.editor.dispatchCommand(
        window.PBLexical.FORMAT_ELEMENT_COMMAND,
        this.align === which ? '' : which
      );
      this.editor.focus();
    },
    /**
     * Turn the selected blocks into a paragraph, heading, quote or list.
     *
     * Choosing what is already in force goes back to body text, so the same button both
     * applies and removes — there is no separate "unheading".
     */
    setBlock: function (key) {
      if (!this.editor) return;

      var L = window.PBLexical;
      var target = this.block === key ? 'paragraph' : key;

      var LIST_COMMANDS = {
        ul: L.INSERT_UNORDERED_LIST_COMMAND,
        ol: L.INSERT_ORDERED_LIST_COMMAND,
        check: L.INSERT_CHECK_LIST_COMMAND
      };

      if (LIST_COMMANDS[target]) {
        this.editor.dispatchCommand(LIST_COMMANDS[target], undefined);
        this.editor.focus();
        this.panel = '';

        return;
      }

      // Coming OUT of a list is its own command — $setBlocksType cannot unwrap list items.
      if (LIST_COMMANDS[this.block]) {
        this.editor.dispatchCommand(L.REMOVE_LIST_COMMAND, undefined);
      }

      this.editor.update(function () {
        var sel = L.$getSelection();
        if (!L.$isRangeSelection(sel)) return;

        L.$setBlocksType(sel, function () {
          if (target === 'quote') return L.$createQuoteNode();
          if (target === 'code') return L.$createCodeNode();
          if (target === 'paragraph') return L.$createParagraphNode();

          return L.$createHeadingNode(target);
        });
      });

      this.panel = '';
      this.editor.focus();
    },
    /**
     * Set an inline CSS property on the selection — font, size, colour, highlight.
     *
     * Written as inline style rather than as a class because the stored HTML is rendered
     * OUTSIDE this editor too (the read-only view, and anything that shows a page's body): a
     * class would need every one of those to know about it, an inline style needs nobody.
     * An empty value clears the property rather than setting it to the string "".
     */
    style: function (property, value) {
      if (!this.editor) return;

      var L = window.PBLexical;
      var patch = {};
      patch[property] = value || null;

      this.editor.update(function () {
        var sel = L.$getSelection();
        if (L.$isRangeSelection(sel)) L.$patchStyleText(sel, patch);
      });

      this.editor.focus();
    },
    /** The colour grid, for text and for highlight. Closes itself once something is picked. */
    pickColor: function (value) {
      this.style(this.panel === 'highlight' ? 'background-color' : 'color', value);
      this.panel = '';
    },
    togglePanel: function (which) {
      this.panel = this.panel === which ? '' : which;
      this.menu = false;
      if (this.panel) this.link = { open: false, url: '' };
    },
    toggleMenu: function () {
      this.menu = !this.menu;
      if (this.menu) this.panel = '';
    },

    /** Indent and outdent. In a list these change the nesting level; elsewhere, the margin. */
    indent: function (out) {
      if (!this.editor) return;

      var L = window.PBLexical;
      this.editor.dispatchCommand(
        out ? L.OUTDENT_CONTENT_COMMAND : L.INDENT_CONTENT_COMMAND,
        undefined
      );
      this.editor.focus();
    },

    /** Insert a table of the size the little form asks for. */
    insertTable: function () {
      if (!this.editor) return;

      var rows = Math.min(50, Math.max(1, parseInt(this.table.rows, 10) || 1));
      var cols = Math.min(20, Math.max(1, parseInt(this.table.cols, 10) || 1));

      var editor = this.editor;
      this.panel = '';

      this.withCaret(function () {
        editor.dispatchCommand(window.PBLexical.INSERT_TABLE_COMMAND, {
          // Strings, not numbers: that is the shape INSERT_TABLE_COMMAND takes.
          rows: String(rows),
          columns: String(cols),
          includeHeaders: true
        });
      });
    },

    /**
     * Insert an image by URL.
     *
     * By URL and not by upload: this screen has no media endpoint yet (`mediaUpload` is empty
     * for wiki pages), so offering a file picker would be offering something that cannot
     * finish. Upload arrives with the endpoint.
     */
    insertImage: function () {
      var url = String(this.image.url || '').trim();
      var alt = String(this.image.alt || '').trim();

      this.panel = '';
      this.image = { url: '', alt: '' };
      if (!url || !this.editor) return;

      var L = window.PBLexical;
      this.insertNode(function () { return new WkNodes.image(url, alt); });
    },

    /**
     * Run something with a caret guaranteed to exist.
     *
     * Every insert goes through the selection, and a toolbar can be reached without the editor
     * ever having been focused — click Insert → Table in an untouched comment box and there is
     * no selection to insert into, so the command runs and silently does nothing. Pressing a
     * button and watching nothing happen is the worst failure mode: it reads as broken rather
     * than as refused.
     *
     * Lexical's OWN `focus(callback, {defaultSelection})` and not a hand-placed selection: a
     * selection set while the root element is unfocused does not survive the reconcile that
     * follows — the browser's selection is somewhere else entirely and Lexical drops it — so
     * the command dispatched in the next update still finds nothing. Running inside the focus
     * callback is what puts the work after the caret is real.
     */
    withCaret: function (run) {
      if (!this.editor) return;

      var L = window.PBLexical;

      try {
        // The ROOT ELEMENT's own focus(), not `editor.focus()`. Lexical's deliberately does
        // not steal DOM focus, so on an untouched editor it sets a selection that the next
        // reconcile drops — the command then runs against nothing. Focusing the
        // contenteditable is what makes the caret real.
        if (this.$refs.doc && document.activeElement !== this.$refs.doc) this.$refs.doc.focus();

        this.editor.update(function () {
          if (L.$isRangeSelection(L.$getSelection())) return;

          var last = L.$getRoot().getLastChild();
          if (last && last.selectEnd) last.selectEnd();
        }, { discrete: true });
      } catch (e) { /* whatever selection there is will have to do */ }

      run();
    },

    /**
     * Put a node into the document at the caret, followed by an empty paragraph.
     *
     * Takes a FUNCTION that builds the node, not a node. Lexical nodes can only be constructed
     * inside an update — building one outside throws error #337 — so every insert below hands
     * over a recipe and this runs it in the right place. One rule, enforced in one place.
     *
     * The trailing paragraph is not decoration: a block node at the very end of a document
     * with nothing after it leaves nowhere to put the caret, and the page becomes one you
     * cannot type at the bottom of.
     */
    insertNode: function (build) {
      if (!this.editor) return;

      var L = window.PBLexical;
      var editor = this.editor;

      this.withCaret(function () {
        editor.update(function () {
          var node = build();
          if (!node) return;

          L.$insertNodeToNearestRoot(node);

          if (!node.isInline || !node.isInline()) {
            var after = L.$createParagraphNode();
            node.insertAfter(after);
            after.select();
          }
        });
      });

      this.panel = '';
    },

    /** Insert an inline node — an equation or a date — in the run of text. */
    insertInline: function (build) {
      if (!this.editor) return;

      var L = window.PBLexical;
      var editor = this.editor;

      this.withCaret(function () {
        editor.update(function () {
          var node = build();
          if (node) L.$insertNodes([node]);
        });
      });

      this.panel = '';
    },

    insertRule: function () {
      this.insertNode(function () { return new WkNodes.rule(); });
    },
    insertPageBreak: function () {
      this.insertNode(function () { return new WkNodes.pageBreak(); });
    },
    insertSticky: function () {
      var L = window.PBLexical;
      var color = this.sticky.color;

      this.insertNode(function () {
        var note = new WkNodes.sticky(color);
        note.append(L.$createParagraphNode());

        return note;
      });
    },
    insertPoll: function () {
      var options = String(this.poll.options || '')
        .split('\n')
        .map(function (line) { return line.trim(); })
        .filter(Boolean)
        .map(function (text) { return { text: text, votes: 0 }; });

      var question = String(this.poll.question || '').trim();

      if (!question || options.length < 2) {
        this.$pb.toast('A poll needs a question and at least two options.', 'error');

        return;
      }

      this.insertNode(function () { return new WkNodes.poll(question, options); });
      this.poll = { question: '', options: 'Yes\nNo' };
    },
    insertLayout: function () {
      var L = window.PBLexical;
      var columns = this.layout.columns;

      this.insertNode(function () {
        var container = new WkNodes.layout(columns);

        for (var i = 0; i < container.__columns; i++) {
          var item = new WkNodes.layoutItem();
          item.append(L.$createParagraphNode());
          container.append(item);
        }

        return container;
      });
    },
    insertCollapsible: function () {
      var L = window.PBLexical;
      var heading = String(this.collapsible.title || '').trim() || 'Details';

      this.insertNode(function () {
        var box = new WkNodes.collapsible(true);
        var title = new WkNodes.collapsibleTitle();
        var body = new WkNodes.collapsibleContent();

        title.append(L.$createTextNode(heading));
        body.append(L.$createParagraphNode());
        box.append(title);
        box.append(body);

        return box;
      });

      this.collapsible = { title: '' };
    },
    insertEquation: function () {
      var tex = String(this.equation.tex || '').trim();
      if (!tex) return;

      var inline = this.equation.inline === true;
      var build = function () { return new WkNodes.equation(tex, inline); };

      this.equation = { tex: '', inline: inline };

      if (inline) this.insertInline(build); else this.insertNode(build);
    },
    insertDate: function () {
      var iso = this.date.value;
      this.insertInline(function () { return new WkNodes.date(iso); });
    },

    /**
     * Insert an embed — YouTube, Figma, Excalidraw or an X post.
     *
     * The URL is turned into an embed by the PROVIDER's own rule (WK_EMBEDS), which is also
     * what refuses a URL that is not that provider's. A link that does not match is a mistake
     * worth saying out loud rather than an iframe pointed at somewhere unexpected.
     */
    insertEmbed: function () {
      var provider = this.embed.provider;
      var spec = WK_EMBEDS[provider];
      var src = spec && spec.toSrc(String(this.embed.url || '').trim());

      if (!src) {
        this.$pb.toast('That does not look like a ' + (spec ? spec.label : 'valid') + ' link.', 'error');

        return;
      }

      this.insertNode(function () { return new WkNodes.embed(provider, src); });
      this.embed = { provider: provider, url: '' };
    },

    /**
     * Choose the sheet the document is laid out on.
     *
     * The editor does not own this — it is a property of the PAGE and the page is what saves
     * it, so the choice goes back up as an event. Picking the format already in force is not
     * a change, and must not become a save.
     */
    chooseFormat: function (key) {
      this.panel = '';
      if (key === this.pageFormat) return;
      this.$emit('update:pageFormat', key);
    },

    /** Run an Insert menu item: open its form, or do the thing. */
    runInsert: function (item) {
      this.menu = false;
      if (item.run) { this[item.run](); return; }
      this.panel = item.panel;
    },

    /**
     * Upload a chosen file and insert it (docs/features/wiki-lexical-editor.md).
     *
     * `file-0` and `{result:[{url}]}` are the shape every uploader in this app already
     * answers, so this is the same client code the work-item editor needs — not a second
     * upload protocol for one screen.
     *
     * Nothing here decides policy: the server re-checks type, size and permission, and this
     * only ever shows what it says went wrong. A file that fails must not also cost the
     * document, so the editor is untouched until a URL comes back.
     */
    uploadImage: function (event) {
      var input = event.target;
      var file = input.files && input.files[0];

      // Cleared immediately so choosing the SAME file twice still fires a change event.
      input.value = '';
      this.uploadAndInsert(file ? [file] : []);
    },

    /**
     * Send image files to the server and put what comes back into the document.
     *
     * ONE path for all three ways an image arrives — the file picker, a paste, a drop — so
     * there is one upload protocol, one error message and one busy flag to get right.
     *
     * Uploaded one at a time rather than in one request: the endpoint fails a whole batch when
     * any file in it is rejected, so pasting three screenshots and one PDF would lose the
     * three. Sequential also keeps the images in the order they were pasted.
     */
    uploadAndInsert: function (files) {
      var self = this;
      var queue = (files || []).filter(function (f) { return f && /^image\//.test(f.type); });

      if (!queue.length || !this.mediaUpload || this.uploading) return;

      var token = document.querySelector('meta[name="csrf-token"]');
      this.uploading = true;

      var next = function (i) {
        if (i >= queue.length) {
          self.uploading = false;

          return;
        }

        var body = new FormData();
        body.append('file-0', queue[i]);

        fetch(self.mediaUpload, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': token ? token.content : '', Accept: 'application/json' },
          body: body
        })
          .then(function (r) { return r.json().catch(function () { return {}; }); })
          .then(function (data) {
            if (data.errorMessage || !data.result || !data.result.length) {
              throw new Error(data.errorMessage || 'The upload did not come back with a file.');
            }

            var url = data.result[0].url;
            var name = data.result[0].name || '';

            self.insertNode(function () { return new WkNodes.image(url, name); });
          })
          .catch(function (e) { self.$pb.toast(e.message || 'Upload failed.', 'error'); })
          .then(function () { next(i + 1); });
      };

      next(0);
    },

    /**
     * Drop images the server will not keep, and say so.
     *
     * Pasting a region of a web page brings `<img src="https://someone-else.com/…">` with it.
     * It imports and it LOOKS pasted — and then `RichTextSanitizer` strips the `src` on the
     * next save, because an image may only come from this application's own origin or the
     * named embed hosts. Hotlinking somebody else's server leaks every reader's IP to them and
     * breaks the moment they move the file.
     *
     * So the editor removes them itself and says why. A visible message beats an image that
     * quietly turns into an empty box after a reload — which is what happened before, and
     * reads as the editor losing your work.
     *
     * Upload and drop are unaffected: those become this origin's own URLs.
     */
    pruneRemoteImages: function () {
      if (!this.editor) return;

      var L = window.PBLexical;
      var self = this;
      var dropped = 0;

      this.editor.update(function () {
        var walk = function (node) {
          if (node.getType && node.getType() === 'wk-image') {
            var src = String(node.__src || '');
            // Relative, or this origin's own. Anything else cannot survive the save.
            var local = src === '' || src.charAt(0) === '/'
              || src.indexOf(window.location.origin + '/') === 0;

            if (!local) {
              node.remove();
              dropped++;
            }

            return;
          }

          if (node.getChildren) node.getChildren().forEach(walk);
        };

        walk(L.$getRoot());
      });

      if (dropped && this.$pb) {
        this.$pb.toast(
          dropped === 1
            ? 'An image from another site was removed — upload or drop the file instead.'
            : dropped + ' images from other sites were removed — upload or drop the files instead.',
          'error'
        );
      }
    },

    /**
     * A drag carrying files is over the editor.
     *
     * Prevented on BOTH dragenter and dragover, which is what makes the element a drop target
     * at all — with either missing the cursor says "no" and the browser navigates away to the
     * file when it is released.
     *
     * `dragging` is what draws the outline. A drop target that looks identical to the page
     * around it is one people do not believe in, and they go back to the toolbar.
     */
    dragOverEditor: function (event, over) {
      var dt = event && event.dataTransfer;
      if (!dt || !this.mediaUpload) return;
      if (Array.prototype.indexOf.call(dt.types || [], 'Files') === -1) return;

      event.preventDefault();
      // Says "this will be copied here" rather than the default "moved", which on a file drag
      // is the cursor for something the page is about to refuse.
      try { dt.dropEffect = 'copy'; } catch (e) { /* read-only in some drags */ }
      this.dragging = over === true;
    },

    /**
     * Image files on the clipboard, pasted.
     *
     * Copying an image in a browser puts a BITMAP on the clipboard, not markup — there is no
     * `<img>` to import and no URL to keep, so nothing in Lexical's own paste handling has
     * anything to do with it and the paste silently does nothing. This is what catches it:
     * take the files, upload them, insert what comes back.
     *
     * Returns true only when files were actually taken, so an ordinary text or HTML paste
     * falls straight through to the rich-text handler that knows how to deal with it.
     */
    pasteFiles: function (event) {
      if (!this.mediaUpload || !event) return false;

      var data = event.clipboardData || (event.dataTransfer || null);
      if (!data) return false;

      var files = [];

      // `items` rather than `files` first: a browser image copy arrives as an item with a
      // `getAsFile()` and an empty `files` list in some browsers, and as a file in others.
      if (data.items && data.items.length) {
        Array.prototype.forEach.call(data.items, function (item) {
          if (item.kind === 'file' && /^image\//.test(item.type || '')) {
            var f = item.getAsFile();
            if (f) files.push(f);
          }
        });
      }

      if (!files.length && data.files && data.files.length) {
        Array.prototype.forEach.call(data.files, function (f) {
          if (/^image\//.test(f.type || '')) files.push(f);
        });
      }

      if (!files.length) return false;

      // Taken, so the browser must not ALSO drop its own copy in — a pasted screenshot would
      // otherwise appear twice, once as an upload and once as a base64 blob the size of the
      // image, saved into the document.
      event.preventDefault();
      this.uploadAndInsert(files);

      return true;
    },

    undo: function () {
      if (this.editor) this.editor.dispatchCommand(window.PBLexical.UNDO_COMMAND, undefined);
    },
    redo: function () {
      if (this.editor) this.editor.dispatchCommand(window.PBLexical.REDO_COMMAND, undefined);
    },
    /**
     * Strip inline formatting from the selection, leaving the text and the block alone.
     *
     * Both kinds: the toggled formats, and the style properties — otherwise "clear formatting"
     * leaves the text still 24px and still purple, which is not what the button says.
     */
    clearFormatting: function () {
      var self = this;

      ['bold', 'italic', 'underline', 'strikethrough', 'code', 'subscript', 'superscript']
        .forEach(function (f) {
          if (self.active[f]) self.format(f);
        });

      ['font-family', 'font-size', 'color', 'background-color'].forEach(function (p) {
        self.style(p, '');
      });
    },

    /**
     * The link button.
     *
     * A link on the caret is removed outright; otherwise the toolbar grows an input. Not
     * `window.prompt`: a modal dialog blocks the page, and it cannot show what is already
     * there. The selection survives the input taking focus because it lives in Lexical's
     * editor state rather than in the browser's.
     */
    toggleLink: function () {
      if (!this.editor) return;

      var L = window.PBLexical;

      if (this.isLink) {
        this.editor.dispatchCommand(L.TOGGLE_LINK_COMMAND, null);
        this.editor.focus();

        return;
      }

      var self = this;
      var opening = !this.link.open;

      // The input is a real focusable element, so opening it takes focus OUT of the document
      // and Lexical's selection goes with it. Remembered here and put back in applyLink —
      // otherwise the URL is applied to nothing and the button silently does nothing.
      if (opening) {
        this.editor.getEditorState().read(function () {
          var sel = L.$getSelection();
          self._savedSelection = L.$isRangeSelection(sel) ? sel.clone() : null;
        });
      }

      this.link = { open: opening, url: '' };
      if (opening) {
        this.$nextTick(function () {
          if (self.$refs.linkInput) self.$refs.linkInput.focus();
        });
      }
    },
    applyLink: function () {
      var url = String(this.link.url || '').trim();
      var saved = this._savedSelection;

      this.link = { open: false, url: '' };
      this._savedSelection = null;
      if (!url || !this.editor) return;

      // A bare domain is what people type; without a scheme the browser reads it as a path on
      // this app and the link silently goes nowhere.
      if (!/^([a-z][a-z0-9+.-]*:|\/|#)/i.test(url)) url = 'https://' + url;

      var L2 = window.PBLexical;

      // Selection first, in its own update, so the command that follows sees the range the
      // user actually had when they reached for the button.
      if (saved) {
        this.editor.update(function () { L2.$setSelection(saved.clone()); }, { discrete: true });
      }

      this.editor.dispatchCommand(L2.TOGGLE_LINK_COMMAND, url);
      this.editor.focus();
    },

    onBlur: function () {
      clearTimeout(this._syncTimer);
      this.emitValue();
      this.$emit('blur');
    }
  },

  template:
    '<div class="wk-editor" :class="{ \'wk-editor--mini\': minimal }">' +

    // ---- toolbar, rendered into the host the page provides. Vue keeps owning it: unlike
    // Jodit's, every button here restates itself as the caret moves.
    // A FIELD's toolbar sits directly above the field; a DOCUMENT's is teleported into the
    // screen-wide bar the page provides. One <component> rather than two branches of markup,
    // so the toolbar itself is written once.
    '<component :is="(toolbarHost && !minimal) ? \'teleport\' : \'div\'" ' +
    ':to="(toolbarHost && !minimal) ? toolbarHost : undefined" v-if="ready">' +
    '<div class="wk-toolbar" :class="{ \'wk-toolbar--mini\': minimal }">' +

    '<div class="wk-toolbar__group">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :disabled="!canUndo" @click="undo" data-tip="Undo" aria-label="Undo" v-html="icon(\'rotate-left\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :disabled="!canRedo" @click="redo" data-tip="Redo" aria-label="Redo" v-html="icon(\'rotate-right\', 16)"></button>' +
    '</div>' +

    // ---- block type. A dropdown, not a button each: eleven types is more than a toolbar can
    // show, and the one in force is worth naming rather than leaving you to read the lights.
    '<div v-if="!minimal" class="wk-toolbar__group wk-toolbar__group--rel">' +
    '<button type="button" class="wk-tb wk-tb--wide wk-tb--block" @mousedown.prevent ' +
    ':class="{ \'wk-tb--on\': panel === \'block\' }" @click="togglePanel(\'block\')" ' +
    'data-tip="Block type" aria-label="Block type">' +
    '<span class="wk-tb__label">{{ blockLabel }}</span>' +
    '<span v-html="icon(\'chevron-down\', 12)"></span></button>' +

    '<div v-if="panel === \'block\'" class="wk-pop wk-pop--menu">' +
    '<button v-for="b in blockOptions" :key="b.key" type="button" class="wk-menu__item" ' +
    ':class="{ \'wk-menu__item--on\': block === b.key }" @mousedown.prevent @click="setBlock(b.key)">' +
    '<span class="wk-menu__icon">' +
    '<span v-if="b.glyph" class="wk-tb__glyph">{{ b.glyph }}</span>' +
    '<span v-else v-html="icon(b.icon, 15)"></span></span>{{ b.label }}</button>' +
    '</div></div>' +

    '<div v-if="!minimal" class="wk-toolbar__group">' +
    '<select class="wk-select" :value="font" @change="style(\'font-family\', $event.target.value)" aria-label="Font">' +
    '<option v-for="f in fonts" :key="f.label" :value="f.value">{{ f.label }}</option></select>' +
    '<select class="wk-select wk-select--sm" :value="size" @change="style(\'font-size\', $event.target.value)" aria-label="Font size">' +
    '<option v-for="z in sizes" :key="z || \'auto\'" :value="z">{{ z || \'Size\' }}</option></select>' +
    '</div>' +

    '<div class="wk-toolbar__group">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.bold }" @click="format(\'bold\')" data-tip="Bold" aria-label="Bold" v-html="icon(\'bold\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.italic }" @click="format(\'italic\')" data-tip="Italic" aria-label="Italic" v-html="icon(\'italic\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.underline }" @click="format(\'underline\')" data-tip="Underline" aria-label="Underline" v-html="icon(\'underline\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.strikethrough }" @click="format(\'strikethrough\')" data-tip="Strikethrough" aria-label="Strikethrough" v-html="icon(\'strikethrough\', 16)"></button>' +
    '<button type="button" v-if="!minimal" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.subscript }" @click="format(\'subscript\')" data-tip="Subscript" aria-label="Subscript"><span class="wk-tb__glyph">X<sub>2</sub></span></button>' +
    '<button type="button" v-if="!minimal" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.superscript }" @click="format(\'superscript\')" data-tip="Superscript" aria-label="Superscript"><span class="wk-tb__glyph">X<sup>2</sup></span></button>' +
    '<button type="button" v-if="!minimal" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': active.code }" @click="format(\'code\')" data-tip="Inline code" aria-label="Inline code"><span class="wk-tb__glyph">&lt;/&gt;</span></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent @click="clearFormatting" data-tip="Clear formatting" aria-label="Clear formatting" v-html="icon(\'eraser\', 16)"></button>' +
    '</div>' +

    // ---- colour. One grid, reused for text and highlight — which one it writes to is whichever
    // button opened it, so there is one popover to position and one to close.
    '<div v-if="!minimal" class="wk-toolbar__group wk-toolbar__group--rel">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': panel === \'color\' }" @click="togglePanel(\'color\')" data-tip="Text colour" aria-label="Text colour">' +
    '<span class="wk-swatch" :style="{ background: color || \'#1f2328\' }"></span>A</button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': panel === \'highlight\' }" @click="togglePanel(\'highlight\')" data-tip="Highlight" aria-label="Highlight">' +
    '<span class="wk-swatch" :style="{ background: highlight || \'#fde68a\' }"></span>H</button>' +
    '<div v-if="panel === \'color\' || panel === \'highlight\'" class="wk-pop wk-pop--colors">' +
    '<button v-for="c in colors" :key="c || \'none\'" type="button" class="wk-chip" @mousedown.prevent @click="pickColor(c)" ' +
    ':style="c ? { background: c } : {}" :class="{ \'wk-chip--none\': !c }" :aria-label="c || \'Clear colour\'" :data-tip="c || \'Clear\'"></button>' +
    '</div></div>' +

    // Lists, for a field only. In the full editor they live in the block dropdown; with that
    // hidden they would be unreachable, and a comment without a bullet list is a comment
    // people write as "- " and a dash.
    '<div v-if="minimal" class="wk-toolbar__group">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': block === \'ul\' }" ' +
    '@click="setBlock(\'ul\')" data-tip="Bulleted list" aria-label="Bulleted list" v-html="icon(\'list-ul\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': block === \'ol\' }" ' +
    '@click="setBlock(\'ol\')" data-tip="Numbered list" aria-label="Numbered list" v-html="icon(\'list-ol\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': block === \'quote\' }" ' +
    '@click="setBlock(\'quote\')" data-tip="Quote" aria-label="Quote"><span class="wk-tb__glyph">”</span></button>' +
    '</div>' +

    '<div v-if="!minimal" class="wk-toolbar__group">' +
    '<button type="button" class="wk-tb" @mousedown.prevent @click="indent(true)" data-tip="Outdent" aria-label="Outdent" v-html="icon(\'outdent\', 16)"></button>' +
    '<button type="button" class="wk-tb" @mousedown.prevent @click="indent(false)" data-tip="Indent" aria-label="Indent" v-html="icon(\'indent\', 16)"></button>' +
    '</div>' +

    '<div v-if="!minimal" class="wk-toolbar__group">' +
    '<button v-for="a in [\'left\',\'center\',\'right\',\'justify\']" :key="a" type="button" class="wk-tb" @mousedown.prevent ' +
    ':class="{ \'wk-tb--on\': align === a }" @click="alignAs(a)" :data-tip="\'Align \' + a" :aria-label="\'Align \' + a" ' +
    'v-html="icon(\'align-\' + a, 16)"></button>' +
    '</div>' +

    '<div class="wk-toolbar__group">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': isLink || link.open }" @click="toggleLink" ' +
    ':data-tip="isLink ? \'Remove link\' : \'Add link\'" :aria-label="isLink ? \'Remove link\' : \'Add link\'" ' +
    'v-html="icon(\'link\', 16)"></button>' +
    '<input v-if="link.open" v-model="link.url" ref="linkInput" class="wk-link-input" ' +
    'placeholder="Paste or type a link, then Enter" @keyup.enter="applyLink" @keyup.esc="link.open = false" />' +
    '</div>' +

    // ---- image. Its own button rather than a line in the Insert menu: it is the one thing
    // people reach for constantly, and two clicks for the common case is one too many.
    '<div class="wk-toolbar__group wk-toolbar__group--rel">' +
    '<button type="button" class="wk-tb" @mousedown.prevent :class="{ \'wk-tb--on\': panel === \'image\' }" ' +
    '@click="togglePanel(\'image\')" :data-tip="uploading ? \'Uploading…\' : \'Image or GIF\'" aria-label="Image or GIF" ' +
    'v-html="icon(\'image\', 16)"></button>' +

    '<div v-if="panel === \'image\'" class="wk-pop">' +
    // Upload first, because it is what most people want and the URL field is the fallback.
    '<label class="wk-pop__file" :class="{ \'wk-pop__file--busy\': uploading }">' +
    '<span v-html="icon(\'arrow-up-from-bracket\', 14)"></span>' +
    '{{ uploading ? \'Uploading…\' : \'Upload an image or GIF\' }}' +
    '<input type="file" accept="image/*" :disabled="uploading || !mediaUpload" @change="uploadImage" /></label>' +
    '<p class="wk-pop__or">or paste a link</p>' +
    '<input v-model="image.url" class="wk-link-input wk-link-input--pop" placeholder="Image URL" @keyup.enter="insertImage" />' +
    '<input v-model="image.alt" class="wk-link-input wk-link-input--pop" placeholder="Alt text (optional)" @keyup.enter="insertImage" />' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertImage">Insert image</button>' +
    '</div></div>' +

    // ---- insert. One menu, not a row of buttons: the set is ten items and growing, and a
    // toolbar stops being readable at about eight. A field gets it too — a table or a
    // collapsible section is as useful in a work item as on a page — minus the one item
    // that cannot mean anything there (see insertItems).
    '<div class="wk-toolbar__group wk-toolbar__group--rel">' +
    '<button type="button" class="wk-tb wk-tb--wide" @mousedown.prevent :class="{ \'wk-tb--on\': menu }" ' +
    '@click="toggleMenu" data-tip="Insert" aria-label="Insert">' +
    '<span v-html="icon(\'plus\', 15)"></span><span class="wk-tb__label">Insert</span></button>' +

    '<div v-if="menu" class="wk-pop wk-pop--menu">' +
    '<button v-for="i in insertItems" :key="i.key" type="button" class="wk-menu__item" ' +
    '@mousedown.prevent @click="runInsert(i)">' +
    '<span class="wk-menu__icon" v-html="icon(i.icon, 15)"></span>{{ i.label }}</button>' +
    '</div>' +

    // ---- the forms behind the menu items that need one.
    '<div v-if="panel === \'table\'" class="wk-pop">' +
    '<label class="wk-pop__row">Rows<input type="number" min="1" max="50" v-model="table.rows" class="wk-num" /></label>' +
    '<label class="wk-pop__row">Columns<input type="number" min="1" max="20" v-model="table.cols" class="wk-num" /></label>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertTable">Insert table</button>' +
    '</div>' +

    '<div v-if="panel === \'embed\'" class="wk-pop">' +
    '<div class="wk-pop__tabs">' +
    '<button v-for="p in embedProviders" :key="p.key" type="button" class="wk-pop__tab" @mousedown.prevent ' +
    ':class="{ \'wk-pop__tab--on\': embed.provider === p.key }" @click="embed.provider = p.key">{{ p.label }}</button>' +
    '</div>' +
    '<input v-model="embed.url" class="wk-link-input wk-link-input--pop" :placeholder="embedPlaceholder" @keyup.enter="insertEmbed" />' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertEmbed">Insert</button>' +
    '</div>' +

    '<div v-if="panel === \'poll\'" class="wk-pop">' +
    '<input v-model="poll.question" class="wk-link-input wk-link-input--pop" placeholder="Question" />' +
    '<textarea v-model="poll.options" class="wk-area" rows="4" placeholder="One option per line"></textarea>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertPoll">Insert poll</button>' +
    '</div>' +

    '<div v-if="panel === \'layout\'" class="wk-pop">' +
    '<label class="wk-pop__row">Columns<input type="number" min="2" max="4" v-model="layout.columns" class="wk-num" /></label>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertLayout">Insert columns</button>' +
    '</div>' +

    '<div v-if="panel === \'collapsible\'" class="wk-pop">' +
    '<input v-model="collapsible.title" class="wk-link-input wk-link-input--pop" placeholder="Summary title" @keyup.enter="insertCollapsible" />' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertCollapsible">Insert container</button>' +
    '</div>' +

    '<div v-if="panel === \'equation\'" class="wk-pop">' +
    '<input v-model="equation.tex" class="wk-link-input wk-link-input--pop" placeholder="LaTeX, e.g. \\frac{a}{b}" @keyup.enter="insertEquation" />' +
    '<label class="wk-pop__row"><span>Inline</span><input type="checkbox" v-model="equation.inline" /></label>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertEquation">Insert equation</button>' +
    '</div>' +

    '<div v-if="panel === \'sticky\'" class="wk-pop">' +
    '<div class="wk-pop__tabs">' +
    '<button v-for="c in stickyColors" :key="c" type="button" class="wk-chip wk-chip--sticky" ' +
    ':class="[\'wk-chip--\' + c, { \'wk-chip--on\': sticky.color === c }]" @click="sticky.color = c" :aria-label="c"></button>' +
    '</div>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertSticky">Insert note</button>' +
    '</div>' +

    '<div v-if="panel === \'date\'" class="wk-pop">' +
    '<input type="date" v-model="date.value" class="wk-link-input wk-link-input--pop" />' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent @click="insertDate">Insert date</button>' +
    '</div>' +
    '</div>' +

    // ---- the sheet the document is laid out on (docs/features/wiki-page-format.md).
    // Beside Insert rather than up in the page header: it belongs with the other things that
    // change how the document is built, not with the breadcrumb and the save status. A field
    // has no sheet, so `minimal` leaves it out entirely.
    '<div v-if="!minimal" class="wk-toolbar__group wk-toolbar__group--rel">' +
    '<button type="button" class="wk-tb wk-tb--wide" @mousedown.prevent :class="{ \'wk-tb--on\': panel === \'format\' }" ' +
    '@click="togglePanel(\'format\')" data-tip="Page format" aria-label="Page format">' +
    '<span v-html="icon(\'file-lines\', 15)"></span><span class="wk-tb__label">{{ formatLabel }}</span>' +
    '<span v-html="icon(\'chevron-down\', 12)"></span></button>' +

    '<div v-if="panel === \'format\'" class="wk-pop wk-pop--menu wk-pop--right">' +
    '<p class="wk-pop__head">Page format</p>' +
    '<button v-for="f in formatOptions" :key="f.key" type="button" class="wk-menu__item" ' +
    '@mousedown.prevent @click="chooseFormat(f.key)">' +
    '<span class="wk-menu__icon" v-html="f.key === pageFormat ? icon(\'check\', 14, \'text-brand\') : \'\'"></span>' +
    '<span class="wk-menu__stack"><span>{{ f.label }}</span><span class="wk-menu__hint">{{ f.hint }}</span></span>' +
    '</button></div>' +
    '</div>' +

    '</div></component>' +

    // ---- the floating toolbar over a selection (docs/features/wiki-comments.md).
    // Teleported to <body> so it is never clipped by the scroller the document lives in, and
    // `@mousedown.prevent` throughout so reaching for it never destroys the selection it is
    // there to act on.
    '<teleport to="body">' +
    '<div v-if="float.open && !draft.open" class="wk-float" :style="floatStyle" @mousedown.prevent>' +
    '<button type="button" class="wk-tb" :class="{ \'wk-tb--on\': active.bold }" @click="format(\'bold\')" data-tip="Bold" aria-label="Bold" v-html="icon(\'bold\', 15)"></button>' +
    '<button type="button" class="wk-tb" :class="{ \'wk-tb--on\': active.italic }" @click="format(\'italic\')" data-tip="Italic" aria-label="Italic" v-html="icon(\'italic\', 15)"></button>' +
    '<button type="button" class="wk-tb" :class="{ \'wk-tb--on\': active.underline }" @click="format(\'underline\')" data-tip="Underline" aria-label="Underline" v-html="icon(\'underline\', 15)"></button>' +
    '<button type="button" class="wk-tb" :class="{ \'wk-tb--on\': isLink }" @click="toggleLink" data-tip="Link" aria-label="Link" v-html="icon(\'link\', 15)"></button>' +
    '<span class="wk-float__sep"></span>' +
    '<button v-for="h in highlights" :key="h.key" type="button" class="wk-chip wk-chip--hl" ' +
    ':style="{ background: h.value }" @click="applyHighlight(h.value)" :data-tip="h.label" :aria-label="h.label"></button>' +
    '<button type="button" class="wk-chip wk-chip--none" @click="applyHighlight(\'\')" data-tip="Remove highlight" aria-label="Remove highlight"></button>' +
    '<span class="wk-float__sep"></span>' +
    '<button type="button" class="wk-tb wk-tb--wide" @click="startComment" data-tip="Comment" aria-label="Comment">' +
    '<span v-html="icon(\'note\', 15)"></span><span class="wk-tb__label">Comment</span></button>' +
    '</div>' +

    // ---- the comment being written. Anchored where the selection was, so it reads as being
    // attached to the passage rather than floating over the page.
    '<div v-if="draft.open" class="wk-draft" :style="floatStyle" @mousedown.stop>' +
    '<p class="wk-draft__quote">“{{ draft.quote }}”</p>' +
    '<textarea ref="draftBox" v-model="draft.body" class="wk-area wk-area--draft" rows="3" ' +
    'placeholder="Add a comment..." @keydown.esc="cancelComment"></textarea>' +
    '<div class="wk-draft__actions">' +
    '<button type="button" class="wk-draft__cancel" @click="cancelComment">Cancel</button>' +
    '<button type="button" class="wk-pop__go" @mousedown.prevent :disabled="!draft.body.trim()" @click="submitComment">Comment</button>' +
    '</div></div>' +
    // ---- the `@` popup. Teleported like the floating toolbar, and for the same reason: it
    // must never be clipped by the scroller the document lives in.
    // `@mousedown.prevent` so clicking a row never takes the caret out of the word it is
    // about to replace.
    '<div v-if="mention.open" class="wk-mention-pop" :style="mentionStyle" @mousedown.prevent>' +
    '<p v-if="mention.loading && !mention.users.length" class="wk-mention-pop__note">Searching…</p>' +
    '<p v-else-if="!mention.users.length" class="wk-mention-pop__note">No people found</p>' +
    '<button v-for="(u, i) in mention.users" :key="u.id" type="button" class="wk-mention-row" ' +
    ':class="{ \'wk-mention-row--on\': i === mention.index }" @click="pickMention(u)" @mouseenter="mention.index = i">' +
    '<span v-if="u.avatar_url" class="wk-msg__face"><img :src="u.avatar_url" alt="" /></span>' +
    '<span v-else class="wk-msg__face" :style="{ background: u.avatar_color }">{{ u.initial }}</span>' +
    '<span class="wk-mention-row__who"><span class="wk-mention-row__name">{{ u.name }}</span>' +
    '<span class="wk-mention-row__mail">{{ u.email }}</span></span></button>' +
    '</div>' +
    '</teleport>' +

    // ---- the document itself.
    '<div class="wk-editor__sheet">' +
    // contenteditable is the HOST's to set, not Lexical's: setRootElement wires the editor to
    // the element but leaves the attribute alone, and without it every keystroke goes nowhere
    // while the toolbar still works — which reads as "typing is broken", not as an error.
    // Bound to `disabled` so archiving a page makes it read-only with no remount.
    '<div ref="doc" :class="docClass" :style="docStyle" :data-placeholder="placeholder" ' +
    ':data-empty="empty ? \'1\' : \'0\'" :contenteditable="disabled ? \'false\' : \'true\'" ' +
    'role="textbox" aria-multiline="true" spellcheck="true" @blur="onBlur" @click="onDocClick"></div>' +
    '<p v-if="!ready" class="wk-editor__down">The editor could not start. Reload the page — if it keeps happening, the Lexical bundle is missing (public/assets/vendor/lexical/README.md).</p>' +
    '</div>' +

    '</div>'
};
