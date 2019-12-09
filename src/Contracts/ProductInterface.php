<?php

namespace lasselehtinen\Groschen\Contracts;

use Illuminate\Support\Collection;
use DateTime;

interface ProductInterface
{
    /**
     * Get the products identifiers
     * @return Collection
     */
    public function getProductIdentifiers();

    /**
     * Get the products composition (Onix Codelist 2)
     * @return string|null
     */
    public function getProductComposition();

    /**
     * Get the products type AKA Opus binding code
     * @return string
     */
    public function getProductType();

    /**
     * Get the products form (Onix codelist 150)
     * @return string|null
     */
    public function getProductForm();

    /**
     * Get the products form detail (Onix codelist 175)
     * @return string|null
     */
    public function getProductFormDetail();

    /**
     * Get the products technical binding type
     * @return string|null
     */
    public function getTechnicalBindingType();

    /**
     * Get the products form features
     * @return Collection
     */
    public function getProductFormFeatures();

    /**
     * Get the products measures
     * @return Collection
     */
    public function getMeasures();

    /**
     * Get the products collections/series
     * @return Collection
     */
    public function getCollections();

    /**
     * Get the products title details
     * @return Collection
     */
    public function getTitleDetails();

    /**
     * Get the products contributors
     * @return Collection
     */
    public function getContributors();

    /**
     * Get the all contributors, including those that don't have Onix roles
     * @return Collection
     */
    public function getAllContributors();

    /**
     * Get the products languages
     * @return Collection
     */
    public function getLanguages();

    /**
     * Get the products extents
     * @return Collection
     */
    public function getExtents();

    /**
     * Get the products text contents
     * @return Collection
     */
    public function getTextContents();

    /**
     * Get publisher for the product
     * @return string
     */
    public function getPublisher();

    /**
     * Get the products imprints
     * @return Collection
     */
    public function getImprints();

    /**
     * Get the products brand
     * @return string
     */
    public function getBrand();

    /**
     * Get the product RRP including tax
     * @return float
     */
    public function getPrice();

    /**
     * Get the products original publication date
     * @return DateTime|null
     */
    public function getOriginalPublicationDate();

    /**
     * Get the products latest publication date
     * @return DateTime|null
     */
    public function getLatestPublicationDate();

    /**
     * Get the products subjects, like library class, Thema, BIC, BISAC etc.
     * @return Collection
     */
    public function getSubjects();

    /**
     * Get the products audience groups
     * @return Collection
     */
    public function getAudiences();

    /**
     * Get the products AudienceRanges
     * @return Collection
     */
    public function getAudienceRanges();

    /**
     * Get the products publishers and their role
     * @return Collection
     */
    public function getPublishers();

    /**
     * Get the products publishing status (Onix codelist 64)
     * @return string
     */
    public function getPublishingStatus();

    /**
     * Get the product publishing dates
     * @return Collection
     */
    public function getPublishingDates();

    /**
     * Get The products prices
     * @return Collection
     */
    public function getPrices();

    /**
     * Get the products supporting resources
     * @return Collection
     */
    public function getSupportingResources();

    /**
     * Get the related products
     * @return Collection
     */
    public function getRelatedProducts();

    /**
     * Is the product confidential?
     * @return boolean
     */
    public function isConfidential();

    /**
     * Is the product a luxury book?
     * @return boolean
     */
    public function isLuxuryBook();

    /**
     * Get the products cost center
     * @return int|null
     */
    public function getCostCenter();

    /**
     * Get the products cost center name
     * @return string|null
     */
    public function getCostCenterName();

    /**
     * Get the products media type
     * @return string
     */
    public function getMediaType();

    /**
     * Get the products binding code
     * @return string
     */
    public function getBindingCode();

    /**
     * Get the products discount group
     * @return int|null
     */
    public function getDiscountGroup();

    /**
     * Get the products status
     * @return string
     */
    public function getStatus();

    /**
     * Get the products status code
     * @return int
     */
    public function getStatusCode();

    /**
     * Get the number of products in the series
     * @return int|null
     */
    public function getProductsInSeries();

    /**
     * Is the product immaterial?
     * @return boolean
     */
    public function isImmaterial();

    /**
     * Is the product a Print On Demand product?
     * @return boolean
     */
    public function isPrintOnDemand();

    /**
     * Get internal product number
     * @return string|null
     */
    public function getInternalProdNo();

    /**
     * Get customs number
     * @return int
     */
    public function getCustomsNumber();

    /**
     * Get the products library class
     * @return string|null
     */
    public function getLibraryClass();

    /**
     * Get the products marketing category
     * @return string|null
     */
    public function getMarketingCategory();

    /**
     * Get the products sales season
     * @return string|null
     */
    public function getSalesSeason();

    /**
     * Get the latest stock arrival date
     * @return DateTime|null
     */
    public function getLatestStockArrivalDate();

    /**
     * Get the latest print number
     * @return int|null
     */
    public function getLatestPrintNumber();

    /**
     * Get the sales restrictions
     * @return Collection
     */
    public function getSalesRestrictions();

    /**
     * Get the rights and distribution for each channel
     * @return Collection
     */
    public function getDistributionChannels();

    /**
     * Is the product connected to ERP?
     * @return boolean
     */
    public function isConnectedToErp();

    /**
     * Get the products print orders
     * @return Collection
     */
    public function getPrintOrders();

    /**
     * Is the product "Main edition"?
     * @return boolean
     */
    public function isMainEdition();

    /**
     * Is the product "Internet edition"?
     * @return boolean
     */
    public function isInternetEdition();

    /**
     * Get the products production plan
     * @return Collection
     */
    public function getProductionPlan();

    /**
     * Get the technical description comment
     * @return string|null
     */
    public function getTechnicalDescriptionComment();

    /**
     * Get the products technical printing data
     * @return Collection
     */
    public function getTechnicalData();

    /**
     * Get the prizes that the product has received
     * @return Collection
     */
    public function getPrizes();

    /**
     * Get products availability code
     * @return string|null
     */
    public function getProductAvailability();

    /**
     * Check if original publication date has passed
     * @return bool
     */
    public function isPublicationDatePassed();

    /**
     * Get the products stocks
     * @return Collection
     */
    public function getStocks();

    /**
     * Get the supply dates
     * @return Collection
     */
    public function getSupplyDates();

    /**
     * Get all contacts
     * @return Collection
     */
    public function getContacts();

    /**
     * Get all editions
     * @return Collection
     */
    public function getEditions();

    /**
     * Get the first publication date of the products web page
     * @return DateTime|null
     */
    public function getWebPublishingStartDate();

    /**
     * Get the end date for the products web page
     * @return DateTime|null
     */
    public function getWebPublishingEndDate();

    /**
     * Get all comments
     * @return Collection
     */
    public function getComments();
}
