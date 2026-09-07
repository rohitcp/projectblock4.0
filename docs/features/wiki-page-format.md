# Wiki Page Format

Extends [Wiki & Knowledge Management](wiki.md); read that first — this feature adds no access
system of its own and reuses that one's collections, pages and permissions entirely.

The editor this runs in is Lexical — see [Wiki Editor — Lexical](wiki-lexical-editor.md).
Reference page while building: `/wiki/collections/1/pages/1`.

## Requirement

The wiki editor lays a document out on an **A4 sheet with automatic page breaks**, the way a
word processor does — not as text filling a panel. A4 is the default and is preselected for
every page, existing pages included.

From the editor a writer can change the sheet. Supported formats:

| Key | Sheet | At 96dpi |
|---|---|---|
| `a4` | A4, 210 × 297 mm | 794 × 1123 px |
| `letter` | US Letter, 8.5 × 11 in | 816 × 1056 px |
| `legal` | US Legal, 8.5 × 14 in | 816 × 1344 px |
| `paperless` | *no sheet* | — |

**Paperless** removes the fixed dimensions and the page breaks: one continuous scrolling
document, held to the configured content width. It still *prints* on a sheet — paperless
describes how the page is written, not what comes out of a printer.

## User Roles

Whoever may **edit** the page may change its format — the same permission, checked in the same
place, as writing to the page (`WikiCollection::writableBy`). A reader sees the document at the
format it was written on and has no control to change it.

## Database Fields

`wiki_pages.page_format` — `string(20)`, default `'a4'`, after `content`
(`2026_09_30_000001_add_page_format_to_wiki_pages.php`).

The format belongs to the **page**, not to the reader: it is how the document is laid out, so it
is stored beside the content and comes back identically on every screen that opens it. A string
rather than an enum because the list of paper sizes is the kind that grows — a new size should
be a line in `WikiPage::PAGE_FORMATS`, not a migration. Existing rows backfill to `a4`, which is
exactly how they were already being displayed, so nothing that exists changes appearance.

## Business Rules

- A page with no stored format is **A4**. Both the column default and the client fall back to
  `WikiPage::PAGE_FORMAT_DEFAULT`.
- The format is validated against `WikiPage::PAGE_FORMATS` server-side, never taken as given.
- Changing the format goes through the page's **existing autosave endpoint**
  (`PATCH /wiki/collections/{collection}/pages/{page}`), so choosing a paper size cannot become
  a route into a page you may only read.
- Changing the format **must not reload or reset the document**. The editor re-styles the frame
  that is already open; nothing typed, selected, or in the undo history is lost.
- Content and formatting are untouched by a format change — only the sheet under them changes.
- Reopening the page loads the stored format before the document is drawn, so it is never laid
  out twice.

## How the sheet is drawn

The document is an ordinary element on the page — Lexical, unlike the Jodit editor this
replaced, does not put it in an iframe. So the sheet is real CSS in `lexical-editor.css` rather
than a string injected into a frame, and the format is applied by handing that stylesheet two
custom properties:

    --wk-page-w   the sheet's width
    --wk-page-h   the sheet's height, which is also the page-boundary interval

The component sets them from `WK_PAGE_FORMATS`; the stylesheet carries no rule per format, so a
new paper size is a line of JS and nothing else. Paperless is the absence of a sheet, and gets
its own class instead: no width, no boundaries, held only to `--wk-content-w`.

**The sheets are a repeating background**, not real per-page elements — splitting one
continuous `contenteditable` into an element per page is what costs you the caret, the
selection, and every table that spans a break. One tile draws a full sheet of white, then the
30px gutter, then the next sheet's edge. The tile is a page **plus** the gap, so the white band
is exactly one printed page; putting the gap inside the page height would quietly make every
sheet 30px shorter than the paper it stands for.

