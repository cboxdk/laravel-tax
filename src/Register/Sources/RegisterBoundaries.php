<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\ReportsDistrictOverlays;
use Cbox\Tax\Contracts\ReportsSplitPostcodes;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Exceptions\DatasetUnreadable;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Store\ShardReader;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Territories\UsLocalStructure;
use Cboxdk\TaxResolver\Accuracy;
use Cboxdk\TaxResolver\Authority;
use Cboxdk\TaxResolver\BoundaryData;
use Cboxdk\TaxResolver\Geometry;
use Cboxdk\TaxResolver\ParsedAddress;
use Cboxdk\TaxResolver\Point;
use Cboxdk\TaxResolver\Resolver;
use Cboxdk\TaxResolver\UnsupportedFormatVersion;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Which local authorities tax a US address, out of the register's boundary files.
 *
 * The walk itself is `cboxdk/tax-resolver`, not code written here, and that is the
 * point of it: the register runs the SAME resolver over its own artifacts at build
 * time to prove they resolve its conformance deck. Two readers of one format drift
 * apart quietly — which is exactly what happened when that package read a
 * formatVersion 3 artifact as if it were 2 and answered "no local authority levies
 * here" for every address in all twenty-four Streamlined states, with the suite
 * green throughout.
 *
 * NULL AND EMPTY MEAN OPPOSITE THINGS, and the contract this implements is built on
 * the difference. Null is "I do not answer for this address" and sends the engine to
 * the state rate; an empty list is a row saying no local authority levies there, and
 * is priced as the whole rate. Nothing here collapses them.
 */
final class RegisterBoundaries implements LocalAuthorityResolver, ReportsDistrictOverlays, ReportsSplitPostcodes
{
    /** The district overlay format this reads. */
    private const int OVERLAY_FORMAT = 1;

    /** @var array<string, list<array{authority: string, replaces: list<string>, from: ?string, until: ?string, zips: list<string>, geometry: Geometry}>|null> */
    private array $districts = [];

    /** @var array<string, ShardReader> */
    private array $streetShards = [];

    /** @var array<string, ShardReader> */
    private array $postalShards = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $postalHeads = [];

    public function __construct(
        private StoreLayout $layout,
        private string $version,
        private ?RegisterDataset $dataset = null,
        private Resolver $resolver = new Resolver,
    ) {}

