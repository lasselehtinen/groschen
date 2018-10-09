<?php
namespace lasselehtinen\Groschen;

use Cache;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Collection;
use Isbn;
use kamermans\OAuth2\GrantType\NullGrantType;
use kamermans\OAuth2\OAuth2Middleware;
use lasselehtinen\Groschen\Contracts\ProductInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use League\Uri\Modifiers\MergeQuery;
use League\Uri\Modifiers\RemoveQueryKeys;
use League\Uri\Schemes\Http as HttpUri;
use Njasm\Soundcloud\Soundcloud;

class OpusGroschen implements ProductInterface
{
    /**
     * Product number
     * @var string
     */
    private $productNumber;

    /**
     * Opus work ID
     * @var string
     */
    private $workId;

    /**
     * Opus production ID
     * @var string
     */
    private $productionId;

    /**
     * Raw product information
     * @var stdClass
     */
    private $product;

    /**
     * Raw product information
     * @var stdClass
     */
    private $workLevel;

    /**
     * Guzzle HTTP client
     * @var GuzzleHttp\Client
     */
    private $client;

    /**
     * @param string $productNumber
     */
    public function __construct($productNumber)
    {
        // Get access token for Opus
        $accessToken = Cache::remember('accessToken', 1440, function () {
            $provider = new GenericProvider([
                'clientId' => config('groschen.opus.clientId'),
                'clientSecret' => config('groschen.opus.clientSecret'),
                'redirectUri' => url('oauth2/callback'),
                'urlAuthorize' => config('groschen.opus.urlAuthorize'),
                'urlAccessToken' => config('groschen.opus.urlAccessToken'),
                'urlResourceOwnerDetails' => config('groschen.opus.urlResourceOwnerDetails'),
            ]);

            // Try to get an access token using the resource owner password credentials grant
            return $provider->getAccessToken('password', [
                'username' => config('groschen.opus.username'),
                'password' => config('groschen.opus.password'),
                'scope' => 'opus',
            ]);
        });

        // Generate new OAuth middleware for Guzzle
        $oauth = new OAuth2Middleware(new NullGrantType);
        $oauth->setAccessToken([
            'access_token' => $accessToken->getToken(),
        ]);

        // Create new HandlerStack for Guzzle and push OAuth middleware
        $stack = HandlerStack::create();
        $stack->push($oauth);

        // Create Guzzle and push the OAuth middleware to the handler stack
        $this->client = new Client([
            'base_uri' => config('groschen.opus.hostname'),
            'handler' => $stack,
            'auth' => 'oauth',
        ]);

        $this->productNumber = $productNumber;
        $this->workId = $this->searchProductions('workId');
        $this->productionId = $this->searchProductions('id');
        $this->product = $this->getProduct();
        $this->workLevel = $this->getWorkLevel();
    }

