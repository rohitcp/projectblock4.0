{{--
  One label/value line inside a context card.

  Label left, value right on a desktop client; stacked on a phone, which is what the
  `pb-row-l` / `pb-row-v` classes in the layout's media query do (§19). Stacking rather than
  shrinking, because a long project name beside a long label is what makes either of them
  wrap mid-word.

  Skipped entirely when the value is empty, so a card never shows "Due Date —" for an item
  that simply has no due date.
--}}
@props(['label' => '', 'value' => ''])

@if ($value !== '' && $value !== null)
  <tr>
    <td class="pb-row-l" valign="top"
        style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:13px;color:#6b7280;padding:3px 12px 3px 0;white-space:nowrap;">{{ $label }}</td>
    <td class="pb-row-v" valign="top" align="right"
        style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:13px;font-weight:600;color:#23272f;padding:3px 0;">{{ $value }}</td>
  </tr>
@endif