    /**
     * `$at` DOES NOT NARROW THIS ANSWER, and the reason is in the data rather than in
     * the code. The register's boundary artifacts carry no effective dates: each is a
     * snapshot of where the lines ran when its release was compiled, and one release
     * holds exactly one such snapshot. There is nothing here to select a date within.
     *
     * So an annexation between the supply date and the installed release is answered
     * with today's lines. That is a real limit and it is narrow — the RATES are dated
     * and resolved on `$at` correctly, so only a boundary that actually MOVED is
     * affected. Where it matters, historical boundaries are a store-version question:
     * sync the release that was current then and `tax:data:activate` it.
     *
     * The parameter stays in the signature because the contract is shared with
     * resolvers that DO have dated sources, and a host binding one of those gets the
     * date it needs.
     *
     * @return list<string>|null
     */
    public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array
    {
        $subdivision = $jurisdiction->subdivision;
        $locality = $jurisdiction->locality;

        if ($subdivision === null || $locality === null || $jurisdiction->country->value !== 'US') {
            return null;
        }

        $state = substr($subdivision->value, 3);

        if ($locality->scheme === LocalityScheme::County->value) {
            return $this->byName($state, $locality->value);
        }

        if (in_array($locality->scheme, [LocalityScheme::Authority->value, LocalityScheme::CaliforniaPlace->value], true)) {
            return $this->byAuthorityCode($state, $locality->value);
        }

        $address = $this->address($locality->scheme, $locality->value);

        if ($address === null) {
            return null;
        }

        $authorities = $this->resolveParsed($state, $address);

        if ($authorities === null) {
            // A POINT IN NO POLYGON IS NO LOCAL TAX — only where the register says its
            // layers leave no levying ground out on that date. California's and New
            // Mexico's do; Texas's do not yet (a district in force with no polygon, and
            // districts that start before the layer carries them), and there "outside
            // every feature" stays unresolved. A ZIP missing from a postal file is never
            // read this way: it is a gap in the file, not ground without tax.
            return $address->point !== null
                && $this->dataset?->usLocalAbsenceHolds($state, $at ?? new DateTimeImmutable('today')) === true
                ? []
                : null;
        }

        $codes = array_map(fn (Authority $authority): string => $this->code($state, $authority), $authorities);

        // A POLYGON LAYER NAMES THE LOCALS, NOT THE STATE. A postal set carries the
        // state as a member where the state's rate applies; a polygon file lists
        // cities, combined areas, transit and districts, and the state share is due
        // over all of them. Where locals are filed as COMPONENTS — Texas — leaving it
        // out summed the locals alone: an Austin address came back at 2.5%,
        // authoritative, where 8.75% is due. Where they are COMBINED totals —
        // California, New Mexico — the combined record replaces the state share, so
        // listing the state beside it changes nothing.
        if ($address->point !== null && $address->zip5 === '' && $codes !== [] && ! in_array('us:'.$state, $codes, true)) {
            array_unshift($codes, 'us:'.$state);
        }

        if ($address->point !== null && $address->zip5 !== '') {
            return $this->withDistricts($state, $codes, $address->point, $at ?? new DateTimeImmutable('today'));
        }

        return $codes;
    }

