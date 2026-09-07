/* Project Pages › the Jodit editor.
   ------------------------------------------------------------------
   A page is a document — tables, structure, long-form text — and Quill drops what it has no
   format for, tables included. Jodit keeps them, so Pages uses it while work items, comments
   and status updates stay on <wi-editor>: those are short-form fields where Quill is a fine
   fit, and moving them would touch the drawer and both comment composers for no gain.

   The contract here is deliberately IDENTICAL to <wi-editor> — same props, same events, same
   flush() — so the Pages screen swaps one tag for the other and nothing else changes. That is
   also what lets this file degrade: when the Jodit package is not installed, pages.js renders
   <wi-editor> instead and the screen keeps working.
   ------------------------------------------------------------------ */

/** Is the Jodit package actually loaded? The Pro build is licensed and not vendored by default. */
/** Escape before building menu/chip markup by hand — names and emails are user content. */
function pgEsc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * The sheets a wiki page can be laid out on (docs/features/wiki-page-format.md).
 *
 * Widths and heights are CSS pixels at 96dpi, which is the only unit the editor's frame can
 * reason about: A4 210x297mm, Letter 8.5x11in, Legal 8.5x14in. `print` is the name the same
 * sheet goes by in an `@page` rule, so a printed page comes out the size it was written on.
 *
 * `paperless` is the absence of a sheet — it carries no dimensions on purpose, and every
 * branch below reads that as "no page, no breaks, just a document that scrolls".
 */
var PG_PAGE_FORMATS = {
  a4: { label: 'A4', width: 794, height: 1123, print: 'A4' },
  letter: { label: 'Letter', width: 816, height: 1056, print: 'letter' },
  legal: { label: 'Legal', width: 816, height: 1344, print: 'legal' },
  paperless: { label: 'Paperless', width: null, height: null, print: 'A4' }
};

/** The sheet a format name stands for, or null for paperless and for anything unknown. */
function pgSheet(format) {
  var sheet = PG_PAGE_FORMATS[format];

  return sheet && sheet.height ? sheet : null;
}

function pgJoditReady() {
  return typeof window !== 'undefined' && typeof window.Jodit !== 'undefined';
}

