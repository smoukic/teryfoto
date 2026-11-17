<?php

namespace Tests\Feature;

use App\Filament\Resources\GalleryResource\Pages\CreateGallery;
use App\Filament\Resources\GalleryResource\Pages\EditGallery;
use App\Filament\Resources\GalleryResource\RelationManagers\Photos;
use App\Filament\Resources\GalleryResource\RelationManagers\Selections;
use App\Models\Gallery;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GalleryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_protected_workflow_from_filament_to_export(): void
    {
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Livewire::test(CreateGallery::class)
            ->fillForm(['mode' => 'proof', 'title' => 'Soukromé focení', 'new_password' => 'Testovaci-heslo', 'watermark' => true])
            ->call('create')->assertHasNoFormErrors()->assertRedirect();

        $gallery = Gallery::sole();
        $this->assertTrue(Hash::check('Testovaci-heslo', $gallery->password));
        $this->get(route('admin.gallery.upload', $gallery))->assertOk();
        $this->postJson(route('admin.gallery.upload.store', $gallery), [
            'photo' => UploadedFile::fake()->image('snímek.jpg', 640, 500),
        ])->assertCreated();
        $photo = $gallery->photos()->sole();
        $disk = Storage::disk('local');
        $directory = $photo->directory();
        $disk->assertExists("{$directory}/thumb.webp");
        $disk->assertExists("{$directory}/preview-marked.webp");
        $this->assertNotSame($disk->get("{$directory}/preview.webp"), $disk->get("{$directory}/preview-marked.webp"));

        $path = '/g/'.$gallery->token;
        auth()->logout();
        $this->get($path)->assertOk()->assertSee('Galerie je zamčená')->assertDontSee('Soukromé focení');
        $this->get("{$path}/foto/{$photo->id}/thumb")->assertForbidden();
        $this->postJson(route('admin.gallery.upload.store', $gallery), [
            'photo' => UploadedFile::fake()->image('skryty.jpg'),
        ])->assertUnauthorized();
        $this->post("{$path}/heslo", ['password' => 'Testovaci-heslo'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('Soukromé focení')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $preview = $this->get("{$path}/foto/{$photo->id}/preview")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame($disk->path("{$directory}/preview-marked.webp"), $preview->baseResponse->getFile()->getPathname());
        $this->get("{$path}/foto/{$photo->id}/original")->assertNotFound();

        $payload = [
            'name' => 'Klientka',
            'submission_key' => (string) Str::uuid(),
            'items' => [['id' => $photo->id, 'note' => 'Prosím o černobílou verzi.']],
        ];
        $this->postJson("{$path}/vyber", [...$payload, 'items' => [['id' => $photo->id + 5]]])->assertUnprocessable();
        $this->postJson("{$path}/vyber", $payload)->assertOk()->assertJson(['saved' => true]);
        $this->postJson("{$path}/vyber", $payload)->assertOk();
        $selection = $gallery->selections()->sole();
        $this->assertSame('snímek.jpg', $selection->items[0]['filename']);
        $this->assertSame('Prosím o černobílou verzi.', $selection->items[0]['note']);

        $this->actingAs($admin);
        $this->get('/admin/galleries/'.$gallery->id.'/edit')->assertOk();
        Livewire::test(Photos::class, ['ownerRecord' => $gallery, 'pageClass' => EditGallery::class])
            ->assertCanSeeTableRecords([$photo]);
        Livewire::test(Selections::class, ['ownerRecord' => $gallery, 'pageClass' => EditGallery::class])
            ->assertCanSeeTableRecords([$selection])
            ->callAction(TestAction::make('export')->table($selection))
            ->assertFileDownloaded("vyber-{$selection->id}.txt");

        Livewire::test(EditGallery::class, ['record' => $gallery->id])
            ->fillForm(['mode' => 'proof', 'title' => 'Upravený název'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('Testovaci-heslo', $gallery->fresh()->password));

        Livewire::test(EditGallery::class, ['record' => $gallery->id])
            ->fillForm(['remove_password' => true])->call('save')->assertHasNoFormErrors();
        $this->assertNull($gallery->fresh()->password);

        $gallery->delete();
        $disk->assertMissing("{$directory}/original");
        $this->get($path)->assertNotFound();
    }

    public function test_delivery_mode_downloads_originals_and_resolves_duplicate_zip_names(): void
    {
        Storage::fake('local');
        $gallery = Gallery::create(['title' => 'Hotové fotografie', 'mode' => 'delivery']);
        foreach (range(1, 2) as $index) {
            app(\App\Services\PhotoUpload::class)->store($gallery, UploadedFile::fake()->image('foto.jpg'));
        }
        $photo = $gallery->photos()->firstOrFail();
        $path = '/g/'.$gallery->token;
        $this->get("{$path}/foto/{$photo->id}/original")->assertOk()->assertDownload('foto.jpg');
        $this->postJson("{$path}/vyber", [])->assertNotFound();

        $response = $this->get("{$path}/zip")->assertOk()->assertDownload('fotografie.zip');
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('foto.jpg', $zip->getNameIndex(0));
        $this->assertSame('2-foto.jpg', $zip->getNameIndex(1));
        $zip->close();
        unlink($file);
    }
}