    /**
     * The districts drawn over the postal layer that reach this locality's ZIP and
     * were not tested against a point — a ZIP+4 or a bare ZIP in a ZIP one of them
     * reaches. A ZIP+4 WITH its point was tested, so it reports none; one whose
     * district file cannot be read reports a district of unknown authority, since
     * nothing was tested at all.
     *
     * @return list<array{authority: ?string, replaces: list<string>}>
     */
    public function districtsUnchecked(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): array
    {
        $subdivision = $jurisdiction->subdivision;
        $locality = $jurisdiction->locality;

        if ($subdivision === null || $locality === null || ! in_array($locality->scheme, [LocalityScheme::Zip9->value, LocalityScheme::Zip9AndPoint->value], true)) {
            return [];
        }

        $state = substr($subdivision->value, 3);

        if (! is_file($this->layout->file($this->version, 'boundaries/'.$state.'.overlay.json'))) {
            return [];
        }

        $districts = $this->districts($state);

        if ($districts === null) {
            return [['authority' => null, 'replaces' => []]];
        }

        if ($locality->scheme === LocalityScheme::Zip9AndPoint->value) {
            return [];
        }

        $zip5 = substr(preg_replace('/\D/', '', $locality->value) ?? '', 0, 5);
        $day = ($at ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $unchecked = [];

        foreach ($districts as $district) {
            if ($this->inForce($district['from'], $district['until'], $day) && in_array($zip5, $district['zips'], true)) {
                $unchecked[] = ['authority' => $district['authority'], 'replaces' => $district['replaces']];
            }
        }

        return $unchecked;
    }

    /**
     * A DISTRICT DRAWN OVER THE POSTAL LAYER stands in place of what it `replaces`
     * where the point falls inside it. Nebraska's Good Life Districts set the state's
     * own rate — 2.75% in Avenue One, where the state's is 5.5% — inside boundaries no
     * ZIP follows, so the postal files never name them and only the point can say.
     * Tested AFTER the postal answer, never instead of it: the ZIP+4 still decides the
     * city and the county, the district only swaps what it names.
     *
     * A district file that cannot be read changes nothing here; the rate source flags
     * the answer through {@see districtsUnchecked()} instead.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function withDistricts(string $state, array $codes, Point $point, DateTimeImmutable $at): array
    {
        $day = $at->format('Y-m-d');

        foreach ($this->districts($state) ?? [] as $district) {
            if (! $this->inForce($district['from'], $district['until'], $day) || $district['geometry']->authoritiesAt($point) === []) {
                continue;
            }

            if (in_array($district['authority'], $codes, true)) {
                continue;
            }

            // In the place of the first code it replaces, so a breakdown still reads
            // state line first.
            $at = null;
            $kept = [];

            foreach ($codes as $code) {
                if (in_array($code, $district['replaces'], true)) {
                    $at ??= count($kept);

                    continue;
                }

                $kept[] = $code;
            }

            array_splice($kept, $at ?? count($kept), 0, [$district['authority']]);
            $codes = $kept;
        }

        return $codes;
    }

    /**
     * The state's district overlay, read once. An empty list where the state draws
     * none; null where it draws some this reader cannot read — a format it does not
     * implement, or a file that is not one.
     *
     * Several features under one authority are parts of one district: the state's own
     * layer draws some districts in pieces, and a point in any piece is inside it.
     *
     * @return list<array{authority: string, replaces: list<string>, from: ?string, until: ?string, zips: list<string>, geometry: Geometry}>|null
     */
    private function districts(string $state): ?array
    {
        if (array_key_exists($state, $this->districts)) {
            return $this->districts[$state];
        }

        $json = $this->read($state.'.overlay.json');

        if ($json === null) {
            return $this->districts[$state] = is_file($this->layout->file($this->version, 'boundaries/'.$state.'.overlay.json')) ? null : [];
        }

        if (($json['formatVersion'] ?? null) !== self::OVERLAY_FORMAT || ! is_array($json['features'] ?? null)) {
            return $this->districts[$state] = null;
        }

        $districts = [];

        foreach ($json['features'] as $feature) {
            $properties = Shape::map(Shape::map($feature)['properties'] ?? null);
            $authority = Shape::text($properties['authority'] ?? null);

            if ($authority === null) {
                return $this->districts[$state] = null;
            }

            $zips = [];

            // Published as numbers by release 303; a number loses a ZIP's leading zero,
            // so each is padded back to five digits.
            foreach (is_array($properties['zips'] ?? null) ? $properties['zips'] : [] as $zip) {
                if (is_int($zip) || (is_string($zip) && ctype_digit($zip))) {
                    $zips[] = str_pad((string) $zip, 5, '0', STR_PAD_LEFT);
                }
            }

            try {
                $geometry = Geometry::fromFeatureCollection(['formatVersion' => 3, 'features' => [
                    ['type' => 'Feature', 'properties' => ['authority' => $authority, 'level' => Shape::text($properties['level'] ?? null) ?? 'district'], 'geometry' => Shape::map($feature)['geometry'] ?? null],
                ]]);
            } catch (UnsupportedFormatVersion|InvalidArgumentException) {
                return $this->districts[$state] = null;
            }

            $districts[] = [
                'authority' => $authority,
                'replaces' => array_values(array_filter(is_array($properties['replaces'] ?? null) ? $properties['replaces'] : [], is_string(...))),
                'from' => Shape::text($properties['from'] ?? null),
                'until' => Shape::text($properties['until'] ?? null),
                'zips' => $zips,
                'geometry' => $geometry,
            ];
        }

        return $this->districts[$state] = $districts;
    }

    private function inForce(?string $from, ?string $until, string $day): bool
    {
        return ($from === null || $from <= $day) && ($until === null || $until >= $day);
    }

    /**
     * Whether a bare five-digit ZIP was asked about where the ZIP is split between
     * authority sets. A ZIP+4, a street or a point is never split: the resolver
     * narrows those to one span.
     */
    public function spansSeveralSets(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): bool
    {
        $subdivision = $jurisdiction->subdivision;
        $locality = $jurisdiction->locality;

        if ($subdivision === null || $locality === null || $locality->scheme !== LocalityScheme::Zip9->value) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $locality->value) ?? '';

        if (strlen($digits) !== 5) {
            return false;
        }

        return $this->zipIsUniform(substr($subdivision->value, 3), $digits) === false;
    }

