<?php

namespace Tests\Feature;

use App\Filament\Resources\CatalogResource\Pages\ManageCatalog;
use App\Filament\Resources\GalleryResource\Pages\CreateGallery;
use App\Models\CatalogEntry;
use App\Models\Gallery;
use App\Models\User;
use App\Services\GalleryPricing;
use App\Services\PhotoUpload;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AlbumPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_templates_and_configures_an_album(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageCatalog::class)->callAction(TestAction::make('create'), data: [
            'kind' => 'package', 'name' => 'Portrét',
            'details' => ['price' => 3500, 'included' => 8, 'extra' => 200, 'paid' => 0, 'travel' => 700],
        ])->assertHasNoActionErrors();
        $preset = CatalogEntry::sole();

        Livewire::test(CreateGallery::class)
            ->fillForm(['title' => 'Album', 'settings' => ['pricing' => ['enabled' => true]]])
            ->set('data.package_preset', $preset->id)
            ->call('create')->assertHasNoFormErrors()->assertRedirect();
        $gallery = Gallery::sole();
        $this->assertSame(3500, (int) $gallery->option('pricing.price'));
        $this->assertSame(8, (int) $gallery->option('pricing.included'));
        $this->assertSame('single', $gallery->option('selection_type'));
    }

    public function test_selection_checkout_uses_approved_prices_and_saves_a_receipt(): void
    {
        Storage::fake('local');
        $category = CatalogEntry::create(['name' => 'Tisk', 'kind' => 'category']);
        $service = CatalogEntry::create(['name' => 'Fotokniha', 'kind' => 'service', 'category_id' => $category->id, 'details' => ['price' => 300]]);
        $shipping = CatalogEntry::create(['name' => 'Balík', 'kind' => 'shipping', 'details' => ['price' => 90, 'address' => true]]);
        $gallery = Gallery::create([
            'title' => 'Album', 'mode' => 'proof',
            'settings' => [
                'selection_type' => 'single', 'limit' => 1, 'hide_names' => true, 'disable_notes' => true,
                'hide_after' => true, 'ask_consent' => true, 'terms' => 'Podmínky alba', 'thanks' => 'Děkuji.',
                'payment' => 'Pokyny k převodu',
                'pricing' => [
                    'enabled' => true, 'price' => 3500, 'included' => 1, 'extra' => 200,
                    'paid' => 2000, 'travel' => 700, 'shoot' => 400, 'voucher' => 500, 'quantities' => true,
                    'tiers' => [['from' => 3, 'price' => 150]], 'services' => [$service->id], 'shipping' => [$shipping->id],
                ],
            ],
        ]);
        app(PhotoUpload::class)->store($gallery, UploadedFile::fake()->image('never-visible.jpg'));
        app(PhotoUpload::class)->store($gallery, UploadedFile::fake()->image('another.jpg'));
        [$photo, $other] = $gallery->photos()->orderBy('id')->get()->all();
        $path = '/g/'.$gallery->token;
        $this->get($path)->assertOk()->assertDontSee('never-visible.jpg')->assertDontSee('Pokyny k převodu');
        $pricing = app(GalleryPricing::class)->options($gallery);

        $payload = [
            'name' => 'Klient', 'submission_key' => (string) Str::uuid(),
            'items' => [['id' => $photo->id, 'quantity' => 3, 'note' => 'Zakázaná poznámka']],
            'revision' => $pricing['revision'], 'services' => [$service->id],
            'shipping' => $shipping->id, 'publication_consent' => true,
        ];
        $this->postJson("{$path}/vyber", $payload)->assertUnprocessable()->assertJsonValidationErrors('terms_accepted');
        $payload['terms_accepted'] = true;
        $this->postJson("{$path}/vyber", $payload)->assertUnprocessable()->assertJsonValidationErrors('address');
        $payload['address'] = 'Testovací adresa';
        $this->postJson("{$path}/vyber", [...$payload, 'items' => [...$payload['items'], ['id' => $other->id]]])
            ->assertUnprocessable();
        $this->postJson("{$path}/vyber", [...$payload, 'revision' => str_repeat('0', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson("{$path}/vyber", $payload)->assertOk()->assertJsonPath('receipt.due', 2790);
        $this->postJson("{$path}/vyber", $payload)->assertOk()->assertJsonPath('receipt.due', 2790);
        $this->postJson("{$path}/vyber", [...$payload, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();

        $selection = $gallery->selections()->sole();
        $this->assertNull($selection->items[0]['note']);
        $this->assertSame(3, $selection->items[0]['quantity']);
        $this->assertSame('Testovací adresa', $selection->checkout['address']);
        $this->assertTrue($selection->checkout['publication_consent']);
        $this->assertSame('Podmínky alba', $selection->checkout['terms']);
        $this->get("{$path}/foto/{$photo->id}/thumb")->assertNotFound();
        $this->get($path)->assertOk()->assertSee('Pokyny k p\u0159evodu', false);
    }

    public function test_closed_or_download_restricted_albums_guard_media_endpoints(): void
    {
        Storage::fake('local');
        $gallery = Gallery::create(['title' => 'Album', 'mode' => 'delivery', 'settings' => ['disable_downloads' => true]]);
        app(PhotoUpload::class)->store($gallery, UploadedFile::fake()->image('file.jpg'));
        $photo = $gallery->photos()->sole();
        $path = '/g/'.$gallery->token;

        $this->get("{$path}/foto/{$photo->id}/thumb")->assertOk();
        $this->get("{$path}/foto/{$photo->id}/original")->assertNotFound();
        $this->get("{$path}/zip")->assertNotFound();
        $gallery->update(['expires_at' => now()->subMinute()]);
        $this->get($path)->assertNotFound();
        $this->get("{$path}/foto/{$photo->id}/thumb")->assertNotFound();
    }

    public function test_due_reminder_is_sent_only_once(): void
    {
        config(['mail.default' => 'array']);
        $gallery = Gallery::create([
            'title' => 'Připomínka',
            'settings' => ['selection_type' => 'single', 'reminder_email' => 'klient@example.test',
                'reminder_at' => now()->subHour()->toDateTimeString()],
        ]);
        $this->artisan('galleries:remind')->assertSuccessful();
        $this->artisan('galleries:remind')->assertSuccessful();
        $this->assertNotNull($gallery->fresh()->reminded_at);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }
}
