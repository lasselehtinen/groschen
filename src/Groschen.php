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

        // Add ePub version
        switch ($this->product->bindingCode->id) {
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

        // Check if we have spesific technical binding code for ePub accessiblity settings
        $allowedGtins = [
            9789510397923, // Epub2
            9789520472955, // Epub 3 - Fixed layout
            9789528500308, // Epub 3 - Reflowable
        ];

        if (in_array($this->product->isbn, $allowedGtins)) {
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
                $productFormFeatures->push([
                    'ProductFormFeatureType' => '09',
                    'ProductFormFeatureValue' => '00',
                    'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.',
                ]);
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
                $productFormFeatures->push([
                    'ProductFormFeatureType' => '09',
                    'ProductFormFeatureValue' => '00',
                    'ProductFormFeatureDescription' => 'Ulkoasua ei voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.',
                ]);
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
                $productFormFeatures->push([
                    'ProductFormFeatureType' => '09',
                    'ProductFormFeatureValue' => '00',
                    'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, EPUB Accessibility 1.1, Luettavissa ruudunlukuohjelmalla tai pistenäytöllä, Tämä julkaisu noudattaa saavutettavuusstandardien yleisesti hyväksyttyä tasoa, Ei vaaratekijöitä.',
                ]);
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
     * Get the products discount group
     *
     * @return int|null
     */
    public function getDiscountGroup()
    {
        return (empty($this->product->discountNumber)) ? null : $this->product->discountNumber;
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

        // ePubs should have "Accessiblity request contact"
        $allowedGtins = [
            9789510397923, // Epub2
            9789520472955, // Epub 3 - Fixed layout
            9789528500308, // Epub 3 - Reflowable
        ];

        if (in_array($this->product->isbn, $allowedGtins) && in_array($this->getProductType(), ['ePub2', 'ePub3'])) {
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
}