    /**
     * Whether every address in a ZIP falls in the same set of taxing authorities —
     * so a host can skip geocoding a street where the five digits already decide it.
     *
     * Null where the store holds no postal artifact for the state, or none for this
     * ZIP: that is not knowledge either way. Compared by the authorities themselves,
     * not by set number, because two entries in the table can list the same ones.
     */
    public function zipIsUniform(string $state, string $zip5): ?bool
    {
        $head = $this->postalHead($state);

        if ($head === null) {
            return null;
        }

        $spans = Shape::map($this->postalRows($state, $zip5, $head))[$zip5] ?? null;

        if (! is_array($spans) || $spans === []) {
            return null;
        }

        $sets = is_array($head['sets'] ?? null) ? $head['sets'] : [];
        $distinct = [];

        foreach ($spans as $span) {
            $index = is_array($span) ? ($span[2] ?? null) : null;

            if (! is_int($index)) {
                return null;
            }

            $members = [];

            foreach ((array) ($sets[$index] ?? []) as $entry) {
                $members[] = is_array($entry)
                    ? Shape::scalar($entry['level'] ?? null).':'.Shape::scalar($entry['code'] ?? null)
                    : Shape::scalar($entry);
            }

            sort($members);
            $distinct[implode('|', $members)] = true;
        }

        return count($distinct) <= 1;
    }

    /**
     * The authorities at a parsed address, as the shared resolver reports them.
     *
     * Public because the format document makes this the conformance surface: the
     * register runs the SAME resolver over its own artifacts at build time and cuts
     * a deck from the result, and an engine proves it agrees by feeding that deck
     * through here. Everything above translates a locality into one of these; this
     * is the walk itself.
     *
     * @return list<Authority>|null
     */
    public function resolveParsed(string $state, ParsedAddress $address): ?array
    {
        try {
            $assignment = $this->resolver->resolve($address, $this->postal($state, $address->zip5), $this->geometry($state));
        } catch (UnsupportedFormatVersion|InvalidArgumentException) {
            // The store holds an artifact this resolver cannot read — a format it does
            // not implement, or a geometry `replaces` that is not a list of codes.
            // Deferring sends the engine to the state rate, which is short but honest;
            // reading it anyway is how you get a confident answer that is wrong, and
            // letting the refusal escape stopped every sale into the state.
            return null;
        }

        return $assignment->resolved() ? ($assignment->authorities ?? []) : null;
    }

    /**
     * Whether a local answer in the state needs nothing finer than the county — read
     * from the register where it says so, and from the engine's own list only for a
     * release that predates the field.
     */
    private function resolvesByCounty(string $state): bool
    {
        $needs = $this->dataset?->usLocalResolution($state);

        return $needs !== null
            ? $needs === 'county'
            : in_array('US-'.$state, UsLocalStructure::countyResolvedStates(), true);
    }

