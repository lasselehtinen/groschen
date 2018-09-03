<?php
namespace lasselehtinen\Groschen\Test;

use Exception;
use lasselehtinen\Groschen\Groschen;
use DateTime;

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

        // Product without media type should return null
        $groschen = new Groschen('9789513154097');
        $this->assertNull($groschen->getProductForm());
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
     * Test getting number in series
     * @return void
     */
    public function testGettingNumberInSeriesInCollection()
    {
        // Product with number in series
        $groschen = new Groschen('9789521618772');

        $collection = [
            'CollectionType' => '10', [
                'CollectionSequence' => [
                    'CollectionSequenceType' => '03',
                    'CollectionSequenceNumber' => '12',
                ],
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '01',
                        'TitleText' => 'Tokyo Ghoul',
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
     * @group contributors
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

        // Product with confidential resource
        $groschen = new Groschen('9789513176457');
        $this->assertFalse($groschen->getContributors()->contains('ContributorRole', 'B21'));
    }

    /**
     * Test that stakeholders with same priority are sorted by last name
     * @group contributors
     * @return void
     */
    public function testContributorAreSortedByLastname()
    {
        $groschen = new Groschen('9789510370469');

        // First author
        $firstAuthor = [
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20000034',
            ],
            'PersonNameInverted' => 'Aho, Tuulia',
            'NamesBeforeKey' => 'Tuulia',
            'KeyNames' => 'Aho',
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20001253',
            ],
            'PersonNameInverted' => 'Kurjenluoma, Minna',
            'NamesBeforeKey' => 'Minna',
            'KeyNames' => 'Kurjenluoma',
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
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20006825',
            ],
            'PersonNameInverted' => 'Aarnio, Jari',
            'NamesBeforeKey' => 'Jari',
            'KeyNames' => 'Aarnio',
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Creditor number',
                'IDValue' => '20000867',
            ],
            'PersonNameInverted' => 'Hänninen, Vepe',
            'NamesBeforeKey' => 'Vepe',
            'KeyNames' => 'Hänninen',
        ];

        $this->assertContains($secondAuthor, $groschen->getContributors());
    }

    /**
     * Test that private contributors are hidden
     * @return void
     */
    public function testPrivateContributorsAreHidden() {
        $groschen = new Groschen('9789510412893');

        $editor = [
            'SequenceNumber' => 3,
            'ContributorRole' => 'B21',
            'NameIdentifier' => [
                'NameIDType' => '01',
                'IDTypeName' => 'Resource ID',
                'IDValue' => 'SAMKNU',
            ],
            'PersonNameInverted' => 'Knuuti, Samuli',
            'NamesBeforeKey' => 'Samuli',
            'KeyNames' => 'Knuuti',
        ];

        $this->assertNotContains($editor, $groschen->getContributors(false));
        $this->assertContains($editor, $groschen->getContributors(true));
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

        // Product without text
        $groschen = new Groschen('9789510343135');
        $this->assertFalse($groschen->getTextContents()->contains('TextType', '03'));

        // Product without texts return value in the SOAP response
        $groschen = new Groschen('9789510403969');
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
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Main product group', 'SubjectCode' => 'Kotimainen kauno'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Bonnier Books Finland - Product sub-group', 'SubjectCode' => 'Nykyromaanit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC000000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'FBA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'novellit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet;kirjallisuuspalkinnot;Kiitos kirjasta -mitali;2011;novellit;huumori;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;pessimismi;suomalaisuus;suomalaiset;miehet;kirjallisuuspalkinnot;Kiitos kirjasta -mitali;2011;novellit;pakinat;monologit;eläkeläiset;mielipiteet;pessimismi;miehet'], $subjects);

        // Book with subjects in Allmän tesaurus på svenska
        $groschen = new Groschen('9789510374665');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '65', 'SubjectSchemeName' => 'Allmän tesaurus på svenska', 'SubjectCode' => 'krigföring'], $subjects);

        // Keywords should contain only finnish subjects
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'sota;kokemukset;sotilaat;sotilaat;mielenterveys;mielenterveyshäiriöt;sota;traumat;sota;traumaperäinen stressireaktio;sotilaat;psykiatrinen hoito;sotilaat;sotilaspsykiatria;psykiatria;sota;psykohistoria;talvisota;jatkosota;Lapin sota;sotahistoria;1939-1945;Suomi;sota;kokemukset;sotilaat;sotilaat;mielenterveys;mielenterveyshäiriöt;sota;traumat;sota;traumaperäinen stressireaktio;sotilaat;psykiatrinen hoito;sotilaat;sotilaspsykiatria;psykiatria;sota;psykohistoria;talvisota;jatkosota;Lapin sota;sotahistoria;1939-1945;Suomi;sotarintama;sota;kokemukset;sotilaat;mielenterveys;mielenterveyshäiriöt;traumat;traumaperäinen stressireaktio;psykiatrinen hoito;sotilaspsykiatria;psykiatria;psykohistoria;talvisota;jatkosota;Lapin sota;sotahistoria'], $subjects);

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

        // Product without library class
        $groschen = new Groschen('9789510353189');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => ''], $subjects);

        // Empty values should be filtered
        $groschen = new Groschen('9789520403294');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => ''], $subjects);
        $this->assertNotContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => ''], $subjects);

        // Product where Finna API does not return heading
        $groschen = new Groschen('9789510401378');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'äänikirjat'], $subjects);
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
                [
                    'AudienceRangePrecision' => '04', // To
                    'AudienceRangeValue' => 3,
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
                [
                    'AudienceRangePrecision' => '04', // To
                    'AudienceRangeValue' => 15,
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
                [
                    'AudienceRangePrecision' => '04', // To
                    'AudienceRangeValue' => 18,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());
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
     * Test getting the products imprint
     * @return void
     */
    public function testGettingImprints()
    {
        // Normal WSOY product
        $this->assertCount(0, $this->groschen->getImprints());

        // Johnny Kniga (imprint of WSOY)
        $groschen = new Groschen('9789510379325');
        $this->assertContains(['ImprintName' => 'Johnny Kniga'], $groschen->getImprints());
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
        $groschen = new Groschen('6430027858348');
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
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20171003'], $this->groschen->getPublishingDates());

        // Product without original publishing date
        $groschen = new Groschen('9789513199173');
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '23191231'], $groschen->getPublishingDates());
        $this->assertCount(1, $groschen->getPublishingDates());

        // Product without any dates
        $groschen = new Groschen('9789513164232');
        $this->assertCount(0, $groschen->getPublishingDates());
    }

    /**
     * Test getting latest reprint date
     * @return void
     */
    public function testGettingLatestStockArrivalDate() {
        // Product with only one print
        $groschen = new Groschen('9789510401514');
        $expectedArrivalDate = new DateTime('2013-05-13');
        $this->assertEquals($expectedArrivalDate, $groschen->getLatestStockArrivalDate());

        // Product with several prints
        $groschen = new Groschen('9789510374665');
        $expectedArrivalDate = new DateTime('2013-12-18');
        $this->assertEquals($expectedArrivalDate, $groschen->getLatestStockArrivalDate());
    }

    /**
     * Test getting products prices
     * @return void
     */
    public function testGettingPrices()
    {
        // Supplierâ€™s net price excluding tax
        $suppliersNetPriceExcludingTax = [
            'PriceType' => '05',
            'PriceAmount' => 16.25,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 10,
                'TaxableAmount' => 16.25,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Supplierâ€™s net price including tax
        $suppliersNetPriceIncludingTax = [
            'PriceType' => '07',
            'PriceAmount' => 17.88,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 10,
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
     * Test getting products prices for product with missing prices and 24% VAT
     * @return void
     */
    public function testGettingPricesForProductWithMissingPrice()
    {
        $groschen = new Groschen('9789510353318');

        // RRP excluding tax
        $suppliersNetPriceExcludingTax = [
            'PriceType' => '05',
            'PriceAmount' => 20.32,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'Z',
                'TaxRatePercent' => 24,
                'TaxableAmount' => 20.32,
                'TaxAmount' => 0,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($suppliersNetPriceExcludingTax, $groschen->getPrices());

        // Should not have PriceType 02
        $this->assertFalse($groschen->getPrices()->contains('PriceType', '02'));
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
                        'FeatureValue' => 'c4204770c25afca3d98f629f1916d3f2',
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 1738006,
                    ],
                    [
                        'ResourceVersionFeatureType' => '08',
                        'FeatureValue' => '019fd7ee76362bf683e36aa89351cd54cb55ec89b3d035da645170cbba91307f',
                    ],

                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/0lgbvE8eazaBsSZzQItlbj/*/9789510366264_frontcover_final.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q=',
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
        $groschen = new Groschen('6430060030237');
        $this->assertCount(0, $groschen->getRelatedProducts());
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
        $this->assertSame(902, $groschen->getCostCenter());

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
     * @return void
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
     * @return void
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
     * @return void
     */
    public function testGettingCustomsNumber()
    {
        $this->assertSame(49019900, $this->groschen->getCustomsNumber());

        // Product with different customs number
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
        $this->assertSame('2010/1', $this->groschen->getSalesSeason());

        // Product without sales season
        $groschen = new Groschen('9789510102893');
        $this->assertNull($groschen->getSalesSeason());
    }

}