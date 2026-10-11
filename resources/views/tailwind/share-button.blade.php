@php
    $reason = $presenter->disabledReason();
    $classes = ($size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3 py-2 text-sm')
        .' inline-flex items-center gap-1.5 rounded-lg border border-gray-300 font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800';
@endphp
<span class="inline-flex flex-col items-start gap-1">
    @if ($livewire)
        <button type="button" wire:click="share" @if ($confirm && ! $reason) wire:confirm="{{ $presenter->confirmText() }}" @endif
                @disabled($reason) title="{{ $presenter->title() }}" wire:loading.attr="disabled" class="{{ $classes }}">
            <svg class="size-4 text-[#1877F2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>
            <span>{{ $presenter->text() }}</span>
        </button>
    @else
        <form method="POST" action="{{ $presenter->signedAction() }}" class="inline" @if ($confirm && ! $reason) onsubmit="return confirm(@js($presenter->confirmText()))" @endif>
            @csrf
            <button type="submit" @disabled($reason) title="{{ $presenter->title() }}" class="{{ $classes }}">
                <svg class="size-4 text-[#1877F2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>
                <span>{{ $presenter->text() }}</span>
            </button>
        </form>
    @endif
    @if ($reason)
        <span class="sr-only">{{ $reason }}</span>
    @endif
    @if ($flash)
        <span class="text-xs {{ $flash['type'] === 'error' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $flash['message'] }}</span>
    @endif
</span>
