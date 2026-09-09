{{--
  The small contextual mark above the heading (§2).

  ## Why it is a character and not a picture

  An <img> is blocked by default in most clients, so a pictorial icon is blank for a large
  share of readers — and a blank square above the heading is worse than no icon. An inline
  <svg> is worse still: Gmail and Outlook strip it.

  So the mark is built from a tinted circle and ONE typographic character, and the characters
  are restricted to ASCII (plus U+2713, which every mail font has). Emoji were the obvious
  alternative and are rejected on purpose: they render as full-colour pictures in some clients,
  monochrome glyphs in others, and as a missing-glyph box in Outlook's Word engine — three
  different marks for the same email.

  The glyph is decorative. Every email says what it is in its heading, so a reader who sees
  only a tinted circle has lost nothing.
--}}
@props(['name' => 'mail', 'tint' => '#1b5f8a'])

@php
    $glyphs = [
        'code' => '#',        // a code to enter
        'password' => '&bull;&bull;&bull;',  // a password
        'invite' => '+',      // being added to something
        'project' => '&rarr;',
        'assigned' => '&#10003;',
        'comment' => '&rdquo;',
        'mention' => '@',
    ];
    $glyph = $glyphs[$name] ?? $glyphs['code'];
    // The circle is a 10%-opacity wash of the tint, mixed here rather than with rgba(): the
    // Word engine drops rgba backgrounds entirely and would leave the glyph on white.
    $wash = '#eef3f7';
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
  <tr>
    <td width="44" height="44" align="center" valign="middle"
        style="width:44px;height:44px;background:{{ $wash }};border-radius:22px;
               font-family:Inter,Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;
               line-height:44px;color:{{ $tint }};text-align:center;">{!! $glyph !!}</td>
  </tr>
</table>
