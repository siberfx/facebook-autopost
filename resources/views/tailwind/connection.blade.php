@php
    $badge = $presenter->badge();
    $tone = [
        'success' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-950 dark:text-green-300',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-300',
        'danger' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950 dark:text-red-300',
        'neutral' => 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300',
    ][$badge['tone']];
    $confirmText = 'Disconnect Facebook? The token is revoked and nothing posts until you connect again.';
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900') }}>
    <div class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
        <svg class="size-5 text-[#1877F2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>
        Facebook
    </div>

    @include('facebook-autopost::tailwind.flash', ['flash' => $flash])

    @if (! $connection->connected)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Not connected</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Connect a Facebook account to share your content.</p>
            </div>
            @if ($canManage)
                <a href="{{ $connectUrl }}" class="inline-flex items-center rounded-lg bg-[#1877F2] px-4 py-2 text-sm font-semibold text-white hover:bg-[#166FE5] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1877F2] focus-visible:ring-offset-2">Connect Facebook</a>
            @endif
        </div>
    @else
        <div class="flex flex-wrap items-center gap-4">
            @if ($connection->picture)
                <img src="{{ $connection->picture }}" alt="" class="size-12 rounded-full object-cover" referrerpolicy="no-referrer">
            @else
                <span aria-hidden="true" class="flex size-12 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-[#1877F2] dark:bg-blue-950">{{ $presenter->initials() }}</span>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $presenter->displayName() }}</p>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tone }}">{{ $badge['label'] }}</span>
                </div>
                @if ($connection->pageId)
                    <p class="truncate text-sm text-gray-500 dark:text-gray-400">Page ID {{ $connection->pageId }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                    @if ($presenter->connectedOn() && $presenter->validUntil()) · @endif
                    @if ($presenter->validUntil()) Valid until {{ $presenter->validUntil() }} @endif
                </p>
            </div>

            @if ($canManage)
            <div class="flex gap-2">
                <a href="{{ $connectUrl }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold {{ $presenter->needsReconnect() ? 'bg-[#1877F2] text-white hover:bg-[#166FE5]' : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800' }}">Reconnect</a>

                @if ($livewire)
                    <button type="button" wire:click="disconnect" wire:confirm="{{ $confirmText }}"
                            class="inline-flex items-center rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">Disconnect</button>
                @else
                    <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">Disconnect</button>
                    </form>
                @endif
            </div>
            @endif
        </div>

        @if ($connection->status === 'unknown')
            <p class="text-xs text-gray-500 dark:text-gray-400">Facebook could not be asked about this token just now; it may still work.</p>
        @endif
    @endif
</div>
