{{-- The message a campaign wrote, and nothing the platform put there.

     Deliberately not a framework notification template. Those render
     config('app.name') as the header, the HTML title and the footer copyright,
     so a campaign's message would arrive headed by "Benevolent" -- deferral 21,
     which does not fire precisely because this file exists.

     Plain text: the body is a plain textarea with no template language behind
     it, so this is exactly what the operator typed, with no second rendering to
     disagree with the first. Nothing here substitutes a supporter's name into
     anything -- personalization is D-20's and is Step 6's to answer.

     The unsubscribe line below is the one thing that differs between two
     recipients' copies (D-16). It is addressing rather than personalization:
     it says which envelope this copy belongs to, and still says nothing about
     who is reading it. --}}
{!! $body !!}

--
{{ $campaignName }}
@if ($replyTo)
Reply to this message and it will reach us at {{ $replyTo }}.
@else
Replies to this message are not monitored.
@endif

{{-- Last, plain, and on its own line so that no mail client folds it into the
     sentence above and breaks the link. Named in words rather than left as a
     bare URL, because a bare URL in a plain-text message is the shape people
     have been taught not to click.

     The closing line matters, and only appears when there is a reply address
     to honour it: a supporter who cannot make the link work still has a way
     out, and saying so is the difference between an opt-out that exists and
     one that works. It costs a sentence. A campaign with no contact address
     does not get that line, because offering to answer a reply nobody reads
     would be worse than saying nothing. --}}
To stop receiving email from {{ $campaignName }}, open this link:
{{ $unsubscribeUrl }}
@if ($replyTo)
You can also reply to this message and ask.
@endif
