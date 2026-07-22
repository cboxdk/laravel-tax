<?php

declare(strict_types=1);

namespace Cbox\Tax\Enums;

use Cbox\Tax\Contracts\ProductTaxability;

/**
 * The taxability class of what is being supplied. Rate sources key rates off this
 * plus the jurisdiction, and the {@see ProductTaxability} seam
 * decides whether it is taxable in a given jurisdiction.
 *
 * `Standard` is the default general-goods class (standard-rated tangible goods).
 * `DigitalService` is called out because its place-of-supply rules differ. The
 * remaining cases mirror the product categories the us-tax-data taxability dataset
 * carries, so a host can ask a state-specific question ("is candy taxable in CA?")
 * instead of collapsing everything to standard. Each case maps to a dataset
 * category key via {@see datasetCategory()}.
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
     * The us-tax-data taxability category key this class maps to. Identical to the
     * enum value for every case except `Standard`, which maps to the dataset's
     * general-tangible-goods category `goods_general`.
     */
    public function datasetCategory(): string
    {
        return $this === self::Standard ? 'goods_general' : $this->value;
    }
}
