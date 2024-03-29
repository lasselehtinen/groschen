<?php

namespace lasselehtinen\Groschen\Contracts;

use DateTime;
use Illuminate\Support\Collection;

interface ProductInterface
{
    /**
     * Returns the editions work id
     *
     * @return int
     */
    public function getWorkId();

    /**
     * Returns the editions id
     *
     * @return int
     */
    public function getEditionId();

    /**
     * Get the products identifiers
     *
     * @return Collection
     */
    public function getProductIdentifiers();

    /**
     * Get the products composition (Onix Codelist 2)
     *
     * @return string|null
     */
    public function getProductComposition();

    /**
     * Get the products type aka Mockingbird binding code
     *
     * @return string
     */
    public function getProductType();

    /**
     * Get the products form (Onix codelist 150)
     *
     * @return string|null
     */
    public function getProductForm();

    /**
     * Get the products form detail (Onix codelist 175)
     *
     * @return Collection
     */
    public function getProductFormDetails();

    /**
     * Get the products technical binding type
     *
     * @return string|null
     */
    public function getTechnicalBindingType();

    /**
     * Get the products form features
     *
     * @return Collection
     */
    public function getProductFormFeatures();

    /**
     * Get the products measures
     *
     * @return Collection
     */
    public function getMeasures();

    /**
     * Get the products collections/series
     *
     * @return Collection
     */
    public function getCollections();

    /**
     * Get the products title details
     *
     * @return Collection
     */
    public function getTitleDetails();

    /**
     * Get the products contributors
     *
     * @return Collection
     */
    public function getContributors();

    /**
     * Get the all contributors, including those that don't have Onix roles
     *
     * @return Collection
     */
    public function getAllContributors();

    /**
     * Get the products languages
     *
     * @return Collection
     */
    public function getLanguages();

    /**
     * Get the products extents
     *
     * @return Collection
     */
    public function getExtents();

    /**
     * Get the products estimated number of pages
     *
     * @return int|null
     */
    public function getEstimatedNumberOfPages();

    /**
     * Get the products number of characters
     *
     * @return int|null
     */
    public function getNumberOfCharacters();

    /**
     * Get the products text contents
     *
     * @return Collection
     */
    public function getTextContents();

    /**
     * Get publisher for the product
     *
     * @return string
     */
    public function getPublisher();

    /**
     * Get the products imprints
     *
     * @return Collection
     */
    public function getImprints();

    /**
     * Get the products brand
     *
     * @return string
     */
    public function getBrand();

    /**
     * Get the product RRP including tax
     *
     * @return float
     */
    public function getPrice();

    /**
     * Get the products original publication date
     *
     * @return DateTime|null
     */
    public function getOriginalPublicationDate();

    /**
     * Get the products latest publication date
     *
     * @return DateTime|null
     */
    public function getLatestPublicationDate();

    /**
     * Get the products subjects, like library class, Thema, BIC, BISAC etc.
     *
     * @return Collection
     */
    public function getSubjects();

    /**
     * Get the products audience groups
     *
     * @return Collection
     */
    public function getAudiences();

    /**
     * Get the products AudienceRanges
     *
     * @return Collection
     */
    public function getAudienceRanges();

    /**
     * Get the products publishers and their role
     *
     * @return Collection
     */
    public function getPublishers();

    /**
     * Get the products publishing status (Onix codelist 64)
     *
     * @return string
     */
    public function getPublishingStatus();

    /**
     * Get the product publishing dates
     *
     * @return Collection
     */
    public function getPublishingDates();

    /**
     * Get The products prices
     *
     * @return Collection
     */
    public function getPrices();

    /**
     * Get the products supporting resources
     *
     * @return Collection
     */
    public function getSupportingResources();

    /**
     * Get the related products
     *
     * @return Collection
     */
    public function getRelatedProducts();

    /**
     * Get the related works
     *
     * @return Collection
     */
    public function getRelatedWorks();

    /**
     * Is the product confidential?
     *
     * @return bool
     */
    public function isConfidential();

    /**
     * Is the product a luxury book?
     *
     * @return bool
     */
    public function isLuxuryBook();

    /**
     * Get the products cost center
     *
     * @return int|null
     */
    public function getCostCenter();

    /**
     * Get the products cost center name
     *
     * @return string|null
     */
    public function getCostCenterName();

    /**
     * Get the products media type
     *
     * @return string
     */
    public function getMediaType();

    /**
     * Get the products binding code
     *
     * @return string
     */
    public function getBindingCode();

    /**
     * Get the products discount group
     *
     * @return int|null
     */
    public function getDiscountGroup();

    /**
     * Get the products status
     *
     * @return string
     */
    public function getStatus();

    /**
     * Get the number of products in the series
     *
     * @return int|null
     */
    public function getProductsInSeries();

