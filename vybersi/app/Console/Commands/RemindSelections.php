<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RemindSelections extends Command
{
    protected $signature = 'galleries:remind';

    protected $description = 'Send due reminders for galleries waiting for a single selection';

    public function handle(): int
    {
        if (config('mail.default') === 'log') {
            $this->warn('Připomínky čekají na nastavení skutečného doručování pošty.');

            return self::SUCCESS;
        }

        $failed = false;
        $galleries = Gallery::query()->where('mode', 'proof')->whereNull('reminded_at')
            ->where('settings->selection_type', 'single')
            ->whereNotNull('settings->reminder_email')->whereNotNull('settings->reminder_at');

        foreach ($galleries->cursor() as $gallery) {
            try {
                Cache::lock('selection-reminder:'.$gallery->id, 300)->get(function () use ($gallery) {
                    $gallery->refresh();
                    $address = $gallery->option('reminder_email');
                    $date = $gallery->option('reminder_at');
                    if (! $address || ! $date || $gallery->reminded_at || ! $gallery->isOpen()
                        || $gallery->mode !== 'proof' || $gallery->option('selection_type') !== 'single'
                        || $gallery->selections()->exists() || now()->lt($date)) {
                        return;
                    }

                    $url = rtrim(config('app.url'), '/').route('gallery.show', $gallery->token, false);
                    Mail::raw("Dobrý den, v galerii stále čekáme na váš výběr fotografií.\n\n".$url,
                        fn ($message) => $message->to($address)->subject('Připomínka výběru fotografií'));
                    $gallery->update(['reminded_at' => now()]);
                });
            } catch (Throwable $error) {
                report($error);
                $failed = true;
                $this->error("Připomínka pro galerii #{$gallery->id} se nepodařila.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