A sheet's left and right edges are **two more background layers**, each a 1px column whose own
gradient stops at the page height. That is not decoration: a `box-shadow` was the obvious way to
give the sheets depth, and it cannot be done that way — a shadow wraps the whole continuous
column, so it painted two coloured edges straight down every gutter and the gap read as a slot
rather than as a space between two sheets. The gutter is the same tone as the desk around it, so
it reads as the desk showing through.

**Text is kept out of the gutters** by `paginate()` in the editor. A background cannot move
text, so without this a paragraph landing on a boundary is drawn straight through the gap and
it reads as a grey stripe across the words. Instead: measure every top-level block, and when
one would cross the bottom of the page it is on, give it a `margin-top` big enough to start the
next one.

- **Margins only.** Nothing is restructured or re-parented, so the caret, the selection and the
  undo history are untouched. It is pagination as far as the eye is concerned and not one step
  further.
- **On the DOM, not in the model.** The margins are never saved; they are cleared and
  recomputed on every pass, so a document opened on another screen is laid out for that screen.
- Measured with `getBoundingClientRect` against the sheet's own box, not `offsetTop` —
  `offsetTop` is relative to the nearest positioned ancestor, so it carries the document's
  position on screen and puts every boundary out by that much.
- **A block taller than a page is left to straddle.** An image or a table that cannot fit
  anywhere would be pushed forever by a rule that says "move it down".

The pass runs debounced after every change, on a format change, and on window resize (a zoom
change re-lays-out the text without changing the document).

`@media print` / `@page` are still what break the actual paper.

Because it is only two custom properties changing on an element, a format change is a repaint —
the editor is never rebuilt, so nothing typed and nothing selected is lost to picking a paper
size.

## UI Requirements

- A **page-format** control at the right-hand end of the editor toolbar, next to **Insert**,
  shown only to editors. It belongs with the other things that change how the document is
  built, not up in the header with the breadcrumb and the save status.
- It is labelled with the format currently in force (`A4`, `Letter`, …) rather than a bare
  gear, because the format is the one document-wide setting this screen has.
- Clicking it opens a picker listing all four formats with a one-line hint, the current one
  ticked. Choosing applies immediately and saves immediately.
- The control lives in `<wk-editor>` but the **page** owns the value: the editor emits
  `update:pageFormat` and `wiki-page.js` is what stores and saves it.
- The read-only view (a reader without write access) renders at the same width the page was
  written on, so the same document is not shown at two measures depending on permission.

## Acceptance Criteria

1. A page that has never had a format opens on A4, with page boundaries visible.
2. Content flows onto the next sheet as it is written, and no paragraph is left sitting in
   the gap between two sheets.
3. The picker offers A4, Letter, Legal and Paperless, with the current one marked.
4. Choosing Letter or Legal re-sizes the sheet immediately; the document, its formatting, the
   caret and the undo history all survive.
5. Choosing Paperless removes the sheet, the fixed width and every boundary, leaving one
   continuous scrolling document at the content width.
6. The choice is saved with the page and the same format is in force after a reload.
7. A reader without edit rights sees no Page setup control and cannot change the format.
8. `page_format` outside the supported list is rejected by validation.
9. Printing produces the chosen sheet size; a paperless page prints on A4.

## Real-Time Requirements

None. The format is a document property saved with the page's normal autosave; there is no
broadcast and no live-update requirement.

## Queue Requirements

None.

## Audit Requirements

None beyond what the page already records — the format is written through the same `update`
path, so `updated_by` and `updated_at` move with it.

## Files

| File | What it does |
|---|---|
| `database/migrations/2026_09_30_000001_add_page_format_to_wiki_pages.php` | The column. |
| `app/Models/WikiPage.php` | `PAGE_FORMATS`, `PAGE_FORMAT_DEFAULT`, fillable. |
| `app/Http/Controllers/Wiki/PageController.php` | Bootstraps the format, validates the save. |
| `public/assets/js/lexical/editor.js` | `WK_PAGE_FORMATS` and the sheet's custom properties. |
| `public/assets/css/lexical-editor.css` | The sheet, the boundaries and the print rules. |
| `public/assets/js/wiki-page.js` | The Page setup control and the save. |
