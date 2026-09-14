{{--
  A verification / one-time code (§9), and in that email it IS the primary element.

  ## Spacing that survives being copied

  The digits are letter-spaced for reading, NOT split into groups with a real space: a code
  shown as "482 194" is copied with the space in it, and the field it is pasted into usually
  rejects that. So the value is emitted exactly as issued and only the tracking is visual.

  `letter-spacing` adds its gap after the LAST character too, which pushes the block visibly
  off-centre at this size — the negative text-indent of the same amount pulls it back.

  `white-space:nowrap` is the §19 requirement that a code must never wrap; the media query
  steps the size down on a narrow screen rather than letting it break.
--}}
@props(['code' => ''])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
  <tr>
    <td align="center"
        style="background:#f3f6f8;border:1px solid #e3e9ee;border-radius:10px;padding:22px 16px;">
      <div class="pb-code"
           style="font-family:Inter,Arial,Helvetica,sans-serif;font-size:36px;line-height:1.15;font-weight:700;
                  color:#1b5f8a;letter-spacing:10px;text-indent:10px;white-space:nowrap;">{{ $code }}</div>
    </td>
  </tr>
</table>
