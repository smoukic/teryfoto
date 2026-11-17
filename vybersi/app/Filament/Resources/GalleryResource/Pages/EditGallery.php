<?php

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGallery extends EditRecord
{
    protected static string $resource = GalleryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings'] ??= [];
        $data['settings']['selection_type'] ??= 'group';
        $data['settings']['status'] ??= 'open';
        $data['settings']['sort'] ??= 'upload';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = GalleryResource::prepareData($data);
        $previous = $this->record->settings ?? [];
        $oldPricing = $previous['pricing'] ?? [];
        $newPricing = $data['settings']['pricing'] ?? [];
        $data['settings'] = array_replace($previous, $data['settings'] ?? []);
        if ($oldPricing || $newPricing) {
            $data['settings']['pricing'] = array_replace($oldPricing, $newPricing);
        }
        if ($this->record->selections()->exists()) {
            $data['settings']['selection_type'] = $this->record->option('selection_type', 'group');
        }
        foreach (['reminder_email', 'reminder_at'] as $field) {
            if (data_get($data, "settings.{$field}") !== $this->record->option($field)) {
                $data['reminded_at'] = null;
            }
        }

        return $data;
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
