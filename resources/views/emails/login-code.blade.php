{{-- Verification / one-time code (§9, §21). The code IS the primary element, so there is no
     button: there is nothing to click, only something to type. --}}
<x-email.layout preheader="Your Project Block verification code — expires in {{ $ttlMinutes }} minutes."
                footerNote="If you did not request this code, please contact your administrator.">

  <x-email.icon name="code" />

  <x-email.heading>Your verification code</x-email.heading>
  <x-email.text>Enter this code to continue signing in to Project Block.</x-email.text>

  <x-email.code :code="$code" />

  <x-email.text muted>
    This code expires in {{ $ttlMinutes }} minutes and can be used once.
    Do not share this code with anyone.
  </x-email.text>

  <x-email.text muted>If you didn’t request it, you can safely ignore this email.</x-email.text>
</x-email.layout>
