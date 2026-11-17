<?php

namespace App\Filament\Resources\GalleryResource\RelationManagers;

use App\Models\Photo;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class Photos extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Fotografie';

    public function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('preview')->label('Náhled')->getStateUsing(
                fn (Photo $record) => route('gallery.media', [$this->getOwnerRecord()->token, $record->id, 'thumb']),
            ),
            TextColumn::make('filename')->label('Název souboru')->searchable(),
        ])->recordActions([
            Action::make('preview')->label('Otevřít')->url(
                fn (Photo $record) => route('gallery.media', [$this->getOwnerRecord()->token, $record->id, 'preview']),
                shouldOpenInNewTab: true,
            ),
            DeleteAction::make(),
        ]);
    }
}
