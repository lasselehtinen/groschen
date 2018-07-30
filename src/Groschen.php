<?php
namespace lasselehtinen\Groschen;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Collection;
use Isbn;
use lasselehtinen\Groschen\Contracts\ProductInterface;
use LasseLehtinen\SchillingSoapWrapper\Services\Lookup;
use LasseLehtinen\SchillingSoapWrapper\Services\Product;
use LasseLehtinen\SchillingSoapWrapper\Services\Project;
use LasseLehtinen\SchillingSoapWrapper\Services\TextHandling;
use League\Uri\Modifiers\MergeQuery;
use League\Uri\Modifiers\RemoveQueryKeys;
use League\Uri\Schemes\Http as HttpUri;
use Njasm\Soundcloud\Soundcloud;
use stdClass;

class Groschen implements ProductInterface
{
    /**
     * Product number
     * @var string
     */
    private $productNumber;

    /**
     * Raw product information
     * @var \stdClass
     */
    private $product;

    /**
     * @param string $productNumber
     */
    public function __construct($productNumber)
    {
        $this->setProductNumber($productNumber);
        $this->setProductInformation();
    }

    /**
     * Set the product number
     * @param string $productNumber
     */
    public function setProductNumber($productNumber)
    {
        // Check that product exists in Schilling
        $lookupValue = $this->getLookupValue(7, $productNumber);

        if (empty($lookupValue)) {
            throw new Exception('Product does not exist in Schilling.');
        }

        $this->productNumber = $productNumber;
    }

