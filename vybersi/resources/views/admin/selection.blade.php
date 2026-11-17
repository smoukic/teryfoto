<ul style="display: grid; gap: .75rem; max-height: 55vh; overflow: auto;">
    @foreach ($items as $item)
        <li>
            <strong>{{ $item['filename'] }}</strong>
            @if (filled($item['note']))
                <p style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $item['note'] }}</p>
            @endif
        </li>
    @endforeach
</ul>
