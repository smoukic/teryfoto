<?php

namespace App\Http\Controllers;

use App\Filament\Resources\GalleryResource;
use App\Models\Gallery;
use App\Services\PhotoUpload;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class UploadController extends Controller
{
    public function show(Request $request, Gallery $gallery)
    {
        $this->authorizeAdmin($request);

        return view('admin.upload', [
            'gallery' => $gallery,
            'back' => GalleryResource::getUrl('edit', ['record' => $gallery], panel: 'admin'),
        ]);
    }

    public function store(Request $request, Gallery $gallery, PhotoUpload $upload)
    {
        $this->authorizeAdmin($request);
        $input = $request->validate(['photo' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max('28mb')]]);
        $upload->store($gallery, $input['photo']);

        return response()->json(['saved' => true], 201);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->canAccessPanel(Filament::getPanel('admin')), 403);
    }
}
