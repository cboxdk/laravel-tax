<?php

declare(strict_types=1);

namespace Cbox\Tax\Register\Reader;

use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\ValueObjects\TaxQuery;

/**
 * Where each {@see TaxClass} lands in the register's category vocabulary.
 *
 * THIS MAP IS LOSSY, AND KNOWING WHERE IS THE POINT OF WRITING IT DOWN. The engine
 * has 56 classes; 116 categories carry a live rate. So sixty distinctions the
 * register makes cannot be said in a `TaxClass` at all — `goods.medical_equipment`
 * has fourteen children (dialysis, oxygen, enteral feeding, breast pumps and their
 * supplies and their kits, hearing aids, prostheses) against one `MedicalDevice`,
 * and several US states split on exactly those lines.
 *
 * The answer is not to grow the enum. `TaxClass` was derived from a retired
 * dataset's 87 headings and is a hand-maintained copy of a vocabulary somebody else
 * now publishes, versions, and ships catalogue mappings into. The public API still
 * accepts TaxClass, with a commodity code to refine it within its mapped category,
 * and a query may name the register's own key instead — `categoryKey` on
 * {@see TaxQuery} — validated against the installed release.
 *
 * Where a class has no counterpart it maps to the nearest PARENT rather than to a
 * sibling that is nearly right. A parent is honestly coarse; a near-sibling is
 * confidently wrong, and the second one prices an invoice.
 */
final class CategoryMap
{
    /** The class every unmapped item falls to, and the reason it is safe to. */
    public const string FALLBACK = 'goods';

    /** @var array<string, TaxClass>|null */
    private static ?array $reverse = null;

    public static function keyFor(TaxClass $class): string
    {
        return match ($class) {
            TaxClass::Groceries => 'goods.food.basic',
            TaxClass::PreparedFood => 'goods.food.prepared',
            TaxClass::Candy => 'goods.food.candy',
            TaxClass::SoftDrinks => 'goods.food.soft_drinks',
            TaxClass::DietarySupplements => 'goods.food.dietary_supplements',
            TaxClass::Wine => 'goods.beverages.alcoholic',

            TaxClass::Book => 'goods.publications.book',
            TaxClass::Newspaper => 'goods.publications.newspaper',
            TaxClass::Periodical => 'goods.publications.periodical',
            // Printed advertising has no category of its own. It is NOT
            // `services.digital.advertising` — that is advertising supplied
            // electronically, and the register corrected exactly this confusion in
            // its own data. The parent is the honest answer.
            TaxClass::AdvertisingPrint => 'goods.publications',

            TaxClass::PrescriptionMedicine => 'goods.medicine.prescription',
            TaxClass::OtcMedicine => 'goods.medicine.otc',
            // Fourteen children collapse here. See the class docblock.
            TaxClass::MedicalDevice => 'goods.medical_equipment',
            TaxClass::MedicalCare => 'services.medical',

            TaxClass::Accommodation => 'services.accommodation',
            TaxClass::PassengerTransport => 'services.passenger_transport',

            TaxClass::CulturalAdmission => 'services.cultural_admission',
            TaxClass::SportingAdmission => 'services.sporting_admission',
            TaxClass::Broadcasting => 'services.broadcasting',

            // The register files original art and antiques together; the engine
            // separates them. Both resolve to the one category that exists.
            TaxClass::ArtOriginal, TaxClass::Antique => 'goods.art',

            TaxClass::Water => 'goods.water',
            // Electricity, gas, district heating and firewood are one category in
            // the register. The engine's four classes are finer than the data.
            TaxClass::Electricity, TaxClass::Gas, TaxClass::Heating, TaxClass::Firewood => 'goods.energy',
            TaxClass::FossilFuel => 'goods.energy',
            TaxClass::RenewableEnergy => 'goods.solar_panels',

            TaxClass::Housing => 'services.construction',
            TaxClass::Renovation => 'services.housing_renovation',

            TaxClass::PersonalCare => 'services.personal_care',
            TaxClass::RepairService => 'services.repair',
            TaxClass::CleaningService => 'services.cleaning',
            TaxClass::WasteTreatment => 'services.waste',
            TaxClass::SocialCare => 'services.domestic_care',
            TaxClass::AuthorshipService => 'services.authors',
            TaxClass::FuneralService => 'services.funeral',
            TaxClass::PostalService => 'services.delivery',
            TaxClass::FinancialAdmin => 'services.financial',
            TaxClass::ProfessionalService => 'services.professional',
            TaxClass::DataProcessing => 'services.data_processing',
            TaxClass::WebHosting => 'services.digital.hosting',
            TaxClass::AiApi => 'services.digital.ai',

            TaxClass::AgriculturalInput => 'goods.agricultural_inputs',
            // Live plants, cut flowers and livestock are produce, not inputs, and
            // the register has no category for them. Up to the root rather than
            // across to inputs, which carries a different rate in several states.
            TaxClass::AgriculturalProduce => 'goods',

            TaxClass::Clothing, TaxClass::Footwear => 'goods.clothing',
            TaxClass::ChildCarSeat => 'goods.child_seats',
            TaxClass::Bicycle => 'goods.bicycles',
            // Nothing reduces general goods, electronics or furniture anywhere, so
            // the root is not a loss here — it is the whole answer.
            TaxClass::GeneralGoods, TaxClass::Electronics, TaxClass::Furniture => self::FALLBACK,

            TaxClass::DigitalService => 'services.digital',
            TaxClass::DigitalProduct => 'goods.digital_products',
            TaxClass::SoftwarePrewritten => 'goods.software.prewritten',
            TaxClass::SoftwareCustom => 'goods.software.custom',
        };
    }

    /**
     * The class that governs LEGAL behaviour for a register category key — which
     * place-of-supply article applies — when the caller named a key and no class.
     *
     * The key decides the rate; the class decides where the supply happens, and the
     * register's vocabulary does not say that. So this takes the nearest class whose
     * own key is at or above this one: `goods.medical_equipment.prosthetic` is governed
     * as a medical device, `goods.clothing.childrens` as clothing. Classes that share a
     * key all share a place-of-supply rule, so which of them is taken does not matter.
     *
     * A service with no mapped ancestor — education, insurance, restaurant — falls to
     * the Directive's GENERAL rule for B2C services, Art. 45, the supplier's
     * establishment. That is the default the law itself applies when nothing more
     * specific does. A caller who knows a specific article governs passes the class.
     */
    public static function governing(string $key): TaxClass
    {
        if (self::$reverse === null) {
            $reverse = [self::FALLBACK => TaxClass::GeneralGoods];

            foreach (TaxClass::cases() as $class) {
                $reverse[self::keyFor($class)] ??= $class;
            }

            self::$reverse = $reverse;
        }

        foreach (self::ladder($key) as $rung) {
            if (isset(self::$reverse[$rung])) {
                return self::$reverse[$rung];
            }
        }

        return str_starts_with($key, 'services.') ? TaxClass::ProfessionalService : TaxClass::GeneralGoods;
    }

    /**
     * A category and every ancestor above it, most specific first.
     *
     * The ladder a resolver walks when nothing is published at the exact key —
     * upward only, and only while each step still has one answer. Going up makes a
     * question broader, so it can only ever be tried after the specific one failed.
     *
     * @return list<string>
     */
    public static function ladder(string $key): array
    {
        $rungs = [$key];
        $parts = explode('.', $key);

        while (count($parts) > 1) {
            array_pop($parts);
            $rungs[] = implode('.', $parts);
        }

        return $rungs;
    }
}
