/* Wiki — one page (docs/features/wiki.md).
   ------------------------------------------------------------------
   A document: a title you type over, the editor below it, and an autosave that says when it
   last wrote. The editor is <wk-editor> — Lexical (docs/features/wiki-lexical-editor.md) —
   which replaced Jodit here and here only: Project Pages still mount <pg-editor> and comments
   still mount <wi-editor>, so nothing outside the Wiki could regress with the swap.

   The two components share a contract on purpose — same props, same events, same flush() — so
   this file barely knows which one it has.
   ------------------------------------------------------------------ */
PB.boot('wiki-page', {
  props: { bootstrap: Object },
  // <wk-editor> is a definition object from lexical/editor.js, not a global component:
  // it has to be registered on THIS app, exactly as projects/pages.js registers its own.
  components: { 'wk-editor': WkEditor },
  data: function () {
    var b = this.bootstrap || {};
    return {
      page: b.page || {},
      collection: b.collection || {},
      canEdit: !!b.canEdit,
      titleMax: b.titleMax || 200,
      endpoints: b.endpoints || {},
      title: (b.page && b.page.title) || '',
      content: (b.page && b.page.content) || '',
      // The sheet this document is written on (docs/features/wiki-page-format.md). It comes
      // from the page, not from a preference, so the document reads the same on every screen
      // that opens it — and it is here rather than inside <wk-editor> because the page is what
      // saves it.
      pageFormat: (b.page && b.page.page_format) || 'a4',
      pageFormats: b.pageFormats || ['a4', 'letter', 'legal', 'paperless'],
      /* Comments (docs/features/wiki-comments.md). The PAGE owns them, not the editor: the
         editor knows which passages are marked, the sidebar knows what was said, and the
         socket updates one list that both read. */
      threads: b.threads || [],
      commentsOpen: !!(b.threads && b.threads.length),
      commentTab: 'open',
      activeThread: '',
      reply: { uid: '', body: '' },
      editing: { id: 0, body: '' },
      canComment: !!b.canComment,
      userId: b.userId || 0,
      anchorTimer: null,
      saving: false,
      savedAt: null,
      dirty: false,
      timer: null,
      rename: { open: false, title: '' }
    };
  },
  computed: {
    /* The read-only view is not the editor, so it gets no iframe and no sheet — but it should
       still be the width of the page it was written on, or a reader opens the same document at
       two different measures depending on whether they may edit it. */
    docStyle: function () {
      var sheet = WK_PAGE_FORMATS[this.pageFormat];

      return sheet && sheet.width ? { maxWidth: sheet.width + 'px' } : {};
    },
    /* Open threads are the working list; resolved ones are still there, behind a tab. A
       resolved conversation that vanishes entirely is one nobody can check later. */
    openThreads: function () {
      return this.threads.filter(function (t) { return !t.resolved; });
    },
    resolvedThreads: function () {
      return this.threads.filter(function (t) { return t.resolved; });
    },
    shownThreads: function () {
      return this.commentTab === 'resolved' ? this.resolvedThreads : this.openThreads;
    },
    status: function () {
      if (this.saving) return 'Saving…';
      if (this.dirty) return 'Unsaved changes';

      return this.savedAt ? 'Saved' : '';
    }
  },
  methods: {
    icon: function (name, size, cls) { return wiIcon(name, size, cls); },

    /* "Saved a moment ago" rather than a timestamp: the only question this answers is whether
       the last keystroke made it, and a clock time makes you do the subtraction. */
    fmtWhen: function (d) {
      if (!d) return '';
      var secs = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000));
      if (secs < 60) return 'a moment ago';
      var mins = Math.round(secs / 60);
      if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');

      return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },

    /* The toolbar's page-format picker chose one. Applied immediately and saved immediately:
       the editor re-styles the sheet in place, so nothing written is touched, and the choice
       belongs to the page rather than to this visit. */
    chooseFormat: function (key) {
      if (!this.canEdit || key === this.pageFormat) return;
      this.pageFormat = key;
      this.dirty = true;
      this.save();
    },

    /* ---- comments (docs/features/wiki-comments.md) ---- */

    /** "Sep 6, 2026 · 10:03 PM" — the format the requirement asks for, in the reader's locale. */
    fmtStamp: function (iso) {
      if (!iso) return '';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return '';

      return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
        + ' · ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    },
    mine: function (comment) {
      return comment.author && Number(comment.author.id) === Number(this.userId);
    },

    toggleComments: function () { this.commentsOpen = !this.commentsOpen; },

    /**
     * Show a thread, and take the document to it.
     *
     * Both directions go through here — a click in the sidebar and a click on a highlighted
     * passage — so "selected" means the same thing whichever end it started from.
     */
    openThread: function (uid) {
      this.activeThread = uid;
      this.commentsOpen = true;
      if (this.threads.some(function (t) { return t.uid === uid && t.resolved; })) {
        this.commentTab = 'resolved';
      }

      this.$nextTick(function () {
        var mark = document.querySelector('mark[data-wk-threads~="' + uid + '"]');
        if (mark && mark.scrollIntoView) mark.scrollIntoView({ block: 'center', behavior: 'smooth' });
      });
    },

    /**
     * A comment was written in the editor — create the thread, THEN mark the document.
     *
     * That order matters: a mark written first and a request that fails leaves a highlight in
     * the document pointing at a thread nobody can open, and the next autosave would persist
     * it. The editor holds the selection until this comes back.
     */
    addThread: async function (draft) {
      try {
        var resp = await this.$pb.api(this.endpoints.comments, {
          method: 'POST',
          body: { uid: draft.uid, quote: draft.quote, body: draft.body }
        });

        this.threads = resp.threads || this.threads;
        if (this.$refs.editor) this.$refs.editor.applyCommentMark(draft.uid);
        this.activeThread = draft.uid;
        this.commentsOpen = true;
        this.commentTab = 'open';
        // The mark IS a document change, so it has to be saved like one.
        this.dirty = true;
        this.queue();
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
        if (this.$refs.editor) this.$refs.editor.cancelComment();
      }
    },

    sendReply: async function (thread) {
      var body = String(this.reply.body || '').trim();
      if (!body || this.reply.uid !== thread.uid) return;

      this.reply = { uid: '', body: '' };
      await this.threadCall(this.$pb.withId(this.endpoints.commentReply, thread.id), 'POST', { body: body });
    },

    startEdit: function (comment) { this.editing = { id: comment.id, body: comment.body }; },
    cancelEdit: function () { this.editing = { id: 0, body: '' }; },
    saveEdit: async function (thread, comment) {
      var body = String(this.editing.body || '').trim();
      if (!body) return;

      this.cancelEdit();
      await this.threadCall(this.messageUrl(thread, comment), 'PATCH', { body: body });
    },

    removeComment: async function (thread, comment) {
      await this.threadCall(this.messageUrl(thread, comment), 'DELETE');
      // The last message taking its thread with it is the server's decision, so the mark is
      // pulled only once the thread is actually gone from the list it sent back.
      var gone = !this.threads.some(function (t) { return t.uid === thread.uid; });
      if (gone && this.$refs.editor) {
        this.$refs.editor.removeCommentMark(thread.uid);
        this.dirty = true;
        this.queue();
      }
    },

    setResolved: async function (thread, resolved) {
      await this.threadCall(
        this.$pb.withId(this.endpoints.commentResolve, thread.id),
        'POST',
        { resolved: resolved }
      );
      if (resolved && this.activeThread === thread.uid) this.activeThread = '';
    },

    messageUrl: function (thread, comment) {
      return this.$pb.withId(this.endpoints.commentMessage, thread.id).replace('__COMMENT__', comment.id);
    },

    /** Every thread mutation answers with the whole list, so they all land the same way. */
    threadCall: async function (url, method, body) {
      try {
        var resp = await this.$pb.api(url, { method: method, body: body });
        this.threads = resp.threads || this.threads;
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
    },

    /**
     * The editor says which anchors the document still has.
     *
     * Told to the server, which decides what it means — a thread whose passage is gone is
     * FLAGGED, never deleted. Only editors report: a reader cannot change the document, so
     * their view of the anchors proves nothing.
     */
    reportAnchors: async function (uids) {
      if (!this.canEdit) return;

      clearTimeout(this.anchorTimer);
      var self = this;
      this.anchorTimer = setTimeout(async function () {
        try {
          var resp = await self.$pb.api(self.endpoints.commentAnchors, {
            method: 'POST', body: { uids: uids }
          });
          self.threads = resp.threads || self.threads;
        } catch (e) { /* an anchor sweep that fails costs a flag, not the page */ }
      }, 1200);
    },

    /** A thread moved in somebody else's browser. */
    onLiveComment: function (payload) {
      if (!payload || Number(payload.page_id) !== Number(this.page.id)) return;
      // Our own echo: the person who just typed already has it on screen, and redrawing would
      // move their caret out of the box they are still typing in.
      if (Number(payload.actor_id) === Number(this.userId)) return;

      var incoming = payload.thread;
      if (!incoming) return;

      var rest = this.threads.filter(function (t) { return t.id !== incoming.id; });

      if (payload.type === 'wiki.comment.deleted' && incoming.removed) {
        this.threads = rest;
        if (this.activeThread === incoming.uid) this.activeThread = '';

        return;
      }

      rest.push(incoming);
      this.threads = rest.sort(function (a, b) { return a.id - b.id; });
    },

    openRename: function () { this.rename = { open: true, title: this.title }; },
    applyRename: function () {
      var next = String(this.rename.title || '').trim();
      if (!next) return;
      this.title = next;
      this.rename.open = false;
      this.dirty = true;
      this.save();
    },

    onTitle: function () { this.dirty = true; this.queue(); },
    onContent: function (html) { this.content = html; this.dirty = true; this.queue(); },

    /* Debounced, like the project pages editor: a save per keystroke would be a request per
       keystroke, and the last one to arrive would not necessarily be the last one sent. */
    queue: function () {
      if (!this.canEdit) return;
      clearTimeout(this.timer);
      var self = this;
      this.timer = setTimeout(function () { self.save(); }, 900);
    },

    save: async function () {
      if (!this.canEdit || this.saving) return;

      var title = String(this.title || '').trim();
      if (!title) return;

      this.saving = true;
      try {
        await this.$pb.api(this.endpoints.update, {
          method: 'PATCH',
          body: { title: title, content: this.content, page_format: this.pageFormat }
        });
        this.dirty = false;
        this.savedAt = new Date();
      } catch (e) {
        this.$pb.toast(this.$pb.firstError(e), 'error');
      }
      this.saving = false;
    }
  },

  mounted: function () {
    var self = this;
    // Nothing typed should be lost to a click on the back link.
    window.addEventListener('beforeunload', function (e) {
      if (!self.dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    /* Live comments (docs/features/wiki-comments.md). realtime.js is DEFERRED and this screen
       is not, so the socket may not exist yet when mounted() runs — the same ordering the
       Help Center Inbox records. Both paths are taken, so neither order is the wrong one. */
    var listen = function () {
      if (self.stopComments || !window.PB || !window.PB.onWikiComments) return;
      self.stopComments = window.PB.onWikiComments(self.page.id, function (payload) {
        self.onLiveComment(payload);
      });
    };

    listen();
    window.addEventListener('pb:realtime-ready', listen);
  },
  beforeUnmount: function () {
    clearTimeout(this.anchorTimer);
    if (this.stopComments) this.stopComments();
  },

  template:
    '<div class="flex-1 min-h-0 flex flex-col">' +

    // ---- header, the same bar the project page carries: where you are, then how it is doing.
    '<div class="flex items-center gap-2 px-6 h-12 border-b border-line shrink-0">' +
    '<a :href="endpoints.collection" class="inline-flex items-center gap-1.5 text-[13px] text-sub hover:text-ink shrink-0">' +
    '<span v-html="icon(\'arrow-left\', 15)"></span>{{ collection.name }}</a>' +
    '<span class="text-faint shrink-0">/</span>' +
    '<span class="text-[13px] font-medium text-ink truncate">{{ title }}</span>' +
    '<button v-if="canEdit" type="button" @click="openRename" data-tip="Rename page" aria-label="Rename page" ' +
    'class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover shrink-0" ' +
    'v-html="icon(\'pen\', 14)"></button>' +

    '<div class="ml-auto flex items-center gap-2">' +
    // Comments toggle. The count is of OPEN threads: a resolved conversation is not something
    // anyone still has to deal with, and counting it would keep a badge lit forever.
    '<button v-if="canComment" type="button" @click="toggleComments" ' +
    'class="h-7 inline-flex items-center gap-1.5 px-2 rounded-md text-[12px] shrink-0" ' +
    ':class="commentsOpen ? \'bg-sel text-brand\' : \'text-sub hover:bg-hover hover:text-ink\'" ' +
    'data-tip="Comments" aria-label="Comments">' +
    '<span v-html="icon(\'note\', 14)"></span>' +
    '<span v-if="openThreads.length">{{ openThreads.length }}</span></button>' +
    // Stated honestly — a failed save keeps saying "Unsaved changes".
    '<span class="text-[12px] text-faint whitespace-nowrap">' +
    '<template v-if="saving">Saving…</template>' +
    '<template v-else-if="dirty">Unsaved changes</template>' +
    '<template v-else-if="savedAt">Saved {{ fmtWhen(savedAt) }}</template>' +
    '</span>' +
    '</div></div>' +

    // The toolbar's home. <wk-editor> TELEPORTS its toolbar in here — it is Vue's own markup,
    // because every button restates itself as the caret moves, so unlike Jodit's it cannot
    // just be moved once and left alone.
    // INSIDE the scroller, so `position: sticky` has something to stick to. Outside it the bar
    // is fixed only because the layout happens to put it there — which stops being true the
    // moment anything above it grows.
    '<div class="flex-1 min-h-0 flex">' +
    '<div class="pb-page flex-1 min-h-0 overflow-y-auto">' +
    '<div id="pb-page-toolbar" class="pb-page-toolbar" v-show="canEdit"></div>' +
    // The editor sits OUTSIDE the centred column: the sheet sets its own width from the page
    // format, and the toolbar spans the screen (lexical-editor.css).
    '<wk-editor v-if="canEdit" ref="editor" :model-value="content" ' +
    '@update:model-value="onContent" @blur="save" ' +
    'toolbar-host="#pb-page-toolbar" min-height="420px" placeholder="Start writing…" ' +
    ':media-upload="endpoints.mediaUpload" :page-format="pageFormat" :page-formats="pageFormats" ' +
    ':threads="threads" :active-thread="activeThread" :mention-url="endpoints.mentions" ' +
    '@update:page-format="chooseFormat" @comment="addThread" @open-thread="openThread" ' +
    '@anchors="reportAnchors" />' +

    // A read-only invitation gets the rendered document, not a disabled editor pretending.
    '<div v-else class="pb-page-doc px-6 pt-6" :style="docStyle">' +
    '<div class="wi-rich" v-html="content"></div>' +
    '<p v-if="!content" class="text-[13px] text-faint">This page is empty.</p>' +
    '</div>' +
    '</div>' +

    // ============ comments sidebar (docs/features/wiki-comments.md) ============
    // Beside the document rather than over it: a comment is about a passage, and a panel that
    // covers the passage is a panel you have to close to answer.
    '<aside v-if="canComment && commentsOpen" class="wk-comments">' +
    '<div class="wk-comments__head">' +
    '<div class="wk-comments__tabs">' +
    '<button type="button" class="wk-comments__tab" :class="{ \'wk-comments__tab--on\': commentTab === \'open\' }" ' +
    '@click="commentTab = \'open\'">Open<span v-if="openThreads.length"> ({{ openThreads.length }})</span></button>' +
    '<button type="button" class="wk-comments__tab" :class="{ \'wk-comments__tab--on\': commentTab === \'resolved\' }" ' +
    '@click="commentTab = \'resolved\'">Resolved<span v-if="resolvedThreads.length"> ({{ resolvedThreads.length }})</span></button>' +
    '</div>' +
    '<button type="button" class="wk-comments__close" @click="commentsOpen = false" aria-label="Close comments" ' +
    'v-html="icon(\'xmark\', 14)"></button>' +
    '</div>' +

    '<div class="wk-comments__list">' +
    '<p v-if="!shownThreads.length" class="wk-comments__empty">' +
    '{{ commentTab === \'resolved\' ? \'Nothing resolved yet.\' : \'Select some text and press Comment to start a thread.\' }}</p>' +

    '<article v-for="t in shownThreads" :key="t.uid" class="wk-thread" ' +
    ':class="{ \'wk-thread--on\': activeThread === t.uid }" @click="openThread(t.uid)">' +

    // The passage this thread is about, and — when the passage is gone — the fact that it is.
    // Never deleted with it: the conversation may be the only record of why.
    '<p v-if="t.anchor_missing" class="wk-thread__gone">Referenced text deleted</p>' +
    '<p v-else-if="t.quote" class="wk-thread__quote">“{{ t.quote }}”</p>' +

    '<div v-for="c in t.comments" :key="c.id" class="wk-msg">' +
    '<span v-if="c.author.avatar_url" class="wk-msg__face"><img :src="c.author.avatar_url" alt="" /></span>' +
    '<span v-else class="wk-msg__face" :style="{ background: c.author.avatar_color }">{{ c.author.initial }}</span>' +
    '<div class="wk-msg__body">' +
    '<p class="wk-msg__who">{{ c.author.name }}' +
    '<span class="wk-msg__when">{{ fmtStamp(c.created_at) }}<template v-if="c.edited_at"> · edited</template></span></p>' +

    '<template v-if="editing.id === c.id">' +
    '<textarea v-model="editing.body" class="wk-area wk-area--edit" rows="2"></textarea>' +
    '<div class="wk-msg__acts">' +
    '<button type="button" @click.stop="saveEdit(t, c)">Save</button>' +
    '<button type="button" @click.stop="cancelEdit">Cancel</button></div>' +
    '</template>' +
    '<template v-else>' +
    '<p class="wk-msg__text">{{ c.body }}</p>' +
    '<div v-if="mine(c)" class="wk-msg__acts">' +
    '<button type="button" class="wk-act" data-tip="Edit" aria-label="Edit comment" ' +
    '@click.stop="startEdit(c)" v-html="icon(\'pen-line\', 14)"></button>' +
    '<button type="button" class="wk-act wk-act--danger" data-tip="Delete" aria-label="Delete comment" ' +
    '@click.stop="removeComment(t, c)" v-html="icon(\'trash-can\', 14)"></button></div>' +
    '</template>' +

    '</div></div>' +

    // Reply is open to anyone who can see the thread; resolve is not — see the controller.
    '<div class="wk-thread__foot">' +
    '<template v-if="reply.uid === t.uid">' +
    '<textarea v-model="reply.body" class="wk-area wk-area--edit" rows="2" placeholder="Reply…" ' +
    '@click.stop @keydown.esc="reply = { uid: \'\', body: \'\' }"></textarea>' +
    '<div class="wk-msg__acts">' +
    '<button type="button" @click.stop="sendReply(t)">Reply</button>' +
    '<button type="button" @click.stop="reply = { uid: \'\', body: \'\' }">Cancel</button></div>' +
    '</template>' +
    '<template v-else>' +
    '<button type="button" class="wk-act" data-tip="Reply" aria-label="Reply" ' +
    '@click.stop="reply = { uid: t.uid, body: \'\' }" v-html="icon(\'reply\', 14)"></button>' +
    // Resolve and Reopen are the SAME control in two states, so they sit in the same place
    // rather than one appearing where the other was.
    '<button v-if="canEdit && !t.resolved" type="button" class="wk-act wk-act--go" ' +
    'data-tip="Resolve" aria-label="Resolve thread" ' +
    '@click.stop="setResolved(t, true)" v-html="icon(\'check\', 14)"></button>' +
    '<button v-if="canEdit && t.resolved" type="button" class="wk-act" ' +
    'data-tip="Reopen" aria-label="Reopen thread" ' +
    '@click.stop="setResolved(t, false)" v-html="icon(\'rotate\', 14)"></button>' +
    '</template>' +
    '</div>' +

    '</article></div></aside>' +
    '</div>' +

    '<pb-modal :open="rename.open" title="Rename page" width="max-w-[460px]" @close="rename.open = false">' +
    '<label class="block text-[13px] font-medium text-ink mb-1.5">Page name</label>' +
    '<input v-model="rename.title" :maxlength="titleMax" class="pb-input" @keyup.enter="applyRename" />' +
    '<template #footer>' +
    '<button type="button" class="h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover" ' +
    '@click="rename.open = false">Cancel</button>' +
    '<button type="button" :disabled="!rename.title.trim()" @click="applyRename" ' +
    'class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold disabled:opacity-50">Save</button>' +
    '</template></pb-modal>' +
    '</div>'
}, { root: 'wiki-page-root' });
