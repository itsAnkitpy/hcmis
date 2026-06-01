{{--
    M4.A topbar client context (D-M4-6). Rendered via the USER_MENU_BEFORE
    panel render hook. Three shapes:
      - global HC staff        → read-only "All clients" badge (always cross-tenant)
      - single-client staff    → read-only badge with the client's name
      - multi-client staff     → a switcher that POSTs to tenant.switch; the
                                  SetCurrentTenant middleware re-validates + re-pins
                                  on the next request (FR-U03)
    Inline styles keep it rendering regardless of the panel's compiled CSS.
--}}
@php
    $badgeStyle = 'display:inline-flex;align-items:center;gap:.375rem;height:2rem;padding:0 .75rem;margin-right:.5rem;border:1px solid rgb(209 213 219);border-radius:.5rem;font-size:.8125rem;color:rgb(55 65 81);background:#fff;';
@endphp

@if ($isGlobal)
    <span style="{{ $badgeStyle }}" title="You can see every client's data">
        <span style="color:rgb(107 114 128)">Client:</span> All clients
    </span>
@elseif ($tenants->count() <= 1)
    @if ($tenants->isNotEmpty())
        <span style="{{ $badgeStyle }}">
            <span style="color:rgb(107 114 128)">Client:</span> {{ $tenants->first()->name }}
        </span>
    @endif
@else
    <form action="{{ route('tenant.switch') }}" method="POST" style="margin-right:.5rem">
        @csrf
        <select name="tenant" onchange="this.form.submit()" aria-label="Switch client"
            style="{{ $badgeStyle }} cursor:pointer;padding-right:1.5rem;">
            @foreach ($tenants as $tenant)
                <option value="{{ $tenant->id }}" @selected($tenant->id === $currentId)>
                    {{ $tenant->name }}
                </option>
            @endforeach
        </select>
    </form>
@endif
