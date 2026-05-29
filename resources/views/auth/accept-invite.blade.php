<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set your password — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white shadow-sm ring-1 ring-gray-200 rounded-xl p-8">
        <div class="text-center">
            <h1 class="text-2xl font-semibold text-gray-900">Welcome, {{ $user->name }}</h1>
            <p class="mt-2 text-sm text-gray-600">
                Set a password to finish your invite to <strong>{{ config('app.name') }}</strong>.
            </p>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ $fullUrl }}" method="POST" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input id="email" type="email" value="{{ $user->email }}" disabled
                    class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-500" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">New password</label>
                <input id="password" name="password" type="password" required autofocus
                    class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                    class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500" />
            </div>

            <button type="submit"
                class="w-full rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:ring-offset-2">
                Set password &amp; sign in
            </button>
        </form>
    </div>
</body>
</html>