    /**
     * A county resolved by NAME, for the four states where the county is the only
     * local authority that can apply and no boundary artifact exists.
     *
     * THE MATCH IS ORDERED, and Virginia is why. `Fairfax County` and `Fairfax City`
     * are different authorities over different ground, and so are Franklin, Richmond
     * and Roanoke. Dropping the unit word to match would make each pair ambiguous and
     * refuse — costing Fairfax its regional rate for nothing. So the full name is
     * tried first, and only then the name with its unit word removed.
     *
     * @return list<string>|null
     */
    private function byName(string $state, string $county): ?array
    {
        if ($this->dataset === null || ! $this->resolvesByCounty($state)) {
            return null;
        }

        // THE STATE IS NOT A COUNTY. Its own name is in the list, and a state and a
        // county can share one — Hawaii and the County of Hawaii — so counting it made
        // "Hawaii County" match two and refuse.
        $names = array_filter(
            $this->dataset->namesIn('us/'.$state),
            static fn (string $code): bool => $code !== 'us:'.$state,
            ARRAY_FILTER_USE_KEY,
        );
        $wanted = $this->fold($county);
        $bare = $this->bareName($county);
        $unit = $this->unitsOf($county);
        $exact = null;
        $loose = [];

        foreach ($names as $code => $name) {
            $folded = $this->fold($name);

            if ($folded === $wanted) {
                $exact = $code;

                break;
            }

            // A NAMED UNIT MATCHES ONLY THE SAME UNIT. Virginia has four names that
            // are a county and an independent city at once; with the unit stripped
            // from both sides, "Richmond County" — in no regional area — matched
            // "Richmond City" and took its 6% where 5.3% is due.
            if (($folded === $bare || $this->bareName($name) === $bare) && $this->sameUnit($unit, $this->unitsOf($name))) {
                $loose[] = $code;
            }
        }

        if ($exact !== null) {
            return ['us:'.$state, $exact];
        }

        // A PLACE THE REGISTER DRAWS AND PRICES AT NOTHING LOCAL. Listed by its legal
        // name (`Roanoke city`, `Roanoke County`), which is unique in the state and is
        // how a geocoder writes it. Found here, the name is a real place with no local
        // levy — the state share, and certain. Only a name found nowhere stays unknown.
        $unpriced = $this->dataset->usLocalUnpriced($state) ?? [];
        $looseUnpriced = 0;

        foreach ($unpriced as $place) {
            if ($this->fold($place['legalName']) === $wanted) {
                return [];
            }

            if ($this->bareName($place['legalName']) === $bare && $this->sameUnit($unit, $this->unitsOf($place['legalName']))) {
                $looseUnpriced++;
            }
        }

        // THE UNIT WORD IS PART OF THE NAME, and Virginia is why. A Virginia city is
        // independent of every county, and the register stores it BARE — so
        // `Fairfax City` must reach `Fairfax` while `Fairfax County` reaches
        // `Fairfax County`. Matching both stripped finds two and refuses, costing
        // Fairfax its regional rate for nothing.
        if (preg_match('/\s+city$/i', $county) === 1) {
            $bareOnly = [];

            foreach ($names as $jurisdiction => $name) {
                if ($this->fold($name) === $bare) {
                    $bareOnly[] = $jurisdiction;
                }
            }

            if (count($bareOnly) === 1) {
                return ['us:'.$state, $bareOnly[0]];
            }
        }

        // Two localities answer to the same bare name and nothing said which — priced
        // or not. Refusing sends the caller to the state rate, flagged; guessing bills
        // the wrong authority, or none where one levies.
        if (count($loose) + $looseUnpriced !== 1) {
            return null;
        }

        return $loose === [] ? [] : ['us:'.$state, $loose[0]];
    }

    /**
     * The unit words a place name carries, before or after the name: `Honolulu
     * County` is a county, `City and County of Honolulu` both, and `Miami-Dade` says
     * nothing.
     *
     * @return list<string>
     */
    private function unitsOf(string $name): array
    {
        $name = strtolower(trim($name));
        $units = [];

        if (preg_match('/^((?:city and county|county|city|town|borough|parish|municipality)) of\s+/', $name, $leading) === 1) {
            $units = explode(' and ', $leading[1]);
        }

        if (preg_match('/\s+(county|city|parish|borough)$/', $name, $trailing) === 1) {
            $units[] = $trailing[1];
        }

        return array_values(array_unique($units));
    }

