{{-- Invited to a project (§12, §21 → "Open Project").

     The CTA points at THIS project's work items — the requirement is explicit that it must
     always be the project the notification is about, and `projectUrl` is built from that
     project in the mailable rather than assembled here, so it cannot be assembled wrongly.

     "Invited", with a body that says access is already granted. Somebody added from Project
     Settings → Members is a workspace coworker, so there is nothing for them to accept and no
     link to click before they can open it. A subject that said "invited" over a body that
     implied a pending acceptance would be the one thing worse than either word alone. --}}
<x-email.layout preheader="{{ $inviterName }} added you to {{ $projectName }} in {{ $workspaceName }}.">

  <x-email.icon name="project" />

  <x-email.heading>You’ve been added to a project</x-email.heading>

  {{-- By name. The recipient is a coworker in this workspace, so we always have one — and an
       email that opens with their name reads as addressed to them rather than broadcast. --}}
  <x-email.text>Hi {{ $recipientName }} — {{ $inviterName }} added you to <strong style="color:#23272f;">{{ $projectName }}</strong>.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="added you to this project" />
  @endif

  <x-email.card :title="$projectName">
    <x-email.row label="Workspace" :value="$workspaceName" />
    <x-email.row label="Project role" :value="$roleLabel" />
    <x-email.row label="Added by" :value="$inviterName" />
  </x-email.card>

  {{-- Said plainly: there is no invitation to accept, which is the question a subject line
       reading "invited" otherwise leaves open. --}}
  <x-email.text>You already have access — open the project whenever you’re ready.</x-email.text>

  <x-email.button :url="$projectUrl">Open Project</x-email.button>
  <x-email.fallback-link :url="$projectUrl" />
</x-email.layout>
