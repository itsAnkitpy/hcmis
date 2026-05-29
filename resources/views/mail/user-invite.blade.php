<x-mail::message>
# Hi {{ $invitee->name }},

You've been invited to **{{ $appName }}**@if ($assignedTenant) to work on **{{ $assignedTenant->name }}**@endif.

Click the button below to set your password and finish signing in. The link is good for 72 hours.

<x-mail::button :url="$acceptUrl">
Set your password
</x-mail::button>

If you didn't expect this email, you can safely ignore it — the invite link will expire on its own.

Thanks,<br>
{{ $appName }}
</x-mail::message>
