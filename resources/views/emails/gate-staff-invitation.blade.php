<p>You have been invited to help admit guests at {{ $eventTitle }}.</p>

<p><a href="{{ $invitationUrl }}" style="color: #0756a5; text-decoration: underline; font-weight: 600;">Accept gate staff invitation</a></p>

<p>If the link does not open, copy and paste this URL into your browser:<br>
{{ $invitationUrl }}</p>

<p>This single-use invitation expires at {{ $expiresAt->toDayDateTimeString() }}.</p>
