{{-- Back Office sign-in code (§9). Same shell as every other Project Block email; the copy
     names the Back Office so a code for the admin console is never mistaken for an app one. --}}
<x-email.layout preheader="Your Project Block Back Office code — expires in {{ $ttlMinutes }} minutes."
                footerNote="If you did not request this code, please contact your administrator.">

  <x-email.icon name="code" />

  <x-email.heading>Your Back Office code</x-email.heading>
  <x-email.text>Enter this code to finish signing in to the Project Block Back Office.</x-email.text>

  <x-email.code :code="$code" />

  <x-email.text muted>
    This code expires in {{ $ttlMinutes }} minutes and can be used once.
    Do not share this code with anyone.
  </x-email.text>
</x-email.layout>
