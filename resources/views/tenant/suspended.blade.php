<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Account unavailable — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-8">
        <div class="text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
                <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
            </div>
            <h1 class="mt-4 text-2xl font-semibold text-gray-900">Account unavailable</h1>
            <p class="mt-2 text-sm text-gray-600">
                Hi {{ $user->name }} — access to your {{ $tenants->count() === 1 ? 'client account is' : 'client accounts are' }}
                currently paused. Please contact <strong>{{ config('app.name') }}</strong> to restore access.
            </p>
        </div>

        @if ($tenants->isNotEmpty())
            <ul class="mt-6 divide-y divide-gray-100 rounded-lg ring-1 ring-gray-200">
                @foreach ($tenants as $tenant)
                    <li class="flex items-center justify-between px-4 py-3 text-sm">
                        <span class="font-medium text-gray-800">{{ $tenant->name }}</span>
                        <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 capitalize">
                            {{ $tenant->status->value }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <form action="{{ route('tenant.logout') }}" method="POST" class="mt-6">
            @csrf
            <button type="submit"
                class="w-full rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                Sign out
            </button>
        </form>
    </div>
</body>
</html>
