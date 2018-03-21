<?php
namespace lasselehtinen\Groschen\Test;

use Exception;
use lasselehtinen\Groschen\Groschen;

class GroschenOpusIntegrationTest extends TestCase
{
    private $groschen;

    protected function setUp()
    {
        parent::setUp();

        $groschen = new Groschen('9789100126537');
        $this->groschen = $groschen;
    }

    /**
     * Test that non-existing product throws exception
     * @return void
     */
    public function testNonExistingProductThrowsException()
    {
        $this->expectException(Exception::class);

        $groschen = new Groschen('foobar');
    }

    /**
     * Test getting all products identifiers
     * @return void
     */
    public function testGettingProductIdentifiers()
    {
        // Product with valid GTIN/EAN/ISBN13
        $this->assertContains(['ProductIDType' => '01', 'id_type_name' => 'Bonnier Books Finland - Internal product number', 'id_value' => 9789100126537], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '03', 'id_value' => 9789100126537], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '15', 'id_value' => 9789100126537], $this->groschen->getProductIdentifiers());
    }

    /**
     * Test getting products composition
     * @return void
     */
    public function testGettingProductComposition()
    {
        // Normal trade item
        $this->assertSame('00', $this->groschen->getProductComposition());

        // Trade-only product - TODO
        //$groschen = new Groschen('6416889067166');
        //$this->assertSame('20', $groschen->getProductComposition());
    }

    /**
     * Test getting products form - TODO
     * @return void
     */
    public function testGettingProductForm()
    {
        // Hardcover
        $this->assertSame('BB', $this->groschen->getProductForm());

        // E-book mapping for Bokinfo
        $groschen = new Groschen('9789100127411');
        $this->assertSame('EA', $groschen->getProductForm());

        // MP3-CD mapping for Bokinfo
        $groschen = new Groschen('9789173486149');
        $this->assertSame('AC', $groschen->getProductForm());
    }

    /**
     * Test getting products form detail - TODO
     * @return void
     */
    public function testGettingFormDetail()
    {
        // Hardcover does not have product form detail
        $this->assertNull($this->groschen->getProductFormDetail());

        // Pocket book
        $groschen = new Groschen('9789175032849');
        $this->assertSame('B104', $groschen->getProductFormDetail());
    }

    /**
     * Test getting products collections/series
     * @return void
     */
    public function testGettingCollections()
    {
        $this->assertFalse($this->groschen->getCollections()->contains('CollectionType', '10'));

        // Product with series and number in series
        $groschen = new Groschen('9789176513521');

        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '01',
                        'TitleText' => 'Harry Hole',
                    ],
                ],
                'CollectionSequence' => [
                    'CollectionSequenceType' => '03',
                    'CollectionSequenceNumber' => '5',
                ],
            ],
        ];

        $this->assertContains($collection, $groschen->getCollections());
    }

    /**
     * Test getting title details
     * @return void
     */
    public function testGettingTitleDetails()
    {
        // Zlatan
        $groschen = new Groschen('9789100128470');
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Jag är Zlatan (specialutgåva)', 'Subtitle' => 'Zlatans egen berättelse']], $groschen->getTitleDetails());
        $this->assertContains(['TitleType' => '10', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Lagercrantz/Jag är Zlatan']], $groschen->getTitleDetails());

        // Product with original title
        $groschen = new Groschen('9789100102975');

        $this->assertContains(['TitleType' => '03', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'The Da Vinci code']], $groschen->getTitleDetails());
    }

    /**
     * Test getting products contributors
     * @group contributors
     * @return void
     */
    public function testGettingContributors()
    {
        // Zlatan himself
        $author = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Internal ID',
                'IDValue' => 36208,
            ],
            'PersonNameInverted' => 'Ibrahimovic, Zlatan',
            'NamesBeforeKey' => 'Zlatan',
            'KeyNames' => 'Ibrahimovic',
        ];

        $this->assertContains($author, $this->groschen->getContributors());

        // Second author
        $author = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Internal ID',
                'IDValue' => 15101,
            ],
            'PersonNameInverted' => 'Lagercrantz, David',
            'NamesBeforeKey' => 'David',
            'KeyNames' => 'Lagercrantz',
        ];
    }

    /**
     * Test getting products languages - TODO - Find sample product that has one or multiple
     * @return void
     */
    public function testGettingLanguages()
    {
        // Test product does not have the language defined
        $this->assertCount(0, $this->groschen->getLanguages());

        // Book that has language
        $groschen = new Groschen('9789100171872');
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'swe'], $groschen->getLanguages());
        $this->assertCount(1, $groschen->getLanguages());

        // Book that is translated - TODO
        /*
    $groschen = new Groschen('9789510409749');
    $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fin'], $groschen->getLanguages());
    $this->assertContains(['LanguageRole' => '02', 'LanguageCode' => 'eng'], $groschen->getLanguages());
    $this->assertCount(2, $groschen->getLanguages());
     */
    }

    /**
     * Test getting products extents
     * @return void
     */
    public function testGettingExtents()
    {
        $this->assertContains(['ExtentType' => '00', 'ExtentValue' => '432', 'ExtentUnit' => '03'], $this->groschen->getExtents());
        $this->assertCount(1, $this->groschen->getExtents());

        // Audio book with duration - Zlatan is rounded to 12 hours
        $groschen = new Groschen('9789174331516');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '01200', 'ExtentUnit' => '15'], $groschen->getExtents());

        // Audio book with duration with minutes
        $groschen = new Groschen('9789174332117');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '01930', 'ExtentUnit' => '15'], $groschen->getExtents());

    }

    /**
     * Test getting product text contents - TODO
     * @return void
     */
    public function testGettingTextContents()
    {
        // Check that we can find text
        $this->assertCount(1, $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00'));

        // Check that text contains string
        $this->assertContains('Det har skrivits flera böcker om Zlatan Ibrahimovic', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());
    }

    /**
     * Test getting the products RRP incl. VAT
     * @return void
     */
    public function testGettingPrice()
    {
        $this->assertSame(155.0, $this->groschen->getPrice());
    }

    /**
     * Test getting the products weight
     * @return void
     */
    public function testGettingMeasures()
    {
        $this->assertContains(['MeasureType' => '01', 'Measurement' => 270, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '02', 'Measurement' => 228, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '03', 'Measurement' => 41, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '08', 'Measurement' => 1080, 'MeasureUnitCode' => 'gr'], $this->groschen->getMeasures());

        // eBook should not have any measures
        $groschen = new Groschen('9789100130091');
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '01'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '02'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '03'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '08'));
    }

    /**
     * Test getting various subjects
     * @return void
     */
    public function testGettingSubjects()
    {
        $subjects = $this->groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'SFBC'], $subjects);

        // TODO - YKL, Thema interest age
    }

    /**
     * Test getting the products publisher(s)
     * @return void
     */
    public function testGettingPublishers()
    {
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Albert Bonniers Förlag'], $this->groschen->getPublishers());
    }

    /**
     * Test getting the products imprint
     * @return void
     */
    public function testGettingImprints()
    {
        // Product without imprint
        $this->assertCount(0, $this->groschen->getImprints());

        // Johnny Kniga (imprint of WSOY)
        $groschen = new Groschen('9789185419746');
        $this->assertContains(['ImprintName' => 'Minotaur'], $groschen->getImprints());
    }

    /**
     * Test getting products publishing status
     * @return void
     */
    public function testGettingPublishingStatus()
    {
        // Published
        $this->assertSame('04', $this->groschen->getPublishingStatus());

        // Development
        $groschen = new Groschen('9789510397374');
        $this->assertSame('02', $groschen->getPublishingStatus());

        // Exclusive sales
        $groschen = new Groschen('6430027856139');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Sold out
        $groschen = new Groschen('6416889067166');
        $this->assertSame('07', $groschen->getPublishingStatus());

        // Development-confidential
        $groschen = new Groschen('6430060030169');
        $this->assertSame('00', $groschen->getPublishingStatus());

        // Cancelled
        $groschen = new Groschen('6417892033025');
        $this->assertSame('01', $groschen->getPublishingStatus());

        // POD / shortrun
        $groschen = new Groschen('9789513168865');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Delivery block
        $groschen = new Groschen('9789510359686');
        $this->assertSame('16', $groschen->getPublishingStatus());
    }

    /**
     * Test getting products publishing dates
     * @return void
     */
    public function testGettingPublishingDates()
    {
        // Original Publishing date
        $this->assertContains(['PublishingDateRole' => '01', 'Date' => '20111108'], $this->groschen->getPublishingDates());

        // Latest reprint
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20111216'], $this->groschen->getPublishingDates());
    }

    /**
     * Test getting products prices
     * @return void
     */
    public function testGettingPrices()
    {
        // Supplier’s net price excluding tax
        $suppliersNetPriceExcludingTax = [
            'PriceType' => '05',
            'PriceAmount' => 146.23,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 6,
                'TaxableAmount' => 146.23,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Supplier’s net price including tax
        $suppliersNetPriceIncludingTax = [
            'PriceType' => '07',
            'PriceAmount' => 155,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 6,
                'TaxableAmount' => 146.23,
                'TaxAmount' => 8.77,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($suppliersNetPriceExcludingTax, $this->groschen->getPrices());
        $this->assertContains($suppliersNetPriceIncludingTax, $this->groschen->getPrices());
    }

    /**
     * Test getting publishers retail prices
     * @return void
     */
    public function testGettingPublishersRetailPrices()
    {
        // Publishers recommended retail price including tax
        $publishersRecommendedRetailPriceIncludingTax = [
            'PriceType' => '42',
            'PriceAmount' => 25,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 10,
                'TaxableAmount' => 22.73,
                'TaxAmount' => 2.27,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($publishersRecommendedRetailPriceIncludingTax, $this->groschen->getPrices());
    }

    /**
     * Test getting supporting resources like cover image / reading sample links etc.
     * @return void
     */
    public function testGettingCoverImageInSupportingResources()
    {
        $supportingResource = [
            'ResourceContentType' => '01',
            'ContentAudience' => '00',
            'ResourceMode' => '03',
            'ResourceVersion' => [
                'ResourceForm' => '02',
                'ResourceVersionFeatures' => [
                    [
                        'ResourceVersionFeatureType' => '02',
                        'FeatureValue' => '2398',
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => '1594',
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/0lgbvE8eazaBsSZzQItlbj/*/9789510366264_frontcover_final.jpg?authcred=b25peDpueUVISEI=',
            ],
        ];

        $this->assertContains($supportingResource, $this->groschen->getSupportingResources());

        // Product without cover image
        $groschen = new Groschen('6430060030237');
        $this->assertCount(0, $groschen->getSupportingResources());
    }

    /**
     * Test getting audio sample links to Soundcloud
     * @return void
     */
    public function testGettingExternalLinksInSupportingResources()
    {
        // Product with links to multiple external sources
        $groschen = new Groschen('9789510409749');

        // Audio sample in Soundcloud
        $audioSample = [
            'ResourceContentType' => '15',
            'ContentAudience' => '00',
            'ResourceMode' => '02',
            'ResourceVersion' => [
                'ResourceForm' => '03',
                'ResourceLink' => 'https://soundcloud.com/wsoy/9789510409749-kaikki-se-valo-jota-emme-naee',
            ],
        ];

        // Youtube
        $youTube = [
            'ResourceContentType' => '26',
            'ContentAudience' => '00',
            'ResourceMode' => '05',
            'ResourceVersion' => [
                'ResourceForm' => '03',
                'ResourceLink' => 'https://www.youtube.com/watch?v=4Ewj4uYx3Zc',
            ],
        ];

        // Reading sample in Issuu
        $readingSample = [
            'ResourceContentType' => '15',
            'ContentAudience' => '00',
            'ResourceMode' => '04',
            'ResourceVersion' => [
                'ResourceForm' => '03',
                'ResourceLink' => 'http://issuu.com/kirja/docs/9789510409749-kaikki-se-valo-jota-emme-nae',
            ],
        ];

        $this->assertContains($audioSample, $groschen->getSupportingResources());
        $this->assertContains($youTube, $groschen->getSupportingResources());
        $this->assertContains($readingSample, $groschen->getSupportingResources());
    }

    /**
     * Test getting related products
     * @return void
     */
    public function testGettingRelatedProducts()
    {
        // List of related products that should be returned
        $relatedProducts = [
            9789100128470, // Inbunden (INB)
            9789100127411, // E-bok (EBOK)
            9789173486156, // Ljudbok, digital, mp3-fil (FIL)
            9789174331516, // Ljudbok, CD (CDA)
            9789173486149, // Ljudbok, mp3-CD (MP3)
            9789100128197, // Storpocket (SPO)
            9789175032849, // Pocket (POC)
            9789100130091, // E-bok (EBOK)
            9789100128296, // Applikation (APP)
            9789100128579, // Applikation (APP)
        ];

        foreach ($relatedProducts as $relatedProduct) {
            $relation = [
                'ProductRelationCode' => '06',
                'ProductIdentifiers' => [
                    [
                        'ProductIDType' => '03',
                        'IDValue' => $relatedProduct,
                    ],
                ],
            ];

            $this->assertContains($relation, $this->groschen->getRelatedProducts());
        }

        // Check that current product is not in the list
        $currentProduct = [
            'ProductRelationCode' => '06',
            'ProductIdentifiers' => [
                [
                    'ProductIDType' => '03',
                    'IDValue' => 9789100126537,
                ],
            ],
        ];

        $this->assertNotContains($currentProduct, $this->groschen->getRelatedProducts());
    }

    /**
     * Test checking if product is confidential
     * @return void
     */
    public function testCheckingIfProductIsConfidential()
    {
        $this->assertFalse($this->groschen->isConfidential());

        // Development-confidential
        $groschen = new Groschen('6430060030169');
        $this->assertTrue($groschen->isConfidential());
    }

    /**
     * Test getting products cost center
     * @return void
     */
    public function testGettingCostCenter()
    {
        $this->assertSame(301, $this->groschen->getCostCenter());

        // Some other cost center
        $groschen = new Groschen('6430060030169');
        $this->assertSame(914, $groschen->getCostCenter());

        // Cancelled product which does not have cost center
        $groschen = new Groschen('9789510418666');
        $this->assertNull($groschen->getCostCenter());

        // Product with only one dimension
        $groschen = new Groschen('6417892033018');
        $this->assertSame(350, $groschen->getCostCenter());
    }

    /**
     * Test getting products media type
     * @return void
     */
    public function testGettingMediaType()
    {
        $this->assertSame('BB', $this->groschen->getMediaType());

        // Some other cost center
        $groschen = new Groschen('9789510343203');
        $this->assertSame('AJ', $groschen->getMediaType());

        // Cancelled product which does not have media type
        $groschen = new Groschen('9789510418666');
        $this->assertNull($groschen->getMediaType());
    }

    /**
     * Test getting the products binding code
     * @return void
     */
    public function testGettingBindingCode()
    {
        $this->assertNull($this->groschen->getBindingCode());

        // Product with a binding code
        $groschen = new Groschen('9789510343203');
        $this->assertSame('A103', $groschen->getBindingCode());
    }

    /**
     * Test getting the products discount group
     * @return void
     */
    public function testGettingDiscountGroup()
    {
        $this->assertSame(1, $this->groschen->getDiscountGroup());

        // Product with a different discount code
        $groschen = new Groschen('9789510343203');
        $this->assertSame(4, $groschen->getDiscountGroup());
    }

    /**
     * Test getting the product status code
     * @return void
     */
    public function testGettingStatusCode()
    {
        $this->assertSame(2, $this->groschen->getStatusCode());

        // Product with a different status code
        $groschen = new Groschen('6430060030169');
        $this->assertSame(6, $groschen->getStatusCode());
    }

    /**
     * Test getting the number of products in the series
     * @return void
     */
    public function testGettingProductsInSeries()
    {
        $this->assertNull($this->groschen->getProductsInSeries());

        // Product with four products in the serie
        $groschen = new Groschen('9789521610165');
        $this->assertSame(4, $groschen->getProductsInSeries());
    }

    /**
     * Test checking for if the product is immaterial
     * @return void
     */
    public function testCheckingIfProductIsImmaterial()
    {
        $this->assertFalse($this->groschen->isImmaterial());

        // Immaterial product
        $groschen = new Groschen('9789510410622');
        $this->assertTrue($groschen->isImmaterial());
    }

    /**
     * Test checking if the product a Print On Demand product
     * @return boolean
     */
    public function testCheckingIfProductIsPrintOnDemand()
    {
        $this->assertFalse($this->groschen->isPrintOnDemand());

        // POD product
        $groschen = new Groschen('9789513170585');
        $this->assertTrue($groschen->isPrintOnDemand());
    }

    /**
     * Test getting the internal product number
     * @return string|null
     */
    public function testGettingInternalProdNo()
    {
        // Should be same as GTIN
        $this->assertSame('9789510366264', $this->groschen->getInternalProdNo());

        // Old marketing product
        $groschen = new Groschen('80000003');
        $this->assertSame('533632', $groschen->getInternalProdNo());
    }

    /**
     * Test getting customs number
     * @return int|null
     */
    public function testGettingCustomsNumber()
    {
        $this->assertSame(49019900, $this->groschen->getCustomsNumber());

        // Product with different customs number
        $groschen = new Groschen('9789174331516');
        $this->assertSame(85234920, $groschen->getCustomsNumber());
    }

    /**
     * Test getting the products library class
     * @return string|null
     */
    public function testGettingLibraryClass()
    {
        $this->assertSame('84.2', $this->groschen->getLibraryClass());

        // Product with library class with a prefix
        $groschen = new Groschen('9789513158699');
        $this->assertSame('L84.2', $groschen->getLibraryClass());

        // Product where product does not have library class
        $groschen = new Groschen('9789510809556');
        $this->assertNull($groschen->getLibraryClass());
    }

    /**
     * Test getting the products marketing category
     * @return void
     */
    public function testGettingMarketingCategory()
    {
        // Default test product does not have marketing category
        $this->assertNull($this->groschen->getMarketingCategory());

        // Product with "don't use"
        $groschen = new Groschen('9789513192693');
        $this->assertNull($groschen->getMarketingCategory());

        // Product with "Basic"
        $groschen = new Groschen('9789513157371');
        $this->assertSame('Basic', $groschen->getMarketingCategory());

        // Product with "Star"
        $groschen = new Groschen('9789510433645');
        $this->assertSame('Star', $groschen->getMarketingCategory());
    }

    /**
     * Test getting products sales season
     * @return void
     */
    public function testGettingSalesSeason()
    {
        $this->assertSame('2011/2', $this->groschen->getSalesSeason());

        // Product without sales season - TODO
        //$groschen = new Groschen('9789510102893');
        //$this->assertNull($groschen->getSalesSeason());
    }

}
