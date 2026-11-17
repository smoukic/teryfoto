<?php

namespace App\Services;

use App\Models\CatalogEntry;
use App\Models\Gallery;

class GalleryPricing
{
    public function options(Gallery $gallery): ?array
    {
        if ($gallery->mode !== 'proof' || ! $gallery->option('pricing.enabled')) {
            return null;
        }

        $options = [];
        foreach (['price', 'included', 'extra', 'paid', 'travel', 'shoot', 'voucher'] as $key) {
            $options[$key] = (int) $gallery->option("pricing.{$key}", 0);
        }
        $options['quantities'] = (bool) $gallery->option('pricing.quantities');
        $options['tiers'] = collect($gallery->option('pricing.tiers', []))
            ->map(fn (array $tier) => ['from' => (int) $tier['from'], 'price' => (int) $tier['price']])
            ->sortBy('from')->values()->all();
        $options['services'] = $this->catalogOptions($gallery, 'service', 'services');
        $options['shipping'] = $this->catalogOptions($gallery, 'shipping', 'shipping');
        $options['revision'] = hash('sha256', json_encode($options, JSON_THROW_ON_ERROR));

        return $options;
    }

    private function catalogOptions(Gallery $gallery, string $kind, string $key): array
    {
        return CatalogEntry::query()->where('kind', $kind)->whereIn('id', $gallery->option("pricing.{$key}", []))
            ->with('category')->orderBy('name')->orderBy('id')->get()
            ->map(fn (CatalogEntry $entry) => [
                'id' => $entry->id,
                'name' => $entry->name,
                'price' => (int) ($entry->details['price'] ?? 0),
                'category' => $entry->category?->name,
                'address' => (bool) ($entry->details['address'] ?? false),
            ])->all();
    }

    public function totals(array $options, int $units, array $services, ?array $shipping): array
    {
        $extraUnits = max(0, $units - $options['included']);
        $unitPrice = $options['extra'];
        foreach ($options['tiers'] as $tier) {
            if ($units >= $tier['from']) {
                $unitPrice = $tier['price'];
            }
        }

        $subtotal = $options['price'] + $extraUnits * $unitPrice + $options['travel'] + $options['shoot']
            + array_sum(array_column($services, 'price')) + ($shipping['price'] ?? 0);
        $due = max(0, $subtotal - $options['paid'] - $options['voucher']);

        return [
            'units' => $units, 'extra_units' => $extraUnits, 'unit_price' => $unitPrice,
            'package_price' => $options['price'], 'travel' => $options['travel'], 'shoot' => $options['shoot'],
            'paid' => $options['paid'], 'voucher' => $options['voucher'],
            'subtotal' => $subtotal, 'due' => $due, 'services' => $services, 'shipping' => $shipping,
        ];
    }
}
