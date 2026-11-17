<?php

namespace App\Filament\Resources\GalleryResource;

use App\Filament\Resources\CatalogResource;
use App\Models\CatalogEntry;
use App\Models\Gallery;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class AlbumSettings
{
    public static function tabs(): Tabs
    {
        $proof = fn (Get $get) => $get('mode') === 'proof';
        $priced = fn (Get $get) => $get('settings.pricing.enabled');
        $reminder = fn (Get $get) => $get('mode') === 'proof' && $get('settings.selection_type') === 'single';

        return Tabs::make('Nastavení galerie')->columnSpanFull()->tabs([
            Tabs\Tab::make('Galerie')->columns(2)->schema([
                TextInput::make('title')->label('Název')->required()->maxLength(255),
                Select::make('mode')->label('Typ galerie')->options([
                    'proof' => 'Výběr fotografií', 'delivery' => 'Předání hotových fotografií',
                ])->default('proof')->required()->live(),
                Select::make('settings.status')->label('Dostupnost')->options([
                    'open' => 'Otevřeno', 'draft' => 'Koncept', 'closed' => 'Uzavřeno',
                ])->default('open')->required(),
                Select::make('settings.selection_type')->label('Způsob výběru')->options([
                    'single' => 'Jednoduchý výběr · jedno finální odeslání',
                    'group' => 'Hromadný výběr · samostatné odeslání každého klienta',
                ])->default('single')->selectablePlaceholder(false)->live()->visible($proof)
                    ->disabled(fn (?Gallery $record) => $record?->selections()->exists() ?? false),
                TextInput::make('new_password')->label('Heslo galerie')->password()->revealable()
                    ->autocomplete('new-password')->maxLength(72)
                    ->helperText('Prázdné pole ponechá dosavadní nastavení.'),
                Toggle::make('remove_password')->label('Odebrat heslo')
                    ->visible(fn (?Gallery $record) => filled($record?->password)),
                Select::make('settings.sort')->label('Řazení fotografií')->options([
                    'upload' => 'Pořadí nahrání', 'newest' => 'Nejnovější první',
                    'name_asc' => 'Název A–Z', 'name_desc' => 'Název Z–A',
                ])->default('upload')->required(),
                DateTimePicker::make('expires_at')->label('Vyprší')->timezone('Europe/Prague')->seconds(false),
                Toggle::make('watermark')->label('Vodoznak na náhledech'),
                Toggle::make('settings.disable_downloads')->label('Zakázat stahování originálů'),
                Toggle::make('settings.hide_names')->label('Skrýt názvy fotografií'),
                Toggle::make('settings.disable_notes')->label('Zakázat poznámky k fotografiím')->visible($proof),
                Toggle::make('settings.hide_after')->label('Skrýt fotografie po odeslání')->visible($proof),
                Toggle::make('settings.ask_consent')->label('Nabídnout souhlas se zveřejněním')->visible($proof),
                TextInput::make('settings.limit')->label('Maximální počet vybraných fotografií')
                    ->integer()->minValue(1)->maxValue(2000)->placeholder('Bez limitu')->visible($proof),
                TextInput::make('settings.reminder_email')->label('E-mail pro připomínku výběru')
                    ->email()->maxLength(254)->requiredWith('settings.reminder_at')->visible($reminder),
                DateTimePicker::make('settings.reminder_at')->label('Odeslat připomínku nejdříve')
                    ->requiredWith('settings.reminder_email')->timezone('Europe/Prague')->seconds(false)->visible($reminder),
            ]),
            Tabs\Tab::make('Balíček a služby')->visible($proof)->columns(2)->schema([
                Toggle::make('settings.pricing.enabled')->label('Počítat cenu výběru')->live(),
                Select::make('package_preset')->label('Načíst šablonu balíčku')
                    ->options(fn () => CatalogEntry::options('package'))->searchable()->live()->dehydrated(false)
                    ->visible($priced)->afterStateUpdated(function (Set $set, $state) {
                        $entry = CatalogEntry::where('kind', 'package')->find($state);
                        if ($entry) {
                            foreach (['price', 'included', 'extra', 'paid', 'travel'] as $field) {
                                $set("settings.pricing.{$field}", $entry->details[$field] ?? 0);
                            }
                        }
                    }),
                ...array_map(fn ($field) => $field->visible($priced), [
                    CatalogResource::money('settings.pricing.price', 'Cena balíčku'),
                    CatalogResource::count('settings.pricing.included', 'Fotografie v balíčku'),
                    CatalogResource::money('settings.pricing.extra', 'Cena za fotografii navíc'),
                    CatalogResource::money('settings.pricing.paid', 'Již zaplaceno'),
                    CatalogResource::money('settings.pricing.travel', 'Doprava na focení'),
                    CatalogResource::money('settings.pricing.shoot', 'Příplatek za focení'),
                    CatalogResource::money('settings.pricing.voucher', 'Hodnota dárkového poukazu'),
                    Toggle::make('settings.pricing.quantities')->label('Umožnit více kusů od jedné fotografie'),
                    Select::make('settings.pricing.services')->label('Nabízené služby')->multiple()->searchable()
                        ->options(fn () => CatalogEntry::options('service')),
                    Select::make('settings.pricing.shipping')->label('Nabízené způsoby doručení')->multiple()->searchable()
                        ->options(fn () => CatalogEntry::options('shipping')),
                    Repeater::make('settings.pricing.tiers')->label('Cenové hladiny fotografií')
                        ->helperText('Při dosažení hranice se tato cena použije na všechny kusy nad rámec balíčku.')
                        ->defaultItems(0)->maxItems(20)->columns(2)->columnSpanFull()->schema([
                            CatalogResource::count('from', 'Od celkového počtu kusů')->minValue(1)->default(1)->distinct(),
                            CatalogResource::money('price', 'Cena za kus nad rámec balíčku'),
                        ]),
                ]),
            ]),
            Tabs\Tab::make('Texty pro klienta')->columns(2)->schema([
                ...self::message('description', 'Zpráva při otevření galerie'),
                ...self::message('settings.thanks', 'Poděkování po odeslání', $proof),
                ...self::message('settings.payment', 'Platební pokyny při kladném doplatku', $proof),
                ...self::message('settings.terms', 'Obchodní podmínky k povinnému odsouhlasení', $proof),
            ]),
        ]);
    }

    private static function message(string $path, string $label, ?\Closure $visible = null): array
    {
        return [
            Textarea::make($path)->label($label)->maxLength(4000)->visible($visible ?? true),
            Select::make($path.'_preset')->label('Vložit text ze šablony')
                ->options(fn () => CatalogEntry::options('text'))->searchable()->live()->dehydrated(false)
                ->visible($visible ?? true)->afterStateUpdated(function (Set $set, $state) use ($path) {
                    $entry = CatalogEntry::where('kind', 'text')->find($state);
                    if ($entry) {
                        $set($path, $entry->details['body'] ?? '');
                    }
                }),
        ];
    }
}
