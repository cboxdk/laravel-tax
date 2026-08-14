<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

/**
 * The engine's former product classification, kept as a translator.
 *
 * @deprecated Use {@see TaxClass}. This exists so a caller already storing these
 * values on their products can migrate a field at a time rather than in one
 * release, and it will be removed once that migration has had time to happen.
 *
 * Why it was replaced, briefly, because the reason matters more than the rename:
 * these 25 cases were derived from the US question — *is this taxable in this
 * state*, a boolean per (state, category) — and then reused for the EU, which asks
 * *which band* against a schedule of 87 headings. Measured against the Commission's
 * own published rates for all 27 Member States, this list reached 23% of the bands;
 * {@see TaxClass} reaches 98%. The gap was not sloppiness, it was a list answering
 * the wrong question.
 */
enum TaxCategory: string
{
    case Standard = 'standard';
    case DigitalService = 'digital_service';
    case DigitalProducts = 'digital_products';
    case Clothing = 'clothing';
    case Grocery = 'grocery';
    case PreparedFood = 'prepared_food';
    case Candy = 'candy';
    case SoftDrinks = 'soft_drinks';
    case PrescriptionDrugs = 'prescription_drugs';
    case OtcDrugs = 'otc_drugs';
    case DietarySupplements = 'dietary_supplements';
    case MedicalDevices = 'medical_devices';
    case GoodsElectronics = 'goods_electronics';
    case GoodsFurniture = 'goods_furniture';
    case Books = 'books';
    case Magazines = 'magazines';
    case Newspapers = 'newspapers';
    case SoftwarePrewritten = 'software_prewritten';
    case SoftwareCustom = 'software_custom';
    case ServicesProfessional = 'services_professional';
    case ServicesRepair = 'services_repair';
    case ServicesDataProcessing = 'services_data_processing';
    case ServicesPersonalCare = 'services_personal_care';
    case ServicesWebHosting = 'services_web_hosting';
    case ServicesAiApi = 'services_ai_api';

    /**
     * The class this category becomes.
     *
     * Every case maps to exactly one class and none is dropped, so a stored value
     * always converts. Clothing maps to {@see TaxClass::Clothing} rather than
     * splitting into footwear: the old list did not distinguish them, and inventing
     * a distinction during a migration would silently reclassify a merchant's shoes.
     */
    public function toClass(): TaxClass
    {
        return match ($this) {
            self::Standard => TaxClass::GeneralGoods,
            self::DigitalService => TaxClass::DigitalService,
            self::DigitalProducts => TaxClass::DigitalProduct,
            self::Clothing => TaxClass::Clothing,
            self::Grocery => TaxClass::Groceries,
            self::PreparedFood => TaxClass::PreparedFood,
            self::Candy => TaxClass::Candy,
            self::SoftDrinks => TaxClass::SoftDrinks,
            self::PrescriptionDrugs => TaxClass::PrescriptionMedicine,
            self::OtcDrugs => TaxClass::OtcMedicine,
            self::DietarySupplements => TaxClass::DietarySupplements,
            self::MedicalDevices => TaxClass::MedicalDevice,
            self::GoodsElectronics => TaxClass::Electronics,
            self::GoodsFurniture => TaxClass::Furniture,
            self::Books => TaxClass::Book,
            self::Magazines => TaxClass::Periodical,
            self::Newspapers => TaxClass::Newspaper,
            self::SoftwarePrewritten => TaxClass::SoftwarePrewritten,
            self::SoftwareCustom => TaxClass::SoftwareCustom,
            self::ServicesProfessional => TaxClass::ProfessionalService,
            self::ServicesRepair => TaxClass::RepairService,
            self::ServicesDataProcessing => TaxClass::DataProcessing,
            self::ServicesPersonalCare => TaxClass::PersonalCare,
            self::ServicesWebHosting => TaxClass::WebHosting,
            self::ServicesAiApi => TaxClass::AiApi,
        };
    }
}