    /**
     * Whether two names can be the same place: either says no unit, or they share one.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameUnit(array $a, array $b): bool
    {
        return $a === [] || $b === [] || array_intersect($a, $b) !== [];
    }

    /**
     * An authority the caller already resolved, named by the source's own code.
     *
     * Matched on the code SEGMENT rather than by building a jurisdiction code, so a
     * county and a city filing under different prefixes both resolve without this
     * having to know which prefix a state uses. Two authorities under one code in
     * one state refuse rather than guess — the same rule the name match follows.
     *
     * @return list<string>|null
     */
    private function byAuthorityCode(string $state, string $code): ?array
    {
        if ($this->dataset === null) {
            return null;
        }

        // `06:ALAMEDA` carries the state FIPS in front of the place; the authority
        // is the last segment, and the prefix is context the register already has.
        // A plain code has no colon, and `strrpos` returns FALSE there — cast to an
        // int that is a perfectly good offset, which quietly ate the first digit of
        // every Streamlined FIPS.
        $separator = strrpos($code, ':');
        $wanted = strtoupper(trim($separator === false ? $code : substr($code, $separator + 1)));
        $found = [];

        foreach (array_keys($this->dataset->namesIn('us/'.$state)) as $jurisdiction) {
            $segment = explode(':', $jurisdiction)[2] ?? null;

            if ($segment === null) {
                continue;
            }

            $tail = str_contains($segment, '-') ? substr($segment, (int) strpos($segment, '-') + 1) : $segment;

            if (strtoupper($tail) === $wanted) {
                $found[] = $jurisdiction;
            }
        }

        if (count($found) !== 1) {
            return null;
        }

        $sets = $this->postalHead($state)['sets'] ?? null;

        if (! is_array($sets) || $sets === []) {
            // NO BOUNDARY DATA TO CHECK THE CALLER AGAINST. Texas publishes none — it
            // is not a Streamlined member — and California files geometry rather than
            // a postal artifact. There the caller's single code is all anybody holds,
            // and trusting an explicit input is a different act from inventing a
            // stack around it.
            return ['us:'.$state, $found[0]];
        }

        return $this->setContaining($state, $sets, $found[0]);
    }

    /**
     * The COMPLETE authority set an authority code sits in, out of the boundary file.
     *
     * One authority code is not a stack. `sst-fips:36000` is Kansas City, and pairing
     * it with the state gave 8.125% — a confident answer missing Wyandotte County's
     * 1%, where the same address by ZIP+4 gives 9.125%. That is the under-charge
     * stamped `Authoritative` this contract names as the one outcome to prevent, and
     * it was being produced by the code that answers when the caller knows the
     * authority but not the address.
     *
     * The boundary file already holds the answer: its `sets` table is every
     * combination of authorities that occurs in the state. Where the named authority
     * appears in exactly ONE of them, that set is the stack, complete and provable —
     * 832 of Kansas' 1 015 authorities are in that position.
     *
     * Where it appears in SEVERAL, there is no right answer to give. Kansas City sits
     * in 24 distinct sets: every one includes Wyandotte County, and they differ by
     * which special districts reach that part of the city. Returning the part they
     * agree on would be a floor presented as a total — the same defect one rung
     * quieter. So this defers, and the engine prices the state share and FLAGS it,
     * which sends the operator to the address-level lookup that can actually answer.
     *
     * A state with no postal artifact (Texas publishes none) reaches neither branch.
     * There the caller's single code is all anybody holds, and trusting an explicit
     * input is different from inventing a stack around it.
     *
     * @param  array<array-key, mixed>  $sets
     * @return list<string>|null
     */
    private function setContaining(string $state, array $sets, string $authority): ?array
    {
        $matched = [];

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            $codes = [];

            foreach ($set as $entry) {
                if (! is_array($entry) || ! is_string($entry['level'] ?? null) || ! is_string($entry['code'] ?? null)) {
                    continue 2;
                }

                $codes[] = $this->code($state, new Authority(
                    level: $entry['level'],
                    code: $entry['code'],
                    type: is_string($entry['type'] ?? null) ? $entry['type'] : null,
                    jurisdiction: is_string($entry['jurisdiction'] ?? null) ? $entry['jurisdiction'] : null,
                ));
            }

            if (! in_array($authority, $codes, true)) {
                continue;
            }

            $matched[implode('|', $codes)] = array_values(array_unique($codes));

            if (count($matched) > 1) {
                // Two distinct stacks contain it. Nothing below this address-level
                // distinction can choose between them.
                return null;
            }
        }

