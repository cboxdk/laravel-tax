<?php

declare(strict_types=1);

namespace Cbox\Tax\RateSource;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Enums\Confidence;
use Cbox\Tax\Enums\RateKind;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\ValueObjects\TaxRate;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Reads EU VAT rates LIVE from the Commission's *Taxes in Europe Database* — its
 * `VatRetrievalService` SOAP endpoint, called directly. This exists because TEDB
 * publishes no downloadable export: the SOAP service and the web UI are the only
 * ways to get the data, so an adapter that expects a file (see {@see TedbRateSource})
 * cannot be used without the operator first building an ETL of their own.
 *
 * The service needs no key and no registration. One call covers one member state
 * for one date; the parsed result is cached (when a cache repository is supplied)
 * so a lookup costs one request per country per TTL, not one per assessment.
 *
 * What is taken, and what is deliberately not:
 *
 *  - The **standard rate** is the `STANDARD`/`DEFAULT` entry — exactly one per
 *    member state, so it is unambiguous and always used.
 *  - A **reduced band** is taken only when the categories mapped in {@see BANDS}
 *    resolve to EXACTLY ONE rate for that country. TEDB routinely carries a
 *    category at several rates (French pharmaceuticals sit at 2.1%, 5.5% and 10%
 *    at once, because the sub-scopes differ), and no rule in the response says
 *    which applies to a given supply. Where that happens the band is dropped and
 *    the standard rate applies — over-charging is recoverable, silently applying
 *    the wrong reduced rate is not.
 *  - Categories with no confident equivalent — notably digital services and
 *    e-publications, whose treatment TEDB folds into other categories in some
 *    states — are not mapped at all rather than guessed.
 *
 * Deny-by-default: an unreachable service, a SOAP fault, unparseable XML or a
 * member state the response does not carry all yield `null`, so a composed
 * {@see ChainTaxRateSource} falls through rather than the engine guessing.
 */
