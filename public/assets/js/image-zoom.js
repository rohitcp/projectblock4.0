/* ---------------------------------------------------------------------------------------
   Image zoom — a SHARED component. Posted images in any rich-text body get a sensible width,
   a zoom control and a lightbox. (docs/features/work-items.md — comment editor image handling)

   ## One component, every editor

   Work item comments and descriptions, wiki pages, project pages, drafts, and the help
   centre's requests and replies all store HTML that can contain a pasted image, written by
   three different editors (Lexical, Quill, Jodit) across a dozen screens. The reading
   experience is the same problem in all of them, so it is solved once here and loaded through
   `partials/image-zoom.blade.php`; its chrome is in assets/css/styles.css, the one stylesheet
   every screen has.

   Adding a new screen needs no change to this file — include the partial, render the body
   with one of the classes in `BODY_IMAGES`, and it works.

   ## Why this is JavaScript and not a stylesheet

   The requirement is that a posted image takes 50–75% of the comment width "based on its
   original width and height" — larger images nearer 75%, medium ones nearer 50%. CSS cannot
   branch on an image's INTRINSIC size, so the decision has to be made once the file has
   loaded and `naturalWidth` is known. Everything that CAN be expressed in CSS (the height
   cap, the never-overflow rule, the control's chrome) lives in work-items.css instead.

   ## Why a delegated observer and not a Vue component

   `.wi-rich` bodies are written with `v-html` in seven places across work-items.js, and the
   wiki and drafts screens render the same class from their own scripts. A component would
   have to be threaded through all of them and would still miss the server-rendered ones.
   Watching the document catches every one of them, including bodies that arrive later from
   a poll or a websocket push.

   ## What it deliberately leaves alone

   Editing surfaces. An image inside `.wk-doc` or a Quill/Jodit editor is being composed, not
   read: wrapping it would put furniture inside the editor's own model, and the next autosave
   would either write it back or strip it. Those are sized by CSS only.
   --------------------------------------------------------------------------------------- */
