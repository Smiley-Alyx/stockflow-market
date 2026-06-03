<x-filament-panels::page>
    @php
        $readiness = $this->readiness();
        $overview = $this->overview();
        $links = $this->links();
        $overallStatus = collect($readiness)->contains(fn (array $check): bool => ($check['critical'] ?? true) && ! $check['ok'])
            ? 'unavailable'
            : (collect($readiness)->contains(fn (array $check): bool => ! $check['ok']) ? 'degraded' : 'ok');
    @endphp

    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-sm text-gray-500 dark:text-gray-400">Readiness</div>
            <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $overallStatus }}</div>
        </div>

        @foreach ($overview as $label => $value)
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Dependencies</h2>

            <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($readiness as $name => $check)
                    <div class="flex items-start justify-between gap-4 py-3">
                        <div>
                            <div class="font-medium text-gray-950 dark:text-white">{{ $name }}</div>
                            @if (! empty($check['detail']))
                                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</div>
                            @endif
                        </div>
                        <div class="text-right">
                            <span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $check['ok'] ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' }}">
                                {{ $check['status'] }}
                            </span>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $check['critical'] ? 'critical' : 'optional' }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Observability links</h2>

            <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($links as $label => $url)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div class="font-medium text-gray-950 dark:text-white">{{ $label }}</div>
                        <a
                            href="{{ $url }}"
                            target="_blank"
                            rel="noreferrer"
                            class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                        >
                            {{ $url }}
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