readonly class TedbSoapRateSource implements TaxRateSource
{
    public const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/';

    private const string TYPES_NS = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types';

    private const string SOAP_ACTION = 'urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates';

    private const string SOURCE = 'tedb';

    /** Sentinel for a category TEDB rates as not-applicable or out-of-scope. */
    private const string UNUSABLE = 'n/a';

    /**
     * The member states TEDB answers for. Greece is `EL` here, not the ISO `GR` —
     * TEDB rejects the whole request with `TEDB-ERR-2` if any code is unknown, so
     * the translation happens before the call (VIES has the same quirk).
     *
     * @var list<string>
     */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    ];

    /**
     * Our taxability categories mapped to the TEDB category identifiers that mean
     * the same supply. Kept deliberately small: a mapping is only listed where the
     * Annex III category and ours describe the same thing without interpretation.
     *
     * Each entry is a list of TIERS tried in order, most specific first. Member
     * states differ in how finely they split Annex III point 6: Poland rates
     * NEWSPAPERS on its own, while Sweden files newspapers under the broader
     * LOAN_LIBRARIES ("books, newspapers and periodicals, on physical means of
     * support or supplied electronically"). Trying the specific tier first keeps
     * Poland's own newspaper rate; falling through to the broad one keeps Sweden's.
     *
     * @var array<string, list<list<string>>>
     */
    private const BANDS = [
        TaxCategory::Grocery->value => [['FOODSTUFFS']],
        TaxCategory::PreparedFood->value => [['RESTAURANT', 'FOOD_SERVICE']],
        TaxCategory::Books->value => [['BOOKS'], ['LOAN_LIBRARIES']],
        TaxCategory::Newspapers->value => [['NEWSPAPERS'], ['LOAN_LIBRARIES']],
        TaxCategory::Magazines->value => [['PERIODICALS'], ['LOAN_LIBRARIES']],
        TaxCategory::MedicalDevices->value => [['MEDICAL_EQUIPMENT']],
        TaxCategory::PrescriptionDrugs->value => [['PHARMACEUTICAL_PRODUCTS']],
    ];

    public function __construct(
        private Factory $http,
        private ?Repository $cache = null,
        private int $ttl = 86400,
        private string $endpoint = self::ENDPOINT,
    ) {}

    public function rateFor(
        Jurisdiction $jurisdiction,
        TaxCategory $category,
        ?DateTimeImmutable $at = null,
    ): ?TaxRate {
        $country = $jurisdiction->country->value;

        if (! in_array($country, self::EU, true)) {
            return null;
        }

        $table = $this->table($country, ($at ?? new DateTimeImmutable)->format('Y-m-d'));

        if ($table === null) {
            return null;
        }

        $band = $table['bands'][$category->value] ?? null;

        if (is_string($band)) {
            $kind = $band === '0' ? RateKind::Zero : RateKind::Reduced;

            return new TaxRate($band, $kind, self::SOURCE, Confidence::Authoritative);
        }

        $standard = $table['standard'] ?? null;

        if (! is_string($standard)) {
            return null;
        }

        return new TaxRate($standard, RateKind::Standard, self::SOURCE, Confidence::Authoritative);
    }

    /**
     * The parsed rate table for one member state on one date, from cache when a
     * repository is bound. A failed call is not cached — a transient outage must
     * not pin a null for the whole TTL.
     *
     * @return array{standard: string|null, bands: array<string, string>}|null
     */
    private function table(string $country, string $on): ?array
    {
        $key = 'cbox-tax:tedb:'.$country.':'.$on;
        $cached = $this->cache?->get($key);

        if (is_array($cached) && array_key_exists('standard', $cached) && is_array($cached['bands'] ?? null)) {
            /** @var array{standard: string|null, bands: array<string, string>} $cached */
            return $cached;
        }

        // TEDB spells Greece EL; an unknown code faults the entire request.
        $isoCode = $country === 'GR' ? 'EL' : $country;

        $xml = $this->call($isoCode, $on);

        if ($xml === null) {
            return null;
        }

        $table = $this->parse($xml, $isoCode);

        if ($table !== null) {
            $this->cache?->put($key, $table, $this->ttl);
        }

        return $table;
    }

    /** The raw SOAP response body, or null on any transport-level failure. */
    private function call(string $isoCode, string $on): ?string
    {
        $envelope = sprintf(
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            .' xmlns:urn="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService"'
            .' xmlns:typ="%s"><soapenv:Body><urn:retrieveVatRatesReqMsg>'
            .'<typ:memberStates><typ:isoCode>%s</typ:isoCode></typ:memberStates>'
            .'<typ:situationOn>%s</typ:situationOn>'
            .'</urn:retrieveVatRatesReqMsg></soapenv:Body></soapenv:Envelope>',
            self::TYPES_NS,
            $isoCode,
            $on,
        );

        try {
            $response = $this->http
                ->withHeaders([
                    'Content-Type' => 'text/xml;charset=UTF-8',
                    'SOAPAction' => self::SOAP_ACTION,
                ])
                ->withBody($envelope, 'text/xml;charset=UTF-8')
                ->post($this->endpoint);
        } catch (Throwable) {
            return null;
        }

        // A SOAP fault answers 500 with a fault body; both are refusals here.
        return $response->successful() ? $response->body() : null;
    }

    /**
     * Reduce a response to one standard rate plus the unambiguous reduced bands.
     *
     * @return array{standard: string|null, bands: array<string, string>}|null
     */
    private function parse(string $xml, string $isoCode): ?array
    {
        $document = new DOMDocument;

        // LIBXML_NONET refuses network fetches during parsing; entity substitution
        // stays off, so a hostile response cannot reach out or expand.
        if (@$document->loadXML($xml, LIBXML_NONET) === false) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('t', self::TYPES_NS);

        $results = $xpath->query('//t:vatRateResults');

        if ($results === false || $results->length === 0) {
            return null;
        }

        $standard = null;

        /** @var array<string, list<string>> $byCategory */
        $byCategory = [];

        foreach ($results as $result) {
            if (! $result instanceof DOMElement) {
                continue;
            }

            // A response can carry several member states; only this one's rows
            // may contribute, or a multi-country reply would overwrite the rate.
            if ($this->text($xpath, 't:memberState', $result) !== $isoCode) {
                continue;
            }

            $type = $this->text($xpath, 't:rate/t:type', $result);
            $value = $this->text($xpath, 't:rate/t:value', $result);

            if ($value === null) {
                continue;
            }

            if ($type === 'DEFAULT') {
                $standard = $this->percent($value);

                continue;
            }

            // EXEMPTED and PARKING_RATE compete with the reduced rates for the same
            // category and must be counted, or a category that is zero-rated for
            // print and reduced for its electronic form would resolve to whichever
            // row happened to be read. NOT_APPLICABLE / OUT_OF_SCOPE carry no usable
            // rate, so they poison the category outright.
            if ($type === 'NOT_APPLICABLE' || $type === 'OUT_OF_SCOPE') {
                foreach ($this->identifiers($xpath, $result) as $identifier) {
                    $byCategory[$identifier][] = self::UNUSABLE;
                }

                continue;
            }

            if ($type !== 'REDUCED_RATE' && $type !== 'SUPER_REDUCED_RATE'
                && $type !== 'EXEMPTED' && $type !== 'PARKING_RATE') {
                continue;
            }

            foreach ($this->identifiers($xpath, $result) as $identifier) {
                $byCategory[$identifier][] = $this->percent($value);
            }
        }

        if ($standard === null && $byCategory === []) {
            return null;
        }

        return ['standard' => $standard, 'bands' => $this->bands($byCategory)];
    }

    /**
     * Keep a band only where every rate TEDB carries for the mapped categories is
     * the same one — several distinct rates means the response cannot say which
     * applies, so the standard rate is used instead.
     *
     * @param  array<string, list<string>>  $byCategory
     * @return array<string, string>
     */
    private function bands(array $byCategory): array
    {
        $bands = [];

        foreach (self::BANDS as $category => $tiers) {
            foreach ($tiers as $identifiers) {
                $rates = [];

                foreach ($identifiers as $identifier) {
                    foreach ($byCategory[$identifier] ?? [] as $rate) {
                        $rates[$rate] = true;
                    }
                }

                if ($rates === []) {
                    continue;
                }

                // The tier is present: it decides, whether it resolves or not.
                // Falling through to a broader tier after an AMBIGUOUS specific one
                // would answer a question the state deliberately split.
                if (count($rates) === 1 && ! array_key_exists(self::UNUSABLE, $rates)) {
                    $bands[$category] = (string) array_key_first($rates);
                }

                break;
            }
        }

        return $bands;
    }

    /**
     * The category identifiers a result is tagged with.
     *
     * @return list<string>
     */
    private function identifiers(DOMXPath $xpath, DOMElement $result): array
    {
        $nodes = $xpath->query('t:category/t:identifier', $result);

        if ($nodes === false) {
            return [];
        }

        $identifiers = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $identifiers[] = $node->textContent;
            }
        }

        return $identifiers;
    }

    private function text(DOMXPath $xpath, string $expression, DOMElement $context): ?string
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $first = $nodes->item(0);

        return $first instanceof DOMElement ? trim($first->textContent) : null;
    }

    /** TEDB serialises rates as doubles ("25.0", "2.1"); normalise the trailing zero. */
    private function percent(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
