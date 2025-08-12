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
            'Author WS' => 1,
            'Editor WS in Chief' => 2,
            'Editing WS author' => 3,
            'Index WS' => 4,
            'Preface WS' => 5,
            'Foreword WS' => 6,
            'Introduction WS' => 7,
            'Prologue WS' => 8,
            'Afterword WS' => 9,
            'Epilogue WS' => 10,
            'Illustrator WS' => 11,
            'Illustrator, cover WS' => 11,
            'Designer, cover WS' => 11,
            'Photographer WS' => 12,
            'Reader WS' => 13,
            'Translator WS' => 14,
            'Graphic WS Designer' => 15,
            'Cover WS design or artwork by' => 16,
            'Composer WS' => 17,
            'Arranged WS by' => 18,
            'Maps WS' => 19,
            'Assistant WS' => 20,
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

            $printOrders->push([
                'printNumber' => $print->print,
                'orderedQuantity' => isset($print->quantityOrdered) ? $print->quantityOrdered : null,
                'deliveries' => collect($deliveries),
            ]);
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

        // Selkokirja or Easy-to-read is determined from Thema interest age codes
        $hasEasyToReadAgeGroups = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->contains(function ($subject, $key) {
            return in_array($subject['SubjectCode'], ['5AR', '5AX', '5AZ']);
        });

        if ($hasEasyToReadAgeGroups) {
            $editionTypes->push(['EditionType' => 'SMP']);
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
        if ($this->isImmaterial() || $this->getAllContributors()->contains('Role', 'Printer WS') === false) {
            return null;
        }

        // Get the printer contact
        $printer = $this->getAllContributors()->where('Role', 'Printer WS')->reject(function (array $contributor, int $key) {
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
            'BISAC' => 'Code,Thema Code 1,Thema Code 2,Thema Code 3,Thema Code 4,Qual Code 1,Qual Code 2,Qual Code 3,Qual Code 4',
            'ANT000000' => 'WC',
            'ANT056000' => 'WC,KJSA',
            'ANT001000' => 'WC,1KBB',
            'ANT002000' => 'WCU',
            'ANT003000' => 'WCS',
            'ANT005000' => 'WCS',
            'ANT006000' => 'WCNG',
            'ANT007000' => 'WCRB',
            'ANT054000' => 'WC,1KBC',
            'ANT008000' => 'WCC',
            'ANT010000' => 'WCJ',
            'ANT011000' => 'WCF',
            'ANT012000' => 'WCS,XR',
            'ANT015000' => 'WCW',
            'ANT053000' => 'WCN',
            'ANT016000' => 'WCK',
            'ANT017000' => 'WCL',
            'ANT018000' => 'WCNG',
            'ANT021000' => 'WCP',
            'ANT022000' => 'WCN',
            'ANT023000' => 'WCS',
            'ANT024000' => 'WCK',
            'ANT029000' => 'WCS',
            'ANT025000' => 'WC,AT',
            'ANT031000' => 'WC,JP',
            'ANT052000' => 'WC,JBCC1',
            'ANT032000' => 'WCNC',
            'ANT033000' => 'WCS',
            'ANT034000' => 'WCU',
            'ANT035000' => 'WCNC',
            'ANT036000' => 'WCX',
            'ANT037000' => 'WC,AVD',
            'ANT038000' => 'WC,GBC',
            'ANT040000' => 'WCV',
            'ANT041000' => 'WCR',
            'ANT043000' => 'WCT',
            'ANT042000' => 'WCT,WCS',
            'ANT042010' => 'WCT,WCS,SFC',
            'ANT044000' => 'WCG',
            'ANT045000' => 'WCW',
            'ANT047000' => 'WCV,WCVB',
            'ANT055000' => 'WC,WBZ',
            'ANT050000' => 'WCW',
            'ANT009000' => 'WC,WG',
            'ANT051000' => 'WC,WBXD1',
            'ARC000000' => 'AM',
            'ARC022000' => 'AMC,ABC',
            'ARC023000' => 'AM,GBCY',
            'ARC024000' => 'AM',
            'ARC024010' => 'AMG',
            'ARC011000' => 'AMG',
            'ARC016000' => 'AMN',
            'ARC003000' => 'AMK',
            'ARC019000' => 'AMD,TNK',
            'ARC001000' => 'AMA',
            'ARC002000' => 'AMCD',
            'ARC004000' => 'AMC,AMD',
            'ARC014000' => 'AM,TNKX',
            'ARC014010' => 'AM,TNKX,ABC',
            'ARC005000' => 'AMX',
            'ARC005010' => 'AMX,3B,6PJ',
            'ARC005020' => 'AMX,1QBA,3C,6CA',
            'ARC005030' => 'AMX,3KH,3KL,6MB',
            'ARC005040' => 'AMX,NHDL,3KLY,6RC',
            'ARC005050' => 'AMX,6BA,6RD',
            'ARC005060' => 'AMX,6RA',
            'ARC005070' => 'AMX,3MNQ,3MPB,6MC',
            'ARC005080' => 'AMX,3MPQ,3MRB',
            'ARC006000' => 'AMB',
            'ARC006010' => 'AMB',
            'ARC006020' => 'AMB',
            'ARC007000' => 'AMR',
            'ARC007010' => 'AMR,TNKH',
            'ARC008000' => 'AMV',
            'ARC009000' => 'AMC,AMCM',
            'ARC015000' => 'AMD',
            'ARC017000' => 'AMD,KJMP',
            'ARC012000' => 'AM,GBC',
            'ARC020000' => 'AM',
            'ARC021000' => 'AMC,TNKS',
            'ARC013000' => 'AM,JNU',
            'ARC018000' => 'AMCR,RNU',
            'ARC010000' => 'AMVD',
            'ARC025000' => 'AMK,6VE',
            'ART000000' => 'AB',
            'ART015010' => 'AGA,NHH,1H',
            'ART015020' => 'AGA,1KBB',
            'ART038000' => 'AGA,1KBB,5PB-US-C',
            'ART039000' => 'AGA,1KBB,5PB-US-D',
            'ART040000' => 'AGA,1KBB,5PB-US-H,6LC',
            'ART054000' => 'AB,GBCY',
            'ART037000' => 'AB',
            'ART019000' => 'AGA,NHF,1F',
            'ART019010' => 'AGA,NHF,1FPC',
            'ART019020' => 'AGA,NHF,1FK',
            'ART019030' => 'AGA,NHF,1FPJ',
            'ART042000' => 'AGA,NHM,1M',
            'ART055000' => 'AFJY',
            'ART043000' => 'ABQ',
            'ART015040' => 'AGA,NHK,1KBC',
            'ART044000' => 'AGA,NHK,1KJ,1KL',
            'ART045000' => 'AFP',
            'ART006000' => 'AGC',
            'ART006010' => 'AGC',
            'ART006020' => 'AGC',
            'ART007000' => 'AGZC',
            'ART008000' => 'AGA,6CK',
            'ART056000' => 'ABC',
            'ART009000' => 'ABA',
            'ART046000' => 'AFKV',
            'ART063000' => 'AFKN',
            'ART015030' => 'AGA,NHD,1D',
            'ART057000' => 'AFKV',
            'ART013000' => 'AGA,6FD,6ND',
            'ART067000' => 'ABK',
            'ART061000' => 'AFP',
            'ART058000' => 'AGTS,6UB',
            'ART015000' => 'AGA',
            'ART015050' => 'AGA,3B,6PJ',
            'ART015060' => 'AGA,1QBA,3C,6CA',
            'ART015070' => 'AGA,3KH,3KL,6MB',
            'ART015080' => 'AGA,NHDL,3KLY,6RC',
            'ART015090' => 'AGA,6BA,6RD',
            'ART015120' => 'AGA,6RA',
            'ART015100' => 'AGA,3MNQ,3MPB,6MC',
            'ART015110' => 'AGA,3MPQ,3MRB',
            'ART041000' => 'AGA,1K,5PBA',
            'ART016000' => 'AGB',
            'ART016010' => 'AGB',
            'ART016020' => 'AGB',
            'ART016030' => 'AGB',
            'ART066000' => 'AGB,5PS',
            'ART047000' => 'AGA,1FB',
            'ART017000' => 'AFJ',
            'ART059000' => 'GLZ',
            'ART060000' => 'AFKP',
            'ART023000' => 'AGA,JBCC1',
            'ART048000' => 'AFH',
            'ART062000' => 'AGT',
            'ART025000' => 'AB,GBC',
            'ART049000' => 'AGA,1DTA,1QBDR',
            'ART026000' => 'AFKB,AFKN',
            'ART027000' => 'AB,JNU',
            'ART050000' => 'AG',
            'ART050050' => 'AGHX,5X',
            'ART050010' => 'AGH',
            'ART050020' => 'AGNL',
            'ART050030' => 'AGN',
            'ART050040' => 'AGHF',
            'ART035000' => 'AGR,5PG',
            'ART050060' => 'AG,6FG',
            'ART028000' => 'AGZ',
            'ART031000' => 'AFCL,AGZ',
            'ART002000' => 'AFC,AGZ',
            'ART003000' => 'WFU,AGZ',
            'ART004000' => 'AKLC,AGZ',
            'ART051000' => 'AGZC',
            'ART010000' => 'AFF,AGZ',
            'ART052000' => 'AFF,AGZ,AGH',
            'ART018000' => 'AFCL,AGZ',
            'ART020000' => 'AFC,AGZ',
            'ART021000' => 'AFFC,AGZ',
            'ART033000' => 'AFFK,AGZ',
            'ART034000' => 'AFFC,AGZ',
            'ART024000' => 'AFH,AGZ',
            'ART053000' => 'AFKB,AGZ',
            'ART029000' => 'AFCC,AGZ',
            'ART064000' => 'AKLF,AFKV',
            'ART065000' => 'AGB,JBSF1',
            'BIB000000' => 'QRMF1',
            'BIB001000' => 'QRMF1',
            'BIB001010' => 'QRMF1,YNRX',
            'BIB001020' => 'QRMF1',
            'BIB001080' => 'QRMF1',
            'BIB001090' => 'QRMF1',
            'BIB001030' => 'QRMF13',
            'BIB001100' => 'QRMF1',
            'BIB001110' => 'QRMF1',
            'BIB001040' => 'QRMF1',
            'BIB001050' => 'QRMF1',
            'BIB001060' => 'QRMF1',
            'BIB001070' => 'QRMF1,YNRX',
            'BIB022000' => 'QRMF1',
            'BIB022010' => 'QRMF1,YNRX',
            'BIB022020' => 'QRMF1',
            'BIB022080' => 'QRMF1',
            'BIB022090' => 'QRMF1',
            'BIB022030' => 'QRMF13',
            'BIB022100' => 'QRMF1',
            'BIB022110' => 'QRMF1',
            'BIB022040' => 'QRMF1',
            'BIB022050' => 'QRMF1',
            'BIB022060' => 'QRMF1',
            'BIB022070' => 'QRMF1,YNRX',
            'BIB002000' => 'QRMF1',
            'BIB002010' => 'QRMF1,YNRX',
            'BIB002020' => 'QRMF1',
            'BIB002080' => 'QRMF1',
            'BIB002090' => 'QRMF1',
            'BIB002030' => 'QRMF13',
            'BIB002100' => 'QRMF1',
            'BIB002110' => 'QRMF1',
            'BIB002040' => 'QRMF1',
            'BIB002050' => 'QRMF1',
            'BIB002060' => 'QRMF1',
            'BIB002070' => 'QRMF1,YNRX',
            'BIB003000' => 'QRMF1',
            'BIB003010' => 'QRMF1,YNRX',
            'BIB003020' => 'QRMF1',
            'BIB003080' => 'QRMF1',
            'BIB003090' => 'QRMF1',
            'BIB003030' => 'QRMF13',
            'BIB003100' => 'QRMF1',
            'BIB003110' => 'QRMF1',
            'BIB003040' => 'QRMF1',
            'BIB003050' => 'QRMF1',
            'BIB003060' => 'QRMF1',
            'BIB003070' => 'QRMF1,YNRX',
            'BIB004000' => 'QRMF1',
            'BIB004010' => 'QRMF1,YNRX',
            'BIB004020' => 'QRMF1',
            'BIB004080' => 'QRMF1',
            'BIB004090' => 'QRMF1',
            'BIB004030' => 'QRMF13',
            'BIB004100' => 'QRMF1',
            'BIB004110' => 'QRMF1',
            'BIB004040' => 'QRMF1',
            'BIB004050' => 'QRMF1',
            'BIB004060' => 'QRMF1',
            'BIB004070' => 'QRMF1,YNRX',
            'BIB005000' => 'QRMF1,YNRX',
            'BIB005010' => 'QRMF1,YNRX',
            'BIB005020' => 'QRMF1,YNRX',
            'BIB005080' => 'QRMF1,YNRX',
            'BIB005090' => 'QRMF1,YNRX',
            'BIB005030' => 'QRMF13,YNRX',
            'BIB005100' => 'QRMF1,YNRX',
            'BIB005110' => 'QRMF1,YNRX',
            'BIB005040' => 'QRMF1,YNRX',
            'BIB005050' => 'QRMF1,YNRX',
            'BIB005060' => 'QRMF1,YNRX',
            'BIB005070' => 'QRMF1,YNRX',
            'BIB006000' => 'QRMF1',
            'BIB006010' => 'QRMF1,YNRX',
            'BIB006020' => 'QRMF1',
            'BIB006080' => 'QRMF1',
            'BIB006090' => 'QRMF1',
            'BIB006030' => 'QRMF13',
            'BIB006100' => 'QRMF1',
            'BIB006110' => 'QRMF1',
            'BIB006040' => 'QRMF1',
            'BIB006050' => 'QRMF1',
            'BIB006060' => 'QRMF1',
            'BIB006070' => 'QRMF1,YNRX',
            'BIB007000' => 'QRMF1,2ADS',
            'BIB007010' => 'QRMF1,YNRX,2ADS',
            'BIB007020' => 'QRMF1,2ADS',
            'BIB007080' => 'QRMF1,2ADS',
            'BIB007090' => 'QRMF1,2ADS',
            'BIB007030' => 'QRMF13,2ADS',
            'BIB007100' => 'QRMF1,2ADS',
            'BIB007110' => 'QRMF1,2ADS',
            'BIB007040' => 'QRMF1,2ADS',
            'BIB007050' => 'QRMF1,2ADS',
            'BIB007060' => 'QRMF1,2ADS',
            'BIB007070' => 'QRMF1,YNRX,2ADS',
            'BIB008000' => 'QRMF1',
            'BIB008010' => 'QRMF1,YNRX',
            'BIB008020' => 'QRMF1',
            'BIB008080' => 'QRMF1',
            'BIB008090' => 'QRMF1',
            'BIB008030' => 'QRMF13',
            'BIB008100' => 'QRMF1',
            'BIB008110' => 'QRMF1',
            'BIB008040' => 'QRMF1',
            'BIB008050' => 'QRMF1',
            'BIB008060' => 'QRMF1',
            'BIB008070' => 'QRMF1,YNRX',
            'BIB009000' => 'QRMF1',
            'BIB009010' => 'QRMF1,YNRX',
            'BIB009020' => 'QRMF1',
            'BIB009080' => 'QRMF1',
            'BIB009090' => 'QRMF1',
            'BIB009030' => 'QRMF13',
            'BIB009100' => 'QRMF1',
            'BIB009110' => 'QRMF1',
            'BIB009040' => 'QRMF1',
            'BIB009050' => 'QRMF1',
            'BIB009060' => 'QRMF1',
            'BIB009070' => 'QRMF1,YNRX',
            'BIB010000' => 'QRMF1',
            'BIB010010' => 'QRMF1,YNRX',
            'BIB010020' => 'QRMF1',
            'BIB010080' => 'QRMF1',
            'BIB010090' => 'QRMF1',
            'BIB010030' => 'QRMF13',
            'BIB010100' => 'QRMF1',
            'BIB010110' => 'QRMF1',
            'BIB010040' => 'QRMF1',
            'BIB010050' => 'QRMF1',
            'BIB010060' => 'QRMF1',
            'BIB010070' => 'QRMF1,YNRX',
            'BIB011000' => 'QRMF1',
            'BIB011010' => 'QRMF1,YNRX',
            'BIB011020' => 'QRMF1',
            'BIB011080' => 'QRMF1',
            'BIB011090' => 'QRMF1',
            'BIB011030' => 'QRMF13',
            'BIB011100' => 'QRMF1',
            'BIB011110' => 'QRMF1',
            'BIB011040' => 'QRMF1',
            'BIB011050' => 'QRMF1',
            'BIB011060' => 'QRMF1',
            'BIB011070' => 'QRMF1,YNRX',
            'BIB012000' => 'QRMF1',
            'BIB012010' => 'QRMF1,YNRX',
            'BIB012020' => 'QRMF1',
            'BIB012080' => 'QRMF1',
            'BIB012090' => 'QRMF1',
            'BIB012030' => 'QRMF13',
            'BIB012100' => 'QRMF1',
            'BIB012110' => 'QRMF1',
            'BIB012040' => 'QRMF1',
            'BIB012050' => 'QRMF1',
            'BIB012060' => 'QRMF1',
            'BIB012070' => 'QRMF1,YNRX',
            'BIB013000' => 'QRMF1',
            'BIB013010' => 'QRMF1,YNRX',
            'BIB013020' => 'QRMF1',
            'BIB013080' => 'QRMF1',
            'BIB013090' => 'QRMF1',
            'BIB013030' => 'QRMF13',
            'BIB013100' => 'QRMF1',
            'BIB013110' => 'QRMF1',
            'BIB013040' => 'QRMF1',
            'BIB013050' => 'QRMF1',
            'BIB013060' => 'QRMF1',
            'BIB013070' => 'QRMF1,YNRX',
            'BIB014000' => 'QRMF1',
            'BIB014010' => 'QRMF1,YNRX',
            'BIB014020' => 'QRMF1',
            'BIB014080' => 'QRMF1',
            'BIB014090' => 'QRMF1',
            'BIB014030' => 'QRMF13',
            'BIB014100' => 'QRMF1',
            'BIB014110' => 'QRMF1',
            'BIB014040' => 'QRMF1',
            'BIB014050' => 'QRMF1',
            'BIB014060' => 'QRMF1',
            'BIB014070' => 'QRMF1,YNRX',
            'BIB015000' => 'QRMF1',
            'BIB015010' => 'QRMF1,YNRX',
            'BIB015020' => 'QRMF1',
            'BIB015080' => 'QRMF1',
            'BIB015090' => 'QRMF1',
            'BIB015030' => 'QRMF13',
            'BIB015100' => 'QRMF1',
            'BIB015110' => 'QRMF1',
            'BIB015040' => 'QRMF1',
            'BIB015050' => 'QRMF1',
            'BIB015060' => 'QRMF1',
            'BIB015070' => 'QRMF1,YNRX',
            'BIB016000' => 'QRMF1',
            'BIB016010' => 'QRMF1,YNRX',
            'BIB016020' => 'QRMF1',
            'BIB016080' => 'QRMF1',
            'BIB016090' => 'QRMF1',
            'BIB016030' => 'QRMF13',
            'BIB016100' => 'QRMF1',
            'BIB016110' => 'QRMF1',
            'BIB016040' => 'QRMF1',
            'BIB016050' => 'QRMF1',
            'BIB016060' => 'QRMF1',
            'BIB016070' => 'QRMF1,YNRX',
            'BIB024000' => 'QRMF1,2ADS',
            'BIB024010' => 'QRMF1,YNRX,2ADS',
            'BIB024020' => 'QRMF1,2ADS',
            'BIB024030' => 'QRMF1,2ADS',
            'BIB024040' => 'QRMF1,2ADS',
            'BIB024050' => 'QRMF13,2ADS',
            'BIB024060' => 'QRMF1,2ADS',
            'BIB024070' => 'QRMF1,2ADS',
            'BIB024080' => 'QRMF1,2ADS',
            'BIB024090' => 'QRMF1,2ADS',
            'BIB024100' => 'QRMF1,2ADS',
            'BIB024110' => 'QRMF1,YNRX,2ADS',
            'BIB017000' => 'QRMF1,2ADS',
            'BIB017010' => 'QRMF1,YNRX,2ADS',
            'BIB017020' => 'QRMF1,2ADS',
            'BIB017080' => 'QRMF1,2ADS',
            'BIB017090' => 'QRMF1,2ADS',
            'BIB017030' => 'QRMF13,2ADS',
            'BIB017100' => 'QRMF1,2ADS',
            'BIB017110' => 'QRMF1,2ADS',
            'BIB017040' => 'QRMF1,2ADS',
            'BIB017050' => 'QRMF1,2ADS',
            'BIB017060' => 'QRMF1,2ADS',
            'BIB017070' => 'QRMF1,YNRX,2ADS',
            'BIB018000' => 'QRMF1',
            'BIB018010' => 'QRMF1,YNRX',
            'BIB018020' => 'QRMF1',
            'BIB018080' => 'QRMF1',
            'BIB018090' => 'QRMF1',
            'BIB018030' => 'QRMF13',
            'BIB018100' => 'QRMF1',
            'BIB018110' => 'QRMF1',
            'BIB018040' => 'QRMF1',
            'BIB018050' => 'QRMF1',
            'BIB018060' => 'QRMF1',
            'BIB018070' => 'QRMF1,YNRX',
            'BIB025000' => 'QRMF1',
            'BIB025010' => 'QRMF1,YNRX',
            'BIB025020' => 'QRMF1',
            'BIB025030' => 'QRMF1',
            'BIB025040' => 'QRMF1',
            'BIB025050' => 'QRMF13',
            'BIB025060' => 'QRMF1',
            'BIB025070' => 'QRMF1',
            'BIB025080' => 'QRMF1',
            'BIB025090' => 'QRMF1',
            'BIB025100' => 'QRMF1',
            'BIB025110' => 'QRMF1,YNRX',
            'BIB019000' => 'QRMF1,2ADS',
            'BIB019010' => 'QRMF1,YNRX,2ADS',
            'BIB019020' => 'QRMF1,2ADS',
            'BIB019080' => 'QRMF1,2ADS',
            'BIB019090' => 'QRMF1,2ADS',
            'BIB019030' => 'QRMF13,2ADS',
            'BIB019100' => 'QRMF1,2ADS',
            'BIB019110' => 'QRMF1,2ADS',
            'BIB019040' => 'QRMF1,2ADS',
            'BIB019050' => 'QRMF1,2ADS',
            'BIB019060' => 'QRMF1,2ADS',
            'BIB019070' => 'QRMF1,YNRX,2ADS',
            'BIB023000' => 'QRMF1',
            'BIB023010' => 'QRMF1,YNRX',
            'BIB023020' => 'QRMF1',
            'BIB023080' => 'QRMF1',
            'BIB023090' => 'QRMF1',
            'BIB023030' => 'QRMF13',
            'BIB023100' => 'QRMF1',
            'BIB023110' => 'QRMF1',
            'BIB023040' => 'QRMF1',
            'BIB023050' => 'QRMF1',
            'BIB023060' => 'QRMF1',
            'BIB023070' => 'QRMF1,YNRX',
            'BIB020000' => 'QRMF1',
            'BIB020010' => 'QRMF1,YNRX',
            'BIB020020' => 'QRMF1',
            'BIB020080' => 'QRMF1',
            'BIB020090' => 'QRMF1',
            'BIB020030' => 'QRMF13',
            'BIB020100' => 'QRMF1',
            'BIB020110' => 'QRMF1',
            'BIB020040' => 'QRMF1',
            'BIB020050' => 'QRMF1',
            'BIB020060' => 'QRMF1',
            'BIB020070' => 'QRMF1,YNRX',
            'BIO000000' => 'DNB',
            'BIO023000' => 'DNBP',
            'BIO001000' => 'DNBF,AGB,AMB,AJCD',
            'BIO034000' => 'DNB,WG',
            'BIO003000' => 'DNBB',
            'BIO024000' => 'DNB,DNXC',
            'BIO029000' => 'DNB,WB',
            'BIO002000' => 'DNB,JBSL',
            'BIO002010' => 'DNB,5PB-US-C,5PBD',
            'BIO002040' => 'DNB,5PB-AA-A,5PBCB',
            'BIO002020' => 'DNB,5PB-US-D',
            'BIO002030' => 'DNB,5PB-US-H',
            'BIO028000' => 'DNB,JBSL11,5PBA',
            'BIO025000' => 'DNB,KNTP',
            'BIO019000' => 'DNB,JN',
            'BIO005000' => 'DNBF',
            'BIO030000' => 'DNBT,WN',
            'BIO035000' => 'DNBF,AKT',
            'BIO036000' => 'DNB,JKSW',
            'BIO006000' => 'DNBH',
            'BIO037000' => 'DNB,5PGJ',
            'BIO027000' => 'DNB,JKSW1',
            'BIO020000' => 'DNB,LAT',
            'BIO031000' => 'DNB,JBSJ,5PS',
            'BIO007000' => 'DNBL',
            'BIO017000' => 'DNBT',
            'BIO008000' => 'DNBH,DNXM',
            'BIO004000' => 'DNBF,AVN,AVP',
            'BIO033000' => 'DNB,5PM',
            'BIO026000' => 'DNC',
            'BIO009000' => 'DNBM',
            'BIO010000' => 'DNBH,JPHL',
            'BIO011000' => 'DNBH,JPHL',
            'BIO012000' => 'GBCB',
            'BIO018000' => 'DNBX',
            'BIO013000' => 'DNB',
            'BIO014000' => 'DNBR',
            'BIO015000' => 'DNBT',
            'BIO032000' => 'DNBH,JPW',
            'BIO021000' => 'DNBM,JM,JH',
            'BIO016000' => 'DNBS',
            'BIO038000' => 'DNXP',
            'BIO022000' => 'DNB,JBSF1',
            'OCC000000' => 'VX',
            'OCC022000' => 'VXPR',
            'OCC042000' => 'VXWM,QRYX2',
            'OCC031000' => 'JBG',
            'OCC032000' => 'VXPS',
            'OCC002000' => 'VXFA',
            'OCC030000' => 'VXFA,1FP',
            'OCC009000' => 'VXFA1',
            'OCC044000' => 'VXHT2',
            'OCC036010' => 'QRST',
            'OCC003000' => 'VXPS',
            'OCC004000' => 'VXPC',
            'OCC005000' => 'VXF',
            'OCC008000' => 'VXF',
            'OCC017000' => 'VXFJ1',
            'OCC045000' => 'VXF',
            'OCC024000' => 'VXFC1',
            'OCC006000' => 'VXN',
            'OCC039000' => 'VXA',
            'OCC037000' => 'VXV',
            'OCC033000' => 'VXK,QRY',
            'OCC036050' => 'VXWS,QRY',
            'OCC011000' => 'VXH',
            'OCC011010' => 'VXHK',
            'OCC011020' => 'VXH,QRVJ2',
            'OCC040000' => 'QRYX2',
            'OCC038000' => 'VXFD',
            'OCC019000' => 'VXA',
            'OCC028000' => 'VXWM,QRYX2',
            'OCC010000' => 'VXM,VSPD',
            'OCC012000' => 'VXW,QRVK2',
            'OCC043000' => 'VXHF',
            'OCC014000' => 'VXA',
            'OCC015000' => 'VXFN',
            'OCC016000' => 'VXW,QRYX',
            'OCC018000' => 'VXP,JMX',
            'OCC007000' => 'VXP,VXFT',
            'OCC034000' => 'VXPR',
            'OCC035000' => 'VXPJ',
            'OCC020000' => 'VX,QRVK',
            'OCC021000' => 'VX,GBC',
            'OCC041000' => 'QRVP7,VFVC',
            'OCC036030' => 'VXWS,QRRV',
            'OCC027000' => 'QRYM2,VXPH',
            'OCC023000' => 'VXQ',
            'OCC025000' => 'VXQB',
            'OCC029000' => 'VXQ',
            'OCC026000' => 'VXWT,QRYX5',
            'BUS000000' => 'KJ',
            'BUS001000' => 'KFC',
            'BUS001010' => 'KFCF',
            'BUS001020' => 'KFCP',
            'BUS001040' => 'KFCM',
            'BUS001050' => 'KFCR',
            'BUS002000' => 'KJSA',
            'BUS003000' => 'KFCM1',
            'BUS004000' => 'KFFK',
            'BUS114000' => 'KFFJ,KFFF',
            'BUS005000' => 'KFCM',
            'BUS006000' => 'KJMV1',
            'BUS007000' => 'KJP',
            'BUS007010' => 'KJP',
            'BUS008000' => 'KJG',
            'BUS009000' => 'KJP',
            'BUS010000' => 'LNC',
            'BUS091000' => 'KJQ',
            'BUS011000' => 'KJP,CBW',
            'BUS012000' => 'VSC',
            'BUS012010' => 'VSC,JNRD',
            'BUS012020' => 'VSCB',
            'BUS037020' => 'VSCB',
            'BUS056030' => 'VSCB',
            'BUS073000' => 'KCC',
            'BUS013000' => 'KCL',
            'BUS110000' => 'KJ,LNAC5',
            'BUS075000' => 'KJL',
            'BUS016000' => 'KCK',
            'BUS077000' => 'KJZ',
            'BUS017000' => 'KFFH',
            'BUS017010' => 'KFFH',
            'BUS017020' => 'KFFH,KFCM2',
            'BUS017030' => 'KFFH',
            'BUS104000' => 'KJR',
            'BUS111000' => 'KJD',
            'BUS018000' => 'KJSU',
            'BUS019000' => 'KJMD',
            'BUS092000' => 'KCM',
            'BUS020000' => 'KJMV6',
            'BUS068000' => 'KCM',
            'BUS072000' => 'KCM,RNU',
            'BUS078000' => 'KJMV9',
            'BUS090000' => 'KJE',
            'BUS090010' => 'KJSG',
            'BUS090030' => 'KFFF,UDBM',
            'BUS090050' => 'KJE,KJSG',
            'BUS090040' => 'KJE,KJVS',
            'BUS021000' => 'KCH',
            'BUS022000' => 'KCV,RGCM',
            'BUS023000' => 'KCZ',
            'BUS069000' => 'KC',
            'BUS069010' => 'KC',
            'BUS039000' => 'KCB',
            'BUS044000' => 'KCC',
            'BUS069040' => 'KCK',
            'BUS069030' => 'KCA',
            'BUS024000' => 'KJB',
            'BUS025000' => 'KJH',
            'BUS099000' => 'KCVG',
            'BUS026000' => 'KCL',
            'BUS093000' => 'KJMV4',
            'BUS027000' => 'KFF',
            'BUS027010' => 'KFF',
            'BUS027020' => 'KFF,GPQD',
            'BUS027030' => 'KFFT',
            'BUS086000' => 'KCJ',
            'BUS028000' => 'KFFJ',
            'BUS105000' => 'KJVF',
            'BUS029000' => 'KCSA',
            'BUS115000' => 'KJVS',
            'BUS113000' => 'GTQ',
            'BUS079000' => 'KCP',
            'BUS094000' => 'KJJ',
            'BUS080000' => 'KJVS',
            'BUS030000' => 'KJMV2',
            'BUS082000' => 'KJM',
            'BUS070000' => 'KN',
            'BUS070010' => 'KNAC',
            'BUS070020' => 'KNDR',
            'BUS070030' => 'KNTX',
            'BUS070160' => 'KNJ',
            'BUS070040' => 'KNB',
            'BUS070110' => 'KNT',
            'BUS070090' => 'KNDD,KNSX',
            'BUS070140' => 'KNS,KFF',
            'BUS070120' => 'KNAC',
            'BUS070170' => 'KN,MBP',
            'BUS081000' => 'KNSG',
            'BUS070050' => 'KND',
            'BUS070060' => 'KNT',
            'BUS070150' => 'KNAT',
            'BUS070070' => 'KNS',
            'BUS070130' => 'KNDC',
            'BUS057000' => 'KNP',
            'BUS070080' => 'KNS',
            'BUS070100' => 'KNG',
            'BUS031000' => 'KCBM',
            'BUS083000' => 'KJMK',
            'BUS032000' => 'KCS',
            'BUS033000' => 'KFFN',
            'BUS033010' => 'KFFN',
            'BUS033020' => 'KFFN',
            'BUS033040' => 'KFFN',
            'BUS033050' => 'KFFN',
            'BUS033060' => 'KFFN',
            'BUS033080' => 'KFFN',
            'BUS033070' => 'KFFN,GPQD',
            'BUS034000' => 'KFF',
            'BUS035000' => 'KJK',
            'BUS001030' => 'KJK,KFC',
            'BUS069020' => 'KCL',
            'BUS043030' => 'KJK,KJS',
            'BUS064020' => 'KJK,KFFD',
            'BUS036000' => 'KFFM',
            'BUS036070' => 'KFFM',
            'BUS036010' => 'KFFM',
            'BUS014000' => 'KFFM',
            'BUS014010' => 'KFFM',
            'BUS014020' => 'KFFM',
            'BUS036080' => 'KFFM',
            'BUS036020' => 'KFFM',
            'BUS036030' => 'KFFM',
            'BUS036040' => 'KFFM',
            'BUS036090' => 'KFFM',
            'BUS036050' => 'KFFM,KFFR',
            'BUS036060' => 'KFFM',
            'BUS112000' => 'KFF,LWKL,5PGP',
            'BUS098000' => 'KCVP,KJMK',
            'BUS038000' => 'KCF,KNX',
            'BUS038010' => 'KCF,KNXU',
            'BUS038020' => 'KCF',
            'BUS071000' => 'KJMB',
            'BUS116000' => 'KJMV9',
            'BUS040000' => 'KJS',
            'BUS041000' => 'KJM',
            'BUS042000' => 'KJM',
            'BUS043000' => 'KJS',
            'BUS043010' => 'KJSJ',
            'BUS043020' => 'KJS',
            'BUS043040' => 'KJS',
            'BUS043060' => 'KJSM',
            'BUS043050' => 'KJSJ',
            'BUS106000' => 'KJMV2,VSC',
            'BUS015000' => 'KJVB',
            'BUS045000' => 'KCBM',
            'BUS046000' => 'KJMB',
            'BUS100000' => 'GLZ,KJM',
            'BUS047000' => 'KJN',
            'BUS048000' => 'KJV',
            'BUS074000' => 'KJVX',
            'BUS074010' => 'KJVX,KF',
            'BUS074020' => 'KJVX,KFFC',
            'BUS074030' => 'KJVX,KJM',
            'BUS074040' => 'KJVX,KJS',
            'BUS084000' => 'KJWF',
            'BUS095000' => 'KJWF',
            'BUS096000' => 'KJWB',
            'BUS049000' => 'KJT',
            'BUS085000' => 'KJU',
            'BUS103000' => 'KJU',
            'BUS102000' => 'KJVT',
            'BUS050000' => 'VSB',
            'BUS050010' => 'VSB,KJMV1',
            'BUS050020' => 'VSB,KFFM',
            'BUS050030' => 'VSB,KFCM',
            'BUS050040' => 'VSB,VSR',
            'BUS050050' => 'VSB,KFCT',
            'BUS107000' => 'VSC',
            'BUS087000' => 'KJMV5,KJMN',
            'BUS101000' => 'KJMP',
            'BUS051000' => 'KFFD',
            'BUS052000' => 'KJSP',
            'BUS076000' => 'KJMV8',
            'BUS053000' => 'KJMQ',
            'BUS054000' => 'KFFR',
            'BUS054010' => 'KFFR,VSH',
            'BUS054020' => 'KFFR',
            'BUS054030' => 'KFFR',
            'BUS055000' => 'KJ,GBC',
            'BUS108000' => 'KJMV6',
            'BUS058000' => 'KJS',
            'BUS058010' => 'KJMV7',
            'BUS089000' => 'KJWS',
            'BUS059000' => 'KJW',
            'BUS060000' => 'KJVS',
            'BUS061000' => 'KCH',
            'BUS063000' => 'KJC',
            'BUS062000' => 'KCS',
            'BUS064000' => 'KFCT',
            'BUS064010' => 'KFCT,KFFH,LNUC',
            'BUS064030' => 'KFCT,KJVS,LNUC',
            'BUS088000' => 'KJMT',
            'BUS065000' => 'KJMQ',
            'BUS066000' => 'KJMV2',
            'BUS067000' => 'KCVS',
            'BUS109000' => 'KJ,JBSF1',
            'BUS097000' => 'KJWX',
            'BUS117000' => 'KJMV2,JBFK4',
            'CGN000000' => 'XAK',
            'CGN012000' => 'XQB',
            'CGN001000' => 'XAK,DNT',
            'CGN008000' => 'XQF',
            'CGN004010' => 'XQD',
            'CGN013000' => 'XQL,FDB',
            'CGN004020' => 'XQXE,5X',
            'CGN004030' => 'XQM',
            'CGN010000' => 'XQV',
            'CGN004040' => 'XQH',
            'CGN014000' => 'XQT',
            'CGN009000' => 'XAK,5PS',
            'CGN006000' => 'XAK',
            'CGN004050' => 'XAM',
            'CGN004240' => 'XAM,XQG',
            'CGN004100' => 'XAM,XQD',
            'CGN004230' => 'XAM,FDB',
            'CGN004110' => 'XAMX,5X',
            'CGN004120' => 'XAM,XQM',
            'CGN004140' => 'XAM,XQV',
            'CGN004150' => 'XAM,XQH',
            'CGN004250' => 'XAM,XQT',
            'CGN004300' => 'XAM,XQM,XQL',
            'CGN004130' => 'XAM,5PS',
            'CGN004320' => 'XAM,YFZS',
            'CGN004260' => 'XAM,XQM',
            'CGN004270' => 'XAM,XQL',
            'CGN004160' => 'XAM,XQC',
            'CGN004170' => 'XAM,XQA',
            'CGN004180' => 'XAM,XQR',
            'CGN004280' => 'XAM,XQS',
            'CGN004190' => 'XAM,XQL',
            'CGN004200' => 'XAM,XQJ',
            'CGN004290' => 'XAM,XQH',
            'CGN004210' => 'XAMT',
            'CGN004310' => 'XAMY',
            'CGN004060' => 'XQC',
            'CGN007000' => 'XQA',
            'CGN007010' => 'XQA',
            'CGN007020' => 'XQA',
            'CGN015000' => 'XR',
            'CGN011000' => 'XQW,5PG',
            'CGN004090' => 'XQR',
            'CGN004070' => 'XQL',
            'CGN004080' => 'XQK',
            'CGN016000' => 'XQH',
            'CGN017000' => 'XQK',
            'COM000000' => 'UB',
            'COM004000' => 'UYQ',
            'COM016000' => 'UYQV,UYQP',
            'COM025000' => 'UYQE',
            'COM042000' => 'UYQL',
            'COM093000' => 'UNKD',
            'COM005000' => 'UFL',
            'COM027000' => 'UFK',
            'COM005030' => 'UFL,KJC',
            'COM066000' => 'UFS',
            'COM084010' => 'UNS',
            'COM084020' => 'UDF',
            'COM084030' => 'UFB',
            'COM078000' => 'UFG',
            'COM081000' => 'UFP',
            'COM054000' => 'UFC',
            'COM058000' => 'UFD',
            'COM006000' => 'UKP,VSG',
            'COM055000' => 'UQ',
            'COM055030' => 'UQJ',
            'COM055010' => 'UQR',
            'COM055020' => 'UQF',
            'COM055040' => 'UQ,UNS',
            'COM011000' => 'UYF',
            'COM059000' => 'UY,TJF',
            'COM013000' => 'UB',
            'COM014000' => 'UY',
            'COM072000' => 'UYM',
            'COM017000' => 'GPFC',
            'COM018000' => 'UNC,GPH',
            'COM021030' => 'UNC',
            'COM062000' => 'UNA,UYZM',
            'COM089000' => 'UYZF,UNC',
            'COM021040' => 'UND',
            'COM094000' => 'UYQM',
            'COM044000' => 'UYQN',
            'COM021000' => 'UN',
            'COM087000' => 'UG',
            'COM087010' => 'UGM,UYU',
            'COM007000' => 'UGC,TGPC',
            'COM087020' => 'UGL',
            'COM087030' => 'UGP,UDP',
            'COM071000' => 'UGV,UGN',
            'COM048000' => 'UTR',
            'COM061000' => 'UTD',
            'COM091000' => 'UTC',
            'COM063000' => 'UF,KJMV3',
            'COM085000' => 'UB,CBW',
            'COM023000' => 'UB,JNV',
            'COM064000' => 'KJE',
            'COM092000' => 'UKM',
            'COM099000' => 'UTFB,JKVF1',
            'COM067000' => 'UK',
            'COM074000' => 'UDT,UDH',
            'COM041000' => 'TJFD',
            'COM038000' => 'UKD',
            'COM050000' => 'UKP',
            'COM050020' => 'UKPM',
            'COM050010' => 'UKPC',
            'COM049000' => 'UKS',
            'COM090000' => 'UDH',
            'COM080000' => 'UBB,TBX',
            'COM079010' => 'UYZ',
            'COM012050' => 'UYT',
            'COM032000' => 'UB',
            'COM031000' => 'UYZM,GPF',
            'COM034000' => 'UG',
            'COM060000' => 'UBW',
            'COM060100' => 'UDBS',
            'COM060170' => 'UFL,KJMV3',
            'COM060040' => 'UDD,URD',
            'COM060110' => 'UDBS',
            'COM060120' => 'UDBD',
            'COM060140' => 'UDBS',
            'COM060150' => 'UD',
            'COM060010' => 'UDBR',
            'COM060130' => 'UGB',
            'COM060160' => 'UMW',
            'COM060180' => 'UMWS',
            'COM095000' => 'UKL',
            'COM051010' => 'UMX',
            'COM051040' => 'UYFL',
            'COM051060' => 'UMX',
            'COM051070' => 'UMX',
            'COM051310' => 'UMPN',
            'COM051270' => 'UMW',
            'COM051280' => 'UMX',
            'COM051260' => 'UMW',
            'COM051480' => 'UMW',
            'COM051470' => 'UMPN',
            'COM051350' => 'UMX',
            'COM051400' => 'UMX',
            'COM051360' => 'UMX',
            'COM051410' => 'UMX',
            'COM051170' => 'UMT',
            'COM051450' => 'UMZL',
            'COM051200' => 'UMP',
            'COM051320' => 'UMT',
            'COM036000' => 'UYF',
            'COM037000' => 'UYA,UYQM',
            'COM039000' => 'UFL,KJ',
            'COM077000' => 'UFM',
            'COM043000' => 'UT',
            'COM075000' => 'UKN,UK',
            'COM060030' => 'UT',
            'COM043020' => 'UT',
            'COM043040' => 'UTP',
            'COM046000' => 'UL',
            'COM046100' => 'ULP',
            'COM046110' => 'ULH,ULP',
            'COM046070' => 'ULJL',
            'COM046020' => 'ULH',
            'COM046080' => 'ULQ',
            'COM046030' => 'ULJ',
            'COM046040' => 'ULD',
            'COM046050' => 'ULD',
            'COM047000' => 'UYQV',
            'COM096000' => 'UYFP',
            'COM051000' => 'UM',
            'COM051300' => 'UMB',
            'COM010000' => 'UMC',
            'COM012040' => 'UMK',
            'COM051370' => 'UMQ',
            'COM051380' => 'UMP',
            'COM051460' => 'UMS',
            'COM051210' => 'UMN',
            'COM051390' => 'UM',
            'COM051220' => 'UYFP',
            'COM097000' => 'UYX',
            'COM052000' => 'UB,GBC',
            'COM053000' => 'UR',
            'COM083000' => 'URY,GPJ',
            'COM043050' => 'UTN',
            'COM015000' => 'URJ',
            'COM079000' => 'UBJ',
            'COM051230' => 'UM',
            'COM012000' => 'UG',
            'COM051430' => 'UM,KJMP',
            'COM051330' => 'UMZT',
            'COM051240' => 'UYD',
            'COM051440' => 'UM',
            'COM073000' => 'UYQS,UYU',
            'COM088000' => 'UTE',
            'COM019000' => 'UTFB',
            'COM020020' => 'UTM',
            'COM088010' => 'UTE,ULJL',
            'COM030000' => 'UTE,UKS,UNH',
            'COM046090' => 'UTV',
            'COM088020' => 'UTE,ULD',
            'COM070000' => 'UYZG',
            'COM057000' => 'UYV,UYW,UDBV',
            'COM098000' => 'UDY',
            'CKB000000' => 'WB',
            'CKB107000' => 'WBQ,VFXB',
            'CKB100000' => 'WBX',
            'CKB088000' => 'WBXD',
            'CKB006000' => 'WBXD3',
            'CKB007000' => 'WBXD2',
            'CKB130000' => 'WBXD3',
            'CKB126000' => 'WBXD1',
            'CKB019000' => 'WBXN1',
            'CKB118000' => 'WBXN3',
            'CKB008000' => 'WBXN',
            'CKB127000' => 'WBAC',
            'CKB119000' => 'WBQ',
            'CKB120000' => 'WBQ',
            'CKB101000' => 'WBV',
            'CKB003000' => 'WBVD',
            'CKB009000' => 'WBVS2',
            'CKB010000' => 'WBV',
            'CKB012000' => 'WBV',
            'CKB014000' => 'WBVS1',
            'CKB112000' => 'WBVM',
            'CKB095000' => 'WBVQ',
            'CKB021000' => 'WBVS',
            'CKB024000' => 'WBVQ',
            'CKB122000' => 'WBVQ',
            'CKB062000' => 'WBVS',
            'CKB063000' => 'WBVS',
            'CKB064000' => 'WBV',
            'CKB073000' => 'WBVG',
            'CKB121000' => 'WBVR',
            'CKB102000' => 'WBVH',
            'CKB079000' => 'WBVD,WBVM',
            'CKB029000' => 'WBR,WJX',
            'CKB131000' => 'WBS',
            'CKB030000' => 'WB',
            'CKB039000' => 'WBH',
            'CKB106000' => 'WBHS,VFJB1',
            'CKB103000' => 'WBHS,VFJB3',
            'CKB025000' => 'WBHS5,VFJB5',
            'CKB111000' => 'WBHS2,VFJB1',
            'CKB104000' => 'WBHS,VFJB4',
            'CKB114000' => 'WBHS4,VFMD',
            'CKB108000' => 'WBHS3,VFMD',
            'CKB050000' => 'WBHS1,VFMD',
            'CKB051000' => 'WBHS1,VFMD',
            'CKB052000' => 'WBHS,VFMD',
            'CKB026000' => 'WBHS,VFMD',
            'CKB041000' => 'WB,NHTB',
            'CKB042000' => 'WBA,5HC',
            'CKB115000' => 'WBB',
            'CKB128000' => 'WBB',
            'CKB023000' => 'WBA',
            'CKB004000' => 'WBVS',
            'CKB005000' => 'WBSB',
            'CKB015000' => 'WBW',
            'CKB020000' => 'WBC',
            'CKB116000' => 'WBA',
            'CKB033000' => 'WBA',
            'CKB037000' => 'WBA',
            'CKB113000' => 'WBD',
            'CKB057000' => 'WBS',
            'CKB060000' => 'WBSB',
            'CKB129000' => 'WBS',
            'CKB068000' => 'WBA',
            'CKB069000' => 'WBA',
            'CKB070000' => 'WBF',
            'CKB110000' => 'WBH,WBK',
            'CKB109000' => 'WBSD',
            'CKB081000' => 'WBS',
            'CKB089000' => 'WBS',
            'CKB117000' => 'WBA,WNG',
            'CKB071000' => 'WB,GBC',
            'CKB031000' => 'WBN',
            'CKB001000' => 'WBN,1H',
            'CKB002000' => 'WBN,1KBB',
            'CKB002010' => 'WBN,1KBB-US-WPC',
            'CKB002020' => 'WBN,1KBB-US-NA',
            'CKB002030' => 'WBN,1KBB-US-M',
            'CKB002040' => 'WBN,1KBB-US-NE',
            'CKB002050' => 'WBN,1KBB-US-WPN',
            'CKB002060' => 'WBN,1KBB-US-S',
            'CKB002070' => 'WBN,1KBB-US-SW,1KBB-US-WM',
            'CKB002080' => 'WBN,1KBB-US-W',
            'CKB090000' => 'WBN,1F',
            'CKB097000' => 'WBN,1M',
            'CKB013000' => 'WBN,5PB-US-G,5PB-US-F',
            'CKB091000' => 'WBN,1KBC',
            'CKB016000' => 'WBN,1KJ',
            'CKB099000' => 'WBN,1KL',
            'CKB017000' => 'WBN,1FPC',
            'CKB011000' => 'WBN,1DDU',
            'CKB092000' => 'WBN,1D',
            'CKB034000' => 'WBN,1DDF',
            'CKB036000' => 'WBN,1DFG',
            'CKB038000' => 'WBN,1DXG',
            'CKB043000' => 'WBN,1DTH',
            'CKB044000' => 'WBN,1FK',
            'CKB058000' => 'WBN,1K,5PBA',
            'CKB045000' => 'WBN',
            'CKB046000' => 'WBN,1DDR',
            'CKB047000' => 'WBN,1DST',
            'CKB048000' => 'WBN,1FPJ',
            'CKB049000' => 'WBN,5PGJ',
            'CKB123000' => 'WBN,1FPK',
            'CKB055000' => 'WBN,1QRM',
            'CKB056000' => 'WBN,1KLCM',
            'CKB093000' => 'WBN,1FB',
            'CKB065000' => 'WBN,1DTP',
            'CKB066000' => 'WBN,1DSP',
            'CKB072000' => 'WBN,1DTA',
            'CKB074000' => 'WBN,1DN',
            'CKB078000' => 'WBN,1KBB,5PB-US-C',
            'CKB124000' => 'WBN,1FM',
            'CKB080000' => 'WBN,1DSE',
            'CKB083000' => 'WBN,1FMT',
            'CKB084000' => 'WBN,1DTT',
            'CKB094000' => 'WBN,1FMV',
            'CKB077000' => 'WBA,5HR',
            'CKB105000' => 'WBT',
            'CKB018000' => 'WBTX',
            'CKB096000' => 'WBTR',
            'CKB035000' => 'WBTM',
            'CKB032000' => 'WBTB',
            'CKB040000' => 'WBTH',
            'CKB054000' => 'WBTB',
            'CKB059000' => 'WBH,WBK',
            'CKB061000' => 'WBTP',
            'CKB067000' => 'WBTC',
            'CKB098000' => 'WBTJ',
            'CKB076000' => 'WBTF',
            'CKB085000' => 'WBTM,WBVG',
            'CKB082000' => 'WJXF',
            'CKB125000' => 'WBJK',
            'CKB086000' => 'WBJ',
            'CRA000000' => 'WF',
            'CRA001000' => 'WFBQ',
            'CRA002000' => 'WFL',
            'CRA048000' => 'WFJ',
            'CRA046000' => 'WFT',
            'CRA049000' => 'WFS',
            'CRA003000' => 'WFS',
            'CRA043000' => 'WF,YNPH',
            'CRA005000' => 'WJK',
            'CRA056000' => 'WFH',
            'CRA057000' => 'WFH',
            'CRA006000' => 'WFS',
            'CRA007000' => 'WFBV',
            'CRA009000' => 'WFB,WJF',
            'CRA060000' => 'WFB',
            'CRA061000' => 'WFB',
            'CRA010000' => 'WFW',
            'CRA047000' => 'WFV',
            'CRA011000' => 'WFQ',
            'CRA062000' => 'WFQ,WKDW',
            'CRA012000' => 'WFN',
            'CRA034000' => 'WF,5H',
            'CRA014000' => 'WFJ',
            'CRA055000' => 'WFC',
            'CRA050000' => 'WFD',
            'CRA017000' => 'WFP',
            'CRA018000' => 'WDH,WFH',
            'CRA054000' => 'WF',
            'CRA045000' => 'WDHM',
            'CRA020000' => 'WDHB,WFH',
            'CRA053000' => 'WF',
            'CRA022000' => 'WFB',
            'CRA004000' => 'WFBS2',
            'CRA044000' => 'WFBC',
            'CRA008000' => 'WFBC',
            'CRA015000' => 'WFBS1',
            'CRA016000' => 'WFBL',
            'CRA021000' => 'WFBC',
            'CRA023000' => 'WFTM',
            'CRA024000' => 'WFA',
            'CRA025000' => 'WFT',
            'CRA026000' => 'WFBQ',
            'CRA051000' => 'WFS',
            'CRA027000' => 'WFW,WJJ',
            'CRA028000' => 'WFN',
            'CRA029000' => 'AFH,WFA',
            'CRA030000' => 'WFH,ATXM',
            'CRA031000' => 'WFBQ',
            'CRA032000' => 'WF,GBC',
            'CRA058000' => 'WFB',
            'CRA033000' => 'WFF',
            'CRA052000' => 'WFT',
            'CRA035000' => 'WFBW',
            'CRA064000' => 'WFS',
            'CRA036000' => 'WFA',
            'CRA037000' => 'WFH,WFB',
            'CRA065000' => 'TTX',
            'CRA039000' => 'WFH',
            'CRA063000' => 'WF,VSZD',
            'CRA040000' => 'WFG',
            'CRA059000' => 'WFP',
            'CRA041000' => 'WFH,WFQ',
            'CRA042000' => 'WFQ',
            'DES000000' => 'AK',
            'DES001000' => 'AKH',
            'DES002000' => 'AK',
            'DES003000' => 'AFT',
            'DES004000' => 'AK',
            'DES005000' => 'AKT,AKTF',
            'DES006000' => 'AKR',
            'DES007000' => 'AKC',
            'DES007010' => 'AKL,KJSA',
            'DES007020' => 'AKL,KJSC',
            'DES007030' => 'AKL',
            'DES007040' => 'AKLB',
            'DES007050' => 'AKD',
            'DES008000' => 'AKX',
            'DES015000' => 'AKB',
            'DES009000' => 'AKP',
            'DES014000' => 'AFKG',
            'DES011000' => 'AKP',
            'DES012000' => 'AK,GBC',
            'DES013000' => 'AKT,AFW',
            'DRA000000' => 'DD',
            'DRA011000' => 'DD,1H',
            'DRA001000' => 'DD,1KBB',
            'DRA001010' => 'DD,1KBB,5PB-US-C',
            'DRA006000' => 'DDA',
            'DRA002000' => 'DD,DNT',
            'DRA005000' => 'DD,1F',
            'DRA005010' => 'DD,1FPJ',
            'DRA012000' => 'DD,1M',
            'DRA013000' => 'DD,1KBC',
            'DRA014000' => 'DD,1KJ,1KL',
            'DRA004000' => 'DD,1D',
            'DRA003000' => 'DD,1DDU,1DDR',
            'DRA004010' => 'DD,1DDF',
            'DRA004020' => 'DD,1DFG',
            'DRA004030' => 'DD,1DST',
            'DRA004040' => 'DD,1DSE,1DSP',
            'DRA020000' => 'DD,1K,5PBA',
            'DRA017000' => 'DD,5PS',
            'DRA018000' => 'DDA,6MB',
            'DRA015000' => 'DD,1FB',
            'DRA008000' => 'DD,QRA',
            'DRA016000' => 'DD,1DTA,1QBDR',
            'DRA010000' => 'DDA,5PX-GB-S',
            'DRA019000' => 'DD',
            'EDU000000' => 'JN',
            'EDU001000' => 'JNK',
            'EDU001020' => 'JNK,JNLB,JNLC',
            'EDU001010' => 'JNK',
            'EDU001030' => 'JNK,JNM',
            'EDU001040' => 'JNK',
            'EDU002000' => 'JNP',
            'EDU003000' => 'JNF',
            'EDU057000' => 'JNDG,YPA',
            'EDU049000' => 'JNT',
            'EDU005000' => 'JNSV',
            'EDU044000' => 'JNT',
            'EDU050000' => 'JNT',
            'EDU043000' => 'JND',
            'EDU039000' => 'JNV',
            'EDU006000' => 'JNFC',
            'EDU014000' => 'JNFC',
            'EDU031000' => 'JNR',
            'EDU045000' => 'JNFC',
            'EDU007000' => 'JNDG',
            'EDU008000' => 'JN,GPQ',
            'EDU041000' => 'JNQ',
            'EDU034000' => 'JNF',
            'EDU034030' => 'JNF,JPQB',
            'EDU034010' => 'JNF',
            'EDU009000' => 'JNC',
            'EDU042000' => 'JN',
            'EDU011000' => 'JNDH',
            'EDU013000' => 'JNKG',
            'EDU016000' => 'JNB',
            'EDU017000' => 'JNH',
            'EDU048000' => 'JNFK',
            'EDU018000' => 'JNT',
            'EDU032000' => 'JNF',
            'EDU051000' => 'JNC',
            'EDU020000' => 'JNF,JBSL1',
            'EDU021000' => 'JND',
            'EDU036000' => 'JNK',
            'EDU022000' => 'JNF',
            'EDU040000' => 'JNA',
            'EDU046000' => 'JNMT',
            'EDU024000' => 'JN,GBC',
            'EDU037000' => 'JN',
            'EDU052000' => 'JN,JBSC',
            'EDU060000' => 'JNL',
            'EDU060010' => 'JNL',
            'EDU023000' => 'JNLA,JNG',
            'EDU010000' => 'JNLB',
            'EDU025000' => 'JNLC',
            'EDU015000' => 'JNM',
            'EDU060020' => 'JNL',
            'EDU034020' => 'JNLP',
            'EDU060030' => 'JNLP',
            'EDU060040' => 'JNL',
            'EDU060050' => 'JNLR',
            'EDU026000' => 'JNS',
            'EDU026050' => 'JNSL,5PM',
            'EDU026010' => 'JNS,5PM',
            'EDU026030' => 'JNS,5PM',
            'EDU026060' => 'JNSP',
            'EDU026020' => 'JNSG,5PMJ',
            'EDU026040' => 'JNSC,5PMB',
            'EDU058000' => 'JNDG',
            'EDU027000' => 'JN,JHBC',
            'EDU038000' => 'JNK,VSKB',
            'EDU059000' => 'JNT',
            'EDU053000' => 'JNMT',
            'EDU029000' => 'JNT',
            'EDU029090' => 'JNUM',
            'EDU029100' => 'JNT',
            'EDU029110' => 'JNU',
            'EDU029050' => 'JNU,YPA,YPJ',
            'EDU029070' => 'JNU,YPJJ6',
            'EDU029080' => 'JNTS,YPC',
            'EDU029060' => 'JNU,YPWL2',
            'EDU029010' => 'JNTS,YPMF',
            'EDU033000' => 'JNU,YPWF',
            'EDU029020' => 'JNTS,YPCA2',
            'EDU029030' => 'JNU,YPMP',
            'EDU029040' => 'JNU,YPJJ',
            'EDU030000' => 'JNDH',
            'EDU054000' => 'JN,JBSD',
            'EDU055000' => 'JNF,JBFK',
            'EDU061000' => 'JNQ',
            'EDU056000' => 'JNRV',
            'FAM000000' => 'VFV',
            'FAM001000' => 'JBFK,VFJM',
            'FAM001010' => 'JBFK1,VFJM',
            'FAM001030' => 'JBFK3,VFJM',
            'FAM001020' => 'JBFK,JBSP4,VFJM,5LKS',
            'FAM002000' => 'VF',
            'FAM004000' => 'JKSF,VFVK',
            'FAM006000' => 'VFV',
            'FAM007000' => 'VFJQ1',
            'FAM047000' => 'VFJR2,5PMJ',
            'FAM048000' => 'VFJR1,5PMH',
            'FAM008000' => 'VFXB1',
            'FAM050000' => 'VFXC',
            'FAM049000' => 'JBFK4,VFJN',
            'FAM012000' => 'JBSP1,JMC,5LC,5PM',
            'FAM013000' => 'VFV',
            'FAM051000' => 'VFVG',
            'FAM014000' => 'VFJX',
            'FAM015000' => 'VFVS',
            'FAM052000' => 'VFV',
            'FAM016000' => 'VFX',
            'FAM017000' => 'JKSG,JBSP4,5LKS',
            'FAM053000' => 'VFV',
            'FAM058000' => 'WQY',
            'FAM021000' => 'VFVN',
            'FAM028000' => 'VFJR3,5PMJ',
            'FAM056000' => 'VFV,JBSJ,5PS',
            'FAM046000' => 'VFV',
            'FAM003000' => 'VFXC1,JBSP2,5LF',
            'FAM025000' => 'VFXC,5LB',
            'FAM005000' => 'VFV,VFJG,JBSP4,5LKS',
            'FAM054000' => 'VFV,VFJG,JBSP3,5LKM',
            'FAM039000' => 'VFXC,JBSP1,5LC',
            'FAM043000' => 'VFXC1,JBSP2,5LF',
            'FAM029000' => 'VFVG',
            'FAM030000' => 'VFVG',
            'FAM055000' => 'VFV,JWC',
            'FAM057000' => 'VFV,JBSL13',
            'FAM034000' => 'VFX',
            'FAM034020' => 'VFX,VFVS',
            'FAM020000' => 'VFX,VFV,5JB',
            'FAM022000' => 'VFX,VFVX',
            'FAM032000' => 'VFX,VFV,5JA',
            'FAM033000' => 'VFX,VFVX',
            'FAM034010' => 'VFX',
            'FAM042000' => 'VFX,VFVS',
            'FAM035000' => 'VFJN',
            'FAM037000' => 'VFV,VFXC',
            'FAM038000' => 'VFV,GBC',
            'FAM041000' => 'VFV',
            'FAM044000' => 'VFXC',
            'FIC000000' => 'FB',
            'FIC064000' => 'FB',
            'FIC002000' => 'FJ',
            'FIC075000' => 'FYV,FYH',
            'FIC049000' => 'FB,5PB-US-C',
            'FIC049010' => 'FW,5PB-US-C,5PGM',
            'FIC049030' => 'FP,5PB-US-C,5X',
            'FIC049040' => 'FV,5PB-US-C',
            'FIC049050' => 'FF,5PB-US-C',
            'FIC049070' => 'FBAN,5PB-US-C',
            'FIC049020' => 'FB,5PB-US-C',
            'FIC040000' => 'FDK',
            'FIC053000' => 'FW,5PB-US-B',
            'FIC067000' => 'FB',
            'FIC003000' => 'DNT,FB',
            'FIC054000' => 'FB,5PB-US-D',
            'FIC041000' => 'FC',
            'FIC078000' => 'FW,5PGF',
            'FIC042000' => 'FW,5PGM',
            'FIC042090' => 'FW,FV,5PGM',
            'FIC042010' => 'FW,FBC,5PGM',
            'FIC042050' => 'FW,DNT,5PGM',
            'FIC042100' => 'FW,FBA,5PGM',
            'FIC042080' => 'FW,FM,5PGM',
            'FIC042020' => 'FW,FL,5PGM',
            'FIC042030' => 'FW,FV,5PGM',
            'FIC042040' => 'FW,FR,5PGM',
            'FIC042110' => 'FW,FRH,5PGM',
            'FIC042120' => 'FW,FRM,5PGM',
            'FIC042060' => 'FW,FH,5PGM',
            'FIC042070' => 'FW,FJW,5PGM',
            'FIC069000' => 'FB,FXR',
            'FIC004000' => 'FBC',
            'FIC043000' => 'FB,FXB',
            'FIC050000' => 'FF',
            'FIC051000' => 'FB,5PB',
            'FIC079000' => 'FB,5PM',
            'FIC070000' => 'FH',
            'FIC055000' => 'FDB',
            'FIC065000' => 'FYD',
            'FIC005000' => 'FP,5X',
            'FIC005010' => 'FP,5X',
            'FIC005020' => 'FP,DNT,5X',
            'FIC005060' => 'FP,FV,5X',
            'FIC005070' => 'FP,5X,5PS',
            'FIC005080' => 'FP,5X,5PSB',
            'FIC005030' => 'FP,5X,5PSG',
            'FIC005040' => 'FP,5X,5PSL',
            'FIC005090' => 'FP,5X,5PT',
            'FIC005050' => 'FP,5X',
            'FIC010000' => 'FN',
            'FIC045000' => 'FS',
            'FIC045010' => 'FS,FXD',
            'FIC045020' => 'FS',
            'FIC009000' => 'FM',
            'FIC009100' => 'FM,FJ',
            'FIC009110' => 'FMH,3KHF',
            'FIC009040' => 'FM,DNT',
            'FIC009010' => 'FMW',
            'FIC009070' => 'FMT',
            'FIC009120' => 'FM,VXQM',
            'FIC009020' => 'FMB',
            'FIC009130' => 'FMH,3MNQ-GB-V',
            'FIC009030' => 'FMH',
            'FIC009080' => 'FMK',
            'FIC009140' => 'FM,FJM',
            'FIC009050' => 'FM',
            'FIC009090' => 'FMR',
            'FIC009060' => 'FMX',
            'FIC076000' => 'FB,JBSF11',
            'FIC071000' => 'FB',
            'FIC012000' => 'FK',
            'FIC027040' => 'FB',
            'FIC056000' => 'FB,5PB-US-H',
            'FIC014000' => 'FV',
            'FIC014010' => 'FV,1QBA',
            'FIC014060' => 'FV,1KBB,3MNQ-US-E',
            'FIC014070' => 'FV,1KBB,3MG-US-A,3MLQ-US-B',
            'FIC014020' => 'FV,3KH,3KL',
            'FIC014030' => 'FV,3KLY',
            'FIC014040' => 'FV,FJMF,3MPBFB',
            'FIC014050' => 'FV,FJMS,3MPBLB',
            'FIC058000' => 'FB,5H',
            'FIC015000' => 'FK',
            'FIC016000' => 'FU',
            'FIC060000' => 'FU',
            'FIC059000' => 'FB,5PBA',
            'FIC046000' => 'FW,5PGJ',
            'FIC034000' => 'FB',
            'FIC068000' => 'FB,5PS',
            'FIC072000' => 'FB,5PSB',
            'FIC011000' => 'FB,5PSG',
            'FIC018000' => 'FB,5PSL',
            'FIC073000' => 'FB,5PT',
            'FIC019000' => 'FB',
            'FIC129000' => 'FYW',
            'FIC061000' => 'FMM',
            'FIC057000' => 'FYM',
            'FIC021000' => 'FB',
            'FIC035000' => 'FB,FXK',
            'FIC080000' => 'FV',
            'FIC081000' => 'FW,5PGP',
            'FIC022000' => 'FF',
            'FIC022100' => 'FFD',
            'FIC022050' => 'FF,DNT',
            'FIC022070' => 'FFJ',
            'FIC022110' => 'FFJ,WNG',
            'FIC022120' => 'FFJ,WF',
            'FIC022130' => 'FFJ,WB',
            'FIC022140' => 'FFJ',
            'FIC022150' => 'FFJ,FKW',
            'FIC022010' => 'FFL',
            'FIC022060' => 'FFH',
            'FIC022080' => 'FF',
            'FIC022160' => 'FF,5PGJ',
            'FIC022020' => 'FFP',
            'FIC022090' => 'FFD',
            'FIC022030' => 'FFC',
            'FIC022040' => 'FFS',
            'FIC077000' => 'FB,FXE',
            'FIC062000' => 'FFL',
            'FIC024000' => 'FKW',
            'FIC082000' => 'FBA,5P',
            'FIC037000' => 'FB,FXP',
            'FIC025000' => 'FB,FXM',
            'FIC026000' => 'FW,5PG',
            'FIC027000' => 'FR',
            'FIC027260' => 'FR,FJ',
            'FIC049060' => 'FR,5PB-US-C',
            'FIC027340' => 'FRR',
            'FIC027270' => 'FRF',
            'FIC027080' => 'FR,DNT',
            'FIC027020' => 'FRD',
            'FIC027010' => 'FRX,5X',
            'FIC027030' => 'FRT',
            'FIC027350' => 'FRP',
            'FIC027050' => 'FRH',
            'FIC027360' => 'FRH,1KBB',
            'FIC027140' => 'FRH,1QBA',
            'FIC027460' => 'FRH,1KBB,3MNQ-US-F',
            'FIC027150' => 'FRH,3KH,3KL',
            'FIC027070' => 'FRH,3ML-GB-PR',
            'FIC027370' => 'FRH,3KLY',
            'FIC027160' => 'FRH,1DDU-GB-S',
            'FIC027280' => 'FRH,3MD-GB-G',
            'FIC027200' => 'FRH,3MP',
            'FIC027170' => 'FRH,3MNQ-GB-V',
            'FIC027180' => 'FRH,1DN',
            'FIC027290' => 'FR,5HC',
            'FIC027380' => 'FR,5LKS',
            'FIC027300' => 'FR,5PS',
            'FIC027390' => 'FR,5PSB',
            'FIC027190' => 'FR,5PSG',
            'FIC027210' => 'FR,5PSL',
            'FIC027400' => 'FR,5PT',
            'FIC027410' => 'FRQ',
            'FIC027220' => 'FRP',
            'FIC027230' => 'FR,FXT,5PB',
            'FIC027240' => 'FRD,5LKE',
            'FIC027120' => 'FRT',
            'FIC027310' => 'FRT,VXQM2',
            'FIC027320' => 'FRT,VXQM2',
            'FIC027440' => 'FRT,VXWM',
            'FIC027420' => 'FRP',
            'FIC027470' => 'FRD,JBSW',
            'FIC027480' => 'FRD',
            'FIC027250' => 'FR,FQ',
            'FIC027450' => 'FR',
            'FIC027130' => 'FR,FL',
            'FIC027330' => 'FR,FG',
            'FIC027110' => 'FRM',
            'FIC027090' => 'FRU',
            'FIC027100' => 'FRJ',
            'FIC027430' => 'FRD',
            'FIC008000' => 'FT',
            'FIC052000' => 'FUP',
            'FIC028000' => 'FL',
            'FIC028010' => 'FL,FJ',
            'FIC028090' => 'FLU',
            'FIC028070' => 'FLQ',
            'FIC028040' => 'FL,DNT',
            'FIC028140' => 'FL,FF',
            'FIC028100' => 'FLPB',
            'FIC028110' => 'FLPB',
            'FIC028020' => 'FLH',
            'FIC028120' => 'FL,FU',
            'FIC028050' => 'FLR',
            'FIC028130' => 'FLW',
            'FIC028030' => 'FLS',
            'FIC028060' => 'FLM',
            'FIC028080' => 'FLG',
            'FIC047000' => 'FJN',
            'FIC029000' => 'FYB',
            'FIC066000' => 'FB,FXR',
            'FIC074000' => 'FB,FXR,1KBB-US-S',
            'FIC038000' => 'FG',
            'FIC063000' => 'FMS',
            'FIC031000' => 'FH',
            'FIC031010' => 'FH,FF',
            'FIC031100' => 'FH,FS',
            'FIC006000' => 'FHD',
            'FIC031020' => 'FH,FFH',
            'FIC031030' => 'FHP',
            'FIC031040' => 'FHM',
            'FIC031050' => 'FH,FJM',
            'FIC031060' => 'FHP',
            'FIC031080' => 'FHX',
            'FIC031070' => 'FH,FK',
            'FIC030000' => 'FH',
            'FIC036000' => 'FHK',
            'FIC031090' => 'FHT',
            'FIC048000' => 'FBAN',
            'FIC039000' => 'FDV',
            'FIC032000' => 'FJM',
            'FIC033000' => 'FJW',
            'FIC044000' => 'FB',
            'FIC083000' => 'FB',
            'FIC083010' => 'FB',
            'FIC083020' => 'FB',
            'FIC083030' => 'FB',
            'FIC083040' => 'FB',
            'FIC084000' => 'FB',
            'FIC084010' => 'FBC',
            'FIC084020' => 'FBC',
            'FIC084030' => 'FB',
            'FIC084040' => 'FBA',
            'FIC085000' => 'FB',
            'FIC086000' => 'FB',
            'FIC087000' => 'FB',
            'FIC088000' => 'FB',
            'FIC089000' => 'FB',
            'FIC090000' => 'FB',
            'FIC090010' => 'FBC',
            'FIC090020' => 'FB',
            'FIC090030' => 'FBA',
            'FIC091000' => 'FB',
            'FIC092000' => 'FB',
            'FIC093000' => 'FB',
            'FIC094000' => 'FB',
            'FIC094010' => 'FBC',
            'FIC094020' => 'FB',
            'FIC094030' => 'FBA',
            'FIC095000' => 'FB',
            'FIC096000' => 'FB',
            'FIC097000' => 'FB',
            'FIC098000' => 'FB',
            'FIC098010' => 'FBC',
            'FIC098020' => 'FBC',
            'FIC098030' => 'FBC',
            'FIC098040' => 'FBC',
            'FIC098050' => 'FB',
            'FIC098060' => 'FBA',
            'FIC099000' => 'FB',
            'FIC100000' => 'FB',
            'FIC101000' => 'FB',
            'FIC101010' => 'FBC',
            'FIC101020' => 'FBC',
            'FIC101030' => 'FB',
            'FIC101040' => 'FBA',
            'FIC102000' => 'FB',
            'FIC102010' => 'FB',
            'FIC102020' => 'FBA',
            'FIC103000' => 'FB',
            'FIC104000' => 'FB',
            'FIC105000' => 'FB',
            'FIC105010' => 'FBC',
            'FIC105020' => 'FB',
            'FIC105030' => 'FBA',
            'FIC106000' => 'FB',
            'FIC106010' => 'FBC',
            'FIC106020' => 'FB',
            'FIC106030' => 'FBA',
            'FIC107000' => 'FB',
            'FIC108000' => 'FB',
            'FIC109000' => 'FB',
            'FIC110000' => 'FB',
            'FIC111000' => 'FB',
            'FIC111010' => 'FB',
            'FIC111020' => 'FB',
            'FIC111030' => 'FB',
            'FIC112000' => 'FB',
            'FIC113000' => 'FB',
            'FIC114000' => 'FB',
            'FIC115000' => 'FB',
            'FIC116000' => 'FB',
            'FIC117000' => 'FB',
            'FIC118000' => 'FB',
            'FIC119000' => 'FB',
            'FIC120000' => 'FB',
            'FIC120010' => 'FBC',
            'FIC120020' => 'FB',
            'FIC120030' => 'FBA',
            'FIC121000' => 'FB',
            'FIC121010' => 'FBC',
            'FIC121020' => 'FB',
            'FIC121030' => 'FBA',
            'FIC122000' => 'FB',
            'FIC123000' => 'FB',
            'FIC124000' => 'FB',
            'FIC124010' => 'FBC',
            'FIC124020' => 'FB',
            'FIC124030' => 'FBA',
            'FIC125000' => 'FB',
            'FIC126000' => 'FB',
            'FIC127000' => 'FB',
            'FIC128000' => 'FB',
            'FOR000000' => 'CJ',
            'FOR001000' => 'CJ,2H',
            'FOR033000' => 'CJ,1QBA',
            'FOR002000' => 'CJ,2CSR',
            'FOR034000' => 'CJ,2AJB',
            'FOR029000' => 'CJ,2AF',
            'FOR003000' => 'CJ,2GDC',
            'FOR035000' => 'CJ,2ZP',
            'FOR036000' => 'CJ,2AGZ',
            'FOR004000' => 'CJ,2ACSD',
            'FOR006000' => 'CJ,2ACD',
            'FOR007000' => 'CJAD,2ACB,4LE',
            'FOR037000' => 'CJ,2FCF',
            'FOR008000' => 'CJ,2ADF',
            'FOR009000' => 'CJ,2ACG',
            'FOR010000' => 'CJ,2AHM',
            'FOR011000' => 'CJ,2CSJ',
            'FOR038000' => 'CJ,2BMH',
            'FOR012000' => 'CJ,2FCM',
            'FOR030000' => 'CJ,2B',
            'FOR031000' => 'CJ,2JN',
            'FOR013000' => 'CJ,2ADT',
            'FOR014000' => 'CJ,2GJ',
            'FOR015000' => 'CJ,2GK',
            'FOR016000' => 'CJ,2ADL',
            'FOR017000' => 'CJ',
            'FOR005000' => 'CBDX',
            'FOR018000' => 'WTK',
            'FOR039000' => 'CJ,2ACSN',
            'FOR032000' => 'CJ,2P',
            'FOR045000' => 'CJ,2ACBA,2ACBC',
            'FOR040000' => 'CJ,2BXF',
            'FOR019000' => 'CJ,2AGP',
            'FOR020000' => 'CJ,2ADP',
            'FOR041000' => 'CJ,2AD',
            'FOR021000' => 'CJ,2AGR',
            'FOR022000' => 'CJ,2ACS',
            'FOR023000' => 'CJ,2AGS',
            'FOR024000' => 'CJ,2AG',
            'FOR025000' => 'CJ,2G',
            'FOR026000' => 'CJ,2ADS',
            'FOR042000' => 'CJ,2HCBD',
            'FOR043000' => 'CJ,2ACSW',
            'FOR027000' => 'CJ,2FM',
            'FOR044000' => 'CJ,2GRV',
            'FOR028000' => 'CJ,2ACY',
            'GAM000000' => 'WD',
            'GAM020000' => 'WFX',
            'GAM001010' => 'WDMG',
            'GAM001000' => 'WDMG',
            'GAM002000' => 'WDMC',
            'GAM002030' => 'WDMC',
            'GAM002010' => 'WDMC1',
            'GAM002040' => 'WDMC2',
            'GAM001030' => 'WDMG1',
            'GAM019000' => 'WFX',
            'GAM003000' => 'WDKC',
            'GAM003040' => 'WDKC,CBD',
            'GAM023000' => 'SXE',
            'GAM016000' => 'WDHW',
            'GAM004000' => 'WDP',
            'GAM004020' => 'WDP',
            'GAM004050' => 'WDP',
            'GAM004030' => 'WDP',
            'GAM004040' => 'WDP,SKG',
            'GAM021000' => 'WZSJ',
            'GAM022000' => 'ATX',
            'GAM005000' => 'WDK',
            'GAM006000' => 'ATXF',
            'GAM018000' => 'WDJ',
            'GAM007000' => 'WDK',
            'GAM008000' => 'WDKX',
            'GAM009000' => 'WD,GBC',
            'GAM010000' => 'WDHW',
            'GAM024000' => 'WFX',
            'GAM017000' => 'WDKN',
            'GAM011000' => 'WD',
            'GAM012000' => 'WDKX',
            'GAM013000' => 'UDX',
            'GAM014000' => 'WDKC',
            'GAR000000' => 'WM',
            'GAR027000' => 'WM',
            'GAR027010' => 'WM,RGBA',
            'GAR027020' => 'WM',
            'GAR027030' => 'WM,1QMT',
            'GAR001000' => 'WMQR',
            'GAR002000' => 'WM',
            'GAR004000' => 'WMPC',
            'GAR004010' => 'WMPC',
            'GAR004030' => 'WMPC',
            'GAR004040' => 'WMPC',
            'GAR004050' => 'WMPC',
            'GAR004060' => 'WMPC',
            'GAR004080' => 'WMPC',
            'GAR005000' => 'WMPF',
            'GAR006000' => 'WMD',
            'GAR007000' => 'WMD',
            'GAR008000' => 'WMF',
            'GAR009000' => 'WMP',
            'GAR010000' => 'WMQR1',
            'GAR011000' => 'WMQ,TVSH',
            'GAR013000' => 'WMD',
            'GAR014000' => 'WMQL',
            'GAR015000' => 'WMQL',
            'GAR031000' => 'WMP',
            'GAR016000' => 'WMQF',
            'GAR017000' => 'WMPC',
            'GAR030000' => 'WMB,AJ',
            'GAR018000' => 'WM,GBC',
            'GAR019000' => 'WM',
            'GAR019010' => 'WM,1KBC',
            'GAR019020' => 'WM,1KBB-US-NA',
            'GAR019030' => 'WM,1KBB-US-M',
            'GAR019040' => 'WM,1KBB-US-NE',
            'GAR019050' => 'WM,1KBB-US-WPN',
            'GAR019060' => 'WM,1KBB-US-S',
            'GAR019070' => 'WM,1KBB-US-SW,1KBB-US-WM',
            'GAR019080' => 'WM,1KBB-US-W',
            'GAR020000' => 'WMQ',
            'GAR021000' => 'WMPS',
            'GAR022000' => 'WMQ',
            'GAR023000' => 'WMQ,WMPS',
            'GAR024000' => 'WMPS',
            'GAR028000' => 'WMT',
            'GAR025000' => 'WMPF',
            'GAR029000' => 'WMQW',
            'HEA000000' => 'VFD',
            'HEA001000' => 'VXHA',
            'HEA027000' => 'VFJB1',
            'HEA032000' => 'VXH',
            'HEA029000' => 'VXHC',
            'HEA003000' => 'WJH',
            'HEA047000' => 'VFD',
            'HEA044000' => 'VFXB',
            'HEA053000' => 'VXH',
            'HEA046000' => 'VFDJ',
            'HEA048000' => 'VFMD',
            'HEA006000' => 'VFMD',
            'HEA034000' => 'VFMD',
            'HEA013000' => 'VFMD',
            'HEA017000' => 'VFMD',
            'HEA023000' => 'VFMD',
            'HEA019000' => 'VFMD',
            'HEA039000' => 'VFJB',
            'HEA039020' => 'VFJB9,MJCJ2',
            'HEA039140' => 'VFJB6',
            'HEA039030' => 'VFJB3',
            'HEA039150' => 'VFJB9',
            'HEA039040' => 'VFJB,MJCJ',
            'HEA039050' => 'VFJB5',
            'HEA039160' => 'VFJB,MJG',
            'HEA039010' => 'VFJB,MJH',
            'HEA039060' => 'VFJB,MJCG1',
            'HEA039070' => 'VFJB,MJCJ1,MJS',
            'HEA039170' => 'VFJB7',
            'HEA039080' => 'VFJB4',
            'HEA039090' => 'VFJB9,MJCM',
            'HEA039100' => 'VFJB,MJE',
            'HEA039110' => 'VFJB,MKJ',
            'HEA039120' => 'VFJB,MJL',
            'HEA039130' => 'VFJB,MJK',
            'HEA007000' => 'VFMG',
            'HEA002000' => 'VFMG',
            'HEA007010' => 'VFMG,SP',
            'HEA007020' => 'VFMG,ATQ',
            'HEA007040' => 'VFMG',
            'HEA007030' => 'VFMG',
            'HEA007050' => 'VFMG2',
            'HEA022000' => 'VFMG',
            'HEA045000' => 'VFVJ,MFKC1',
            'HEA033000' => 'VFDF',
            'HEA009000' => 'VXH',
            'HEA028000' => 'VFD,MBP',
            'HEA010000' => 'VFDB',
            'HEA035000' => 'VFJD',
            'HEA011000' => 'VXHT4',
            'HEA012000' => 'VXH',
            'HEA030000' => 'VXHH',
            'HEA054000' => 'VFD,5PS',
            'HEA049000' => 'VFJG',
            'HEA014000' => 'VFMS,VXHJ',
            'HEA015000' => 'VFDM',
            'HEA051000' => 'VFDW2',
            'HEA055000' => 'VFJQ',
            'HEA016000' => 'VXHN',
            'HEA040000' => 'VFD,MKE',
            'HEA036000' => 'VFJ,MKAL',
            'HEA018000' => 'VFJD',
            'HEA041000' => 'VFXB',
            'HEA020000' => 'VF,GBC',
            'HEA042000' => 'VFVC',
            'HEA043000' => 'VFJV',
            'HEA052000' => 'SRMN1',
            'HEA050000' => 'VFD,MBNK',
            'HEA037000' => 'VFD,MJQ',
            'HEA024000' => 'VFDW',
            'HEA038000' => 'VFD',
            'HEA025000' => 'VFMG1',
            'HIS000000' => 'NH',
            'HIS001000' => 'NHH,1H',
            'HIS001010' => 'NHH,1HFJ',
            'HIS001020' => 'NHH,1HFG',
            'HIS001030' => 'NHH,1HB',
            'HIS001040' => 'NHH,1HFM',
            'HIS047000' => 'NHH,1HFMS',
            'HIS001050' => 'NHH,1HFD',
            'HIS056000' => 'NHTB,5PB-US-C',
            'HIS038000' => 'NHK,1K',
            'HIS002000' => 'NHC,1QBA',
            'HIS002030' => 'NHC,NHG,3C-AA-E',
            'HIS002010' => 'NHC,NHD,1QBAG',
            'HIS002020' => 'NHDA,1QBAR',
            'HIS003000' => 'NHF,1F',
            'HIS050000' => 'NHF,1FC',
            'HIS008000' => 'NHF,1FPC',
            'HIS021000' => 'NHF,1FPJ',
            'HIS023000' => 'NHF,1FPK',
            'HIS017000' => 'NHF,1FK',
            'HIS062000' => 'NHF,1FKA',
            'HIS048000' => 'NHF,1FM',
            'HIS004000' => 'NHM,1MB',
            'HIS059000' => 'NHQ,1QBCB',
            'HIS006000' => 'NHK,1KBC',
            'HIS006010' => 'NHK,1KBC',
            'HIS006020' => 'NHK,1KBC',
            'HIS006030' => 'NHK,1KBC',
            'HIS006040' => 'NHK,1QF-CA-A',
            'HIS006050' => 'NHK,1KBC-CA-B',
            'HIS006060' => 'NHK,1QF-CA-T',
            'HIS006070' => 'NHK,1KBC-CA-O',
            'HIS006080' => 'NHK,1QF-CA-P',
            'HIS006090' => 'NHK,1KBC-CA-Q',
            'HIS041000' => 'NHK,1KJ',
            'HIS041010' => 'NHK,1KJC',
            'HIS041020' => 'NHK,1KJD',
            'HIS041030' => 'NHK,1KJH',
            'HIS041040' => 'NHK,1KJWJ',
            'HIS039000' => 'NHTB',
            'HIS049000' => 'NHB',
            'HIS010000' => 'NHD,1D',
            'HIS037010' => 'NHDJ,1D',
            'HIS037020' => 'NHDL,1D',
            'HIS040000' => 'NHD,1QBDA',
            'HIS005000' => 'NHD,1DT',
            'HIS063000' => 'NHD,1DDB,1DDN,1DDL',
            'HIS010010' => 'NHD,1DT',
            'HIS013000' => 'NHD,1DDF',
            'HIS014000' => 'NHD,1DFG',
            'HIS015000' => 'NHD,1DDU',
            'HIS015010' => 'NHDJ,1DDU,3KH',
            'HIS015020' => 'NHDJ,1DDU,3KL',
            'HIS015030' => 'NHD,1DDU,3MD-GB-G',
            'HIS015040' => 'NHD,1DDU,3MG,3MGQS-GB-K',
            'HIS015050' => 'NHD,1DDU,3ML-GB-P',
            'HIS015060' => 'NHD,1DDU,3MNQ-GB-V',
            'HIS015070' => 'NHD,1DDU,3MP',
            'HIS015080' => 'NHD,1DDU,3MR',
            'HIS015090' => 'NHD,1DDU-GB-S',
            'HIS015100' => 'NHD,1DDU-GB-W',
            'HIS042000' => 'NHD,1DXG',
            'HIS018000' => 'NHD,1DDR',
            'HIS020000' => 'NHD,1DST',
            'HIS044000' => 'NHD,1DN',
            'HIS060000' => 'NHD,1DTP',
            'HIS064000' => 'NHD,1DSP',
            'HIS045000' => 'NHD,1DSE',
            'HIS010020' => 'NHD,1DD',
            'HIS051000' => 'NHB,RGR',
            'HIS052000' => 'NHTP',
            'HIS016000' => 'NHAH',
            'HIS028000' => 'NHK,NHTB,JBSL11,1K,5PBA',
            'HIS065000' => 'NHTB,5PGP',
            'HIS022000' => 'NHTB,5PGJ',
            'HIS024000' => 'NHK,1KL',
            'HIS061000' => 'NHKA,1KL',
            'HIS007000' => 'NHK,1KLC',
            'HIS025000' => 'NHK,1KLCM',
            'HIS033000' => 'NHK,1KLS',
            'HIS066000' => 'NHTB,JBSJ,5PS',
            'HIS057000' => 'NHTM',
            'HIS026000' => 'NHG,1FB',
            'HIS026010' => 'NHG,1FBX',
            'HIS009000' => 'NHG,1HBE',
            'HIS026020' => 'NHG,1FBN',
            'HIS026030' => 'NHG,1FBQ',
            'HIS019000' => 'NHG,1FBH',
            'HIS026040' => 'NHG,1FBS',
            'HIS055000' => 'NHG,1DTT,1QBCS',
            'HIS027000' => 'NHW',
            'HIS027220' => 'NHWA',
            'HIS027140' => 'JWCM,NHW',
            'HIS027010' => 'JWMC,NHW',
            'HIS027160' => 'NHW,1KBC',
            'HIS027250' => 'NHWR3',
            'HIS027260' => 'NHWF',
            'HIS027270' => 'NHW,AMKL',
            'HIS027280' => 'JWCG,NHW',
            'HIS027290' => 'JWKF,NHW',
            'HIS027300' => 'JWCD,NHW',
            'HIS027230' => 'NHWD',
            'HIS027150' => 'JWCK,NHW',
            'HIS027030' => 'JWMN,NHW',
            'HIS027050' => 'NHW,AJF',
            'HIS031000' => 'NHTV',
            'HIS027180' => 'JWCS,NHW',
            'HIS027060' => 'JWK,NHW',
            'HIS027310' => 'JWTU,NHW',
            'HIS027110' => 'NHW,1KBB',
            'HIS027240' => 'JWMV,NHW',
            'HIS027320' => 'JWMV,JWCM,NHW',
            'HIS027330' => 'JWMV,JWCD,NHW',
            'HIS027340' => 'JWMV,JWCK,NHW',
            'HIS027120' => 'JWXV,NHW',
            'HIS027080' => 'JWM,TTMW,NHW',
            'HIS037030' => 'NHB',
            'HIS037090' => 'NHB,3MD',
            'HIS037040' => 'NHB,3MG',
            'HIS037050' => 'NHB,3ML',
            'HIS037060' => 'NHB,3MN',
            'HIS037070' => 'NHB,3MP',
            'HIS037100' => 'NHTW,3MPQ',
            'HIS043000' => 'NHTZ1,3MPBLB',
            'HIS037080' => 'NHB,3MR',
            'HIS029000' => 'NHK,1KB',
            'HIS053000' => 'NHM,1MK',
            'HIS046000' => 'NHQ,1QMP',
            'HIS030000' => 'NHB,GBC',
            'HIS032000' => 'NHQ,1DTA,1QBDR',
            'HIS054000' => 'NHTB',
            'HIS035000' => 'NHB,YPJ',
            'HIS036000' => 'NHK,1KBB',
            'HIS036020' => 'NHK,1KBB,3MG-US-A',
            'HIS036030' => 'NHK,1KBB,3MLQ-US-B,3MLQ-US-C',
            'HIS036040' => 'NHK,1KBB,3MN',
            'HIS036050' => 'NHK,1KBB,3MNQ-US-E,3MNB-US-D',
            'HIS036060' => 'NHK,1KBB,3MP',
            'HIS036070' => 'NHK,1KBB,3MR',
            'HIS036010' => 'NHK,WQH,1KBB',
            'HIS036080' => 'NHK,WQH,1KBB-US-NA',
            'HIS036090' => 'NHK,WQH,1KBB-US-M',
            'HIS036100' => 'NHK,WQH,1KBB-US-NE',
            'HIS036110' => 'NHK,WQH,1KBB-US-WPN',
            'HIS036120' => 'NHK,WQH,1KBB-US-S',
            'HIS036130' => 'NHK,WQH,1KBB-US-W',
            'HIS036140' => 'NHK,WQH,1KBB-US-W',
            'HIS027130' => 'NHW',
            'HIS027350' => 'NHWR,NHWD,1FBG,3KL',
            'HIS027200' => 'NHWR,1D,3MNB',
            'HIS027210' => 'NHWR,1KBB,1DDU,3MNBF',
            'HIS027090' => 'NHWR5,3MPBFB',
            'HIS027100' => 'NHWR7,3MPBLB',
            'HIS027360' => 'NHWR7,3MPBLB,1DT',
            'HIS027370' => 'NHWR7,3MPBLB,1D',
            'HIS027380' => 'NHWR7,3MPBLB,1QRM,1HB',
            'HIS027390' => 'NHWR7,3MPBLB,1QSP',
            'HIS027020' => 'NHWR9,1FPK,3MPQM-US-N',
            'HIS027070' => 'NHWR9,1FMV,3MPQS-US-Q',
            'HIS027040' => 'NHWR9,1FBX,3MPQZ',
            'HIS027190' => 'NHWR9,1FCA,3MRB',
            'HIS027170' => 'NHWR9,1FBQ,3MRB',
            'HIS058000' => 'NHTB,JBSF1',
            'HIS037000' => 'NHB',
            'HOM000000' => 'WK',
            'HOM019000' => 'WKH',
            'HOM003000' => 'WJK',
            'HOM004000' => 'WK',
            'HOM005000' => 'WKD',
            'HOM001000' => 'WKDW',
            'HOM006000' => 'WKD,THRX',
            'HOM012000' => 'WKD,TNTB',
            'HOM014000' => 'WKD,TNTP',
            'HOM024000' => 'WK,AMCR',
            'HOM026000' => 'UDV',
            'HOM020000' => 'WKDM',
            'HOM009000' => 'WKD',
            'HOM011000' => 'WK',
            'HOM010000' => 'WKDM',
            'HOM025000' => 'WK,VS',
            'HOM013000' => 'WKU',
            'HOM015000' => 'WKD',
            'HOM016000' => 'WK,GBC',
            'HOM017000' => 'WKR',
            'HOM021000' => 'WK,TNKS,VFB',
            'HOM023000' => 'WK',
            'HOM027000' => 'UDY',
            'HOM022000' => 'WK,VSZ',
            'HUM000000' => 'WH',
            'HUM015000' => 'WHX',
            'HUM001000' => 'WHX,XY',
            'HUM003000' => 'WHX',
            'HUM004000' => 'WHJ',
            'HUM005000' => 'WH,DC',
            'HUM007000' => 'WHP',
            'HUM017000' => 'WH',
            'HUM018000' => 'WH',
            'HUM016000' => 'WDKX',
            'HUM008000' => 'WH,5X',
            'HUM009000' => 'WH,WN',
            'HUM010000' => 'WH,KJ',
            'HUM020000' => 'WH,JBCC1',
            'HUM021000' => 'WH,5P',
            'HUM022000' => 'WH,NH',
            'HUM023000' => 'WHG,ATN',
            'HUM019000' => 'WH,CBX',
            'HUM024000' => 'WH,5PS',
            'HUM011000' => 'WH,VFV',
            'HUM012000' => 'WH,VFVG',
            'HUM006000' => 'WH,JP',
            'HUM014000' => 'WH,QR',
            'HUM025000' => 'WH,JN',
            'HUM013000' => 'WH,SC',
            'HUM026000' => 'WH,WT',
            'JUV000000' => 'YFB',
            'JUV001000' => 'YFC',
            'JUV001020' => 'YFC,YNHA1',
            'JUV001010' => 'YFC',
            'JUV054000' => 'YBG',
            'JUV054010' => 'YBGC',
            'JUV054020' => 'YBGS',
            'JUV002000' => 'YFP',
            'JUV002010' => 'YFP,YNNM',
            'JUV002020' => 'YFP,YNNJ29',
            'JUV002370' => 'YFP,YBLL',
            'JUV002030' => 'YFP,YNNJ23',
            'JUV002040' => 'YFP,YNNK',
            'JUV002300' => 'YFP,YNNL',
            'JUV002050' => 'YFP,YNNH2,YNNJ22',
            'JUV002310' => 'YFP,YNNJ25',
            'JUV002290' => 'YFP,YNNJ2',
            'JUV002060' => 'YFP,YNNA',
            'JUV002070' => 'YFP,YNNH1,YNNJ21',
            'JUV002270' => 'YFH,YNXB',
            'JUV002280' => 'YFP,YNNK',
            'JUV002080' => 'YFP,YNNJ2',
            'JUV002090' => 'YFP,YNNF',
            'JUV002100' => 'YFP,YNNS',
            'JUV002110' => 'YFP,YNNJ21',
            'JUV002120' => 'YFP,YNNM',
            'JUV002320' => 'YFP,YNNJ2',
            'JUV002330' => 'YFP,YNNJ2',
            'JUV002130' => 'YFP,YNNJ24',
            'JUV002140' => 'YFP,YNNL',
            'JUV002340' => 'YFP,YNNB2',
            'JUV002350' => 'YFP,YNNJ9',
            'JUV002150' => 'YFP,YNNJ22',
            'JUV002160' => 'YFP,YNNJ',
            'JUV002170' => 'YFP,YNNS',
            'JUV002180' => 'YFP,YNNJ31',
            'JUV002360' => 'YFP',
            'JUV002380' => 'YFP,YNNK',
            'JUV002190' => 'YFP,YNNH',
            'JUV002200' => 'YFP,YNNJ26',
            'JUV002210' => 'YFP,YNNJ31',
            'JUV002220' => 'YFP,YNNM',
            'JUV002230' => 'YFP,YNNJ31',
            'JUV002240' => 'YFP,YNNM',
            'JUV002250' => 'YFP,YNNJ21',
            'JUV002390' => 'YFP,YNNL',
            'JUV002260' => 'YFP',
            'JUV073000' => 'YFB,YNTP',
            'JUV003000' => 'YFB,YNA',
            'JUV010000' => 'YBCS1',
            'JUV004000' => 'YFX',
            'JUV004050' => 'YFX,1H',
            'JUV004060' => 'YFX,1F',
            'JUV004040' => 'YFX,1KBC',
            'JUV004010' => 'YFX,1D',
            'JUV004070' => 'YFX,1KL',
            'JUV004020' => 'YFX,1KBB',
            'JUV047000' => 'YFB,YNL',
            'JUV005000' => 'YFB,YNMH',
            'JUV006000' => 'YFB,YNK',
            'JUV007000' => 'YFA',
            'JUV048000' => 'YFB,YNPJ',
            'JUV008000' => 'XQ,YFB',
            'JUV008040' => 'XQG,YFC',
            'JUV008050' => 'XQN,YFP',
            'JUV008060' => 'XQB,YFA',
            'JUV008070' => 'XQM,YFJ',
            'JUV008080' => 'XQM,YFH',
            'JUV008090' => 'XQV,YFT',
            'JUV008100' => 'XQH,YFD',
            'JUV008110' => 'XQT,YFQ',
            'JUV008010' => 'XAM,YFB',
            'JUV008030' => 'XQ,YFB',
            'JUV008120' => 'XQD,YFCF',
            'JUV008130' => 'XQM,YFH',
            'JUV008140' => 'XQL,YFG',
            'JUV008020' => 'XQK,YFG',
            'JUV049000' => 'YFB,YNTC',
            'JUV009000' => 'YFB,YBL',
            'JUV009010' => 'YFB,YBLA',
            'JUV009120' => 'YFB,YBLN1',
            'JUV009020' => 'YFB,YBLD',
            'JUV009030' => 'YFB,YBLC',
            'JUV009070' => 'YFB,YBLJ',
            'JUV009090' => 'YFB,YBL',
            'JUV009040' => 'YFB,YBLF',
            'JUV009100' => 'YFB,YBLJ',
            'JUV009050' => 'YFB,YBLN1',
            'JUV009060' => 'YFB,YBLH',
            'JUV009110' => 'YFB,YBLN1',
            'JUV009080' => 'YFB,YBLA',
            'JUV050000' => 'YFB,YNPC',
            'JUV039150' => 'YFB,YXK',
            'JUV074000' => 'YFB,YXP',
            'JUV059000' => 'YFE',
            'JUV012030' => 'YFJ',
            'JUV012040' => 'YFJ',
            'JUV012000' => 'YFJ,YDC',
            'JUV012020' => 'YFJ',
            'JUV013000' => 'YFN,YXF',
            'JUV013010' => 'YFN,YXFF',
            'JUV013090' => 'YFN,YXF',
            'JUV013080' => 'YFN,YXF',
            'JUV013020' => 'YFN,YXF,YXFD',
            'JUV013030' => 'YFN,YXF',
            'JUV013040' => 'YFN,YXF',
            'JUV013050' => 'YFN,YXFF',
            'JUV013060' => 'YFN,YXF',
            'JUV013070' => 'YFN,YXFR',
            'JUV037000' => 'YFH',
            'JUV069000' => 'YFD',
            'JUV014000' => 'YFB,YNMF',
            'JUV015000' => 'YFB,YXA',
            'JUV015010' => 'YFB,YXA',
            'JUV015020' => 'YFB,YXL',
            'JUV015030' => 'YFB,YXL',
            'JUV039170' => 'YFB,YBLM',
            'JUV016000' => 'YFT',
            'JUV016010' => 'YFT,1H',
            'JUV016020' => 'YFT,1QBA',
            'JUV016030' => 'YFT,1F',
            'JUV016160' => 'YFT,1KBC',
            'JUV016170' => 'YFT,1KBC',
            'JUV016180' => 'YFT,1KBC',
            'JUV016040' => 'YFT,1D',
            'JUV016050' => 'YFT,YNHD',
            'JUV016060' => 'YFT,3MPBGJ-DE-H,5PGJ',
            'JUV016070' => 'YFT,3KH,3KL',
            'JUV016210' => 'YFT,1FB',
            'JUV016080' => 'YFT,YNJ',
            'JUV016090' => 'YFT,3B',
            'JUV016100' => 'YFT,3KLY',
            'JUV016110' => 'YFT,1KBB',
            'JUV016120' => 'YFT,1KBB,3MLQ-US-B,3MLQ-US-C',
            'JUV016140' => 'YFT,1KBB,3MN',
            'JUV016200' => 'YFT,1KBB,3MNQ-US-E,3MNB-US-D',
            'JUV016150' => 'YFT,1KBB,3MP',
            'JUV016190' => 'YFT,1KBB,3MR',
            'JUV017000' => 'YFB,YNMD,5HC',
            'JUV017100' => 'YFB,YNMD,5HKA',
            'JUV017010' => 'YFB,YNMD,5HPD',
            'JUV017020' => 'YFB,YNMD,5HPF',
            'JUV017140' => 'YFB,YNMD,5HCL',
            'JUV017030' => 'YFB,YNMD,5HCP',
            'JUV017110' => 'YFB,YNMD,5HPU',
            'JUV017050' => 'YFB,YNMD,5PB-US-C',
            'JUV017150' => 'YFB,YNMD,5HCJ',
            'JUV017120' => 'YFB,YNMD,5HPV',
            'JUV017130' => 'YFB,YNMD,YNMC,5HCF',
            'JUV017060' => 'YFB,YNMD,5HCS',
            'JUV017070' => 'YFB,YNMD,5HCE',
            'JUV017080' => 'YFB,YNMD,5HC',
            'JUV017090' => 'YFB,YNMD,5HP',
            'JUV018000' => 'YFD',
            'JUV019000' => 'YFQ',
            'JUV051000' => 'YBCS2',
            'JUV020000' => 'YFCA',
            'JUV021000' => 'YFCF,YNKC',
            'JUV022000' => 'YFJ',
            'JUV012050' => 'YFJ,1H',
            'JUV022010' => 'YFJ,1DDU-GB-E',
            'JUV012060' => 'YFJ,1F',
            'JUV012070' => 'YFJ,1KJ,1KL',
            'JUV022020' => 'YFJ,1QBAR,1QBAG',
            'JUV012080' => 'YFJ,1K,5PBA',
            'JUV022030' => 'YFJ,1DN',
            'JUV060000' => 'YFB,YXB,5PS',
            'JUV023000' => 'YFB,YNMK',
            'JUV024000' => 'YFB,YNML',
            'JUV025000' => 'YFB,YNML',
            'JUV026000' => 'YFMR',
            'JUV072000' => 'YFB,YNTM',
            'JUV027000' => 'YFB',
            'JUV066000' => 'YFH,YNXB6',
            'JUV052000' => 'YFH,YNXB',
            'JUV028000' => 'YFCF',
            'JUV077000' => 'YFB,YXP,5PM',
            'JUV055000' => 'YBLB',
            'JUV058000' => 'YFH,YNX',
            'JUV030000' => 'YFB,YNM',
            'JUV030010' => 'YFB,YNM,1H',
            'JUV030020' => 'YFB,YNM,1F',
            'JUV030080' => 'YFB,YNM,1M',
            'JUV030030' => 'YFB,YNM,1KBC',
            'JUV030090' => 'YFB,YNM,1KBC,5PBA',
            'JUV030040' => 'YFB,YNM,1KJ,1KL',
            'JUV030050' => 'YFB,YNM,1D',
            'JUV030100' => 'YFB,YNM,1KLCM',
            'JUV030110' => 'YFB,YNM,1FB',
            'JUV030120' => 'YFB,YNM,1QMP',
            'JUV030060' => 'YFB,YNM,1KBB',
            'JUV011010' => 'YFB,YNM,1KBB,5PB-US-C',
            'JUV011020' => 'YFB,YNM,1KBB,5PB-US-D',
            'JUV011030' => 'YFB,YNM,1KBB,5PB-US-H',
            'JUV030130' => 'YFB,YNM,1KBB',
            'JUV011040' => 'YFB,YNM,1KBB,5PB-US-E',
            'JUV031000' => 'YFB,YND',
            'JUV031010' => 'YFB,YND',
            'JUV031020' => 'YFB,YNDB',
            'JUV031030' => 'YFB,YNF',
            'JUV031040' => 'YFB,YNC',
            'JUV031050' => 'YFB,YNF',
            'JUV031060' => 'YFB,YND',
            'JUV070000' => 'YFB,YDP',
            'JUV061000' => 'YFB,YNKA',
            'JUV043000' => 'YFB,YPCA21',
            'JUV044000' => 'YFB,YPCA21',
            'JUV045000' => 'YFB,YBD',
            'JUV063000' => 'YFB,YXZG',
            'JUV033000' => 'YFK',
            'JUV033250' => 'YFK,5PGF',
            'JUV033010' => 'YFK,5PGM',
            'JUV033040' => 'YFK,YFC,5PGM',
            'JUV033050' => 'YFK,YFP,5PGM',
            'JUV033060' => 'YFK,YBCS1,5PGM',
            'JUV033070' => 'YFK,XQW,5PGM',
            'JUV033080' => 'YFK,YPCA21,5PGM',
            'JUV033090' => 'YFK,YXE,5PGM',
            'JUV033100' => 'YFK,YFN,5PGM',
            'JUV033110' => 'YFK,YFH,YFG,5PGM',
            'JUV033120' => 'YFK,YXHB,5PGM',
            'JUV033140' => 'YFK,YFT,5PGM',
            'JUV033150' => 'YFK,5PGM,5HC',
            'JUV033160' => 'YFK,YFQ,5PGM',
            'JUV033280' => 'YFK,5PGM',
            'JUV033170' => 'YFK,YBL,5PGM',
            'JUV033180' => 'YFK,YFCF,5PGM',
            'JUV033190' => 'YFK,YNM,5PGM',
            'JUV033200' => 'YFK,YFM,5PGM',
            'JUV033220' => 'YFK,YXZ,5PGM',
            'JUV033230' => 'YFK,YFR,5PGM',
            'JUV033240' => 'YFK,YX,5PGM',
            'JUV033260' => 'YFK,5PGD',
            'JUV033020' => 'YFK,5PGJ',
            'JUV033270' => 'YFK,5PGP',
            'JUV056000' => 'YFG',
            'JUV034000' => 'YFB,YNMW',
            'JUV035000' => 'YFS',
            'JUV029000' => 'YFB,YNT,YNN',
            'JUV029030' => 'YFP,YXZE',
            'JUV029010' => 'YFP,YXZG',
            'JUV029040' => 'YFP,YNNT',
            'JUV029050' => 'YFP,YNNT',
            'JUV029020' => 'YFP,YNNV2',
            'JUV053000' => 'YFG',
            'JUV053010' => 'YFG,YNXF',
            'JUV053020' => 'YFG,YNNZ',
            'JUV064000' => 'YFG,YNTT',
            'JUV038000' => 'YFU',
            'JUV039000' => 'YFB,YX',
            'JUV039290' => 'YFB,YXZB',
            'JUV039020' => 'YFB,YXW',
            'JUV039230' => 'YFB,YXQF',
            'JUV039190' => 'YFM,YXHL',
            'JUV039030' => 'YFB,YXG',
            'JUV039240' => 'YFB,YXLD',
            'JUV039040' => 'YFB,YXJ',
            'JUV039250' => 'YFB,YXZM',
            'JUV039050' => 'YFB,YXE',
            'JUV039060' => 'YFMF',
            'JUV039200' => 'YFB,YX',
            'JUV039090' => 'YFB,YXW',
            'JUV039100' => 'YFB,YXQ',
            'JUV039010' => 'YFB,YXQD',
            'JUV039070' => 'YFB,YXZH',
            'JUV039120' => 'YFB,YXPB,YXN',
            'JUV039280' => 'YFB,YXZR',
            'JUV039130' => 'YFB,YXS',
            'JUV039140' => 'YFB,YXD',
            'JUV039210' => 'YFB,YXQD',
            'JUV039270' => 'YFB,YXR',
            'JUV039220' => 'YFB,YX',
            'JUV039180' => 'YFB,YXQ',
            'JUV076000' => 'YFB,YNHA',
            'JUV032000' => 'YFR',
            'JUV032010' => 'YFR,YNWD3',
            'JUV032020' => 'YFR,YNWD4',
            'JUV032170' => 'YFR,YNW',
            'JUV032220' => 'YFR,YNW',
            'JUV032180' => 'YFR,YNWY',
            'JUV032090' => 'YFR,YNNJ24',
            'JUV032100' => 'YFR',
            'JUV032030' => 'YFR,YNWD2',
            'JUV032040' => 'YFR,YNVM',
            'JUV032190' => 'YFR,YNWD',
            'JUV032200' => 'YFR,YNWG',
            'JUV032110' => 'YFR,YNWM2',
            'JUV032120' => 'YFR,YNWM',
            'JUV032070' => 'YFR,YNWJ',
            'JUV032230' => 'YFR,YNW',
            'JUV032240' => 'YFR,YNW',
            'JUV032140' => 'YFR,YNWY',
            'JUV032150' => 'YFR,YNWD1',
            'JUV032210' => 'YFR,YNWG',
            'JUV032060' => 'YFR,YNWW',
            'JUV032080' => 'YFR,YNWM',
            'JUV032160' => 'YFR',
            'JUV062000' => 'YFGS',
            'JUV057000' => 'YFV',
            'JUV071000' => 'YFF',
            'JUV036000' => 'YFB,YNT',
            'JUV036010' => 'YFB,YNNZ',
            'JUV036020' => 'YFB,YNTD',
            'JUV067000' => 'YFCB',
            'JUV040000' => 'YFB,YNVD',
            'JUV041000' => 'YFB,YNTR',
            'JUV041010' => 'YFB,YNTR',
            'JUV041020' => 'YFB,YNTR',
            'JUV041030' => 'YFB,YNTR',
            'JUV041050' => 'YFB,YNTR',
            'JUV068000' => 'YFB,YNM',
            'JUV078000' => 'YFD,YNXB2',
            'JUV046000' => 'YFH',
            'JUV075000' => 'YFCW',
            'JUV079000' => 'YFD,YNXB2',
            'JUV042000' => 'YFC,1KBB-US-W',
            'JUV080000' => 'YFD,YNXB3',
            'JNF000000' => 'YN',
            'JNF071000' => 'YXZB',
            'JNF001000' => 'YBG',
            'JNF001010' => 'YBGC',
            'JNF001020' => 'YBGS',
            'JNF002000' => 'YNHA',
            'JNF003000' => 'YNN',
            'JNF003220' => 'YNN',
            'JNF003010' => 'YNNJ29',
            'JNF003330' => 'YNN,YBLL',
            'JNF003020' => 'YNNJ23',
            'JNF003030' => 'YNNK',
            'JNF003250' => 'YNNL',
            'JNF003040' => 'YNNH2,YNNJ22',
            'JNF003260' => 'YNNJ25',
            'JNF003230' => 'YNNJ2',
            'JNF003050' => 'YNNA',
            'JNF003060' => 'YNNH1,YNNJ21',
            'JNF003210' => 'YNNK',
            'JNF003070' => 'YNNJ2',
            'JNF003270' => 'YNN,YXZG',
            'JNF003080' => 'YNNF',
            'JNF003090' => 'YNNS',
            'JNF003100' => 'YNNJ21',
            'JNF003340' => 'YNNM',
            'JNF003280' => 'YNNJ2',
            'JNF003290' => 'YNNJ2',
            'JNF003110' => 'YNNJ24',
            'JNF003120' => 'YNNL',
            'JNF003300' => 'YNNB2',
            'JNF003310' => 'YNNJ9',
            'JNF003130' => 'YNNJ22',
            'JNF003140' => 'YNNJ',
            'JNF003150' => 'YNNS',
            'JNF003160' => 'YNNJ31',
            'JNF003320' => 'YNNB',
            'JNF003350' => 'YNNK',
            'JNF003170' => 'YNNH',
            'JNF003180' => 'YNNJ31',
            'JNF003190' => 'YNNM',
            'JNF003360' => 'YNNM',
            'JNF003240' => 'YNNJ21',
            'JNF003370' => 'YNNL',
            'JNF003200' => 'YNN',
            'JNF004000' => 'YNV',
            'JNF005000' => 'YNTP',
            'JNF006000' => 'YNA',
            'JNF006010' => 'YNA,YNUC',
            'JNF006020' => 'YNA',
            'JNF006030' => 'YNPJ',
            'JNF006040' => 'YNA',
            'JNF006050' => 'YNA',
            'JNF006060' => 'YNA',
            'JNF006070' => 'YNA',
            'JNF067000' => 'YBLM',
            'JNF007000' => 'YNB',
            'JNF007010' => 'YNB,YNA',
            'JNF007050' => 'YNB,5PB',
            'JNF007020' => 'YNB,YNH',
            'JNF007150' => 'YNB,YXB,5PS',
            'JNF007030' => 'YNB,YNL',
            'JNF007040' => 'YNB,YNC',
            'JNF007060' => 'YNB,YND',
            'JNF007070' => 'YNB,YNKA',
            'JNF007130' => 'YNB,YNKA,1KBB',
            'JNF007080' => 'YNB,YNR',
            'JNF007140' => 'YNB,YNMW',
            'JNF007090' => 'YNB,YNT',
            'JNF007110' => 'YNB,YXZ',
            'JNF007100' => 'YNB,YNW',
            'JNF007120' => 'YNB,YNMF',
            'JNF063000' => 'YNL,YNGL',
            'JNF009000' => 'YNMH',
            'JNF010000' => 'YPJV',
            'JNF011000' => 'YNK',
            'JNF059000' => 'YNPJ',
            'JNF062000' => 'XQA,YN',
            'JNF062010' => 'XQA,YNB',
            'JNF062020' => 'XQA,YNH',
            'JNF062030' => 'XQA,YNT,YNN',
            'JNF062040' => 'XQA,YX',
            'JNF012000' => 'YNTC',
            'JNF012040' => 'YNTC1',
            'JNF012010' => 'YNVU',
            'JNF012030' => 'YNTC,YNTC2',
            'JNF012050' => 'YNTC',
            'JNF013000' => 'YBL',
            'JNF013010' => 'YBLA',
            'JNF013110' => 'YBLN1',
            'JNF013020' => 'YBLD',
            'JNF013030' => 'YBLC',
            'JNF013080' => 'YBLJ',
            'JNF013040' => 'YBLC',
            'JNF013050' => 'YBLF',
            'JNF013090' => 'YBLJ',
            'JNF013060' => 'YBLN1',
            'JNF013070' => 'YBLH',
            'JNF013100' => 'YBLN1',
            'JNF013120' => 'YBLA',
            'JNF014000' => 'YNPC',
            'JNF015000' => 'YNPH',
            'JNF016000' => 'YNG,YNX',
            'JNF053180' => 'YXK',
            'JNF069000' => 'YXP',
            'JNF017000' => 'YND,YNDS',
            'JNF019000' => 'YXF',
            'JNF019010' => 'YXFF',
            'JNF019090' => 'YXF',
            'JNF019080' => 'YXF',
            'JNF019020' => 'YXF,YXFD',
            'JNF019030' => 'YXF',
            'JNF019040' => 'YXFS',
            'JNF019050' => 'YXFF',
            'JNF019060' => 'YXF',
            'JNF019070' => 'YXFR',
            'JNF020000' => 'YRDM',
            'JNF020010' => 'YRDM,2ACB',
            'JNF020020' => 'YRDM,2ADF',
            'JNF020030' => 'YRDM,2ADS',
            'JNF021000' => 'YNV',
            'JNF021010' => 'YNVM',
            'JNF021020' => 'YNVM',
            'JNF021030' => 'YNV',
            'JNF021040' => 'YNVP',
            'JNF021050' => 'YNG',
            'JNF021060' => 'YNVU',
            'JNF021070' => 'YNVP',
            'JNF022000' => 'YNPG',
            'JNF023000' => 'YNMF',
            'JNF024000' => 'YXA',
            'JNF024120' => 'YXA,YBLM',
            'JNF024010' => 'YXAB',
            'JNF024020' => 'YXL',
            'JNF024100' => 'YXJ',
            'JNF024030' => 'YXA',
            'JNF024040' => 'YXAB,YNW',
            'JNF024050' => 'YXA,YXW',
            'JNF024140' => 'YXLD',
            'JNF024130' => 'YXLD6',
            'JNF024060' => 'YXA',
            'JNF024070' => 'YXK',
            'JNF024080' => 'YXR',
            'JNF024090' => 'YXAX',
            'JNF024110' => 'YBLM',
            'JNF025000' => 'YNH',
            'JNF025010' => 'YNH,1H',
            'JNF025020' => 'YNH,1QBA',
            'JNF025030' => 'YNH,1F',
            'JNF025040' => 'YNH,1M',
            'JNF025050' => 'YNH,1KBC',
            'JNF025230' => 'YNH,1KBC',
            'JNF025240' => 'YNH,1KBC',
            'JNF025060' => 'YNH,1KL',
            'JNF025070' => 'YNH,1D',
            'JNF025080' => 'YNHD',
            'JNF025090' => 'YNH,3MPBGJ-DE-H,5PGJ',
            'JNF025100' => 'YNH,3KH,3KL',
            'JNF025110' => 'YNH,1KLCM',
            'JNF025120' => 'YNH,1FB',
            'JNF025130' => 'YNJ',
            'JNF025140' => 'YNH',
            'JNF025150' => 'YNH,3B',
            'JNF025160' => 'YNH,3KLY',
            'JNF025260' => 'YNMC',
            'JNF025170' => 'YNH,1KBB',
            'JNF025180' => 'YNH,1KBB',
            'JNF025190' => 'YNH,1KBB,3MLQ-US-B,3MLQ-US-C',
            'JNF025200' => 'YNH,1KBB,3MN',
            'JNF025270' => 'YNH,1KBB,3MNQ-US-E,3MNB-US-D',
            'JNF025210' => 'YNH,1KBB,3MP',
            'JNF025250' => 'YNH,1KBB,3MR',
            'JNF026000' => 'YNMD',
            'JNF026100' => 'YNMD,5HKA',
            'JNF026010' => 'YNMD,YNRM,5HPD',
            'JNF026020' => 'YNMD,YNRM,5HPF',
            'JNF026030' => 'YNMD,5HCP',
            'JNF026110' => 'YNMD,YNRJ,5HPU',
            'JNF026050' => 'YNMD,5PB-US-C',
            'JNF026120' => 'YNMD,YNRJ,5HPV',
            'JNF026130' => 'YNMD,YNMC,5HCF',
            'JNF026060' => 'YNMD,5HCS',
            'JNF026070' => 'YNMD,5HCE',
            'JNF026080' => 'YNMD,5HC',
            'JNF026090' => 'YNMD,5HP',
            'JNF027000' => 'YNP',
            'JNF028000' => 'YNU',
            'JNF028010' => 'YNUC',
            'JNF028020' => 'YNU',
            'JNF070000' => 'YXD',
            'JNF029000' => 'YPC',
            'JNF029010' => 'YPCA2',
            'JNF029020' => 'YPCA4',
            'JNF029030' => 'YPCA22',
            'JNF029060' => 'YPCA2',
            'JNF029050' => 'YPC,2S',
            'JNF029040' => 'YPCA23',
            'JNF030000' => 'YNKC',
            'JNF053080' => 'YXB,5PS',
            'JNF031000' => 'YNMK',
            'JNF032000' => 'YNML',
            'JNF033000' => 'YNML',
            'JNF034000' => 'YNL',
            'JNF035000' => 'YNTM,YPMF',
            'JNF035020' => 'YNTM,YPMF',
            'JNF035030' => 'YNTM,YPMF',
            'JNF035040' => 'YNTM,YPMF',
            'JNF035050' => 'YNTM,YPMF',
            'JNF060000' => 'YPJK',
            'JNF064000' => 'YN',
            'JNF036000' => 'YNC',
            'JNF036010' => 'YNC,6CA',
            'JNF036020' => 'YNC,YPAD',
            'JNF036030' => 'YNC,YPAD',
            'JNF036090' => 'YNC',
            'JNF036040' => 'YNC,6JD',
            'JNF036050' => 'YNC,6PB',
            'JNF036060' => 'YNC,6RJ',
            'JNF036070' => 'YNC,6RF',
            'JNF036080' => 'YNCS',
            'JNF072000' => 'YXP,5PM',
            'JNF008000' => 'YNX',
            'JNF038000' => 'YNM',
            'JNF038010' => 'YNM,1H',
            'JNF038020' => 'YNM,1F',
            'JNF038030' => 'YNM,1M',
            'JNF038040' => 'YNM,1KBC',
            'JNF038120' => 'YNM,1KBC,5PBA',
            'JNF038050' => 'YNM,1KJ,1KL',
            'JNF038060' => 'YNM,1D',
            'JNF038070' => 'YNM,1KLCM',
            'JNF038080' => 'YNM,1FB',
            'JNF038090' => 'YNM,1QMP',
            'JNF038100' => 'YNM,1KBB',
            'JNF018010' => 'YNM,1KBB,5PB-US-C',
            'JNF018020' => 'YNM,1KBB,5PB-US-D',
            'JNF018030' => 'YNM,1KBB,5PB-US-H',
            'JNF038130' => 'YNM,1KBB',
            'JNF018040' => 'YNM,1KBB,5PB-US-E',
            'JNF039000' => 'YND',
            'JNF039010' => 'YND',
            'JNF039020' => 'YNDB',
            'JNF039030' => 'YNF',
            'JNF039040' => 'YNF',
            'JNF039050' => 'YND',
            'JNF040000' => 'YNRA',
            'JNF041000' => 'YNA',
            'JNF066000' => 'YNHA1',
            'JNF042000' => 'YDP',
            'JNF042010' => 'YDP,YNU',
            'JNF045000' => 'YPCA21',
            'JNF046000' => 'YPCA21',
            'JNF047000' => 'YBD',
            'JNF065000' => 'YXZG',
            'JNF048000' => 'YR',
            'JNF048010' => 'YRE',
            'JNF048020' => 'YRW',
            'JNF048030' => 'YRD',
            'JNF048040' => 'YRE',
            'JNF048050' => 'YRD',
            'JNF049000' => 'YNR,YPJN',
            'JNF049040' => 'YNRX',
            'JNF049140' => 'YNRX',
            'JNF049150' => 'YNRX',
            'JNF049020' => 'YNRX,YNRM',
            'JNF049170' => 'YNRX',
            'JNF049010' => 'YNRX',
            'JNF049320' => 'YNRF',
            'JNF049080' => 'YNRM',
            'JNF049090' => 'YNRR,1FP',
            'JNF049330' => 'YNRD',
            'JNF049100' => 'YNRP',
            'JNF049110' => 'YNRJ',
            'JNF049130' => 'YNRM',
            'JNF049180' => 'YNRM',
            'JNF049190' => 'YNRM,YNUC',
            'JNF049120' => 'YNRM,YNRX',
            'JNF049200' => 'YNRM,YPCA21',
            'JNF049210' => 'YNRM,YXF',
            'JNF049220' => 'YNRM,YNV',
            'JNF049240' => 'YNRM,5HP',
            'JNF049250' => 'YNRM',
            'JNF049260' => 'YNRM,YBL',
            'JNF049280' => 'YNRM,YNN',
            'JNF049290' => 'YNRM,YXZ',
            'JNF049310' => 'YNRM,YX',
            'JNF050000' => 'YNGL',
            'JNF051000' => 'YNT',
            'JNF051030' => 'YNTA,YPMP1',
            'JNF051040' => 'YNNZ,YPMP51',
            'JNF051050' => 'YNT,YPMP1',
            'JNF051070' => 'YNT,YPMP3',
            'JNF051160' => 'YXZE',
            'JNF051170' => 'YNT',
            'JNF051080' => 'YNNV',
            'JNF037010' => 'YNNV',
            'JNF051180' => 'YNNV,YPJT',
            'JNF037060' => 'YNNV',
            'JNF037070' => 'YNNB1',
            'JNF037080' => 'YNNV',
            'JNF037020' => 'YXZG,YPMP6',
            'JNF051100' => 'YNNC',
            'JNF051110' => 'YNTD',
            'JNF037030' => 'YNNT',
            'JNF037050' => 'YNNA',
            'JNF051190' => 'YNT',
            'JNF051140' => 'YNT,YPMP5',
            'JNF037040' => 'YNNT',
            'JNF051200' => 'YNT',
            'JNF051150' => 'YNN',
            'JNF052000' => 'YPJJ',
            'JNF052010' => 'YPJJ',
            'JNF052020' => 'YNMC',
            'JNF052030' => 'YNRU',
            'JNF043000' => 'YNKA',
            'JNF044000' => 'YPJJ5',
            'JNF052040' => 'YPJJ',
            'JNF053000' => 'YX',
            'JNF053010' => 'YX',
            'JNF053220' => 'YXQF',
            'JNF053270' => 'YXZ,YNKA',
            'JNF053020' => 'YXHL',
            'JNF053030' => 'YXG',
            'JNF053230' => 'YXLD,YXLD2',
            'JNF053040' => 'YXJ',
            'JNF053240' => 'YXZM',
            'JNF053050' => 'YXE',
            'JNF053060' => 'YXHB',
            'JNF053090' => 'YX',
            'JNF053100' => 'YXW',
            'JNF053110' => 'YXQ',
            'JNF053120' => 'YXQD',
            'JNF053070' => 'YXZH',
            'JNF053140' => 'YXPB,YXN',
            'JNF053160' => 'YXD',
            'JNF053170' => 'YXQD',
            'JNF053200' => 'YX',
            'JNF053210' => 'YXQ',
            'JNF068000' => 'YNHA',
            'JNF054000' => 'YNW',
            'JNF054010' => 'YNWD3',
            'JNF054020' => 'YNWD4',
            'JNF054030' => 'YNW',
            'JNF054240' => 'YNW',
            'JNF054040' => 'YNWY',
            'JNF054170' => 'YNW,YNNJ24',
            'JNF054180' => 'YNW',
            'JNF054050' => 'YNWD2',
            'JNF054230' => 'YNWD',
            'JNF054060' => 'YNWG',
            'JNF054070' => 'YNWM2',
            'JNF054190' => 'YNWM',
            'JNF054080' => 'YNW',
            'JNF054100' => 'YNW',
            'JNF054110' => 'YNW',
            'JNF054120' => 'YNWD',
            'JNF054200' => 'YNWY',
            'JNF054210' => 'YNWY',
            'JNF054130' => 'YNWD1',
            'JNF054140' => 'YNWG',
            'JNF054150' => 'YNWW',
            'JNF054160' => 'YNWM',
            'JNF054220' => 'YNW',
            'JNF055000' => 'YPWL',
            'JNF055010' => 'YNL,YPZ,4TM',
            'JNF055030' => 'YPZ,4TN',
            'JNF061000' => 'YPMT',
            'JNF051010' => 'YPMT,YNNZ',
            'JNF051020' => 'YPWE',
            'JNF051090' => 'YPMT5',
            'JNF051120' => 'YNTG',
            'JNF061010' => 'YNTD',
            'JNF051130' => 'YNTG',
            'JNF061020' => 'YNTC',
            'JNF056000' => 'YNVD',
            'JNF057000' => 'YNTR',
            'JNF057010' => 'YNTR',
            'JNF057020' => 'YNTR',
            'JNF057030' => 'YNTR',
            'JNF057040' => 'YNTR',
            'JNF057050' => 'YNTR',
            'JNF058000' => 'YNM',
            'JNF073000' => 'YXZB',
            'LAN000000' => 'CB',
            'LAN001000' => 'CFLA',
            'LAN004000' => 'GTC',
            'LAN022000' => 'CBW',
            'LAN006000' => 'CJBG',
            'LAN007000' => 'CJCW',
            'LAN008000' => 'KNTP2,JBCT4',
            'LAN029000' => 'CFM',
            'LAN025000' => 'GLM',
            'LAN025010' => 'GLC',
            'LAN025020' => 'GLC',
            'LAN025030' => 'GLK',
            'LAN025040' => 'GLH',
            'LAN025060' => 'GLF',
            'LAN025050' => 'GLM,JNU',
            'LAN009000' => 'CF',
            'LAN024000' => 'CFF',
            'LAN009010' => 'CFF',
            'LAN009020' => 'CFK',
            'LAN011000' => 'CFH',
            'LAN009030' => 'CFG',
            'LAN009040' => 'CFD',
            'LAN016000' => 'CFG',
            'LAN009050' => 'CFB',
            'LAN009060' => 'CFK',
            'LAN010000' => 'CFC',
            'LAN026000' => 'CBP',
            'LAN027000' => 'KNTP1',
            'LAN012000' => 'CJBR',
            'LAN013000' => 'CJCR',
            'LAN015000' => 'CFG',
            'LAN017000' => 'CFZ,2S',
            'LAN018000' => 'CJCK,CJBG',
            'LAN021000' => 'CJBG',
            'LAN020000' => 'CJ',
            'LAN028000' => 'CBW',
            'LAN023000' => 'CFP',
            'LAN005000' => 'CBV,CJCW',
            'LAN005010' => 'CBW',
            'LAN002000' => 'CBW',
            'LAN005020' => 'KNTP1',
            'LAN005030' => 'CBV',
            'LAN005040' => 'CBW',
            'LAN005050' => 'CBV',
            'LAN005060' => 'CBW',
            'LAN005070' => 'CBV',
            'LAW000000' => 'LA',
            'LAW001000' => 'LNDB',
            'LAW102000' => 'LNKF',
            'LAW002000' => 'LBDA',
            'LAW003000' => 'LNAC5',
            'LAW004000' => 'LNZC',
            'LAW005000' => 'LNCH',
            'LAW006000' => 'LNAC5',
            'LAW007000' => 'LNPB',
            'LAW008000' => 'LNPC',
            'LAW009000' => 'LNC,LNP',
            'LAW010000' => 'LNMK,LASD',
            'LAW011000' => 'LAFD',
            'LAW012000' => 'LNAC',
            'LAW013000' => 'LNDC',
            'LAW014000' => 'LNCB',
            'LAW014010' => 'LBBM',
            'LAW103000' => 'LAFC',
            'LAW015000' => 'LNQ',
            'LAW016000' => 'LAM',
            'LAW104000' => 'LNQ',
            'LAW017000' => 'LBG',
            'LAW018000' => 'LNDX',
            'LAW019000' => 'LNCQ',
            'LAW020000' => 'LNTU',
            'LAW021000' => 'LNCJ',
            'LAW022000' => 'LNCD',
            'LAW023000' => 'LNAA,LNZC',
            'LAW024000' => 'LNAA',
            'LAW025000' => 'LNAA',
            'LAW026000' => 'LNF',
            'LAW026010' => 'LNFQ',
            'LAW026020' => 'LNFX1',
            'LAW027000' => 'LNFX',
            'LAW028000' => 'LAFF',
            'LAW106000' => 'LNJD',
            'LAW029000' => 'LNAC3',
            'LAW030000' => 'LA,GBCD',
            'LAW031000' => 'LNTQ',
            'LAW094000' => 'LNHD',
            'LAW118000' => 'LN,JKVG',
            'LAW092000' => 'LNTD',
            'LAW107000' => 'LNTS',
            'LAW108000' => 'LNDS',
            'LAW032000' => 'LNDA1',
            'LAW033000' => 'LNJ',
            'LAW034000' => 'LNKJ',
            'LAW101000' => 'LA',
            'LAW035000' => 'LNW,LNL',
            'LAW036000' => 'LATC',
            'LAW037000' => 'LNAC3',
            'LAW038000' => 'LNM',
            'LAW038010' => 'LNMK',
            'LAW038020' => 'LNMB',
            'LAW038030' => 'LNMB',
            'LAW041000' => 'LAR,JKVF1',
            'LAW043000' => 'LAQG',
            'LAW044000' => 'LAS',
            'LAW109000' => 'LNDH',
            'LAW039000' => 'LNDH',
            'LAW089000' => 'LNDU,LNDV',
            'LAW046000' => 'LNTJ',
            'LAW047000' => 'LNSH9,LNFG2',
            'LAW110000' => 'LA,5PBA',
            'LAW049000' => 'LNPN',
            'LAW050000' => 'LNR',
            'LAW050010' => 'LNRC',
            'LAW050020' => 'LNRD',
            'LAW050030' => 'LNRF',
            'LAW051000' => 'LB',
            'LAW119000' => 'LAFS,LW',
            'LAW111000' => 'LNAA1',
            'LAW052000' => 'LA',
            'LAW053000' => 'LNAA12,LNFY',
            'LAW054000' => 'LNH',
            'LAW055000' => 'LNSH,LNFG2',
            'LAW112000' => 'LNSH3',
            'LAW056000' => 'LAT,KJWB',
            'LAW059000' => 'LAT,JNM',
            'LAW060000' => 'LAZ',
            'LAW061000' => 'LAT',
            'LAW062000' => 'LAT',
            'LAW063000' => 'LASH',
            'LAW113000' => 'LNB,LNDJ,LNV',
            'LAW064000' => 'LNAC',
            'LAW100000' => 'LNW',
            'LAW095000' => 'LNAL,LATC',
            'LAW066000' => 'LBDM,LNCB5',
            'LAW096000' => 'LNJ',
            'LAW093000' => 'LNTM',
            'LAW067000' => 'LNTM1',
            'LAW114000' => 'LNCD1',
            'LAW068000' => 'LNDK',
            'LAW069000' => 'LAB',
            'LAW070000' => 'LNCR',
            'LAW071000' => 'LASP',
            'LAW115000' => 'LNPP',
            'LAW097000' => 'LNVJ',
            'LAW098000' => 'VSD',
            'LAW116000' => 'LNDC2',
            'LAW074000' => 'LNS',
            'LAW075000' => 'LNX,LNEF',
            'LAW076000' => 'LNDB,LNCJ',
            'LAW077000' => 'LNK,KNB',
            'LAW078000' => 'LNSH',
            'LAW079000' => 'LA,GBC',
            'LAW080000' => 'LNV',
            'LAW081000' => 'LASN',
            'LAW082000' => 'LNTM,JBFV4',
            'LAW099000' => 'LNDB8',
            'LAW083000' => 'LNPD',
            'LAW084000' => 'LNJS',
            'LAW086000' => 'LNU',
            'LAW087000' => 'LNV',
            'LAW117000' => 'LNKT',
            'LAW088000' => 'LAS,LNFY',
            'LAW090000' => 'LNW',
            'LAW091000' => 'LNAC3',
            'LCO000000' => 'DNT',
            'LCO001000' => 'DNT,1H',
            'LCO002000' => 'DNT,1KBB',
            'LCO002010' => 'DNT,1KBB,5PB-US-C',
            'LCO003000' => 'DB',
            'LCO004000' => 'DNT,1F',
            'LCO004010' => 'DNT,1FPC',
            'LCO004020' => 'DNT,1FKA',
            'LCO004030' => 'DNT,1FPJ',
            'LCO005000' => 'DNT,1M',
            'LCO006000' => 'DNT,1KBC',
            'LCO007000' => 'DNT,1KJ,1KL',
            'LCO015000' => 'DND',
            'LCO008000' => 'DNT,1D',
            'LCO008010' => 'DNT,1DT',
            'LCO009000' => 'DNT,1DDU',
            'LCO008020' => 'DNT,1DDF',
            'LCO008030' => 'DNT,1DFG',
            'LCO008040' => 'DNT,1DST',
            'LCO008050' => 'DNT,1DN',
            'LCO008060' => 'DNT,1DSE,1DSP',
            'LCO010000' => 'DNL',
            'LCO013000' => 'DNT,1K,5PBA',
            'LCO020000' => 'DNPB',
            'LCO011000' => 'DND',
            'LCO016000' => 'DNT,5PS',
            'LCO017000' => 'DB,6MB',
            'LCO012000' => 'DNT,1FB',
            'LCO014000' => 'DNT,1DTA,1QBDR',
            'LCO018000' => 'DNS',
            'LCO019000' => 'DNT',
            'LIT000000' => 'DS',
            'LIT004010' => 'DS,1H',
            'LIT004020' => 'DS,1KBB',
            'LIT004040' => 'DS,1KBB,5PB-US-C',
            'LIT004030' => 'DS,1KBB,5PB-US-D',
            'LIT004050' => 'DS,1KBB,5PB-US-H',
            'LIT023000' => 'DS,1KBB',
            'LIT004190' => 'DSBB',
            'LIT008000' => 'DS,1F',
            'LIT008010' => 'DS,1FPC',
            'LIT008020' => 'DS,1FKA',
            'LIT008030' => 'DS,1FPJ',
            'LIT004070' => 'DS,1M',
            'LIT007000' => 'DS',
            'LIT004080' => 'DS,1KBC',
            'LIT004100' => 'DS,1KJ,1KL',
            'LIT009000' => 'DSY',
            'LIT017000' => 'DSK,XR',
            'LIT020000' => 'DSM',
            'LIT013000' => 'DSG',
            'LIT004130' => 'DS,1D',
            'LIT004110' => 'DS,1DT',
            'LIT004120' => 'DS,1DDU,1DDR',
            'LIT004150' => 'DS,1DDF',
            'LIT004170' => 'DS,1DFG',
            'LIT004200' => 'DS,1DST',
            'LIT004250' => 'DS,1DN',
            'LIT004280' => 'DS,1DSE,1DSP',
            'LIT022000' => 'DS,JBGB',
            'LIT003000' => 'DS,JBSF11',
            'LIT004180' => 'DSK,6GA,6RA',
            'LIT021000' => 'DSK,FK',
            'LIT016000' => 'DSK,WH',
            'LIT004060' => 'DS,1K,5PBA',
            'LIT004210' => 'DS,JBSR,5PGJ',
            'LIT004160' => 'DS,5PS',
            'LIT011000' => 'DSBB,6MB',
            'LIT004220' => 'DS,1FB',
            'LIT024000' => 'DSB',
            'LIT024010' => 'DSBC,3MD',
            'LIT024020' => 'DSBD,3MG',
            'LIT024030' => 'DSBD,3ML',
            'LIT024040' => 'DSBF',
            'LIT024050' => 'DSBH',
            'LIT024060' => 'DSBJ',
            'LIT004230' => 'DSK,FF',
            'LIT014000' => 'DSC',
            'LIT012000' => 'DSR,6RC',
            'LIT019000' => 'DSBC',
            'LIT004240' => 'DS,1DTA,1QBDR',
            'LIT004260' => 'DSK,FL,FM',
            'LIT006000' => 'DSA,GTD',
            'LIT015000' => 'DSG,5PX-GB-S',
            'LIT018000' => 'DSK,FYB',
            'LIT025000' => 'DS',
            'LIT025010' => 'DS,NH',
            'LIT025020' => 'DS,WN',
            'LIT025030' => 'DS,JP',
            'LIT025040' => 'DS,QR',
            'LIT025050' => 'DS,JBSF1',
            'LIT004290' => 'DS,JBSF1',
            'MAT000000' => 'PB',
            'MAT002000' => 'PBF',
            'MAT002010' => 'PBF',
            'MAT002030' => 'PBF',
            'MAT002040' => 'PBF',
            'MAT002050' => 'PBF',
            'MAT003000' => 'PBW',
            'MAT004000' => 'PBC',
            'MAT005000' => 'PBKA',
            'MAT036000' => 'PBV',
            'MAT040000' => 'PBKD',
            'MAT006000' => 'PBC',
            'MAT007000' => 'PBKJ',
            'MAT007010' => 'PBKJ',
            'MAT007020' => 'PBKJ',
            'MAT008000' => 'PBD',
            'MAT039000' => 'PB',
            'MAT009000' => 'PBD',
            'MAT037000' => 'PBKF',
            'MAT011000' => 'PBUD',
            'MAT012000' => 'PBM',
            'MAT012010' => 'PBMW',
            'MAT012020' => 'PBMS',
            'MAT012030' => 'PBMP',
            'MAT012040' => 'PBML',
            'MAT013000' => 'PBV',
            'MAT014000' => 'PBG',
            'MAT015000' => 'PBX,PBB',
            'MAT016000' => 'PB',
            'MAT017000' => 'PBUH',
            'MAT018000' => 'PBCD',
            'MAT034000' => 'PBK',
            'MAT019000' => 'PBF',
            'MAT020000' => 'PDD',
            'MAT021000' => 'PBCN',
            'MAT022000' => 'PBH',
            'MAT041000' => 'PBKS',
            'MAT042000' => 'PBU',
            'MAT023000' => 'PBJ',
            'MAT029000' => 'PBT',
            'MAT029010' => 'PBTB',
            'MAT029020' => 'PBT',
            'MAT029030' => 'PBT',
            'MAT029040' => 'PBWL',
            'MAT029050' => 'PBT',
            'MAT025000' => 'PDZM,WDKN',
            'MAT026000' => 'PB,GBC',
            'MAT027000' => 'PB',
            'MAT028000' => 'PBCH',
            'MAT030000' => 'PB,JNU',
            'MAT038000' => 'PBP',
            'MAT031000' => 'PBKF',
            'MAT032000' => 'PBMB',
            'MAT033000' => 'PBK',
            'MED000000' => 'MB',
            'MED001000' => 'MX,VXHA',
            'MED002000' => 'MBPM',
            'MED022020' => 'MJCJ2',
            'MED003000' => 'MQ',
            'MED003010' => 'MQF',
            'MED003020' => 'MX,VXHP',
            'MED003070' => 'MKS,MQH',
            'MED003090' => 'MX,VFMS',
            'MED003030' => 'MQG',
            'MED003100' => 'MBPM',
            'MED003040' => 'MBG',
            'MED003050' => 'MQT',
            'MED003060' => 'MQS,MQV',
            'MED003080' => 'MJL',
            'MED004000' => 'MX',
            'MED005000' => 'MFC',
            'MED006000' => 'MKA',
            'MED101000' => 'MRT',
            'MED007000' => 'MJPD,MKZL',
            'MED111000' => 'MKZF',
            'MED008000' => 'MF,PSB',
            'MED090000' => 'MBGR,MBNS',
            'MED009000' => 'MF,TCB',
            'MED010000' => 'MJD',
            'MED011000' => 'MQ,VFG',
            'MED012000' => 'MJCL2',
            'MED013000' => 'MXH',
            'MED014000' => 'MJ',
            'MED015000' => 'MKPL',
            'MED016000' => 'MKE',
            'MED016010' => 'MKE,MQG',
            'MED016020' => 'MKE',
            'MED016080' => 'MKEP',
            'MED016060' => 'MKEH',
            'MED016050' => 'MKEP',
            'MED016030' => 'MKED',
            'MED016040' => 'MKEH',
            'MED016090' => 'MKE,MBPM',
            'MED016070' => 'MKEH',
            'MED017000' => 'MJK',
            'MED018000' => 'MJA',
            'MED019000' => 'MKS',
            'MED019010' => 'MQH',
            'MED098000' => 'MKSF',
            'MED020000' => 'MR,GBCD',
            'MED021000' => 'MBNH3',
            'MED022000' => 'MJC',
            'MED023000' => 'MKG',
            'MED024000' => 'MR',
            'MED025000' => 'MFKC3',
            'MED026000' => 'MKP',
            'MED027000' => 'MJG,MFGM',
            'MED116000' => 'MKV',
            'MED028000' => 'MBNS',
            'MED109000' => 'MB',
            'MED050000' => 'MBDC',
            'MED112000' => 'MBGR',
            'MED029000' => 'MBPC',
            'MED030000' => 'MKT',
            'MED031000' => 'MJH',
            'MED107000' => 'MFN',
            'MED032000' => 'MKN',
            'MED033000' => 'MKC',
            'MED034000' => 'MJ',
            'MED035000' => 'MBP',
            'MED036000' => 'MBQ',
            'MED037000' => 'MBNC',
            'MED038000' => 'MJF',
            'MED114000' => 'MJJ',
            'MED110000' => 'MFCH',
            'MED039000' => 'MBX',
            'MED040000' => 'MX,VXH',
            'MED041000' => 'MQ,VFG',
            'MED043000' => 'MBP',
            'MED044000' => 'MJCM',
            'MED115000' => 'MBN',
            'MED022090' => 'MJCJ',
            'MED117000' => 'MBF',
            'MED108000' => 'MBG',
            'MED045000' => 'MJ',
            'MED047000' => 'MBGL',
            'MED048000' => 'MBG,TTBL',
            'MED113000' => 'MBPN',
            'MED049000' => 'MBPR',
            'MED051000' => 'MBF',
            'MED102000' => 'MKLD',
            'MED052000' => 'MKFM',
            'MED118000' => 'MK,JW',
            'MED055000' => 'MJR',
            'MED056000' => 'MKJ',
            'MED057000' => 'MKJ,PSAN',
            'MED091000' => 'MJC',
            'MED058000' => 'MQC',
            'MED058010' => 'MQCL,MKA',
            'MED058020' => 'MQCA,MJA',
            'MED058240' => 'MQCA',
            'MED058030' => 'MQCL2',
            'MED058040' => 'MQCL1',
            'MED058050' => 'MQCA',
            'MED058060' => 'MQCL4',
            'MED058070' => 'MQCX',
            'MED058100' => 'MQCL,MQG',
            'MED058110' => 'MQCZ',
            'MED058120' => 'MQCL,MKC',
            'MED058220' => 'MQCL6',
            'MED058140' => 'MQCH',
            'MED058150' => 'MQCL,MBNH3',
            'MED058160' => 'MQCL,MJCL',
            'MED058230' => 'MQCL9,MKB',
            'MED058080' => 'MQCL3',
            'MED058170' => 'MQCM',
            'MED058180' => 'MQCL5',
            'MED058190' => 'MQC,MR',
            'MED058200' => 'MQCB',
            'MED058090' => 'MQCW,MBDC,MBQ',
            'MED058210' => 'MQC,MRG',
            'MED059000' => 'MBPN',
            'MED060000' => 'MBNH3',
            'MED061000' => 'MKVP',
            'MED062000' => 'MJCL',
            'MED062010' => 'MJCL',
            'MED062020' => 'MJCL,MJF',
            'MED062030' => 'MJCL,MJL',
            'MED062040' => 'MJCL,MKD',
            'MED062050' => 'MJCL,MJS',
            'MED062060' => 'MJCL,MJK',
            'MED063000' => 'MJQ',
            'MED064000' => 'MQR',
            'MED065000' => 'MJE',
            'MED092000' => 'MXH',
            'MED066000' => 'MJP',
            'MED093000' => 'MKAL',
            'MED103000' => 'MKFP',
            'MED067000' => 'MKF',
            'MED068000' => 'MKF,MFG',
            'MED094000' => 'MKD,MKP',
            'MED069000' => 'MKD',
            'MED070000' => 'MKCM,MKDN',
            'MED071000' => 'MKG',
            'MED072000' => 'MQP',
            'MED073000' => 'MQV',
            'MED074000' => 'MBDP',
            'MED104000' => 'MBD',
            'MED075000' => 'MFG',
            'MED100000' => 'MQK',
            'MED095000' => 'MBPM',
            'MED076000' => 'MBN',
            'MED077000' => 'MQWP',
            'MED105000' => 'MKL',
            'MED105010' => 'MKL,MKD',
            'MED105020' => 'MKGW',
            'MED078000' => 'MBN',
            'MED079000' => 'MJL',
            'MED080000' => 'MKSH,MJCL1,MKR',
            'MED081000' => 'MR',
            'MED121000' => 'MKH',
            'MED082000' => 'MFKC',
            'MED106000' => 'MBGR',
            'MED083000' => 'MJM',
            'MED119000' => 'MKZS',
            'MED084000' => 'MKW',
            'MED085000' => 'MN',
            'MED085090' => 'MND',
            'MED085040' => 'MNH',
            'MED085060' => 'MNG',
            'MED085030' => 'MNPC,MNP',
            'MED085100' => 'MN,MJQ',
            'MED085080' => 'MNB',
            'MED085010' => 'MNN',
            'MED085110' => 'MNK',
            'MED085020' => 'MKEP',
            'MED085120' => 'MNS',
            'MED085130' => 'MN,MJP',
            'MED085140' => 'MN,MKD',
            'MED085150' => 'MN,MFKC,MJS',
            'MED085070' => 'MNQ',
            'MED085050' => 'MNJ',
            'MED120000' => 'MBGT',
            'MED042000' => 'MKB',
            'MED086000' => 'MRG',
            'MED096000' => 'MKGT',
            'MED087000' => 'MQF',
            'MED097000' => 'MKVT',
            'MED088000' => 'MJS',
            'MED089000' => 'MZ',
            'MED089040' => 'MZT',
            'MED089010' => 'MZDH',
            'MED089020' => 'MZD',
            'MED089030' => 'MZC',
            'MED089050' => 'MZS',
            'MUS000000' => 'AV',
            'MUS004000' => 'AV,KNTF',
            'MUS012000' => 'AVD',
            'MUS055000' => 'AV',
            'MUS014000' => 'AV,5PB',
            'MUS015000' => 'AVA',
            'MUS049000' => 'AVL',
            'MUS002000' => 'AVLA,ATQL',
            'MUS053000' => 'AVLP,6SS',
            'MUS003000' => 'AVLP,6BM',
            'MUS005000' => 'AVLA,6CA',
            'MUS026000' => 'AVL,5LC',
            'MUS051000' => 'AVLC',
            'MUS006000' => 'AVLA,6CA',
            'MUS010000' => 'AVLT,6CM,6BL',
            'MUS011000' => 'AVLP,ATQ',
            'MUS013000' => 'AVLX,AVRS',
            'MUS017000' => 'AVLT,6FD',
            'MUS019000' => 'AVLP,6HA',
            'MUS024000' => 'AVLW',
            'MUS025000' => 'AVLP,6JD',
            'MUS036000' => 'AVLW,6LC',
            'MUS045000' => 'AVLA',
            'MUS046000' => 'AVLP,AVLM',
            'MUS027000' => 'AVLP,6NK',
            'MUS028000' => 'AVLF',
            'MUS029000' => 'AVLP,6PB',
            'MUS030000' => 'AVLP,6PN',
            'MUS031000' => 'AVLP,6RJ',
            'MUS047000' => 'AVLP,6RK',
            'MUS035000' => 'AVLP,6RF,6RG',
            'MUS039000' => 'AVLP,6SB,6RH',
            'MUS020000' => 'AVM,AVC',
            'MUS050000' => 'AVN,AVP',
            'MUS022000' => 'AVS',
            'MUS001000' => 'AV',
            'MUS007000' => 'AVSD',
            'MUS008000' => 'AVS',
            'MUS016000' => 'AVS',
            'MUS038000' => 'AVSD',
            'MUS040000' => 'AVS',
            'MUS041000' => 'AVA',
            'MUS042000' => 'AVSA',
            'MUS052000' => 'AVQ',
            'MUS023000' => 'AVR',
            'MUS023010' => 'AVRN1',
            'MUS023060' => 'AVRL1',
            'MUS023020' => 'AVRJ',
            'MUS023030' => 'AVRG',
            'MUS023040' => 'AVRL',
            'MUS023050' => 'AVRN2',
            'MUS054000' => 'AVA',
            'MUS037000' => 'AVQ',
            'MUS037010' => 'AVQ,AVN,AVP',
            'MUS037020' => 'AVQ,AVLA',
            'MUS037120' => 'AVQ,AVRN1',
            'MUS037030' => 'AVQ,AVLC',
            'MUS037040' => 'AVQ,AVRL1',
            'MUS037050' => 'AVQ',
            'MUS037060' => 'AVQ,AVLM',
            'MUS037070' => 'AVQ,AVLA,AVLF',
            'MUS037080' => 'AVQ,AVRJ',
            'MUS037090' => 'AVQ,AVRG,AVRG1',
            'MUS037100' => 'AVQS',
            'MUS037130' => 'AVQ,AVRL',
            'MUS037110' => 'AVQS',
            'MUS037140' => 'AVQ,AVRN2',
            'MUS032000' => 'AVX',
            'MUS033000' => 'AV,GBC',
            'MUS048000' => 'AVLK',
            'MUS048010' => 'AVLK,5PGM',
            'MUS009000' => 'AVLK,5PGM',
            'MUS018000' => 'AVLK,6GD',
            'MUS021000' => 'AVLK,QRVJ1',
            'MUS048020' => 'AVLK,5PGJ',
            'MUS048030' => 'AVLK,5PGP',
            'NAT000000' => 'WN',
            'NAT039000' => 'JBFU',
            'NAT001000' => 'WNC',
            'NAT003000' => 'WNCF',
            'NAT042000' => 'WNCF',
            'NAT043000' => 'WNCB',
            'NAT005000' => 'WNCN',
            'NAT007000' => 'WNA',
            'NAT012000' => 'WNCS,PSVC',
            'NAT016000' => 'WNGH',
            'NAT017000' => 'WNCN',
            'NAT019000' => 'WNCF',
            'NAT020000' => 'WNCS',
            'NAT002000' => 'WNCF,PSVM3',
            'NAT028000' => 'WNCK',
            'NAT037000' => 'WNC',
            'NAT044000' => 'WNCF',
            'NAT004000' => 'WNCB',
            'NAT009000' => 'WNW,RBC',
            'NAT010000' => 'RNC',
            'NAT045000' => 'WNW,RGB',
            'NAT045050' => 'WNW,RGBP',
            'NAT045010' => 'WNW,RGBA',
            'NAT014000' => 'WNW,RGBL',
            'NAT018000' => 'WNW,RGBG',
            'NAT041000' => 'WNW,RGBS',
            'NAT025000' => 'WNW,RBKC',
            'NAT045020' => 'WNW,RGBC',
            'NAT045030' => 'WNW,RGBU,1QMP',
            'NAT029000' => 'WNW,RGBG',
            'NAT045040' => 'WNW',
            'NAT046000' => 'RNKH1',
            'NAT011000' => 'RNK',
            'NAT024000' => 'WN',
            'NAT015000' => 'WNR',
            'NAT023000' => 'RNR',
            'NAT038000' => 'RNF',
            'NAT026000' => 'WNP',
            'NAT047000' => 'WNP',
            'NAT048000' => 'WNP',
            'NAT013000' => 'WNP',
            'NAT022000' => 'WNP',
            'NAT034000' => 'WNP',
            'NAT027000' => 'WN,GBC',
            'NAT049000' => 'WN',
            'NAT030000' => 'WNR',
            'NAT031000' => 'WNCS1',
            'NAT032000' => 'WNW',
            'NAT033000' => 'WNX',
            'NAT036000' => 'WNWM',
            'PER000000' => 'AT',
            'PER001000' => 'ATDC',
            'PER017000' => 'ATFV',
            'PER014000' => 'AT,KNT',
            'PER002000' => 'ATXC',
            'PER015000' => 'ATXD',
            'PER003000' => 'ATQ',
            'PER003090' => 'ATQR',
            'PER003050' => 'ATQC',
            'PER003010' => 'ATQL,6CA',
            'PER003020' => 'ATQZ,6FD',
            'PER003100' => 'ATQ,ATY',
            'PER003030' => 'ATQT,6JD',
            'PER003040' => 'ATQT',
            'PER003060' => 'ATQ',
            'PER003070' => 'ATQ,GBC',
            'PER021000' => 'ATQ',
            'PER003080' => 'ATQT',
            'PER004000' => 'ATF',
            'PER004010' => 'ATFX',
            'PER004060' => 'ATFN',
            'PER004070' => 'ATFN,ATMB',
            'PER004080' => 'ATFV',
            'PER004090' => 'ATFN,ATMC',
            'PER004100' => 'ATFN,ATMB',
            'PER004110' => 'ATFR',
            'PER004120' => 'ATFN,ATMH',
            'PER004130' => 'ATFN,ATMN',
            'PER004140' => 'ATFN,ATMN',
            'PER004150' => 'ATFN,ATMB',
            'PER004020' => 'ATFG',
            'PER004030' => 'ATFA',
            'PER004040' => 'ATF,GBC',
            'PER004050' => 'CBVS,ATFD',
            'PER022000' => 'ATXD',
            'PER018000' => 'ATFB',
            'PER020000' => 'ATDC,DDV',
            'PER007000' => 'ATXM',
            'PER008000' => 'ATL',
            'PER008010' => 'ATL,ATY',
            'PER008020' => 'ATL,GBC',
            'PER009000' => 'AT,GBC',
            'PER016000' => 'ATFD,ATJD',
            'PER019000' => 'ATX',
            'PER010000' => 'ATJ',
            'PER010010' => 'ATJX',
            'PER010060' => 'ATJS',
            'PER010070' => 'ATJS',
            'PER010080' => 'ATJS,ATMC',
            'PER010090' => 'ATJS,ATMF',
            'PER010100' => 'ATJS,ATJS1',
            'PER010110' => 'ATJS,ATMN',
            'PER010020' => 'ATJ',
            'PER010030' => 'ATJ,ATY',
            'PER010040' => 'ATJ,GBC',
            'PER010050' => 'CBVS,ATJD',
            'PER011000' => 'ATD',
            'PER013000' => 'ATD',
            'PER011010' => 'ATDF',
            'PER011020' => 'ATD,ATY',
            'PER006000' => 'ATX',
            'PER011030' => 'CBV,ATDF',
            'PER011040' => 'ATDH',
            'PER023000' => 'ATDC',
            'PET000000' => 'WNG',
            'PET002000' => 'WNGK',
            'PET003000' => 'WNGC',
            'PET003010' => 'WNGC',
            'PET004000' => 'WNGD',
            'PET004010' => 'WNGD',
            'PET004020' => 'WNGD1',
            'PET010000' => 'WNG',
            'PET005000' => 'WNGF',
            'PET012000' => 'WNG,MZL',
            'PET006000' => 'WNGH',
            'PET013000' => 'WNGX',
            'PET011000' => 'WNGR',
            'PET008000' => 'WNG,GBC',
            'PET009000' => 'WNGS',
            'PHI000000' => 'QD',
            'PHI001000' => 'QDTN',
            'PHI028000' => 'QDHC,QRF',
            'PHI026000' => 'QDH',
            'PHI003000' => 'QDHC',
            'PHI004000' => 'QDTK',
            'PHI035000' => 'QD',
            'PHI005000' => 'QDTQ',
            'PHI007000' => 'QDT',
            'PHI008000' => 'QDTQ',
            'PHI036000' => 'QDT,CFP',
            'PHI033000' => 'QDHC,QRD',
            'PHI009000' => 'QDH',
            'PHI002000' => 'QDHA',
            'PHI012000' => 'QDHF',
            'PHI037000' => 'QDH,NHDL',
            'PHI016000' => 'QDHR',
            'PHI046000' => 'QD',
            'PHI038000' => 'CFA',
            'PHI011000' => 'QDTL',
            'PHI013000' => 'QDTJ',
            'PHI014000' => 'QD',
            'PHI015000' => 'QDTM',
            'PHI031000' => 'QDH',
            'PHI039000' => 'QDHR9',
            'PHI040000' => 'QDTS1',
            'PHI027000' => 'QDHR7',
            'PHI041000' => 'QDHM',
            'PHI006000' => 'QDHR5',
            'PHI010000' => 'QDHH',
            'PHI042000' => 'QDHR1',
            'PHI018000' => 'QDHR5',
            'PHI043000' => 'QDHR7',
            'PHI020000' => 'QDHR3',
            'PHI032000' => 'QDHM',
            'PHI044000' => 'QDH',
            'PHI029000' => 'QDHR7',
            'PHI045000' => 'QDHR',
            'PHI030000' => 'QDTQ',
            'PHI019000' => 'QDTS',
            'PHI021000' => 'QD,GBC',
            'PHI022000' => 'QRAB',
            'PHI034000' => 'QDTS',
            'PHI023000' => 'QDHC,QRRL5',
            'PHI025000' => 'QDHC,QRFB23',
            'PHO000000' => 'AJ',
            'PHO025000' => 'AJC,GBCY',
            'PHO026000' => 'AJTF,WNX',
            'PHO003000' => 'AJ,ABQ',
            'PHO004000' => 'AJC',
            'PHO004010' => 'AJC',
            'PHO004020' => 'AJC',
            'PHO021000' => 'AJ,AKL',
            'PHO005000' => 'AJ',
            'PHO027000' => 'AJTF,JKVF1',
            'PHO010000' => 'AJ,AGA',
            'PHO011000' => 'AJCD',
            'PHO011010' => 'AJCD',
            'PHO011020' => 'AJCD',
            'PHO011030' => 'AJCD',
            'PHO014000' => 'AJC',
            'PHO015000' => 'AJF',
            'PHO017000' => 'AJ,GBC',
            'PHO023000' => 'AJ',
            'PHO023010' => 'AJTF',
            'PHO001000' => 'AJTF,AM,AGP',
            'PHO023070' => 'AJF',
            'PHO023080' => 'AJF',
            'PHO023020' => 'AJCP',
            'PHO023030' => 'AJCX',
            'PHO009000' => 'AJTF,AKT',
            'PHO023110' => 'AJTF,WB',
            'PHO023100' => 'AJ,NH',
            'PHO023040' => 'AJTF,AGNL',
            'PHO023090' => 'AJF',
            'PHO023050' => 'AJCX',
            'PHO013000' => 'AJTF,AGN',
            'PHO016000' => 'AJCP',
            'PHO019000' => 'AJ,WTM',
            'PHO023060' => 'AJF,SC',
            'PHO023120' => 'AJF',
            'PHO023130' => 'AJTF',
            'PHO018000' => 'AJT',
            'PHO022000' => 'AJTV,ATFX',
            'PHO020000' => 'AJT',
            'PHO006000' => 'AJT',
            'PHO024000' => 'AJTH',
            'PHO007000' => 'AJT',
            'PHO012000' => 'AJTS',
            'POE000000' => 'DC',
            'POE007000' => 'DC,1H',
            'POE005010' => 'DC,1KBB',
            'POE005050' => 'DC,1KBB,5PB-US-C',
            'POE005060' => 'DC,1KBB,5PB-US-D',
            'POE005070' => 'DC,1KBB,5PB-US-H',
            'POE015000' => 'DC,1KBB,5PB-US-E',
            'POE008000' => 'DCA,DB',
            'POE001000' => 'DCQ',
            'POE009000' => 'DC,1F',
            'POE009010' => 'DC,1FPC',
            'POE009020' => 'DC,1FPJ',
            'POE010000' => 'DC,1M',
            'POE011000' => 'DC,1KBC',
            'POE011010' => 'DC,1KBC,5PBA',
            'POE012000' => 'DC,1KJ,1KL',
            'POE005030' => 'DC,1D',
            'POE005020' => 'DC,1DDU,1DDR',
            'POE017000' => 'DC,1DDF',
            'POE018000' => 'DC,1DFG',
            'POE019000' => 'DC,1DST',
            'POE020000' => 'DC,1DSE,1DSP',
            'POE014000' => 'DCA,6EH',
            'POE025000' => 'DCRB',
            'POE021000' => 'DC,5PS',
            'POE022000' => 'DCA,DB,6MB',
            'POE013000' => 'DC,1FB',
            'POE016000' => 'DC,1DTA,1QBDR',
            'POE026000' => 'DCF,5PX-GB-S',
            'POE023000' => 'DC',
            'POE023010' => 'DC,FXL',
            'POE023050' => 'DC',
            'POE003000' => 'DC,QR',
            'POE023020' => 'DC,FXD',
            'POE023030' => 'DC,FXE',
            'POE023040' => 'DC,FXR',
            'POE024000' => 'DC',
            'POL000000' => 'JP',
            'POL040000' => 'JP,1KBB',
            'POL040010' => 'JPQ,1KBB',
            'POL040030' => 'JPQ,1KBB',
            'POL006000' => 'JPQ,1KBB',
            'POL040040' => 'JPT,1KBB',
            'POL030000' => 'JPQ,1KBB',
            'POL020000' => 'JPR,1KBB',
            'POL039000' => 'JBFV3',
            'POL003000' => 'JPVC',
            'POL004000' => 'JPVH',
            'POL045000' => 'NHTQ,JP',
            'POL046000' => 'JP',
            'POL009000' => 'JPB',
            'POL022000' => 'JPHC',
            'POL064000' => 'JPZ',
            'POL032000' => 'JPA',
            'POL061000' => 'JWXK,NHTZ',
            'POL062000' => 'JPSL',
            'POL033000' => 'GTQ',
            'POL010000' => 'JPA',
            'POL035010' => 'JPVH',
            'POL047000' => 'NHTQ,JP',
            'POL036000' => 'JPSH',
            'POL048000' => 'JPSN',
            'POL011000' => 'JPS',
            'POL001000' => 'JPSF',
            'POL011010' => 'JPSD',
            'POL011020' => 'KCL',
            'POL021000' => 'LBBC',
            'POL013000' => 'KNXN,KNXU',
            'POL014000' => 'JKSW1',
            'POL041000' => 'JPWH',
            'POL034000' => 'GTU',
            'POL023000' => 'KCP',
            'POL035000' => 'JPV',
            'POL042000' => 'JPF',
            'POL042010' => 'JPFB',
            'POL042060' => 'KCSA',
            'POL005000' => 'JPFC,JPFF',
            'POL042020' => 'JPFM,JPFK',
            'POL007000' => 'JPHV',
            'POL042030' => 'JPHX',
            'POL042050' => 'JPF',
            'POL031000' => 'JPFN',
            'POL042040' => 'JPF',
            'POL016000' => 'JPH',
            'POL008000' => 'JPWC,JPHF',
            'POL065000' => 'JPH,JBCT',
            'POL043000' => 'JPW',
            'POL015000' => 'JPL',
            'POL066000' => 'JBFL',
            'POL049000' => 'JPV',
            'POL017000' => 'JPP',
            'POL071000' => 'JPWA',
            'POL028000' => 'JPQB',
            'POL067000' => 'JPQB,TV',
            'POL002000' => 'RPC',
            'POL050000' => 'JPQB,TJK',
            'POL038000' => 'JPQB,JBCC8',
            'POL024000' => 'JPQB,KCP',
            'POL068000' => 'JPQB,RNFY',
            'POL044000' => 'RND',
            'POL073000' => 'JPQB,KCVJ',
            'POL070000' => 'JPQB,JBFH',
            'POL069000' => 'JPQB,JW',
            'POL026000' => 'RP',
            'POL063000' => 'JPQB,PDK',
            'POL029000' => 'JPQB,JB',
            'POL027000' => 'JKS',
            'POL019000' => 'JKS',
            'POL018000' => 'JP,GBC',
            'POL072000' => 'QRAM2,JPFR',
            'POL012000' => 'JW',
            'POL037000' => 'JPWL',
            'POL051000' => 'JPA',
            'POL052000' => 'JP,JBSF1',
            'POL040020' => 'JP',
            'POL053000' => 'JP,1H',
            'POL054000' => 'JP,1F',
            'POL055000' => 'JP,1M',
            'POL056000' => 'JP,1KBC',
            'POL057000' => 'JP,1KJ,1KL',
            'POL058000' => 'JP,1D',
            'POL059000' => 'JP,1FB',
            'POL060000' => 'JP,1DTA,1QBDR',
            'PSY000000' => 'JM',
            'PSY054000' => 'JM',
            'PSY003000' => 'JM',
            'PSY042000' => 'JMBT',
            'PSY007000' => 'MKM',
            'PSY051000' => 'JMR,JMM',
            'PSY008000' => 'JMR',
            'PSY034000' => 'JMR',
            'PSY039000' => 'JMC',
            'PSY002000' => 'JMC,JBSP2',
            'PSY043000' => 'JMC,JMD,JBSP3',
            'PSY004000' => 'JMC,JBSP1',
            'PSY044000' => 'JMC',
            'PSY012000' => 'JM',
            'PSY013000' => 'JMQ',
            'PSY055000' => 'JM',
            'PSY050000' => 'JMH,JBSL',
            'PSY053000' => 'JMA,PSAJ',
            'PSY040000' => 'JML',
            'PSY014000' => 'JMK',
            'PSY052000' => 'JMQ',
            'PSY015000' => 'JM',
            'PSY016000' => 'JMU',
            'PSY035000' => 'JMT',
            'PSY021000' => 'JMJ',
            'PSY017000' => 'JMHC,JHBK',
            'PSY036000' => 'JM',
            'PSY045000' => 'JMA',
            'PSY045010' => 'JMAL',
            'PSY045070' => 'MKMT6,JMAQ',
            'PSY045040' => 'JMA',
            'PSY045050' => 'JMA',
            'PSY045020' => 'JMAN',
            'PSY045060' => 'JMAJ',
            'PSY026000' => 'JMAF',
            'PSY045030' => 'JMA',
            'PSY020000' => 'JMM',
            'PSY023000' => 'JMS',
            'PSY024000' => 'JMM',
            'PSY046000' => 'JM,MBPM',
            'PSY022000' => 'JMP',
            'PSY038000' => 'MKZR',
            'PSY022060' => 'JMP',
            'PSY022010' => 'JMP',
            'PSY022020' => 'MKJA',
            'PSY022030' => 'JMP',
            'PSY009000' => 'JMP',
            'PSY049000' => 'JMP',
            'PSY022070' => 'JMP',
            'PSY011000' => 'MKZD',
            'PSY022090' => 'MKJ',
            'PSY022080' => 'JMP,JMS',
            'PSY022040' => 'JMP,MKPB',
            'PSY022050' => 'JMP',
            'PSY028000' => 'MKMT',
            'PSY006000' => 'MKMT3',
            'PSY010000' => 'MKMT5',
            'PSY041000' => 'MKMT4',
            'PSY048000' => 'MKMT2',
            'PSY056000' => 'MKMT,5PS',
            'PSY029000' => 'JM,GBC',
            'PSY030000' => 'JMB',
            'PSY031000' => 'JMH',
            'PSY032000' => 'JMB',
            'PSY037000' => 'JM,JHBZ',
            'REF000000' => 'GB',
            'REF001000' => 'GBCY',
            'REF002000' => 'RGX',
            'REF004000' => 'GBCR',
            'REF030000' => 'VSG',
            'REF007000' => 'GBD',
            'REF008000' => 'CBD',
            'REF009000' => 'GBCT',
            'REF010000' => 'GBA',
            'REF011000' => 'WJX',
            'REF032000' => 'WJX,KNSJ',
            'REF013000' => 'NHTG,WQY',
            'REF015000' => 'VS',
            'REF033000' => 'VS',
            'REF035000' => 'WZS',
            'REF018000' => 'WDKX',
            'REF019000' => 'GBCQ',
            'REF020000' => 'GPS',
            'REF034000' => 'GTT',
            'REF022000' => 'CBF',
            'REF023000' => 'WDKX',
            'REF024000' => 'WJW',
            'REF025000' => 'CB',
            'REF027000' => 'GBCY',
            'REL000000' => 'QR',
            'REL001000' => 'QRYA5',
            'REL114000' => 'QRS',
            'REL072000' => 'QRA,NK',
            'REL004000' => 'QRYA5',
            'REL005000' => 'QRRB',
            'REL006020' => 'DNBX,QRMF1',
            'REL006030' => 'DNBX,QRMF12,QRJF1',
            'REL006040' => 'DNBX,QRMF13',
            'REL006050' => 'QRVC,QRMF1',
            'REL006060' => 'QRVC,QRMF12,QRJF1',
            'REL006750' => 'QRVC,QRMF12,QRJF1',
            'REL006760' => 'QRVC,QRMF12,QRJF1',
            'REL006770' => 'QRVC,QRMF12,QRJF1',
            'REL006780' => 'QRVC,QRMF12,QRJF1',
            'REL006790' => 'QRVC,QRMF12,QRMF14,QRJF1',
            'REL006070' => 'QRVC,QRMF13',
            'REL006800' => 'QRVC,QRMF13',
            'REL006810' => 'QRVC,QRMF13',
            'REL006820' => 'QRVC,QRMF13',
            'REL006830' => 'QRVC,QRMF13',
            'REL006080' => 'QRVC,QRMF1',
            'REL006090' => 'QRVC,QRMF12,QRJF1',
            'REL006100' => 'QRVC,QRMF13',
            'REL006110' => 'QRMF19',
            'REL006120' => 'QRMF19,QRMF12,QRJF1',
            'REL006130' => 'QRMF19,QRMF13',
            'REL006160' => 'QRVC,QRMF1',
            'REL006650' => 'QRVC,QRMF1,NHTP1',
            'REL006660' => 'QRVC,QRMF1',
            'REL006670' => 'QRVC,QRMF1',
            'REL006680' => 'QRVC,QRMF1',
            'REL006410' => 'QRVC,QRMF1',
            'REL006150' => 'QRMF19',
            'REL006000' => 'QRVC,QRMF1',
            'REL006700' => 'QRVC,QRMF1',
            'REL006400' => 'QRVC,QRMF1',
            'REL006630' => 'QRVC,QRMF1',
            'REL006210' => 'QRVC,QRMF12,QRJF1',
            'REL006840' => 'QRVC,QRMF12,QRJF1',
            'REL006850' => 'QRVC,QRMF12,QRJF1',
            'REL006740' => 'QRVC,QRMF12,QRJF1',
            'REL006730' => 'QRVC,QRMF12,QRJF1',
            'REL006880' => 'QRVC,QRMF12,QRMF14,QRJF1',
            'REL006220' => 'QRVC,QRMF13',
            'REL006710' => 'QRVC,QRMF13',
            'REL006720' => 'QRVC,QRMF13',
            'REL006860' => 'QRVC,QRMF13',
            'REL006870' => 'QRVC,QRMF13',
            'REL006890' => 'QRVC,QRMF13,QRMF14',
            'REL006140' => 'QRVC,QRMF1',
            'REL115000' => 'QRAM7',
            'REL007000' => 'QRF',
            'REL007010' => 'QRF,QRAX',
            'REL007020' => 'QRFP',
            'REL007030' => 'QRFF',
            'REL007040' => 'QRFB',
            'REL007050' => 'QRFB21',
            'REL092000' => 'QRFB23',
            'REL108000' => 'QRMB',
            'REL014000' => 'QRMB,QRVS',
            'REL008000' => 'QRMB,LAFX',
            'REL108010' => 'QRMB,QRVS',
            'REL108020' => 'QRMB,QRAX',
            'REL011000' => 'QRMP,QRVP3',
            'REL095000' => 'QRMP,QRVP3',
            'REL091000' => 'QRMP,QRVP3',
            'REL012000' => 'QRMP,5PGM',
            'REL012140' => 'QRMP,QRVS3,5PGM',
            'REL012010' => 'QRMP,QRVL,VFJX,5PGM',
            'REL012020' => 'QRMP,QRVJ3,5PGM',
            'REL012150' => 'QRMP,QRVJ3,5PGM',
            'REL012030' => 'QRMP,VFV,5PGM',
            'REL012040' => 'QRMP,QRVX,5PGM',
            'REL108030' => 'QRMP,5PGM',
            'REL012050' => 'QRMP,QRVP7,5PGM',
            'REL012060' => 'QRMP,QRVP7,5PGM,5JB',
            'REL012160' => 'QRMP,VFX,5PGM',
            'REL012070' => 'QRMP,QRVX,5PGM',
            'REL012170' => 'QRMP,DNC,5PGM',
            'REL012080' => 'QRMP,QRVJ2,5PGM',
            'REL012090' => 'QRMP,VSC,5PGM',
            'REL012110' => 'QRMP,QRVS2,5PGM',
            'REL012120' => 'QRMP,QRVX,5PGM',
            'REL099000' => 'QRMP,5PGM',
            'REL063000' => 'QRMP,5PGM',
            'REL012130' => 'QRMP,QRVP7,5PGM,5JA',
            'REL109000' => 'QRM,QRVS3',
            'REL109010' => 'QRM,QRVS3',
            'REL109020' => 'QRM,QRVS3',
            'REL050000' => 'QRM,QRVP5',
            'REL023000' => 'QRM,QRVX',
            'REL030000' => 'QRM,QRVS4',
            'REL045000' => 'QRM,QRVS4',
            'REL074000' => 'QRM,QRVS2',
            'REL080000' => 'QRM,QRVS2',
            'REL109030' => 'QRM,QRVS3,5AC',
            'REL055000' => 'QRMP,QRVJ1',
            'REL055010' => 'QRMP1',
            'REL055020' => 'QRMP,QRVJ',
            'REL067000' => 'QRM,QRVG',
            'REL067010' => 'QRM,QRVG',
            'REL067020' => 'QRM,QRVG',
            'REL067030' => 'QRM,QRVG',
            'REL067040' => 'QRM,QRVG',
            'REL067050' => 'QRM,QRVG',
            'REL067060' => 'QRM,QRAB9',
            'REL067070' => 'QRM,QRAM1',
            'REL067080' => 'QRM,QRAX',
            'REL067120' => 'QRM,QRVG',
            'REL104000' => 'QRM,QRVG',
            'REL067090' => 'QRM,QRVG',
            'REL067130' => 'QRM,QRVG',
            'REL067100' => 'QRM,QRAB7',
            'REL067110' => 'QRM,QRVG',
            'REL070000' => 'QRM',
            'REL002000' => 'QRMB3,5PB-US-B',
            'REL003000' => 'QRMB31',
            'REL073000' => 'QRMB32',
            'REL093000' => 'QRMB33',
            'REL009000' => 'QRM,QRVP3',
            'REL010000' => 'QRMB1',
            'REL083000' => 'QRMB5',
            'REL046000' => 'QRMB5',
            'REL094000' => 'QRMB',
            'REL027000' => 'QRMB31',
            'REL015000' => 'QRM,QRAX',
            'REL096000' => 'QRMB5',
            'REL013000' => 'QRM,AB',
            'REL082000' => 'QRMB34',
            'REL043000' => 'QRMB3,5PB-US-B',
            'REL044000' => 'QRMB35',
            'REL049000' => 'QRMB2',
            'REL079000' => 'QRMB36',
            'REL097000' => 'QRMB33',
            'REL053000' => 'QRMB3',
            'REL088000' => 'QRMB37',
            'REL110000' => 'QRM,QRVS1',
            'REL098000' => 'QRMB5',
            'REL059000' => 'QRMB39',
            'REL111000' => 'QRMB33',
            'REL081000' => 'QRVS3',
            'REL017000' => 'QRAC',
            'REL018000' => 'QRRL1',
            'REL019000' => 'QRVP5',
            'REL020000' => 'QRYM',
            'REL021000' => 'QRAB1',
            'REL100000' => 'QRYX9',
            'REL022000' => 'QRVJ3',
            'REL024000' => 'QRRL',
            'REL107000' => 'QRYM',
            'REL025000' => 'QRMB9',
            'REL026000' => 'QRVP3',
            'REL085000' => 'QRAB9',
            'REL113000' => 'QRA',
            'REL028000' => 'QRAM1',
            'REL077000' => 'QR',
            'REL078000' => 'QRAM6',
            'REL112000' => 'QRYC1',
            'REL032000' => 'QRD',
            'REL032010' => 'QRD,QRAX',
            'REL032020' => 'QRDP',
            'REL032030' => 'QRDF',
            'REL032040' => 'QRD,QRVG',
            'REL033000' => 'QRAX',
            'REL034000' => 'QRVP2,5HP',
            'REL034010' => 'QRVP2,5HP,5PGM',
            'REL034020' => 'QRVP2,5HPD',
            'REL034030' => 'QRVP2,5HPF',
            'REL034040' => 'QRVP2,5HP,5PGJ',
            'REL034050' => 'QRVP2,5HP',
            'REL029000' => 'QRRT',
            'REL036000' => 'QRVX',
            'REL016000' => 'QRVS',
            'REL037000' => 'QRP',
            'REL037010' => 'QRP,QRAX',
            'REL041000' => 'QRPF1',
            'REL037030' => 'QRPP',
            'REL037040' => 'QRPB3',
            'REL090000' => 'QRPB4',
            'REL037050' => 'QRPB1',
            'REL037060' => 'QRP,QRVG',
            'REL038000' => 'QRRC',
            'REL040000' => 'QRJ',
            'REL040050' => 'QRJB2',
            'REL040030' => 'QRJ,QRAX',
            'REL040060' => 'QRJ,VXWK',
            'REL040070' => 'QRJB1',
            'REL040080' => 'QRJB3',
            'REL040010' => 'QRJP',
            'REL040040' => 'QRJF',
            'REL064000' => 'QRJF5',
            'REL040090' => 'QRJ,QRVG',
            'REL071000' => 'QRVP',
            'REL042000' => 'QRVK',
            'REL101000' => 'QRMB8',
            'REL086000' => 'QRVS5',
            'REL047000' => 'QRVK2',
            'REL117000' => 'QRS,VXWS',
            'REL051000' => 'QRAB',
            'REL119000' => 'QRVP1',
            'REL087000' => 'QRVJ2',
            'REL052000' => 'QRVJ',
            'REL052010' => 'QRVJ,QRM',
            'REL052030' => 'QRVJ,QRPP',
            'REL052020' => 'QRVJ,QRJ',
            'REL075000' => 'QRA,JM',
            'REL054000' => 'QR,GBC',
            'REL106000' => 'QRAM3',
            'REL084000' => 'QRAM2',
            'REL116000' => 'QRAM9',
            'REL120000' => 'QRVQ',
            'REL089000' => 'QRYM',
            'REL058000' => 'QRVH',
            'REL058010' => 'QRVH,QRM',
            'REL058020' => 'QRVH,QRJ',
            'REL105000' => 'QRVP7',
            'REL060000' => 'QRRL3',
            'REL061000' => 'QRRD',
            'REL062000' => 'QRVK',
            'REL065000' => 'QRRL5',
            'REL066000' => 'QRAB1',
            'REL102000' => 'QRVG',
            'REL068000' => 'QRYC5',
            'REL103000' => 'QRYA',
            'REL118000' => 'QRYX5,VXWT',
            'REL069000' => 'QRRF',
            'SCI000000' => 'PD',
            'SCI001000' => 'PHDS',
            'SCI003000' => 'PDG',
            'SCI102000' => 'PSAX',
            'SCI010000' => 'TCB',
            'SCI012000' => 'PBWS',
            'SCI013000' => 'PN',
            'SCI013010' => 'PNF',
            'SCI013020' => 'PSB',
            'SCI013070' => 'PNRA',
            'SCI013100' => 'PNRH',
            'SCI013080' => 'PNC',
            'SCI013060' => 'TDC',
            'SCI013030' => 'PNK',
            'SCI013040' => 'PNN',
            'SCI013050' => 'PNR',
            'SCI013090' => 'PSB',
            'SCI090000' => 'JMAQ',
            'SCI019000' => 'RB',
            'SCI030000' => 'RG',
            'SCI031000' => 'RBG',
            'SCI081000' => 'RBK',
            'SCI083000' => 'RBKF',
            'SCI042000' => 'RBP',
            'SCI048000' => 'PNV',
            'SCI052000' => 'RBKC',
            'SCI091000' => 'RBGH,RBGB',
            'SCI082000' => 'RBC',
            'SCI023000' => 'PDND',
            'SCI024000' => 'PHDY',
            'SCI026000' => 'TQ',
            'SCI080000' => 'PD',
            'SCI101000' => 'PD,JBFV5',
            'SCI028000' => 'PDN',
            'SCI092000' => 'RNPG',
            'SCI034000' => 'PDX',
            'SCI093000' => 'PDN',
            'SCI086000' => 'PS',
            'SCI056000' => 'PS,MFC,MFG',
            'SCI006000' => 'PSG',
            'SCI007000' => 'PSB',
            'SCI088000' => 'RNCB',
            'SCI008000' => 'PS',
            'SCI009000' => 'PHVN',
            'SCI011000' => 'PST',
            'SCI017000' => 'PSF',
            'SCI072000' => 'PSC',
            'SCI020000' => 'PSAF',
            'SCI027000' => 'PSAJ',
            'SCI029000' => 'PSAK',
            'SCI073000' => 'PST,TVS',
            'SCI036000' => 'PSX,MFC,MFG',
            'SCI039000' => 'PSPM',
            'SCI045000' => 'PSG',
            'SCI049000' => 'PSD',
            'SCI094000' => 'PSQ',
            'SCI089000' => 'PSAN',
            'SCI087000' => 'PSAB',
            'SCI099000' => 'PSG',
            'SCI070000' => 'PSV',
            'SCI025000' => 'PSVA2',
            'SCI070060' => 'PSVP',
            'SCI070010' => 'PSVC,PSVF',
            'SCI070020' => 'PSVA',
            'SCI070030' => 'PSVM',
            'SCI070040' => 'PSVJ',
            'SCI070050' => 'PSVM3',
            'SCI041000' => 'PHD',
            'SCI084000' => 'PHDF,TGMF1',
            'SCI018000' => 'PHDT',
            'SCI085000' => 'PHDF,TGMF',
            'SCI095000' => 'PHDF,TGMF',
            'SCI096000' => 'TGMD',
            'SCI079000' => 'PHDT',
            'SCI065000' => 'PHH',
            'SCI047000' => 'PDND',
            'SCI050000' => 'PDT',
            'SCI100000' => 'PSAF',
            'SCI054000' => 'RBX',
            'SCI075000' => 'PDA,PDR',
            'SCI055000' => 'PH',
            'SCI005000' => 'PHVB',
            'SCI074000' => 'PHM',
            'SCI077000' => 'PHFC',
            'SCI016000' => 'PNT',
            'SCI021000' => 'PHK',
            'SCI022000' => 'PHK',
            'SCI032000' => 'PHVG',
            'SCI033000' => 'PHDV',
            'SCI038000' => 'PHK',
            'SCI040000' => 'PHU',
            'SCI051000' => 'PHN',
            'SCI053000' => 'PHJ',
            'SCI103000' => 'PHP',
            'SCI097000' => 'PHFC',
            'SCI057000' => 'PHQ',
            'SCI061000' => 'PHR',
            'SCI058000' => 'PNRL',
            'SCI059000' => 'TTBM',
            'SCI060000' => 'PD,GBC',
            'SCI043000' => 'PDM',
            'SCI076000' => 'PDN',
            'SCI098000' => 'TTD',
            'SCI004000' => 'PG',
            'SCI015000' => 'PGK',
            'SCI098010' => 'PGS',
            'SCI098020' => 'TTDX',
            'SCI098030' => 'PGM,PGS',
            'SCI078000' => 'PNFS',
            'SCI063000' => 'PD,JNU',
            'SCI064000' => 'GPFC',
            'SCI066000' => 'PGZ',
            'SCI067000' => 'PHDS',
            'SCI068000' => 'PDD',
            'SEL000000' => 'VS',
            'SEL001000' => 'VFJM',
            'SEL003000' => 'VFJK',
            'SEL004000' => 'VSPM',
            'SEL005000' => 'VFJG',
            'SEL036000' => 'VFJP',
            'SEL008000' => 'VSP,VFV',
            'SEL040000' => 'VSS',
            'SEL041000' => 'VS,VFJB',
            'SEL041010' => 'VFJL',
            'SEL041020' => 'VFJP',
            'SEL041030' => 'VFJQ',
            'SEL041040' => 'VFJL,VFVC',
            'SEL009000' => 'VSPT',
            'SEL048000' => 'VFJQ2',
            'SEL010000' => 'VFJX',
            'SEL012000' => 'VXN',
            'SEL014000' => 'VFJJ,VFJH',
            'SEL042000' => 'VSPQ',
            'SEL038000' => 'WJF',
            'SEL046000' => 'VFVC',
            'SEL039000' => 'VSZ',
            'SEL015000' => 'VXFG',
            'SEL045000' => 'WZSJ',
            'SEL019000' => 'VXM',
            'SEL020000' => 'VFJQ1',
            'SEL020010' => 'VFJQ1',
            'SEL011000' => 'VFJQ1',
            'SEL021000' => 'VSPM',
            'SEL037000' => 'VSPX',
            'SEL031000' => 'VS',
            'SEL016000' => 'VSPM',
            'SEL030000' => 'VSPT',
            'SEL023000' => 'VSPM',
            'SEL027000' => 'VSC',
            'SEL043000' => 'VFJQ3',
            'SEL049000' => 'VFB',
            'SEL047000' => 'VFB',
            'SEL049010' => 'VSY',
            'SEL017000' => 'VXHP',
            'SEL044000' => 'VS',
            'SEL033000' => 'VFJQ',
            'SEL024000' => 'VFJS',
            'SEL035000' => 'VS,KJMT',
            'SEL034000' => 'VFVC',
            'SEL032000' => 'VXA',
            'SEL026000' => 'VFJL',
            'SEL006000' => 'VFJK',
            'SEL013000' => 'VFJK',
            'SEL026010' => 'VFL',
            'SEL029000' => 'VFJK',
            'SOC000000' => 'JB',
            'SOC046000' => 'JBFV1',
            'SOC072000' => 'JPW,JBFA',
            'SOC055000' => 'JBCC4',
            'SOC002000' => 'JHM',
            'SOC002010' => 'JHMC',
            'SOC002020' => 'JHM',
            'SOC003000' => 'NK',
            'SOC068000' => 'JBSL13',
            'SOC056000' => 'JBSL,5PBD',
            'SOC061000' => 'JMHC',
            'SOC067000' => 'JBFV2',
            'SOC047000' => 'JBSP1',
            'SOC058000' => 'JBGX',
            'SOC004000' => 'JKV',
            'SOC005000' => 'JBCC6',
            'SOC036000' => 'JHBZ',
            'SOC006000' => 'JHBD',
            'SOC042000' => 'GTP,KCM',
            'SOC040000' => 'JBFF',
            'SOC031000' => 'JBFA',
            'SOC057000' => 'JBFN',
            'SOC007000' => 'JBFH',
            'SOC041000' => 'JB',
            'SOC008000' => 'JBSL',
            'SOC008010' => 'JBSL,1H',
            'SOC069000' => 'JBSL,1KBB',
            'SOC001000' => 'JBSL,1KBB,5PB-US-C',
            'SOC043000' => 'JBSL,1KBB,5PB-US-D',
            'SOC008080' => 'JBSL,1KBB',
            'SOC044000' => 'JBSL,1KBB,5PB-US-H',
            'SOC021000' => 'JBSL,1KBB,5PB-US-E',
            'SOC008020' => 'JBSL,1F',
            'SOC008030' => 'JBSL,1M',
            'SOC008040' => 'JBSL,1KBC',
            'SOC008050' => 'JBSL,1KJ,1KL',
            'SOC008060' => 'JBSL,1D',
            'SOC008070' => 'JBSL,1FB',
            'SOC010000' => 'JBSF11',
            'SOC011000' => 'JBGB',
            'SOC038000' => 'JBSX',
            'SOC037000' => 'JBFZ',
            'SOC032000' => 'JBSF',
            'SOC013000' => 'JBSP4',
            'SOC014000' => 'JBCC6,5HC',
            'SOC015000' => 'RGC',
            'SOC016000' => 'JKS',
            'SOC065000' => 'JBFW',
            'SOC073000' => 'JBFJ',
            'SOC062000' => 'JBSL11',
            'SOC048000' => 'JBSR,5PGP',
            'SOC049000' => 'JBSR,5PGJ',
            'SOC064000' => 'JBSJ,5PS',
            'SOC064010' => 'JBSJ,5PSB',
            'SOC012000' => 'JBSJ,5PSG',
            'SOC017000' => 'JBSJ,5PSL',
            'SOC064020' => 'JBSF3,5PT',
            'SOC052000' => 'JBCT',
            'SOC018000' => 'JBSF2',
            'SOC019000' => 'JHBC',
            'SOC020000' => 'JBSL1',
            'SOC030000' => 'JKVP',
            'SOC029000' => 'JBFM',
            'SOC033000' => 'JKSN1',
            'SOC022000' => 'JBCC1',
            'SOC034000' => 'JBFW',
            'SOC045000' => 'JBFC,JBFD',
            'SOC063000' => 'JBFV',
            'SOC059000' => 'JBFW',
            'SOC070000' => 'JBSL1',
            'SOC023000' => 'JB,GBC',
            'SOC066000' => 'JBFG',
            'SOC053000' => 'GTM',
            'SOC024000' => 'JHBC',
            'SOC060000' => 'JBFK2',
            'SOC054000' => 'NHTS',
            'SOC050000' => 'JBSA',
            'SOC025000' => 'JKSN',
            'SOC026000' => 'JHB',
            'SOC026010' => 'JHBK',
            'SOC026020' => 'JBSC',
            'SOC026040' => 'JHBA',
            'SOC026030' => 'JBSD',
            'SOC039000' => 'JBSR',
            'SOC027000' => 'JHBC',
            'SOC071000' => 'PDR',
            'SOC051000' => 'JBFK',
            'SOC035000' => 'JKSN1',
            'SOC028000' => 'JBSF1',
            'SPO000000' => 'SC',
            'SPO001000' => 'SMC',
            'SPO078000' => 'SK',
            'SPO062000' => 'SKR',
            'SPO057000' => 'SK',
            'SPO021000' => 'SKG',
            'SPO055000' => 'SK',
            'SPO065000' => 'SK',
            'SPO002000' => 'SVR',
            'SPO003000' => 'SFC',
            'SPO003020' => 'SFC',
            'SPO003030' => 'SFC,SCX',
            'SPO003040' => 'SFC',
            'SPO004000' => 'SFM',
            'SPO006000' => 'SXB,SHP',
            'SPO007000' => 'SFV',
            'SPO008000' => 'SRB',
            'SPO068000' => 'SCBM',
            'SPO009000' => 'SZR',
            'SPO074000' => 'SZN',
            'SPO070000' => 'SX',
            'SPO077000' => 'SC',
            'SPO061000' => 'SCG',
            'SPO003010' => 'SCG,SFC',
            'SPO061010' => 'SCG,SFM',
            'SPO061020' => 'SCG,SFBD',
            'SPO061030' => 'SCG,SFBC',
            'SPO082000' => 'SC,JNM',
            'SPO054000' => 'SFD',
            'SPO066000' => 'JHBS',
            'SPO011000' => 'SZD,SMQ',
            'SPO076000' => 'SCL',
            'SPO063000' => 'SC',
            'SPO012000' => 'SC',
            'SPO064000' => 'SXQ',
            'SPO071000' => 'SRF',
            'SPO073000' => 'SFJ',
            'SPO014000' => 'SVF',
            'SPO015000' => 'SFBD',
            'SPO016000' => 'SFH',
            'SPO017000' => 'SHG',
            'SPO075000' => 'SC',
            'SPO018000' => 'SZC',
            'SPO019000' => 'SCX',
            'SPO022000' => 'SVH',
            'SPO026000' => 'SFK',
            'SPO027000' => 'SRM',
            'SPO027010' => 'SRML',
            'SPO028000' => 'SMF',
            'SPO028010' => 'SMFA',
            'SPO028020' => 'SMFK',
            'SPO029000' => 'SZG',
            'SPO058000' => 'SCBB',
            'SPO030000' => 'SZ,SZV',
            'SPO060000' => 'SFX',
            'SPO031000' => 'SFT',
            'SPO032000' => 'SFT',
            'SPO042000' => 'SFTC',
            'SPO044000' => 'SFTD',
            'SPO045000' => 'SFTA',
            'SPO033000' => 'SC,GBC',
            'SPO079000' => 'SZG',
            'SPO034000' => 'SMX',
            'SPO056000' => 'SFBT,SFBV',
            'SPO035000' => 'SZE',
            'SPO037000' => 'SVT',
            'SPO038000' => 'SMX',
            'SPO040000' => 'SFBC',
            'SPO067000' => 'SFC',
            'SPO041000' => 'SCGP',
            'SPO046000' => 'SHB',
            'SPO047000' => 'SCG',
            'SPO048000' => 'SHBM',
            'SPO049000' => 'SFP',
            'SPO050000' => 'SZC',
            'SPO051000' => 'SP',
            'SPO005000' => 'SPN',
            'SPO010000' => 'SPNK',
            'SPO025000' => 'SPNK',
            'SPO080000' => 'SPNL',
            'SPO036000' => 'SPNG',
            'SPO059000' => 'SPCA',
            'SPO069000' => 'SPG',
            'SPO043000' => 'SPC',
            'SPO052000' => 'ST',
            'SPO081000' => 'STP',
            'SPO020000' => 'STK',
            'SPO023000' => 'STG,STJ',
            'SPO039000' => 'STA',
            'SPO072000' => 'STC',
            'SPO053000' => 'SRC',
            'STU000000' => 'JNZ',
            'STU001000' => 'YPZ,4Z-US-B',
            'STU002000' => 'YPZ,4Z-US-O',
            'STU003000' => 'YPZ,YPWN,4Z-US-P',
            'STU034000' => 'LX,4Z-US-L',
            'STU004000' => 'DSRC',
            'STU006000' => 'JNDH,VSD,4TNC',
            'STU007000' => 'JNDH,KNV,4CPF',
            'STU008000' => 'YPZ',
            'STU009000' => 'JNM,VSK,4Z-US-N',
            'STU010000' => 'JNM,VSK',
            'STU011000' => 'KFCX,4CPC',
            'STU028000' => 'YPZ,4LE',
            'STU031000' => 'JNKG,VSK',
            'STU013000' => 'JNDH,KJBX,4Z-US-D',
            'STU015000' => 'JNM,VSK,4CTM',
            'STU016000' => 'JNDH,JNM,4Z-US-E',
            'STU025000' => 'YPZ,4Z-US-M',
            'STU012000' => 'YPZ,4Z-US-C',
            'STU017000' => 'LX,4Z-US-F',
            'STU018000' => 'JNDH,JNM',
            'STU032000' => 'MRG,4Z-US-G',
            'STU035000' => 'MQC,MRG,4Z-US-J',
            'STU021000' => 'JNZ,4CP',
            'STU033000' => 'YPZ,4Z-US-H',
            'STU022000' => 'YPZ,JNLC,1KBB-US-NAK',
            'STU024000' => 'YPZ,4Z-US-A',
            'STU036000' => 'JNZ,YPWL2',
            'STU026000' => 'YPZ',
            'STU019000' => 'JNDH,JNKH,4Z-US-I',
            'STU027000' => 'YPZ',
            'STU037000' => 'MRG,1KBB,4CPC',
            'STU029000' => 'JNDH,JNR,4CP',
            'TEC000000' => 'TB',
            'TEC001000' => 'TTA',
            'TEC002000' => 'TRP,TTDS',
            'TEC003000' => 'TV',
            'TEC003080' => 'TVK',
            'TEC003030' => 'TVK',
            'TEC003060' => 'TVBP',
            'TEC003020' => 'TVH',
            'TEC003100' => 'TVHH',
            'TEC003110' => 'TVSW',
            'TEC003040' => 'TVR',
            'TEC003050' => 'TVDR',
            'TEC003090' => 'TVG',
            'TEC003070' => 'TVF',
            'TEC003010' => 'TVQ',
            'TEC003120' => 'TVU',
            'TEC004000' => 'TJFM',
            'TEC009090' => 'TRC',
            'TEC059000' => 'MQW,TCB',
            'TEC048000' => 'RGV',
            'TEC009010' => 'TDC',
            'TEC009020' => 'TN',
            'TEC009100' => 'TNCJ',
            'TEC009110' => 'TNF',
            'TEC009120' => 'TNCE',
            'TEC009130' => 'TNFL',
            'TEC009140' => 'TNH',
            'TEC009150' => 'TNCC',
            'TEC009160' => 'TR',
            'TEC005000' => 'TNK,TNT',
            'TEC005010' => 'TNTC',
            'TEC005020' => 'TNKP,TNT',
            'TEC005030' => 'THRX',
            'TEC005040' => 'TNKP,TNT',
            'TEC005050' => 'TNKH',
            'TEC005060' => 'TNTB',
            'TEC005070' => 'TNTP',
            'TEC005080' => 'TNTR',
            'TEC071000' => 'TJK',
            'TEC071010' => 'TJK',
            'TEC006000' => 'TBG',
            'TEC007000' => 'THR',
            'TEC008000' => 'TJF',
            'TEC008010' => 'TJFC',
            'TEC008020' => 'TJFC',
            'TEC008030' => 'TJFC',
            'TEC008050' => 'TJFC',
            'TEC008060' => 'TJFC',
            'TEC008070' => 'TJFD',
            'TEC008080' => 'TGMM',
            'TEC008090' => 'TJFD',
            'TEC008100' => 'TJF',
            'TEC008110' => 'TJFD',
            'TEC065000' => 'JKSW',
            'TEC009000' => 'TBC',
            'TEC010000' => 'TQ',
            'TEC010010' => 'TQK',
            'TEC010020' => 'TQSR,RNH',
            'TEC010030' => 'TQSW',
            'TEC074000' => 'TTP',
            'TEC011000' => 'TTBF',
            'TEC045000' => 'TNKF',
            'TEC049000' => 'TVT',
            'TEC012000' => 'TDCT',
            'TEC012010' => 'PND',
            'TEC012020' => 'TDCT2',
            'TEC012030' => 'TDCT1',
            'TEC012040' => 'TDCT',
            'TEC013000' => 'TGMD',
            'TEC056000' => 'TBX',
            'TEC050000' => 'TTBL',
            'TEC014000' => 'TGMF2',
            'TEC015000' => 'TTBM',
            'TEC016000' => 'TBD,AK',
            'TEC016010' => 'TBD,AK',
            'TEC016020' => 'TBD,AKP',
            'TEC009060' => 'TGP',
            'TEC017000' => 'KNXC',
            'TEC018000' => 'TD',
            'TEC057000' => 'TBY',
            'TEC019000' => 'TTBL',
            'TEC046000' => 'TGB',
            'TEC020000' => 'TD',
            'TEC060000' => 'TTS',
            'TEC021000' => 'TGM',
            'TEC021010' => 'TDCQ',
            'TEC021020' => 'TGMM',
            'TEC021030' => 'TGM',
            'TEC021040' => 'TGMS',
            'TEC022000' => 'TBM',
            'TEC009070' => 'TGB',
            'TEC023000' => 'TDPM',
            'TEC024000' => 'TJFN',
            'TEC025000' => 'TTM',
            'TEC026000' => 'TTU',
            'TEC061000' => 'TJKT1,TJKW',
            'TEC027000' => 'TBN',
            'TEC029000' => 'TBC,KJM',
            'TEC030000' => 'TTB',
            'TEC058000' => 'TVP',
            'TEC047000' => 'THFP,KNB',
            'TEC072000' => 'TDCW',
            'TEC031000' => 'TH',
            'TEC031010' => 'THV',
            'TEC031020' => 'THY',
            'TEC031030' => 'THF',
            'TEC028000' => 'THK',
            'TEC062000' => 'TB,KJMP',
            'TEC032000' => 'TGPQ',
            'TEC033000' => 'TJKD',
            'TEC034000' => 'TJKR',
            'TEC035000' => 'TB,GBC',
            'TEC036000' => 'RGW',
            'TEC066000' => 'PDG',
            'TEC037000' => 'TJFM1',
            'TEC064000' => 'TJS',
            'TEC067000' => 'TJKH,UYS',
            'TEC052000' => 'PDR',
            'TEC063000' => 'TNC',
            'TEC039000' => 'TJFD',
            'TEC054000' => 'TNCB',
            'TEC073000' => 'TBC',
            'TEC040000' => 'KND',
            'TEC044000' => 'TB,CBW',
            'TEC041000' => 'TJK',
            'TEC043000' => 'TJKV',
            'TEC055000' => 'TGMP,TDPF,TDCP',
            'TEC070000' => 'TDPT',
            'TEC068000' => 'TGBF',
            'TEC069000' => 'TGX',
            'TRA000000' => 'WG',
            'TRA001000' => 'WGC',
            'TRA001010' => 'WGC,WC',
            'TRA001020' => 'WGC,VSG',
            'TRA001030' => 'WGCV',
            'TRA001080' => 'VSF',
            'TRA001050' => 'WGC',
            'TRA001060' => 'WGC,AJ',
            'TRA001140' => 'WGCV,TRCS',
            'TRA001150' => 'WGC',
            'TRA002000' => 'WGM',
            'TRA002040' => 'WGM',
            'TRA002010' => 'WGM',
            'TRA002050' => 'TRPS',
            'TRA002030' => 'WGM,WGCV',
            'TRA010000' => 'WGD',
            'TRA003000' => 'WGCK',
            'TRA003010' => 'WGCK',
            'TRA003020' => 'WGCK,AJ',
            'TRA003030' => 'WGCK,WGCV',
            'TRA008000' => 'TRLN',
            'TRA009000' => 'WGCF,WGFL',
            'TRA004000' => 'WGF',
            'TRA004010' => 'WGF',
            'TRA004020' => 'WGF,AJ',
            'TRA006000' => 'WGG,TRL',
            'TRA006010' => 'WGG',
            'TRA006020' => 'WGG,AJ',
            'TRA006030' => 'WGGV',
            'TRA006040' => 'WGG',
            'TRV000000' => 'WT',
            'TRV002000' => 'WT,1H',
            'TRV002010' => 'WT,1HFJ',
            'TRV002020' => 'WT,1HFG',
            'TRV002030' => 'WT,1HFGK',
            'TRV002050' => 'WT,1HB',
            'TRV002040' => 'WT,1HBM',
            'TRV002070' => 'WT,1HFM',
            'TRV002060' => 'WT,1HFMS',
            'TRV002080' => 'WT,1HFD',
            'TRV003000' => 'WT,1F',
            'TRV003010' => 'WT,1FC',
            'TRV003030' => 'WT,1FP',
            'TRV003020' => 'WT,1FPC',
            'TRV003050' => 'WT,1FPJ',
            'TRV003080' => 'WT,1FPK',
            'TRV003090' => 'WT,1FPM',
            'TRV003100' => 'WT,1FPCW',
            'TRV003040' => 'WT,1FK',
            'TRV003060' => 'WT,1FM',
            'TRV003070' => 'WT,1FB',
            'TRV004000' => 'WT,1M',
            'TRV006000' => 'WT,1KBC',
            'TRV006010' => 'WT,1QF-CA-A',
            'TRV006040' => 'WT,1QF-CA-T',
            'TRV006020' => 'WT,1KBC-CA-O',
            'TRV006030' => 'WT,1KBC-CA-C,1KBC-CA-S',
            'TRV006060' => 'WT,1KBC-CA-Q',
            'TRV006050' => 'WT,1KBC-CA-A,1KBC-CA-B',
            'TRV007000' => 'WT,1KJ',
            'TRV008000' => 'WT,1KLC',
            'TRV010000' => 'WTL',
            'TRV009000' => 'WT,1D',
            'TRV009010' => 'WT,1DFA',
            'TRV009020' => 'WT,1DDB,1DDN,1DDL',
            'TRV009160' => 'WT,1DXY',
            'TRV009040' => 'WT,1DT',
            'TRV009050' => 'WT,1DDF',
            'TRV009060' => 'WT,1DFG',
            'TRV009070' => 'WT,1DDU',
            'TRV009080' => 'WT,1DXG',
            'TRV009100' => 'WT,1DDR',
            'TRV009110' => 'WT,1DST',
            'TRV009120' => 'WT,1DN',
            'TRV009030' => 'WT,1DND',
            'TRV009170' => 'WT,1DNF',
            'TRV009090' => 'WT,1DNC,1MTNG',
            'TRV009180' => 'WT,1DNN',
            'TRV009190' => 'WT,1DNS',
            'TRV009130' => 'WT,1DSE,1DSP',
            'TRV009140' => 'WT,1DFH',
            'TRV009150' => 'WT,1DD',
            'TRV036000' => 'WTHH',
            'TRV005000' => 'WTHH',
            'TRV028000' => 'WTHX',
            'TRV013000' => 'WTHH',
            'TRV035000' => 'WTHV',
            'TRV030000' => 'WTHH',
            'TRV022000' => 'WTHR',
            'TRV031000' => 'WTH,WGC',
            'TRV012000' => 'WT,1DT',
            'TRV027000' => 'WTR',
            'TRV014000' => 'WT,1KLCM',
            'TRV015000' => 'WT,1FB',
            'TRV015010' => 'WT,1HBE',
            'TRV015020' => 'WT,1FBH',
            'TRV015030' => 'WT,1DTT',
            'TRV016000' => 'WTHM',
            'TRV018000' => 'WTHH1',
            'TRV019000' => 'WTM',
            'TRV020000' => 'WT,1QMP',
            'TRV021000' => 'WT,GBC',
            'TRV023000' => 'WT,1DTA',
            'TRV024000' => 'WT,1KLS',
            'TRV024010' => 'WT,1KLSA',
            'TRV024020' => 'WT,1KLSB',
            'TRV024030' => 'WT,1KLSH,1MKPE',
            'TRV024040' => 'WT,1KLSE,1KLZTG',
            'TRV024050' => 'WT,1KLSR',
            'TRV026000' => 'WTH',
            'TRV001000' => 'WTHA',
            'TRV029000' => 'WTHT',
            'TRV026100' => 'WTHE,SZD',
            'TRV033000' => 'WTHG',
            'TRV026010' => 'WTHB',
            'TRV026120' => 'WTHD',
            'TRV026030' => 'WTH,VFJD',
            'TRV026020' => 'WTHC',
            'TRV011000' => 'WTHF',
            'TRV026130' => 'WTH,VXQ',
            'TRV034000' => 'WTHW,SZC',
            'TRV026070' => 'WTH,5PS',
            'TRV026090' => 'WTHM',
            'TRV026110' => 'WTHM,JWT',
            'TRV026040' => 'WTH,WNG',
            'TRV026060' => 'WTH,QRVP,5PG',
            'TRV026140' => 'WTH',
            'TRV026050' => 'WTH,5LKS',
            'TRV032000' => 'WTH,WJS',
            'TRV026080' => 'WTHE,SC',
            'TRV025000' => 'WT,1KBB',
            'TRV025010' => 'WT,1KBB-US-M',
            'TRV025020' => 'WT,1KBB-US-ML',
            'TRV025030' => 'WT,1KBB-US-MP',
            'TRV025040' => 'WT,1KBB-US-N',
            'TRV025050' => 'WT,1KBB-US-NA',
            'TRV025060' => 'WT,1KBB-US-NE',
            'TRV025070' => 'WT,1KBB-US-S',
            'TRV025080' => 'WT,1KBB-US-SC',
            'TRV025090' => 'WT,1KBB-US-SE',
            'TRV025100' => 'WT,1KBB-US-SW',
            'TRV025110' => 'WT,1KBB-US-W',
            'TRV025120' => 'WT,1KBB-US-WM',
            'TRV025130' => 'WT,1KBB-US-WP',
            'TRU000000' => 'DNXC',
            'TRU006000' => 'DNXC',
            'TRU004000' => 'DNXC,JBG',
            'TRU011000' => 'DNXC,LNQE',
            'TRU001000' => 'DNXC,JPSH',
            'TRU007000' => 'DNXC,JKVF1',
            'TRU008000' => 'DNXC',
            'TRU010000' => 'DNXC',
            'TRU002000' => 'DNXC3',
            'TRU002020' => 'DNXC3',
            'TRU002010' => 'DNXC3',
            'TRU003000' => 'DNXC,JKVM',
            'TRU009000' => 'DNXC,JBFK2',
            'TRU005000' => 'DNXC,JKVK',
            'YAF000000' => 'YFB,5AN',
            'YAF001000' => 'YFC,5AN',
            'YAF001010' => 'YFC,YNHA1,5AN',
            'YAF001020' => 'YFC,5AN',
            'YAF071000' => 'YFE,5AN',
            'YAF002000' => 'YFP,5AN',
            'YAF002010' => 'YFP,YNNJ24,5AN',
            'YAF002020' => 'YFP,YNNS,5AN',
            'YAF002030' => 'YFH,YNXB,5AN',
            'YAF002040' => 'YFP,YNNH,5AN',
            'YAF004000' => 'YFB,YNA,5AN',
            'YAF005000' => 'YFX,5AN',
            'YAF006000' => 'YFB,YNL,5AN',
            'YAF007000' => 'YFB,YNMH,5AN',
            'YAF008000' => 'YFB,YNK,5AN',
            'YAF009000' => 'YFA,5AN',
            'YAF072000' => 'YFB,5AN',
            'YAF010000' => 'XQ,YFB,5AN',
            'YAF010050' => 'XQG,YFC,5AN',
            'YAF010060' => 'XQB,YFA,5AN',
            'YAF010070' => 'XQ,YFB,YXW,5AN',
            'YAF010180' => 'XQ,YFB,YXP,5AN',
            'YAF010080' => 'XQL,YFE,5AN',
            'YAF010090' => 'XQM,YFJ,5AN',
            'YAF010100' => 'XQM,YFH,5AN',
            'YAF010110' => 'XQV,YFT,5AN',
            'YAF010120' => 'XQH,YFD,5AN',
            'YAF010130' => 'XQT,YFQ,5AN',
            'YAF010140' => 'XQ,YFB,YXB,5AN,5PS',
            'YAF035000' => 'YFZS,5AN',
            'YAF010010' => 'XAM,YFB,5AN',
            'YAF010020' => 'XQ,YFB,5AN',
            'YAF010150' => 'XQD,YFCF,5AN',
            'YAF010160' => 'XQM,YFH,5AN',
            'YAF010170' => 'XQR,YFM,5AN',
            'YAF010030' => 'XQL,YFG,5AN',
            'YAF010040' => 'XQK,YFG,5AN',
            'YAF011000' => 'YFB,YXW,5AN',
            'YAF012000' => 'YFB,YNTC,5AN',
            'YAF013000' => 'YFB,YNPC,5AN',
            'YAF058070' => 'YFB,YXK,5AN',
            'YAF014000' => 'YFB,YXP,5AN,5PB',
            'YAF015000' => 'YFE,5AN',
            'YAF016000' => 'YFB,5AN',
            'YAF017000' => 'YFJ,5AN',
            'YAF017010' => 'YFJ,5AN',
            'YAF017020' => 'YFJ,YDC,5AN',
            'YAF017030' => 'YFJ,5AN',
            'YAF018000' => 'YFN,YXF,5AN',
            'YAF018010' => 'YFN,YXFF,5AN',
            'YAF018020' => 'YFN,YXF,5AN',
            'YAF018080' => 'YFN,YXF,5AN',
            'YAF018030' => 'YFN,YXF,YXFD,5AN',
            'YAF018040' => 'YFN,YXF,5AN',
            'YAF018050' => 'YFN,YXFF,5AN',
            'YAF018060' => 'YFN,YXF,5AN',
            'YAF018070' => 'YFN,YXFR,5AN',
            'YAF019000' => 'YFH,5AN',
            'YAF019010' => 'YFHW,5AN',
            'YAF019020' => 'YFHT,5AN',
            'YAF019030' => 'YFHB,5AN',
            'YAF019040' => 'YFHH,YFT,5AN',
            'YAF019060' => 'YFHR,5AN',
            'YAF019050' => 'YFH,YNXW,5AN',
            'YAF020000' => 'YFB,YNPJ,5AN',
            'YAF021000' => 'YFD,5AN',
            'YAF022000' => 'YFB,YNMF,5AN',
            'YAF023000' => 'YFB,YXA,5AN',
            'YAF023010' => 'YFB,YXL,5AN',
            'YAF024000' => 'YFT,5AN',
            'YAF024010' => 'YFT,1H,5AN',
            'YAF024020' => 'YFT,1QBA,5AN',
            'YAF024030' => 'YFT,1F,5AN',
            'YAF024040' => 'YFT,1KBC,5AN',
            'YAF024050' => 'YFT,1D,5AN',
            'YAF024060' => 'YFT,YNHD,5AN',
            'YAF024070' => 'YFT,3MPBGJ-DE-H,5AN,5PGJ',
            'YAF024080' => 'YFT,3KH,3KL,5AN',
            'YAF024090' => 'YFT,1FB,5AN',
            'YAF024100' => 'YFT,YNJ,5AN',
            'YAF024110' => 'YFT,3B,5AN',
            'YAF024120' => 'YFT,3KLY,5AN',
            'YAF024130' => 'YFT,1KBB,5AN',
            'YAF024140' => 'YFT,1KBB,3MLQ-US-B,3MLQ-US-C,5AN',
            'YAF024150' => 'YFT,1KBB,3MN,5AN',
            'YAF024160' => 'YFT,1KBB,3MNQ-US-E,3MNB-US-D,5AN',
            'YAF024170' => 'YFT,1KBB,3MP,5AN',
            'YAF024180' => 'YFT,1KBB,3MR,5AN',
            'YAF025000' => 'YFB,YNMD,5AN,5HC',
            'YAF026000' => 'YFD,5AN',
            'YAF027000' => 'YFQ,5AN',
            'YAF027010' => 'YFQ,5AN',
            'YAF028000' => 'YFCA,5AN',
            'YAF029000' => 'YFCF,YNKC,5AN',
            'YAF030000' => 'YFJ,5AN',
            'YAF030010' => 'YFJ,5AN',
            'YAF030020' => 'YFJ,1QBAR,1QBAG,5AN',
            'YAF031000' => 'YFB,YXB,5AN,5PS',
            'YAF032000' => 'YFB,YNMK,5AN',
            'YAF033000' => 'YFB,YNML,5AN',
            'YAF034000' => 'YFB,YNML,5AN',
            'YAF036000' => 'YFB,5AN',
            'YAF037000' => 'YFB,YX,5AN',
            'YAF038000' => 'YFHD,5AN',
            'YAF039000' => 'YFB,5AN',
            'YAF040000' => 'YFH,YNXB6,5AN',
            'YAF041000' => 'YFH,YNXB,5AN',
            'YAF042000' => 'YFCF,5AN',
            'YAF074000' => 'YFB,YXP,5AN,5PM',
            'YAF044000' => 'YFV,5AN',
            'YAF073000' => 'YFB,5AN,5P',
            'YAF045000' => 'YFH,YNX,5AN',
            'YAF046000' => 'YFB,YNM,5AN',
            'YAF046020' => 'YFB,YNM,1H,5AN',
            'YAF046030' => 'YFB,YNM,1F,5AN',
            'YAF046040' => 'YFB,YNM,1M,5AN',
            'YAF046050' => 'YFB,YNM,1KBC,5AN',
            'YAF046060' => 'YFB,YNM,1KJ,1KL,5AN',
            'YAF046070' => 'YFB,YNM,1D,5AN',
            'YAF046010' => 'YFB,YNM,5AN,5PBA',
            'YAF046080' => 'YFB,YNM,1KLCM,5AN',
            'YAF046090' => 'YFB,YNM,1FB,5AN',
            'YAF046100' => 'YFB,YNM,1QMP,5AN',
            'YAF046110' => 'YFB,YNM,1KBB,5AN',
            'YAF046120' => 'YFB,YNM,1KBB,5AN,5PB-US-C',
            'YAF046130' => 'YFB,YNM,1KBB,5AN,5PB-US-D',
            'YAF046140' => 'YFB,YNM,1KBB,5AN,5PB-US-H',
            'YAF046160' => 'YFB,YNM,1KBB,5AN',
            'YAF046150' => 'YFB,YNM,1KBB,5AN,5PB-US-E',
            'YAF047000' => 'YFB,YND,5AN',
            'YAF047010' => 'YFB,YNDB,5AN',
            'YAF047020' => 'YFB,YNF,5AN',
            'YAF047030' => 'YFB,YNC,5AN',
            'YAF047040' => 'YFB,YNF,5AN',
            'YAF047050' => 'YFB,YND,5AN',
            'YAF048000' => 'YFB,YDP,5AN',
            'YAF049000' => 'YFB,YNKA,5AN',
            'YAF050000' => 'YFB,YXZG,5AN',
            'YAF051000' => 'YFK,5AN',
            'YAF051010' => 'YFK,YXZR,5AN',
            'YAF051020' => 'YFK,5AN,5PGF',
            'YAF051030' => 'YFK,5AN,5PGM',
            'YAF051040' => 'YFK,YFC,5AN,5PGM',
            'YAF051050' => 'YFK,XQW,5AN,5PGM',
            'YAF051060' => 'YFK,YFH,5AN,5PGM',
            'YAF051070' => 'YFK,YFT,5AN,5PGM',
            'YAF051080' => 'YFK,YFCF,5AN,5PGM',
            'YAF051090' => 'YFK,YFM,5AN,5PGM',
            'YAF051100' => 'YFK,YFG,5AN,5PGM',
            'YAF051110' => 'YFK,YXZ,5AN,5PGM',
            'YAF051120' => 'YFK,5AN,5PGD',
            'YAF051130' => 'YFK,5AN,5PGJ',
            'YAF051140' => 'YFK,5AN,5PGP',
            'YAF052000' => 'YFMR,5AN',
            'YAF052010' => 'YFMR,5AN',
            'YAF052020' => 'YFMR,5AN',
            'YAF052030' => 'YFMR,YFT,5AN',
            'YAF052040' => 'YFMR,YXB,5AN,5PS',
            'YAF052070' => 'YFMR,YXP,5AN,5PB',
            'YAF052050' => 'YFHR,5AN',
            'YAF052060' => 'YFMR,YFQ,5AN',
            'YAF053000' => 'YFB,YNMW,5AN',
            'YAF027020' => 'YFQ,5AN',
            'YAF054000' => 'YFS,5AN',
            'YAF054010' => 'YFS,5AN',
            'YAF054020' => 'YFS,5AN',
            'YAF043000' => 'YFP,YNT,YNN,5AN',
            'YAF043010' => 'YFP,YXZG,5AN',
            'YAF056000' => 'YFG,5AN',
            'YAF056010' => 'YFG,YNXF,5AN',
            'YAF003000' => 'YFE,5AN',
            'YAF056030' => 'YFG,YFM,5AN',
            'YAF056020' => 'YFG,5AN',
            'YAF063000' => 'YFG,5AN',
            'YAF057000' => 'YFU,YDC,5AN',
            'YAF058000' => 'YFB,YX,5AN',
            'YAF058280' => 'YFB,YXZB,5AN',
            'YAF058010' => 'YFB,YXN,5AN',
            'YAF058020' => 'YFB,YXQF,5AN',
            'YAF058030' => 'YFB,YXZ,5AN',
            'YAF058230' => 'YFB,YXLD2,5AN',
            'YAF058040' => 'YFB,YXHL,5AN',
            'YAF058050' => 'YFB,YXG,5AN',
            'YAF058060' => 'YFB,YXK,5AN',
            'YAF058080' => 'YFB,YXJ,5AN',
            'YAF058090' => 'YFB,YXLD1,5AN',
            'YAF058100' => 'YFB,YXZM,5AN',
            'YAF058110' => 'YFB,YXE,5AN',
            'YAF058120' => 'YFMF,5AN',
            'YAF058140' => 'YFB,YXLD,5AN',
            'YAF058150' => 'YFB,YXW,5AN',
            'YAF058160' => 'YFB,YXQ,5AN',
            'YAF058170' => 'YFB,YXQD,5AN',
            'YAF058130' => 'YFB,YXZH,5AN',
            'YAF058180' => 'YFB,YXHY,5AN',
            'YAF058190' => 'YFB,YXPB,YXN,5AN',
            'YAF058200' => 'YFB,YXZR,5AN',
            'YAF058210' => 'YFB,YXS,5AN',
            'YAF058220' => 'YFB,YXD,5AN',
            'YAF058240' => 'YFB,YXQD,5AN',
            'YAF058250' => 'YFB,YXGS,5AN',
            'YAF058260' => 'YFB,YX,5AN',
            'YAF058270' => 'YFB,YXQ,5AN',
            'YAF059000' => 'YFR,5AN',
            'YAF059010' => 'YFR,YNWD3,5AN',
            'YAF059020' => 'YFR,YNWD4,5AN',
            'YAF059030' => 'YFR,YNWP,5AN',
            'YAF059040' => 'YFR,YNNJ24,5AN',
            'YAF059050' => 'YFR,5AN',
            'YAF059060' => 'YFR,YNWD2,5AN',
            'YAF059070' => 'YFR,YNWG,5AN',
            'YAF059080' => 'YFR,YNWM2,5AN',
            'YAF059090' => 'YFR,YNWJ,5AN',
            'YAF059100' => 'YFR,YNWY,5AN',
            'YAF059110' => 'YFR,YNWD1,5AN',
            'YAF059120' => 'YFR,YNWG,5AN',
            'YAF059130' => 'YFR,YNWW,5AN',
            'YAF059140' => 'YFR,YNWM,5AN',
            'YAF060000' => 'YFGS,5AN',
            'YAF061000' => 'YFF,5AN',
            'YAF055000' => 'YFB,YNT,5AN',
            'YAF062000' => 'YFCB,5AN',
            'YAF062010' => 'YFCB,YFCF,5AN',
            'YAF062020' => 'YFCB,5AN',
            'YAF062030' => 'YFCB,5AN',
            'YAF062040' => 'YFCB,5AN',
            'YAF064000' => 'YFB,5AN',
            'YAF064010' => 'YFB,5AN',
            'YAF065000' => 'YFD,YNXB2,5AN',
            'YAF066000' => 'YFH,5AN',
            'YAF067000' => 'YFC,YNJ,5AN',
            'YAF068000' => 'YFD,YNXB2,5AN',
            'YAF069000' => 'YFC,1KBB-US-W,5AN',
            'YAF070000' => 'YFD,YNXB3,5AN',
            'YAN000000' => 'YN,5AN',
            'YAN058000' => 'YXZB,5AN',
            'YAN001000' => 'YBG,5AN',
            'YAN002000' => 'YNHA,5AN',
            'YAN003000' => 'YNN,5AN',
            'YAN003010' => 'YNN,5AN',
            'YAN003020' => 'YNNK,5AN',
            'YAN003030' => 'YNNS,5AN',
            'YAN004000' => 'YNTP,5AN',
            'YAN005000' => 'YNA,5AN',
            'YAN005010' => 'YNA,YNUC,5AN',
            'YAN005020' => 'YNA,5AN',
            'YAN005030' => 'YNPJ,5AN',
            'YAN005040' => 'YNA,5AN',
            'YAN005050' => 'YNA,5AN',
            'YAN005060' => 'YNA,5AN',
            'YAN005070' => 'YNA,5AN',
            'YAN006000' => 'YNB,5AN',
            'YAN006010' => 'YNB,YNA,5AN',
            'YAN006020' => 'YNB,5AN,5PB',
            'YAN006030' => 'YNB,YNH,5AN',
            'YAN006150' => 'YNB,YXB,5AN,5PS',
            'YAN006040' => 'YNB,YNL,5AN',
            'YAN006050' => 'YNB,YNC,5AN',
            'YAN006060' => 'YNB,YND,5AN',
            'YAN006070' => 'YNB,YNKA,5AN',
            'YAN006080' => 'YNB,YNKA,1KBB,5AN',
            'YAN006090' => 'YNB,YNR,5AN',
            'YAN006100' => 'YNB,YNMW,5AN',
            'YAN006110' => 'YNB,YNT,5AN',
            'YAN006120' => 'YNB,YXZ,5AN',
            'YAN006130' => 'YNB,YNW,5AN',
            'YAN006140' => 'YNB,YNMF,5AN',
            'YAN008000' => 'YNL,5AN',
            'YAN009000' => 'YNMH,5AN',
            'YAN010000' => 'YPJV,5AN',
            'YAN011000' => 'YXV,5AN',
            'YAN012000' => 'XQA,YN,5AN',
            'YAN012010' => 'XQA,YNB,5AN',
            'YAN012020' => 'XQA,YNH,5AN',
            'YAN012030' => 'XQA,YNT,YNN,5AN',
            'YAN012040' => 'XQA,YX,5AN',
            'YAN013000' => 'YNTC,5AN',
            'YAN013030' => 'YNTC1,5AN',
            'YAN013010' => 'YNVU,5AN',
            'YAN013020' => 'YNTC2,5AN',
            'YAN013040' => 'YNTC,5AN',
            'YAN014000' => 'YNPC,5AN',
            'YAN015000' => 'YNPH,5AN',
            'YAN016000' => 'YNG,YNX,5AN',
            'YAN051230' => 'YXK,5AN',
            'YAN017000' => 'YND,YNDS,5AN',
            'YAN018000' => 'YXF,5AN',
            'YAN018010' => 'YXFF,5AN',
            'YAN018020' => 'YXF,5AN',
            'YAN018080' => 'YXF,5AN',
            'YAN018030' => 'YXF,YXFD,5AN',
            'YAN018040' => 'YXF,5AN',
            'YAN018050' => 'YXFF,5AN',
            'YAN018060' => 'YXF,5AN',
            'YAN018070' => 'YXFR,5AN',
            'YAN019000' => 'YNPJ,5AN',
            'YAN020000' => 'YRDM,5AN',
            'YAN020010' => 'YRDM,2ACB,4LE,5AN',
            'YAN020020' => 'YRDM,2ADF,5AN',
            'YAN020030' => 'YRDM,2ADS,5AN',
            'YAN021000' => 'YNV,5AN',
            'YAN021010' => 'YNVP,5AN',
            'YAN021020' => 'YNG,5AN',
            'YAN022000' => 'YNPG,5AN',
            'YAN023000' => 'YNMF,5AN',
            'YAN024000' => 'YXA,5AN',
            'YAN024010' => 'YXA,5AN',
            'YAN024020' => 'YXAB,5AN',
            'YAN024030' => 'YXLB,5AN',
            'YAN024040' => 'YXAB,5AN',
            'YAN024050' => 'YXA,YXW,5AN',
            'YAN024100' => 'YXLD,5AN',
            'YAN024090' => 'YXLD6,5AN',
            'YAN024060' => 'YXK,5AN',
            'YAN024070' => 'YXR,5AN',
            'YAN024080' => 'YXAX,YXHY,5AN',
            'YAN025000' => 'YNH,5AN',
            'YAN025010' => 'YNH,1H,5AN',
            'YAN025020' => 'YNH,1QBA,5AN',
            'YAN025030' => 'YNH,1F,5AN',
            'YAN025040' => 'YNH,1M,5AN',
            'YAN025050' => 'YNH,1KBC,5AN',
            'YAN025060' => 'YNH,1KL,5AN',
            'YAN025070' => 'YNH,1D,5AN',
            'YAN025080' => 'YNHD,5AN',
            'YAN025090' => 'YNH,3MPBGJ-DE-H,5AN,5PGJ',
            'YAN025100' => 'YNH,3KH,3KL,5AN',
            'YAN025110' => 'YNH,1KLCM,5AN',
            'YAN025120' => 'YNH,1FB,5AN',
            'YAN025130' => 'YNJ,5AN',
            'YAN025140' => 'YNH,5AN',
            'YAN025150' => 'YNH,3B,5AN',
            'YAN025160' => 'YNH,3KLY,5AN',
            'YAN025170' => 'YNH,1KBB,5AN',
            'YAN025180' => 'YNH,1KBB,5AN',
            'YAN025190' => 'YNH,1KBB,3MLQ-US-B,5AN',
            'YAN025200' => 'YNH,1KBB,3MN,5AN',
            'YAN025210' => 'YNH,1KBB,3MNQ-US-E,3MNB-US-D,5AN',
            'YAN025220' => 'YNH,1KBB,3MP,5AN',
            'YAN025230' => 'YNH,1KBB,3MR,5AN',
            'YAN026000' => 'YNMD,5AN,5HC',
            'YAN027000' => 'YNP,5AN',
            'YAN028000' => 'YNU,5AN',
            'YAN029000' => 'YX,5AN',
            'YAN030000' => 'YPC,5AN',
            'YAN030010' => 'YPC,5AN',
            'YAN030020' => 'YPCA2,5AN',
            'YAN030030' => 'YPCA4,5AN',
            'YAN030040' => 'YPCA2,5AN',
            'YAN030050' => 'YPCA23,5AN',
            'YAN031000' => 'YNKC,5AN',
            'YAN032000' => 'YXB,5AN,5PS',
            'YAN033000' => 'YNL,5AN',
            'YAN034000' => 'YPMF,5AN',
            'YAN034010' => 'YPMF,5AN',
            'YAN034020' => 'YPMF,5AN',
            'YAN035000' => 'YPJK,5AN',
            'YAN036000' => 'YN,5AN',
            'YAN037000' => 'YNC,5AN',
            'YAN037010' => 'YNC,YPAD,5AN',
            'YAN037020' => 'YNC,YPAD,5AN',
            'YAN037030' => 'YNC,5AN,6PB',
            'YAN037040' => 'YNC,5AN,6RJ',
            'YAN037050' => 'YNC,5AN,6RF',
            'YAN059000' => 'YXP,5AN,5PM',
            'YAN007000' => 'YNX,5AN',
            'YAN038000' => 'YNM,5AN',
            'YAN038020' => 'YNM,1H,5AN',
            'YAN038030' => 'YNM,1F,5AN',
            'YAN038040' => 'YNM,1M,5AN',
            'YAN038050' => 'YNM,1KBC,5AN',
            'YAN038060' => 'YNM,1KJ,1KL,5AN',
            'YAN038070' => 'YNM,1D,5AN',
            'YAN038010' => 'YNM,5AN,5PBA',
            'YAN038080' => 'YNM,1KLCM,5AN',
            'YAN038090' => 'YNM,1FB,5AN',
            'YAN038100' => 'YNM,1KBB,5AN',
            'YAN038110' => 'YNM,1KBB,5AN,5PB-US-C',
            'YAN038120' => 'YNM,1KBB,5AN,5PB-US-D',
            'YAN038130' => 'YNM,1KBB,5AN,5PB-US-H',
            'YAN038150' => 'YNM,1KBB,5AN',
            'YAN038140' => 'YNM,1KBB,5AN,5PB-US-E',
            'YAN039000' => 'YND,5AN',
            'YAN039010' => 'YNDB,5AN',
            'YAN039020' => 'YNF,5AN',
            'YAN039030' => 'YNF,5AN',
            'YAN039040' => 'YND,5AN',
            'YAN040000' => 'YNPK,5AN',
            'YAN041000' => 'YNRA,5AN',
            'YAN042000' => 'YNA,5AN',
            'YAN043000' => 'YDP,5AN',
            'YAN044000' => 'YPCA5,5AN',
            'YAN045000' => 'YXZG,5AN',
            'YAN046000' => 'YR,5AN',
            'YAN047000' => 'YNR,5AN',
            'YAN047010' => 'YNR,5AN',
            'YAN047020' => 'YNRX,5AN',
            'YAN047030' => 'YNRF,5AN',
            'YAN047040' => 'YNRM,5AN,5PGM',
            'YAN047050' => 'YNRR,1FP,5AN',
            'YAN047060' => 'YNRD,5AN,5PGD',
            'YAN047070' => 'YNRP,5AN,5PGP',
            'YAN047080' => 'YNRJ,5AN,5PGJ',
            'YAN048000' => 'YNRM,5AN,5PGM',
            'YAN048010' => 'YNRM,YXHL,5AN,5PGM',
            'YAN048020' => 'YNRM,YNRX,5AN,5PGM',
            'YAN048030' => 'YNRM,YXF,5AN,5PGM',
            'YAN048040' => 'YNRM,5AN,5PGM',
            'YAN049000' => 'YNGL,5AN',
            'YAN050000' => 'YNT,5AN',
            'YAN050010' => 'YNTA,5AN',
            'YAN050020' => 'YNNZ,YPMP51,5AN',
            'YAN050030' => 'YNT,YPMP1,5AN',
            'YAN050040' => 'YNNT,5AN',
            'YAN050050' => 'YNT,YPMP3,5AN',
            'YAN050060' => 'YXZE,5AN',
            'YAN050070' => 'YNNV,YPJT,5AN',
            'YAN050080' => 'YNNV,YPMP6,5AN',
            'YAN050090' => 'YNNC,5AN',
            'YAN050100' => 'YNTD,5AN',
            'YAN050110' => 'YNT,5AN',
            'YAN050120' => 'YNT,YPMP5,5AN',
            'YAN050130' => 'YNN,5AN',
            'YAN052000' => 'YPJJ,5AN',
            'YAN052010' => 'YPJJ,5AN',
            'YAN052020' => 'YNMC,5AN',
            'YAN052030' => 'YNRU,5AN',
            'YAN052040' => 'YNKA,5AN',
            'YAN052050' => 'YPJJ5,5AN',
            'YAN052060' => 'YPJJ,5AN',
            'YAN051000' => 'YX,5AN',
            'YAN051010' => 'YXM,5AN',
            'YAN051020' => 'YXQF,5AN',
            'YAN051270' => 'YXZ,5AN',
            'YAN051030' => 'YXZ,5AN',
            'YAN051280' => 'YX,5AN',
            'YAN051210' => 'YXLD2,5AN',
            'YAN051040' => 'YXHL,5AN',
            'YAN051050' => 'YXG,5AN',
            'YAN051060' => 'YXLD,5AN',
            'YAN051070' => 'YXJ,5AN',
            'YAN051080' => 'YXLD1,5AN',
            'YAN051090' => 'YXZM,5AN',
            'YAN051100' => 'YXE,5AN',
            'YAN051110' => 'YXHB,5AN',
            'YAN051140' => 'YX,5AN',
            'YAN051150' => 'YXQ,5AN',
            'YAN051160' => 'YXQD,5AN',
            'YAN051120' => 'YXZH,5AN',
            'YAN051170' => 'YXHY,5AN',
            'YAN051180' => 'YXPB,YXN,5AN',
            'YAN051190' => 'YXS,5AN',
            'YAN051200' => 'YXD,5AN',
            'YAN051220' => 'YXQD,5AN',
            'YAN051240' => 'YXGS,5AN',
            'YAN051250' => 'YX,5AN',
            'YAN051260' => 'YXQ,5AN',
            'YAN053000' => 'YNW,5AN',
            'YAN053010' => 'YNWD3,5AN',
            'YAN053020' => 'YNWD4,5AN',
            'YAN053030' => 'YNW,5AN',
            'YAN053040' => 'YNW,5AN',
            'YAN053050' => 'YNWD2,5AN',
            'YAN053060' => 'YNWM2,5AN',
            'YAN053070' => 'YNW,5AN',
            'YAN053080' => 'YNW,5AN',
            'YAN053090' => 'YNW,5AN',
            'YAN053100' => 'YNWD1,5AN',
            'YAN053110' => 'YNWG,5AN',
            'YAN053120' => 'YNWM,5AN',
            'YAN054000' => 'YPWL,5AN',
            'YAN054010' => 'YNL,5AN',
            'YAN054020' => 'YPZ,5AN',
            'YAN055000' => 'YPMT,5AN',
            'YAN055010' => 'YPMT,YNNZ,5AN',
            'YAN055020' => 'YPWE,5AN',
            'YAN055030' => 'YPMT5,5AN',
            'YAN055040' => 'YNTD,5AN',
            'YAN055050' => 'YNTG,5AN',
            'YAN055060' => 'YNTC,5AN',
            'YAN056000' => 'YNTR,5AN',
            'YAN056010' => 'YNTR,5AN',
            'YAN056020' => 'YNTR,5AN',
            'YAN056030' => 'YNTR,5AN',
            'YAN057000' => 'YNM,5AN',
            'YAN060000' => 'YXZB,5AN',
            'NON000000' => 'WZS',
        ];

        // Search for exact match
        $bisacCode = array_search(implode(',', $themaCodes), $mapping);

        if (! empty($bisacCode)) {
            return $bisacCode;
        }

        // If still no match found, try the other Thema codes
        foreach ($themaCodes as $themaCode) {
            $bisacCode = array_search($themaCode, $mapping);
            if (! empty($bisacCode)) {
                return $bisacCode;
            }

        }

        return null;
    }
}
