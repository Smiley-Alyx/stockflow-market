<x-filament-panels::page>
    @php
        $readiness = $this->readiness();
        $overview = $this->overview();
        $links = $this->links();
        $failedCriticalChecks = collect($readiness)->filter(fn (array $check): bool => ($check['critical'] ?? true) && ! $check['ok'])->count();
        $failedChecks = collect($readiness)->filter(fn (array $check): bool => ! $check['ok'])->count();
        $healthyChecks = collect($readiness)->filter(fn (array $check): bool => $check['ok'])->count();
        $overallStatus = $failedCriticalChecks > 0 ? 'unavailable' : ($failedChecks > 0 ? 'degraded' : 'ok');
        $statusClasses = [
            'ok' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-300',
            'degraded' => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300',
            'unavailable' => 'border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300',
        ];
        $metricNumbers = collect($overview)->map(fn (string $value): int => (int) str_replace(' ', '', $value))->all();
        $maxMetric = max([1, ...$metricNumbers]);
    @endphp

    <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-950">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Stockflow runtime</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ config('stockflow.runtime.service_name') }}
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-md border px-3 py-1.5 text-sm font-semibold {{ $statusClasses[$overallStatus] }}">
                        {{ strtoupper($overallStatus) }}
                    </span>
                    <span class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">
                        {{ $healthyChecks }}/{{ count($readiness) }} checks ok
                    </span>
                    <span class="inline-flex items-center rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-200">
                        {{ now()->format('H:i:s') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="grid gap-px bg-gray-100 dark:bg-gray-800 md:grid-cols-3">
            <div class="bg-white p-5 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">Healthy dependencies</div>
                <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $healthyChecks }}</div>
            </div>
            <div class="bg-white p-5 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">Degraded dependencies</div>
                <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $failedChecks }}</div>
            </div>
            <div class="bg-white p-5 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">Critical failures</div>
                <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $failedCriticalChecks }}</div>
            </div>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($overview as $label => $value)
            @php
                $numericValue = (int) str_replace(' ', '', $value);
                $barWidth = max(6, min(100, (int) round(($numericValue / $maxMetric) * 100)));
                $isProblemMetric = str_contains(strtolower($label), 'failed') || str_contains(strtolower($label), 'pending');
            @endphp

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                    <div class="h-10 w-10 rounded-md {{ $isProblemMetric ? 'bg-warning-50 dark:bg-warning-500/10' : 'bg-primary-50 dark:bg-primary-500/10' }}"></div>
                </div>

                <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    <div
                        class="h-full rounded-full {{ $isProblemMetric ? 'bg-warning-500' : 'bg-primary-500' }}"
                        style="width: {{ $barWidth }}%"
                    ></div>
                </div>
            </div>
        @endforeach
    </section>

    <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Dependencies</h2>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($readiness as $name => $check)
                    <div class="grid gap-4 px-5 py-4 md:grid-cols-[1fr_auto] md:items-center">
                        <div class="min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="h-2.5 w-2.5 rounded-full {{ $check['ok'] ? 'bg-success-500' : 'bg-danger-500' }}"></span>
                                <div class="font-medium text-gray-950 dark:text-white">{{ $name }}</div>
                            </div>
                            @if (! empty($check['detail']))
                                <div class="mt-2 break-words text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</div>
                            @endif
                        </div>

                        <div class="flex items-center gap-2 md:justify-end">
                            <span class="inline-flex rounded-md px-2.5 py-1 text-xs font-semibold {{ $check['ok'] ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' : 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' }}">
                                {{ $check['status'] }}
                            </span>
                            <span class="inline-flex rounded-md bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ $check['critical'] ? 'critical' : 'optional' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Observability links</h2>
            </div>

            <div class="grid gap-3 p-5">
                @foreach ($links as $label => $url)
                    <a
                        href="{{ $url }}"
                        target="_blank"
                        rel="noreferrer"
                        class="group rounded-lg border border-gray-200 px-4 py-3 transition hover:border-primary-300 hover:bg-primary-50 dark:border-gray-700 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div class="font-medium text-gray-950 dark:text-white">{{ $label }}</div>
                            <div class="text-sm font-semibold text-primary-600 group-hover:text-primary-500 dark:text-primary-400">Open</div>
                        </div>
                        <div class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $url }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