    /**
     * Searches for productions
     * @param  string $searchField
     * @param  string $return
     * @return string
     */
    public function searchProductions($return)
    {
        // Search for the ISBN in Opus
        $response = $this->client->get('work/v2/search/productions', [
            'query' => [
                'q' => $this->productNumber,
                'limit' => 1,
                'searchFields' => 'isbn',
                '$select' => $return,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());

        if (count($json->results) == 0) {
            throw new Exception('Could not find product in Opus.');
        }

        if (count($json->results) > 1) {
            throw new Exception('Too many results found in Opus.');
        }

        // Check that attribute exists
        if (empty($json->results[0]->document->{$return})) {
            throw new Exception('The return field in Opus does not exist in response.');
        }

        return $json->results[0]->document->{$return};
    }

    /**
     * Get the product information
     * @return stdClass
     */
    public function getProduct()
    {
        // Get the production from Opus
        $response = $this->client->get('work/v1/works/' . $this->workId . '/productions/' . $this->productionId);

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the work level information
     * @return stdClass
     */
    public function getWorkLevel()
    {
        // Get the production from Opus
        $response = $this->client->get('work/v1/works/' . $this->workId);

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Return the raw product information
     * @return stdClass
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
            'id_value' => $this->product->isbn,
        ]);

        // GTIN-13 and ISBN-13
        if (!empty($this->product->isbn) && $this->isValidGtin($this->product->isbn)) {
            foreach (['03', '15'] as $id_value) {
                $productIdentifiers->push([
                    'ProductIDType' => $id_value,
                    'id_value' => $this->product->isbn,
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
        // Determine whether it is a normal Single-item retail or Trade-only product - TODO, 20 for trade items
        return '00';
    }

    /**
     * Get the products from (Onix codelist 150) - TODO
     * @return string|null
     */
    public function getProductForm()
    {
        return null;
    }

    /**
     * Get the products form detail (Onix codelist 175) - TODO
     * @return string|null
     */
    public function getProductFormDetail()
    {
        return null;
    }

    /**
     * Get the products form features
     * @return Collection
     */
    public function getProductFormFeatures()
    {
        $productFormFeatures = new Collection;

        // Add ePub version
        switch ($this->product->technicalProductionType->name) {
            case 'EPUB2':
                $featureValue = '101A';
                break;
            case 'EPUB3':
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

        if (isset($this->product->series)) {
            $collections->push([
                'CollectionType' => '10', [
                    'TitleDetail' => [
                        'TitleType' => '01',
                        'TitleElement' => [
                            'TitleElementLevel' => '01',
                            'TitleText' => $this->product->series->name,
                        ],
                    ],
                ],
            ]);

            // Add Collection sequence if product has NumberInSeries

            if (isset($this->product->numberInSeries)) {
                $collections = $collections->map(function ($collection) {
                    // Add CollectionSequence to Collection
                    $collectionSequence = [
                        'CollectionSequenceType' => '03',
                        'CollectionSequenceNumber' => $this->product->numberInSeries,
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
        $titleDetails->push([
            'TitleType' => '01',
            'TitleElement' => [
                'TitleElementLevel' => '01',
                'TitleText' => $this->product->title,
            ],
        ]);

        // Add subtitle
        if (!empty($this->product->subtitle)) {
            $titleDetails = $titleDetails->map(function ($titleDetail) {
                $titleDetail['TitleElement']['Subtitle'] = $this->product->subtitle;
                return $titleDetail;
            });
        }

        // Original title
        if (!empty($this->product->originalTitle)) {
            $titleDetails->push([
                'TitleType' => '03',
                'TitleElement' => [
                    'TitleElementLevel' => '01',
                    'TitleText' => $this->product->originalTitle,
                ],
            ]);
        }

        // Distributors title
        $titleDetails->push([
            'TitleType' => '10',
            'TitleElement' => [
                'TitleElementLevel' => '01',
                'TitleText' => $this->product->deliveryNoteTitle,
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
        if (!isset($this->product->members)) {
            return $contributors;
        }

        // Init SequenceNumber
        $sequenceNumber = 1;

        foreach ($this->product->members as $contributor) {
            // Get contributor role
            $contributorRole = $this->getContributorRole($contributor->role->id);

            if (!is_null($contributorRole)) {
                // Add to collection
                $contributors->push([
                    'SequenceNumber' => $sequenceNumber,
                    'ContributorRole' => $contributorRole,
                    'NameIdentifier' => [
                        'NameIDType' => '01',
                        'IDTypeName' => 'Internal ID',
                        'IDValue' => $contributor->contact->id,
                    ],
                    'PersonNameInverted' => $contributor->contact->lastName . ', ' . $contributor->contact->firstName,
                    'NamesBeforeKey' => $contributor->contact->firstName,
                    'KeyNames' => $contributor->contact->lastName,
                ]);
            }

            $sequenceNumber++;
        }

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
        if (!empty($this->product->languages)) {
            foreach ($this->product->languages as $language) {
                $languages->push([
                    'LanguageRole' => '01',
                    'LanguageCode' => $language->id,
                ]);
            }
        }

        // Add original languages
        foreach ($this->workLevel->originalLanguages as $originalLanguage) {
            $languages->push([
                'LanguageRole' => '02',
                'LanguageCode' => $originalLanguage->id,
            ]);
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
        if (isset($this->product->pages) && $this->product->pages > 0) {
            $extents->push([
                'ExtentType' => '00',
                'ExtentValue' => $this->product->pages,
                'ExtentUnit' => '03',
            ]);
        }

        // Audio duration, convert from HH:MM to HHHMM - TODO - audioPlaytimeHours what is the accuracy? No minutes?
        if (isset($this->product->audioPlaytimeHours)) {
            // Some products do not have play time in minutes, fill minutes with 00
            if (!isset($this->product->audioPlaytimeMinutes)) {
                $extentValue = str_pad($this->product->audioPlaytimeHours, 3, '0', STR_PAD_LEFT) . '00';
            } else {
                $extentValue = str_pad($this->product->audioPlaytimeHours, 3, '0', STR_PAD_LEFT) . str_pad($this->product->audioPlaytimeMinutes, 2, '0', STR_PAD_LEFT);
            }

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

        if ($this->product->publishingHouse->name !== $this->product->brand->name) {
            $imprints->push([
                'ImprintName' => $this->product->brand->name,
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
        return round($this->product->resellerPrice, 2);
    }

    /**
     * Get the products recommended retail price RRP excluding VAT
     * @return float|null
     */
    public function getPriceExcludingVat()
    {
        return round($this->getPrice() / (($this->getTaxRate() + 100) / 100), 2);
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
        $measures->push(['MeasureType' => '01', 'Measurement' => intval($this->product->height * 1000), 'MeasureUnitCode' => 'mm']);
        $measures->push(['MeasureType' => '02', 'Measurement' => intval($this->product->width * 1000), 'MeasureUnitCode' => 'mm']);
        $measures->push(['MeasureType' => '03', 'Measurement' => intval($this->product->depth * 1000), 'MeasureUnitCode' => 'mm']);

        // Add weight
        $measures->push(['MeasureType' => '08', 'Measurement' => intval($this->product->weight * 1000), 'MeasureUnitCode' => 'gr']);

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

        //dd($this->product);

        // Thema subject category
        if (isset($this->product->thema->id)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '93',
                'SubjectSchemeName' => 'Thema subject category',
                'SubjectCode' => $this->product->thema->id,
            ]);
        }

        // BIC subject category
        if (isset($this->product->thema->customProperties->bic2)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '12',
                'SubjectSchemeName' => 'BIC subject category',
                'SubjectCode' => $this->product->thema->customProperties->bic2,
            ]);
        }

        // Main product group
        if (isset($this->product->bonnierRightsCategory->name)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Bonnier Books Finland - Main product group',
                'SubjectCode' => $this->product->bonnierRightsCategory->name,
            ]);
        }

        // Sub product group
        if (isset($this->product->bonnierRightsSubCategory->name)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group',
                'SubjectCode' => trim($this->product->bonnierRightsSubCategory->name),
            ]);
        }

        return $subjects;
    }

    /**
     * Get the products text contents
     * @return Collection
     */
    public function getTextContents()
    {
        $textContents = new Collection;

        // Get texts
        $response = $this->client->get('work/v1/works/' . $this->workId . '/productions/' . $this->productionId . '/texts');
        $texts = json_decode($response->getBody()->getContents());

        if (!empty($texts->texts)) {
            foreach ($texts->texts as $text) {
                // Pr. titelinformation
                if ($text->textType->id === '15') {
                    $textContents->push([
                        'TextType' => '03',
                        'ContentAudience' => '00',
                        'Text' => $this->purifyHtml($text->text),
                    ]);
                }
            }
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

        // Add main publisher
        $publishers->push(['PublishingRole' => '01', 'PublisherName' => $this->product->publishingHouse->name]);

        return $publishers;
    }

    /**
     * Get the products publishing status (Onix codelist 64)
     * @return string
     */
    public function getPublishingStatus()
    {
    }

    /**
     * get the product publishing dates
     * @return Collection
     */
    public function getPublishingDates()
    {
        $publishingDates = new Collection;

        // Add original publishing date
        $publishingDate = DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->publishingDate);
        $publishingDates->push(['PublishingDateRole' => '01', 'Date' => $publishingDate->format('Ymd')]);

        // Get prints
        $response = $this->client->get('work/v1/works/' . $this->workId . '/productions/' . $this->productionId . '/printchanges');
        $prints = json_decode($response->getBody()->getContents());

        // Latest reprint date
        $latestPrint = end($prints->prints);

        foreach ($latestPrint->timePlanEntries as $timePlanEntry) {
            // Delivery in stock
            if ($timePlanEntry->type->id === '11') {
                $lastReprintDate = DateTime::createFromFormat('Y-m-d*H:i:s', $timePlanEntry->actual);
                $publishingDates->push(['PublishingDateRole' => '12', 'Date' => $lastReprintDate->format('Ymd')]);
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

        // Go through all Price Types
        foreach ($priceTypes as $priceType) {
            // Calculate price with tax included
            if ($priceType['TaxIncluded'] === false) {
                $priceAmount = $this->getPriceExcludingVat();
            } else {
                $priceAmount = $this->getPrice();
            }

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
        // Form taxable and tax amount
        if ($priceType['TaxIncluded'] === true) {
            $taxAmount = $this->getPrice() - $this->getPriceExcludingVat();
        } else {
            $taxAmount = 0;
        }

        return [
            'TaxType' => '01',
            'TaxRateCode' => $priceType['TaxRateCode'],
            'TaxRatePercent' => $this->getTaxRate(),
            'TaxableAmount' => $this->getPriceExcludingVat(),
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
                'metadataToReturn' => 'height, width',
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
                    'ResourceVersionFeatures' => [
                        [
                            'ResourceVersionFeatureType' => '02',
                            'FeatureValue' => $hit->metadata->height,
                        ],
                        [
                            'ResourceVersionFeatureType' => '03',
                            'FeatureValue' => $hit->metadata->width,
                        ],
                    ],
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
     * Get the related products
     * @return Collection
     */
    public function getRelatedProducts()
    {
        $relatedProducts = new Collection;

        foreach ($this->workLevel->productions as $production) {
            // Do not add current product
            if ($production->isbn !== $this->productNumber) {
                $relatedProducts->push([
                    'ProductRelationCode' => '06',
                    'ProductIdentifiers' => [
                        [
                            'ProductIDType' => '03',
                            'IDValue' => $production->isbn,
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
        return floatval(preg_replace('/[^0-9]/', '', $this->product->taxCode->name));
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
                            default:
                                $subjectSchemeIdentifier = null;
                                $subjectSchemeName = 'Unknown';
                                break;
                        }

                        // Go through all the headings/subjects
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
     * @return string
     */
    public function getContributorRole($role)
    {
        // Mapping and role priorities
        $roleMappings = [
            1 => 'A01',
            24 => 'B11',
            '' => 'B01',
            '' => 'A34',
            '' => 'A15',
            '' => 'A23',
            '' => 'A24',
            '' => 'A16',
            '' => 'A19',
            '' => 'A22',
            '' => 'A12',
            '' => 'A13',
            '' => 'E07',
            '' => 'B06',
            '' => 'A36',
            '' => 'A36',
            '' => 'A06',
            '' => 'B25',
            '' => 'A39',
            '' => 'Z01',
            '' => 'B21',
        ];

        if (array_key_exists($role, $roleMappings)) {
            return $roleMappings[$role];
        } else {
            return null;
        }
    }

    /**
     * Is the product confidential?
     * @return boolean
     */
    public function isConfidential()
    {
        return $this->product->isPublished === false;
    }

    /**
     * Get the products cost center
     * @return int|null
     */
    public function getCostCenter()
    {
        if (isset($this->product->costCenter)) {
            return intval($this->product->costCenter->id);
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
        return null;
    }

    /**
     * Get the number of products in the series
     * @return void
     */
    public function getProductsInSeries()
    {
        if (isset($this->product->externalInformation->numberInSeries)) {
            return intval($this->product->externalInformation->numberInSeries);
        }

        return null;
    }

    /**
     * Is the product immaterial?
     * @return boolean
     */
    public function isImmaterial()
    {
        return ($this->product->dispositionCode->id === 'y') ? true : false;
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
        return intval($this->product->customsNumber);
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
        if (!isset($this->product->seasonYear)) {
            return null;
        }

        // Form sales period
        switch ($this->product->seasonPeriod->name) {
            case 'Höst':
                $period = 2;
                break;
            case 'Spring':
                $period = 1;
                break;
        }

        return $this->product->seasonYear->name . '/' . $period;
    }

    /**
     * Get the products audience groups
     * @return Collection
     */
    public function getAudiences()
    {
        // Collection for audiences
        $audiences = new Collection;

        // If no age group defined, General/trade
        if (!isset($this->product->interestAge)) {
            $audienceCodeValue = '01'; // General/trade
        } else {
            // Map the age group to Audience
            switch ($this->product->interestAge->name) {
                case '0-3':
                case '3-6':
                case '6-9':
                case '9-12':
                case '12-15':
                    $audienceCodeValue = '02'; // Children/juvenile
                    break;
                case 'Unga vuxna':
                    $audienceCodeValue = '03'; // Young adult
                    break;
            }
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

        if (!empty($this->product->interestAge) && $this->product->interestAge->name !== 'Unga vuxna') {
            list($fromAge, $toAge) = explode('-', $this->product->interestAge->name);

            $audienceRanges->push([
                'AudienceRangeQualifier' => 17,
                'AudienceRangeScopes' => [
                    [
                        'AudienceRangePrecision' => '03', // From
                        'AudienceRangeValue' => intval($fromAge),
                    ],
                    [
                        'AudienceRangePrecision' => '04', // To
                        'AudienceRangeValue' => intval($toAge),
                    ],
                ],
            ]);
        }

        return $audienceRanges;
    }

    /**
     * Get the latest stock arrival date
     * @return DateTime|null
     */
    public function getLatestStockArrivalDate()
    {
        foreach ($this->product->activePrint->timePlan->entries as $timeplan) {
            if ($timeplan->type->name === 'Delivery to warehouse') {
                return DateTime::createFromFormat('Y-m-d*H:i:s', $timeplan->planned);
            }
        }

        return null;
    }

    /**
     * Get the latest print number
     * @return int|null
     */
    public function getLatestPrintNumber()
    {
        if(!isset($this->product->activePrint->printNumber)) {
            return null;
        }

        return $this->product->activePrint->printNumber;
    }

    /**
     * Is the product allowed for subscription?
     * @return boolean
     */
    public function isSubscriptionProduct()
    {
        return (bool) false;
    }

    /**
     * Get the sales restrictions
     * @return Collection
     */
    public function getSalesRestrictions()
    {
        return new Collection;
    }

}
