<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogResource\Pages\ManageCatalog;
use App\Models\CatalogEntry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CatalogResource extends Resource
{
    protected static ?string $model = CatalogEntry::class;

    protected static ?string $navigationLabel = 'Ceník a šablony';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'položka ceníku nebo šablona';

    protected static ?string $pluralModelLabel = 'Ceník a šablony';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Typ položky')->options(CatalogEntry::KINDS)
                ->default('package')->live()->required()->disabled(fn (?CatalogEntry $record) => $record !== null),
            TextInput::make('name')->label('Název')->required()->maxLength(255),
            Select::make('category_id')->label('Kategorie')->options(fn () => CatalogEntry::options('category'))
                ->searchable()->visible(fn (Get $get) => $get('kind') === 'service'),
            self::money('details.price', 'Cena')->visible(fn (Get $get) => in_array($get('kind'), ['package', 'service', 'shipping'], true)),
            ...array_map(fn ($field) => $field->visible(fn (Get $get) => $get('kind') === 'package'), [
                self::count('details.included', 'Fotografie v balíčku'),
                self::money('details.extra', 'Cena za fotografii navíc'),
                self::money('details.paid', 'Již zaplaceno'),
                self::money('details.travel', 'Doprava na focení'),
            ]),
            Toggle::make('details.address')->label('Vyžadovat doručovací adresu')
                ->default(false)->visible(fn (Get $get) => $get('kind') === 'shipping'),
            Textarea::make('details.body')->label('Text')->maxLength(4000)->required()
                ->visible(fn (Get $get) => $get('kind') === 'text')->columnSpanFull(),
        ]);
    }

    public static function money(string $path, string $label): TextInput
    {
        return TextInput::make($path)->label($label)->suffix('Kč')->integer()->minValue(0)
            ->maxValue(10000000)->default(0)->required();
    }

    public static function count(string $path, string $label): TextInput
    {
        return TextInput::make($path)->label($label)->integer()->minValue(0)->maxValue(2000)->default(0)->required();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Název')->searchable()->sortable(),
            TextColumn::make('kind')->label('Typ')->badge()->formatStateUsing(fn ($state) => CatalogEntry::KINDS[$state]),
            TextColumn::make('category.name')->label('Kategorie'),
            TextColumn::make('details.price')->label('Cena')->money('CZK', locale: 'cs'),
        ])->defaultSort('name')->filters([
            SelectFilter::make('kind')->label('Typ')->options(CatalogEntry::KINDS),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCatalog::route('/')];
    }
}
