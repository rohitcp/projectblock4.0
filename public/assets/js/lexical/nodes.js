/* Wiki › the editor's own Lexical nodes.
   ------------------------------------------------------------------
   Everything the toolbar can insert that Lexical does not ship: page break, image, embed
   (YouTube · Figma · Excalidraw · X), poll, columns, equation, sticky note, collapsible
   container, date, horizontal rule. See docs/features/wiki-lexical-editor.md.

   Its own file because the editor component was already long, and because these are one kind
   of thing: each is a class that knows how to BE a piece of a document — how to draw itself,
   how to serialize, and how to come back from stored HTML.

   ------------------------------------------------------------------
   TWO RULES EVERY NODE HERE FOLLOWS, both learned the hard way:

   1. `class`, never a prototype chain. Lexical's node classes are real ES classes, so an ES5
      constructor cannot call `super` at all — and the failure happens at IMPORT, so the page
      fails to OPEN rather than failing to insert.

   2. exportDOM must survive the server. `RichTextSanitizer` drops anything not on its
      allowlist, and the autosave runs every document through it — so a node that exports a
      tag or attribute the sanitizer does not know about is destroyed on the first save. Every
      node below exports allowed tags plus `data-wk-*` attributes, which the sanitizer permits
      BECAUSE these nodes are rebuilt from them. The two files are a pair: add a node here,
      add its attribute there.
   ------------------------------------------------------------------ */

/** Escape before building a node's DOM by hand — all of this is user content. */
function wkNodeEsc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * The providers an embed node may point at, and how each turns a pasted URL into an embed.
 *
 * `hosts` is checked on BOTH sides — here and in RichTextSanitizer::EMBED_HOSTS — so neither
 * the client nor the server can widen the list alone. An `<iframe>` is a page inside the page;
 * an open one would let anyone paste a convincing login form onto a teammate's screen.
 *
 * X/Twitter has no iframe and no hosts: a live tweet needs Twitter's widgets.js fetched from
 * their CDN at runtime, which this app does not do for any dependency, and which would tell X
 * which of your pages embed which tweets. It renders as a link card instead.
 */
var WK_EMBEDS = {
  youtube: {
    label: 'YouTube video',
    placeholder: 'YouTube URL or video ID',
    hosts: ['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtube-nocookie.com'],
    /* youtube-nocookie, not youtube.com: it is the same player without the tracking cookie
       being set on a reader who never asked to watch anything. */
    toSrc: function (input) {
      var id = /^[\w-]{11}$/.test(input) ? input : wkYouTubeId(input);

      return id ? 'https://www.youtube-nocookie.com/embed/' + id : null;
    },
    ratio: '56.25%'
  },
  figma: {
    label: 'Figma document',
    placeholder: 'Figma file or prototype URL',
    hosts: ['figma.com', 'www.figma.com'],
    toSrc: function (input) {
      return /^https:\/\/([a-z0-9-]+\.)?figma\.com\//i.test(input)
        ? 'https://www.figma.com/embed?embed_host=share&url=' + encodeURIComponent(input)
        : null;
    },
    ratio: '62%'
  },
  excalidraw: {
    label: 'Excalidraw drawing',
    placeholder: 'Excalidraw share link',
    hosts: ['excalidraw.com', 'www.excalidraw.com', 'link.excalidraw.com'],
    // The scene lives on excalidraw.com, not in this document — the trade-off for not
    // vendoring a React-only drawing canvas. A shared link is what gets embedded.
    toSrc: function (input) {
      return /^https:\/\/([a-z0-9-]+\.)?excalidraw\.com\//i.test(input) ? input : null;
    },
    ratio: '62%'
  },
  tweet: {
    label: 'X post',
    placeholder: 'Link to a post on X',
    hosts: [],
    toSrc: function (input) {
      return /^https:\/\/(x\.com|twitter\.com)\/[^/]+\/status\/\d+/i.test(input) ? input : null;
    },
    ratio: null
  }
};

/** The eleven characters YouTube calls a video, out of any of the shapes it hands them out in. */
function wkYouTubeId(url) {
  var m = String(url || '').match(
    /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/
  );

  return m ? m[1] : null;
}

