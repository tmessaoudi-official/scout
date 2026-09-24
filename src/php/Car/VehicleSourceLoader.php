<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * `config/car/sources.json`, strictly. Every configured pattern is compile-checked at load —
 * a pattern that cannot compile would match nothing and read as a quiet portal (hard rule 2 with
 * an alibi) — and every key an adapter could never act on is refused rather than ignored.
 */
final class VehicleSourceLoader
{
    private const array TYPES = ['email_alert', 'sitemap_jsonld', 'alcopa', 'fixture'];
    private const array FAMILIES = ['portal', 'dealer', 'auction'];
    private const array PATTERN_PARAMS = ['subject_pattern', 'card_separator_pattern', 'price_pattern', 'facts_pattern', 'title_pattern', 'make_model_pattern', 'make_model_unknown_pattern', 'seller_pattern', 'postcode_pattern'];

    /**
     * The named groups `facts_pattern` may carry beside `body`/`fuel`/`year`/`km` (rows 37+38,
     * 2026-09-05). A group here REPLACES the param that would otherwise provide the same fact,
     * and the loader refuses both together: two providers for one fact is a guess about which one
     * the adapter honours, and the ignored one fails silently.
     */
    private const array FACTS_GROUP_REPLACES = [
        'make' => ['make_model_source', 'make_model_pattern', 'title_pattern'],
        'title' => ['title_pattern'],
        'price' => ['price_pattern'],
    ];

    /**
     * Of those, the ones NO vehicle adapter reads — measured, not assumed
     * (`grep -rn "'<key>'" src/php/Car/` finds this file and nothing else).
     *
     * Kept as its own list rather than removed from `PATTERN_PARAMS`, so the day an adapter learns
     * to read one the regex check is already there and only this line moves.
     */
    private const array UNREAD_PARAMS = ['seller_pattern', 'postcode_pattern'];

    /**
     * Every `params` key a vehicle adapter actually reads, PER TYPE — the allow-list, and the rent
     * side's `ConfigLoader::EMAIL_ALERT_PARAMS` mechanism ported over (Track 6-A2).
     *
     * `UNREAD_PARAMS` refused two keys by name and this class's own docblock claimed that closed
     * the inert-parameter hole. It did not: a `link_hosts`, a `commune_pattern` carried across from
     * a rent block, or any plain typo loaded cleanly, compiled nothing, and did nothing at all. A
     * misspelt name is the commonest way to reach this failure and the only one no by-name list
     * can see.
     *
     * Read from the CODE, not from what the shipped config uses:
     * `grep -rnoE '[-]>param\(' src/php/Car/ src/php/Cli/` finds `VehicleEmailSource` (from,
     * subject_pattern, card_separator, card_separator_pattern, link_after, id_from, price_pattern,
     * title_pattern, facts_pattern, make_model_pattern, make_model_source,
     * make_model_unknown_pattern, link_host) and `CarScout` (from). A param an adapter
     * reads but this list omits is a refusal on a CORRECT config — the opposite failure, loud, and
     * the safe direction. The two move in the same change.
     *
     * `sitemap_jsonld` reads NONE: `SitemapVehicleSource` contains no `param(` call and autohero
     * configures none, so a param on one is inert by construction. `fixture` likewise.
     *
     * The two `UNREAD_PARAMS` keys are deliberately ABSENT from this list: they keep their own,
     * more specific refusal above, and an empty-string declaration of one — which that guard lets
     * through — is refused here instead of being quietly accepted.
     */
    private const array READ_PARAMS = [
        'email_alert' => [
            'from',
            'link_host',
            'subject_pattern',
            'card_separator',
            'card_separator_pattern',
            'link_after',
            'id_from',
            'price_pattern',
            'title_pattern',
            'facts_pattern',
            'make_model_pattern',
            'make_model_source',
            'make_model_unknown_pattern',
        ],
        'sitemap_jsonld' => [],
        // `alcopa` is a site-specific adapter: every selector is code, so no param is read.
        'alcopa' => [],
        'fixture' => [],
    ];

