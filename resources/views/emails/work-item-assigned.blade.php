{{-- Work item assigned (§13, §21 → "View Work Item"). The CTA deep-links to the item itself. --}}
<x-email.layout preheader="{{ $assignerName }} assigned {{ $identifier }} {{ $title }} to you.">

  <x-email.icon name="assigned" />

  <x-email.heading>A work item has been assigned to you</x-email.heading>
  <x-email.text>{{ $assignerName }} assigned this to you in {{ $projectName }}.</x-email.text>

  @if (!empty($actor))
    <x-email.actor :name="$actor->name" :url="$actor->avatarUrl" :initial="$actor->initial" :id="$actor->id" line="assigned this to you" />
  @endif

  <x-email.card :eyebrow="$identifier" :title="$title">
    <x-email.row label="Project" :value="$projectName" />
    <x-email.row label="Assigned by" :value="$assignerName" />
  </x-email.card>

  @if (!empty($description))
    {{-- What the work IS, not only what it is called: an assignment carrying just a title
         makes everybody open the app to find out whether it is urgent. --}}
    <x-email.text>{{ $description }}</x-email.text>
  @endif

  <x-email.button :url="$url">View Work Item</x-email.button>
  <x-email.fallback-link :url="$url" />
</x-email.layout>