/** The colours a sticky note comes in. Keys are stored; the CSS knows the rest. */
var WK_STICKY_COLORS = ['yellow', 'pink', 'blue', 'green'];

/* Every node class, built once the vendored bundle has parsed. Populated by wkDefineNodes(). */
var WkNodes = {};

function wkDefineNodes() {
  if (WkNodes.image || typeof window === 'undefined' || !window.PBLexical) return WkNodes;

  var L = window.PBLexical;

  /* ================================================================
     Media
     ================================================================ */

  /**
   * An image — and a GIF, which is an image and not a node of its own.
   *
   * ROUND-TRIP FIRST: Lexical drops any DOM it has no node for, and the autosave then writes
   * the document back WITHOUT it, so a page with an image would lose it simply by being
   * opened. That is why this existed before the insert button did.
   */
  class WkImageNode extends L.DecoratorNode {
    constructor(src, alt, key) {
      super(key);
      this.__src = src || '';
      this.__alt = alt || '';
    }

    static getType() { return 'wk-image'; }
    static clone(n) { return new WkImageNode(n.__src, n.__alt, n.__key); }
    static importJSON(json) { return new WkImageNode(json.src, json.alt); }
    static importDOM() {
      return {
        img: () => ({
          conversion: (el) => ({
            node: new WkImageNode(el.getAttribute('src'), el.getAttribute('alt'))
          }),
          priority: 0
        })
      };
    }

    exportJSON() { return { type: 'wk-image', version: 1, src: this.__src, alt: this.__alt }; }
    exportDOM() { return { element: this.img() }; }
    createDOM() {
      var el = this.img();
      el.className = 'wk-img';

      return el;
    }
    img() {
      var el = document.createElement('img');
      el.setAttribute('src', this.__src);
      if (this.__alt) el.setAttribute('alt', this.__alt);

      return el;
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return false; }
  }

  /**
   * An embedded page: YouTube, Figma, Excalidraw — or an X post, which is a link card.
   *
   * ONE node for four providers rather than four near-identical ones. What differs between
   * them is a URL shape and an aspect ratio, both of which are data (WK_EMBEDS); what is the
   * same is everything a node has to do. Four classes would have been four places to fix the
   * next thing the sanitizer changes.
   *
   * The URL is re-checked against the provider's host list on the way IN as well as out —
   * `data-wk-src` arrives from stored HTML, which is user content, and a node built from it
   * must not be able to point the iframe somewhere the allowlist would have refused.
   */
  class WkEmbedNode extends L.DecoratorNode {
    constructor(provider, src, key) {
      super(key);
      this.__provider = WK_EMBEDS[provider] ? provider : 'youtube';
      this.__src = src || '';
    }

    static getType() { return 'wk-embed'; }
    static clone(n) { return new WkEmbedNode(n.__provider, n.__src, n.__key); }
    static importJSON(json) { return new WkEmbedNode(json.provider, json.src); }
    static importDOM() {
      var build = (el) => {
        var provider = el.getAttribute('data-wk-provider');
        var src = el.getAttribute('data-wk-src') || el.getAttribute('src') || '';

        return { node: new WkEmbedNode(provider, src) };
      };

      return {
        iframe: (el) => (el.getAttribute('data-wk') === 'embed'
          ? { conversion: build, priority: 2 }
          : null),
        figure: (el) => (el.getAttribute('data-wk') === 'embed'
          ? { conversion: build, priority: 2 }
          : null)
      };
    }

    exportJSON() {
      return { type: 'wk-embed', version: 1, provider: this.__provider, src: this.__src };
    }
    exportDOM() { return { element: this.frame() }; }
    createDOM() {
      var wrap = document.createElement('div');
      wrap.className = 'wk-embed wk-embed--' + this.__provider;
      wrap.appendChild(this.frame());

      return wrap;
    }

    /** The element that IS the embed — the same one stored and rendered, so they cannot drift. */
    frame() {
      var spec = WK_EMBEDS[this.__provider];

      // A link card, not an iframe: see WK_EMBEDS.tweet.
      if (!spec.ratio) {
        var card = document.createElement('figure');
        card.className = 'wk-tweet';
        card.setAttribute('data-wk', 'embed');
        card.setAttribute('data-wk-provider', this.__provider);
        card.setAttribute('data-wk-src', this.__src);
        card.innerHTML = '<a href="' + wkNodeEsc(this.__src) + '" target="_blank" rel="noopener noreferrer">'
          + wkNodeEsc(this.__src) + '</a>';

        return card;
      }

      var el = document.createElement('iframe');
      el.setAttribute('data-wk', 'embed');
      el.setAttribute('data-wk-provider', this.__provider);
      el.setAttribute('data-wk-src', this.__src);
      el.setAttribute('src', this.__src);
      el.setAttribute('title', spec.label);
      el.setAttribute('frameborder', '0');
      el.setAttribute('allowfullscreen', 'true');

      return el;
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return false; }
  }

  /* ================================================================
     Breaks and rules
     ================================================================ */

  /** A horizontal rule. Nothing inside it to edit. */
  class WkRuleNode extends L.DecoratorNode {
    static getType() { return 'wk-rule'; }
    static clone(n) { return new WkRuleNode(n.__key); }
    static importJSON() { return new WkRuleNode(); }
    static importDOM() {
      return { hr: () => ({ conversion: () => ({ node: new WkRuleNode() }), priority: 0 }) };
    }

    exportJSON() { return { type: 'wk-rule', version: 1 }; }
    exportDOM() { return { element: document.createElement('hr') }; }
    createDOM() {
      var el = document.createElement('hr');
      el.className = 'wk-rule';

      return el;
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return false; }
  }

  /**
   * A deliberate page break — where the author says the page ends, rather than where the sheet
   * happens to run out (docs/features/wiki-page-format.md).
   *
   * Drawn as a labelled line while editing and printed as a real `break-after: page`. On a
   * paperless page it still shows: it is a statement about the document, and it comes back the
   * moment a sheet is chosen again.
   */
  class WkPageBreakNode extends L.DecoratorNode {
    static getType() { return 'wk-page-break'; }
    static clone(n) { return new WkPageBreakNode(n.__key); }
    static importJSON() { return new WkPageBreakNode(); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'page-break'
          ? { conversion: () => ({ node: new WkPageBreakNode() }), priority: 2 }
          : null)
      };
    }

    exportJSON() { return { type: 'wk-page-break', version: 1 }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('div');
      el.className = 'wk-pagebreak';
      el.setAttribute('data-wk', 'page-break');

      return el;
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return false; }
  }

  /* ================================================================
     Interactive
     ================================================================ */

  /**
   * A poll: a question, some options, and who voted for what.
   *
   * Votes live IN THE DOCUMENT and are saved by the page's autosave, the way the Lexical
   * playground does it. The honest consequence, recorded here because it is not obvious from
   * the UI: voting is a document write, so only people who can edit the page can vote, and
   * two people voting at the same second will have one overwrite the other. Moving votes to
   * their own table is what fixes both, and is a deliberate later decision rather than an
   * oversight.
   *
   * The whole poll is stored as JSON in one attribute. It is parsed defensively on the way
   * back in — it is user content, and a crafted value should cost the poll, not the page.
   */
  class WkPollNode extends L.DecoratorNode {
    constructor(question, options, key) {
      super(key);
      this.__question = question || 'Untitled poll';
      this.__options = Array.isArray(options) && options.length
        ? options.map(function (o) {
          return { text: String((o && o.text) || ''), votes: parseInt((o && o.votes) || 0, 10) || 0 };
        })
        : [{ text: 'Yes', votes: 0 }, { text: 'No', votes: 0 }];
    }

    static getType() { return 'wk-poll'; }
    static clone(n) { return new WkPollNode(n.__question, n.__options, n.__key); }
    static importJSON(json) { return new WkPollNode(json.question, json.options); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'poll'
          ? {
            conversion: (node) => {
              var data = {};
              try { data = JSON.parse(node.getAttribute('data-wk-poll') || '{}') || {}; } catch (e) { data = {}; }

              return { node: new WkPollNode(data.question, data.options) };
            },
            priority: 2
          }
          : null)
      };
    }

    exportJSON() {
      return {
        type: 'wk-poll', version: 1, question: this.__question, options: this.__options
      };
    }
    exportDOM() { return { element: this.build(false) }; }
    createDOM(config, editor) { return this.build(true, editor); }

    /**
     * @param live  wire the vote buttons — only true for the copy on screen. The exported
     *              copy is the same markup with no listeners, so a reader outside the editor
     *              sees the results and cannot silently vote into HTML nobody will save.
     */
    build(live, editor) {
      var self = this;
      var total = this.__options.reduce(function (sum, o) { return sum + o.votes; }, 0);

      var el = document.createElement('div');
      el.className = 'wk-poll';
      el.setAttribute('data-wk', 'poll');
      el.setAttribute('data-wk-poll', JSON.stringify({
        question: this.__question, options: this.__options
      }));

      var head = document.createElement('p');
      head.className = 'wk-poll__q';
      head.textContent = this.__question;
      el.appendChild(head);

      this.__options.forEach(function (option, index) {
        var share = total ? Math.round((option.votes / total) * 100) : 0;

        var row = document.createElement(live ? 'button' : 'div');
        row.className = 'wk-poll__row';
        if (live) row.setAttribute('type', 'button');
        row.innerHTML = '<span class="wk-poll__bar" style="width:' + share + '%"></span>'
          + '<span class="wk-poll__text">' + wkNodeEsc(option.text) + '</span>'
          + '<span class="wk-poll__count">' + option.votes + '</span>';

        if (live && editor) {
          row.addEventListener('click', function (e) {
            e.preventDefault();
            // Through the editor, never by mutating this DOM: the node is the truth and the
            // DOM is its projection, so a vote is an update like any other edit — which is
            // also what puts it in the undo history and in the autosave.
            editor.update(function () {
              var node = L.$getNodeByKey(self.getKey());
              if (node) node.vote(index);
            });
          });
        }

        el.appendChild(row);
      });

      return el;
    }

    /** One vote for one option. Writable-copy first, as every Lexical mutation must be. */
    vote(index) {
      var writable = this.getWritable();
      var options = writable.__options.map(function (o) { return { text: o.text, votes: o.votes }; });
      if (!options[index]) return;
      options[index].votes += 1;
      writable.__options = options;
    }

    updateDOM() {
      // The bars and counts change on every vote, so the DOM cannot be reused — returning
      // true is what tells Lexical to draw it again.
      return true;
    }
    decorate() { return null; }
    isInline() { return false; }
  }

  /**
   * A sticky note.
   *
   * An ElementNode, not a decorator: the point of a sticky note is that you type in it, and
   * everything Lexical already does — selection, formatting, undo — should keep working
   * inside one. The colour is the only thing the node itself carries.
   */
  class WkStickyNode extends L.ElementNode {
    constructor(color, key) {
      super(key);
      this.__color = WK_STICKY_COLORS.indexOf(color) > -1 ? color : 'yellow';
    }

    static getType() { return 'wk-sticky'; }
    static clone(n) { return new WkStickyNode(n.__color, n.__key); }
    static importJSON(json) { return new WkStickyNode(json.color); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'sticky'
          ? {
            conversion: (node) => ({ node: new WkStickyNode(node.getAttribute('data-wk-color')) }),
            priority: 2
          }
          : null)
      };
    }

    exportJSON() {
      return { type: 'wk-sticky', version: 1, color: this.__color };
    }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('div');
      el.className = 'wk-sticky wk-sticky--' + this.__color;
      el.setAttribute('data-wk', 'sticky');
      el.setAttribute('data-wk-color', this.__color);

      return el;
    }
    updateDOM(prev, dom) {
      if (prev.__color === this.__color) return false;
      dom.className = 'wk-sticky wk-sticky--' + this.__color;
      dom.setAttribute('data-wk-color', this.__color);

      return false;
    }
  }

  /* ================================================================
     Structure
     ================================================================ */

  /**
   * A columns layout: a container of equal-width items you can type into.
   *
   * ElementNodes for both, because the whole point is that the columns hold document — a
   * decorator would give you a box that Lexical cannot see inside.
   */
  class WkLayoutNode extends L.ElementNode {
    constructor(columns, key) {
      super(key);
      this.__columns = Math.min(4, Math.max(2, parseInt(columns, 10) || 2));
    }

    static getType() { return 'wk-layout'; }
    static clone(n) { return new WkLayoutNode(n.__columns, n.__key); }
    static importJSON(json) { return new WkLayoutNode(json.columns); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'layout'
          ? {
            conversion: (node) => ({
              // Counted from the children rather than read from an attribute: the children
              // are what actually exist, and a mismatch would draw empty columns.
              node: new WkLayoutNode(node.children.length)
            }),
            priority: 2
          }
          : null)
      };
    }

    exportJSON() { return { type: 'wk-layout', version: 1, columns: this.__columns }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('div');
      el.className = 'wk-layout';
      el.setAttribute('data-wk', 'layout');
      // Inline, not a class per count: the sanitizer allows `style` on a div, and a rule per
      // possible column count is four rules that all say the same thing.
      el.style.gridTemplateColumns = 'repeat(' + this.__columns + ', minmax(0, 1fr))';

      return el;
    }
    updateDOM() { return false; }
  }

  class WkLayoutItemNode extends L.ElementNode {
    static getType() { return 'wk-layout-item'; }
    static clone(n) { return new WkLayoutItemNode(n.__key); }
    static importJSON() { return new WkLayoutItemNode(); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'layout-item'
          ? { conversion: () => ({ node: new WkLayoutItemNode() }), priority: 2 }
          : null)
      };
    }

    exportJSON() { return { type: 'wk-layout-item', version: 1 }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('div');
      el.className = 'wk-layout__item';
      el.setAttribute('data-wk', 'layout-item');

      return el;
    }
    updateDOM() { return false; }
  }

  /**
   * A collapsible container — `<details>` with a `<summary>` title and a body.
   *
   * The native elements rather than a pair of divs plus JavaScript: a collapsible written here
   * still opens and closes when the stored HTML is rendered OUTSIDE this editor, which is
   * where most people will read it. That is also why `open` had to be added to the sanitizer.
   */
  class WkCollapsibleNode extends L.ElementNode {
    constructor(open, key) {
      super(key);
      this.__open = open !== false;
    }

    static getType() { return 'wk-collapsible'; }
    static clone(n) { return new WkCollapsibleNode(n.__open, n.__key); }
    static importJSON(json) { return new WkCollapsibleNode(json.open); }
    static importDOM() {
      return {
        details: (el) => ({
          conversion: (node) => ({ node: new WkCollapsibleNode(node.hasAttribute('open')) }),
          priority: 2
        })
      };
    }

    exportJSON() { return { type: 'wk-collapsible', version: 1, open: this.__open }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() {
      var el = this.build();

      // Remembering the twist is a document edit, so it goes through the editor rather than
      // being left in the DOM where the next save would not see it.
      el.addEventListener('toggle', () => {
        var open = el.open;
        if (open === this.__open) return;
        this.getWritable().__open = open;
      });

      return el;
    }
    build() {
      var el = document.createElement('details');
      el.className = 'wk-collapsible';
      if (this.__open) el.setAttribute('open', '');

      return el;
    }
    updateDOM() { return false; }
  }

  class WkCollapsibleTitleNode extends L.ElementNode {
    static getType() { return 'wk-collapsible-title'; }
    static clone(n) { return new WkCollapsibleTitleNode(n.__key); }
    static importJSON() { return new WkCollapsibleTitleNode(); }
    static importDOM() {
      return {
        summary: () => ({ conversion: () => ({ node: new WkCollapsibleTitleNode() }), priority: 2 })
      };
    }

    exportJSON() { return { type: 'wk-collapsible-title', version: 1 }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('summary');
      el.className = 'wk-collapsible__title';

      return el;
    }
    updateDOM() { return false; }
  }

  class WkCollapsibleContentNode extends L.ElementNode {
    static getType() { return 'wk-collapsible-content'; }
    static clone(n) { return new WkCollapsibleContentNode(n.__key); }
    static importJSON() { return new WkCollapsibleContentNode(); }
    static importDOM() {
      return {
        div: (el) => (el.getAttribute('data-wk') === 'collapsible-content'
          ? { conversion: () => ({ node: new WkCollapsibleContentNode() }), priority: 2 }
          : null)
      };
    }

    exportJSON() { return { type: 'wk-collapsible-content', version: 1 }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('div');
      el.className = 'wk-collapsible__body';
      el.setAttribute('data-wk', 'collapsible-content');

      return el;
    }
    updateDOM() { return false; }
  }

  /* ================================================================
     Inline
     ================================================================ */

  /**
   * A LaTeX equation, inline or as its own block.
   *
   * THE LATEX IS WHAT IS STORED, never the rendered output. Typeset markup is long, brittle,
   * and unfixable once the source is gone; the source is short, editable, and can be rendered
   * again by whatever comes after KaTeX. `data-wk-tex` is the document; everything visible is
   * derived from it on load.
   *
   * Rendered as MathML rather than KaTeX's HTML output, so no stylesheet and no font files
   * have to be vendored — see public/assets/vendor/katex/README.md.
   */
  class WkEquationNode extends L.DecoratorNode {
    constructor(tex, inline, key) {
      super(key);
      this.__tex = tex || '';
      this.__inline = inline === true;
    }

    static getType() { return 'wk-equation'; }
    static clone(n) { return new WkEquationNode(n.__tex, n.__inline, n.__key); }
    static importJSON(json) { return new WkEquationNode(json.tex, json.inline); }
    static importDOM() {
      var build = (el) => ({
        node: new WkEquationNode(
          el.getAttribute('data-wk-tex') || el.textContent,
          el.getAttribute('data-wk-inline') === '1'
        )
      });
      var match = (el) => (el.getAttribute('data-wk') === 'equation'
        ? { conversion: build, priority: 2 }
        : null);

      return { span: match, div: match };
    }

    exportJSON() {
      return { type: 'wk-equation', version: 1, tex: this.__tex, inline: this.__inline };
    }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement(this.__inline ? 'span' : 'div');
      el.className = this.__inline ? 'wk-eq wk-eq--inline' : 'wk-eq';
      el.setAttribute('data-wk', 'equation');
      el.setAttribute('data-wk-tex', this.__tex);
      if (this.__inline) el.setAttribute('data-wk-inline', '1');

      // KaTeX may be absent (a checkout that has not vendored it) or may refuse the input —
      // a half-typed formula is the normal case, not an error. Either way the LaTeX itself is
      // shown, which is still readable and still editable.
      try {
        window.katex.render(this.__tex, el, {
          output: 'mathml',
          throwOnError: false,
          displayMode: !this.__inline
        });
      } catch (e) {
        el.textContent = this.__tex;
      }

      return el;
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return this.__inline; }
  }

  /**
   * A date.
   *
   * The ISO value is stored and the readable text is derived, so the same document reads
   * "6 September 2026" for one person and whatever their locale says for another — and a
   * search or a later feature that wants to sort by it has something to sort.
   */
  class WkDateNode extends L.DecoratorNode {
    constructor(iso, key) {
      super(key);
      this.__iso = /^\d{4}-\d{2}-\d{2}$/.test(String(iso || '')) ? iso : '';
    }

    static getType() { return 'wk-date'; }
    static clone(n) { return new WkDateNode(n.__iso, n.__key); }
    static importJSON(json) { return new WkDateNode(json.iso); }
    static importDOM() {
      return {
        span: (el) => (el.getAttribute('data-wk') === 'date'
          ? {
            conversion: (node) => ({ node: new WkDateNode(node.getAttribute('data-wk-date')) }),
            priority: 2
          }
          : null)
      };
    }

    exportJSON() { return { type: 'wk-date', version: 1, iso: this.__iso }; }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var el = document.createElement('span');
      el.className = 'wk-date';
      el.setAttribute('data-wk', 'date');
      el.setAttribute('data-wk-date', this.__iso);
      el.textContent = this.readable();

      return el;
    }
    readable() {
      if (!this.__iso) return '—';

      // Parsed as UTC noon: a plain 'YYYY-MM-DD' is midnight UTC, which is the previous day
      // for anyone west of Greenwich, and a date that shifts by timezone is a bug people
      // report as "it shows yesterday".
      var d = new Date(this.__iso + 'T12:00:00Z');

      return isNaN(d.getTime())
        ? this.__iso
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    }
    updateDOM() { return false; }
    decorate() { return null; }
    isInline() { return true; }
  }

  /**
   * A comment anchor — the run of text a thread is attached to
   * (docs/features/wiki-comments.md).
   *
   * A subclass of Lexical's own MarkNode, which already models "this text is referenced by
   * these ids" and already survives being typed through, split and merged. What it does NOT do
   * is put the ids in the DOM: `createDOM` writes a bare `<mark>` and there is no exportDOM at
   * all, so the ids would be lost the first time the page was saved and every thread would
   * come back anchored to nothing. That is the whole reason this subclass exists.
   *
   * The node carries ids and NOTHING ELSE. No author, no text, no timestamp — those live in
   * the database, keyed by the id, which is what makes replies, resolve, permissions, history
   * and real-time possible. A comment baked into a document has none of them.
   *
   * Several ids on one mark is normal, not an edge case: two people commenting on overlapping
   * passages is exactly what happens, and `wk-comment--stacked` is what says so.
   */
  class WkCommentNode extends L.MarkNode {
    /**
     * Lexical 0.50 registers a node through `$config()`, not through `static getType()` alone.
     * MarkNode declares `this.config('mark', …)`, and an inherited one registers THIS class
     * under the parent's type — so the exporter looks up MarkNode's own HTML config and the
     * mark comes out as a bare `<span>`, ids and all gone, on the first save. Declaring the
     * subclass's own config is what makes exportDOM below the one that actually runs.
     */
    $config() { return this.config('wk-comment', { extends: L.MarkNode }); }

    static getType() { return 'wk-comment'; }
    static clone(node) { return new WkCommentNode(node.getIDs(), node.__key); }
    static importJSON(json) { return new WkCommentNode(json.ids || []); }
    static importDOM() {
      return {
        mark: (el) => (el.getAttribute('data-wk') === 'comment'
          ? {
            conversion: (node) => {
              var ids = String(node.getAttribute('data-wk-threads') || '')
                .split(/\s+/)
                .filter(Boolean);

              // A mark with no ids left is not an anchor any more — returning null lets its
              // text through as ordinary text rather than as a highlight nothing explains.
              return ids.length ? { node: new WkCommentNode(ids) } : null;
            },
            priority: 2
          }
          : null)
      };
    }

    exportJSON() {
      return { type: 'wk-comment', version: 1, ids: this.getIDs() };
    }
    exportDOM() { return { element: this.build() }; }
    createDOM() { return this.build(); }
    build() {
      var ids = this.getIDs();
      var el = document.createElement('mark');

      el.className = 'wk-comment' + (ids.length > 1 ? ' wk-comment--stacked' : '');
      el.setAttribute('data-wk', 'comment');
      el.setAttribute('data-wk-threads', ids.join(' '));

      return el;
    }
    // The ids change as threads are added to or removed from a passage, and the class and the
    // attribute both follow — so `false`, meaning "I updated it myself, do not rebuild".
    updateDOM(prev, dom) {
      var ids = this.getIDs();

      dom.className = 'wk-comment' + (ids.length > 1 ? ' wk-comment--stacked' : '');
      dom.setAttribute('data-wk-threads', ids.join(' '));

      return false;
    }
    isInline() { return true; }

    /**
     * Stay in the HTML.
     *
     * MarkNode returns `destination !== 'clone'`, which means "leave me out of the HTML" —
     * a sensible default for a transient highlight, and exactly wrong for a comment anchor.
     * With it inherited, the exporter walks straight past the `<mark>` and emits only its
     * children: the document saves with the passage intact, the anchor gone, and every thread
     * flagged "referenced text deleted" on the next load. The symptom looks like a comment bug
     * and is a one-line inheritance.
     */
    excludeFromCopy() { return false; }
  }

  /**
   * An `@mention` chip.
   *
   * A TextNode subclass, not a decorator: a mention behaves like a word. Backspace should
   * delete it whole, the caret should step over it, selection should include it, and copying a
   * paragraph should bring it along — all of which a TextNode already does and a decorator
   * would have to reimplement badly.
   *
   * THE ID IS WHAT IS STORED; the name is only what it reads as. Renaming somebody later must
   * not break a mention that already exists, which is why `data-user-id` is the payload and
   * the text is derived. The same markup the rest of the app's mentions use
   * (`RichTextSanitizer` already allows it), so a wiki mention and a work-item mention are the
   * same thing to every reader of the stored HTML.
   *
   * It survives as DATA, never as authority: a crafted `data-user-id` reaching the database is
   * expected and harmless — whatever acts on a mention re-checks that the id names a real,
   * mentionable person.
   */
  class WkMentionNode extends L.TextNode {
    $config() { return this.config('wk-mention', { extends: L.TextNode }); }

    static getType() { return 'wk-mention'; }
    static clone(node) {
      return new WkMentionNode(node.__mentionName, node.__userId, node.__key);
    }
    static importJSON(json) { return new WkMentionNode(json.mentionName, json.userId); }
    static importDOM() {
      return {
        span: (el) => (el.getAttribute('data-mention-type') === 'user'
          ? {
            conversion: (node) => ({
              node: new WkMentionNode(
                String(node.textContent || '').replace(/^@/, ''),
                node.getAttribute('data-user-id')
              )
            }),
            priority: 2
          }
          : null)
      };
    }

    constructor(name, userId, key) {
      super('@' + String(name || ''), key);
      this.__mentionName = String(name || '');
      this.__userId = String(userId || '');
    }

    exportJSON() {
      return Object.assign({}, super.exportJSON(), {
        type: 'wk-mention',
        version: 1,
        mentionName: this.__mentionName,
        userId: this.__userId
      });
    }
    exportDOM() { return { element: this.build() }; }
    createDOM(config) {
      var el = this.build();
      // The base class applies the theme's text formats (bold, italic…) to the element it is
      // given, so a mention inside bold text still reads as bold.
      L.TextNode.prototype.createDOM.call(this, config);

      return el;
    }
    build() {
      var el = document.createElement('span');

      el.className = 'pb-mention';
      el.setAttribute('data-mention-type', 'user');
      el.setAttribute('data-user-id', this.__userId);
      el.textContent = '@' + this.__mentionName;

      return el;
    }
    updateDOM() { return false; }
    /** One unit. Typing inside a mention would leave a chip whose text no longer names anyone. */
    isTextEntity() { return true; }
    canInsertTextBefore() { return false; }
    canInsertTextAfter() { return false; }
  }

  WkNodes = {
    comment: WkCommentNode,
    mention: WkMentionNode,
    image: WkImageNode,
    embed: WkEmbedNode,
    rule: WkRuleNode,
    pageBreak: WkPageBreakNode,
    poll: WkPollNode,
    sticky: WkStickyNode,
    layout: WkLayoutNode,
    layoutItem: WkLayoutItemNode,
    collapsible: WkCollapsibleNode,
    collapsibleTitle: WkCollapsibleTitleNode,
    collapsibleContent: WkCollapsibleContentNode,
    equation: WkEquationNode,
    date: WkDateNode
  };

  return WkNodes;
}

/** Every class, in the order createEditor wants them: a flat list of what the document can hold. */
function wkNodeClasses() {
  wkDefineNodes();

  return Object.keys(WkNodes).map(function (key) { return WkNodes[key]; });
}
