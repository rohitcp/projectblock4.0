{{-- A work item is blocked by others. The blockers ARE the message, so they are the card. --}}
<x-email.layout preheader="{{ $identifier }} {{ $title }} is blocked.">

  <x-email.icon name="assigned" />

  <x-email.heading>A work item is blocked</x-email.heading>
  <x-email.text>{{ $actorName }} marked this as blocked in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="marked this blocked" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    @foreach ($blockers as $blocker)
      <x-email.row label="Blocked by" :value="trim(($blocker['identifier'] ?? '').' '.($blocker['title'] ?? ''))" />
    @endforeach
  </x-email.card>

  <x-email.button :url="$url">View Work Item</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