(function () {
  'use strict';

  /* Editing surfaces, whose images are none of our business — see the note above. */
  var EDITING = '.wk-doc, .ql-editor, .jodit-wysiwyg, [contenteditable="true"]';

  /* Images inside a body that renders stored rich text. `.wi-rich` is the read view shared by
     work items, the wiki and the help centre; `.wk-read` is the read-only Lexical view. A new
     screen joins in by using one of these classes.

     Written out per body rather than as a `BODIES + ' img'` join: a comma binds looser than a
     descendant space, so that join would have read as ".wi-rich, .wk-read img" and matched the
     containers themselves rather than the images in them. */
  var BODY_IMAGES = '.wi-rich img, .wk-read img';

  /* Natural widths, in CSS pixels, that anchor the 50–75% band. Anything at or above BIG is
     "very large" and gets the top of the band; anything at or below SMALL is "medium" and
     gets the bottom; in between the width is interpolated. The numbers are the two ends of
     the range a screenshot realistically lands in — a phone grab is around 750 wide, a
     retina desktop grab around 1600–3000. */
  var BIG = 1600;
  var SMALL = 700;
  var MIN_PCT = 50;
  var MAX_PCT = 75;

  var ZOOM_ICON =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';

  var CLOSE_ICON =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
    '<path d="M18 6 6 18M6 6l12 12"/></svg>';

  /**
   * The share of the comment width this image should occupy, as a percentage.
   *
   * Linear between the two anchors rather than a step, so two screenshots taken seconds apart
   * on the same screen do not land either side of a threshold and render at visibly different
   * sizes in the same thread.
   */
  function widthPercent(naturalWidth) {
    if (naturalWidth >= BIG) return MAX_PCT;
    if (naturalWidth <= SMALL) return MIN_PCT;

    var t = (naturalWidth - SMALL) / (BIG - SMALL);

    return Math.round(MIN_PCT + t * (MAX_PCT - MIN_PCT));
  }

  /** How much of the viewport's height a posted image may take before width gives way. */
  var HEIGHT_CAP = '60vh';

  /**
   * Size one image and give it its zoom control.
   *
   * ## Three ceilings, one `max-width`, and why it is on the wrapper
   *
   * The percentage has to sit on the FIGURE. Put on the image, it resolves against the figure
   * — which is shrink-to-fit, so it stayed the full column width while the picture inside it
   * shrank, leaving the zoom control marooned in blank space to the right of its own image.
   * On the figure it resolves against the comment body, which is what the requirement means
   * by "the available comment width".
   *
   * The three terms of the `min()`:
   *   - `N%`        the 50–75% share, from the image's natural width;
   *   - `Wpx`       its natural width, so a small image is never enlarged;
   *   - `60vh × ratio`  the height cap expressed as a width. Capping HEIGHT instead would
   *                 letterbox a portrait screenshot inside a box that kept a width it no
   *                 longer needed; converting it lets a tall image give up width to stay
   *                 short, which is what preserves the aspect ratio honestly. Left in CSS
   *                 units so it still tracks a window the reader resizes.
   */
  function apply(img) {
    var nw = img.naturalWidth;
    var nh = img.naturalHeight;

    // Not loaded yet, or an SVG with no intrinsic size. Leave it to the stylesheet.
    if (!nw || !nh) return;

    var fig = img.parentNode;

    // Already wrapped by a previous pass over the same body.
    if (fig && fig.classList && fig.classList.contains('pb-figure')) return;

    fig = document.createElement('span');
    fig.className = 'pb-figure';
    fig.style.maxWidth = 'min(' + widthPercent(nw) + '%, ' + nw + 'px, calc(' +
      HEIGHT_CAP + ' * ' + (nw / nh).toFixed(4) + '))';
    img.parentNode.insertBefore(fig, img);
    fig.appendChild(img);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pb-figure__zoom';
    btn.setAttribute('aria-label', 'Expand image');
    btn.setAttribute('data-tip', 'Expand');
    btn.innerHTML = ZOOM_ICON;
    fig.appendChild(btn);
  }

  /** Every unprocessed image in a rich-text body, sized as soon as its dimensions are known. */
  function scan(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var imgs = scope.querySelectorAll(BODY_IMAGES);

    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];

      if (img.dataset.pbZoom === '1' || img.closest(EDITING)) continue;
      img.dataset.pbZoom = '1';

      if (img.complete) {
        apply(img);
      } else {
        // `once` so a cached image that fires load twice (it can, across re-attachments)
        // does not wrap itself a second time.
        img.addEventListener('load', function () { apply(this); }, { once: true });
      }
    }
  }

  // ---- Lightbox ------------------------------------------------------------------------

  var box = null;
  var boxImg = null;
  var lastFocus = null;

  function build() {
    if (box) return;

    box = document.createElement('div');
    box.className = 'pb-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Image preview');
    box.innerHTML =
      '<button type="button" class="pb-lightbox__close" aria-label="Close preview">' + CLOSE_ICON + '</button>' +
      '<img class="pb-lightbox__img" alt="" />';

    boxImg = box.querySelector('.pb-lightbox__img');

    // Clicking the backdrop closes; clicking the picture itself does not, so a stray click
    // while inspecting a screenshot does not throw away what you were looking at.
    box.addEventListener('click', function (e) {
      if (e.target === box || e.target.closest('.pb-lightbox__close')) close();
    });

    document.body.appendChild(box);
  }

  function open(img) {
    build();
    lastFocus = document.activeElement;
    boxImg.src = img.currentSrc || img.src;
    boxImg.alt = img.alt || '';
    box.classList.add('is-open');
    // The drawer scrolls behind the overlay otherwise, which moves the comment out from
    // under the preview you opened it from.
    document.body.classList.add('pb-lightbox-open');
    box.querySelector('.pb-lightbox__close').focus();
  }

  function close() {
    if (!box || !box.classList.contains('is-open')) return;

    box.classList.remove('is-open');
    document.body.classList.remove('pb-lightbox-open');
    // Dropped so a large preview is not held in memory, and so reopening re-reads the file.
    boxImg.removeAttribute('src');

    if (lastFocus && lastFocus.focus) lastFocus.focus();
    lastFocus = null;
  }

  // ---- Wiring --------------------------------------------------------------------------

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.pb-figure__zoom');

    if (btn) {
      e.preventDefault();
      e.stopPropagation();
      open(btn.parentNode.querySelector('img'));

      return;
    }

    var img = e.target.closest('.pb-figure img');

    if (img) {
      e.preventDefault();
      // Stopped as well: a comment body can sit inside a row that opens the work item, and
      // enlarging a screenshot should not also navigate away from it.
      e.stopPropagation();
      open(img);
    }
  });

  /* Escape closes the preview and NOTHING ELSE.

     Capture phase, and the event is stopped once it has been used. The work item drawer closes
     on Escape too, and it is underneath the preview — bubbling meant one keypress dismissed
     both, so a reader who enlarged a screenshot lost the whole comment thread on the way out.
     Only swallowed when there was actually a preview open, so Escape still reaches the drawer
     the rest of the time. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !box || !box.classList.contains('is-open')) return;

    e.preventDefault();
    e.stopPropagation();
    close();
  }, true);

  /* Bodies arrive whenever Vue re-renders a comment list, so one pass at startup would only
     ever catch what the server sent. Coalesced because a single re-render fires many mutation
     records and each pass walks the document.

     `setTimeout` and NOT `requestAnimationFrame`: a background tab never paints, so rAF does
     not run there at all. A comment that arrives by poll or push while the tab is in the
     background would then still be unprocessed when it is brought forward — the image would
     be sitting there full width with no zoom control until something else re-rendered it. */
  var pending = false;

  function schedule() {
    if (pending) return;
    pending = true;
    setTimeout(function () { pending = false; scan(document); }, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); });
  } else {
    scan(document);
  }

  new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
})();
