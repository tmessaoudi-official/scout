<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * The car's snapshot as stored beside its verdict — the evidence a verdict was formed from.
 *
 * Covers EVERY constructor parameter of {@see VehicleListing}, and a reflection test says so: a
 * property that does not round-trip is a property a re-judge silently loses. A malformed snapshot
 * is refused loudly rather than degraded to a bare listing.
 */
final class VehicleSnapshot
{
    public static function encode(VehicleListing $listing): string
    {
        return json_encode([
            'sourceName' => $listing->sourceName,
            'externalId' => $listing->externalId,
            'title' => $listing->title,
            'description' => $listing->description,
            'fields' => (object) $listing->fields,
            'url' => $listing->url,
            'make' => $listing->make,
            'model' => $listing->model,
            'priceEur' => $listing->priceEur,
            'year' => $listing->year,
            'month' => $listing->month,
            'mileageKm' => $listing->mileageKm,
            'fuel' => $listing->fuel,
            'gearbox' => $listing->gearbox,
            'body' => $listing->body,
            'sellerType' => $listing->sellerType,
            'postcode' => $listing->postcode,
            'observedAt' => $listing->observedAt,
            'saleOpensAt' => $listing->saleOpensAt,
            'closingAt' => $listing->closingAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @throws \InvalidArgumentException on anything that is not a snapshot this class wrote */
    public static function decode(string $json): VehicleListing
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('instantané véhicule illisible : ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !isset($data['sourceName'], $data['externalId']) || !is_array($data['fields'] ?? null)) {
            throw new \InvalidArgumentException('instantané véhicule sans la forme attendue');
        }

        $str = static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null;
        $int = static fn (mixed $v): ?int => is_int($v) || (is_float($v) && floor($v) === $v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;

        return new VehicleListing(
            sourceName: (string) $data['sourceName'],
            externalId: (string) $data['externalId'],
            title: (string) ($data['title'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            fields: $data['fields'],
            url: $str($data['url'] ?? null),
            make: $str($data['make'] ?? null),
            model: $str($data['model'] ?? null),
            priceEur: $int($data['priceEur'] ?? null),
            year: $int($data['year'] ?? null),
            month: $int($data['month'] ?? null),
            mileageKm: $int($data['mileageKm'] ?? null),
            fuel: $str($data['fuel'] ?? null),
            gearbox: $str($data['gearbox'] ?? null),
            body: $str($data['body'] ?? null),
            sellerType: $str($data['sellerType'] ?? null),
            postcode: $str($data['postcode'] ?? null),
            observedAt: $str($data['observedAt'] ?? null),
            saleOpensAt: $str($data['saleOpensAt'] ?? null),
            closingAt: $str($data['closingAt'] ?? null),
        );
    }
}