    /** @return array<string, VehicleSourceDefinition> */
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
     * @param array<string,mixed> $data
     *
     * @return array<string, VehicleSourceDefinition>
     */
    public static function fromArray(array $data, string $pointer = 'car/sources.json'): array
    {
        $root = new Reader($pointer, $data);
        $sources = $root->requireObject('sources');
        $out = [];

        foreach ($sources->keys() as $name) {
            if (str_starts_with($name, '_')) {
                continue;
            }
            $where = $pointer . '.sources.' . $name;
            $r = $sources->requireObject($name);

            $enabled = $r->requireBool('enabled');
            $family = $r->requireString('family', self::FAMILIES);
            $type = $r->requireString('type', self::TYPES);
            $feedSilentDays = $r->optInt('feed_silent_days', null);
            if ($feedSilentDays !== null) {
                if ($type !== 'email_alert') {
                    throw ConfigError::at($where . '.feed_silent_days', 'seule une source email_alert rapporte une date de flux');
                }
                if ($feedSilentDays < 1) {
                    throw ConfigError::at($where . '.feed_silent_days', 'doit valoir au moins 1 jour');
                }
            }
            $url = $r->optString('url', null);
            $itemUrlPattern = $r->optString('item_url_pattern', null);
            $lotBudget = $r->optInt('lot_budget_per_pass', 50, 1, 5000) ?? 50;
            $rateLimit = $r->optInt('rate_limit_ms', 2000, 0, 600000) ?? 2000;
            $fixture = $r->optString('fixture', null);

            $params = [];
            $p = $r->optObject('params');
            if ($p !== null) {
                foreach ($p->keys() as $key) {
                    $params[$key] = $p->requireString($key, allowEmpty: true);
                }
                $p->done();
            }
            foreach (self::PATTERN_PARAMS as $key) {
                if (isset($params[$key]) && $params[$key] !== '' && @preg_match($params[$key], '') === false) {
                    throw ConfigError::at($where . '.params.' . $key, 'expression régulière invalide — elle ne correspondrait jamais à rien sans le dire');
                }
            }

            // DECLARED, COMPILE-CHECKED, AND READ BY NOBODY — refused rather than accepted (Track 1c).
            //
            // These are in `PATTERN_PARAMS`, so a config carrying one loads cleanly, passes its
            // regex check, and then does absolutely nothing: `grep -rn "'seller_pattern'"
            // src/php/Car/` finds this file and no adapter. That is the inert-parameter defect the
            // rent side already paid for — `title_pattern` sat unread on every non-segmented email
            // source until 2026-08-26, which made `exclude_title_patterns` unreachable there.
            //
            // `title_pattern` LEFT this list on 2026-08-31, when `VehicleEmailSource` learned to
            // read it against the subject for leboncoin — which is the discharge this comment asks
            // for, performed rather than deferred.
            //
            // Refusing costs nothing today (neither shipped source configures one) and it is the
            // only way the next person to reach for one finds out. When an adapter learns to read
            // one, delete it from this list in the same change.
            foreach (self::UNREAD_PARAMS as $key) {
                if (isset($params[$key]) && $params[$key] !== '') {
                    throw ConfigError::at(
                        $where . '.params.' . $key,
                        $key . ' est déclaré mais AUCUN adaptateur véhicule ne le lit : le configurer '
                            . 'ne ferait rien du tout, en silence. Faites-le lire par un adaptateur '
                            . 'et retirez-le de VehicleSourceLoader::UNREAD_PARAMS dans le même changement',
                    );
                }
            }

            // THE ALLOW-LIST (Track 6-A2). Everything above refuses a key BY NAME; this refuses
            // every name nobody reads, which is the only guard a typo cannot walk past.
            //
            // Deliberately OUTSIDE the `enabled` branch — `--source=<name>` force-runs a disabled
            // source, which is the documented onboarding path, so a guard firing only on enabled
            // sources is one the intended workflow never meets.
            $read = self::READ_PARAMS[$type];
            foreach (array_keys($params) as $key) {
                if (\in_array($key, $read, true)) {
                    continue;
                }

                throw ConfigError::at(
                    $where . '.params.' . $key,
                    'aucun adaptateur véhicule ne lit ce paramètre sur une source ' . $type
                        . '. Un nom mal orthographié se charge sans bruit et ne fait rien — le motif '
                        . 'est absent, rien ne le remplace, et rien ne ressemble à une panne. '
                        . 'Paramètres lus pour ce type : ' . ($read === [] ? 'aucun' : implode(', ', $read)),
                );
            }

            $map = [];
            $m = $r->optObject('map');
            if ($m !== null) {
                foreach ($m->keys() as $key) {
                    $map[$key] = $m->requireString($key);
                }
                $m->done();
            }

            // A VALUE, not a pattern, so `PATTERN_PARAMS` cannot check it — and a typo here is
            // silent in the worst way: `make_model_source: "titre"` would fall to the `link`
            // branch, match nothing, and leave every listing with a null make, which scores 0 on
            // the brand component rather than reading as a fault.
            if (isset($params['make_model_source']) && !in_array($params['make_model_source'], ['link', 'title'], true)) {
                throw ConfigError::at(
                    $where . '.params.make_model_source',
                    'attendu « link » ou « title », reçu « ' . $params['make_model_source'] . ' »',
                );
            }
            if (isset($params['make_model_source']) && !isset($params['make_model_pattern'])) {
                throw ConfigError::at(
                    $where . '.params.make_model_source',
                    'nomme la source d\'un motif qui n\'existe pas — ajoutez make_model_pattern ou retirez cette clé',
                );
            }

            // THE PORTAL'S OWN "I DON'T KNOW" TOKEN (Track 6-A4).
            //
            // A capture that succeeds is not the same as a capture that means something: ParuVendu
            // writes `/voiture-occasion/autres/autres/` when it cannot name the marque, and that
            // token was stored as the make, matched no `brand_avoid` stem, and earned the whole
            // brand share — on a **DS**, which is on the avoid list. It is not the unknown-make arm
            // (the value is non-null and looks real); it is a wrong answer wearing one.
            //
            // Declared PER SOURCE because it is a property of one portal's URL scheme, not of the
            // domain. TWO refusals, and neither is decoration:
            //
            //   - EMPTY is refused. `PATTERN_PARAMS` above skips an empty value, so an empty
            //     declaration would compile-check clean and null nothing at all — a disabled
            //     feature dressed as a configured one (the `detail_budget_per_pass: 0` precedent).
            //     An OMITTED key is fine and means "this portal has no sentinel", which is every
            //     other source.
            //   - It needs `make_model_pattern`, for the same reason `make_model_source` does:
            //     a sentinel for captures nobody makes can never fire, and reads as configured.
            if (isset($params['make_model_unknown_pattern'])) {
                if ($params['make_model_unknown_pattern'] === '') {
                    throw ConfigError::at(
                        $where . '.params.make_model_unknown_pattern',
                        'un motif vide ne reconnaîtrait jamais aucun jeton — retirez la clé ou donnez-lui une valeur',
                    );
                }
                // A facts `make` group (row 38) is a make provider too — the sentinel applies to
                // whichever haystack the make came from.
                $factsNamesMake = str_contains((string) ($params['facts_pattern'] ?? ''), '(?<make>')
                    || str_contains((string) ($params['facts_pattern'] ?? ''), '(?P<make>');
                if (!isset($params['make_model_pattern']) && !$factsNamesMake) {
                    throw ConfigError::at(
                        $where . '.params.make_model_unknown_pattern',
                        'décrit les jetons d\'un motif qui n\'existe pas — ajoutez make_model_pattern ou retirez cette clé',
                    );
                }
            }

            // ROWS 37 + 38 (2026-09-05): identity scheme, regex segmentation, and the labelled-card
            // groups — each refusal is a shape whose failure is silent.
            //
            // `id_from` is a VALUE, like `make_model_source`: a typo would fall to link identity,
            // and on a portal whose links are per-recipient tokens that is a fresh identity per
            // message — the whole backlog re-notified, reading as a busy market.
            if (isset($params['id_from']) && !in_array($params['id_from'], ['link', 'content'], true)) {
                throw ConfigError::at(
                    $where . '.params.id_from',
                    'attendu « link » ou « content », reçu « ' . $params['id_from'] . ' »',
                );
            }
            // Both separators is a guess about which one the adapter honours — the rent loader's
            // own rule, and the same words.
            if (($params['card_separator'] ?? '') !== '' && ($params['card_separator_pattern'] ?? '') !== '') {
                throw ConfigError::at(
                    $where . '.params.card_separator_pattern',
                    'card_separator et card_separator_pattern sont deux mécanismes pour la même chose : '
                        . 'n\'en configurez qu\'un, sinon celui qui est ignoré échoue en silence',
                );
            }
            // A `facts_pattern` group and the param that provides the same fact: two providers,
            // one honoured, the other inert. Refused by name so the operator is sent to the line
            // that is actually there.
            $facts = (string) ($params['facts_pattern'] ?? '');
            foreach (self::FACTS_GROUP_REPLACES as $group => $replaced) {
                if ($facts === '' || !str_contains($facts, '(?<' . $group . '>') && !str_contains($facts, "(?P<" . $group . '>')) {
                    continue;
                }
                foreach ($replaced as $key) {
                    if (($params[$key] ?? '') !== '') {
                        throw ConfigError::at(
                            $where . '.params.' . $key,
                            $key . ' et le groupe nommé « ' . $group . ' » de facts_pattern fournissent le même fait : '
                                . 'gardez l\'un des deux, sinon celui qui est ignoré échoue en silence',
                        );
                    }
                }
            }

            if ($type === 'email_alert') {
                if ($enabled && ($params['from'] ?? '') === '') {
                    throw ConfigError::at($where . '.params.from', 'une source email_alert activée doit nommer son expéditeur — la boîte sert plusieurs portails');
                }
                if ($enabled && ($params['link_host'] ?? '') === '') {
                    throw ConfigError::at($where . '.params.link_host', 'une source email_alert activée doit nommer l\'hôte de ses liens d\'annonce');
                }
            }
            if ($type === 'sitemap_jsonld') {
                if ($url === null) {
                    throw ConfigError::at($where . '.url', 'une source sitemap_jsonld nomme son sitemap');
                }
                if ($itemUrlPattern === null || @preg_match($itemUrlPattern, '') === false) {
                    throw ConfigError::at($where . '.item_url_pattern', 'une source sitemap_jsonld nomme le motif de ses pages de lot, avec un groupe capturant l\'identifiant');
                }
                foreach (['ref', 'price'] as $required) {
                    if (($map[$required] ?? '') === '') {
                        throw ConfigError::at($where . '.map.' . $required, 'obligatoire pour une source sitemap_jsonld');
                    }
                }
            }
            if ($type === 'alcopa') {
                // The saved search IS the index, and a lot page is only ever reached from it, so the
                // URL must be that site's search — a different host would put robots, pacing and
                // every selector on a site nobody measured.
                if ($url === null || preg_match('~^https://www\.alcopa-auction\.fr/recherche\?~', $url) !== 1) {
                    throw ConfigError::at($where . '.url', 'une source alcopa nomme sa recherche enregistrée, https://www.alcopa-auction.fr/recherche?…');
                }
                if ($itemUrlPattern !== null || $map !== []) {
                    throw ConfigError::at($where, 'une source alcopa ne lit ni item_url_pattern ni map : ses sélecteurs sont du code, et ces clés ne feraient rien');
                }
            }
            if ($type === 'fixture' && $fixture === null) {
                throw ConfigError::at($where . '.fixture', 'une source fixture nomme son fichier');
            }
            $r->done();

            $out[$name] = new VehicleSourceDefinition(
                name: $name, enabled: $enabled, family: $family, type: $type, params: $params, url: $url,
                itemUrlPattern: $itemUrlPattern, map: $map, lotBudgetPerPass: $lotBudget, rateLimitMs: $rateLimit,
                feedSilentDays: $feedSilentDays, fixture: $fixture,
            );
        }
        $sources->done();
        $root->done();

        return $out;
    }
}
