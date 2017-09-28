<?php
namespace lasselehtinen\Groschen\Test;

use Exception;
use lasselehtinen\Groschen\Groschen;

class GroschenIntegrationTest extends TestCase
{
    private $groschen;

    protected function setUp()
    {
        parent::setUp();

        $groschen = new Groschen('9789510366264');
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
        $this->assertContains(['ProductIDType' => '01', 'id_type_name' => 'Bonnier Books Finland - Internal product number', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '03', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '15', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());

        // Product without valid GTIN/EAN/ISBN13
        $groschen = new Groschen('80000003');
        $this->assertContains(['ProductIDType' => '01', 'id_type_name' => 'Bonnier Books Finland - Internal product number', 'id_value' => 80000003], $groschen->getProductIdentifiers());
        $this->assertFalse($groschen->getProductIdentifiers()->contains('ProductIDType', '03'));
        $this->assertFalse($groschen->getProductIdentifiers()->contains('ProductIDType', '15'));
    }

    /**
     * Test getting products composition
     * @return void
     */
    public function testGettingProductComposition()
    {
        // Normal trade item
        $this->assertSame('00', $this->groschen->getProductComposition());

        // Trade-only product
        $groschen = new Groschen('6416889067166');
        $this->assertSame('20', $groschen->getProductComposition());
    }

    /**
     * Test getting products form
     * @return void
     */
    public function testGettingProductForm()
    {
        // Hardcover
        $this->assertSame('BB', $this->groschen->getProductForm());

        // E-book mapping for Bokinfo
        $groschen = new Groschen('9789510365250');
        $this->assertSame('EA', $groschen->getProductForm());

        // Kit mapping for Bokinfo
        $groschen = new Groschen('9789513167349');
        $this->assertSame('BF', $groschen->getProductForm());

        // MP3-CD mapping for Bokinfo
        $groschen = new Groschen('9789510417591');
        $this->assertSame('AC', $groschen->getProductForm());
    }

    /**
     * Test getting products form detail
     * @return void
     */
    public function testGettingFormDetail()
    {
        // Hardcover does not have product form detail
        $this->assertNull($this->groschen->getProductFormDetail());

        // Pocket book
        $groschen = new Groschen('9789510366752');
        $this->assertSame('B104', $groschen->getProductFormDetail());

        // Nokia Ovi ebook
        $groschen = new Groschen('9789510358535');
        $this->assertSame('E136', $groschen->getProductFormDetail());

        // iPhone / iPad
        $groschen = new Groschen('9789510392263');
        $this->assertSame('E134', $groschen->getProductFormDetail());

        // iPhone / iPad
        $groschen = new Groschen('9789510394335');
        $this->assertSame('E134', $groschen->getProductFormDetail());

        // ePub 3
        $groschen = new Groschen('9789510428627');
        $this->assertSame('E101', $groschen->getProductFormDetail());

        // Picture-audio book
        $groschen = new Groschen('9789510429358');
        $this->assertSame('A302', $groschen->getProductFormDetail());
    }

    /**
     * Test getting products collections/series
     * @return void
     */
    public function testGettingCollections()
    {
        $this->assertFalse($this->groschen->getCollections()->contains('CollectionType', '10'));

        // Product with series
        $groschen = new Groschen('9789510400432');

        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '01',
                        'TitleText' => 'Bon-pokkarit',
                    ],
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
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Mielensäpahoittaja']], $this->groschen->getTitleDetails());
        $this->assertContains(['TitleType' => '10', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Mielensäpahoittaja']], $this->groschen->getTitleDetails());
        // Should not have original title
        $this->assertFalse($this->groschen->getTitleDetails()->contains('TitleType', '03'));

        // Product with all possible titles
        $groschen = new Groschen('9789510400432');

        // Main title with subtitle
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Joululaulu', 'Subtitle' => 'Aavetarina joulusta']], $groschen->getTitleDetails());

        // Original title
        $this->assertContains(['TitleType' => '03', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'A Christmas Carol']], $groschen->getTitleDetails());

        // Distributors title
        $this->assertContains(['TitleType' => '10', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Joululaulu (pokkari)']], $groschen->getTitleDetails());
    }

    /**
     * Test getting products contributors
     * @return void
     */
    public function testGettingContributors()
    {
        // Product without any stakeholder
        $groschen = new Groschen('6430060030077');
        $this->assertSame(0, $groschen->getContributors()->count());

        // Author
        $author = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20001267',
            ],
            'PersonNameInverted' => 'Kyrö, Tuomas',
            'NamesBeforeKey' => 'Tuomas',
            'KeyNames' => 'Kyrö',
        ];

        $this->assertContains($author, $this->groschen->getContributors());

        // Graphic designer
        $graphicDesigner = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A36',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20005894',
            ],
            'PersonNameInverted' => 'Tuominen, Mika',
            'NamesBeforeKey' => 'Mika',
            'KeyNames' => 'Tuominen',
        ];