    /**
     * Set the product information
     * @return void
     */
    public function setProductInformation()
    {
        // Create instances for Schilling Web Service API
        $product = new Product(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        // Get product with additional info
        $products = $product->getProducts([
            'ProductNumber' => $this->productNumber,
            'WithPartnerInfoData' => true,
            'WithPriceData' => true,
            'WithInternetInformation' => true,
        ]);

        // Return first result
        $this->product = $products[0];
    }

    /**
     * Return the raw product information
     * @return \stdClass
     */
    public function getProductInformation()
    {
        return $this->product;
    }

    /**
     * Get the products identifiers
     * @return Collection
     */
    public function getProductIdentifiers()
    {
        $productIdentifiers = new Collection;

        // Propietary internal product number
        $productIdentifiers->push([
            'ProductIDType' => '01',
            'id_type_name' => 'Bonnier Books Finland - Internal product number',
            'id_value' => $this->product->ProductNumber,
        ]);

        // GTIN-13 and ISBN-13
        if (!empty($this->product->EAN) && $this->isValidGtin($this->product->EAN)) {
            foreach (['03', '15'] as $id_value) {
                $productIdentifiers->push([
                    'ProductIDType' => $id_value,
                    'id_value' => $this->product->EAN,
                ]);
            }
        }

        return $productIdentifiers;
    }

    /**
     * Get the products composition (Onix Codelist 2)
     * @return string|null
     */
    public function getProductComposition()
    {
        // Determine whether it is a normal Single-item retail or Trade-only product
        return ($this->product->SubGroup == 18) ? '20' : '00';
    }

    /**
     * Get the products from (Onix codelist 150)
     * @return string|null
     */
    public function getProductForm()
    {
        // If missing, return null
        if (empty($this->product->MediaType)) {
            return null;
        }

        // E-book mapping for Bokinfo
        if ($this->product->MediaType === 'ED') {
            return 'EA';
        }

        // Kit mapping for Bokinfo
        if ($this->product->MediaType === 'PH') {
            return 'BF';
        }

        // MP3-CD mapping for Bokinfo
        if ($this->product->MediaType === 'AE' && $this->product->BindingCode === 'A103') {
            return 'AC';
        }

        return $this->product->MediaType;
    }

    /**
     * Get the products form detail (Onix codelist 175)
     * @return string|null
     */
    public function getProductFormDetail()
    {
        if (empty($this->product->BindingCode)) {
            return null;
        }

        switch ($this->product->BindingCode) {
            // Custom binding code Nokia Ovi ebook is mapped to Book ‘app’ for other operating system
            case 'W990':
                return 'E136';
                break;
            // Custom binding code iPhone / iPad is mapped to Book ‘app’ for iOS
            case 'W991':
            case 'W992':
                return 'E134';
                break;
            // Custom binding code ePub 3 is mapped to EPUB
            case 'W993':
                return 'E101';
                break;
            // Custom binding code Picture-audio book is mapped to Readalong audio
            case 'W994':
                return 'A302';
                break;
            default:
                return $this->product->BindingCode;
                break;
        }
    }

    /**
     * Get the products form features
     * @return Collection
     */
    public function getProductFormFeatures()
    {
        $productFormFeatures = new Collection;

        // Add ePub version
        switch ($this->product->BindingCode) {
            case 'E101':
                $featureValue = '101A';
                break;
            case 'W993':
                $featureValue = '101B';
                break;
        }

        if (isset($featureValue)) {
            $productFormFeatures->push([
                'ProductFormFeatureType' => '15',
                'ProductFormFeatureValue' => $featureValue,
            ]);
        }

        return $productFormFeatures;
    }

    /**
     * Check if the given product number is valid GTIN
     * @param  string  $gtin
     * @return boolean
     */
    public function isValidGtin($gtin)
    {
        $isbn = new Isbn\Isbn();

        return $isbn->validation->isbn13($gtin);
    }

    /**
     * Get the products collections/series
     * @return Collection
     */
    public function getCollections()
    {
        $collections = new Collection;

        if (!empty($this->product->BookSeries)) {
            $collections->push([
                'CollectionType' => '10', [
                    'TitleDetail' => [
                        'TitleType' => '01',
                        'TitleElement' => [
                            'TitleElementLevel' => '01',
                            'TitleText' => $this->product->BookSeries,
                        ],
                    ],
                ],
            ]);

            // Add Collection sequence if product has NumberInSeries
            if ($this->product->NumberInSeries > 0) {
                $collections = $collections->map(function ($collection) {
                    // Add CollectionSequence to Collection
                    $collectionSequence = [
                        'CollectionSequenceType' => '03',
                        'CollectionSequenceNumber' => $this->product->NumberInSeries,
                    ];

                    $collection[0]['CollectionSequence'] = $collectionSequence;

                    return $collection;
                });
            }
        }

        return $collections;
    }

    /**
     * Get the products title details
     * @return  Collection
     */
    public function getTitleDetails()
    {
        $titleDetails = new Collection;

        // Main title
        if (!empty($this->product->Title)) {
            $titleDetails->push([
                'TitleType' => '01',
                'TitleElement' => [
                    'TitleElementLevel' => '01',
                    'TitleText' => $this->product->Title,
                ],
            ]);
        }

        // Add subtitle
        if (!empty($this->product->SubTitle)) {
            $titleDetails = $titleDetails->map(function ($titleDetail) {
                $titleDetail['TitleElement']['Subtitle'] = $this->product->SubTitle;
                return $titleDetail;
            });
        }

        // Original title
        if (!empty($this->product->LongSubtitle)) {
            $titleDetails->push([
                'TitleType' => '03',
                'TitleElement' => [
                    'TitleElementLevel' => '01',
                    'TitleText' => $this->product->LongSubtitle,
                ],
            ]);
        }

        // Distributors title
        $titleDetails->push([
            'TitleType' => '10',
            'TitleElement' => [
                'TitleElementLevel' => '01',
                'TitleText' => $this->product->ProductText,
            ],
        ]);

        return $titleDetails;
    }

    /**
     * Get the products contributors
     * @return Collection
     */
    public function getContributors()
    {
        $contributors = new Collection;

        // If no stakeholders present
        if (!isset($this->product->PartnerInfo)) {
            return $contributors;
        }

        foreach ($this->product->PartnerInfo as $contributor) {
            if (!empty($contributor->KeyNo) && !is_null($this->getContributorRole($contributor->RoleId))) {
                // Get stakeholders name
                switch ($contributor->Type) {
                    // Resource
                    case '1':
                        $name = $this->getLookupValue(506, $contributor->KeyNo);
                        $idTypeName = 'Resource ID';
                        break;
                    // Creditor and author
                    case '3':
                    case '5':
                        $name = $this->getLookupValue(6, $contributor->KeyNo);
                        $idTypeName = 'Creditor number';
                        break;
                    default:
                        $name = null;
                        $idTypeName = null;
                        break;
                }

                if (!empty($name)) {
                    // Check if name is in typical "Lastname, Firstname" format or pseudonym
                    if (strpos($name, ', ') !== false) {
                        list($lastname, $firstname) = explode(', ', $name);
                    } else {
                        $lastname = $name;
                        $firstname = null;
                    }

                    // Add to collection
                    $contributors->push([
                        'ContributorRole' => $this->getContributorRole($contributor->RoleId),
                        'NameIdentifier' => [
                            'NameIDType' => '01',
                            'IDTypeName' => $idTypeName,
                            'IDValue' => $contributor->KeyNo,
                        ],
                        'PersonNameInverted' => $name,
                        'NamesBeforeKey' => $firstname,
                        'KeyNames' => $lastname,
                        'Sorting' => [
                            'priority' => $contributor->Priority,
                            'role_priority' => $this->getRolePriority($contributor->RoleId),
                        ],
                    ]);
                }
            }
        }

        // Sort the array by priority, then by role priority
        $contributors = $contributors->sortByDesc(function ($contributors) {
            return $contributors['Sorting']['role_priority'] . '-' . $contributors['Sorting']['priority'] . '-' . (100 - ord($contributors['KeyNames']));
        });

        // Add SequenceNumber and drop sorting fields
        $sequenceNumber = 1;
        $contributors = $contributors->map(function ($contributor) use (&$sequenceNumber) {
            // Insert SequenceNumber as first key
            $contributor = ['SequenceNumber' => $sequenceNumber] + $contributor;
            unset($contributor['Sorting']);
            $sequenceNumber++;

            return $contributor;
        });

        return $contributors;
    }

    /**
     * Get the products languages
     * @return Collection
     */
    public function getLanguages()
    {
        $languages = new Collection;

        // Add text language
        if (!empty($this->product->TextLanguage)) {
            foreach (explode('_', $this->product->TextLanguage) as $language) {
                $languages->push([
                    'LanguageRole' => '01',
                    'LanguageCode' => $language,
                ]);
            }
        }

        // Add original languages
        if (!empty($this->product->TranslatedFrom)) {
            foreach (explode('_', $this->product->TranslatedFrom) as $language) {
                $languages->push([
                    'LanguageRole' => '02',
                    'LanguageCode' => $language,
                ]);
            }
        }

        return $languages;
    }

    /**
     * Get the products extents
     * @return Collection
     */
    public function getExtents()
    {
        $extents = new Collection;

        // Number of pages
        if ($this->product->PageCount > 0) {
            $extents->push([
                'ExtentType' => '00',
                'ExtentValue' => $this->product->PageCount,
                'ExtentUnit' => '03',
            ]);
        }

        // Audio duration, convert from HH:MM to HHHMM
        if (!empty($this->product->Unit)) {
            list($hours, $minutes) = explode(':', $this->product->Unit);
            $extentValue = str_pad($hours, 3, '0', STR_PAD_LEFT) . str_pad($minutes, 2, '0', STR_PAD_LEFT);

            $extents->push([
                'ExtentType' => '09',
                'ExtentValue' => $extentValue,
                'ExtentUnit' => '15',
            ]);
        }

        return $extents;
    }

    /**
     * Get the publishers name
     * @return string
     */
    public function getPublisher()
    {
        switch ($this->product->Owner) {
            case '1':
            case '3':
                return 'Werner Söderström Osakeyhtiö';
                break;
            case '2:':
            case '4:':
            case '5:':
                return 'Kustannusosakeyhtiö Tammi';
                break;
            default:
                throw new Exception('No mapping for publisher exists.');
                break;
        }
    }

    /**
     * Get the products imprints
     * @return Collection
     */
    public function getImprints()
    {
        $imprints = new Collection;

        if (!empty($this->product->OriginalPublisher)) {
            $imprints->push([
                'ImprintName' => $this->getLookupValue(595, $this->product->OriginalPublisher),
            ]);
        }

        return $imprints;
    }

    /**
     * Get the products recommended retail price RRP including VAT
     * @return float|null
     */
    public function getPrice()
    {
        foreach ($this->product->PriceList as $price) {
            if ($price->PriceGroup === '0i') {
                return floatval($price->Salesprice);
            }
        }

        return null;
    }

    /**
     * Get the products measures
     * @return Collection
     */
    public function getMeasures()
    {
        // Collection for measures
        $measures = new Collection;

        // Add width, height and length
        $measures->push(['MeasureType' => '01', 'Measurement' => intval($this->product->Height), 'MeasureUnitCode' => 'mm']);
        $measures->push(['MeasureType' => '02', 'Measurement' => intval($this->product->Width), 'MeasureUnitCode' => 'mm']);
        $measures->push(['MeasureType' => '03', 'Measurement' => intval($this->product->Length), 'MeasureUnitCode' => 'mm']);

        // Add weight
        $measures->push(['MeasureType' => '08', 'Measurement' => intval($this->product->NetWeight * 1000), 'MeasureUnitCode' => 'gr']);

        // Filter out zero values
        $measures = $measures->filter(function ($measure) {
            return $measure['Measurement'] > 0;
        });

        return $measures;
    }

    /**
     * Get the products original publication date
     * @return DateTime|null
     */
    public function getOriginalPublicationDate()
    {
        if (is_null($this->product->OriginalPublishingDate)) {
            return null;
        }

        return DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->OriginalPublishingDate);
    }

