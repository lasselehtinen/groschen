<?php
namespace lasselehtinen\Groschen;

use Cache;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Isbn;
use kamermans\OAuth2\GrantType\NullGrantType;
use kamermans\OAuth2\OAuth2Middleware;
use lasselehtinen\Groschen\Contracts\ProductInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use League\Uri\Modifiers\MergeQuery;
use League\Uri\Modifiers\RemoveQueryKeys;
use League\Uri\Schemes\Http as HttpUri;
use stdClass;

class Groschen implements ProductInterface
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
     * Whether the work level is already fetched
     * @var bool
     */
    private $workLevelFetched;

    /**
     * Guzzle HTTP client
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * Guzzle HTTP client
     * @var \GuzzleHttp\Client
     */
    private $searchClient;

    /**
     * @param string $productNumber
     */
    public function __construct($productNumber)
    {
        // Get access token for Opus
        $accessToken = Cache::remember('accessToken', 59, function () {
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
            'headers' => [
                'User-Agent' => gethostname() . ' / ' . Client::VERSION . ' PHP/' . PHP_VERSION,
            ],
        ]);

        // Create Guzzle and push the OAuth middleware to the handler stack
        $this->searchClient = new Client([
            'base_uri' => config('groschen.opus.search_hostname'),
            'handler' => $stack,
            'auth' => 'oauth',
            'headers' => [
                'User-Agent' => gethostname() . ' / ' . Client::VERSION . ' PHP/' . PHP_VERSION,
            ],
        ]);

        $this->productNumber = $productNumber;
        list($this->workId, $this->productionId) = $this->getEditionAndWorkId();
        $this->product = $this->getProduct();
        $this->workLevelFetched = false;
    }

    /**
     * Get the editions and works id
     * @return array
     */
    public function getEditionAndWorkId()
    {
        // Search for the ISBN in Opus
        $response = $this->client->get('v2/search/productions', [
            'query' => [
                'q' => $this->productNumber,
                'limit' => 1,
                'searchFields' => 'isbn',
                '$select' => 'workId,id',
                '$filter' => '(isCancelled eq true or isCancelled eq false)',
            ],
        ]);

        $json = json_decode($response->getBody()->getContents());

        if (count($json->results) == 0) {
            throw new Exception('Could not find product in Opus.');
        }

        if (count($json->results) > 1) {
            throw new Exception('Too many results found in Opus.');
        }

        return [
            $json->results[0]->document->workId,
            $json->results[0]->document->id,
        ];
    }

    /**
     * Get the product information
     * @return stdClass
     */
    public function getProduct()
    {
        // Get the production from Opus
        try {
            $response = $this->client->get('/v1/works/' . $this->workId . '/productions/' . $this->productionId);
        } catch (ServerException $e) {
            throw new Exception('Server exception: ' . $e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the print production plan
     * @return mixed
     */
    public function getPrintProductionPlan()
    {
        // Get the production from Opus
        try {
            $response = $this->client->get('/v1/works/' . $this->workId . '/productions/' . $this->productionId . '/printchanges');
        } catch (ServerException $e) {
            throw new Exception('Server exception: ' . $e->getResponse()->getBody());
        }

        return json_decode($response->getBody()->getContents());
    }

    /**
     * Get the work level information
     * @return stdClass
     */
    public function getWorkLevel()
    {
        if($this->workLevelFetched === false) {
            // Get the production from Opus
            $response = $this->client->get('/v1/works/' . $this->workId);
            $this->workLevel = json_decode($response->getBody()->getContents());
            $this->workLevelFetched = true;
        }

        return $this->workLevel;
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
     * Get the products type AKA Opus binding code
     * @return string
     */
    public function getProductType()
    {
        return $this->product->bindingCode->name;
    }

    /**
     * Get the products from (Onix codelist 150) - TODO
     * @return string|null
     */
    public function getProductForm()
    {
        switch ($this->product->bindingCode->id) {
            // ePub2
            case 'EPUB2':
                return 'ED';
                break;
            // ePub3
            case 'EPUB3':
                return 'ED';
                break;
            // PDF
            case 'PDF':
                return 'EA';
                break;
            // For all others we can just pick the two first letters
            default:
                return substr($this->product->bindingCode->id, 0, 2);
                break;
        }
    }

    /**
     * Get the products form detail (Onix codelist 175) - TODO
     * @return string|null
     */
    public function getProductFormDetail()
    {
        switch ($this->product->bindingCode->id) {
            // ePub2
            case 'EPUB2':
                return 'E101';
                break;
            // ePub3
            case 'EPUB3':
                return 'W993';
                break;
            // PDF
            case 'PDF':
                return 'E107';
                break;
            // Return last three characters if they are 2+4 combo
            default:
                return (strlen($this->product->bindingCode->id) === 6) ? substr($this->product->bindingCode->id, 2, 4) : null;
                break;
        }
    }

    /**
     * Get the products technical binding type
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
        if (isset($this->product->marketingSerie)) {
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
     * @param  boolean $returnInternalResources
     * @return Collection
     */
    public function getContributors($returnInternalResources = true)
    {
        $contributors = new Collection;

        // If no stakeholders present
        if (!isset($this->product->members)) {
            return $contributors;
        }

        // Filter those team members that we don't have the Onix role mapping
        $teamMembers = collect($this->product->members)->filter(function ($teamMember) {
            return !is_null($this->getContributorRole($teamMember->role->id));
        })->sortBy(function ($teamMember) {
            // We sort by priority level, sort order and then by the lastname
            $priorityLevel = (isset($teamMember->prioLevel)) ? $teamMember->prioLevel->id : 0;
            $sortOrderPriority = $teamMember->sortOrder;
            $rolePriority = $this->getRolePriority($teamMember->role->name);
            $lastNamePriority = (!empty($teamMember->contact->lastName)) ? ord($teamMember->contact->lastName) : 0;

            $sortOrder = $priorityLevel . '-' . $sortOrderPriority . '-' . $rolePriority . '-' . $lastNamePriority;

            return $sortOrder;
        });

        // Remove internal resource if required
        if ($returnInternalResources === false) {
            $teamMembers = $teamMembers->filter(function ($teamMember) {
                return isset($teamMember->prioLevel->name) && $teamMember->prioLevel->name !== 'Internal';
            });
        }

        // Init SequenceNumber
        $sequenceNumber = 1;

        foreach ($teamMembers as $contributor) {
            // Form contributor data
            $contributorData = [
                'SequenceNumber' => $sequenceNumber,
                'ContributorRole' => $this->getContributorRole($contributor->role->id),
                'NamesBeforeKey' => trim($contributor->contact->firstName),
            ];

            // Handle PersonNameInverted and KeyNames differently depending if they have the lastname or not
            if (empty($contributor->contact->lastName) && !empty($contributor->contact->firstName)) {
                $contributorData['PersonNameInverted'] = trim($contributor->contact->firstName);
                $contributorData['KeyNames'] = trim($contributor->contact->firstName);
            } else {
                $contributorData['PersonNameInverted'] = trim($contributor->contact->lastName) . ', ' . trim($contributor->contact->firstName);
                $contributorData['KeyNames'] = trim($contributor->contact->lastName);
            }

            // Add to collection
            $contributors->push($contributorData);

            $sequenceNumber++;
        }

        return $contributors;
    }

    /**
     * Get the all contributors, including those that don't have Onix roles
     * @return Collection
     */
    public function getAllContributors()
    {
        $contributors = new Collection;

        // If no stakeholders present
        if (!isset($this->product->members)) {
            return $contributors;
        }

        foreach ($this->product->members as $member) {
            $contributors->push([
                'Role' => $member->role->name,
                'FirstName' => $member->contact->firstName ?? null,
                'LastName' => $member->contact->lastName ?? null,
            ]);
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
        foreach ($this->getWorkLevel()->originalLanguages as $originalLanguage) {
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
            $audioPlaytimeHours = str_pad($this->product->audioPlaytimeHours, 3, '0', STR_PAD_LEFT);

            // If no minutes are given, use 00
            $audioPlaytimeMinutes = (!isset($this->product->audioPlaytimeMinutes)) ? '00' : str_pad($this->product->audioPlaytimeMinutes, 2, '0', STR_PAD_LEFT);

            // Skip if we don't have value
            $extentValue = $audioPlaytimeHours . $audioPlaytimeMinutes;
            if($extentValue !== '00000') {
                $extents->push([
                    'ExtentType' => '09',
                    'ExtentValue' => $extentValue,
                    'ExtentUnit' => '15',
                ]);
            }
        }

        return $extents;
    }

    /**
     * Get the publishers name
     * @return string
     */
    public function getPublisher()
    {
        return $this->product->publishingHouse->name;
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
     * Get the products net price RRP including VAT
     * @return float|null
     */
    public function getPrice()
    {
        return (isset($this->product->resellerPriceIncludingVat)) ? floatval($this->product->resellerPriceIncludingVat) : null;
    }

    /**
     * Get the products net price excluding VAT
     * @return float|null
     */
    public function getPriceExcludingVat()
    {
        return (isset($this->product->resellerPrice)) ? floatval($this->product->resellerPrice) : null;
    }

    /**
     * Get the products retail price including VAT
     * @return float|null
     */
    public function getPublisherRetailPrice()
    {
        return (isset($this->product->publisherRetailPriceIncludingVat)) ? floatval($this->product->publisherRetailPriceIncludingVat) : null;
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
        if (!empty($this->product->height)) {
            $measures->push(['MeasureType' => '01', 'Measurement' => intval($this->product->height), 'MeasureUnitCode' => 'mm']);
        }

        if (!empty($this->product->width)) {
            $measures->push(['MeasureType' => '02', 'Measurement' => intval($this->product->width), 'MeasureUnitCode' => 'mm']);
        }

        if (!empty($this->product->depth)) {
            $measures->push(['MeasureType' => '03', 'Measurement' => intval($this->product->depth), 'MeasureUnitCode' => 'mm']);
        }

        // Add weight
        if (!empty($this->product->weight)) {
            $measures->push(['MeasureType' => '08', 'Measurement' => intval($this->product->weight * 1000), 'MeasureUnitCode' => 'gr']);
        }

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
        if (empty($this->product->OriginalPublishingDate)) {
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
        if (!isset($this->product->publishingDate) || is_null($this->product->publishingDate)) {
            return null;
        }

        return DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->publishingDate);
    }

    /**
     * Get the products subjects, like library class, Thema, BIC, BISAC etc.
     * @return Collection
     */
    public function getSubjects()
    {
        // Init array for subjects
        $subjects = new Collection;

        // Library class
        $libraryClass = $this->getLibraryClass();

        if (!empty($libraryClass)) {
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
                'SubjectSchemeName' => 'Bonnier Books Finland - Main product group',
                'SubjectCode' => $this->product->mainGroup->id,
                'SubjectHeadingText' => $this->product->mainGroup->name,
            ]);
        }

        // Sub product group
        if (isset($this->product->subGroup)) {
            $subjects->push([
                'SubjectSchemeIdentifier' => '23',
                'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group',
                'SubjectCode' => $this->product->subGroup->id,
                'SubjectHeadingText' => trim($this->product->subGroup->name),
            ]);

            // BISAC Subject Heading
            $subjects->push(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => $this->getBisacCode()]);

            // BIC subject category
            $subjects->push(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => $this->getBicCode()]);

            // Thema subject category
            $subjects->push(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => $this->getThemaSubjectCode()]);
        }

        // Thema interest age
        $subjects->push(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => $this->getThemaInterestAge()]);

        // Fiktiivisen aineiston lisäluokitus
        if (isset($this->product->mainGroup, $this->product->subGroup)) {
            $subjects->push(['SubjectSchemeIdentifier' => '80', 'SubjectSchemeName' => 'Fiktiivisen aineiston lisäluokitus', 'SubjectCode' => $this->getFiktiivisenAineistonLisaluokitus()]);
        }

        // Suomalainen kirja-alan luokitus
        $subjects->push(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => $this->getFinnishBookTradeCategorisation()]);

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
            $subjects->push(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => implode(';', array_unique($keywords))]);
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
        $response = $this->client->get('v1/works/' . $this->workId . '/productions/' . $this->productionId . '/texts');
        $json = json_decode($response->getBody()->getContents());
        $texts = collect($json->texts);

        // Headline
        $headline = $texts->filter(function ($text) {
            return $text->textType->name === 'Headline';
        });

        // Copy 1
        $copyOne = $texts->filter(function ($text) {
            return $text->textType->name === 'Copy 1';
        });

        // Copy 2
        $copyTwo = $texts->filter(function ($text) {
            return $text->textType->name === 'Copy 2';
        });

        // Author description
        $authorDescription = $texts->filter(function ($text) {
            return $text->textType->name === 'Author presentation';
        });

        // Merge the texts and add missing paragraph tags
        $mergedTexts = $headline->merge($copyOne)->merge($copyTwo)->merge($authorDescription)->transform(function ($text) {
            if (substr($text->text, 0, 3) !== '<p>') {
                $text->text = '<p>' . $text->text . '</p>';
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
        $response = $this->client->get('/v1/works/' . $this->workId . '/reviewquotes');
        $json = json_decode($response->getBody()->getContents());

        foreach ($json->reviewQuotes as $reviewQuote) {
            if (!empty($reviewQuote->quote) && !empty($reviewQuote->source)) {
                $textContents->push([
                    'TextType' => '06',
                    'ContentAudience' => '00',
                    'Text' => $this->purifyHtml($reviewQuote->quote),
                    'SourceTitle' => $this->purifyHtml($reviewQuote->source),
                ]);
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
        $publishers->push(['PublishingRole' => '01', 'PublisherName' => $this->getPublisher()]);

        return $publishers;
    }

    /**
     * Get the products publishing status (Onix codelist 64)
     * @return string
     */
    public function getPublishingStatus()
    {
        switch ($this->product->listingCode->name) {
            case 'Published':
            case 'Exclusive Sales':
            case 'Short run':
                return '04';
                break;
            case 'Development':
                return '02';
                break;
            case 'Sold out':
                return '07';
                break;
            case 'Development-Confidential':
                return '00';
                break;
            case 'Cancelled':
                return '01';
                break;
            case 'Delivery block':
                return '16';
                break;
            default:
                throw new Exception('Could not map product governing code to publishing status');
                break;
        }
    }

    /**
     * Get the product publishing dates
     * @return Collection
     */
    public function getPublishingDates()
    {
        $publishingDates = new Collection;

        // Add original publishing date
        if (!empty($this->product->publishingDate)) {
            $publishingDate = DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->publishingDate);
            $publishingDates->push(['PublishingDateRole' => '01', 'Date' => $publishingDate->format('Ymd')]);
        }

        // Add Embargo / First permitted day of sale
        if (!empty($this->product->firstSellingDay)) {
            $embargoDate = DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->firstSellingDay);
            $publishingDates->push(['PublishingDateRole' => '02', 'Date' => $embargoDate->format('Ymd')]);
        }
        
        // Add public announcement date / Season
        if (!empty($this->product->seasonYear) && !empty($this->product->seasonPeriod)) {
            if ($this->product->seasonYear->name !== '2099' && $this->product->seasonPeriod->name !== 'N/A') {
                $publishingDates->push([
                    'PublishingDateRole' => '09',
                    'Date' => $this->product->seasonYear->name . ' ' . $this->product->seasonPeriod->name,
                    'Format' => 12,
                ]);
            }
        }

        // Latest reprint date
        $latestStockArrivalDate = $this->getLatestStockArrivalDate();

        if (!is_null($latestStockArrivalDate)) {
            $publishingDates->push(['PublishingDateRole' => '12', 'Date' => $latestStockArrivalDate->format('Ymd')]);
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
        if (!is_null($this->getPriceExcludingVat())) {
            $priceTypes->push([
                'PriceTypeCode' => '05',
                'TaxIncluded' => false,
                'TaxRateCode' => 'Z',
                'PriceAmount' => $this->getPriceExcludingVat(),
            ]);
        }

        // Supplier’s net price including tax
        if (!is_null($this->getPrice())) {
            $priceTypes->push([
                'PriceTypeCode' => '07',
                'TaxIncluded' => true,
                'TaxRateCode' => 'S',
                'PriceAmount' => $this->getPrice(),
            ]);
        }

        // Publishers recommended retail price including tax
        if (!is_null($this->getPublisherRetailPrice())) {
            $priceTypes->push([
                'PriceTypeCode' => '42',
                'TaxIncluded' => true,
                'TaxRateCode' => 'S',
                'PriceAmount' => round($this->getPublisherRetailPrice(), 2), // Always round to two decimals
            ]);
        }

        // Remove price types that don't have price
        $priceTypes = $priceTypes->filter(function ($priceType, $key) {
            return !is_null($priceType['PriceAmount']);
        });

        // Go through all Price Types
        foreach ($priceTypes as $priceType) {
            $prices->push([
                'PriceType' => $priceType['PriceTypeCode'],
                'PriceAmount' => $priceType['PriceAmount'],
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
     * Get the tax element
     * @param  array $priceType
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

        // Search for cover image in Elvis
        $response = $client->request('POST', 'search', [
            'query' => [
                'q' => 'gtin:' . $this->productNumber . ' AND cf_catalogMediatype:cover AND (ancestorPaths:/WSOY/Kansikuvat OR ancestorPaths:/Tammi/Kansikuvat OR ancestorPaths:/Kosmos/Kansikuvat)',
                'metadataToReturn' => 'height, width, mimeType, fileSize',
                'num' => 1,
            ],
        ]);

        $searchResults = json_decode($response->getBody());

        // Elvis uses mime types, so we need mapping table for ResourceVersionFeatureValue codelist
        $mimeTypeToCodelistMapping = [
            'application/pdf' => 'D401',
            'image/gif' => 'D501',
            'image/jpeg' => 'D502',
            'image/png' => 'D503',
            'image/tiff' => 'D504',
        ];

        // Add cover image to collection
        foreach ($searchResults->hits as $hit) {
            // Download the file for MD5/SHA checksums
            $contents = file_get_contents($this->getAuthCredUrl($hit->originalUrl));

            // Check that we have all the required metadata fields
            $requiredMetadataFields = [
                'mimeType',
                'height',
                'width',
                'filename',
                'fileSize',
            ];

            foreach ($requiredMetadataFields as $requiredMetadataField) {
                if (property_exists($hit->metadata, $requiredMetadataField) === false) {
                    throw new Exception('The required metadata field '. $requiredMetadataField . ' does not exist in Elvis.');
                }
            }

            $supportingResources->push([
                'ResourceContentType' => '01',
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
                    ],
                    'ResourceLink' => $this->getAuthCredUrl($hit->originalUrl),
                ],
            ]);
        }

        // Logout from Elvis
        $response = $client->request('GET', 'logout');

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

            if (isset($resourceContentType) && isset($resourceMode)) {
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
     * Get the related products
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
                            'IDValue' => $production->isbn,
                        ],
                    ],
                ]);
            }
        }

        return $relatedProducts;
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

        if (!array_key_exists($this->product->subGroup->id, $subGroupToBisacMapping)) {
            return null;
        }

        return $subGroupToBisacMapping[$this->product->subGroup->id];
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

        if (!array_key_exists($this->product->subGroup->id, $subGroupToBicMapping)) {
            return null;
        }

        return $subGroupToBicMapping[$this->product->subGroup->id];
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
            '35' => 'FQ',
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
            '35' => 'YFQ',
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
        if (($this->product->mainGroup->id === '3' || $this->product->mainGroup->id === '4') && array_key_exists($this->product->subGroup->id, $themaMappingTableChildren)) {
            return $themaMappingTableChildren[$this->product->subGroup->id];
        }

        // Adults Thema mapping
        if (array_key_exists($this->product->subGroup->id, $themaMappingTableAdults)) {
            return $themaMappingTableAdults[$this->product->subGroup->id];
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
                'lookfor' => $this->product->isbn,
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
                        if(isset($subject->heading) && is_array($subject->heading)) {
                            foreach ($subject->heading as $heading) {
                                if ($heading !== 'Ellibs') {
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
        }

        return $keywords;
    }

    /**
     * Get Finnish book trade categorisations - See http://www.onixkeskus.fi/onix/misc/popup.jsp?page=onix_help_subjectcategorisation
     * @return string|null
     */
    public function getFinnishBookTradeCategorisation()
    {
        if (isset($this->product->libraryCodePrefix)) {
            return $this->product->libraryCodePrefix->id;
        }

        return null;
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
        if ($this->product->mainGroup->name !== 'Tietokirjallisuus' && array_key_exists($this->product->subGroup->id, $mappingTable)) {
            return $mappingTable[$this->product->subGroup->id];
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

        if (isset($this->product->interestAge) && array_key_exists($this->product->interestAge->id, $mappingTable)) {
            return $mappingTable[$this->product->interestAge->id];
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
        // Mapping Opus roles to Onix roles
        $roleMappings = [
            '301' => 'A19', // Afterword
            '302' => 'B25', // Arranged by
            '303' => 'Z01', // Assistant
            '304' => 'A01', // Author
            '305' => 'A06', // Composer
            //'306' => '', // Coordinator
            //'307' => '', // Copy Writer
            '308' => 'A36', // Cover design or artwork by
            '309' => 'B11', // Editor-in-chief
            '310' => 'B01', // Editor
            '311' => 'B11', // Editor in Chief
            '312' => 'A22', // Epilogue
            '313' => 'A23', // Foreword
            //'314' => '', // Freelancer
            '315' => 'A11', // Graphic Designer
            '316' => 'A12', // Illustrator
            '317' => 'A34', // Index
            '318' => 'A24', // Introduction
            //'319' => '', // Layout
            '320' => 'A39', // Maps
            '321' => 'E08', // Performed by
            //'322' => '', // Photo editor
            '323' => 'A13', // Photographer
            '324' => 'A15', // Preface
            //'325' => '', // Production Planner
            //'326' => '', // Project Manager
            '327' => 'A16', // Prologue
            //'328' => '', // Proof reader
            '329' => 'E07', // Reader
            //'330' => '', // Responsible person
            //'331' => '', // Senior Editor
            '332' => 'E05', // Singer
            //'333' => '', // Specialist
            '334' => 'B06', // Translator
            //'335' => '', // Studio
            //'336' => '', // Printer
            '337' => 'A36', // Cover design or artwork by
            '338' => 'A11', // Designed by
            '340' => 'A36', // Cover design or artwork by
            '341' => 'A12', // Illustrator
            '342' => 'A36', // Cover design or artwork by
            '343' => 'A13', // Photographs by
        ];          

        return (isset($roleMappings[$role])) ? $roleMappings[$role] : null;            
    }

    /**
     * Is the product confidential?
     * @return boolean
     */
    public function isConfidential()
    {        
        return $this->product->listingCode->name === 'Development-Confidential' || $this->product->listingCode->name === 'Cancelled-Confidential';
    }

    /**
     * Is the product a luxury book?
     * @return boolean
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
     * @return string|null
     */
    public function getMediaType()
    {
        return $this->getProductForm();
    }

    /**
     * Get the products binding code
     * @return string|null
     */
    public function getBindingCode()
    {
        return $this->getProductFormDetail();
    }

    /**
     * Get the products discount group
     * @return int|null
     */
    public function getDiscountGroup()
    {
        return (empty($this->product->discountNumber)) ? null : $this->product->discountNumber;
    }

    /**
     * Get the products status
     * @return string
     */
    public function getStatus()
    {
        return $this->product->listingCode->name;
    }

    /**
     * Get the products status code
     * @return int
     */
    public function getStatusCode()
    {
        /*
        if(!isset($this->product->listingCode->customProperties->schillingId_1001)) {
            throw new Exception('Status is not mapped between Opus and Schilling');
        }*/

        return intval($this->product->listingCode->customProperties->schillingId_1001);
    }

    /**
     * Get the number of products in the series
     * @return int|null
     */
    public function getProductsInSeries()
    {
        return (empty($this->product->numberInSeries)) ? null : intval($this->product->numberInSeries);
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
        return $this->product->listingCode->name === 'Short run';
    }

    /**
     * Get internal product number
     * @return string|null
     */
    public function getInternalProdNo()
    {
        return $this->product->isbn;
    }

    /**
     * Get customs number
     * @return int|null
     */
    public function getCustomsNumber()
    {
        switch ($this->getProductType()) {
            // Audio and MP3 CD
            case 'CD':
            case 'MP3-CD':
                return 85234920;
                break;
            // Digital products should return null
            case 'ePub2':
            case 'ePub3':
            case 'Application':
            case 'Downloadable audio file':
            case 'Picture-and-audio book':
                return null;
                break;
            default:
                return 49019900;
                break;
        }

        return intval($this->product->customsNumber);
    }

    /**
     * Get the products library class
     * @return string|null
     */
    public function getLibraryClass()
    {
        if (!isset($this->product->libraryCode)) {
            return null;            
        }

        return $this->product->libraryCode->id;
    }

    /**
     * Get the products marketing category
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
     * @return string|null
     */
    public function getSalesSeason()
    {
        if (!isset($this->product->seasonYear) && !isset($this->product->seasonPeriod)) {
            return null;
        }

        if (isset($this->product->seasonYear) && !isset($this->product->seasonPeriod)) {
            return $this->product->seasonYear->name;
        }

        // Form sales period
        switch ($this->product->seasonPeriod->name) {
            case 'Spring':
                return $this->product->seasonYear->name . '/1';
                break;
            case 'Autumn':
                return $this->product->seasonYear->name . '/2';
                break;
        }
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

        $interestAges = [
            '0+' => 0,
            '3+' => 3,
            '5+' => 5,
            '7+' => 7,
            '9+' => 9,
            '10+' => 10,
            '12+' => 12,
            '15+' => 15,
        ];

        if (!empty($this->product->interestAge) && in_array($this->product->interestAge->name, $interestAges)) {
            $audienceRanges->push([
                'AudienceRangeQualifier' => 17,
                'AudienceRangeScopes' => [
                    [
                        'AudienceRangePrecision' => '03', // From
                        'AudienceRangeValue' => $interestAges[$this->product->interestAge->name],
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
            if (isset($timeplan->type->name) && $timeplan->type->name === 'Delivery to warehouse') {
                if (isset($timeplan->actual)) {
                    return DateTime::createFromFormat('Y-m-d*H:i:s', $timeplan->actual);
                }

                if (isset($timeplan->planned)) {
                    return DateTime::createFromFormat('Y-m-d*H:i:s', $timeplan->planned);
                }
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
        if (!isset($this->product->activePrint->printNumber)) {
            return null;
        }

        return $this->product->activePrint->printNumber;
    }

    /**
     * Get the sales restrictions
     * @return Collection
     */
    public function getSalesRestrictions()
    {
        $salesRestrictions = new Collection;

        // We currently only support digital products
        if ($this->isImmaterial() === false) {
            return $salesRestrictions;
        }

        // Get list of distribution channels
        $distributionChannels = $this->getDistributionChannels();
        
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
        if($distributionChannels->contains('hasRights', true)) {
            $retailerExclusiveSalesOutlets = $distributionChannels->where('hasRights', true)->map(function ($distributionChannel, $key) {
                return [
                    'SalesOutlet' => [
                        'SalesOutletIdentifiers' => [
                            [
                                'SalesOutletIDType' => '01',
                                'IDValue' => $distributionChannel['channel'],
                            ],
                        ]
                    ],
                ];
            });

            $salesRestrictions->push([
                'SalesRestrictionType' => '04', // Retailer exclusive
                'SalesOutlets' => $retailerExclusiveSalesOutlets->toArray(),
            ]);
        }

        // Add SalesOutlets where we don't have rights as " Retailer exception"
        if($distributionChannels->contains('hasRights', false)) {
            $retailerExceptionSalesOutlets = $distributionChannels->where('hasRights', false)->map(function ($distributionChannel, $key) {
                return [
                    'SalesOutlet' => [
                        'SalesOutletIdentifiers' => [
                            [
                                'SalesOutletIDType' => '01',
                                'IDValue' => $distributionChannel['channel'],
                            ],
                        ],
                    ],
                ];
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
     * @param  string $role
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
     * @return Collection
     */
    public function getDistributionChannels()
    {
        // Collection for distribution channels
        $distributionChannels = new Collection;

        foreach ($this->product->exportRules as $exportRule) {
            $distributionChannels->push([
                'channel' => $exportRule->salesChannel->name,
                'channelType' => $exportRule->salesType->name,
                'hasRights' => $exportRule->hasRights,
                'distributionAllowed' => $exportRule->hasDistribution,
            ]);
        }

        return $distributionChannels;
    }

    /**
     * Is the product connected to ERP?
     * @return boolean
     */
    public function isConnectedToErp()
    {
        return (bool) $this->product->isConnectedToERP;
    }

    /**
     * Get the products print orders
     * @return Collection
     */
    public function getPrintOrders()
    {
        // Get the production print orders from Opus
        $response = $this->client->get('/v1/works/' . $this->workId . '/productions/' . $this->productionId . '/printchanges');
        $opusPrintOrders = json_decode($response->getBody()->getContents());

        // Collection for print orders
        $printOrders = new Collection;

        foreach ($opusPrintOrders->prints as $print) {
            // Get deliveries
            $response = $this->client->get('/v2/works/' . $this->workId . '/productions/' . $this->productionId . '/prints/' . $print->print . '/deliveryspecifications');
            $opusDeliviries = json_decode($response->getBody()->getContents());

            // Store all delivieries to array for later use
            $deliveries = [];

            foreach ($opusDeliviries->deliverySpecifications as $delivery) {
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
     * @return boolean
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
     * @return boolean
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
     * @return Collection
     */
    public function getProductionPlan()
    {
        $productionPlan = new Collection;

        $opusProductionPlan = $this->getPrintProductionPlan();

        foreach ($opusProductionPlan->prints as $productionPlanEntry) {
             // Add all time plan entries
            foreach ($productionPlanEntry->timePlanEntries as $timePlanEntry) {
                $productionPlan->push([
                    'print' => $productionPlanEntry->print,
                    'id' => $timePlanEntry->type->id,
                    'name' => $timePlanEntry->type->name,
                    'planned_date' => isset($timePlanEntry->planned) ? DateTime::createFromFormat('Y-m-d\TH:i:s', $timePlanEntry->planned) : null,
                    'actual_date' => isset($timePlanEntry->actual) ? DateTime::createFromFormat('Y-m-d\TH:i:s', $timePlanEntry->actual) : null,
                ]);
            }
        }

        return $productionPlan;
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
            'paperType' => null,
            'grammage' => null,
            'colors' => null,
            'colorNames' => null,
            'foil' => null,
            'hasBlindEmbossing'  => false,
            'hasFlaps'  => false,
            'hasUvSpotVarnishGlossy'  => false,
            'hasUvSpotVarnishMatt'  => false,
            'hasDispersionVarnish'  => false,
            'hasReliefSpotVarnish'  => false,
            'placement' => null,
            'lamination' => null,
        ]);

        // End papers
        $technicalData->push([
            'partName' => 'endPapers',
            'paperType' => $this->product->activePrint->foePaper->name ?? null,
            'paperName' => $this->product->activePrint->foePaperOther ?? null,
            'grammage' => (isset($this->product->activePrint->foeWeight)) ? intval($this->product->activePrint->foeWeight) : null,
            'colors' => (isset($this->product->activePrint->foePaper)) ? str_replace('+', '/', $this->product->activePrint->foePaper->name) : null,
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
     * @return string|null
     */
    public function getProductAvailability()
    {
        // Digital products
        if ($this->isImmaterial()) {
            // Only statuses Published and Development are affected by the publication date
            if ($this->product->listingCode->name === 'Development' || $this->product->listingCode->name === 'Published') {
                // Either "In stock" or "Not yet available"
                return $this->isPublicationDatePassed() ? '21' : '10';
            }

            // All other governing codes
            switch ($this->product->listingCode->name) {
                case 'Sold out':
                case 'Short Run':
                    return '40';
                    break;
            }
        }

        // Governing codes which are mapped directly where available stock or publishing date do not affect
        switch ($this->product->listingCode->name) {
            case 'Development':
                return '10';
                break;
            case 'Cancelled':
                return '01';
                break;
            case 'Exclusive Sales':
                return '22';
                break;
            case 'Development-Confidential':
            case 'Delivery block':
                return '40';
                break;
            case 'Sold out':
                return '43';
                break;
        }

        // Already published product
        if ($this->product->listingCode->name === 'Published') {
            // Check if the product has free stock
            $onHand = $this->getStocks()->pluck('OnHand')->first();
            $hasStock = (!empty($onHand) && $onHand > 0) ? true : false;

            if ($hasStock) {
                return '21';
            }

            $tomorrow = new DateTime('tomorrow');
            $stockArrivalDate = $this->getLatestStockArrivalDate();

            return ($tomorrow > $stockArrivalDate) ? '31' : '30';
        }

        // Short-run is always available
        if ($this->product->listingCode->name === 'Short run') {
            return '21';
        }

        return null;
    }

    /**
     * Check if original publication date has passed
     * @return bool
     */
    public function isPublicationDatePassed()
    {
        $publicationDate = $this->getOriginalPublicationDate() ?? $this->getLatestPublicationDate();
        $tomorrow = new DateTime('tomorrow');

        return ($tomorrow > $publicationDate);
    }

    /**
     * Get the products stocks
     * @return Collection
     */
    public function getStocks()
    {
        $stocks = new Collection;

        // Get stocks from API
        $client = new Client([
            'base_uri' => 'http://stocks.books.local/api/products/gtin/',
            'timeout'  => 2.0,
        ]);

        try {
            $response = $client->request('GET', $this->productNumber);
            $json = json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            $response = $e->getResponse();

            if (empty($response)) {
                throw new Exception('Stock API response is empty for GTIN ' . $this->productNumber);
            }

            $json = json_decode($response->getBody()->getContents());

            if ($json->data->error_code !== 404 && $json->data->error_message !== 'The model could not be found.') {
                throw new Exception('Could not fetch stock data for GTIN ' . $this->productNumber);
            } else {
                return $stocks;
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

        $stocks->push([
            'LocationIdentifier' => [
                'LocationIDType' => '06',
                'IDValue' => '6430049920009',
            ],
            'LocationName' => 'Porvoon Kirjakeskus / Tarmolan päävarasto',
            'OnHand' => $onHand,
            'Proximity' => $proximityValue,
        ]);

        return $stocks;
    }

    /**
     * Get the supply dates
     * @return Collection
     */
    public function getSupplyDates()
    {
        $supplyDates = new Collection;

        // Latest reprint date
        $latestStockArrivalDate = $this->getLatestStockArrivalDate();

        if (!is_null($latestStockArrivalDate)) {
            $supplyDates->push(['SupplyDateRole' => '08', 'Date' => $latestStockArrivalDate->format('Ymd')]);
        }

        return $supplyDates;
    }

    /**
     * Get all contacts
     * @return Collection
     */
    public function getContacts()
    {
        $contacts = new Collection;

        // Get the maximum amount
        $response = $this->searchClient->get('v3/search/contacts', [
            'query' => [
                'limit' => 1,
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
                if(isset($result->document->erpSupplierId)) {
                    $contacts->push([
                        'firstName' => optional($result->document)->firstName,
                        'lastName' => optional($result->document)->lastName,
                        'supplierId' => intval($result->document->erpSupplierId),
                    ]);
                }
            }
      
            // Increase offset
            $offset += $limit;
        }

        return $contacts;
    }

    /**
     * Get all editions
     * @return Collection
     */
    public function getEditions()
    {
        $editions = new Collection;

        // Get the maximum amount
        $response = $this->client->get('v2/search/productions', [
            'query' => [
                'limit' => 1,
                '$select' => 'isbn,title',
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
                    '$select' => 'isbn,title',
                    '$filter' => '(isCancelled eq true or isCancelled eq false)',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);

            $json = json_decode($response->getBody()->getContents());

            foreach ($json->results as $result) {
                if(isset($result->document->isbn)) {
                    $editions->push([
                        'isbn' => intval($result->document->isbn),
                        'title' => optional($result->document)->title,                        
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
     * @return DateTime|null
     */
    public function getWebPublishingStartDate()
    {
        if(!isset($this->product->activeWebPeriod->startDate)) {
            return null;
        }

        return DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->activeWebPeriod->startDate);
    }

    /**
     * Get the end date for the products web page
     * @return DateTime|null
     */
    public function getWebPublishingEndDate()
    {
        if(!isset($this->product->activeWebPeriod->endDate)) {
            return null;
        }
        
        return DateTime::createFromFormat('Y-m-d*H:i:s', $this->product->activeWebPeriod->endDate);
    }
}
