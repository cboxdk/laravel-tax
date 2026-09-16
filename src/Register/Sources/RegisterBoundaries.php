<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Sources;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Enums\LocalityScheme;
use Cbox\Tax\Register\Reader\RegisterDataset;
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
final class RegisterBoundaries implements LocalAuthorityResolver
{
    /** @var array<string, ShardReader> */
    private array $streetShards = [];

    public function __construct(
        private StoreLayout $layout,
        private string $version,
        private ?RegisterDataset $dataset = null,
        private Resolver $resolver = new Resolver,
    ) {}

    /**
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

        return $authorities === null
            ? null
            : array_map(fn (Authority $authority): string => $this->code($state, $authority), $authorities);
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
        } catch (UnsupportedFormatVersion) {
            // The store holds an artifact this resolver cannot read. Deferring sends
            // the engine to the state rate, which is short but honest; reading it
            // anyway is how you get a confident answer that is wrong.
            return null;
        }

        return $assignment->resolved() ? ($assignment->authorities ?? []) : null;
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
        if ($this->dataset === null || ! in_array('US-'.$state, UsLocalStructure::countyResolvedStates(), true)) {
            return null;
        }

        $names = $this->dataset->namesIn('us/'.$state);
        $wanted = $this->fold($county);
        $bare = $this->fold(preg_replace('/\s+(county|city|parish|borough)$/i', '', $county) ?? $county);
        $exact = null;
        $loose = [];

        foreach ($names as $code => $name) {
            $folded = $this->fold($name);

            if ($folded === $wanted) {
                $exact = $code;

                break;
            }

            if ($folded === $bare || $this->fold(preg_replace('/\s+(county|city|parish|borough)$/i', '', $name) ?? $name) === $bare) {
                $loose[] = $code;
            }
        }

        if ($exact !== null) {
            return ['us:'.$state, $exact];
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

        // Two localities answer to the same bare name and nothing said which. Refusing
        // sends the caller to the state rate; guessing bills the wrong authority.
        if (count($loose) !== 1) {
            return null;
        }

        return ['us:'.$state, $loose[0]];
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

        return count($found) === 1 ? ['us:'.$state, $found[0]] : null;
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

        return null;
    }

    private function postal(string $state, string $zip5): BoundaryData
    {
        $main = $this->read($state.'.zip.json');

        if ($main === null) {
            // No postal artifact for this state. An EMPTY BoundaryData resolves to
            // null rather than to [], which is what "we hold nothing here" means.
            return new BoundaryData;
        }

        return BoundaryData::fromArtifacts($main, $this->streets($state, $zip5));
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
