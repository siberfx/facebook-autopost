@php
    $badge = $presenter->badge();
    $tone = ['success' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger', 'neutral' => 'text-bg-secondary'][$badge['tone']];
    $confirmText = 'Disconnect Facebook? The token is revoked and nothing posts until you connect again.';
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('card') }}>
    <div class="card-header d-flex align-items-center gap-2 fw-semibold">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877F2" aria-hidden="true"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>
        Facebook
    </div>
    <div class="card-body">
        @include('facebook-autopost::bootstrap.flash', ['flash' => $flash])

        @if (! $connection->connected)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <div class="fw-medium">Not connected</div>
                    <div class="text-body-secondary small">Connect a Facebook account to share your content.</div>
                </div>
                @if ($canManage)
                    <a href="{{ $connectUrl }}" class="btn btn-primary">Connect Facebook</a>
                @endif
            </div>
        @else
            <div class="d-flex flex-wrap align-items-center gap-3">
                @if ($connection->picture)
                    <img src="{{ $connection->picture }}" alt="" width="48" height="48" class="rounded-circle object-fit-cover" referrerpolicy="no-referrer">
                @else
                    <span aria-hidden="true" class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold" style="width:48px;height:48px">{{ $presenter->initials() }}</span>
                @endif

                <div class="flex-grow-1" style="min-width:0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold text-truncate">{{ $presenter->displayName() }}</span>
                        <span class="badge {{ $tone }}">{{ $badge['label'] }}</span>
                    </div>
                    @if ($connection->pageId)
                        <div class="text-body-secondary small text-truncate">Page ID {{ $connection->pageId }}</div>
                    @endif
                    <div class="text-body-secondary small mt-1">
                        @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                        @if ($presenter->connectedOn() && $presenter->validUntil()) · @endif
                        @if ($presenter->validUntil()) Valid until {{ $presenter->validUntil() }} @endif
                    </div>
                </div>

                @if ($canManage)
                <div class="d-flex gap-2">
                    <a href="{{ $connectUrl }}" class="btn {{ $presenter->needsReconnect() ? 'btn-primary' : 'btn-outline-secondary' }}">Reconnect</a>
                    @if ($livewire)
                        <button type="button" class="btn btn-outline-danger" wire:click="disconnect" wire:confirm="{{ $confirmText }}">Disconnect</button>
                    @else
                        <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                        </form>
                    @endif
                </div>
                @endif
            </div>

            @if ($connection->status === 'unknown')
                <div class="text-body-secondary small mt-2">Facebook could not be asked about this token just now; it may still work.</div>
            @endif
        @endif
    </div>
</div>
