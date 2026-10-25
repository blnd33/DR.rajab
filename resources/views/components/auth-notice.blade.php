{{-- Why the user is on the login page (device logged out, timeout, deactivated). --}}
@if (session('auth_notice'))
    <div role="status" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ session('auth_notice') }}
    </div>
@endif
