<?php

namespace lasselehtinen\Groschen;

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
use Isbn;
use kamermans\OAuth2\GrantType\NullGrantType;
use kamermans\OAuth2\OAuth2Middleware;
use lasselehtinen\Groschen\Contracts\ProductInterface;
use League\ISO3166\ISO3166;
use League\OAuth2\Client\Provider\GenericProvider;
use League\Uri\Uri;
use League\Uri\UriModifier;
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
        $productIdentifiers->push([
            'ProductIDType' => '01',
            'id_type_name' => 'Werner Söderström Ltd - Internal product number',
            'id_value' => intval($this->product->isbn),
        ]);

        // GTIN-13 and ISBN-13
        if (! empty($this->product->isbn) && $this->isValidGtin($this->product->isbn)) {
            foreach (['03', '15'] as $id_value) {
                $productIdentifiers->push([
                    'ProductIDType' => $id_value,
                    'id_value' => intval($this->product->isbn),
                ]);
            }
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

        // Headband
        $headBand = $this->getTechnicalData()->where('partName', 'bookBinding')->pluck('headBand')->first();

        if (! empty($headBand)) {
            $productFormDetails->push('B407');
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
        $isbn = new Isbn\Isbn();

        return $isbn->validation->isbn13($gtin);
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

                    return $collection;
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
        if (! empty($this->product->subtitle)) {
            $titleDetails = $titleDetails->map(function ($titleDetail) {
                $titleDetail['TitleElement']['Subtitle'] = $this->product->subtitle;

                return $titleDetail;
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
            // We sort by priority level, sort order and then by the lastname
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
                if (empty($contributor->contact->lastName) && ! empty($contributor->contact->firstName)) {
                    $contributorData['PersonName'] = trim($contributor->contact->firstName);
                    $contributorData['KeyNames'] = trim($contributor->contact->firstName);
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
                        'Website' => (string) $link->value, /** @phpstan-ignore-line */
                    ];
                }
            })->toArray();

            // Add contributor dates
            $contributorData['ContributorDates'] = [];

            if (property_exists($contact, 'birthDay')) {
                $birthDay = DateTime::createFromFormat('!Y-m-d', substr($contact->birthDay, 0, 10));

                array_push($contributorData['ContributorDates'], [
                    'ContributorDateRole' => '50',
                    'Date' => $birthDay->format('Y'),
                    'DateFormat' => '05',
                ]);
            }

            // Add ISNI if exists
            if (property_exists($contact, 'isni')) {
                $contributorData['ISNI'] = $contact->isni;
            }

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
        foreach ($this->getWorkLevel()->originalLanguages as $originalLanguage) {
            $languages->push([
                'LanguageRole' => '02',
                'LanguageCode' => $originalLanguage->id,
            ]);
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
        if (isset($this->product->brand->name) && $this->product->brand->name === 'Johnny Kniga') {
            return 'Johnny Kniga';
        }

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
     * Get the products subjects, like library class, Thema, BIC, BISAC etc.
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

            // BISAC Subject Heading
            $subjects->push(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => $this->getBisacCode()]);

            // BIC subject category
            $subjects->push(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => $this->getBicCode()]);
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

        // Kirjavälitys product group
        $kirjavälitysProductGroup = $this->getKirjavälitysProductGroup();

        if (is_array($kirjavälitysProductGroup)) {
            $subjects->push($kirjavälitysProductGroup);
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

        if ($keywords->count() > 0) {
            $subjects->push(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => $keywords->unique()->implode(';')]);
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
            $now = new DateTime();
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

        // Add hits to collection
        foreach ($hits as $hit) {
            // Check that we have all the required metadata fields
            foreach (array_diff($metadataFields, ['cf_mockingbirdContactId', 'copyright', 'creatorName']) as $requiredMetadataField) {
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
                    'ResourceLink' => $this->getAuthCredUrl($hit->originalUrl),
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
     * @return string
     */
    public function getAuthCredUrl($url)
    {
        // Add authCred to query parameters
        $uri = Uri::createFromString($url);
        $newUri = UriModifier::mergeQuery($uri, 'authcred='.base64_encode(config('groschen.elvis.username').':'.config('groschen.elvis.password')));

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
            // Do not add current product
            if (isset($production->isbn) && $production->isbn !== $this->productNumber) {
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

        // Add other books from the same authors
        foreach ($this->getContributors()->where('ContributorRole', 'A01') as $author) {
            // TODO Add filter just for where role is author aka (roles/any(t: t eq '304'))
            $response = $this->client->get('v2/search/productions', [
                'query' => [
                    'q' => null,
                    'limit' => 999,
                    '$select' => 'id,workId,isbn',
                    '$filter' => "(contactRoles/any(t: t eq '".$author['Identifier']."|304'))",
                ],
            ]);

            $json = json_decode($response->getBody()->getContents());

            foreach ($json->results as $result) {
                // Do not add current products
                if (property_exists($result->document, 'isbn') && $this->productNumber !== $result->document->isbn) {
                    $relatedProducts->push([
                        'ProductRelationCode' => '22',
                        'ProductIdentifiers' => [
                            [
                                'ProductIDType' => '03',
                                'IDValue' => intval($result->document->isbn),
                            ],
                        ],
                    ]);
                }
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
        return floatval(preg_replace('/[^0-9]/', '', $this->product->taxCode->name));
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
     * Returns the BISAC code equivalent for Mockingbird sub-group
     *
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

        if (! array_key_exists($this->product->subGroup->id, $subGroupToBisacMapping)) {
            return null;
        }

        return $subGroupToBisacMapping[$this->product->subGroup->id];
    }

    /**
     * Returns the BIC code equivalent for Mockingbird sub-group
     *
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
            '35' => 'FQ',
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

        if (! array_key_exists($this->product->subGroup->id, $subGroupToBicMapping)) {
            return null;
        }

        return $subGroupToBicMapping[$this->product->subGroup->id];
    }

    /**
     * Return the Kirjavälitys product group
     *
     * @return array|null
     */
    public function getKirjavälitysProductGroup()
    {
        // Product group mapping
        $kirjavälitysProductGroups = [
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

        if (isset($productGroup) && array_key_exists($productGroup, $kirjavälitysProductGroups)) {
            return [
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Kirjavälitys - Tuoteryhmä',
                'SubjectCode' => $productGroup,
                'SubjectHeadingText' => $kirjavälitysProductGroups[$productGroup],
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
        if ($this->product->libraryCodePrefix->id === 'T' && $isPocketBook === false) {
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
        $interestAge = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->filter(function ($subject, $key) {
            return Str::startsWith($subject['SubjectCode'], '5A');
        })->pluck('SubjectCode')->first();

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

        $interestAge = $this->getSubjects()->where('SubjectSchemeIdentifier', '98')->filter(function ($subject, $key) {
            return Str::startsWith($subject['SubjectCode'], '5A');
        })->pluck('SubjectCode')->first();

        $interestAges = [
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

        if (! empty($interestAge) && array_key_exists($interestAge, $interestAges)) {
            $audienceRanges->push([
                'AudienceRangeQualifier' => 17,
                'AudienceRangeScopes' => [
                    [
                        'AudienceRangePrecision' => '03', // From
                        'AudienceRangeValue' => $interestAges[$interestAge],
                    ],
                ],
            ]);
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

        // We currently only support digital products and also if none of sales channels contain true, don't return restriction at
        // all because it means they are not checked
        if ($this->isImmaterial() === false || $distributionChannels->contains('hasRights', true) === false) {
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
            //'hasPhotoSection' => false,
            //'photoSectionExtent' => null,
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
        foreach ($this->product->awards as $award) {
            $prizes->push([
                'PrizeName' => $award->name,
                'PrizeCode' => '01',
            ]);
        }

        // Nominations
        foreach ($this->product->nominations as $nomination) {
            $prizes->push([
                'PrizeName' => $nomination->name,
                'PrizeCode' => '07',
            ]);
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
                        'SupplierIDType' => '01',
                        'IDTypeName' => 'BR-ID',
                        'IDValue' => 10012,
                    ],
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
                'SupplierName' => 'Kirjavälitys',
                'TelephoneNumber' => '+358 10 345 1520',
                'EmailAddress' => 'tilaukset@kirjavalitys.fi',
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
            $response = $client->request('GET', $this->productNumber);
            $json = json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $json = json_decode($response->getBody()->getContents());

            if ($json->data->error_code !== 404 && $json->data->error_message !== 'The model could not be found.') {
                throw new Exception('Could not fetch stock data for GTIN '.$this->productNumber);
            } else {
                // Add default supplier
                $supplierName = 'Kirjavälitys';
                $telephoneNumber = '+358 10 345 1520';
                $emailAddress = 'tilaukset@kirjavalitys.fi';

                // Kirjavälitys identifiers
                $supplierIdentifiers = [
                    [
                        'SupplierIDType' => '01',
                        'IDTypeName' => 'BR-ID',
                        'IDValue' => 10012,
                    ],
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
            // Bokinfo ID
            if (! empty($json->data->stock_location->bokinfo_id)) {
                $supplierIdentifiers[] = [
                    'SupplierIDType' => '01',
                    'IDTypeName' => 'BR-ID',
                    'IDValue' => $json->data->stock_location->bokinfo_id,
                ];
            }

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

        // Check if article text or title contains information about edition type
        $title = $this->getTitleDetails()->where('TitleType', '01')->pluck('TitleElement.TitleText')->first();
        $deliveryNoteTitle = $this->getTitleDetails()->where('TitleType', '10')->pluck('TitleElement.TitleText')->first();

        // Illustrated
        if (Str::contains($title, 'kuvitettu') || Str::contains($deliveryNoteTitle, 'kuvitettu')) {
            $editionTypes->push(['EditionType' => 'ILL']);
        }

        // Movie tie-in
        if (Str::contains($title, 'leffakansi') || Str::contains($deliveryNoteTitle, 'leffakansi')) {
            $editionTypes->push(['EditionType' => 'MDT']);
        }

        // ePub 3 with extra audio
        if ($this->getTechnicalBindingType() === 'EPUB3' && (bool) $this->product->activePrint->ebookHasAudioFile === true) {
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
            return 1.25;
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
            $contentTypes->push([
                'ContentType' => '10',
                'Primary' => true,
            ]);

            // Add audio book as a secondary content type if ePub 3 contains audio
            if ($this->getTechnicalBindingType() === 'EPUB3' && (bool) $this->product->activePrint->ebookHasAudioFile === true) {
                $contentTypes->push([
                    'ContentType' => '01',
                    'Primary' => false,
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
        $currentDate = new DateTime();

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
        $printer = $this->getAllContributors()->where('Role', 'Printer WS')->first();

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
        if (is_array($this->product->countriesExcluded) && count($this->product->countriesExcluded) > 0) {
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

            foreach ($bibliographicCharacters as $bibliographicCharacter) {
                $parts = explode(' ', $bibliographicCharacter);
                $lastname = array_pop($parts);
                $firstname = implode(' ', $parts);

                // Add to collection
                if (! empty($firstname)) {
                    $namesAsSubjects->push([
                        'NameType' => '00',
                        'PersonName' => $bibliographicCharacter,
                        'PersonNameInverted' => $lastname.', '.$firstname,
                        'KeyNames' => $lastname,
                        'NamesBeforeKey' => $firstname,
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
}
