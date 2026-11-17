@extends('layout')

@section('body')
    <main class="frame hero">
        <p class="kicker">Soukromé fotogalerie</p>
        <h1>Vyber si.</h1>
        <p class="intro">Pokud vám fotograf poslal odkaz, otevřete ho a zobrazí se vaše galerie.</p>
        <a class="button primary mt-5" href="{{ route('filament.admin.auth.login') }}">Přihlášení fotografa</a>
    </main>
@endsection
