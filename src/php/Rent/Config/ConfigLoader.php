<?php

declare(strict_types=1);

namespace Scout\Rent\Config;

use Scout\Rent\Core\Tenure;
use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * Reads `config/rent/criteria.json` and `config/rent/sources.json`, and refuses everything it does not
 * recognise.
 *
 * Config is JSON rather than YAML — ruled 2026-08-07 (`docs/OPEN-QUESTIONS.md` Q22): this container
 * has no `ext-yaml` and the egress policy blocks installing a parser, so `.yaml` files would sit
 * unread. `ext-json` is always present, and phorj's `Core.Json` is a default feature, so both
 * implementations can read the same file.
 *
 * Strictness is the whole design. Every unknown key is a hard error ({@see Reader::done()}), because
 * the alternative — ignoring what it does not understand — means a misspelled `mixd_tenure` silently
 * leaves a mixed-stock landlord classified as pure, and §1's fail-closed rule disarmed by a typo.
 */
final class ConfigLoader
{
    /** Placeholder for a URL that has not been verified against the live site (hard rule 1). */
    public const string UNVERIFIED_URL = 'REMPLACER';

    /**
     * RFC 7230 token — the only characters legal in a header NAME. The same rule
     * `CurlHttpClient::HEADER_NAME_TOKEN` enforces at the funnel, applied here at load time so the
     * operator learns immediately. See that constant for why a non-token name is a smuggling shape,
     * and why the `D` modifier (no `$`-before-trailing-newline) is load-bearing.
     */
    private const string HEADER_NAME_TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /**
     * Every `params` key an `email_alert` source may carry — the union of every reader in
     * `EmailAlertSource` (`params[...]`, `param()`, `stringParam()`, `matchParam()`) and the CLI
     * construction sites, read from the CODE rather than from what the shipped config happens to
     * use. Anything else is refused; see the guard in `sourceFromReader()` for why this list exists
     * and why it applies to email sources only.
     *
     * Add a key here in the same change that teaches an adapter to read it. A param the adapter
     * reads but this list omits is a refusal on a config that is correct — the opposite failure,
     * and a loud one, which is the safe direction.
     */
    /**
     * Every `params` key whose value is a REGEX and must therefore compile at load.
     *
     * **A NAMED CONSTANT, and read by reflection in `ConfigTest`, because the inline literal this
     * replaced was untestable in the direction that matters** (C2 round 2, 2026-09-04). Six of its
     * eight entries had no test at all and no ledger case touched the line, so deleting
     * `advertiser_pattern` — the §1-relevant one, feeding `Core\LandlordRegistry` — left the whole
     * suite and the whole ledger green. The car side already carried the cure:
     * `Car\VehicleSourceLoader::PATTERN_PARAMS` plus a reflection test that fails when a key is
     * added and not covered. This is that shape, ported.
     *
     * Why each one is here rather than merely nice to have: `matchParam()` reads them with
     * `@preg_match`, which neither warns nor throws, so a pattern that does not compile matches
     * nothing SILENTLY. On `advertiser_pattern` that silence re-opens the exact hole the registry
     * closes — a card advertised by a bailleur is judged with the portal's `LIBRE` default again.
     * On `subject_pattern` the failure is not an extraction but an ADMISSION: it decides which
     * messages the source reads at all, and a dead one ingests every message from that sender,
     * including on leboncoin the VEHICLE alerts sharing its sender, link host and card separator.
     *
     * @var list<string>
     */
    private const array PATTERN_PARAMS = [
        'title_pattern',
        'residence_pattern',
        'commune_pattern',
        'surface_pattern',
        'rooms_pattern',
        'card_separator_pattern',
        'advertiser_pattern',
        'subject_pattern',
    ];

    private const array EMAIL_ALERT_PARAMS = [
        'from',
        'subject_pattern',
        'link_host',
        'id_from',
        'card_separator',
        'card_separator_pattern',
        'title_pattern',
        'residence_pattern',
        'commune_pattern',
        'surface_pattern',
        'rooms_pattern',
        'advertiser_pattern',
    ];

