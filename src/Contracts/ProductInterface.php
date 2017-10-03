<?php

namespace lasselehtinen\Groschen\Contracts;

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

    // TODO
    // ResourceVersionFeatures to SupportingResources
}
