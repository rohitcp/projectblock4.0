@extends('layouts.auth')
@section('title', 'Invitation unavailable — Project Block')

{{--
  Every "this link cannot be used" state (invite spec §62–§64, §69/§70). An unknown token
  shows the same panel as a revoked one, so the page cannot be used to probe which workspaces
  or invitations exist (§73).
--}}
@php
  $panels = [
      'expired' => [
          'heading' => 'Invitation expired',
          'body' => 'This invitation is no longer valid. Please contact the workspace administrator for a new invitation.',
      ],
      /*
       * `revoked` and `unknown` MUST stay word-for-word identical (§73): an attacker trying
       * tokens must not be able to tell "this invitation was cancelled" from "no such
       * invitation", or the page becomes a way to enumerate them.
       *
       * What changed is what they both SAY. The copy used to assert the invitation "has been
       * cancelled", which is one of the three things that actually land here and the least
       * likely: resending an invitation issues a NEW token and retires the old one, so every
       * earlier email for that person now leads here. Telling somebody holding a superseded
       * link that their invitation was cancelled is both wrong and alarming, and it sends
       * them to ask an administrator about something nobody did.
       *
       * The wording below is true of all three cases without distinguishing them, and it
       * names the fix for the common one first.
       */
      'revoked' => [
          'heading' => 'Invitation link no longer valid',
          'body' => 'If this invitation was sent more than once, only the most recent email works — please open the latest one. Otherwise, ask the workspace administrator to send a new invitation.',
      ],
      'unknown' => [
          'heading' => 'Invitation link no longer valid',
          'body' => 'If this invitation was sent more than once, only the most recent email works — please open the latest one. Otherwise, ask the workspace administrator to send a new invitation.',
      ],
      'accepted' => [
          'heading' => 'This invitation has already been accepted',
          'body' => 'Sign in with the invited email address to open the workspace.',
      ],
      'workspace_unavailable' => [
          'heading' => 'This workspace invitation is no longer valid',
          'body' => 'The workspace is unavailable. Please contact the workspace administrator.',
      ],
  ];
  $panel = $panels[$state] ?? $panels['unknown'];
@endphp

@section('body')
  <header class="flex items-center justify-between px-5 sm:px-10 py-6">
    <span class="flex items-center gap-2">
      <svg width="26" height="26" viewBox="0 0 32 32" fill="#0f0f10" aria-hidden="true">
        <path d="M5 21 L15 4 L20.5 4 L10.5 21 Z" /><path d="M13 28 L23 11 L28.5 11 L18.5 28 Z" />
      </svg>
      <span class="text-[20px] font-bold tracking-tight text-head">Project Block</span>
    </span>
  </header>

  <main class="flex-1 flex justify-center px-5">
    <div class="w-full max-w-[420px] pt-10 sm:pt-16 text-center">
      <span class="mx-auto mb-5 h-12 w-12 rounded-full bg-[#f3f4f6] grid place-items-center" aria-hidden="true">
        {!! pb_icon('circle-exclamation', 22, 'text-faint') !!}
      </span>
      <h1 class="text-[22px] font-bold text-head leading-tight">{{ $panel['heading'] }}</h1>
      <p class="mt-2 text-[15px] text-sub">{{ $panel['body'] }}</p>

      <a href="{{ route('signin') }}" class="mt-7 inline-flex items-center justify-center h-11 px-6 rounded-lg border border-line text-[14px] font-semibold text-ink hover:bg-hover transition-colors">
        Go to sign in
      </a>
    </div>
  </main>
@endsection