        if ($matched === []) {
            // The file lists sets and none of them mentions this authority. That is
            // not the file contradicting the caller, it is the file being silent —
            // a district the postal artifact does not enumerate, say — so the
            // caller's assertion stands, as it does for a state with no file at all.
            return ['us:'.$state, $authority];
        }

        return array_values($matched)[0];
    }

    /**
     * A place name without the unit word, wherever it sits.
     *
     * A geocoder says "Honolulu County"; a charter says "City and County of Honolulu"
     * and "County of Maui". The unit word comes after the name in one and before it in
     * the other, so both are stripped before the names are compared.
     */
    private function bareName(string $value): string
    {
        $value = preg_replace('/^(city and county|county|city|town|borough|parish|municipality) of\s+/i', '', trim($value)) ?? $value;

        return $this->fold(preg_replace('/\s+(county|city|parish|borough)$/i', '', $value) ?? $value);
    }

    private function fold(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    /**
     * The register's jurisdiction code for a resolved authority.
     *
     * `{level, code}` in the state's own space becomes `us:KS:COUNTY-209`. The level
     * is part of the key and not decoration: a county and a special district can
     * file under the same number and levy separately, so joining on the bare code
     * merges two authorities that each want their own share.
     */
    private function code(string $state, Authority $authority): string
    {
        if ($authority->jurisdiction !== null) {
            return $authority->jurisdiction;
        }

        // THE STATE IS IN THE SET, and it is the bare state code, not a child of it.
        // Element 24 of the boundary file repeats the state FIPS where the state's
        // own rate applies and reads `00` where it does not — Nevada writes `00` on
        // every row. So its presence is the answer to "is the state share due here",
        // and mapping it to a `us:KS:STATE-20` that no rate hangs off would drop the
        // state's share from every stacked rate in the country.
        if ($authority->level === 'state') {
            return 'us:'.$state;
        }

        return sprintf('us:%s:%s-%s', $state, strtoupper($authority->level), $authority->code);
    }

    private function address(string $scheme, string $value): ?ParsedAddress
    {
        if ($scheme === LocalityScheme::Zip9->value) {
            $digits = preg_replace('/\D/', '', $value) ?? '';

            if (strlen($digits) < 5) {
                return null;
            }

            return new ParsedAddress(
                zip5: substr($digits, 0, 5),
                plus4: substr($digits, 5, 4),
                accuracy: strlen($digits) >= 9 ? Accuracy::Interpolated : Accuracy::Coarse,
            );
        }

        if ($scheme === LocalityScheme::LatLng->value) {
            [$lat, $lng] = array_pad(array_map(trim(...), explode(',', $value)), 2, null);

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                return null;
            }

            return new ParsedAddress(zip5: '', point: new Point((float) $lng, (float) $lat));
        }

        if ($scheme === LocalityScheme::Zip9AndPoint->value) {
            [$zip, $at] = array_pad(explode('@', $value, 2), 2, '');
            $postal = $this->address(LocalityScheme::Zip9->value, $zip);
            $point = $this->address(LocalityScheme::LatLng->value, $at);

            if ($postal === null) {
                return null;
            }

            return new ParsedAddress(
                zip5: $postal->zip5,
                plus4: $postal->plus4,
                point: $point?->point,
                accuracy: $postal->accuracy,
            );
        }

        return null;
    }

    private function postal(string $state, string $zip5): BoundaryData
    {
        $head = $this->postalHead($state);

        if ($head === null) {
            // No postal artifact for this state. An EMPTY BoundaryData resolves to
            // null rather than to [], which is what "we hold nothing here" means.
            return new BoundaryData;
        }

        return BoundaryData::fromArtifacts([...$head, 'zip' => $this->postalRows($state, $zip5, $head)], $this->streets($state, $zip5));
    }

    /**
     * The state's postal artifact WITHOUT its ZIP table: format version, the sets
     * every row points into, and the cross-ZIP ranges. Small, shared by every lookup
     * in the state, so it is read once per instance.
     *
     * A store compiled before the postal layer was sharded holds the artifact whole.
     * That is still read — it is what those stores have — but only to split it once.
     *
     * @return array<string, mixed>|null
     */
    private function postalHead(string $state): ?array
    {
        if (array_key_exists($state, $this->postalHeads)) {
            return $this->postalHeads[$state];
        }

        $head = $this->read($state.'.zip.head.json');

        if ($head === null) {
            $whole = $this->read($state.'.zip.json');

            if ($whole !== null) {
                $head = ['formatVersion' => $whole['formatVersion'] ?? null, 'sets' => $whole['sets'] ?? [], 'ranges' => $whole['ranges'] ?? [], 'zip' => $whole['zip'] ?? []];
            }
        }

        return $this->postalHeads[$state] = $head;
    }

    /**
     * The ZIP table narrowed to the one postcode asked about — which is all the
     * resolver consults for an address in it. A sharded store reads one record; a
     * whole artifact is narrowed in memory.
     *
     * @param  array<string, mixed>  $head
     * @return array<string, mixed>
     */
    private function postalRows(string $state, string $zip5, array $head): array
    {
        if (array_key_exists('zip', $head)) {
            $whole = Shape::map($head['zip']);

            return array_key_exists($zip5, $whole) ? [$zip5 => $whole[$zip5]] : [];
        }

        $reader = $this->postalShards[$state] ??= new ShardReader(
            $this->layout->file($this->version, 'boundaries/'.$state.'.zip'),
        );

        if (! $reader->exists() || ! $reader->has($zip5)) {
            return [];
        }

        // One record per postcode, as the compile appends it; the reader hands back the
        // list of records under a key, and the rows are the first.
        $records = $reader->read($zip5);

        return count($records) === 1 ? [$zip5 => $records[0]] : throw DatasetUnreadable::corruptShard(
            $this->layout->file($this->version, 'boundaries/'.$state.'.zip'),
            sprintf('postcode %s has %d records where the compile writes one', $zip5, count($records)),
        );
    }

    /**
     * The street layer for ONE postcode, rebuilt from the shard.
     *
     * Never the whole layer: Georgia's is 38 MB on disk and hundreds of megabytes
     * decoded, to answer a question about one ZIP. The shard holds the spans per
     * ZIP and the sets table sits beside it, so this reads two small things and
     * hands the resolver the shape it expects.
     *
     * @return array<string, mixed>|null
     */
    private function streets(string $state, string $zip5): ?array
    {
        $reader = $this->streetShards[$state] ??= new ShardReader(
            $this->layout->file($this->version, 'boundaries/'.$state.'.street'),
        );

        if (! $reader->exists()) {
            return null;
        }

        $spans = $reader->read($zip5);

        if ($spans === []) {
            return null;
        }

        $sets = $this->read($state.'.street.sets.json');

        return [
            'formatVersion' => 3,
            'sets' => $sets['sets'] ?? [],
            'street' => [$zip5 => $spans[0]],
        ];
    }

    private function geometry(string $state): ?Geometry
    {
        $json = $this->read($state.'.geo.json');

        return $json === null ? null : Geometry::fromFeatureCollection($json);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $file): ?array
    {
        $path = $this->layout->file($this->version, 'boundaries/'.$file);

        if (! is_file($path)) {
            return null;
        }

        try {
            $raw = file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $out = [];

        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
