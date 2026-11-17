@extends('layout')
@section('title', 'Nahrát fotografie · Vyber si')

@section('header')
    <a class="button" href="{{ $back }}">Zpět do galerie</a>
@endsection

@section('body')
    <main class="frame hero">
        <p class="kicker">{{ $gallery->title }}</p>
        <h1>Nahrát fotografie</h1>
        <div id="upload-app" data-endpoint="{{ route('admin.gallery.upload.store', $gallery, false) }}" data-back="{{ $back }}"></div>
    </main>
@endsection