        $this->assertContains($graphicDesigner, $this->groschen->getContributors());
    }

    /**
     * Test getting products languages
     * @return void
     */
    public function testGettingLanguages()
    {
        // Book that is not translated, should only have finnish
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fin'], $this->groschen->getLanguages());
        $this->assertCount(1, $this->groschen->getLanguages());

        // Book that is translated
        $groschen = new Groschen('9789510409749');
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fin'], $groschen->getLanguages());
        $this->assertContains(['LanguageRole' => '02', 'LanguageCode' => 'eng'], $groschen->getLanguages());
        $this->assertCount(2, $groschen->getLanguages());

        // Book that has multiple languages (fin/fre/eng)
        $groschen = new Groschen('9789510401699');
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fin'], $groschen->getLanguages());
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fre'], $groschen->getLanguages());
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'eng'], $groschen->getLanguages());
        $this->assertContains(['LanguageRole' => '02', 'LanguageCode' => 'fre'], $groschen->getLanguages());
        $this->assertCount(4, $groschen->getLanguages());
    }

    /**
     * Test getting products extents
     * @return void
     */
    public function testGettingExtents()
    {
        $this->assertContains(['ExtentType' => '00', 'ExtentValue' => '128', 'ExtentUnit' => '03'], $this->groschen->getExtents());
        $this->assertCount(1, $this->groschen->getExtents());

        // Book without any extents
        $groschen = new Groschen('9789510303108');
        $this->assertCount(0, $groschen->getExtents());

        // Audio book with duration and pages?
        $groschen = new Groschen('9789513133115');
        $this->assertContains(['ExtentType' => '00', 'ExtentValue' => '2', 'ExtentUnit' => '03'], $groschen->getExtents());
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '00108', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertCount(2, $groschen->getExtents());
    }

    /**
     * Test getting product text contents
     * @return void
     */
    public function testGettingTextContents()
    {
        // Check that we can find text
        $this->assertCount(1, $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00'));

        // Check that text contains string
        $this->assertContains('Kyllä minä niin mieleni pahoitin, kun aurinko paistoi.', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());
    }

    /**
     * Test getting the products RRP incl. VAT
     * @return void
     */
    public function testGettingPrice()
    {
        $this->assertSame(29.30, $this->groschen->getPrice());
    }

    /**
     * Test getting the products weight
     * @return void
     */
    public function testGettingMeasures()
    {
        $this->assertContains(['MeasureType' => '01', 'Measurement' => 204, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '02', 'Measurement' => 136, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '03', 'Measurement' => 14, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '08', 'Measurement' => 240, 'MeasureUnitCode' => 'gr'], $this->groschen->getMeasures());

        // eBook should not have any measures
        $groschen = new Groschen('9789510416860');
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '01'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '02'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '03'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '08'));
    }

    /**
     * Test getting products original publication date
     * @return void
     */
    public function testGettingOriginalPublicationDate()
    {
        $publicationDate = $this->groschen->getOriginalPublicationDate();
        $this->assertInstanceOf('DateTime', $publicationDate);
        $this->assertSame('01.06.2010', $publicationDate->format('d.m.Y'));
    }

    /**
     * Test getting products latest publication date
     * @return void
     */
    public function testGettingLatestPublicationDate()
    {
        $latestPublicationDate = $this->groschen->getLatestPublicationDate();
        $this->assertInstanceOf('DateTime', $latestPublicationDate);
        $this->assertSame('28.09.2017', $latestPublicationDate->format('d.m.Y'));
    }

    /**
     * Test getting various subjects
     * @return void
     */
    public function testGettingSubjects()
    {
        $subjects = $this->groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => 'Kotimainen kauno'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => 'Nykyromaanit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC000000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'FBA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'novellit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectSchemeName' => 'Keywords', 'SubjectCode' => 'novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet;kirjallisuuspalkinnot;Kiitos kirjasta -mitali;2011;novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet;kirjallisuuspalkinnot;Kiitos kirjasta -mitali;2011;novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet'], $subjects);

        // Another book with more classifications
        $groschen = new Groschen('9789510408452');
        $subjects = $groschen->getSubjects();

        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => 'Käännetty L&N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => 'Scifi'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC028000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FL'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '80', 'SubjectSchemeName' => 'Fiktiivisen aineiston lisäluokitus', 'SubjectCode' => 'Scifi'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'YFG'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => 'N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => '5AN'], $subjects);

    }

    /**
     * Test getting the products publisher(s)
     * @return void
     */
    public function testGettingPublishers()
    {
        // Normal WSOY product
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Werner Söderström Osakeyhtiö'], $this->groschen->getPublishers());

        // WSOY marketing product
        $groschen = new Groschen('6430060030275');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Werner Söderström Osakeyhtiö'], $groschen->getPublishers());

        // Normal Tammi product
        $groschen = new Groschen('9789513179564');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Kustannusosakeyhtiö Tammi'], $groschen->getPublishers());

        // Manga product
        $groschen = new Groschen('9789521619779');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Kustannusosakeyhtiö Tammi'], $groschen->getPublishers());

        // Tammi marketing product
        $groschen = new Groschen('6430061220026');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Kustannusosakeyhtiö Tammi'], $groschen->getPublishers());
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
        $groschen = new Groschen('6430027856108');
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
        // Publishing date
        $this->assertContains(['PublishingDateRole' => '01', 'Date' => '20100601'], $this->groschen->getPublishingDates());

        // Latest reprint
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20170928'], $this->groschen->getPublishingDates());

        // Product without original publishing date
        $groschen = new Groschen('6430061220040');
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20151221'], $groschen->getPublishingDates());
        $this->assertCount(1, $groschen->getPublishingDates());

        // Product without any dates
        $groschen = new Groschen('9789513164232');
        $this->assertCount(0, $groschen->getPublishingDates());
    }

    /**
     * Test getting products prices
     * @return void
     */
    public function testGettingPrices()
    {
        // RRP excluding tax
        $recommendedRetailPrice = [
            'PriceType' => '01',
            'PriceAmount' => 26.64,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 10,
                'TaxableAmount' => 26.64,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // RRP including tax
        $recommendedRetailPriceIncludingTax = [
            'PriceType' => '02',
            'PriceAmount' => 29.30,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 10,
                'TaxableAmount' => 26.64,
                'TaxAmount' => 2.66,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Bokinfo workaround for Supplier’s net price excluding tax. Basically same as 01
        $suppliersNetPrice = $recommendedRetailPrice;
        $suppliersNetPrice['PriceType'] = '05';

        $this->assertContains($recommendedRetailPrice, $this->groschen->getPrices());
        $this->assertContains($recommendedRetailPriceIncludingTax, $this->groschen->getPrices());
        $this->assertContains($suppliersNetPrice, $this->groschen->getPrices());

        // Product with missing prices and 24% VAT
        $groschen = new Groschen('9789510353318');

        // RRP excluding tax
        $recommendedRetailPrice = [
            'PriceType' => '01',
            'PriceAmount' => 33.33,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 24,
                'TaxableAmount' => 33.33,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Bokinfo workaround for Supplier’s net price excluding tax. Basically same as 01
        $suppliersNetPrice = $recommendedRetailPrice;
        $suppliersNetPrice['PriceType'] = '05';

        $this->assertContains($recommendedRetailPrice, $groschen->getPrices());
        $this->assertContains($suppliersNetPrice, $groschen->getPrices());

        // Should not have PriceType 02
        $this->assertFalse($groschen->getPrices()->contains('PriceType', '02'));
    }
}
