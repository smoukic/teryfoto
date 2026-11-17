<?php

use App\Http\Controllers\GalleryController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\PrivateGallery;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::middleware(PrivateGallery::class)
    ->controller(GalleryController::class)
    ->prefix('g/{gallery:token}')
    ->name('gallery.')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/heslo', 'unlock')->middleware('throttle:10,1')->name('unlock');
        Route::get('/foto/{id}/{variant}', 'media')->whereNumber('id')
            ->whereIn('variant', ['thumb', 'preview', 'original'])->name('media');
        Route::get('/zip', 'zip')->middleware('throttle:5,1')->name('zip');
        Route::post('/vyber', 'submit')->middleware('throttle:12,1')->name('submit');
    });

Route::middleware('auth')->controller(UploadController::class)->group(function () {
    Route::get('/admin/galleries/{gallery}/upload', 'show')->name('admin.gallery.upload');
    Route::post('/admin/galleries/{gallery}/upload', 'store')->name('admin.gallery.upload.store');
});