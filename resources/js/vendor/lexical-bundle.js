/* Lexical — the browser bundle for the Wiki editor.
   ------------------------------------------------------------------
   Lexical ships as ES modules and has no prebuilt browser global, unlike every other vendored
   dependency here (Echo, Quill, Jodit, Tabulator all ship an IIFE). This file is the entry a
   one-off Vite lib build compiles into that missing global — see vite.lexical.config.js and
   public/assets/vendor/lexical/README.md.

   Only the pieces the editor actually uses are re-exported. That is the whole point of a
   hand-written entry rather than a `export * from 'lexical'`: the bundle carries what the
   toolbar can do and nothing else, and adding a feature is a line here plus a rebuild.
   ------------------------------------------------------------------ */

export {
  createEditor,
  $getRoot,
  $getSelection,
  $setSelection,
  $isRangeSelection,
  $createParagraphNode,
  $createTextNode,
  $insertNodes,
  $isRootOrShadowRoot,
  ParagraphNode,
  TextNode,
  $isTextNode,
  UNDO_COMMAND,
  REDO_COMMAND,
  CAN_UNDO_COMMAND,
  CAN_REDO_COMMAND,
  FORMAT_TEXT_COMMAND,
  FORMAT_ELEMENT_COMMAND,
  INDENT_CONTENT_COMMAND,
  OUTDENT_CONTENT_COMMAND,
  SELECTION_CHANGE_COMMAND,
  KEY_MODIFIER_COMMAND,
  COMMAND_PRIORITY_LOW,
  COMMAND_PRIORITY_EDITOR,
  COMMAND_PRIORITY_CRITICAL,
  PASTE_COMMAND,
  DROP_COMMAND,
  DRAGOVER_COMMAND,
  DecoratorNode,
  ElementNode,
  $getNodeByKey,
  $isElementNode,
  $isParagraphNode
} from 'lexical';

export {
  HeadingNode,
  QuoteNode,
  $createHeadingNode,
  $createQuoteNode,
  $isHeadingNode,
  $isQuoteNode,
  registerRichText
} from '@lexical/rich-text';

export {
  ListNode,
  ListItemNode,
  $isListNode,
  $isListItemNode,
  INSERT_UNORDERED_LIST_COMMAND,
  INSERT_ORDERED_LIST_COMMAND,
  INSERT_CHECK_LIST_COMMAND,
  REMOVE_LIST_COMMAND,
  registerList,
  registerCheckList
} from '@lexical/list';

/* Code blocks. `registerCodeHighlighting` is what tokenises the contents as you type — without
   it a code block is a monospaced box and nothing more. */
export {
  CodeNode,
  CodeHighlightNode,
  $createCodeNode,
  $isCodeNode,
  registerCodeHighlighting
} from '@lexical/code';

/* `registerLink` is deliberately NOT exported. In 0.50 it is marked @internal and takes signal
   stores rather than a plain options object — calling it with `{}` throws on `validateUrl`.
   `$toggleLink` is the public helper doing the actual work, and the editor registers
   TOGGLE_LINK_COMMAND around it itself. */
export {
  LinkNode,
  AutoLinkNode,
  $isLinkNode,
  $createLinkNode,
  $toggleLink,
  TOGGLE_LINK_COMMAND
} from '@lexical/link';

/* Registering the table nodes does two jobs. The first is data safety and came before the
   feature: a node type Lexical does not know about is dropped on import and then written back
   MISSING by the autosave, so a page would lose a table simply by being opened. The second is
   the toolbar's Insert table, which needs the commands and the two plugin registrations. */
export {
  TableNode,
  TableRowNode,
  TableCellNode,
  $isTableNode,
  INSERT_TABLE_COMMAND,
  registerTablePlugin,
  registerTableSelectionObserver
} from '@lexical/table';

/* Comment marks (docs/features/wiki-comments.md). MarkNode is Lexical's own "this run of text
   is referenced by these ids" node — exactly the shape a comment anchor needs, and the reason
   the editor does not invent one. It carries the ids and NOTHING else: the comments themselves
   live in the database, keyed by those ids. */
export {
  MarkNode,
  $isMarkNode,
  $wrapSelectionInMarkNode,
  $unwrapMarkNode,
  $getMarkIDs
} from '@lexical/mark';

export { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';

export {
  $setBlocksType,
  $patchStyleText,
  $getSelectionStyleValueForProperty
} from '@lexical/selection';

export { registerHistory, createEmptyHistoryState } from '@lexical/history';

export {
  $findMatchingParent,
  mergeRegister,
  $getNearestNodeOfType,
  $insertNodeToNearestRoot
} from '@lexical/utils';
