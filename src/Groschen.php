<?php

namespace lasselehtinen\Groschen;

use Biblys\Isbn\Isbn;
use Cache;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use kamermans\OAuth2\GrantType\NullGrantType;
use kamermans\OAuth2\OAuth2Middleware;
use Laravel\Nightwatch\Facades\Nightwatch;
use lasselehtinen\Groschen\Contracts\ProductInterface;
use League\ISO3166\ISO3166;
use League\OAuth2\Client\Provider\GenericProvider;
use League\Uri\Uri;
use League\Uri\UriModifier;
use Real\Validator\Gtin;
use stdClass;
use WhiteCube\Lingua\LanguagesRepository;
use WhiteCube\Lingua\Service as Lingua;

class Groschen implements ProductInterface
{
    /**
     * Product number
     *
     * @var string
     */
    private $productNumber;

    /**
     * Mockingbird work ID
     *
     * @var string
     */
    private $workId;

    /**
     * Mockingbird production ID
     *
     * @var string
     */
    private $productionId;

    /**
     * Raw product information
     *
     * @var stdClass
     */
    private $product;

    /**
     * Raw product information
     *
     * @var stdClass
     */
    private $workLevel;

    /**
     * Whether the work level is already fetched
     *
     * @var bool
     */
    private $workLevelFetched;

    /**
     * Guzzle HTTP client
     *
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * Guzzle HTTP client
     *
     * @var \GuzzleHttp\Client
     */
    private $searchClient;

    /**
     * @param  string  $productNumber
     */
    public function __construct($productNumber)
    {
        // Get access token for Mockingbird
        $accessToken = Cache::remember('accessToken', 3599, function () {
            $provider = new GenericProvider([
                'clientId' => config('groschen.mockingbird.clientId'),
                'clientSecret' => config('groschen.mockingbird.clientSecret'),
                'redirectUri' => url('oauth2/callback'),
                'urlAuthorize' => config('groschen.mockingbird.urlAuthorize'),
                'urlAccessToken' => config('groschen.mockingbird.urlAccessToken'),
                'urlResourceOwnerDetails' => config('groschen.mockingbird.urlResourceOwnerDetails'),
            ]);

            // Try to get an access token using the resource owner password credentials grant
            return $provider->getAccessToken('password', [
                'username' => config('groschen.mockingbird.username'),
                'password' => config('groschen.mockingbird.password'),
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

        // Push Nightwatch's middleware
        if (app()->runningUnitTests() === false) {
            $stack->push(Nightwatch::guzzleMiddleware());
        }

        // Create Guzzle and push the OAuth middleware to the handler stack
        $this->client = new Client([
            'base_uri' => config('groschen.mockingbird.work_api_hostname'),
            'handler' => $stack,
            'auth' => 'oauth',
            'headers' => [
                'User-Agent' => gethostname().' / '.' PHP/'.PHP_VERSION,
            ],
        ]);

        // Create Guzzle and push the OAuth middleware to the handler stack
        $this->searchClient = new Client([
            'base_uri' => config('groschen.mockingbird.contact_api_hostname'),
            'handler' => $stack,
            'auth' => 'oauth',
            'headers' => [
                'User-Agent' => gethostname().' / '.' PHP/'.PHP_VERSION,
            ],
        ]);

        $this->productNumber = $productNumber;
        [$this->workId, $this->productionId] = $this->getEditionAndWorkId();
        $this->product = $this->getProduct();
        $this->workLevelFetched = false;
    }

    /**
     * Get the editions and works id
     *
     * @return array
     */
    public function getEditionAndWorkId()
    {
        // Search for the ISBN in Mockingbird
        $response = $this->client->get('v2/search/productions', [
            'query' => [
                'q' => $this->productNumber,
                'searchFields' => 'isbn',
                '$select' => 'workId,id,isCancelled',
                '$filter' => '(isCancelled eq true or isCancelled eq false)',
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());

        if (count($json->results) == 0) {
            throw new Exception('Could not find product in Mockingbird.');
        }

        // If we get multiple results, prefer the one that is not deactivated
        if (count($json->results) > 1) {
            // Remove those that are deactivated
            foreach ($json->results as $key => $result) {
                if ($result->document->isCancelled === true) {
                    unset($json->results[$key]);
                }
            }

            if (count($json->results) > 1) {
                throw new Exception('ISBN has multiple active editions');
            }

            $key = array_key_first($json->results);

            return [
                $json->results[$key]->document->workId,
                $json->results[$key]->document->id,
            ];
        }

        return [
            $json->results[0]->document->workId,
            $json->results[0]->document->id,
        ];
    }

    /**
     * Returns the editions work id
     *
     * @return int
     */
    public function getWorkId()
    {
        return intval($this->workId);
    }

    /**
     * Returns the editions id
     *
     * @return int
     */
    public function getEditionId()
    {
        return intval($this->productionId);
    }

    /**
     * Get the product information
     *
     * @return stdClass
     */
    public function getProduct()
    {
        // Get the production from Mockingbird
        try {
            $response = $this->client->get('/v2/editions/'.$this->productionId);
        } catch (ServerException $e) {
            throw new Exception('Server exception: '.$e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the print production plan
     *
     * @return mixed
     */
    public function getPrintProductionPlan()
    {
        // Get the production plan from Mockingbird
        try {
            $response = $this->client->get('/v1/works/'.$this->workId.'/productions/'.$this->productionId.'/printchanges');
        } catch (ServerException $e) {
            throw new Exception('Server exception: '.$e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the communication plan
     *
     * @return mixed
     */
    public function getCommunicationPlan()
    {
        // Get the communication plan from Mockingbird
        try {
            $response = $this->client->get('/v1/works/'.$this->workId.'/communicationplan');
        } catch (ServerException $e) {
            throw new Exception('Server exception: '.$e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the work level information
     *
     * @return stdClass
     */
    public function getWorkLevel()
    {
        if ($this->workLevelFetched === false) {
            // Get the production from Mockingbird
            $response = $this->client->get('/v1/works/'.$this->workId);
            $this->workLevel = json_decode($response->getBody()->getContents());
            $this->workLevelFetched = true;
        }

        return $this->workLevel;
    }

    /**
     * Get the products identifiers
     *
     * @return Collection
     */
    public function getProductIdentifiers()
    {
        $productIdentifiers = new Collection;

        // Propietary internal product number
        if (property_exists($this->product, 'isbn') && ! empty($this->product->isbn)) {
            $productIdentifiers->push([
                'ProductIDType' => '01',
                'id_type_name' => 'Werner Söderström Ltd - Internal product number',
                'id_value' => intval($this->product->isbn),
            ]);
        }

        // GTIN-13
        if (! empty($this->product->isbn) && $this->isValidGtin($this->product->isbn)) {
            $productIdentifiers->push([
                'ProductIDType' => '03',
                'id_value' => intval($this->product->isbn),
            ]);
        }

        // ISBN-13
        if (! empty($this->product->isbn) && $this->isValidIsbn13($this->product->isbn)) {
            $productIdentifiers->push([
                'ProductIDType' => '15',
                'id_value' => intval($this->product->isbn),
            ]);
        }

        return $productIdentifiers;
    }

    /**
     * Get the products composition (Onix Codelist 2)
     *
     * @return string|null
     */
    public function getProductComposition()
    {
        // Determine whether it is a normal Single-item retail or Trade-only product - TODO, 20 for trade items
        return '00';
    }

    /**
     * Get the products type
     *
     * @return string
     */
    public function getProductType()
    {
        return $this->product->bindingCode->name;
    }

    /**
     * Get the products from (Onix codelist 150)
     *
     * @return string|null
     */
    public function getProductForm()
    {
        if (property_exists($this->product->bindingCode->customProperties, 'onixProductForm') === false) {
            throw new Exception('Binding code '.$this->product->bindingCode->name.' does not have ProductForm or OnixProductForm in custom properties. Contact support to add.');
        }

        return $this->product->bindingCode->customProperties->onixProductForm;
    }

    /**
     * Get the products form details (Onix codelist 175)
     *
     * @return Collection
     */
    public function getProductFormDetails()
    {
        $productFormDetails = new Collection;

        if (property_exists($this->product->bindingCode->customProperties, 'productFormDetail') === true) {
            $productFormDetails->push($this->product->bindingCode->customProperties->productFormDetail);
        }

        if (property_exists($this->product->bindingCode->customProperties, 'OnixProductFormDetail') === true) {
            $productFormDetails->push($this->product->bindingCode->customProperties->OnixProductFormDetail);
        }

        // Add additional entry for ePub 3's that contain audio
        if (isset($this->product->activePrint->ebookHasAudioFile) && $this->product->activePrint->ebookHasAudioFile === true) {
            $productFormDetails->push('A305');
        }

        // Reflowable / Fixed layout ePub 3's
        if (property_exists($this->product, 'technicalProductionType') && property_exists($this->product->technicalProductionType, 'customProperties') && property_exists($this->product->technicalProductionType->customProperties, 'onixProductFormFeatureValueEpub') && in_array($this->product->technicalProductionType->customProperties->onixProductFormFeatureValueEpub, ['101A', '101B', '101C', '101D', '101E', '101F', '107C', '107D', '107G', '107J', '116A', '116B', '116C']) === false) {
            $productFormDetails->push($this->product->technicalProductionType->customProperties->onixProductFormFeatureValueEpub);
        }

        // Workaround for old ePub2 without technicalProductionType
        if ($this->getProductType() === 'ePub2') {
            $productFormDetails->push('E200');
        }

        // Add technical detail if product is not immaterial
        if ($this->isImmaterial() === false) {
            // Headband
            $headBand = $this->getTechnicalData()->where('partName', 'bookBinding')->pluck('headBand')->first();

            if (! empty($headBand)) {
                $productFormDetails->push('B407');
            }

            // Ribbon marker
            $ribbon = $this->getTechnicalData()->where('partName', 'bookBinding')->pluck('ribbonMarker')->first();

            if (! empty($ribbon)) {
                $productFormDetails->push('B506');
            }

            // Printed endpapers
            $endPaperColors = $this->getTechnicalData()->where('partName', 'endPapers')->pluck('colors')->first();

            if (! empty($endPaperColors)) {
                [$frontColors, $backColors] = explode('/', $endPaperColors);
                $endPaperIsPrinted = ($frontColors > 0 || $backColors > 0);

                if ($endPaperIsPrinted) {
                    $productFormDetails->push('B408');
                }
            }

            // Check if glued or sewn
            $technicalBindingType = $this->getTechnicalBindingType();

            if (Str::contains($technicalBindingType, 'sewn binding')) {
                $productFormDetails->push('B304');
            }

            if (Str::contains($technicalBindingType, 'glued binding')) {
                $productFormDetails->push('B305');
            }

            // Saddle stiched
            if ($this->getProductType() === 'Saddle-stitched' || Str::contains($technicalBindingType, 'saddle stitch')) {
                $productFormDetails->push('B310');
            }

            // Paperback with flaps
            if (Str::startsWith($technicalBindingType, 'Soft cover with flaps')) {
                $productFormDetails->push('B504');
            }

            // Dust jacket
            if (Str::startsWith($technicalBindingType, 'Dust jacket')) {
                $productFormDetails->push('B502');
            }

            // Paper over boards
            if (Str::startsWith($technicalBindingType, 'Printed cover')) {
                $productFormDetails->push('B402');
            }

            // Lamination
            $lamination = $this->getTechnicalData()->where('partName', 'printedCover')->pluck('lamination')->first();

            if (! empty($lamination)) {
                $productFormDetails->push('B415');
            }

        }

        return $productFormDetails;
    }

    /**
     * Get the products technical binding type
     *
     * @return string|null
     */
    public function getTechnicalBindingType()
    {
        if (property_exists($this->product, 'technicalProductionType') === false) {
            return null;
        }

        return $this->product->technicalProductionType->name;
    }

    /**
     * Get the products form features
     *
     * @return Collection
     */
    public function getProductFormFeatures()
    {
        $productFormFeatures = new Collection;

        // Add ePub version if exists
        if (property_exists($this->product->activePrint, 'ebookVersion')) {
            switch ($this->product->activePrint->ebookVersion) {
                case '2.0':
                case '2.0.1':
                    $featureValue = '101A';
                    break;

                case '3.0':
                    $featureValue = '101B';
                    break;
                case '3.0.1':
                    $featureValue = '101C';
                    break;
                case '3.1':
                    $featureValue = '101D';
                    break;
                case '3.2':
                    $featureValue = '101E';
                    break;
                case '3.3':
                    $featureValue = '101F';
                    break;

                default:
                    throw new Exception('Unknown ePub version '.$this->product->activePrint->ebookVersion.'. Cannot map to Onix ProductFormFeatureValue.');
            }

            $productFormFeatures->push([
                'ProductFormFeatureType' => '15',
                'ProductFormFeatureValue' => $featureValue,
            ]);
        }

        /*
        // Check if we have spesific product form features for hazards
        if (property_exists($this->product->productionDetails, 'hazardIds') && is_array($this->product->productionDetails->hazardIds)) {
            // Get hazard types for mapping
            $hazardTypesMapping = $this->getProductionDetailsOptions('hazards');

            foreach ($this->product->productionDetails->hazardIds as $hazardId) {
                $onixCode = $hazardTypesMapping->where('id', $hazardId)->pluck('onixCode')->first();

                if (is_null($onixCode)) {
                    throw new Exception('Could not find mapping for hazard type with id '.$hazardId);
                }

                $productFormFeatures->push([
                    'ProductFormFeatureType' => '12',
                    'ProductFormFeatureValue' => $onixCode,
                ]);
            }
        }

        // Check if we have spesific product form features for ePub accessiblity settings
        if (property_exists($this->product->productionDetails, 'accessibilityDetailIds') && is_array($this->product->productionDetails->accessibilityDetailIds)) {
            // Get accessibility details for mapping
            $accessibilityDetailsMapping = $this->getProductionDetailsOptions('accessibilityDetails');

            foreach ($this->product->productionDetails->accessibilityDetailIds as $accessibilityDetailId) {
                $onixCode = $accessibilityDetailsMapping->where('id', $accessibilityDetailId)->pluck('onixCode')->first();

                if (is_null($onixCode)) {
                    throw new Exception('Could not find mapping for ePub accessiblity type with id '.$accessibilityDetailId);
                }

                $productFormFeatures->push([
                    'ProductFormFeatureType' => '09',
                    'ProductFormFeatureValue' => $onixCode,
                ]);
            }
        }

        // Codelist 192 values 02 - 09 are picked from "Ebook standard"
        if (property_exists($this->product->productionDetails, 'ebookStandardId') && ! empty($this->product->productionDetails->ebookStandardId)) {
            // Get ebookStandard for mapping
            $ebookStandardsMapping = $this->getProductionDetailsOptions('ebookStandards');

            $onixCode = $ebookStandardsMapping->where('id', $this->product->productionDetails->ebookStandardId)->pluck('onixCode')->first();

            if (is_null($onixCode)) {
                throw new Exception('Could not find mapping for ePub accessiblity type with id '.$this->product->productionDetails->ebookStandardId);
            }

            $productFormFeatures->push([
                'ProductFormFeatureType' => '09',
                'ProductFormFeatureValue' => $onixCode,
            ]);
        }*/

        // ePub2's
        if ($this->getProductType() === 'ePub2') {
            $productFormFeaturesToAdd = [
                '09' => [
                    '09', // Inaccessible or known limited accessibility
                    '76', // EAA exception 2 – Disproportionate burden
                ],
                '12' => [
                    '00', // No known hazards or warnings
                ],
            ];

            // Accessibility summary for ePub2
            /*
            $productFormFeatures->push([
                'ProductFormFeatureType' => '09',
                'ProductFormFeatureValue' => '00',
                'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.',
            ]);
            */
        }

        // ePub3 - Fixed format
        if (property_exists($this->product, 'technicalProductionType') && $this->product->technicalProductionType->name === 'ePub3 – Fixed Format') {
            $productFormFeaturesToAdd = [
                '09' => [
                    '09', // Inaccessible or known limited accessibility
                    '77',  // EAA exception 3 - Fundamental alteration
                ],
                '12' => [
                    '00', // No known hazards or warnings
                ],
            ];

            // Accessibility summary for ePub3 - Fixed layout
            /*
            $productFormFeatures->push([
                'ProductFormFeatureType' => '09',
                'ProductFormFeatureValue' => '00',
                'ProductFormFeatureDescription' => 'Ulkoasua ei voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.',
            ]);
            */
        }

        // ePub3 - Reflowable
        if (property_exists($this->product, 'technicalProductionType') && $this->product->technicalProductionType->name === 'ePub3 – Reflowable') {
            $productFormFeaturesToAdd = [
                '09' => [
                    '04', // Epub accessibility specification 1.1
                    '36', // Appearance of all textual content can be modified
                    '52', // All non-decorative content supports reading without sight
                    '85', // WCAG level AA
                    '81', // WCAG v2.1
                ],
                '12' => [
                    '00', // No known hazards or warnings
                ],
            ];

            // Accessibility summary for ePub3 - Reflowable
            /*
            $productFormFeatures->push([
                'ProductFormFeatureType' => '09',
                'ProductFormFeatureValue' => '00',
                'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, EPUB Accessibility 1.1, Luettavissa ruudunlukuohjelmalla tai pistenäytöllä, Tämä julkaisu noudattaa saavutettavuusstandardien yleisesti hyväksyttyä tasoa, Ei vaaratekijöitä.',
            ]);
            */
        }

        // Add ProductFormFeatures
        if (isset($productFormFeaturesToAdd)) {
            foreach ($productFormFeaturesToAdd as $productFormFeatureType => $productFormFeaturesToAdd) {
                foreach ($productFormFeaturesToAdd as $productFormFeatureToAdd) {
                    $productFormFeatures->push([
                        'ProductFormFeatureType' => $productFormFeatureType,
                        'ProductFormFeatureValue' => $productFormFeatureToAdd,
                    ]);
                }
            }

            // Publisher contact for further accessibility information is common for all
            $productFormFeatures->push([
                'ProductFormFeatureType' => '09',
                'ProductFormFeatureValue' => '99',
                'ProductFormFeatureDescription' => $this->getAccessibilityEmail(),
            ]);
        }

        // Battery information
        if (property_exists($this->product, 'batteryInfo') && property_exists($this->product->batteryInfo, 'onixCode')) {
            // Define battery info
            $battery = [
                'ProductFormFeatureType' => '19',
                'ProductFormFeatureValue' => $this->product->batteryInfo->onixCode,
            ];

            // Add description if available
            if (property_exists($this->product, 'batteryDescription')) {
                $battery['ProductFormFeatureDescription'] = $this->product->batteryDescription;
            }

            $productFormFeatures->push($battery);
        }

        // Battery chemistry
        if (property_exists($this->product, 'batteryType') && property_exists($this->product->batteryType, 'onixCode')) {
            $productFormFeatures->push([
                'ProductFormFeatureType' => '19',
                'ProductFormFeatureValue' => $this->product->batteryType->onixCode,
            ]);
        }

        // EUDR related information
        $response = $this->client->get('/v1/editions/'.$this->productionId.'/deforestationregulation');
        $deforestationregulationResponse = collect(json_decode($response->getBody()->getContents(), true))->filter(function (array $print, int $key) {
            // Must have status or statements
            return array_key_exists('status', $print) || count($print['statements']) > 0;
        })->sortByDesc('printNumber');

        // Since Onix does not support per print level information, use the latest one
        $latestPrint = $deforestationregulationResponse->first();

        // Check if we have an exemption
        $exemptionMapping = [
            '53' => 'Deforestation free',
            '54' => 'Stock present',
            '56' => 'Beyond scope',
        ];

        if (! empty($latestPrint) && array_key_exists('status', $latestPrint) && in_array($latestPrint['status']['name'], $exemptionMapping)) {
            $productFormFeatures->push([
                'ProductFormFeatureType' => array_search($latestPrint['status']['name'], $exemptionMapping),
            ]);
        }

        // Add DDS numbers from statements
        foreach ($deforestationregulationResponse as $print) {
            if (array_key_exists('statements', $print) === true && count($print['statements']) > 0) {
                foreach ($print['statements'] as $statement) {
                    if (array_key_exists('referenceNumber', $statement) && ! empty($statement['referenceNumber']) && array_key_exists('verificationCode', $statement) && ! empty($statement['verificationCode'])) {
                        $productFormFeatures->push([
                            'ProductFormFeatureType' => '50',
                            'ProductFormFeatureValue' => $statement['referenceNumber'].'+'.$statement['verificationCode'],
                        ]);
                    }
                }
            }

        }

        return $productFormFeatures;
    }

    /**
     * Check if the given product number is valid GTIN
     *
     * @param  string  $gtin
     * @return bool
     */
    public function isValidGtin($gtin)
    {
        return Gtin\Factory::isValid($gtin);
    }

    /**
     * Check if the given product number is valid ISBN
     *
     * @param  string  $gtin
     * @return bool
     */
    public function isValidIsbn13($gtin)
    {
        try {
            Isbn::validateAsEan13($gtin);
        } catch (Exception $e) { // Will throw because third hyphen is misplaced
            return false;
        }

        return true;
    }

    /**
     * Get the products collections/series
     *
     * @return Collection
     */
    public function getCollections()
    {
        $collections = new Collection;

        // Add book series
        if (isset($this->product->series)) {
            $collections->push([
                'CollectionType' => '10', [
                    'TitleDetail' => [
                        'TitleType' => '01',
                        'TitleElement' => [
                            'TitleElementLevel' => '02',
                            'TitleText' => trim($this->product->series->name),
                        ],
                    ],
                ],
            ]);

            // Add Collection sequence if product has NumberInSeries
            if (isset($this->product->numberInSeries)) {
                $collections = $collections->map(function ($collection) {
                    // Add CollectionSequence to Collection
                    $collectionSequence = [
                        'CollectionSequenceType' => '02',
                        'CollectionSequenceNumber' => $this->product->numberInSeries,
                    ];

                    $collection[0]['CollectionSequence'] = $collectionSequence;

                    return $collection; // @phpstan-ignore-line
                });
            }
        }

        // Add products marketing serie
        if (! empty($this->product->marketingSerie)) {
            $collections->push([
                'CollectionType' => '11', [
                    'TitleDetail' => [
                        'TitleType' => '01',
                        'TitleElement' => [
                            'TitleElementLevel' => '02',
                            'TitleText' => $this->product->marketingSerie,
                        ],
                    ],
                ],
            ]);
        }

        return $collections;
    }

    /**
     * Get the products title details
     *
     * @return Collection
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
        if (! empty($this->product->subtitle) && $this->product->subtitle !== '-') {
            $titleDetails = $titleDetails->map(function ($titleDetail) {
                $titleDetail['TitleElement']['Subtitle'] = $this->product->subtitle;

                return $titleDetail; // @phpstan-ignore-line
            });
        }

        // Original title
        // Note: Since title is automatically copied to original title, we don't want to return if they are the same
        if (! empty($this->product->originalTitle)) {
            if ($this->isTranslated() || $this->product->title !== $this->product->originalTitle) {
                $titleDetails->push([
                    'TitleType' => '03',
                    'TitleElement' => [
                        'TitleElementLevel' => '01',
                        'TitleText' => $this->product->originalTitle,
                    ],
                ]);
            }
        }

        return $titleDetails;
    }

    /**
     * Get the internal title for edition
     *
     * @return string
     */
    public function getInternalTitle()
    {
        $bindingCodeMapping = [
            'Podcast' => 'podcast',
            'Hardback' => 'kirja',
            'Saddle-stitched' => 'kirja',
            'Paperback' => 'kirja',
            'Spiral bound' => 'kirja',
            'Flex' => 'kirja',
            'Pocket book' => 'pokkari',
            'Trade paperback or "Jättipokkari"' => 'kirja',
            'Board book' => 'kirja',
            'Downloadable audio file' => 'ä-kirja',
            'CD' => 'cd',
            'MP3-CD' => 'cd',
            'Other audio format' => 'muu audio',
            'Picture-and-audio book' => 'kä-kirja',
            'ePub2' => 'e-kirja',
            'ePub3' => 'e-kirja',
            'Application' => 'sovellus',
            'Kit' => 'paketti',
            'Miscellaneous' => 'muu',
            'Pre-recorded digital audio player' => 'kirjastosoitin',
            'PDF' => 'pdf',
            'Calendar (Hardback)' => 'kalenteri',
            'Calendar (Paperback)' => 'kalenteri',
            'Calendar (Other)' => 'kalenteri',
            'Marketing material' => 'mark. materiaali',
            'Multiple-component retail product' => 'moniosainen',
        ];

        if (array_key_exists($this->getProductType(), $bindingCodeMapping) === false) {
            throw new Exception('Could not map binding code for internal title. Binding code: '.$this->getProductType());
        }

        $format = $bindingCodeMapping[$this->getProductType()];

        if (isset($this->product->activePrint->ebookHasAudioFile) && $this->product->activePrint->ebookHasAudioFile === true) {
            $format = 'eä-kirja';
        }

        // Space reserved for format + one space
        $spaceForFormat = mb_strlen($format) + 1;

        return trim(mb_substr($this->product->title, 0, 50 - $spaceForFormat)).' '.$format;
    }

    /**
     * Get the products contributors
     *
     * @param  bool  $returnInternalResources
     * @return Collection
     */
    public function getContributors($returnInternalResources = true)
    {
        $contributors = new Collection;

        // If no stakeholders present
        if (! isset($this->product->members)) {
            return $contributors;
        }

        // Filter those team members that we don't have the Onix role mapping
        $teamMembers = collect($this->product->members)->filter(function ($teamMember) {
            return property_exists($teamMember->role, 'customProperties') && property_exists($teamMember->role->customProperties, 'onixCode');
        })->sortBy(function ($teamMember) {
            // We sort by priority level, sort order, role priority and then by the lastname
            $priorityLevel = (isset($teamMember->prioLevel)) ? $teamMember->prioLevel->id : 0;
            $sortOrderPriority = $teamMember->sortOrder;
            $rolePriority = $this->getRolePriority($teamMember->role->name);
            $lastNamePriority = (! empty($teamMember->contact->lastName)) ? ord($teamMember->contact->lastName) : 0;
            $sortOrder = str_pad(strval($priorityLevel), 3, '0', STR_PAD_LEFT).'-'.str_pad(strval($sortOrderPriority), 3, '0', STR_PAD_LEFT).'-'.str_pad(strval($rolePriority), 3, '0', STR_PAD_LEFT).'-'.str_pad(strval($lastNamePriority), 3, '0', STR_PAD_LEFT);

            return $sortOrder;
        });

        // Remove internal resource if required
        if ($returnInternalResources === false) {
            $teamMembers = $teamMembers->filter(function ($teamMember) {
                return isset($teamMember->prioLevel->name) && $teamMember->prioLevel->name !== 'Internal';
            });
        }

        // Remove duplicate roles for the same person
        $teamMembers = $teamMembers->unique(function ($teamMember) {
            return $teamMember->contact->id.$teamMember->role->customProperties->onixCode;
        });

        // Init SequenceNumber
        $sequenceNumber = 1;

        foreach ($teamMembers as $contributor) {
            // Form contributor data
            $contributorData = [
                'Identifier' => $contributor->contact->id,
                'SequenceNumber' => $sequenceNumber,
                'ContributorRole' => $contributor->role->customProperties->onixCode,
            ];

            // Get contact
            $response = $this->searchClient->get('v2/contacts/'.$contributor->contact->id);
            $contact = json_decode($response->getBody()->getContents());

            if ($contact->isCompanyContact === true) {
                if (isset($contact->company->name1) && isset($contact->company->name2)) {
                    $contributorData['CorporateName'] = trim($contact->company->name1.' '.$contact->company->name2);
                } else {
                    $contributorData['CorporateName'] = trim($contact->company->name1);
                }
            } else {
                // Handle PersonNameInverted and KeyNames differently depending if they have the lastname or not
                if (empty($contributor->contact->lastName) && ! empty($contributor->contact->firstName) && Str::contains($contributor->contact->firstName, 'Tekoäly') === false) {
                    $contributorData['PersonName'] = trim($contributor->contact->firstName);
                    $contributorData['KeyNames'] = trim($contributor->contact->firstName);
                    // AI with non-audiobook reader role
                } elseif ($contributor->contact->firstName === 'Tekoäly' && empty($contributor->contact->lastName)) {
                    $contributorData['UnnamedPersons'] = '09';
                    // AI with general male voice
                } elseif ($contributor->contact->firstName === 'Tekoäly, miesääni') {
                    $contributorData['UnnamedPersons'] = '05';
                    // AI with general female voice
                } elseif ($contributor->contact->firstName === 'Tekoäly, naisääni') {
                    $contributorData['UnnamedPersons'] = '06';
                    // AI with unspecisified voice
                } elseif ($contributor->contact->firstName === 'Tekoäly, määrittelemätön ääni') {
                    $contributorData['UnnamedPersons'] = '07';
                    // AI Reader - voice replica
                } elseif ($contributor->role->name === 'AI Reader – voice replica') {
                    $contributorData['UnnamedPersons'] = '08';

                    // Add AlternativeName
                    $contributorData['AlternativeName'] = [
                        'NameType' => '04',
                        'PersonName' => Str::after($contributor->contact->firstName, 'Tekoäly '),
                    ];
                    // AI Reader - Named male
                } elseif ($contributor->role->name === 'AI Reader – male' && $contributor->contact->firstName != 'Tekoäly, miesääni') {
                    $contributorData['UnnamedPersons'] = '05';

                    // Add AlternativeName
                    $contributorData['AlternativeName'] = [
                        'NameType' => '07',
                        'PersonName' => Str::after($contributor->contact->firstName, 'Tekoäly '),
                    ];
                } elseif ($contributor->role->name === 'AI Reader – female' && $contributor->contact->firstName != 'Tekoäly, naisääni') {
                    $contributorData['UnnamedPersons'] = '06';

                    // Add AlternativeName
                    $contributorData['AlternativeName'] = [
                        'NameType' => '07',
                        'PersonName' => Str::after($contributor->contact->firstName, 'Tekoäly '),
                    ];
                } elseif ($contributor->role->name === 'AI Reader – unspecified' && $contributor->contact->firstName != 'Tekoäly, määrittelemätön ääni') {
                    $contributorData['UnnamedPersons'] = '07';

                    // Add AlternativeName
                    $contributorData['AlternativeName'] = [
                        'NameType' => '07',
                        'PersonName' => Str::after($contributor->contact->firstName, 'Tekoäly '),
                    ];
                } else {
                    $contributorData['PersonName'] = trim($contributor->contact->firstName).' '.trim($contributor->contact->lastName);
                    $contributorData['PersonNameInverted'] = trim($contributor->contact->lastName).', '.trim($contributor->contact->firstName);
                    $contributorData['KeyNames'] = trim($contributor->contact->lastName);
                    $contributorData['NamesBeforeKey'] = trim($contributor->contact->firstName);
                }
            }

            $response = $this->searchClient->get('v2/contacts/'.$contributor->contact->id.'/links');
            $links = json_decode($response->getBody()->getContents());

            // Add BiographicalNote
            $contributorData['BiographicalNote'] = collect($contact->texts)->filter(function ($text, $key) {
                return $text->textType->name === 'Contact presentation';
            })->pluck('text')->first();

            // Mapping Mockingbird link types to Onix codelist 73 values
            $linkTypeMapping = [
                'Other' => '00',
                'Webpage' => '06',
                'Website' => '06',
                'Blog' => '23',
                'Wiki' => '00',
                'Facebook' => '42',
                'YouTube' => '42',
                'Trailer' => '42',
                'Twitter' => '42',
                'Instagram' => '42',
            ];

            // Add links
            $contributorData['WebSites'] = collect($links->contactLinks)->map(function ($link, $key) use ($contributorData, $linkTypeMapping) {
                // Form website description
                if (array_key_exists('CorporateName', $contributorData)) {
                    $name = $contributorData['CorporateName'];
                } else {
                    $name = (array_key_exists('NamesBeforeKey', $contributorData)) ? $contributorData['NamesBeforeKey'].' '.$contributorData['KeyNames'] : $contributorData['KeyNames'];
                }

                if (is_object($link) && property_exists($link, 'linkType')) {

                    switch ($link->linkType->name) {
                        case 'Facebook':
                            $description = $name.' Facebookissa';
                            break;
                        case 'Twitter':
                            $description = $name.' Twitterissä';
                            break;
                        case 'Instagram':
                            $description = $name.' Instagramissa';
                            break;
                        case 'YouTube':
                            $description = $name.' YouTubessa';
                            break;
                        case 'Webpage':
                            $description = 'Tekijän omat nettisivut';
                            break;
                        case 'Other':
                            $description = 'Muu linkki';
                            break;
                        default:
                            $description = null;
                            break;
                    }

                    return [
                        'WebsiteRole' => $linkTypeMapping[(string) $link->linkType->name],
                        'WebsiteDescription' => $description,
                        'Website' => (string) $link->value,
                    ];
                }
            })->toArray();

            // Add selection lists
            $response = $this->searchClient->get('v2/contacts/'.$contributor->contact->id.'/lists');
            $selectionLists = json_decode($response->getBody()->getContents());

            $contributorData['SelectionLists'] = collect($selectionLists->lists)->map(function ($list, $key) {
                return $list->name;
            })->toArray();

            // Add contributorHasAuthorImage
            $contributorData['HasAuthorImage'] = $contact->hasAuthorPhotographStandard;

            // Add to collection
            $contributors->push($contributorData);

            $sequenceNumber++;
        }

        return $contributors;
    }

    /**
     * Get the all contributors, including those that don't have Onix roles
     *
     * @return Collection
     */
    public function getAllContributors()
    {
        $contributors = new Collection;

        // If no stakeholders present
        if (! isset($this->product->members)) {
            return $contributors;
        }

        foreach ($this->product->members as $member) {
            $contributors->push([
                'Id' => $member->contact->id,
                'PriorityLevel' => $member->prioLevel->name ?? null,
                'Role' => $member->role->name,
                'FirstName' => $member->contact->firstName ?? null,
                'LastName' => $member->contact->lastName ?? null,
            ]);
        }

        return $contributors;
    }

    /**
     * Get the products languages
     *
     * @return Collection
     */
    public function getLanguages()
    {
        $languages = new Collection;

        // Add text language
        if (! empty($this->product->languages)) {
            foreach ($this->product->languages as $language) {
                $languages->push([
                    'LanguageRole' => '01',
                    'LanguageCode' => $language->id,
                ]);
            }
        }

        // Add original languages
        if (property_exists($this->getWorkLevel(), 'originalLanguages')) {
            foreach ($this->getWorkLevel()->originalLanguages as $originalLanguage) {
                $languages->push([
                    'LanguageRole' => '02',
                    'LanguageCode' => $originalLanguage->id,
                ]);
            }
        }

        // Validate that all LanguageCodes are valid
        $languages->pluck('LanguageCode')->each(function ($languageCode, $key) {
            LanguagesRepository::register(['name' => 'greek', 'iso-639-2b' => 'grc']);
            $language = Lingua::createFromISO_639_2b($languageCode);

            try {
                $language->toName();
            } catch (Exception $e) {
                throw new Exception('Incorrect LanguageCode '.$languageCode);
            }
        });

        return $languages;
    }

    /**
     * Get the products extents
     *
     * @return Collection
     */
    public function getExtents()
    {
        $extents = new Collection;

        // Number of pages
        if (isset($this->product->pages) && $this->product->pages > 0 && $this->isImmaterial() === false) {
            $extents->push([
                'ExtentType' => '00',
                'ExtentValue' => strval($this->product->pages),
                'ExtentUnit' => '03',
            ]);
        }

        // Audio duration, convert from HH:MM to HHHMM
        $productIsAllowedToHaveAudio = in_array($this->getProductForm(), [
            'AC', // CD-Audio
            'AE', // Audio disc
            'AJ', // Downloadable audio file
            'ED', // Digital (delivered electronically). eBooks sometimes contain audio.
        ]);

        if ($productIsAllowedToHaveAudio && isset($this->product->audioPlaytimeHours)) {
            $audioPlaytimeHours = str_pad($this->product->audioPlaytimeHours, 3, '0', STR_PAD_LEFT);

            // If no minutes are given, use 00
            $audioPlaytimeMinutes = (! isset($this->product->audioPlaytimeMinutes)) ? '00' : str_pad($this->product->audioPlaytimeMinutes, 2, '0', STR_PAD_LEFT);

            // Skip if we don't have value
            $extentValue = $audioPlaytimeHours.$audioPlaytimeMinutes;
            if ($extentValue !== '00000') {
                // Hours and minutes HHHMM
                $extents->push([
                    'ExtentType' => '09',
                    'ExtentValue' => strval($extentValue),
                    'ExtentUnit' => '15',
                ]);

                // Seconds
                $extents->push([
                    'ExtentType' => '09',
                    'ExtentValue' => strval((intval($audioPlaytimeHours) * 3600) + (intval($audioPlaytimeMinutes) * 60)),
                    'ExtentUnit' => '06',
                ]);
            }

            // Add audio playtime with seconds if exists
            $audioPlaytimeSeconds = (! isset($this->product->audioPlaytimeSeconds)) ? '00' : str_pad($this->product->audioPlaytimeSeconds, 2, '0', STR_PAD_LEFT);

            if (! empty($audioPlaytimeSeconds) && $audioPlaytimeSeconds !== '00') {
                $extentValue = $audioPlaytimeHours.$audioPlaytimeMinutes.$audioPlaytimeSeconds;

                $extents->push([
                    'ExtentType' => '09',
                    'ExtentValue' => strval($extentValue),
                    'ExtentUnit' => '16',
                ]);
            }
        }

        // E-book word and pages count by approximation (Finnish words is 8.5 characters on average and around 1500 characters per page)
        if (isset($this->product->numberOfCharacters)) {
            $extents->push([
                'ExtentType' => '02',
                'ExtentValue' => strval($this->product->numberOfCharacters),
                'ExtentUnit' => '01',
            ]);

            $extents->push([
                'ExtentType' => '10',
                'ExtentValue' => strval(round($this->product->numberOfCharacters / 8.5)),
                'ExtentUnit' => '02',
            ]);

            $extents->push([
                'ExtentType' => '10',
                'ExtentValue' => strval(max(1, round($this->product->numberOfCharacters / 1500))),
                'ExtentUnit' => '03',
            ]);
        }

        // Add number of pages in the printer counterpart for digital products from main edition
        if ($this->isImmaterial() && isset($this->product->pages) && $this->product->pages > 0) {
            $extents->push([
                'ExtentType' => '08',
                'ExtentValue' => strval($this->product->pages),
                'ExtentUnit' => '03',
            ]);
        }

        // Filter out zero values
        $extents = $extents->filter(function ($extent) {
            return intval($extent['ExtentValue']) > 0;
        });

        return $extents->sortBy('ExtentType');
    }

    /**
     * Get the products estimated number of pages
     *
     * @return int|null
     */
    public function getEstimatedNumberOfPages()
    {
        return (isset($this->product->estimatedNumberOfPages)) ? intval($this->product->estimatedNumberOfPages) : null;
    }

    /**
     * Get the products estimated number of pages
     *
     * @return int|null
     */
    public function getInsideNumberOfPages()
    {
        return (isset($this->product->activePrint->insidePages)) ? intval($this->product->activePrint->insidePages) : null;
    }

    /**
     * Get the products number of characters
     *
     * @return int|null
     */
    public function getNumberOfCharacters()
    {
        return (isset($this->product->numberOfCharacters)) ? intval($this->product->numberOfCharacters) : null;
    }

    /**
     * Get the publishers name
     *
     * @return string
     */
    public function getPublisher()
    {
        return $this->product->publishingHouse->name;
    }

    /**
     * Get the publishers id
     *
     * @return string
     */
    public function getPublisherId()
    {
        return $this->product->publishingHouse->id;
    }

    /**
     * Get the products imprints
     *
     * @return Collection
     */
    public function getImprints()
    {
        $imprints = new Collection;

        if (isset($this->product->brand->name) && $this->product->publishingHouse->name !== $this->product->brand->name && $this->product->brand->name !== 'Johnny Kniga') {
            $imprints->push([
                'ImprintName' => $this->product->brand->name,
            ]);
        }

        return $imprints;
    }

    /**
     * Get the products brand
     *
     * @return string
     */
    public function getBrand()
    {
        if (! isset($this->product->brand)) {
            throw new Exception('The edition is missing brand.');
        }

        if ($this->getCostCenter() === 909) {
            return 'Disney';
        }

        return $this->product->brand->name;
    }

    /**
     * Get the products net price RRP including VAT
     *
     * @return float|null
     */
    public function getPrice()
    {
        return (isset($this->product->resellerPriceIncludingVat)) ? floatval($this->product->resellerPriceIncludingVat) : null;
    }

    /**
     * Get the products net price excluding VAT
     *
     * @return float|null
     */
    public function getPriceExcludingVat()
    {
        return (isset($this->product->resellerPrice)) ? floatval($this->product->resellerPrice) : null;
    }

    /**
     * Get the products retail price including VAT
     *
     * @return float|null
     */
    public function getPublisherRetailPrice()
    {
        return (isset($this->product->publisherRetailPriceIncludingVat)) ? floatval($this->product->publisherRetailPriceIncludingVat) : null;
    }

    /**
     * Get the products measures
     *
     * @return Collection
     */
    public function getMeasures()
    {
        // Collection for measures
        $measures = new Collection;

        // Do not return any measurements for digital products even though they might exists
        if ($this->isImmaterial()) {
            return $measures;
        }

        // Add width, height and length
        if (! empty($this->product->height)) {
            $measures->push(['MeasureType' => '01', 'Measurement' => intval($this->product->height), 'MeasureUnitCode' => 'mm']);
        }

        if (! empty($this->product->width)) {
            $measures->push(['MeasureType' => '02', 'Measurement' => intval($this->product->width), 'MeasureUnitCode' => 'mm']);
        }

        if (! empty($this->product->depth)) {
            $measures->push(['MeasureType' => '03', 'Measurement' => intval($this->product->depth), 'MeasureUnitCode' => 'mm']);
        }

        // Add weight
        if (! empty($this->product->weight)) {
            $measures->push(['MeasureType' => '08', 'Measurement' => intval($this->product->weight * 1000), 'MeasureUnitCode' => 'gr']);
        }

        // Filter out zero values
        $measures = $measures->filter(function ($measure) {
            return $measure['Measurement'] > 0;
        });

        return $measures;
    }

    /**
     * Get the products estimated measures
     *
     * @return Collection
     */
    public function getEstimatedMeasures()
    {
        // Collection for measures
        $measures = new Collection;

        // Do not return any measurements for digital products even though they might exists
        if ($this->isImmaterial()) {
            return $measures;
        }

        // Add width, height and length
        if (! empty($this->product->estimatedHeight)) {
            $measures->push(['MeasureType' => '01', 'Measurement' => intval($this->product->estimatedHeight), 'MeasureUnitCode' => 'mm']);
        }

        if (! empty($this->product->estimatedWidth)) {
            $measures->push(['MeasureType' => '02', 'Measurement' => intval($this->product->estimatedWidth), 'MeasureUnitCode' => 'mm']);
        }

        if (! empty($this->product->estimatedDepth)) {
            $measures->push(['MeasureType' => '03', 'Measurement' => intval($this->product->estimatedDepth), 'MeasureUnitCode' => 'mm']);
        }

        // Add weight
        if (! empty($this->product->estimatedWeight)) {
            $measures->push(['MeasureType' => '08', 'Measurement' => intval($this->product->estimatedWeight * 1000), 'MeasureUnitCode' => 'gr']);
        }

        // Filter out zero values
        $measures = $measures->filter(function ($measure) {
            return $measure['Measurement'] > 0;
        });

        return $measures;
    }

    /**
     * Get the products original publication date
     *
     * @return DateTime|null
     */
    public function getOriginalPublicationDate()
    {
        if (empty($this->product->OriginalPublishingDate)) {
            return null;
        }

        return DateTime::createFromFormat('!Y-m-d', substr($this->product->OriginalPublishingDate, 0, 10));
    }

    /**
     * Get the products latest publication date
     *
     * @return DateTime|null
     */
    public function getLatestPublicationDate()
    {
        if (! property_exists($this->product, 'publishingDate') && empty($this->product->publishingDate)) {
            return null;
        }

        return DateTime::createFromFormat('!Y-m-d', substr($this->product->publishingDate, 0, 10));
    }

    /**
     * Get the products subjects, like library class, Thema, BIC etc.
     *
     * @return Collection
     */
    public function getSubjects()
    {
        // Init array for subjects
        $subjects = new Collection;

        // Library class
        $libraryClass = $this->getLibraryClass();

        if (! empty($libraryClass)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '66',
                'SubjectSchemeName' => 'YKL',
                'SubjectCode' => $libraryClass,
            ]);
        }

        // Main product group
        if (isset($this->product->mainGroup)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Werner Söderström Ltd - Main product group',
                'SubjectCode' => $this->product->mainGroup->id,
                'SubjectHeadingText' => $this->product->mainGroup->name,
            ]);
        }

        // Sub product group
        if (isset($this->product->subGroup)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Werner Söderström Ltd - Product sub-group',
                'SubjectCode' => $this->product->subGroup->id,
                'SubjectHeadingText' => trim($this->product->subGroup->name),
            ]);
        }

        // BIC subject category
        $bicCodes = $this->getBicCodes();
        foreach ($bicCodes as $bicCode) {
            $subjects->push(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => $bicCode]);
        }

        // Internal category
        if (isset($this->product->internalCategory)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Internal category',
                'SubjectCode' => $this->product->internalCategory->name,
            ]);
        }

        // Cost center number
        if (! empty($this->getCostCenter())) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Werner Söderström Ltd - Cost center',
                'SubjectCode' => strval($this->getCostCenter()),
                'SubjectHeadingText' => $this->getCostCenterName(),
            ]);
        }

        // Storia product group
        $storiaProductGroup = $this->getStoriaProductGroup();

        if (is_array($storiaProductGroup)) {
            $subjects->push($storiaProductGroup);
        }

        // This is disabled until the Thema project has completed
        $themaCodes = collect([]);

        // Get Thema codes
        $themaCodes = $this->getThemaCodes();

        foreach ($themaCodes as $themaCode) {
            $subjects->push([
                'SubjectSchemeIdentifier' => $themaCode['subjectSchemeIdentifier'],
                'SubjectSchemeName' => $themaCode['subjectSchemeName'],
                'SubjectCode' => $themaCode['codeValue'],
            ]);
        }

        // BISAC
        $bisacCode = $this->getBisacCode($themaCodes->pluck('codeValue')->toArray());

        if (! empty($bisacCode)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '10',
                'SubjectSchemeName' => 'BISAC Subject Heading',
                'SubjectCode' => $bisacCode,
            ]);
        }

        // Suomalainen kirja-alan luokitus
        $subjects->push(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => $this->getFinnishBookTradeCategorisation()]);

        // Collection to hold keywords
        $keywords = collect([]);

        // Add marketing keywords
        $keywords = $keywords->merge($this->getMarketingKeywords());

        // Add prizes
        $keywords = $keywords->merge($this->getPrizes()->pluck('PrizeName'));

        // Add bibliographical characters
        $keywords = $keywords->merge($this->getBibliographicCharacters());

        // Add keywords from Mockingbird
        $keywords = $keywords->merge($this->getKeywords());

        // Remove duplicates
        $keywords = $keywords->map(function (string $keyword, int $key) {
            return strtolower($keyword);
        })->unique();

        if ($keywords->count() > 0) {
            $subjects->push(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => $keywords->implode(';')]);
        }

        // Remove those where SubjectCode and/or SubjectHeadingText is empty
        $subjects = $subjects->filter(function ($subject) {
            return ! empty($subject['SubjectCode']) || ! empty($subject['SubjectHeadingText']);
        });

        return $subjects->sortBy('SubjectSchemeIdentifier');
    }

    /**
     * Get keywords
     *
     * @return Collection
     */
    public function getKeywords()
    {
        $keywords = collect([]);

        if (isset($this->product->keywords) && ! empty($this->product->keywords)) {
            foreach (explode(';', $this->product->keywords) as $keyword) {
                $keywords->push($keyword);
            }
        }

        return $keywords;
    }

    /**
     * Get the marketing keywords
     *
     * @return Collection
     */
    public function getMarketingKeywords()
    {
        $marketingKeywords = collect([]);

        if (isset($this->product->bookTypes) && ! empty($this->product->bookTypes)) {
            foreach (explode(';', $this->product->bookTypes) as $bookType) {
                $marketingKeywords->push($bookType);
            }
        }

        return $marketingKeywords;
    }

    /**
     * Get bibliographic characters
     *
     * @return Collection
     */
    public function getBibliographicCharacters()
    {
        $bibliographicCharacters = collect([]);

        if (isset($this->product->bibliographicCharacters) && ! empty($this->product->bibliographicCharacters)) {
            foreach (explode(';', $this->product->bibliographicCharacters) as $bibliographicCharacter) {
                $bibliographicCharacters->push($bibliographicCharacter);
            }
        }

        return $bibliographicCharacters;
    }

    /**
     * Get the products text contents
     *
     * @return Collection
     */
    public function getTextContents()
    {
        $textContents = new Collection;

        // Get texts
        $response = $this->client->get('v1/works/'.$this->workId.'/productions/'.$this->productionId.'/texts');
        $json = json_decode($response->getBody()->getContents());
        $texts = collect($json->texts);

        // Headline
        $headline = $texts->filter(function ($text) {
            return $text->textType->name === 'Headline';
        });

        if ($headline->count() === 1) {
            $textContents->push([
                'TextType' => '10',
                'ContentAudience' => '00',
                'Text' => $headline->pluck('text')->first(),
            ]);
        }

        // Copy 1
        $copyOne = $texts->filter(function ($text) {
            return $text->textType->name === 'Copy 1';
        });

        if ($copyOne->count() === 1) {
            $textContents->push([
                'TextType' => '02',
                'ContentAudience' => '00',
                'Text' => $copyOne->pluck('text')->first(),
            ]);
        }

        // Copy 2
        $copyTwo = $texts->filter(function ($text) {
            return $text->textType->name === 'Copy 2';
        });

        // Author description
        $authorDescription = $texts->filter(function ($text) {
            return $text->textType->name === 'Author presentation';
        });

        // Use general contributor texts as backup
        /*
        if ($authorDescription->count() === 0) {
            // List team members that have prio level primary
            $primaryContributorIds = $this->getAllContributors()->where('PriorityLevel', 'Primary')->pluck('Id');

            $authorDescription = collect([
                (object) ['text' => $this->getContributors()->whereIn('Identifier', $primaryContributorIds)->where('BiographicalNote', '<>', '')->pluck('BiographicalNote')->unique()->implode('')],
            ]);
        }
        */

        // Merge the texts and add missing paragraph tags
        $mergedTexts = $headline->merge($copyOne)->merge($copyTwo)->merge($authorDescription)->transform(function ($text) {
            if (substr($text->text, 0, 3) !== '<p>') {
                $text->text = '<p>'.$text->text.'</p>';
            }

            return $text;
        });

        // Add if texts exist
        if ($mergedTexts->count() > 0) {
            // Add to collection
            $textContents->push([
                'TextType' => '03',
                'ContentAudience' => '00',
                'Text' => $this->purifyHtml($mergedTexts->implode('text')),
            ]);
        }

        // Get review quotes
        $response = $this->client->get('/v1/works/'.$this->workId.'/reviewquotes');
        $json = json_decode($response->getBody()->getContents());

        foreach ($json->reviewQuotes as $reviewQuote) {
            if (! empty($reviewQuote->quote) && ! empty($reviewQuote->source)) {
                $textContents->push([
                    'TextType' => '06',
                    'ContentAudience' => '00',
                    'Text' => $this->purifyHtml($reviewQuote->quote),
                    'SourceTitle' => $this->purifyHtml($reviewQuote->source),
                ]);
            }
        }

        // Description for collection
        if (property_exists($this->product, 'series') && property_exists($this->product->series, 'id')) {
            $response = $this->client->get('v1/series/'.$this->product->series->id);
            $json = json_decode($response->getBody()->getContents());

            if (property_exists($json, 'description') && ! empty($json->description)) {
                $textContents->push([
                    'TextType' => '17',
                    'ContentAudience' => '00',
                    'Text' => $this->purifyHtml($json->description),
                ]);
            }
        }

        // Remove empty texts
        $textContents = $textContents->filter(function ($textContent) {
            return ! empty($textContent['Text']);
        });

        return $textContents;
    }

    /**
     * Get a spesific text
     *
     * @param  string  $name
     * @return null|string
     */
    public function getText($name)
    {
        // Get texts
        $response = $this->client->get('v1/works/'.$this->workId.'/productions/'.$this->productionId.'/texts');
        $json = json_decode($response->getBody()->getContents());
        $texts = collect($json->texts);

        $text = $texts->filter(function ($text) use ($name) {
            return $text->textType->name === $name;
        });

        if ($text->count() === 0) {
            return null;
        }

        return $text->first()->text;
    }

    /**
     * Get the products publishers and their role
     *
     * @return Collection
     */
    public function getPublishers()
    {
        $publishers = new Collection;

        // Array holding web sites
        $webSites = [];

        $publisherWebsites = [
            'Bazar' => 'https://www.bazarkustannus.fi',
            'CrimeTime' => 'http://www.crime.fi',
            'Docendo' => 'https://docendo.fi',
            'Johnny Kniga' => 'https://www.johnnykniga.fi',
            'Kosmos' => 'https://www.kosmoskirjat.fi',
            'Minerva' => 'https://www.minervakustannus.fi',
            'Readme.fi' => 'https://www.readme.fi',
            'Tammi' => 'https://www.tammi.fi',
            'WSOY' => 'https://www.wsoy.fi',
        ];

        $publisher = $this->getPublisher();

        if (array_key_exists($publisher, $publisherWebsites) === false) {
            throw new Exception('Could not find web site mapping for: '.$publisher);
        }

        // Add web site link to publishers website
        array_push($webSites, [
            'WebsiteRole' => '01',
            'WebsiteLink' => $publisherWebsites[$publisher],
        ]);

        // Add link to sustainability page
        array_push($webSites, [
            'WebsiteRole' => '50',
            'WebsiteLink' => 'https://bonnierbooks.com/sustainability/',
        ]);

        // Add main publisher
        $publishers->push([
            'PublishingRole' => '01',
            'PublisherIdentifiers' => [
                [
                    'PublisherIDType' => '15',
                    'IDTypeName' => 'Y-tunnus',
                    'IDValue' => '0599340-0',
                ],
            ],
            'PublisherName' => $this->getPublisher(),
            'WebSites' => $webSites,
        ]);

        return $publishers;
    }

    /**
     * Get the products publishing status (Onix codelist 64)
     *
     * @return string
     */
    public function getPublishingStatus()
    {
        // Check that we don't have illogical combinations
        if (in_array($this->product->listingCode->name, ['Short run', 'Print On Demand']) && $this->isImmaterial()) {
            throw new Exception('Product has governing code that is not allowed for immaterial / digital products.');
        }

        // For published and short run books, the books stocks affect the publishing status.
        if (in_array($this->product->listingCode->name, ['Published', 'Short run'])) {
            // For digital/immaterial products, we don't need to check the stock balances
            if ($this->isImmaterial()) {
                return '04';
            }

            // Check if the product has free stock
            $onHand = $this->getSuppliers()->pluck('OnHand')->first();
            $hasStock = (! empty($onHand) && $onHand > 0) ? true : false;

            if ($hasStock) {
                return '04';
            }

            // If product has no stock, check if we have stock arrival date in the future
            $tomorrow = new DateTime('tomorrow');
            $stockArrivalDate = $this->getLatestStockArrivalDate();

            return ($tomorrow > $stockArrivalDate) ? '06' : '04';
        }

        // Other statuses
        switch ($this->product->listingCode->name) {
            case 'Sold out':
                return '07';
            case 'Cancelled':
                return '01';
            case 'Development':
                return '02';
            case 'Exclusive Sales':
            case 'Delivery block':
            case 'Print On Demand':
                return '04';
            case 'Development-Confidential':
                return '00';
            case 'Permanently withdrawn from sale':
                return '11';
            default:
                throw new Exception('Could not map product governing code '.$this->product->listingCode->name.' to publishing status');
        }
    }

    /**
     * Get the product publishing dates
     *
     * @return Collection
     */
    public function getPublishingDates()
    {
        $publishingDates = new Collection;

        // Add original publishing date
        if (! empty($this->product->publishingDate)) {
            $publishingDate = DateTime::createFromFormat('!Y-m-d', substr($this->product->publishingDate, 0, 10));
            $publishingDates->push(['PublishingDateRole' => '01', 'Date' => $publishingDate->format('Ymd')]);
        }

        // Add Embargo / First permitted day of sale if given
        if (! empty($this->product->firstSellingDay)) {
            $salesEmbargoDate = DateTime::createFromFormat('!Y-m-d', substr($this->product->firstSellingDay, 0, 10));
        }

        // For digital products, set sales embargo date same as publication date
        if ($this->isImmaterial() && ! empty($this->product->publishingDate)) {
            $salesEmbargoDate = DateTime::createFromFormat('!Y-m-d', substr($this->product->publishingDate, 0, 10));
        }

        if (! empty($salesEmbargoDate)) {
            $publishingDates->push(['PublishingDateRole' => '02', 'Date' => $salesEmbargoDate->format('Ymd')]);
        }

        // Add public announcement date / Season
        if (! empty($this->product->seasonYear) && ! empty($this->product->seasonPeriod)) {
            if ($this->product->seasonYear->name !== '2099' && $this->product->seasonPeriod->name !== 'N/A') {
                $publishingDates->push([
                    'PublishingDateRole' => '09',
                    'Date' => $this->product->seasonYear->name.' '.$this->product->seasonPeriod->name,
                    'Format' => 12,
                ]);
            }
        }

        // Get latest reprint date and check if the date as passed
        $latestStockArrivalDate = $this->getLatestStockArrivalDate();

        if (! is_null($latestStockArrivalDate)) {
            $now = new DateTime;
            $publishingDateRole = ($latestStockArrivalDate < $now) ? '12' : '26';

            // Always add Last reprint date
            if ($publishingDateRole === '12') {
                $publishingDates->push(['PublishingDateRole' => $publishingDateRole, 'Date' => $latestStockArrivalDate->format('Ymd')]);
            }

            // Add reprint dates only if we are past publishing date
            if ($publishingDateRole === '26' && isset($publishingDate) && $publishingDate < $now) {
                $publishingDates->push(['PublishingDateRole' => $publishingDateRole, 'Date' => $latestStockArrivalDate->format('Ymd')]);
            }
        }

        return $publishingDates;
    }

    /**
     * Get The products prices
     *
     * @return Collection
     */
    public function getPrices()
    {
        $prices = new Collection;

        // Price types to collect
        $priceTypes = new Collection;

        // Recommended Retail Price excluding tax is calculated as double the reseller price excluding tax
        if (! is_null($this->getPriceExcludingVat())) {
            $priceTypes->push([
                'PriceTypeCode' => '01',
                'TaxIncluded' => false,
                'TaxRateCode' => 'Z',
                'PriceAmount' => $this->getPriceExcludingVat() * 2,
            ]);
        }

        // Supplier’s net price excluding tax
        if (! is_null($this->getPriceExcludingVat())) {
            $priceTypes->push([
                'PriceTypeCode' => '05',
                'TaxIncluded' => false,
                'TaxRateCode' => 'Z',
                'PriceAmount' => $this->getPriceExcludingVat(),
            ]);
        }

        // Supplier’s net price including tax
        if (! is_null($this->getPrice())) {
            $priceTypes->push([
                'PriceTypeCode' => '07',
                'TaxIncluded' => true,
                'TaxRateCode' => 'S',
                'PriceAmount' => $this->getPrice(),
            ]);
        }

        // Publishers recommended retail price including tax
        if (! is_null($this->getPublisherRetailPrice())) {
            $priceTypes->push([
                'PriceTypeCode' => '42',
                'TaxIncluded' => true,
                'TaxRateCode' => 'S',
                'PriceAmount' => round($this->getPublisherRetailPrice(), 2), // Always round to two decimals
            ]);
        }

        // Remove price types that don't have price
        $priceTypes = $priceTypes->filter(function ($priceType, $key) {
            return ! is_null($priceType['PriceAmount']);
        });

        // Go through all Price Types
        foreach ($priceTypes as $priceType) {
            $price = [
                'PriceType' => $priceType['PriceTypeCode'],
                'PriceAmount' => $priceType['PriceAmount'],
                'Tax' => $this->getTaxElement($priceType),
                'CurrencyCode' => 'EUR',
                'Territory' => [
                    'RegionsIncluded' => 'WORLD',
                ],
            ];

            // Remove TaxElement from prices that no not include tax
            $taxExcludedPriceTypes = [
                '01',
                '03',
                '05',
                '06',
                '08',
                '11',
                '13',
                '15',
                '21',
                '23',
                '25',
                '31',
                '32',
                '33',
                '35',
                '41',
            ];

            if (in_array($price['PriceType'], $taxExcludedPriceTypes)) {
                unset($price['Tax']);
            }

            $prices->push($price);

            // Add pocket book price group as separate PriceType
            if ($priceType['PriceTypeCode'] === '42' && ! is_null($this->getPocketBookPriceGroup())) {
                $price = array_slice($price, 0, 2, true) + ['PriceCoded' => ['PriceCodeType' => '02', 'PriceCode' => $this->product->priceGroupPocket->name]] + array_slice($price, 2, count($price) - 1, true);

                // We need to remove PriceAmount since it is either PriceAmount OR PriceCoded
                unset($price['PriceAmount']);
                $prices->push($price);
            }
        }

        return $prices;
    }

    /**
     * Get the tax element
     *
     * @param  array  $priceType
     * @return array
     */
    public function getTaxElement($priceType)
    {
        // Form taxable and tax amount
        switch ($priceType['PriceTypeCode']) {
            case '05':
                $taxAmount = 0;
                $taxableAmount = $this->getPriceExcludingVat();
                break;
            case '07':
                $taxAmount = $this->getPrice() - $this->getPriceExcludingVat();
                $taxableAmount = $this->getPriceExcludingVat();
                break;
            case '42':
                $taxAmount = $this->product->publisherRetailPriceIncludingVat - $this->product->publisherRetailPrice;
                $taxableAmount = $this->product->publisherRetailPrice;
                break;
            default:
                $taxAmount = 0;
                $taxableAmount = 0;
                break;
        }

        return [
            'TaxType' => '01',
            'TaxRateCode' => $priceType['TaxRateCode'],
            'TaxRatePercent' => $this->getTaxRate(),
            'TaxableAmount' => $taxableAmount,
            'TaxAmount' => round($taxAmount, 2),
        ];
    }

    /**
     * Get products supporting resources
     *
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
        $response = $client->request('POST', 'login', ['query' => ['username' => config('groschen.elvis.username'), 'password' => config('groschen.elvis.password')]]);
        $json = json_decode($response->getBody());

        // Check that we are logged in
        if ($json->loginSuccess === false) {
            throw new Exception($json->loginFaultMessage);
        }

        // Publisher mapping
        $publisher = ($this->product->brand->name === 'Sangatsu Manga') ? 'Tammi' : $this->product->publishingHouse->name;

        // Disney are based on cost center, not brand
        if ($this->getCostCenter() === 909) {
            $publisher = 'Disney';
        }

        // Cover image
        $queries = [
            'gtin:'.$this->productNumber.' AND cf_catalogMediatype:cover AND ancestorPaths:"/'.$publisher.'/Kansikuvat"',
        ];

        // Add separate queries for each contributor
        foreach ($this->getContributors() as $contributor) {
            array_push($queries, 'cf_mockingbirdContactId:'.$contributor['Identifier'].' AND cf_preferredimage:true AND cf_availableinpublicweb:true AND ancestorPaths:"/'.$publisher.'/Kirjailijakuvat"');
        }

        // List of metadata fields from Elvis that we need
        $metadataFields = [
            'height',
            'width',
            'mimeType',
            'fileSize',
            'cf_catalogMediatype',
            'cf_mockingbirdContactId',
            'copyright',
            'creatorName',
            'versionNumber',
            'fileModified',
        ];

        // Elvis uses mime types, so we need mapping table for ResourceVersionFeatureValue codelist
        $mimeTypeToCodelistMapping = [
            'application/pdf' => 'D401',
            'image/gif' => 'D501',
            'image/jpeg' => 'D502',
            'image/png' => 'D503',
            'image/tiff' => 'D504',
            'application/vnd.adobe.photoshop' => 'D507',
        ];

        // Perform queries in Elvis
        $hits = [];
        foreach ($queries as $query) {
            $response = $client->request('POST', 'search', [
                'query' => [
                    'q' => $query,
                    'metadataToReturn' => implode(',', $metadataFields),
                ],
            ]);

            $searchResults = json_decode($response->getBody());
            $hits = array_merge($hits, $searchResults->hits);
        }

        // Logout
        $client->request('POST', 'logout');

        // Add hits to collection
        foreach ($hits as $hit) {
            // Check that we have all the required metadata fields
            foreach (array_diff($metadataFields, ['cf_mockingbirdContactId', 'copyright', 'creatorName', 'versionNumber', 'assetModified']) as $requiredMetadataField) {
                if (property_exists($hit->metadata, $requiredMetadataField) === false) {
                    throw new Exception('The required metadata field '.$requiredMetadataField.' does not exist in Elvis.');
                }
            }

            // Determine ResourceContentTypes
            unset($resourceContentType);

            // Normal cover
            if (strtolower($hit->metadata->cf_catalogMediatype) === 'cover' && Str::contains($hit->metadata->assetPath, 'Kansikuvat') && Str::contains($hit->metadata->filename, '3d', true) === false) {
                $resourceContentType = '01';
            }

            // 3D front cover
            if (strtolower($hit->metadata->cf_catalogMediatype) === 'cover' && Str::contains($hit->metadata->assetPath, 'Kansikuvat') && Str::contains($hit->metadata->filename, '3d', true)) {
                $resourceContentType = '03';
            }

            // Author images
            if (strtolower($hit->metadata->cf_catalogMediatype) === 'stakeholder' && Str::contains($hit->metadata->assetPath, 'Kirjailijakuvat')) {
                $resourceContentType = '04';
            }

            if (! isset($resourceContentType)) {
                throw new Exception('Could not determine ResourceContentType for '.$hit->metadata->assetPath);
            }

            $supportingResource = [
                'ResourceContentType' => $resourceContentType,
                'ContentAudience' => '00',
                'ResourceMode' => '03',
                'ResourceVersion' => [
                    'ResourceForm' => '02',
                    'ResourceVersionFeatures' => [
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
                            'ResourceVersionFeatureType' => '07',
                            'FeatureValue' => $hit->metadata->fileSize->value,
                        ],
                    ],
                    'ResourceLink' => $this->getAuthCredUrl($hit->originalUrl, $hit->metadata->versionNumber),
                    'ContentDate' => [
                        'ContentDateRole' => '01',
                        'Date' => DateTime::createFromFormat('!Y-m-d', substr($hit->metadata->fileModified->formatted, 0, 10))->format('Ymd'),
                    ],
                ],
            ];

            // Add ResourceVersionFeatureType 06 (Proprietary ID of resource contributor) if ResourceContentType 04 (Author image) and copyright
            if ($resourceContentType === '04') {
                // Required credit and Copyright
                if (property_exists($hit->metadata, 'copyright') && ! empty($hit->metadata->copyright)) {
                    $supportingResource['ResourceFeatures'][] = [
                        'ResourceFeatureType' => '01',
                        'FeatureValue' => $hit->metadata->copyright,
                    ];
                }

                if (property_exists($hit->metadata, 'creatorName') && ! empty($hit->metadata->creatorName[0])) {
                    $supportingResource['ResourceFeatures'][] = [
                        'ResourceFeatureType' => '03',
                        'FeatureValue' => $hit->metadata->creatorName[0],
                    ];
                }

                array_splice($supportingResource['ResourceVersion']['ResourceVersionFeatures'], 5, 0, [
                    [
                        'ResourceVersionFeatureType' => '06',
                        'FeatureValue' => $hit->metadata->cf_mockingbirdContactId,
                    ],
                ]);
            }

            $supportingResources->push($supportingResource);
        }

        // Add audio/reading samples and YouTube trailers
        foreach ($this->product->links as $link) {
            switch ($link->linkType->name) {
                case 'YouTube':
                    $resourceContentType = '26';
                    $resourceMode = '05';
                    break;
                case 'Issuu reading sample':
                case 'Reading sample':
                    $resourceContentType = '15';
                    $resourceMode = '04';
                    break;
            }

            if (isset($resourceContentType) && isset($resourceMode) && isset($link->url)) {
                $supportingResources->push([
                    'ResourceContentType' => $resourceContentType,
                    'ContentAudience' => '00',
                    'ResourceMode' => $resourceMode,
                    'ResourceVersion' => [
                        'ResourceForm' => '03',
                        'ResourceLink' => $link->url,
                    ],
                ]);
            }
        }

        return $supportingResources;
    }

    /**
     * Get the authCred URL for the Elvis links
     *
     * @param  string  $url
     * @param  int  $versionNumber
     * @return string
     */
    public function getAuthCredUrl($url, $versionNumber)
    {
        // Add authCred to query parameters
        $uri = Uri::createFromString($url);
        $newUri = UriModifier::mergeQuery($uri, 'authcred='.base64_encode(config('groschen.elvis.username').':'.config('groschen.elvis.password')));

        // Add version number if larger than 1
        if ($versionNumber > 1) {
            $newUri = UriModifier::mergeQuery($newUri, 'version='.$versionNumber);
        }

        // Remove the underscore version parameter
        $newUri = UriModifier::removeParams($newUri, '_');

        return (string) $newUri;
    }

    /**
     * Get the related products
     *
     * @return Collection
     */
    public function getRelatedProducts()
    {
        $relatedProducts = new Collection;

        foreach ($this->getWorkLevel()->productions as $production) {
            // Do not add current product or confidential products
            if (isset($production->isbn)) {
                $relatedProductGroschen = new Groschen($production->isbn);
            }

            if (isset($production->isbn) && $production->isbn !== $this->productNumber && isset($relatedProductGroschen) && $relatedProductGroschen->isConfidential() === false) {
                $relatedProducts->push([
                    'ProductRelationCode' => '06',
                    'ProductIdentifiers' => [
                        [
                            'ProductIDType' => '03',
                            'IDValue' => intval($production->isbn),
                        ],
                    ],
                ]);
            }
        }

        return $relatedProducts;
    }

    /**
     * Get related works
     *
     * @return Collection
     */
    public function getRelatedWorks()
    {
        $relatedWorks = new Collection;

        $relatedWorks->push([
            'WorkRelationCode' => '01',
            'WorkIdentifiers' => [
                [
                    'WorkIDType' => '01',
                    'IDTypeName' => 'Werner Söderström teostunniste',
                    'IDValue' => intval($this->workId),
                ],
            ],
        ]);

        return $relatedWorks;
    }

    /**
     * Get the products tax rate
     *
     * @return float
     */
    public function getTaxRate()
    {
        return floatval($this->product->taxCode->customProperties->taxRatePercent);
    }

    /**
     * Purifies the given XHTML
     *
     * @param  string  $text
     * @return string
     */
    public function purifyHtml($text)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.TidyLevel', 'heavy');
        $config->set('HTML.Allowed', 'p,br,strong,em,b,i,ul,ol,li,sub,sup,dl,dt,dd');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('AutoFormat.RemoveEmpty', true);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($text);
    }

    /**
     * Returns the BIC code mapped from Thema
     *
     * @return array
     */
    public function getBicCodes()
    {
        // Mapping table
        $themaToBicMapping = [
            'A' => 'A',
            'AB' => 'AB',
            'ABA' => 'ABA',
            'ABC' => 'ABC',
            'ABK' => 'ABK',
            'ABQ' => 'ABQ',
            'AF' => 'AF',
            'AFC' => 'AFC',
            'AFCC' => 'AFCC',
            'AFCL' => 'AFCL',
            'AFCM' => 'AFC',
            'AFCP' => 'AFC',
            'AFF' => 'AFF',
            'AFFC' => 'AFF',
            'AFFK' => 'AFF',
            'AFH' => 'AFH',
            'AFJ' => 'AFJ',
            'AFJY' => 'AFY',
            'AFK' => 'AFK',
            'AFKB' => 'AFKB',
            'AFKC' => 'AFKC',
            'AFKG' => 'AFKG',
            'AFKN' => 'AFKN',
            'AFKP' => 'AFKP',
            'AFKV' => 'AFKV',
            'AFP' => 'AFP',
            'AFT' => 'AFTB',
            'AFW' => 'AFW',
            'AG' => 'AG',
            'AGA' => 'ACXJ',
            'AGB' => 'AGB',
            'AGC' => 'AGC',
            'AGH' => 'AGH',
            'AGHF' => 'AGHF',
            'AGHN' => 'AGHN',
            'AGHX' => 'AGHX',
            'AGK' => 'AGK',
            'AGN' => 'AGN',
            'AGNA' => 'AGN',
            'AGNB' => 'AGNB',
            'AGNL' => 'AGN',
            'AGNS' => 'AGN',
            'AGP' => 'AGP',
            'AGR' => 'AGR',
            'AGT' => 'AG',
            'AGTS' => 'AG',
            'AGZ' => 'AGZ',
            'AGZC' => 'AGZ',
            'AJ' => 'AJ',
            'AJC' => 'AJC',
            'AJCD' => 'AJB',
            'AJCP' => 'AJCP',
            'AJCX' => 'AJCX',
            'AJF' => 'AJCR',
            'AJT' => 'AJG',
            'AJTA' => 'AJG',
            'AJTF' => 'AJRD',
            'AJTH' => 'AJG',
            'AJTR' => 'AJG',
            'AJTS' => 'AJG',
            'AJTV' => 'AJRH',
            'AK' => 'AK',
            'AKB' => 'AKB',
            'AKC' => 'AKC',
            'AKD' => 'AKD',
            'AKH' => 'AKH',
            'AKHM' => 'AKH',
            'AKL' => 'AKL',
            'AKLB' => 'AKLB',
            'AKLC' => 'AKLC',
            'AKLC1' => 'AKLC1',
            'AKLF' => 'AK',
            'AKLP' => 'AKLP',
            'AKP' => 'AKP',
            'AKR' => 'AKR',
            'AKT' => 'AKTH',
            'AKTF' => 'AKT',
            'AKTR' => 'AKTH',
            'AKX' => 'AK',
            'AM' => 'AM',
            'AMA' => 'AMA',
            'AMB' => 'AMB',
            'AMC' => 'AMC',
            'AMCD' => 'AMC',
            'AMCM' => 'AMC',
            'AMCR' => 'AMCR',
            'AMD' => 'AMD',
            'AMG' => 'AMG',
            'AMK' => 'AMK',
            'AMKH' => 'AMK',
            'AMKL' => 'AMKL',
            'AMKS' => 'AMKH',
            'AMN' => 'AMN',
            'AMR' => 'AMR',
            'AMV' => 'AMV',
            'AMVD' => 'AMVD',
            'AMX' => 'AMX',
            'AT' => 'AB',
            'ATA' => 'AB',
            'ATC' => 'APB',
            'ATD' => 'AN',
            'ATDC' => 'ANC',
            'ATDF' => 'ANF',
            'ATDH' => 'ANH',
            'ATDS' => 'ANS',
            'ATF' => 'APF',
            'ATFA' => 'APFA',
            'ATFB' => 'APFB',
            'ATFD' => 'APFD',
            'ATFG' => 'APFG',
            'ATFN' => 'APFN',
            'ATFR' => 'APFR',
            'ATFV' => 'APFV',
            'ATFV1' => 'APFV',
            'ATFX' => 'APFX',
            'ATJ' => 'APT',
            'ATJD' => 'APTD',
            'ATJS' => 'APT',
            'ATJS1' => 'APT',
            'ATJX' => 'APTX',
            'ATL' => 'APW',
            'ATLD' => 'APWD',
            'ATM' => 'AP',
            'ATMB' => 'AP',
            'ATMC' => 'AP',
            'ATMF' => 'AP',
            'ATMH' => 'AP',
            'ATMN' => 'AP',
            'ATMP' => 'AP',
            'ATN' => 'AP',
            'ATQ' => 'ASD',
            'ATQC' => 'ASDC',
            'ATQL' => 'ASDL',
            'ATQR' => 'ASDR',
            'ATQT' => 'ASDT',
            'ATQV' => 'ASD',
            'ATQZ' => 'ASDX',
            'ATR' => 'AB',
            'ATS' => 'ANH',
            'ATT' => 'ANH',
            'ATV' => 'AB',
            'ATX' => 'ASZ',
            'ATXB' => 'ASZ',
            'ATXC' => 'ASZW',
            'ATXD' => 'ASZB',
            'ATXF' => 'ASZG',
            'ATXM' => 'ASZM',
            'ATXP' => 'ASZP',
            'ATXZ' => 'ASZX',
            'ATXZ1' => 'ASZX',
            'ATY' => 'HBTB',
            'ATZ' => 'AP',
            'AV' => 'AV',
            'AVA' => 'AVA',
            'AVC' => 'AVC',
            'AVD' => 'AVD',
            'AVL' => 'AVG',
            'AVLA' => 'AVGC5',
            'AVLC' => 'AVGC8',
            'AVLF' => 'AVGC9',
            'AVLK' => 'AVGD',
            'AVLM' => 'AVGM',
            'AVLP' => 'AVG',
            'AVLT' => 'AVGH',
            'AVLW' => 'AVGW',
            'AVLX' => 'AVGV',
            'AVM' => 'AV',
            'AVN' => 'AVH',
            'AVP' => 'AVH',
            'AVQ' => 'AVQ',
            'AVQS' => 'AVQS',
            'AVR' => 'AVR',
            'AVRG' => 'AVRG',
            'AVRG1' => 'AVRG',
            'AVRJ' => 'AVRJ',
            'AVRL' => 'AVRL',
            'AVRL1' => 'AVRL1',
            'AVRL2' => 'AVRL',
            'AVRL3' => 'AVRL',
            'AVRN' => 'AVRN',
            'AVRN1' => 'AVRN',
            'AVRN2' => 'AVRN',
            'AVRQ' => 'AVRQ',
            'AVRS' => 'AVRS',
            'AVS' => 'AVS',
            'AVSA' => 'AVS',
            'AVSD' => 'AVS',
            'AVX' => 'AVX',
            'C' => 'C',
            'CB' => 'CB',
            'CBD' => 'CBD',
            'CBDX' => 'CBDX',
            'CBF' => 'CBF',
            'CBG' => 'CBG',
            'CBM' => 'CBX',
            'CBP' => 'CBP',
            'CBV' => 'CBV',
            'CBVS' => 'CBVS',
            'CBW' => 'CBWT',
            'CBX' => 'CBX',
            'CF' => 'CF',
            'CFA' => 'CFA',
            'CFB' => 'CFB',
            'CFC' => 'CFC',
            'CFD' => 'CFD',
            'CFDC' => 'CFDC',
            'CFDM' => 'CFDM',
            'CFF' => 'CFF',
            'CFFD' => 'CFFD',
            'CFG' => 'CFG',
            'CFH' => 'CFH',
            'CFK' => 'CFK',
            'CFL' => 'CFL',
            'CFLA' => 'CFLA',
            'CFM' => 'CFM',
            'CFN' => 'CF',
            'CFP' => 'CFP',
            'CFX' => 'CFX',
            'CFZ' => 'CFZ',
            'CJ' => 'EL',
            'CJA' => 'EBA',
            'CJAB' => 'CJA',
            'CJAD' => 'EBA',
            'CJB' => 'EL',
            'CJBC' => 'EL',
            'CJBG' => 'ELG',
            'CJBR' => 'ELH',
            'CJBT' => 'ELS',
            'CJC' => 'ELX',
            'CJCK' => 'ELXD',
            'CJCL' => 'ELXG',
            'CJCR' => 'ELXJ',
            'CJCW' => 'ELXN',
            'CJP' => 'ES',
            'CJPD' => 'ESB',
            'CJPG' => 'EST',
            'D' => 'D',
            'DB' => 'DB',
            'DBS' => 'DB',
            'DBSG' => 'DB',
            'DBSN' => 'DB',
            'DC' => 'DC',
            'DCA' => 'DC',
            'DCC' => 'DC',
            'DCF' => 'DCF',
            'DCQ' => 'DCQ',
            'DCR' => 'DC',
            'DCRB' => 'DC',
            'DCRC' => 'DC',
            'DCRG' => 'DC',
            'DCRH' => 'DC',
            'DCRL' => 'DC',
            'DCRS' => 'DC',
            'DD' => 'DDS',
            'DDA' => 'DDS',
            'DDC' => 'DD',
            'DDL' => 'DDS',
            'DDT' => 'DDS',
            'DDV' => 'DC',
            'DH' => 'DN',
            'DN' => 'DN',
            'DNB' => 'BG',
            'DNBA' => 'BGA',
            'DNBB' => 'BGB',
            'DNBB1' => 'BGBA',
            'DNBF' => 'BGF',
            'DNBF1' => 'BGFA',
            'DNBG' => 'BGF',
            'DNBG1' => 'BGFA',
            'DNBH' => 'BGH',
            'DNBH1' => 'BGHA',
            'DNBL' => 'BGL',
            'DNBL1' => 'BGLA',
            'DNBM' => 'BG',
            'DNBM1' => 'BGA',
            'DNBP' => 'BG',
            'DNBP1' => 'BGA',
            'DNBR' => 'BGR',
            'DNBR1' => 'BGRA',
            'DNBS' => 'BGS',
            'DNBS1' => 'BGSA',
            'DNBT' => 'BGT',
            'DNBT1' => 'BGTA',
            'DNBX' => 'BGX',
            'DNBX1' => 'BGXA',
            'DNBZ' => 'BK',
            'DNC' => 'BM',
            'DND' => 'BJ',
            'DNG' => 'JFFZ',
            'DNL' => 'DNF',
            'DNP' => 'DNJ',
            'DNPB' => 'DN',
            'DNS' => 'DNS',
            'DNT' => 'DQ',
            'DNX' => 'BT',
            'DNXC' => 'BTC',
            'DNXC2' => 'BTC',
            'DNXC3' => 'BTC',
            'DNXH' => 'BTH',
            'DNXM' => 'BTM',
            'DNXP' => 'BTP',
            'DNXR' => 'BTP',
            'DNXZ' => 'BTX',
            'DS' => 'DS',
            'DSA' => 'DSA',
            'DSB' => 'DSB',
            'DSBB' => 'DSBB',
            'DSBC' => 'DSBD',
            'DSBD' => 'DSBD',
            'DSBF' => 'DSBF',
            'DSBH' => 'DSBH',
            'DSBH5' => 'DSBH5',
            'DSBJ' => 'DSBH',
            'DSC' => 'DSC',
            'DSG' => 'DSG',
            'DSK' => 'DSK',
            'DSM' => 'DS',
            'DSR' => 'DSR',
            'DSRC' => 'DSRC',
            'DSY' => 'DSY',
            'DSYC' => 'DSYC',
            'F' => 'F',
            'FB' => 'FA',
            'FBA' => 'FA',
            'FBAN' => 'FA',
            'FBC' => 'FC',
            'FC' => 'FA',
            'FD' => 'FA',
            'FDB' => 'FA',
            'FDK' => 'FA',
            'FDM' => 'FA',
            'FDV' => 'FA',
            'FF' => 'FF',
            'FFC' => 'FFC',
            'FFD' => 'FF',
            'FFH' => 'FFH',
            'FFJ' => 'FF',
            'FFK' => 'FF',
            'FFL' => 'FF',
            'FFP' => 'FF',
            'FFS' => 'FF',
            'FG' => 'FA',
            'FH' => 'FH',
            'FHD' => 'FHD',
            'FHF' => 'FH',
            'FHK' => 'FH',
            'FHM' => 'FH',
            'FHP' => 'FHP',
            'FHQ' => 'FH',
            'FHR' => 'FH',
            'FHS' => 'FH',
            'FHT' => 'FH',
            'FHX' => 'FH',
            'FJ' => 'FJ',
            'FJD' => 'FJ',
            'FJH' => 'FJH',
            'FJM' => 'FJM',
            'FJMC' => 'FJMC',
            'FJMF' => 'FJMF',
            'FJMS' => 'FJMS',
            'FJMV' => 'FJMV',
            'FJN' => 'FJ',
            'FJW' => 'FJW',
            'FK' => 'FK',
            'FKC' => 'FKC',
            'FKM' => 'FK',
            'FKW' => 'FK',
            'FL' => 'FL',
            'FLC' => 'FLC',
            'FLG' => 'FL',
            'FLH' => 'FL',
            'FLJ' => 'FL',
            'FLM' => 'FL',
            'FLP' => 'FL',
            'FLPB' => 'FL',
            'FLQ' => 'FL',
            'FLR' => 'FL',
            'FLS' => 'FLS',
            'FLU' => 'FL',
            'FLW' => 'FL',
            'FM' => 'FM',
            'FMB' => 'FM',
            'FMBN' => 'FM',
            'FMH' => 'FM',
            'FMJ' => 'FM',
            'FMK' => 'FM',
            'FMM' => 'FM',
            'FMR' => 'FMR',
            'FMS' => 'FM',
            'FMT' => 'FM',
            'FMW' => 'FM',
            'FMX' => 'FM',
            'FN' => 'FQ',
            'FNF' => 'FQ',
            'FNM' => 'FQ',
            'FNT' => 'FQ',
            'FP' => 'FP',
            'FQ' => 'FA',
            'FR' => 'FR',
            'FRB' => 'FR',
            'FRD' => 'FRD',
            'FRDJ' => 'FR',
            'FRE' => 'FR',
            'FRF' => 'FR',
            'FRG' => 'FR',
            'FRH' => 'FRH',
            'FRJ' => 'FR',
            'FRK' => 'FR',
            'FRL' => 'FR',
            'FRLC' => 'FR',
            'FRLE' => 'FR',
            'FRLF' => 'FR',
            'FRLH' => 'FR',
            'FRM' => 'FR',
            'FRN' => 'FR',
            'FRP' => 'FR',
            'FRQ' => 'FR',
            'FRR' => 'FR',
            'FRS' => 'FR',
            'FRT' => 'FR',
            'FRU' => 'FR',
            'FRV' => 'FR',
            'FRW' => 'FR',
            'FRX' => 'FR',
            'FS' => 'FA',
            'FT' => 'FT',
            'FU' => 'FA',
            'FUP' => 'FA',
            'FV' => 'FV',
            'FW' => 'FW',
            'FX' => 'FY',
            'FXB' => 'FY',
            'FXC' => 'FY',
            'FXD' => 'FY',
            'FXE' => 'FY',
            'FXED' => 'FY',
            'FXF' => 'FY',
            'FXK' => 'FY',
            'FXL' => 'FY',
            'FXM' => 'FY',
            'FXN' => 'FY',
            'FXP' => 'FY',
            'FXQ' => 'FY',
            'FXR' => 'FY',
            'FXS' => 'FY',
            'FXT' => 'FY',
            'FXV' => 'FY',
            'FY' => 'FY',
            'FYB' => 'FYB',
            'FYC' => 'FY',
            'FYD' => 'FY',
            'FYG' => 'FY',
            'FYH' => 'FY',
            'FYJ' => 'FY',
            'FYL' => 'FY',
            'FYM' => 'FY',
            'FYP' => 'FY',
            'FYQ' => 'FA',
            'FYR' => 'FY',
            'FYS' => 'FY',
            'FYT' => 'FYT',
            'FYV' => 'FY',
            'FYW' => 'FA',
            'FZ' => 'FZC',
            'G' => 'G',
            'GB' => 'GB',
            'GBA' => 'GBA',
            'GBC' => 'GBC',
            'GBCB' => 'GBCB',
            'GBCD' => 'GBC',
            'GBCF' => 'GBC',
            'GBCQ' => 'GBCQ',
            'GBCR' => 'GBCR',
            'GBCS' => 'GBCS',
            'GBCT' => 'GBCT',
            'GBCY' => 'GBCY',
            'GBD' => 'GBC',
            'GL' => 'GL',
            'GLC' => 'GLC',
            'GLCA' => 'GLC',
            'GLF' => 'GLF',
            'GLH' => 'GLH',
            'GLK' => 'GLK',
            'GLM' => 'GLM',
            'GLP' => 'GLP',
            'GLZ' => 'GM',
            'GM' => 'GTC',
            'GP' => 'GP',
            'GPF' => 'GPF',
            'GPFC' => 'GPFC',
            'GPH' => 'GPH',
            'GPJ' => 'GPJ',
            'GPQ' => 'GPQ',
            'GPQD' => 'GPQD',
            'GPS' => 'GPS',
            'GPSB' => 'GPS',
            'GPSD' => 'GPS',
            'GPSE' => 'GPS',
            'GPSF' => 'GPS',
            'GT' => 'GT',
            'GTB' => 'GTG',
            'GTC' => 'GTC',
            'GTD' => 'GTE',
            'GTF' => 'GT',
            'GTK' => 'GTR',
            'GTM' => 'GTB',
            'GTP' => 'GTF',
            'GTQ' => 'JFFS',
            'GTS' => 'JFCX',
            'GTT' => 'GTH',
            'GTU' => 'GTJ',
            'GTV' => 'GTN',
            'GTZ' => 'GTG',
            'J' => 'J',
            'JB' => 'JF',
            'JBC' => 'JFC',
            'JBCC' => 'JFC',
            'JBCC1' => 'JFCA',
            'JBCC2' => 'JFCD',
            'JBCC3' => 'JFCK',
            'JBCC4' => 'JFCV',
            'JBCC6' => 'JFC',
            'JBCC7' => 'JFC',
            'JBCC8' => 'JFC',
            'JBCC9' => 'JFCX',
            'JBCT' => 'JFD',
            'JBCT1' => 'JFD',
            'JBCT2' => 'JFDT',
            'JBCT3' => 'JFDV',
            'JBCT4' => 'JFD',
            'JBCT5' => 'JFD',
            'JBF' => 'JFF',
            'JBFA' => 'JFFJ',
            'JBFA1' => 'JFFJ',
            'JBFB' => 'JFF',
            'JBFB2' => 'JFF',
            'JBFC' => 'JFFA',
            'JBFD' => 'JFFB',
            'JBFF' => 'JFFC',
            'JBFG' => 'JFFD',
            'JBFG1' => 'JFF',
            'JBFH' => 'JFFN',
            'JBFH1' => 'JFFN',
            'JBFJ' => 'JFFN',
            'JBFK' => 'JFFE',
            'JBFK1' => 'JFFE1',
            'JBFK2' => 'JFFE2',
            'JBFK3' => 'JFFE3',
            'JBFK4' => 'JFFE',
            'JBFL' => 'JFF',
            'JBFM' => 'JFFG',
            'JBFN' => 'JFFH',
            'JBFN2' => 'JFFH1',
            'JBFQ' => 'JFFM',
            'JBFS' => 'JFFT',
            'JBFU' => 'JFFZ',
            'JBFV' => 'JFM',
            'JBFV1' => 'JFMA',
            'JBFV2' => 'JFMC',
            'JBFV3' => 'JFMD',
            'JBFV4' => 'JFME',
            'JBFV5' => 'JFMG',
            'JBFV6' => 'JFM',
            'JBFW' => 'JFF',
            'JBFX' => 'JFF',
            'JBFZ' => 'JFFR',
            'JBG' => 'JFH',
            'JBGB' => 'JFHF',
            'JBGX' => 'JFHC',
            'JBS' => 'JFS',
            'JBSA' => 'JFSC',
            'JBSB' => 'JFSL9',
            'JBSC' => 'JFSF',
            'JBSD' => 'JFSG',
            'JBSD1' => 'JFSG',
            'JBSE' => 'JFSG',
            'JBSF' => 'JFSJ',
            'JBSF1' => 'JFSJ1',
            'JBSF11' => 'JFFK',
            'JBSF2' => 'JFSJ2',
            'JBSF3' => 'JFSJ5',
            'JBSJ' => 'JFSK1',
            'JBSJ2' => 'JFSK1',
            'JBSL' => 'JFSL',
            'JBSL1' => 'JFSL1',
            'JBSL11' => 'JFSL9',
            'JBSL13' => 'JFSL',
            'JBSP' => 'JFSP',
            'JBSP1' => 'JFSP1',
            'JBSP2' => 'JFSP2',
            'JBSP3' => 'JFSP3',
            'JBSP4' => 'JFSP31',
            'JBSP9' => 'JFFR',
            'JBSR' => 'JFSR2',
            'JBSW' => 'JFSS',
            'JBSX' => 'JFSV1',
            'JBSY' => 'JFSV',
            'JH' => 'JH',
            'JHB' => 'JHBT',
            'JHBA' => 'JHBA',
            'JHBC' => 'JHBC',
            'JHBD' => 'JHBD',
            'JHBK' => 'JHBK',
            'JHBL' => 'JHBL',
            'JHBS' => 'JHBS',
            'JHBZ' => 'JHBZ',
            'JHM' => 'JHM',
            'JHMC' => 'JHMC',
            'JK' => 'JK',
            'JKS' => 'JKS',
            'JKSB' => 'JKSB',
            'JKSB1' => 'JKSB1',
            'JKSF' => 'JKSF',
            'JKSG' => 'JKSG',
            'JKSL' => 'JKS',
            'JKSM' => 'JKSM',
            'JKSN' => 'JKSN',
            'JKSN1' => 'JKSN1',
            'JKSN2' => 'JKSN2',
            'JKSR' => 'JKSR',
            'JKSW' => 'JKSW',
            'JKSW1' => 'JKSW1',
            'JKSW2' => 'JKSW2',
            'JKSW3' => 'JKSW3',
            'JKV' => 'JKV',
            'JKVC' => 'JKVC',
            'JKVF' => 'JKVF',
            'JKVF1' => 'JKVF1',
            'JKVG' => 'JKVG',
            'JKVJ' => 'JKVJ',
            'JKVK' => 'JKVK',
            'JKVM' => 'JKVM',
            'JKVN' => 'JKV',
            'JKVN1' => 'JKV',
            'JKVP' => 'JKVP',
            'JKVQ' => 'JKVQ',
            'JKVQ1' => 'JKVQ1',
            'JKVQ2' => 'JKVQ2',
            'JKVS' => 'JKVS',
            'JKVV' => 'JKV',
            'JM' => 'JM',
            'JMA' => 'JMA',
            'JMAF' => 'JMAF',
            'JMAF1' => 'JMA',
            'JMAJ' => 'JMAJ',
            'JMAL' => 'JMAL',
            'JMAN' => 'JMAN',
            'JMAP' => 'JMA',
            'JMAQ' => 'JMAQ',
            'JMB' => 'JMB',
            'JMBT' => 'JMBT',
            'JMC' => 'JMC',
            'JMD' => 'JMD',
            'JME' => 'JM',
            'JMF' => 'JMF',
            'JMG' => 'JMG',
            'JMH' => 'JMH',
            'JMHC' => 'JMH',
            'JMJ' => 'JMJ',
            'JMK' => 'JMK',
            'JML' => 'JML',
            'JMM' => 'JMM',
            'JMN' => 'JM',
            'JMP' => 'JMP',
            'JMQ' => 'JMQ',
            'JMR' => 'JMR',
            'JMS' => 'JMS',
            'JMT' => 'JMT',
            'JMU' => 'JMU',
            'JMX' => 'JMX',
            'JN' => 'JN',
            'JNA' => 'JNA',
            'JNAM' => 'JNAM',
            'JNAS' => 'JNA',
            'JNB' => 'JNB',
            'JNC' => 'JNC',
            'JND' => 'JNK',
            'JNDC' => 'JNK',
            'JNDG' => 'JNKC',
            'JNDH' => 'JNKD',
            'JNE' => 'JN',
            'JNF' => 'JNF',
            'JNFC' => 'JNH',
            'JNFK' => 'JNFN',
            'JNFN' => 'JNKP',
            'JNG' => 'JNF',
            'JNH' => 'JNQ',
            'JNK' => 'JNK',
            'JNKG' => 'JNKG',
            'JNKH' => 'JNKH',
            'JNL' => 'JNL',
            'JNLA' => 'JNLA',
            'JNLB' => 'JNLB',
            'JNLC' => 'JNLC',
            'JNLP' => 'JNLP',
            'JNLQ' => 'JNL',
            'JNLR' => 'JNLR',
            'JNLV' => 'JNL',
            'JNM' => 'JNM',
            'JNMT' => 'JNMT',
            'JNP' => 'JNP',
            'JNQ' => 'JNQ',
            'JNR' => 'JNR',
            'JNRD' => 'JNRV',
            'JNRV' => 'JNRV',
            'JNS' => 'JNS',
            'JNSC' => 'JNSC1',
            'JNSG' => 'JNSG2',
            'JNSL' => 'JNSL',
            'JNSP' => 'JNSP',
            'JNSR' => 'JNS',
            'JNSV' => 'JNS',
            'JNT' => 'JNT',
            'JNTC' => 'JNF',
            'JNTP' => 'JNF',
            'JNTR' => 'JNT',
            'JNTS' => 'JNT',
            'JNU' => 'JNU',
            'JNUM' => 'JNUM',
            'JNUM1' => 'JNUM',
            'JNV' => 'JNV',
            'JNW' => 'JNW',
            'JNZ' => 'JNZ',
            'JP' => 'JP',
            'JPA' => 'JPA',
            'JPB' => 'JPB',
            'JPF' => 'JPF',
            'JPFA' => 'JPF',
            'JPFB' => 'JPFB',
            'JPFC' => 'JPFC',
            'JPFF' => 'JPFF',
            'JPFK' => 'JPFK',
            'JPFL' => 'JPF',
            'JPFM' => 'JPFM',
            'JPFN' => 'JPFN',
            'JPFN2' => 'JPFN',
            'JPFP' => 'JPF',
            'JPFQ' => 'JPFQ',
            'JPFR' => 'JPFR',
            'JPFT' => 'JPFM',
            'JPH' => 'JPH',
            'JPHC' => 'JPHC',
            'JPHF' => 'JPHF',
            'JPHL' => 'JPHL',
            'JPHR' => 'JPH',
            'JPHT' => 'JPH',
            'JPHV' => 'JPHV',
            'JPHX' => 'JPHX',
            'JPL' => 'JPL',
            'JPN' => 'JPR',
            'JPP' => 'JPP',
            'JPQ' => 'JPQ',
            'JPQB' => 'JPQB',
            'JPR' => 'JPR',
            'JPRB' => 'JPRB',
            'JPS' => 'JPS',
            'JPSD' => 'JPSD',
            'JPSF' => 'JPSF',
            'JPSH' => 'JPSH',
            'JPSL' => 'JPSL',
            'JPSL1' => 'JPSL',
            'JPSN' => 'JPSN2',
            'JPT' => 'JPR',
            'JPV' => 'JPV',
            'JPVC' => 'JPVH1',
            'JPVH' => 'JPVH',
            'JPVR' => 'JPVR',
            'JPVR1' => 'JPVR',
            'JPW' => 'JPW',
            'JPWA' => 'JPVK',
            'JPWB' => 'JPWD',
            'JPWC' => 'JPVL',
            'JPWG' => 'JPWD',
            'JPWH' => 'JPWH',
            'JPWL' => 'JPWL',
            'JPWQ' => 'JPWQ',
            'JPWS' => 'JPWS',
            'JPZ' => 'JPZ',
            'JW' => 'JW',
            'JWA' => 'JWA',
            'JWC' => 'JW',
            'JWCD' => 'JWD',
            'JWCG' => 'JWDG',
            'JWCK' => 'JWF',
            'JWCM' => 'JWG',
            'JWCS' => 'JWH',
            'JWD' => 'JWM',
            'JWJ' => 'JWJ',
            'JWK' => 'JWK',
            'JWKF' => 'JWKF',
            'JWL' => 'JWL',
            'JWLF' => 'JWLF',
            'JWLP' => 'JWLP',
            'JWM' => 'JWM',
            'JWMC' => 'JWMC',
            'JWMN' => 'JWMN',
            'JWMV' => 'JWMV3',
            'JWT' => 'JWT',
            'JWTU' => 'JWTU',
            'JWX' => 'JWX',
            'JWXF' => 'JWXF',
            'JWXJ' => 'JWX',
            'JWXK' => 'JWXK',
            'JWXN' => 'JWXN',
            'JWXR' => 'JWXR',
            'JWXT' => 'JWXT',
            'JWXV' => 'JWXV',
            'JWXZ' => 'JWXZ',
            'K' => 'K',
            'KC' => 'KC',
            'KCA' => 'KCA',
            'KCB' => 'KCB',
            'KCBM' => 'KCBM',
            'KCC' => 'KCC',
            'KCCD' => 'KCCD',
            'KCD' => 'KCD',
            'KCF' => 'KCF',
            'KCG' => 'KCG',
            'KCH' => 'KCH',
            'KCJ' => 'KCJ',
            'KCK' => 'KCK',
            'KCL' => 'KCL',
            'KCLT' => 'KCLT',
            'KCM' => 'KCM',
            'KCP' => 'KCP',
            'KCS' => 'KCS',
            'KCSA' => 'KCS',
            'KCSD' => 'KCS',
            'KCSG' => 'KCS',
            'KCST' => 'KCS',
            'KCSV' => 'KCS',
            'KCV' => 'KCB',
            'KCVD' => 'KCT',
            'KCVG' => 'KCN',
            'KCVJ' => 'KCQ',
            'KCVK' => 'KCR',
            'KCVM' => 'KC',
            'KCVP' => 'KC',
            'KCVQ' => 'KC',
            'KCVS' => 'KCU',
            'KCX' => 'KCX',
            'KCY' => 'KCY',
            'KCZ' => 'KCZ',
            'KF' => 'KF',
            'KFC' => 'KFC',
            'KFCC' => 'KFCC',
            'KFCF' => 'KFCF',
            'KFCM' => 'KFCM',
            'KFCM1' => 'KFCM',
            'KFCM2' => 'KFCM',
            'KFCP' => 'KFCP',
            'KFCR' => 'KFCR',
            'KFCT' => 'KFC',
            'KFCX' => 'KFCX',
            'KFF' => 'KFF',
            'KFFC' => 'KFF',
            'KFFD' => 'KFFD',
            'KFFF' => 'KFF',
            'KFFH' => 'KFFH',
            'KFFJ' => 'KFF',
            'KFFK' => 'KFFK',
            'KFFL' => 'KFFL',
            'KFFM' => 'KFFM',
            'KFFN' => 'KFFN',
            'KFFP' => 'KFFP',
            'KFFR' => 'KFFR',
            'KFFS' => 'KFF',
            'KFFT' => 'KFF',
            'KFFX' => 'KFFX',
            'KJ' => 'KJ',
            'KJB' => 'KJB',
            'KJBX' => 'KJBX',
            'KJC' => 'KJC',
            'KJD' => 'KJD',
            'KJDD' => 'KJD',
            'KJE' => 'KJE',
            'KJF' => 'KJF',
            'KJG' => 'KJG',
            'KJH' => 'KJH',
            'KJJ' => 'KJJ',
            'KJK' => 'KJK',
            'KJL' => 'KJL',
            'KJM' => 'KJM',
            'KJMB' => 'KJMB',
            'KJMD' => 'KJMD',
            'KJMK' => 'KJMV3',
            'KJMN' => 'KJMV5',
            'KJMP' => 'KJMP',
            'KJMQ' => 'KJMQ',
            'KJMT' => 'KJMT',
            'KJMV' => 'KJMV',
            'KJMV1' => 'KJMV1',
            'KJMV2' => 'KJMV2',
            'KJMV21' => 'KJMV2',
            'KJMV22' => 'KJMV2',
            'KJMV3' => 'KJMV',
            'KJMV4' => 'KJMV4',
            'KJMV5' => 'KJMV5',
            'KJMV6' => 'KJMV6',
            'KJMV7' => 'KJMV7',
            'KJMV8' => 'KJMV8',
            'KJMV9' => 'KJMV9',
            'KJN' => 'KJN',
            'KJP' => 'KJP',
            'KJQ' => 'KJQ',
            'KJR' => 'KJR',
            'KJS' => 'KJS',
            'KJSA' => 'KJSA',
            'KJSC' => 'KJS',
            'KJSG' => 'KJS',
            'KJSJ' => 'KJS',
            'KJSM' => 'KJSM',
            'KJSP' => 'KJSP',
            'KJSR' => 'KJS',
            'KJST' => 'KJS',
            'KJSU' => 'KJSU',
            'KJT' => 'KJT',
            'KJU' => 'KJU',
            'KJV' => 'KJV',
            'KJVB' => 'KJVB',
            'KJVD' => 'KJVD',
            'KJVF' => 'KJVF',
            'KJVG' => 'KJVG',
            'KJVH' => 'KJV',
            'KJVN' => 'KJVN',
            'KJVP' => 'KJVP',
            'KJVQ' => 'KJV',
            'KJVS' => 'KJVS',
            'KJVT' => 'KJVT',
            'KJVV' => 'KJVV',
            'KJVW' => 'KJVW',
            'KJVX' => 'KJVX',
            'KJW' => 'KJW',
            'KJWB' => 'KJWB',
            'KJWF' => 'KJWF',
            'KJWS' => 'KJWS',
            'KJWX' => 'KJWX',
            'KJZ' => 'KJZ',
            'KN' => 'KN',
            'KNA' => 'KNA',
            'KNAC' => 'KNAC',
            'KNAF' => 'KNAF',
            'KNAL' => 'KNAL',
            'KNAT' => 'KNAT',
            'KNB' => 'KNB',
            'KNBL' => 'KNBL',
            'KNBP' => 'KNB',
            'KNBT' => 'KNBT',
            'KNBW' => 'KNBW',
            'KND' => 'KND',
            'KNDC' => 'KNDC',
            'KNDD' => 'KNDD',
            'KNDR' => 'KND',
            'KNG' => 'KNG',
            'KNJ' => 'KNJ',
            'KNJC' => 'KNJC',
            'KNJH' => 'KNJH',
            'KNP' => 'KNP',
            'KNS' => 'KNS',
            'KNSB' => 'KNS',
            'KNSG' => 'KNSG',
            'KNSJ' => 'KNSJ',
            'KNSX' => 'KNSX',
            'KNSZ' => 'KNSZ',
            'KNT' => 'KNT',
            'KNTC' => 'KNT',
            'KNTF' => 'KNTF',
            'KNTP' => 'KNTP',
            'KNTP1' => 'KNTP',
            'KNTP2' => 'KNTJ',
            'KNTR' => 'KNTR',
            'KNTV' => 'KNTX',
            'KNTX' => 'KNTX',
            'KNV' => 'KNV',
            'KNX' => 'KNX',
            'KNXC' => 'KNXC',
            'KNXH' => 'KNX',
            'KNXN' => 'KNXB3',
            'KNXU' => 'KNXB2',
            'L' => 'L',
            'LA' => 'LA',
            'LAB' => 'LAB',
            'LABN' => 'LA',
            'LAF' => 'LAF',
            'LAFC' => 'LAFC',
            'LAFD' => 'LAFD',
            'LAFF' => 'LAF',
            'LAFG' => 'LAF',
            'LAFP' => 'LAF',
            'LAFR' => 'LAFR',
            'LAFS' => 'LAFS',
            'LAFT' => 'LAF',
            'LAFX' => 'LAFX',
            'LAH' => 'LA',
            'LAM' => 'LAM',
            'LAP' => 'LA',
            'LAQ' => 'LAQ',
            'LAQG' => 'LAQG',
            'LAR' => 'LAR',
            'LAS' => 'LAS',
            'LASB' => 'LAS',
            'LASD' => 'LASD',
            'LASH' => 'LAS',
            'LASK' => 'LAS',
            'LASN' => 'LAS',
            'LASP' => 'LASP',
            'LAT' => 'LAT',
            'LATC' => 'LATC',
            'LAY' => 'LAY',
            'LAZ' => 'LAZ',
            'LB' => 'LB',
            'LBB' => 'LBB',
            'LBBC' => 'LBBC',
            'LBBC1' => 'LBBC1',
            'LBBD' => 'LBBD',
            'LBBF' => 'LBBF',
            'LBBJ' => 'LBBJ',
            'LBBK' => 'LBBK',
            'LBBL' => 'LBB',
            'LBBM' => 'LBBM',
            'LBBM1' => 'LBBM1',
            'LBBM3' => 'LBBM3',
            'LBBM5' => 'LBBM',
            'LBBM7' => 'LBBM',
            'LBBP' => 'LBBP',
            'LBBP1' => 'LBBP',
            'LBBQ' => 'LBB',
            'LBBR' => 'LBBR',
            'LBBR1' => 'LBBR',
            'LBBS' => 'LBBS',
            'LBBU' => 'LBBU',
            'LBBV' => 'LBBV',
            'LBBZ' => 'LBBZ',
            'LBD' => 'LBD',
            'LBDA' => 'LBDA',
            'LBDK' => 'LBDK',
            'LBDM' => 'LBDM',
            'LBDT' => 'LBDT',
            'LBG' => 'LBG',
            'LBH' => 'LBH',
            'LBHG' => 'LBHG',
            'LBHT' => 'LBHT',
            'LBJ' => 'LB',
            'LBK' => 'LB',
            'LBL' => 'LBL',
            'LN' => 'LN',
            'LNA' => 'LNA',
            'LNAA' => 'LNAA',
            'LNAA1' => 'LNAA1',
            'LNAA12' => 'LNAA',
            'LNAA2' => 'LNAA2',
            'LNAC' => 'LNAC',
            'LNAC1' => 'LNAC1',
            'LNAC12' => 'LNAC12',
            'LNAC14' => 'LNAC14',
            'LNAC16' => 'LNAC16',
            'LNAC3' => 'LNAC3',
            'LNAC4' => 'LNAC',
            'LNAC5' => 'LNAC5',
            'LNAD' => 'LNA',
            'LNAF' => 'LNAF',
            'LNAL' => 'LNAL',
            'LNB' => 'LNB',
            'LNBA' => 'LNB',
            'LNBB' => 'LNB',
            'LNBF' => 'LNB',
            'LNC' => 'LNC',
            'LNCB' => 'LNCB',
            'LNCB1' => 'LNCB1',
            'LNCB2' => 'LNCB2',
            'LNCB3' => 'LNCB3',
            'LNCB4' => 'LNCB4',
            'LNCB5' => 'LNCB5',
            'LNCB6' => 'LNCB6',
            'LNCB7' => 'LNCB',
            'LNCB8' => 'LNCB',
            'LNCC' => 'LNC',
            'LNCD' => 'LNCD',
            'LNCD1' => 'LNCD1',
            'LNCE' => 'LNCD',
            'LNCF' => 'LNCF',
            'LNCG' => 'LNCD',
            'LNCH' => 'LNCH',
            'LNCJ' => 'LNCJ',
            'LNCK' => 'LNCD',
            'LNCL' => 'LNCL',
            'LNCN' => 'LNCN',
            'LNCQ' => 'LNCQ',
            'LNCQ1' => 'LNCQ',
            'LNCQ2' => 'LNCQ',
            'LNCR' => 'LNCR',
            'LND' => 'LND',
            'LNDA' => 'LNDA',
            'LNDA1' => 'LNDA1',
            'LNDA3' => 'LNDA3',
            'LNDB' => 'LND',
            'LNDB1' => 'LND',
            'LNDB2' => 'LND',
            'LNDB3' => 'LND',
            'LNDB4' => 'LND',
            'LNDB5' => 'LND',
            'LNDB6' => 'LND',
            'LNDB7' => 'LND',
            'LNDB8' => 'LND',
            'LNDC' => 'LNDC',
            'LNDC1' => 'LNDC',
            'LNDC2' => 'LNDC2',
            'LNDC4' => 'LNDC4',
            'LNDC5' => 'LND',
            'LNDE' => 'LND',
            'LNDF' => 'LNDF',
            'LNDG' => 'LNDC',
            'LNDH' => 'LNDH',
            'LNDJ' => 'LND',
            'LNDK' => 'LNDK',
            'LNDL' => 'LNF',
            'LNDM' => 'LNDM',
            'LNDP' => 'LNDP',
            'LNDS' => 'LNDS',
            'LNDU' => 'LNDU',
            'LNDV' => 'LNDU',
            'LNDX' => 'LND',
            'LNE' => 'LND',
            'LNEA' => 'LND',
            'LNEB' => 'LND',
            'LNEC' => 'LND',
            'LNEF' => 'LND',
            'LNF' => 'LNF',
            'LNFB' => 'LNFB',
            'LNFG' => 'LNFG',
            'LNFG1' => 'LNFG',
            'LNFG2' => 'LNFG',
            'LNFJ' => 'LNFJ',
            'LNFJ1' => 'LNFJ1',
            'LNFJ2' => 'LNFJ',
            'LNFL' => 'LNFL',
            'LNFN' => 'LNFN',
            'LNFQ' => 'LNFQ',
            'LNFR' => 'LNFR',
            'LNFS' => 'LNFB',
            'LNFT' => 'LNFT',
            'LNFU' => 'LNFB',
            'LNFV' => 'LNFV',
            'LNFW' => 'LNFB',
            'LNFX' => 'LNFX',
            'LNFX1' => 'LNFX1',
            'LNFX3' => 'LNFX3',
            'LNFX31' => 'LNFX',
            'LNFX5' => 'LNFX5',
            'LNFX51' => 'LNFX5',
            'LNFX7' => 'LNFB',
            'LNFY' => 'LNFX',
            'LNH' => 'LNH',
            'LNHD' => 'LNHD',
            'LNHH' => 'LNHH',
            'LNHJ' => 'LNH',
            'LNHR' => 'LNHR',
            'LNHU' => 'LNHU',
            'LNHW' => 'LNH',
            'LNHX' => 'LNHR',
            'LNJ' => 'LNJ',
            'LNJD' => 'LNJD',
            'LNJS' => 'LNJS',
            'LNJX' => 'LNJX',
            'LNK' => 'LNK',
            'LNKC' => 'LNKJ',
            'LNKF' => 'LNKF',
            'LNKG' => 'LNKG',
            'LNKJ' => 'LNKJ',
            'LNKK' => 'LNK',
            'LNKN' => 'LNKN',
            'LNKP' => 'LNKJ',
            'LNKT' => 'LNKT',
            'LNKV' => 'LNKV',
            'LNKW' => 'LNKW',
            'LNKX' => 'LNK',
            'LNL' => 'LNL',
            'LNM' => 'LNM',
            'LNMB' => 'LNMB',
            'LNMC' => 'LNMC',
            'LNMF' => 'LNMF',
            'LNMG' => 'LNMK',
            'LNMI' => 'LNM',
            'LNMK' => 'LNMK',
            'LNP' => 'LNP',
            'LNPA' => 'LNPA',
            'LNPB' => 'LNPB',
            'LNPC' => 'LNPC',
            'LNPC1' => 'LNPC',
            'LNPD' => 'LNPD',
            'LNPE' => 'LNP',
            'LNPF' => 'LNPF',
            'LNPN' => 'LNPN',
            'LNPP' => 'LNPP',
            'LNQ' => 'LNQ',
            'LNQD' => 'LNQD',
            'LNQE' => 'LNQ',
            'LNQH' => 'LNQ',
            'LNQT' => 'LNQ',
            'LNR' => 'LNR',
            'LNRC' => 'LNRC',
            'LNRD' => 'LNRD',
            'LNRF' => 'LNRF',
            'LNRL' => 'LNRL',
            'LNRV' => 'LNRV',
            'LNS' => 'LNS',
            'LNSD' => 'LNS',
            'LNSH' => 'LNSH',
            'LNSH1' => 'LNSH1',
            'LNSH3' => 'LNSH3',
            'LNSH5' => 'LNSH5',
            'LNSH7' => 'LNSH7',
            'LNSH9' => 'LNSH9',
            'LNSP' => 'LNSP',
            'LNT' => 'LNT',
            'LNTC' => 'LNTC',
            'LNTD' => 'LNTD',
            'LNTH' => 'LNTH',
            'LNTH1' => 'LNTH1',
            'LNTJ' => 'LNTJ',
            'LNTM' => 'LNTM',
            'LNTM1' => 'LNTM1',
            'LNTM2' => 'LNTM2',
            'LNTN' => 'LNTM2',
            'LNTQ' => 'LNTQ',
            'LNTS' => 'LNTS',
            'LNTU' => 'LNTU',
            'LNTV' => 'LNTJ',
            'LNTX' => 'LNTX',
            'LNU' => 'LNU',
            'LNUC' => 'LNUC',
            'LNUD' => 'LNU',
            'LNUE' => 'LNU',
            'LNUG' => 'LNU',
            'LNUP' => 'LNUP',
            'LNUS' => 'LNUS',
            'LNUT' => 'LNUT',
            'LNUU' => 'LNU',
            'LNUV' => 'LNU',
            'LNUW' => 'LNU',
            'LNUX' => 'LNU',
            'LNUY' => 'LNU',
            'LNV' => 'LNV',
            'LNVC' => 'LNVC',
            'LNVF' => 'LNVF',
            'LNVJ' => 'LNVJ',
            'LNW' => 'LNW',
            'LNWB' => 'LNW',
            'LNX' => 'LN',
            'LNY' => 'LN',
            'LNZ' => 'LNZ',
            'LNZC' => 'LNZC',
            'LNZL' => 'LNZL',
            'LW' => 'LAFS',
            'LWF' => 'LAFS',
            'LWFA' => 'LAFS',
            'LWFB' => 'LAFS',
            'LWFC' => 'LAFS',
            'LWFD' => 'LAFS',
            'LWK' => 'LAFS',
            'LWKF' => 'LAFS',
            'LWKG' => 'LAFS',
            'LWKH' => 'LAFS',
            'LWKL' => 'LAFS',
            'LWKM' => 'LAFS',
            'LWKN' => 'LAFS',
            'LWKP' => 'LAFS',
            'LWKR' => 'LAFS',
            'LWKT' => 'LAFS',
            'LWKT1' => 'LAFS',
            'LWKT2' => 'LAFS',
            'LWKT3' => 'LAFS',
            'LWKT4' => 'LAFS',
            'LWKT5' => 'LAFS',
            'LWKT6' => 'LAFS',
            'LX' => 'LR',
            'M' => 'M',
            'MB' => 'MB',
            'MBD' => 'MBD',
            'MBDC' => 'MBDC',
            'MBDP' => 'MBDP',
            'MBDS' => 'MB',
            'MBF' => 'MBF',
            'MBG' => 'MBG',
            'MBGD' => 'MBG',
            'MBGL' => 'MBGL',
            'MBGR' => 'MBGR',
            'MBGR1' => 'MBGR1',
            'MBGT' => 'MBGT',
            'MBN' => 'MBN',
            'MBNC' => 'MBNC',
            'MBND' => 'MBN',
            'MBNF' => 'MBN',
            'MBNH' => 'MBNH',
            'MBNH1' => 'MBNH1',
            'MBNH2' => 'MBNH2',
            'MBNH3' => 'MBNH3',
            'MBNH4' => 'MBNH4',
            'MBNH9' => 'MBNH9',
            'MBNK' => 'MBN',
            'MBNS' => 'MBNS',
            'MBP' => 'MBP',
            'MBPA' => 'MBP',
            'MBPC' => 'MBPC',
            'MBPK' => 'MBPK',
            'MBPM' => 'MBPM',
            'MBPN' => 'MBP',
            'MBPR' => 'MBPR',
            'MBQ' => 'MBQ',
            'MBR' => 'MBP',
            'MBS' => 'MBS',
            'MBX' => 'MBX',
            'MF' => 'MF',
            'MFC' => 'MFC',
            'MFCC' => 'MFCC',
            'MFCH' => 'MFCH',
            'MFCR' => 'MFCR',
            'MFCX' => 'MFCX',
            'MFG' => 'MFG',
            'MFGC' => 'MFGC',
            'MFGG' => 'MFGG',
            'MFGM' => 'MFGM',
            'MFGT' => 'MFG',
            'MFGV' => 'MFGV',
            'MFK' => 'MFK',
            'MFKC' => 'MFKC',
            'MFKC1' => 'MFKC1',
            'MFKC3' => 'MFKC3',
            'MFKH' => 'MFKH',
            'MFKH3' => 'MFKH3',
            'MFN' => 'MFN',
            'MJ' => 'MJ',
            'MJA' => 'MJA',
            'MJAD' => 'MJAD',
            'MJC' => 'MJC',
            'MJCG' => 'MJCG',
            'MJCG1' => 'MJCG1',
            'MJCJ' => 'MJCJ',
            'MJCJ1' => 'MJCJ1',
            'MJCJ2' => 'MJCJ2',
            'MJCJ3' => 'MJCJ3',
            'MJCJ4' => 'MJCJ',
            'MJCL' => 'MJCL',
            'MJCL1' => 'MJCL1',
            'MJCL2' => 'MJCL2',
            'MJCM' => 'MJCM',
            'MJCM1' => 'MJCM1',
            'MJD' => 'MJD',
            'MJE' => 'MJE',
            'MJF' => 'MJF',
            'MJG' => 'MJG',
            'MJGD' => 'MJGD',
            'MJH' => 'MJH',
            'MJJ' => 'MJJ',
            'MJK' => 'MJK',
            'MJL' => 'MJL',
            'MJM' => 'MJM',
            'MJP' => 'MJP',
            'MJPD' => 'MJPD',
            'MJQ' => 'MJQ',
            'MJR' => 'MJR',
            'MJRD' => 'MJRD',
            'MJS' => 'MJS',
            'MK' => 'MM',
            'MKA' => 'MMB',
            'MKAL' => 'MMBP',
            'MKB' => 'MMC',
            'MKC' => 'MJT',
            'MKCM' => 'MJTF',
            'MKD' => 'MJW',
            'MKDN' => 'MJWN',
            'MKE' => 'MMD',
            'MKED' => 'MMD',
            'MKEH' => 'MMD',
            'MKEP' => 'MMDS',
            'MKF' => 'MMF',
            'MKFC' => 'MMFC',
            'MKFH' => 'MMFH',
            'MKFK' => 'MJC',
            'MKFM' => 'MMFM',
            'MKFM1' => 'MMFM',
            'MKFP' => 'MMFP',
            'MKFS' => 'MJC',
            'MKG' => 'MMG',
            'MKGT' => 'MMGT',
            'MKGW' => 'MMGW',
            'MKH' => 'MM',
            'MKHC' => 'MM',
            'MKJ' => 'MJN',
            'MKJA' => 'MJNA',
            'MKJD' => 'MJND',
            'MKL' => 'MMH',
            'MKLD' => 'MMH',
            'MKM' => 'MMJ',
            'MKMT' => 'MMJT',
            'MKMT1' => 'MMJT',
            'MKMT11' => 'MMZ',
            'MKMT2' => 'MMJT',
            'MKMT3' => 'MMJT',
            'MKMT4' => 'MMJT',
            'MKMT5' => 'MMJT',
            'MKMT6' => 'MMJT1',
            'MKN' => 'MJX',
            'MKP' => 'MMK',
            'MKPB' => 'MMKB',
            'MKPD' => 'MMKD',
            'MKPL' => 'MMKL',
            'MKR' => 'MMN',
            'MKS' => 'MMP',
            'MKSF' => 'MMPF',
            'MKSG' => 'MMPG',
            'MKSH' => 'MMPH',
            'MKSJ' => 'MMPJ',
            'MKT' => 'MMQ',
            'MKV' => 'MMR',
            'MKVB' => 'MMRB',
            'MKVD' => 'MMRD',
            'MKVP' => 'MMRP',
            'MKVQ' => 'MMR',
            'MKVT' => 'MMRT',
            'MKW' => 'MMS',
            'MKZ' => 'MMZ',
            'MKZD' => 'MMZD',
            'MKZF' => 'MMZF',
            'MKZL' => 'MMZL',
            'MKZP' => 'MMZ',
            'MKZR' => 'MMZR',
            'MKZS' => 'MMZS',
            'MKZV' => 'MJZ',
            'MN' => 'MN',
            'MNB' => 'MNB',
            'MNC' => 'MNC',
            'MND' => 'MNC',
            'MNG' => 'MNG',
            'MNH' => 'MNH',
            'MNJ' => 'MNJ',
            'MNK' => 'MNK',
            'MNL' => 'MNL',
            'MNN' => 'MNN',
            'MNP' => 'MNP',
            'MNPC' => 'MNPC',
            'MNQ' => 'MNQ',
            'MNS' => 'MNS',
            'MNZ' => 'MNZ',
            'MQ' => 'MQ',
            'MQC' => 'MQC',
            'MQCA' => 'MQCA',
            'MQCB' => 'MQCB',
            'MQCH' => 'MQCH',
            'MQCL' => 'MQCL',
            'MQCL1' => 'MQCL1',
            'MQCL2' => 'MQCL2',
            'MQCL3' => 'MQCL3',
            'MQCL4' => 'MQCL4',
            'MQCL5' => 'MQCL5',
            'MQCL6' => 'MQCL6',
            'MQCL7' => 'MQCL',
            'MQCL8' => 'MQCL',
            'MQCL9' => 'MQCL9',
            'MQCM' => 'MQCM',
            'MQCW' => 'MQCW',
            'MQCX' => 'MQCX',
            'MQCX1' => 'MQCX',
            'MQCZ' => 'MQCZ',
            'MQD' => 'MQD',
            'MQDB' => 'MQDB',
            'MQF' => 'MQF',
            'MQG' => 'MQ',
            'MQH' => 'MQH',
            'MQK' => 'MQK',
            'MQP' => 'MQP',
            'MQR' => 'MQR',
            'MQS' => 'MQS',
            'MQT' => 'MQT',
            'MQTC' => 'MQTC',
            'MQU' => 'MQU',
            'MQV' => 'MQV',
            'MQVB' => 'MQVB',
            'MQW' => 'MQW',
            'MQWB' => 'MQWB',
            'MQWP' => 'MQWP',
            'MQZ' => 'MQZ',
            'MR' => 'MR',
            'MRG' => 'MRG',
            'MRGD' => 'MRGD',
            'MRGK' => 'MRGK',
            'MRGL' => 'MRGL',
            'MRP' => 'MR',
            'MRT' => 'MRT',
            'MX' => 'MX',
            'MXH' => 'MXH',
            'MXN' => 'MX',
            'MZ' => 'MZ',
            'MZA' => 'MZ',
            'MZAB' => 'MZ',
            'MZAD' => 'MZ',
            'MZB' => 'MZ',
            'MZC' => 'MZC',
            'MZD' => 'MZD',
            'MZDH' => 'MZDH',
            'MZF' => 'MZF',
            'MZG' => 'MZG',
            'MZH' => 'MZH',
            'MZK' => 'MZK',
            'MZL' => 'MZL',
            'MZM' => 'MZM',
            'MZMP' => 'MZMP',
            'MZP' => 'MZP',
            'MZR' => 'MZR',
            'MZS' => 'MZS',
            'MZSN' => 'MZSN',
            'MZT' => 'MZT',
            'MZV' => 'MZV',
            'MZX' => 'MZX',
            'N' => 'HB',
            'NH' => 'HB',
            'NHA' => 'HBA',
            'NHAH' => 'HBAH',
            'NHAP' => 'HBA',
            'NHB' => 'HBG',
            'NHC' => 'HBLA',
            'NHD' => 'HBJD1',
            'NHDA' => 'HBJD1',
            'NHDC' => 'HBJD1',
            'NHDE' => 'HBJD1',
            'NHDG' => 'HBJD1',
            'NHDJ' => 'HBJD1',
            'NHDL' => 'HBJD1',
            'NHDN' => 'HBJD1',
            'NHF' => 'HBJF',
            'NHG' => 'HBJF1',
            'NHH' => 'HBJH',
            'NHHA' => 'HBJH',
            'NHK' => 'HBJK',
            'NHKA' => 'HBJK',
            'NHM' => 'HBJM',
            'NHQ' => 'HBJQ',
            'NHT' => 'HBT',
            'NHTB' => 'HBTB',
            'NHTB1' => 'HBTB',
            'NHTD' => 'HBTD',
            'NHTF' => 'HBTB',
            'NHTG' => 'HBTG',
            'NHTK' => 'HBTK',
            'NHTM' => 'HBTM',
            'NHTM1' => 'HBTM',
            'NHTP' => 'HBTP',
            'NHTP1' => 'HBTP1',
            'NHTQ' => 'HBTQ',
            'NHTR' => 'HBTR',
            'NHTR1' => 'HBTR',
            'NHTS' => 'HBTS',
            'NHTT' => 'HBT',
            'NHTV' => 'HBTV4',
            'NHTW' => 'HBTW',
            'NHTX' => 'HBT',
            'NHTZ' => 'HBTZ',
            'NHTZ1' => 'HBTZ1',
            'NHW' => 'HBW',
            'NHWA' => 'HBW',
            'NHWD' => 'HBW',
            'NHWF' => 'HBW',
            'NHWL' => 'HBW',
            'NHWR' => 'HBW',
            'NHWR1' => 'HBW',
            'NHWR3' => 'HBWP',
            'NHWR5' => 'HBWN',
            'NHWR7' => 'HBWQ',
            'NHWR9' => 'HBW',
            'NK' => 'HD',
            'NKA' => 'HDA',
            'NKD' => 'HDDA',
            'NKDS' => 'HDD',
            'NKL' => 'HDL',
            'NKP' => 'HDP',
            'NKR' => 'HDR',
            'NKT' => 'HDT',
            'NKV' => 'HDL',
            'NKX' => 'HDW',
            'P' => 'P',
            'PB' => 'PB',
            'PBB' => 'PBB',
            'PBC' => 'PBC',
            'PBCD' => 'PBCD',
            'PBCH' => 'PBCH',
            'PBCN' => 'PBCN',
            'PBD' => 'PBD',
            'PBF' => 'PBF',
            'PBG' => 'PBG',
            'PBH' => 'PBH',
            'PBJ' => 'PBJ',
            'PBK' => 'PBK',
            'PBKA' => 'PBKA',
            'PBKB' => 'PBKB',
            'PBKD' => 'PBKD',
            'PBKF' => 'PBKF',
            'PBKJ' => 'PBKJ',
            'PBKL' => 'PBKL',
            'PBKQ' => 'PBKQ',
            'PBKS' => 'PBKS',
            'PBM' => 'PBM',
            'PBMB' => 'PBMB',
            'PBMH' => 'PBMH',
            'PBML' => 'PBML',
            'PBMP' => 'PBMP',
            'PBMS' => 'PBMS',
            'PBMW' => 'PBMW',
            'PBMX' => 'PBMX',
            'PBP' => 'PBP',
            'PBPD' => 'PBPD',
            'PBPH' => 'PBPH',
            'PBT' => 'PBT',
            'PBTB' => 'PBTB',
            'PBU' => 'PBU',
            'PBUD' => 'PBUD',
            'PBUH' => 'PBUH',
            'PBV' => 'PBV',
            'PBW' => 'PBW',
            'PBWH' => 'PBWH',
            'PBWL' => 'PBWL',
            'PBWR' => 'PBWR',
            'PBWS' => 'PBWS',
            'PBWX' => 'PBWX',
            'PBX' => 'PBX',
            'PD' => 'PD',
            'PDA' => 'PDA',
            'PDC' => 'PDC',
            'PDD' => 'PDD',
            'PDE' => 'PDE',
            'PDG' => 'PDG',
            'PDJ' => 'PD',
            'PDK' => 'PDK',
            'PDM' => 'PD',
            'PDN' => 'PDN',
            'PDND' => 'PDND',
            'PDR' => 'PDR',
            'PDT' => 'PD',
            'PDX' => 'PDX',
            'PDZ' => 'PDZ',
            'PDZM' => 'PDZM',
            'PG' => 'PG',
            'PGC' => 'PGC',
            'PGG' => 'PGG',
            'PGK' => 'PGK',
            'PGM' => 'PGM',
            'PGS' => 'PGS',
            'PGT' => 'PGT',
            'PGZ' => 'PGZ',
            'PH' => 'PH',
            'PHD' => 'PHD',
            'PHDB' => 'PHDB',
            'PHDD' => 'PHDD',
            'PHDF' => 'PHDF',
            'PHDS' => 'PHDS',
            'PHDT' => 'PHDT',
            'PHDV' => 'PHDV',
            'PHDY' => 'PHDY',
            'PHF' => 'PHF',
            'PHFB' => 'PHFB',
            'PHFC' => 'PHFC',
            'PHFC1' => 'PHFC1',
            'PHFC2' => 'PHFC2',
            'PHFG' => 'PHFG',
            'PHFP' => 'PHFP',
            'PHH' => 'PHH',
            'PHJ' => 'PHJ',
            'PHJL' => 'PHJL',
            'PHK' => 'PHK',
            'PHM' => 'PHM',
            'PHN' => 'PHN',
            'PHP' => 'PHP',
            'PHQ' => 'PHQ',
            'PHR' => 'PHR',
            'PHS' => 'PHS',
            'PHU' => 'PHU',
            'PHV' => 'PHV',
            'PHVB' => 'PHVB',
            'PHVD' => 'PHVD',
            'PHVG' => 'PHVG',
            'PHVJ' => 'PHVJ',
            'PHVN' => 'PHVN',
            'PHVQ' => 'PHVQ',
            'PHVS' => 'PHVS',
            'PN' => 'PN',
            'PNA' => 'PN',
            'PNB' => 'PN',
            'PNC' => 'PN',
            'PND' => 'PN',
            'PNF' => 'PNF',
            'PNFC' => 'PNFC',
            'PNFR' => 'PNFR',
            'PNFS' => 'PNFS',
            'PNK' => 'PNK',
            'PNN' => 'PNN',
            'PNND' => 'PNND',
            'PNNP' => 'PNNP',
            'PNR' => 'PNR',
            'PNRA' => 'PNR',
            'PNRC' => 'PNRC',
            'PNRD' => 'PNRD',
            'PNRD1' => 'PNRD',
            'PNRE' => 'PN',
            'PNRH' => 'PNRH',
            'PNRL' => 'PNRL',
            'PNRP' => 'PNRP',
            'PNRR' => 'PNR',
            'PNRS' => 'PNRS',
            'PNRW' => 'PNRW',
            'PNRX' => 'PNRX',
            'PNT' => 'PNT',
            'PNV' => 'PNV',
            'PS' => 'PS',
            'PSA' => 'PSA',
            'PSAB' => 'PSAB',
            'PSAD' => 'PSAD',
            'PSAF' => 'PSAF',
            'PSAG' => 'PSAG',
            'PSAJ' => 'PSAJ',
            'PSAK' => 'PSAK',
            'PSAN' => 'PSAN',
            'PSAN1' => 'PSAN',
            'PSAN2' => 'PSAN',
            'PSAN3' => 'PSAN',
            'PSAN4' => 'PSAN',
            'PSAN5' => 'PSAN',
            'PSAX' => 'PS',
            'PSB' => 'PSB',
            'PSBD' => 'PSB',
            'PSC' => 'PSC',
            'PSD' => 'PSD',
            'PSE' => 'PS',
            'PSF' => 'PSF',
            'PSG' => 'PSG',
            'PSGN' => 'PSGN',
            'PSP' => 'PSP',
            'PSPA' => 'PSTV',
            'PSPF' => 'PSPF',
            'PSPM' => 'PSPM',
            'PSQ' => 'PSQ',
            'PST' => 'PST',
            'PSTB' => 'PST',
            'PSTH' => 'PST',
            'PSTJ' => 'PST',
            'PSTM' => 'PST',
            'PSV' => 'PSV',
            'PSVA' => 'PSVT',
            'PSVA2' => 'PSVT7',
            'PSVA4' => 'PSVT5',
            'PSVA6' => 'PSVT3',
            'PSVA8' => 'PSVT6',
            'PSVC' => 'PSVW1',
            'PSVF' => 'PSVW3',
            'PSVJ' => 'PSVW6',
            'PSVM' => 'PSVW7',
            'PSVM1' => 'PSVW71',
            'PSVM2' => 'PSVW73',
            'PSVM3' => 'PSVW79',
            'PSVP' => 'PSVP',
            'PSX' => 'PSX',
            'PSXE' => 'PSXE',
            'Q' => 'HP',
            'QD' => 'HP',
            'QDH' => 'HP',
            'QDHA' => 'HPCA',
            'QDHC' => 'HPDF',
            'QDHC2' => 'HPDF',
            'QDHF' => 'HPCB',
            'QDHH' => 'HP',
            'QDHK' => 'HPDC',
            'QDHL' => 'HP',
            'QDHM' => 'HPCD1',
            'QDHP' => 'HP',
            'QDHR' => 'HP',
            'QDHR1' => 'HPCD',
            'QDHR3' => 'HPCF',
            'QDHR5' => 'HPCF3',
            'QDHR7' => 'HPCF7',
            'QDHR9' => 'HPCF5',
            'QDT' => 'HP',
            'QDTJ' => 'HPJ',
            'QDTK' => 'HPK',
            'QDTL' => 'HPL',
            'QDTM' => 'HPM',
            'QDTN' => 'HPN',
            'QDTQ' => 'HPQ',
            'QDTS' => 'HPS',
            'QDTS1' => 'HPS',
            'QDX' => 'HPX',
            'QDXB' => 'HPX',
            'QR' => 'HR',
            'QRA' => 'HRA',
            'QRAB' => 'HRAB',
            'QRAB1' => 'HRAB1',
            'QRAB7' => 'HRAB',
            'QRAB9' => 'HRAB',
            'QRAC' => 'HRAC',
            'QRAF' => 'HRAF',
            'QRAM' => 'HRAM',
            'QRAM1' => 'HRAM1',
            'QRAM2' => 'HRAM2',
            'QRAM3' => 'HRAM3',
            'QRAM6' => 'HRAM6',
            'QRAM7' => 'HRAM7 ',
            'QRAM9' => 'HRAM9',
            'QRAX' => 'HRAX',
            'QRD' => 'HRG',
            'QRDB' => 'HRG',
            'QRDF' => 'HRGS',
            'QRDF1' => 'HRGS',
            'QRDF2' => 'HRGS',
            'QRDP' => 'HRGP',
            'QRF' => 'HRE',
            'QRFB' => 'HRE',
            'QRFB1' => 'HRE',
            'QRFB2' => 'HRE',
            'QRFB21' => 'HREX',
            'QRFB23' => 'HREZ',
            'QRFF' => 'HRES',
            'QRFP' => 'HREP',
            'QRJ' => 'HRJ',
            'QRJB' => 'HRJ',
            'QRJB1' => 'HRJ',
            'QRJB2' => 'HRJ',
            'QRJB3' => 'HRJ',
            'QRJF' => 'HRJS',
            'QRJF1' => 'HRJS',
            'QRJF5' => 'HRJS',
            'QRJP' => 'HRJP',
            'QRM' => 'HRC',
            'QRMB' => 'HRCC',
            'QRMB1' => 'HRCC7',
            'QRMB2' => 'HRCC8',
            'QRMB3' => 'HRCC9',
            'QRMB31' => 'HRCC91',
            'QRMB32' => 'HRCC92',
            'QRMB33' => 'HRCC93',
            'QRMB34' => 'HRCC9',
            'QRMB35' => 'HRCC95',
            'QRMB36' => 'HRCC96',
            'QRMB37' => 'HRCC97',
            'QRMB38' => 'HRCC9',
            'QRMB39' => 'HRCC99',
            'QRMB5' => 'HRCC',
            'QRMB8' => 'HRCZ',
            'QRMB9' => 'HRCJ',
            'QRMF' => 'HRC',
            'QRMF1' => 'HRCF',
            'QRMF12' => 'HRCF1',
            'QRMF13' => 'HRCF2',
            'QRMF14' => 'HRCF',
            'QRMF19' => 'HRCG9',
            'QRMF3' => 'HRC',
            'QRMP' => 'HRCV',
            'QRMP1' => 'HRCV1',
            'QRP' => 'HRH',
            'QRPB' => 'HRH',
            'QRPB1' => 'HRH',
            'QRPB2' => 'HRH',
            'QRPB3' => 'HRH',
            'QRPB4' => 'HRHX',
            'QRPF' => 'HRH',
            'QRPF1' => 'HRHS',
            'QRPF2' => 'HRH',
            'QRPP' => 'HRHP',
            'QRR' => 'HRK',
            'QRRB' => 'HRKB',
            'QRRC' => 'HRKJ',
            'QRRD' => 'HRKS',
            'QRRF' => 'HRKZ',
            'QRRL' => 'HRKN',
            'QRRL1' => 'HRKN1',
            'QRRL3' => 'HRKN3',
            'QRRL5' => 'HRKN5',
            'QRRL6' => 'HRKN',
            'QRRM' => 'HRK',
            'QRRN' => 'HRKT',
            'QRRT' => 'HRKT',
            'QRRT1' => 'HRKP',
            'QRRV' => 'HRK',
            'QRS' => 'HRKP',
            'QRSA' => 'HRKP1',
            'QRSG' => 'HRKP3',
            'QRSL' => 'HRKP4',
            'QRST' => 'HRKP2',
            'QRSV' => 'HRKP',
            'QRSW' => 'HRKP5',
            'QRV' => 'HRL',
            'QRVA' => 'HRLC',
            'QRVA2' => 'HRLC',
            'QRVB' => 'HRL',
            'QRVC' => 'HRCG ',
            'QRVD' => 'HRL',
            'QRVG' => 'HRHT',
            'QRVG2' => 'HRCM',
            'QRVG3' => 'HRCM',
            'QRVH' => 'HRCP',
            'QRVJ' => 'HRCL',
            'QRVJ1' => 'HRHC',
            'QRVJ2' => 'HRCL1',
            'QRVJ3' => 'HRLD',
            'QRVK' => 'HRCS',
            'QRVK2' => 'HRHX',
            'QRVK4' => 'HRHX',
            'QRVL' => 'HRL',
            'QRVP' => 'HRCV',
            'QRVP1' => 'HRHC',
            'QRVP2' => 'HRGC',
            'QRVP3' => 'HRCV2',
            'QRVP4' => 'HRHP',
            'QRVP5' => 'HRCV3',
            'QRVP7' => 'HRCV4',
            'QRVQ' => 'HRL',
            'QRVS' => 'HRCX',
            'QRVS1' => 'HRCX1',
            'QRVS2' => 'HRCX6',
            'QRVS3' => 'HRCX4',
            'QRVS4' => 'HRCX7',
            'QRVS5' => 'HRCX8',
            'QRVX' => 'HRCV9',
            'QRY' => 'HRQ',
            'QRYA' => 'HRQA',
            'QRYA5' => 'HRQA5',
            'QRYC' => 'HRQC',
            'QRYC1' => 'HRQC1',
            'QRYC5' => 'HRQC5',
            'QRYM' => 'HRQM',
            'QRYM2' => 'HRQM2',
            'QRYX' => 'HRQX',
            'QRYX2' => 'HRQX2',
            'QRYX5' => 'HRQX5',
            'QRYX9' => 'HRQX9',
            'R' => 'R',
            'RB' => 'RB',
            'RBC' => 'RBC',
            'RBG' => 'RBG',
            'RBGB' => 'RBGB',
            'RBGD' => 'RBGD',
            'RBGF' => 'RBGF',
            'RBGG' => 'RBGG',
            'RBGH' => 'RBGH',
            'RBGK' => 'RBGK',
            'RBGL' => 'RBGL',
            'RBK' => 'RBK',
            'RBKC' => 'RBKC',
            'RBKF' => 'RBKF',
            'RBP' => 'RBP',
            'RBPC' => 'RBP',
            'RBPM' => 'RBP',
            'RBX' => 'RBX',
            'RG' => 'RG',
            'RGB' => 'RGB',
            'RGBA' => 'RGBA',
            'RGBC' => 'RGBC',
            'RGBC1' => 'RGBC',
            'RGBC2' => 'RGBC',
            'RGBD' => 'RGBC',
            'RGBF' => 'RGBF',
            'RGBG' => 'RBKF',
            'RGBL' => 'RGBL',
            'RGBL1' => 'RGBL',
            'RGBL2' => 'RGBL',
            'RGBL3' => 'RGBL',
            'RGBL4' => 'RGBL',
            'RGBP' => 'RGBP',
            'RGBP1' => 'RGBP',
            'RGBQ' => 'RGB',
            'RGBR' => 'RGBR',
            'RGBS' => 'RGBS',
            'RGBU' => 'RGB',
            'RGC' => 'RGC',
            'RGCD' => 'RGC',
            'RGCG' => 'RGC',
            'RGCM' => 'RGCM',
            'RGCP' => 'RGCP',
            'RGCS' => 'RGC',
            'RGCT' => 'RGC',
            'RGCU' => 'RGC',
            'RGL' => 'RGL',
            'RGM' => 'RGM',
            'RGR' => 'RGR',
            'RGV' => 'RGV',
            'RGW' => 'RGW',
            'RGX' => 'GBG',
            'RGXB' => 'GBGM',
            'RGXH' => 'RGS',
            'RGXM' => 'RGS',
            'RGXP' => 'GBGP',
            'RN' => 'RN',
            'RNA' => 'RNA',
            'RNB' => 'RNB',
            'RNC' => 'RNC',
            'RNCB' => 'RNCB',
            'RND' => 'RND',
            'RNF' => 'RNF',
            'RNFD' => 'RNFD',
            'RNFF' => 'RNFF',
            'RNFY' => 'RNFY',
            'RNH' => 'RNH',
            'RNK' => 'RNK',
            'RNKH' => 'RNKH',
            'RNKH1' => 'RNKH1',
            'RNKH2' => 'RNKH',
            'RNP' => 'RNP',
            'RNPD' => 'RNPD',
            'RNPG' => 'RNPG',
            'RNQ' => 'RNQ',
            'RNR' => 'RNR',
            'RNT' => 'RNT',
            'RNU' => 'RNU',
            'RP' => 'RP',
            'RPC' => 'RPC',
            'RPG' => 'RPG',
            'RPT' => 'RPT',
            'S' => 'WS',
            'SC' => 'WS',
            'SCB' => 'WSB',
            'SCBB' => 'WSBB',
            'SCBC' => 'WSPC1',
            'SCBG' => 'WSBG',
            'SCBM' => 'WSBM',
            'SCBT' => 'WSBT',
            'SCBV' => 'WSBV',
            'SCG' => 'WSD',
            'SCGF' => 'WSDF',
            'SCGP' => 'WSDP',
            'SCK' => 'WSDX',
            'SCL' => 'WSC',
            'SCX' => 'WSBX',
            'SF' => 'WSJ',
            'SFB' => 'WSJ',
            'SFBC' => 'WSJA',
            'SFBD' => 'WSJS',
            'SFBF' => 'WSJQ',
            'SFBH' => 'WSJ',
            'SFBK' => 'WSJL',
            'SFBT' => 'WSJF1',
            'SFBV' => 'WSJF2',
            'SFC' => 'WSJT',
            'SFD' => 'WSJC',
            'SFG' => 'WSJH',
            'SFH' => 'WSJG',
            'SFJ' => 'WSJH',
            'SFK' => 'WSJJ',
            'SFL' => 'WSJK',
            'SFM' => 'WSJM',
            'SFN' => 'WSJN',
            'SFP' => 'WSJV',
            'SFQ' => 'WSJ',
            'SFT' => 'WSJR',
            'SFTA' => 'WSJR2',
            'SFTB' => 'WSJR3',
            'SFTC' => 'WSJR4',
            'SFTD' => 'WSJR5',
            'SFV' => 'WSJY',
            'SFX' => 'WSJZ',
            'SH' => 'WSK',
            'SHB' => 'WSK',
            'SHBF' => 'WSKC',
            'SHBM' => 'WSKQ',
            'SHG' => 'WSL',
            'SHP' => 'WSM',
            'SK' => 'WSN',
            'SKG' => 'WSNB',
            'SKL' => 'WSNF',
            'SKR' => 'WSNP',
            'SM' => 'WS',
            'SMC' => 'WSF',
            'SMF' => 'WSP',
            'SMFA' => 'WSPC',
            'SMFC' => 'WSPG',
            'SMFF' => 'WSPC',
            'SMFK' => 'WSPM',
            'SMQ' => 'WSQ',
            'SMQB' => 'WSQ',
            'SMX' => 'WSR',
            'SP' => 'WSS',
            'SPC' => 'WSSC',
            'SPCA' => 'WSSC1',
            'SPCA1' => 'WSSC1',
            'SPCA2' => 'WSSC1',
            'SPCD' => 'WSSC',
            'SPCS' => 'WSSC',
            'SPG' => 'WSSG',
            'SPN' => 'WSSN',
            'SPND' => 'WSSN1',
            'SPNG' => 'WSSN3',
            'SPNK' => 'WSSN5',
            'SPNL' => 'WSSN7',
            'SR' => 'WST',
            'SRB' => 'WSTB',
            'SRC' => 'WSTC',
            'SRF' => 'WSTF',
            'SRM' => 'WSTM',
            'SRMA' => 'WSTM',
            'SRMC' => 'WST',
            'SRMJ' => 'WSTM',
            'SRMK' => 'WSTM',
            'SRML' => 'WSTM',
            'SRMM' => 'WSTM',
            'SRMN' => 'WSTM',
            'SRMN1' => 'WSTM',
            'SRMN2' => 'WSTM',
            'SRMS' => 'WSTM',
            'SRMT' => 'WSTM',
            'SRMV' => 'WST',
            'ST' => 'WSW',
            'STA' => 'WSWK',
            'STAA' => 'WSWK',
            'STAB' => 'WSWK',
            'STAN' => 'WSWK',
            'STAN1' => 'WSWK',
            'STAN2' => 'WSWK',
            'STAT' => 'WSWK',
            'STC' => 'WSWM',
            'STG' => 'WSWS',
            'STH' => 'WSWS',
            'STJ' => 'WSWS',
            'STK' => 'WSWY',
            'STL' => 'WSW',
            'STLN' => 'WSW',
            'STP' => 'WSW',
            'STS' => 'WSW',
            'SV' => 'WSX',
            'SVB' => 'WSX',
            'SVF' => 'WSXF',
            'SVFF' => 'WSXF',
            'SVFS' => 'WSXF',
            'SVH' => 'WSXH',
            'SVHH' => 'WSXH',
            'SVR' => 'WSXR',
            'SVS' => 'WSXS',
            'SVT' => 'WSXT',
            'SX' => 'WS',
            'SXB' => 'WSU',
            'SXD' => 'WS',
            'SXE' => 'WS',
            'SXQ' => 'WSE',
            'SZ' => 'WSZ',
            'SZC' => 'WSZC',
            'SZD' => 'WSQ',
            'SZE' => 'WSZ',
            'SZG' => 'WSZG',
            'SZK' => 'WSZK',
            'SZN' => 'WSZN',
            'SZR' => 'WSZR',
            'SZV' => 'WSZV',
            'T' => 'T',
            'TB' => 'TB',
            'TBC' => 'TBC',
            'TBD' => 'TBD',
            'TBDG' => 'TBDG',
            'TBG' => 'TBG',
            'TBJ' => 'TBJ',
            'TBM' => 'TBM',
            'TBMM' => 'TBMM',
            'TBN' => 'TBN',
            'TBR' => 'TBR',
            'TBX' => 'TBX',
            'TBY' => 'TBY',
            'TC' => 'TC',
            'TCB' => 'TCB',
            'TCBG' => 'TCBG',
            'TCBS' => 'TCBS',
            'TD' => 'TD',
            'TDC' => 'TDC',
            'TDCA' => 'TDC',
            'TDCF' => 'TDC',
            'TDCJ' => 'TDCJ',
            'TDCP' => 'TDCP',
            'TDCQ' => 'TDCQ',
            'TDCT' => 'TDCT',
            'TDCT1' => 'TDCT',
            'TDCT2' => 'TDCT',
            'TDCW' => 'TDCW',
            'TDCX' => 'TD',
            'TDP' => 'TDP',
            'TDPF' => 'TDH',
            'TDPF1' => 'TDH',
            'TDPJ' => 'TDJ',
            'TDPJ1' => 'TDJP',
            'TDPM' => 'TDM',
            'TDPP' => 'TDPP',
            'TDPT' => 'TDPP',
            'TG' => 'TG',
            'TGB' => 'TGB',
            'TGBF' => 'TGBF',
            'TGBN' => 'TGBN',
            'TGH' => 'TG',
            'TGM' => 'TGM',
            'TGMB' => 'TGMB',
            'TGMD' => 'TGMD',
            'TGMF' => 'TGMF',
            'TGMF1' => 'TGMF1',
            'TGMF2' => 'TGMF2',
            'TGML' => 'TGB',
            'TGMM' => 'TGB',
            'TGMP' => 'TGB',
            'TGMS' => 'TGB',
            'TGMT' => 'TGMT',
            'TGP' => 'TGP',
            'TGPC' => 'TGPC',
            'TGPQ' => 'TGPQ',
            'TGPR' => 'TGPR',
            'TGX' => 'TGX',
            'TH' => 'TH',
            'THD' => 'TH',
            'THF' => 'THF',
            'THFG' => 'THFG',
            'THFP' => 'THFP',
            'THFS' => 'THFS',
            'THK' => 'THK',
            'THN' => 'THN',
            'THR' => 'THR',
            'THRM' => 'THRM',
            'THRX' => 'THRS',
            'THV' => 'THX',
            'THVB' => 'THX',
            'THVG' => 'THX',
            'THVH' => 'THX',
            'THVS' => 'THX',
            'THVW' => 'THX',
            'THY' => 'THRB',
            'THYB' => 'THRH',
            'THYC' => 'THT',
            'TJ' => 'TJ',
            'TJD' => 'TJ',
            'TJF' => 'TJF',
            'TJFC' => 'TJFC',
            'TJFD' => 'TJFD',
            'TJFM' => 'TJFM',
            'TJFM1' => 'TJFM1',
            'TJFN' => 'TJFN',
            'TJK' => 'TJK',
            'TJKD' => 'TJKD',
            'TJKH' => 'TJK',
            'TJKR' => 'TJKR',
            'TJKS' => 'TJKS',
            'TJKT' => 'TJKT',
            'TJKT1' => 'TJKT1',
            'TJKV' => 'TJKV',
            'TJKW' => 'TJKW',
            'TJS' => 'TJFD',
            'TN' => 'TN',
            'TNC' => 'TNC',
            'TNCB' => 'TNCB',
            'TNCC' => 'TNCC',
            'TNCE' => 'TNCE',
            'TNCJ' => 'TNCJ',
            'TNF' => 'TNF',
            'TNFD' => 'TNF',
            'TNFL' => 'TNFL',
            'TNH' => 'TNH',
            'TNK' => 'TNK',
            'TNKA' => 'TNK',
            'TNKD' => 'TNK',
            'TNKE' => 'TNK',
            'TNKF' => 'TNKF',
            'TNKH' => 'TNKH',
            'TNKP' => 'TNK',
            'TNKR' => 'TNK',
            'TNKS' => 'TNKS',
            'TNKX' => 'TNKX',
            'TNT' => 'TNT',
            'TNTB' => 'TNTB',
            'TNTC' => 'TNTC',
            'TNTP' => 'TNTP',
            'TNTR' => 'TNTR',
            'TQ' => 'TQ',
            'TQD' => 'TQD',
            'TQK' => 'TQK',
            'TQP' => 'TQ',
            'TQS' => 'TQS',
            'TQSR' => 'TQSR',
            'TQSW' => 'TQSW',
            'TR' => 'TR',
            'TRC' => 'TRC',
            'TRCS' => 'TRCS',
            'TRCT' => 'TRCT',
            'TRF' => 'TRF',
            'TRFT' => 'TRFT',
            'TRL' => 'TRL',
            'TRLD' => 'TRLD',
            'TRLN' => 'TRLN',
            'TRLT' => 'TRLT',
            'TRP' => 'TRP',
            'TRPS' => 'TRPS',
            'TRT' => 'TRT',
            'TRV' => 'TR',
            'TT' => 'TT',
            'TTA' => 'TTA',
            'TTB' => 'TTB',
            'TTBF' => 'TTBF',
            'TTBL' => 'TTBL',
            'TTBM' => 'TTBM',
            'TTBS' => 'TTBS',
            'TTD' => 'TTD',
            'TTDS' => 'TTDS',
            'TTDX' => 'TTD',
            'TTG' => 'TT',
            'TTM' => 'TTM',
            'TTMW' => 'TTMW',
            'TTP' => 'TTP',
            'TTS' => 'TTS',
            'TTU' => 'TTU',
            'TTV' => 'TTV',
            'TTVC' => 'TTVC',
            'TTVC2' => 'TTVC',
            'TTVH' => 'TTVH',
            'TTVR' => 'TTVR',
            'TTVS' => 'TTV',
            'TTVT' => 'TTV',
            'TTW' => 'TT',
            'TTX' => 'TTX',
            'TV' => 'TV',
            'TVB' => 'TVB',
            'TVBP' => 'TVB',
            'TVBT' => 'TVB',
            'TVD' => 'TVD',
            'TVDR' => 'TVDR',
            'TVF' => 'TVF',
            'TVG' => 'TVG',
            'TVH' => 'TVH',
            'TVHB' => 'TVHB',
            'TVHE' => 'TVH',
            'TVHF' => 'TVHF',
            'TVHH' => 'TVHH',
            'TVHP' => 'TVHP',
            'TVK' => 'TVK',
            'TVM' => 'TVM',
            'TVP' => 'TVP',
            'TVQ' => 'TVQ',
            'TVR' => 'TVR',
            'TVS' => 'TVS',
            'TVSH' => 'TVS',
            'TVSW' => 'TVSW',
            'TVT' => 'TVT',
            'TVU' => 'TV',
            'U' => 'U',
            'UB' => 'UB',
            'UBB' => 'UB',
            'UBH' => 'UBH',
            'UBJ' => 'UBJ',
            'UBL' => 'UBL',
            'UBM' => 'UB',
            'UBW' => 'UBW',
            'UD' => 'UD',
            'UDA' => 'UDA',
            'UDB' => 'UDB',
            'UDBA' => 'UDBA',
            'UDBD' => 'UDBD',
            'UDBG' => 'UDBG',
            'UDBM' => 'UDBM',
            'UDBR' => 'UDBR',
            'UDBS' => 'UDBS',
            'UDBV' => 'UDBV',
            'UDD' => 'UD',
            'UDF' => 'UDF',
            'UDH' => 'UDH',
            'UDM' => 'UDM',
            'UDP' => 'UDP',
            'UDQ' => 'UDQ',
            'UDT' => 'UDT',
            'UDV' => 'UDV',
            'UDX' => 'UDX',
            'UDY' => 'UDB',
            'UF' => 'UF',
            'UFB' => 'UFB',
            'UFC' => 'UFC',
            'UFD' => 'UFD',
            'UFG' => 'UFG',
            'UFK' => 'UFK',
            'UFL' => 'UFL',
            'UFLS' => 'UFLS',
            'UFM' => 'UFM',
            'UFP' => 'UFP',
            'UFS' => 'UFS',
            'UG' => 'UG',
            'UGA' => 'UGB',
            'UGB' => 'UGB',
            'UGC' => 'UGC',
            'UGD' => 'UGD',
            'UGG' => 'UGG ',
            'UGK' => 'UGK',
            'UGL' => 'UGL',
            'UGM' => 'UGM',
            'UGN' => 'UGN',
            'UGP' => 'UGP',
            'UGV' => 'UGV',
            'UK' => 'UK',
            'UKC' => 'UKC',
            'UKD' => 'UKD',
            'UKF' => 'UKF',
            'UKG' => 'UKG',
            'UKL' => 'UK',
            'UKM' => 'UKM',
            'UKN' => 'UKN',
            'UKP' => 'UKP',
            'UKPC' => 'UKPC',
            'UKPM' => 'UKPM',
            'UKR' => 'UKR',
            'UKS' => 'UKS',
            'UKX' => 'UKX',
            'UL' => 'UL',
            'ULD' => 'ULD',
            'ULH' => 'ULH',
            'ULJ' => 'UL',
            'ULJL' => 'ULL',
            'ULP' => 'ULP',
            'ULQ' => 'ULQ',
            'ULR' => 'ULR',
            'UM' => 'UM',
            'UMA' => 'UMA',
            'UMB' => 'UMB',
            'UMC' => 'UMC',
            'UMF' => 'UMF',
            'UMG' => 'UMG',
            'UMH' => 'UMH',
            'UMJ' => 'UMJ',
            'UMK' => 'UMK',
            'UMKB' => 'UMKB',
            'UMKC' => 'UMKC',
            'UMKL' => 'UMKL',
            'UML' => 'UML',
            'UMN' => 'UMN',
            'UMP' => 'UMP',
            'UMPN' => 'UMPN',
            'UMPW' => 'UMPW',
            'UMQ' => 'UMQ',
            'UMR' => 'UMR',
            'UMS' => 'UMS',
            'UMT' => 'UMT',
            'UMW' => 'UMW',
            'UMWS' => 'UMWS',
            'UMX' => 'UMX',
            'UMZ' => 'UMZ',
            'UMZL' => 'UMZL',
            'UMZT' => 'UMZT',
            'UMZW' => 'UMZW',
            'UN' => 'UN',
            'UNA' => 'UNA',
            'UNAN' => 'UNA',
            'UNAR' => 'UNAR',
            'UNC' => 'UNC',
            'UND' => 'UND',
            'UNF' => 'UNF',
            'UNH' => 'UNH',
            'UNJ' => 'UNJ',
            'UNK' => 'UNK',
            'UNKD' => 'UNK',
            'UNKP' => 'UNK',
            'UNN' => 'UNN',
            'UNS' => 'UNS',
            'UP' => 'UB',
            'UQ' => 'UQ',
            'UQF' => 'UQF',
            'UQJ' => 'UQJ',
            'UQL' => 'UQL',
            'UQR' => 'UQR',
            'UQT' => 'UQT',
            'UR' => 'UR',
            'URD' => 'URD',
            'URH' => 'URH',
            'URJ' => 'URJ',
            'URQ' => 'URQ',
            'URS' => 'URS',
            'URW' => 'URW',
            'URY' => 'URY',
            'UT' => 'UT',
            'UTC' => 'UTC',
            'UTD' => 'UTD',
            'UTE' => 'UT',
            'UTF' => 'UTF',
            'UTFB' => 'UTFB',
            'UTG' => 'UTG',
            'UTM' => 'UTM',
            'UTN' => 'UTN',
            'UTP' => 'UTP',
            'UTR' => 'UTR',
            'UTS' => 'UTS',
            'UTV' => 'UTV',
            'UTW' => 'UTW',
            'UTX' => 'UTX',
            'UX' => 'UB',
            'UXA' => 'UB',
            'UXJ' => 'UB',
            'UXT' => 'UB',
            'UY' => 'UY',
            'UYA' => 'UYA',
            'UYAM' => 'UYAM',
            'UYD' => 'UYD',
            'UYF' => 'UYF',
            'UYFL' => 'UYFL',
            'UYFP' => 'UYFP',
            'UYM' => 'UYM',
            'UYQ' => 'UYQ',
            'UYQD' => 'UYQ',
            'UYQE' => 'UYQE',
            'UYQF' => 'UYQ',
            'UYQL' => 'UYQL',
            'UYQM' => 'UYQM',
            'UYQN' => 'UYQN',
            'UYQP' => 'UYQP',
            'UYQS' => 'UYQS',
            'UYQV' => 'UYQV',
            'UYS' => 'UYS',
            'UYT' => 'UYT',
            'UYU' => 'UYU',
            'UYV' => 'UYV',
            'UYW' => 'UY',
            'UYX' => 'UY',
            'UYY' => 'UY',
            'UYZ' => 'UYZ',
            'UYZF' => 'UYZF',
            'UYZG' => 'UYZG',
            'UYZM' => 'UYZM',
            'V' => 'V',
            'VF' => 'VF',
            'VFB' => 'VFB',
            'VFD' => 'VFD',
            'VFDB' => 'VFD',
            'VFDB1' => 'VFD',
            'VFDB2' => 'VFD',
            'VFDF' => 'VFDF',
            'VFDJ' => 'VFD',
            'VFDM' => 'VFDM',
            'VFDM1' => 'VFDM',
            'VFDW' => 'VFDW',
            'VFDW1' => 'VFDW',
            'VFDW2' => 'VFDW',
            'VFG' => 'VFG',
            'VFJ' => 'VFJ',
            'VFJB' => 'VFJB',
            'VFJB1' => 'VFJB',
            'VFJB2' => 'VFJB',
            'VFJB3' => 'VFJB',
            'VFJB31' => 'VFJB',
            'VFJB4' => 'VFJB',
            'VFJB5' => 'VFJB',
            'VFJB6' => 'VFJB',
            'VFJB7' => 'VFJB',
            'VFJB9' => 'VFJB',
            'VFJD' => 'VFJD',
            'VFJG' => 'VFJG',
            'VFJH' => 'VFJ',
            'VFJJ' => 'VFJJ',
            'VFJK' => 'VFJK',
            'VFJL' => 'VFJB',
            'VFJM' => 'VFJ',
            'VFJN' => 'VFJ',
            'VFJP' => 'VFJP',
            'VFJQ' => 'VFJB',
            'VFJQ1' => 'VFJB',
            'VFJQ2' => 'VFJ',
            'VFJQ3' => 'VFJB',
            'VFJR' => 'VFJB',
            'VFJR1' => 'VFJB',
            'VFJR2' => 'VFJB',
            'VFJR3' => 'VFJB',
            'VFJR4' => 'VFJB',
            'VFJS' => 'VFJS',
            'VFJT' => 'VFJ',
            'VFJV' => 'VFJB',
            'VFJX' => 'VFJX',
            'VFJX1' => 'VFJX',
            'VFL' => 'VFL',
            'VFM' => 'VFM',
            'VFMD' => 'VFMD',
            'VFMG' => 'VFMG',
            'VFMG1' => 'VFMG',
            'VFMG2' => 'VFMG',
            'VFMS' => 'VFMS',
            'VFV' => 'VFV',
            'VFVC' => 'VFVC',
            'VFVG' => 'VFVG',
            'VFVG2' => 'VFVG',
            'VFVJ' => 'VFV',
            'VFVK' => 'VFVK',
            'VFVM' => 'VFV',
            'VFVN' => 'VFV',
            'VFVS' => 'VFVS',
            'VFVX' => 'VFVX',
            'VFX' => 'VFX',
            'VFXB' => 'VFXB',
            'VFXB1' => 'VFXB1',
            'VFXC' => 'VFXC',
            'VFXC1' => 'VFXC1',
            'VS' => 'VS',
            'VSA' => 'VS',
            'VSB' => 'VSB',
            'VSC' => 'VSC',
            'VSCB' => 'VSC',
            'VSD' => 'VSD',
            'VSF' => 'VSF',
            'VSG' => 'VSG',
            'VSH' => 'VSH',
            'VSK' => 'VSK',
            'VSKB' => 'VSK',
            'VSL' => 'VSL',
            'VSN' => 'VSN',
            'VSP' => 'VSP',
            'VSPD' => 'VSP',
            'VSPM' => 'VSPM',
            'VSPP' => 'VSP',
            'VSPQ' => 'VSP',
            'VSPT' => 'VSPT',
            'VSPX' => 'VSPX',
            'VSR' => 'VSR',
            'VSS' => 'VS',
            'VSW' => 'VSW',
            'VSY' => 'VS',
            'VSZ' => 'VSZ',
            'VSZD' => 'VSZ',
            'VSZM' => 'VSZ',
            'VX' => 'VX',
            'VXA' => 'VXA',
            'VXF' => 'VXF',
            'VXFA' => 'VXFA',
            'VXFA1' => 'VXFA1',
            'VXFC' => 'VXFC',
            'VXFC1' => 'VXFC1',
            'VXFD' => 'VXFD',
            'VXFG' => 'VXFG',
            'VXFJ' => 'VXFJ',
            'VXFJ1' => 'VXFJ',
            'VXFJ2' => 'VXFJ',
            'VXFN' => 'VXFN',
            'VXFT' => 'VXFT',
            'VXH' => 'VXH',
            'VXHA' => 'VXHT1',
            'VXHC' => 'VXHC',
            'VXHF' => 'VXH',
            'VXHH' => 'VXHH',
            'VXHJ' => 'VXHJ',
            'VXHK' => 'VXHK',
            'VXHN' => 'VXH',
            'VXHP' => 'VXH',
            'VXHT' => 'VXHT',
            'VXHT2' => 'VXHT2',
            'VXHT4' => 'VXHT',
            'VXHV' => 'VXH',
            'VXK' => 'VXA',
            'VXM' => 'VXM',
            'VXN' => 'VXN',
            'VXP' => 'VXP',
            'VXPC' => 'VXPC',
            'VXPH' => 'VXPH',
            'VXPJ' => 'VXPJ',
            'VXPR' => 'VXPR',
            'VXPS' => 'VXPS',
            'VXQ' => 'VXQ',
            'VXQB' => 'VXQB',
            'VXQG' => 'VXQG',
            'VXQM' => 'VXQM',
            'VXQM1' => 'VXQM',
            'VXQM2' => 'VXQM',
            'VXQM3' => 'VXQM',
            'VXQM4' => 'VXQM',
            'VXQM5' => 'VXQM',
            'VXQM6' => 'VXQM',
            'VXV' => 'VXV',
            'VXW' => 'VXW',
            'VXWK' => 'VXWK',
            'VXWM' => 'VXWM',
            'VXWS' => 'VXWS',
            'VXWT' => 'VXWT',
            'W' => 'W',
            'WB' => 'WB',
            'WBA' => 'WBA',
            'WBAC' => 'WBA',
            'WBB' => 'WBB',
            'WBC' => 'WBC',
            'WBD' => 'WBD',
            'WBF' => 'WBF',
            'WBH' => 'WBH',
            'WBHS' => 'WBHS',
            'WBHS1' => 'WBHS',
            'WBHS2' => 'WBHS',
            'WBHS3' => 'WBHS',
            'WBHS4' => 'WBHS',
            'WBHS5' => 'WBHS',
            'WBHS6' => 'WBHS',
            'WBJ' => 'WBJ',
            'WBJK' => 'WBJ',
            'WBK' => 'WBH',
            'WBL' => 'WB',
            'WBN' => 'WBN',
            'WBNB' => 'WBN',
            'WBQ' => 'WBQ',
            'WBR' => 'WBR',
            'WBS' => 'WBS',
            'WBSB' => 'WBS',
            'WBSC' => 'WBS',
            'WBSD' => 'WBS',
            'WBT' => 'WBT',
            'WBTB' => 'WBTB',
            'WBTC' => 'WBTC',
            'WBTF' => 'WBTF',
            'WBTH' => 'WBTH',
            'WBTJ' => 'WBT',
            'WBTM' => 'WBT',
            'WBTP' => 'WBTP',
            'WBTR' => 'WBTR',
            'WBTX' => 'WBTX',
            'WBV' => 'WBV',
            'WBVA' => 'WBV',
            'WBVD' => 'WBVD',
            'WBVG' => 'WBVG',
            'WBVH' => 'WBV',
            'WBVM' => 'WBVM',
            'WBVQ' => 'WBVQ',
            'WBVR' => 'WBV',
            'WBVS' => 'WBVS',
            'WBVS1' => 'WBVS',
            'WBVS2' => 'WBVS',
            'WBVS21' => 'WBV',
            'WBW' => 'WBW',
            'WBX' => 'WBX',
            'WBXD' => 'WBXD',
            'WBXD1' => 'WBXD1',
            'WBXD2' => 'WBXD2',
            'WBXD3' => 'WBXD3',
            'WBXN' => 'WBXN',
            'WBXN1' => 'WBXN',
            'WBXN12' => 'WBXN',
            'WBXN3' => 'WBXN',
            'WBZ' => 'WBZ',
            'WC' => 'WC',
            'WCB' => 'WCB',
            'WCC' => 'WCC',
            'WCF' => 'WCF',
            'WCG' => 'WCG',
            'WCJ' => 'WCJ',
            'WCJB' => 'WCJ',
            'WCK' => 'WCK',
            'WCL' => 'WCL',
            'WCN' => 'WCN',
            'WCNC' => 'WCN',
            'WCNG' => 'WCN',
            'WCP' => 'WCP',
            'WCR' => 'WCR',
            'WCRB' => 'WC',
            'WCS' => 'WCS',
            'WCT' => 'WC',
            'WCU' => 'WCU',
            'WCV' => 'WCV',
            'WCVB' => 'WCV',
            'WCW' => 'WCW',
            'WCX' => 'WCX',
            'WCXM' => 'WCX',
            'WCXS' => 'WCX',
            'WD' => 'WD',
            'WDH' => 'WDH',
            'WDHB' => 'WDH',
            'WDHM' => 'WDHM',
            'WDHR' => 'WDHR',
            'WDHW' => 'WDHW',
            'WDJ' => 'WDJ',
            'WDK' => 'WDK',
            'WDKC' => 'WDKC',
            'WDKN' => 'WDKN',
            'WDKX' => 'WDKX',
            'WDM' => 'WDM',
            'WDMC' => 'WDMC',
            'WDMC1' => 'WDMC1',
            'WDMC2' => 'WDMC2',
            'WDMG' => 'WDMG',
            'WDMG1' => 'WDMG1',
            'WDMG2' => 'WDMG',
            'WDMG3' => 'WDMG',
            'WDP' => 'WDP',
            'WF' => 'WF',
            'WFA' => 'WFA',
            'WFB' => 'WFB',
            'WFBC' => 'WFBC',
            'WFBL' => 'WFBL',
            'WFBQ' => 'WFBQ',
            'WFBS' => 'WFBS',
            'WFBS1' => 'WFBS',
            'WFBS2' => 'WFBS',
            'WFBV' => 'WFBV',
            'WFBW' => 'WFB',
            'WFC' => 'WFC',
            'WFD' => 'WF',
            'WFE' => 'WF',
            'WFF' => 'WFF',
            'WFG' => 'WFG',
            'WFH' => 'WFH',
            'WFJ' => 'WFJ',
            'WFK' => 'WFK',
            'WFL' => 'WF',
            'WFN' => 'WFN',
            'WFP' => 'WFL',
            'WFQ' => 'WFL',
            'WFS' => 'WFS',
            'WFT' => 'WFT',
            'WFTM' => 'WFTM',
            'WFU' => 'WFU',
            'WFV' => 'WFV',
            'WFW' => 'WFW',
            'WFX' => 'WF',
            'WFY' => 'WF',
            'WG' => 'WG',
            'WGC' => 'WGC',
            'WGCB' => 'WGCB',
            'WGCF' => 'WGCF',
            'WGCG' => 'WGC',
            'WGCK' => 'WGCK',
            'WGCQ' => 'WGC',
            'WGCT' => 'WGCT',
            'WGCV' => 'WGCV',
            'WGD' => 'WG',
            'WGF' => 'WGF',
            'WGFD' => 'WGF',
            'WGFL' => 'WGF',
            'WGG' => 'WGG',
            'WGGB' => 'WGG',
            'WGGD' => 'WGG',
            'WGGP' => 'WGG',
            'WGGV' => 'WGGV',
            'WGM' => 'WGM',
            'WH' => 'WH',
            'WHB' => 'WH',
            'WHG' => 'WHG',
            'WHJ' => 'WHJ',
            'WHL' => 'WHL',
            'WHP' => 'WHP',
            'WHX' => 'WHX',
            'WJ' => 'WJ',
            'WJF' => 'WJF',
            'WJH' => 'WJH',
            'WJJ' => 'WJ',
            'WJK' => 'WJK',
            'WJS' => 'WJS',
            'WJW' => 'WJW',
            'WJX' => 'WJX',
            'WJXC' => 'WJX',
            'WJXF' => 'WJX',
            'WJY' => 'WJ',
            'WK' => 'WK',
            'WKD' => 'WKD',
            'WKDM' => 'WKDM',
            'WKDW' => 'WKDW',
            'WKH' => 'WKH',
            'WKR' => 'WKR',
            'WKU' => 'WK',
            'WM' => 'WM',
            'WMB' => 'WMB',
            'WMD' => 'WMD',
            'WMF' => 'WMF',
            'WMP' => 'WMP',
            'WMPC' => 'WMPC',
            'WMPF' => 'WMPF',
            'WMPS' => 'WMPS',
            'WMPY' => 'WMP',
            'WMQ' => 'WMQ',
            'WMQB' => 'WMQB',
            'WMQF' => 'WMQF',
            'WMQL' => 'WMQL',
            'WMQN' => 'WMQN',
            'WMQP' => 'WMQP',
            'WMQR' => 'WMQR',
            'WMQR1' => 'WMPX',
            'WMQW' => 'WMQW',
            'WMT' => 'WMT',
            'WN' => 'WN',
            'WNA' => 'WNA',
            'WNC' => 'WNC',
            'WNCB' => 'WNCB',
            'WNCF' => 'WNCF',
            'WNCK' => 'WNCK',
            'WNCN' => 'WNCN',
            'WNCS' => 'WNCS',
            'WNCS1' => 'WNCS1',
            'WNCS2' => 'WNCS2',
            'WND' => 'WND',
            'WNF' => 'WNF',
            'WNG' => 'WNG',
            'WNGC' => 'WNGC',
            'WNGD' => 'WNGD',
            'WNGD1' => 'WNGD1',
            'WNGF' => 'WNGF',
            'WNGH' => 'WNGH',
            'WNGK' => 'WNGK',
            'WNGR' => 'WNGR',
            'WNGS' => 'WNGS',
            'WNGX' => 'WNGX',
            'WNH' => 'WNH',
            'WNJ' => 'WND',
            'WNP' => 'WNP',
            'WNPB' => 'WNP',
            'WNR' => 'WNR',
            'WNS' => 'WNC',
            'WNW' => 'WNW',
            'WNWM' => 'WNWM',
            'WNX' => 'WNX',
            'WQ' => 'WQ',
            'WQH' => 'WQH',
            'WQN' => 'WQN',
            'WQP' => 'WQP',
            'WQY' => 'WQY',
            'WT' => 'WT',
            'WTD' => 'WTD',
            'WTH' => 'WTH',
            'WTHA' => 'WTHA',
            'WTHB' => 'WTHB',
            'WTHC' => 'WTHC',
            'WTHD' => 'WTH',
            'WTHE' => 'WTH',
            'WTHF' => 'WTHF',
            'WTHG' => 'WTH',
            'WTHH' => 'WTHH',
            'WTHH1' => 'WTHH1',
            'WTHK' => 'WTH',
            'WTHL' => 'WTH',
            'WTHM' => 'WTHM',
            'WTHN' => 'WTH',
            'WTHR' => 'WTHR',
            'WTHT' => 'WTHT',
            'WTHV' => 'WTH',
            'WTHW' => 'WTH',
            'WTHX' => 'WTHX',
            'WTHY' => 'WTH',
            'WTK' => 'WTK',
            'WTL' => 'WTL',
            'WTLC' => 'WTLC',
            'WTLP' => 'WTLP',
            'WTM' => 'WTM',
            'WTR' => 'WTR',
            'WTRD' => 'WTRD',
            'WTRM' => 'WTRM',
            'WTRS' => 'WTRS',
            'WZ' => 'WZ',
            'WZG' => 'WZG',
            'WZP' => 'WZ',
            'WZS' => 'WZS',
            'WZSD' => 'WZ',
            'WZSJ' => 'WZS',
            'WZSN' => 'WZS',
            'WZSP' => 'WZ',
            'X' => 'YFW',
            'XA' => 'YFW',
            'XAB' => 'YFW',
            'XAD' => 'YFW',
            'XADC' => 'YFW',
            'XAK' => 'YFW',
            'XAKC' => 'YFW',
            'XAM' => 'FXA',
            'XAMA' => 'YFW',
            'XAMB' => 'YFW',
            'XAMC' => 'YFW',
            'XAMD' => 'YFW',
            'XAME' => 'YFW',
            'XAMF' => 'YFW',
            'XAMG' => 'YFW',
            'XAML' => 'FXA',
            'XAMR' => 'FXA',
            'XAMT' => 'FXA',
            'XAMV' => 'FXA',
            'XAMX' => 'FXA',
            'XAMX2' => 'FXA',
            'XAMY' => 'FXA',
            'XAX' => 'YFW',
            'XQ' => 'YFW',
            'XQA' => 'FXZ',
            'XQAY' => 'YFW',
            'XQB' => 'FXL',
            'XQC' => 'FX',
            'XQD' => 'FX',
            'XQE' => 'YFW',
            'XQED' => 'YFW',
            'XQF' => 'YFW',
            'XQG' => 'YFW',
            'XQGW' => 'YFW',
            'XQH' => 'YFW',
            'XQJ' => 'YFW',
            'XQK' => 'YFW',
            'XQL' => 'YFW',
            'XQLM' => 'YFW',
            'XQM' => 'YFW',
            'XQMM' => 'YFW',
            'XQMP' => 'YFW',
            'XQN' => 'YFW',
            'XQP' => 'YFW',
            'XQQ' => 'YFW',
            'XQR' => 'YFW',
            'XQS' => 'YFW',
            'XQT' => 'YFW',
            'XQV' => 'YFW',
            'XQW' => 'YFW',
            'XQX' => 'FX',
            'XQXE' => 'FX',
            'XQXV' => 'FX',
            'XR' => 'FZG',
            'XRM' => 'FZG',
            'XY' => 'YNUC',
            'Y' => 'Y',
            'YB' => 'YB',
            'YBC' => 'YBC',
            'YBCB' => 'YBCB',
            'YBCH' => 'YBCH',
            'YBCS' => 'YBCS',
            'YBCS1' => 'YBCS',
            'YBCS2' => 'YBCS',
            'YBD' => 'YFB',
            'YBG' => 'YBG',
            'YBGC' => 'YBGC',
            'YBGH' => 'YBG',
            'YBGS' => 'YBG',
            'YBL' => 'YBL',
            'YBLA' => 'YBLA',
            'YBLB' => 'YBLB',
            'YBLC' => 'YBLC',
            'YBLD' => 'YBLD',
            'YBLF' => 'YBLF',
            'YBLG' => 'YBL',
            'YBLH' => 'YBLH',
            'YBLJ' => 'YBLJ',
            'YBLL' => 'YBL',
            'YBLM' => 'YBL',
            'YBLM1' => 'YBL',
            'YBLN' => 'YBLN',
            'YBLN1' => 'YBLN1',
            'YBLP' => 'YBLP',
            'YBLQ' => 'YBL',
            'YBLT' => 'YBLT',
            'YD' => 'YD',
            'YDA' => 'YDA',
            'YDC' => 'YDC',
            'YDP' => 'YDP',
            'YF' => 'YF',
            'YFA' => 'YFA',
            'YFB' => 'YFB',
            'YFC' => 'YFC',
            'YFCA' => 'YFC',
            'YFCB' => 'YFCB',
            'YFCF' => 'YFCF',
            'YFCW' => 'YFC',
            'YFD' => 'YFD',
            'YFE' => 'YFB',
            'YFEB' => 'YFB',
            'YFF' => 'YFC',
            'YFG' => 'YFG',
            'YFGR' => 'YFG',
            'YFGS' => 'YFG',
            'YFH' => 'YFH',
            'YFHB' => 'YFH',
            'YFHD' => 'YFH',
            'YFHG' => 'YFH',
            'YFHH' => 'YFH',
            'YFHK' => 'YFH',
            'YFHR' => 'YFHR',
            'YFHT' => 'YFH',
            'YFHW' => 'YFH',
            'YFJ' => 'YFJ',
            'YFJB' => 'YFJ',
            'YFJH' => 'YFJ',
            'YFJK' => 'YFJ',
            'YFJM' => 'YFJ',
            'YFK' => 'YFB',
            'YFM' => 'YFM',
            'YFMF' => 'YFM',
            'YFMR' => 'YFM',
            'YFN' => 'YFN',
            'YFP' => 'YFP',
            'YFQ' => 'YFQ',
            'YFR' => 'YFR',
            'YFS' => 'YFS',
            'YFT' => 'YFT',
            'YFU' => 'YFU',
            'YFV' => 'YFB',
            'YFX' => 'YFB',
            'YFY' => 'YFY',
            'YFZ' => 'YF',
            'YFZC' => 'YF',
            'YFZD' => 'YF',
            'YFZH' => 'YF',
            'YFZR' => 'YF',
            'YFZS' => 'YF',
            'YFZT' => 'YF',
            'YFZV' => 'YF',
            'YFZW' => 'YF',
            'YFZZ' => 'YRG',
            'YN' => 'YN',
            'YNA' => 'YNA',
            'YNB' => 'YNM',
            'YNC' => 'YNCP',
            'YNCB' => 'YNC',
            'YNCS' => 'YNC',
            'YND' => 'YND',
            'YNDB' => 'YNDB',
            'YNDS' => 'YNDS',
            'YNF' => 'YNF',
            'YNG' => 'YNG',
            'YNGL' => 'YNGL',
            'YNH' => 'YNH',
            'YNHA' => 'YNM',
            'YNHA1' => 'YNM',
            'YNHD' => 'YNM',
            'YNHP' => 'YNH',
            'YNJ' => 'YNJ',
            'YNJC' => 'YNH',
            'YNK' => 'YNK',
            'YNKA' => 'YNK',
            'YNKC' => 'YNK',
            'YNKG' => 'YNK',
            'YNL' => 'YNL',
            'YNM' => 'YNM',
            'YNMC' => 'YNM',
            'YNMD' => 'YNM',
            'YNMF' => 'YNM',
            'YNMH' => 'YNM',
            'YNMK' => 'YNM',
            'YNML' => 'YNM',
            'YNMP' => 'YNM',
            'YNMR' => 'YNM',
            'YNMW' => 'YNM',
            'YNN' => 'YNN',
            'YNNA' => 'YNNA',
            'YNNB' => 'YNNR',
            'YNNB1' => 'YNNR',
            'YNNB2' => 'YNNR',
            'YNNB3' => 'YNNR',
            'YNNB4' => 'YNNR',
            'YNNB5' => 'YNNR',
            'YNNB9' => 'YNNR',
            'YNNC' => 'YNN',
            'YNND' => 'YNN',
            'YNNF' => 'YNNF',
            'YNNH' => 'YNND',
            'YNNH1' => 'YNND',
            'YNNH2' => 'YNND',
            'YNNH3' => 'YNND',
            'YNNH4' => 'YNND',
            'YNNH5' => 'YNND',
            'YNNJ' => 'YNNR',
            'YNNJ1' => 'YNNR',
            'YNNJ14' => 'YNNR',
            'YNNJ2' => 'YNNR',
            'YNNJ21' => 'YNNR',
            'YNNJ22' => 'YNNR',
            'YNNJ23' => 'YNNR',
            'YNNJ24' => 'YNNR',
            'YNNJ25' => 'YNNF',
            'YNNJ26' => 'YNNF',
            'YNNJ27' => 'YNNF',
            'YNNJ28' => 'YNNR',
            'YNNJ29' => 'YNNR',
            'YNNJ3' => 'YNNR',
            'YNNJ31' => 'YNNR',
            'YNNJ9' => 'YNNR',
            'YNNK' => 'YNNR',
            'YNNL' => 'YNNR',
            'YNNM' => 'YNNR',
            'YNNS' => 'YNNR',
            'YNNT' => 'YNN',
            'YNNV' => 'YNN',
            'YNNV1' => 'YNN',
            'YNNV2' => 'YNN',
            'YNNZ' => 'YNTS',
            'YNP' => 'YNP',
            'YNPB' => 'YNP',
            'YNPC' => 'YNPC',
            'YNPG' => 'YNP',
            'YNPH' => 'YNPH',
            'YNPH1' => 'YNPH',
            'YNPH2' => 'YNPH',
            'YNPJ' => 'YNP',
            'YNPK' => 'YNPK',
            'YNQ' => 'YNP',
            'YNR' => 'YNR',
            'YNRA' => 'YN',
            'YNRD' => 'YNR',
            'YNRE' => 'YNR',
            'YNRF' => 'YNR',
            'YNRG' => 'YNR',
            'YNRH' => 'YNR',
            'YNRJ' => 'YNR',
            'YNRM' => 'YNR',
            'YNRP' => 'YNR',
            'YNRR' => 'YNR',
            'YNRU' => 'YNR',
            'YNRX' => 'YNR',
            'YNRY' => 'YNR',
            'YNT' => 'YNT',
            'YNTA' => 'YNT',
            'YNTC' => 'YNT',
            'YNTC1' => 'YNT',
            'YNTC2' => 'YNT',
            'YNTD' => 'YNT',
            'YNTE' => 'YNT',
            'YNTF' => 'YNT',
            'YNTG' => 'YNT',
            'YNTM' => 'YNT',
            'YNTP' => 'YNTB',
            'YNTR' => 'YNTR',
            'YNTT' => 'YNT',
            'YNU' => 'YNU',
            'YNUC' => 'YNUC',
            'YNV' => 'YNV',
            'YNVD' => 'YNV',
            'YNVD1' => 'YNV',
            'YNVD2' => 'YNV',
            'YNVD3' => 'YNV',
            'YNVM' => 'YNV',
            'YNVP' => 'YNVP',
            'YNVU' => 'YNVU',
            'YNW' => 'YNW',
            'YNWA' => 'YNW',
            'YNWD' => 'YNW',
            'YNWD1' => 'YNWA',
            'YNWD2' => 'YNW',
            'YNWD3' => 'YNW',
            'YNWD4' => 'YNW',
            'YNWD5' => 'YNWC',
            'YNWD6' => 'YNW',
            'YNWD7' => 'YNWB',
            'YNWD8' => 'YNW',
            'YNWG' => 'YNWG',
            'YNWJ' => 'YNW',
            'YNWK' => 'YNW',
            'YNWM' => 'YNW',
            'YNWM1' => 'YNW',
            'YNWM2' => 'YNW',
            'YNWP' => 'YNW',
            'YNWT' => 'YNW',
            'YNWW' => 'YNWW',
            'YNWY' => 'YNWY',
            'YNWZ' => 'YNW',
            'YNX' => 'YNX',
            'YNXB' => 'YNX',
            'YNXB1' => 'YNX',
            'YNXB2' => 'YNX',
            'YNXB3' => 'YNX',
            'YNXB4' => 'YNX',
            'YNXB5' => 'YNX',
            'YNXB6' => 'YNX',
            'YNXB7' => 'YNX',
            'YNXF' => 'YNXF',
            'YNXW' => 'YNXW',
            'YP' => 'YQ',
            'YPA' => 'YQA',
            'YPAB' => 'YQA',
            'YPAD' => 'YQB',
            'YPAF' => 'YQD',
            'YPAG' => 'YQA',
            'YPAK' => 'YQA',
            'YPC' => 'YQC',
            'YPCA' => 'YQC',
            'YPCA1' => 'YQC',
            'YPCA2' => 'YQCS',
            'YPCA21' => 'YQCR',
            'YPCA22' => 'YQCS1',
            'YPCA23' => 'YQCS',
            'YPCA24' => 'YQCS5',
            'YPCA4' => 'YQCS',
            'YPCA5' => 'YQC',
            'YPCA9' => 'YQE',
            'YPCA91' => 'YQE',
            'YPCK' => 'YQC',
            'YPCK2' => 'YQC',
            'YPCK21' => 'YQC',
            'YPCK22' => 'YQCR',
            'YPCK9' => 'YQE',
            'YPCK91' => 'YQE',
            'YPCS' => 'YQF',
            'YPCS4' => 'YQF',
            'YPCS9' => 'YQFL',
            'YPCS91' => 'YQFL',
            'YPJ' => 'YQJ',
            'YPJH' => 'YQH',
            'YPJJ' => 'YQJ',
            'YPJJ1' => 'YQJ',
            'YPJJ3' => 'YQN',
            'YPJJ4' => 'YQX',
            'YPJJ5' => 'YQJP',
            'YPJJ6' => 'YQNP',
            'YPJK' => 'YQJ',
            'YPJL' => 'YQ',
            'YPJM' => 'YQJ',
            'YPJN' => 'YQR',
            'YPJN1' => 'YQRN3',
            'YPJN2' => 'YQRN4',
            'YPJN3' => 'YQRN1',
            'YPJN4' => 'YQRC',
            'YPJN5' => 'YQRN2',
            'YPJN9' => 'YQRN',
            'YPJT' => 'YQG',
            'YPJV' => 'YQV',
            'YPJV1' => 'YQV',
            'YPJV2' => 'YQV',
            'YPJV3' => 'YQV',
            'YPJX' => 'YQJ',
            'YPM' => 'YQS',
            'YPMF' => 'YQM',
            'YPMF1' => 'YQMT',
            'YPMF2' => 'YQM',
            'YPMF3' => 'YQM',
            'YPMP' => 'YQS',
            'YPMP1' => 'YQSB',
            'YPMP3' => 'YQSC',
            'YPMP5' => 'YQSP',
            'YPMP51' => 'YQSP',
            'YPMP6' => 'YQS',
            'YPMP7' => 'YQS',
            'YPMT' => 'YQT',
            'YPMT2' => 'YQTD',
            'YPMT3' => 'YQT',
            'YPMT4' => 'YQTF',
            'YPMT5' => 'YQT',
            'YPMT6' => 'YQTU',
            'YPMT7' => 'YQTD',
            'YPMT8' => 'YQTD',
            'YPW' => 'YQ',
            'YPWB' => 'YQS',
            'YPWC' => 'YQY',
            'YPWC1' => 'YQY',
            'YPWC2' => 'YQY',
            'YPWC3' => 'YQY',
            'YPWC4' => 'YQY',
            'YPWC5' => 'YQY',
            'YPWC9' => 'YQY',
            'YPWD' => 'YQY',
            'YPWE' => 'YQY',
            'YPWF' => 'YQW',
            'YPWG' => 'YQY',
            'YPWL' => 'YQX',
            'YPWL1' => 'YQX',
            'YPWL2' => 'YQX',
            'YPWL3' => 'YQX',
            'YPWL4' => 'YQNP',
            'YPWN' => 'YQY',
            'YPZ' => 'YQZ',
            'YPZH' => 'YQZ',
            'YPZN' => 'YQZ',
            'YPZP' => 'YQZ',
            'YR' => 'YR',
            'YRD' => 'YRD',
            'YRDC' => 'YRDC',
            'YRDL' => 'YRDL',
            'YRDM' => 'YQC',
            'YRE' => 'YRE',
            'YRG' => 'YRG',
            'YRW' => 'YRW',
            'YX' => 'YX',
            'YXA' => 'YXA',
            'YXAB' => 'YXA',
            'YXAD' => 'YXA',
            'YXAM' => 'YXA',
            'YXAX' => 'YXAX',
            'YXAX1' => 'YXA',
            'YXB' => 'YXS',
            'YXBD' => 'YXS',
            'YXC' => 'YXS',
            'YXD' => 'YXL',
            'YXE' => 'YXL',
            'YXEB' => 'YXL',
            'YXED' => 'YXL',
            'YXEF' => 'YXL',
            'YXEH' => 'YXL',
            'YXEJ' => 'YXL',
            'YXEL' => 'YXL',
            'YXEN' => 'YXL',
            'YXET' => 'YXL',
            'YXF' => 'YXF',
            'YXFB' => 'YXF',
            'YXFC' => 'YXF',
            'YXFD' => 'YXFD',
            'YXFF' => 'YXF',
            'YXFR' => 'YXFM',
            'YXFS' => 'YXFM',
            'YXG' => 'YXG',
            'YXGS' => 'YXG',
            'YXH' => 'YXS',
            'YXHB' => 'YX',
            'YXHL' => 'YXS',
            'YXHP' => 'YX',
            'YXHY' => 'YXFT',
            'YXJ' => 'YXJ',
            'YXK' => 'YXK',
            'YXL' => 'YXA',
            'YXLB' => 'YXA',
            'YXLB1' => 'YXA',
            'YXLD' => 'YXA',
            'YXLD1' => 'YXA',
            'YXLD2' => 'YXA',
            'YXLD6' => 'YXA',
            'YXM' => 'YXN',
            'YXN' => 'YXN',
            'YXP' => 'YX',
            'YXPB' => 'YX',
            'YXQ' => 'YXC',
            'YXQD' => 'YXC',
            'YXQF' => 'YXC',
            'YXQP' => 'YXC',
            'YXR' => 'YX',
            'YXS' => 'YX',
            'YXT' => 'YXT',
            'YXTB' => 'YXT',
            'YXV' => 'YXV',
            'YXW' => 'YX',
            'YXWP' => 'YXA',
            'YXZ' => 'YXA',
            'YXZB' => 'YXZ',
            'YXZC' => 'YXZ',
            'YXZD' => 'YXZ',
            'YXZE' => 'YXZ',
            'YXZF' => 'YXZ',
            'YXZG' => 'YXZG',
            'YXZH' => 'YXZ',
            'YXZM' => 'YXA',
            'YXZR' => 'YXZR',
            'YXZW' => 'YXZW',
            'YZ' => 'YZ',
            'YZG' => 'YZ',
            'YZP' => 'YZ',
            'YZS' => 'YZ',
            'YZSD' => 'YZ',
            'YZSG' => 'YZ',
            'YZSN' => 'YZ',
            'YZSP' => 'YZ',

        ];

        // Get Thema subject codes
        $themaCodes = $this->getThemaCodes()->pluck('codeValue');

        $bicCodes = [];

        foreach ($themaCodes as $themaCode) {
            if (array_key_exists($themaCode, $themaToBicMapping)) {
                array_push($bicCodes, $themaToBicMapping[$themaCode]);
            }
        }

        return $bicCodes;
    }

    /**
     * Return the Storia product group
     *
     * @return array|null
     */
    public function getStoriaProductGroup()
    {
        // Product group mapping
        $storiaProductGroups = [
            '00' => 'Kotimainen Kaunokirjallisuus',
            '01' => 'Käännetty Kaunokirjallisuus',
            '03' => 'Tietokirjallisuus',
            '04' => 'Lasten ja nuorten kirjat',
            '06' => 'Pokkarit',
            '64' => 'Äänikirjat',
            '10' => 'Peruskoulun oppikirjat',
            '20' => 'Oppikirjat',
            '40' => 'Kalenterit',
            '50' => 'Kartat',
            '05' => 'Nuotit',
            '63' => 'Musiikkiäänitteet',
            '82' => 'Pelit',
            '86' => 'Puuha- ja värityskirjat',
            '80' => 'Myymälämateriaalit (telineet ym.)',
        ];

        if (isset($this->product->mainGroup)) {
            switch ($this->product->mainGroup->name) {
                case 'Kotimainen kauno':
                    $productGroup = '00';
                    break;
                case 'Käännetty kauno':
                    $productGroup = '01';
                    break;
                case 'Tietokirjallisuus':
                case 'Kotimainen asiaproosa':
                case 'Käännetty asiaproosa':
                    $productGroup = '03';
                    break;
                case 'Kotimainen L&N':
                case 'Käännetty L&N':
                    $productGroup = '04';
                    break;
                default:
                    $productGroup = null;
                    break;
            }
        }

        // Binding code overrides main group based mapping
        switch ($this->getProductType()) {
            case 'Pocket book':
                $productGroup = '06';
                break;
            case 'CD':
                $productGroup = '64';
                break;
            case 'Calendar':
                $productGroup = '40';
                break;
            case 'Marketing material':
                $productGroup = '80';
                break;
        }

        // For coloring books try to check if title contains a hint
        if (Str::contains($this->product->title, ['värityskirja', 'puuhakirja'])) {
            $productGroup = '86';
        }

        if (isset($productGroup) && array_key_exists($productGroup, $storiaProductGroups)) {
            return [
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Storia - Tuoteryhmä',
                'SubjectCode' => $productGroup,
                'SubjectHeadingText' => $storiaProductGroups[$productGroup],
            ];
        }

        return null;
    }

    /**
     * Get Finnish book trade categorisations - See http://www.onixkeskus.fi/onix/misc/popup.jsp?page=onix_help_subjectcategorisation
     *
     * @return string|null
     */
    public function getFinnishBookTradeCategorisation()
    {
        // Pocket books should always return 'T'
        $isPocketBook = $this->getProductForm() === 'BC' && $this->getProductFormDetails()->contains('B104');

        if ($isPocketBook) {
            return 'T';
        }

        if (isset($this->product->libraryCodePrefix) === false) {
            return null;
        }

        // Sometimes pocket book is the main edition and the prefix is shared to editions where it does not belong
        if ($this->product->libraryCodePrefix->id === 'T' && $isPocketBook === false) { // @phpstan-ignore-line
            return null;
        }

        return $this->product->libraryCodePrefix->id;
    }

    /**
     * Return Thema interest age / special interest qualifier based on the Mockingbird age group
     *
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

        if (isset($this->product->interestAge) && array_key_exists($this->product->interestAge->name, $mappingTable)) {
            return $mappingTable[$this->product->interestAge->name];
        } else {
            return null;
        }
    }

    /**
     * Is the product confidential?
     *
     * @return bool
     */
    public function isConfidential()
    {
        $confidentialStatuses = [
            'Development-Confidential',
            'Cancelled-Confidential',
            'Exclusive - Direct Delivery',
        ];

        return in_array($this->product->listingCode->name, $confidentialStatuses);
    }

    /**
     * Is the product a luxury book?
     *
     * @return bool
     */
    public function isLuxuryBook()
    {
        $costCenter = $this->getCostCenter();

        if ($costCenter === 314 || $costCenter === 935) {
            return true;
        }

        return false;
    }

    /**
     * Get the products cost center
     *
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
     * Get the products cost center name
     *
     * @return string|null
     */
    public function getCostCenterName()
    {
        if (isset($this->product->costCenter)) {
            return $this->product->costCenter->name;
        }

        return null;
    }

    /**
     * Get the products media type
     *
     * @return string|null
     */
    public function getMediaType()
    {
        return $this->getProductForm();
    }

    /**
     * Get the products binding code
     *
     * @return string|null
     */
    public function getBindingCode()
    {
        if (property_exists($this->product->bindingCode->customProperties, 'productFormDetail') === false) {
            return null;
        }

        return $this->product->bindingCode->customProperties->productFormDetail;
    }

    /**
     * Get the products status
     *
     * @return string
     */
    public function getStatus()
    {
        return $this->product->listingCode->name;
    }

    /**
     * Get the number of products in the series
     *
     * @return int|null
     */
    public function getProductsInSeries()
    {
        return (empty($this->product->numberInSeries)) ? null : intval($this->product->numberInSeries);
    }

    /**
     * Is the product immaterial?
     *
     * @return bool
     */
    public function isImmaterial()
    {
        return in_array($this->getProductType(), [
            'Podcast',
            'Downloadable audio file',
            'Picture-and-audio book',
            'ePub2',
            'ePub3',
            'Application',
            'PDF',
        ]);
    }

    /**
     * Is the product a Print On Demand product?
     *
     * @return bool
     */
    public function isPrintOnDemand()
    {
        return $this->product->listingCode->name === 'Short run' || $this->product->listingCode->name === 'Print On Demand';
    }

    /**
     * Is the product a Print On Demand checked?
     *
     * @return bool
     */
    public function isPrintOnDemandChecked()
    {
        return isset($this->product->isPrintOnDemand) && $this->product->isPrintOnDemand === true;
    }

    /**
     * Get internal product number
     *
     * @return string|null
     */
    public function getInternalProdNo()
    {
        return $this->product->isbn;
    }

    /**
     * Get customs number
     *
     * @return int|null
     */
    public function getCustomsNumber()
    {
        switch ($this->getProductType()) {
            // Audio and MP3 CD
            case 'CD':
            case 'MP3-CD':
                return 85234920;
                // Digital products should return null
            case 'ePub2':
            case 'ePub3':
            case 'Application':
            case 'Downloadable audio file':
            case 'Picture-and-audio book':
                return null;
            default:
                return 49019900;
        }
    }

    /**
     * Get the products library class
     *
     * @return string|null
     */
    public function getLibraryClass()
    {
        if (! isset($this->product->libraryCode)) {
            return null;
        }

        return $this->product->libraryCode->id;
    }

    /**
     * Get the products marketing category
     *
     * @return string|null
     */
    public function getMarketingCategory()
    {
        if (isset($this->product->effortType)) {
            return $this->product->effortType->name;
        }

        return null;
    }

    /**
     * Get the products sales season
     *
     * @return string|null
     */
    public function getSalesSeason()
    {
        if (! isset($this->product->seasonYear) && ! isset($this->product->seasonPeriod)) {
            return null;
        }

        if (! isset($this->product->seasonYear) && isset($this->product->seasonPeriod)) {
            return null;
        }

        if (isset($this->product->seasonYear) && ! isset($this->product->seasonPeriod)) {
            return $this->product->seasonYear->name;
        }

        // Form sales period
        switch ($this->product->seasonPeriod->name) {
            case 'Spring':
                return $this->product->seasonYear->name.'/1';
            case 'Autumn':
                return $this->product->seasonYear->name.'/2';
            default:
                return $this->product->seasonYear->name.'/'.$this->product->seasonPeriod->name;
        }
    }

    /**
     * Get the products backlist sales season
     *
     * @return string|null
     */
    public function getBacklistSalesSeason()
    {

        if (! isset($this->product->backlistSeasonYear) || ! isset($this->product->backlistSeasonPeriod)) {
            return null;
        }

        if (isset($this->product->backlistSeasonYear) && ! isset($this->product->backlistSeasonPeriod)) {
            return $this->product->backlistSeasonYear->name;
        }

        return $this->product->backlistSeasonYear->name.' '.$this->product->backlistSeasonPeriod->name;
    }

    /**
     * Get the products audience groups
     *
     * @return Collection
     */
    public function getAudiences()
    {
        // Collection for audiences
        $audiences = new Collection;

        // Get Thema interest age
        $interestAges = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->filter(function ($subject, $key) {
            return Str::startsWith($subject['SubjectCode'], '5A');
        })->pluck('SubjectCode');

        foreach ($interestAges as $interestAge) {
            // Map the Thema interest age to Audience
            switch ($interestAge) {
                // Children/juvenile
                case '5AB':
                case '5AC':
                case '5AD':
                case '5AF':
                case '5AG':
                case '5AH':
                case '5AJ':
                case '5AK':
                case '5AL':
                case '5AM':
                case '5AN':
                case '5AP':
                case '5AQ':
                    $audienceCodeValue = '02';
                    break;
                    // Young adult
                case '5AS':
                case '5AT':
                case '5AU':
                    $audienceCodeValue = '03';
                    break;
                    // General/trade as fallback
                default:
                    $audienceCodeValue = '01';
                    break;
            }

            $audiences->push(['AudienceCodeType' => '01', 'AudienceCodeValue' => $audienceCodeValue]);
        }

        // If no interest ages are found, add General/Trade
        if (count($interestAges) === 0) {
            $audiences->push(['AudienceCodeType' => '01', 'AudienceCodeValue' => '01']);
        }

        return $audiences;
    }

    /**
     * Get the products AudienceRanges
     *
     * @return Collection
     */
    public function getAudienceRanges()
    {
        // Collection for audience ranges
        $audienceRanges = new Collection;

        $interestAges = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->filter(function ($subject, $key) {
            return Str::startsWith($subject['SubjectCode'], '5A');
        })->pluck('SubjectCode');

        // Map Thema interest ages to numeric values
        $interestAgeMapping = [
            '5AB' => 0,
            '5AC' => 3,
            '5AD' => 4,
            '5AF' => 5,
            '5AG' => 6,
            '5AH' => 7,
            '5AJ' => 8,
            '5AK' => 9,
            '5AL' => 10,
            '5AM' => 11,
            '5AN' => 12,
            '5AP' => 13,
            '5AQ' => 14,
            '5AS' => 15,
            '5AT' => 16,
            '5AU' => 17,
        ];

        foreach ($interestAges as $interestAge) {
            if (! empty($interestAge) && array_key_exists($interestAge, $interestAgeMapping)) {
                $audienceRanges->push([
                    'AudienceRangeQualifier' => 17,
                    'AudienceRangeScopes' => [
                        [
                            'AudienceRangePrecision' => '03', // From
                            'AudienceRangeValue' => $interestAgeMapping[$interestAge],
                        ],
                    ],
                ]);
            }
        }

        return $audienceRanges;
    }

    /**
     * Get the latest stock arrival date
     *
     * @return DateTime|null
     */
    public function getLatestStockArrivalDate()
    {
        // Get the production print orders from Mockingbird
        $response = $this->client->get('/v1/works/'.$this->workId.'/productions/'.$this->productionId.'/printchanges');
        $printOrders = json_decode($response->getBody()->getContents());

        // Collection for dates
        $printDates = collect([]);

        foreach ($printOrders->prints as $print) {
            foreach ($print->timePlanEntries as $timePlanEntry) {
                if ($timePlanEntry->type->name === 'Delivery to warehouse') {
                    if (isset($timePlanEntry->planned)) {
                        $printDates->push(['date' => DateTime::createFromFormat('!Y-m-d', substr($timePlanEntry->planned, 0, 10))]);
                    }

                    if (isset($timePlanEntry->actual)) {
                        $printDates->push(['date' => DateTime::createFromFormat('!Y-m-d', substr($timePlanEntry->actual, 0, 10))]);
                    }
                }
            }
        }

        return $printDates->max('date');
    }

    /**
     * Get the latest print number
     *
     * @return int|null
     */
    public function getLatestPrintNumber()
    {
        if (! isset($this->product->activePrint->printNumber)) {
            return null;
        }

        return $this->product->activePrint->printNumber;
    }

    /**
     * Get the sales restrictions
     *
     * @return Collection
     */
    public function getSalesRestrictions()
    {
        $salesRestrictions = new Collection;

        // Get list of distribution channels
        $distributionChannels = $this->getDistributionChannels();

        // Only send sales restrictions to digital products
        if ($this->isImmaterial() === false) {
            return $salesRestrictions;
        }

        // If none of the library channels has rights, add restriction "Not for sale to libraries"
        if ($distributionChannels->where('channelType', 'Licencing for libraries')->contains('hasRights', true) === false) {
            $salesRestrictions->push([
                'SalesRestrictionType' => '09', // Not for sale to libraries
            ]);
        }

        // If none of the subscription channels has rights, add restriction "Not for sale to subscription services"
        if ($distributionChannels->where('channelType', 'Subscription')->contains('hasRights', true) === false) {
            $salesRestrictions->push([
                'SalesRestrictionType' => '12', // Not for sale to subscription services
            ]);
        }

        // Check if we have subscription only product
        if ($distributionChannels->where('channelType', 'Subscription')->contains('hasRights', true)) {
            // Check if all other channels contain false
            if ($distributionChannels->where('channelType', '!=', 'Subscription')->contains('hasRights', true) === false) {
                $salesRestrictions->push([
                    'SalesRestrictionType' => '13', // Subscription services only
                ]);
            }
        }

        // Add SalesOutlets where we have rights as "Retailer exclusive"
        if ($distributionChannels->containsStrict('hasRights', true)) {
            $retailerExclusiveSalesOutlets = $distributionChannels->where('hasRights', true)->map(function ($distributionChannel, $key) {
                // Get IDValue
                $salesOutletIdentifierIdValue = $distributionChannel['salesOutletId'];
                $salesOutletIDType = '03';

                // In case sales outlet id is not found, fall back to propietary
                if (is_null($salesOutletIdentifierIdValue)) {
                    $salesOutletIDType = '01';
                    $salesOutletIdentifierIdValue = $distributionChannel['channel'];
                }

                return [
                    'SalesOutlet' => [
                        'SalesOutletIdentifiers' => [
                            [
                                'SalesOutletIDType' => $salesOutletIDType,
                                'IDValue' => $salesOutletIdentifierIdValue,
                            ],
                        ],
                    ],
                ];
            })->unique(function ($retailerExclusiveSalesOutlet) {
                return $retailerExclusiveSalesOutlet['SalesOutlet']['SalesOutletIdentifiers'][0]['IDValue'];
            });

            $salesRestrictions->push([
                'SalesRestrictionType' => '04', // Retailer exclusive
                'SalesOutlets' => $retailerExclusiveSalesOutlets->toArray(),
            ]);
        }

        // Add SalesOutlets where we don't have rights as "Retailer exception"
        if ($distributionChannels->containsStrict('hasRights', false)) {
            $retailerExceptionSalesOutlets = $distributionChannels->where('hasRights', false)->map(function ($distributionChannel, $key) {
                // Get IDValue
                $salesOutletIdentifierIdValue = $distributionChannel['salesOutletId'];
                $salesOutletIDType = '03';

                // In case mapping is not found, fall back to propietary
                if (is_null($salesOutletIdentifierIdValue)) {
                    $salesOutletIDType = '01';
                    $salesOutletIdentifierIdValue = $distributionChannel['channel'];
                }

                return [
                    'SalesOutlet' => [
                        'SalesOutletIdentifiers' => [
                            [
                                'SalesOutletIDType' => $salesOutletIDType,
                                'IDValue' => $salesOutletIdentifierIdValue,
                            ],
                        ],
                    ],
                ];
            })->unique(function ($retailerExceptionSalesOutlet) {
                return $retailerExceptionSalesOutlet['SalesOutlet']['SalesOutletIdentifiers'][0]['IDValue'];
            });

            $salesRestrictions->push([
                'SalesRestrictionType' => '11', // Retailer exception
                'SalesOutlets' => $retailerExceptionSalesOutlets->toArray(),
            ]);
        }

        return $salesRestrictions->sortBy('SalesRestrictionType');
    }

    /**
     * Get the role priority
     *
     * @param  string  $role
     * @return int
     */
    public function getRolePriority($role)
    {
        $rolePriorities = [
            'Author' => 1,
            'Editor in Chief' => 2,
            'Editing author' => 3,
            'Index' => 4,
            'Preface' => 5,
            'Foreword' => 6,
            'Introduction' => 7,
            'Prologue' => 8,
            'Afterword' => 9,
            'Epilogue' => 10,
            'Illustrator' => 11,
            'Illustrator, cover' => 11,
            'Designer, cover' => 11,
            'Photographer' => 12,
            'Reader' => 13,
            'Translator' => 14,
            'Graphic Designer' => 15,
            'Cover design or artwork by' => 16,
            'Composer' => 17,
            'Arranged by' => 18,
            'Maps' => 19,
            'Assistant' => 20,
        ];

        if (array_key_exists($role, $rolePriorities)) {
            return $rolePriorities[$role];
        }

        return 0;
    }

    /**
     * Get the rights and distribution for each channel
     *
     * @return Collection
     */
    public function getDistributionChannels()
    {
        // Collection for distribution channels
        $distributionChannels = new Collection;

        foreach ($this->product->exportRules as $exportRule) {
            $distributionChannels->push([
                'channel' => trim($exportRule->salesChannel->name),
                'channelType' => $exportRule->salesType->name,
                'hasRights' => $exportRule->hasRights,
                'distributionAllowed' => $exportRule->hasDistribution,
                'salesOutletId' => $exportRule->salesChannel->customProperties->onixSalesOutletId ?? null,
            ]);
        }

        // Remove Elisa, Elisa kirja kuukausitilaus and Alma Talent
        $distributionChannels = $distributionChannels->filter(function (array $distributionChannel, int $key) {
            return in_array($distributionChannel['salesOutletId'], ['ELS', 'ELK', 'ALT']) === false;
        });

        return $distributionChannels;
    }

    /**
     * Is the product connected to ERP?
     *
     * @return bool
     */
    public function isConnectedToErp()
    {
        return (bool) $this->product->isConnectedToERP;
    }

    /**
     * Get the products print orders
     *
     * @return Collection
     */
    public function getPrintOrders()
    {
        // Collection for print orders
        $printOrders = new Collection;

        // For non-physical products return empty collection
        if ($this->isImmaterial()) {
            return $printOrders;
        }

        // Get the production print orders from Mockingbird
        $response = $this->client->get('/v1/works/'.$this->workId.'/productions/'.$this->productionId.'/printchanges');
        $prints = json_decode($response->getBody()->getContents());

        foreach ($prints->prints as $print) {
            // Get deliveries
            $response = $this->client->get('/v2/works/'.$this->workId.'/productions/'.$this->productionId.'/printnumbers/'.$print->print.'/deliveryspecifications');
            $mockingbirdDeliviries = json_decode($response->getBody()->getContents());

            // Store all delivieries to array for later use
            $deliveries = [];

            foreach ($mockingbirdDeliviries->deliverySpecifications as $delivery) {
                $deliveries[] = [
                    'recipient' => $delivery->deliveryType->name,
                    'supplier' => $delivery->printerContact->name,
                    'orderedQuantity' => $delivery->deliveryItems[0]->quantityOrdered,
                    'plannedDeliveryDate' => isset($delivery->deliveryItems[0]->plannedDeliveryDate) ? $delivery->deliveryItems[0]->plannedDeliveryDate : null,
                ];
            }

            $printOrder = [
                'printNumber' => $print->print,
                'orderedQuantity' => isset($print->quantityOrdered) ? $print->quantityOrdered : null,
                // 'supplierId' => $mockingbirdDeliviries->deliverySpecifications->printerContact->id,
                'deliveries' => collect($deliveries),
            ];

            // Add supplier ID if available
            if (property_exists($mockingbirdDeliviries, 'deliverySpecifications') && is_array($mockingbirdDeliviries->deliverySpecifications) && ! empty($mockingbirdDeliviries->deliverySpecifications)) {
                $printOrder['supplierId'] = $mockingbirdDeliviries->deliverySpecifications[0]->printerContact->id;
            } else {
                $printOrder['supplierId'] = null;
            }

            $printOrders->push($printOrder);
        }

        return $printOrders;
    }

    /**
     * Is the product "Main edition"?
     *
     * @return bool
     */
    public function isMainEdition()
    {
        foreach ($this->getWorkLevel()->productions as $production) {
            if ($production->id === $this->product->id && $production->isMainEdition === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the product "Internet edition"?
     *
     * @return bool
     */
    public function isInternetEdition()
    {
        foreach ($this->getWorkLevel()->productions as $production) {
            if ($production->id === $this->product->id && $production->externalPrimaryEdition->isPrimary === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the product "Internet edition" explicitly set
     *
     * @return bool
     */
    public function isInternetEditionExplicitlySet()
    {
        foreach ($this->getWorkLevel()->productions as $production) {
            if ($production->id === $this->product->id && $production->externalPrimaryEdition->isPrimary === true && $production->externalPrimaryEdition->isExplicitlySet === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the products production plan
     *
     * @return Collection
     */
    public function getProductionPlan()
    {
        $productionPlans = new Collection;

        $mockingbirdProductionPlan = $this->getPrintProductionPlan();

        foreach ($mockingbirdProductionPlan->prints as $productionPlanEntry) {
            // Add all time plan entries
            foreach ($productionPlanEntry->timePlanEntries as $timePlanEntry) {
                $productionPlans->push([
                    'print' => $productionPlanEntry->print,
                    'quantity' => ($timePlanEntry->type->name === 'Delivery to warehouse' && isset($productionPlanEntry->quantityOrdered)) ? $productionPlanEntry->quantityOrdered : null,
                    'id' => $timePlanEntry->type->id,
                    'name' => $timePlanEntry->type->name,
                    'planned_date' => isset($timePlanEntry->planned) ? DateTime::createFromFormat('!Y-m-d', substr($timePlanEntry->planned, 0, 10)) : null,
                    'actual_date' => isset($timePlanEntry->actual) ? DateTime::createFromFormat('!Y-m-d', substr($timePlanEntry->actual, 0, 10)) : null,
                ]);
            }
        }

        return $productionPlans;
    }

    /**
     * Test getting the technical description comment
     *
     * @return string|null
     */
    public function getTechnicalDescriptionComment()
    {
        return $this->product->activePrint->miscComment ?? null;
    }

    /**
     * Get the products technical printing data
     *
     * @return Collection
     */
    public function getTechnicalData()
    {
        $technicalData = new Collection;

        // Inside
        $technicalData->push([
            'partName' => 'inside',
            'width' => intval($this->product->activePrint->insideTrimmedFormatWidth),
            'height' => intval($this->product->activePrint->insideTrimmedFormatHeight),
            'paperType' => $this->product->activePrint->insidePaper->name ?? null,
            'paperName' => $this->product->activePrint->insideName ?? null,
            'grammage' => (isset($this->product->activePrint->insideWeight->name)) ? intval($this->product->activePrint->insideWeight->name) : null,
            'grammageOther' => (isset($this->product->activePrint->insideWeightOther)) ? intval($this->product->activePrint->insideWeightOther) : null,
            'bulk' => $this->product->activePrint->insideBulk->name ?? null,
            'bulkValue' => $this->product->activePrint->insideBulkOther ?? null,
            'colors' => (isset($this->product->activePrint->insidePrinting->name)) ? str_replace('+', '/', $this->product->activePrint->insidePrinting->name) : null,
            'colorNames' => $this->product->activePrint->insideColors ?? null,
            // 'hasPhotoSection' => false,
            // 'photoSectionExtent' => null,
            'numberOfPages' => $this->product->pages ?? null,
        ]);

        // Case
        $technicalData->push([
            'partName' => 'case',
            'coverMaterial' => $this->product->activePrint->cover->material ?? null,
            'foil' => $this->product->activePrint->cover->foil ?? null,
            'embossing' => $this->product->activePrint->cover->hasBlindEmbossing ?? null,
            'foilPlacement' => $this->product->activePrint->cover->placement->name ?? null,
        ]);

        // Attachment
        $technicalData->push([
            'partName' => 'attachment',
            'paperType' => $this->product->activePrint->imageSheetPaper->name ?? null,
            'paperName' => $this->product->activePrint->imageSheetName ?? null,
            'grammage' => (isset($this->product->activePrint->imageSheetWeight)) ? intval($this->product->activePrint->imageSheetWeight) : null,
            'numberOfPages' => (isset($this->product->activePrint->imageSheetPages)) ? intval($this->product->activePrint->imageSheetPages) : null,
            'colors' => (isset($this->product->activePrint->imageSheetPrinting->name)) ? str_replace('+', '/', $this->product->activePrint->imageSheetPrinting->name) : null,
            'colorNames' => $this->product->activePrint->imageSheetColors ?? null,
        ]);

        // Printed Cover
        $technicalData->push([
            'partName' => 'printedCover',
            'paperType' => $this->product->activePrint->printedCover->paper->name ?? null,
            'paperName' => $this->product->activePrint->printedCover->paperOther ?? null,
            'grammage' => (isset($this->product->activePrint->printedCover->paperWeight)) ? intval($this->product->activePrint->printedCover->paperWeight) : null,
            'colors' => (isset($this->product->activePrint->printedCover->printing->name)) ? str_replace('+', '/', $this->product->activePrint->printedCover->printing->name) : null,
            'colorNames' => $this->product->activePrint->printedCover->colors ?? null,
            'foil' => $this->product->activePrint->printedCover->foil ?? null,
            'hasBlindEmbossing' => $this->product->activePrint->printedCover->hasBlindEmbossing ?? null,
            'hasUvSpotVarnishGlossy' => $this->product->activePrint->printedCover->hasUvSpotVarnishGlossy ?? null,
            'hasUvSpotVarnishMatt' => $this->product->activePrint->printedCover->hasUvSpotVarnishMatt ?? null,
            'hasDispersionVarnish' => $this->product->activePrint->printedCover->hasDispersionVarnish ?? null,
            'hasReliefSpotVarnish' => $this->product->activePrint->printedCover->hasReliefSpotVarnish ?? null,
            'placement' => $this->product->activePrint->printedCover->placement->name ?? null,
            'lamination' => $this->product->activePrint->printedCover->lamination->name ?? null,
        ]);

        // Dust jacket
        $technicalData->push([
            'partName' => 'dustJacket',
            'paperType' => $this->product->activePrint->jacket->paper->name ?? null,
            'paperName' => $this->product->activePrint->jacket->paperOther ?? null,
            'grammage' => (isset($this->product->activePrint->jacket->paperWeight)) ? intval($this->product->activePrint->jacket->paperWeight) : null,
            'colors' => (isset($this->product->activePrint->jacket->printing->name)) ? str_replace('+', '/', $this->product->activePrint->jacket->printing->name) : null,
            'colorNames' => $this->product->activePrint->jacket->colors ?? null,
            'foil' => $this->product->activePrint->jacket->foil ?? null,
            'hasBlindEmbossing' => $this->product->activePrint->jacket->hasBlindEmbossing ?? false,
            'hasUvSpotVarnishGlossy' => $this->product->activePrint->jacket->hasUvSpotVarnishGlossy ?? false,
            'hasUvSpotVarnishMatt' => $this->product->activePrint->jacket->hasUvSpotVarnishMatt ?? false,
            'hasDispersionVarnish' => $this->product->activePrint->jacket->hasDispersionVarnish ?? false,
            'hasReliefSpotVarnish' => $this->product->activePrint->jacket->hasReliefSpotVarnish ?? false,
            'placement' => $this->product->activePrint->jacket->placement->name ?? null,
            'lamination' => $this->product->activePrint->jacket->lamination->name ?? null,
        ]);

        // Soft cover
        $technicalData->push([
            'partName' => 'softCover',
            'paperType' => $this->product->activePrint->softCover->paper->name ?? null,
            'paperName' => $this->product->activePrint->softCover->paperOther ?? null,
            'grammage' => (isset($this->product->activePrint->softCover->paperWeight)) ? intval($this->product->activePrint->softCover->paperWeight) : null,
            'colors' => (isset($this->product->activePrint->softCover->printing->name)) ? str_replace('+', '/', $this->product->activePrint->softCover->printing->name) : null,
            'colorNames' => $this->product->activePrint->softCover->colors ?? null,
            'foil' => $this->product->activePrint->softCover->foil ?? null,
            'hasBlindEmbossing' => $this->product->activePrint->softCover->hasBlindEmbossing ?? false,
            'hasUvSpotVarnishGlossy' => $this->product->activePrint->softCover->hasUvSpotVarnishGlossy ?? false,
            'hasUvSpotVarnishMatt' => $this->product->activePrint->softCover->hasUvSpotVarnishMatt ?? false,
            'hasDispersionVarnish' => $this->product->activePrint->softCover->hasDispersionVarnish ?? false,
            'hasReliefSpotVarnish' => $this->product->activePrint->softCover->hasReliefSpotVarnish ?? false,
            'placement' => $this->product->activePrint->softCover->placement->name ?? null,
            'lamination' => $this->product->activePrint->softCover->lamination->name ?? null,
        ]);

        // End papers
        $technicalData->push([
            'partName' => 'endPapers',
            'paperType' => $this->product->activePrint->foePaper->name ?? null,
            'paperName' => $this->product->activePrint->foePaperOther ?? null,
            'grammage' => (isset($this->product->activePrint->foeWeight)) ? intval($this->product->activePrint->foeWeight) : null,
            'colors' => (isset($this->product->activePrint->foePrinting->name)) ? str_replace('+', '/', $this->product->activePrint->foePrinting->name) : null,
            'colorNames' => $this->product->activePrint->foeColors ?? null,
            'selfEnds' => $this->product->activePrint->foeIsPressed,
        ]);

        // End papers
        $technicalData->push([
            'partName' => 'bookBinding',
            'bindingType' => $this->product->activePrint->bookbindingBinding->name ?? null,
            'boardThickness' => (isset($this->product->activePrint->bookbindingBinderThickness)) ? floatval($this->product->activePrint->bookbindingBinderThickness->name) : null,
            'headBand' => $this->product->activePrint->bookbindingHeadband ?? null,
            'ribbonMarker' => $this->product->activePrint->bookbindingClampingBand ?? null,
            'spineType' => $this->product->activePrint->bookbindingSpineType->name ?? null,
            'spideWidth' => (isset($this->product->activePrint->definitiveSpineWidth)) ? intval($this->product->activePrint->definitiveSpineWidth) : null,
            'clothedSpineMaterial' => $this->product->activePrint->bookbindingMaterial ?? null,
            'comments' => $this->product->activePrint->bookbindingComment ?? null,
        ]);

        return $technicalData;
    }

    /**
     * Get the prizes that the product has received
     *
     * @return Collection
     */
    public function getPrizes()
    {
        $prizes = new Collection;

        // Won awards
        if (property_exists($this->product, 'awards')) {
            foreach ($this->product->awards as $award) {
                $prizes->push([
                    'PrizeName' => $award->name,
                    'PrizeCode' => '01',
                ]);
            }
        }

        // Nominations
        if (property_exists($this->product, 'nominations')) {
            foreach ($this->product->nominations as $nomination) {
                $prizes->push([
                    'PrizeName' => $nomination->name,
                    'PrizeCode' => '07',
                ]);
            }
        }

        return $prizes;
    }

    /**
     * Get products availability code
     *
     * @return string|null
     */
    public function getProductAvailability()
    {
        // Check that we don't have illogical combinations
        if (in_array($this->product->listingCode->name, ['Short run', 'Print On Demand']) && $this->isImmaterial()) {
            throw new Exception('Product has governing code that is not allowed for immaterial / digital products.');
        }

        // Governing codes where the available stock affects
        if (in_array($this->product->listingCode->name, ['Published', 'Short run'])) {
            // Check if the product has free stock
            $onHand = $this->getSuppliers()->pluck('OnHand')->first();
            $hasStock = (! empty($onHand) && $onHand > 0) ? true : false;

            if ($hasStock) {
                return '21';
            }

            // If product has no stock, check if we have stock arrival date in the future
            $tomorrow = new DateTime('tomorrow');
            $stockArrivalDate = $this->getLatestStockArrivalDate();

            return ($tomorrow > $stockArrivalDate) ? '31' : '32';
        }

        // On Print On Demand it depends if we are pass publication date
        if ($this->product->listingCode->name === 'Print On Demand') {
            if ($this->isPublicationDatePassed()) {
                return '23';
            } else {
                return '12';
            }
        }

        // Governing codes which are mapped directly where available stock does not affect
        switch ($this->product->listingCode->name) {

            case 'Sold out':
            case 'Permanently withdrawn from sale':
                return '40';
            case 'Cancelled':
                return '01';
            case 'Development':
                return '10';
            case 'Exclusive Sales':
                return '30';
            case 'Delivery block':
                return '34';
        }

        return null;

    }

    /**
     * Check if original publication date has passed
     *
     * @return bool
     */
    public function isPublicationDatePassed()
    {
        $publicationDate = $this->getOriginalPublicationDate() ?? $this->getLatestPublicationDate();
        $tomorrow = new DateTime('tomorrow');

        return $tomorrow > $publicationDate;
    }

    /**
     * Get the products suppliers
     *
     * @return Collection
     */
    public function getSuppliers()
    {
        $suppliers = new Collection;

        // Add fake supplier for digital products
        if ($this->isImmaterial()) {
            $suppliers->push([
                'SupplierRole' => '03',
                'SupplierIdentifiers' => [
                    [
                        'SupplierIDType' => '06',
                        'IDTypeName' => 'GLN',
                        'IDValue' => 6418616999993,
                    ],
                    [
                        'SupplierIDType' => '23',
                        'IDTypeName' => 'VAT Identity Number',
                        'IDValue' => 'FI01100310',
                    ],
                ],
                'SupplierName' => 'Storia',
                'TelephoneNumber' => '+358 10 345 1520',
                'EmailAddress' => 'tilaukset@storia.fi',
                'OnHand' => 100,
                'Proximity' => '07',
            ]);

            return $suppliers;
        }

        // Get stocks from API
        $client = new Client([
            'base_uri' => 'http://stocks.books.local/api/products/gtin/',
            'timeout' => 2.0,
        ]);

        try {
            $response = $client->request('GET', strval($this->productNumber));
            $json = json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $json = json_decode($response->getBody()->getContents());

            if ($json->data->error_code !== 404 && $json->data->error_message !== 'The model could not be found.') {
                throw new Exception('Could not fetch stock data for GTIN '.$this->productNumber);
            } else {
                // Add default supplier
                $supplierName = 'Storia';
                $telephoneNumber = '+358 10 345 1520';
                $emailAddress = 'tilaukset@storia.fi';

                // Storia identifiers
                $supplierIdentifiers = [
                    [
                        'SupplierIDType' => '06',
                        'IDTypeName' => 'GLN',
                        'IDValue' => 6418616999993,
                    ],
                    [
                        'SupplierIDType' => '23',
                        'IDTypeName' => 'VAT Identity Number',
                        'IDValue' => 'FI01100310',
                    ],
                ];

                $suppliers->push([
                    'SupplierRole' => '03',
                    'SupplierIdentifiers' => $supplierIdentifiers,
                    'SupplierName' => $supplierName,
                    'TelephoneNumber' => $telephoneNumber,
                    'EmailAddress' => $emailAddress,
                    'OnHand' => 0,
                    'Proximity' => '03',
                ]);

                return $suppliers;
            }
        }

        // Determine correct proximity value, 0-100 = Exactly and 101 - = More than
        if ($json->data->available_stock > 100) {
            $proximityValue = '07';
            $onHand = 100; // With on hand values more than 100, don't show exact value but > 100
        } else {
            $proximityValue = '03';
            $onHand = $json->data->available_stock;
        }

        // Add LocationIdentifiers
        $supplierIdentifiers = [];

        if (is_object($json->data->stock_location)) {
            // GLN number
            if (! empty($json->data->stock_location->gln)) {
                $supplierIdentifiers[] = [
                    'SupplierIDType' => '06',
                    'IDTypeName' => 'GLN',
                    'IDValue' => $json->data->stock_location->gln,
                ];
            }

            // VAT identity number
            if (! empty($json->data->stock_location->vat_identity_number)) {
                $supplierIdentifiers[] = [
                    'SupplierIDType' => '23',
                    'IDTypeName' => 'VAT Identity Number',
                    'IDValue' => $json->data->stock_location->vat_identity_number,
                ];
            }
        }

        $suppliers->push([
            'SupplierRole' => '03',
            'SupplierIdentifiers' => $supplierIdentifiers,
            'SupplierName' => $json->data->stock_location->name,
            'TelephoneNumber' => $json->data->stock_location->telephone_number,
            'EmailAddress' => $json->data->stock_location->email,
            'OnHand' => $onHand,
            'Proximity' => $proximityValue,
        ]);

        return $suppliers;
    }

    /**
     * Get the supply dates
     *
     * @return Collection
     */
    public function getSupplyDates()
    {
        $supplyDates = new Collection;

        // Latest reprint date
        $latestStockArrivalDate = $this->getLatestStockArrivalDate();

        if (! is_null($latestStockArrivalDate)) {
            $supplyDates->push(['SupplyDateRole' => '34', 'Date' => $latestStockArrivalDate->format('Ymd')]);
        }

        return $supplyDates;
    }

    /**
     * Get all contacts
     *
     * @return Collection
     */
    public function getContacts()
    {
        $contacts = new Collection;

        // Get the maximum amount
        $response = $this->searchClient->get('v3/search/contacts', [
            'query' => [
                'q' => '',
                'limit' => 1,
                '$select' => 'id',
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());

        $totalCount = $json->pagination->itemsTotalCount;

        // Loop through all pages, maximum is 1000
        $offset = 0;
        $limit = 1000;

        while ($offset <= $totalCount) {
            // Query current page with offset
            $response = $this->searchClient->get('v3/search/contacts', [
                'query' => [
                    '$select' => 'id,firstName,lastName,erpSupplierId',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);

            $json = json_decode($response->getBody()->getContents());

            foreach ($json->results as $result) {
                $contacts->push([
                    'id' => intval($result->document->id),
                    'firstName' => optional($result->document)->firstName,
                    'lastName' => optional($result->document)->lastName,
                    'supplierId' => (property_exists($result->document, 'erpSupplierId')) ? intval($result->document->erpSupplierId) : null,
                ]);
            }

            // Increase offset
            $offset += $limit;
        }

        return $contacts;
    }

    /**
     * Get all editions
     *
     * @return Collection
     */
    public function getEditions()
    {
        $editions = new Collection;

        // Get the maximum amount
        $response = $this->client->get('v2/search/productions', [
            'query' => [
                'limit' => 1,
                '$select' => 'isbn,title,publishingHouseName',
                '$filter' => '(isCancelled eq true or isCancelled eq false)',
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());
        $totalCount = $json->pagination->itemsTotalCount;

        // Loop through all pages, maximum is 1000
        $offset = 0;
        $limit = 1000;

        while ($offset <= $totalCount) {
            // Query current page with offset
            $response = $this->client->get('v2/search/productions', [
                'query' => [
                    '$select' => 'isbn,title,publishingHouseName',
                    '$filter' => '(isCancelled eq true or isCancelled eq false)',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);

            $json = json_decode($response->getBody()->getContents());

            foreach ($json->results as $result) {
                if (isset($result->document->isbn)) {
                    $editions->push([
                        'isbn' => intval($result->document->isbn),
                        'title' => optional($result->document)->title,
                        'publisher' => optional($result->document)->publishingHouseName,
                    ]);
                }
            }

            // Increase offset
            $offset += $limit;
        }

        return $editions;
    }

    /**
     * Get the first publication date of the products web page
     *
     * @return DateTime|null
     */
    public function getWebPublishingStartDate()
    {
        if (! isset($this->product->activeWebPeriod->startDate)) {
            return null;
        }

        return DateTime::createFromFormat('!Y-m-d', substr($this->product->activeWebPeriod->startDate, 0, 10));
    }

    /**
     * Get the end date for the products web page
     *
     * @return DateTime|null
     */
    public function getWebPublishingEndDate()
    {
        if (! isset($this->product->activeWebPeriod->endDate)) {
            return null;
        }

        return DateTime::createFromFormat('!Y-m-d', substr($this->product->activeWebPeriod->endDate, 0, 10));
    }

    /**
     * Get all comments
     *
     * @return Collection
     */
    public function getComments()
    {
        $comments = new Collection;

        // List of comments fields that we want to pick
        $commentFields = [
            'general' => 'comment',
            'insert/cover material' => 'miscMaterialInsertCover',
            'print order' => 'miscComment',
            'price' => 'miscPrice',
            'rights' => 'rightsComment',
        ];

        foreach ($commentFields as $name => $field) {
            if (isset($this->product->{$field}) || isset($this->product->activePrint->{$field})) {
                $comments->push([
                    'type' => $name,
                    'comment' => (isset($this->product->{$field})) ? $this->product->{$field} : $this->product->activePrint->{$field},
                ]);
            }
        }

        return $comments;
    }

    /**
     * Get products sales status
     *
     * @return string|null
     */
    public function getSalesStatus()
    {
        if (! isset($this->product->salesStatus)) {
            return null;
        }

        return $this->product->salesStatus->name;
    }

    /**
     * Get the products main editions ISBN
     *
     * @return int|null
     */
    public function getMainEditionIsbn()
    {
        foreach ($this->getWorkLevel()->productions as $production) {
            if ($production->isMainEdition === true && ! empty($production->isbn)) {
                return intval($production->isbn);
            }
        }

        return null;
    }

    /**
     * Get the products main editions cost center
     *
     * @return int|null
     */
    public function getMainEditionCostCenter()
    {
        if (isset($this->getWorkLevel()->costCenter->id)) {
            return intval($this->getWorkLevel()->costCenter->id);
        }

        return null;
    }

    /**
     * Check if the product is translated or not
     *
     * @return bool
     */
    public function isTranslated()
    {
        // Check if main group contains "Käännetty" / "Translated" or contains contributor with translator role
        $mainGroup = $this->getSubjects()->where('SubjectSchemeIdentifier', '23')->where('SubjectSchemeName', 'Werner Söderström Ltd - Main product group')->pluck('SubjectHeadingText')->first();

        if (Str::contains($mainGroup, 'Käännetty') || $this->getContributors()->contains('ContributorRole', 'B06')) {
            return true;
        }

        return false;
    }

    /**
     * Return list of Thema codes
     *
     * @return Collection
     */
    public function getThemaCodes()
    {
        $themaCodes = new Collection;

        // Get Thema codes from work level
        $response = $this->client->get('/v1/works/'.$this->workId.'/themas');
        $contents = json_decode($response->getBody()->getContents());

        foreach ($contents as $themaCode) {
            // Map themaCodeType name to Onix codelist subject scheme identifier
            switch ($themaCode->themaCodeType->name) {
                case 'Primary':
                    $subjectSchemeIdentifier = 93;
                    $subjectSchemeName = 'Thema subject category';
                    break;
                case 'Place qualifier':
                    $subjectSchemeIdentifier = 94;
                    $subjectSchemeName = 'Thema place qualifier';
                    break;
                case 'Language qualifier':
                    $subjectSchemeIdentifier = 95;
                    $subjectSchemeName = 'Thema language qualifier';
                    break;
                case 'Time period qualifier':
                    $subjectSchemeIdentifier = 96;
                    $subjectSchemeName = 'Thema time period qualifier';
                    break;
                case 'Educational purpose qualifier':
                    $subjectSchemeIdentifier = 97;
                    $subjectSchemeName = 'Thema educational purpose qualifier';
                    break;
                case 'Interest qualifier':
                    $subjectSchemeIdentifier = 98;
                    $subjectSchemeName = 'Thema interest age / special interest qualifier';
                    break;
                case 'Style qualifier':
                    $subjectSchemeIdentifier = 99;
                    $subjectSchemeName = 'Thema style qualifier';
                    break;
                default:
                    $subjectSchemeIdentifier = null;
                    $subjectSchemeName = null;
                    break;
            }

            $themaCodes->push([
                'codeValue' => $themaCode->themaCodeValue,
                'subjectSchemeIdentifier' => strval($subjectSchemeIdentifier),
                'subjectSchemeName' => $subjectSchemeName,
                'sortOrder' => $themaCode->sortOrder,
            ]);
        }

        // Sort the codes by identifier and sort order from Mockingbird
        $themaCodes = $themaCodes->sortBy(function ($themaCode) {
            return $themaCode['subjectSchemeIdentifier'].'-'.$themaCode['sortOrder'];
        });

        return $themaCodes;
    }

    /**
     * Get all EditionTypes
     *
     * @return Collection
     */
    public function getEditionTypes()
    {
        $editionTypes = new Collection;

        // Check if title contains information about edition type
        $title = $this->getTitleDetails()->where('TitleType', '01')->pluck('TitleElement.TitleText')->first();

        // Illustrated
        if (Str::contains($title, 'kuvitettu')) {
            $editionTypes->push(['EditionType' => 'ILL']);
        }

        // Movie tie-in
        if (Str::contains($title, 'leffakansi')) {
            $editionTypes->push(['EditionType' => 'MDT']);
        }

        // Selkokirja is determined from Thema interest age codes
        $hasSelkoKirjaAgeGroups = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->contains(function ($subject, $key) {
            return in_array($subject['SubjectCode'], ['5AZ']);
        });

        if ($hasSelkoKirjaAgeGroups) {
            $editionTypes->push(['EditionType' => 'SMP']);
        }

        // Easy-to-read is determined from Thema interest age codes
        $hasEasyToReadAgeGroups = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->contains(function ($subject, $key) {
            return in_array($subject['SubjectCode'], ['5AR', '5AX']);
        });

        if ($hasEasyToReadAgeGroups) {
            $editionTypes->push(['EditionType' => 'ETR']);
        }

        // ePub 3 with extra audio
        if ($this->getProductType() === 'ePub3' && (bool) $this->product->activePrint->ebookHasAudioFile === true) {
            $editionTypes->push(['EditionType' => 'ENH']);
        }

        return $editionTypes->unique('EditionType');
    }

    /**
     * Get the activities for the work
     *
     * @return mixed
     */
    public function getActivities()
    {
        // Get the activities from Mockingbird
        try {
            $response = $this->client->get('/v1/works/'.$this->workId.'/activities');
        } catch (ServerException $e) {
            throw new Exception('Server exception: '.$e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get all Events
     *
     * @return Collection
     */
    public function getEvents()
    {
        $events = new Collection;

        $activities = $this->getActivities();

        if (is_object($activities) && property_exists($activities, 'activities') && is_array($activities->activities)) {
            foreach ($activities->activities as $activity) {
                if ($activity->sharingLevel->name === 'Public') {
                    $events->push([
                        'EventRole' => '31',
                        'EventName' => $activity->name,
                        'EventDate' => DateTime::createFromFormat('!Y-m-d', substr($activity->activityStartDate, 0, 10))->format('Ymd'),
                    ]);
                }
            }
        }

        return $events;
    }

    /**
     * Get the retail price multipler
     *
     * @return float
     */
    public function getRetailPriceMultiplier()
    {
        // Manga and pocket books
        if ($this->getCostCenter() === 965 || $this->getProductType() === 'Pocket book') {
            return 1.64;
        }

        // Immaterial
        if ($this->isImmaterial()) {
            return 1.43;
        }

        return 1.2;
    }

    /**
     * Get all ProductContentTypes
     *
     * @return Collection
     */
    public function getProductContentTypes()
    {
        $contentTypes = new Collection;

        // Audio-only binding codes
        $audioOnly = [
            'Pre-recorded digital - No test case exists',
            'Downloadable audio file',
            'CD',
            'MP3-CD',
            'Other audio format',
            'Podcast',
        ];

        if (in_array($this->getProductType(), $audioOnly)) {
            $contentTypes->push([
                'ContentType' => '01',
                'Primary' => true,
            ]);

            return $contentTypes;
        }

        // Picture-and-audio book or ePub 3 with or without audio (technical binding type == ePub3 and "Contains audio" is checked)
        if ($this->getProductType() === 'Picture-and-audio book') {
            $contentTypes->push([
                'ContentType' => '10',
                'Primary' => true,
            ]);

            $contentTypes->push([
                'ContentType' => '01',
                'Primary' => false,
            ]);

            return $contentTypes;
        }

        // eBook 3s with or without audio
        if ($this->getProductType() === 'ePub3') {
            // Add audio book as a secondary content type if ePub 3 contains audio
            if ((bool) $this->product->activePrint->ebookHasAudioFile === true) {
                $contentTypes->push([
                    'ContentType' => '01',
                    'Primary' => false,
                ]);
            }

            // Add Primary content type
            if (property_exists($this->product->productionDetails, 'primaryContentTypeId') && ! empty($this->product->productionDetails->primaryContentTypeId)) {
                $primaryContentTypesMapping = $this->getProductionDetailsOptions('primaryContentTypes');

                $onixCode = $primaryContentTypesMapping->where('id', $this->product->productionDetails->primaryContentTypeId)->pluck('onixCode')->first();

                if (is_null($onixCode)) {
                    throw new Exception('Could not find mapping for content type with id '.$this->product->productionDetails->primaryContentTypeId);
                }

                $contentTypes->push([
                    'ContentType' => $onixCode,
                    'Primary' => true,
                ]);
            }

            // Check if we have spesific content types
            if (property_exists($this->product->productionDetails, 'contentTypeIds') && is_array($this->product->productionDetails->contentTypeIds) && ! empty($this->product->productionDetails->contentTypeIds)) {
                // Get content types for mapping
                $contentTypesMapping = $this->getProductionDetailsOptions('contentTypes');

                foreach ($this->product->productionDetails->contentTypeIds as $contentTypeId) {
                    $onixCode = $contentTypesMapping->where('id', $contentTypeId)->pluck('onixCode')->first();

                    if (is_null($onixCode)) {
                        throw new Exception('Could not find mapping for content type with id '.$contentTypeId);
                    }

                    $contentTypes->push([
                        'ContentType' => $onixCode,
                        'Primary' => false,
                    ]);
                }
            } else {
                // Fallback to text only
                $contentTypes->push([
                    'ContentType' => '10',
                    'Primary' => true,
                ]);
            }

            return $contentTypes;
        }

        // Kit, Miscellaneous, Application, Marketing material should not return anything
        $undetermined = [
            'Kit',
            'Miscellaneous',
            'Application',
            'Marketing material',
        ];

        if (in_array($this->getProductType(), $undetermined)) {
            return $contentTypes;
        }

        // For everything else add "Text"
        $contentTypes->push([
            'ContentType' => '10',
            'Primary' => true,
        ]);

        return $contentTypes;
    }

    /**
     * Get the products trade category
     *
     * @return string|null
     */
    public function getTradeCategory()
    {
        switch ($this->getProductType()) {
            case 'Pocket book':
                return '04';
            case 'Podcast':
                return '17';
        }

        return null;
    }

    /**
     * Get the NotificationType
     *
     * @return string
     */
    public function getNotificationType()
    {
        // Product that has the status "Exclusive sales" should return 01 - Early notification from Codelist 1
        if ($this->getStatus() === 'Exclusive Sales') {
            return '01';
        }

        // Product that has the status "Permanently withdrawn from sales" should return 01 - Early notification from Codelist 1
        if ($this->getStatus() === 'Permanently withdrawn from sale') {
            return '05';
        }

        // Use OriginalPublishingDate if given, other fallback to PublishingDate
        $dateRole = ($this->getPublishingDates()->contains('PublishingDateRole', '01')) ? '01' : '12';

        // Convert to DateTime
        $publicationDate = DateTime::createFromFormat('Ymd', $this->getPublishingDates()->where('PublishingDateRole', $dateRole)->pluck('Date')->first());

        // Create new current date
        $currentDate = new DateTime;

        // If publication date is in the future, use Advance notification (before publication)
        // Otherwise use 'Notification confirmed on publication'
        if ($publicationDate > $currentDate) {
            $value = '02';
        } else {
            $value = '03';
        }

        return $value;
    }

    /**
     * Getting the country of manufacture. Returns two letter ISO 3166-1 code.
     *
     * @return string|null
     */
    public function getCountryOfManufacture()
    {
        // Check if the product contains Printer role or is digital
        if ($this->isImmaterial() || $this->getAllContributors()->contains('Role', 'Printer') === false) {
            return null;
        }

        // Get the printer contact

        $printer = $this->getAllContributors()->where('Role', 'Printer')->reject(function (array $contributor, int $key) {
            return Str::contains($contributor['FirstName'], 'Yhteispainatus') || Str::contains($contributor['LastName'], 'Yhteispainatus');
        })->first();

        if (is_null($printer)) {
            return null;
        }

        $response = $this->searchClient->get('v2/contacts/'.$printer['Id']);
        $contact = json_decode($response->getBody()->getContents());

        foreach ($contact->addresses as $address) {
            if (property_exists($address, 'country')) {
                try {
                    $iso3166 = (new ISO3166)->name($address->country);

                    return $iso3166['alpha2'];
                } catch (\League\ISO3166\Exception\OutOfBoundsException $e) {
                    throw new Exception('Cannot find ISO-3166 code for country named '.$address->country);
                }
            }
        }

        return null;
    }

    /**
     * Get list of product contacts
     *
     * @return Collection
     */
    public function getProductContacts()
    {
        $productContacts = new Collection;

        if (in_array($this->getProductType(), ['ePub2', 'ePub3'])) {
            $productContacts->push([
                'ProductContactRole' => '01',
                'ProductContactName' => 'Werner Söderström Ltd',
                'ProductContactEmail' => $this->getAccessibilityEmail(),
            ]);
        }

        return $productContacts;
    }

    /**
     * Get the accessibility email
     *
     * @return string
     */
    public function getAccessibilityEmail()
    {
        // Email is per publisher
        $publisherAccessibilityEmail = [
            'Bazar' => 'saavutettavuus@bazarkustannus.fi',
            'CrimeTime' => 'saavutettavuus@crime.fi',
            'Docendo' => 'saavutettavuus@docendo.fi',
            'Johnny Kniga' => 'saavutettavuus@johnnykniga.fi',
            'Kosmos' => 'saavutettavuus@kosmoskirjat.fi',
            'Minerva' => 'saavutettavuus@docendo.fi',
            'Readme.fi' => 'saavutettavuus@readme.fi',
            'Tammi' => 'saavutettavuus@tammi.fi',
            'WSOY' => 'saavutettavuus@wsoy.fi',
        ];

        if (in_array($this->getPublisher(), array_keys($publisherAccessibilityEmail)) === false) {
            throw new Exception('Publisher '.$this->getPublisher().' does not have accessibility email mapping defined.');
        }

        return $publisherAccessibilityEmail[$this->getPublisher()];
    }

    /**
     * Get the sales rights territories
     *
     * @return Collection
     */
    public function getSalesRightsTerritories()
    {
        $territories = new Collection;

        if (empty($this->product->countriesIncluded) && empty($this->product->countriesExcluded)) {
            $territories->push(['RegionsIncluded' => 'WORLD']);

            return $territories;
        }

        // Add included countries
        if (is_array($this->product->countriesIncluded) && count($this->product->countriesIncluded) > 0) {
            $countriesIncluded = [];

            foreach ($this->product->countriesIncluded as $includedCountry) {
                array_push($countriesIncluded, $includedCountry->id);
            }

            $territories->push(['CountriesIncluded' => implode(' ', $countriesIncluded)]);
        }

        // Add excluded countries
        if (property_exists($this->product, 'countriesExcluded') && is_array($this->product->countriesExcluded) && count($this->product->countriesExcluded) > 0) {
            $countriesExcluded = [];

            foreach ($this->product->countriesExcluded as $excludedCountry) {
                array_push($countriesExcluded, $excludedCountry->id);
            }

            $territories->push(['CountriesExcluded' => implode(' ', $countriesExcluded)]);
        }

        return $territories;
    }

    /**
     * Search for editions
     *
     * @param  string  $query
     * @param  string  $filter
     * @return Collection
     */
    public function searchEditions($query = '', $filter = null)
    {
        // Get the number of pages
        $response = $this->client->get('v2/search/productions', [
            'query' => [
                'q' => $query,
                'limit' => 1000,
                '$filter' => $filter,
                '$select' => 'workId,id,isbn',
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());

        // Collection to hold all editions
        $editions = new Collection;

        // In case of just one page, we don't have to do pagination
        if ($json->pagination->pagesTotalCount === 1) {
            foreach ($json->results as $result) {
                $editions->push([
                    'workId' => $result->document->workId,
                    'editionId' => $result->document->id,
                    'isbn' => $result->document->isbn,
                ]);
            }

            return $editions;
        }

        // List pages
        $pages = intval($json->pagination->pagesTotalCount + 1);

        for ($page = 1; $page <= $pages; $page++) {
            $offset = ($page === 1) ? 0 : 1000 * ($page - 1);

            // Get page
            $response = $this->client->get('v2/search/productions', [
                'query' => [
                    'q' => $query,
                    'limit' => 1000,
                    '$filter' => $filter,
                    'offset' => $offset,
                    '$select' => 'workId,id,isbn,interestAgeName',
                ],
            ]);

            $json = json_decode($response->getBody()->getContents());

            foreach ($json->results as $result) {
                $editions->push([
                    'workId' => $result->document->workId,
                    'editionId' => $result->document->id,
                    'isbn' => $result->document->isbn ?? null,
                ]);
            }
        }

        return $editions;
    }

    /**
     * Get the products names as subjects
     *
     * @return Collection
     */
    public function getNamesAsSubjects()
    {
        $namesAsSubjects = new Collection;

        if (isset($this->product->bibliographicCharacters) && ! empty($this->product->bibliographicCharacters)) {
            $bibliographicCharacters = explode(';', $this->product->bibliographicCharacters);
            $bibliographicCharacters = array_map('trim', $bibliographicCharacters);

            foreach ($bibliographicCharacters as $bibliographicCharacter) {
                $lastname = Str::afterLast($bibliographicCharacter, ' ');
                $firstnames = Str::beforeLast($bibliographicCharacter, ' ');

                // Add to collection
                if ($lastname !== $bibliographicCharacter && $firstnames !== $bibliographicCharacter) {
                    $namesAsSubjects->push([
                        'NameType' => '00',
                        'PersonName' => $bibliographicCharacter,
                        'PersonNameInverted' => $lastname.', '.$firstnames,
                        'KeyNames' => $lastname,
                        'NamesBeforeKey' => $firstnames,
                    ]);
                } else {
                    $namesAsSubjects->push([
                        'NameType' => '00',
                        'PersonName' => $bibliographicCharacter,
                        'PersonNameInverted' => $lastname,
                        'KeyNames' => $lastname,
                    ]);
                }

            }
        }

        return $namesAsSubjects;
    }

    /**
     * Get the pocket book price group
     *
     * @return string|null
     */
    public function getPocketBookPriceGroup()
    {
        if (isset($this->product->priceGroupPocket) && ! empty($this->product->priceGroupPocket)) {
            return $this->product->priceGroupPocket->name;
        }

        return null;
    }

    /**
     * Get the target persona(s)
     *
     * @return Collection
     */
    public function getTargetPersonas()
    {
        $targetPersonas = new Collection;

        $communicationPlan = $this->getCommunicationPlan();

        if (isset($communicationPlan->targetGroups) && is_array($communicationPlan->targetGroups)) {
            foreach ($communicationPlan->targetGroups as $targetGroup) {
                $targetPersonas->push($targetGroup->name);
            }
        }

        return $targetPersonas;
    }

    /**
     * Return calculated publisher retail price incl. VAT
     *
     * @return float
     */
    public function getCalculatedPublisherRetailPrice()
    {
        if ($this->getPrice() === 0.0) {
            return 0.0;
        }

        $price = $this->getPrice() * $this->getRetailPriceMultiplier();

        // Rounding for pocket books, manga and digital products is up to nearest 10 cents
        if ($this->getCostCenter() === 965 || $this->getProductType() === 'Pocket book' || $this->isImmaterial()) {
            return ceil($price * 10) / 10;
        }

        // All others to nearest 90 cents
        $fraction = $price - floor($price);

        if ($fraction > 0.9) {
            $price++;
        }

        return floor($price) + 0.9;
    }

    /**
     * Get the editions planning code
     *
     * @return string|null
     */
    public function getPlanningCode()
    {
        if (isset($this->product->dispositionCode) && ! empty($this->product->dispositionCode->name)) {
            return $this->product->dispositionCode->name;
        }

        return null;
    }

    /**
     * Get production details options
     *
     * @return Collection
     */
    public function getProductionDetailsOptions($option)
    {
        // Get the production detail options
        $response = $this->client->get('/v1/settings/productiondetailoptions');
        $json = json_decode($response->getBody()->getContents(), true);

        if (array_key_exists($option, $json) === false) {
            throw new Exception('The given option '.$option.'does not exist in production detail options');
        }

        return collect($json[$option]);
    }

    /**
     * Get the products schema hazards
     *
     * @return Collection
     */
    public function getSchemaHazards()
    {
        $schemaHazards = new Collection;

        // Hazards are listed as ProductFormFeatureType 12 from Codelist 143
        // See: https://ns.editeur.org/onix/en/143
        $hazardMappingTable = [
            '00' => 'none',
            '13' => 'flashing',
            '14' => 'noFlashingHazard',
            '15' => 'sound',
            '16' => 'noSoundHazard',
            '17' => 'motionSimulation',
            '18' => 'noMotionSimulationHazard',
            '24' => 'unknownFlashingHazard',
            '25' => 'unknownSoundHazard',
            '26' => 'unknownMotionSimulationHazard',
        ];

        foreach ($this->getProductFormFeatures()->where('ProductFormFeatureType', 12) as $productFormFeature) {
            if (array_key_exists($productFormFeature['ProductFormFeatureValue'], $hazardMappingTable)) {
                $schemaHazards->push($hazardMappingTable[$productFormFeature['ProductFormFeatureValue']]);
            }
        }

        // If exactly three hazards and all are "no", just return "none"
        if ($schemaHazards->count() === 3 && $schemaHazards->contains('noFlashingHazard') && $schemaHazards->contains('noSoundHazard') && $schemaHazards->contains('noMotionSimulationHazard')) {
            $schemaHazards = collect(['none']);
        }

        // If no hazards are defined, return 'unknown'
        if ($schemaHazards->count() === 0) {
            $schemaHazards->push('unknown');
        }

        return $schemaHazards;
    }

    /**
     * Get the products schema access modes
     *
     * @return Collection
     */
    public function getSchemaAccessModes()
    {
        $schemaAccessModes = new Collection;

        // auditory
        $auditoryProductContentTypes = [
            '01', // Audiobook
            '22', // Additional audio content not part of main content
            '13', // Other speech content
            '03', // Music recording
            '04', // Other audio
            '21', // Partial performance – spoken word
            '23', // Promotional audio for other book product
        ];

        if ($this->getProductContentTypes()->whereIn('ContentType', $auditoryProductContentTypes)->count() > 0) {
            $schemaAccessModes->push('auditory');
        }

        // chartOnVisual and/or diagramOnVisual
        if ($this->getProductContentTypes()->contains('ContentType', '19')) {
            $schemaAccessModes->push('chartOnVisual');
            $schemaAccessModes->push('diagramOnVisual');
        }

        // chemOnVisual
        if ($this->getProductContentTypes()->contains('ContentType', '47')) {
            $schemaAccessModes->push('chemOnVisual');
        }

        // mathOnVisual
        if ($this->getProductContentTypes()->contains('ContentType', '48')) {
            $schemaAccessModes->push('mathOnVisual');
        }

        // musicOnVisual
        if ($this->getProductContentTypes()->contains('ContentType', '11')) {
            $schemaAccessModes->push('musicOnVisual');
        }

        // textOnVisual
        if ($this->getProductContentTypes()->contains('ContentType', '49')) {
            $schemaAccessModes->push('textOnVisual');
        }

        // textual
        if ($this->getProductContentTypes()->contains('ContentType', '10') || $this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '52')->count() === 1) {
            $schemaAccessModes->push('textual');
        }

        // visual
        $visualProductContentTypes = [
            '07', // Still images / graphics, or
            '18', // Photographs, or
            '19', // Figures, diagrams, charts, graphs, or
            '20', // Additional images / graphics not part of main work, or
            '12', // Maps and/or other cartographic content, or
            '46', // Decorative images or graphics, or
            '50', // Video content without audio, or
            '24', // Animated / interactive illustrations
        ];

        if ($this->getProductContentTypes()->whereIn('ContentType', $visualProductContentTypes)->count() > 0) {
            $schemaAccessModes->push('visual');
        }

        return $schemaAccessModes;
    }

    /**
     * Get the products schema access modes
     *
     * @return Collection
     */
    public function getSchemaAccessModeSufficients()
    {
        return $this->getSchemaAccessModes();
    }

    /**
     * Get the products schema accessibility features
     *
     * @return Collection
     */
    public function getSchemaAccessibilityFeatures()
    {
        $schemaAccessibilityFeatures = new Collection;

        // alternativeText
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '14')->count() === 1) {
            $schemaAccessibilityFeatures->push('alternativeText');
        }

        // annotations
        if ($this->getEditionTypes()->contains('EditionType', 'ANN')) {
            $schemaAccessibilityFeatures->push('annotations');
        }

        // ARIA
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '30')->count() === 1) {
            $schemaAccessibilityFeatures->push('ARIA');
        }

        // audioDescription
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '28')->count() === 1) {
            $schemaAccessibilityFeatures->push('audioDescription');
        }

        // braille
        if ($this->getEditionTypes()->contains('EditionType', 'BRL') || $this->getProductFormDetails()->contains('E146')) {
            $schemaAccessibilityFeatures->push('braille');
        }

        // ChemML
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '18')->count() === 1) {
            $schemaAccessibilityFeatures->push('ChemML');
        }

        // closedCaptions
        if ($this->getEditionTypes()->contains('EditionType', 'V210')) {
            $schemaAccessibilityFeatures->push('closedCaptions');
        }

        // describedMath
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '14')->count() === 1 && $this->getProductContentTypes()->contains('ContentType', '48')) {
            $schemaAccessibilityFeatures->push('describedMath');
        }

        // displayTransformability
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '36')->count() === 1) {
            $schemaAccessibilityFeatures->push('displayTransformability');
        }

        // highContrastAudio
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '27')->count() === 1) {
            $schemaAccessibilityFeatures->push('highContrastAudio');
        }

        // highContrastDisplay
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '26')->count() === 1 || $this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '37')->count() === 1) {
            $schemaAccessibilityFeatures->push('highContrastDisplay');
        }

        // index
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '12')->count() === 1) {
            $schemaAccessibilityFeatures->push('index');
        }

        // largePrint
        if ($this->getEditionTypes()->contains('EditionType', 'LTE') || $this->getEditionTypes()->contains('EditionType', 'ULP')) {
            $schemaAccessibilityFeatures->push('largePrint');
        }

        // latex
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '35')->count() === 1) {
            $schemaAccessibilityFeatures->push('latex');
        }

        // longDescription
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->whereIn('ProductFormFeatureValue', ['15', '16'])->count() > 0) {
            $schemaAccessibilityFeatures->push('longDescription');
        }

        // MathML
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '17')->count() === 1) {
            $schemaAccessibilityFeatures->push('MathML');
        }

        // MathML-chemistry
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '34')->count() === 1) {
            $schemaAccessibilityFeatures->push('MathML-chemistry');
        }

        // none
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '09')->count() === 1) {
            $schemaAccessibilityFeatures->push('none');
        }

        // openCaptions
        if ($this->getProductFormDetails()->contains('V211')) {
            $schemaAccessibilityFeatures->push('openCaptions');
        }

        // pageBreakMarkers
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '19')->count() === 1) {
            $schemaAccessibilityFeatures->push('pageBreakMarkers');
        }

        // readingOrder
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '13')->count() === 1) {
            $schemaAccessibilityFeatures->push('readingOrder');
        }

        // signLanguage
        if ($this->getProductFormDetails()->contains('V213')) {
            $schemaAccessibilityFeatures->push('signLanguage');
        }

        // structuralNavigation
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '29')->count() === 1) {
            $schemaAccessibilityFeatures->push('structuralNavigation');
        }

        // sychronizedAudioText
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '20')->count() === 1) {
            $schemaAccessibilityFeatures->push('sychronizedAudioText');
        }

        // tableOfContents
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '11')->count() === 1) {
            $schemaAccessibilityFeatures->push('tableOfContents');
        }

        // taggedPDF
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->whereIn('ProductFormFeatureValue', ['05', '06'])->count() > 0) {
            $schemaAccessibilityFeatures->push('taggedPDF');
        }

        // transcript
        if ($this->getProductFormDetails()->contains('V212')) {
            $schemaAccessibilityFeatures->push('transcript');
        }

        // ttsMarkup
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '21')->count() === 1 && $this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '22')->count() === 1) {
            $schemaAccessibilityFeatures->push('ttsMarkup');
        }

        // unknown
        if ($this->getProductFormFeatures()->where('ProductFormFeatureType', '09')->where('ProductFormFeatureValue', '08')->count() > 0) {
            $schemaAccessibilityFeatures->push('unknown');
        }

        // unlocked not implemented because watermarking

        return $schemaAccessibilityFeatures->unique();
    }

    /**
     * Tries to map Thema codes to BISAC codes. Based on the "Mapping from BISAC 2021 to Thema v1.5 codes"
     * https://www.editeur.org/files/Thema/20220706_Public_BISAC%202021%20to%20Thema%201.5%20Mapping.xlsx
     *
     * @param  array  $themaCodes
     * @return string|null
     */
    public function getBisacCode($themaCodes)
    {
        if (empty($themaCodes)) {
            return null;
        }

        $mapping = [
            'WC' => 'ANT000000',
            'WC,KJSA' => 'ANT056000',
            'WC,1KBB' => 'ANT001000',
            'WCU' => 'ANT034000',
            'WCS' => 'ANT033000',
            'WCNG' => 'ANT018000',
            'WCRB' => 'ANT007000',
            'WC,1KBC' => 'ANT054000',
            'WCC' => 'ANT008000',
            'WCJ' => 'ANT010000',
            'WCF' => 'ANT011000',
            'WCS,XR' => 'ANT012000',
            'WCW' => 'ANT050000',
            'WCN' => 'ANT022000',
            'WCK' => 'ANT024000',
            'WCL' => 'ANT017000',
            'WCP' => 'ANT021000',
            'WC,AT' => 'ANT025000',
            'WC,JP' => 'ANT031000',
            'WC,JBCC1' => 'ANT052000',
            'WCNC' => 'ANT035000',
            'WCX' => 'ANT036000',
            'WC,AVD' => 'ANT037000',
            'WC,GBC' => 'ANT038000',
            'WCV' => 'ANT040000',
            'WCR' => 'ANT041000',
            'WCT' => 'ANT043000',
            'WCT,WCS' => 'ANT042000',
            'WCT,WCS,SFC' => 'ANT042010',
            'WCG' => 'ANT044000',
            'WCV,WCVB' => 'ANT047000',
            'WC,WBZ' => 'ANT055000',
            'WC,WG' => 'ANT009000',
            'WC,WBXD1' => 'ANT051000',
            'AM' => 'ARC020000',
            'AMC,ABC' => 'ARC022000',
            'AM,GBCY' => 'ARC023000',
            'AMG' => 'ARC011000',
            'AMN' => 'ARC016000',
            'AMK' => 'ARC003000',
            'AMD,TNK' => 'ARC019000',
            'AMA' => 'ARC001000',
            'AMCD' => 'ARC002000',
            'AMC,AMD' => 'ARC004000',
            'AM,TNKX' => 'ARC014000',
            'AM,TNKX,ABC' => 'ARC014010',
            'AMX' => 'ARC005000',
            'AMX,3B,6PJ' => 'ARC005010',
            'AMX,1QBA,3C,6CA' => 'ARC005020',
            'AMX,3KH,3KL,6MB' => 'ARC005030',
            'AMX,NHDL,3KLY,6RC' => 'ARC005040',
            'AMX,6BA,6RD' => 'ARC005050',
            'AMX,6RA' => 'ARC005060',
            'AMX,3MNQ,3MPB,6MC' => 'ARC005070',
            'AMX,3MPQ,3MRB' => 'ARC005080',
            'AMB' => 'ARC006020',
            'AMR' => 'ARC007000',
            'AMR,TNKH' => 'ARC007010',
            'AMV' => 'ARC008000',
            'AMC,AMCM' => 'ARC009000',
            'AMD' => 'ARC015000',
            'AMD,KJMP' => 'ARC017000',
            'AM,GBC' => 'ARC012000',
            'AMC,TNKS' => 'ARC021000',
            'AM,JNU' => 'ARC013000',
            'AMCR,RNU' => 'ARC018000',
            'AMVD' => 'ARC010000',
            'AMK,6VE' => 'ARC025000',
            'AB' => 'ART037000',
            'AGA,NHH,1H' => 'ART015010',
            'AGA,1KBB' => 'ART015020',
            'AGA,1KBB,5PB-US-C' => 'ART038000',
            'AGA,1KBB,5PB-US-D' => 'ART039000',
            'AGA,1KBB,5PB-US-H,6LC' => 'ART040000',
            'AB,GBCY' => 'ART054000',
            'AGA,NHF,1F' => 'ART019000',
            'AGA,NHF,1FPC' => 'ART019010',
            'AGA,NHF,1FK' => 'ART019020',
            'AGA,NHF,1FPJ' => 'ART019030',
            'AGA,NHM,1M' => 'ART042000',
            'AFJY' => 'ART055000',
            'ABQ' => 'ART043000',
            'AGA,NHK,1KBC' => 'ART015040',
            'AGA,NHK,1KJ,1KL' => 'ART044000',
            'AFP' => 'ART061000',
            'AGC' => 'ART006020',
            'AGZC' => 'ART051000',
            'AGA,6CK' => 'ART008000',
            'ABC' => 'ART056000',
            'ABA' => 'ART009000',
            'AFKV' => 'ART057000',
            'AFKN' => 'ART063000',
            'AGA,NHD,1D' => 'ART015030',
            'AGA,6FD,6ND' => 'ART013000',
            'ABK' => 'ART067000',
            'AGTS,6UB' => 'ART058000',
            'AGA' => 'ART015000',
            'AGA,3B,6PJ' => 'ART015050',
            'AGA,1QBA,3C,6CA' => 'ART015060',
            'AGA,3KH,3KL,6MB' => 'ART015070',
            'AGA,NHDL,3KLY,6RC' => 'ART015080',
            'AGA,6BA,6RD' => 'ART015090',
            'AGA,6RA' => 'ART015120',
            'AGA,3MNQ,3MPB,6MC' => 'ART015100',
            'AGA,3MPQ,3MRB' => 'ART015110',
            'AGA,1K,5PBA' => 'ART041000',
            'AGB' => 'ART016030',
            'AGB,5PS' => 'ART066000',
            'AGA,1FB' => 'ART047000',
            'AFJ' => 'ART017000',
            'GLZ' => 'ART059000',
            'AFKP' => 'ART060000',
            'AGA,JBCC1' => 'ART023000',
            'AFH' => 'ART048000',
            'AGT' => 'ART062000',
            'AB,GBC' => 'ART025000',
            'AGA,1DTA,1QBDR' => 'ART049000',
            'AFKB,AFKN' => 'ART026000',
            'AB,JNU' => 'ART027000',
            'AG' => 'ART050000',
            'AGHX,5X' => 'ART050050',
            'AGH' => 'ART050010',
            'AGNL' => 'ART050020',
            'AGN' => 'ART050030',
            'AGHF' => 'ART050040',
            'AGR,5PG' => 'ART035000',
            'AG,6FG' => 'ART050060',
            'AGZ' => 'ART028000',
            'AFCL,AGZ' => 'ART018000',
            'AFC,AGZ' => 'ART020000',
            'WFU,AGZ' => 'ART003000',
            'AKLC,AGZ' => 'ART004000',
            'AFF,AGZ' => 'ART010000',
            'AFF,AGZ,AGH' => 'ART052000',
            'AFFC,AGZ' => 'ART034000',
            'AFFK,AGZ' => 'ART033000',
            'AFH,AGZ' => 'ART024000',
            'AFKB,AGZ' => 'ART053000',
            'AFCC,AGZ' => 'ART029000',
            'AKLF,AFKV' => 'ART064000',
            'AGB,JBSF1' => 'ART065000',
            'QRMF1' => 'BIB020060',
            'QRMF1,YNRX' => 'BIB020070',
            'QRMF13' => 'BIB020030',
            'QRMF13,YNRX' => 'BIB005030',
            'QRMF1,2ADS' => 'BIB019060',
            'QRMF1,YNRX,2ADS' => 'BIB019070',
            'QRMF13,2ADS' => 'BIB019030',
            'DNB' => 'BIO013000',
            'DNBP' => 'BIO023000',
            'DNBF,AGB,AMB,AJCD' => 'BIO001000',
            'DNB,WG' => 'BIO034000',
            'DNBB' => 'BIO003000',
            'DNB,DNXC' => 'BIO024000',
            'DNB,WB' => 'BIO029000',
            'DNB,JBSL' => 'BIO002000',
            'DNB,5PB-US-C,5PBD' => 'BIO002010',
            'DNB,5PB-AA-A,5PBCB' => 'BIO002040',
            'DNB,5PB-US-D' => 'BIO002020',
            'DNB,5PB-US-H' => 'BIO002030',
            'DNB,JBSL11,5PBA' => 'BIO028000',
            'DNB,KNTP' => 'BIO025000',
            'DNB,JN' => 'BIO019000',
            'DNBF' => 'BIO005000',
            'DNBT,WN' => 'BIO030000',
            'DNBF,AKT' => 'BIO035000',
            'DNB,JKSW' => 'BIO036000',
            'DNBH' => 'BIO000000',
            'DNB,5PGJ' => 'BIO037000',
            'DNB,JKSW1' => 'BIO027000',
            'DNB,LAT' => 'BIO020000',
            'DNB,JBSJ,5PS' => 'BIO031000',
            'DNBL' => 'BIO007000',
            'DNBT' => 'BIO015000',
            'DNBH,DNXM' => 'BIO008000',
            'DNBF,AVN,AVP' => 'BIO004000',
            'DNB,5PM' => 'BIO033000',
            'DNC' => 'BIO026000',
            'DNBM' => 'BIO009000',
            'DNBH,JPHL' => 'BIO011000',
            'GBCB' => 'BIO012000',
            'DNBX' => 'BIO018000',
            'DNBR' => 'BIO014000',
            'DNBH,JPW' => 'BIO032000',
            'DNBM,JM,JH' => 'BIO021000',
            'DNBS' => 'BIO016000',
            'DNXP' => 'BIO038000',
            'DNB,JBSF1' => 'BIO022000',
            'VX' => 'OCC000000',
            'VXPR' => 'OCC034000',
            'VXWM,QRYX2' => 'OCC028000',
            'JBG' => 'OCC031000',
            'VXPS' => 'OCC003000',
            'VXFA' => 'OCC002000',
            'VXFA,1FP' => 'OCC030000',
            'VXFA1' => 'OCC009000',
            'VXHT2' => 'OCC044000',
            'QRST' => 'OCC036010',
            'VXPC' => 'OCC004000',
            'VXF' => 'OCC045000',
            'VXFJ1' => 'OCC017000',
            'VXFC1' => 'OCC024000',
            'VXN' => 'SEL012000',
            'VXA' => 'SEL032000',
            'VXV' => 'OCC037000',
            'VXK,QRY' => 'OCC033000',
            'VXWS,QRY' => 'OCC036050',
            'VXH' => 'HEA012000',
            'VXHK' => 'OCC011010',
            'VXH,QRVJ2' => 'OCC011020',
            'QRYX2' => 'OCC040000',
            'VXFD' => 'OCC038000',
            'VXM,VSPD' => 'OCC010000',
            'VXW,QRVK2' => 'OCC012000',
            'VXHF' => 'OCC043000',
            'VXFN' => 'OCC015000',
            'VXW,QRYX' => 'OCC016000',
            'VXP,JMX' => 'OCC018000',
            'VXP,VXFT' => 'OCC007000',
            'VXPJ' => 'OCC035000',
            'VX,QRVK' => 'OCC020000',
            'VX,GBC' => 'OCC021000',
            'QRVP7,VFVC' => 'OCC041000',
            'VXWS,QRRV' => 'OCC036030',
            'QRYM2,VXPH' => 'OCC027000',
            'VXQ' => 'OCC029000',
            'VXQB' => 'OCC025000',
            'VXWT,QRYX5' => 'OCC026000',
            'KJ' => 'BUS000000',
            'KFC' => 'BUS001000',
            'KFCF' => 'BUS001010',
            'KFCP' => 'BUS001020',
            'KFCM' => 'BUS005000',
            'KFCR' => 'BUS001050',
            'KJSA' => 'BUS002000',
            'KFCM1' => 'BUS003000',
            'KFFK' => 'BUS004000',
            'KFFJ,KFFF' => 'BUS114000',
            'KJMV1' => 'BUS006000',
            'KJP' => 'BUS009000',
            'KJG' => 'BUS008000',
            'LNC' => 'BUS010000',
            'KJQ' => 'BUS091000',
            'KJP,CBW' => 'BUS011000',
            'VSC' => 'SEL027000',
            'VSC,JNRD' => 'BUS012010',
            'VSCB' => 'BUS056030',
            'KCC' => 'BUS044000',
            'KCL' => 'POL011020',
            'KJ,LNAC5' => 'BUS110000',
            'KJL' => 'BUS075000',
            'KCK' => 'BUS069040',
            'KJZ' => 'BUS077000',
            'KFFH' => 'BUS017030',
            'KFFH,KFCM2' => 'BUS017020',
            'KJR' => 'BUS104000',
            'KJD' => 'BUS111000',
            'KJSU' => 'BUS018000',
            'KJMD' => 'BUS019000',
            'KCM' => 'BUS068000',
            'KJMV6' => 'BUS108000',
            'KCM,RNU' => 'BUS072000',
            'KJMV9' => 'BUS116000',
            'KJE' => 'COM064000',
            'KJSG' => 'BUS090010',
            'KFFF,UDBM' => 'BUS090030',
            'KJE,KJSG' => 'BUS090050',
            'KJE,KJVS' => 'BUS090040',
            'KCH' => 'BUS061000',
            'KCV,RGCM' => 'BUS022000',
            'KCZ' => 'BUS023000',
            'KC' => 'BUS069010',
            'KCB' => 'BUS039000',
            'KCA' => 'BUS069030',
            'KJB' => 'BUS024000',
            'KJH' => 'BUS025000',
            'KCVG' => 'BUS099000',
            'KJMV4' => 'BUS093000',
            'KFF' => 'BUS034000',
            'KFF,GPQD' => 'BUS027020',
            'KFFT' => 'BUS027030',
            'KCJ' => 'BUS086000',
            'KFFJ' => 'BUS028000',
            'KJVF' => 'BUS105000',
            'KCSA' => 'POL042060',
            'KJVS' => 'BUS060000',
            'GTQ' => 'POL033000',
            'KCP' => 'POL023000',
            'KJJ' => 'BUS094000',
            'KJMV2' => 'BUS066000',
            'KJM' => 'BUS042000',
            'KN' => 'BUS070000',
            'KNAC' => 'BUS070120',
            'KNDR' => 'BUS070020',
            'KNTX' => 'BUS070030',
            'KNJ' => 'BUS070160',
            'KNB' => 'BUS070040',
            'KNT' => 'BUS070060',
            'KNDD,KNSX' => 'BUS070090',
            'KNS,KFF' => 'BUS070140',
            'KN,MBP' => 'BUS070170',
            'KNSG' => 'BUS081000',
            'KND' => 'TEC040000',
            'KNAT' => 'BUS070150',
            'KNS' => 'BUS070080',
            'KNDC' => 'BUS070130',
            'KNP' => 'BUS057000',
            'KNG' => 'BUS070100',
            'KCBM' => 'BUS045000',
            'KJMK' => 'BUS083000',
            'KCS' => 'BUS062000',
            'KFFN' => 'BUS033080',
            'KFFN,GPQD' => 'BUS033070',
            // 'KJK' => 'BUS035000',
            'KJK,KFC' => 'BUS001030',
            'KJK,KJS' => 'BUS043030',
            'KJK,KFFD' => 'BUS064020',
            'KFFM' => 'BUS036060',
            'KFFM,KFFR' => 'BUS036050',
            'KFF,LWKL,5PGP' => 'BUS112000',
            'KCVP,KJMK' => 'BUS098000',
            'KCF,KNX' => 'BUS038000',
            'KCF,KNXU' => 'BUS038010',
            'KCF' => 'BUS038020',
            'KJMB' => 'BUS046000',
            'KJS' => 'BUS058000',
            'KJSJ' => 'BUS043050',
            'KJSM' => 'BUS043060',
            'KJMV2,VSC' => 'BUS106000',
            'KJVB' => 'BUS015000',
            'GLZ,KJM' => 'BUS100000',
            'KJN' => 'BUS047000',
            'KJV' => 'BUS048000',
            'KJVX' => 'BUS074000',
            'KJVX,KF' => 'BUS074010',
            'KJVX,KFFC' => 'BUS074020',
            'KJVX,KJM' => 'BUS074030',
            'KJVX,KJS' => 'BUS074040',
            'KJWF' => 'BUS095000',
            'KJWB' => 'BUS096000',
            'KJT' => 'BUS049000',
            'KJU' => 'BUS103000',
            'KJVT' => 'BUS102000',
            'VSB' => 'BUS050000',
            'VSB,KJMV1' => 'BUS050010',
            'VSB,KFFM' => 'BUS050020',
            'VSB,KFCM' => 'BUS050030',
            'VSB,VSR' => 'BUS050040',
            'VSB,KFCT' => 'BUS050050',
            'KJMV5,KJMN' => 'BUS087000',
            'KJMP' => 'BUS101000',
            'KFFD' => 'BUS051000',
            'KJSP' => 'BUS052000',
            'KJMV8' => 'BUS076000',
            'KJMQ' => 'BUS065000',
            'KFFR' => 'BUS054030',
            'KFFR,VSH' => 'BUS054010',
            'KJ,GBC' => 'BUS055000',
            'KJMV7' => 'BUS058010',
            'KJWS' => 'BUS089000',
            'KJW' => 'BUS059000',
            'KJC' => 'BUS063000',
            'KFCT' => 'BUS064000',
            'KFCT,KFFH,LNUC' => 'BUS064010',
            'KFCT,KJVS,LNUC' => 'BUS064030',
            'KJMT' => 'BUS088000',
            'KCVS' => 'BUS067000',
            'KJ,JBSF1' => 'BUS109000',
            'KJWX' => 'BUS097000',
            'KJMV2,JBFK4' => 'BUS117000',
            'XAK' => 'CGN006000',
            'XQB' => 'CGN012000',
            'XAK,DNT' => 'CGN001000',
            'XQF' => 'CGN008000',
            'XQD' => 'CGN004010',
            'XQL,FDB' => 'CGN013000',
            'XQXE,5X' => 'CGN004020',
            'XQM' => 'CGN004030',
            'XQV' => 'CGN010000',
            'XQH' => 'CGN016000',
            'XQT' => 'CGN014000',
            'XAK,5PS' => 'CGN009000',
            'XAM' => 'CGN004050',
            'XAM,XQG' => 'CGN004240',
            'XAM,XQD' => 'CGN004100',
            'XAM,FDB' => 'CGN004230',
            'XAMX,5X' => 'CGN004110',
            'XAM,XQM' => 'CGN004260',
            'XAM,XQV' => 'CGN004140',
            'XAM,XQH' => 'CGN004290',
            'XAM,XQT' => 'CGN004250',
            'XAM,XQM,XQL' => 'CGN004300',
            'XAM,5PS' => 'CGN004130',
            'XAM,YFZS' => 'CGN004320',
            'XAM,XQL' => 'CGN004190',
            'XAM,XQC' => 'CGN004160',
            'XAM,XQA' => 'CGN004170',
            'XAM,XQR' => 'CGN004180',
            'XAM,XQS' => 'CGN004280',
            'XAM,XQJ' => 'CGN004200',
            'XAMT' => 'CGN004210',
            'XAMY' => 'CGN004310',
            'XQC' => 'CGN004060',
            'XQA' => 'CGN007020',
            'XR' => 'CGN015000',
            'XQW,5PG' => 'CGN011000',
            'XQR' => 'CGN004090',
            'XQL' => 'CGN004070',
            'XQK' => 'CGN017000',
            'UB' => 'COM032000',
            'UYQ' => 'COM004000',
            'UYQV,UYQP' => 'COM016000',
            'UYQE' => 'COM025000',
            'UYQL' => 'COM042000',
            'UNKD' => 'COM093000',
            'UFL' => 'COM005000',
            'UFK' => 'COM027000',
            'UFL,KJC' => 'COM005030',
            'UFS' => 'COM066000',
            'UNS' => 'COM084010',
            'UDF' => 'COM084020',
            'UFB' => 'COM084030',
            'UFG' => 'COM078000',
            'UFP' => 'COM081000',
            'UFC' => 'COM054000',
            'UFD' => 'COM058000',
            'UKP,VSG' => 'COM006000',
            'UQ' => 'COM055000',
            'UQJ' => 'COM055030',
            'UQR' => 'COM055010',
            'UQF' => 'COM055020',
            'UQ,UNS' => 'COM055040',
            'UYF' => 'COM036000',
            'UY,TJF' => 'COM059000',
            'UY' => 'COM014000',
            'UYM' => 'COM072000',
            'GPFC' => 'SCI064000',
            'UNC,GPH' => 'COM018000',
            'UNC' => 'COM021030',
            'UNA,UYZM' => 'COM062000',
            'UYZF,UNC' => 'COM089000',
            'UND' => 'COM021040',
            'UYQM' => 'COM094000',
            'UYQN' => 'COM044000',
            'UN' => 'COM021000',
            'UG' => 'COM012000',
            'UGM,UYU' => 'COM087010',
            'UGC,TGPC' => 'COM007000',
            'UGL' => 'COM087020',
            'UGP,UDP' => 'COM087030',
            'UGV,UGN' => 'COM071000',
            'UTR' => 'COM048000',
            'UTD' => 'COM061000',
            'UTC' => 'COM091000',
            'UF,KJMV3' => 'COM063000',
            'UB,CBW' => 'COM085000',
            'UB,JNV' => 'COM023000',
            'UKM' => 'COM092000',
            'UTFB,JKVF1' => 'COM099000',
            'UK' => 'COM067000',
            'UDT,UDH' => 'COM074000',
            'TJFD' => 'TEC039000',
            'UKD' => 'COM038000',
            'UKP' => 'COM050000',
            'UKPM' => 'COM050020',
            'UKPC' => 'COM050010',
            'UKS' => 'COM049000',
            'UDH' => 'COM090000',
            'UBB,TBX' => 'COM080000',
            'UYZ' => 'COM079010',
            'UYT' => 'COM012050',
            'UYZM,GPF' => 'COM031000',
            'UBW' => 'COM060000',
            'UDBS' => 'COM060140',
            'UFL,KJMV3' => 'COM060170',
            'UDD,URD' => 'COM060040',
            'UDBD' => 'COM060120',
            'UD' => 'COM060150',
            'UDBR' => 'COM060010',
            'UGB' => 'COM060130',
            'UMW' => 'COM051480',
            'UMWS' => 'COM060180',
            'UKL' => 'COM095000',
            'UMX' => 'COM051410',
            'UYFL' => 'COM051040',
            'UMPN' => 'COM051470',
            'UMT' => 'COM051320',
            'UMZL' => 'COM051450',
            'UMP' => 'COM051380',
            'UYA,UYQM' => 'COM037000',
            'UFL,KJ' => 'COM039000',
            'UFM' => 'COM077000',
            'UT' => 'COM043020',
            'UKN,UK' => 'COM075000',
            'UTP' => 'COM043040',
            'UL' => 'COM046000',
            'ULP' => 'COM046100',
            'ULH,ULP' => 'COM046110',
            'ULJL' => 'COM046070',
            'ULH' => 'COM046020',
            'ULQ' => 'COM046080',
            'ULJ' => 'COM046030',
            'ULD' => 'COM046050',
            'UYQV' => 'COM047000',
            'UYFP' => 'COM051220',
            'UM' => 'COM051440',
            'UMB' => 'COM051300',
            'UMC' => 'COM010000',
            'UMK' => 'COM012040',
            'UMQ' => 'COM051370',
            'UMS' => 'COM051460',
            'UMN' => 'COM051210',
            'UYX' => 'COM097000',
            'UB,GBC' => 'COM052000',
            'UR' => 'COM053000',
            'URY,GPJ' => 'COM083000',
            'UTN' => 'COM043050',
            'URJ' => 'COM015000',
            'UBJ' => 'COM079000',
            'UM,KJMP' => 'COM051430',
            'UMZT' => 'COM051330',
            'UYD' => 'COM051240',
            'UYQS,UYU' => 'COM073000',
            'UTE' => 'COM088000',
            'UTFB' => 'COM019000',
            'UTM' => 'COM020020',
            'UTE,ULJL' => 'COM088010',
            'UTE,UKS,UNH' => 'COM030000',
            'UTV' => 'COM046090',
            'UTE,ULD' => 'COM088020',
            'UYZG' => 'COM070000',
            'UYV,UYW,UDBV' => 'COM057000',
            'UDY' => 'HOM027000',
            'WB' => 'CKB030000',
            'WBQ,VFXB' => 'CKB107000',
            'WBX' => 'CKB100000',
            'WBXD' => 'CKB088000',
            'WBXD3' => 'CKB130000',
            'WBXD2' => 'CKB007000',
            'WBXD1' => 'CKB126000',
            'WBXN1' => 'CKB019000',
            'WBXN3' => 'CKB118000',
            'WBXN' => 'CKB008000',
            'WBAC' => 'CKB127000',
            'WBQ' => 'CKB120000',
            'WBV' => 'CKB064000',
            'WBVD' => 'CKB003000',
            'WBVS2' => 'CKB009000',
            'WBVS1' => 'CKB014000',
            'WBVM' => 'CKB112000',
            'WBVQ' => 'CKB122000',
            'WBVS' => 'CKB004000',
            'WBVG' => 'CKB073000',
            'WBVR' => 'CKB121000',
            'WBVH' => 'CKB102000',
            'WBVD,WBVM' => 'CKB079000',
            'WBR,WJX' => 'CKB029000',
            'WBS' => 'CKB089000',
            'WBH' => 'CKB039000',
            'WBHS,VFJB1' => 'CKB106000',
            'WBHS,VFJB3' => 'CKB103000',
            'WBHS5,VFJB5' => 'CKB025000',
            'WBHS2,VFJB1' => 'CKB111000',
            'WBHS,VFJB4' => 'CKB104000',
            'WBHS4,VFMD' => 'CKB114000',
            'WBHS3,VFMD' => 'CKB108000',
            'WBHS1,VFMD' => 'CKB051000',
            'WBHS,VFMD' => 'CKB026000',
            'WB,NHTB' => 'CKB041000',
            'WBA,5HC' => 'CKB042000',
            'WBB' => 'CKB128000',
            'WBA' => 'CKB069000',
            'WBSB' => 'CKB060000',
            'WBW' => 'CKB015000',
            'WBC' => 'CKB020000',
            'WBD' => 'CKB113000',
            'WBF' => 'CKB070000',
            'WBH,WBK' => 'CKB059000',
            'WBSD' => 'CKB109000',
            'WBA,WNG' => 'CKB117000',
            'WB,GBC' => 'CKB071000',
            'WBN' => 'CKB045000',
            'WBN,1H' => 'CKB001000',
            'WBN,1KBB' => 'CKB002000',
            'WBN,1KBB-US-WPC' => 'CKB002010',
            'WBN,1KBB-US-NA' => 'CKB002020',
            'WBN,1KBB-US-M' => 'CKB002030',
            'WBN,1KBB-US-NE' => 'CKB002040',
            'WBN,1KBB-US-WPN' => 'CKB002050',
            'WBN,1KBB-US-S' => 'CKB002060',
            'WBN,1KBB-US-SW,1KBB-US-WM' => 'CKB002070',
            'WBN,1KBB-US-W' => 'CKB002080',
            'WBN,1F' => 'CKB090000',
            'WBN,1M' => 'CKB097000',
            'WBN,5PB-US-G,5PB-US-F' => 'CKB013000',
            'WBN,1KBC' => 'CKB091000',
            'WBN,1KJ' => 'CKB016000',
            'WBN,1KL' => 'CKB099000',
            'WBN,1FPC' => 'CKB017000',
            'WBN,1DDU' => 'CKB011000',
            'WBN,1D' => 'CKB092000',
            'WBN,1DDF' => 'CKB034000',
            'WBN,1DFG' => 'CKB036000',
            'WBN,1DXG' => 'CKB038000',
            'WBN,1DTH' => 'CKB043000',
            'WBN,1FK' => 'CKB044000',
            'WBN,1K,5PBA' => 'CKB058000',
            'WBN,1DDR' => 'CKB046000',
            'WBN,1DST' => 'CKB047000',
            'WBN,1FPJ' => 'CKB048000',
            'WBN,5PGJ' => 'CKB049000',
            'WBN,1FPK' => 'CKB123000',
            'WBN,1QRM' => 'CKB055000',
            'WBN,1KLCM' => 'CKB056000',
            'WBN,1FB' => 'CKB093000',
            'WBN,1DTP' => 'CKB065000',
            'WBN,1DSP' => 'CKB066000',
            'WBN,1DTA' => 'CKB072000',
            'WBN,1DN' => 'CKB074000',
            'WBN,1KBB,5PB-US-C' => 'CKB078000',
            'WBN,1FM' => 'CKB124000',
            'WBN,1DSE' => 'CKB080000',
            'WBN,1FMT' => 'CKB083000',
            'WBN,1DTT' => 'CKB084000',
            'WBN,1FMV' => 'CKB094000',
            'WBA,5HR' => 'CKB077000',
            'WBT' => 'CKB105000',
            'WBTX' => 'CKB018000',
            'WBTR' => 'CKB096000',
            'WBTM' => 'CKB035000',
            'WBTB' => 'CKB054000',
            'WBTH' => 'CKB040000',
            'WBTP' => 'CKB061000',
            'WBTC' => 'CKB067000',
            'WBTJ' => 'CKB098000',
            'WBTF' => 'CKB076000',
            'WBTM,WBVG' => 'CKB085000',
            'WJXF' => 'CKB082000',
            'WBJK' => 'CKB125000',
            'WBJ' => 'CKB086000',
            'WF' => 'CRA053000',
            'WFBQ' => 'CRA031000',
            'WFL' => 'CRA002000',
            'WFJ' => 'CRA014000',
            'WFT' => 'CRA052000',
            'WFS' => 'CRA064000',
            'WF,YNPH' => 'CRA043000',
            'WJK' => 'HOM003000',
            'WFH' => 'CRA039000',
            'WFBV' => 'CRA007000',
            'WFB,WJF' => 'CRA009000',
            'WFB' => 'CRA058000',
            'WFW' => 'CRA010000',
            'WFV' => 'CRA047000',
            'WFQ' => 'CRA042000',
            'WFQ,WKDW' => 'CRA062000',
            'WFN' => 'CRA028000',
            'WF,5H' => 'CRA034000',
            'WFC' => 'CRA055000',
            'WFD' => 'CRA050000',
            'WFP' => 'CRA059000',
            'WDH,WFH' => 'CRA018000',
            'WDHM' => 'CRA045000',
            'WDHB,WFH' => 'CRA020000',
            'WFBS2' => 'CRA004000',
            'WFBC' => 'CRA021000',
            'WFBS1' => 'CRA015000',
            'WFBL' => 'CRA016000',
            'WFTM' => 'CRA023000',
            'WFA' => 'CRA036000',
            'WFW,WJJ' => 'CRA027000',
            'AFH,WFA' => 'CRA029000',
            'WFH,ATXM' => 'CRA030000',
            'WF,GBC' => 'CRA032000',
            'WFF' => 'CRA033000',
            'WFBW' => 'CRA035000',
            'WFH,WFB' => 'CRA037000',
            'TTX' => 'CRA065000',
            'WF,VSZD' => 'CRA063000',
            'WFG' => 'CRA040000',
            'WFH,WFQ' => 'CRA041000',
            'AK' => 'DES004000',
            'AKH' => 'DES001000',
            'AFT' => 'DES003000',
            'AKT,AKTF' => 'DES005000',
            'AKR' => 'DES006000',
            'AKC' => 'DES007000',
            'AKL,KJSA' => 'DES007010',
            'AKL,KJSC' => 'DES007020',
            'AKL' => 'DES007030',
            'AKLB' => 'DES007040',
            'AKD' => 'DES007050',
            'AKX' => 'DES008000',
            'AKB' => 'DES015000',
            'AKP' => 'DES011000',
            'AFKG' => 'DES014000',
            'AK,GBC' => 'DES012000',
            'AKT,AFW' => 'DES013000',
            'DD' => 'DRA019000',
            'DD,1H' => 'DRA011000',
            'DD,1KBB' => 'DRA001000',
            'DD,1KBB,5PB-US-C' => 'DRA001010',
            'DDA' => 'DRA006000',
            'DD,DNT' => 'DRA002000',
            'DD,1F' => 'DRA005000',
            'DD,1FPJ' => 'DRA005010',
            'DD,1M' => 'DRA012000',
            'DD,1KBC' => 'DRA013000',
            'DD,1KJ,1KL' => 'DRA014000',
            'DD,1D' => 'DRA004000',
            'DD,1DDU,1DDR' => 'DRA003000',
            'DD,1DDF' => 'DRA004010',
            'DD,1DFG' => 'DRA004020',
            'DD,1DST' => 'DRA004030',
            'DD,1DSE,1DSP' => 'DRA004040',
            'DD,1K,5PBA' => 'DRA020000',
            'DD,5PS' => 'DRA017000',
            'DDA,6MB' => 'DRA018000',
            'DD,1FB' => 'DRA015000',
            'DD,QRA' => 'DRA008000',
            'DD,1DTA,1QBDR' => 'DRA016000',
            'DDA,5PX-GB-S' => 'DRA010000',
            'JN' => 'EDU037000',
            'JNK' => 'EDU036000',
            'JNK,JNLB,JNLC' => 'EDU001020',
            'JNK,JNM' => 'EDU001030',
            'JNP' => 'EDU002000',
            'JNF' => 'EDU022000',
            'JNDG,YPA' => 'EDU057000',
            'JNT' => 'EDU029100',
            'JNSV' => 'EDU005000',
            'JND' => 'EDU021000',
            'JNV' => 'EDU039000',
            'JNFC' => 'EDU045000',
            'JNR' => 'EDU031000',
            'JNDG' => 'EDU058000',
            'JN,GPQ' => 'EDU008000',
            'JNQ' => 'EDU061000',
            'JNF,JPQB' => 'EDU034030',
            'JNC' => 'EDU051000',
            'JNDH' => 'EDU030000',
            'JNKG' => 'EDU013000',
            'JNB' => 'EDU016000',
            'JNH' => 'EDU017000',
            'JNFK' => 'EDU048000',
            'JNF,JBSL1' => 'EDU020000',
            'JNA' => 'EDU040000',
            'JNMT' => 'EDU053000',
            'JN,GBC' => 'EDU024000',
            'JN,JBSC' => 'EDU052000',
            'JNL' => 'EDU060040',
            'JNLA,JNG' => 'EDU023000',
            'JNLB' => 'EDU010000',
            'JNLC' => 'EDU025000',
            'JNM' => 'EDU015000',
            'JNLP' => 'EDU060030',
            'JNLR' => 'EDU060050',
            'JNS' => 'EDU026000',
            'JNSL,5PM' => 'EDU026050',
            'JNS,5PM' => 'EDU026030',
            'JNSP' => 'EDU026060',
            'JNSG,5PMJ' => 'EDU026020',
            'JNSC,5PMB' => 'EDU026040',
            'JN,JHBC' => 'EDU027000',
            'JNK,VSKB' => 'EDU038000',
            'JNUM' => 'EDU029090',
            'JNU' => 'EDU029110',
            'JNU,YPA,YPJ' => 'EDU029050',
            'JNU,YPJJ6' => 'EDU029070',
            'JNTS,YPC' => 'EDU029080',
            'JNU,YPWL2' => 'EDU029060',
            'JNTS,YPMF' => 'EDU029010',
            'JNU,YPWF' => 'EDU033000',
            'JNTS,YPCA2' => 'EDU029020',
            'JNU,YPMP' => 'EDU029030',
            'JNU,YPJJ' => 'EDU029040',
            'JN,JBSD' => 'EDU054000',
            'JNF,JBFK' => 'EDU055000',
            'JNRV' => 'EDU056000',
            'VFV' => 'FAM041000',
            'JBFK,VFJM' => 'FAM001000',
            'JBFK1,VFJM' => 'FAM001010',
            'JBFK3,VFJM' => 'FAM001030',
            'JBFK,JBSP4,VFJM,5LKS' => 'FAM001020',
            'VF' => 'FAM002000',
            'JKSF,VFVK' => 'FAM004000',
            'VFJQ1' => 'SEL011000',
            'VFJR2,5PMJ' => 'FAM047000',
            'VFJR1,5PMH' => 'FAM048000',
            'VFXB1' => 'FAM008000',
            'VFXC' => 'FAM044000',
            'JBFK4,VFJN' => 'FAM049000',
            'JBSP1,JMC,5LC,5PM' => 'FAM012000',
            'VFVG' => 'FAM030000',
            'VFJX' => 'SEL010000',
            'VFVS' => 'FAM015000',
            'VFX' => 'FAM034010',
            'JKSG,JBSP4,5LKS' => 'FAM017000',
            'WQY' => 'FAM058000',
            'VFVN' => 'FAM021000',
            'VFJR3,5PMJ' => 'FAM028000',
            'VFV,JBSJ,5PS' => 'FAM056000',
            'VFXC1,JBSP2,5LF' => 'FAM043000',
            'VFXC,5LB' => 'FAM025000',
            'VFV,VFJG,JBSP4,5LKS' => 'FAM005000',
            'VFV,VFJG,JBSP3,5LKM' => 'FAM054000',
            'VFXC,JBSP1,5LC' => 'FAM039000',
            'VFV,JWC' => 'FAM055000',
            'VFV,JBSL13' => 'FAM057000',
            'VFX,VFVS' => 'FAM042000',
            'VFX,VFV,5JB' => 'FAM020000',
            'VFX,VFVX' => 'FAM033000',
            'VFX,VFV,5JA' => 'FAM032000',
            'VFJN' => 'FAM035000',
            'VFV,VFXC' => 'FAM037000',
            'VFV,GBC' => 'FAM038000',
            'FB' => 'FIC000000',
            'FJ' => 'FIC002000',
            'FYV,FYH' => 'FIC075000',
            'FB,5PB-US-C' => 'FIC049020',
            'FW,5PB-US-C,5PGM' => 'FIC049010',
            'FP,5PB-US-C,5X' => 'FIC049030',
            'FV,5PB-US-C' => 'FIC049040',
            'FF,5PB-US-C' => 'FIC049050',
            'FBAN,5PB-US-C' => 'FIC049070',
            'FDK' => 'FIC040000',
            'FW,5PB-US-B' => 'FIC053000',
            'DNT,FB' => 'FIC003000',
            'FB,5PB-US-D' => 'FIC054000',
            'FC' => 'FIC041000',
            'FW,5PGF' => 'FIC078000',
            'FW,5PGM' => 'FIC042000',
            'FW,FV,5PGM' => 'FIC042030',
            'FW,FBC,5PGM' => 'FIC042010',
            'FW,DNT,5PGM' => 'FIC042050',
            'FW,FBA,5PGM' => 'FIC042100',
            'FW,FM,5PGM' => 'FIC042080',
            'FW,FL,5PGM' => 'FIC042020',
            'FW,FR,5PGM' => 'FIC042040',
            'FW,FRH,5PGM' => 'FIC042110',
            'FW,FRM,5PGM' => 'FIC042120',
            'FW,FH,5PGM' => 'FIC042060',
            'FW,FJW,5PGM' => 'FIC042070',
            'FB,FXR' => 'FIC066000',
            'FBC' => 'FIC124010',
            'FB,FXB' => 'FIC043000',
            'FF' => 'FIC022080',
            'FB,5PB' => 'FIC051000',
            'FB,5PM' => 'FIC079000',
            'FH' => 'FIC030000',
            'FDB' => 'FIC055000',
            'FYD' => 'FIC065000',
            'FP,5X' => 'FIC005050',
            'FP,DNT,5X' => 'FIC005020',
            'FP,FV,5X' => 'FIC005060',
            'FP,5X,5PS' => 'FIC005070',
            'FP,5X,5PSB' => 'FIC005080',
            'FP,5X,5PSG' => 'FIC005030',
            'FP,5X,5PSL' => 'FIC005040',
            'FP,5X,5PT' => 'FIC005090',
            'FN' => 'FIC010000',
            'FS' => 'FIC045020',
            'FS,FXD' => 'FIC045010',
            'FM' => 'FIC009050',
            'FM,FJ' => 'FIC009100',
            'FMH,3KHF' => 'FIC009110',
            'FM,DNT' => 'FIC009040',
            'FMW' => 'FIC009010',
            'FMT' => 'FIC009070',
            'FM,VXQM' => 'FIC009120',
            'FMB' => 'FIC009020',
            'FMH,3MNQ-GB-V' => 'FIC009130',
            'FMH' => 'FIC009030',
            'FMK' => 'FIC009080',
            'FM,FJM' => 'FIC009140',
            'FMR' => 'FIC009090',
            'FMX' => 'FIC009060',
            'FB,JBSF11' => 'FIC076000',
            'FK' => 'FIC015000',
            'FB,5PB-US-H' => 'FIC056000',
            'FV' => 'FIC014000',
            'FV,1QBA' => 'FIC014010',
            'FV,1KBB,3MNQ-US-E' => 'FIC014060',
            'FV,1KBB,3MG-US-A,3MLQ-US-B' => 'FIC014070',
            'FV,3KH,3KL' => 'FIC014020',
            'FV,3KLY' => 'FIC014030',
            'FV,FJMF,3MPBFB' => 'FIC014040',
            'FV,FJMS,3MPBLB' => 'FIC014050',
            'FB,5H' => 'FIC058000',
            'FU' => 'FIC060000',
            'FB,5PBA' => 'FIC059000',
            'FW,5PGJ' => 'FIC046000',
            'FB,5PS' => 'FIC068000',
            'FB,5PSB' => 'FIC072000',
            'FB,5PSG' => 'FIC011000',
            'FB,5PSL' => 'FIC018000',
            'FB,5PT' => 'FIC073000',
            'FYW' => 'FIC129000',
            'FMM' => 'FIC061000',
            'FYM' => 'FIC057000',
            'FB,FXK' => 'FIC035000',
            'FW,5PGP' => 'FIC081000',
            'FFD' => 'FIC022090',
            'FF,DNT' => 'FIC022050',
            'FFJ' => 'FIC022140',
            'FFJ,WNG' => 'FIC022110',
            'FFJ,WF' => 'FIC022120',
            'FFJ,WB' => 'FIC022130',
            'FFJ,FKW' => 'FIC022150',
            'FFL' => 'FIC062000',
            'FFH' => 'FIC022060',
            'FF,5PGJ' => 'FIC022160',
            'FFP' => 'FIC022020',
            'FFC' => 'FIC022030',
            'FFS' => 'FIC022040',
            'FB,FXE' => 'FIC077000',
            'FKW' => 'FIC024000',
            'FBA,5P' => 'FIC082000',
            'FB,FXP' => 'FIC037000',
            'FB,FXM' => 'FIC025000',
            'FW,5PG' => 'FIC026000',
            'FR' => 'FIC027450',
            'FR,FJ' => 'FIC027260',
            'FR,5PB-US-C' => 'FIC049060',
            'FRR' => 'FIC027340',
            'FRF' => 'FIC027270',
            'FR,DNT' => 'FIC027080',
            'FRD' => 'FIC027430',
            'FRX,5X' => 'FIC027010',
            'FRT' => 'FIC027120',
            'FRP' => 'FIC027420',
            'FRH' => 'FIC027050',
            'FRH,1KBB' => 'FIC027360',
            'FRH,1QBA' => 'FIC027140',
            'FRH,1KBB,3MNQ-US-F' => 'FIC027460',
            'FRH,3KH,3KL' => 'FIC027150',
            'FRH,3ML-GB-PR' => 'FIC027070',
            'FRH,3KLY' => 'FIC027370',
            'FRH,1DDU-GB-S' => 'FIC027160',
            'FRH,3MD-GB-G' => 'FIC027280',
            'FRH,3MP' => 'FIC027200',
            'FRH,3MNQ-GB-V' => 'FIC027170',
            'FRH,1DN' => 'FIC027180',
            'FR,5HC' => 'FIC027290',
            'FR,5LKS' => 'FIC027380',
            'FR,5PS' => 'FIC027300',
            'FR,5PSB' => 'FIC027390',
            'FR,5PSG' => 'FIC027190',
            'FR,5PSL' => 'FIC027210',
            'FR,5PT' => 'FIC027400',
            'FRQ' => 'FIC027410',
            'FR,FXT,5PB' => 'FIC027230',
            'FRD,5LKE' => 'FIC027240',
            'FRT,VXQM2' => 'FIC027320',
            'FRT,VXWM' => 'FIC027440',
            'FRD,JBSW' => 'FIC027470',
            'FR,FQ' => 'FIC027250',
            'FR,FL' => 'FIC027130',
            'FR,FG' => 'FIC027330',
            'FRM' => 'FIC027110',
            'FRU' => 'FIC027090',
            'FRJ' => 'FIC027100',
            'FT' => 'FIC008000',
            'FUP' => 'FIC052000',
            'FL' => 'FIC028000',
            'FL,FJ' => 'FIC028010',
            'FLU' => 'FIC028090',
            'FLQ' => 'FIC028070',
            'FL,DNT' => 'FIC028040',
            'FL,FF' => 'FIC028140',
            'FLPB' => 'FIC028110',
            'FLH' => 'FIC028020',
            'FL,FU' => 'FIC028120',
            'FLR' => 'FIC028050',
            'FLW' => 'FIC028130',
            'FLS' => 'FIC028030',
            'FLM' => 'FIC028060',
            'FLG' => 'FIC028080',
            'FJN' => 'FIC047000',
            'FYB' => 'FIC029000',
            'FB,FXR,1KBB-US-S' => 'FIC074000',
            'FG' => 'FIC038000',
            'FMS' => 'FIC063000',
            'FH,FF' => 'FIC031010',
            'FH,FS' => 'FIC031100',
            'FHD' => 'FIC006000',
            'FH,FFH' => 'FIC031020',
            'FHP' => 'FIC031060',
            'FHM' => 'FIC031040',
            'FH,FJM' => 'FIC031050',
            'FHX' => 'FIC031080',
            'FH,FK' => 'FIC031070',
            'FHK' => 'FIC036000',
            'FHT' => 'FIC031090',
            'FBAN' => 'FIC048000',
            'FDV' => 'FIC039000',
            'FJM' => 'FIC032000',
            'FJW' => 'FIC033000',
            'FBA' => 'FIC000000',
            'CJ' => 'LAN020000',
            'CJ,2H' => 'FOR001000',
            'CJ,1QBA' => 'FOR033000',
            'CJ,2CSR' => 'FOR002000',
            'CJ,2AJB' => 'FOR034000',
            'CJ,2AF' => 'FOR029000',
            'CJ,2GDC' => 'FOR003000',
            'CJ,2ZP' => 'FOR035000',
            'CJ,2AGZ' => 'FOR036000',
            'CJ,2ACSD' => 'FOR004000',
            'CJ,2ACD' => 'FOR006000',
            'CJAD,2ACB,4LE' => 'FOR007000',
            'CJ,2FCF' => 'FOR037000',
            'CJ,2ADF' => 'FOR008000',
            'CJ,2ACG' => 'FOR009000',
            'CJ,2AHM' => 'FOR010000',
            'CJ,2CSJ' => 'FOR011000',
            'CJ,2BMH' => 'FOR038000',
            'CJ,2FCM' => 'FOR012000',
            'CJ,2B' => 'FOR030000',
            'CJ,2JN' => 'FOR031000',
            'CJ,2ADT' => 'FOR013000',
            'CJ,2GJ' => 'FOR014000',
            'CJ,2GK' => 'FOR015000',
            'CJ,2ADL' => 'FOR016000',
            'CBDX' => 'FOR005000',
            'WTK' => 'FOR018000',
            'CJ,2ACSN' => 'FOR039000',
            'CJ,2P' => 'FOR032000',
            'CJ,2ACBA,2ACBC' => 'FOR045000',
            'CJ,2BXF' => 'FOR040000',
            'CJ,2AGP' => 'FOR019000',
            'CJ,2ADP' => 'FOR020000',
            'CJ,2AD' => 'FOR041000',
            'CJ,2AGR' => 'FOR021000',
            'CJ,2ACS' => 'FOR022000',
            'CJ,2AGS' => 'FOR023000',
            'CJ,2AG' => 'FOR024000',
            'CJ,2G' => 'FOR025000',
            'CJ,2ADS' => 'FOR026000',
            'CJ,2HCBD' => 'FOR042000',
            'CJ,2ACSW' => 'FOR043000',
            'CJ,2FM' => 'FOR027000',
            'CJ,2GRV' => 'FOR044000',
            'CJ,2ACY' => 'FOR028000',
            'WD' => 'GAM011000',
            'WFX' => 'GAM024000',
            'WDMG' => 'GAM001000',
            'WDMC' => 'GAM002030',
            'WDMC1' => 'GAM002010',
            'WDMC2' => 'GAM002040',
            'WDMG1' => 'GAM001030',
            'WDKC' => 'GAM014000',
            'WDKC,CBD' => 'GAM003040',
            'SXE' => 'GAM023000',
            'WDHW' => 'GAM010000',
            'WDP' => 'GAM004030',
            'WDP,SKG' => 'GAM004040',
            'WZSJ' => 'SEL045000',
            'ATX' => 'PER006000',
            'WDK' => 'GAM007000',
            'ATXF' => 'GAM006000',
            'WDJ' => 'GAM018000',
            'WDKX' => 'REF023000',
            'WD,GBC' => 'GAM009000',
            'WDKN' => 'GAM017000',
            'UDX' => 'GAM013000',
            'WM' => 'GAR019000',
            'WM,RGBA' => 'GAR027010',
            'WM,1QMT' => 'GAR027030',
            'WMQR' => 'GAR001000',
            'WMPC' => 'GAR017000',
            'WMPF' => 'GAR025000',
            'WMD' => 'GAR013000',
            'WMF' => 'GAR008000',
            'WMP' => 'GAR031000',
            'WMQR1' => 'GAR010000',
            'WMQ,TVSH' => 'GAR011000',
            'WMQL' => 'GAR015000',
            'WMQF' => 'GAR016000',
            'WMB,AJ' => 'GAR030000',
            'WM,GBC' => 'GAR018000',
            'WM,1KBC' => 'GAR019010',
            'WM,1KBB-US-NA' => 'GAR019020',
            'WM,1KBB-US-M' => 'GAR019030',
            'WM,1KBB-US-NE' => 'GAR019040',
            'WM,1KBB-US-WPN' => 'GAR019050',
            'WM,1KBB-US-S' => 'GAR019060',
            'WM,1KBB-US-SW,1KBB-US-WM' => 'GAR019070',
            'WM,1KBB-US-W' => 'GAR019080',
            'WMQ' => 'GAR022000',
            'WMPS' => 'GAR024000',
            'WMQ,WMPS' => 'GAR023000',
            'WMT' => 'GAR028000',
            'WMQW' => 'GAR029000',
            'VFD' => 'HEA038000',
            'VXHA' => 'HEA001000',
            'VFJB1' => 'HEA027000',
            'VXHC' => 'HEA029000',
            'WJH' => 'HEA003000',
            'VFXB' => 'HEA041000',
            'VFDJ' => 'HEA046000',
            'VFMD' => 'HEA019000',
            'VFJB' => 'HEA039000',
            'VFJB9,MJCJ2' => 'HEA039020',
            'VFJB6' => 'HEA039140',
            'VFJB3' => 'HEA039030',
            'VFJB9' => 'HEA039150',
            'VFJB,MJCJ' => 'HEA039040',
            'VFJB5' => 'HEA039050',
            'VFJB,MJG' => 'HEA039160',
            'VFJB,MJH' => 'HEA039010',
            'VFJB,MJCG1' => 'HEA039060',
            'VFJB,MJCJ1,MJS' => 'HEA039070',
            'VFJB7' => 'HEA039170',
            'VFJB4' => 'HEA039080',
            'VFJB9,MJCM' => 'HEA039090',
            'VFJB,MJE' => 'HEA039100',
            'VFJB,MKJ' => 'HEA039110',
            'VFJB,MJL' => 'HEA039120',
            'VFJB,MJK' => 'HEA039130',
            'VFMG' => 'HEA022000',
            'VFMG,SP' => 'HEA007010',
            'VFMG,ATQ' => 'HEA007020',
            'VFMG2' => 'HEA007050',
            'VFVJ,MFKC1' => 'HEA045000',
            'VFDF' => 'HEA033000',
            'VFD,MBP' => 'HEA028000',
            'VFDB' => 'HEA010000',
            'VFJD' => 'HEA018000',
            'VXHT4' => 'HEA011000',
            'VXHH' => 'HEA030000',
            'VFD,5PS' => 'HEA054000',
            'VFJG' => 'SEL005000',
            'VFMS,VXHJ' => 'HEA014000',
            'VFDM' => 'HEA015000',
            'VFDW2' => 'HEA051000',
            'VFJQ' => 'SEL033000',
            'VXHN' => 'HEA016000',
            'VFD,MKE' => 'HEA040000',
            'VFJ,MKAL' => 'HEA036000',
            'VF,GBC' => 'HEA020000',
            'VFVC' => 'SEL034000',
            'VFJV' => 'HEA043000',
            'SRMN1' => 'HEA052000',
            'VFD,MBNK' => 'HEA050000',
            'VFD,MJQ' => 'HEA037000',
            'VFDW' => 'HEA024000',
            'VFMG1' => 'HEA025000',
            'NH' => 'HIS000000',
            'NHH,1H' => 'HIS001000',
            'NHH,1HFJ' => 'HIS001010',
            'NHH,1HFG' => 'HIS001020',
            'NHH,1HB' => 'HIS001030',
            'NHH,1HFM' => 'HIS001040',
            'NHH,1HFMS' => 'HIS047000',
            'NHH,1HFD' => 'HIS001050',
            'NHTB,5PB-US-C' => 'HIS056000',
            'NHK,1K' => 'HIS038000',
            'NHC,1QBA' => 'HIS002000',
            'NHC,NHG,3C-AA-E' => 'HIS002030',
            'NHC,NHD,1QBAG' => 'HIS002010',
            'NHDA,1QBAR' => 'HIS002020',
            'NHF,1F' => 'HIS003000',
            'NHF,1FC' => 'HIS050000',
            'NHF,1FPC' => 'HIS008000',
            'NHF,1FPJ' => 'HIS021000',
            'NHF,1FPK' => 'HIS023000',
            'NHF,1FK' => 'HIS017000',
            'NHF,1FKA' => 'HIS062000',
            'NHF,1FM' => 'HIS048000',
            'NHM,1MB' => 'HIS004000',
            'NHQ,1QBCB' => 'HIS059000',
            'NHK,1KBC' => 'HIS006030',
            'NHK,1QF-CA-A' => 'HIS006040',
            'NHK,1KBC-CA-B' => 'HIS006050',
            'NHK,1QF-CA-T' => 'HIS006060',
            'NHK,1KBC-CA-O' => 'HIS006070',
            'NHK,1QF-CA-P' => 'HIS006080',
            'NHK,1KBC-CA-Q' => 'HIS006090',
            'NHK,1KJ' => 'HIS041000',
            'NHK,1KJC' => 'HIS041010',
            'NHK,1KJD' => 'HIS041020',
            'NHK,1KJH' => 'HIS041030',
            'NHK,1KJWJ' => 'HIS041040',
            'NHTB' => 'HIS054000',
            'NHB' => 'HIS037000',
            'NHD,1D' => 'HIS010000',
            'NHDJ,1D' => 'HIS037010',
            'NHDL,1D' => 'HIS037020',
            'NHD,1QBDA' => 'HIS040000',
            'NHD,1DT' => 'HIS010010',
            'NHD,1DDB,1DDN,1DDL' => 'HIS063000',
            'NHD,1DDF' => 'HIS013000',
            'NHD,1DFG' => 'HIS014000',
            'NHD,1DDU' => 'HIS015000',
            'NHDJ,1DDU,3KH' => 'HIS015010',
            'NHDJ,1DDU,3KL' => 'HIS015020',
            'NHD,1DDU,3MD-GB-G' => 'HIS015030',
            'NHD,1DDU,3MG,3MGQS-GB-K' => 'HIS015040',
            'NHD,1DDU,3ML-GB-P' => 'HIS015050',
            'NHD,1DDU,3MNQ-GB-V' => 'HIS015060',
            'NHD,1DDU,3MP' => 'HIS015070',
            'NHD,1DDU,3MR' => 'HIS015080',
            'NHD,1DDU-GB-S' => 'HIS015090',
            'NHD,1DDU-GB-W' => 'HIS015100',
            'NHD,1DXG' => 'HIS042000',
            'NHD,1DDR' => 'HIS018000',
            'NHD,1DST' => 'HIS020000',
            'NHD,1DN' => 'HIS044000',
            'NHD,1DTP' => 'HIS060000',
            'NHD,1DSP' => 'HIS064000',
            'NHD,1DSE' => 'HIS045000',
            'NHD,1DD' => 'HIS010020',
            'NHB,RGR' => 'HIS051000',
            'NHTP' => 'HIS052000',
            'NHAH' => 'HIS016000',
            'NHK,NHTB,JBSL11,1K,5PBA' => 'HIS028000',
            'NHTB,5PGP' => 'HIS065000',
            'NHTB,5PGJ' => 'HIS022000',
            'NHK,1KL' => 'HIS024000',
            'NHKA,1KL' => 'HIS061000',
            'NHK,1KLC' => 'HIS007000',
            'NHK,1KLCM' => 'HIS025000',
            'NHK,1KLS' => 'HIS033000',
            'NHTB,JBSJ,5PS' => 'HIS066000',
            'NHTM' => 'HIS057000',
            'NHG,1FB' => 'HIS026000',
            'NHG,1FBX' => 'HIS026010',
            'NHG,1HBE' => 'HIS009000',
            'NHG,1FBN' => 'HIS026020',
            'NHG,1FBQ' => 'HIS026030',
            'NHG,1FBH' => 'HIS019000',
            'NHG,1FBS' => 'HIS026040',
            'NHG,1DTT,1QBCS' => 'HIS055000',
            'NHW' => 'HIS027130',
            'NHWA' => 'HIS027220',
            'JWCM,NHW' => 'HIS027140',
            'JWMC,NHW' => 'HIS027010',
            'NHW,1KBC' => 'HIS027160',
            'NHWR3' => 'HIS027250',
            'NHWF' => 'HIS027260',
            'NHW,AMKL' => 'HIS027270',
            'JWCG,NHW' => 'HIS027280',
            'JWKF,NHW' => 'HIS027290',
            'JWCD,NHW' => 'HIS027300',
            'NHWD' => 'HIS027230',
            'JWCK,NHW' => 'HIS027150',
            'JWMN,NHW' => 'HIS027030',
            'NHW,AJF' => 'HIS027050',
            'NHTV' => 'HIS031000',
            'JWCS,NHW' => 'HIS027180',
            'JWK,NHW' => 'HIS027060',
            'JWTU,NHW' => 'HIS027310',
            'NHW,1KBB' => 'HIS027110',
            'JWMV,NHW' => 'HIS027240',
            'JWMV,JWCM,NHW' => 'HIS027320',
            'JWMV,JWCD,NHW' => 'HIS027330',
            'JWMV,JWCK,NHW' => 'HIS027340',
            'JWXV,NHW' => 'HIS027120',
            'JWM,TTMW,NHW' => 'HIS027080',
            'NHB,3MD' => 'HIS037090',
            'NHB,3MG' => 'HIS037040',
            'NHB,3ML' => 'HIS037050',
            'NHB,3MN' => 'HIS037060',
            'NHB,3MP' => 'HIS037070',
            'NHTW,3MPQ' => 'HIS037100',
            'NHTZ1,3MPBLB' => 'HIS043000',
            'NHB,3MR' => 'HIS037080',
            'NHK,1KB' => 'HIS029000',
            'NHM,1MK' => 'HIS053000',
            'NHQ,1QMP' => 'HIS046000',
            'NHB,GBC' => 'HIS030000',
            'NHQ,1DTA,1QBDR' => 'HIS032000',
            'NHB,YPJ' => 'HIS035000',
            'NHK,1KBB' => 'HIS036000',
            'NHK,1KBB,3MG-US-A' => 'HIS036020',
            'NHK,1KBB,3MLQ-US-B,3MLQ-US-C' => 'HIS036030',
            'NHK,1KBB,3MN' => 'HIS036040',
            'NHK,1KBB,3MNQ-US-E,3MNB-US-D' => 'HIS036050',
            'NHK,1KBB,3MP' => 'HIS036060',
            'NHK,1KBB,3MR' => 'HIS036070',
            'NHK,WQH,1KBB' => 'HIS036010',
            'NHK,WQH,1KBB-US-NA' => 'HIS036080',
            'NHK,WQH,1KBB-US-M' => 'HIS036090',
            'NHK,WQH,1KBB-US-NE' => 'HIS036100',
            'NHK,WQH,1KBB-US-WPN' => 'HIS036110',
            'NHK,WQH,1KBB-US-S' => 'HIS036120',
            'NHK,WQH,1KBB-US-W' => 'HIS036140',
            'NHWR,NHWD,1FBG,3KL' => 'HIS027350',
            'NHWR,1D,3MNB' => 'HIS027200',
            'NHWR,1KBB,1DDU,3MNBF' => 'HIS027210',
            'NHWR5,3MPBFB' => 'HIS027090',
            'NHWR7,3MPBLB' => 'HIS027100',
            'NHWR7,3MPBLB,1DT' => 'HIS027360',
            'NHWR7,3MPBLB,1D' => 'HIS027370',
            'NHWR7,3MPBLB,1QRM,1HB' => 'HIS027380',
            'NHWR7,3MPBLB,1QSP' => 'HIS027390',
            'NHWR9,1FPK,3MPQM-US-N' => 'HIS027020',
            'NHWR9,1FMV,3MPQS-US-Q' => 'HIS027070',
            'NHWR9,1FBX,3MPQZ' => 'HIS027040',
            'NHWR9,1FCA,3MRB' => 'HIS027190',
            'NHWR9,1FBQ,3MRB' => 'HIS027170',
            'NHTB,JBSF1' => 'HIS058000',
            'WK' => 'HOM023000',
            'WKH' => 'HOM019000',
            'WKD' => 'HOM015000',
            'WKDW' => 'HOM001000',
            'WKD,THRX' => 'HOM006000',
            'WKD,TNTB' => 'HOM012000',
            'WKD,TNTP' => 'HOM014000',
            'WK,AMCR' => 'HOM024000',
            'UDV' => 'HOM026000',
            'WKDM' => 'HOM010000',
            'WK,VS' => 'HOM025000',
            'WKU' => 'HOM013000',
            'WK,GBC' => 'HOM016000',
            'WKR' => 'HOM017000',
            'WK,TNKS,VFB' => 'HOM021000',
            'WK,VSZ' => 'HOM022000',
            'WH' => 'HUM018000',
            'WHX' => 'HUM003000',
            'WHX,XY' => 'HUM001000',
            'WHJ' => 'HUM004000',
            'WH,DC' => 'HUM005000',
            'WHP' => 'HUM007000',
            'WH,5X' => 'HUM008000',
            'WH,WN' => 'HUM009000',
            'WH,KJ' => 'HUM010000',
            'WH,JBCC1' => 'HUM020000',
            'WH,5P' => 'HUM021000',
            'WH,NH' => 'HUM022000',
            'WHG,ATN' => 'HUM023000',
            'WH,CBX' => 'HUM019000',
            'WH,5PS' => 'HUM024000',
            'WH,VFV' => 'HUM011000',
            'WH,VFVG' => 'HUM012000',
            'WH,JP' => 'HUM006000',
            'WH,QR' => 'HUM014000',
            'WH,JN' => 'HUM025000',
            'WH,SC' => 'HUM013000',
            'WH,WT' => 'HUM026000',
            'YFB' => 'JUV000000',
            'YFC' => 'JUV001000',
            'YFC,YNHA1' => 'JUV001020',
            'YBG' => 'JNF001000',
            'YBGC' => 'JNF001010',
            'YBGS' => 'JNF001020',
            'YFP' => 'JUV002260',
            'YFP,YNNM' => 'JUV002240',
            'YFP,YNNJ29' => 'JUV002020',
            'YFP,YBLL' => 'JUV002370',
            'YFP,YNNJ23' => 'JUV002030',
            'YFP,YNNK' => 'JUV002380',
            'YFP,YNNL' => 'JUV002390',
            'YFP,YNNH2,YNNJ22' => 'JUV002050',
            'YFP,YNNJ25' => 'JUV002310',
            'YFP,YNNJ2' => 'JUV002330',
            'YFP,YNNA' => 'JUV002060',
            'YFP,YNNH1,YNNJ21' => 'JUV002070',
            'YFH,YNXB' => 'JUV052000',
            'YFP,YNNF' => 'JUV002090',
            'YFP,YNNS' => 'JUV002170',
            'YFP,YNNJ21' => 'JUV002250',
            'YFP,YNNJ24' => 'JUV002130',
            'YFP,YNNB2' => 'JUV002340',
            'YFP,YNNJ9' => 'JUV002350',
            'YFP,YNNJ22' => 'JUV002150',
            'YFP,YNNJ' => 'JUV002160',
            'YFP,YNNJ31' => 'JUV002230',
            'YFP,YNNH' => 'JUV002190',
            'YFP,YNNJ26' => 'JUV002200',
            'YFB,YNTP' => 'JUV073000',
            'YFB,YNA' => 'JUV003000',
            'YBCS1' => 'JUV010000',
            'YFX' => 'JUV004000',
            'YFX,1H' => 'JUV004050',
            'YFX,1F' => 'JUV004060',
            'YFX,1KBC' => 'JUV004040',
            'YFX,1D' => 'JUV004010',
            'YFX,1KL' => 'JUV004070',
            'YFX,1KBB' => 'JUV004020',
            'YFB,YNL' => 'JUV047000',
            'YFB,YNMH' => 'JUV005000',
            'YFB,YNK' => 'JUV006000',
            'YFA' => 'JUV007000',
            'YFB,YNPJ' => 'JUV048000',
            'XQ,YFB' => 'JUV008030',
            'XQG,YFC' => 'JUV008040',
            'XQN,YFP' => 'JUV008050',
            'XQB,YFA' => 'JUV008060',
            'XQM,YFJ' => 'JUV008070',
            'XQM,YFH' => 'JUV008130',
            'XQV,YFT' => 'JUV008090',
            'XQH,YFD' => 'JUV008100',
            'XQT,YFQ' => 'JUV008110',
            'XAM,YFB' => 'JUV008010',
            'XQD,YFCF' => 'JUV008120',
            'XQL,YFG' => 'JUV008140',
            'XQK,YFG' => 'JUV008020',
            'YFB,YNTC' => 'JUV049000',
            'YFB,YBL' => 'JUV009090',
            'YFB,YBLA' => 'JUV009080',
            'YFB,YBLN1' => 'JUV009110',
            'YFB,YBLD' => 'JUV009020',
            'YFB,YBLC' => 'JUV009030',
            'YFB,YBLJ' => 'JUV009100',
            'YFB,YBLF' => 'JUV009040',
            'YFB,YBLH' => 'JUV009060',
            'YFB,YNPC' => 'JUV050000',
            'YFB,YXK' => 'JUV039150',
            'YFB,YXP' => 'JUV074000',
            'YFE' => 'JUV059000',
            'YFJ' => 'JUV022000',
            'YFJ,YDC' => 'JUV012000',
            'YFN,YXF' => 'JUV013060',
            'YFN,YXFF' => 'JUV013050',
            'YFN,YXF,YXFD' => 'JUV013020',
            'YFN,YXFR' => 'JUV013070',
            'YFH' => 'JUV037000',
            'YFD' => 'JUV018000',
            'YFB,YNMF' => 'JUV014000',
            'YFB,YXA' => 'JUV015010',
            'YFB,YXL' => 'JUV015030',
            'YFB,YBLM' => 'JUV039170',
            'YFT' => 'JUV016000',
            'YFT,1H' => 'JUV016010',
            'YFT,1QBA' => 'JUV016020',
            'YFT,1F' => 'JUV016030',
            'YFT,1KBC' => 'JUV016180',
            'YFT,1D' => 'JUV016040',
            'YFT,YNHD' => 'JUV016050',
            'YFT,3MPBGJ-DE-H,5PGJ' => 'JUV016060',
            'YFT,3KH,3KL' => 'JUV016070',
            'YFT,1FB' => 'JUV016210',
            'YFT,YNJ' => 'JUV016080',
            'YFT,3B' => 'JUV016090',
            'YFT,3KLY' => 'JUV016100',
            'YFT,1KBB' => 'JUV016110',
            'YFT,1KBB,3MLQ-US-B,3MLQ-US-C' => 'JUV016120',
            'YFT,1KBB,3MN' => 'JUV016140',
            'YFT,1KBB,3MNQ-US-E,3MNB-US-D' => 'JUV016200',
            'YFT,1KBB,3MP' => 'JUV016150',
            'YFT,1KBB,3MR' => 'JUV016190',
            'YFB,YNMD,5HC' => 'JUV017080',
            'YFB,YNMD,5HKA' => 'JUV017100',
            'YFB,YNMD,5HPD' => 'JUV017010',
            'YFB,YNMD,5HPF' => 'JUV017020',
            'YFB,YNMD,5HCL' => 'JUV017140',
            'YFB,YNMD,5HCP' => 'JUV017030',
            'YFB,YNMD,5HPU' => 'JUV017110',
            'YFB,YNMD,5PB-US-C' => 'JUV017050',
            'YFB,YNMD,5HCJ' => 'JUV017150',
            'YFB,YNMD,5HPV' => 'JUV017120',
            'YFB,YNMD,YNMC,5HCF' => 'JUV017130',
            'YFB,YNMD,5HCS' => 'JUV017060',
            'YFB,YNMD,5HCE' => 'JUV017070',
            'YFB,YNMD,5HP' => 'JUV017090',
            'YFQ' => 'JUV019000',
            'YBCS2' => 'JUV051000',
            'YFCA' => 'JUV020000',
            'YFCF,YNKC' => 'JUV021000',
            'YFJ,1H' => 'JUV012050',
            'YFJ,1DDU-GB-E' => 'JUV022010',
            'YFJ,1F' => 'JUV012060',
            'YFJ,1KJ,1KL' => 'JUV012070',
            'YFJ,1QBAR,1QBAG' => 'JUV022020',
            'YFJ,1K,5PBA' => 'JUV012080',
            'YFJ,1DN' => 'JUV022030',
            'YFB,YXB,5PS' => 'JUV060000',
            'YFB,YNMK' => 'JUV023000',
            'YFB,YNML' => 'JUV025000',
            'YFMR' => 'JUV026000',
            'YFB,YNTM' => 'JUV072000',
            'YFH,YNXB6' => 'JUV066000',
            'YFCF' => 'JUV028000',
            'YFB,YXP,5PM' => 'JUV077000',
            'YBLB' => 'JUV055000',
            'YFH,YNX' => 'JUV058000',
            'YFB,YNM' => 'JUV068000',
            'YFB,YNM,1H' => 'JUV030010',
            'YFB,YNM,1F' => 'JUV030020',
            'YFB,YNM,1M' => 'JUV030080',
            'YFB,YNM,1KBC' => 'JUV030030',
            'YFB,YNM,1KBC,5PBA' => 'JUV030090',
            'YFB,YNM,1KJ,1KL' => 'JUV030040',
            'YFB,YNM,1D' => 'JUV030050',
            'YFB,YNM,1KLCM' => 'JUV030100',
            'YFB,YNM,1FB' => 'JUV030110',
            'YFB,YNM,1QMP' => 'JUV030120',
            'YFB,YNM,1KBB' => 'JUV030130',
            'YFB,YNM,1KBB,5PB-US-C' => 'JUV011010',
            'YFB,YNM,1KBB,5PB-US-D' => 'JUV011020',
            'YFB,YNM,1KBB,5PB-US-H' => 'JUV011030',
            'YFB,YNM,1KBB,5PB-US-E' => 'JUV011040',
            'YFB,YND' => 'JUV031060',
            'YFB,YNDB' => 'JUV031020',
            'YFB,YNF' => 'JUV031050',
            'YFB,YNC' => 'JUV031040',
            'YFB,YDP' => 'JUV070000',
            'YFB,YNKA' => 'JUV061000',
            'YFB,YPCA21' => 'JUV044000',
            'YFB,YBD' => 'JUV045000',
            'YFB,YXZG' => 'JUV063000',
            'YFK' => 'JUV033000',
            'YFK,5PGF' => 'JUV033250',
            'YFK,5PGM' => 'JUV033280',
            'YFK,YFC,5PGM' => 'JUV033040',
            'YFK,YFP,5PGM' => 'JUV033050',
            'YFK,YBCS1,5PGM' => 'JUV033060',
            'YFK,XQW,5PGM' => 'JUV033070',
            'YFK,YPCA21,5PGM' => 'JUV033080',
            'YFK,YXE,5PGM' => 'JUV033090',
            'YFK,YFN,5PGM' => 'JUV033100',
            'YFK,YFH,YFG,5PGM' => 'JUV033110',
            'YFK,YXHB,5PGM' => 'JUV033120',
            'YFK,YFT,5PGM' => 'JUV033140',
            'YFK,5PGM,5HC' => 'JUV033150',
            'YFK,YFQ,5PGM' => 'JUV033160',
            'YFK,YBL,5PGM' => 'JUV033170',
            'YFK,YFCF,5PGM' => 'JUV033180',
            'YFK,YNM,5PGM' => 'JUV033190',
            'YFK,YFM,5PGM' => 'JUV033200',
            'YFK,YXZ,5PGM' => 'JUV033220',
            'YFK,YFR,5PGM' => 'JUV033230',
            'YFK,YX,5PGM' => 'JUV033240',
            'YFK,5PGD' => 'JUV033260',
            'YFK,5PGJ' => 'JUV033020',
            'YFK,5PGP' => 'JUV033270',
            'YFG' => 'JUV053000',
            'YFB,YNMW' => 'JUV034000',
            'YFS' => 'JUV035000',
            'YFB,YNT,YNN' => 'JUV029000',
            'YFP,YXZE' => 'JUV029030',
            'YFP,YXZG' => 'JUV029010',
            'YFP,YNNT' => 'JUV029050',
            'YFP,YNNV2' => 'JUV029020',
            'YFG,YNXF' => 'JUV053010',
            'YFG,YNNZ' => 'JUV053020',
            'YFG,YNTT' => 'JUV064000',
            'YFU' => 'JUV038000',
            'YFB,YX' => 'JUV039220',
            'YFB,YXZB' => 'JUV039290',
            'YFB,YXW' => 'JUV039090',
            'YFB,YXQF' => 'JUV039230',
            'YFM,YXHL' => 'JUV039190',
            'YFB,YXG' => 'JUV039030',
            'YFB,YXLD' => 'JUV039240',
            'YFB,YXJ' => 'JUV039040',
            'YFB,YXZM' => 'JUV039250',
            'YFB,YXE' => 'JUV039050',
            'YFMF' => 'JUV039060',
            'YFB,YXQ' => 'JUV039180',
            'YFB,YXQD' => 'JUV039210',
            'YFB,YXZH' => 'JUV039070',
            'YFB,YXPB,YXN' => 'JUV039120',
            'YFB,YXZR' => 'JUV039280',
            'YFB,YXS' => 'JUV039130',
            'YFB,YXD' => 'JUV039140',
            'YFB,YXR' => 'JUV039270',
            'YFB,YNHA' => 'JUV076000',
            'YFR' => 'JUV032160',
            'YFR,YNWD3' => 'JUV032010',
            'YFR,YNWD4' => 'JUV032020',
            'YFR,YNW' => 'JUV032240',
            'YFR,YNWY' => 'JUV032140',
            'YFR,YNNJ24' => 'JUV032090',
            'YFR,YNWD2' => 'JUV032030',
            'YFR,YNVM' => 'JUV032040',
            'YFR,YNWD' => 'JUV032190',
            'YFR,YNWG' => 'JUV032210',
            'YFR,YNWM2' => 'JUV032110',
            'YFR,YNWM' => 'JUV032080',
            'YFR,YNWJ' => 'JUV032070',
            'YFR,YNWD1' => 'JUV032150',
            'YFR,YNWW' => 'JUV032060',
            'YFGS' => 'JUV062000',
            'YFV' => 'JUV057000',
            'YFF' => 'JUV071000',
            'YFB,YNT' => 'JUV036000',
            'YFB,YNNZ' => 'JUV036010',
            'YFB,YNTD' => 'JUV036020',
            'YFCB' => 'JUV067000',
            'YFB,YNVD' => 'JUV040000',
            'YFB,YNTR' => 'JUV041050',
            'YFD,YNXB2' => 'JUV079000',
            'YFCW' => 'JUV075000',
            'YFC,1KBB-US-W' => 'JUV042000',
            'YFD,YNXB3' => 'JUV080000',
            'YN' => 'JNF064000',
            'YXZB' => 'JNF073000',
            'YNHA' => 'JNF068000',
            'YNN' => 'JNF051150',
            'YNNJ29' => 'JNF003010',
            'YNN,YBLL' => 'JNF003330',
            'YNNJ23' => 'JNF003020',
            'YNNK' => 'JNF003350',
            'YNNL' => 'JNF003370',
            'YNNH2,YNNJ22' => 'JNF003040',
            'YNNJ25' => 'JNF003260',
            'YNNJ2' => 'JNF003290',
            'YNNA' => 'JNF037050',
            'YNNH1,YNNJ21' => 'JNF003060',
            'YNN,YXZG' => 'JNF003270',
            'YNNF' => 'JNF003080',
            'YNNS' => 'JNF003150',
            'YNNJ21' => 'JNF003240',
            'YNNM' => 'JNF003360',
            'YNNJ24' => 'JNF003110',
            'YNNB2' => 'JNF003300',
            'YNNJ9' => 'JNF003310',
            'YNNJ22' => 'JNF003130',
            'YNNJ' => 'JNF003140',
            'YNNJ31' => 'JNF003180',
            'YNNB' => 'JNF003320',
            'YNNH' => 'JNF003170',
            'YNV' => 'JNF021030',
            'YNTP' => 'JNF005000',
            'YNA' => 'JNF041000',
            'YNA,YNUC' => 'JNF006010',
            'YNPJ' => 'JNF059000',
            'YBLM' => 'JNF024110',
            'YNB' => 'JNF007000',
            'YNB,YNA' => 'JNF007010',
            'YNB,5PB' => 'JNF007050',
            'YNB,YNH' => 'JNF007020',
            'YNB,YXB,5PS' => 'JNF007150',
            'YNB,YNL' => 'JNF007030',
            'YNB,YNC' => 'JNF007040',
            'YNB,YND' => 'JNF007060',
            'YNB,YNKA' => 'JNF007070',
            'YNB,YNKA,1KBB' => 'JNF007130',
            'YNB,YNR' => 'JNF007080',
            'YNB,YNMW' => 'JNF007140',
            'YNB,YNT' => 'JNF007090',
            'YNB,YXZ' => 'JNF007110',
            'YNB,YNW' => 'JNF007100',
            'YNB,YNMF' => 'JNF007120',
            'YNL,YNGL' => 'JNF063000',
            'YNMH' => 'JNF009000',
            'YPJV' => 'JNF010000',
            'YNK' => 'JNF011000',
            'XQA,YN' => 'JNF062000',
            'XQA,YNB' => 'JNF062010',
            'XQA,YNH' => 'JNF062020',
            'XQA,YNT,YNN' => 'JNF062030',
            'XQA,YX' => 'JNF062040',
            'YNTC' => 'JNF061020',
            'YNTC1' => 'JNF012040',
            'YNVU' => 'JNF021060',
            'YNTC,YNTC2' => 'JNF012030',
            'YBL' => 'JNF013000',
            'YBLA' => 'JNF013120',
            'YBLN1' => 'JNF013100',
            'YBLD' => 'JNF013020',
            'YBLC' => 'JNF013040',
            'YBLJ' => 'JNF013090',
            'YBLF' => 'JNF013050',
            'YBLH' => 'JNF013070',
            'YNPC' => 'JNF014000',
            'YNPH' => 'JNF015000',
            'YNG,YNX' => 'JNF016000',
            'YXK' => 'JNF024070',
            'YXP' => 'JNF069000',
            'YND,YNDS' => 'JNF017000',
            'YXF' => 'JNF019060',
            'YXFF' => 'JNF019050',
            'YXF,YXFD' => 'JNF019020',
            'YXFS' => 'JNF019040',
            'YXFR' => 'JNF019070',
            'YRDM' => 'JNF020000',
            'YRDM,2ACB' => 'JNF020010',
            'YRDM,2ADF' => 'JNF020020',
            'YRDM,2ADS' => 'JNF020030',
            'YNVM' => 'JNF021020',
            'YNVP' => 'JNF021070',
            'YNG' => 'JNF021050',
            'YNPG' => 'JNF022000',
            'YNMF' => 'JNF023000',
            'YXA' => 'JNF024060',
            'YXA,YBLM' => 'JNF024120',
            'YXAB' => 'JNF024010',
            'YXL' => 'JNF024020',
            'YXJ' => 'JNF053040',
            'YXAB,YNW' => 'JNF024040',
            'YXA,YXW' => 'JNF024050',
            'YXLD' => 'JNF024140',
            'YXLD6' => 'JNF024130',
            'YXR' => 'JNF024080',
            'YXAX' => 'JNF024090',
            'YNH' => 'JNF025140',
            'YNH,1H' => 'JNF025010',
            'YNH,1QBA' => 'JNF025020',
            'YNH,1F' => 'JNF025030',
            'YNH,1M' => 'JNF025040',
            'YNH,1KBC' => 'JNF025240',
            'YNH,1KL' => 'JNF025060',
            'YNH,1D' => 'JNF025070',
            'YNHD' => 'JNF025080',
            'YNH,3MPBGJ-DE-H,5PGJ' => 'JNF025090',
            'YNH,3KH,3KL' => 'JNF025100',
            'YNH,1KLCM' => 'JNF025110',
            'YNH,1FB' => 'JNF025120',
            'YNJ' => 'JNF025130',
            'YNH,3B' => 'JNF025150',
            'YNH,3KLY' => 'JNF025160',
            'YNMC' => 'JNF052020',
            'YNH,1KBB' => 'JNF025180',
            'YNH,1KBB,3MLQ-US-B,3MLQ-US-C' => 'JNF025190',
            'YNH,1KBB,3MN' => 'JNF025200',
            'YNH,1KBB,3MNQ-US-E,3MNB-US-D' => 'JNF025270',
            'YNH,1KBB,3MP' => 'JNF025210',
            'YNH,1KBB,3MR' => 'JNF025250',
            'YNMD' => 'JNF026000',
            'YNMD,5HKA' => 'JNF026100',
            'YNMD,YNRM,5HPD' => 'JNF026010',
            'YNMD,YNRM,5HPF' => 'JNF026020',
            'YNMD,5HCP' => 'JNF026030',
            'YNMD,YNRJ,5HPU' => 'JNF026110',
            'YNMD,5PB-US-C' => 'JNF026050',
            'YNMD,YNRJ,5HPV' => 'JNF026120',
            'YNMD,YNMC,5HCF' => 'JNF026130',
            'YNMD,5HCS' => 'JNF026060',
            'YNMD,5HCE' => 'JNF026070',
            'YNMD,5HC' => 'JNF026080',
            'YNMD,5HP' => 'JNF026090',
            'YNP' => 'JNF027000',
            'YNU' => 'JNF028020',
            'YNUC' => 'JNF028010',
            'YXD' => 'JNF053160',
            'YPC' => 'JNF029000',
            'YPCA2' => 'JNF029060',
            'YPCA4' => 'JNF029020',
            'YPCA22' => 'JNF029030',
            'YPC,2S' => 'JNF029050',
            'YPCA23' => 'JNF029040',
            'YNKC' => 'JNF030000',
            'YXB,5PS' => 'JNF053080',
            'YNMK' => 'JNF031000',
            'YNML' => 'JNF033000',
            'YNL' => 'JNF034000',
            'YNTM,YPMF' => 'JNF035050',
            'YPJK' => 'JNF060000',
            'YNC' => 'JNF036090',
            'YNC,6CA' => 'JNF036010',
            'YNC,YPAD' => 'JNF036030',
            'YNC,6JD' => 'JNF036040',
            'YNC,6PB' => 'JNF036050',
            'YNC,6RJ' => 'JNF036060',
            'YNC,6RF' => 'JNF036070',
            'YNCS' => 'JNF036080',
            'YXP,5PM' => 'JNF072000',
            'YNX' => 'JNF008000',
            'YNM' => 'JNF058000',
            'YNM,1H' => 'JNF038010',
            'YNM,1F' => 'JNF038020',
            'YNM,1M' => 'JNF038030',
            'YNM,1KBC' => 'JNF038040',
            'YNM,1KBC,5PBA' => 'JNF038120',
            'YNM,1KJ,1KL' => 'JNF038050',
            'YNM,1D' => 'JNF038060',
            'YNM,1KLCM' => 'JNF038070',
            'YNM,1FB' => 'JNF038080',
            'YNM,1QMP' => 'JNF038090',
            'YNM,1KBB' => 'JNF038130',
            'YNM,1KBB,5PB-US-C' => 'JNF018010',
            'YNM,1KBB,5PB-US-D' => 'JNF018020',
            'YNM,1KBB,5PB-US-H' => 'JNF018030',
            'YNM,1KBB,5PB-US-E' => 'JNF018040',
            'YND' => 'JNF039050',
            'YNDB' => 'JNF039020',
            'YNF' => 'JNF039040',
            'YNRA' => 'JNF040000',
            'YNHA1' => 'JNF066000',
            'YDP' => 'JNF042000',
            'YDP,YNU' => 'JNF042010',
            'YPCA21' => 'JNF046000',
            'YBD' => 'JNF047000',
            'YXZG' => 'JNF065000',
            'YR' => 'JNF048000',
            'YRE' => 'JNF048040',
            'YRW' => 'JNF048020',
            'YRD' => 'JNF048050',
            'YNR,YPJN' => 'JNF049000',
            'YNRX' => 'JNF049010',
            'YNRX,YNRM' => 'JNF049020',
            'YNRF' => 'JNF049320',
            'YNRM' => 'JNF049250',
            'YNRR,1FP' => 'JNF049090',
            'YNRD' => 'JNF049330',
            'YNRP' => 'JNF049100',
            'YNRJ' => 'JNF049110',
            'YNRM,YNUC' => 'JNF049190',
            'YNRM,YNRX' => 'JNF049120',
            'YNRM,YPCA21' => 'JNF049200',
            'YNRM,YXF' => 'JNF049210',
            'YNRM,YNV' => 'JNF049220',
            'YNRM,5HP' => 'JNF049240',
            'YNRM,YBL' => 'JNF049260',
            'YNRM,YNN' => 'JNF049280',
            'YNRM,YXZ' => 'JNF049290',
            'YNRM,YX' => 'JNF049310',
            'YNGL' => 'JNF050000',
            'YNT' => 'JNF051200',
            'YNTA,YPMP1' => 'JNF051030',
            'YNNZ,YPMP51' => 'JNF051040',
            'YNT,YPMP1' => 'JNF051050',
            'YNT,YPMP3' => 'JNF051070',
            'YXZE' => 'JNF051160',
            'YNNV' => 'JNF037080',
            'YNNV,YPJT' => 'JNF051180',
            'YNNB1' => 'JNF037070',
            'YXZG,YPMP6' => 'JNF037020',
            'YNNC' => 'JNF051100',
            'YNTD' => 'JNF061010',
            'YNNT' => 'JNF037040',
            'YNT,YPMP5' => 'JNF051140',
            'YPJJ' => 'JNF052040',
            'YNRU' => 'JNF052030',
            'YNKA' => 'JNF043000',
            'YPJJ5' => 'JNF044000',
            'YX' => 'JNF053200',
            'YXQF' => 'JNF053220',
            'YXZ,YNKA' => 'JNF053270',
            'YXHL' => 'JNF053020',
            'YXG' => 'JNF053030',
            'YXLD,YXLD2' => 'JNF053230',
            'YXZM' => 'JNF053240',
            'YXE' => 'JNF053050',
            'YXHB' => 'JNF053060',
            'YXW' => 'JNF053100',
            'YXQ' => 'JNF053210',
            'YXQD' => 'JNF053170',
            'YXZH' => 'JNF053070',
            'YXPB,YXN' => 'JNF053140',
            'YNW' => 'JNF054220',
            'YNWD3' => 'JNF054010',
            'YNWD4' => 'JNF054020',
            'YNWY' => 'JNF054210',
            'YNW,YNNJ24' => 'JNF054170',
            'YNWD2' => 'JNF054050',
            'YNWD' => 'JNF054120',
            'YNWG' => 'JNF054140',
            'YNWM2' => 'JNF054070',
            'YNWM' => 'JNF054160',
            'YNWD1' => 'JNF054130',
            'YNWW' => 'JNF054150',
            'YPWL' => 'JNF055000',
            'YNL,YPZ,4TM' => 'JNF055010',
            'YPZ,4TN' => 'JNF055030',
            'YPMT' => 'JNF061000',
            'YPMT,YNNZ' => 'JNF051010',
            'YPWE' => 'JNF051020',
            'YPMT5' => 'JNF051090',
            'YNTG' => 'JNF051130',
            'YNVD' => 'JNF056000',
            'YNTR' => 'JNF057050',
            'CB' => 'REF025000',
            'CFLA' => 'LAN001000',
            'GTC' => 'LAN004000',
            'CBW' => 'LAN005060',
            'CJBG' => 'LAN021000',
            'CJCW' => 'LAN007000',
            'KNTP2,JBCT4' => 'LAN008000',
            'CFM' => 'LAN029000',
            'GLM' => 'LAN025000',
            'GLC' => 'LAN025020',
            'GLK' => 'LAN025030',
            'GLH' => 'LAN025040',
            'GLF' => 'LAN025060',
            'GLM,JNU' => 'LAN025050',
            'CF' => 'LAN009000',
            'CFF' => 'LAN009010',
            'CFK' => 'LAN009060',
            'CFH' => 'LAN011000',
            'CFG' => 'LAN015000',
            'CFD' => 'LAN009040',
            'CFB' => 'LAN009050',
            'CFC' => 'LAN010000',
            'CBP' => 'LAN026000',
            'KNTP1' => 'LAN005020',
            'CJBR' => 'LAN012000',
            'CJCR' => 'LAN013000',
            'CFZ,2S' => 'LAN017000',
            'CJCK,CJBG' => 'LAN018000',
            'CFP' => 'LAN023000',
            'CBV,CJCW' => 'LAN005000',
            'CBV' => 'LAN005070',
            'LA' => 'LAW052000',
            'LNDB' => 'LAW001000',
            'LNKF' => 'LAW102000',
            'LBDA' => 'LAW002000',
            'LNAC5' => 'LAW006000',
            'LNZC' => 'LAW004000',
            'LNCH' => 'LAW005000',
            'LNPB' => 'LAW007000',
            'LNPC' => 'LAW008000',
            'LNC,LNP' => 'LAW009000',
            'LNMK,LASD' => 'LAW010000',
            'LAFD' => 'LAW011000',
            'LNAC' => 'LAW064000',
            'LNDC' => 'LAW013000',
            'LNCB' => 'LAW014000',
            'LBBM' => 'LAW014010',
            'LAFC' => 'LAW103000',
            'LNQ' => 'LAW104000',
            'LAM' => 'LAW016000',
            'LBG' => 'LAW017000',
            'LNDX' => 'LAW018000',
            'LNCQ' => 'LAW019000',
            'LNTU' => 'LAW020000',
            'LNCJ' => 'LAW021000',
            'LNCD' => 'LAW022000',
            'LNAA,LNZC' => 'LAW023000',
            'LNAA' => 'LAW025000',
            'LNF' => 'LAW026000',
            'LNFQ' => 'LAW026010',
            'LNFX1' => 'LAW026020',
            'LNFX' => 'LAW027000',
            'LAFF' => 'LAW028000',
            'LNJD' => 'LAW106000',
            'LNAC3' => 'LAW091000',
            'LA,GBCD' => 'LAW030000',
            'LNTQ' => 'LAW031000',
            'LNHD' => 'LAW094000',
            'LN,JKVG' => 'LAW118000',
            'LNTD' => 'LAW092000',
            'LNTS' => 'LAW107000',
            'LNDS' => 'LAW108000',
            'LNDA1' => 'LAW032000',
            'LNJ' => 'LAW096000',
            'LNKJ' => 'LAW034000',
            'LNW,LNL' => 'LAW035000',
            'LATC' => 'LAW036000',
            'LNM' => 'LAW038000',
            'LNMK' => 'LAW038010',
            'LNMB' => 'LAW038030',
            'LAR,JKVF1' => 'LAW041000',
            'LAQG' => 'LAW043000',
            'LAS' => 'LAW044000',
            'LNDH' => 'LAW039000',
            'LNDU,LNDV' => 'LAW089000',
            'LNTJ' => 'LAW046000',
            'LNSH9,LNFG2' => 'LAW047000',
            'LA,5PBA' => 'LAW110000',
            'LNPN' => 'LAW049000',
            'LNR' => 'LAW050000',
            'LNRC' => 'LAW050010',
            'LNRD' => 'LAW050020',
            'LNRF' => 'LAW050030',
            'LB' => 'LAW051000',
            'LAFS,LW' => 'LAW119000',
            'LNAA1' => 'LAW111000',
            'LNAA12,LNFY' => 'LAW053000',
            'LNH' => 'LAW054000',
            'LNSH,LNFG2' => 'LAW055000',
            'LNSH3' => 'LAW112000',
            'LAT,KJWB' => 'LAW056000',
            'LAT,JNM' => 'LAW059000',
            'LAZ' => 'LAW060000',
            'LAT' => 'LAW062000',
            'LASH' => 'LAW063000',
            'LNB,LNDJ,LNV' => 'LAW113000',
            'LNW' => 'LAW090000',
            'LNAL,LATC' => 'LAW095000',
            'LBDM,LNCB5' => 'LAW066000',
            'LNTM' => 'LAW093000',
            'LNTM1' => 'LAW067000',
            'LNCD1' => 'LAW114000',
            'LNDK' => 'LAW068000',
            'LAB' => 'LAW069000',
            'LNCR' => 'LAW070000',
            'LASP' => 'LAW071000',
            'LNPP' => 'LAW115000',
            'LNVJ' => 'LAW097000',
            'VSD' => 'LAW098000',
            'LNDC2' => 'LAW116000',
            'LNS' => 'LAW074000',
            'LNX,LNEF' => 'LAW075000',
            'LNDB,LNCJ' => 'LAW076000',
            'LNK,KNB' => 'LAW077000',
            'LNSH' => 'LAW078000',
            'LA,GBC' => 'LAW079000',
            'LNV' => 'LAW087000',
            'LASN' => 'LAW081000',
            'LNTM,JBFV4' => 'LAW082000',
            'LNDB8' => 'LAW099000',
            'LNPD' => 'LAW083000',
            'LNJS' => 'LAW084000',
            'LNU' => 'LAW086000',
            'LNKT' => 'LAW117000',
            'LAS,LNFY' => 'LAW088000',
            'DNT' => 'LCO019000',
            'DNT,1H' => 'LCO001000',
            'DNT,1KBB' => 'LCO002000',
            'DNT,1KBB,5PB-US-C' => 'LCO002010',
            'DB' => 'LCO003000',
            'DNT,1F' => 'LCO004000',
            'DNT,1FPC' => 'LCO004010',
            'DNT,1FKA' => 'LCO004020',
            'DNT,1FPJ' => 'LCO004030',
            'DNT,1M' => 'LCO005000',
            'DNT,1KBC' => 'LCO006000',
            'DNT,1KJ,1KL' => 'LCO007000',
            'DND' => 'LCO011000',
            'DNT,1D' => 'LCO008000',
            'DNT,1DT' => 'LCO008010',
            'DNT,1DDU' => 'LCO009000',
            'DNT,1DDF' => 'LCO008020',
            'DNT,1DFG' => 'LCO008030',
            'DNT,1DST' => 'LCO008040',
            'DNT,1DN' => 'LCO008050',
            'DNT,1DSE,1DSP' => 'LCO008060',
            'DNL' => 'LCO010000',
            'DNT,1K,5PBA' => 'LCO013000',
            'DNPB' => 'LCO020000',
            'DNT,5PS' => 'LCO016000',
            'DB,6MB' => 'LCO017000',
            'DNT,1FB' => 'LCO012000',
            'DNT,1DTA,1QBDR' => 'LCO014000',
            'DNS' => 'LCO018000',
            'DS' => 'LIT025000',
            'DS,1H' => 'LIT004010',
            'DS,1KBB' => 'LIT023000',
            'DS,1KBB,5PB-US-C' => 'LIT004040',
            'DS,1KBB,5PB-US-D' => 'LIT004030',
            'DS,1KBB,5PB-US-H' => 'LIT004050',
            'DSBB' => 'LIT004190',
            'DS,1F' => 'LIT008000',
            'DS,1FPC' => 'LIT008010',
            'DS,1FKA' => 'LIT008020',
            'DS,1FPJ' => 'LIT008030',
            'DS,1M' => 'LIT004070',
            'DS,1KBC' => 'LIT004080',
            'DS,1KJ,1KL' => 'LIT004100',
            'DSY' => 'LIT009000',
            'DSK,XR' => 'LIT017000',
            'DSM' => 'LIT020000',
            'DSG' => 'LIT013000',
            'DS,1D' => 'LIT004130',
            'DS,1DT' => 'LIT004110',
            'DS,1DDU,1DDR' => 'LIT004120',
            'DS,1DDF' => 'LIT004150',
            'DS,1DFG' => 'LIT004170',
            'DS,1DST' => 'LIT004200',
            'DS,1DN' => 'LIT004250',
            'DS,1DSE,1DSP' => 'LIT004280',
            'DS,JBGB' => 'LIT022000',
            'DS,JBSF11' => 'LIT003000',
            'DSK,6GA,6RA' => 'LIT004180',
            'DSK,FK' => 'LIT021000',
            'DSK,WH' => 'LIT016000',
            'DS,1K,5PBA' => 'LIT004060',
            'DS,JBSR,5PGJ' => 'LIT004210',
            'DS,5PS' => 'LIT004160',
            'DSBB,6MB' => 'LIT011000',
            'DS,1FB' => 'LIT004220',
            'DSB' => 'LIT024000',
            'DSBC,3MD' => 'LIT024010',
            'DSBD,3MG' => 'LIT024020',
            'DSBD,3ML' => 'LIT024030',
            'DSBF' => 'LIT024040',
            'DSBH' => 'LIT024050',
            'DSBJ' => 'LIT024060',
            'DSK,FF' => 'LIT004230',
            'DSC' => 'LIT014000',
            'DSR,6RC' => 'LIT012000',
            'DSBC' => 'LIT019000',
            'DS,1DTA,1QBDR' => 'LIT004240',
            'DSK,FL,FM' => 'LIT004260',
            'DSA,GTD' => 'LIT006000',
            'DSG,5PX-GB-S' => 'LIT015000',
            'DSK,FYB' => 'LIT018000',
            'DS,NH' => 'LIT025010',
            'DS,WN' => 'LIT025020',
            'DS,JP' => 'LIT025030',
            'DS,QR' => 'LIT025040',
            'DS,JBSF1' => 'LIT004290',
            'PB' => 'MAT027000',
            'PBF' => 'MAT019000',
            'PBW' => 'MAT003000',
            'PBC' => 'MAT006000',
            'PBKA' => 'MAT005000',
            'PBV' => 'MAT013000',
            'PBKD' => 'MAT040000',
            'PBKJ' => 'MAT007020',
            'PBD' => 'MAT009000',
            'PBKF' => 'MAT031000',
            'PBUD' => 'MAT011000',
            'PBM' => 'MAT012000',
            'PBMW' => 'MAT012010',
            'PBMS' => 'MAT012020',
            'PBMP' => 'MAT012030',
            'PBML' => 'MAT012040',
            'PBG' => 'MAT014000',
            'PBX,PBB' => 'MAT015000',
            'PBUH' => 'MAT017000',
            'PBCD' => 'MAT018000',
            'PBK' => 'MAT033000',
            'PDD' => 'SCI068000',
            'PBCN' => 'MAT021000',
            'PBH' => 'MAT022000',
            'PBKS' => 'MAT041000',
            'PBU' => 'MAT042000',
            'PBJ' => 'MAT023000',
            'PBT' => 'MAT029050',
            'PBTB' => 'MAT029010',
            'PBWL' => 'MAT029040',
            'PDZM,WDKN' => 'MAT025000',
            'PB,GBC' => 'MAT026000',
            'PBCH' => 'MAT028000',
            'PB,JNU' => 'MAT030000',
            'PBP' => 'MAT038000',
            'PBMB' => 'MAT032000',
            'MB' => 'MED109000',
            'MX,VXHA' => 'MED001000',
            'MBPM' => 'MED095000',
            'MJCJ2' => 'MED022020',
            'MQ' => 'MED003000',
            'MQF' => 'MED087000',
            'MX,VXHP' => 'MED003020',
            'MKS,MQH' => 'MED003070',
            'MX,VFMS' => 'MED003090',
            'MQG' => 'MED003030',
            'MBG' => 'MED108000',
            'MQT' => 'MED003050',
            'MQS,MQV' => 'MED003060',
            'MJL' => 'MED079000',
            'MX' => 'MED004000',
            'MFC' => 'MED005000',
            'MKA' => 'MED006000',
            'MRT' => 'MED101000',
            'MJPD,MKZL' => 'MED007000',
            'MKZF' => 'MED111000',
            'MF,PSB' => 'MED008000',
            'MBGR,MBNS' => 'MED090000',
            'MF,TCB' => 'MED009000',
            'MJD' => 'MED010000',
            'MQ,VFG' => 'MED041000',
            'MJCL2' => 'MED012000',
            'MXH' => 'MED092000',
            'MJ' => 'MED045000',
            'MKPL' => 'MED015000',
            'MKE' => 'MED016020',
            'MKE,MQG' => 'MED016010',
            'MKEP' => 'MED085020',
            'MKEH' => 'MED016070',
            'MKED' => 'MED016030',
            'MKE,MBPM' => 'MED016090',
            'MJK' => 'MED017000',
            'MJA' => 'MED018000',
            'MKS' => 'MED019000',
            'MQH' => 'MED019010',
            'MKSF' => 'MED098000',
            'MR,GBCD' => 'MED020000',
            'MBNH3' => 'MED060000',
            'MJC' => 'MED091000',
            'MKG' => 'MED071000',
            'MR' => 'MED081000',
            'MFKC3' => 'MED025000',
            'MKP' => 'MED026000',
            'MJG,MFGM' => 'MED027000',
            'MKV' => 'MED116000',
            'MBNS' => 'MED028000',
            'MBDC' => 'MED050000',
            'MBGR' => 'MED106000',
            'MBPC' => 'MED029000',
            'MKT' => 'MED030000',
            'MJH' => 'MED031000',
            'MFN' => 'MED107000',
            'MKN' => 'MED032000',
            'MKC' => 'MED033000',
            'MBP' => 'MED043000',
            'MBQ' => 'MED036000',
            'MBNC' => 'MED037000',
            'MJF' => 'MED038000',
            'MJJ' => 'MED114000',
            'MFCH' => 'MED110000',
            'MBX' => 'MED039000',
            'MX,VXH' => 'MED040000',
            'MJCM' => 'MED044000',
            'MBN' => 'MED078000',
            'MJCJ' => 'MED022090',
            'MBF' => 'MED051000',
            'MBGL' => 'MED047000',
            'MBG,TTBL' => 'MED048000',
            'MBPN' => 'MED059000',
            'MBPR' => 'MED049000',
            'MKLD' => 'MED102000',
            'MKFM' => 'MED052000',
            'MK,JW' => 'MED118000',
            'MJR' => 'MED055000',
            'MKJ' => 'PSY022090',
            'MKJ,PSAN' => 'MED057000',
            'MQC' => 'MED058000',
            'MQCL,MKA' => 'MED058010',
            'MQCA,MJA' => 'MED058020',
            'MQCA' => 'MED058050',
            'MQCL2' => 'MED058030',
            'MQCL1' => 'MED058040',
            'MQCL4' => 'MED058060',
            'MQCX' => 'MED058070',
            'MQCL,MQG' => 'MED058100',
            'MQCZ' => 'MED058110',
            'MQCL,MKC' => 'MED058120',
            'MQCL6' => 'MED058220',
            'MQCH' => 'MED058140',
            'MQCL,MBNH3' => 'MED058150',
            'MQCL,MJCL' => 'MED058160',
            'MQCL9,MKB' => 'MED058230',
            'MQCL3' => 'MED058080',
            'MQCM' => 'MED058170',
            'MQCL5' => 'MED058180',
            'MQC,MR' => 'MED058190',
            'MQCB' => 'MED058200',
            'MQCW,MBDC,MBQ' => 'MED058090',
            'MQC,MRG' => 'MED058210',
            'MKVP' => 'MED061000',
            'MJCL' => 'MED062010',
            'MJCL,MJF' => 'MED062020',
            'MJCL,MJL' => 'MED062030',
            'MJCL,MKD' => 'MED062040',
            'MJCL,MJS' => 'MED062050',
            'MJCL,MJK' => 'MED062060',
            'MJQ' => 'MED063000',
            'MQR' => 'MED064000',
            'MJE' => 'MED065000',
            'MJP' => 'MED066000',
            'MKAL' => 'MED093000',
            'MKFP' => 'MED103000',
            'MKF' => 'MED067000',
            'MKF,MFG' => 'MED068000',
            'MKD,MKP' => 'MED094000',
            'MKD' => 'MED069000',
            'MKCM,MKDN' => 'MED070000',
            'MQP' => 'MED072000',
            'MQV' => 'MED073000',
            'MBDP' => 'MED074000',
            'MBD' => 'MED104000',
            'MFG' => 'MED075000',
            'MQK' => 'MED100000',
            'MQWP' => 'MED077000',
            'MKL' => 'MED105000',
            'MKL,MKD' => 'MED105010',
            'MKGW' => 'MED105020',
            'MKSH,MJCL1,MKR' => 'MED080000',
            'MKH' => 'MED121000',
            'MFKC' => 'MED082000',
            'MJM' => 'MED083000',
            'MKZS' => 'MED119000',
            'MKW' => 'MED084000',
            'MN' => 'MED085000',
            'MND' => 'MED085090',
            'MNH' => 'MED085040',
            'MNG' => 'MED085060',
            'MNPC,MNP' => 'MED085030',
            'MN,MJQ' => 'MED085100',
            'MNB' => 'MED085080',
            'MNN' => 'MED085010',
            'MNK' => 'MED085110',
            'MNS' => 'MED085120',
            'MN,MJP' => 'MED085130',
            'MN,MKD' => 'MED085140',
            'MN,MFKC,MJS' => 'MED085150',
            'MNQ' => 'MED085070',
            'MNJ' => 'MED085050',
            'MBGT' => 'MED120000',
            'MKB' => 'MED042000',
            'MRG' => 'MED086000',
            'MKGT' => 'MED096000',
            'MKVT' => 'MED097000',
            'MJS' => 'MED088000',
            'MZ' => 'MED089000',
            'MZT' => 'MED089040',
            'MZDH' => 'MED089010',
            'MZD' => 'MED089020',
            'MZC' => 'MED089030',
            'MZS' => 'MED089050',
            'AV' => 'MUS001000',
            'AV,KNTF' => 'MUS004000',
            'AVD' => 'MUS012000',
            'AV,5PB' => 'MUS014000',
            'AVA' => 'MUS054000',
            'AVL' => 'MUS049000',
            'AVLA,ATQL' => 'MUS002000',
            'AVLP,6SS' => 'MUS053000',
            'AVLP,6BM' => 'MUS003000',
            'AVLA,6CA' => 'MUS006000',
            'AVL,5LC' => 'MUS026000',
            'AVLC' => 'MUS051000',
            'AVLT,6CM,6BL' => 'MUS010000',
            'AVLP,ATQ' => 'MUS011000',
            'AVLX,AVRS' => 'MUS013000',
            'AVLT,6FD' => 'MUS017000',
            'AVLP,6HA' => 'MUS019000',
            'AVLW' => 'MUS024000',
            'AVLP,6JD' => 'MUS025000',
            'AVLW,6LC' => 'MUS036000',
            'AVLA' => 'MUS045000',
            'AVLP,AVLM' => 'MUS046000',
            'AVLP,6NK' => 'MUS027000',
            'AVLF' => 'MUS028000',
            'AVLP,6PB' => 'MUS029000',
            'AVLP,6PN' => 'MUS030000',
            'AVLP,6RJ' => 'MUS031000',
            'AVLP,6RK' => 'MUS047000',
            'AVLP,6RF,6RG' => 'MUS035000',
            'AVLP,6SB,6RH' => 'MUS039000',
            'AVM,AVC' => 'MUS020000',
            'AVN,AVP' => 'MUS050000',
            'AVS' => 'MUS040000',
            'AVSD' => 'MUS038000',
            'AVSA' => 'MUS042000',
            'AVQ' => 'MUS037050',
            'AVR' => 'MUS023000',
            'AVRN1' => 'MUS023010',
            'AVRL1' => 'MUS023060',
            'AVRJ' => 'MUS023020',
            'AVRG' => 'MUS023030',
            'AVRL' => 'MUS023040',
            'AVRN2' => 'MUS023050',
            'AVQ,AVN,AVP' => 'MUS037010',
            'AVQ,AVLA' => 'MUS037020',
            'AVQ,AVRN1' => 'MUS037120',
            'AVQ,AVLC' => 'MUS037030',
            'AVQ,AVRL1' => 'MUS037040',
            'AVQ,AVLM' => 'MUS037060',
            'AVQ,AVLA,AVLF' => 'MUS037070',
            'AVQ,AVRJ' => 'MUS037080',
            'AVQ,AVRG,AVRG1' => 'MUS037090',
            'AVQS' => 'MUS037110',
            'AVQ,AVRL' => 'MUS037130',
            'AVQ,AVRN2' => 'MUS037140',
            'AVX' => 'MUS032000',
            'AV,GBC' => 'MUS033000',
            'AVLK' => 'MUS048000',
            'AVLK,5PGM' => 'MUS009000',
            'AVLK,6GD' => 'MUS018000',
            'AVLK,QRVJ1' => 'MUS021000',
            'AVLK,5PGJ' => 'MUS048020',
            'AVLK,5PGP' => 'MUS048030',
            'WN' => 'NAT049000',
            'JBFU' => 'NAT039000',
            'WNC' => 'NAT037000',
            'WNCF' => 'NAT044000',
            'WNCB' => 'NAT004000',
            'WNCN' => 'NAT017000',
            'WNA' => 'NAT007000',
            'WNCS,PSVC' => 'NAT012000',
            'WNGH' => 'PET006000',
            'WNCS' => 'NAT020000',
            'WNCF,PSVM3' => 'NAT002000',
            'WNCK' => 'NAT028000',
            'WNW,RBC' => 'NAT009000',
            'RNC' => 'NAT010000',
            'WNW,RGB' => 'NAT045000',
            'WNW,RGBP' => 'NAT045050',
            'WNW,RGBA' => 'NAT045010',
            'WNW,RGBL' => 'NAT014000',
            'WNW,RGBG' => 'NAT029000',
            'WNW,RGBS' => 'NAT041000',
            'WNW,RBKC' => 'NAT025000',
            'WNW,RGBC' => 'NAT045020',
            'WNW,RGBU,1QMP' => 'NAT045030',
            'WNW' => 'NAT032000',
            'RNKH1' => 'NAT046000',
            'RNK' => 'NAT011000',
            'WNR' => 'NAT030000',
            'RNR' => 'NAT023000',
            'RNF' => 'NAT038000',
            'WNP' => 'NAT034000',
            'WN,GBC' => 'NAT027000',
            'WNCS1' => 'NAT031000',
            'WNX' => 'NAT033000',
            'WNWM' => 'NAT036000',
            'AT' => 'PER000000',
            'ATDC' => 'PER023000',
            'ATFV' => 'PER004080',
            'AT,KNT' => 'PER014000',
            'ATXC' => 'PER002000',
            'ATXD' => 'PER022000',
            'ATQ' => 'PER021000',
            'ATQR' => 'PER003090',
            'ATQC' => 'PER003050',
            'ATQL,6CA' => 'PER003010',
            'ATQZ,6FD' => 'PER003020',
            'ATQ,ATY' => 'PER003100',
            'ATQT,6JD' => 'PER003030',
            'ATQT' => 'PER003080',
            'ATQ,GBC' => 'PER003070',
            'ATF' => 'PER004000',
            'ATFX' => 'PER004010',
            'ATFN' => 'PER004060',
            'ATFN,ATMB' => 'PER004150',
            'ATFN,ATMC' => 'PER004090',
            'ATFR' => 'PER004110',
            'ATFN,ATMH' => 'PER004120',
            'ATFN,ATMN' => 'PER004140',
            'ATFG' => 'PER004020',
            'ATFA' => 'PER004030',
            'ATF,GBC' => 'PER004040',
            'CBVS,ATFD' => 'PER004050',
            'ATFB' => 'PER018000',
            'ATDC,DDV' => 'PER020000',
            'ATXM' => 'PER007000',
            'ATL' => 'PER008000',
            'ATL,ATY' => 'PER008010',
            'ATL,GBC' => 'PER008020',
            'AT,GBC' => 'PER009000',
            'ATFD,ATJD' => 'PER016000',
            'ATJ' => 'PER010020',
            'ATJX' => 'PER010010',
            'ATJS' => 'PER010070',
            'ATJS,ATMC' => 'PER010080',
            'ATJS,ATMF' => 'PER010090',
            'ATJS,ATJS1' => 'PER010100',
            'ATJS,ATMN' => 'PER010110',
            'ATJ,ATY' => 'PER010030',
            'ATJ,GBC' => 'PER010040',
            'CBVS,ATJD' => 'PER010050',
            'ATD' => 'PER013000',
            'ATDF' => 'PER011010',
            'ATD,ATY' => 'PER011020',
            'CBV,ATDF' => 'PER011030',
            'ATDH' => 'PER011040',
            'WNG' => 'PET010000',
            'WNGK' => 'PET002000',
            'WNGC' => 'PET003010',
            'WNGD' => 'PET004010',
            'WNGD1' => 'PET004020',
            'WNGF' => 'PET005000',
            'WNG,MZL' => 'PET012000',
            'WNGX' => 'PET013000',
            'WNGR' => 'PET011000',
            'WNG,GBC' => 'PET008000',
            'WNGS' => 'PET009000',
            'QD' => 'PHI014000',
            'QDTN' => 'PHI001000',
            'QDHC,QRF' => 'PHI028000',
            'QDH' => 'PHI044000',
            'QDHC' => 'PHI003000',
            'QDTK' => 'PHI004000',
            'QDTQ' => 'PHI030000',
            'QDT' => 'PHI007000',
            'QDT,CFP' => 'PHI036000',
            'QDHC,QRD' => 'PHI033000',
            'QDHA' => 'PHI002000',
            'QDHF' => 'PHI012000',
            'QDH,NHDL' => 'PHI037000',
            'QDHR' => 'PHI045000',
            'CFA' => 'PHI038000',
            'QDTL' => 'PHI011000',
            'QDTJ' => 'PHI013000',
            'QDTM' => 'PHI015000',
            'QDHR9' => 'PHI039000',
            'QDTS1' => 'PHI040000',
            'QDHR7' => 'PHI029000',
            'QDHM' => 'PHI032000',
            'QDHR5' => 'PHI018000',
            'QDHH' => 'PHI010000',
            'QDHR1' => 'PHI042000',
            'QDHR3' => 'PHI020000',
            'QDTS' => 'PHI034000',
            'QD,GBC' => 'PHI021000',
            'QRAB' => 'REL051000',
            'QDHC,QRRL5' => 'PHI023000',
            'QDHC,QRFB23' => 'PHI025000',
            'AJ' => 'PHO023000',
            'AJC,GBCY' => 'PHO025000',
            'AJTF,WNX' => 'PHO026000',
            'AJ,ABQ' => 'PHO003000',
            'AJC' => 'PHO014000',
            'AJ,AKL' => 'PHO021000',
            'AJTF,JKVF1' => 'PHO027000',
            'AJ,AGA' => 'PHO010000',
            'AJCD' => 'PHO011030',
            'AJF' => 'PHO023120',
            'AJ,GBC' => 'PHO017000',
            'AJTF' => 'PHO023130',
            'AJTF,AM,AGP' => 'PHO001000',
            'AJCP' => 'PHO016000',
            'AJCX' => 'PHO023050',
            'AJTF,AKT' => 'PHO009000',
            'AJTF,WB' => 'PHO023110',
            'AJ,NH' => 'PHO023100',
            'AJTF,AGNL' => 'PHO023040',
            'AJTF,AGN' => 'PHO013000',
            'AJ,WTM' => 'PHO019000',
            'AJF,SC' => 'PHO023060',
            'AJT' => 'PHO007000',
            'AJTV,ATFX' => 'PHO022000',
            'AJTH' => 'PHO024000',
            'AJTS' => 'PHO012000',
            'DC' => 'POE024000',
            'DC,1H' => 'POE007000',
            'DC,1KBB' => 'POE005010',
            'DC,1KBB,5PB-US-C' => 'POE005050',
            'DC,1KBB,5PB-US-D' => 'POE005060',
            'DC,1KBB,5PB-US-H' => 'POE005070',
            'DC,1KBB,5PB-US-E' => 'POE015000',
            'DCA,DB' => 'POE008000',
            'DCQ' => 'POE001000',
            'DC,1F' => 'POE009000',
            'DC,1FPC' => 'POE009010',
            'DC,1FPJ' => 'POE009020',
            'DC,1M' => 'POE010000',
            'DC,1KBC' => 'POE011000',
            'DC,1KBC,5PBA' => 'POE011010',
            'DC,1KJ,1KL' => 'POE012000',
            'DC,1D' => 'POE005030',
            'DC,1DDU,1DDR' => 'POE005020',
            'DC,1DDF' => 'POE017000',
            'DC,1DFG' => 'POE018000',
            'DC,1DST' => 'POE019000',
            'DC,1DSE,1DSP' => 'POE020000',
            'DCA,6EH' => 'POE014000',
            'DCRB' => 'POE025000',
            'DC,5PS' => 'POE021000',
            'DCA,DB,6MB' => 'POE022000',
            'DC,1FB' => 'POE013000',
            'DC,1DTA,1QBDR' => 'POE016000',
            'DCF,5PX-GB-S' => 'POE026000',
            'DC,FXL' => 'POE023010',
            'DC,QR' => 'POE003000',
            'DC,FXD' => 'POE023020',
            'DC,FXE' => 'POE023030',
            'DC,FXR' => 'POE023040',
            'JP' => 'POL040020',
            'JP,1KBB' => 'POL040000',
            'JPQ,1KBB' => 'POL030000',
            'JPT,1KBB' => 'POL040040',
            'JPR,1KBB' => 'POL020000',
            'JBFV3' => 'POL039000',
            'JPVC' => 'POL003000',
            'JPVH' => 'POL035010',
            'NHTQ,JP' => 'POL047000',
            'JPB' => 'POL009000',
            'JPHC' => 'POL022000',
            'JPZ' => 'POL064000',
            'JPA' => 'POL051000',
            'JWXK,NHTZ' => 'POL061000',
            'JPSL' => 'POL062000',
            'JPSH' => 'POL036000',
            'JPSN' => 'POL048000',
            'JPS' => 'POL011000',
            'JPSF' => 'POL001000',
            'JPSD' => 'POL011010',
            'LBBC' => 'POL021000',
            'KNXN,KNXU' => 'POL013000',
            'JKSW1' => 'POL014000',
            'JPWH' => 'POL041000',
            'GTU' => 'POL034000',
            'JPV' => 'POL049000',
            'JPF' => 'POL042040',
            'JPFB' => 'POL042010',
            'JPFC,JPFF' => 'POL005000',
            'JPFM,JPFK' => 'POL042020',
            'JPHV' => 'POL007000',
            'JPHX' => 'POL042030',
            'JPFN' => 'POL031000',
            'JPH' => 'POL016000',
            'JPWC,JPHF' => 'POL008000',
            'JPH,JBCT' => 'POL065000',
            'JPW' => 'POL043000',
            'JPL' => 'POL015000',
            'JBFL' => 'POL066000',
            'JPP' => 'POL017000',
            'JPWA' => 'POL071000',
            'JPQB' => 'POL028000',
            'JPQB,TV' => 'POL067000',
            'RPC' => 'POL002000',
            'JPQB,TJK' => 'POL050000',
            'JPQB,JBCC8' => 'POL038000',
            'JPQB,KCP' => 'POL024000',
            'JPQB,RNFY' => 'POL068000',
            'RND' => 'POL044000',
            'JPQB,KCVJ' => 'POL073000',
            'JPQB,JBFH' => 'POL070000',
            'JPQB,JW' => 'POL069000',
            'RP' => 'POL026000',
            'JPQB,PDK' => 'POL063000',
            'JPQB,JB' => 'POL029000',
            'JKS' => 'SOC016000',
            'JP,GBC' => 'POL018000',
            'QRAM2,JPFR' => 'POL072000',
            'JW' => 'POL012000',
            'JPWL' => 'POL037000',
            'JP,JBSF1' => 'POL052000',
            'JP,1H' => 'POL053000',
            'JP,1F' => 'POL054000',
            'JP,1M' => 'POL055000',
            'JP,1KBC' => 'POL056000',
            'JP,1KJ,1KL' => 'POL057000',
            'JP,1D' => 'POL058000',
            'JP,1FB' => 'POL059000',
            'JP,1DTA,1QBDR' => 'POL060000',
            'JM' => 'PSY036000',
            'JMBT' => 'PSY042000',
            'MKM' => 'PSY007000',
            'JMR,JMM' => 'PSY051000',
            'JMR' => 'PSY034000',
            'JMC' => 'PSY044000',
            'JMC,JBSP2' => 'PSY002000',
            'JMC,JMD,JBSP3' => 'PSY043000',
            'JMC,JBSP1' => 'PSY004000',
            'JMQ' => 'PSY052000',
            'JMH,JBSL' => 'PSY050000',
            'JMA,PSAJ' => 'PSY053000',
            'JML' => 'PSY040000',
            'JMK' => 'PSY014000',
            'JMU' => 'PSY016000',
            'JMT' => 'PSY035000',
            'JMJ' => 'PSY021000',
            'JMHC,JHBK' => 'PSY017000',
            'JMA' => 'PSY045030',
            'JMAL' => 'PSY045010',
            'MKMT6,JMAQ' => 'PSY045070',
            'JMAN' => 'PSY045020',
            'JMAJ' => 'PSY045060',
            'JMAF' => 'PSY026000',
            'JMM' => 'PSY024000',
            'JMS' => 'PSY023000',
            'JM,MBPM' => 'PSY046000',
            'JMP' => 'PSY022050',
            'MKZR' => 'PSY038000',
            'MKJA' => 'PSY022020',
            'MKZD' => 'PSY011000',
            'JMP,JMS' => 'PSY022080',
            'JMP,MKPB' => 'PSY022040',
            'MKMT' => 'PSY028000',
            'MKMT3' => 'PSY006000',
            'MKMT5' => 'PSY010000',
            'MKMT4' => 'PSY041000',
            'MKMT2' => 'PSY048000',
            'MKMT,5PS' => 'PSY056000',
            'JM,GBC' => 'PSY029000',
            'JMB' => 'PSY032000',
            'JMH' => 'PSY031000',
            'JM,JHBZ' => 'PSY037000',
            'GB' => 'REF000000',
            'GBCY' => 'REF027000',
            'RGX' => 'REF002000',
            'GBCR' => 'REF004000',
            'VSG' => 'REF030000',
            'GBD' => 'REF007000',
            'CBD' => 'REF008000',
            'GBCT' => 'REF009000',
            'GBA' => 'REF010000',
            'WJX' => 'REF011000',
            'WJX,KNSJ' => 'REF032000',
            'NHTG,WQY' => 'REF013000',
            'VS' => 'SEL044000',
            'WZS' => 'NON000000',
            'GBCQ' => 'REF019000',
            'GPS' => 'REF020000',
            'GTT' => 'REF034000',
            'CBF' => 'REF022000',
            'WJW' => 'REF024000',
            'QR' => 'REL077000',
            'QRYA5' => 'REL004000',
            'QRS' => 'REL114000',
            'QRA,NK' => 'REL072000',
            'QRRB' => 'REL005000',
            'DNBX,QRMF1' => 'REL006020',
            'DNBX,QRMF12,QRJF1' => 'REL006030',
            'DNBX,QRMF13' => 'REL006040',
            'QRVC,QRMF1' => 'REL006140',
            'QRVC,QRMF12,QRJF1' => 'REL006730',
            'QRVC,QRMF12,QRMF14,QRJF1' => 'REL006880',
            'QRVC,QRMF13' => 'REL006870',
            'QRMF19' => 'REL006150',
            'QRMF19,QRMF12,QRJF1' => 'REL006120',
            'QRMF19,QRMF13' => 'REL006130',
            'QRVC,QRMF1,NHTP1' => 'REL006650',
            'QRVC,QRMF13,QRMF14' => 'REL006890',
            'QRAM7' => 'REL115000',
            'QRF' => 'REL007000',
            'QRF,QRAX' => 'REL007010',
            'QRFP' => 'REL007020',
            'QRFF' => 'REL007030',
            'QRFB' => 'REL007040',
            'QRFB21' => 'REL007050',
            'QRFB23' => 'REL092000',
            'QRMB' => 'REL094000',
            'QRMB,QRVS' => 'REL108010',
            'QRMB,LAFX' => 'REL008000',
            'QRMB,QRAX' => 'REL108020',
            'QRMP,QRVP3' => 'REL091000',
            'QRMP,5PGM' => 'REL063000',
            'QRMP,QRVS3,5PGM' => 'REL012140',
            'QRMP,QRVL,VFJX,5PGM' => 'REL012010',
            'QRMP,QRVJ3,5PGM' => 'REL012150',
            'QRMP,VFV,5PGM' => 'REL012030',
            'QRMP,QRVX,5PGM' => 'REL012120',
            'QRMP,QRVP7,5PGM' => 'REL012050',
            'QRMP,QRVP7,5PGM,5JB' => 'REL012060',
            'QRMP,VFX,5PGM' => 'REL012160',
            'QRMP,DNC,5PGM' => 'REL012170',
            'QRMP,QRVJ2,5PGM' => 'REL012080',
            'QRMP,VSC,5PGM' => 'REL012090',
            'QRMP,QRVS2,5PGM' => 'REL012110',
            'QRMP,QRVP7,5PGM,5JA' => 'REL012130',
            'QRM,QRVS3' => 'REL109020',
            'QRM,QRVP5' => 'REL050000',
            'QRM,QRVX' => 'REL023000',
            'QRM,QRVS4' => 'REL045000',
            'QRM,QRVS2' => 'REL080000',
            'QRM,QRVS3,5AC' => 'REL109030',
            'QRMP,QRVJ1' => 'REL055000',
            'QRMP1' => 'REL055010',
            'QRMP,QRVJ' => 'REL055020',
            'QRM,QRVG' => 'REL067110',
            'QRM,QRAB9' => 'REL067060',
            'QRM,QRAM1' => 'REL067070',
            'QRM,QRAX' => 'REL015000',
            'QRM,QRAB7' => 'REL067100',
            'QRM' => 'REL070000',
            'QRMB3,5PB-US-B' => 'REL043000',
            'QRMB31' => 'REL027000',
            'QRMB32' => 'REL073000',
            'QRMB33' => 'REL111000',
            'QRM,QRVP3' => 'REL009000',
            'QRMB1' => 'REL010000',
            'QRMB5' => 'REL098000',
            'QRM,AB' => 'REL013000',
            'QRMB34' => 'REL082000',
            'QRMB35' => 'REL044000',
            'QRMB2' => 'REL049000',
            'QRMB36' => 'REL079000',
            'QRMB3' => 'REL053000',
            'QRMB37' => 'REL088000',
            'QRM,QRVS1' => 'REL110000',
            'QRMB39' => 'REL059000',
            'QRVS3' => 'REL081000',
            'QRAC' => 'REL017000',
            'QRRL1' => 'REL018000',
            'QRVP5' => 'REL019000',
            'QRYM' => 'REL089000',
            'QRAB1' => 'REL066000',
            'QRYX9' => 'REL100000',
            'QRVJ3' => 'REL022000',
            'QRRL' => 'REL024000',
            'QRMB9' => 'REL025000',
            'QRVP3' => 'REL026000',
            'QRAB9' => 'REL085000',
            'QRA' => 'REL113000',
            'QRAM1' => 'REL028000',
            'QRAM6' => 'REL078000',
            'QRYC1' => 'REL112000',
            'QRD' => 'REL032000',
            'QRD,QRAX' => 'REL032010',
            'QRDP' => 'REL032020',
            'QRDF' => 'REL032030',
            'QRD,QRVG' => 'REL032040',
            'QRAX' => 'REL033000',
            'QRVP2,5HP' => 'REL034050',
            'QRVP2,5HP,5PGM' => 'REL034010',
            'QRVP2,5HPD' => 'REL034020',
            'QRVP2,5HPF' => 'REL034030',
            'QRVP2,5HP,5PGJ' => 'REL034040',
            'QRRT' => 'REL029000',
            'QRVX' => 'REL036000',
            'QRVS' => 'REL016000',
            'QRP' => 'REL037000',
            'QRP,QRAX' => 'REL037010',
            'QRPF1' => 'REL041000',
            'QRPP' => 'REL037030',
            'QRPB3' => 'REL037040',
            'QRPB4' => 'REL090000',
            'QRPB1' => 'REL037050',
            'QRP,QRVG' => 'REL037060',
            'QRRC' => 'REL038000',
            'QRJ' => 'REL040000',
            'QRJB2' => 'REL040050',
            'QRJ,QRAX' => 'REL040030',
            'QRJ,VXWK' => 'REL040060',
            'QRJB1' => 'REL040070',
            'QRJB3' => 'REL040080',
            'QRJP' => 'REL040010',
            'QRJF' => 'REL040040',
            'QRJF5' => 'REL064000',
            'QRJ,QRVG' => 'REL040090',
            'QRVP' => 'REL071000',
            'QRVK' => 'REL062000',
            'QRMB8' => 'REL101000',
            'QRVS5' => 'REL086000',
            'QRVK2' => 'REL047000',
            'QRS,VXWS' => 'REL117000',
            'QRVP1' => 'REL119000',
            'QRVJ2' => 'REL087000',
            'QRVJ' => 'REL052000',
            'QRVJ,QRM' => 'REL052010',
            'QRVJ,QRPP' => 'REL052030',
            'QRVJ,QRJ' => 'REL052020',
            'QRA,JM' => 'REL075000',
            'QR,GBC' => 'REL054000',
            'QRAM3' => 'REL106000',
            'QRAM2' => 'REL084000',
            'QRAM9' => 'REL116000',
            'QRVQ' => 'REL120000',
            'QRVH' => 'REL058000',
            'QRVH,QRM' => 'REL058010',
            'QRVH,QRJ' => 'REL058020',
            'QRVP7' => 'REL105000',
            'QRRL3' => 'REL060000',
            'QRRD' => 'REL061000',
            'QRRL5' => 'REL065000',
            'QRVG' => 'REL102000',
            'QRYC5' => 'REL068000',
            'QRYA' => 'REL103000',
            'QRYX5,VXWT' => 'REL118000',
            'QRRF' => 'REL069000',
            'PD' => 'SCI080000',
            'PHDS' => 'SCI067000',
            'PDG' => 'TEC066000',
            'PSAX' => 'SCI102000',
            'TCB' => 'SCI010000',
            'PBWS' => 'SCI012000',
            'PN' => 'SCI013000',
            'PNF' => 'SCI013010',
            'PSB' => 'SCI007000',
            'PNRA' => 'SCI013070',
            'PNRH' => 'SCI013100',
            'PNC' => 'SCI013080',
            'TDC' => 'TEC009010',
            'PNK' => 'SCI013030',
            'PNN' => 'SCI013040',
            'PNR' => 'SCI013050',
            'JMAQ' => 'SCI090000',
            'RB' => 'SCI019000',
            'RG' => 'SCI030000',
            'RBG' => 'SCI031000',
            'RBK' => 'SCI081000',
            'RBKF' => 'SCI083000',
            'RBP' => 'SCI042000',
            'PNV' => 'SCI048000',
            'RBKC' => 'SCI052000',
            'RBGH,RBGB' => 'SCI091000',
            'RBC' => 'SCI082000',
            'PDND' => 'SCI047000',
            'PHDY' => 'SCI024000',
            'TQ' => 'TEC010000',
            'PD,JBFV5' => 'SCI101000',
            'PDN' => 'SCI076000',
            'RNPG' => 'SCI092000',
            'PDX' => 'SCI034000',
            'PS' => 'SCI008000',
            'PS,MFC,MFG' => 'SCI056000',
            'PSG' => 'SCI099000',
            'RNCB' => 'SCI088000',
            'PHVN' => 'SCI009000',
            'PST' => 'SCI011000',
            'PSF' => 'SCI017000',
            'PSC' => 'SCI072000',
            'PSAF' => 'SCI100000',
            'PSAJ' => 'SCI027000',
            'PSAK' => 'SCI029000',
            'PST,TVS' => 'SCI073000',
            'PSX,MFC,MFG' => 'SCI036000',
            'PSPM' => 'SCI039000',
            'PSD' => 'SCI049000',
            'PSQ' => 'SCI094000',
            'PSAN' => 'SCI089000',
            'PSAB' => 'SCI087000',
            'PSV' => 'SCI070000',
            'PSVA2' => 'SCI025000',
            'PSVP' => 'SCI070060',
            'PSVC,PSVF' => 'SCI070010',
            'PSVA' => 'SCI070020',
            'PSVM' => 'SCI070030',
            'PSVJ' => 'SCI070040',
            'PSVM3' => 'SCI070050',
            'PHD' => 'SCI041000',
            'PHDF,TGMF1' => 'SCI084000',
            'PHDT' => 'SCI079000',
            'PHDF,TGMF' => 'SCI095000',
            'TGMD' => 'TEC013000',
            'PHH' => 'SCI065000',
            'PDT' => 'SCI050000',
            'RBX' => 'SCI054000',
            'PDA,PDR' => 'SCI075000',
            'PH' => 'SCI055000',
            'PHVB' => 'SCI005000',
            'PHM' => 'SCI074000',
            'PHFC' => 'SCI097000',
            'PNT' => 'SCI016000',
            'PHK' => 'SCI038000',
            'PHVG' => 'SCI032000',
            'PHDV' => 'SCI033000',
            'PHU' => 'SCI040000',
            'PHN' => 'SCI051000',
            'PHJ' => 'SCI053000',
            'PHP' => 'SCI103000',
            'PHQ' => 'SCI057000',
            'PHR' => 'SCI061000',
            'PNRL' => 'SCI058000',
            'TTBM' => 'TEC015000',
            'PD,GBC' => 'SCI060000',
            'PDM' => 'SCI043000',
            'TTD' => 'SCI098000',
            'PG' => 'SCI004000',
            'PGK' => 'SCI015000',
            'PGS' => 'SCI098010',
            'TTDX' => 'SCI098020',
            'PGM,PGS' => 'SCI098030',
            'PNFS' => 'SCI078000',
            'PD,JNU' => 'SCI063000',
            'PGZ' => 'SCI066000',
            'VFJM' => 'SEL001000',
            'VFJK' => 'SEL029000',
            'VSPM' => 'SEL023000',
            'VFJP' => 'SEL041020',
            'VSP,VFV' => 'SEL008000',
            'VSS' => 'SEL040000',
            'VS,VFJB' => 'SEL041000',
            'VFJL' => 'SEL026000',
            'VFJL,VFVC' => 'SEL041040',
            'VSPT' => 'SEL030000',
            'VFJQ2' => 'SEL048000',
            'VFJJ,VFJH' => 'SEL014000',
            'VSPQ' => 'SEL042000',
            'WJF' => 'SEL038000',
            'VSZ' => 'SEL039000',
            'VXFG' => 'SEL015000',
            'VXM' => 'SEL019000',
            'VSPX' => 'SEL037000',
            'VFJQ3' => 'SEL043000',
            'VFB' => 'SEL047000',
            'VSY' => 'SEL049010',
            'VXHP' => 'SEL017000',
            'VFJS' => 'SEL024000',
            'VS,KJMT' => 'SEL035000',
            'VFL' => 'SEL026010',
            'JB' => 'SOC041000',
            'JBFV1' => 'SOC046000',
            'JPW,JBFA' => 'SOC072000',
            'JBCC4' => 'SOC055000',
            'JHM' => 'SOC002020',
            'JHMC' => 'SOC002010',
            'NK' => 'SOC003000',
            'JBSL13' => 'SOC068000',
            'JBSL,5PBD' => 'SOC056000',
            'JMHC' => 'SOC061000',
            'JBFV2' => 'SOC067000',
            'JBSP1' => 'SOC047000',
            'JBGX' => 'SOC058000',
            'JKV' => 'SOC004000',
            'JBCC6' => 'SOC005000',
            'JHBZ' => 'SOC036000',
            'JHBD' => 'SOC006000',
            'GTP,KCM' => 'SOC042000',
            'JBFF' => 'SOC040000',
            'JBFA' => 'SOC031000',
            'JBFN' => 'SOC057000',
            'JBFH' => 'SOC007000',
            'JBSL' => 'SOC008000',
            'JBSL,1H' => 'SOC008010',
            'JBSL,1KBB' => 'SOC008080',
            'JBSL,1KBB,5PB-US-C' => 'SOC001000',
            'JBSL,1KBB,5PB-US-D' => 'SOC043000',
            'JBSL,1KBB,5PB-US-H' => 'SOC044000',
            'JBSL,1KBB,5PB-US-E' => 'SOC021000',
            'JBSL,1F' => 'SOC008020',
            'JBSL,1M' => 'SOC008030',
            'JBSL,1KBC' => 'SOC008040',
            'JBSL,1KJ,1KL' => 'SOC008050',
            'JBSL,1D' => 'SOC008060',
            'JBSL,1FB' => 'SOC008070',
            'JBSF11' => 'SOC010000',
            'JBGB' => 'SOC011000',
            'JBSX' => 'SOC038000',
            'JBFZ' => 'SOC037000',
            'JBSF' => 'SOC032000',
            'JBSP4' => 'SOC013000',
            'JBCC6,5HC' => 'SOC014000',
            'RGC' => 'SOC015000',
            'JBFW' => 'SOC059000',
            'JBFJ' => 'SOC073000',
            'JBSL11' => 'SOC062000',
            'JBSR,5PGP' => 'SOC048000',
            'JBSR,5PGJ' => 'SOC049000',
            'JBSJ,5PS' => 'SOC064000',
            'JBSJ,5PSB' => 'SOC064010',
            'JBSJ,5PSG' => 'SOC012000',
            'JBSJ,5PSL' => 'SOC017000',
            'JBSF3,5PT' => 'SOC064020',
            'JBCT' => 'SOC052000',
            'JBSF2' => 'SOC018000',
            'JHBC' => 'SOC027000',
            'JBSL1' => 'SOC070000',
            'JKVP' => 'SOC030000',
            'JBFM' => 'SOC029000',
            'JKSN1' => 'SOC035000',
            'JBCC1' => 'SOC022000',
            'JBFC,JBFD' => 'SOC045000',
            'JBFV' => 'SOC063000',
            'JB,GBC' => 'SOC023000',
            'JBFG' => 'SOC066000',
            'GTM' => 'SOC053000',
            'JBFK2' => 'SOC060000',
            'NHTS' => 'SOC054000',
            'JBSA' => 'SOC050000',
            'JKSN' => 'SOC025000',
            'JHB' => 'SOC026000',
            'JHBK' => 'SOC026010',
            'JBSC' => 'SOC026020',
            'JHBA' => 'SOC026040',
            'JBSD' => 'SOC026030',
            'JBSR' => 'SOC039000',
            'PDR' => 'TEC052000',
            'JBFK' => 'SOC051000',
            'JBSF1' => 'SOC028000',
            'SC' => 'SPO075000',
            'SMC' => 'SPO001000',
            'SK' => 'SPO065000',
            'SKR' => 'SPO062000',
            'SKG' => 'SPO021000',
            'SVR' => 'SPO002000',
            'SFC' => 'SPO067000',
            'SFC,SCX' => 'SPO003030',
            'SFM' => 'SPO004000',
            'SXB,SHP' => 'SPO006000',
            'SFV' => 'SPO007000',
            'SRB' => 'SPO008000',
            'SCBM' => 'SPO068000',
            'SZR' => 'SPO009000',
            'SZN' => 'SPO074000',
            'SX' => 'SPO070000',
            'SCG' => 'SPO047000',
            'SCG,SFC' => 'SPO003010',
            'SCG,SFM' => 'SPO061010',
            'SCG,SFBD' => 'SPO061020',
            'SCG,SFBC' => 'SPO061030',
            'SC,JNM' => 'SPO082000',
            'SFD' => 'SPO054000',
            'JHBS' => 'SPO066000',
            'SZD,SMQ' => 'SPO011000',
            'SCL' => 'SPO076000',
            'SXQ' => 'SPO064000',
            'SRF' => 'SPO071000',
            'SFJ' => 'SPO073000',
            'SVF' => 'SPO014000',
            'SFBD' => 'SPO015000',
            'SFH' => 'SPO016000',
            'SHG' => 'SPO017000',
            'SZC' => 'SPO050000',
            'SCX' => 'SPO019000',
            'SVH' => 'SPO022000',
            'SFK' => 'SPO026000',
            'SRM' => 'SPO027000',
            'SRML' => 'SPO027010',
            'SMF' => 'SPO028000',
            'SMFA' => 'SPO028010',
            'SMFK' => 'SPO028020',
            'SZG' => 'SPO079000',
            'SCBB' => 'SPO058000',
            'SZ,SZV' => 'SPO030000',
            'SFX' => 'SPO060000',
            'SFT' => 'SPO032000',
            'SFTC' => 'SPO042000',
            'SFTD' => 'SPO044000',
            'SFTA' => 'SPO045000',
            'SC,GBC' => 'SPO033000',
            'SMX' => 'SPO038000',
            'SFBT,SFBV' => 'SPO056000',
            'SZE' => 'SPO035000',
            'SVT' => 'SPO037000',
            'SFBC' => 'SPO040000',
            'SCGP' => 'SPO041000',
            'SHB' => 'SPO046000',
            'SHBM' => 'SPO048000',
            'SFP' => 'SPO049000',
            'SP' => 'SPO051000',
            'SPN' => 'SPO005000',
            'SPNK' => 'SPO025000',
            'SPNL' => 'SPO080000',
            'SPNG' => 'SPO036000',
            'SPCA' => 'SPO059000',
            'SPG' => 'SPO069000',
            'SPC' => 'SPO043000',
            'ST' => 'SPO052000',
            'STP' => 'SPO081000',
            'STK' => 'SPO020000',
            'STG,STJ' => 'SPO023000',
            'STA' => 'SPO039000',
            'STC' => 'SPO072000',
            'SRC' => 'SPO053000',
            'JNZ' => 'STU000000',
            'YPZ,4Z-US-B' => 'STU001000',
            'YPZ,4Z-US-O' => 'STU002000',
            'YPZ,YPWN,4Z-US-P' => 'STU003000',
            'LX,4Z-US-L' => 'STU034000',
            'DSRC' => 'STU004000',
            'JNDH,VSD,4TNC' => 'STU006000',
            'JNDH,KNV,4CPF' => 'STU007000',
            'YPZ' => 'STU027000',
            'JNM,VSK,4Z-US-N' => 'STU009000',
            'JNM,VSK' => 'STU010000',
            'KFCX,4CPC' => 'STU011000',
            'YPZ,4LE' => 'STU028000',
            'JNKG,VSK' => 'STU031000',
            'JNDH,KJBX,4Z-US-D' => 'STU013000',
            'JNM,VSK,4CTM' => 'STU015000',
            'JNDH,JNM,4Z-US-E' => 'STU016000',
            'YPZ,4Z-US-M' => 'STU025000',
            'YPZ,4Z-US-C' => 'STU012000',
            'LX,4Z-US-F' => 'STU017000',
            'JNDH,JNM' => 'STU018000',
            'MRG,4Z-US-G' => 'STU032000',
            'MQC,MRG,4Z-US-J' => 'STU035000',
            'JNZ,4CP' => 'STU021000',
            'YPZ,4Z-US-H' => 'STU033000',
            'YPZ,JNLC,1KBB-US-NAK' => 'STU022000',
            'YPZ,4Z-US-A' => 'STU024000',
            'JNZ,YPWL2' => 'STU036000',
            'JNDH,JNKH,4Z-US-I' => 'STU019000',
            'MRG,1KBB,4CPC' => 'STU037000',
            'JNDH,JNR,4CP' => 'STU029000',
            'TB' => 'TEC000000',
            'TTA' => 'TEC001000',
            'TRP,TTDS' => 'TEC002000',
            'TV' => 'TEC003000',
            'TVK' => 'TEC003030',
            'TVBP' => 'TEC003060',
            'TVH' => 'TEC003020',
            'TVHH' => 'TEC003100',
            'TVSW' => 'TEC003110',
            'TVR' => 'TEC003040',
            'TVDR' => 'TEC003050',
            'TVG' => 'TEC003090',
            'TVF' => 'TEC003070',
            'TVQ' => 'TEC003010',
            'TVU' => 'TEC003120',
            'TJFM' => 'TEC004000',
            'TRC' => 'TEC009090',
            'MQW,TCB' => 'TEC059000',
            'RGV' => 'TEC048000',
            'TN' => 'TEC009020',
            'TNCJ' => 'TEC009100',
            'TNF' => 'TEC009110',
            'TNCE' => 'TEC009120',
            'TNFL' => 'TEC009130',
            'TNH' => 'TEC009140',
            'TNCC' => 'TEC009150',
            'TR' => 'TEC009160',
            'TNK,TNT' => 'TEC005000',
            'TNTC' => 'TEC005010',
            'TNKP,TNT' => 'TEC005040',
            'THRX' => 'TEC005030',
            'TNKH' => 'TEC005050',
            'TNTB' => 'TEC005060',
            'TNTP' => 'TEC005070',
            'TNTR' => 'TEC005080',
            'TJK' => 'TEC041000',
            'TBG' => 'TEC006000',
            'THR' => 'TEC007000',
            'TJF' => 'TEC008100',
            'TJFC' => 'TEC008060',
            'TGMM' => 'TEC021020',
            'JKSW' => 'TEC065000',
            'TBC' => 'TEC073000',
            'TQK' => 'TEC010010',
            'TQSR,RNH' => 'TEC010020',
            'TQSW' => 'TEC010030',
            'TTP' => 'TEC074000',
            'TTBF' => 'TEC011000',
            'TNKF' => 'TEC045000',
            'TVT' => 'TEC049000',
            'TDCT' => 'TEC012040',
            'PND' => 'TEC012010',
            'TDCT2' => 'TEC012020',
            'TDCT1' => 'TEC012030',
            'TBX' => 'TEC056000',
            'TTBL' => 'TEC019000',
            'TGMF2' => 'TEC014000',
            'TBD,AK' => 'TEC016010',
            'TBD,AKP' => 'TEC016020',
            'TGP' => 'TEC009060',
            'KNXC' => 'TEC017000',
            'TD' => 'TEC020000',
            'TBY' => 'TEC057000',
            'TGB' => 'TEC009070',
            'TTS' => 'TEC060000',
            'TGM' => 'TEC021030',
            'TDCQ' => 'TEC021010',
            'TGMS' => 'TEC021040',
            'TBM' => 'TEC022000',
            'TDPM' => 'TEC023000',
            'TJFN' => 'TEC024000',
            'TTM' => 'TEC025000',
            'TTU' => 'TEC026000',
            'TJKT1,TJKW' => 'TEC061000',
            'TBN' => 'TEC027000',
            'TBC,KJM' => 'TEC029000',
            'TTB' => 'TEC030000',
            'TVP' => 'TEC058000',
            'THFP,KNB' => 'TEC047000',
            'TDCW' => 'TEC072000',
            'TH' => 'TEC031000',
            'THV' => 'TEC031010',
            'THY' => 'TEC031020',
            'THF' => 'TEC031030',
            'THK' => 'TEC028000',
            'TB,KJMP' => 'TEC062000',
            'TGPQ' => 'TEC032000',
            'TJKD' => 'TEC033000',
            'TJKR' => 'TEC034000',
            'TB,GBC' => 'TEC035000',
            'RGW' => 'TEC036000',
            'TJFM1' => 'TEC037000',
            'TJS' => 'TEC064000',
            'TJKH,UYS' => 'TEC067000',
            'TNC' => 'TEC063000',
            'TNCB' => 'TEC054000',
            'TB,CBW' => 'TEC044000',
            'TJKV' => 'TEC043000',
            'TGMP,TDPF,TDCP' => 'TEC055000',
            'TDPT' => 'TEC070000',
            'TGBF' => 'TEC068000',
            'TGX' => 'TEC069000',
            'WG' => 'TRA000000',
            'WGC' => 'TRA001150',
            'WGC,WC' => 'TRA001010',
            'WGC,VSG' => 'TRA001020',
            'WGCV' => 'TRA001030',
            'VSF' => 'TRA001080',
            'WGC,AJ' => 'TRA001060',
            'WGCV,TRCS' => 'TRA001140',
            'WGM' => 'TRA002010',
            'TRPS' => 'TRA002050',
            'WGM,WGCV' => 'TRA002030',
            'WGD' => 'TRA010000',
            'WGCK' => 'TRA003010',
            'WGCK,AJ' => 'TRA003020',
            'WGCK,WGCV' => 'TRA003030',
            'TRLN' => 'TRA008000',
            'WGCF,WGFL' => 'TRA009000',
            'WGF' => 'TRA004010',
            'WGF,AJ' => 'TRA004020',
            'WGG,TRL' => 'TRA006000',
            'WGG' => 'TRA006040',
            'WGG,AJ' => 'TRA006020',
            'WGGV' => 'TRA006030',
            'WT' => 'TRV000000',
            'WT,1H' => 'TRV002000',
            'WT,1HFJ' => 'TRV002010',
            'WT,1HFG' => 'TRV002020',
            'WT,1HFGK' => 'TRV002030',
            'WT,1HB' => 'TRV002050',
            'WT,1HBM' => 'TRV002040',
            'WT,1HFM' => 'TRV002070',
            'WT,1HFMS' => 'TRV002060',
            'WT,1HFD' => 'TRV002080',
            'WT,1F' => 'TRV003000',
            'WT,1FC' => 'TRV003010',
            'WT,1FP' => 'TRV003030',
            'WT,1FPC' => 'TRV003020',
            'WT,1FPJ' => 'TRV003050',
            'WT,1FPK' => 'TRV003080',
            'WT,1FPM' => 'TRV003090',
            'WT,1FPCW' => 'TRV003100',
            'WT,1FK' => 'TRV003040',
            'WT,1FM' => 'TRV003060',
            'WT,1FB' => 'TRV015000',
            'WT,1M' => 'TRV004000',
            'WT,1KBC' => 'TRV006000',
            'WT,1QF-CA-A' => 'TRV006010',
            'WT,1QF-CA-T' => 'TRV006040',
            'WT,1KBC-CA-O' => 'TRV006020',
            'WT,1KBC-CA-C,1KBC-CA-S' => 'TRV006030',
            'WT,1KBC-CA-Q' => 'TRV006060',
            'WT,1KBC-CA-A,1KBC-CA-B' => 'TRV006050',
            'WT,1KJ' => 'TRV007000',
            'WT,1KLC' => 'TRV008000',
            'WTL' => 'TRV010000',
            'WT,1D' => 'TRV009000',
            'WT,1DFA' => 'TRV009010',
            'WT,1DDB,1DDN,1DDL' => 'TRV009020',
            'WT,1DXY' => 'TRV009160',
            'WT,1DT' => 'TRV012000',
            'WT,1DDF' => 'TRV009050',
            'WT,1DFG' => 'TRV009060',
            'WT,1DDU' => 'TRV009070',
            'WT,1DXG' => 'TRV009080',
            'WT,1DDR' => 'TRV009100',
            'WT,1DST' => 'TRV009110',
            'WT,1DN' => 'TRV009120',
            'WT,1DND' => 'TRV009030',
            'WT,1DNF' => 'TRV009170',
            'WT,1DNC,1MTNG' => 'TRV009090',
            'WT,1DNN' => 'TRV009180',
            'WT,1DNS' => 'TRV009190',
            'WT,1DSE,1DSP' => 'TRV009130',
            'WT,1DFH' => 'TRV009140',
            'WT,1DD' => 'TRV009150',
            'WTHH' => 'TRV030000',
            'WTHX' => 'TRV028000',
            'WTHV' => 'TRV035000',
            'WTHR' => 'TRV022000',
            'WTH,WGC' => 'TRV031000',
            'WTR' => 'TRV027000',
            'WT,1KLCM' => 'TRV014000',
            'WT,1HBE' => 'TRV015010',
            'WT,1FBH' => 'TRV015020',
            'WT,1DTT' => 'TRV015030',
            'WTHM' => 'TRV026090',
            'WTHH1' => 'TRV018000',
            'WTM' => 'TRV019000',
            'WT,1QMP' => 'TRV020000',
            'WT,GBC' => 'TRV021000',
            'WT,1DTA' => 'TRV023000',
            'WT,1KLS' => 'TRV024000',
            'WT,1KLSA' => 'TRV024010',
            'WT,1KLSB' => 'TRV024020',
            'WT,1KLSH,1MKPE' => 'TRV024030',
            'WT,1KLSE,1KLZTG' => 'TRV024040',
            'WT,1KLSR' => 'TRV024050',
            'WTH' => 'TRV026140',
            'WTHA' => 'TRV001000',
            'WTHT' => 'TRV029000',
            'WTHE,SZD' => 'TRV026100',
            'WTHG' => 'TRV033000',
            'WTHB' => 'TRV026010',
            'WTHD' => 'TRV026120',
            'WTH,VFJD' => 'TRV026030',
            'WTHC' => 'TRV026020',
            'WTHF' => 'TRV011000',
            'WTH,VXQ' => 'TRV026130',
            'WTHW,SZC' => 'TRV034000',
            'WTH,5PS' => 'TRV026070',
            'WTHM,JWT' => 'TRV026110',
            'WTH,WNG' => 'TRV026040',
            'WTH,QRVP,5PG' => 'TRV026060',
            'WTH,5LKS' => 'TRV026050',
            'WTH,WJS' => 'TRV032000',
            'WTHE,SC' => 'TRV026080',
            'WT,1KBB' => 'TRV025000',
            'WT,1KBB-US-M' => 'TRV025010',
            'WT,1KBB-US-ML' => 'TRV025020',
            'WT,1KBB-US-MP' => 'TRV025030',
            'WT,1KBB-US-N' => 'TRV025040',
            'WT,1KBB-US-NA' => 'TRV025050',
            'WT,1KBB-US-NE' => 'TRV025060',
            'WT,1KBB-US-S' => 'TRV025070',
            'WT,1KBB-US-SC' => 'TRV025080',
            'WT,1KBB-US-SE' => 'TRV025090',
            'WT,1KBB-US-SW' => 'TRV025100',
            'WT,1KBB-US-W' => 'TRV025110',
            'WT,1KBB-US-WM' => 'TRV025120',
            'WT,1KBB-US-WP' => 'TRV025130',
            'DNXC' => 'TRU010000',
            'DNXC,JBG' => 'TRU004000',
            'DNXC,LNQE' => 'TRU011000',
            'DNXC,JPSH' => 'TRU001000',
            'DNXC,JKVF1' => 'TRU007000',
            'DNXC3' => 'TRU002010',
            'DNXC,JKVM' => 'TRU003000',
            'DNXC,JBFK2' => 'TRU009000',
            'DNXC,JKVK' => 'TRU005000',
            'YFB,5AN' => 'YAF000000',
            'YFC,5AN' => 'YAF001000',
            'YFC,YNHA1,5AN' => 'YAF001010',
            'YFE,5AN' => 'YAF003000',
            'YFP,5AN' => 'YAF002000',
            'YFP,YNNJ24,5AN' => 'YAF002010',
            'YFP,YNNS,5AN' => 'YAF002020',
            'YFH,YNXB,5AN' => 'YAF041000',
            'YFP,YNNH,5AN' => 'YAF002040',
            'YFB,YNA,5AN' => 'YAF004000',
            'YFX,5AN' => 'YAF005000',
            'YFB,YNL,5AN' => 'YAF006000',
            'YFB,YNMH,5AN' => 'YAF007000',
            'YFB,YNK,5AN' => 'YAF008000',
            'YFA,5AN' => 'YAF009000',
            'XQ,YFB,5AN' => 'YAF010020',
            'XQG,YFC,5AN' => 'YAF010050',
            'XQB,YFA,5AN' => 'YAF010060',
            'XQ,YFB,YXW,5AN' => 'YAF010070',
            'XQ,YFB,YXP,5AN' => 'YAF010180',
            'XQL,YFE,5AN' => 'YAF010080',
            'XQM,YFJ,5AN' => 'YAF010090',
            'XQM,YFH,5AN' => 'YAF010160',
            'XQV,YFT,5AN' => 'YAF010110',
            'XQH,YFD,5AN' => 'YAF010120',
            'XQT,YFQ,5AN' => 'YAF010130',
            'XQ,YFB,YXB,5AN,5PS' => 'YAF010140',
            'YFZS,5AN' => 'YAF035000',
            'XAM,YFB,5AN' => 'YAF010010',
            'XQD,YFCF,5AN' => 'YAF010150',
            'XQR,YFM,5AN' => 'YAF010170',
            'XQL,YFG,5AN' => 'YAF010030',
            'XQK,YFG,5AN' => 'YAF010040',
            'YFB,YXW,5AN' => 'YAF058150',
            'YFB,YNTC,5AN' => 'YAF012000',
            'YFB,YNPC,5AN' => 'YAF013000',
            'YFB,YXK,5AN' => 'YAF058060',
            'YFB,YXP,5AN,5PB' => 'YAF014000',
            'YFJ,5AN' => 'YAF030010',
            'YFJ,YDC,5AN' => 'YAF017020',
            'YFN,YXF,5AN' => 'YAF018060',
            'YFN,YXFF,5AN' => 'YAF018050',
            'YFN,YXF,YXFD,5AN' => 'YAF018030',
            'YFN,YXFR,5AN' => 'YAF018070',
            'YFH,5AN' => 'YAF066000',
            'YFHW,5AN' => 'YAF019010',
            'YFHT,5AN' => 'YAF019020',
            'YFHB,5AN' => 'YAF019030',
            'YFHH,YFT,5AN' => 'YAF019040',
            'YFHR,5AN' => 'YAF052050',
            'YFH,YNXW,5AN' => 'YAF019050',
            'YFB,YNPJ,5AN' => 'YAF020000',
            'YFD,5AN' => 'YAF026000',
            'YFB,YNMF,5AN' => 'YAF022000',
            'YFB,YXA,5AN' => 'YAF023000',
            'YFB,YXL,5AN' => 'YAF023010',
            'YFT,5AN' => 'YAF024000',
            'YFT,1H,5AN' => 'YAF024010',
            'YFT,1QBA,5AN' => 'YAF024020',
            'YFT,1F,5AN' => 'YAF024030',
            'YFT,1KBC,5AN' => 'YAF024040',
            'YFT,1D,5AN' => 'YAF024050',
            'YFT,YNHD,5AN' => 'YAF024060',
            'YFT,3MPBGJ-DE-H,5AN,5PGJ' => 'YAF024070',
            'YFT,3KH,3KL,5AN' => 'YAF024080',
            'YFT,1FB,5AN' => 'YAF024090',
            'YFT,YNJ,5AN' => 'YAF024100',
            'YFT,3B,5AN' => 'YAF024110',
            'YFT,3KLY,5AN' => 'YAF024120',
            'YFT,1KBB,5AN' => 'YAF024130',
            'YFT,1KBB,3MLQ-US-B,3MLQ-US-C,5AN' => 'YAF024140',
            'YFT,1KBB,3MN,5AN' => 'YAF024150',
            'YFT,1KBB,3MNQ-US-E,3MNB-US-D,5AN' => 'YAF024160',
            'YFT,1KBB,3MP,5AN' => 'YAF024170',
            'YFT,1KBB,3MR,5AN' => 'YAF024180',
            'YFB,YNMD,5AN,5HC' => 'YAF025000',
            'YFQ,5AN' => 'YAF027020',
            'YFCA,5AN' => 'YAF028000',
            'YFCF,YNKC,5AN' => 'YAF029000',
            'YFJ,1QBAR,1QBAG,5AN' => 'YAF030020',
            'YFB,YXB,5AN,5PS' => 'YAF031000',
            'YFB,YNMK,5AN' => 'YAF032000',
            'YFB,YNML,5AN' => 'YAF034000',
            'YFB,YX,5AN' => 'YAF058260',
            'YFHD,5AN' => 'YAF038000',
            'YFH,YNXB6,5AN' => 'YAF040000',
            'YFCF,5AN' => 'YAF042000',
            'YFB,YXP,5AN,5PM' => 'YAF074000',
            'YFV,5AN' => 'YAF044000',
            'YFB,5AN,5P' => 'YAF073000',
            'YFH,YNX,5AN' => 'YAF045000',
            'YFB,YNM,5AN' => 'YAF046000',
            'YFB,YNM,1H,5AN' => 'YAF046020',
            'YFB,YNM,1F,5AN' => 'YAF046030',
            'YFB,YNM,1M,5AN' => 'YAF046040',
            'YFB,YNM,1KBC,5AN' => 'YAF046050',
            'YFB,YNM,1KJ,1KL,5AN' => 'YAF046060',
            'YFB,YNM,1D,5AN' => 'YAF046070',
            'YFB,YNM,5AN,5PBA' => 'YAF046010',
            'YFB,YNM,1KLCM,5AN' => 'YAF046080',
            'YFB,YNM,1FB,5AN' => 'YAF046090',
            'YFB,YNM,1QMP,5AN' => 'YAF046100',
            'YFB,YNM,1KBB,5AN' => 'YAF046160',
            'YFB,YNM,1KBB,5AN,5PB-US-C' => 'YAF046120',
            'YFB,YNM,1KBB,5AN,5PB-US-D' => 'YAF046130',
            'YFB,YNM,1KBB,5AN,5PB-US-H' => 'YAF046140',
            'YFB,YNM,1KBB,5AN,5PB-US-E' => 'YAF046150',
            'YFB,YND,5AN' => 'YAF047050',
            'YFB,YNDB,5AN' => 'YAF047010',
            'YFB,YNF,5AN' => 'YAF047040',
            'YFB,YNC,5AN' => 'YAF047030',
            'YFB,YDP,5AN' => 'YAF048000',
            'YFB,YNKA,5AN' => 'YAF049000',
            'YFB,YXZG,5AN' => 'YAF050000',
            'YFK,5AN' => 'YAF051000',
            'YFK,YXZR,5AN' => 'YAF051010',
            'YFK,5AN,5PGF' => 'YAF051020',
            'YFK,5AN,5PGM' => 'YAF051030',
            'YFK,YFC,5AN,5PGM' => 'YAF051040',
            'YFK,XQW,5AN,5PGM' => 'YAF051050',
            'YFK,YFH,5AN,5PGM' => 'YAF051060',
            'YFK,YFT,5AN,5PGM' => 'YAF051070',
            'YFK,YFCF,5AN,5PGM' => 'YAF051080',
            'YFK,YFM,5AN,5PGM' => 'YAF051090',
            'YFK,YFG,5AN,5PGM' => 'YAF051100',
            'YFK,YXZ,5AN,5PGM' => 'YAF051110',
            'YFK,5AN,5PGD' => 'YAF051120',
            'YFK,5AN,5PGJ' => 'YAF051130',
            'YFK,5AN,5PGP' => 'YAF051140',
            'YFMR,5AN' => 'YAF052020',
            'YFMR,YFT,5AN' => 'YAF052030',
            'YFMR,YXB,5AN,5PS' => 'YAF052040',
            'YFMR,YXP,5AN,5PB' => 'YAF052070',
            'YFMR,YFQ,5AN' => 'YAF052060',
            'YFB,YNMW,5AN' => 'YAF053000',
            'YFS,5AN' => 'YAF054020',
            'YFP,YNT,YNN,5AN' => 'YAF043000',
            'YFP,YXZG,5AN' => 'YAF043010',
            'YFG,5AN' => 'YAF063000',
            'YFG,YNXF,5AN' => 'YAF056010',
            'YFG,YFM,5AN' => 'YAF056030',
            'YFU,YDC,5AN' => 'YAF057000',
            'YFB,YXZB,5AN' => 'YAF058280',
            'YFB,YXN,5AN' => 'YAF058010',
            'YFB,YXQF,5AN' => 'YAF058020',
            'YFB,YXZ,5AN' => 'YAF058030',
            'YFB,YXLD2,5AN' => 'YAF058230',
            'YFB,YXHL,5AN' => 'YAF058040',
            'YFB,YXG,5AN' => 'YAF058050',
            'YFB,YXJ,5AN' => 'YAF058080',
            'YFB,YXLD1,5AN' => 'YAF058090',
            'YFB,YXZM,5AN' => 'YAF058100',
            'YFB,YXE,5AN' => 'YAF058110',
            'YFMF,5AN' => 'YAF058120',
            'YFB,YXLD,5AN' => 'YAF058140',
            'YFB,YXQ,5AN' => 'YAF058270',
            'YFB,YXQD,5AN' => 'YAF058240',
            'YFB,YXZH,5AN' => 'YAF058130',
            'YFB,YXHY,5AN' => 'YAF058180',
            'YFB,YXPB,YXN,5AN' => 'YAF058190',
            'YFB,YXZR,5AN' => 'YAF058200',
            'YFB,YXS,5AN' => 'YAF058210',
            'YFB,YXD,5AN' => 'YAF058220',
            'YFB,YXGS,5AN' => 'YAF058250',
            'YFR,5AN' => 'YAF059050',
            'YFR,YNWD3,5AN' => 'YAF059010',
            'YFR,YNWD4,5AN' => 'YAF059020',
            'YFR,YNWP,5AN' => 'YAF059030',
            'YFR,YNNJ24,5AN' => 'YAF059040',
            'YFR,YNWD2,5AN' => 'YAF059060',
            'YFR,YNWG,5AN' => 'YAF059120',
            'YFR,YNWM2,5AN' => 'YAF059080',
            'YFR,YNWJ,5AN' => 'YAF059090',
            'YFR,YNWY,5AN' => 'YAF059100',
            'YFR,YNWD1,5AN' => 'YAF059110',
            'YFR,YNWW,5AN' => 'YAF059130',
            'YFR,YNWM,5AN' => 'YAF059140',
            'YFGS,5AN' => 'YAF060000',
            'YFF,5AN' => 'YAF061000',
            'YFB,YNT,5AN' => 'YAF055000',
            'YFCB,5AN' => 'YAF062040',
            'YFCB,YFCF,5AN' => 'YAF062010',
            'YFD,YNXB2,5AN' => 'YAF068000',
            'YFC,YNJ,5AN' => 'YAF067000',
            'YFC,1KBB-US-W,5AN' => 'YAF069000',
            'YFD,YNXB3,5AN' => 'YAF070000',
            'YN,5AN' => 'YAN036000',
            'YXZB,5AN' => 'YAN060000',
            'YBG,5AN' => 'YAN001000',
            'YNHA,5AN' => 'YAN002000',
            'YNN,5AN' => 'YAN050130',
            'YNNK,5AN' => 'YAN003020',
            'YNNS,5AN' => 'YAN003030',
            'YNTP,5AN' => 'YAN004000',
            'YNA,5AN' => 'YAN042000',
            'YNA,YNUC,5AN' => 'YAN005010',
            'YNPJ,5AN' => 'YAN019000',
            'YNB,5AN' => 'YAN006000',
            'YNB,YNA,5AN' => 'YAN006010',
            'YNB,5AN,5PB' => 'YAN006020',
            'YNB,YNH,5AN' => 'YAN006030',
            'YNB,YXB,5AN,5PS' => 'YAN006150',
            'YNB,YNL,5AN' => 'YAN006040',
            'YNB,YNC,5AN' => 'YAN006050',
            'YNB,YND,5AN' => 'YAN006060',
            'YNB,YNKA,5AN' => 'YAN006070',
            'YNB,YNKA,1KBB,5AN' => 'YAN006080',
            'YNB,YNR,5AN' => 'YAN006090',
            'YNB,YNMW,5AN' => 'YAN006100',
            'YNB,YNT,5AN' => 'YAN006110',
            'YNB,YXZ,5AN' => 'YAN006120',
            'YNB,YNW,5AN' => 'YAN006130',
            'YNB,YNMF,5AN' => 'YAN006140',
            'YNL,5AN' => 'YAN054010',
            'YNMH,5AN' => 'YAN009000',
            'YPJV,5AN' => 'YAN010000',
            'YXV,5AN' => 'YAN011000',
            'XQA,YN,5AN' => 'YAN012000',
            'XQA,YNB,5AN' => 'YAN012010',
            'XQA,YNH,5AN' => 'YAN012020',
            'XQA,YNT,YNN,5AN' => 'YAN012030',
            'XQA,YX,5AN' => 'YAN012040',
            'YNTC,5AN' => 'YAN055060',
            'YNTC1,5AN' => 'YAN013030',
            'YNVU,5AN' => 'YAN013010',
            'YNTC2,5AN' => 'YAN013020',
            'YNPC,5AN' => 'YAN014000',
            'YNPH,5AN' => 'YAN015000',
            'YNG,YNX,5AN' => 'YAN016000',
            'YXK,5AN' => 'YAN024060',
            'YND,YNDS,5AN' => 'YAN017000',
            'YXF,5AN' => 'YAN018060',
            'YXFF,5AN' => 'YAN018050',
            'YXF,YXFD,5AN' => 'YAN018030',
            'YXFR,5AN' => 'YAN018070',
            'YRDM,5AN' => 'YAN020000',
            'YRDM,2ACB,4LE,5AN' => 'YAN020010',
            'YRDM,2ADF,5AN' => 'YAN020020',
            'YRDM,2ADS,5AN' => 'YAN020030',
            'YNV,5AN' => 'YAN021000',
            'YNVP,5AN' => 'YAN021010',
            'YNG,5AN' => 'YAN021020',
            'YNPG,5AN' => 'YAN022000',
            'YNMF,5AN' => 'YAN023000',
            'YXA,5AN' => 'YAN024010',
            'YXAB,5AN' => 'YAN024040',
            'YXLB,5AN' => 'YAN024030',
            'YXA,YXW,5AN' => 'YAN024050',
            'YXLD,5AN' => 'YAN051060',
            'YXLD6,5AN' => 'YAN024090',
            'YXR,5AN' => 'YAN024070',
            'YXAX,YXHY,5AN' => 'YAN024080',
            'YNH,5AN' => 'YAN025140',
            'YNH,1H,5AN' => 'YAN025010',
            'YNH,1QBA,5AN' => 'YAN025020',
            'YNH,1F,5AN' => 'YAN025030',
            'YNH,1M,5AN' => 'YAN025040',
            'YNH,1KBC,5AN' => 'YAN025050',
            'YNH,1KL,5AN' => 'YAN025060',
            'YNH,1D,5AN' => 'YAN025070',
            'YNHD,5AN' => 'YAN025080',
            'YNH,3MPBGJ-DE-H,5AN,5PGJ' => 'YAN025090',
            'YNH,3KH,3KL,5AN' => 'YAN025100',
            'YNH,1KLCM,5AN' => 'YAN025110',
            'YNH,1FB,5AN' => 'YAN025120',
            'YNJ,5AN' => 'YAN025130',
            'YNH,3B,5AN' => 'YAN025150',
            'YNH,3KLY,5AN' => 'YAN025160',
            'YNH,1KBB,5AN' => 'YAN025180',
            'YNH,1KBB,3MLQ-US-B,5AN' => 'YAN025190',
            'YNH,1KBB,3MN,5AN' => 'YAN025200',
            'YNH,1KBB,3MNQ-US-E,3MNB-US-D,5AN' => 'YAN025210',
            'YNH,1KBB,3MP,5AN' => 'YAN025220',
            'YNH,1KBB,3MR,5AN' => 'YAN025230',
            'YNMD,5AN,5HC' => 'YAN026000',
            'YNP,5AN' => 'YAN027000',
            'YNU,5AN' => 'YAN028000',
            'YX,5AN' => 'YAN051250',
            'YPC,5AN' => 'YAN030010',
            'YPCA2,5AN' => 'YAN030040',
            'YPCA4,5AN' => 'YAN030030',
            'YPCA23,5AN' => 'YAN030050',
            'YNKC,5AN' => 'YAN031000',
            'YXB,5AN,5PS' => 'YAN032000',
            'YPMF,5AN' => 'YAN034020',
            'YPJK,5AN' => 'YAN035000',
            'YNC,5AN' => 'YAN037000',
            'YNC,YPAD,5AN' => 'YAN037020',
            'YNC,5AN,6PB' => 'YAN037030',
            'YNC,5AN,6RJ' => 'YAN037040',
            'YNC,5AN,6RF' => 'YAN037050',
            'YXP,5AN,5PM' => 'YAN059000',
            'YNX,5AN' => 'YAN007000',
            'YNM,5AN' => 'YAN057000',
            'YNM,1H,5AN' => 'YAN038020',
            'YNM,1F,5AN' => 'YAN038030',
            'YNM,1M,5AN' => 'YAN038040',
            'YNM,1KBC,5AN' => 'YAN038050',
            'YNM,1KJ,1KL,5AN' => 'YAN038060',
            'YNM,1D,5AN' => 'YAN038070',
            'YNM,5AN,5PBA' => 'YAN038010',
            'YNM,1KLCM,5AN' => 'YAN038080',
            'YNM,1FB,5AN' => 'YAN038090',
            'YNM,1KBB,5AN' => 'YAN038150',
            'YNM,1KBB,5AN,5PB-US-C' => 'YAN038110',
            'YNM,1KBB,5AN,5PB-US-D' => 'YAN038120',
            'YNM,1KBB,5AN,5PB-US-H' => 'YAN038130',
            'YNM,1KBB,5AN,5PB-US-E' => 'YAN038140',
            'YND,5AN' => 'YAN039040',
            'YNDB,5AN' => 'YAN039010',
            'YNF,5AN' => 'YAN039030',
            'YNPK,5AN' => 'YAN040000',
            'YNRA,5AN' => 'YAN041000',
            'YDP,5AN' => 'YAN043000',
            'YPCA5,5AN' => 'YAN044000',
            'YXZG,5AN' => 'YAN045000',
            'YR,5AN' => 'YAN046000',
            'YNR,5AN' => 'YAN047010',
            'YNRX,5AN' => 'YAN047020',
            'YNRF,5AN' => 'YAN047030',
            'YNRM,5AN,5PGM' => 'YAN048040',
            'YNRR,1FP,5AN' => 'YAN047050',
            'YNRD,5AN,5PGD' => 'YAN047060',
            'YNRP,5AN,5PGP' => 'YAN047070',
            'YNRJ,5AN,5PGJ' => 'YAN047080',
            'YNRM,YXHL,5AN,5PGM' => 'YAN048010',
            'YNRM,YNRX,5AN,5PGM' => 'YAN048020',
            'YNRM,YXF,5AN,5PGM' => 'YAN048030',
            'YNGL,5AN' => 'YAN049000',
            'YNT,5AN' => 'YAN050110',
            'YNTA,5AN' => 'YAN050010',
            'YNNZ,YPMP51,5AN' => 'YAN050020',
            'YNT,YPMP1,5AN' => 'YAN050030',
            'YNNT,5AN' => 'YAN050040',
            'YNT,YPMP3,5AN' => 'YAN050050',
            'YXZE,5AN' => 'YAN050060',
            'YNNV,YPJT,5AN' => 'YAN050070',
            'YNNV,YPMP6,5AN' => 'YAN050080',
            'YNNC,5AN' => 'YAN050090',
            'YNTD,5AN' => 'YAN055040',
            'YNT,YPMP5,5AN' => 'YAN050120',
            'YPJJ,5AN' => 'YAN052060',
            'YNMC,5AN' => 'YAN052020',
            'YNRU,5AN' => 'YAN052030',
            'YNKA,5AN' => 'YAN052040',
            'YPJJ5,5AN' => 'YAN052050',
            'YXM,5AN' => 'YAN051010',
            'YXQF,5AN' => 'YAN051020',
            'YXZ,5AN' => 'YAN051030',
            'YXLD2,5AN' => 'YAN051210',
            'YXHL,5AN' => 'YAN051040',
            'YXG,5AN' => 'YAN051050',
            'YXJ,5AN' => 'YAN051070',
            'YXLD1,5AN' => 'YAN051080',
            'YXZM,5AN' => 'YAN051090',
            'YXE,5AN' => 'YAN051100',
            'YXHB,5AN' => 'YAN051110',
            'YXQ,5AN' => 'YAN051260',
            'YXQD,5AN' => 'YAN051220',
            'YXZH,5AN' => 'YAN051120',
            'YXHY,5AN' => 'YAN051170',
            'YXPB,YXN,5AN' => 'YAN051180',
            'YXS,5AN' => 'YAN051190',
            'YXD,5AN' => 'YAN051200',
            'YXGS,5AN' => 'YAN051240',
            'YNW,5AN' => 'YAN053090',
            'YNWD3,5AN' => 'YAN053010',
            'YNWD4,5AN' => 'YAN053020',
            'YNWD2,5AN' => 'YAN053050',
            'YNWM2,5AN' => 'YAN053060',
            'YNWD1,5AN' => 'YAN053100',
            'YNWG,5AN' => 'YAN053110',
            'YNWM,5AN' => 'YAN053120',
            'YPWL,5AN' => 'YAN054000',
            'YPZ,5AN' => 'YAN054020',
            'YPMT,5AN' => 'YAN055000',
            'YPMT,YNNZ,5AN' => 'YAN055010',
            'YPWE,5AN' => 'YAN055020',
            'YPMT5,5AN' => 'YAN055030',
            'YNTG,5AN' => 'YAN055050',
            'YNTR,5AN' => 'YAN056030',
            'FQ' => 'FIC000000',
            'FYT' => 'FIC000000',
            'DN' => 'BIO000000',
            'DNF' => 'BIO000000',
            'Y' => 'JUV000000',
            'FJMS' => 'FIC014050',
            'DCC' => 'POE005000',
            'DNBF1' => 'BIO008000',
            'NHWR7' => 'HIS027110',
            'VSP' => 'SEL031000',
            'FXD' => 'FIC027000',
            'FRX' => 'FIC027010',
            'FKM' => 'FIC027170',
            'DCF' => 'POE005010',
            'AVLP' => 'MUS023000',
            'DNBA' => 'BIO026000',
            'NHD' => 'HIS010000',
            'DNX' => 'BIO026000',
            'DNXM' => 'BIO008000',
            'DNXR' => 'BIO026000',
            'ATC' => 'ART000000',
            'FD' => 'FIC031000',
            'DNG' => 'BIO000000',
            'FFK' => 'FIC009000',
            'QDX' => 'JUV039000',
            'NHTZ1' => 'HIS011000',
            'FXB' => 'FIC016000',
            'JPHL' => 'JNF005000',
            'VFM' => 'SEL031000',
            'SCK' => 'SPO018000',
            'FXL' => 'FIC052000',
            'NHWR' => 'HIS027100',
            'VFJ' => 'SEL031000',
            'FXE' => 'FIC019000',
            'DNBS1' => 'BIO008000',
            'FLC' => 'FIC019000',
            'RNA' => 'REF013000',
            'JBCT4' => 'JUV014000',
            'KNSB' => 'REF013000',
            'JBF' => 'JUV009000',
            'FHQ' => 'FIC014000',
            'NHTW' => 'HIS037100',
            'VFJR2' => 'SEL031000',
            'JWXR' => 'JUV006000',
            'NHTX' => 'HIS015000',
            'JBFK4' => 'JUV009000',
            'FXN' => 'FIC051000',
            'NHWL' => 'HIS011000',
            'NHWR9' => 'FIC014000',
            'JWK' => 'JUV038000',
            'NHG' => 'HIS000000',
            'JWXK' => 'JUV005000',
            'WJ' => 'REF003000',
            'JWD' => 'JUV013000',
            'JKVM' => 'JUV009000',
            'VSR' => 'SEL031000',
            'PSX' => 'PER007000',
            'PDZ' => 'PER006000',
            'FXS' => 'FIC045000',
            'SVFF' => 'SEL031000',
            'DNP' => 'BIO000000',
            'FKC' => 'FIC028000',
            'JPFQ' => 'JUV039000',
            'VFJJ' => 'SEL031000',
            'VSPD' => 'SEL031000',
            'PSA' => 'PER008000',
            'NHDE' => 'HIS010180',
            'SCBT' => 'SOC012000',
            'WTLC' => 'TRV014000',
            'AVN' => 'MUS023050',
            'SFB' => 'SPO013000',
            'DNBZ' => 'BIO000000',
            'VXHT' => 'ART020000',
            'JBCT2' => 'JUV014000',
            'JWCM' => 'JUV010000',
            'JPVR1' => 'JUV005000',
            'FP' => 'FIC031020',
            'JKVG' => 'JUV009000',
            'VFVX' => 'SEL031000',
            'QRYX5' => 'REF009000',
            'FXM' => 'FIC014000',
            'KCY' => 'REF000000',
            'FLP' => 'FIC019000',
            'NHTR' => 'HIS016000',
            'WTLP' => 'TRV014000',
            'KNTF' => 'REF013000',
            'PSXE' => 'PER007000',
            'FXQ' => 'FIC019000',
            'STAN1' => 'REF015000',
            'FJMF' => 'FIC029000',
            'KNTP2' => 'REF015000',
            'AVM' => 'MUS025000',
            'JBC' => 'JUV014000',
            'FRV' => 'FIC027000',
            'TRCT' => 'TRV004000',
            'FXK' => 'FIC022000',
            'JBS' => 'JUV014000',
            'JWXN' => 'JUV015000',
            'JWXF' => 'JUV009000',
            'JWA' => 'JUV013000',
            'NHDJ' => 'HIS010300',
            'JBFN2' => 'JUV009000',
            'SKL' => 'SOC009000',
            'FXV' => 'FIC019000',
            'QDXB' => 'REF009000',
            'VSA' => 'SEL031000',
            'VSPP' => 'SEL031000',
            // 'XA' => 'REF009000',
            'WNJ' => 'TRV012000',
            'LBK' => 'REF002000',
            'SCBG' => 'SOC012000',
            'J' => 'SOC000000',
            'XY' => 'COM000000',
            'K' => 'BUS000000',
            'WZG' => 'SEL032000',
            'AFF' => 'CGN006000',
            'SZD' => 'HEA000000',
            'P' => 'SCI000000',
            'GTP' => 'BUS068000',
            'XQX' => 'CGN004110',
            'WZ' => 'NON000000',
            'S' => 'SPO000000',
            'RN' => 'NAT011000',
            'DNBH1' => 'BIO000000',
            'TDPF' => 'TEC021000',
            // 'FBA' => 'FIC000000',
            // 'WBXD3' => 'CKB006000',
            'WQ' => 'HIS054000',
            'SV' => 'SPO000000',
            'WQP' => 'REF013000',
            'AFW' => 'CRA061000',
            'X' => 'CGN000000',
            'AFCC' => 'ART022000',
            'SZ' => 'DES013000',
            'SVS' => 'SPO037000',
            // 'FU' => 'FIC016000',
            'SMQB' => 'SPO018000',
            'MFKH3' => 'HEA049000',
            'KJK' => 'BUS041000',
            // 'KJH' => 'BUS038000',
            'WQH' => 'HIS000000',
            'JWM' => 'HIS027080',
            // 'FRJ' => 'FIC027100',
            // 'FXD' => 'FIC045000',
            'FB,FU' => 'FIC016000',
            'FB,FUP' => 'FIC052000',
            'YHFK' => 'JUV037000',
            'FBA,FXS' => 'FIC000000',
            'FBA,FT' => 'FIC000000',
        ];

        $codes = implode(',', $themaCodes);

        // Search for exact match
        if (array_key_exists($codes, $mapping)) {
            return $mapping[$codes];
        }

        // If still no match found, try the other Thema codes, primary is first
        foreach ($themaCodes as $themaCode) {
            if (array_key_exists($themaCode, $mapping)) {
                return $mapping[$themaCode];
            }
        }

        // If match still not found, try navigating up the primary Thema code
        $primaryThemaCode = reset($themaCodes);

        for ($i = 1; $i < strlen($primaryThemaCode); $i++) {
            $shortenedCode = substr($primaryThemaCode, 0, -$i);

            if (array_key_exists($shortenedCode, $mapping)) {
                return $mapping[$shortenedCode];
            }
        }

        return null;
    }

    /**
     * Get ePub usage constraints
     *
     * @return Collection
     */
    public function getEpubUsageConstraints()
    {
        $epubUsageConstraints = new Collection;

        // Skip when product is not digital
        if ($this->getPlanningCode() !== 'y') {
            return $epubUsageConstraints;
        }

        // Add TDM restriction when "Prohibit Text And Data Mining" is checked
        if (property_exists($this->product, 'prohibitTextAndDataMining') && $this->product->prohibitTextAndDataMining === true) {
            $epubUsageConstraints->push(['EpubUsageType' => '11', 'EpubUsageStatus' => '03']);
        }

        return $epubUsageConstraints;
    }
}
