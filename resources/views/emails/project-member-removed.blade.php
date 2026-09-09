{{-- Removed from a project — the counterpart to project-member-added.

     The tone is deliberately flat. Losing access is not a failure and not a reprimand, so the
     email states what changed, who changed it, and what the person still has, and stops. --}}
<x-email.layout preheader="{{ $removedByName }} removed you from {{ $projectName }}.">

  <x-email.icon name="project" />

  <x-email.heading>You’ve been removed from a project</x-email.heading>
  <x-email.text>{{ $removedByName }} removed your access to <strong style="color:#23272f;">{{ $projectName }}</strong>.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="made this change" />
  @endif

  <x-email.card :title="$projectName">
    <x-email.row label="Workspace" :value="$workspaceName" />
    <x-email.row label="Removed by" :value="$removedByName" />
  </x-email.card>

  {{-- Said plainly, because it is the question this email raises: losing a project is not
       losing the account, and somebody who is not told that will assume the worse of the two. --}}
  <x-email.text>You’re still a member of {{ $workspaceName }} and keep access to your other projects there.</x-email.text>

  <x-email.button :url="$workspaceUrl">Go to Workspace</x-email.button>

  <x-email.text muted>If you think this was a mistake, contact whoever manages this project.</x-email.text>

  <x-email.fallback-link :url="$workspaceUrl" />
</x-email.layout>
