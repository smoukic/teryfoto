<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GalleryResource\Pages;
use App\Filament\Resources\GalleryResource\RelationManagers;
use App\Models\Gallery;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GalleryResource extends Resource
{
    protected static ?string $model = Gallery::class;

    protected static ?string $modelLabel = 'galerie';

    protected static ?string $pluralModelLabel = 'galerie';

    protected static ?string $navigationLabel = 'Galerie';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([GalleryResource\AlbumSettings::tabs()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Galerie')->searchable()->sortable(),
            TextColumn::make('mode')->label('Typ')->badge()
                ->formatStateUsing(fn ($state) => $state === 'delivery' ? 'Předání' : 'Výběr'),
            TextColumn::make('photos_count')->label('Fotografie')->counts('photos')->sortable(),
            TextColumn::make('selections_count')->label('Výběry')->counts('selections')->sortable(),
            TextColumn::make('availability')->label('Stav')->badge()->getStateUsing(fn (Gallery $record) => match (true) {
                $record->expires_at?->isPast() => 'Vypršelo',
                $record->option('status') === 'draft' => 'Koncept',
                $record->option('status') === 'closed' => 'Uzavřeno',
                $record->mode === 'delivery' => 'Předání otevřeno',
                $record->selections_count > 0 => 'Výběr doručen',
                default => 'Čeká na výběr',
            }),
            TextColumn::make('updated_at')->label('Upraveno')->dateTime('j. n. Y H:i')->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([EditAction::make()]);
    }

    public static function prepareData(array $data): array
    {
        if ($data['remove_password'] ?? false) {
            $data['password'] = null;
        } elseif (filled($data['new_password'] ?? null)) {
            $data['password'] = $data['new_password'];
        }

        unset($data['new_password'], $data['remove_password']);

        return $data;
    }

    public static function getRelations(): array
    {
        return [RelationManagers\Photos::class, RelationManagers\Selections::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGalleries::route('/'),
            'create' => Pages\CreateGallery::route('/create'),
            'edit' => Pages\EditGallery::route('/{record}/edit'),
        ];
    }
}
