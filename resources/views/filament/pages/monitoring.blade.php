<x-filament-panels::page>
    @php
        $readiness = $this->readiness();
        $overview = $this->overview();
        $links = $this->links();
        $failedCriticalChecks = collect($readiness)->filter(fn (array $check): bool => ($check['critical'] ?? true) && ! $check['ok'])->count();
        $failedChecks = collect($readiness)->filter(fn (array $check): bool => ! $check['ok'])->count();
        $healthyChecks = collect($readiness)->filter(fn (array $check): bool => $check['ok'])->count();
        $overallStatus = $failedCriticalChecks > 0 ? 'unavailable' : ($failedChecks > 0 ? 'degraded' : 'ok');
        $metricNumbers = collect($overview)->map(fn (string $value): int => (int) str_replace(' ', '', $value))->all();
        $maxMetric = max([1, ...$metricNumbers]);
    @endphp

    <style>
        .sf-monitoring {
            --sf-bg: #f8fafc;
            --sf-card: #ffffff;
            --sf-card-soft: #f1f5f9;
            --sf-border: #dbe3ef;
            --sf-text: #111827;
            --sf-muted: #64748b;
            --sf-primary: #d97706;
            --sf-primary-soft: #fff7ed;
            --sf-success: #15803d;
            --sf-success-soft: #dcfce7;
            --sf-warning: #b45309;
            --sf-warning-soft: #fef3c7;
            --sf-danger: #b91c1c;
            --sf-danger-soft: #fee2e2;
            display: grid;
            gap: 22px;
        }

        .dark .sf-monitoring {
            --sf-bg: #020617;
            --sf-card: #0f172a;
            --sf-card-soft: #111827;
            --sf-border: #334155;
            --sf-text: #f8fafc;
            --sf-muted: #94a3b8;
            --sf-primary-soft: rgba(217, 119, 6, 0.14);
            --sf-success-soft: rgba(21, 128, 61, 0.18);
            --sf-warning-soft: rgba(180, 83, 9, 0.2);
            --sf-danger-soft: rgba(185, 28, 28, 0.2);
        }

        .sf-hero,
        .sf-panel,
        .sf-metric {
            background: var(--sf-card);
            border: 1px solid var(--sf-border);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .sf-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 22px;
            padding: 24px;
            align-items: center;
            border-top: 4px solid var(--sf-primary);
        }

        .sf-eyebrow,
        .sf-label {
            color: var(--sf-muted);
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .sf-title {
            margin-top: 6px;
            color: var(--sf-text);
            font-size: 30px;
            font-weight: 750;
            line-height: 1.15;
        }

        .sf-subtitle {
            margin-top: 8px;
            color: var(--sf-muted);
            font-size: 14px;
        }

        .sf-hero-status {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }

        .sf-pill {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            border-radius: 999px;
            border: 1px solid var(--sf-border);
            padding: 7px 12px;
            color: var(--sf-text);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sf-pill-ok {
            border-color: rgba(21, 128, 61, 0.28);
            background: var(--sf-success-soft);
            color: var(--sf-success);
        }

        .sf-pill-degraded {
            border-color: rgba(180, 83, 9, 0.28);
            background: var(--sf-warning-soft);
            color: var(--sf-warning);
        }

        .sf-pill-unavailable {
            border-color: rgba(185, 28, 28, 0.28);
            background: var(--sf-danger-soft);
            color: var(--sf-danger);
        }

        .sf-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .sf-summary-card {
            background: var(--sf-card);
            border: 1px solid var(--sf-border);
            border-radius: 10px;
            padding: 18px;
        }

        .sf-summary-value {
            margin-top: 8px;
            color: var(--sf-text);
            font-size: 32px;
            font-weight: 760;
            line-height: 1;
        }

        .sf-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .sf-metric {
            padding: 18px;
        }

        .sf-metric-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .sf-metric-value {
            margin-top: 8px;
            color: var(--sf-text);
            font-size: 28px;
            font-weight: 760;
        }

        .sf-metric-mark {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--sf-primary-soft);
            border: 1px solid rgba(217, 119, 6, 0.18);
        }

        .sf-metric-mark-warning {
            background: var(--sf-warning-soft);
            border-color: rgba(180, 83, 9, 0.18);
        }

        .sf-bar {
            height: 8px;
            margin-top: 16px;
            border-radius: 999px;
            background: var(--sf-card-soft);
            overflow: hidden;
        }

        .sf-bar-fill {
            height: 100%;
            border-radius: inherit;
            background: var(--sf-primary);
        }

        .sf-bar-fill-warning {
            background: var(--sf-warning);
        }

        .sf-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.75fr);
            gap: 22px;
        }

        .sf-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--sf-border);
            background: var(--sf-card-soft);
        }

        .sf-panel-title {
            color: var(--sf-text);
            font-size: 16px;
            font-weight: 750;
        }

        .sf-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sf-table th,
        .sf-table td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--sf-border);
            text-align: left;
            vertical-align: top;
        }

        .sf-table th {
            color: var(--sf-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .sf-table td {
            color: var(--sf-text);
            font-size: 14px;
        }

        .sf-table tr:last-child td {
            border-bottom: 0;
        }

        .sf-service {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .sf-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: var(--sf-danger);
            box-shadow: 0 0 0 4px var(--sf-danger-soft);
        }

        .sf-dot-ok {
            background: var(--sf-success);
            box-shadow: 0 0 0 4px var(--sf-success-soft);
        }

        .sf-detail {
            margin-top: 6px;
            color: var(--sf-muted);
            font-size: 13px;
            word-break: break-word;
        }

        .sf-links {
            display: grid;
            gap: 12px;
            padding: 16px;
        }

        .sf-link-card {
            display: block;
            border: 1px solid var(--sf-border);
            border-radius: 10px;
            padding: 14px;
            color: inherit;
            text-decoration: none;
            transition: border-color 160ms ease, background 160ms ease, transform 160ms ease;
        }

        .sf-link-card:hover {
            background: var(--sf-primary-soft);
            border-color: rgba(217, 119, 6, 0.45);
            transform: translateY(-1px);
        }

        .sf-link-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .sf-link-title {
            color: var(--sf-text);
            font-weight: 750;
        }

        .sf-link-action {
            border-radius: 999px;
            background: var(--sf-primary);
            color: #ffffff;
            font-size: 12px;
            font-weight: 750;
            padding: 6px 10px;
            white-space: nowrap;
        }

        .sf-link-url {
            margin-top: 8px;
            color: var(--sf-muted);
            font-size: 13px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 1100px) {
            .sf-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sf-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .sf-hero {
                grid-template-columns: 1fr;
            }

            .sf-hero-status {
                justify-content: flex-start;
            }

            .sf-summary,
            .sf-metrics {
                grid-template-columns: 1fr;
            }

            .sf-table {
                min-width: 680px;
            }

            .sf-table-wrap {
                overflow-x: auto;
            }
        }
    </style>

    <div class="sf-monitoring">
        <section class="sf-hero">
            <div>
                <div class="sf-eyebrow">Stockflow runtime</div>
                <div class="sf-title">{{ config('stockflow.runtime.service_name') }}</div>
                <div class="sf-subtitle">Состояние сервиса, очередей, зависимостей и внешних панелей наблюдаемости.</div>
            </div>

            <div class="sf-hero-status">
                <span class="sf-pill sf-pill-{{ $overallStatus }}">{{ strtoupper($overallStatus) }}</span>
                <span class="sf-pill">{{ $healthyChecks }}/{{ count($readiness) }} checks ok</span>
                <span class="sf-pill">{{ now()->format('H:i:s') }}</span>
            </div>
        </section>

        <section class="sf-summary">
            <div class="sf-summary-card">
                <div class="sf-label">Healthy dependencies</div>
                <div class="sf-summary-value">{{ $healthyChecks }}</div>
            </div>
            <div class="sf-summary-card">
                <div class="sf-label">Degraded dependencies</div>
                <div class="sf-summary-value">{{ $failedChecks }}</div>
            </div>
            <div class="sf-summary-card">
                <div class="sf-label">Critical failures</div>
                <div class="sf-summary-value">{{ $failedCriticalChecks }}</div>
            </div>
        </section>

        <section class="sf-metrics">
            @foreach ($overview as $label => $value)
                @php
                    $numericValue = (int) str_replace(' ', '', $value);
                    $barWidth = max(6, min(100, (int) round(($numericValue / $maxMetric) * 100)));
                    $isProblemMetric = str_contains(strtolower($label), 'failed') || str_contains(strtolower($label), 'pending');
                @endphp

                <div class="sf-metric">
                    <div class="sf-metric-top">
                        <div>
                            <div class="sf-label">{{ $label }}</div>
                            <div class="sf-metric-value">{{ $value }}</div>
                        </div>
                        <div class="sf-metric-mark {{ $isProblemMetric ? 'sf-metric-mark-warning' : '' }}"></div>
                    </div>

                    <div class="sf-bar">
                        <div
                            class="sf-bar-fill {{ $isProblemMetric ? 'sf-bar-fill-warning' : '' }}"
                            style="width: {{ $barWidth }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </section>

        <div class="sf-grid">
            <section class="sf-panel">
                <div class="sf-panel-header">
                    <div class="sf-panel-title">Dependencies</div>
                    <span class="sf-pill sf-pill-{{ $overallStatus }}">{{ $overallStatus }}</span>
                </div>

                <div class="sf-table-wrap">
                    <table class="sf-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Criticality</th>
                                <th>Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($readiness as $name => $check)
                                <tr>
                                    <td>
                                        <div class="sf-service">
                                            <span class="sf-dot {{ $check['ok'] ? 'sf-dot-ok' : '' }}"></span>
                                            {{ $name }}
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sf-pill {{ $check['ok'] ? 'sf-pill-ok' : 'sf-pill-unavailable' }}">
                                            {{ $check['status'] }}
                                        </span>
                                    </td>
                                    <td>{{ $check['critical'] ? 'critical' : 'optional' }}</td>
                                    <td>
                                        @if (! empty($check['detail']))
                                            <div class="sf-detail">{{ $check['detail'] }}</div>
                                        @else
                                            <span class="sf-detail">No issues</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sf-panel">
                <div class="sf-panel-header">
                    <div class="sf-panel-title">Observability links</div>
                </div>

                <div class="sf-links">
                    @foreach ($links as $label => $url)
                        <a
                            href="{{ $url }}"
                            target="_blank"
                            rel="noreferrer"
                            class="sf-link-card"
                        >
                            <div class="sf-link-row">
                                <div class="sf-link-title">{{ $label }}</div>
                                <div class="sf-link-action">Open</div>
                            </div>
                            <div class="sf-link-url">{{ $url }}</div>
                        </a>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
