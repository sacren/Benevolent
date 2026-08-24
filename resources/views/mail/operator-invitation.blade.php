{{-- An invitation to help run a campaign, and nothing the platform put there.

     Deliberately not a framework notification template, for the reason
     mail/blast.blade.php gives: those print config('app.name') as the header,
     title and footer, and this message is the campaign's.

     Plain text. The link is on its own line so no mail client folds it into a
     sentence and breaks it, and it is named in words first, because a bare URL
     in a plain-text message is the shape people have been taught not to click.

     The last paragraph is the part a stranger most needs: somebody who was not
     expecting this is told that ignoring it is safe, which is true, since
     nothing happens unless the link is used. --}}
{{ $campaignName }} has invited you to help run the campaign{{ $asOwner ? ', as an Owner who can manage who else runs it' : '' }}.

To accept, open this link and choose a password:
{{ $acceptUrl }}

The link works once.
@if ($replyTo)
If you have questions, reply to this message and it will reach {{ $campaignName }} at {{ $replyTo }}.
@endif

If you were not expecting this, you can ignore it. Nothing happens unless the link is used.
