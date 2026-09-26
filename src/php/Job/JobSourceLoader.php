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
    private const array TYPES = ['email_alert', 'email_digest'];
    private const array FAMILIES = ['portal'];
    private const array PATTERN_PARAMS = ['card_link_pattern', 'place_pattern', 'pay_pattern', 'subject_pattern', 'card_pattern', 'id_pattern', 'id_token_pattern', 'subject_company_pattern', 'salary_pattern', 'tjm_pattern'];

    /**
     * Every `params` key an adapter READS, per type — the allow-list. Read from the code
     * (`grep -n "param('" src/php/Job/`), never from what the shipped config uses; the two move in
     * the same change. `JobEmailPatternMissTest` reads this by reflection to prove each one is counted.
     */
    private const array READ_PARAMS = [
        'email_alert' => ['from', 'card_link_pattern', 'footer_marker', 'place_pattern', 'pay_pattern'],
        'email_digest' => ['from', 'subject_pattern', 'subject_company_pattern', 'card_pattern', 'id_pattern', 'id_token_pattern', 'salary_pattern', 'tjm_pattern', 'place_absent'],
    ];

    /** Required on an ENABLED source, per type: without one of these the source yields nothing, or nothing placed. */
    private const array REQUIRED_WHEN_ENABLED = [
        'email_alert' => ['from', 'card_link_pattern', 'place_pattern'],
        'email_digest' => ['from', 'card_pattern', 'id_pattern'],
    ];

    /**
     * The named groups `JobDigestEmailSource` requires in `card_pattern`, plus ONE of
     * {@see CARD_PLACE_GROUPS}: Free-Work states its place as the last `facts` segment, HelloWork and
     * Apec on a line of its own. `contracts`, `company`, `pay` and `description` are optional. A card that states no
     * place at all (Collective.work) must DECLARE it with `place_absent: "true"`, so a place group
     * forgotten in a pattern is still refused rather than read as a portal that names none.
     */
    private const array CARD_GROUPS = ['title', 'url'];

    private const array CARD_PLACE_GROUPS = ['facts', 'place'];

    private static function namesGroup(string $pattern, string $group): bool
    {
        return str_contains($pattern, '(?<' . $group . '>') || str_contains($pattern, '(?P<' . $group . '>');
    }

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

            // Same reason as the place groups: a card pattern matching without these fills nothing and
            // counts every card as a hit.
            $card = $params['card_pattern'] ?? '';
            $placeAbsent = $params['place_absent'] ?? null;
            if ($placeAbsent !== null && $placeAbsent !== 'true') {
                throw ConfigError::at($where . '.params.place_absent', 'seule la valeur "true" est admise — omettez la clé sinon');
            }
            if ($card !== '') {
                foreach (self::CARD_GROUPS as $group) {
                    if (!self::namesGroup($card, $group)) {
                        throw ConfigError::at($where . '.params.card_pattern', 'le motif doit nommer le groupe « ' . $group . ' »');
                    }
                }
                $statesPlace = self::namesGroup($card, 'facts') || self::namesGroup($card, 'place');
                if (!$statesPlace && $placeAbsent !== 'true') {
                    throw ConfigError::at($where . '.params.card_pattern', 'le motif doit nommer le groupe « ' . implode(' » ou « ', self::CARD_PLACE_GROUPS) . ' » : sans lui aucune offre n\'a de lieu. Si le courrier n\'en indique aucun, déclarez-le avec place_absent: "true"');
                }
                // A declaration the pattern beside it contradicts reads as considered, and is not.
                if ($statesPlace && $placeAbsent !== null) {
                    throw ConfigError::at($where . '.params.place_absent', 'le motif de carte nomme un lieu : place_absent le contredirait');
                }
            }

            // Two providers of the company, one honoured and the other inert, is refused, not resolved.
            $subjectCompany = $params['subject_company_pattern'] ?? '';
            if ($subjectCompany !== '') {
                if (!self::namesGroup($subjectCompany, 'company')) {
                    throw ConfigError::at($where . '.params.subject_company_pattern', 'le motif doit nommer le groupe « company »');
                }
                if ($card !== '' && self::namesGroup($card, 'company')) {
                    throw ConfigError::at($where . '.params.subject_company_pattern', 'le motif de carte nomme déjà « company » : deux sources pour un même champ, dont une sans effet');
                }
            }

            // The group the adapter decodes. Without it the rule matches, decodes nothing, and every
            // card would still count as a hit.
            $token = $params['id_token_pattern'] ?? '';
            if ($token !== '' && !self::namesGroup($token, 'token')) {
                throw ConfigError::at($where . '.params.id_token_pattern', 'le motif doit nommer le groupe « token » — la partie base64url décodée pour y lire l\'identifiant');
            }

            if ($enabled) {
                foreach (self::REQUIRED_WHEN_ENABLED[$type] as $key) {
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
