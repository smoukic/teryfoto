<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class GalleryController extends Controller
{
    public function show(Request $request, Gallery $gallery)
    {
        $allowed = $this->allowed($request, $gallery);
        $data = $allowed ? [
            'title' => $gallery->title,
            'description' => $gallery->description,
            'mode' => $gallery->mode,
            'submit' => route('gallery.submit', $gallery->token, false),
            'zip' => route('gallery.zip', $gallery->token, false),
            'photos' => $gallery->photos()->orderBy('id')->get()->map(function ($photo) use ($gallery) {
                $prefix = route('gallery.media', [$gallery->token, $photo->id, 'thumb'], false);
                $base = substr($prefix, 0, -strlen('thumb'));

                return [
                    'id' => $photo->id,
                    'name' => $photo->filename,
                    'thumb' => $prefix,
                    'preview' => $base.'preview',
                    'download' => $gallery->mode === 'delivery' ? $base.'original' : null,
                ];
            }),
        ] : null;

        return response()->view('gallery', compact('gallery', 'data'));
    }

    public function unlock(Request $request, Gallery $gallery)
    {
        $input = $request->validate(['password' => 'required|string|max:255']);
        if ($gallery->password && ! Hash::check($input['password'], $gallery->password)) {
            return back()->withErrors(['password' => 'Nesprávné heslo.']);
        }

        $request->session()->regenerate();
        $request->session()->put('gallery.'.$gallery->token, $this->passwordSignature($gallery));

        return redirect()->route('gallery.show', $gallery->token);
    }

    public function media(Request $request, Gallery $gallery, int $id, string $variant)
    {
        abort_unless($this->allowed($request, $gallery), 403);
        abort_if($variant === 'original' && $gallery->mode !== 'delivery', 404);
        $photo = $gallery->photos()->findOrFail($id);
        $directory = $gallery->directory().'/'.$photo->key;
        $disk = Storage::disk('local');

        if ($variant === 'original') {
            abort_unless($disk->exists("{$directory}/original"), 404);

            return response()->download($disk->path("{$directory}/original"), $photo->filename, [
                'Content-Type' => $photo->mime,
            ]);
        }

        $suffix = $gallery->mode === 'proof' && $gallery->watermark ? '-marked' : '';
        $path = "{$directory}/{$variant}{$suffix}.webp";
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), ['Content-Type' => 'image/webp']);
    }

    public function submit(Request $request, Gallery $gallery)
    {
        abort_unless($this->allowed($request, $gallery), 403);
        abort_unless($gallery->mode === 'proof', 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'submission_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:2000',
            'items.*' => 'required|array:id,note',
            'items.*.id' => 'required|integer|distinct',
            'items.*.note' => 'nullable|string|max:1500',
        ]);
        $ids = array_column($data['items'], 'id');
        $photos = $gallery->photos()->whereIntegerInRaw('id', $ids)->get()->keyBy('id');
        abort_unless($photos->count() === count($ids), 422, 'Některé fotografie už nejsou k dispozici.');

        $items = array_map(fn ($item) => [
            'filename' => $photos[$item['id']]->filename,
            'note' => $item['note'] ?? null,
        ], $data['items']);
        $gallery->selections()->firstOrCreate(
            ['submission_key' => $data['submission_key']],
            ['name' => $data['name'], 'items' => $items],
        );

        return response()->json(['saved' => true]);
    }

    public function zip(Request $request, Gallery $gallery)
    {
        abort_unless($this->allowed($request, $gallery), 403);
        abort_unless($gallery->mode === 'delivery' && $gallery->photos()->exists(), 404);
        $disk = Storage::disk('local');
        $path = tempnam(sys_get_temp_dir(), 'vybersi-');
        if ($path === false) {
            throw new RuntimeException('Vytvoření dočasného ZIPu selhalo.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Otevření ZIPu selhalo.');
            }

            $used = [];
            foreach ($gallery->photos()->orderBy('id')->cursor() as $photo) {
                $filename = $photo->filename;
                $name = $filename;
                $index = 1;
                while (isset($used[mb_strtolower($name)])) {
                    $name = ++$index.'-'.$filename;
                }
                $used[mb_strtolower($name)] = true;
                if (! $zip->addFile($disk->path($gallery->directory()."/{$photo->key}/original"), $name)) {
                    throw new RuntimeException('Přidání fotografie do ZIPu selhalo.');
                }
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
            if (! $zip->close()) {
                throw new RuntimeException('Uložení ZIPu selhalo.');
            }

            return response()->download($path, 'fotografie.zip')->deleteFileAfterSend();
        } catch (Throwable $exception) {
            unlink($path);
            throw $exception;
        }
    }

    private function allowed(Request $request, Gallery $gallery): bool
    {
        return ! $gallery->password
            || $request->user()?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'))
            || hash_equals($this->passwordSignature($gallery), (string) $request->session()->get('gallery.'.$gallery->token));
    }

    private function passwordSignature(Gallery $gallery): string
    {
        return hash('sha256', $gallery->password ?? '');
    }
}
