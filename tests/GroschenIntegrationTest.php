<?php
namespace lasselehtinen\Groschen\Test;

use DateTime;
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
     * Test that deactivated product is fetched also
     * @return void
     */
    public function testDeactivatedProductWorksFine()
    {
        $groschen = new Groschen('9789510439555');
        $this->assertSame('00', $this->groschen->getProductComposition());
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
    }

    /**
     * Test getting products composition
     * @return void
     */
    public function testGettingProductComposition()
    {
        // Normal trade item
        $this->assertSame('00', $this->groschen->getProductComposition());
    }

    /**
     * Test getting products type
     * @return void
     */
    public function testGettingProductType()
    {
        // Hardback
        $this->assertSame('Hardback', $this->groschen->getProductType());

        // ePub 3
        $groschen = new Groschen('9789510441374');
        $this->assertSame('ePub3', $groschen->getProductType());

        // Downloadable audio file
        $groschen = new Groschen('9789510423783');
        $this->assertSame('Downloadable audio file', $groschen->getProductType());
    }

    /**
     * Test getting products form and product form detail
     * @return void
     */
    public function testGettingProductFormAndProductFormDetail()
    {
        // Hardback
        $groschen = new Groschen('9789510405314');
        $this->assertSame('BB', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // Saddle-stitched
        $groschen = new Groschen('9789513173968');
        $this->assertSame('BF', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // Pocket book
        $groschen = new Groschen('9789510362938');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertSame('B104', $groschen->getProductFormDetail());

        // Spiral bound
        $groschen = new Groschen('9789513147013');
        $this->assertSame('BE', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // Flex
        $groschen = new Groschen('9789510425855');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertSame('B116', $groschen->getProductFormDetail());

        // Trade paperback or "Jättipokkari"
        $groschen = new Groschen('9789520403072');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertSame('B106', $groschen->getProductFormDetail());

        // Board book
        $groschen = new Groschen('9789521609336');
        $this->assertSame('BH', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // ePub2
        $groschen = new Groschen('9789513199388');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertSame('E101', $groschen->getProductFormDetail());

        // ePub3
        $groschen = new Groschen('9789510428788');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertSame('W993', $groschen->getProductFormDetail());

        // Application
        $groschen = new Groschen('9789510392263');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // Downloadable audio file
        $groschen = new Groschen('9789510428412');
        $this->assertSame('AJ', $groschen->getProductForm());
        $this->assertSame('A103', $groschen->getProductFormDetail());

        // CD
        $groschen = new Groschen('9789510379110');
        $this->assertSame('AC', $groschen->getProductForm());
        $this->assertSame('A101', $groschen->getProductFormDetail());

        // MP3-CD
        $groschen = new Groschen('9789520402983');
        $this->assertSame('AE', $groschen->getProductForm());
        $this->assertSame('A103', $groschen->getProductFormDetail());

        // Paperback
        $groschen = new Groschen('9789510382745');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());

        // Picture-and-audio book
        $groschen = new Groschen('9789510429945');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertSame('W994', $groschen->getProductFormDetail());

        // PDF e-book
        $groschen = new Groschen('9789510422281');
        $this->assertSame('EA', $groschen->getProductForm());
        $this->assertSame('E107', $groschen->getProductFormDetail());

        // Other audio format
        $groschen = new Groschen('9789510232644');
        $this->assertSame('AZ', $groschen->getProductForm());
        $this->assertNull($groschen->getProductFormDetail());
    }

     /**
     * Test getting ProductFormFeatures
     * @return void
     */
    public function testGettingTechnicalBindingType()
    {
        // ePub 2
        $groschen = new Groschen('9789510439838');
        $this->assertNull($groschen->getTechnicalBindingType());

        // Hardback
        $groschen = new Groschen('9789510423417');
        $this->assertSame('Printed cover, glued binding', $groschen->getTechnicalBindingType());
    }

    /**
     * Test getting ProductFormFeatures
     * @return void
     */
    public function testGettingProductFormFeatures()
    {
        // Hardback should not have any product form features
        $this->assertCount(0, $this->groschen->getProductFormFeatures());

        // ePub 2
        $groschen = new Groschen('9789510383575');
        $this->assertContains(['ProductFormFeatureType' => '15', 'ProductFormFeatureValue' => '101A'], $groschen->getProductFormFeatures());

        // ePub 3
        $groschen = new Groschen('9789510414255');
        $this->assertContains(['ProductFormFeatureType' => '15', 'ProductFormFeatureValue' => '101B'], $groschen->getProductFormFeatures());
    }

    /**
     * Test getting products collections/series
     * @return void
     */
    public function testGettingCollections()
    {
        $this->assertFalse($this->groschen->getCollections()->contains('CollectionType', '10'));

        // Product with bibliographical series
        $groschen = new Groschen('9789510424810');

        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => 'Calendar Girl',
                    ],
                ],
                'CollectionSequence' => [
                    'CollectionSequenceType' => '02',
                    'CollectionSequenceNumber' => 9,
                ],
            ],
        ];

        $this->assertContains($collection, $groschen->getCollections());

        // Product with marketing series
        $groschen = new Groschen('9789510400432');

        $collection = [
            'CollectionType' => '11', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => 'Bon-pokkarit',
                    ],
                ],
            ],
        ];

        $this->assertContains($collection, $groschen->getCollections());

        // Product with extra spaces in the series name
        $groschen = new Groschen('9789520410568');

        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => 'Lumikki-kirjat',
                    ],
                ],
            ],
        ];

        $this->assertContains($collection, $groschen->getCollections());
    }

    /**
     * Test getting number in series
     * @return void
     */
    public function testGettingNumberInSeriesInCollection()
    {
        // Product with number in series
        $groschen = new Groschen('9789521618772');

        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => 'Tokyo Ghoul',
                    ],
                ],
                'CollectionSequence' => [
                    'CollectionSequenceType' => '02',
                    'CollectionSequenceNumber' => 12,
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
     * @group contributors
     * @return void
     */
    public function testGettingContributors()
    {
        // Author
        $author = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Kyrö, Tuomas',
            'NamesBeforeKey' => 'Tuomas',
            'KeyNames' => 'Kyrö',
        ];

        $this->assertContains($author, $this->groschen->getContributors());

        // Graphic designer
        $graphicDesigner = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A11',
            'PersonNameInverted' => 'Tuominen, Mika',
            'NamesBeforeKey' => 'Mika',
            'KeyNames' => 'Tuominen',
        ];

        $this->assertContains($graphicDesigner, $this->groschen->getContributors());

        // These two should be to only contributors
        $this->assertCount(2, $this->groschen->getContributors(false));

        // Product with confidential resource
        $groschen = new Groschen('9789513176457');
        $this->assertFalse($groschen->getContributors()->contains('ContributorRole', 'B21'));
    }

    /**
     * Test contributor with only one name or pseudonym is handled correctly
     * @return void
     */
    public function testContributorWithOnlyOneNameOrPseudonymIsHandledCorrectly()
    {
        $groschen = new Groschen('9789521619021');

        // Author
        $author = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Sunaakugan',
            'NamesBeforeKey' => 'Sunaakugan',
            'KeyNames' => 'Sunaakugan',
        ];

        $this->assertContains($author, $groschen->getContributors());
    }

    /**
     * Test that stakeholders with same priority are sorted by last name
     * @group contributors
     * @return void
     */
    public function testContributorAreSortedByLastname()
    {
        $groschen = new Groschen('9789513131524');

        // First author
        $firstAuthor = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Govindji, Azmina',
            'NamesBeforeKey' => 'Azmina',
            'KeyNames' => 'Govindji',
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Worrall Thompson, Anthony',
            'NamesBeforeKey' => 'Anthony',
            'KeyNames' => 'Worrall Thompson',
        ];

        $this->assertContains($secondAuthor, $groschen->getContributors());
    }

    /**
     * Test that priority contributor with same role
     * @group contributors
     * @return void
     */
    public function testContributorPriorityIsHandledCorrectly()
    {
        $groschen = new Groschen('9789510421987');

        // First author
        $firstAuthor = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Aarnio, Jari',
            'NamesBeforeKey' => 'Jari',
            'KeyNames' => 'Aarnio',
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Hänninen, Vepe',
            'NamesBeforeKey' => 'Vepe',
            'KeyNames' => 'Hänninen',
        ];

        $this->assertContains($secondAuthor, $groschen->getContributors());
    }

    /**
     * Test contributors without priority level are handled correctly
     * @return void
     */
    public function testContributorsWithoutPriorityLevelAreHandledCorrectly()
    {
        $groschen = new Groschen('9789510434123');

        // Author should be first
        $author = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonNameInverted' => 'Fredman, Virve',
            'NamesBeforeKey' => 'Virve',
            'KeyNames' => 'Fredman',
        ];

        $this->assertContains($author, $groschen->getContributors(false));
    }

    /**
     * Test that private contributors are hidden
     * @return void
     */
    public function testPrivateContributorsAreHidden()
    {
        $groschen = new Groschen('9789510415344');

        $editor = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'B01',
            'PersonNameInverted' => 'Rouhiainen, Mikko',
            'NamesBeforeKey' => 'Mikko',
            'KeyNames' => 'Rouhiainen',
        ];

        $this->assertNotContains($editor, $groschen->getContributors(false));
        $this->assertContains($editor, $groschen->getContributors(true));
    }

    /**
     * Test getting all contributors
     * @return void
     */
    public function testGettingAllContributors()
    {
        // Author
        $author = [
            'Role' => 'Author',
            'FirstName' => 'Tuomas',
            'LastName' => 'Kyrö',
        ];

        $this->assertContains($author, $this->groschen->getAllContributors());

        // Keski-Suomen Sivu
        $layout = [
            'Role' => 'Layout',
            'FirstName' => 'Keski-Suomen Sivu Oy',
            'LastName' => null,
        ];

        $this->assertContains($layout, $this->groschen->getAllContributors());

        // Printer
        $printer = [
            'Role' => 'Printer',
            'FirstName' => 'Bookwell Oy',
            'LastName' => null,
        ];

        $this->assertContains($printer, $this->groschen->getAllContributors());
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

        // Book with issue
        $groschen = new Groschen('9789510438039');
        $this->assertContains(['LanguageRole' => '01', 'LanguageCode' => 'fin'], $groschen->getLanguages());
        $this->assertCount(1, $groschen->getLanguages());

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
     * @see  https://bonnierforlagen.tpondemand.com/entity/3431-audio-book-duration-is-not-converted
     * @return void
     */
    public function testGettingExtents()
    {
        $this->assertContains(['ExtentType' => '00', 'ExtentValue' => '128', 'ExtentUnit' => '03'], $this->groschen->getExtents());
        $this->assertCount(1, $this->groschen->getExtents());

        // Book without any extents
        $groschen = new Groschen('9789510303108');
        $this->assertCount(0, $groschen->getExtents());

        // Audio book with duration
        $groschen = new Groschen('9789513194642');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '00930', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertCount(1, $groschen->getExtents());

        // Audio book with duration 0 should not return anything
        $groschen = new Groschen('9789510447871');
        $this->assertNotContains(['ExtentType' => '09', 'ExtentValue' => '00000', 'ExtentUnit' => '15'], $groschen->getExtents());
    }

    /**
     * Test getting audio books duration only with hours
     * @return void
     */
    public function testGettingAudioBookDurationOnlyWithHours()
    {
        $groschen = new Groschen('9789510442128');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '01400', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertCount(1, $groschen->getExtents());
    }

    /**
     * Test getting product text contents
     * @return void
     */
    public function testGettingTextContents()
    {
        // Check that we can find text
        $this->assertCount(1, $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00'));

        // Check that text contains description
        $this->assertContains('Kyllä minä niin mieleni pahoitin, kun aurinko paistoi.', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());

        // Check that text contains review quotes and sources
        $this->assertTrue($this->groschen->getTextContents()->contains('TextType', '06'));
        $this->assertContains('Herrajumala, en ole mistään nauttinut näin aikapäiviin! Aivan mahtavia - ja täyttä asiaa!', $this->groschen->getTextContents()->where('TextType', '06')->where('ContentAudience', '00')->pluck('Text')->first());
        $this->assertContains('Sari Orhinmaa, toimittaja', $this->groschen->getTextContents()->where('TextType', '06')->where('ContentAudience', '00')->pluck('SourceTitle')->first());

        // Product without reviews
        $groschen = new Groschen('9789510433911');
        $this->assertContains('Ulkoministerin poika Juho Nortamo saa tietää olevansa ottolapsi.', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());

        // Product without text
        $groschen = new Groschen('9789510343135');
        $this->assertFalse($groschen->getTextContents()->contains('TextType', '03'));
    }

    /**
     * Test getting the products RRP incl. VAT
     * @return void
     */
    public function testGettingPrice()
    {
        $this->assertSame(17.88, $this->groschen->getPrice());
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
     * Test getting various subjects
     * @return void
     */
    public function testGettingSubjects()
    {
        $subjects = $this->groschen->getSubjects();

        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => '1', 'SubjectHeadingText' => 'Kotimainen kauno'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => '24', 'SubjectHeadingText' => 'Nykyromaanit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC000000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'FBA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'novellit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet;kirjallisuuspalkinnot;Kiitos kirjasta -mitali;2011;kaunokirjallisuus;suomenkielinen kirjallisuus;romaanit;lyhytproosa'], $subjects);

        // Book with subjects in Allmän tesaurus på svenska
        $groschen = new Groschen('9789510374665');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '65', 'SubjectSchemeName' => 'Allmän tesaurus på svenska', 'SubjectCode' => 'krigföring'], $subjects);

        // Keywords should contain only finnish subjects
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'sota;kokemukset;sotilaat;mielenterveys;mielenterveyshäiriöt;traumat;traumaperäinen stressireaktio;psykiatrinen hoito;sotilaspsykiatria;psykiatria;psykohistoria;talvisota;jatkosota;Lapin sota;sotahistoria;Suomi;1939-1945;sotarintama'], $subjects);

        // Another book with more classifications
        $groschen = new Groschen('9789510408452');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => '4', 'SubjectHeadingText' => 'Käännetty L&N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => '31', 'SubjectHeadingText' => 'Scifi'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC028000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FL'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '80', 'SubjectSchemeName' => 'Fiktiivisen aineiston lisäluokitus', 'SubjectCode' => 'Scifi'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'YFG'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => 'N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => '5AN'], $subjects);

        // Product without library class
        $groschen = new Groschen('9789510427057');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => ''], $subjects);

        // Empty values should be filtered
        $groschen = new Groschen('9789520403294');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => ''], $subjects);
        $this->assertNotContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => ''], $subjects);

        // Product where Finna API does not return heading
        $groschen = new Groschen('9789510401378');
        $this->assertContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'äänikirjat'], $groschen->getSubjects());

        // Check that "Ellibs" is not added as a keyword
        $groschen = new Groschen('9789513170424');
        $this->assertNotContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'Ellibs'], $groschen->getSubjects());
    }

    /**
     * Test getting the products internal category
     * @return void
     */
    public function testGettingInternalCategory()
    {
        $this->assertFalse($this->groschen->getSubjects()->contains('SubjectSchemeIdentifier', '24'));

        $groschen = new Groschen('9789520418564');
        $this->assertContains(['SubjectSchemeIdentifier' => '24', 'SubjectSchemeName' => 'Internal category', 'SubjectCode' => 'valmis', 'SubjectHeadingText' => 'Valmis'], $groschen->getSubjects());
    }

    /**
     * Test product without subgroup is not throwing exception
     * @return void
     */
    public function testProductWithoutSubgroupIsNotThrowingException()
    {
        $groschen = new Groschen('9789510430347');
        $subjects = $groschen->getSubjects();
        $this->assertInstanceOf('Illuminate\Support\Collection', $subjects);
    }

    /**
     * Test getting audiences
     * @return void
     */
    public function testGettingAudiences()
    {
        // General/trade
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '01'], $this->groschen->getAudiences());
        $this->assertCount(1, $this->groschen->getAudiences());

        // Children/juvenile book
        $groschen = new Groschen('9789510429877');
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '02'], $groschen->getAudiences());
        $this->assertCount(1, $groschen->getAudiences());

        // Young adult
        $groschen = new Groschen('9789510434444');
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '03'], $groschen->getAudiences());
        $this->assertCount(1, $groschen->getAudiences());
    }

    /**
     * Test getting AudienceRanges
     * @return void
     */
    public function testGettingAudienceRanges()
    {
        // General/trade should not contain any audience ranges
        $this->assertCount(0, $this->groschen->getAudienceRanges());

        // Product with age group of 0 should be from 0 to 3
        $groschen = new Groschen('9789513181512');

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 0,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());

        // Product with age group of 5 should be mapped to 6 because of Bokinfo
        $groschen = new Groschen('9789513181512');

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 0,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());

        // Product with age group of 12 should be from 12 to 15
        $groschen = new Groschen('9789521619571');

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 12,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());

        // Product with age group of 15 should be from 15 to 18
        $groschen = new Groschen('9789510401521');

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 15,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());
    }

    /**
     * Test getting the products publisher
     * @return void
     */
    public function testGettingTheProductsPublisher()
    {
        // Normal WSOY product
        $this->assertSame('WSOY', $this->groschen->getPublisher());

        // Johnny Kniga product
        $groschen = new Groschen('9789510405314');
        $this->assertSame('WSOY', $groschen->getPublisher());

        // Normal Tammi product
        $groschen = new Groschen('9789513179564');
        $this->assertSame('Tammi', $groschen->getPublisher());

        // Manga product
        $groschen = new Groschen('9789521619779');
        $this->assertSame('Tammi', $groschen->getPublisher());
    }

    /**
     * Test getting the products publisher(s)
     * @return void
     */
    public function testGettingPublishers()
    {
        // Normal WSOY product
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'WSOY'], $this->groschen->getPublishers());

        // Johnny Kniga product
        $groschen = new Groschen('9789510405314');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'WSOY'], $groschen->getPublishers());

        // Normal Tammi product
        $groschen = new Groschen('9789513179564');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Tammi'], $groschen->getPublishers());

        // Manga product
        $groschen = new Groschen('9789521619779');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Tammi'], $groschen->getPublishers());

        // Kosmos product
        $groschen = new Groschen('9789523520189');
        $this->assertContains(['PublishingRole' => '01', 'PublisherName' => 'Kosmos'], $groschen->getPublishers());
    }

    /**
     * Test getting the products imprint
     * @return void
     */
    public function testGettingImprints()
    {
        // Normal WSOY product
        $this->assertCount(0, $this->groschen->getImprints());

        // Johnny Kniga (imprint of WSOY)
        $groschen = new Groschen('9789510405314');
        $this->assertContains(['ImprintName' => 'Johnny Kniga'], $groschen->getImprints());
    }

    /**
     * Test getting the products brands
     * @return void
     */
    public function testGettingBrands()
    {
        // Normal WSOY product
        $this->assertSame('WSOY', $this->groschen->getBrand());

        // Disney product (Tammi)
        $groschen = new Groschen('9789520416904');
        $this->assertContains('Disney', $groschen->getBrand());
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
        $groschen = new Groschen('9789513197506');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Sold out
        $groschen = new Groschen('9789510324370');
        $this->assertSame('07', $groschen->getPublishingStatus());

        // Development-confidential
        $groschen = new Groschen('9789510426159');
        $this->assertSame('00', $groschen->getPublishingStatus());

        // Cancelled
        $groschen = new Groschen('9789513189556');
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

        // Public announcement date / Season
        $this->assertContains(['PublishingDateRole' => '09', 'Date' => '2010 Spring', 'Format' => 12], $this->groschen->getPublishingDates());

        // Check that fake season 2099 N/A is not shown
        $groschen = new Groschen('9789527144404');
        $this->assertNotContains(['PublishingDateRole' => '09', 'Date' => '2099 N/A', 'Format' => 12], $groschen->getPublishingDates());

        // Latest reprint
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20171003'], $this->groschen->getPublishingDates());

        // Products with sales embargo
        $groschen = new Groschen('9789520407230');
        $this->assertContains(['PublishingDateRole' => '02', 'Date' => '20190926'], $groschen->getPublishingDates());
    }

    /**
     * Test getting latest reprint date
     * @return void
     */
    public function testGettingLatestStockArrivalDate()
    {
        // Product with only one print
        $groschen = new Groschen('9789510401514');
        $expectedArrivalDate = new DateTime('2013-05-13');
        $this->assertEquals($expectedArrivalDate, $groschen->getLatestStockArrivalDate());

        // Product with several prints
        $groschen = new Groschen('9789510374665');
        $expectedArrivalDate = new DateTime('2013-12-18');
        $this->assertEquals($expectedArrivalDate, $groschen->getLatestStockArrivalDate());

        // Product with second print having only actual date
        $groschen = new Groschen('9789510437605');
        $expectedArrivalDate = new DateTime('2018-12-04');
        $this->assertEquals($expectedArrivalDate, $groschen->getLatestStockArrivalDate());
    }

    /**
     * Test getting the latest print number
     * @return void
     */
    public function testGettingLatestPrintNumber()
    {
        $groschen = new Groschen('9789510374665');
        $this->assertSame(5, $groschen->getLatestPrintNumber());

        $groschen = new Groschen('9789510355763');
        $this->assertSame(1, $groschen->getLatestPrintNumber());
    }

    /**
     * Test getting products prices
     * @return void
     */
    public function testGettingPrices()
    {
        // Suppliers net price excluding tax
        $suppliersNetPriceExcludingTax = [
            'PriceType' => '05',
            'PriceAmount' => 16.25,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 10.0,
                'TaxableAmount' => 16.25,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Suppliers net price including tax
        $suppliersNetPriceIncludingTax = [
            'PriceType' => '07',
            'PriceAmount' => 17.88,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 10.0,
                'TaxableAmount' => 16.25,
                'TaxAmount' => 1.63,
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
     * Test that missing prices do not produce an exception
     * @return void
     */
    public function testMissingPricesDoNotProduceException()
    {
        $groschen = new Groschen('9789510442012');

        $this->assertInstanceOf('Illuminate\Support\Collection', $groschen->getPrices());
    }

    /**
     * Test getting publishers retail prices
     * @return void
     */
    public function testGettingPublishersRetailPrices()
    {
        $groschen = new Groschen('9789510348956');

        // Publishers recommended retail price including tax
        $publishersRecommendedRetailPriceIncludingTax = [
            'PriceType' => '42',
            'PriceAmount' => 25.00,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 10.0,
                'TaxableAmount' => 22.73,
                'TaxAmount' => 2.27,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($publishersRecommendedRetailPriceIncludingTax, $groschen->getPrices());
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
                        'ResourceVersionFeatureType' => '01',
                        'FeatureValue' => 'D502',
                    ],
                    [
                        'ResourceVersionFeatureType' => '02',
                        'FeatureValue' => 2398,
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => 1594,
                    ],
                    [
                        'ResourceVersionFeatureType' => '04',
                        'FeatureValue' => '9789510366264_frontcover_final.jpg',
                    ],
                    [
                        'ResourceVersionFeatureType' => '05',
                        'FeatureValue' => '1.7',
                    ],
                    [
                        'ResourceVersionFeatureType' => '06',
                        'FeatureValue' => 'd36970ebb03a0f7389d10e7377c647fc',
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 1738043,
                    ],
                    [
                        'ResourceVersionFeatureType' => '08',
                        'FeatureValue' => 'ca41d940ffb4e0cbeeee1503b4b42443f019f59ce6ef249bf24030bdabf3281a',
                    ],

                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/0lgbvE8eazaBsSZzQItlbj/*/9789510366264_frontcover_final.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q=',
            ],
        ];

        $this->assertContains($supportingResource, $this->groschen->getSupportingResources());

        // Product without cover image
        $groschen = new Groschen('9789510377161');
        $this->assertCount(0, $groschen->getSupportingResources());
    }

    /**
     * Test getting audio sample links to Soundcloud
     * @see https://bonnierforlagen.tpondemand.com/entity/3444-external-links-are-missing
     * @return void
     */
    public function testGettingExternalLinksInSupportingResources()
    {
        // Product with links to multiple external sources
        $groschen = new Groschen('9789510409749');

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
            9789510369654, // ePub
            9789510366431, // MP3
            9789510366424, // CD
            9789510407554, // Pocket book
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

        // Product without any relations
        $groschen = new Groschen('9789513160753');
        $this->assertCount(0, $groschen->getRelatedProducts());
    }

    /**
     * Getting related products without GTIN
     * @return void
     */
    public function testGettingRelatedProductsWithoutGtin()
    {
        $groschen = new Groschen('9789510433669');

        $relation = [
            'ProductRelationCode' => '06',
            'ProductIdentifiers' => [
                [
                    'ProductIDType' => '03',
                    'IDValue' => 9789510442074,
                ],
            ],
        ];

        $this->assertContains($relation, $groschen->getRelatedProducts());
    }

    /**
     * Test checking if product is confidential
     * @return void
     */
    public function testCheckingIfProductIsConfidential()
    {
        $this->assertFalse($this->groschen->isConfidential());

        // Development-confidential
        $groschen = new Groschen('9789510426159');
        $this->assertTrue($groschen->isConfidential());

        // Cancelled-Confidential
        $groschen = new Groschen('9789510446041');
        $this->assertTrue($groschen->isConfidential());
    }

    /**
     * Test checking if product is a luxury book
     * @return void
     */
    public function testCheckingIfProductIsLuxuryBook()
    {
        $this->assertFalse($this->groschen->isLuxuryBook());

        // WSOY luxury book
        $groschen = new Groschen('9789510385876');
        $this->assertTrue($groschen->isLuxuryBook());

        // Tammi luxury book
        $groschen = new Groschen('9789513195144');
        $this->assertTrue($groschen->isLuxuryBook());
    }

    /**
     * Test getting products cost center
     * @return void
     */
    public function testGettingCostCenter()
    {
        $this->assertSame(301, $this->groschen->getCostCenter());

        // Some other cost center
        $groschen = new Groschen('9789513161873');
        $this->assertSame(902, $groschen->getCostCenter());
    }

    /**
     * Test getting products cost center
     * @return void
     */
    public function testGettingCostCenterName()
    {
        $this->assertSame('Kotimainen kauno', $this->groschen->getCostCenterName());

        // Some other cost center
        $groschen = new Groschen('9789513161873');
        $this->assertSame('Tietokirjat', $groschen->getCostCenterName());
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
    public function testGettingProductsStatus()
    {
        $this->assertSame('Published', $this->groschen->getStatus());

        // Product with a different status code
        $groschen = new Groschen('9789510426159');
        $this->assertSame('Development-Confidential', $groschen->getStatus());
    }

    /**
     * Test getting the product status code
     * @return void
     */
    public function testGettingStatusCode()
    {
        $this->assertSame(2, $this->groschen->getStatusCode());

        // Product with a different status code
        $groschen = new Groschen('9789510426159');
        $this->assertSame(6, $groschen->getStatusCode());
    }

    /**
     * Test getting the number of products in the series
     * @return void
     */
    public function testGettingProductsInSeries()
    {
        //$this->assertNull($this->groschen->getProductsInSeries());

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
     * @return void
     */
    public function testCheckingIfProductIsPrintOnDemand()
    {
        //$this->assertFalse($this->groschen->isPrintOnDemand());

        // POD product
        $groschen = new Groschen('9789513170585');
        $this->assertTrue($groschen->isPrintOnDemand());
    }

    /**
     * Test getting the internal product number
     * @return void
     */
    public function testGettingInternalProdNo()
    {
        // Should be same as GTIN
        $this->assertSame('9789510366264', $this->groschen->getInternalProdNo());
    }

    /**
     * Test getting customs number
     * @return void
     */
    public function testGettingCustomsNumber()
    {
        $this->assertSame(49019900, $this->groschen->getCustomsNumber());

        // Ebooks should not have TARIC code
        $groschen = new Groschen('9789510365441');
        $this->assertNull($groschen->getCustomsNumber());

        // Audio CD's have different TARIC code
        $groschen = new Groschen('9789510344972');
        $this->assertSame(85234920, $groschen->getCustomsNumber());
    }

    /**
     * Test getting the products library class
     * @return void
     */
    public function testGettingLibraryClass()
    {
        $this->assertSame('84.2', $this->groschen->getLibraryClass());

        // Product with library class with a prefix should only return the Dewey part
        $groschen = new Groschen('9789513158699');
        $this->assertSame('84.2', $groschen->getLibraryClass());

        // Product where product does not have library class
        $groschen = new Groschen('9789521606700');
        $this->assertNull($groschen->getLibraryClass());
    }


    /**
     * Test getting the products library class
     * @return void
     */
    public function testGettingFinnishBookTradeCategorisation()
    {
        $this->assertNull($this->groschen->getFinnishBookTradeCategorisation());

        // Product with library class with a prefix
        $groschen = new Groschen('9789513158699');
        $this->assertSame('L', $groschen->getFinnishBookTradeCategorisation());

        // Product where product does not have library class
        $groschen = new Groschen('9789521606700');
        $this->assertNull($groschen->getFinnishBookTradeCategorisation());
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
        $this->assertSame('2010/1', $this->groschen->getSalesSeason());

        // Product with the fall sales season 2013/3
        $groschen = new Groschen('9789510374665');
        $this->assertSame('2013/2', $groschen->getSalesSeason());

        // Product without sales season
        $groschen = new Groschen('9789510102893');
        $this->assertNull($groschen->getSalesSeason());

        // Product that season but no period
        $groschen = new Groschen('9789513130855');
        $this->assertNull($groschen->getSalesSeason());
    }

    /**
     * Test getting products sales season
     * @return void
     */
    public function testGettingBacklistSalesSeason()
    {
        $this->assertNull($this->groschen->getBacklistSalesSeason());

        // Product with the fall sales season 2013/3
        $groschen = new Groschen('9789513129071');
        $this->assertSame('2016 Autumn', $groschen->getBacklistSalesSeason());
    }

    /**
     * Test getting sales restrictions
     * @return void
     */
    public function testGettingSalesRestrictionsSubscriptionAndLibraryExcludedProducts()
    {
        // Physical products should not have any restrictions as they are not supported yet
        $this->assertCount(0, $this->groschen->getSalesRestrictions());

        // Product that does not have subscription or library rights
        $groschen = new Groschen('9789510439555');
        $this->assertTrue($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '12'));
        $this->assertTrue($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '09'));
        $this->assertFalse($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '13'));

        // Product that has subscription and library rights
        $groschen = new Groschen('9789510445297');
        $this->assertFalse($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '12'));
        $this->assertFalse($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '09'));
        $this->assertFalse($groschen->getSalesRestrictions()->contains('SalesRestrictionType', '13'));
    }

    /**
     * Test getting sales restrictions for each outlet
     * @return void
     */
    public function testGettingSalesRestrictionsForSalesOutlet()
    {
        // ePub with unit and subscription rights but no library
        $groschen = new Groschen('9789510369654');
        $salesRestrictions = $groschen->getSalesRestrictions();
        $exclusiveRetailers = $salesRestrictions->where('SalesRestrictionType', '04')->pluck('SalesOutlets')->first();
        $retailerExceptions = $salesRestrictions->where('SalesRestrictionType', '11')->pluck('SalesOutlets')->first();

        // Check that normal unit sales library exists, but library not
        $salesOutlet = [
            'SalesOutlet' => [
              'SalesOutletIdentifiers' => [
                  [
                    'SalesOutletIDType' => '03',
                    'IDValue' => 'ELL',
                  ],
                ],
            ],
        ];

        $library = [
            'SalesOutlet' => [
              'SalesOutletIdentifiers' => [
                  [
                    'SalesOutletIDType' => '03',
                    'IDValue' => 'ELL',
                  ],
              ],
            ],
        ];

        // Normal unit sales channel should appear in exclusive retailers, not in exceptions
        $this->assertContains($salesOutlet, $exclusiveRetailers);
        $this->assertNotContains($salesOutlet, $retailerExceptions);

        // Library should appear on exceptions and not in exclusive retailers
        $this->assertNotContains($library, $exclusiveRetailers);
        $this->assertContains($library, $retailerExceptions);
    }

    /**
     * Test getting sales restrictions for subscription only product
     * @return void
     */
    public function testGettingSalesRestrictionsForSubscriptionOnlyProduct()
    {
        $groschen = new Groschen('9789510446126');
        $salesRestrictions = $groschen->getSalesRestrictions();

        // Should have "Not for sale to libraries"
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '09'));

        // Should have "Subscription services only"
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '13'));
    }

    /**
     * Test getting the products tax rate
     * @return void
     */
    public function testGettingTaxRate()
    {
        // Hardback
        $this->assertSame(10.00, $this->groschen->getTaxRate());

        // Digital product
        $groschen = new Groschen('9789510435199');
        $this->assertSame(10.00, $groschen->getTaxRate());

        // Product with 24% VAT
        $groschen = new Groschen('9789510338728');
        $this->assertSame(24.00, $groschen->getTaxRate());
    }

    /**
     * Get the distribution channels in Opus
     * @return void
     */
    public function testGettingDistributionChannels()
    {
        // ePub 2 unit sales only product
        $groschen = new Groschen('9789510417188');

        $elisa = $groschen->getDistributionChannels()
            ->where('channel', 'Elisa Kirja')
            ->where('channelType', 'Unit sales')
            ->where('hasRights', true)
            ->where('distributionAllowed', true);

        $this->assertCount(1, $elisa->toArray());

        $bookbeat = $groschen->getDistributionChannels()
            ->where('channel', 'BookBeat')
            ->where('channelType', 'Subscription')
            ->where('hasRights', true)
            ->where('distributionAllowed', true);

        $this->assertCount(0, $bookbeat->toArray());
    }

    /**
     * Test the is connected to ERP
     * @return void
     */
    public function testIfProductIsConnectedToErp()
    {
        $this->assertTrue($this->groschen->isConnectedToErp());

        // Product that is not connected to ERP
        $groschen = new Groschen('9789510443781');
        $this->assertFalse($groschen->isConnectedToErp());
    }

    /**
     * Test getting print orders and their recipients
     * @return void
     */
    public function testGettingPrintOrders()
    {
        $groschen = new Groschen('9789510383124');
        $firstPrint = $groschen->getPrintOrders()->where('printNumber', 1)->first();

        dd($groschen->getPrintOrders());

        $this->assertSame(4350, $firstPrint['orderedQuantity']);

        // Delivery without planned delivery date
        $this->assertSame('ScandBook / Liettua', $firstPrint['deliveries']->where('recipient', 'Production department')->pluck('supplier')->first());
        $this->assertSame(5, $firstPrint['deliveries']->where('recipient', 'Production department')->pluck('orderedQuantity')->first());
        $this->assertNull($firstPrint['deliveries']->where('recipient', 'Production department')->pluck('plannedDeliveryDate')->first());

        // Delivery with planned delivery date
        $this->assertSame('ScandBook / Liettua', $firstPrint['deliveries']->where('recipient', 'Warehouse')->pluck('supplier')->first());
        $this->assertSame(750, $firstPrint['deliveries']->where('recipient', 'Warehouse')->pluck('orderedQuantity')->first());
        $this->assertSame('2019-05-13T00:00:00', $firstPrint['deliveries']->where('recipient', 'Warehouse')->pluck('plannedDeliveryDate')->first());
    }

    /**
     * Test if product is main edition
     * @return void
     */
    public function testIfProductIsMainEdition()
    {
        // Main edition
        $groschen = new Groschen('9789510389997');
        $this->assertTrue($groschen->isMainEdition());

        // Product that is not main edition
        $groschen = new Groschen('9789510409169');
        $this->assertFalse($groschen->isMainEdition());
    }

    /**
     * Test if product is internet edition
     * @return void
     */
    public function testIfProductIsInternetEdition()
    {
        // Internet edition
        $groschen = new Groschen('9789510390795');
        $this->assertTrue($groschen->isInternetEdition());

        // Product that is not internet edition
        $groschen = new Groschen('9789510390016');
        $this->assertFalse($groschen->isInternetEdition());
    }

    /**
     * Test getting production plan
     * @return void
     */
    public function testGettingProductionPlan()
    {
        $groschen = new Groschen('9789510423417');
        $productionPlan = $groschen->getProductionPlan();

        $plannedDate = $productionPlan->where('print', 1)->where('name', 'Delivery to warehouse')->pluck('planned_date')->first();
        $expectedDate = new DateTime('2019-03-11');

        $this->assertSame($plannedDate->format('Y-m-d'), $expectedDate->format('Y-m-d'));
    }

    /**
     * Test getting comments
     * @return void
     */
    public function testGettingTechnicalDescriptionComment()
    {
        $groschen = new Groschen('9789520405786');
        $this->assertSame('PMS on cover PMS 322 C.', $groschen->getTechnicalDescriptionComment());
    }

    /**
     * Test getting technical printing data
     * @return void
     */
    public function testGettingTechnicalData()
    {
        $groschen = new Groschen('9789520405786');

        // Insides
        $inside = [
            'partName' => 'inside',
            'width' => 135,
            'height' => 215,
            'paperType' => null,
            'paperName' => 'HOLBFSC',
            'grammage' => 70,
            'bulk' => 'Other',
            'bulkValue' => '1,8',
            'colors' => '1/1',
            'colorNames' => null,
            //'hasPhotoSection' => false,
            //'photoSectionExtent' => null,
            'numberOfPages' => 300,
        ];

        $this->assertContains($inside, $groschen->getTechnicalData());

        // Case
        $case = [
            'partName' => 'case',
            'coverMaterial' => 'GEL-LS-191 black',
            'foil' => 'Kurz Luxor 220 gold',
            'embossing' => false,
            'foilPlacement' => null,
        ];

        $this->assertContains($case, $groschen->getTechnicalData());

        // Printed cover
        $printedCover = [
            'partName' => 'printedCover',
            'paperType' => 'Other',
            'paperName' => 'GEL',
            'grammage' => 115,
            'colors' => '0/0',
            'colorNames' => null,
            'foil' => null,
            'hasBlindEmbossing' => false,
            'hasUvSpotVarnishGlossy' => false,
            'hasUvSpotVarnishMatt' => false,
            'hasDispersionVarnish' => false,
            'hasReliefSpotVarnish' => false,
            'placement' => null,
            'lamination' => null,
        ];

        $this->assertContains($printedCover, $groschen->getTechnicalData());

        // Dust jacket
        $dustJacket = [
            'partName' => 'dustJacket',
            'paperType' => 'Other',
            'paperName' => 'ARTG130',
            'grammage' => 130,
            'colors' => '5/0',
            'colorNames' => null,
            'foil' => 'Yes',
            'hasBlindEmbossing' => false,
            'hasUvSpotVarnishGlossy' => false,
            'hasUvSpotVarnishMatt' => false,
            'hasDispersionVarnish' => false,
            'hasReliefSpotVarnish' => false,
            'placement' => null,
            'lamination' => 'Matt lamination',
        ];

        $this->assertContains($dustJacket, $groschen->getTechnicalData());

        // Soft cover
        $softCover = [
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
        ];

        $this->assertContains($softCover, $groschen->getTechnicalData());

        // End papers
        $endPapers = [
            'partName' => 'endPapers',
            'paperType' => 'Other',
            'paperName' => 'MUNPC115_15',
            'grammage' => 115,
            'colors' => 'Other',
            'colorNames' => '4+0',
            'selfEnds' => false,
        ];

        $this->assertContains($endPapers, $groschen->getTechnicalData());

        // Book binding
        $bookBinding = [
            'partName' => 'bookBinding',
            'bindingType' => 'GLUED',
            'boardThickness' => 2.0,
            'headBand' => 'black',
            'ribbonMarker' => null,
            'spineType' => 'Rounded',
            'spideWidth' => 21,
            'clothedSpineMaterial' => null,
            'comments' => 'Kannen mittapiirros Saara S/Sanna U  5.11.18'
        ];

        $this->assertContains($bookBinding, $groschen->getTechnicalData());
    }

    /**
     * Test getting technical printing data for image attachment
     * @return void
     */
    public function testGettingAttachmentTechnicalData()
    {
        $groschen = new Groschen('9789510439203');

        // Attachment
        $attachment = [
            'partName' => 'attachment',
            'paperType' => 'Wood-free coated',
            'paperName' => 'G-Print',
            'grammage' => 115,
            'numberOfPages' => 16,
            'colors' => '4/4',
            'colorNames' => null,
        ];

        $this->assertContains($attachment, $groschen->getTechnicalData());
    }

    /**
     * Test getting technical data for a project that does not have any does not throw exception
     * @return void
     */
    public function testGettingTechnicalDataForProductWithoutAnyDoesNotThrowException()
    {
        $groschen = new Groschen('9789510429938');
        $this->assertCount(8, $groschen->getTechnicalData());
    }

    /**
     * Test getting products prizes
     * @return void
     */
    public function testGettingPrizes()
    {
        // Product without awards
        $this->assertCount(0, $this->groschen->getPrizes());

        // Product that is winner of a prize/award
        $groschen = new Groschen('9789510382745');

        $prizes = [
            'PrizeName' => 'Finlandia-palkinto',
            'PrizeCode' => '01',
        ];

        $this->assertContains($prizes, $groschen->getPrizes());

        // Product that is nominee for a prize/award
        $groschen = new Groschen('9789520401603');

        $prizes = [
            'PrizeName' => 'Finlandia-palkinto',
            'PrizeCode' => '07',
        ];

        $this->assertContains($prizes, $groschen->getPrizes());
    }

    /**
     * Test getting product availability
     * @return void
     */
    public function testGettingProductAvailability()
    {
        // Development, digital and publishing date is in the future
        $groschen = new Groschen('9789510438343');
        $this->assertSame('10', $groschen->getProductAvailability());

        // Published, digital and publishing date is in the future
        $groschen = new Groschen('9789510442425');
        $this->assertSame('10', $groschen->getProductAvailability());

        // Development, digital and publishing date is in the past
        $groschen = new Groschen('9789510420157');
        $this->assertSame('21', $groschen->getProductAvailability());

        // Published, digital and publishing date is in the past
        $groschen = new Groschen('9789513151409');
        $this->assertSame('21', $groschen->getProductAvailability());

        // Cancelled digital product
        $groschen = new Groschen('9789510384763');
        $this->assertSame('01', $groschen->getProductAvailability());

        // Published physical product that has stock
        $groschen = new Groschen('9789513140045');
        $this->assertSame('21', $groschen->getProductAvailability());

        // Published physical product that does not have stock and no planned reprint date
        $groschen = new Groschen('9789521610509');
        $this->assertSame('31', $groschen->getProductAvailability());

        // Published physical product that does not have stock but reprint is coming
        $groschen = new Groschen('9789513122225');
        $this->assertSame('30', $groschen->getProductAvailability());

        // Short-run product with stock
        $groschen = new Groschen('9789510407356');
        $this->assertSame('21', $groschen->getProductAvailability());

        // Short-run product without any stock
        $groschen = new Groschen('9789510409701');
        $this->assertSame('21', $groschen->getProductAvailability());

        // Development-confidential should return 40
        $groschen = new Groschen('9789510369401');
        $this->assertSame('40', $groschen->getProductAvailability());

        // Development, publishing date in the future
        $groschen = new Groschen('9789510412626');
        $this->assertSame('10', $groschen->getProductAvailability());

        // Exclusive sales
        $groschen = new Groschen('9789510408513');
        $this->assertSame('22', $groschen->getProductAvailability());
    }

    /**
     * Test if the publication date passed is handled correctly
     * @return void
     */
    public function testIfPublicationDateIsPassed()
    {
        $groschen = new Groschen('9789513178888');
        $this->assertFalse($groschen->isPublicationDatePassed());

        $groschen = new Groschen('9789520401122');
        $this->assertTrue($groschen->isPublicationDatePassed());
    }

    /**
     * Test getting products stocks
     * @return void
     */
    public function testGettingStocks()
    {
        // Product with stock
        $stock = [
            'LocationIdentifier' => [
                'LocationIDType' => '06',
                'IDValue' => '6430049920009',
            ],
            'LocationName' => 'Porvoon Kirjakeskus / Tarmolan päävarasto',
            'OnHand' => 100,
            'Proximity' => '07',
        ];

        $this->assertContains($stock, $this->groschen->getStocks());

        // Digital product should not return anything
        $groschen = new Groschen('9789510420157');
        $this->assertCount(0, $groschen->getStocks());
    }

    /**
     * Test getting SupplyDates
     * @return void
     */
    public function testGettingSupplyDates()
    {
        // Product with stock
        $supplyDate = [
            'SupplyDateRole' => '08',
            'Date' => '20171003',
        ];

        $this->assertCount(1, $this->groschen->getSupplyDates());
        $this->assertContains($supplyDate, $this->groschen->getSupplyDates());

        // Digital product should not return anything
        $groschen = new Groschen('9789510420157');
        $this->assertCount(0, $groschen->getSupplyDates());
    }

    /**
     * Test getting all contacts
     * @return void
     */
    public function testGettingContacts()
    {
        $contact = [
            'firstName' => 'Veikko',
            'lastName' => 'Neuvonen',
            'supplierId' => 20004662,
        ];

        $this->assertContains($contact, $this->groschen->getContacts());
    }

    /**
     * Test getting all editions
     * @return void
     */
    public function testGettingEditions()
    {
        $edition = [
            'isbn' => 9789520411299,
            'title' => 'Vallasrouva',
        ];

        $this->assertContains($edition, $this->groschen->getEditions());
    }

    /**
     * Test getting web publishing dates
     * @return void
     */
    public function testGettingWebPublishingDates()
    {
        // Product with start date but with no end date
        $expectedWebPublishingStartDate = new DateTime('2014-08-15');
        $this->assertEquals($expectedWebPublishingStartDate, $this->groschen->getWebPublishingStartDate());
        $this->assertNull($this->groschen->getWebPublishingEndDate());

        // Product with both dates
        $groschen = new Groschen('9789510240243');
        $expectedWebPublishingStartDate = new DateTime('2019-02-21');
        $expectedWebPublishingEndDate = new DateTime('2019-02-25');
        $this->assertEquals($expectedWebPublishingStartDate, $groschen->getWebPublishingStartDate());
        $this->assertEquals($expectedWebPublishingEndDate, $groschen->getWebPublishingEndDate());
    }

    /**
     * Test getting editions comments
     * @return void
     */
    public function testGettingComments()
    {
        $groschen = new Groschen('9789520404338');
        $comments = $groschen->getComments();

        $this->assertContains(['type' => 'general', 'comment' => 'Waterbased varnish glossy to cover and inside!'], $comments);
        $this->assertFalse($comments->contains('type', 'insert/cover material'), $comments);
        $this->assertContains(['type' => 'print order', 'comment' => 'Your offer no. 6100745'], $comments);
        $this->assertContains(['type' => 'price', 'comment' => '2 x 2000 cps 1,11 / kpl. for 2 x 2.500 copies would be 0,98 EURO/cop. = 4.900,00 EURO'], $comments);
        $this->assertFalse($comments->contains('type', 'rights'), $comments);
    }

    /**
     * Test getting products sales status
     * @return void
     */
    public function testGettingSalesStatus()
    {
        //$this->assertNull($this->groschen->getSalesStatus());

        $groschen = new Groschen('9789510433058');
        $this->assertSame('Star', $groschen->getSalesStatus());
    }
}
