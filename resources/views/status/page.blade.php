<!doctype html>
<html lang="{{ str_replace('_', '-', $snapshot['locale']) }}">
<head>
    <meta charset="utf-8">
    @if ($unlisted)
        <meta name="robots" content="noindex">
    @endif
    <title>{{ $snapshot['title'] }}</title>
</head>
<body>
    <main>
        <header>
            <h1>{{ $snapshot['title'] }}</h1>
            <p role="status">
                <span aria-hidden="true">●</span>
                {{ __('status.states.' . $snapshot['overall_state']) }}
            </p>
        </header>

        <section aria-labelledby="components-heading">
            <h2 id="components-heading">{{ __('status.components') }}</h2>
            @foreach ($snapshot['components'] as $component)
                <article>
                    <h3>{{ $component['name'] }}</h3>
                    <p>
                        <span aria-hidden="true">●</span>
                        {{ __('status.states.' . $component['state']) }}
                    </p>
                    <dl>
                        @foreach ($component['uptime'] as $period => $percentage)
                            <dt>{{ __('status.uptime_for', ['period' => $period]) }}</dt>
                            <dd>{{ number_format($percentage, 2, $snapshot['locale'] === 'pt_BR' ? ',' : '.', '') }}%</dd>
                        @endforeach
                    </dl>
                    <section aria-label="{{ __('status.history_for', ['component' => $component['name']]) }}">
                        <h4>{{ __('status.history') }}</h4>
                        <ul>
                            @foreach ($component['history'] as $day)
                                <li>
                                    <span aria-hidden="true">●</span>
                                    {{ $day['date'] }}: {{ __('status.states.' . $day['state']) }}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </article>
            @endforeach
        </section>

        <section aria-labelledby="incidents-heading">
            <h2 id="incidents-heading">{{ __('status.incidents') }}</h2>
            <ol>
                @foreach ($snapshot['incidents'] as $incident)
                    <li>
                        {{ __('status.states.' . $incident['state']) }}
                        — {{ __('status.incident_started', ['at' => \Carbon\Carbon::parse($incident['started_at'])->timezone($snapshot['timezone'])->format($snapshot['locale'] === 'pt_BR' ? 'd/m/Y H:i' : 'm/d/Y h:i A')]) }}
                        @if ($incident['resolved_at'] !== null)
                            — {{ __('status.incident_resolved', ['at' => \Carbon\Carbon::parse($incident['resolved_at'])->timezone($snapshot['timezone'])->format($snapshot['locale'] === 'pt_BR' ? 'd/m/Y H:i' : 'm/d/Y h:i A')]) }}
                        @endif
                        <ol>
                            @foreach ($incident['updates'] as $update)
                                <li>{{ __('status.incident_update', ['status' => $update['status'], 'at' => \Carbon\Carbon::parse($update['published_at'])->timezone($snapshot['timezone'])->format($snapshot['locale'] === 'pt_BR' ? 'd/m/Y H:i' : 'm/d/Y h:i A')]) }}</li>
                            @endforeach
                        </ol>
                    </li>
                @endforeach
            </ol>
        </section>

        <p>{{ __('status.uptime_formula') }}</p>
    </main>
</body>
</html>
