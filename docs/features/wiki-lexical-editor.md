# Wiki Editor — Lexical

Extends [Wiki & Knowledge Management](wiki.md). Reference implementation:
[the Lexical playground](https://playground.lexical.dev/).

## Requirement

The Wiki page editor is **Lexical** (Meta, MIT), replacing Jodit.

## Scope

| Screen | Editor | |
|---|---|---|
| **Wiki page** | `<wk-editor>` — Lexical, full document | page formats, comments, the Insert set |
| **Work item drawer** | `<wk-editor minimal>` — Lexical, field | description · comment · reply · status update |
| Project Pages | `<pg-editor>` — Jodit | unchanged |
| Other screens | `<wi-editor>` — Quill | unchanged |

The Wiki went first, alone, so a regression had one place to come from. The work item drawer
followed and took **all four** of its rich-text boxes at once — leaving one behind would mean
the drawer loaded three editors to show four fields. Jodit and the Quill fallback behind it are
both gone from that screen, and with them `useJodit`, `editorButtons` and `editorLicense`.

**`minimal` is a prop, not a second component.** A second Lexical editor would be a second copy
of the same mount, the same HTML in-and-out and the same node registration, free to drift the
first time one of them is fixed. What `minimal` turns off is the document furniture — page
formats, the block dropdown, font and size, colour, alignment, indent, the Insert menu,
comments and pagination — and it renders the toolbar inline above the field instead of
teleporting it into a screen-wide bar.

What a field keeps: undo/redo · bold, italic, underline, strikethrough, clear formatting ·
bulleted and numbered lists and quote · link · **image, uploaded or by URL** · **Insert** ·
`@` mentions.

Lists get their own buttons in `minimal` only, because the dropdown that holds them in the full
editor is hidden — and a comment without a bullet list is a comment people write with a dash.

Sub/superscript and inline code are **not** in a field: writing H₂O or a code span is document
work, and three buttons nobody presses is three buttons in the way. **Page break** is the one
Insert item a field does not get — it has no sheet, no pagination and nothing to print, so it
is left out rather than left to do nothing when pressed.

### Pasting and dropping images

Copying an image in a browser puts a **bitmap on the clipboard, not markup** — there is no
`<img>` to import and no URL to keep, so nothing in Lexical's own paste handling has anything
to do with it and the paste silently does nothing. `pasteFiles()` catches the files, uploads
them through the same endpoint the picker uses, and inserts what comes back. Drag-and-drop is
the same act with a different gesture and goes through the same path.

It is a **native capture-phase listener on the root element**, not a `PASTE_COMMAND` handler.
Lexical's rich-text registration has its own file-paste path, and a command listener — even at
`COMMAND_PRIORITY_CRITICAL` — does not stop it: the upload lands AND a second bare `<img>` with
no `src` appears beside it. Capture phase on the element itself runs first, so
`stopImmediatePropagation()` is what keeps one paste to one image.

**Drop needs `dragenter` prevented as well as `dragover`.** Chrome only treats an element as a
drop target when both are; with only `dragover` the cursor says "no", the drop event never
fires, and the browser navigates away to the file — which looks exactly like "drag and drop
does not work in the editor". The editor also draws a dashed outline while a file is over it,
because a drop target that looks identical to the page around it is one people do not believe
in, and they go back to the toolbar.

**Images are capped to the space they are in.** `max-width: 100%` alone is not enough: a
screenshot is typically 1600px wide and 2000px TALL, and scaling it to the column width still
leaves something that fills the screen and pushes the Comment button out of reach. So a
document image is capped at `70vh` and a field's at `320px`, with `object-fit: contain` keeping
the proportions. The same caps apply to `.wi-rich`, so a posted comment reads the same as it
did while being written.

Files are uploaded **one at a time**, not as a batch: the endpoint fails a whole batch when any
file in it is rejected, so pasting three screenshots and one PDF would lose the three.

### Images from other sites are removed, and said so

Pasting a region of a web page brings `<img src="https://someone-else.com/…">` with it. It
imports and it looks pasted — and then `RichTextSanitizer` strips the `src` on the next save,
because an image may only come from this application's own origin or the named embed hosts.
Hotlinking somebody else's server leaks every reader's IP to them and breaks the moment they
move the file.

So `pruneRemoteImages()` removes them in the editor and says why. A visible message beats an
image that quietly turns into an empty box after a reload — which is what happened before, and
reads as the editor losing your work. Uploads and drops are unaffected: those become this
origin's own URLs.

Keeping a pasted web image would mean the server fetching and re-hosting it, which is a real
feature with real care needed around what a URL may point at. Not done.

### A caret has to exist before anything is inserted into it

Every insert goes through the selection, and a toolbar can be reached without the editor ever
having been focused: click Insert → Table in an untouched comment box and there is no selection
to insert into, so the command runs and silently does nothing. Pressing a button and watching
nothing happen is the worst failure mode — it reads as broken rather than as refused.

`withCaret()` focuses the **root element** and places a caret at the end of the document before
running the insert. Two details it took a while to find:

- **Not `editor.focus()`.** Lexical's own focus deliberately does not steal DOM focus, so on an
  untouched editor it sets a selection the next reconcile drops, and the command still finds
  nothing.
- **The popover's action buttons need `@mousedown.prevent`**, exactly like the toolbar's.
  Without it the button takes focus *after* the handler has run and blurs the editor the
  handler just focused.

The files live at `assets/js/lexical/` and load through `partials.lexical-editor`, not under
`wiki/`: two screens mount them now, and a path that says `wiki` for a shared editor is a lie
the next person has to discover.

## What the editor can do

| Group | Properties |
|---|---|
| History | undo, redo (buttons disable when there is nothing to undo) |
| Block | a dropdown — see below |
| Type | font family, font size |
| Inline | bold, italic, underline, strikethrough, subscript, superscript, inline code, clear formatting |
| Colour | text colour, highlight — one shared palette |
| Lists | bulleted, numbered, indent, outdent |
| Alignment | left, centre, right, justify |
| Link | add, remove; a bare domain gets `https://` |
| Image | upload, or by URL — its own button, not a menu line |
| Insert | ten more nodes — see the table below |
| Page | A4 / Letter / Legal / Paperless ([wiki-page-format.md](wiki-page-format.md)) |

Plus HTML in and out, and paste — including from Word — through Lexical's own rich-text paste.

### The block-type dropdown

Normal · Heading 1–5 · Numbered list · Bullet list · Check list · Quote · Code block.

A dropdown rather than a button each: eleven block types is more than a toolbar can show, and
the one in force is worth **naming** rather than leaving you to work out which button is lit.
One list in `blockOptions` feeds both the menu and `setBlock`, so the two cannot drift.

**"Normal" is the paragraph.** The requested list named it twice — once as *Normal* and once as
*P* — and two entries that do the same thing is a menu you have to stop and think about, so
they are one.

Two of these needed more than a label:

- **Check list** — `registerCheckList` draws nothing; it is what makes the boxes tick. The box
  itself is CSS on the list item, so a ticked item still reads as ticked when the stored HTML
  is rendered outside the editor with nothing listening for clicks.
- **Code block** — `@lexical/code`, with `registerCodeHighlighting` tokenising as you type.
  Without it a code block is a monospaced box and nothing more. `CodeHighlightNode` must be
  registered alongside `CodeNode`: the highlighter builds one per token, and an unregistered
  node type takes the editor down the first time somebody types inside a block.

Both also needed the sanitizer — see below.

**Font, size, colour and highlight are written as inline style, not as classes.** The stored
HTML is rendered outside this editor too (the read-only view, and anything that shows a page's
body); a class would need every one of those to know about it, an inline style needs nobody.
`clear formatting` therefore clears both kinds — otherwise the text is still 24px and still
purple after pressing it, which is not what the button says.

## The Insert menu

One menu rather than a row of buttons: the set is ten items and growing, and a toolbar stops
being readable at about eight.

**Image is the exception and sits on the toolbar itself** — it is the one thing people reach for
constantly, and two clicks for the common case is one too many. It is not in both places: a
second way to do the same thing is a second thing to keep in step.

| Node | Class | Stored as | Notes |
|---|---|---|---|
| Image / GIF | `WkImageNode` | `<img src alt>` | Upload or by URL. A GIF is an image, not a node of its own. |
| Table | `@lexical/table` | `<table>` | Rows × columns; cells hold document. |
| YouTube | `WkEmbedNode` | `<iframe data-wk-provider="youtube">` | `youtube-nocookie.com` — the same player without a tracking cookie on a reader who never pressed play. |
| Figma | `WkEmbedNode` | `<iframe data-wk-provider="figma">` | File or prototype URL through Figma's `/embed`. |
| Excalidraw | `WkEmbedNode` | `<iframe data-wk-provider="excalidraw">` | A share link. **The drawing lives on excalidraw.com**, not in this document — see below. |
| X post | `WkEmbedNode` | `<figure class="wk-tweet">` | A link card, not a live tweet — see below. |
| Poll | `WkPollNode` | `<div data-wk-poll="{…}">` | Question, options, votes — as JSON in one attribute. |
| Columns layout | `WkLayoutNode` + item | `<div data-wk="layout">` | 2–4 columns; each holds document. |
| Collapsible | `WkCollapsibleNode` + title/content | `<details><summary>` | Native elements, so it still opens outside the editor. |
| Equation | `WkEquationNode` | `<span data-wk-tex="…">` | LaTeX, inline or block. |
| Sticky note | `WkStickyNode` | `<div data-wk="sticky">` | Four colours; holds document. |
| Date | `WkDateNode` | `<span data-wk-date="…">` | ISO stored, readable text derived. |
| Horizontal rule | `WkRuleNode` | `<hr>` | |
| Page break | `WkPageBreakNode` | `<div data-wk="page-break">` | A labelled line while editing, a real `break-after: page` on paper. |

All of them live in `public/assets/js/lexical/nodes.js`.

## Three decisions inside that list

**Excalidraw is an embed, not an editor.** `@excalidraw/excalidraw` is React-only; using it
would mean vendoring React and mounting a React root inside a Vue page inside a Lexical
decorator, for one node. The node holds an excalidraw.com share link instead. The trade-off,
stated plainly because it is not visible from the UI: **the drawing is not in your document or
your database** — it is on excalidraw.com, and it is as durable as that link.

**X posts are link cards.** A live tweet needs Twitter's `widgets.js` fetched from their CDN at
runtime. This app vendors every dependency and fetches nothing at runtime (see any
`public/assets/vendor/*/README.md`), and the script would also tell X which of your pages embed
which posts. YouTube and Figma need no script, so those are real embeds.

**Poll votes live in the document**, saved by the page autosave, the way the playground does it.
The consequence, which the UI does not show: voting is a document write, so only people who can
edit the page can vote, and two people voting in the same second will have one overwrite the
other. Moving votes to their own table fixes both and is a deliberate later decision.

## The sanitizer is half of this feature

`RichTextSanitizer` runs over every document on save and drops anything not on its allowlist.
A node that exports a tag or an attribute the sanitizer does not know about is **destroyed on
the first autosave** — so the two files are a pair, and adding a node means editing both:

- `details` / `summary` and the `open` attribute were added for the collapsible container.
- `aria-checked` on `li` and `data-is-checklist` on `ul`/`ol` were added for check lists. A
  check list's whole state lives in these: `aria-checked` per item is the tick, and its
  presence on any child is also how a plain `<ul>` is recognised as a check list at all.
  Without them every check list comes back as bullets with the ticks gone.
- `data-language` / `data-highlight-language` on `pre` keep a code block's language, which is
  what the highlighter colours by.
- `data-wk`, `data-wk-provider`, `data-wk-src`, `data-wk-poll`, `data-wk-tex`, `data-wk-inline`,
  `data-wk-color`, `data-wk-date` are allowed on `div`, `span`, `figure`, `iframe`, `details` —
  because the nodes are **rebuilt from them** when a page is opened.
- `EMBED_HOSTS` adds figma.com and excalidraw.com to the iframe allowlist. An `<iframe>` is a
  page inside the page; an open one would let anyone paste a convincing login form onto a
  teammate's screen. The list exists on both sides — sanitizer and `WK_EMBEDS` — so neither
  can widen it alone.

Everything allowed there is allowed as **data, never as authority**: `data-wk-src` is re-checked
against the provider's host list when the node is built, and `data-wk-poll` is parsed with every
field defaulted. A crafted value costs the node, not the page.

## Image upload

`POST /wiki/collections/{collection}/pages/media` → `{result:[{url,name,size}]}`, the same shape
every uploader in this app already answers, so the client code is written once.

Files go on the **private** disk and are streamed by
`GET /wiki/collections/{collection}/pages/media/{media}` behind the collection's own read check —
so a reader who may open the collection sees its images, and nobody else does. The public disk
would hand out a URL that works for anyone who receives it, regardless of workspace.

Addressed by **collection**, not by page: the URL is baked into the page's stored HTML at upload
time and has to keep resolving afterwards, including if the image is moved to another page.
Limits live in `config('wiki.media')`.

## `@` mentions

Type `@` and a people picker opens under the caret; arrows move, Enter or Tab chooses, Escape
closes, a click picks. Choosing inserts a chip and a trailing space — without the space the
caret sits inside the chip and the next character typed becomes part of somebody's name.

**`WkMentionNode` extends `TextNode`, not a decorator.** A mention behaves like a word:
Backspace deletes it whole, the caret steps over it, selection includes it, copying a paragraph
brings it along. All of that a TextNode already does and a decorator would reimplement badly.

**The id is what is stored; the name is only what it reads as** — renaming somebody later must
not break a mention that already exists. It exports the same
`<span class="pb-mention" data-mention-type="user" data-user-id="12">` the rest of the app's
mentions use, which the sanitizer already allowed, so a wiki mention and a work-item mention are
the same thing to every reader of the stored HTML. It survives as data, never as authority.

**The trigger is read from the document, not remembered from keystrokes**: paste, undo, arrow
keys and a click all move the caret without a keypress, and a remembered trigger goes stale on
every one of them. The `@` must start a word, so an email address mid-sentence does not open a
people picker.

**Who is offered** comes from `PageMentionController` — whoever can OPEN the collection, not the
whole workspace. Naming somebody who cannot read the page is a mention that leads them to a 403,
and on a private collection the list would also disclose who is on it. Gated on read, so a
reader replying to a comment gets the same list as an editor writing a paragraph.

The keyboard handler runs in **capture phase** on the document element, ahead of Lexical's own:
Enter has to choose a person rather than break the paragraph, which is the one thing that makes
a picker feel broken.

**Not yet wired: the notification.** Typing `@somebody` inserts and stores the mention, and
nobody is told. `MentionSync` / `MentionNotifier` are shaped around work items, so pointing them
at a wiki page is its own piece of work.

## What is still NOT here

Mention **notifications** (above), and table row/column editing beyond what `@lexical/table`
gives.

## How Lexical is vendored

Lexical ships ES modules only — no browser global, unlike Echo, Quill, Jodit and Tabulator. So
the vendored file is **built**, not copied:

    resources/js/vendor/lexical-bundle.js   the subset the editor uses, re-exported
    vite.lexical.config.js                  one-off lib build → IIFE, global `PBLexical`
    public/assets/vendor/lexical/           the committed output + README

    npx vite build --config vite.lexical.config.js

Adding a feature that needs a Lexical API the bundle does not carry is two steps: export it from
the entry, rebuild. The bundle carries the subset deliberately.

## Three things Lexical does not do for you

Each of these presents as "the editor is broken" rather than as an error, so they are worth
knowing before the next person debugs one:

1. **`contenteditable` is the host's to set.** `setRootElement()` wires the editor to an element
   but leaves the attribute alone. Without it the toolbar works and every keystroke goes
   nowhere. It is bound to `disabled`, which is also how an archived page becomes read-only
   without a remount.
2. **`registerLink` is `@internal` in 0.50** and takes signal stores, not an options object —
   calling it with `{}` throws on `validateUrl`. The editor registers `TOGGLE_LINK_COMMAND`
   itself, around the public `$toggleLink`.
3. **A custom node must be a real `class`.** Lexical's node classes are ES classes, so a
   constructor written the older prototype way cannot call `super` at all, and Lexical also
   calls inherited statics (`transform()` among them) on every registered node. Both failures
   surface at IMPORT, not at insert — so a page with an image would fail to *open*.

## Two details the toolbar depends on

- **Buttons carry `@mousedown.prevent`**, so clicking one never takes focus out of the document
  and the selection the user made is still there when the command runs.
- **The link input is a real input**, so opening it *does* take focus — the selection is cloned
  when it opens and restored before the URL is applied. Not `window.prompt`: a modal blocks the
  page and cannot show what is already there.

Button state is read from the document on every selection change, never toggled by the click
that caused it. A button that says "bold" because you pressed bold, rather than because the
caret is in bold text, is wrong the moment you move the caret.

## UI Requirements

One toolbar in the page header, teleported into `#pb-page-toolbar` (which is sticky), grouped in
the order of the table above and **centred** — it sits over the sheet rather than hugging the
left edge of a screen the document is not using. It wraps rather than scrolls: a control that
has run off the end of a bar is a control nobody finds. Icons come from the app's own registry via `wiIcon`, so the
toolbar follows a change to `config/icons.php`; H1/H2/H3, quote, sub/superscript and inline code
are text glyphs, because the icon set has no icon for them.

Colour, insert-table and insert-image open **popovers**, one at a time. The colour grid is a
fixed palette rather than `<input type="color">`: a native colour input opens an operating-system
dialog, which is a modal over the page that cannot be styled or previewed.

## Acceptance Criteria

1. The Wiki page opens with the Lexical editor and the page's stored content.
2. Typing, Enter, Backspace and selection behave as a document editor.
3. Every toolbar control applies its property, and restates itself as the caret moves — the
   block dropdown, the two selects and the two colour swatches included.
4. A check list ticks on click, and the ticks survive save → reload; a code block is
   syntax-highlighted and stays highlighted after a reload.
5. A link can be added and removed; a bare domain gets `https://`.
6. Undo and redo work, and their buttons disable when there is nothing to undo or redo.
7. A page containing a table or an image keeps both across open → autosave → reload.
8. Every Insert menu item adds its node, and every node survives save → sanitize → reload
   unchanged; the toolbar's image button uploads and inserts in one step.
9. Uploading an image returns an authorized URL that only someone who may open the collection
   can fetch.
10. Clear formatting removes the toggled formats AND the font, size, colour and highlight.
11. All four page formats apply live and persist ([wiki-page-format.md](wiki-page-format.md)).
12. A reader without edit rights gets the rendered document and no toolbar.
13. Project Pages and work-item editors are untouched.

## Real-Time / Queue / Audit Requirements

None. The editor writes through the page's existing autosave, which already records
`updated_by` and `updated_at`.

## Files

| File | What it does |
|---|---|
| `resources/js/vendor/lexical-bundle.js` | The Lexical subset the editor uses. |
| `vite.lexical.config.js` | Builds it into the vendored IIFE. |
| `public/assets/vendor/lexical/` | The committed bundle + README. |
| `public/assets/js/lexical/editor.js` | `<wk-editor>` — the component, the toolbar, the Insert menu. |
| `public/assets/js/lexical/nodes.js` | Every node class the editor can insert, plus `WkMentionNode`. |
| `app/Http/Controllers/Wiki/PageMentionController.php` | Who `@` may offer. |
| `public/assets/vendor/katex/` | KaTeX, for the Equation node (MathML output — no fonts vendored). |
| `app/Services/RichTextSanitizer.php` | The other half: what may survive a save. |
| `app/Http/Controllers/Wiki/PageMediaController.php` | Image upload and serving. |
| `app/Models/WikiPageMedia.php` + its migration | The uploaded file's row. |
| `config/wiki.php` | Upload size and type limits. |
| `public/assets/css/lexical-editor.css` | The theme's other half — sheet, boundaries, toolbar. |
| `public/assets/js/wiki-page.js` | Mounts `<wk-editor>`; owns the page format and the save. |
| `resources/views/partials/lexical-editor.blade.php` | Loads the bundle, the component and the stylesheet, in the one order that works. |
