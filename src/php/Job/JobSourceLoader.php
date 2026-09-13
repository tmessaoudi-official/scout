<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * `config/job/sources.json`, strictly — the car loader's refusals, applied from source #1.
 *
 * Every refusal is a shape whose failure is silent: a misspelt param loads and does nothing, a
 * pattern that cannot compile matches nothing (`@preg_match` neither warns nor throws), and an
 * enabled source with no card link pattern yields no offer while its health reads a quiet market.
 */
final class JobSourceLoader
{
    private const array TYPES = ['email_alert'];
    private const array FAMILIES = ['portal'];
    private const array PATTERN_PARAMS = ['card_link_pattern', 'place_pattern', 'pay_pattern'];

    /**
     * Every `params` key an adapter READS, per type — the allow-list. Read from the code
     * (`grep -n "param('" src/php/Job/`), never from what the shipped config uses; the two move in
     * the same change. `JobEmailPatternMissTest` reads this by reflection to prove each one is counted.
     */
    private const array READ_PARAMS = [
        'email_alert' => ['from', 'card_link_pattern', 'footer_marker', 'place_pattern', 'pay_pattern'],
    ];

    /** Required on an ENABLED source: without one of these the source yields nothing, or nothing placed. */
    private const array REQUIRED_WHEN_ENABLED = ['from', 'card_link_pattern', 'place_pattern'];

    /** @return array<string, JobSourceDefinition> */
    public static function load(string $path): array
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

        return self::fromArray($data, basename($path));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, JobSourceDefinition>
     */
    public static function fromArray(array $data, string $pointer = 'job/sources.json'): array
    {
        $root = new Reader($pointer, $data);
        $sources = $root->requireObject('sources');
        $out = [];

        foreach ($sources->keys() as $name) {
            $where = $pointer . '.sources.' . $name;
            $r = $sources->requireObject($name);

            $enabled = $r->requireBool('enabled');
            $family = $r->requireString('family', self::FAMILIES);
            $type = $r->requireString('type', self::TYPES);
            $feedSilentDays = $r->optInt('feed_silent_days', null);
            if ($feedSilentDays !== null && $feedSilentDays < 1) {
                throw ConfigError::at($where . '.feed_silent_days', 'doit valoir au moins 1 jour');
            }

            $params = [];
            $p = $r->optObject('params');
            if ($p !== null) {
                foreach ($p->keys() as $key) {
                    $params[$key] = $p->requireString($key, allowEmpty: true);
                }
                $p->done();
            }

            // THE ALLOW-LIST, outside the `enabled` branch: `--source=<name>` force-runs a disabled
            // source, which is the onboarding path, so a guard on enabled sources only never meets it.
            $read = self::READ_PARAMS[$type];
            foreach (array_keys($params) as $key) {
                if (!in_array($key, $read, true)) {
                    throw ConfigError::at(
                        $where . '.params.' . $key,
                        'aucun adaptateur offres ne lit ce paramètre sur une source ' . $type
                            . ' : un nom mal orthographié se charge sans bruit et ne fait rien. Paramètres lus : ' . implode(', ', $read),
                    );
                }
            }

            foreach (self::PATTERN_PARAMS as $key) {
                if (($params[$key] ?? '') !== '' && @preg_match($params[$key], '') === false) {
                    throw ConfigError::at($where . '.params.' . $key, 'expression régulière invalide — elle ne correspondrait jamais à rien sans le dire');
                }
            }

            // Both groups are what the adapter reads. A pattern that matches without them fills
            // neither field and counts every card as a hit.
            $place = $params['place_pattern'] ?? '';
            if ($place !== '') {
                foreach (['company', 'location'] as $group) {
                    if (!str_contains($place, '(?<' . $group . '>') && !str_contains($place, '(?P<' . $group . '>')) {
                        throw ConfigError::at($where . '.params.place_pattern', 'le motif doit nommer le groupe « ' . $group . ' »');
                    }
                }
            }

            if ($enabled) {
                foreach (self::REQUIRED_WHEN_ENABLED as $key) {
                    if (($params[$key] ?? '') === '') {
                        throw ConfigError::at($where . '.params.' . $key, 'obligatoire pour une source ' . $type . ' activée');
                    }
                }
            }
            $r->done();

            $out[$name] = new JobSourceDefinition(
                name: $name,
                enabled: $enabled,
                family: $family,
                type: $type,
                params: $params,
                feedSilentDays: $feedSilentDays,
            );
        }
        $sources->done();
        $root->done();

        return $out;
    }
}
