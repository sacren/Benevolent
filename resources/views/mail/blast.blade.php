{{-- The message a campaign wrote, and nothing the platform put there.

     Deliberately not a framework notification template. Those render
     config('app.name') as the header, the HTML title and the footer copyright,
     so a campaign's message would arrive headed by "Benevolent" -- deferral 21,
     which does not fire precisely because this file exists.

     Plain text: the body is a plain textarea with no template language behind
     it, so this is exactly what the operator typed, with no second rendering to
     disagree with the first. Nothing here substitutes a supporter's name into
     anything -- personalization is D-20's and is Step 6's to answer.

     No unsubscribe line yet, and its absence is Step 5's rather than an
     oversight (D-16). It is the first thing that will be added here. --}}
{!! $body !!}

--
{{ $campaignName }}
@if ($replyTo)
Reply to this message and it will reach us at {{ $replyTo }}.
@else
Replies to this message are not monitored.
@endif
