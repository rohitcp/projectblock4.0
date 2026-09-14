{{--
  One person's face (§16) — the uploaded photo when there is one, their initials when there
  is not. NEVER initials over a photo that exists.

  ## Both halves are tables, not a styled <span>

  A circle in email is a table cell with a `border-radius`, and Outlook's Word engine ignores
  the radius and renders a square — which is fine, and is the reason the initials tile still
  reads correctly there. A `<div>` with line-height would collapse in the same client.

  The fallback colour comes from `pb_avatar_color()`, the PHP twin of the app's own
  `avatarColor()`, so somebody's tile is the same colour in an email as it is on screen.

  ## Props

  - `name`   the person's display name; also the image's alt text, so a blocked photo still
             says who it is rather than showing a broken-image glyph.
  - `url`    their uploaded photo, or null.
  - `initial` the letter to fall back to.
  - `id`     their user id, which is what picks the fallback colour.
  - `size`   pixels. 40 by default; 32 inside a comment quote.
--}}
@props([
    'name' => '',
    'url' => null,
    'initial' => null,
    'id' => null,
    'size' => 40,
])

@php
    $size = (int) $size;
    $letter = mb_strtoupper(mb_substr((string) ($initial ?: $name ?: '?'), 0, 1));
    $radius = (int) round($size / 2);
@endphp

@if ($url)
  <img src="{{ $url }}" alt="{{ $name }}" width="{{ $size }}" height="{{ $size }}"
       style="display:block;width:{{ $size }}px;height:{{ $size }}px;border-radius:{{ $radius }}px;border:1px solid #e5e7eb;object-fit:cover;" />
@else
  <table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td width="{{ $size }}" height="{{ $size }}" align="center" valign="middle"
          style="width:{{ $size }}px;height:{{ $size }}px;background:{{ pb_avatar_color($id ?: $name) }};
                 border-radius:{{ $radius }}px;font-family:Inter,Arial,Helvetica,sans-serif;
                 font-size:{{ max(11, (int) round($size * 0.4)) }}px;font-weight:700;color:#ffffff;
                 line-height:{{ $size }}px;text-align:center;">{{ $letter }}</td>
    </tr>
  </table>
@endif
