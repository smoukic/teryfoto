@extends('layout')
@section('title', $data ? $gallery->title.' · Vyber si' : 'Soukromá galerie · Vyber si')

@section('body')
    @if ($data)
        <div id="gallery-app"></div>
        <script type="application/json" id="gallery-data">{!! json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @else
        <main class="frame hero">
            <p class="kicker">Soukromá fotogalerie</p>
            <h1>Galerie je zamčená.</h1>
            <form class="panel mt-8 max-w-md" method="post" action="{{ route('gallery.unlock', $gallery->token, false) }}">
                @csrf
                <label class="field-label" for="password">Heslo od fotografa</label>
                <input class="field" id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password') <p class="error mt-2" role="alert">{{ $message }}</p> @enderror
                <button class="button primary mt-4" type="submit">Otevřít galerii</button>
            </form>
        </main>
    @endif
@endsection