    /**
     * Load criteria, applying a gitignored local override if one is present.
     *
     * The override exists so genuinely personal tuning never has to enter git (Q11): the committed
     * file carries only values that were already public in `prototype/sources.yaml`. Merging is
     * **deep for objects and wholesale for arrays** — an array merge would have no sane semantics
     * (does an override's commune list add to the base or replace it?), and "replace" is the only
     * answer a reader can predict.
     *
     * @throws ConfigError
     */
    public static function loadCriteria(string $path, ?string $localPath = null): Criteria
    {
        $data = self::decodeObject($path);

        if ($localPath !== null && is_file($localPath)) {
            $data = self::deepMerge($data, self::decodeObject($localPath));
        }

        return self::criteriaFromArray($data, basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ConfigError
     */
    public static function criteriaFromArray(array $data, string $pointer = 'criteria.json'): Criteria
    {
        $r = new Reader($pointer, $data);

        $communeLabels = [];
        $communes = [];
        // An EMPTY list is legal and means region mode: the postcode prefixes alone decide. See
        // Criteria::matchesCommune(). The pairing with `postcode_prefixes` is checked below, once
        // both have been read — neither is meaningful on its own.
        foreach ($r->requireStringList('communes', allowEmptyList: true) as $label) {
            $key = Criteria::communeKey($label);
            if ($key === '') {
                // The message names BOTH causes: `communeKey()` returns `''` for a name with no
                // alphanumeric content AND for one it cannot fold, and "has no letters or digits"
                // is baffling for a label that visibly has plenty of both.
                throw ConfigError::at(
                    $pointer . '.communes',
                    'commune ' . var_export($label, true) . ' cannot be normalised — it has no '
                        . 'letters or digits, or its text is not readable (bad encoding, or undecoded HTML entities)',
                );
            }
            if (isset($communeLabels[$key])) {
                throw ConfigError::at(
                    $pointer . '.communes',
                    var_export($label, true) . ' and ' . var_export($communeLabels[$key], true)
                        . ' normalise to the same commune',
                );
            }
            $communeLabels[$key] = $label;
            $communes[] = $key;
        }

        $prefixes = [];
        foreach ($r->requireStringList('postcode_prefixes', allowEmptyList: true) as $prefix) {
            if (preg_match('~^\d{1,5}$~', $prefix) !== 1) {
                throw ConfigError::at(
                    $pointer . '.postcode_prefixes',
                    var_export($prefix, true) . ' is not a run of 1–5 digits',
                );
            }
            $prefixes[] = $prefix;
        }

        // THE ONE COMBINATION THAT FAILS OPEN. Every other shape of these two keys narrows: a name
        // list narrows, a prefix list narrows, and the two together narrow twice. Both empty is the
        // single arrangement that widens to everything, and it is reachable by an ordinary edit —
        // emptying `communes` to go departement-wide and not noticing the prefixes were never set.
        //
        // Refused rather than defaulted, because over-matching is INVISIBLE: a filter that admits
        // the whole country looks exactly like a suddenly busy rental market, and nothing in the
        // health model can tell the difference (hard rule 2's failure shape, arriving through
        // config instead of a selector).
        if ($communes === [] && $prefixes === []) {
            throw ConfigError::at(
                $pointer . '.communes',
                'empty with no `postcode_prefixes` either — in region mode the prefixes are the only '
                    . 'filter left, so this asks to be notified about every rental in France. Name at '
                    . 'least one commune, or at least one postcode prefix',
            );
        }

        $minRooms = $r->optInt('min_rooms', null, 1, 20);
        $minSurface = $r->optFloat('min_surface_m2', null, 1.0, 1000.0);
        $maxRent = $r->optInt('max_rent_cc', null, 1, 100000);
        // Bounded low: below 1 €/m² nothing in Île-de-France is a dwelling, and above 20 the
        // threshold would start eating the ordinary market rather than the implausible tail.
        $minPricePerM2 = $r->optFloat('min_price_per_m2', null, 1.0, 20.0);

        // These are property-type and listing-kind patterns (colocation, meublé, résidence senior),
        // NOT tenure. Tenure exclusion lives in `Tenure::isExcluded()` and is deliberately absent
        // from config — see the Criteria class docblock and CLAUDE.md §1.
        $patterns = self::patternList($r, 'exclude_patterns', $pointer);
        $titlePatterns = self::patternList($r, 'exclude_title_patterns', $pointer);

        // Commune preference (score component S1). Ranking never filters; `communes` above does.
        $rankReader = $r->optObject('commune_rank');
        $rank = [];
        if ($rankReader !== null) {
            foreach ($rankReader->keys() as $label) {
                $key = Criteria::communeKey($label);

                // REFUSED, symmetrically with `communes` above — and the asymmetry was live for a
                // day. `communeKey()` used to THROW on a name it could not fold, so a malformed rank
                // label failed loudly here; since it returns `''` instead (so that one unfoldable
                // listing commune cannot abort a whole pass), `commune_rank['']` became
                // constructible in REGION MODE, where the `in_array` check below is deliberately
                // skipped. `rankOf()` has no `''` guard, so EVERY listing whose commune could not be
                // folded was then awarded that rank — a review panel measured an unreadable commune
                // scoring rank 1, "commune de premier choix", with the raw bytes in `reasons[]`.
                //
                // Score-only, so §1 was never in reach; but a fix for a listing-side failure must
                // not quietly widen what the CONFIG accepts, and config is the one input that
                // should fail loudly.
                if ($key === '') {
                    throw ConfigError::at(
                        $pointer . '.commune_rank.' . $label,
                        'commune ' . var_export($label, true) . ' cannot be normalised — it has no '
                            . 'letters or digits, or its text is not readable (bad encoding, or undecoded HTML entities)',
                    );
                }

                // Skipped in REGION MODE, where there is no list for a rank to be outside of. The
                // check exists to catch dead config; applied to an empty `communes` it would reject
                // every rank instead, forcing anyone who widens to a departement to delete the half
                // of their configuration that says where they actually want to live. A rank never
                // filters, so a rank on a commune the prefixes exclude is inert, not dangerous.
                if ($communes !== [] && !in_array($key, $communes, true)) {
                    throw ConfigError::at(
                        $pointer . '.commune_rank.' . $label,
                        'ranked but not in `communes` — a rank for a commune that is filtered out is dead config',
                    );
                }
                $rank[$key] = $rankReader->requireInt($label, 1, 1000);

                // A ranked commune is also VOCABULARY, not only a preference. `communeLabels` is
                // what EmailAlertSource scans an alert body with — it is the one field an alert
                // reliably carries and the criteria engine cannot do without (Q32). Built from
                // `communes` alone, region mode emptied it, and every listing arriving by email
                // silently lost its commune: no S1 score, nothing to show in the notification, and
                // a weaker dedup key, while the listing still matched on its postcode so nothing
                // looked broken.
                //
                // In list mode this changes nothing — the check above already requires every ranked
                // commune to be in `communes`, so the key is present. In region mode it is the only
                // vocabulary there is. An alert for an UNRANKED commune still resolves its location
                // by postcode prefix; it just cannot name it. Recognising arbitrary French place
                // names would mean geocoding, which this deliberately stays out of.
                $communeLabels[$key] ??= $label;
            }
            $rankReader->done();
        }

        $weightsReader = $r->optObject('weights');
        $weights = $weightsReader === null ? new Weights() : self::weightsFrom($weightsReader);

        $notifyReader = $r->optObject('notify');
        $notify = $notifyReader === null ? new NotifyPolicy() : self::notifyPolicyFrom($notifyReader);

        $freshness = $r->optInt('freshness_minutes', 60, 1, 10080) ?? 60;

        $commuteEnabled = false;
        $commuteStation = null;
        $commuteMinutes = null;
        $commuteReader = $r->optObject('commute');
        if ($commuteReader !== null) {
            $commuteEnabled = $commuteReader->optBool('enabled', false);
            $commuteStation = $commuteReader->optString('station', null);
            $commuteMinutes = $commuteReader->optInt('max_minutes', null, 1, 600);
            $commuteReader->done();

            if ($commuteEnabled && ($commuteStation === null || $commuteMinutes === null)) {
                throw ConfigError::at(
                    $pointer . '.commute',
                    'enabled but `station` or `max_minutes` is missing — an enabled commute filter '
                        . 'with nothing to measure against would silently score every listing the same',
                );
            }
        }

        if ($commuteEnabled && $weights->commute === 0) {
            throw ConfigError::at(
                $pointer . '.weights.commute',
                'commute is enabled but weighted 0, so it can never affect a score. '
                    . 'Either give it a weight or set `commute.enabled` to false',
            );
        }
        if (!$commuteEnabled && $weights->commute !== 0) {
            throw ConfigError::at(
                $pointer . '.weights.commute',
                'weighted ' . $weights->commute . ' but `commute.enabled` is false, so every listing '
                    . 'would lose that share of the score for a component that never runs',
            );
        }

        $r->done();

        return new Criteria(
            communes: $communes,
            communeLabels: $communeLabels,
            postcodePrefixes: $prefixes,
            minRooms: $minRooms,
            minSurfaceM2: $minSurface === null ? null : (float) $minSurface,
            maxRentCc: $maxRent,
            excludePatterns: $patterns,
            excludeTitlePatterns: $titlePatterns,
            communeRank: $rank,
            weights: $weights,
            notify: $notify,
            freshnessMinutes: $freshness,
            commuteEnabled: $commuteEnabled,
            commuteStation: $commuteStation,
            commuteMaxMinutes: $commuteMinutes,
            minPricePerM2: $minPricePerM2,
        );
    }

    /**
     * @return list<string>
     *
     * @throws ConfigError
     */
    private static function patternList(Reader $r, string $key, string $pointer): array
    {
        if (!$r->has($key)) {
            return [];
        }

        $out = [];
        foreach ($r->requireStringList($key, allowEmptyList: true) as $pattern) {
            self::assertUsablePattern($pattern, $pointer . '.' . $key);
            $out[] = $pattern;
        }

        return $out;
    }

    /**
     * @return array<string, SourceDefinition> keyed by source name
     *
     * @throws ConfigError
     */
    public static function loadSources(string $path): array
    {
        return self::sourcesFromArray(self::decodeObject($path), basename($path));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string, SourceDefinition>
     *
     * @throws ConfigError
     */
    public static function sourcesFromArray(array $data, string $pointer = 'sources.json'): array
    {
        $top = new Reader($pointer, $data);
        $sources = $top->requireObject('sources');
        $top->done();

        $out = [];
        foreach ($sources->keys() as $name) {
            $out[$name] = self::sourceFromReader($name, $sources->requireObject($name));
        }
        $sources->done();

        if ($out === []) {
            throw ConfigError::at($pointer . '.sources', 'no sources defined');
        }

        return $out;
    }

    /** @throws ConfigError */
    private static function sourceFromReader(string $name, Reader $r): SourceDefinition
    {
        $where = $r->pointer();

        if (preg_match('~^[a-z][a-z0-9_]*$~', $name) !== 1) {
            throw ConfigError::at($where, 'source names are lowercase, digits and underscores, starting with a letter');
        }

        $enabled = $r->requireBool('enabled');
        $family = $r->requireString('family', ['institutional', 'private']);

        $type = $r->requireString('type');
        if ($type === 'browser') {
            // Recognised and refused, rather than accepted and unimplemented. Ruled 2026-08-07 (Q10):
            // a stub adapter that fails at fetch time is a source that looks configured and is not.
            throw ConfigError::at(
                $where . '.type',
                'browser automation is not permitted in this project (docs/OPEN-QUESTIONS.md Q10). '
                    . 'Use `email_alert` for a portal that cannot be polled',
            );
        }
        if (!in_array($type, SourceDefinition::TYPES, true)) {
            throw ConfigError::at($where . '.type', 'expected one of ' . implode(', ', SourceDefinition::TYPES) . ', got ' . var_export($type, true));
        }

        // Required, never defaulted in the file. See the class docblock of SourceDefinition.
        if (!$r->has('mixed_tenure')) {
            throw ConfigError::at(
                $where . '.mixed_tenure',
                'required — it is the flag that arms the fail-closed rule of CLAUDE.md §1. '
                    . 'Say true unless this landlord provably publishes no social stock at all',
            );
        }
        $mixedTenure = $r->requireBool('mixed_tenure');

        $defaultTenure = null;
        if ($r->has('default_tenure')) {
            $raw = $r->optString('default_tenure', null);
            if ($raw !== null) {
                $defaultTenure = Tenure::tryFrom($raw);
                if ($defaultTenure === null) {
                    throw ConfigError::at($where . '.default_tenure', 'not a known tenure: ' . var_export($raw, true));
                }
                if ($defaultTenure->isExcluded()) {
                    throw ConfigError::at(
                        $where . '.default_tenure',
                        $raw . ' is in the excluded set (CLAUDE.md §1). A source whose default tenure is '
                            . 'social housing is wholly out of scope — remove the source rather than '
                            . 'declaring it here',
                    );
                }
                if ($defaultTenure === Tenure::UNKNOWN) {
                    throw ConfigError::at(
                        $where . '.default_tenure',
                        'UNKNOWN is what the classifier concludes, not what a source declares. Use null',
                    );
                }
            }
        }

        $url = $r->optString('url', null);
        $baseUrl = $r->optString('base_url', null);
        $method = $r->optString('method', 'GET', ['GET', 'POST']) ?? 'GET';
        $body = $r->optString('body', null);
        $itemsPath = $r->optString('items_path', null);
        $embeddedJsonSelector = $r->optString('embedded_json_selector', null);
        $itemSelector = $r->optString('item_selector', null);
        $pageParam = $r->optString('page_param', null);
        $pagePath = $r->optString('page_path', null);
        $totalSelector = $r->optString('total_selector', null);
        $maxPages = $r->optInt('max_pages', 20, 1, 500) ?? 20;
        $legalRisk = $r->optBool('legal_risk', false);
        $proseAbsent = $r->optBool('prose_absent', false);
        $fixture = $r->optString('fixture', null);
        $rateLimitMs = $r->optInt('rate_limit_ms', 2000, 0, 600000) ?? 2000;

        // A per-source silence threshold (2026-08-29). Refused on any source that cannot ACT on
        // it — only `email_alert` reports a feed date, so on `html`/`json` the key would be a
        // configured feature that never runs, the `detail_budget_per_pass: 0` shape. And refused
        // at 0 for the reason the env var is: 0 disables the one verdict that tells a dead alert
        // from a quiet market. Absent means the global `RENT_FEED_SILENT_DAYS`, which is what keeps
        // every shipped block byte-identical.
        $feedSilentDays = $r->optInt('feed_silent_days', null);

        if ($feedSilentDays !== null) {
            if ($type !== 'email_alert') {
                throw ConfigError::at(
                    $where . '.feed_silent_days',
                    'seule une source email_alert rapporte une date de flux — sur une source `' . $type
                        . '` ce seuil ne pourrait jamais agir (fonctionnalité désactivée déguisée en configurée)',
                );
            }

            if ($feedSilentDays < 1) {
                throw ConfigError::at(
                    $where . '.feed_silent_days',
                    'doit valoir au moins 1 jour — 0 désactiverait la détection de flux muet',
                );
            }
        }

        $headers = self::stringMap($r->optObject('headers'));
        $params = self::stringMap($r->optObject('params'));

        foreach ($headers as $headerName => $headerValue) {
            // A name that is not a token is how the User-Agent refusal below gets smuggled past:
            // libcurl reads the header name from the text before the first colon, so a KEY of
            // `user-agent: Mozilla` would clear an equality check and still disguise the request.
            // A colon can never appear in a valid token, so refusing non-tokens closes every
            // spelling of that shape at once.
            if (preg_match(self::HEADER_NAME_TOKEN, (string) $headerName) !== 1) {
                throw ConfigError::at(
                    $where . '.headers',
                    'header name ' . var_export($headerName, true) . ' is not a valid HTTP token '
                        . '(letters, digits and !#$%&\'*+-.^_`|~ only — no colon, space or '
                        . 'control character)',
                );
            }

            if (preg_match('~[\r\n]~', $headerValue) === 1) {
                throw ConfigError::at(
                    $where . '.headers',
                    'the value of header ' . $headerName . ' contains a line break — that is HTTP '
                        . 'header injection, not a header',
                );
            }

            if (strtolower((string) $headerName) === 'user-agent') {
                // In cURL, a User-Agent entry in CURLOPT_HTTPHEADER silently overrides
                // CURLOPT_USERAGENT — so this one config key would disguise every request from the
                // source, which is the browser impersonation hard rule 5 forbids outright. Refused
                // here so the operator learns at load time; `CurlHttpClient` refuses it again at
                // the funnel, because config is not the only way a header reaches a request.
                throw ConfigError::at(
                    $where . '.headers',
                    'a User-Agent header is not configurable. CLAUDE.md hard rule 5: this tool '
                        . 'identifies honestly, with one fixed User-Agent. If the source blocks '
                        . 'plain clients, use the email-alert route instead',
                );
            }
        }

        $mapReader = $r->optObject('map');
        $map = $mapReader === null ? new FieldMap(ref: ['id']) : FieldMap::fromReader($mapReader);

        // A DECLARATION CONTRADICTED BY THE CONFIGURATION BESIDE IT is worse than no declaration:
        // it reads as considered, and it makes every push on the source carry a caveat that is
        // false. `prose_absent` says the payload holds no listing text for the exclusion lists to
        // scan — so a `description` selector, which exists precisely to extract that text, settles
        // the question the other way. An email source maps no description (it is parsed out of the
        // body, not selected), so this binds where somebody could actually set both.
        //
        // OUTSIDE the `enabled` branch, deliberately, for the reason the `card_separator` +
        // `mixed_tenure` refusal is: `--source=<name>` force-runs a disabled source, so a drafted
        // block reaches a real notification without ever having been enabled.
        if ($proseAbsent && $map->description !== []) {
            throw ConfigError::at(
                $where . '.prose_absent',
                'this source maps a `description`, so it does publish listing text — prose_absent '
                    . 'would make every notification claim a check was impossible when it was made',
            );
        }

        $detailMapReader = $r->optObject('detail_map');
        $detailMap = $detailMapReader === null ? null : FieldMap::detailFromReader($detailMapReader);
        $detailBudget = $r->optInt('detail_budget_per_pass', 20, min: 0);

        $r->done();

        // A PARAM NO ADAPTER READS IS REFUSED — on an email source ONLY (Track 5b, F-A).
        //
        // `params` is OVERLOADED, and that is why this guard is scoped by type rather than applied
        // everywhere. On an `html` or `json` source it IS the HTTP query string — `HtmlSource` does
        // `withQuery([...$this->definition->params, ...$extra])` — so In'li's `price_max`,
        // `area_min`, `room_min` and Logirep's `ss_trnsctntp` are arguments for the SITE, read by no
        // adapter by design and impossible to enumerate. Refusing them would break both live
        // polling sources at startup, which is the lockout the `RENT_FEED_SILENT_DAYS` hard refusal
        // already cost this repo once.
        //
        // On an `email_alert` source `params` is adapter configuration, a closed set, and the
        // absence of this check was the ENABLING CONDITION for the whole silent-extraction family:
        // a misspelt `titel_pattern` loaded cleanly and did nothing, which is exactly the PAP
        // failure — the pattern silently absent, the generic scan answering instead, wrong values
        // stored, and nothing reading as a fault. `CLAUDE.md` claimed "any other unrecognised key
        // is a validation error"; that was true of the top level and false here.
        //
        // The list is the UNION OF EVERY READER, taken from the code rather than from what the
        // shipped config happens to use — `params[...]`, `param()`, `stringParam()` and
        // `matchParam()` in `EmailAlertSource` plus the construction sites. A param added to the
        // adapter and forgotten here becomes a refusal on a config that is actually correct, so the
        // two move together.
        //
        // Deliberately OUTSIDE the `enabled` branch: `--source=<name>` force-runs a disabled
        // source — the documented onboarding path, `/add-source` step 5 — so a guard that fires
        // only on enabled sources is one the intended workflow walks straight past.
        if ($type === 'email_alert') {
            foreach (array_keys($params) as $key) {
                if (\in_array($key, self::EMAIL_ALERT_PARAMS, true)) {
                    continue;
                }

                throw ConfigError::at(
                    $where . '.params.' . $key,
                    'aucun adaptateur ne lit ce paramètre sur une source email_alert. Un nom mal '
                        . 'orthographié se charge sans bruit et ne fait rien — le motif est absent, '
                        . 'le balayage générique répond à sa place, et rien ne ressemble à une '
                        . 'panne. Paramètres lus : ' . implode(', ', self::EMAIL_ALERT_PARAMS),
                );
            }
        }

        // BOTH SEPARATORS SEGMENT, SO EVERY GUARD ABOUT SEGMENTATION READS BOTH (C2 round-1
        // correctness lens, 2026-09-02). `card_separator_pattern` is the regex form added for
        // Bien'ici, and `EmailAlertSource::segments()` honours it IN PREFERENCE to the literal —
        // but the two guards below tested the literal alone, so both could be defeated by writing
        // the same configuration in the other form, with the whole suite and the whole ledger
        // green. One of them names its own subject a §1 decision. §1 says a config key that can
        // re-enable an excluded regime is P0 even when nothing sets it, and the third guard further
        // down already read both keys — which is what makes this a slip rather than a design.
        // The key NAMED in a refusal is the one the file actually carries: an error pointing at
        // `card_separator` on a config that configures `card_separator_pattern` sends the operator
        // to a line that is not there, which is how a correct refusal reads as a broken tool.
        $separatorKey = ($params['card_separator'] ?? '') !== ''
            ? 'card_separator'
            : (($params['card_separator_pattern'] ?? '') !== '' ? 'card_separator_pattern' : null);
        $segmented = $separatorKey !== null;

        // Deliberately OUTSIDE the `enabled` branch, both of them. `--source=<name>` force-runs a
        // disabled source — that is the documented onboarding path, `/add-source` step 5 — so a
        // guard that fires only on enabled sources is a guard the intended workflow walks straight
        // past.
        if ($segmented && $mixedTenure) {
            // Segmenting makes each listing's description its own CARD rather than the whole
            // message. That is the Cityloger ruling — a map addresses the listing, never the page —
            // and it costs one signal: a batch-level `logement conventionné` stated once for a
            // whole digest stops being visible to any individual card's classifier.
            //
            // On a pure-LIBRE portal that costs nothing, and every segmented source today is one.
            // On a mixed-stock source it is a §1 decision nobody has made against a real payload,
            // so it is refused here rather than answered by a batch-veto mechanism written blind.
            // Same shape as `detail_budget_per_pass: 0`.
            throw ConfigError::at(
                $where . '.params.' . $separatorKey,
                'segmenter un message en cartes retire au classifieur toute mention de régime '
                    . 'énoncée une seule fois pour le lot entier, ce qui sur une source '
                    . 'mixed_tenure est une décision §1 que personne n\'a prise face à un vrai '
                    . 'message. Retirez mixed_tenure, ou retirez ' . $separatorKey,
            );
        }

        // A segmented source keyed on the LINK must say which links are listings.
        //
        // This replaces a blanket *"segmented needs id_from: content"*, which was true only while
        // link identity meant the whole message's links. Now the segmented path keys on the CARD's
        // own last qualifying link, so `link` is a real answer — and that old rule's own comment
        // said as much: "`link` is a real answer for a portal whose alerts DO carry listing URLs".
        // Bien'ici is one: `/annonce/laforet-immo-facile-22588736` is a real, stable id in the path.
        //
        // What is left to refuse is the shape whose failure is SILENT. With no `link_host` every
        // link qualifies, so a card's identity becomes whatever link happens to sit last in it — an
        // advert, a photo, a "manage my alerts". Two cards ending on the same one is caught loudly
        // at fetch (two distinct cards, one identity). Two cards ending on DIFFERENT rotating advert
        // links is not caught at all: every card gets a plausible unique id that changes with the
        // next campaign, so the whole source re-notifies for ever and reads as a busy market.
        // `trim() === ''` rather than `isset()`: `looksLikeAListing()` reads the value with
        // `stringParam()`, which treats an empty string as unset, so `"link_host": ""` passes an
        // `isset` check and then makes every link qualify — the exact silent shape the message
        // below describes, reached by a different mistake.
        $linkHost = $params['link_host'] ?? null;

        if ($segmented
            && ($params['id_from'] ?? 'link') !== 'content'
            && (!\is_string($linkHost) || trim($linkHost) === '')) {
            throw ConfigError::at(
                $where . '.params.link_host',
                'une source segmentée et identifiée par lien doit dire quels liens sont des '
                    . 'annonces — sans link_host, l\'identité d\'une carte est le dernier lien '
                    . 'venu (publicité, photo, gestion d\'alerte), et une publicité qui tourne '
                    . 'renotifie toute la source sans jamais le dire. Ajoutez link_host, ou '
                    . 'passez à `id_from: content`',
            );
        }

        // `matchParam()` reads these with `@preg_match`, so a pattern that does not compile never
        // warns and never throws: it returns false, and the field is null for ever. On
        // `residence_pattern` that silently narrows the identity floor, since the residence is one
        // of the three facts it accepts.
        // On `commune_pattern` it is worse still, because the failure has a plausible explanation
        // sitting next to it: the commune falls back to the ranked-vocabulary scan, which on a
        // region-mode config knows almost no names, so `null` reads as "this town is not one of the
        // ranked ones" rather than as "the pattern is broken".
        // `surface_pattern` and `rooms_pattern` join the list for the same reason and with a sharper
        // edge: on a miss they yield `null` rather than falling back, so a broken one silently
        // removes a NUMERIC filter's input. An unknown surface is never a disqualification
        // (hard rule 9), so the listing goes on matching with one fewer filter and one fewer score
        // component — visible nowhere.
        // BOTH SEPARATORS IS A GUESS ABOUT WHICH ONE THE ADAPTER HONOURS, and the ignored one fails
        // silently — the same rule `HtmlSource` applies to its three pagination mechanisms.
        if (($params['card_separator'] ?? '') !== '' && ($params['card_separator_pattern'] ?? '') !== '') {
            throw ConfigError::at(
                $where . '.params.card_separator_pattern',
                'card_separator et card_separator_pattern sont deux mécanismes pour la même chose : '
                    . 'n\'en configurez qu\'un, sinon celui qui est ignoré échoue en silence',
            );
        }

        // `card_separator_pattern` joins the compile-check with the sharpest edge of all: a broken
        // one throws at fetch (see the adapter), but an unbroken one that matches NOTHING merges
        // every card in the message into a single listing carrying the first card's facts under the
        // last card's link. That is the 2026-08-31 Bien'ici defect, and it is invisible.
        // `advertiser_pattern` joins them with a §1 edge rather than an extraction one: a broken one
        // yields `null`, the listing keeps the source's own profile, and a landlord-advertised card
        // on a `mixed_tenure: false` portal is notified as LIBRE again. That is the exact hole
        // LandlordRegistry closes, silently re-opened by a typo.
        // `subject_pattern` joins them with the sharpest edge of all, because its failure is not an
        // extraction but an ADMISSION: it decides which messages the source reads at all. A pattern
        // that does not compile matches nothing, `@preg_match` neither warns nor throws, and the
        // source silently ingests every message from its sender — including, on leboncoin, the
        // VEHICLE alerts that share its sender, its link host and its card separator.
        foreach (self::PATTERN_PARAMS as $patternKey) {
            $pattern = $params[$patternKey] ?? null;

            if ($pattern === null || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, '') === false) {
                throw ConfigError::at(
                    $where . '.params.' . $patternKey,
                    'expression régulière invalide — elle ne compile pas, donc elle ne '
                        . 'correspondrait jamais à rien sans le dire',
                );
            }
        }

        $idFrom = $params['id_from'] ?? null;
        if ($idFrom !== null && $idFrom !== '' && !in_array($idFrom, ['link', 'content'], true)) {
            // Ignoring an unknown value would fall back to link-identity — which on a portal whose
            // links are all tracking redirects means every card in a message shares one id. That is
            // the exact collapse `content` exists to prevent, reached through a typo.
            throw ConfigError::at(
                $where . '.params.id_from',
                'valeur inconnue « ' . $idFrom . ' » — attendu `link` (défaut) ou `content`',
            );
        }

        if ($enabled) {
            if ($type === 'fixture') {
                if ($fixture === null) {
                    throw ConfigError::at($where . '.fixture', 'a fixture source must name its payload file');
                }
            } elseif ($type === 'email_alert') {
                // ONE MAILBOX SERVES EVERY PORTAL — that is the point of a single `rent-watch`
                // label — so `params.from` is not a nicety, it is the source's scope. Without it
                // the source reads every message in the folder within the window and ingests other
                // portals' alerts as its own, while `SourceHealth` records a plausible count
                // throughout.
                //
                // It is also what scopes the IMAP query: `ImapMailbox` pushes `FROM` into `SEARCH`,
                // so each source gets its own window rather than a slice of one shared one.
                // Without it a busy portal starves a quiet one silently, and it worsens with every
                // source added — measured 2026-08-25, when a year of another portal's archive
                // relabelled into the folder took SeLoger from 9 listings to 0 and nothing but the
                // health baseline noticed.
                //
                // At LOAD, because a source that can only fail once it is polling is a source that
                // fails in production. A DISABLED block is left alone: a draft has not claimed to
                // work yet.
                $from = $params['from'] ?? null;

                if (!\is_string($from) || trim($from) === '') {
                    throw ConfigError::at(
                        $where . '.params.from',
                        'an enabled email_alert source must name the sender it reads. One mailbox '
                            . 'serves every portal, so without this the source ingests other '
                            . 'portals\' alerts as its own and reports a plausible count while '
                            . 'doing it',
                    );
                }
            } else {
                if ($url === null) {
                    throw ConfigError::at($where . '.url', 'an enabled ' . $type . ' source needs a url');
                }
                if (str_contains($url, self::UNVERIFIED_URL)) {
                    throw ConfigError::at(
                        $where . '.url',
                        'still the ' . self::UNVERIFIED_URL . ' placeholder. CLAUDE.md hard rule 1: never write '
                            . 'an endpoint from memory — verify it against the live site, or leave the source disabled',
                    );
                }

                // An html source with no `item_selector` cannot know which element is a listing, so
                // it extracts nothing — and extracting nothing is this project's signature silent
                // failure, indistinguishable from a market that went quiet. `HtmlSource::fetch()`
                // refuses it too; this one refuses it at LOAD, before a poll has been scheduled and
                // before a run log has recorded a source that was never going to work.
                if ($type === 'html' && ($itemSelector === null || trim($itemSelector) === '')) {
                    throw ConfigError::at(
                        $where . '.item_selector',
                        'required for an enabled html source — it is the CSS selector picking one '
                            . 'listing element, and without it the adapter finds nothing and reports calm',
                    );
                }

                // Two pagination mechanisms configured at once. Whichever the adapter picks, the
                // other is silently ignored, and a walk that appends the ignored one refetches page
                // one until the bound trips — or worse, terminates "naturally" on a duplicate page.
                if ($pageParam !== null && $pagePath !== null) {
                    throw ConfigError::at(
                        $where . '.page_path',
                        'a source paginates by query parameter or by path, never both — remove one, '
                            . 'because the ignored mechanism fails silently rather than loudly',
                    );
                }

                // Without the placeholder every page after the first requests the same url: a walk
                // that cannot advance, and whose collected count then trips the declared-total check
                // with a message about lost pages rather than about this typo.
                if ($pagePath !== null && !str_contains($pagePath, '{page}')) {
                    throw ConfigError::at(
                        $where . '.page_path',
                        'must contain the {page} placeholder — `' . $pagePath . '` would request the '
                            . 'same url for every page',
                    );
                }

                // A THIRD mechanism, and the same rule: exactly one. `{page}` in the url itself is
                // for a site whose page number sits mid-path (Cityloger), and combining it with
                // either of the others leaves one silently ignored.
                if (str_contains($url, '{page}') && ($pageParam !== null || $pagePath !== null)) {
                    throw ConfigError::at(
                        $where . '.url',
                        'carries a {page} template AND ' . ($pageParam !== null ? 'page_param' : 'page_path')
                            . ' is configured — a source paginates one way, and the ignored mechanism '
                            . 'fails silently rather than loudly',
                    );
                }

                // The mirror of the detail_map refusal below, and for the same reason: only the
                // json adapter extracts an embedded payload, so this key on an html or email source
                // would be read by nobody while looking like behaviour somebody switched on.
                if ($embeddedJsonSelector !== null && $type !== 'json') {
                    throw ConfigError::at(
                        $where . '.embedded_json_selector',
                        'only a json source extracts an embedded payload — this selector would be '
                            . 'silently ignored',
                    );
                }

                // A detail map is a request PER LISTING. Only the html adapter implements it, and a
                // detail_map sitting on a json source would be read by nobody while looking like
                // configured behaviour — the shape of a mapping that fails silently at runtime
                // instead of loudly at load.
                if ($detailMap !== null && $type !== 'html') {
                    throw ConfigError::at(
                        $where . '.detail_map',
                        'only an html source fetches detail pages — this map would be silently ignored',
                    );
                }

                // Zero is not a small budget, it is a disabled feature wearing a configured
                // feature's clothes: a detail_map that never runs leaves a mixed-tenure source
                // resolving UNKNOWN for ever while its health stays green and its count looks
                // right. Omitting the key is fine and defaults; writing 0 is refused. Same
                // reasoning as an unusable RENT_HEARTBEAT_HOURS being a loud startup refusal.
                if ($detailMap !== null && $detailBudget === 0) {
                    throw ConfigError::at(
                        $where . '.detail_budget_per_pass',
                        '0 means this detail_map can never run, which is indistinguishable from a '
                            . 'source that simply publishes nothing extra — remove the detail_map to '
                            . 'say that on purpose, or raise the budget',
                    );
                }

                // The card's `url` is what a detail fetch requests. Without it every gated listing
                // fails at fetch time, one exception per listing, on a source that loaded cleanly.
                if ($detailMap !== null && $map->url === []) {
                    throw ConfigError::at(
                        $where . '.detail_map',
                        'needs `map.url` — the detail page is fetched from each card\'s own url, and '
                            . 'without that mapping there is nothing to request',
                    );
                }
            }
        }

        return new SourceDefinition(
            name: $name,
            enabled: $enabled,
            family: $family,
            type: $type,
            mixedTenure: $mixedTenure,
            defaultTenure: $defaultTenure,
            url: $url,
            baseUrl: $baseUrl,
            method: $method,
            body: $body,
            headers: $headers,
            params: $params,
            itemsPath: $itemsPath,
            itemSelector: $itemSelector,
            pageParam: $pageParam,
            pagePath: $pagePath,
            embeddedJsonSelector: $embeddedJsonSelector,
            detailMap: $detailMap,
            detailBudgetPerPass: $detailBudget,
            totalSelector: $totalSelector,
            maxPages: $maxPages,
            map: $map,
            legalRisk: $legalRisk,
            fixture: $fixture,
            rateLimitMs: $rateLimitMs,
            feedSilentDays: $feedSilentDays,
            proseAbsent: $proseAbsent,
        );
    }

    /**
     * @return array<string,string>
     *
     * @throws ConfigError
     */
    private static function stringMap(?Reader $r): array
    {
        if ($r === null) {
            return [];
        }

        $out = [];
        foreach ($r->keys() as $key) {
            $out[$key] = $r->requireString($key, allowEmpty: true);
        }
        $r->done();

        return $out;
    }

    /**
     * A pattern must compile, and must be written in FOLDED ASCII.
     *
     * Patterns are matched against {@see \Scout\Core\Text::fold()}ed text, which has had its
     * accents removed — so a config author who writes `meublé` gets a pattern that can never match,
     * and gets it silently. Refusing the accented form with a message naming the folded one is the
     * only way that mistake becomes visible; the alternative, folding the pattern for them, would
     * lowercase `\W` into `\w` and change what the regex means.
     *
     * @throws ConfigError
     */
    private static function assertUsablePattern(string $pattern, string $where): void
    {
        if (preg_match('~[\x80-\xFF]~', $pattern) === 1) {
            throw ConfigError::at(
                $where,
                var_export($pattern, true) . ' contains a non-ASCII character. Patterns are matched '
                    . 'against accent-folded text, so write `meuble`, not `meublé` — an accented '
                    . 'pattern can never match anything',
            );
        }

        // `set_error_handler` PUSHES; passing the old handler back would push a second one rather
        // than popping this one, and PHPUnit fails a test that leaves a handler on the stack. The
        // handler is needed at all because an invalid pattern raises a warning that `@` does not
        // hide from a registered handler, and this project runs with `failOnWarning`.
        set_error_handler(static fn (): bool => true);
        try {
            $ok = preg_match('~' . $pattern . '~i', '');
        } finally {
            restore_error_handler();
        }

        if ($ok === false) {
            throw ConfigError::at($where, var_export($pattern, true) . ' is not a valid regular expression');
        }
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ConfigError
     */
    private static function decodeObject(string $path): array
    {
        if (!is_file($path)) {
            throw ConfigError::at($path, 'no such config file');
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ConfigError::at($path, 'config file exists but could not be read');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigError::at($path, 'not valid JSON — ' . $e->getMessage());
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw ConfigError::at($path, 'top level must be a JSON object');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Objects merge key by key; everything else replaces.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (
                is_array($value) && !array_is_list($value)
                && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])
            ) {
                /** @var array<string,mixed> $left */
                $left = $base[$key];
                /** @var array<string,mixed> $value */
                $base[$key] = self::deepMerge($left, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * The `weights` block, read here rather than by {@see Weights} itself (step 8d, 2026-10-17): a pure policy
     * object that read its own block depended on {@see Reader}, whose `fromFile()` opens the file.
     */
    private static function weightsFrom(Reader $r): Weights
    {
        $w = new Weights(
            commune: $r->optInt('commune', 25, 0, 1000) ?? 0,
            commute: $r->optInt('commute', 0, 0, 1000) ?? 0,
            rentHeadroom: $r->optInt('rent_headroom', 15, 0, 1000) ?? 0,
            surface: $r->optInt('surface', 10, 0, 1000) ?? 0,
            lift: $r->optInt('lift', 15, 0, 1000) ?? 0,
            // The one weight allowed to be negative, and required to be: a positive "high floor, no
            // lift" weight would be a bonus for the exact thing the developer is escaping.
            highFloorNoLift: $r->optInt('high_floor_no_lift', -20, -1000, 0) ?? 0,
            freshness: $r->optInt('freshness', 10, 0, 1000) ?? 0,
            // Negative for the same reason and by the same bound: a POSITIVE heating penalty would
            // be a bonus for the exact thing the developer asked to be penalised severely.
            heatingIndividual: $r->optInt('heating_individual', -20, -1000, 0) ?? 0,
            // A SURCHARGE that stacks on the line above, never a replacement for it — so setting
            // this alone still leaves an electric flat carrying the base penalty, and zeroing the
            // base while keeping this one is a configuration that penalises electric heating only.
            heatingIndividualElectric: $r->optInt('heating_individual_electric', -15, -1000, 0) ?? 0,
        );
        $r->done();

        return $w;
    }

    /** The `notify` block — moved out of {@see NotifyPolicy} for the same reason as {@see self::weightsFrom()}. */
    private static function notifyPolicyFrom(Reader $r): NotifyPolicy
    {
        $p = new NotifyPolicy(
            channels: $r->has('channels') ? $r->requireStringList('channels') : ['console'],
            highPriorityScore: $r->optInt('high_priority_score', 70, 0, 100) ?? 70,
            rentDropMinEur: $r->optInt('rent_drop_min_eur', 20, 0) ?? 0,
            rentDropMinPct: $r->has('rent_drop_min_pct') ? $r->requireFloat('rent_drop_min_pct', 0.0, 100.0) : 2.0,
            sourceBrokenCooldownHours: $r->optInt('source_broken_cooldown_hours', 24, 0) ?? 0,
            digestHour: $r->optInt('digest_hour', 8, 0, 23) ?? 8,
            pushMinScore: $r->optInt('push_min_score', null, 0, 100),
        );
        $r->done();

        return $p;
    }
}
