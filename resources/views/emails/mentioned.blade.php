{{-- Mentioned in a comment (§15, §21 → "View Mention"). The URL opens the work item at the
     comment, which is why it is passed in rather than rebuilt from the identifier here. --}}
<x-email.layout preheader="{{ $actorName }} mentioned you in {{ $identifier }} {{ $title }}.">

  <x-email.icon name="mention" />

  <x-email.heading>You were mentioned in {{ $where }}</x-email.heading>
  <x-email.text>{{ $actorName }} mentioned you in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="mentioned you" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    <x-email.row label="Mentioned by" :value="$actorName" />
  </x-email.card>

  {{-- What was actually said. A notification that only says "you were mentioned" makes
       everyone open the app to find out whether it mattered. --}}
  <x-email.quote>{{ $excerpt }}</x-email.quote>

  @if (!empty($description))
    {{-- The work item's own description, labelled, so it is never read as part of the quote. --}}
    <x-email.text muted>About this work item: {{ $description }}</x-email.text>
  @endif

  <x-email.button :url="$url">View Mention</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
