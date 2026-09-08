<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Config\ConfigError;
use Scout\Config\Reader;
use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * `config/car/criteria.json`, read with the rent side's strict `Reader`: every unknown key is a
 * hard error, `_`-prefixed keys are comments, and a gitignored `criteria.local.json` beside it
 * overrides field by field. The weights must sum to 100, so a score is a percentage and a weight
 * typo cannot silently rescale every push.
 */
final class VehicleCriteriaLoader
{
    public static function load(string $path, ?string $localPath = null): VehicleCriteria
    {
        $data = self::decodeObject($path);
        if ($localPath !== null && is_file($localPath)) {
            $data = self::deepMerge($data, self::decodeObject($localPath));
        }

        return self::fromArray($data, basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ConfigError
     */
    public static function fromArray(array $data, string $pointer = 'car/criteria.json'): VehicleCriteria
    {
        $r = new Reader($pointer, $data);

        $maxPrice = $r->requireInt('max_price_eur', 1);
        $prefixes = $r->requireStringList('postcode_prefixes', allowEmptyList: true);
        foreach ($prefixes as $p) {
            if (preg_match('~^\d{2,5}$~', $p) !== 1) {
                throw ConfigError::at($pointer . '.postcode_prefixes', 'préfixe invalide ' . var_export($p, true) . ' — 2 à 5 chiffres');
            }
        }

        // REFUSED BY NAME, not left to the generic unknown-key message. `body_rank` was the key
        // until Track 7 and it meant something this scorer no longer does — a POSITION, paid out
        // proportionally. Saying so is worth a branch: a stale `criteria.local.json` otherwise gets
        // *"clé inconnue"* and no hint that the list it holds is still exactly right.
        if ($r->has('body_rank')) {
            throw ConfigError::at($pointer . '.body_rank', 'clé renommée en `body_favour` (Track 7, 2026-09-08) : les carrosseries sont désormais à égalité, la position ne veut plus rien dire. Renommez la clé, la liste elle-même est inchangée.');
        }

        $bodyFavour = [];
        foreach ($r->requireStringList('body_favour', allowEmptyList: true) as $body) {
            try {
                $key = Text::fold($body);
            } catch (MalformedText $e) {
                throw ConfigError::at($pointer . '.body_favour', 'carrosserie illisible : ' . $e->getMessage());
            }
            if ($key === '' || in_array($key, $bodyFavour, true)) {
                throw ConfigError::at($pointer . '.body_favour', 'carrosserie vide ou en double : ' . var_export($body, true));
            }
            $bodyFavour[] = $key;
        }

        $peakAge = $r->requireInt('peak_age_years', 1, 30);
        $peakKm = $r->requireInt('peak_mileage_km', 1);

        $w = $r->requireObject('weights');
        $weights = [];
        foreach (VehicleScorer::COMPONENTS as $component) {
            $weights[$component] = $w->requireInt($component, 0, 100);
        }
        $w->done();
        if (array_sum($weights) !== 100) {
            throw ConfigError::at($pointer . '.weights', 'la somme des poids doit faire 100, reçu ' . array_sum($weights));
        }

        // FOLDED AT LOAD, so a config may write `Peugeot` and a payload may say `PEUGEOT`. Folding
        // here rather than at every comparison keeps one implementation of the rule.
        $brandAvoid = [];
        foreach ($r->has('brand_avoid') ? $r->requireStringList('brand_avoid', allowEmptyList: true) : [] as $brand) {
            $folded = Text::fold($brand);

            if ($folded === '') {
                throw ConfigError::at($pointer . '.brand_avoid', 'une marque vide n\'est pas une marque');
            }

            $brandAvoid[] = $folded;
        }

        // The FAVOURED half of Track 7's three-way, folded by the same rule for the same reason.
        $brandFavour = [];
        foreach ($r->has('brand_favour') ? $r->requireStringList('brand_favour', allowEmptyList: true) : [] as $brand) {
            $folded = Text::fold($brand);

            if ($folded === '') {
                throw ConfigError::at($pointer . '.brand_favour', 'une marque vide n\'est pas une marque');
            }

            $brandFavour[] = $folded;
        }

        // A MAKE ON BOTH LISTS IS A CONTRADICTION, AND SILENCE ABOUT IT WOULD BE THE WORST ANSWER.
        // `VehicleScorer` checks avoid before favour, so a clash would resolve quietly to *avoided*
        // and the favoured entry would sit in the config doing nothing — a configured preference
        // inert while every score stays plausible, which is precisely the defect class the stem
        // matcher was repaired for. Refusing at load makes it a startup error instead.
        //
        // RESIDUAL, stated rather than left to be found: this compares whole stems. Two stems where
        // one is a strict PREFIX of the other (`ds` avoided beside a hypothetical `dsx` favoured)
        // are not detected, and the scorer's avoid-first order is what decides those.
        $both = array_values(array_intersect($brandAvoid, $brandFavour));
        if ($both !== []) {
            throw ConfigError::at($pointer . '.brand_favour', 'marque(s) à la fois recherchée(s) et à éviter : ' . implode(', ', $both));
        }

        $patterns = $r->requireStringList('exclude_patterns', allowEmptyList: true);
        foreach ($patterns as $pattern) {
            if (@preg_match('~' . $pattern . '~u', '') === false) {
                throw ConfigError::at($pointer . '.exclude_patterns', 'expression invalide : ' . $pattern);
            }
        }

        $n = $r->requireObject('notify');
        $notify = new VehicleNotifyPolicy(
            channels: $n->requireStringList('channels'),
            highPriorityScore: $n->requireInt('high_priority_score', 0, 100),
            priceDropMinEur: $n->requireInt('price_drop_min_eur', 0),
            priceDropMinPct: $n->requireFloat('price_drop_min_pct', 0.0, 100.0),
            sourceAlertCooldownHours: $n->optInt('source_alert_cooldown_hours', 12, 1, 720) ?? 12,
            pushMinScore: $n->optInt('push_min_score', null, 0, 100),
            rollupHour: $n->optInt('rollup_hour', null, 0, 23),
        );
        $n->done();
        $r->done();

        return new VehicleCriteria($maxPrice, $prefixes, $bodyFavour, $peakAge, $peakKm, $weights, $patterns, $notify, $brandAvoid, $brandFavour);
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ConfigError::at(basename($path), 'fichier illisible : ' . $path);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigError::at(basename($path), 'JSON invalide : ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw ConfigError::at(basename($path), 'un objet JSON est attendu à la racine');
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $base[$k] = is_array($v) && is_array($base[$k] ?? null) && !array_is_list($v) ? self::deepMerge($base[$k], $v) : $v;
        }

        return $base;
    }
}
