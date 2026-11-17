<?php

namespace App\Filament\Resources\GalleryResource\RelationManagers;

use App\Models\Selection;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class Selections extends RelationManager
{
    protected static string $relationship = 'selections';

    protected static ?string $title = 'Odeslané výběry';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Od koho')->searchable(),
            TextColumn::make('count')->label('Fotografie')->getStateUsing(fn (Selection $record) => count($record->items)),
            TextColumn::make('created_at')->label('Odesláno')->dateTime('j. n. Y H:i')->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('detail')->label('Zobrazit výběr')
                ->modalHeading(fn (Selection $record) => $record->name)
                ->modalContent(fn (Selection $record) => view('admin.selection', ['items' => $record->items]))
                ->modalSubmitAction(false),
            Action::make('export')->label('Export TXT')->action(fn (Selection $record) => response()->streamDownload(
                fn () => print(implode("\r\n", array_column($record->items, 'filename'))."\r\n"),
                "vyber-{$record->id}.txt",
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            )),
        ]);
    }
}
