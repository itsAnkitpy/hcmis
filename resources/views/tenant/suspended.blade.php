<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Account unavailable — {{ config('app.name') }}</title>
    {{-- Self-contained styles (review S1): no external CDN, so the block screen
         renders offline and adds no third-party runtime dependency. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1rem; background: #f9fafb;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #111827;
        }
        .card { width: 100%; max-width: 28rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; box-shadow: 0 1px 2px rgba(0,0,0,.05); padding: 2rem; }
        .icon-wrap { margin: 0 auto; height: 3rem; width: 3rem; display: flex; align-items: center; justify-content: center; border-radius: 9999px; background: #fef3c7; }
        .icon-wrap svg { height: 1.5rem; width: 1.5rem; color: #d97706; }
        h1 { margin: 1rem 0 0; font-size: 1.5rem; font-weight: 600; text-align: center; }
        .lead { margin: .5rem 0 0; font-size: .875rem; color: #4b5563; text-align: center; }
        ul.clients { list-style: none; margin: 1.5rem 0 0; padding: 0; border: 1px solid #e5e7eb; border-radius: .5rem; }
        ul.clients li { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1rem; font-size: .875rem; }
        ul.clients li + li { border-top: 1px solid #f3f4f6; }
        ul.clients .name { font-weight: 500; color: #1f2937; }
        .badge { border-radius: 9999px; background: #fffbeb; padding: .125rem .625rem; font-size: .75rem; font-weight: 500; color: #b45309; text-transform: capitalize; }
        form.signout { margin-top: 1.5rem; }
        button.signout { width: 100%; border: 0; border-radius: .375rem; background: #1f2937; padding: .5rem 1rem; font-size: .875rem; font-weight: 500; color: #fff; cursor: pointer; }
        button.signout:hover { background: #111827; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
        </div>
        <h1>Account unavailable</h1>
        <p class="lead">
            Hi {{ $user->name }} — access to your {{ $tenants->count() === 1 ? 'client account is' : 'client accounts are' }}
            currently paused. Please contact <strong>{{ config('app.name') }}</strong> to restore access.
        </p>

        @if ($tenants->isNotEmpty())
            <ul class="clients">
                @foreach ($tenants as $tenant)
                    <li>
                        <span class="name">{{ $tenant->name }}</span>
                        <span class="badge">{{ $tenant->status->value }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <form action="{{ route('tenant.logout') }}" method="POST" class="signout">
            @csrf
            <button type="submit" class="signout">Sign out</button>
        </form>
    </div>
</body>
</html>
