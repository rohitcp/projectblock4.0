{{--
  The primary call to action (§8). ONE per email.

  ## The VML block is not decoration

  Outlook's Word engine ignores padding on an inline <a>, which collapses the button to a bare
  link — the single most important element in the email, gone. The conditional comment draws a
  real rounded rectangle for those clients only; every other client ignores it and renders the
  <a> beneath. This is the standard "bulletproof button", and it is why the height and colour
  are stated twice.

  Full width on a phone via `pb-btn` in the layout's media query (§19).
--}}
@props(['url' => '#'])

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;" class="pb-btn-td">
  <tr>
    <td align="center" style="border-radius:8px;background:#1b5f8a;" class="pb-btn-td">
      <!--[if mso]>
      <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                   href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:260px;" arcsize="18%" stroke="f" fillcolor="#1b5f8a">
        <w:anchorlock/>
        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">{{ trim($slot) }}</center>
      </v:roundrect>
      <![endif]-->
      <!--[if !mso]><!-- -->
      <a href="{{ $url }}" class="pb-btn"
         style="display:inline-block;background:#1b5f8a;color:#ffffff;font-family:Inter,Arial,Helvetica,sans-serif;
                font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;
                padding:15px 32px;border-radius:8px;mso-hide:all;">{{ $slot }}</a>
      <!--<![endif]-->
    </td>
  </tr>
</table>
