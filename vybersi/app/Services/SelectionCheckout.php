<?php

namespace App\Services;

use App\Models\Gallery;
use Illuminate\Validation\ValidationException;

class SelectionCheckout
{
    public function __construct(private GalleryPricing $pricing) {}

    public function build(Gallery $gallery, array $data): array
    {
        $terms = $gallery->option('terms');
        if (filled($terms) && empty($data['terms_accepted'])) {
            $this->fail('terms_accepted', 'Potvrďte obchodní podmínky.');
        }

        $options = $this->pricing->options($gallery);
        $checkout = [
            'publication_consent' => $gallery->option('ask_consent') ? (bool) ($data['publication_consent'] ?? false) : null,
            'terms' => filled($terms) ? $terms : null,
            'thanks' => $gallery->option('thanks'),
        ];

        if (! $options) {
            if (filled($data['revision'] ?? null)) {
                $this->fail('revision', 'Nastavení ceny se změnilo. Obnovte galerii a zkontrolujte výběr.');
            }

            return $checkout;
        }
        if ($options['revision'] !== ($data['revision'] ?? null)) {
            $this->fail('revision', 'Nabídka se změnila. Obnovte galerii a zkontrolujte cenu.');
        }

        $services = collect($options['services'])->whereIn('id', $data['services'] ?? [])->values()->all();
        if (count($services) !== count($data['services'] ?? [])) {
            $this->fail('services', 'Některá služba už není k dispozici.');
        }

        $shipping = collect($options['shipping'])->firstWhere('id', $data['shipping'] ?? null);
        if ($options['shipping'] && ! $shipping) {
            $this->fail('shipping', 'Vyberte nabízený způsob doručení.');
        }

        $address = $shipping && $shipping['address'] ? ($data['address'] ?? null) : null;
        if ($shipping && $shipping['address'] && blank($address)) {
            $this->fail('address', 'Vyplňte doručovací adresu.');
        }

        $units = array_sum(array_map(fn ($item) => $options['quantities'] ? (int) ($item['quantity'] ?? 1) : 1, $data['items']));
        $totals = $this->pricing->totals($options, $units, $services, $shipping);

        return [...$checkout, 'totals' => $totals, 'address' => $address,
            'payment' => $totals['due'] > 0 ? $gallery->option('payment') : null];
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