var PgEditor = {
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Write…' },
    minHeight: { type: String, default: '420px' },
    disabled: { type: Boolean, default: false },
    /** Where images go. Absent means the image button uploads nowhere, so it is hidden. */
    mediaUpload: { type: String, default: '' },
    /**
     * The Jodit Pro licence key.
     *
     * Without it the Pro plugins load but stay inert, which presents as "the Pro features are
     * missing" rather than as an error — so it is worth checking here first when they are.
     */
    license: { type: String, default: '' },
    /**
     * A CSS selector for an element to move the toolbar into.
     *
     * Jodit builds its toolbar at the top of its own container, which puts it below anything
     * the page renders above the editor. Moving the finished element lets the toolbar sit at
     * the top of the screen while the title and body scroll beneath it — the same relocation
     * <wi-editor> does, and for the same reason.
     *
     * The element is MOVED, never rebuilt: its buttons carry Jodit's own handlers, so the
     * host must be one Vue renders empty and never patches.
     */
    toolbarHost: { type: String, default: '' },
    /**
     * Render the editing area as a page (Jodit's document view).
     *
     * Turns on Jodit's `iframe` mode, which puts the document in its own frame with its own
     * stylesheet — so the text is laid out on a sheet with page margins rather than filling a
     * panel, and page breaks have something to break. It is also what isolates the document's
     * CSS from the app's: nothing in tailwind.css can reach inside and restyle a user's
     * document, and nothing the document carries can leak out onto the app.
     */
    documentView: { type: Boolean, default: true },
    /**
     * Which sheet the document view lays the text on
     * (docs/features/wiki-page-format.md).
     *
     * 'a4' | 'letter' | 'legal' — a page of that size, with a visible boundary wherever the
     * text runs past the bottom of one and continues on the next — or 'paperless', which
     * removes the sheet altogether and leaves one continuous scrolling document.
     *
     * Changing it re-styles the frame IN PLACE (see applyPageFormat): the editor is never
     * rebuilt, so nothing typed and nothing selected is lost to picking a paper size.
     * Ignored entirely when `documentView` is false — a field has no page to be.
     */
    pageFormat: { type: String, default: 'a4' },
    /**
     * How wide the text runs when there is no sheet to hold it (paperless).
     *
     * A paperless document still needs a measure — full-window text is unreadable — so it
     * follows the content width the screen configures rather than a paper size. Ignored by
     * every other format, which take their width from the sheet itself.
     */
    contentWidth: { type: String, default: '820px' },
    /**
     * A trimmed toolbar, as Jodit's `buttons` string.
     *
     * Empty means Jodit's own full set, which is what Pages wants — a page is a document and
     * gets the document's tools. A host embedding this as a FIELD rather than a surface (the
     * draft description) passes a short list instead, so the control is the size of the job.
     * Set for every breakpoint, because Jodit otherwise falls back to the full set as the
     * viewport narrows and the toolbar grows rather than shrinks.
     */
    buttons: { type: String, default: '' },
    /**
     * The endpoint the `@` autocomplete asks for people (mentions §5).
     *
     * Empty means no mentions — which is what a project-less surface gets, because "who may
     * be mentioned" is a question about a project (§16) and there is nobody to answer it for
     * a draft. The `@` key is then just a character.
     */
    mentionUrl: { type: String, default: '' }
  },
  emits: ['update:modelValue', 'blur'],
  data: function () {
    return { jodit: null };
  },
  mounted: function () {
    if (!pgJoditReady() || !this.$refs.area) return;
    var self = this;

    var options = {
      license: this.license,
      readonly: this.disabled === true,
      placeholder: this.placeholder,
      minHeight: parseInt(this.minHeight, 10) || 420,
      // The page's own chrome supplies the frame; the editor should look like the document.
      toolbarAdaptive: false,
      // Jodit's sticky toolbar positions itself against the WINDOW, so inside a scrolled
      // container it detaches and floats. The page pins it with CSS `position: sticky`
      // instead, which is relative to the scroller it actually lives in.
      toolbarSticky: false,
      statusbar: false,
      showCharsCounter: false,
      showWordsCounter: false,
      showXPathInStatusbar: false,
      // Pasting from Word is the case this editor is here for: keep the formatting, and ask
      // rather than guessing, so a document arrives looking like the document.
      askBeforePasteHTML: true,
      askBeforePasteFromWord: true,
      defaultActionOnPaste: 'insert_clear_html',
      uploader: this.uploaderConfig()
    };

    if (this.buttons) {
      options.buttons = options.buttonsMD = options.buttonsSM = options.buttonsXS = this.buttons;
    }

    if (this.documentView) {
      options.iframe = true;
      // APPENDED to Jodit's own iframe stylesheet, never replacing it: the defaults carry the
      // editor's base typography, and the page-break plugin appends its rules the same way.
      options.iframeStyle = (window.Jodit.defaultOptions.iframeStyle || '') + this.pageStyle()
        + this.formatStyle(this.pageFormat);
    }

    this.jodit = window.Jodit.make(this.$refs.area, options);

    // Jodit exposes its event bus as both `events` and the shorthand `e`.
    var bus = this.jodit.events || this.jodit.e;

    // Debounced like <wi-editor>, and for the same reason: a save clicked inside the window
    // would otherwise read the previous value. flush() closes that window.
    this._syncTimer = null;
    bus.on('change', function () {
      clearTimeout(self._syncTimer);
      self._syncTimer = setTimeout(function () { self.emitValue(); }, 120);
      // A sheet grows as it is written on — see resizeFrame for why Jodit's own auto-resize
      // cannot be relied on here.
      self.resizeFrame();
    });

    bus.on('blur', function () {
      clearTimeout(self._syncTimer);
      self.emitValue();
      self.$emit('blur');
    });

    this.applyOurIcons();
    this.relocateToolbar();
    this.applyPageFormat();
    this.attachMentions();

    // Content LAST, after the listeners — the same ordering lesson <wi-editor> records: a
    // throw while parsing must not cost the editor its wiring.
    if (this.modelValue) this.setHtml(this.modelValue);
  },
  beforeUnmount: function () {
    clearTimeout(this._syncTimer);
    clearTimeout(this._mentionTimer);
    try {
      // Tribute appends its menu to <body>, so it outlives the editor unless detached.
      if (this._tribute && this.jodit && this.jodit.editor) this._tribute.detach(this.jodit.editor);
    } catch (e) { /* the editor is going away regardless */ }
    this._tribute = null;
    try {
      if (this.jodit && this.jodit.destruct) this.jodit.destruct();
    } catch (e) { /* already torn down */ }
    this.jodit = null;
  },
  watch: {
    // The page was replaced under us (a save refreshed it) — take the new value, but never
    // while the user is typing into it.
    modelValue: function (next) {
      if (!this.jodit || this.isFocused()) return;
      if (next !== this.html()) this.setHtml(next || '');
    },
    // A new paper size is applied to the frame that is already open — see applyPageFormat.
    pageFormat: function () { this.applyPageFormat(); },
    disabled: function (next) {
      // Archiving a page makes it read-only without remounting the editor.
      try {
        if (this.jodit && this.jodit.setReadOnly) this.jodit.setReadOnly(next === true);
      } catch (e) { /* older builds expose it as an option only */ }
    }
  },
  methods: {
    /**
     * Replace Jodit's toolbar icons with the app's own.
     *
     * Jodit ships a complete icon set; so does this app, and a toolbar drawn in someone
     * else's line weight sits in the page looking borrowed. `wiIcon` reads the same registry
     * every other icon in the app comes from and honours the same set switch, so the toolbar
     * follows a change to config/icons.php without anything here changing.
     *
     * Names on the left are Jodit's; on the right are ours. Anything not listed keeps Jodit's
     * icon — better a consistent stranger than a missing button.
     */
    applyOurIcons: function () {
      var Icon = (window.Jodit.modules && window.Jodit.modules.Icon) || window.Jodit.Icon;
      if (!Icon || typeof Icon.set !== 'function' || typeof wiIcon !== 'function') return;

      var map = {
        bold: 'bold', italic: 'italic', underline: 'underline', strikethrough: 'strikethrough',
        ul: 'list-ul', ol: 'list-ol',
        indent: 'indent', outdent: 'outdent',
        left: 'align-left', center: 'align-center', right: 'align-right', justify: 'align-justify',
        undo: 'rotate-left', redo: 'rotate-right',
        table: 'table', link: 'link', image: 'image', file: 'paperclip',
        eraser: 'eraser', paragraph: 'paragraph', fontsize: 'text-size',
        print: 'file-lines', preview: 'eye', fullsize: 'expand', copyformat: 'copy',
        hr: 'sort', video: 'play', search: 'magnifying-glass'
      };

      Object.keys(map).forEach(function (joditName) {
        try {
          Icon.set(joditName, wiIcon(map[joditName], 16));
        } catch (e) { /* one unknown name must not cost the whole toolbar */ }
      });
    },

    /**
     * The document's typography, as CSS injected into its own frame.
     *
     * Typography lives here rather than in pages.css because the document is inside an iframe
     * — the app's stylesheets cannot reach it, which is the point of the mode.
     *
     * What is NOT here is the sheet: its width, height, margins and page boundaries change
     * with the chosen format, so they are formatStyle()'s alone. Stating a size in both places
     * is how the two drift apart, and the one that loses is whichever is written first.
     */
    pageStyle: function () {
      return [
        'html{background:#f3f4f6;padding:0;}',
        'body{',
        'font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.75;color:#1f2328;',
        '}',
        'h1{font-size:28px;font-weight:700;margin:28px 0 8px;}',
        'h2{font-size:22px;font-weight:700;margin:24px 0 8px;}',
        'h3{font-size:18px;font-weight:600;margin:20px 0 6px;}',
        'p{margin:0 0 10px;}',
        'ul,ol{padding-left:1.5em;margin:0 0 10px;}',
        'li{margin:4px 0;}',
        'a{color:#2563eb;text-decoration:underline;}',
        'img{max-width:100%;height:auto;}',
        'blockquote{border-left:3px solid #e5e7eb;margin:12px 0;padding:2px 0 2px 14px;color:#6b7280;}',
        'hr{border:0;border-top:1px solid #e5e7eb;margin:24px 0;}',
        // Tables are the reason this editor replaced the last one, so they get real styling.
        'table{border-collapse:collapse;width:100%;margin:12px 0;}',
        'th,td{border:1px solid #e5e7eb;padding:8px 10px;text-align:left;vertical-align:top;}',
        'th{background:#f6f7f8;font-weight:600;}'
      ].join('');
    },

    /**
     * The sheet the text is laid out on, as CSS for the document's frame
     * (docs/features/wiki-page-format.md).
     *
     * PAGED (a4 / letter / legal) — the body IS the stack of sheets: one white column of the
     * format's width, with a boundary line drawn at every multiple of its height. The lines
     * come from a repeating background rather than from real page elements, because the
     * document is one continuous contenteditable — splitting it into per-page elements is what
     * costs you the caret, the selection and every multi-page table. `background-origin` is
     * border-box so the first boundary is measured from the top of the sheet, not from below
     * its top margin, and `min-height` keeps an empty document a full page rather than a
     * sliver.
     *
     * Text is therefore free to sit across a boundary while you write: the line says where
     * this page ends, and the @media print block below is what actually breaks the paper.
     *
     * PAPERLESS — no width, no height, no boundaries. One continuous scrolling document held
     * only to the configured content width. It still PRINTS on a sheet: `paperless` describes
     * how the page is written, not what comes out of a printer.
     */
    formatStyle: function (format) {
      var sheet = pgSheet(format);
      var print = (PG_PAGE_FORMATS[format] || PG_PAGE_FORMATS.a4).print;

      if (!sheet) {
        // Still !important, and still for Jodit's inline `min-height` — a document that has
        // just come off Legal carries an inline 1344px, and without the override a paperless
        // page keeps the height of the sheet it no longer has. The floor is the editor's own
        // minHeight, so an empty paperless page is a comfortable writing area rather than one
        // line, and it grows with whatever is written into it.
        var floor = parseInt(this.minHeight, 10) || 420;

        return [
          'body{',
          'background:#fff;box-shadow:none;',
          'width:auto;max-width:' + this.contentWidth + ';min-height:' + floor + 'px!important;',
          'margin:0 auto;padding:32px 24px 25vh;',
          '}',
          '@media print{@page{size:' + print + ';margin:20mm;}',
          'body{max-width:none;margin:0;padding:0;}}'
        ].join('');
      }

      // The sheet is white; the boundary is the last pixel of each page-height tile, so the
      // tiles repeat down the document for as long as the text does.
      var tile = 'linear-gradient(to bottom,transparent calc(100% - 1px),#d7dbe0 calc(100% - 1px))';

      return [
        'body{',
        'background:#fff ' + tile + ' repeat-y 0 0/100% ' + sheet.height + 'px;',
        'background-origin:border-box;',
        // !important, and only here: Jodit's auto-resize writes its own `min-height` INLINE on
        // the body, sized to the iframe it just measured. Without the override the sheet
        // collapses to the height of whatever has been typed so far and there is never a page
        // to break. Forcing it the other way round is also what makes the frame grow: Jodit
        // resizes the iframe to the document's scrollHeight, which a full sheet now sets.
        'width:' + sheet.width + 'px;max-width:100%;min-height:' + sheet.height + 'px!important;',
        'margin:24px auto 64px;padding:56px 64px;',
        'box-shadow:0 1px 3px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.06);',
        '}',
        // A break the writer inserted deliberately reads as a boundary too, and prints as one.
        '.jodit_page_break,.pg-page-break{',
        'page-break-after:always;break-after:page;',
        'border:0;border-top:1px dashed #c9ced6;margin:24px 0;height:0;',
        '}',
        '@media print{@page{size:' + print + ';margin:0;}',
        'html{background:none;}',
        'body{width:auto;min-height:0;margin:0;padding:56px 64px;background:#fff;box-shadow:none;}}'
      ].join('');
    },

    /**
     * Apply the current format to the frame that is ALREADY OPEN.
     *
     * The rules go into one style element the editor owns, replaced in place — so changing the
     * paper size is a stylesheet swap, never a rebuild. Nothing typed, nothing selected and no
     * undo history is lost to picking a format, which is the whole requirement: the document
     * must not reload or reset.
     */
    applyPageFormat: function () {
      if (!this.documentView || !this.jodit) return;

      try {
        var body = this.jodit.editor;
        var doc = (body && body.ownerDocument) || this.jodit.ed;
        if (!doc || !doc.head) return;

        var tag = doc.getElementById('pg-page-format');
        if (!tag) {
          tag = doc.createElement('style');
          tag.id = 'pg-page-format';
          // Appended last so it wins over the same rules in Jodit's own iframe stylesheet,
          // which carried the format the editor was first built with.
          doc.head.appendChild(tag);
        }

        tag.textContent = this.formatStyle(this.pageFormat);

        // A new sheet is a new height, and the frame around it has to follow — on the next
        // frame, once the browser has laid the new rules out.
        var self = this;
        setTimeout(function () { self.resizeFrame(); }, 0);
      } catch (e) { /* the document keeps the format it was built with, which still reads */ }
    },

    /**
     * Size the frame to the sheet inside it.
     *
     * Jodit auto-resizes its iframe to the document, which is exactly what is wanted — but it
     * measures on its own schedule and, once the sheet is taller than the text, stops agreeing
     * that anything changed. The frame then stays the height of the LAST paragraph and the
     * page below it is simply not drawn, so there is no page to break.
     *
     * The formula is Jodit's own — the document body plus its vertical margins — so the two
     * never fight: whichever runs last computes the same number.
     */
    resizeFrame: function () {
      if (!this.documentView || !this.jodit) return;

      try {
        var frame = this.jodit.iframe;
        var body = this.jodit.editor;
        if (!frame || !body || !body.ownerDocument.defaultView) return;

        var box = body.ownerDocument.defaultView.getComputedStyle(body);
        var margins = (parseInt(box.marginTop, 10) || 0) + (parseInt(box.marginBottom, 10) || 0);

        frame.style.height = (body.offsetHeight + margins) + 'px';
      } catch (e) { /* the frame keeps the height Jodit gave it */ }
    },

    /**
     * Wire the `@` autocomplete onto this editor (mentions §1-§7, §26-§29).
     *
     * TributeJS attached directly to `jodit.editor`, rather than through the
     * `jodit-tributejs` package the requirement names. That package is pinned to Jodit ^3 and
     * registers through the Jodit 3 plugin API; the vendored build is 4.13.37, so it does not
     * attach at all — see public/assets/vendor/tribute/README.md. Its entire job was these
     * few lines, and doing them HERE is what §21/§22 asks for anyway: one central editor
     * configuration, so no form wires mentions up for itself.
     */
    attachMentions: function () {
      if (!this.mentionUrl || typeof window.Tribute === 'undefined' || !this.jodit || !this.jodit.editor) {
        return;
      }

      var self = this;

      this._tribute = new window.Tribute({
        trigger: '@',
        // Matched server-side, so nothing is filtered twice with two different rules.
        lookup: 'name',
        fillAttr: 'name',
        // §28: nothing is fetched until there is something to search for, and the request is
        // debounced — a workspace of two thousand people must not be downloaded because
        // somebody typed `@`.
        values: function (text, done) { self.searchMembers(text, done); },
        // §6: [avatar] name / email. The avatar falls back to the person's own colour, the
        // same one their disc wears everywhere else in the app.
        menuItemTemplate: function (item) {
          var u = item.original;
          var face = u.avatar_url
            ? '<img src="' + pgEsc(u.avatar_url) + '" alt="" class="pb-mention-face" />'
            : '<span class="pb-mention-face" style="background:' + pgEsc(u.avatar_color || '#475569') + '">'
              + pgEsc(u.initial || '?') + '</span>';

          return face + '<span class="pb-mention-who"><span class="pb-mention-name">' + pgEsc(u.name)
            + '</span><span class="pb-mention-email">' + pgEsc(u.email || '') + '</span></span>';
        },
        // §7/§8: the id is what is stored. The name is only what it reads as, so renaming
        // somebody later cannot break a mention that already exists.
        selectTemplate: function (item) {
          if (!item) return '';
          var u = item.original;

          return '<span class="pb-mention" data-mention-type="user" data-user-id="' + pgEsc(String(u.id))
            + '">@' + pgEsc(u.name) + '</span>&nbsp;';
        },
        // §29: the three things the popup can have to say.
        noMatchTemplate: function () {
          return '<span class="pb-mention-empty">' + (self._mentionFailed ? 'Unable to load members. Try again.' : 'No members found') + '</span>';
        },
        allowSpaces: false,
        menuItemLimit: 8,
        selectClass: 'pb-mention-active'
      });

      this._tribute.attach(this.jodit.editor);

      // Enter belongs to the menu while it is open, or picking somebody also breaks the
      // paragraph. Capture phase, ahead of Jodit's own Enter handling.
      this.jodit.editor.addEventListener('keydown', function (e) {
        if (self._tribute && self._tribute.isActive && (e.key === 'Enter' || e.key === 'Tab')) {
          e.stopPropagation();
        }
      }, true);
    },

    /**
     * Ask the server who matches, debounced (§28).
     *
     * A lookup that fails must not stop somebody writing (§29), so the callback is always
     * called — with an empty list — and the popup says so rather than hanging on "Searching".
     */
    searchMembers: function (text, done) {
      var self = this;
      clearTimeout(this._mentionTimer);

      this._mentionTimer = setTimeout(function () {
        var url = self.mentionUrl + (self.mentionUrl.indexOf('?') > -1 ? '&' : '?')
          + 'search=' + encodeURIComponent(text || '');

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
          .then(function (data) { self._mentionFailed = false; done(data.users || []); })
          .catch(function () { self._mentionFailed = true; done([]); });
      }, 250);
    },

    /** Move the built toolbar into the host, if one was named. */
    relocateToolbar: function () {
      if (!this.toolbarHost || !this.jodit) return;

      try {
        var host = document.querySelector(this.toolbarHost);
        var container = this.jodit.container || (this.$refs.area && this.$refs.area.parentNode);
        var toolbar = container && container.querySelector('.jodit-toolbar__box');

        if (host && toolbar) host.appendChild(toolbar);
      } catch (e) { /* the toolbar stays where Jodit put it, which still works */ }
    },
    isFocused: function () {
      try {
        return !!(this.jodit && this.jodit.editor && document.activeElement &&
          this.jodit.editor.contains(document.activeElement));
      } catch (e) { return false; }
    },
    html: function () {
      if (!this.jodit) return '';
      var value = this.jodit.value || '';

      // An empty document still emits scaffolding; normalise so an emptied editor round-trips
      // to "" rather than to markup the server would strip anyway.
      return value.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '').trim() === '' ? '' : value;
    },
    setHtml: function (html) {
      if (!this.jodit) return;
      try {
        this.jodit.value = html || '';
      } catch (e) { /* a parse failure costs the pre-filled text, not the editor */ }
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
      if (!this.jodit) return;
      clearTimeout(this._syncTimer);
      this.emitValue();
    },
    /**
     * Point Jodit's uploader at the project's own media endpoint.
     *
     * The endpoint takes files named `file-0`, `file-1`… and answers `{result:[{url,…}]}`, or
     * `{errorMessage}` — neither of which is Jodit's default shape, so every hook below is a
     * translation between the two. Nothing here decides policy; the server re-checks type,
     * size and permission on every upload.
     */
    uploaderConfig: function () {
      if (!this.mediaUpload) return { insertImageAsBase64URI: true };

      var token = document.querySelector('meta[name="csrf-token"]');

      return {
        url: this.mediaUpload,
        headers: { 'X-CSRF-TOKEN': token ? token.content : '' },
        filesVariableName: function (i) { return 'file-' + i; },
        isSuccess: function (resp) { return resp && !resp.errorMessage; },
        getMessage: function (resp) { return (resp && resp.errorMessage) || ''; },
        process: function (resp) {
          return {
            files: ((resp && resp.result) || []).map(function (f) { return f.url; }),
            error: (resp && resp.errorMessage) || null
          };
        },
        defaultHandlerSuccess: function (data) {
          var self = this;
          (data.files || []).forEach(function (url) { self.s.insertImage(url); });
        }
      };
    }
  },
  template: '<div class="pg-editor"><textarea ref="area"></textarea></div>'
};
