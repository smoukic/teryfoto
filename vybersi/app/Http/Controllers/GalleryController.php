<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Services\GalleryPricing;
use App\Services\SelectionCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class GalleryController extends Controller
{
    public function show(Request $request, Gallery $gallery, GalleryPricing $pricing)
    {
        $this->checkAvailability($request, $gallery);
        $allowed = $this->allowed($request, $gallery);
        $receipt = $allowed && $gallery->mode === 'proof' ? $this->receipt($request, $gallery) : null;
        $sort = match ($gallery->option('sort')) {
            'newest' => ['id', 'desc'],
            'name_asc' => ['filename', 'asc'],
            'name_desc' => ['filename', 'desc'],
            default => ['id', 'asc'],
        };
        $photos = $allowed && ! $this->photosHidden($request, $gallery)
            ? $gallery->photos()->orderBy(...$sort)->orderBy('id')->get() : collect();

        $data = $allowed ? [
            'title' => $gallery->title,
            'description' => $gallery->description,
            'mode' => $gallery->mode,
            'pricing' => $pricing->options($gallery),
            'limit' => (int) $gallery->option('limit', 2000),
            'notesAllowed' => ! $gallery->option('disable_notes'),
            'askConsent' => (bool) $gallery->option('ask_consent'),
            'terms' => $gallery->option('terms'),
            'receipt' => $receipt ? $this->publicReceipt($receipt->checkout ?? []) : null,
            'selectionClosed' => $this->selectionClosed($gallery),
            'photosHidden' => $this->photosHidden($request, $gallery),
            'hideAfter' => (bool) $gallery->option('hide_after'),
            'submit' => route('gallery.submit', $gallery->token, false),
            'zip' => $gallery->downloadsAllowed() ? route('gallery.zip', $gallery->token, false) : null,
            'photos' => $photos->map(function ($photo, $index) use ($gallery) {
                return [
                    'id' => $photo->id,
                    'name' => $gallery->option('hide_names') ? 'Fotografie '.($index + 1) : $photo->filename,
                    'thumb' => route('gallery.media', [$gallery->token, $photo->id, 'thumb'], false),
                    'preview' => route('gallery.media', [$gallery->token, $photo->id, 'preview'], false),
                    'download' => $gallery->downloadsAllowed()
                        ? route('gallery.media', [$gallery->token, $photo->id, 'original'], false) : null,
                ];
            }),
        ] : null;

        return response()->view('gallery', compact('gallery', 'data'));
    }


    public function unlock(Request $request, Gallery $gallery)
    {
        $this->checkAvailability($request, $gallery);
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
        $this->checkAvailability($request, $gallery);
        abort_unless($this->allowed($request, $gallery), 403);
        abort_if($this->photosHidden($request, $gallery), 404);
        abort_if($variant === 'original' && ! $gallery->downloadsAllowed(), 404);
        $photo = $gallery->photos()->findOrFail($id);
        $directory = $gallery->directory().'/'.$photo->key;
        $disk = Storage::disk('local');

        if ($variant === 'original') {
            abort_unless($disk->exists("{$directory}/original"), 404);

            return response()->download($disk->path("{$directory}/original"), $this->downloadName($gallery, $photo), [
                'Content-Type' => $photo->mime,
            ]);
        }

        $suffix = $gallery->watermark ? '-marked' : '';
        $path = "{$directory}/{$variant}{$suffix}.webp";
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), ['Content-Type' => 'image/webp']);
    }

    public function submit(Request $request, Gallery $gallery, SelectionCheckout $checkout)
    {
        $this->checkAvailability($request, $gallery);
        abort_unless($this->allowed($request, $gallery), 403);
        abort_unless($gallery->mode === 'proof', 404);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'submission_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:2000',
            'items.*' => 'required|array:id,note,quantity',
            'items.*.id' => 'required|integer|distinct',
            'items.*.note' => 'nullable|string|max:1500',
            'items.*.quantity' => 'nullable|integer|min:1|max:99',
            'revision' => 'nullable|string|size:64',
            'services' => 'nullable|array|max:200',
            'services.*' => 'integer|distinct',
            'shipping' => 'nullable|integer',
            'address' => 'nullable|string|max:500',
            'terms_accepted' => 'nullable|boolean',
            'publication_consent' => 'nullable|boolean',
        ]);

        $selection = DB::transaction(function () use ($request, $gallery, $data, $checkout) {
            $gallery = Gallery::query()->lockForUpdate()->findOrFail($gallery->id);
            $this->checkAvailability($request, $gallery);
            abort_unless($this->allowed($request, $gallery), 403);
            abort_unless($gallery->mode === 'proof', 404);

            $existing = $gallery->selections()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                return $existing;
            }

            abort_if($this->selectionClosed($gallery), 422, 'Finální výběr už byl odeslán.');
            $ids = array_column($data['items'], 'id');
            $limit = (int) $gallery->option('limit', 2000);
            abort_if(count($ids) > $limit, 422, "Lze vybrat nejvýše {$limit} fotografií.");
            $photos = $gallery->photos()->whereIntegerInRaw('id', $ids)->get()->keyBy('id');
            abort_unless($photos->count() === count($ids), 422, 'Některé fotografie už nejsou k dispozici.');

            $summary = $checkout->build($gallery, $data);
            $quantities = isset($summary['totals']) && $gallery->option('pricing.quantities');
            $items = array_map(fn ($item) => [
                'filename' => $photos[$item['id']]->filename,
                'note' => $gallery->option('disable_notes') ? null : ($item['note'] ?? null),
                'quantity' => $quantities ? (int) ($item['quantity'] ?? 1) : 1,
            ], $data['items']);

            return $gallery->selections()->create([
                'submission_key' => $data['submission_key'], 'name' => $data['name'],
                'items' => $items, 'checkout' => $summary,
            ]);
        });

        if ($request->session()->get('receipt.'.$gallery->token) !== $selection->id) {
            $request->session()->put('receipt.'.$gallery->token, $selection->id);
        }

        return response()->json(['saved' => true, 'receipt' => $this->publicReceipt($selection->checkout ?? [])]);
    }

    private function publicReceipt(array $checkout): array
    {
        return [
            'thanks' => $checkout['thanks'] ?? null,
            'payment' => $checkout['payment'] ?? null,
            'due' => $checkout['totals']['due'] ?? null,
        ];
    }

    private function receipt(Request $request, Gallery $gallery): ?\App\Models\Selection
    {
        $id = $request->session()->get('receipt.'.$gallery->token);

        return $id ? $gallery->selections()->find($id) : null;
    }

    private function selectionClosed(Gallery $gallery): bool
    {
        return $gallery->mode === 'proof' && $gallery->option('selection_type', 'group') === 'single'
            && $gallery->selections()->exists();
    }

    private function photosHidden(Request $request, Gallery $gallery): bool
    {
        return $gallery->mode === 'proof' && $gallery->option('hide_after') && ! $this->isAdmin($request)
            && ($this->selectionClosed($gallery) || $this->receipt($request, $gallery));
    }

    private function checkAvailability(Request $request, Gallery $gallery): void
    {
        abort_unless($gallery->isOpen() || $this->isAdmin($request), 404);
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
    }

    private function downloadName(Gallery $gallery, \App\Models\Photo $photo): string
    {
        if (! $gallery->option('hide_names')) {
            return $photo->filename;
        }
        $extension = match ($photo->mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };

        return "fotografie-{$photo->id}.{$extension}";
    }


    public function zip(Request $request, Gallery $gallery)
    {
        $this->checkAvailability($request, $gallery);
        abort_unless($this->allowed($request, $gallery), 403);
        abort_unless($gallery->downloadsAllowed() && $gallery->photos()->exists(), 404);
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
                $filename = $this->downloadName($gallery, $photo);
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
            || $this->isAdmin($request)
            || hash_equals($this->passwordSignature($gallery), (string) $request->session()->get('gallery.'.$gallery->token));
    }

    private function passwordSignature(Gallery $gallery): string
    {
        return hash('sha256', $gallery->password ?? '');
    }
}