    /**
     * Get the products latest publication date
     * @return DateTime|null
     */
    public function getLatestPublicationDate()
    {
        if (is_null($this->product->PublishingDate)) {
            return null;
        }

        return DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->PublishingDate);
    }

    /**
     * Get the products subjects, like library class, Thema, BIC, BISAC etc.
     * @return Collection
     */
    public function getSubjects()
    {
        // Init array for subjects
        $subjects = new Collection;

        // Finnish Public Libraries Classification System aka YKL
        $libraryClass = preg_replace("/[^0-9.]/", "", $this->getLookupValue(293, $this->product->LiteratureGroup));

        if (!empty($libraryClass)) {
            $subjects->push(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => $libraryClass]);
        }

        // Schilling main and subgroup
        $subjects->push(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => $this->getLookupValue(26, str_pad($this->product->MainGroup, 2, '0', STR_PAD_LEFT))]);
        $subjects->push(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => $this->getLookupValue(27, str_pad($this->product->SubGroup, 3, '0', STR_PAD_LEFT))]);

        // BISAC Subject Heading
        $subjects->push(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => $this->getBisacCode()]);

        // BIC subject category
        $subjects->push(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => $this->getBicCode()]);

        // Thema subject category
        $subjects->push(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => $this->getThemaSubjectCode()]);

        // Thema interest age
        $subjects->push(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => $this->getThemaInterestAge()]);

        // Fiktiivisen aineiston lisäluokitus
        $subjects->push(['SubjectSchemeIdentifier' => '80', 'SubjectSchemeName' => 'Fiktiivisen aineiston lisäluokitus', 'SubjectCode' => $this->getFiktiivisenAineistonLisaluokitus()]);

        // Finnish book trade categorization
        foreach ($this->getFinnishBookTradeCategorisations() as $finnishBookTradeCategorisation) {
            $subjects->push($finnishBookTradeCategorisation);
        }

        // Add subjects/keywords from Finna
        $finnaSubjects = $this->getSubjectWords();
        foreach ($finnaSubjects as $finnaSubject) {
            $subjects->push($finnaSubject);
        }

        // Remove those where SubjectCode is empty
        $subjects = $subjects->filter(function ($subject) {
            return !empty($subject['SubjectCode']);
        });

        // Add all keywords separated by semicolon from finnish ontologies
        $keywords = [];

        foreach ($finnaSubjects as $subject) {
            switch ($subject['SubjectSchemeIdentifier']) {
                case '69': // KAUNO - ontology for fiction
                case '71': // YSO - General Finnish ontology
                case '64': // YSA - General Finnish thesaurus
                    $keywords[] = $subject['SubjectCode'];
                    break;
            }
        }

        if (!empty($keywords)) {
            $subjects->push(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => implode(';', $keywords)]);
        }

        return $subjects;
    }

    /**
     * Get the products audience groups
     * @return Collection
     */
    public function getAudiences()
    {
        // Collection for audiences
        $audiences = new Collection;

        // Map the Schilling age group to Audience
        switch ($this->product->AgeGroup) {
            case '0+':
            case '3+':
            case '5+':
            case '7+':
            case '9+':
            case '10+':
            case '12+':
                $audienceCodeValue = '02'; // Children/juvenile
                break;
            case '15+':
                $audienceCodeValue = '03'; // Young adult
                break;
            default:
                $audienceCodeValue = '01'; // General/trade
                break;
        }

        $audiences->push(['AudienceCodeType' => '01', 'AudienceCodeValue' => $audienceCodeValue]);

        return $audiences;
    }

    /**
     * Get the products AudienceRanges
     * @return Collection
     */
    public function getAudienceRanges()
    {
        // Collection for audience ranges
        $audienceRanges = new Collection;

        if (!empty($this->product->AgeGroup)) {
            // Get age groups and strip the +
            $ageGroups = array_map(function ($ageGroup) {
                return intval(preg_replace('[\D]', '', $ageGroup->KeyValue));
            }, $this->getLookupValues(591));

            // Sort by age and reindex keys
            natsort($ageGroups);
            $ageGroups = array_values($ageGroups);

            // Add AgeGroup 18+
            $ageGroups[] = 18;

            // Determine the from and to values. Since array is sorted, To is the next index in the array.
            $fromKey = array_search($this->product->AgeGroup, $ageGroups);
            $toKey = $fromKey + 1;

            $audienceRanges->push([
                'AudienceRangeQualifier' => 17,
                'AudienceRangeScopes' => [
                    [
                        'AudienceRangePrecision' => '03', // From
                        'AudienceRangeValue' => $ageGroups[$fromKey],
                    ],
                    [
                        'AudienceRangePrecision' => '04', // To
                        'AudienceRangeValue' => $ageGroups[$toKey],
                    ],
                ],
            ]);
        }

        return $audienceRanges;
    }

    /**
     * Get the products text contents
     * @return Collection
     */
    public function getTextContents()
    {
        $textContents = new Collection;

        if (empty($this->product->ProjectId)) {
            return $textContents;
        }

        $text = $this->getLatestMarketingText($this->product->ProjectId);

        if (!empty($text)) {
            $textContents->push([
                'TextType' => '03',
                'ContentAudience' => '00',
                'Text' => $this->getLatestMarketingText($this->product->ProjectId),
            ]);
        }

        return $textContents;
    }

    /**
     * Get the products publishers and their role
     * @return Collection
     */
    public function getPublishers()
    {
        $publishers = new Collection;

        switch ($this->product->Owner) {
            case '1':
            case '3':
                $publisherName = 'Werner Söderström Osakeyhtiö';
                break;
            case '2:':
            case '4:':
            case '5:':
                $publisherName = 'Kustannusosakeyhtiö Tammi';
                break;
            default:
                throw new Exception('No mapping for publisher exists.');
                break;
        }

        // Add main publisher
        $publishers->push(['PublishingRole' => '01', 'PublisherName' => $publisherName]);

        return $publishers;
    }

    /**
     * Get the products publishing status (Onix codelist 64)
     * @return string
     */
    public function getPublishingStatus()
    {
        // Determine the PublishingStatus
        switch ($this->product->NotifyCode) {
            // Development
            case '1':
                return '02'; // Forthcoming
                break;
            // Published
            case '2':
                return '04'; // Active
                break;
            // Exclusive sales
            case '3':
                return '04'; // Active
                break;
            // Sold out
            case '4':
                return '07'; // Out of print
                break;
            // Development-confidential
            case '6':
                return '00'; // Unknown
                break;
            // Cancelled
            case '7':
                return '01'; // Cancelled
                break;
            // POD / shortrun
            case '8':
                return '04'; // Active
                break;
            // Delivery block
            case '9':
                return '16'; // Temporarily withdrawn from sale
                break;
        }
    }

    /**
     * get the product publishing dates
     * @return Collection
     */
    public function getPublishingDates()
    {
        $publishingDates = new Collection;

        // Define the dates we want to collect
        $dates = ['01' => 'OriginalPublishingDate', '12' => 'PublishingDate'];

        foreach ($dates as $publishingDateRole => $date) {
            if (!empty($this->product->{$date})) {
                // Convert to DateTime
                $publishingDate = DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->{$date});

                $publishingDates->push(['PublishingDateRole' => (string) $publishingDateRole, 'Date' => $publishingDate->format('Ymd')]);
            }
        }

        return $publishingDates;
    }

    /**
     * Get The products prices
     * @return Collection
     */
    public function getPrices()
    {
        $prices = new Collection;

        // Price types to collect
        $priceTypes = new Collection;

        // Supplier’s net price excluding tax
        $priceTypes->push([
            'PriceTypeCode' => '05',
            'TaxIncluded' => false,
            'TaxRateCode' => 'Z',
            'PriceGroup' => '0',
        ]);

        // Supplier’s net price including tax
        $priceTypes->push([
            'PriceTypeCode' => '07',
            'TaxIncluded' => true,
            'TaxRateCode' => 'S',
            'PriceGroup' => '0i',
        ]);

        // Publishers retail price including tax
        $priceTypes->push([
            'PriceTypeCode' => '42',
            'TaxIncluded' => true,
            'TaxRateCode' => 'S',
            'PriceGroup' => '4i',
        ]);

        // Go through all Price Types
        foreach ($priceTypes as $priceType) {
            // Price amount
            $priceAmount = $this->getPriceForPriceGroup($priceType);

            if (!is_null($priceAmount)) {
                $prices->push([
                    'PriceType' => $priceType['PriceTypeCode'],
                    'PriceAmount' => $priceAmount,
                    'Tax' => $this->getTaxElement($priceType),
                    'CurrencyCode' => 'EUR',
                    'Territory' => [
                        'RegionsIncluded' => 'WORLD',
                    ],
                ]);
            }
        }

        return $prices;
    }

    /**
     * Get price for the given price group
     * @param  array $priceType
     * @return float|null
     */
    public function getPriceForPriceGroup($priceType)
    {
        foreach ($this->product->PriceList as $price) {
            if ($price->PriceGroup === $priceType['PriceGroup']) {
                return floatval($price->Salesprice);
            }
        }

        return null;
    }

    /**
     * Get the tax element
     * @param  array $priceType
     * @return array
     */
    public function getTaxElement($priceType)
    {
        // Form VAT code
        $vatCode = floatval(preg_replace('/\D/', '', $this->getLookupValue(75, $this->product->VATCode)));

        // Form taxable and tax amount
        if ($priceType['TaxIncluded'] === true) {
            $taxableAmount = $this->getPriceForPriceGroup($priceType) / (($this->getTaxRate() + 100) / 100);
            $taxAmount = $this->getPriceForPriceGroup($priceType) - $taxableAmount;
        } else {
            $taxableAmount = $this->getPriceForPriceGroup($priceType);
            $taxAmount = 0;
        }

        return [
            'TaxType' => '01',
            'TaxRateCode' => $priceType['TaxRateCode'],
            'TaxRatePercent' => $vatCode,
            'TaxableAmount' => round($taxableAmount, 2),
            'TaxAmount' => round($taxAmount, 2),
        ];
    }

    /**
     * Get products supporting resources
     * @return Collection
     */
    public function getSupportingResources()
    {
        $supportingResources = new Collection;

        // Form new Guzzle client
        $client = new Client([
            'base_uri' => config('groschen.elvis.hostname'),
            'cookies' => true,
        ]);

        // Login to Elvis
        $response = $client->request('GET', 'login', ['query' => ['username' => config('groschen.elvis.username'), 'password' => config('groschen.elvis.password')]]);
        $json = json_decode($response->getBody());

        // Check that we are logged in
        if ($json->loginSuccess === false) {
            throw new Exception($json->loginFaultMessage);
        }

        // Search for cover image in Elvis
        $response = $client->request('GET', 'search', [
            'query' => [
                'q' => 'gtin:' . $this->productNumber . ' AND cf_catalogMediatype:cover AND (ancestorPaths:/WSOY/Kansikuvat OR ancestorPaths:/Tammi/Kansikuvat)',
                'metadataToReturn' => 'height, width, mimeType, fileSize',
                'num' => 1,
            ],
        ]);

        $searchResults = json_decode($response->getBody());

        // Add cover image to collection
        foreach ($searchResults->hits as $hit) {
            $supportingResources->push([
                'ResourceContentType' => '01',
                'ContentAudience' => '00',
                'ResourceMode' => '03',
                'ResourceVersion' => [
                    'ResourceForm' => '02',
                    'ResourceVersionFeatures' => $this->getResourceVersionFeatures($hit),
                    'ResourceLink' => $this->getAuthCredUrl($hit->originalUrl),
                ],
            ]);
        }

        // Logout from Elvis
        $response = $client->request('GET', 'logout');

        // Add audio/reading samples and YouTube trailers
        if (isset($this->product->InternetInformation->InternetTexts[0]->InternetLinks)) {
            foreach ($this->product->InternetInformation->InternetTexts[0]->InternetLinks as $internetLink) {
                switch ($internetLink->LinkType) {
                    case 'ääninäyte':
                        $resourceContentType = '15';
                        $resourceMode = '02';

                        // Get permalink URL from Soundcloud
                        $soundcloud = new Soundcloud(config('groschen.soundcloud.clientId'), config('groschen.soundcloud.clientSecret'));
                        $soundcloud->get('/tracks/' . $internetLink->Link);
                        $response = $soundcloud->request();
                        $url = $response->bodyObject()->permalink_url;
                        break;
                    case 'issuu':
                        $resourceContentType = '15';
                        $resourceMode = '04';
                        $url = $internetLink->Link;
                        break;
                    case 'youtube':
                        $resourceContentType = '26';
                        $resourceMode = '05';
                        $url = $internetLink->Link;
                        break;
                    default:
                        $resourceContentType = null;
                        $resourceMode = null;
                        $url = null;
                        break;
                }

                // Add to Collection if URL exists
                if (!empty($url)) {
                    $supportingResources->push([
                        'ResourceContentType' => $resourceContentType,
                        'ContentAudience' => '00',
                        'ResourceMode' => $resourceMode,
                        'ResourceVersion' => [
                            'ResourceForm' => '03',
                            'ResourceLink' => $url,
                        ],
                    ]);
                }
            }
        }

        return $supportingResources;
    }

    /**
     * Returns the ResourceVersionFeatures for the given Elvis metadata hit
     * @param  \stdClass $hit
     * @return array
     */
    public function getResourceVersionFeatures($hit)
    {
        // Elvis uses mime types, so we need mapping table for ResourceVersionFeatureValue codelist
        $mimeTypeToCodelistMapping = [
            'application/pdf' => 'D401',
            'image/gif' => 'D501',
            'image/jpeg' => 'D502',
            'image/png' => 'D503',
            'image/tiff' => 'D504',
        ];

        // Download the file for MD5/SHA checksums
        $contents = file_get_contents($this->getAuthCredUrl($hit->originalUrl));

        // Pixel height/width, filename, download file size in megabytes, checksums and
        $resourceVersionFeatures = [
            [
                'ResourceVersionFeatureType' => '01',
                'FeatureValue' => $mimeTypeToCodelistMapping[$hit->metadata->mimeType],
            ],
            [
                'ResourceVersionFeatureType' => '02',
                'FeatureValue' => $hit->metadata->height,
            ],
            [
                'ResourceVersionFeatureType' => '03',
                'FeatureValue' => $hit->metadata->width,
            ],
            [
                'ResourceVersionFeatureType' => '04',
                'FeatureValue' => $hit->metadata->filename,
            ],
            [
                'ResourceVersionFeatureType' => '05',
                'FeatureValue' => number_format($hit->metadata->fileSize->value / 1048576, 1),
            ],
            [
                'ResourceVersionFeatureType' => '06',
                'FeatureValue' => hash('md5', $contents),
            ],
            [
                'ResourceVersionFeatureType' => '07',
                'FeatureValue' => $hit->metadata->fileSize->value,
            ],
            [
                'ResourceVersionFeatureType' => '08',
                'FeatureValue' => hash('sha256', $contents),
            ],

        ];

        return $resourceVersionFeatures;
    }

    /**
     * Get the related products
     * @return Collection
     */
    public function getRelatedProducts()
    {
        $relatedProducts = new Collection;

        if (isset($this->product->InternetInformation->RelatedProducts)) {
            foreach ($this->product->InternetInformation->RelatedProducts as $relatedProduct) {
                $relatedProducts->push([
                    'ProductRelationCode' => '06',
                    'ProductIdentifiers' => [
                        [
                            'ProductIDType' => '03',
                            'IDValue' => intval($relatedProduct),
                        ],
                    ],
                ]);
            }
        }

        return $relatedProducts;
    }

    /**
     * Get the authCred URL for the Elvis links
     * @param  string $url
     * @return string
     */
    public function getAuthCredUrl($url)
    {
        $uri = HttpUri::createFromString($url);

        // Add authCred to query parameters
        $modifier = new MergeQuery('authcred=' . base64_encode(config('groschen.elvis.username') . ':' . config('groschen.elvis.password')));
        $newUri = $modifier->__invoke($uri);

        // Remove the underscore version parameter
        $modifier = new RemoveQueryKeys(['_']);
        $newUri = $modifier->__invoke($newUri);

        return (string) $newUri;
    }

    /**
     * Get the products tax rate
     * @return float
     */
    public function getTaxRate()
    {
        return floatval(preg_replace('/[^0-9]/', '', $this->getLookupValue(75, $this->product->VATCode)));
    }

    /**
     * Get the stakeholders role priority (ie. author is higher than illustrator)
     * @param  string $roleId
     * @return int
     */
    public function getRolePriority($roleId)
    {
        $rolePriorities = [
            'AUT' => 99,
            'EIC' => 98,
            'EDA' => 97,
            'IND' => 96,
            'PRE' => 95,
            'FOR' => 94,
            'INT' => 93,
            'PRO' => 92,
            'AFT' => 91,
            'EPI' => 90,
            'ILL' => 89,
            'PHO' => 88,
            'REA' => 87,
            'TRA' => 86,
            'GDE' => 85,
            'CDE' => 84,
            'COM' => 83,
            'ARR' => 82,
            'MAP' => 81,
            'AST' => 80,
        ];

        if (array_key_exists($roleId, $rolePriorities)) {
            return $rolePriorities[$roleId];
        }

        return 0;
    }

    /**
     * Get the Schilling lookup value based on the domain and value
     * @param  int $domain
     * @param  string $value
     * @return string|null
     */
    public function getLookupValue($domain, $value)
    {
        // Create Schilling lookup instance
        $lookup = new Lookup(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        $lookupValue = $lookup->lookup(['DomainNumber' => $domain, 'KeyValue' => $value]);

        if (empty($lookupValue)) {
            return null;
        }

        return $lookupValue[0]->DataValue;
    }

    /**
     * Get the Schilling lookup value based on the domain and value
     * @param  int $domain
     * @return array
     */
    public function getLookupValues($domain)
    {
        // Create Schilling lookup instance
        $lookup = new Lookup(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        $lookupValue = $lookup->lookup(['DomainNumber' => $domain]);

        return (empty($lookupValue)) ? null : $lookupValue->ReturnValue;
    }
    /**
     * Get the marketing text from the latest print project
     * @param  string $projectId
     * @return string|null
     */
    public function getLatestMarketingText($projectId)
    {
        // Determine the latest project number
        $latestPrintProject = $this->getLatestPrintProject($projectId);

        // Fetch marketing texts
        $schilling = new TextHandling(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        $texts = $schilling->getTextHandlings(['ProjectNumber' => $latestPrintProject]);

        if (isset($texts->ReturnValue)) {
            foreach ($texts->ReturnValue as $text) {
                // Marketing text has TextType ID 44
                if ($text->TextType->Id === '44') {
                    $marketingText = $text->Text;
                    break;
                }
            }
        }

        if (empty($marketingText)) {
            return null;
        }

        // Clean HTML formattting
        return $this->purifyHtml($marketingText);
    }

    /**
     * Get the latest print project for the given main project
     * @param  string $projectId
     * @return string|null
     */
    public function getLatestPrintProject($projectId)
    {
        // There is a bug in Schilling Web Services have a bug when using ProjectId as query parameter
        // as it does return ProjectNo in the response. Use EditionPrintOverProject instead
        $schilling = new Project(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        // Get the main project
        $mainProject = $schilling->getProjects(['ProjectId' => $projectId])[0];

        if (is_null($mainProject->EditionPrintOverProject)) {
            return null;
        }

        $projectNumberWithoutSuffix = substr($mainProject->EditionPrintOverProject, 0, -2);

        $printProjects = $schilling->getProjects([
            'ProjectNoFrom' => $projectNumberWithoutSuffix . '01',
            'ProjectNoTo' => $projectNumberWithoutSuffix . '99',
        ]);

        if (empty($printProjects)) {
            return null;
        }

        // Create new array for ProjectNumbers
        $projectNumbers = [];

        if (is_array($printProjects)) {
            $projectNumbers[] = $printProjects[0]->ProjectNo;
        } else {
            foreach ($printProjects->ReturnValue as $printProject) {
                $projectNumbers[] = $printProject->ProjectNo;
            }
        }

        return max($projectNumbers);
    }

    /**
     * Purifies the given XHTML
     * @param  string $text
     * @return string
     */
    public function purifyHtml($text)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.TidyLevel', 'heavy');
        $config->set('HTML.Allowed', 'p,br,strong,em,b,i,ul,ol,li,sub,sup,dl,dt,dd');
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new HTMLPurifier($config);
        return $purifier->purify($text);
    }

    /**
     * Returns the BISAC code equivalent for Schilling sub-group
     * @return string|null
     */
    public function getBisacCode()
    {
        // Mapping table
        $subGroupToBisacMapping = [
            '1' => 'LCO010000',
            '2' => 'FIC009000',
            '3' => 'BIO000000',
            '4' => 'HIS000000',
            '5' => 'HUM000000',
            '6' => 'HEA000000',
            '7' => 'HIS037080',
            '8' => 'FIC030000',
            '9' => 'CRA000000',
            '10' => 'PSY000000',
            '11' => 'FIC004000',
            '12' => 'HOM000000',
            '14' => 'JUV000000',
            '15' => 'JUV000000',
            '16' => 'JNF000000',
            '17' => 'NAT000000',
            '19' => 'TRV000000',
            '20' => 'MUS000000',
            '21' => 'PER016000',
            '22' => 'FIC029000',
            '23' => 'JUV000000',
            '24' => 'FIC000000',
            '26' => 'GAR000000',
            '27' => 'POE000000',
            '28' => 'CKB000000',
            '29' => 'FIC010000',
            '30' => 'CGN000000',
            '31' => 'FIC028000',
            '32' => 'ART000000',
            '33' => 'BUS000000',
            '34' => 'REL000000',
            '35' => 'FIC027000',
            '36' => 'SOC000000',
            '38' => 'SCI000000',
            '39' => 'HOM004000',
            '40' => 'JNF001000',
            '41' => 'JUV000000',
            '43' => 'SPO000000',
            '44' => 'FIC010000',
            '45' => 'MUS037000',
            '46' => 'SEL000000',
            '47' => 'FIC015000',
            '48' => 'SPO000000',
            '49' => 'HIS027000',
            '50' => 'PHI000000',
            '51' => 'CGN004050',
        ];

        if (!array_key_exists($this->product->SubGroup, $subGroupToBisacMapping)) {
            return null;
        }

        return $subGroupToBisacMapping[$this->product->SubGroup];
    }

    /**
     * Returns the BIC code equivalent for Schilling sub-group
     * @return string|null
     */
    public function getBicCode()
    {
        // Mapping table
        $subGroupToBicMapping = [
            '1' => 'DNF',
            '2' => 'FM',
            '3' => 'DN',
            '4' => 'HB',
            '5' => 'WH',
            '6' => 'V',
            '7' => 'HBLX',
            '8' => 'FH',
            '9' => 'WF',
            '10' => 'JMC',
            '11' => 'FC',
            '12' => 'WK',
            '13' => 'WZS',
            '14' => 'YBC',
            '15' => 'YFB',
            '16' => 'YN',
            '17' => 'WN',
            '18' => 'WZS',
            '19' => 'WT',
            '20' => 'AV',
            '21' => 'DD',
            '22' => 'FBA',
            '23' => 'YFB',
            '24' => 'FA',
            '25' => 'WZS',
            '26' => 'WM',
            '27' => 'DC',
            '28' => 'WB',
            '29' => 'YF',
            '30' => 'FX',
            '31' => 'FL',
            '32' => 'AK',
            '33' => 'K',
            '34' => 'HR',
            '35' => 'FR',
            '36' => 'J',
            '37' => 'W',
            '38' => 'P',
            '39' => 'WK',
            '40' => 'YBG',
            '41' => 'YB',
            '42' => 'WJ',
            '43' => 'WS',
            '44' => 'YD',
            '45' => 'YNC',
            '46' => 'VS',
            '47' => 'FK',
            '48' => 'WS',
            '49' => 'JW',
            '50' => 'HP',
            '51' => 'FXA',
        ];

        if (!array_key_exists($this->product->SubGroup, $subGroupToBicMapping)) {
            return null;
        }

        return $subGroupToBicMapping[$this->product->SubGroup];
    }

    /**
     * Return the Thema subject class
     * @return string|null
     */
    public function getThemaSubjectCode()
    {
        // Mapping from Schilling subgroup to Thema for adults
        $themaMappingTableAdults = [
            '1' => 'DNL',
            '2' => 'FM',
            '3' => 'DN',
            '4' => 'NH',
            '5' => 'FU',
            '6' => 'VF',
            '8' => 'FH',
            '9' => 'WF',
            '10' => 'JMC',
            '11' => 'FBC',
            '12' => 'WK',
            '13' => 'WZS',
            '14' => 'YBC',
            '15' => 'YFB',
            '16' => 'YN',
            '17' => 'WN',
            '18' => 'WZS',
            '19' => 'WT',
            '20' => 'AV',
            '21' => 'DD',
            '22' => 'FBA',
            '23' => 'YFB',
            '24' => 'FBA',
            '25' => 'WZS',
            '26' => 'WM',
            '27' => 'DC',
            '28' => 'WB',
            '29' => 'YF',
            '30' => 'X',
            '31' => 'FL',
            '32' => 'AK',
            '33' => 'K',
            '34' => 'QR',
            '35' => 'FB',
            '36' => 'J',
            '37' => 'W',
            '38' => 'P',
            '39' => 'WK',
            '40' => 'YBG',
            '41' => 'YB',
            '42' => 'WJ',
            '43' => 'S',
            '44' => 'YD',
            '45' => 'YNC',
            '46' => 'VS',
            '47' => 'FK',
            '48' => 'S',
            '49' => 'JW',
            '50' => 'QD',
            '51' => 'XAM',
        ];

        // Mapping from Schilling subgroup to Thema for children and young adults
        $themaMappingTableChildren = [
            '1' => 'YNL',
            '2' => 'YFH',
            '3' => 'YNB',
            '4' => 'YNH',
            '5' => 'YFQ',
            //'6' => '', Family & health
            '8' => 'YFCB',
            // '9' => '', Handicrafts, decorative arts & crafts
            //'10' => '', Child, developmental & lifespan psychology
            '11' => 'YFA',
            //'12' => '', Home & house maintenance
            '13' => 'YZG',
            '14' => 'YBC',
            '15' => 'YFB',
            '16' => 'YN',
            '17' => 'YNN',
            '18' => 'YZ',
            //'19' => '', Travel & holiday
            '20' => 'YNC',
            '21' => 'YNDS',
            //'22' => '', Modern & contemporary fiction
            '23' => 'YFB',
            //'24' => '', Modern & contemporary fiction
            '25' => 'YZ',
            '26' => 'YNPG',
            '27' => 'YDP',
            '28' => 'YNPC',
            '29' => 'YF',
            '30' => 'X',
            '31' => 'YFG',
            //'32' => '', Industrial / commercial art & design
            //'33' => '', Economics, Finance, Business & Management
            '34' => 'YNR',
            '35' => 'YFB',
            '36' => 'YNK',
            '37' => 'YNV',
            //'38' => '', Mathematics & Science
            //'39' => '', Home & house maintenance
            '40' => 'YBG',
            '41' => 'YB',
            //'42' => '', Lifestyle & personal style guides
            '43' => 'YNW',
            '44' => 'YD',
            '45' => 'YNC',
            //'46' => '', Self-help & personal development
            '47' => 'YFD',
            '48' => 'YNW',
            '49' => 'YNJ',
            //'50' => '', Philosophy
            '51' => 'XAM',
        ];

        // Use different mapping for children and young adults
        if (($this->product->MainGroup === 3 || $this->product->MainGroup === 4) && array_key_exists($this->product->SubGroup, $themaMappingTableChildren)) {
            return $themaMappingTableChildren[$this->product->SubGroup];
        }

        // Adults Thema mapping
        if (array_key_exists($this->product->SubGroup, $themaMappingTableAdults)) {
            return $themaMappingTableAdults[$this->product->SubGroup];
        }

        return null;
    }

    /**
     * Get the subjects from Finna
     * @return array
     */
    public function getSubjectWords()
    {
        // Fetch subjects from Finna API
        $client = new Client();

        $response = $client->get('https://api.finna.fi/v1/search', [
            'query' => [
                'lookfor' => $this->product->EAN,
                'filter[0]' => 'format:0/Book/',
                'field[]' => 'subjectsExtended',
            ]]);

        $json = json_decode($response->getBody()->getContents());

        // Array for
        $keywords = [];

        // Check if we found the book and have subjects
        if ($json->resultCount > 0 && isset($json->records[0]->subjectsExtended)) {
            foreach ($json->records as $record) {
                foreach ($record->subjectsExtended as $subject) {
                    // Detect the subjects ontology if type is subject
                    if (isset($subject->type) && isset($subject->source) && $subject->type === 'topic') {
                        switch ($subject->source) {
                            case 'kaunokki':
                                $subjectSchemeIdentifier = '69';
                                $subjectSchemeName = 'KAUNO - ontology for fiction';
                                break;
                            case 'yso':
                                $subjectSchemeIdentifier = '71';
                                $subjectSchemeName = 'YSO - General Finnish ontology';
                                break;
                            case 'ysa':
                                $subjectSchemeIdentifier = '64';
                                $subjectSchemeName = 'YSA - General Finnish thesaurus';
                                break;
                            case 'allars':
                                $subjectSchemeIdentifier = '65';
                                $subjectSchemeName = 'Allmän tesaurus på svenska';
                                break;
                            default:
                                $subjectSchemeIdentifier = null;
                                $subjectSchemeName = 'Unknown';
                                break;
                        }

                        // Go through all the headings/subjects
                        if (isset($subject->heading)) {
                            foreach ($subject->heading as $heading) {
                                $keywords[] = [
                                    'SubjectSchemeIdentifier' => $subjectSchemeIdentifier,
                                    'SubjectSchemeName' => $subjectSchemeName,
                                    'SubjectCode' => $heading,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $keywords;
    }

    /**
     * Get Finnish book trade categorisations - See http://www.onixkeskus.fi/onix/misc/popup.jsp?page=onix_help_subjectcategorisation
     * @return array
     */
    public function getFinnishBookTradeCategorisations()
    {
        // Array holding the list of categorisation values
        $categorisations = [];

        // Get subject code
        $subjectCode = $this->getLookupValue(293, $this->product->LiteratureGroup);

        // Go through all the characters
        for ($i = 0; $i <= strlen($subjectCode); $i++) {
            $char = substr($subjectCode, $i, 1);

            if (ctype_alpha($char) === true) {
                $categorisations[] = [
                    'SubjectSchemeIdentifier' => '73',
                    'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus',
                    'SubjectCode' => $char,
                ];
            }
        }

        return $categorisations;
    }

    /**
     * Return the Fiktiivisen aineiston lisäluokitus if applicable
     * @return string|null
     */
    public function getFiktiivisenAineistonLisaluokitus()
    {
        // Mapping table from Schilling sub group to Fiktiivisen aineiston lisäluokitus
        $mappingTable = [
            '2' => 'Fantasia',
            '4' => 'Historia',
            '5' => 'Huumori',
            '8' => 'Jännitys',
            '17' => 'Eläimet',
            '22' => 'Novellit',
            '31' => 'Scifi',
            '34' => 'Uskonto',
            '35' => 'Romantiikka',
            '43' => 'Urheilu',
            '47' => 'Kauhu',
            '48' => 'Erä',
            '49' => 'Sota',
        ];

        // Return "Fiktiivisen aineiston lisäluokitus" if mapping exist and main group is not "Tietokirjallisuus" aka Non-fiction
        if ($this->product->MainGroup !== '7' && array_key_exists($this->product->SubGroup, $mappingTable)) {
            return $mappingTable[$this->product->SubGroup];
        } else {
            return null;
        }
    }

    /**
     * Return Thema interest age / special interest qualifier based on the Schilling age group
     * @return string|null
     */
    public function getThemaInterestAge()
    {
        $mappingTable = [
            '0+' => '5AB', // For children c 0–2 years
            '3+' => '5AC', // Interest age: from c 3 years
            '5+' => '5AF', // Interest age: from c 5 years
            '7+' => '5AH', // Interest age: from c 7 years
            '9+' => '5AK', // Interest age: from c 9 years
            '10+' => '5AL', // Interest age: from c 10 years
            '12+' => '5AN', // Interest age: from c 12 years
            '15+' => '5AQ', // Interest age: from c 14 years - Please note that the Thema qualifiers end on 14+, so we have to use that
        ];

        if (array_key_exists($this->product->AgeGroup, $mappingTable)) {
            return $mappingTable[$this->product->AgeGroup];
        } else {
            return null;
        }
    }

    /**
     * Convert Schilling role to an Onix codelist 17: Contributor role code
     * @param  string $role
     * @return string|null
     */
    public function getContributorRole($role)
    {
        // Mapping and role priorities
        $roleMappings = [
            'AUT' => 'A01',
            'EIC' => 'B11',
            'EDA' => 'B01',
            'IND' => 'A34',
            'PRE' => 'A15',
            'FOR' => 'A23',
            'INT' => 'A24',
            'PRO' => 'A16',
            'AFT' => 'A19',
            'EPI' => 'A22',
            'ILL' => 'A12',
            'PHO' => 'A13',
            'REA' => 'E07',
            'TRA' => 'B06',
            'GDE' => 'A36',
            'CDE' => 'A36',
            'COM' => 'A06',
            'ARR' => 'B25',
            'MAP' => 'A39',
            'AST' => 'Z01',
            'EDT' => 'B21',
        ];

        if (!array_key_exists($role, $roleMappings)) {
            return null;
        }

        return $roleMappings[$role];
    }

    /**
     * Is the product confidential?
     * @return boolean
     */
    public function isConfidential()
    {
        return ($this->product->NotifyCode === 6) ? true : false;
    }

    /**
     * Get the products cost center
     * @return int|null
     */
    public function getCostCenter()
    {
        // Product with 2 dimensions (cost center and EAN)
        if (isset($this->product->Dimensions) && count($this->product->Dimensions) > 0) {
            return intval($this->product->Dimensions[0]);
        }

        return null;
    }

    /**
     * Get the products media type
     * @return string
     */
    public function getMediaType()
    {
        return (empty($this->product->MediaType)) ? null : $this->product->MediaType;
    }

    /**
     * Get the products binding code
     * @return string
     */
    public function getBindingCode()
    {
        return (empty($this->product->BindingCode)) ? null : $this->product->BindingCode;
    }

    /**
     * Get the products discount group
     * @return int|null
     */
    public function getDiscountGroup()
    {
        return (empty($this->product->DiscountGroup)) ? null : $this->product->DiscountGroup;
    }

    /**
     * Get the products status code
     * @return int
     */
    public function getStatusCode()
    {
        return intval($this->product->NotifyCode);
    }

    /**
     * Get the number of products in the series
     * @return int|null
     */
    public function getProductsInSeries()
    {
        if (!empty($this->product->BookSeries) && $this->product->PartsInSeries > 0) {
            return intval($this->product->PartsInSeries);
        }

        return null;
    }

    /**
     * Is the product immaterial?
     * @return boolean
     */
    public function isImmaterial()
    {
        return ($this->product->PlanningCode === 'y') ? true : false;
    }

    /**
     * Is the product a Print On Demand product?
     * @return boolean
     */
    public function isPrintOnDemand()
    {
        return ($this->product->NotifyCode === 8) ? true : false;
    }

    /**
     * Get internal product number
     * @return string|null
     */
    public function getInternalProdNo()
    {
        return (!empty($this->product->InternalProdNo)) ? $this->product->InternalProdNo : null;
    }

    /**
     * Get customs number
     * @return int|null
     */
    public function getCustomsNumber()
    {
        return (!empty($this->product->CustomsNumber)) ? intval($this->product->CustomsNumber) : null;
    }

    /**
     * Get the products library class
     * @return string|null
     */
    public function getLibraryClass()
    {
        return $this->getLookupValue(293, $this->product->LiteratureGroup);
    }

    /**
     * Get the products marketing category
     * @return string|null
     */
    public function getMarketingCategory()
    {
        // Get the latest print project attached to the product
        $projectNumber = $this->getLatestPrintProject($this->product->ProjectId);

        // Fetch project
        $schilling = new Project(
            config('groschen.schilling.hostname'),
            config('groschen.schilling.port'),
            config('groschen.schilling.username'),
            config('groschen.schilling.password'),
            config('groschen.schilling.company')
        );

        $project = $schilling->getProjects(['ProjectNo' => $projectNumber])[0];

        if (empty($project->BookDataGroup)) {
            return null;
        }

        // Don't return those with "Do not use" group
        switch ($project->BookDataGroup) {
            case '1':
            case '5':
                return $this->getLookupValue(563, $project->BookDataGroup);
                break;
            default:
                return null;
                break;
        }
    }

    /**
     * Get the products sales season
     * @return string|null
     */
    public function getSalesSeason()
    {
        if (empty($this->product->ReviewCycle)) {
            return null;
        }

        return $this->getLookupValue(580, $this->product->ReviewCycle);
    }
}
