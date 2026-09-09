{{--
  The context card (§7) — "what does this relate to?", grouped.

  Light neutral fill, one hairline, small radius, no shadow (§18): it is a container for facts,
  not a thing to look at. `title` is the subject the rows describe — the work item's title, the
  workspace name — and is optional, because some cards are only label/value pairs.

  Rows go in the slot as <x-email.row>.
--}}
@props([
    'title' => '',
    'eyebrow' => '',
])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;margin:0 0 24px;">
  <tr>
    <td style="padding:18px 20px;">
      @if ($eyebrow)
        {{-- The identifier above the title — "PB-128" — small and quiet, because it is a
             locator rather than a name. --}}
        <div style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:12px;font-weight:600;color:#9ca3af;letter-spacing:.04em;padding-bottom:2px;">{{ $eyebrow }}</div>
      @endif
      @if ($title)
        <div style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#0f0f10;line-height:1.4;padding-bottom:{{ trim($slot) === '' ? '0' : '12px' }};">{{ $title }}</div>
      @endif

      @if (trim($slot) !== '')
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          {{ $slot }}
        </table>
      @endif
    </td>
  </tr>
</table>
