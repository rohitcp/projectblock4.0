{{--
  A comment preview (§14, §15).

  Truncated here rather than at the call site, so every email that quotes somebody cuts at the
  same length and no caller can forget. A comment is stored as HTML, so tags are stripped
  before the cut — otherwise the limit could land inside a tag and the email would ship broken
  markup, and a comment could style the email around it.

  The brand rule down the left says "somebody said this" without needing a label.
--}}
@props(['limit' => 220])

@php
    // Entity-decode first: the stored body escapes `&amp;`, and cutting before decoding could
    // split an entity in half.
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $slot), ENT_QUOTES, 'UTF-8')));
    $preview = mb_strlen($text) > (int) $limit
        ? mb_substr($text, 0, (int) $limit).'…'
        : $text;
@endphp

@if ($preview !== '')
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
      <td style="background:#f8f9fa;border-left:3px solid #1b5f8a;border-radius:0 8px 8px 0;padding:16px 18px;
                 font-family:Inter,Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#23272f;">{{ $preview }}</td>
    </tr>
  </table>
@endif