    /**
     * Is the product immaterial?
     *
     * @return bool
     */
    public function isImmaterial();

    /**
     * Is the product a Print On Demand product?
     *
     * @return bool
     */
    public function isPrintOnDemand();

    /**
     * Get internal product number
     *
     * @return string|null
     */
    public function getInternalProdNo();

    /**
     * Get customs number
     *
     * @return int
     */
    public function getCustomsNumber();

    /**
     * Get the products library class
     *
     * @return string|null
     */
    public function getLibraryClass();

    /**
     * Get the products marketing category
     *
     * @return string|null
     */
    public function getMarketingCategory();

    /**
     * Get the products sales season
     *
     * @return string|null
     */
    public function getSalesSeason();

    /**
     * Get the products backlist sales season
     *
     * @return string|null
     */
    public function getBacklistSalesSeason();

    /**
     * Get the latest stock arrival date
     *
     * @return DateTime|null
     */
    public function getLatestStockArrivalDate();

    /**
     * Get the latest print number
     *
     * @return int|null
     */
    public function getLatestPrintNumber();

    /**
     * Get the sales rights territories
     *
     * @return Collection
     */
    public function getSalesRightsTerritories();

    /**
     * Get the sales restrictions
     *
     * @return Collection
     */
    public function getSalesRestrictions();

    /**
     * Get the rights and distribution for each channel
     *
     * @return Collection
     */
    public function getDistributionChannels();

    /**
     * Is the product connected to ERP?
     *
     * @return bool
     */
    public function isConnectedToErp();

    /**
     * Get the products print orders
     *
     * @return Collection
     */
    public function getPrintOrders();

    /**
     * Is the product "Main edition"?
     *
     * @return bool
     */
    public function isMainEdition();

    /**
     * Is the product "Internet edition"?
     *
     * @return bool
     */
    public function isInternetEdition();

    /**
     * Get the products production plan
     *
     * @return Collection
     */
    public function getProductionPlan();

    /**
     * Get the technical description comment
     *
     * @return string|null
     */
    public function getTechnicalDescriptionComment();

    /**
     * Get the products technical printing data
     *
     * @return Collection
     */
    public function getTechnicalData();

    /**
     * Get the prizes that the product has received
     *
     * @return Collection
     */
    public function getPrizes();

    /**
     * Get products availability code
     *
     * @return string|null
     */
    public function getProductAvailability();

    /**
     * Check if original publication date has passed
     *
     * @return bool
     */
    public function isPublicationDatePassed();

    /**
     * Get the products suppliers
     *
     * @return Collection
     */
    public function getSuppliers();

    /**
     * Get the supply dates
     *
     * @return Collection
     */
    public function getSupplyDates();

    /**
     * Get all contacts
     *
     * @return Collection
     */
    public function getContacts();

    /**
     * Get all editions
     *
     * @return Collection
     */
    public function getEditions();

    /**
     * Get the first publication date of the products web page
     *
     * @return DateTime|null
     */
    public function getWebPublishingStartDate();

    /**
     * Get the end date for the products web page
     *
     * @return DateTime|null
     */
    public function getWebPublishingEndDate();

    /**
     * Get all comments
     *
     * @return Collection
     */
    public function getComments();

    /**
     * Get products sales status
     *
     * @return string|null
     */
    public function getSalesStatus();

    /**
     * Get the products main editions ISBN
     *
     * @return int|null
     */
    public function getMainEditionIsbn();

    /**
     * Get the products main editions cost center
     *
     * @return int|null
     */
    public function getMainEditionCostCenter();

    /**
     * Check if the product is translated or not
     *
     * @return bool
     */
    public function isTranslated();

    /**
     * Get all EditionTypes
     *
     * @return Collection
     */
    public function getEditionTypes();

    /**
     * Get all Events
     *
     * @return Collection
     */
    public function getEvents();

    /**
     * Get the retail price multipler
     *
     * @return float
     */
    public function getRetailPriceMultiplier();

    /**
     * Get all ProductContentTypes
     *
     * @return Collection
     */
    public function getProductContentTypes();

    /**
     * Get the NotificationType
     *
     * @return string
     */
    public function getNotificationType();

    /**
     * Getting the country of manufacture. Returns two letter ISO 3166-1 code.
     *
     * @return string|null
     */
    public function getCountryOfManufacture();

    /**
     * Get the products trade category
     *
     * @return string|null
     */
    public function getTradeCategory();

    /**
     * Get the products names as subjects
     *
     * @return Collection
     */
    public function getNamesAsSubjects();

    /**
     * Get the pocket book price group
     *
     * @return string|null
     */
    public function getPocketBookPriceGroup();

    /**
     * Get the target persona
     *
     * @return Collection
     */
    public function getTargetPersonas();

    /**
     * Get the editions planning code
     *
     * @return string|null
     */
    public function getPlanningCode();
}
