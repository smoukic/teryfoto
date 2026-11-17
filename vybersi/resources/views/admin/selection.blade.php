<div style="max-height: 60vh; overflow: auto;">
    @php($totals = $checkout['totals'] ?? null)
    @if ($totals)
        @php($money = fn ($value) => number_format($value, 0, ',', ' ').' Kč')
        <dl style="display: grid; gap: .3rem; margin-bottom: 1rem;">
            <div>Balíček: {{ $money($totals['package_price']) }} · {{ $totals['units'] }} ks</div>
            @if ($totals['extra_units'])
                <div>Kusy navíc: {{ $totals['extra_units'] }} × {{ $money($totals['unit_price']) }}</div>
            @endif
            @foreach (['travel' => 'Doprava na focení', 'shoot' => 'Focení', 'paid' => 'Již zaplaceno', 'voucher' => 'Poukaz'] as $key => $label)
                @if ($totals[$key])
                    <div>{{ $label }}: {{ $money($totals[$key]) }}</div>
                @endif
            @endforeach
            @foreach ($totals['services'] as $service)
                <div>{{ $service['name'] }}: {{ $money($service['price']) }}</div>
            @endforeach
            @if ($totals['shipping'])
                <div>{{ $totals['shipping']['name'] }}: {{ $money($totals['shipping']['price']) }}</div>
            @endif
            @if (filled($checkout['address'] ?? null))
                <div style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $checkout['address'] }}</div>
            @endif
            <strong>K doplacení {{ $money($totals['due']) }}</strong>
        </dl>
    @endif
    @if (isset($checkout['publication_consent']))
        <p>Souhlas se zveřejněním: {{ $checkout['publication_consent'] ? 'Ano' : 'Ne' }}</p>
    @endif
    @if (filled($checkout['terms'] ?? null))
        <details style="margin-bottom: .75rem;">
            <summary>Odsouhlasené obchodní podmínky</summary>
            <p style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $checkout['terms'] }}</p>
        </details>
    @endif
    <ul style="display: grid; gap: .75rem;">
        @foreach ($items as $item)
            <li>
                <strong>{{ $item['filename'] }}</strong>
                @if (($item['quantity'] ?? 1) > 1) · {{ $item['quantity'] }} ks @endif
                @if (filled($item['note']))
                    <p style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $item['note'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>
</div>
