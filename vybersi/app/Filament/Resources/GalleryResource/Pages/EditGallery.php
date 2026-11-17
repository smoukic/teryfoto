<?php

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGallery extends EditRecord
{
    protected static string $resource = GalleryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return GalleryResource::prepareData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label('Nahrát fotografie')
                ->url(fn () => route('admin.gallery.upload', $this->record)),
            Action::make('share')->label('Sdílecí odkaz')
                ->color('gray')
                ->modalHeading('Sdílecí odkaz')
                ->modalContent(fn () => view('admin.share', [
                    'url' => config('app.url').route('gallery.show', $this->record->token, false),
                ]))
                ->modalSubmitAction(false),
            DeleteAction::make(),
        ];
    }
}
