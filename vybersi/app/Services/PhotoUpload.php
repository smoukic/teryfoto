<?php

namespace App\Services;

use App\Models\Gallery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PhotoUpload
{
    public function store(Gallery $gallery, UploadedFile $file): void
    {
        $path = $file->getRealPath();
        $size = getimagesize($path);
        $mime = $size['mime'] ?? null;
        $decoder = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };

        if (! $decoder || $size[0] * $size[1] > 50000000 || ! ($image = @$decoder($path))) {
            throw ValidationException::withMessages(['photos' => 'Použijte platný JPEG, PNG nebo WebP do 50 megapixelů.']);
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = $exif['Orientation'] ?? 1;
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180,
                5, 8 => 90,
                6, 7 => -90,
                default => 0,
            };
            if ($angle) {
                $image = imagerotate($image, $angle, 0);
            }
        }

        $key = (string) Str::uuid();
        $directory = $gallery->directory()."/{$key}";
        $disk = Storage::disk('local');
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $original = basename(str_replace(chr(92), '/', $file->getClientOriginalName()));
        $original = preg_replace('/[[:cntrl:]]/u', '', $original) ?: 'fotografie.'.$extension;
        $original = Str::limit($original, 220, '');

        try {
            if (! $disk->putFileAs($directory, $file, 'original')) {
                throw new RuntimeException('Uložení originálu selhalo.');
            }
            foreach (['thumb' => 480, 'preview' => 2200] as $variant => $max) {
                $ratio = min(1, $max / max(imagesx($image), imagesy($image)));
                $width = max(1, (int) round(imagesx($image) * $ratio));
                $height = max(1, (int) round(imagesy($image) * $ratio));
                $scaled = imagecreatetruecolor($width, $height);
                imagefill($scaled, 0, 0, imagecolorallocate($scaled, 245, 243, 238));
                imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

                $this->writePreview($scaled, $disk->path("{$directory}/{$variant}.webp"));
                $mark = 'VYBERSI / NAHLED';
                $font = 5;
                $markWidth = imagefontwidth($font) * strlen($mark);
                $lineHeight = imagefontheight($font);
                $x = max(0, (int) (($width - $markWidth) / 2));
                for ($y = max(0, (int) ($height * 0.2)); $y < $height; $y += max(24, (int) ($height * 0.3))) {
                    imagefilledrectangle($scaled, max(0, $x - 8), max(0, $y - 4), $x + $markWidth + 8, $y + $lineHeight + 4, imagecolorallocatealpha($scaled, 0, 0, 0, 63));
                    imagestring($scaled, $font, $x, $y, $mark, imagecolorallocatealpha($scaled, 255, 255, 255, 28));
                }
                $this->writePreview($scaled, $disk->path("{$directory}/{$variant}-marked.webp"));
            }
            $gallery->photos()->create(['key' => $key, 'filename' => $original, 'mime' => $mime]);
        } catch (Throwable $exception) {
            $disk->deleteDirectory($directory);
            throw $exception;
        }
    }

    private function writePreview(\GdImage $image, string $path): void
    {
        if (! imagewebp($image, $path, 83) || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Vytvoření náhledu selhalo.');
        }
    }
}
