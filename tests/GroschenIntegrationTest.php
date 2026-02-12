<?php

namespace lasselehtinen\Groschen\Test;

use DateTime;
use Exception;
use lasselehtinen\Groschen\Groschen;

class GroschenIntegrationTest extends TestCase
{
    private $groschen;

    protected function setUp(): void
    {
        parent::setUp();

        $groschen = new Groschen('9789510366264');

        $this->groschen = $groschen;
    }

    /**
     * Test that non-existing product throws exception
     *
     * @return void
     */
    public function test_non_existing_product_throws_exception()
    {
        $this->expectException(Exception::class);

        $groschen = new Groschen('foobar');
    }

    /**
     * Test that deactivated product is fetched also
     *
     * @return void
     */
    public function test_deactivated_product_works_fine()
    {
        $groschen = new Groschen('9789510439555');
        $this->assertSame('00', $this->groschen->getProductComposition());
    }

    /**
     * Test getting work and edition id
     *
     * @return void
     */
    public function test_getting_work_and_edition_id()
    {
        $ids = $this->groschen->getEditionAndWorkId();
        $this->assertCount(2, $ids);
        $this->assertSame('243763', $ids[0]);
        $this->assertSame('243764', $ids[1]);
    }

    /**
     * Test getting all products identifiers
     *
     * @return void
     */
    public function test_getting_product_identifiers()
    {
        // Product with valid GTIN/EAN/ISBN13
        $this->assertContains(['ProductIDType' => '01', 'id_type_name' => 'Werner Söderström Ltd - Internal product number', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '03', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '15', 'id_value' => 9789510366264], $this->groschen->getProductIdentifiers());

        // Product with valid GTIN but no ISBN-13
        $groschen = new Groschen('6430060032187');
        $this->assertContains(['ProductIDType' => '01', 'id_type_name' => 'Werner Söderström Ltd - Internal product number', 'id_value' => 6430060032187], $groschen->getProductIdentifiers());
        $this->assertContains(['ProductIDType' => '03', 'id_value' => 6430060032187], $groschen->getProductIdentifiers());
        $this->assertNotContains(['ProductIDType' => '15', 'id_value' => 6430060032187], $groschen->getProductIdentifiers());
    }

    /**
     * Test getting products composition
     *
     * @return void
     */
    public function test_getting_product_composition()
    {
        // Normal trade item
        $this->assertSame('00', $this->groschen->getProductComposition());
    }

    /**
     * Test getting products type
     *
     * @return void
     */
    public function test_getting_product_type()
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
     *
     * @return void
     */
    public function test_getting_product_form_and_product_form_details()
    {
        // Hardback / glued binding
        $groschen = new Groschen('9789510405314');
        $this->assertSame('BB', $groschen->getProductForm());
        $this->assertContains('B305', $groschen->getProductFormDetails());
        $this->assertContains('B407', $groschen->getProductFormDetails());

        // Hardback / sewn binding
        $groschen = new Groschen('9789510496886');
        $this->assertSame('BB', $groschen->getProductForm());
        $this->assertContains('B304', $groschen->getProductFormDetails());
        $this->assertContains('B407', $groschen->getProductFormDetails());

        // Saddle-stitched
        $groschen = new Groschen('9789524101301');
        $this->assertSame('BF', $groschen->getProductForm());
        // $this->assertContains('B310', $groschen->getProductFormDetails());

        // Pocket book
        $groschen = new Groschen('9789510362938');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertContains('B104', $groschen->getProductFormDetails());

        // Spiral bound
        $groschen = new Groschen('9789513147013');
        $this->assertSame('BE', $groschen->getProductForm());
        $this->assertEmpty($groschen->getProductFormDetails());

        // Flex
        $groschen = new Groschen('9789510425855');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertContains('B116', $groschen->getProductFormDetails());

        // Board book
        $groschen = new Groschen('9789521609336');
        $this->assertSame('BH', $groschen->getProductForm());
        $this->assertEmpty($groschen->getProductFormDetails());

        // ePub2
        $groschen = new Groschen('9789513199388');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertContains('E101', $groschen->getProductFormDetails());
        $this->assertNotContains('101A', $groschen->getProductFormDetails());

        // ePub3 without audio
        $groschen = new Groschen('9789520441869');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertContains('E101', $groschen->getProductFormDetails());
        $this->assertNotContains('A305', $groschen->getProductFormDetails());

        // ePub3 with additional audio
        $groschen = new Groschen('9789510491263');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertContains('E101', $groschen->getProductFormDetails());
        $this->assertContains('A305', $groschen->getProductFormDetails());

        // Application
        $groschen = new Groschen('9789510392263');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertEmpty($groschen->getProductFormDetails());

        // Downloadable audio file
        $groschen = new Groschen('9789510428412');
        $this->assertSame('AJ', $groschen->getProductForm());
        $this->assertContains('A103', $groschen->getProductFormDetails());

        // CD
        $groschen = new Groschen('9789510379110');
        $this->assertSame('AC', $groschen->getProductForm());
        $this->assertContains('A101', $groschen->getProductFormDetails());

        // MP3-CD
        $groschen = new Groschen('9789520402983');
        $this->assertSame('AE', $groschen->getProductForm());
        $this->assertContains('A103', $groschen->getProductFormDetails());

        // Paperback
        $groschen = new Groschen('9789510382745');
        $this->assertSame('BC', $groschen->getProductForm());
        $this->assertContains('B305', $groschen->getProductFormDetails());

        // Picture-and-audio book
        $groschen = new Groschen('9789510429945');
        $this->assertSame('ED', $groschen->getProductForm());
        $this->assertContains('A305', $groschen->getProductFormDetails());

        // PDF e-book
        $groschen = new Groschen('9789510422281');
        $this->assertSame('EA', $groschen->getProductForm());
        $this->assertContains('E107', $groschen->getProductFormDetails());

        // Miscellaneous
        $groschen = new Groschen('6430060034020');
        $this->assertSame('ZZ', $groschen->getProductForm());
        $this->assertEmpty($groschen->getProductFormDetails());

        // Product with headband
        $groschen = new Groschen('9789523825741');
        $this->assertSame('BB', $groschen->getProductForm());
        $this->assertContains('B407', $groschen->getProductFormDetails());

        // Product with decorated endpapers
        $groschen = new Groschen('9789524031394');
        $this->assertSame('BB', $groschen->getProductForm());
        $this->assertContains('B408', $groschen->getProductFormDetails());

        // Marketing material
        $groschen = new Groschen('9789513172664');
        $this->assertSame('ZZ', $groschen->getProductForm());
        $this->assertEmpty($groschen->getProductFormDetails());

        // eBook that originally had B407 - Head and tail bands
        $groschen = new Groschen('9789510379622');
        $this->assertNotContains('B407', $groschen->getProductFormDetails());

        // Paperback with flaps
        $groschen = new Groschen('9789510497913');
        $this->assertContains('B504', $groschen->getProductFormDetails());

        // Dust jacket
        $groschen = new Groschen('9789520461102');
        $this->assertContains('B502', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510434222');
        $this->assertContains('B502', $groschen->getProductFormDetails());
    }

    /**
     * Spesific test for getting all kinds of product form details
     *
     * @return void
     */
    public function test_getting_product_form_details()
    {
        $groschen = new Groschen('9789523765573');
        $this->assertContains('B104', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523763807');
        $this->assertContains('B305', $groschen->getProductFormDetails());
        // $this->assertContains('B501', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789524034302');
        $this->assertContains('B104', $groschen->getProductFormDetails());
        $this->assertContains('B305', $groschen->getProductFormDetails());

        // TODO - Missing production planning
        // $groschen = new Groschen('9789522793041');
        // $this->assertContains('B504', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523766273');
        $this->assertContains('B305', $groschen->getProductFormDetails());

        // TODO - Is not really sewn?
        $groschen = new Groschen('9789522796677');
        // $this->assertContains('B304', $groschen->getProductFormDetails());
        // $this->assertContains('B501', $groschen->getProductFormDetails());

        // TODO - No stationary information
        $groschen = new Groschen('6430060033092');
        $this->assertContains('B506', $groschen->getProductFormDetails());
        // $this->assertContains('P101', $groschen->getProductFormDetails());
        // $this->assertContains('P201', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789522796257');
        $this->assertContains('B305', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789524032339');
        $this->assertContains('B116', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523760295');
        $this->assertContains('B116', $groschen->getProductFormDetails());
        $this->assertContains('B504', $groschen->getProductFormDetails());

        // TODO - Production planning is missing
        // $groschen = new Groschen('9789522792785');
        // $this->assertContains('B304', $groschen->getProductFormDetails());

        $groschen = new Groschen('6430060033108');
        $this->assertContains('P201', $groschen->getProductFormDetails());

        // TODO - Incorrect B116?
        $groschen = new Groschen('6430060033061');
        // $this->assertContains('B116', $groschen->getProductFormDetails());
        // $this->assertContains('B504', $groschen->getProductFormDetails());
        // $this->assertContains('B506', $groschen->getProductFormDetails());

        $groschen = new Groschen('6430060032729');
        $this->assertContains('P202', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789524032377');
        $this->assertContains('B407', $groschen->getProductFormDetails());
        $this->assertContains('B305', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510480168');
        $this->assertContains('B407', $groschen->getProductFormDetails());
        $this->assertContains('B304', $groschen->getProductFormDetails());

        // TODO - KV has incorrect ProductFormDetail?
        // $groschen = new Groschen('9789510468043');
        // $this->assertContains('B310', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523522336');
        $this->assertContains('B506', $groschen->getProductFormDetails());

        // TODO - Information not in Mockingbird (Flipbook)
        $groschen = new Groschen('9789523522008');
        // $this->assertContains('B601', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523521148');
        $this->assertContains('B504', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523521834');
        $this->assertContains('B305', $groschen->getProductFormDetails());
        $this->assertNotContains('B504', $groschen->getProductFormDetails());

        $groschen = new Groschen('6430060036048');
        $this->assertContains('B506', $groschen->getProductFormDetails());
        $this->assertContains('P201', $groschen->getProductFormDetails());

        $groschen = new Groschen('6430060032743');
        $this->assertContains('P201', $groschen->getProductFormDetails());
        $this->assertContains('B407', $groschen->getProductFormDetails());
        $this->assertContains('B304', $groschen->getProductFormDetails());

        // TODO - Production planning does not structured data
        $groschen = new Groschen('9789524101301');
        // $this->assertContains('B201', $groschen->getProductFormDetails());
        // $this->assertContains('B310', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789523753129');
        $this->assertContains('B304', $groschen->getProductFormDetails());
        $this->assertContains('B504', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789513169947');
        // $this->assertContains('B221', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789520433864');
        // $this->assertContains('B310', $groschen->getProductFormDetails());
        $this->assertContains('B504', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789520461720');
        // $this->assertContains('B208', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789520413705');
        // $this->assertContains('B201', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789513187033');
        // $this->assertContains('B501', $groschen->getProductFormDetails());
        $this->assertContains('B506', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789513185046');
        $this->assertContains('B304', $groschen->getProductFormDetails());
        // $this->assertContains('B501', $groschen->getProductFormDetails());
        $this->assertContains('B506', $groschen->getProductFormDetails());

        // TODO - Not enough data
        // $groschen = new Groschen('6430061220408');
        // $this->assertContains('P108', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789520459956');
        $this->assertContains('B116', $groschen->getProductFormDetails());
        $this->assertContains('B304', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789520448134');
        $this->assertContains('B407', $groschen->getProductFormDetails());
        $this->assertContains('B408', $groschen->getProductFormDetails());
        $this->assertContains('B304', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789520449926');
        // $this->assertContains('B202', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789513171193');
        // $this->assertContains('B208', $groschen->getProductFormDetails());
        // $this->assertContains('B221', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789520451752');
        // $this->assertContains('B212', $groschen->getProductFormDetails());

        // TODO - Question to Tarja
        $groschen = new Groschen('9789513168735');
        // $this->assertContains('B115', $groschen->getProductFormDetails());
        // $this->assertContains('B221', $groschen->getProductFormDetails());
        $this->assertContains('B305', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789520415174');
        // $this->assertContains('B202', $groschen->getProductFormDetails());
        // $this->assertContains('B221', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789520453374');
        $this->assertContains('B407', $groschen->getProductFormDetails());
        $this->assertContains('B408', $groschen->getProductFormDetails());
        $this->assertContains('B305', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789510405666');
        // $this->assertContains('B415', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510418529');
        $this->assertContains('B402', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789510400098');
        // $this->assertContains('B312', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510486542');
        $this->assertContains('B304', $groschen->getProductFormDetails());
        $this->assertContains('B506', $groschen->getProductFormDetails());

        // TODO - Incorrect data in KV?
        $groschen = new Groschen('9789510414835');
        // $this->assertContains('B309', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789510418185');
        // $this->assertContains('P101', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510462454');
        // $this->assertContains('B310', $groschen->getProductFormDetails());
        $this->assertContains('B504', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510427859');
        $this->assertContains('B304', $groschen->getProductFormDetails());
        $this->assertContains('B415', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789510453759');
        // $this->assertContains('P122', $groschen->getProductFormDetails());

        // TODO - Not enough data
        $groschen = new Groschen('9789510485729');
        // $this->assertContains('B313', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510450505');
        $this->assertContains('A103', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510439111');
        // $this->assertContains('B116', $groschen->getProductFormDetails());
        $this->assertContains('B304', $groschen->getProductFormDetails());
        $this->assertContains('B506', $groschen->getProductFormDetails());
    }

    /**
     * Test getting ProductFormFeatures
     *
     * @return void
     */
    public function test_getting_technical_binding_type()
    {
        // ePub 2
        $groschen = new Groschen('9789510439838');
        $this->assertSame('ePub2', $groschen->getTechnicalBindingType());

        // Hardback
        $groschen = new Groschen('9789510423417');
        $this->assertSame('Printed cover, glued binding', $groschen->getTechnicalBindingType());
    }

    /**
     * Test getting ProductFormFeatures
     *
     * @return void
     */
    public function test_getting_product_form_features()
    {
        // Hardback should not have any product form features
        $this->assertCount(0, $this->groschen->getProductFormFeatures());

        // ePub 2
        $groschen = new Groschen('9789510383575');
        $this->assertContains(['ProductFormFeatureType' => '15', 'ProductFormFeatureValue' => '101A'], $groschen->getProductFormFeatures());

        // ePub 3
        $groschen = new Groschen('9789510414255');
        $this->assertContains(['ProductFormFeatureType' => '15', 'ProductFormFeatureValue' => '101B'], $groschen->getProductFormFeatures());

        // ePub 3 with accessibility properties
        /*
        $groschen = new Groschen('9789528500308');

        // No known hazards or warnings
        $this->assertContains(['ProductFormFeatureType' => '12', 'ProductFormFeatureValue' => '00'], $groschen->getProductFormFeatures());

        // All textual content can be modified
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '36'], $groschen->getProductFormFeatures());

        // Next / Previous structural navigation
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '29'], $groschen->getProductFormFeatures());

        // Table of contents navigation
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '11'], $groschen->getProductFormFeatures());

        // Single logical reading order
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '13'], $groschen->getProductFormFeatures());

        // WCAG level AA
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '85'], $groschen->getProductFormFeatures());

        // Epub Accessibility Specification 1.1
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '04'], $groschen->getProductFormFeatures());

        // WCAG v2.1"
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '81'], $groschen->getProductFormFeatures());

        // Language tagging provided
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '22'], $groschen->getProductFormFeatures());

        // All non-decorative content supports reading without sight
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '52'], $groschen->getProductFormFeatures());
        */
    }

    /**
     * Test getting products collections/series
     *
     * @return void
     */
    public function test_getting_collections()
    {
        // Product with bibliographical series
        $collection = [
            'CollectionType' => '10', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => 'Mielensäpahoittaja',
                    ],
                ],
                'CollectionSequence' => [
                    'CollectionSequenceType' => '02',
                    'CollectionSequenceNumber' => 1,
                ],
            ],
        ];

        $this->assertContains($collection, $this->groschen->getCollections());

        // Product with bibliographical series with number in series
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

        // Product with empty marketing series
        $groschen = new Groschen('9789520409432');

        $collection = [
            'CollectionType' => '11', [
                'TitleDetail' => [
                    'TitleType' => '01',
                    'TitleElement' => [
                        'TitleElementLevel' => '02',
                        'TitleText' => '',
                    ],
                ],
            ],
        ];

        $this->assertNotContains($collection, $groschen->getCollections());
    }

    /**
     * Test getting number in series
     *
     * @return void
     */
    public function test_getting_number_in_series_in_collection()
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
     *
     * @return void
     */
    public function test_getting_title_details()
    {
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Mielensäpahoittaja']], $this->groschen->getTitleDetails());

        // Should not have original title
        $this->assertFalse($this->groschen->getTitleDetails()->contains('TitleType', '03'));

        // Should not return dash as subtitle
        $groschen = new Groschen(9789520458805);
        $this->assertNotContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Vuori, joka katosi', 'Subtitle' => '-']], $groschen->getTitleDetails());
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Vuori, joka katosi']], $groschen->getTitleDetails());

        // Product with all possible titles
        $groschen = new Groschen('9789510400432');

        // Main title with subtitle
        $this->assertContains(['TitleType' => '01', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Joululaulu', 'Subtitle' => 'Aavetarina joulusta']], $groschen->getTitleDetails());

        // Original title
        $this->assertContains(['TitleType' => '03', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'A Christmas Carol']], $groschen->getTitleDetails());
    }

    /**
     * Test check if the book is translated or not
     *
     * @return void
     */
    public function test_getting_book_is_translated_or_not()
    {
        $this->assertFalse($this->groschen->isTranslated());

        // Non-fiction with translator and no original title
        $groschen = new Groschen('9789510450741');
        $this->assertTrue($groschen->isTranslated());

        // Translated children and juvenile without original title and translator
        $groschen = new Groschen('9789510343777');
        $this->assertTrue($groschen->isTranslated());

        // Non-fiction book with different title and original title
        $groschen = new Groschen('9789510450741');
        $this->assertTrue($groschen->isTranslated());

        // Domestic fiction with finnish-swede author that has translator
        $groschen = new Groschen('9789520402945');
        $this->assertTrue($groschen->isTranslated());
    }

    /**
     * Test that original title is returned correctly
     *
     * @return void
     */
    public function test_original_title_is_returned_correctly()
    {
        // Translated book that has same title and original title should return the original title
        $groschen = new Groschen('9789510403266');
        $this->assertContains(['TitleType' => '03', 'TitleElement' => ['TitleElementLevel' => '01', 'TitleText' => 'Red Notice']], $groschen->getTitleDetails());

        // Non-translated that have same title and original title
        $groschen = new Groschen('9789527144732');
        $this->assertFalse($groschen->getTitleDetails()->contains('TitleType', '03'));
    }

    /**
     * Test getting contributors for edition that has unmapped team roles
     *
     * @return void
     */
    public function test_getting_contributors_for_edition_with_unmapped_roles()
    {
        // Author
        $author = [
            'Identifier' => 62256,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Leah Mercer',
            'PersonNameInverted' => 'Mercer, Leah',
            'KeyNames' => 'Mercer',
            'NamesBeforeKey' => 'Leah',
            'BiographicalNote' => '<p><strong>Leah Mercer</strong> on kotoisin Nova Scotiasta, Kanadasta. Hän kirjoitti ensimmäisen romaaninsa 13-vuotiaana ja lähetti sen kustantajille, mutta sai vastaukseksi vain liudan rohkaisevia hylkäyksiä. Nuori Leah keskittyi sen jälkeen yleisurheiluun. Valmistuttuaan yliopistosta hän aloitti uran journalistina ja muutti Lontooseen miehensä ja pienen poikansa kanssa. <br/><br/>Heinäkuussa 2021 ilmestynyt <em>Sinä olet arvoitus</em> on Mercerin toinen suomennettu romaani. Häneltä on aiemmin julkaistu teos <em>Kun kerran kohtasimme</em>.</p>',
            'WebSites' => [
                [
                    'WebsiteRole' => '06',
                    'WebsiteDescription' => 'Tekijän omat nettisivut',
                    'Website' => 'http://leahmercer.com/',
                ],
            ],
            'SelectionLists' => [
                'WS Bazarkustannus.fi julkiset tekijät',
            ],
            'HasAuthorImage' => true,
        ];

        $groschen = new Groschen('9789523762091');
        $this->assertContains($author, $groschen->getContributors());
    }

    /**
     * Test getting products contributors
     *
     * @return void
     */
    public function test_getting_contributors()
    {
        // Author
        $author = [
            'Identifier' => 55133,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Tuomas Kyrö',
            'PersonNameInverted' => 'Kyrö, Tuomas',
            'KeyNames' => 'Kyrö',
            'NamesBeforeKey' => 'Tuomas',
            'BiographicalNote' => "<p><strong>Tuomas Kyrö</strong> (s. 1974) on sukupolvensa monipuolisimpia, aktiivisimpia ja kiitetyimpiä kirjailijoita. Hän on kirjoittanut romaaneja, kolumneja, pakinoita ja draamaa sekä toiminut tv- ja elokuvakäsikirjoittajana ja tv-panelistina. Hänelle myönnettiin Pro Finlandia -mitali vuonna 2020.</p> <p>Tuomas Kyrön tuotannon pohjavireenä on syvällinen ihmistuntemus ja terävänäköinen yhteiskunnallisuus. Kyrön esikoisromaani\u{A0}<i>Nahkatakki\u{A0}</i>ilmestyi vuonna 2001. Romaani\u{A0}<i>Liitto\u{A0}</i>oli Finlandia-ehdokkaana vuonna 2005. Kyrö kirjoitti radiolle viisiminuuttisia tarinoita, joiden päähenkilö jatkoi pohdintojaan vuonna 2010 ilmestyneessä romaanissa\u{A0}<i>Mielensäpahoittaja.\u{A0}</i>Ikonisen jäärän tarina on jatkunut toistakymmentä vuotta niin romaaneissa, teattereissa, televisiosarjassa kuin elokuvissakin.</p> <p>Vuonna 2023 Tuomas Kyrö kirjoitti oululaislähtöisen sotilaan tarinan\u{A0}<i>Aleksi Suomesta\u{A0}</i>sekä nyrkkeilijä Robert Heleniuksen henkilökuvan\u{A0}<i>Nyrkki</i>.</p>",
            'WebSites' => [
                [
                    'WebsiteRole' => '42',
                    'WebsiteDescription' => 'Tuomas Kyrö Twitterissä',
                    'Website' => 'https://twitter.com/TuomasKyr',
                ],
            ],
            'SelectionLists' => [
                'WS Bazarkustannus.fi julkiset tekijät',
                'wsoy.fi julkiset tekijät',
                'Kirja.fi julkiset tekijät',
                'Spotify-tekijäsivut',
            ],
            'HasAuthorImage' => true,
        ];

        $this->assertContains($author, $this->groschen->getContributors());

        // Graphic designer
        $graphicDesigner = [
            'Identifier' => 58381,
            'SequenceNumber' => 2,
            'ContributorRole' => 'A11',
            'PersonName' => 'Mika Tuominen',
            'PersonNameInverted' => 'Tuominen, Mika',
            'KeyNames' => 'Tuominen',
            'NamesBeforeKey' => 'Mika',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($graphicDesigner, $this->groschen->getContributors());

        // These two should be to only contributors
        $this->assertCount(2, $this->groschen->getContributors(false));

        // Product with confidential resource
        $groschen = new Groschen('9789513176457');
        $this->assertFalse($groschen->getContributors()->contains('ContributorRole', 'B21'));
    }

    /**
     * Test that duplicate contributors mapped to same Onix are filtered
     *
     * @return void
     */
    public function test_that_contributors_mapped_to_same_onix_role_are_filtered()
    {
        $groschen = new Groschen('9789510471586');

        // Both Illustrator, cover WS and Designer, cover WS are mapped to A36
        $this->assertCount(1, $groschen->getContributors()->where('PersonName', 'Riad Sattouf')->where('ContributorRole', 'A36'), 'The contributor and role combination is duplicated.');
    }

    /**
     * Test contributor with only one name or pseudonym is handled correctly
     *
     * @return void
     */
    public function test_contributor_with_only_one_name_or_pseudonym_is_handled_correctly()
    {
        $groschen = new Groschen('9789521619021');

        // Author
        $author = [
            'Identifier' => 58898,
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonName' => 'Sunaakugan',
            'KeyNames' => 'Sunaakugan',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($author, $groschen->getContributors());
    }

    /**
     * Test that stakeholders with same priority are sorted by last name
     *
     * @return void
     */
    public function test_contributor_are_sorted_by_lastname()
    {
        $groschen = new Groschen('9789513131524');

        // First author
        $firstAuthor = [
            'Identifier' => 57561,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Azmina Govindji',
            'PersonNameInverted' => 'Govindji, Azmina',
            'KeyNames' => 'Govindji',
            'NamesBeforeKey' => 'Azmina',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'Identifier' => 57560,
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonName' => 'Anthony Worrall Thompson',
            'PersonNameInverted' => 'Worrall Thompson, Anthony',
            'KeyNames' => 'Worrall Thompson',
            'NamesBeforeKey' => 'Anthony',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($secondAuthor, $groschen->getContributors());
    }

    /**
     * Test that priority contributor with same role
     *
     * @return void
     */
    public function test_contributor_priority_is_handled_correctly()
    {
        $groschen = new Groschen('9789510421987');

        // First author
        $firstAuthor = [
            'Identifier' => 58980,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Jari Aarnio',
            'PersonNameInverted' => 'Aarnio, Jari',
            'KeyNames' => 'Aarnio',
            'NamesBeforeKey' => 'Jari',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());

        // Second author
        $secondAuthor = [
            'Identifier' => 54752,
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonName' => 'Vepe Hänninen',
            'PersonNameInverted' => 'Hänninen, Vepe',
            'KeyNames' => 'Hänninen',
            'NamesBeforeKey' => 'Vepe',
            'BiographicalNote' => '<p><strong>Vepe Hännisen </strong>kynästä on syntynyt jo kolme romaania ja useita käsikirjoituksia kotimaisiin draamatuotantoihin. Hän kirjoittaa myös dokumentoivaa kaunokirjallisuutta. Hänninen on käsikirjoittanut TV-sarjoja <em>Kylmäverisesti sinun</em>, <em>Suojelijat</em>, <em>Kotikatu</em> ja <em>Helppo elämä</em>. Hänen kirjoittamansa TV-elokuva <em>Pieni pyhiinvaellus</em> voitti lukuisia kotimaisia ja kansainvälisiä palkintoja ja se on saanut jo lähes vakiintuneen aseman Ylen pääsiäisohjelmistossa. Samankaltaista juhlapyhäkertomuksen asemaa on lähestynyt myös elokuva <em>Joulukuusivarkaat</em>.</p> <p>Hännisen töitä on nähty oopperalavalla ja valkokankaallakin. Loppuvuodesta 2011 ensi-iltansa sai Arto Salmisen <em>Varasto</em>-romaaniin perustuva, Hännisen käsikirjoittama menestyselokuva.</p>',
            'WebSites' => [],
            'SelectionLists' => [
                'wsoy.fi julkiset tekijät',
                'Kirja.fi julkiset tekijät',
            ],
            'HasAuthorImage' => true,
        ];

        $this->assertContains($secondAuthor, $groschen->getContributors());
    }

    /**
     * Test contributors without priority level are handled correctly
     *
     * @return void
     */
    public function test_contributors_without_priority_level_are_handled_correctly()
    {
        $groschen = new Groschen('9789510434123');

        // Author should be first
        $author = [
            'Identifier' => 59412,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Virve Fredman',
            'PersonNameInverted' => 'Fredman, Virve',
            'KeyNames' => 'Fredman',
            'NamesBeforeKey' => 'Virve',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($author, $groschen->getContributors(false));
    }

    /**
     * Test getting all contributors
     *
     * @return void
     */
    public function test_getting_all_contributors()
    {
        // Author
        $author = [
            'Id' => 55133,
            'PriorityLevel' => 'Primary',
            'Role' => 'Author',
            'FirstName' => 'Tuomas',
            'LastName' => 'Kyrö',
        ];

        $this->assertContains($author, $this->groschen->getAllContributors());

        // Keski-Suomen Sivu
        $layout = [
            'Id' => 58360,
            'PriorityLevel' => 'Internal',
            'Role' => 'Layout',
            'FirstName' => 'Keski-Suomen Sivu Oy',
            'LastName' => null,
        ];

        $this->assertContains($layout, $this->groschen->getAllContributors());

        // Printer
        $printer = [
            'Id' => 59694,
            'PriorityLevel' => 'Internal',
            'Role' => 'Printer',
            'FirstName' => 'Bookwell Oy',
            'LastName' => null,
        ];

        $this->assertContains($printer, $this->groschen->getAllContributors());
    }

    /**
     * Test getting contributor which is a company
     *
     * @return void
     */
    public function test_getting_contributor_which_is_company()
    {
        $groschen = new Groschen('9789510483725');

        // First author
        $corporateAuthor = [
            'Identifier' => 67060,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'CorporateName' => 'L.O.L. Surprise!',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($corporateAuthor, $groschen->getContributors());
    }

    /**
     * Test getting products languages
     *
     * @return void
     */
    public function test_getting_languages()
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
     *
     * @return void
     */
    public function test_getting_extents()
    {
        $this->assertContains(['ExtentType' => '00', 'ExtentValue' => '128', 'ExtentUnit' => '03'], $this->groschen->getExtents());
    }

    /**
     * Test getting products extents where ExtentValue is 0
     *
     * @return void
     */
    public function test_getting_extent_with_incorrect_values()
    {
        $groschen = new Groschen('9789524924399');
        $this->assertNotContains(['ExtentType' => '10', 'ExtentValue' => '0', 'ExtentUnit' => '02'], $groschen->getExtents());
    }

    /**
     * Test getting products estimated number of pages
     *
     * @return void
     */
    public function test_getting_estimated_number_of_pages()
    {
        $groschen = new Groschen('9789510438183');
        $this->assertNull($groschen->getEstimatedNumberOfPages());

        $groschen = new Groschen('9789521621895');
        $this->assertSame(192, $groschen->getEstimatedNumberOfPages());
    }

    /**
     * Test getting extents from book without any
     *
     * @return void
     */
    public function test_getting_extents_from_book_without_any()
    {
        // Book without any extents
        $groschen = new Groschen('9789510303108');
        $this->assertCount(0, $groschen->getExtents());
    }

    /**
     * Test getting extents for audio book
     *
     * @return void
     */
    public function test_getting_extents_for_audio_book()
    {
        $groschen = new Groschen('9789510448267');
        $extents = $groschen->getExtents();

        $this->assertNotContains(['ExtentType' => '00', 'ExtentValue' => '447', 'ExtentUnit' => '03'], $extents);

        // Number of pages in print counterpart
        $this->assertContains(['ExtentType' => '08', 'ExtentValue' => '447', 'ExtentUnit' => '03'], $extents);

        // Audio book duration in three different formats
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '01344', 'ExtentUnit' => '15'], $extents);
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '0134423', 'ExtentUnit' => '16'], $extents);
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '49440', 'ExtentUnit' => '06'], $extents);
    }

    /**
     * Test getting extents for audio book without duration
     *
     * @return void
     */
    public function test_getting_extents_for_audio_book_without_duration()
    {
        // Audio book with duration 0 should not return anything
        $groschen = new Groschen('9789510447871');
        $this->assertNotContains(['ExtentType' => '09', 'ExtentValue' => '00000', 'ExtentUnit' => '15'], $groschen->getExtents());
    }

    /**
     * Test getting extents for an e-book
     *
     * @return void
     */
    public function test_getting_extents_for_an_ebook()
    {
        // E-book character count should be converted to number of words and pages by approximation from the number of characters
        $groschen = new Groschen('9789510411858');
        $extents = $groschen->getExtents();

        // Number of words, pages and characters
        $this->assertContains(['ExtentType' => '10', 'ExtentValue' => '93984', 'ExtentUnit' => '02'], $extents);
        $this->assertContains(['ExtentType' => '10', 'ExtentValue' => '533', 'ExtentUnit' => '03'], $extents);
        $this->assertContains(['ExtentType' => '02', 'ExtentValue' => '798865', 'ExtentUnit' => '01'], $extents);
    }

    /**
     * Test getting extents for an e-book with only one page
     *
     * @return void
     */
    public function test_getting_extents_for_an_ebook_with_only_one_page()
    {
        // E-book character count should be converted to number of words and pages by approximation from the number of characters
        $groschen = new Groschen('9789520409722');
        $extents = $groschen->getExtents();

        // Number of words, pages and characters
        $this->assertContains(['ExtentType' => '02', 'ExtentValue' => '733', 'ExtentUnit' => '01'], $extents);
        $this->assertContains(['ExtentType' => '08', 'ExtentValue' => '48', 'ExtentUnit' => '03'], $extents);
        $this->assertContains(['ExtentType' => '10', 'ExtentValue' => '86', 'ExtentUnit' => '02'], $extents);
        $this->assertContains(['ExtentType' => '10', 'ExtentValue' => '1', 'ExtentUnit' => '03'], $extents);
    }

    /**
     * Test getting audio books duration only with hours
     *
     * @return void
     */
    public function test_getting_audio_book_duration()
    {
        $groschen = new Groschen('9789510442128');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '01447', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '53220', 'ExtentUnit' => '06'], $groschen->getExtents());
    }

    /**
     * Test that audio book duration is not shared if audio book is main edition
     *
     * @return void
     */
    public function test_that_audio_book_duration_is_not_shared()
    {
        // Audio book which is main edition
        $groschen = new Groschen('9789510449677');
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '00913', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertContains(['ExtentType' => '09', 'ExtentValue' => '33180', 'ExtentUnit' => '06'], $groschen->getExtents());

        // e-book under the same work
        $groschen = new Groschen('9789510496107');
        $this->assertNotContains(['ExtentType' => '09', 'ExtentValue' => '00913', 'ExtentUnit' => '15'], $groschen->getExtents());
        $this->assertNotContains(['ExtentType' => '09', 'ExtentValue' => '33180', 'ExtentUnit' => '06'], $groschen->getExtents());
    }

    /**
     * Test getting product text contents
     *
     * @return void
     */
    public function test_getting_text_contents()
    {
        // Check that we can find text
        $this->assertCount(1, $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00'));

        // Check that text contains description
        $this->assertStringContainsString('Kyllä minä niin mieleni pahoitin, kun aurinko paistoi.', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());

        // Check that language is correct
        $this->assertSame('fin', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Check that text contains review quotes and sources
        $this->assertTrue($this->groschen->getTextContents()->contains('TextType', '06'));
        $this->assertStringContainsString('Herrajumala, en ole mistään nauttinut näin aikapäiviin! Aivan mahtavia - ja täyttä asiaa!', $this->groschen->getTextContents()->where('TextType', '06')->where('ContentAudience', '00')->pluck('Text')->first());
        $this->assertStringContainsString('Sari Orhinmaa, toimittaja', $this->groschen->getTextContents()->where('TextType', '06')->where('ContentAudience', '00')->pluck('SourceTitle')->first());

        // Product without reviews
        $groschen = new Groschen('9789510433911');
        $this->assertStringContainsString('Ulkoministerin poika Juho Nortamo saa tietää olevansa ottolapsi.', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('Text')->first());

        // Product without text
        $groschen = new Groschen('9789510343135');
        $this->assertFalse($groschen->getTextContents()->contains('TextType', '03'));
    }

    /**
     * Test that TextContents language is detected correctly
     *
     * @return void
     */
    public function test_getting_text_contents_language_is_correct()
    {
        // Product with finnish marketing text
        $this->assertSame('fin', $this->groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Product with swedish marketing text
        $groschen = new Groschen('9789528702245');
        $this->assertSame('swe', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Product with english marketing text
        $groschen = new Groschen('9789528702405');
        $this->assertSame('eng', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Product with german marketing text
        $groschen = new Groschen('9789528702252');
        $this->assertSame('ger', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Product with multiple languages but with finnish marketing text
        $groschen = new Groschen('9789510401699');
        $this->assertSame('fin', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());

        // Product without language code should default to finnish
        $groschen = new Groschen('9789520468507');
        $this->assertSame('fin', $groschen->getTextContents()->where('TextType', '03')->where('ContentAudience', '00')->pluck('LanguageCode')->first());
    }

    /**
     * Test that author description(s) are picked from contact if author presentation paragraph is missing
     *
     * @return void
     */
    public function test_author_description_is_taken_from_the_contact_if_author_presentation_paragraph_is_missing()
    {
        $this->markTestSkipped('The logic has been changed.');

        $groschen = new Groschen('9789524030199');
        $textContents = $groschen->getTextContents();
        $this->assertTrue($textContents->contains('TextType', '03'));
        $marketingText = $textContents->where('TextType', '03')->pluck('Text')->first();

        $this->assertStringContainsString('<p><strong>Göran Wennqvist </strong>(s. 1955) on eläköitynyt porvoolainen rikosylikomisario, jolla on takanaan 43 vuotta kestänyt monipuolinen työura ja tehtävät poliisin eri yksiköissä.', $marketingText);
        $this->assertStringContainsString('><p><strong>Tero Haapala</strong> (s. 1958) on eläköitynyt helsinkiläinen rikosylikomisario, joka toimi 41 vuotta kestäneen työuransa kaikki vuodet rikostutkintatehtävissä, ensin Helsingin rikospoliisissa ja 35 viimeisintä vuotta keskusrikospoliisissa.', $marketingText);
    }

    /**
     * Test that author description is listed only once even though the same contact has multiple roles on the edition
     *
     * @return void
     */
    public function test_author_description_is_taken_only_once_if_has_multiple_roles()
    {
        $this->markTestSkipped('Will be implemented later after texts have been fixed.');

        $groschen = new Groschen('9789524030274');
        $textContents = $groschen->getTextContents();

        $this->assertTrue($textContents->contains('TextType', '03'));
        $marketingText = $textContents->where('TextType', '03')->pluck('Text')->first();
        $this->assertSame(1, substr_count($marketingText, 'Juha Vuorinen</strong> (s. 1967) on kirjailija, joka tunnetaan myös radio- ja televisiotoimittajana'));
    }

    /**
     * Test getting texts
     *
     * @return void
     */
    public function test_getting_text()
    {
        // Check that text contains description
        $this->assertStringContainsString('Kyllä minä niin mieleni pahoitin, kun aurinko paistoi.', $this->groschen->getText('Copy 1'));
        $this->assertStringContainsString('40 tapaa pahoittaa mielensä!', $this->groschen->getText('Headline'));

        // Ask for non-existant text-type should return null
        $this->assertNull($this->groschen->getText('foobar'));
    }

    /**
     * Test getting the products RRP incl. VAT
     *
     * @return void
     */
    public function test_getting_price()
    {
        $this->assertSame(18.44, $this->groschen->getPrice());
    }

    /**
     * Test getting the products weight
     *
     * @return void
     */
    public function test_getting_measures()
    {
        $this->assertContains(['MeasureType' => '01', 'Measurement' => 202, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '02', 'Measurement' => 136, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '03', 'Measurement' => 16, 'MeasureUnitCode' => 'mm'], $this->groschen->getMeasures());
        $this->assertContains(['MeasureType' => '08', 'Measurement' => 218, 'MeasureUnitCode' => 'gr'], $this->groschen->getMeasures());

        // eBook should not have any measures
        $groschen = new Groschen('9789510416860');
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '01'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '02'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '03'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '08'));

        // Downloadable audio book should not have any measures
        $groschen = new Groschen('9789510450079');
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '01'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '02'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '03'));
        $this->assertFalse($groschen->getMeasures()->contains('MeasureType', '08'));
    }

    /**
     * Test getting various subjects
     *
     * @return void
     */
    public function test_getting_subjects()
    {
        $subjects = $this->groschen->getSubjects();

        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Werner Söderström Ltd - Main product group', 'SubjectCode' => '1', 'SubjectHeadingText' => 'Kotimainen kauno'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Werner Söderström Ltd - Product sub-group', 'SubjectCode' => '24', 'SubjectHeadingText' => 'Nykyromaanit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Werner Söderström Ltd - Cost center', 'SubjectCode' => '301', 'SubjectHeadingText' => 'WSOY - Kotimainen kauno'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC060000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FA'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'FU'], $subjects);
        $this->assertNotContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'novellit'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'niksit;huumori;kodinhoito;metsänhoito;kalastus;kunnossapito;rakennukset;autot;huolto;pessimismi;ironia;novellit;pakinat;monologit;arkielämä;eläkeläiset;mielipiteet;vanhukset;suomalaisuus;suomalaiset;miehet;cd-äänilevyt;2000-luku;suomenkielinen kirjallisuus;suomen kieli;romaanit;arki;ikääntyneet'], $subjects);

        // Book with subjects in Allmän tesaurus på svenska
        $groschen = new Groschen('9789510374665');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '65', 'SubjectSchemeName' => 'Allmän tesaurus på svenska', 'SubjectCode' => 'krigföring'], $subjects);

        // Keywords should contain only finnish subjects
        $this->assertContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => 'tieto-finlandia-palkinto;sodat;kokemukset;sotilaat;mielenterveys;mielenterveyshäiriöt;traumat;traumaperäinen stressireaktio;psykiatrinen hoito;sotilaspsykiatria;psykiatria;psykohistoria;talvisota;jatkosota;lapin sota;sotahistoria;sodankäynti;suomi;sotarintama;1939-1945;2013;kirjallisuuspalkinnot'], $subjects);

        // Another book with more classifications
        $groschen = new Groschen('9789510408452');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => '84.2'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Werner Söderström Ltd - Main product group', 'SubjectCode' => '4', 'SubjectHeadingText' => 'Käännetty L&N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '23', 'SubjectSchemeName' => 'Werner Söderström Ltd - Product sub-group', 'SubjectCode' => '31', 'SubjectHeadingText' => 'Scifi'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'FIC028000'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '12', 'SubjectSchemeName' => 'BIC subject category', 'SubjectCode' => 'FL'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '93', 'SubjectSchemeName' => 'Thema subject category', 'SubjectCode' => 'FYT'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => 'N'], $subjects);
        $this->assertContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age / special interest qualifier', 'SubjectCode' => '5AN'], $subjects);

        // Product without library class
        $groschen = new Groschen('9789510427057');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '66', 'SubjectSchemeName' => 'YKL', 'SubjectCode' => ''], $subjects);

        // Empty values should be filtered
        $groschen = new Groschen('9789520403294');
        $subjects = $groschen->getSubjects();
        $this->assertNotContains(['SubjectSchemeIdentifier' => '98', 'SubjectSchemeName' => 'Thema interest age', 'SubjectCode' => ''], $subjects);
        $this->assertNotContains(['SubjectSchemeIdentifier' => '20', 'SubjectHeadingText' => ''], $subjects);

        // Check that "Ellibs" is not added as a keyword
        $groschen = new Groschen('9789513170424');
        $this->assertNotContains(['SubjectSchemeIdentifier' => '69', 'SubjectSchemeName' => 'KAUNO - ontology for fiction', 'SubjectCode' => 'Ellibs'], $groschen->getSubjects());

        // BISAC
        $groschen = new Groschen('9789510513101');
        $subjects = $groschen->getSubjects();
        $this->assertContains(['SubjectSchemeIdentifier' => '10', 'SubjectSchemeName' => 'BISAC Subject Heading', 'SubjectCode' => 'JUV019000'], $subjects);
    }

    /**
     * Test getting Storia product groups (Storia tuoteryhmä)
     *
     * @return void
     */
    public function test_getting_storia_product_groups()
    {
        // 00 Kotimainen Kaunokirjallisuus
        $groschen = new Groschen('9789520429034');
        $this->assertSame('00', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 01 Käännetty Kaunokirjallisuus
        $groschen = new Groschen('9789510461730');
        $this->assertSame('01', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 03 Tietokirjallisuus
        $groschen = new Groschen('9789510467176');
        $this->assertSame('03', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 04 Lasten ja nuorten kirjat (kotimainen ja käännetty)
        $groschen = new Groschen('9789520428068');
        $this->assertSame('04', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        $groschen = new Groschen('9789520424909');
        $this->assertSame('04', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 06 Pokkarit
        $groschen = new Groschen('9789510467145');
        $this->assertSame('06', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 64 Äänikirjat
        $groschen = new Groschen('9789510366486');
        $this->assertSame('64', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 86 Puuha- ja värityskirjat
        $groschen = new Groschen('9789513112721');
        $this->assertSame('86', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        $groschen = new Groschen('9789510355794');
        $this->assertSame('86', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // 80 Myymälämateriaalit (telineet ym.)
        // Kadonnut sisar -lava
        $groschen = new Groschen('6430060033023');
        $this->assertSame('80', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Tokmanni, jättipokkarilava kesä 2021
        $groschen = new Groschen('6430060032200');
        $this->assertSame('80', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Neropatin päiväkirja 15 -pöytäteline
        $groschen = new Groschen('6430060032125');
        $this->assertSame('80', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Werner Söderström -kassi logoilla 2020
        $groschen = new Groschen('6430060032040');
        $this->assertSame('80', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Book that has "kassi" in the title should not be determined as marketing material
        $groschen = new Groschen('9789510478318');
        $this->assertSame('00', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Calendar that is Trade paperback or "Jättipokkari"
        $groschen = new Groschen('9789524031745');
        $this->assertSame('01', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // Marketing material
        $groschen = new Groschen('6430060034518');
        $this->assertSame('80', $groschen->getSubjects()->where('SubjectSchemeName', 'Storia - Tuoteryhmä')->pluck('SubjectCode')->first());

        // The following codes are not mapped
        // 10 Peruskoulun oppikirjat
        // 20 Oppikirjat
        // 50 Kartat
        // 82 Pelit
        // 63 Musiikkiäänitteet
    }

    /**
     * Test that we get correct "Suomalainen kirja-alan luokitus" based on binding code / age group
     *
     * @return void
     */
    public function test_getting_correct_suomalainen_kirjastoalan_luokitus()
    {
        // Pocket book
        $groschen = new Groschen('9789513151027');
        $this->assertSame('T', $groschen->getFinnishBookTradeCategorisation());

        // Young adults pocket book should return only T
        $groschen = new Groschen('9789510396100');
        $this->assertSame('T', $groschen->getFinnishBookTradeCategorisation());

        // Childrens book
        $groschen = new Groschen('9789520412722');
        $this->assertSame('L', $groschen->getFinnishBookTradeCategorisation());

        // Young adults book
        $groschen = new Groschen('9789513189075');
        $this->assertSame('N', $groschen->getFinnishBookTradeCategorisation());
    }

    /**
     * Test product without subgroup is not throwing exception
     *
     * @return void
     */
    public function test_product_without_subgroup_is_not_throwing_exception()
    {
        $groschen = new Groschen('9789510430347');
        $subjects = $groschen->getSubjects();
        $this->assertInstanceOf('Illuminate\Support\Collection', $subjects);
    }

    /**
     * Test getting audiences
     *
     * @return void
     */
    public function test_getting_audiences()
    {
        // General/trade
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '01'], $this->groschen->getAudiences());
        $this->assertCount(1, $this->groschen->getAudiences());

        // Children/juvenile book
        $groschen = new Groschen('9789510429877');
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '02'], $groschen->getAudiences());
        $this->assertCount(1, $groschen->getAudiences());

        // Young adult
        $groschen = new Groschen('9789510479957');
        $this->assertContains(['AudienceCodeType' => '01', 'AudienceCodeValue' => '03'], $groschen->getAudiences());
        $this->assertCount(1, $groschen->getAudiences());
    }

    /**
     * Test getting AudienceRanges
     *
     * @return void
     */
    public function test_getting_audience_ranges()
    {
        // General/trade should not contain any audience ranges
        $this->assertCount(0, $this->groschen->getAudienceRanges());

        // Age group mapping
        $ageGroups = [
            '0+' => [
                'gtin' => 9789513181512,
                'expectedAudienceRange' => 0,
            ],
            '3+' => [
                'gtin' => 9789513133030,
                'expectedAudienceRange' => 3,
            ],
            '5+' => [
                'gtin' => 9789513176730,
                'expectedAudienceRange' => 5,
            ],
            '7+' => [
                'gtin' => 9789510426319,
                'expectedAudienceRange' => 7,
            ],
            '9+' => [
                'gtin' => 9789510411735,
                'expectedAudienceRange' => 9,
            ],
            '10+' => [
                'gtin' => 9789520412050,
                'expectedAudienceRange' => 10,
            ],
            '12+' => [
                'gtin' => 9789521619571,
                'expectedAudienceRange' => 12,
            ],
            '14+' => [
                'gtin' => 9789510401521,
                'expectedAudienceRange' => 14,
            ],
        ];

        // Go through all the options
        foreach ($ageGroups as $ageGroup => $parameters) {
            $groschen = new Groschen($parameters['gtin']);

            $expectedAudienceRange = [
                'AudienceRangeQualifier' => 17, // Interest age, years
                'AudienceRangeScopes' => [
                    [
                        'AudienceRangePrecision' => '03', // From
                        'AudienceRangeValue' => $parameters['expectedAudienceRange'],
                    ],
                ],
            ];

            $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());
        }
    }

    /**
     * Test getting AudienceRanges for products with multiple interest qualifiers
     *
     * @return void
     */
    public function test_getting_audience_ranges_with_product_with_multiple_interest_qualifiers()
    {
        $groschen = new Groschen(9789520448608);

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 3,
                ],
            ],
        ];

        $this->assertSame($expectedAudienceRange, $groschen->getAudienceRanges()->first());

        // Another with 5AG and 5AR
        $groschen = new Groschen(9789510505861);

        $expectedAudienceRange = [
            'AudienceRangeQualifier' => 17, // Interest age, years
            'AudienceRangeScopes' => [
                [
                    'AudienceRangePrecision' => '03', // From
                    'AudienceRangeValue' => 6,
                ],
            ],
        ];

        $this->assertContains($expectedAudienceRange, $groschen->getAudienceRanges());
    }

    /**
     * Test getting the products publisher
     *
     * @return void
     */
    public function test_getting_the_products_publisher()
    {
        // Normal WSOY product
        $this->assertSame('WSOY', $this->groschen->getPublisher());

        // Johnny Kniga product
        $groschen = new Groschen('9789510405314');
        $this->assertSame('Johnny Kniga', $groschen->getPublisher());

        // Normal Tammi product
        $groschen = new Groschen('9789513179564');
        $this->assertSame('Tammi', $groschen->getPublisher());

        // Manga product
        $groschen = new Groschen('9789521619779');
        $this->assertSame('Tammi', $groschen->getPublisher());
    }

    /**
     * Test getting the products publisher(s)
     *
     * @return void
     */
    public function test_getting_publishers()
    {
        // Normal WSOY product
        $this->assertContains([
            'PublishingRole' => '01',
            'PublisherIdentifiers' => [
                [
                    'PublisherIDType' => '15',
                    'IDTypeName' => 'Y-tunnus',
                    'IDValue' => '0599340-0',
                ],
            ],
            'PublisherName' => 'WSOY',
            'WebSites' => [
                [
                    'WebsiteRole' => '01',
                    'WebsiteLink' => 'https://www.wsoy.fi',
                ],
                [
                    'WebsiteRole' => '50',
                    'WebsiteLink' => 'https://bonnierbooks.com/sustainability/',
                ],
            ],
        ], $this->groschen->getPublishers());

        // Johnny Kniga product
        $groschen = new Groschen('9789510405314');
        $this->assertContains([
            'PublishingRole' => '01',
            'PublisherIdentifiers' => [
                [
                    'PublisherIDType' => '15',
                    'IDTypeName' => 'Y-tunnus',
                    'IDValue' => '0599340-0',
                ],
            ],
            'PublisherName' => 'Johnny Kniga',
            'WebSites' => [
                [
                    'WebsiteRole' => '01',
                    'WebsiteLink' => 'https://www.johnnykniga.fi',
                ],
                [
                    'WebsiteRole' => '50',
                    'WebsiteLink' => 'https://bonnierbooks.com/sustainability/',
                ],
            ],
        ], $groschen->getPublishers());

        // Normal Tammi product
        $groschen = new Groschen('9789513179564');
        $this->assertContains([
            'PublishingRole' => '01',
            'PublisherIdentifiers' => [
                [
                    'PublisherIDType' => '15',
                    'IDTypeName' => 'Y-tunnus',
                    'IDValue' => '0599340-0',
                ],
            ],
            'PublisherName' => 'Tammi',
            'WebSites' => [
                [
                    'WebsiteRole' => '01',
                    'WebsiteLink' => 'https://www.tammi.fi',
                ],
                [
                    'WebsiteRole' => '50',
                    'WebsiteLink' => 'https://bonnierbooks.com/sustainability/',
                ],
            ],
        ], $groschen->getPublishers());

        // Kosmos product
        $groschen = new Groschen('9789523520189');
        $this->assertContains([
            'PublishingRole' => '01',
            'PublisherIdentifiers' => [
                [
                    'PublisherIDType' => '15',
                    'IDTypeName' => 'Y-tunnus',
                    'IDValue' => '0599340-0',
                ],
            ],
            'PublisherName' => 'Kosmos',
            'WebSites' => [
                [
                    'WebsiteRole' => '01',
                    'WebsiteLink' => 'https://www.kosmoskirjat.fi',
                ],
                [
                    'WebsiteRole' => '50',
                    'WebsiteLink' => 'https://bonnierbooks.com/sustainability/',
                ],
            ],
        ], $groschen->getPublishers());
    }

    /**
     * Test getting the products imprint
     *
     * @return void
     */
    public function test_getting_imprints()
    {
        // Normal WSOY product
        $this->assertCount(0, $this->groschen->getImprints());

        // Bazar product without brand
        $groschen = new Groschen('9789522799531');
        $this->assertCount(0, $groschen->getImprints());

        // Readme product with brand "Kids.fi"
        $groschen = new Groschen('9789522563828');
        $this->assertCount(1, $groschen->getImprints());
        $this->assertContains(['ImprintName' => 'Kids.fi'], $groschen->getImprints());

        // Docendo product with brand "CrimeTime"
        $groschen = new Groschen('9789528504627');
        $this->assertCount(1, $groschen->getImprints());
        $this->assertContains(['ImprintName' => 'CrimeTime'], $groschen->getImprints());
    }

    /**
     * Test getting the products brands
     *
     * @return void
     */
    public function test_getting_brands()
    {
        // Normal WSOY product
        $this->assertSame('WSOY', $this->groschen->getBrand());

        // Disney product (Tammi)
        $groschen = new Groschen('9789520416904');
        $this->assertStringContainsString('Disney', $groschen->getBrand());
    }

    /**
     * Test getting products publishing status
     *
     * @return void
     */
    public function test_getting_publishing_status()
    {
        // Published
        $groschen = new Groschen('9789510397923');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Exclusive sales
        $groschen = new Groschen('9789518467253');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Sold out
        $groschen = new Groschen('9789510324370');
        $this->assertSame('07', $groschen->getPublishingStatus());

        // Development-confidential
        $groschen = new Groschen('9789510456927');
        $this->assertSame('00', $groschen->getPublishingStatus());

        // Cancelled
        $groschen = new Groschen('9789513189556');
        $this->assertSame('01', $groschen->getPublishingStatus());

        // POD / shortrun
        $groschen = new Groschen('9789510516324');
        $this->assertSame('04', $groschen->getPublishingStatus());

        // Delivery block
        $groschen = new Groschen('9789510359686');
        $this->assertSame('07', $groschen->getPublishingStatus());

        // Permanently withdrawn from sale
        $groschen = new Groschen('9789520426705');
        $this->assertSame('11', $groschen->getPublishingStatus());
    }

    /**
     * Test getting products publishing dates
     *
     * @return void
     */
    public function test_getting_publishing_dates()
    {
        // Publishing date
        $this->assertContains(['PublishingDateRole' => '01', 'Date' => '20100601'], $this->groschen->getPublishingDates());
        $this->assertNotContains(['PublishingDateRole' => '02', 'Date' => '20100601'], $this->groschen->getPublishingDates());

        // Public announcement date / Season
        $this->assertContains(['PublishingDateRole' => '09', 'Date' => '2010 Spring', 'Format' => 12], $this->groschen->getPublishingDates());

        // Check that fake season 2099 N/A is not shown
        $groschen = new Groschen('9789527144404');
        $this->assertNotContains(['PublishingDateRole' => '09', 'Date' => '2099 N/A', 'Format' => 12], $groschen->getPublishingDates());

        // Latest reprint
        $this->assertContains(['PublishingDateRole' => '12', 'Date' => '20171003'], $this->groschen->getPublishingDates());

        // Reprint in the future with multiple prints
        // $groschen = new Groschen('9789510386033');
        // $this->assertContains(['PublishingDateRole' => '26', 'Date' => '20211011'], $groschen->getPublishingDates());

        // Products with sales embargo
        $groschen = new Groschen('9789520407230');
        $this->assertContains(['PublishingDateRole' => '02', 'Date' => '20190926'], $groschen->getPublishingDates());

        // Product with only "actual date"
        $groschen = new Groschen('9789510437605');
        $this->assertContains(['PublishingDateRole' => '01', 'Date' => '20181106'], $groschen->getPublishingDates());
        $this->assertNotContains(['PublishingDateRole' => '02', 'Date' => '20181106'], $groschen->getPublishingDates());

        // Digital product should return 02 - Sales embargo date with same value as publishing date
        $groschen = new Groschen('9789510464946');
        $this->assertContains(['PublishingDateRole' => '01', 'Date' => '20210701'], $groschen->getPublishingDates());
        $this->assertContains(['PublishingDateRole' => '02', 'Date' => '20210701'], $groschen->getPublishingDates());

        // We should not send reprint date before publication date
        $groschen = new Groschen('9789510476765');
        $this->assertNotContains(['PublishingDateRole' => '26', 'Date' => '20211220'], $groschen->getPublishingDates());
    }

    /**
     * Test getting latest reprint date
     *
     * @return void
     */
    public function test_getting_latest_stock_arrival_date()
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
     *
     * @return void
     */
    public function test_getting_latest_print_number()
    {
        $groschen = new Groschen('9789510374665');
        $this->assertSame(5, $groschen->getLatestPrintNumber());

        $groschen = new Groschen('9789510355763');
        $this->assertSame(1, $groschen->getLatestPrintNumber());
    }

    /**
     * Test getting products prices
     *
     * @return void
     */
    public function test_getting_prices()
    {
        // RRP excluding tax
        $recommendedRetailPriceExcludingTax = [
            'PriceType' => '01',
            'PriceAmount' => 32.50,
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Suppliers net price excluding tax
        $suppliersNetPriceExcludingTax = [
            'PriceType' => '05',
            'PriceAmount' => 16.25,
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Suppliers net price including tax
        $suppliersNetPriceIncludingTax = [
            'PriceType' => '07',
            'PriceAmount' => 18.44,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 13.5,
                'TaxableAmount' => 16.25,
                'TaxAmount' => 2.19,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($recommendedRetailPriceExcludingTax, $this->groschen->getPrices());
        $this->assertContains($suppliersNetPriceExcludingTax, $this->groschen->getPrices());
        $this->assertContains($suppliersNetPriceIncludingTax, $this->groschen->getPrices());
    }

    /**
     * Test getting products prices for pocket book
     *
     * @return void
     */
    public function test_getting_prices_for_pocket_book()
    {
        // Publisher retail price including tax with PriceAmount
        $publisherRetailPriceIncludingTaxWithPriceAmount = [
            'PriceType' => '42',
            'PriceAmount' => 10.4,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 13.5,
                'TaxableAmount' => 9.12,
                'TaxAmount' => 1.28,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        // Publisher retail price including tax with PriceCoded
        $publisherRetailPriceIncludingTaxWithPriceCoded = [
            'PriceType' => '42',
            'PriceCoded' => [
                'PriceCodeType' => '02',
                'PriceCode' => 'E',
            ],
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 13.5,
                'TaxableAmount' => 9.12,
                'TaxAmount' => 1.28,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $groschen = new Groschen(9789510488225);

        $this->assertContains($publisherRetailPriceIncludingTaxWithPriceAmount, $groschen->getPrices());
        $this->assertContains($publisherRetailPriceIncludingTaxWithPriceCoded, $groschen->getPrices());
    }

    /**
     * Test net price including taxes is rounded correctly
     *
     * @return void
     */
    public function test_net_price_including_taxes_is_recalculated()
    {
        $groschen = new Groschen('9789513191801');

        // Suppliers net price including tax
        $suppliersNetPriceIncludingTax = [
            'PriceType' => '07',
            'PriceAmount' => 6.31,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 13.5,
                'TaxableAmount' => 5.56,
                'TaxAmount' => 0.75,
            ],
            'CurrencyCode' => 'EUR',
            'Territory' => [
                'RegionsIncluded' => 'WORLD',
            ],
        ];

        $this->assertContains($suppliersNetPriceIncludingTax, $groschen->getPrices());
    }

    /**
     * Test that missing prices do not produce an exception
     *
     * @return void
     */
    public function test_missing_prices_do_not_produce_exception()
    {
        $groschen = new Groschen('9789510442012');

        $this->assertInstanceOf('Illuminate\Support\Collection', $groschen->getPrices());
    }

    /**
     * Test getting publishers retail prices
     *
     * @return void
     */
    public function test_getting_publishers_retail_prices()
    {
        $groschen = new Groschen('9789510348956');

        // Publishers recommended retail price including tax
        $publishersRecommendedRetailPriceIncludingTax = [
            'PriceType' => '42',
            'PriceAmount' => 21.9,
            'Tax' => [
                'TaxType' => '01',
                'TaxRateCode' => 'S',
                'TaxRatePercent' => 13.5,
                'TaxableAmount' => 19.21,
                'TaxAmount' => 2.69,
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
     *
     * @return void
     */
    public function test_getting_cover_image_in_supporting_resources()
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
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 1738620,
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/0lgbvE8eazaBsSZzQItlbj/*/9789510366264_frontcover_final.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q%3D&version=2',
                'ContentDate' => [
                    'ContentDateRole' => '01',
                    'Date' => '20260116',
                ],
            ],
        ];

        $this->assertContains($supportingResource, $this->groschen->getSupportingResources());
    }

    /**
     * Test version is added to cover image link in supporting resources
     *
     * @return void
     */
    public function test_version_is_added_to_cover_image_link_in_supporting_resources()
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
                        'FeatureValue' => 1400,
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => 1400,
                    ],
                    [
                        'ResourceVersionFeatureType' => '04',
                        'FeatureValue' => '9789510415665_frontcover_final.jpg',
                    ],
                    [
                        'ResourceVersionFeatureType' => '05',
                        'FeatureValue' => '0.4',
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 373535,
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/2WF93gVGadOBi68p9stOE8/*/9789510415665_frontcover_final.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q%3D&version=2',
                'ContentDate' => [
                    'ContentDateRole' => '01',
                    'Date' => '20260116',
                ],
            ],
        ];

        $groschen = new Groschen('9789510415665');
        $this->assertContains($supportingResource, $groschen->getSupportingResources());
    }

    /**
     * Test getting cover image for Disney which is not brand based
     *
     * @return void
     */
    public function test_getting_cover_image_for_disney()
    {
        $groschen = new Groschen('9789520449155');
        $this->assertTrue($groschen->getSupportingResources()->contains('ResourceContentType', '01'));
    }

    /**
     * Test getting author image in supporting resources
     *
     * @return void
     */
    public function test_getting_author_image_in_supporting_resources()
    {
        $supportingResource = [
            'ResourceContentType' => '04',
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
                        'FeatureValue' => 5059,
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => 3360,
                    ],
                    [
                        'ResourceVersionFeatureType' => '04',
                        'FeatureValue' => 'Max_Manner_c_Nauska_6170.jpg',
                    ],
                    [
                        'ResourceVersionFeatureType' => '05',
                        'FeatureValue' => '12.3',
                    ],
                    [
                        'ResourceVersionFeatureType' => '06',
                        'FeatureValue' => 62281,
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 12858398,
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/D6hl5y3JKlR9FqRtBpClWq/*/Max_Manner_c_Nauska_6170.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q%3D',
                'ContentDate' => [
                    'ContentDateRole' => '01',
                    'Date' => '20241001',
                ],
            ],
            'ResourceFeatures' => [
                [
                    'ResourceFeatureType' => '01',
                    'FeatureValue' => '© Nauska',
                ],
                [
                    'ResourceFeatureType' => '03',
                    'FeatureValue' => 'Nauska',
                ],
            ],
        ];

        $groschen = new Groschen('9789522796844');
        $this->assertContains($supportingResource, $groschen->getSupportingResources());
    }

    /**
     * Test getting supporting resource that has multiple images
     *
     * @return void
     */
    public function test_getting_supporting_resources_with_multiple_images()
    {
        $groschen = new Groschen('9789510485132');
        $this->assertGreaterThan(0, $groschen->getSupportingResources()->count());
    }

    /**
     * Test getting supporting resources for Sangatsu Manga
     *
     * @return void
     */
    public function test_getting_supporting_resources_for_sangatsu_manga()
    {
        $groschen = new Groschen('9789521621338');
        $this->assertGreaterThan(0, $groschen->getSupportingResources()->count());
    }

    /**
     * Test getting Bazar 3D cover image
     *
     * @return void
     */
    public function test_getting_bazar3d_cover_image()
    {
        $coverImageNormal = [
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
                        'FeatureValue' => 5079,
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => 3308,
                    ],
                    [
                        'ResourceVersionFeatureType' => '04',
                        'FeatureValue' => '9789522796714_frontcover_final.jpg',
                    ],
                    [
                        'ResourceVersionFeatureType' => '05',
                        'FeatureValue' => '1.6',
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 1632555,
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/EnfeCLahawWASi-G08b4bh/*/9789522796714_frontcover_final.jpg?authcred=Z3Vlc3Q6Z3Vlc3Q%3D',
                'ContentDate' => [
                    'ContentDateRole' => '01',
                    'Date' => '20260116',
                ],
            ],
        ];

        $coverImage3D = [
            'ResourceContentType' => '03',
            'ContentAudience' => '00',
            'ResourceMode' => '03',
            'ResourceVersion' => [
                'ResourceForm' => '02',
                'ResourceVersionFeatures' => [
                    [
                        'ResourceVersionFeatureType' => '01',
                        'FeatureValue' => 'D503',
                    ],
                    [
                        'ResourceVersionFeatureType' => '02',
                        'FeatureValue' => 2436,
                    ],
                    [
                        'ResourceVersionFeatureType' => '03',
                        'FeatureValue' => 1716,
                    ],
                    [
                        'ResourceVersionFeatureType' => '04',
                        'FeatureValue' => '9789522796714_frontcover_final_3d.png',
                    ],
                    [
                        'ResourceVersionFeatureType' => '05',
                        'FeatureValue' => '3.7',
                    ],
                    [
                        'ResourceVersionFeatureType' => '07',
                        'FeatureValue' => 3837356,
                    ],
                ],
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/8V4HW-00aJUASGSHpJCNB1/*/9789522796714_frontcover_final_3d.png?authcred=Z3Vlc3Q6Z3Vlc3Q%3D',
                'ContentDate' => [
                    'ContentDateRole' => '01',
                    'Date' => '20260116',
                ],
            ],
        ];

        $groschen = new Groschen('9789522796714');
        $this->assertContains($coverImageNormal, $groschen->getSupportingResources());
        $this->assertContains($coverImage3D, $groschen->getSupportingResources());
    }

    /**
     * Test getting audio sample links to Soundcloud
     *
     * @return void
     */
    public function test_getting_external_links_in_supporting_resources()
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

        // Reading sample
        $readingSample = [
            'ResourceContentType' => '15',
            'ContentAudience' => '00',
            'ResourceMode' => '04',
            'ResourceVersion' => [
                'ResourceForm' => '03',
                'ResourceLink' => 'https://elvis.bonnierbooks.fi/file/E3xpJ3rBKdkAfC9Y_R2z3U/*/9789510409749_lukun.pdf?authcred=Z3Vlc3Q6Z3Vlc3Q%3D',
            ],
        ];

        $this->assertContains($youTube, $groschen->getSupportingResources());
        $this->assertContains($readingSample, $groschen->getSupportingResources());
    }

    /**
     * Test getting related products
     *
     * @return void
     */
    public function test_getting_related_products()
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

        // Product without any product relations
        $groschen = new Groschen('9789513160753');
        $this->assertCount(0, $groschen->getRelatedProducts()->where('ProductRelationCode', '06'));
    }

    public function test_getting_related_product_so_that_private_products_are_not_included()
    {
        $groschen = new Groschen('9789510282373');

        $relation = [
            'ProductRelationCode' => '06',
            'ProductIdentifiers' => [
                [
                    'ProductIDType' => '03',
                    'IDValue' => 9789510421543,
                ],
            ],
        ];

        $this->assertNotContains($relation, $groschen->getRelatedProducts());
    }

    /**
     * Getting related products without GTIN
     *
     * @return void
     */
    public function test_getting_related_products_without_gtin()
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
     *
     * @return void
     */
    public function test_checking_if_product_is_confidential()
    {
        $this->assertFalse($this->groschen->isConfidential());

        // Development-confidential
        $groschen = new Groschen('9789510456927');
        $this->assertTrue($groschen->isConfidential());

        // Cancelled-Confidential
        $groschen = new Groschen('9789510446041');
        $this->assertTrue($groschen->isConfidential());
    }

    /**
     * Test checking if product is a luxury book
     *
     * @return void
     */
    public function test_checking_if_product_is_luxury_book()
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
     *
     * @return void
     */
    public function test_getting_cost_center()
    {
        $this->assertSame(301, $this->groschen->getCostCenter());

        // Some other cost center
        $groschen = new Groschen('9789513161873');
        $this->assertSame(902, $groschen->getCostCenter());
    }

    /**
     * Test getting products cost center
     *
     * @return void
     */
    public function test_getting_cost_center_name()
    {
        $this->assertSame('WSOY - Kotimainen kauno', $this->groschen->getCostCenterName());

        // Some other cost center
        $groschen = new Groschen('9789513161873');
        $this->assertSame('Tammi - Tietokirjat', $groschen->getCostCenterName());
    }

    /**
     * Test getting products media type
     *
     * @return void
     */
    public function test_getting_media_type()
    {
        $this->assertSame('BB', $this->groschen->getMediaType());

        // Some other cost center
        $groschen = new Groschen('9789510343203');
        $this->assertSame('AJ', $groschen->getMediaType());
    }

    /**
     * Test getting the products binding code
     *
     * @return void
     */
    public function test_getting_binding_code()
    {
        $this->assertNull($this->groschen->getBindingCode());

        // Product with a binding code
        $groschen = new Groschen('9789510343203');
        $this->assertSame('A103', $groschen->getBindingCode());
    }

    /**
     * Test getting the product status code
     *
     * @return void
     */
    public function test_getting_products_status()
    {
        // Published book
        $groschen = new Groschen('9789510397923');
        $this->assertSame('Published', $groschen->getStatus());

        // Product with a different status code
        $groschen = new Groschen('9789510456927');
        $this->assertSame('Development-Confidential', $groschen->getStatus());
    }

    /**
     * Test getting the number of products in the series
     *
     * @return void
     */
    public function test_getting_products_in_series()
    {
        // $this->assertNull($this->groschen->getProductsInSeries());

        // Product with four products in the serie
        $groschen = new Groschen('9789521610165');
        $this->assertSame(4, $groschen->getProductsInSeries());
    }

    /**
     * Test checking for if the product is immaterial
     *
     * @return void
     */
    public function test_checking_if_product_is_immaterial()
    {
        $this->assertFalse($this->groschen->isImmaterial());

        // Immaterial product
        $groschen = new Groschen('9789510410622');
        $this->assertTrue($groschen->isImmaterial());
    }

    /**
     * Test checking if the product a Print On Demand product
     *
     * @return void
     */
    public function test_checking_if_product_is_print_on_demand()
    {
        $this->assertFalse($this->groschen->isPrintOnDemand());

        // POD product
        $groschen = new Groschen('9789523522923');
        $this->assertTrue($groschen->isPrintOnDemand());
    }

    /**
     * Test getting the internal product number
     *
     * @return void
     */
    public function test_getting_internal_prod_no()
    {
        // Should be same as GTIN
        $this->assertSame('9789510366264', $this->groschen->getInternalProdNo());
    }

    /**
     * Test getting customs number
     *
     * @return void
     */
    public function test_getting_customs_number()
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
     *
     * @return void
     */
    public function test_getting_library_class()
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
     *
     * @return void
     */
    public function test_getting_finnish_book_trade_categorisation()
    {
        $this->assertNull($this->groschen->getFinnishBookTradeCategorisation());

        // Product with library class with a prefix
        $groschen = new Groschen('9789513158699');
        $this->assertSame('L', $groschen->getFinnishBookTradeCategorisation());

        // Pocket books should be always T
        $groschen = new Groschen('9789520427467');
        $this->assertSame('T', $groschen->getFinnishBookTradeCategorisation());

        // Product where product does not have library class
        $groschen = new Groschen('9789521606700');
        $this->assertNull($groschen->getFinnishBookTradeCategorisation());
    }

    /**
     * Test getting the products marketing category
     *
     * @return void
     */
    public function test_getting_marketing_category()
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
     *
     * @return void
     */
    public function test_getting_sales_season()
    {
        // 2010 autumn
        $this->assertSame('2010/1', $this->groschen->getSalesSeason());

        // Product with the fall sales season 2013 autumn
        $groschen = new Groschen('9789510374665');
        $this->assertSame('2013/2', $groschen->getSalesSeason());

        // Product with the fall sales season 2013 autumn
        $groschen = new Groschen('9789520418120');
        $this->assertSame('2021/1', $groschen->getSalesSeason());

        // Product without sales season
        $groschen = new Groschen('9789510102893');
        $this->assertNull($groschen->getSalesSeason());

        // Product that has season but no period
        $groschen = new Groschen('9789513130855');
        $this->assertNull($groschen->getSalesSeason());

        // Product that has season period but no year
        $groschen = new Groschen('9789510451663');
        $this->assertNull($groschen->getSalesSeason());
    }

    /**
     * Test getting products sales season
     *
     * @return void
     */
    public function test_getting_backlist_sales_season()
    {
        $this->assertNull($this->groschen->getBacklistSalesSeason());

        // Product with the fall sales season 2013/3
        $groschen = new Groschen('9789513129071');
        $this->assertSame('2016 Autumn', $groschen->getBacklistSalesSeason());
    }

    /**
     * Test getting sales restrictions
     *
     * @return void
     */
    public function test_getting_sales_restrictions_subscription_and_library_excluded_products()
    {
        // Physical products should not have any restrictions as they are not supported yet
        $this->assertCount(0, $this->groschen->getSalesRestrictions());

        // Product that does not have subscription or library rights
        $groschen = new Groschen('9789510405451');
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
     * Check that we get sales restrictions for product that does not have any
     *
     * @return void
     */
    public function test_getting_sales_restrictions_for_product_that_does_not_have_any()
    {
        $groschen = new Groschen('9789525912531');
        $salesRestrictions = $groschen->getSalesRestrictions();

        // Product that does not have subscription or library rights
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '12'));
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '09'));

        $retailerExceptions = $salesRestrictions->where('SalesRestrictionType', '11')->pluck('SalesOutlets')->first();

        // Check that BookBeat exist in list 11 (Retailer exception)
        $salesOutlet = [
            'SalesOutlet' => [
                'SalesOutletIdentifiers' => [
                    [
                        'SalesOutletIDType' => '03',
                        'IDValue' => 'BOO',
                    ],
                ],
            ],
        ];

        $this->assertContains($salesOutlet, $retailerExceptions);
    }

    /**
     * Test getting sales restrictions for each outlet
     *
     * @return void
     */
    public function test_getting_sales_restrictions_for_sales_outlet()
    {
        // ePub with unit and subscription rights but no library
        $groschen = new Groschen('9789510369654');
        $salesRestrictions = $groschen->getSalesRestrictions();
        $exclusiveRetailers = $salesRestrictions->where('SalesRestrictionType', '04')->pluck('SalesOutlets')->first();

        // Check that normal unit sales exists
        $salesOutlet = [
            'SalesOutlet' => [
                'SalesOutletIdentifiers' => [
                    [
                        'SalesOutletIDType' => '03',
                        'IDValue' => 'BOO',
                    ],
                ],
            ],
        ];

        // Normal unit sales channel should appear in exclusive retailers, not in exceptions
        $this->assertContains($salesOutlet, $exclusiveRetailers);
    }

    /**
     * Test that E-kirjasto is included as exclusive retailer
     *
     * @return void
     */
    public function test_getting_sales_restrictions_for_e_kirjasto()
    {
        $groschen = new Groschen('9789510352212');
        $salesRestrictions = $groschen->getSalesRestrictions();
        $exclusiveRetailers = $salesRestrictions->where('SalesRestrictionType', '04')->pluck('SalesOutlets')->first();

        // Check that normal unit sales exists
        $salesOutlet = [
            'SalesOutlet' => [
                'SalesOutletIdentifiers' => [
                    [
                        'SalesOutletIDType' => '03',
                        'IDValue' => 'EKJ',
                    ],
                ],
            ],
        ];

        $this->assertContains($salesOutlet, $exclusiveRetailers);
    }

    /**
     * Test getting sales restrictions for subscription only product
     *
     * @return void
     */
    public function test_getting_sales_restrictions_for_subscription_only_product()
    {
        $groschen = new Groschen('9789510452127');
        $salesRestrictions = $groschen->getSalesRestrictions();

        // Should have "Not for sale to libraries"
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '09'));

        // Should have "Subscription services only"
        $this->assertTrue($salesRestrictions->contains('SalesRestrictionType', '13'));
    }

    /**
     * Test getting the products tax rate
     *
     * @return void
     */
    public function test_getting_tax_rate()
    {
        // Hardback
        $this->assertSame(13.50, $this->groschen->getTaxRate());

        // Digital product
        $groschen = new Groschen('9789510435199');
        $this->assertSame(13.50, $groschen->getTaxRate());

        // Product with 25.5% VAT
        $groschen = new Groschen('9789510338728');
        $this->assertSame(25.50, $groschen->getTaxRate());
    }

    /**
     * Get the distribution channels in Mockingbird
     *
     * @return void
     */
    public function test_getting_distribution_channels()
    {
        // ePub 2 unit sales only product
        $groschen = new Groschen('9789510417188');

        // Elisa and Elisa kirja kuukausitilaus should be removed
        $elisa = $groschen->getDistributionChannels()
            ->where('channel', 'Elisa Kirja')
            ->where('channelType', 'Unit sales')
            ->where('hasRights', false)
            ->where('distributionAllowed', false)
            ->where('salesOutletId', 'ELS');

        $elisaKirjaKuukausitilaus = $groschen->getDistributionChannels()
            ->where('channel', 'Elisa Kirja Kuukausitilaus')
            ->where('channelType', 'Subscription')
            ->where('hasRights', false)
            ->where('distributionAllowed', false)
            ->where('salesOutletId', 'ELS');

        $almaTalent = $groschen->getDistributionChannels()
            ->where('channel', 'Alma Talent')
            ->where('channelType', 'Subscription')
            ->where('hasRights', false)
            ->where('distributionAllowed', false)
            ->where('salesOutletId', 'ALT');

        $this->assertCount(0, $elisa->toArray());
        $this->assertCount(0, $elisaKirjaKuukausitilaus->toArray());
        $this->assertCount(0, $almaTalent->toArray());

        $bookbeat = $groschen->getDistributionChannels()
            ->where('channel', 'BookBeat')
            ->where('channelType', 'Subscription')
            ->where('hasRights', true)
            ->where('distributionAllowed', true)
            ->where('salesOutletId', 'BOO');

        $this->assertCount(0, $bookbeat->toArray());
    }

    /**
     * Test the is connected to ERP
     *
     * @return void
     */
    public function test_if_product_is_connected_to_erp()
    {
        $this->assertFalse($this->groschen->isConnectedToErp());
    }

    /**
     * Test getting print orders and their recipients
     *
     * @return void
     */
    public function test_getting_print_orders()
    {
        $groschen = new Groschen('9789510383124');
        $printOrder = $groschen->getPrintOrders()->where('printNumber', 24)->first();

        $this->assertSame(5000, $printOrder['orderedQuantity']);
        $this->assertSame(59721, $printOrder['supplierId']); // Livonia Print Ltd

        // Delivery without planned delivery date
        $this->assertSame('Livonia Print Ltd', $printOrder['deliveries']->where('recipient', 'Production department')->pluck('supplier')->first());
        $this->assertSame(2, $printOrder['deliveries']->where('recipient', 'Production department')->pluck('orderedQuantity')->first());
        $this->assertNull($printOrder['deliveries']->where('recipient', 'Production department')->pluck('plannedDeliveryDate')->first());

        // Delivery with planned delivery date
        $this->assertSame('Livonia Print Ltd', $printOrder['deliveries']->where('recipient', 'Warehouse Porvoon Kirjakeskus')->pluck('supplier')->first());
        $this->assertSame(5000, $printOrder['deliveries']->where('recipient', 'Warehouse Porvoon Kirjakeskus')->pluck('orderedQuantity')->first());
        $this->assertSame('2019-09-02T00:00:00', $printOrder['deliveries']->where('recipient', 'Warehouse Porvoon Kirjakeskus')->pluck('plannedDeliveryDate')->first());

        // Product with different printer on different prints
        $groschen = new Groschen('9789510504260');
        $printOrder = $groschen->getPrintOrders()->where('printNumber', 1)->first();
        $this->assertSame(59750, $printOrder['supplierId']); // Scandbook

        $printOrder = $groschen->getPrintOrders()->where('printNumber', 2)->first();
        $this->assertSame(59740, $printOrder['supplierId']); // Otavan kirjapaino Oy
    }

    /**
     * Test getting print orders for digital products
     *
     * @return void
     */
    public function test_getting_print_orders_for_digital_product()
    {
        $groschen = new Groschen('9789510433805');
        $this->assertCount(0, $groschen->getPrintOrders());
    }

    /**
     * Test if product is main edition
     *
     * @return void
     */
    public function test_if_product_is_main_edition()
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
     *
     * @return void
     */
    public function test_if_product_is_internet_edition()
    {
        // Internet edition
        $groschen = new Groschen('9789510390795');
        $this->assertTrue($groschen->isInternetEdition());

        // Product that is not internet edition
        $groschen = new Groschen('9789510390016');
        $this->assertFalse($groschen->isInternetEdition());
    }

    /**
     * Test if product is internet edition is explicitly set
     *
     * @return void
     */
    public function test_if_product_is_internet_edition_is_explicitly_set()
    {
        // Product where spesific edition is selected as web edition
        $groschen = new Groschen('9789523738423');
        $this->assertTrue($groschen->isInternetEditionExplicitlySet());
    }

    /**
     * Test getting production plan
     *
     * @return void
     */
    public function test_getting_production_plan()
    {
        $groschen = new Groschen('9789510423417');
        $productionPlan = $groschen->getProductionPlan();

        $plannedDate = $productionPlan->where('print', 1)->where('name', 'Delivery to warehouse')->pluck('planned_date')->first();
        $expectedDate = new DateTime('2019-03-11');

        $this->assertSame($plannedDate->format('Y-m-d'), $expectedDate->format('Y-m-d'));
    }

    /**
     * During the migration the datestamps were changed, some had milliseconds and some not
     *
     * @return void
     */
    public function test_getting_production_plan_where_date_is_in_different_format()
    {
        $groschen = new Groschen('9789510471425');
        $productionPlan = $groschen->getProductionPlan();
        $actualDate = $productionPlan->where('print', 1)->where('name', 'Definitive print run')->pluck('actual_date')->first();
        $expectedDate = new DateTime('2021-02-12');

        $this->assertSame($actualDate->format('Y-m-d'), $expectedDate->format('Y-m-d'));
    }

    /**
     * Test getting quantity on production plan for warehouse delivery
     *
     * @return void
     */
    public function test_getting_quantity_on_production_plan_for_warehouse_delivery()
    {
        $this->assertSame(3000, $this->groschen->getProductionPlan()->where('print', 16)->where('name', 'Delivery to warehouse')->pluck('quantity')->first());
    }

    /**
     * Test getting comments
     *
     * @return void
     */
    public function test_getting_technical_description_comment()
    {
        $groschen = new Groschen('9789520405786');
        $this->assertSame('PMS on cover PMS 322 C.', $groschen->getTechnicalDescriptionComment());
    }

    /**
     * Test getting technical printing data
     *
     * @return void
     */
    public function test_getting_technical_data()
    {
        $groschen = new Groschen('9789520405786');

        // Insides
        $inside = [
            'partName' => 'inside',
            'width' => 135,
            'height' => 215,
            'paperType' => '',
            'paperName' => 'HOLBFSC',
            'grammage' => 70,
            'grammageOther' => null,
            'bulk' => 'Other',
            'bulkValue' => null,
            'colors' => '1/1',
            'colorNames' => null,
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
            'paperName' => null,
            'grammage' => null,
            'colors' => null,
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

        $this->assertContains($softCover, $groschen->getTechnicalData());

        // End papers
        $endPapers = [
            'partName' => 'endPapers',
            'paperType' => 'Other',
            'paperName' => 'MUNPC115_15',
            'grammage' => 115,
            'colors' => null,
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
            'comments' => 'Kannen mittapiirros Saara S/Sanna U  5.11.18',
        ];

        $this->assertContains($bookBinding, $groschen->getTechnicalData());
    }

    /**
     * Test getting technical printing data for image attachment
     *
     * @return void
     */
    public function test_getting_attachment_technical_data()
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
     *
     * @return void
     */
    public function test_getting_technical_data_for_product_without_any_does_not_throw_exception()
    {
        $groschen = new Groschen('9789510429938');
        $this->assertCount(8, $groschen->getTechnicalData());
    }

    /**
     * Test getting products prizes
     *
     * @return void
     */
    public function test_getting_prizes()
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
     * Test getting publishing status and product availability for Storia
     *
     * @return void
     */
    public function test_getting_product_availability()
    {
        // Sold-out
        $groschen = new Groschen('9789510381380');
        $this->assertSame('07', $groschen->getPublishingStatus());
        $this->assertSame('40', $groschen->getProductAvailability());

        // Digital product with Sold out status
        $groschen = new Groschen('9789513182571');
        $this->assertSame('07', $groschen->getPublishingStatus());
        $this->assertSame('40', $groschen->getProductAvailability());

        // Cancelled
        $groschen = new Groschen('9789510423042');
        $this->assertSame('01', $groschen->getPublishingStatus());
        $this->assertSame('01', $groschen->getProductAvailability());

        // Development product
        $groschen = new Groschen('9789510426906');
        $this->assertSame('02', $groschen->getPublishingStatus());
        $this->assertSame('10', $groschen->getProductAvailability());

        // Exclusive sales
        $groschen = new Groschen('9789513130503');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('30', $groschen->getProductAvailability());

        // Delivery block
        $groschen = new Groschen('9789510365311');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('34', $groschen->getProductAvailability());

        // Published digital product
        $groschen = new Groschen('9789510365274');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('21', $groschen->getProductAvailability());

        // Published product that has stock
        $groschen = new Groschen('9789510507339');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('21', $groschen->getProductAvailability());

        // Published product with 0 stock and no planned reprint in the future
        // $groschen = new Groschen('9789523123366');
        // >$this->assertSame('06', $groschen->getPublishingStatus());
        // $this->assertSame('31', $groschen->getProductAvailability());

        // Published product with 0 stock and planned reprint date in the future
        /*
        $groschen = new Groschen('9789510415504');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('32', $groschen->getProductAvailability());
        */

        // Short-run product that has stock
        $groschen = new Groschen('9789510414378');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('21', $groschen->getProductAvailability());

        // Upcoming Print On Demand product
        /*
        $groschen = new Groschen('9789520448028');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('12', $groschen->getProductAvailability());
        */

        // Print On Demand product in the past
        $groschen = new Groschen('9789523125094');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('23', $groschen->getProductAvailability());

        // Short-run product with 0 stock and planned reprint date in the future
        /*
        $groschen = new Groschen('9789510407301');
        $this->assertSame('04', $groschen->getPublishingStatus());
        $this->assertSame('32', $groschen->getProductAvailability());
        */

        // Published product with 0 stock and no planned reprint - Such combination does not exist at this time
        /*
        $groschen = new Groschen('9789522201355');
        $this->assertSame('06', $groschen->getPublishingStatus());
        $this->assertSame('31', $groschen->getProductAvailability());
        */
    }

    /**
     * Test if the publication date passed is handled correctly
     *
     * @return void
     */
    public function test_if_publication_date_is_passed()
    {
        $groschen = new Groschen('9789513178888');
        $this->assertFalse($groschen->isPublicationDatePassed());

        $groschen = new Groschen('9789520401122');
        $this->assertTrue($groschen->isPublicationDatePassed());
    }

    /**
     * Test getting products suppliers for WSOY product
     *
     * @return void
     */
    public function test_getting_supplier_for_wsoy_product()
    {
        $supplier = [
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
            'OnHand' => 0,
            'Proximity' => '03',
        ];

        // WSOY product with supplier
        $this->assertContains($supplier, $this->groschen->getSuppliers());
    }

    /**
     * Test getting fake supplier for digital product
     *
     * @return void
     */
    public function test_getting_supplier_for_digital_product()
    {
        $supplier = [
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
        ];

        // Digital product should return fake Storia supplier
        $groschen = new Groschen('9789510420157');
        $this->assertContains($supplier, $groschen->getSuppliers());
    }

    /**
     * Test getting supplier for Bazar product in Storia stock
     *
     * @return void
     */
    public function test_getting_supplier_for_bazar_product()
    {
        $supplier = [
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
            'OnHand' => 0,
            'Proximity' => '03',
        ];

        // Product in Storia stock return them as supplier
        $groschen = new Groschen('9789525637595');
        $this->assertContains($supplier, $groschen->getSuppliers());
    }

    /**
     * Test getting supplier for Bazar product in Storia stock
     *
     * @return void
     */
    public function test_getting_supplier_for_bazar_product_that_does_not_exist_in_storia()
    {
        $supplier = [
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
        ];

        // Digital product should return Storia as supplier
        $groschen = new Groschen('9789522794987');
        $this->assertContains($supplier, $groschen->getSuppliers());
    }

    /**
     * Test getting SupplyDates
     *
     * @return void
     */
    public function test_getting_supply_dates()
    {
        // Product with stock
        $supplyDate = [
            'SupplyDateRole' => '34',
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
     *
     * @return void
     */
    public function test_getting_contacts()
    {
        $contact = [
            'id' => 67435,
            'firstName' => 'Jari',
            'lastName' => 'Louhelainen',
            'supplierId' => 20009931,
        ];

        $this->assertContains($contact, $this->groschen->getContacts());
    }

    /**
     * Test getting all editions
     *
     * @return void
     */
    public function test_getting_editions()
    {
        $edition = [
            'isbn' => 9789520478766,
            'title' => 'Disney. Frozen. Anna & Elsa. Jännittävä jääkeksintö',
            'publisher' => 'Tammi',
        ];

        $this->assertContains($edition, $this->groschen->getEditions());
    }

    /**
     * Test getting web publishing dates
     *
     * @return void
     */
    public function test_getting_web_publishing_dates()
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
     *
     * @return void
     */
    public function test_getting_comments()
    {
        $groschen = new Groschen('9789520404338');
        $comments = $groschen->getComments();

        $this->assertTrue($comments->contains('type', 'general'), $comments);
        $this->assertFalse($comments->contains('type', 'insert/cover material'), $comments);
        $this->assertContains(['type' => 'print order', 'comment' => 'On cover and inside dispersion varnish matt. Boardbook binding, with two round corners'], $comments);
        $this->assertFalse($comments->contains('type', 'rights'), $comments);
    }

    /**
     * Test getting products sales status
     *
     * @return void
     */
    public function test_getting_sales_status()
    {
        $this->assertSame('Passive', $this->groschen->getSalesStatus());

        $groschen = new Groschen('9789510430569');
        $this->assertSame('Passive', $groschen->getSalesStatus());
    }

    /**
     * Test getting products main editions ISBN
     *
     * @return void
     */
    public function test_getting_main_edition_isbn()
    {
        $this->assertSame(9789510366264, $this->groschen->getMainEditionIsbn());

        // ePub version of the same book
        $groschen = new Groschen('9789510369654');
        $this->assertSame(9789510366264, $groschen->getMainEditionIsbn());
    }

    /**
     * Test getting products main editions cost center
     *
     * @return void
     */
    public function test_getting_main_edition_cost_center()
    {
        // ePub version
        $groschen = new Groschen('9789510369654');
        $this->assertSame(301, $groschen->getMainEditionCostCenter());
    }

    /**
     * Test getting products work id
     *
     * @return void
     */
    public function test_getting_work_id()
    {
        $this->assertSame(243763, $this->groschen->getWorkId());
    }

    /**
     * Test getting products edition id
     *
     * @return void
     */
    public function test_getting_edition_id()
    {
        $this->assertSame(243764, $this->groschen->getEditionId());
    }

    /**
     * Test getting correct edition and work id for deactivated product
     *
     * @return void
     */
    public function test_getting_correct_edition_and_work_id_for_duplicate_isbn()
    {
        $groschen = new Groschen('9789523820999');
        $this->assertSame(291890, $groschen->getWorkId());
        $this->assertSame(297407, $groschen->getEditionId());
    }

    /**
     * Test getting edition types
     *
     * @return void
     */
    public function test_getting_edition_types()
    {
        // Work that has both physical and digital formats
        $groschen = new Groschen('9789510410738');
        $this->assertCount(0, $groschen->getEditionTypes());

        // Illustrated edition
        $groschen = new Groschen('9789513187057');
        $this->assertCount(1, $groschen->getEditionTypes());
        $this->assertContains(['EditionType' => 'ILL'], $groschen->getEditionTypes());

        // ePub 3 enhanced with audio
        $groschen = new Groschen('9789520405724');
        $this->assertCount(1, $groschen->getEditionTypes());
        $this->assertContains(['EditionType' => 'ENH'], $groschen->getEditionTypes());

        $groschen = new Groschen('9789520429799');
        $this->assertCount(1, $groschen->getEditionTypes());
        $this->assertContains(['EditionType' => 'ENH'], $groschen->getEditionTypes());

        // Selkokirja
        $groschen = new Groschen('9789520475925');
        $this->assertCount(1, $groschen->getEditionTypes());
        $this->assertContains(['EditionType' => 'SMP'], $groschen->getEditionTypes());
        $this->assertNotContains(['EditionType' => 'ETR'], $groschen->getEditionTypes());

        // Helppolukuinen
        $groschen = new Groschen('9789510518601');
        $this->assertNotContains(['EditionType' => 'SMP'], $groschen->getEditionTypes());
        $this->assertContains(['EditionType' => 'ETR'], $groschen->getEditionTypes());
    }

    /**
     * Test getting events
     *
     * @return void
     */
    public function test_getting_events()
    {
        $this->markTestSkipped('We need new public share level, see https://ws.mockingbird.nu/book/262047/launch-plan/activity/82167 for example.');

        // Edition that has no public events
        $groschen = new Groschen('9789510410738');
        $this->assertCount(0, $groschen->getEvents());

        // Edition that has public event
        $groschen = new Groschen('9789510442067');

        $this->assertContains([
            'EventRole' => '31',
            'EventName' => 'Vierailu Pieksämäen kirjakaupassa',
            'EventDate' => '20191112',
        ], $groschen->getEvents());
    }

    /**
     * Test getting the retailer price multiplier, see https://intranet.bonnierbooks.fi/pages/viewpage.action?pageId=16745509
     *
     * @return void
     */
    public function test_getting_retailer_price_multiplier()
    {
        // Manga
        $groschen = new Groschen('9789521616068');
        $this->assertSame(1.645, $groschen->getRetailPriceMultiplier());

        // Pocket book
        $groschen = new Groschen('9789513199401');
        $this->assertSame(1.645, $groschen->getRetailPriceMultiplier());

        // ePub2
        $groschen = new Groschen('9789510425121');
        $this->assertSame(1.435, $groschen->getRetailPriceMultiplier());

        // ePub3
        $groschen = new Groschen('9789520405670');
        $this->assertSame(1.435, $groschen->getRetailPriceMultiplier());

        // Audio book
        $groschen = new Groschen('9789510430415');
        $this->assertSame(1.435, $groschen->getRetailPriceMultiplier());

        // Others
        $this->assertSame(1.205, $this->groschen->getRetailPriceMultiplier());
    }

    /**
     * Test getting trade category
     */
    public function test_getting_trade_category()
    {
        // Normal hardback from catalogue
        $this->assertNull($this->groschen->getTradeCategory());

        // Pocket book
        $groschen = new Groschen('9789510427484');
        $this->assertSame('04', $groschen->getTradeCategory());
    }

    /**
     * Test getting ProductContentTypes
     *
     * @return void
     */
    public function test_getting_product_content_types()
    {
        $textBasedBindingCodes = [
            9789510436134, // Hardback
            9789510419915, // ePub2
            9789513192648, // PDF
        ];

        foreach ($textBasedBindingCodes as $gtin) {
            $groschen = new Groschen($gtin);

            $this->assertContains([
                'ContentType' => '10',
                'Primary' => true,
            ], $groschen->getProductContentTypes());
        }

        $audioBasedBindingCodes = [
            // Pre-recorded digital - No test case exists
            9789510436233, // Downloadable audio file
            9789510321799, // CD
            9789513185787, // MP3-CD
            9789510232644, // Other audio format
            6430060032781, // Podcast
        ];

        foreach ($audioBasedBindingCodes as $gtin) {
            $groschen = new Groschen($gtin);

            $this->assertContains([
                'ContentType' => '01',
                'Primary' => true,
            ], $groschen->getProductContentTypes(), $gtin);
        }

        // Picture-and-audio book
        $groschen = new Groschen(9789510429914);

        $this->assertContains([
            'ContentType' => '10',
            'Primary' => true,
        ], $groschen->getProductContentTypes());

        $this->assertContains([
            'ContentType' => '01',
            'Primary' => false,
        ], $groschen->getProductContentTypes());

        // Board book
        $groschen = new Groschen(9789513141622);
        $this->assertContains([
            'ContentType' => '10',
            'Primary' => true,
        ], $groschen->getProductContentTypes());

        // Marketing material
        $groschen = new Groschen(6430060032064);
        $this->assertCount(0, $groschen->getProductContentTypes());

        // Application
        $groschen = new Groschen(9789510392263);
        $this->assertCount(0, $groschen->getProductContentTypes());

        // ePub3 without audio
        $groschen = new Groschen(9789510477724);

        $this->assertContains([
            'ContentType' => '10',
            'Primary' => true,
        ], $groschen->getProductContentTypes());

        $this->assertNotContains([
            'ContentType' => '01',
            'Primary' => false,
        ], $groschen->getProductContentTypes());

        // ePub 3 with audio
        $groschen = new Groschen(9789510437940);

        $this->assertContains([
            'ContentType' => '10',
            'Primary' => true,
        ], $groschen->getProductContentTypes());

        $this->assertContains([
            'ContentType' => '01',
            'Primary' => false,
        ], $groschen->getProductContentTypes());

        // ePub 3 with text and images
        $groschen = new Groschen(9789528500308);

        // Text
        $this->assertContains([
            'ContentType' => '10',
            'Primary' => true,
        ], $groschen->getProductContentTypes());

        // Still images / graphics
        $this->assertContains([
            'ContentType' => '07',
            'Primary' => false,
        ], $groschen->getProductContentTypes());
    }

    /**
     * Test getting contributor biography and links
     *
     * @return void
     */
    public function test_getting_contributor_biography_and_links()
    {
        // Author
        $author = [
            'Identifier' => 62420,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Elina Kilkku',
            'PersonNameInverted' => 'Kilkku, Elina',
            'KeyNames' => 'Kilkku',
            'NamesBeforeKey' => 'Elina',
            'BiographicalNote' => '<p><strong>Elina Kilkku</strong> (s. 1980) on helsinkiläinen teatteriohjaaja ja kirjailija. Hän on työskennellyt ohjaajana mm. Kansallisteatterissa, Teatteri Jurkassa ja Teatteri Takomossa sekä kirjoittanut useita näytelmiä.<br/><br/>Kilkun esikoisromaani <em>Äideistä paskin</em> ilmestyi vuonna 2014. Sen jälkeen häneltä on julkaistu useita teoksia, muun muassa vuonna 2020 nuorten romaani <em>Ihana tyttö</em>, joka herätti puhuttelevalla aiheellaan paljon keskustelua, sekä hulvaton, parisuhteen stereotypioita kellistävä <em>Vaimovallankumous</em> helmikuussa 2021. Lisäksi hän on kirjoittanut työttömästä freelance-taideammattilaisesta ja sinkkuäidistä kertovan Alina-trilogian, jonka päätösosa <em>Jumalainen jälkinäytös</em> julkaistiin elokuussa 2021. Tässä mustan huumorin maustamassa trilogiassa ovat aiemmin ilmestyneet romaanit <em>Täydellinen näytelmä</em> ja <em>Mahdoton elämä</em>.</p>',
            'WebSites' => [
                [
                    'WebsiteRole' => '06',
                    'WebsiteDescription' => 'Tekijän omat nettisivut',
                    'Website' => 'http://www.elinakilkku.com/',
                ],
                [
                    'WebsiteRole' => '42',
                    'WebsiteDescription' => 'Elina Kilkku Twitterissä',
                    'Website' => 'https://twitter.com/elinakilkku',
                ],
                [
                    'WebsiteRole' => '42',
                    'WebsiteDescription' => 'Elina Kilkku Facebookissa',
                    'Website' => 'https://www.facebook.com/kirjailijaelinakilkku/',
                ],
                [
                    'WebsiteRole' => '42',
                    'WebsiteDescription' => 'Elina Kilkku Instagramissa',
                    'Website' => 'https://www.instagram.com/elinakilkku/',
                ],
            ],
            'SelectionLists' => [
                'WS Bazarkustannus.fi julkiset tekijät',
                'Kirja.fi julkiset tekijät',
            ],
            'HasAuthorImage' => true,
        ];

        $groschen = new Groschen('9789522796783');
        $this->assertContains($author, $groschen->getContributors());
    }

    /**
     * Test getting contributor links for pseudonym
     *
     * @return void
     */
    public function test_getting_links_for_pseudonym()
    {
        // Author
        $author = [
            'Identifier' => 56749,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Jenniemilia',
            'KeyNames' => 'Jenniemilia',
            'BiographicalNote' => '<strong>Jenniemilia</strong> on valmentaja, joogan ja meditaation opettaja sekä valokuvaaja. Hän pitää suosittuja <em>Elä, opi ja rakasta</em> -kursseja, joissa hän hyödyntää mm. kehollisen läsnäolon ja kognitiivisen psykoterapian menetelmiä.',
            'WebSites' => [
                [
                    'WebsiteRole' => '42',
                    'WebsiteDescription' => 'Jenniemilia Facebookissa',
                    'Website' => 'https://www.facebook.com/jenniemiliaofficial',
                ],
            ],
            'SelectionLists' => [
                'wsoy.fi julkiset tekijät',
            ],
            'HasAuthorImage' => true,
        ];

        $groschen = new Groschen('9789510404355');
        $this->assertContains($author, $groschen->getContributors());
    }

    /**
     * Test getting contributor dates (birth year)
     *
     * @return void
     */
    public function test_getting_contributor_dates()
    {
        // Author
        $author = [
            'Identifier' => 54801,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Tove Jansson',
            'PersonNameInverted' => 'Jansson, Tove',
            'KeyNames' => 'Jansson',
            'NamesBeforeKey' => 'Tove',
            'BiographicalNote' => "<p>Kirjailija, taidemaalari, piirtäjä, filosofian tohtori ja Muumi-hahmojen luoja<strong> Tove Marika Jansson</strong> syntyi 9. elokuuta 1914 Helsingissä taiteilijaperheeseen. Hänen isänsä oli kuvanveistäjä Viktor Jansson ja äitinsä piirtäjä Signe Hammarsten.</p> <p>Tove Jansson opiskeli Tukholman taideteollisessa oppilaitoksessa, Suomen taideyhdistyksen piirustuskoulussa Ateneumissa ja Ecole d'Adrien Holy'ssa Pariisissa. Hänen julkinen uransa alkoi piirroksilla pilalehti Garmnissa 1929.</p> <p>Kansainvälisesti tunnetuksi hän tuli satukirjoillaan (vuodesta 1945) ja sarjakuvillaan, joiden mielikuvituksellisen ja humoristisen henkilögallerian päähahmo on hyväntahtoinen Muumipeikko.\u{A0}Veljensä Lars Janssonin kanssa Tove Jansson kirjoitti ja piirsi Muumipeikko-sarjakuvaa 21 vuoden ajan. Muumipeikko-sarjakuva ilmestyi yli 40 maassa vuosina 1954-1975.</p> <p>Tove Janssonin kirjoja on käännetty useille kymmenille kielille ja niistä on tehty lukuisia teatteri-, ooppera-, filmi- sekä tv- ja radiosovituksia.</p> <p>Vuonna 1968 ilmestyi Tove Janssonin muistelmateos <em>Bildhuggarens's dotter</em>, suomennettu <em>Kuvanveistäjän tytär</em> 1969.</p> <p>Tove Jansson kuoli Helsingissä 27. kesäkuuta 2001 86-vuotiaana.</p>",
            'WebSites' => [],
            'ContributorDates' => [
                [
                    'ContributorDateRole' => '50',
                    'Date' => '1914',
                    'DateFormat' => '05',
                ],
            ],
            'ISNI' => '0000000121478925',
        ];

        $groschen = new Groschen('9789510469989');
        $this->assertNotContains($author, $groschen->getContributors());
    }

    /**
     * Test getting work relation code
     *
     * @return void
     */
    public function test_getting_related_works()
    {
        $workRelation = [
            'WorkRelationCode' => '01',
            'WorkIdentifiers' => [
                [
                    'WorkIDType' => '01',
                    'IDTypeName' => 'Werner Söderström teostunniste',
                    'IDValue' => 243763,
                ],
            ],
        ];

        $this->assertContains($workRelation, $this->groschen->getRelatedWorks());
    }

    /**
     * Test getting the Onix notification type
     *
     * @return void
     */
    public function test_getting_notification_type()
    {
        // Already published product
        $this->assertSame('03', $this->groschen->getNotificationType());

        // Publishing date in the future
        $groschen = new Groschen('9789510421611');
        $this->assertSame('02', $groschen->getNotificationType());

        // Exclusive sales
        $groschen = new Groschen('9789513130503');
        $this->assertSame('01', $groschen->getNotificationType());

        // Permanently withdrawn from sales
        $groschen = new Groschen('9789520426705');
        $this->assertSame('05', $groschen->getNotificationType());
    }

    /**
     * Test that duplicate Finna keywords with periods are filtered out
     *
     * @return void
     */
    public function test_duplicate_finna_keywords_with_periods_are_filtered_out()
    {
        $groschen = new Groschen('9789520408657');
        $keywords = explode(';', $groschen->getSubjects()->where('SubjectSchemeIdentifier', '20')->pluck('SubjectHeadingText')->first());

        $this->assertContains('käsityöt', $keywords);
        $this->assertNotContains('käsityöt.', $keywords);
    }

    /**
     * Test that same words with different cases are filtered out
     *
     * @return void
     */
    public function test_different_cases_are_filtered_out()
    {
        $groschen = new Groschen('9789510356197');

        $keywords = explode(';', $groschen->getSubjects()->where('SubjectSchemeIdentifier', '20')->pluck('SubjectHeadingText')->first());

        $this->assertContains('proosarunot', $keywords);
        $this->assertNotContains('Proosarunot', $keywords);
    }

    /**
     * Test that non-fictional characters are listed in keywords
     *
     * @return void
     */
    public function test_non_fictional_characters_are_listed_in_keywords()
    {
        $groschen = new Groschen('9789510452097');
        $keywords = explode(';', $groschen->getSubjects()->where('SubjectSchemeIdentifier', '20')->pluck('SubjectHeadingText')->first());

        $this->assertContains('tim bergling', $keywords);
        $this->assertContains('avicii', $keywords);
        $this->assertContains('muusikot', $keywords);
        $this->assertContains('dj:t', $keywords);

        // Check that names listed are first
        $this->assertSame('tim bergling', $keywords[0]);
        $this->assertSame('avicii', $keywords[1]);
    }

    /**
     * Test getting the count of manufacture
     *
     * @return void
     */
    public function test_getting_country_of_manufacture()
    {
        $this->assertSame('FI', $this->groschen->getCountryOfManufacture());

        // Contact without address information
        $groschen = new Groschen('9789510378151');
        $this->assertNull($groschen->getCountryOfManufacture());

        // Product with only "Yhteyspainatus" printer
        $groschen = new Groschen('9789510483725');
        $this->assertNull($groschen->getCountryOfManufacture());

        // Digital product should return null
        $groschen = new Groschen('9789510477113');
        $this->assertNull($groschen->getCountryOfManufacture());

        // Product with "Yhteispainatus" as the printer
        $groschen = new Groschen('6430060032729');
        $this->assertSame('SK', $groschen->getCountryOfManufacture());

        $groschen = new Groschen('9789520461195');
        $this->assertSame('CN', $groschen->getCountryOfManufacture());

        $groschen = new Groschen('9789510366264');
        $this->assertSame('FI', $groschen->getCountryOfManufacture());
    }

    /**
     * Test getting contributors ISNI numbers
     *
     * @return void
     */
    public function test_getting_contributors_isni_number()
    {
        // Author
        $author = [
            'Identifier' => 68945,
            'SequenceNumber' => 2,
            'ContributorRole' => 'A01',
            'PersonName' => 'Tuomas Eriksson',
            'PersonNameInverted' => 'Eriksson, Tuomas',
            'KeyNames' => 'Eriksson',
            'NamesBeforeKey' => 'Tuomas',
            'BiographicalNote' => null,
            'WebSites' => [],
            'ISNI' => '0000000407191313',
        ];

        $groschen = new Groschen('9789510486320');
        $this->assertNotContains($author, $groschen->getContributors());
    }

    /**
     * Test getting sales restrictions for product that has Territory restrictions
     *
     * @return void
     */
    public function test_getting_sales_rights_territory()
    {
        // Digital product that does not have territory limitations, we return just world
        $groschen = new Groschen('9789510397923');
        $territories = $groschen->getSalesRightsTerritories();
        $this->assertCount(1, $territories);
        $this->assertContains(['RegionsIncluded' => 'WORLD'], $territories);

        // Product that has territory limitations
        $groschen = new Groschen('9789520438654');
        $territories = $groschen->getSalesRightsTerritories();
        $this->assertCount(1, $territories);
        $this->assertContains(['CountriesIncluded' => 'FI'], $territories);
    }

    /**
     * Test searching for editions
     *
     * @return void
     */
    public function test_searching_for_editions()
    {
        // Basic search just with term
        /*
         $groschen = new Groschen('9789520448042');
         $searchResults = $groschen->searchEditions('tervo');
         $this->assertTrue($searchResults->contains('isbn', 9789510407714));

         // Search with filters
         $groschen = new Groschen('9789520448042');
         $searchResults = $groschen->searchEditions('', "(listingCodeId eq '113')");
         $this->assertTrue($searchResults->contains('isbn', 9789523125698));
         $this->assertGreaterThan(50, $searchResults->count());
         */

        // Search with more than 2000k results
        $groschen = new Groschen('9789520448042');
        $searchResults = $groschen->searchEditions('', "(bindingCode eq 'BCB104')");
        $this->assertTrue($searchResults->contains('isbn', 9789510407714));
        $this->assertGreaterThan(2000, $searchResults->count());
    }

    /**
     * Test faking for Johnny Kniga
     *
     * @return void
     */
    public function test_getting_johnny_kniga_as_publishing_house()
    {
        $groschen = new Groschen('9789510475027');
        $this->assertSame('Johnny Kniga', $groschen->getPublisher());
        $this->assertCount(0, $groschen->getImprints());
    }

    /**
     * Test that Finnish library code prefix is not shared if main edition is pocket book
     *
     * @return void
     */
    public function test_finnish_library_code_prefix_is_not_shared_from_main_edition()
    {
        $groschen = new Groschen('9789526637747');

        $this->assertNotContains(['SubjectSchemeIdentifier' => '73', 'SubjectSchemeName' => 'Suomalainen kirja-alan luokitus', 'SubjectCode' => 'T'], $groschen->getSubjects());
    }

    /**
     * Test getting names as subjects
     *
     * @return void
     */
    public function test_getting_names_as_subjects()
    {
        // Biography
        $biography = [
            'NameType' => '00',
            'PersonName' => 'Kari Kairamo',
            'PersonNameInverted' => 'Kairamo, Kari',
            'KeyNames' => 'Kairamo',
            'NamesBeforeKey' => 'Kari',
        ];

        $groschen = new Groschen('9789523757486');
        $this->assertContains($biography, $groschen->getNamesAsSubjects());
    }

    /**
     * Test getting names as subjects for pseudonym
     *
     * @return void
     */
    public function test_getting_names_as_subjects_for_pseudonym()
    {
        // Biography
        $biography = [
            'NameType' => '00',
            'PersonName' => 'Avicii',
            'PersonNameInverted' => 'Avicii',
            'KeyNames' => 'Avicii',
        ];

        $groschen = new Groschen('9789510452097');
        $this->assertContains($biography, $groschen->getNamesAsSubjects());

        // Biography with two names
        $biographyOne = [
            'NameType' => '00',
            'PersonName' => 'Viki',
            'PersonNameInverted' => 'Viki',
            'KeyNames' => 'Viki',
        ];

        $biographyTwo = [
            'NameType' => '00',
            'PersonName' => 'Seksikäs-Suklaa',
            'PersonNameInverted' => 'Seksikäs-Suklaa',
            'KeyNames' => 'Seksikäs-Suklaa',
        ];

        $groschen = new Groschen('9789510472972');
        $this->assertContains($biographyOne, $groschen->getNamesAsSubjects());
        $this->assertContains($biographyTwo, $groschen->getNamesAsSubjects());
    }

    /**
     * Test getting names as subjects for name with multiple parts
     *
     * @return void
     */
    public function test_getting_names_as_subjects_for_name_with_multiple_parts()
    {
        // Biography
        $biography = [
            'NameType' => '00',
            'PersonName' => 'Adolf Fredrik Munck',
            'PersonNameInverted' => 'Munck, Adolf Fredrik',
            'KeyNames' => 'Munck',
            'NamesBeforeKey' => 'Adolf Fredrik',
        ];

        $groschen = new Groschen('9789528500797');
        $this->assertContains($biography, $groschen->getNamesAsSubjects());
    }

    /**
     * Test that prizes are listed in keywords
     *
     * @return void
     */
    public function test_prizes_are_listed_in_keywords()
    {
        $groschen = new Groschen('9789510382745');
        $keywords = explode(';', $groschen->getSubjects()->where('SubjectSchemeIdentifier', '20')->pluck('SubjectHeadingText')->first());

        $this->assertContains('junat', $keywords);

        // Check that names prizes are listed first
        $this->assertSame('finlandia-palkinto', $keywords[0]);
    }

    /**
     * Test that custom keywords are included and listed first
     *
     * @return void
     */
    public function test_book_type_is_listed_in_keywords()
    {
        $groschen = new Groschen('9789510487532');
        $keywords = explode(';', $groschen->getSubjects()->where('SubjectSchemeIdentifier', '20')->pluck('SubjectHeadingText')->first());

        $this->assertContains('booktok', $keywords);
        $this->assertContains('rakkaus', $keywords);

        $this->assertSame('booktok', $keywords[0]);
    }

    /**
     * Test getting editions internal title
     *
     * @return void
     */
    public function test_getting_internal_title()
    {
        $testTitles = [
            9789524030434 => 'ZOV kirja',
            9789513126018 => 'Vauvan vaaka (nuottivihko ja leikkiohjeet) kirja',
            9789521621871 => 'your name. Another Side: Earthbound 2 kirja',
            9789510400098 => 'MUKAVAT MUUMIAMIGURUMIT kirja',
            9789523820777 => 'Vesimelonin kuivatus ja muita matemaattisia kirja',
            9789522892355 => 'Ystävä sä lapsien pokkari',
            9789520442163 => 'Yksisarvinen kirja',
            9789513169947 => 'Yllätys kasvimaalla, Muumipappa! kirja',
            9789524030458 => 'ZOV ä-kirja',
            9789513126995 => 'Kapteeni Kalsari ja ulkoavaruuden uskomattom kirja',
            9789513126001 => 'Vauvan vaaka cd',
            9789510450505 => 'Zombikuume cd',
            9789513178529 => 'PERUTTU Soiva laulukirja 5 kä-kirja',
            9789524030441 => 'ZOV e-kirja',
            9789520447434 => 'Explorer Academy 3. Kaksoiskierre e-kirja',
            9789510493151 => 'Minisijainen metsäretkellä eä-kirja',
            6430060036031 => 'Sitan kalenteri 2023 kalenteri',
            9789523753686 => 'POISTETTU MYYNNISTÄ Voihan nenä! pdf',
            9789510504147 => 'Kettulan kahvila: Pelli-pupun syntymäpäiv eä-kirja',
            6430060032446 => 'Jättipokkarilava kevät 2022, Tokm mark. materiaali',
            9789523737815 => 'Avaruus - Koululaisen kirja ja palapel moniosainen',
        ];

        foreach ($testTitles as $gtin => $expectedInternalTitle) {
            $groschen = new Groschen($gtin);
            $this->assertSame($expectedInternalTitle, $groschen->getInternalTitle());
        }
    }

    /**
     * Tests for checking if the print on demand is checked
     *
     * @return void
     */
    public function test_print_on_demand_is_checked_correctly()
    {
        $groschen = new Groschen('9789520444884');
        $this->assertFalse($groschen->isPrintOnDemandChecked());

        $groschen = new Groschen('9789524032865');
        $this->assertTrue($groschen->isPrintOnDemandChecked());
    }

    /**
     * Test that pocket book price group is fetched correctly
     *
     * @return void
     */
    public function test_pocket_book_price_group_is_fetched_correctly()
    {
        // Not a pocket book
        $groschen = new Groschen('9789520444884');
        $this->assertNull($groschen->getPocketBookPriceGroup());

        // Pocket book with price group
        $groschen = new Groschen('9789510499887');
        $this->assertSame('G', $groschen->getPocketBookPriceGroup());
    }

    /**
     * Test that contributor with two digit sort order is ordered correctly
     *
     * @return void
     */
    public function test_contributor_priority_with_two_number_is_sorted_correctly()
    {
        $groschen = new Groschen('9789520455637');

        // First author
        $firstAuthor = [
            'Identifier' => 64846,
            'SequenceNumber' => 1,
            'ContributorRole' => 'A01',
            'PersonName' => 'Lotta-Sofia Saahko',
            'PersonNameInverted' => 'Saahko, Lotta-Sofia',
            'KeyNames' => 'Saahko',
            'NamesBeforeKey' => 'Lotta-Sofia',
            'BiographicalNote' => '<p><strong>Lotta-Sofia Saahko</strong> (FM) on monipuolinen taitaja, jolla on pitkä kokemus sekä musiikkiteatterilavoilta että YouTubesta, ja joka on kirjoittanut runoja pienestä pitäen. Hän on opiskellut ja työskennellyt koko elämänsä ulkomailla, ja siksi suomalaiset ja karjalaiset juurensa ovat hänelle tärkeitä. Kotimaan tukipisteenä on aina ollut Valkeakosken pappala, ja papasta ja Lotasta on tullut toimiva tiimi.</p>',
            'WebSites' => [],
            'SelectionLists' => [
                'tammi.fi julkiset tekijät',
                'Kirja.fi julkiset tekijät',
                'Spotify-tekijäsivut',
            ],
            'HasAuthorImage' => true,
        ];

        $this->assertContains($firstAuthor, $groschen->getContributors());
    }

    /**
     * Test that only contributor biography with prio level "primary" are shown in TextType 03
     *
     * @return void
     */
    public function test_only_priority_contributor_description_is_shown_in_marketing_text()
    {
        $this->markTestSkipped('Will be implemented later after texts have been fixed.');

        $descriptionMatch = '<strong>Karoliina Niskanen</strong> (s. 1987) valmistui näyttelijäksi';

        // As reader should not appear
        $groschen = new Groschen('9789523823679');
        $this->assertStringNotContainsString($descriptionMatch, $groschen->getTextContents()->where('TextType', '03')->pluck('Text')->first());

        // As author should appear
        $groschen = new Groschen('9789524033008');
        $this->assertStringContainsString($descriptionMatch, $groschen->getTextContents()->where('TextType', '03')->pluck('Text')->first());

        // As author and reader should appear
        $groschen = new Groschen('9789523760769');
        $this->assertStringContainsString($descriptionMatch, $groschen->getTextContents()->where('TextType', '03')->pluck('Text')->first());
    }

    /**
     * Test getting contributors without prio level
     *
     * @return void
     */
    public function test_getting_contributors_without_priolevel()
    {
        $groschen = new Groschen('9789510452493');

        $contributorWithoutPriority = [
            'Id' => 47893,
            'PriorityLevel' => null,
            'Role' => 'Sales Manager',
            'FirstName' => 'SM',
            'LastName' => 'WSOY',
        ];

        $this->assertContains($contributorWithoutPriority, $groschen->getAllContributors());
    }

    /**
     * Test getting latest publication date on product without publication date
     *
     * @return void
     */
    public function test_getting_latest_publication_date_on_product_without_publication_date()
    {
        $groschen = new Groschen('9789510461259');
        $this->assertNull($groschen->getLatestPublicationDate());
    }

    /**
     * Test getting target personas
     *
     * @return void
     */
    public function test_getting_target_personas()
    {
        // Product without target groups
        $this->assertEmpty($this->groschen->getTargetPersonas());

        // Product with two target groups
        $groschen = new Groschen('9789510501474');
        $this->assertContains('Kirjallisuuden arvostajat', $groschen->getTargetPersonas());
        $this->assertContains('Tapalukijat', $groschen->getTargetPersonas());
    }

    /**
     * Test getting calculated publisher retail price
     *
     * @return void
     */
    public function test_getting_calculated_publisher_retail_price()
    {
        // Hardback
        $this->assertSame(22.9, $this->groschen->getCalculatedPublisherRetailPrice());

        // Pocket book
        $groschen = new Groschen('9789510407554');
        $this->assertSame(10.4, $groschen->getCalculatedPublisherRetailPrice());

        // E-book
        $groschen = new Groschen('9789510369654');
        $this->assertSame(7.6, $groschen->getCalculatedPublisherRetailPrice());

        // Product without any price
        $groschen = new Groschen('9789510429259');
        $this->assertSame(0.0, $groschen->getCalculatedPublisherRetailPrice());

        // CD
        $groschen = new Groschen('9789510389997');
        $this->assertSame(19.9, $groschen->getCalculatedPublisherRetailPrice());
    }

    /**
     * Test getting editions planning/disposition code
     *
     * @return void
     */
    public function test_getting_disposition_code()
    {
        // Hardback
        $this->assertSame('d', $this->groschen->getPlanningCode());

        // E-book
        $groschen = new Groschen('9789510369654');
        $this->assertSame('y', $groschen->getPlanningCode());
    }

    /**
     * Test getting ribbon
     *
     * @return void
     */
    public function test_getting_ribbon()
    {
        $groschen = new Groschen('9789513192402');
        $this->assertNotContains('B506', $groschen->getProductFormDetails());

        $groschen = new Groschen('6430060033092');
        $this->assertContains('B506', $groschen->getProductFormDetails());
    }

    /**
     * Test getting hazards
     *
     * @return void
     */
    public function test_getting_schema_hazards()
    {
        // Product with no hazards
        $groschen = new Groschen('9789528500308');
        $schemaHazards = $groschen->getSchemaHazards();

        $this->assertCount(1, $schemaHazards);
        $this->assertContains('none', $schemaHazards);

        // Product where no hazards are not defined, should return 'unknown'
        $groschen = new Groschen('9789510419922');
        $schemaHazards = $groschen->getSchemaHazards();

        $this->assertCount(1, $schemaHazards);
        $this->assertContains('unknown', $schemaHazards);
    }

    // Lisää testituotteita, joissa olisi oikeasti hazardeja (video/ääni/väläys)

    /**
     * Test getting schema access modes
     *
     * @return void
     */
    public function test_getting_schema_access_modes()
    {
        // Product with only text
        $groschen = new Groschen('9789510498248');
        $schemaAccessModes = $groschen->getSchemaAccessModes();

        $this->assertCount(1, $schemaAccessModes);
        $this->assertContains('textual', $schemaAccessModes);

        // Product with text and images
        $groschen = new Groschen('9789528500308');
        $schemaAccessModes = $groschen->getSchemaAccessModes();

        $this->assertCount(2, $schemaAccessModes);
        $this->assertContains('textual', $schemaAccessModes);
        $this->assertContains('visual', $schemaAccessModes);
    }

    /**
     * Test getting schema access mode sufficient
     *
     * @return void
     */
    public function test_getting_schema_access_mode_sufficient()
    {
        // Product with only text
        $groschen = new Groschen('9789510498248');
        $schemaAccessModeSufficients = $groschen->getSchemaAccessModeSufficients();

        $this->assertCount(1, $schemaAccessModeSufficients);
        $this->assertContains('textual', $schemaAccessModeSufficients);

        // Product with text and images
        $groschen = new Groschen('9789528500308');
        $schemaAccessModeSufficients = $groschen->getSchemaAccessModeSufficients();

        $this->assertCount(2, $schemaAccessModeSufficients);
        $this->assertContains('textual', $schemaAccessModeSufficients);
        $this->assertContains('visual', $schemaAccessModeSufficients);
    }

    /**
     * Test getting schema accessibilityFeatures
     *
     * @return void
     */
    public function test_getting_schema_accessibility_features()
    {
        $this->markTestSkipped('Will be implemented later maybe, went to use fixed mapped values instead.');

        $groschen = new Groschen('9789510498248');
        $schemaAccessibilityFeatures = $groschen->getSchemaAccessibilityFeatures();

        $this->assertContains('displayTransformability', $schemaAccessibilityFeatures);
        $this->assertNotContains('ttsMarkup', $schemaAccessibilityFeatures);
        $this->assertContains('structuralNavigation', $schemaAccessibilityFeatures);
        $this->assertContains('tableOfContents', $schemaAccessibilityFeatures);

        // Book with images
        $groschen = new Groschen('9789528500308');
        $schemaAccessibilityFeatures = $groschen->getSchemaAccessibilityFeatures();

        $this->assertContains('displayTransformability', $schemaAccessibilityFeatures);
        $this->assertNotContains('ttsMarkup', $schemaAccessibilityFeatures);
        $this->assertContains('structuralNavigation', $schemaAccessibilityFeatures);
        $this->assertContains('tableOfContents', $schemaAccessibilityFeatures);
        $this->assertContains('alternativeText', $schemaAccessibilityFeatures);
        $this->assertNotContains('describedMath', $schemaAccessibilityFeatures);

        // Single logical reading order Ei mappausta

        // Esimerkki sellaisesta jossa on audiota

    }

    /**
     * Test that we get either E200 or E201 for reflowable / fixed layout ePub3s
     *
     * @return void
     */
    public function test_getting_reflowable_or_fixed_format_in_product_form_details()
    {
        $groschen = new Groschen('9789528501008');
        $this->assertContains('E200', $groschen->getProductFormDetails());

        $groschen = new Groschen('9789510508374');
        $this->assertContains('E201', $groschen->getProductFormDetails());
    }

    /**
     * Test getting AI contributors
     *
     * @return void
     */
    public function test_ai_contributors_are_handled_correctly()
    {
        $groschen = new Groschen('9789520476434');

        // Translator
        $translator = [
            'Identifier' => 76348,
            'SequenceNumber' => 3,
            'ContributorRole' => 'B06',
            'UnnamedPersons' => '09',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        // Cover design or artwork by
        $coverDesigner = [
            'Identifier' => 76348,
            'SequenceNumber' => 4,
            'ContributorRole' => 'A36',
            'UnnamedPersons' => '09',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($translator, $groschen->getContributors());
        $this->assertContains($coverDesigner, $groschen->getContributors());
    }

    /**
     * Test that AI audiobook reader roles are handled correctly
     *
     * @return void
     */
    public function test_ai_audiobook_readers_are_handled_correctly()
    {
        $groschen = new Groschen('9789510441497');

        // AI Reader – male
        $readerMale = [
            'Identifier' => 76353,
            'SequenceNumber' => 1,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '05',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerMale, $groschen->getContributors());

        // AI Reader – female
        $readerFemale = [
            'Identifier' => 76352,
            'SequenceNumber' => 2,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '06',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerFemale, $groschen->getContributors());

        // AI Reader – unspecified
        $readerUnspecified = [
            'Identifier' => 76354,
            'SequenceNumber' => 3,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '07',
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerUnspecified, $groschen->getContributors());

        // AI Reader - voice replica
        $readerVoiceReplica = [
            'Identifier' => 76363,
            'SequenceNumber' => 4,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '08',
            'AlternativeName' => [
                'NameType' => '04',
                'PersonName' => 'Teuvo Testi',
            ],
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerVoiceReplica, $groschen->getContributors());

        // AI Reader - named male voice
        $readerMaleNamed = [
            'Identifier' => 76364,
            'SequenceNumber' => 5,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '05',
            'AlternativeName' => [
                'NameType' => '07',
                'PersonName' => 'Arto',
            ],
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerMaleNamed, $groschen->getContributors());

        // AI Reader - named female voice
        $readerFemaleNamed = [
            'Identifier' => 76365,
            'SequenceNumber' => 6,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '06',
            'AlternativeName' => [
                'NameType' => '07',
                'PersonName' => 'Armi',
            ],
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerFemaleNamed, $groschen->getContributors());

        // AI Reader - Unspecified named
        $readerUnspecifiedNamed = [
            'Identifier' => 76366,
            'SequenceNumber' => 7,
            'ContributorRole' => 'E07',
            'UnnamedPersons' => '07',
            'AlternativeName' => [
                'NameType' => '07',
                'PersonName' => 'Alex',
            ],
            'BiographicalNote' => null,
            'WebSites' => [],
            'SelectionLists' => [],
            'HasAuthorImage' => false,
        ];

        $this->assertContains($readerUnspecifiedNamed, $groschen->getContributors());
    }

    /**
     * Test that Thema is mapped to BIC correctly
     *
     * @return void
     */
    public function test_thema_is_mapped_to_bic_correctly()
    {
        $testCases = [
            9789510504055 => 'ATB',
            9789510504055 => 'DN',
            9789510504048 => 'FF',
            9789510506516 => 'JFFH1',
        ];

        foreach ($testCases as $gtin => $expectedBicCode) {
            $groschen = new Groschen($gtin);
            $this->assertContains($expectedBicCode, $groschen->getBicCodes());
        }
    }

    /**
     * Test that description for collection/series is handled correctly
     *
     * @return void
     */
    public function test_description_for_collection_or_series_is_handled_correct()
    {
        $groschen = new Groschen('9789510479209');
        $textContents = $groschen->getTextContents();

        $this->assertTrue($textContents->contains('TextType', '17'), 'TextContents does not contain TextType 17');
        $descriptionForCollection = $textContents->where('TextType', '17')->pluck('Text')->first();
        $this->assertStringContainsString('Satu Rämön Hildur-sarja on Islannin syrjäisille vuonoille sijoittuva dekkarisarja', $descriptionForCollection);
    }

    /**
     * Test getting contributor selection lists
     *
     * @return void
     */
    public function test_getting_contributor_selection_lists()
    {
        $groschen = new Groschen('9789523621237');

        // Check that contact Jo Nesbo with contact ID 55545 exists
        $this->assertTrue($groschen->getContributors()->contains('Identifier', 55545));

        // Get contact
        $contact = $groschen->getContributors()->where('Identifier', 55545)->first();

        $this->assertArrayHasKey('SelectionLists', $contact);
        $this->assertSame(3, count($contact['SelectionLists']));

        $this->assertContains('wsoy.fi julkiset tekijät', $contact['SelectionLists']);
        $this->assertContains('johnnykniga.fi julkiset tekijät', $contact['SelectionLists']);
        $this->assertContains('Kirja.fi julkiset tekijät', $contact['SelectionLists']);
    }

    /**
     * Test getting contributor author photograph exists boolean
     *
     * @return void
     */
    public function test_getting_author_photograph_exists()
    {
        $groschen = new Groschen('9789520447267');

        // Contributor that has image
        $this->assertTrue($groschen->getContributors()->contains('Identifier', 69495));
        $this->assertTrue($groschen->getContributors()->where('Identifier', 69495)->pluck('HasAuthorImage')->first());

        // Contributor that does not have image
        $this->assertTrue($groschen->getContributors()->contains('Identifier', 58194));
        $this->assertFalse($groschen->getContributors()->where('Identifier', 58194)->pluck('HasAuthorImage')->first());
    }

    /**
     * Test getting accessibility related product form features for EPUB2
     *
     * @return void
     */
    public function test_getting_accessibility_related_product_form_features_for_epub2()
    {
        $groschen = new Groschen('9789510397923');
        $this->assertContains('E200', $groschen->getProductFormDetails());

        // Expected ProductFormFeatures
        $expectedProductFormFeatures = [
            '12' => '00', // No known hazards or warnings
            '09' => '09', // Inaccessible or known limited accessibility
            '09' => '76', // EAA exception 2 – Disproportionate burden
        ];

        $productFormFeatures = $groschen->getProductFormFeatures();

        foreach ($expectedProductFormFeatures as $expectedProductFormFeatureType => $expectedProductFormFeature) {
            $this->assertContains(['ProductFormFeatureType' => $expectedProductFormFeatureType, 'ProductFormFeatureValue' => $expectedProductFormFeature], $productFormFeatures);
        }

        // Compliance certification by – name
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '90', 'ProductFormFeatureDescription' => 'Werner Söderström Ltd'], $productFormFeatures);

        // Publisher contact for further accessibility information
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '99', 'ProductFormFeatureDescription' => 'saavutettavuus@wsoy.fi'], $productFormFeatures);

        // Accessibility summary
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '00', 'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.'], $productFormFeatures);
    }

    /**
     * Test getting accessibility related product form features for Fixed layout ePub3
     *
     * @return void
     */
    public function test_getting_accessibility_related_product_form_features_for_epub3_fixed_layout()
    {
        $groschen = new Groschen('9789520472955');
        $this->assertContains('E201', $groschen->getProductFormDetails());

        // Expected ProductFormFeatures
        $expectedProductFormFeatures = [
            '12' => '00', // No known hazards or warnings
            '09' => '09', // Inaccessible or known limited accessibility
            '09' => '77', // EAA exception 3 - Fundamental alteration
        ];

        $productFormFeatures = $groschen->getProductFormFeatures();

        foreach ($expectedProductFormFeatures as $expectedProductFormFeatureType => $expectedProductFormFeature) {
            $this->assertContains(['ProductFormFeatureType' => $expectedProductFormFeatureType, 'ProductFormFeatureValue' => $expectedProductFormFeature], $productFormFeatures);
        }

        // Compliance certification by – name
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '90', 'ProductFormFeatureDescription' => 'Werner Söderström Ltd'], $productFormFeatures);

        // Publisher contact for further accessibility information
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '99', 'ProductFormFeatureDescription' => 'saavutettavuus@tammi.fi'], $productFormFeatures);

        // Accessibility summary
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '00', 'ProductFormFeatureDescription' => 'Ulkoasua ei voi mukauttaa, Ei saavutettava tai vain osittain saavutettava, Vedotaan poikkeukseen saavutettavuusvaatimuksissa, Ei vaaratekijöitä.'], $productFormFeatures);
    }

    /**
     * Test getting accessibility related product form features for reflowable ePub3
     *
     * @return void
     */
    public function test_getting_accessibility_related_product_form_features_for_epub3_reflowable()
    {
        $groschen = new Groschen('9789528500308');
        $this->assertContains('E200', $groschen->getProductFormDetails());

        // Expected ProductFormFeatures
        $expectedProductFormFeatures = [
            '12' => '00', // No known hazards or warnings
            '09' => '04', // Epub accessibility specification 1.1
            '09' => '36', // Appearance of all textual content can be modified
            '09' => '52', // All non-decorative content supports reading without sight
            '09' => '85', // WCAG level AA
            '09' => '81', // WCAG v2.1
        ];

        $productFormFeatures = $groschen->getProductFormFeatures();

        foreach ($expectedProductFormFeatures as $expectedProductFormFeatureType => $expectedProductFormFeature) {
            $this->assertContains(['ProductFormFeatureType' => $expectedProductFormFeatureType, 'ProductFormFeatureValue' => $expectedProductFormFeature], $productFormFeatures);
        }

        // Compliance certification by – name
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '90', 'ProductFormFeatureDescription' => 'Werner Söderström Ltd'], $productFormFeatures);

        // Publisher contact for further accessibility information
        $this->assertContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '99', 'ProductFormFeatureDescription' => 'saavutettavuus@docendo.fi'], $productFormFeatures);

        // Accessibility summary
        $this->assertNotContains(['ProductFormFeatureType' => '09', 'ProductFormFeatureValue' => '00', 'ProductFormFeatureDescription' => 'Ulkoasua voi mukauttaa, EPUB Accessibility 1.1, Luettavissa ruudunlukuohjelmalla tai pistenäytöllä, Tämä julkaisu noudattaa saavutettavuusstandardien yleisesti hyväksyttyä tasoa, Ei vaaratekijöitä.'], $productFormFeatures);
    }

    /**
     * Test getting product contact for ePubs
     *
     * @return void
     */
    public function test_getting_product_contact_for_epubs()
    {
        $groschen = new Groschen('9789528500308');

        // Docendo ePub
        $this->assertContains([
            'ProductContactRole' => '01',
            'ProductContactName' => 'Werner Söderström Ltd',
            'ProductContactEmail' => 'saavutettavuus@docendo.fi',
        ], $groschen->getProductContacts());
    }

    /**
     * Test that Thema codes are converted to correct BIC codes
     *
     * @return void
     */
    public function test_converting_thema_codes_to_bisac_codes()
    {
        $groschen = new Groschen('9789510504055');

        $this->assertSame('ANT000000', $groschen->getBisacCode(['WC']));
        $this->assertSame('ANT056000', $groschen->getBisacCode(['WC', 'KJSA']));
        $this->assertSame('ANT054000', $groschen->getBisacCode(['WC', '1KBC']));

        // Murtuneet mielet
        $this->assertSame('HIS027130', $groschen->getBisacCode(['NHW', '1DNF', '3MPBLB']));

        // Ronttiäiti ja Ahkera Tiikeri
        $this->assertSame('JUV019000', $groschen->getBisacCode(['YFN', 'YFQ', '5AF']));

        // 9789523768529
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FQ', 'FYT', '1DDU']));

        // 9789528500810
        $this->assertSame('HIS010300', $groschen->getBisacCode(['NHDJ', 'NHTX', '1D', '3KH', '3KL']));

        // 9789520415662
        $this->assertSame('JUV000000', $groschen->getBisacCode(['YBC', 'YFZT', '5AF']));

        // 9789520415839
        $this->assertSame('BIO000000', $groschen->getBisacCode(['DN']));

        // 9789510426333
        $this->assertSame('FIC014050', $groschen->getBisacCode(['FJMS', '1DNF', '3MPBLB-FI-A']));

        // 9789523753204
        $this->assertSame('MUS001000', $groschen->getBisacCode(['AVP']));

        // 9789513191818
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA']));

        // 9789524160223
        $this->assertSame('BIO000000', $groschen->getBisacCode(['DNBH1']));

        // 9789510437162
        // Problematic
        // $this->assertSame('', $groschen->getBisacCode(['W']));

        // 9789523520189
        $this->assertSame('SOC000000', $groschen->getBisacCode(['J']));

        // 9789513160135
        $this->assertSame('BUS000000', $groschen->getBisacCode(['K']));

        // 9789510439364
        $this->assertSame('COM000000', $groschen->getBisacCode(['XY']));

        // 9789510443293
        $this->assertSame('SEL032000', $groschen->getBisacCode(['WZG']));

        // 9789513198671
        $this->assertSame('HIS000000', $groschen->getBisacCode(['WQH']));

        // 9789523217492
        $this->assertSame('CGN006000', $groschen->getBisacCode(['AFF']));

        // 9789524926034
        $this->assertSame('HEA000000', $groschen->getBisacCode(['SZD']));

        // 9789512652693
        $this->assertSame('SCI000000', $groschen->getBisacCode(['P']));

        // 9789510398029
        $this->assertSame('BUS068000', $groschen->getBisacCode(['GTP']));

        // 9789510497982
        $this->assertSame('CGN004110', $groschen->getBisacCode(['XQX']));

        //  6430060033047
        $this->assertSame('NON000000', $groschen->getBisacCode(['WZ']));

        // 9789510398654
        $this->assertSame('SPO000000', $groschen->getBisacCode(['S']));

        // 9789513133771
        $this->assertSame('NAT011000', $groschen->getBisacCode(['RN']));

        // 9789524161459
        $this->assertSame('BIO000000', $groschen->getBisacCode(['DNBH1']));

        // 9789523821019
        $this->assertSame('TEC021000', $groschen->getBisacCode(['TDPF']));

        // 9789513191818
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA']));

        // 9789523215207
        $this->assertSame('CKB130000', $groschen->getBisacCode(['WBXD3']));

        // 9789522916464
        $this->assertSame('HIS054000', $groschen->getBisacCode(['WQ']));

        // 9789523731011
        $this->assertSame('SPO000000', $groschen->getBisacCode(['SV']));

        // 9789513198671
        $this->assertSame('REF013000', $groschen->getBisacCode(['WQP']));

        // 9789523821019
        $this->assertSame('CRA061000', $groschen->getBisacCode(['AFW']));

        // 9789513144906
        $this->assertSame('CGN000000', $groschen->getBisacCode(['X']));

        // 9789523218086
        $this->assertSame('ART022000', $groschen->getBisacCode(['AFCC']));

        // 9789523737204
        $this->assertSame('DES013000', $groschen->getBisacCode(['SZ']));

        // 9789523215375
        $this->assertSame('SPO037000', $groschen->getBisacCode(['SVS']));

        // 9789525474251
        $this->assertSame('FIC060000', $groschen->getBisacCode(['FU']));

        // 9789523215900
        $this->assertSame('SPO018000', $groschen->getBisacCode(['SMQB']));

        // 9789523730823
        $this->assertSame('HEA049000', $groschen->getBisacCode(['MFKH3']));

        // 9789520481797
        $this->assertSame('BUS041000', $groschen->getBisacCode(['KJK']));

        // 9789520481797
        $this->assertSame('BUS025000', $groschen->getBisacCode(['KJH']));

        // 9789520481797
        $this->assertSame('BUS111000', $groschen->getBisacCode(['KJD']));

        // 9789520473266
        $this->assertSame('FIC027430', $groschen->getBisacCode(['FRD']));

        // 9789523215375
        $this->assertSame('HIS027080', $groschen->getBisacCode(['JWM']));

        // 9789520473266
        $this->assertSame('FIC027100', $groschen->getBisacCode(['FRJ']));

        // 9789520473266
        $this->assertSame('FIC027000', $groschen->getBisacCode(['FXD']));

        // Tests added on 29.10.2025
        $this->assertSame('JUV000000', $groschen->getBisacCode(['YFB', 'YFC', '5AL']));
        $this->assertSame('JUV001000', $groschen->getBisacCode(['YFC', 'YFE', '5AN']));
        $this->assertSame('YAF000000', $groschen->getBisacCode(['YFB', '5AN']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FB']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA']));
        $this->assertSame('FIC052000', $groschen->getBisacCode(['FB', 'FUP']));
        $this->assertSame('FIC016000', $groschen->getBisacCode(['FB', 'FU']));

        // DNBZ GBCB 1DNF-FI-CBA - No BISAC mapping, only for DNB - BIO000000 Biography: general
        $this->assertSame('BIO000000', $groschen->getBisacCode(['DNBZ', 'GBCB', '1DNF-FI-CBA']));

        // YFHK XA 5AH - No BISAC mapping, only for YFH - JUV037000 Children’s / Teenage fiction: Fantasy
        $this->assertSame('JUV037000', $groschen->getBisacCode(['YHFK', 'XA', '5AH']));

        // Tests added on 30.10.2025
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA', 'FXS']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA', 'FT']));
        $this->assertSame('FIC014000', $groschen->getBisacCode(['FV', '1DNF', '3MPBGH', '3MPBGJ']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA', 'FBC']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FBA']));
        $this->assertSame('FIC000000', $groschen->getBisacCode(['FYC', 'FBA', 'FXD', 'FSX']));
        $this->assertSame('JUV037000', $groschen->getBisacCode(['YFH', 'YFZT', '5AK']));
        $this->assertSame('HIS037100', $groschen->getBisacCode(['NHTW', '1DTA']));

        // Tests added on 31.10.2025
        $this->assertSame('TEC025000', $groschen->getBisacCode(['JWD']));
        $this->assertSame('PHI000000', $groschen->getBisacCode(['QDX']));

    }

    /**
     * Undocumented functionTes
     *
     * @return void
     */
    public function test_getting_battery_information()
    {
        // Product without batteries
        $this->assertCount(0, $this->groschen->getProductFormFeatures()->where('ProductFormFeatureType', '19')->toArray());

        // Product with batteries
        $groschen = new Groschen('9789520483913');

        // Battery type and safety
        $this->assertCount(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '19')->where('ProductFormFeatureValue', '03')->toArray());
        $batteryType = $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '19')->where('ProductFormFeatureValue', '03')->first();
        $this->assertSame('3 batteries included (AG10 x 3 button cell batteries (1.5V x 3 = 4.5V)', $batteryType['ProductFormFeatureDescription']);

        // Battery chemistry
        $this->assertCount(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '19')->where('ProductFormFeatureValue', '26')->toArray());
    }

    /**
     * Test getting ePub usage constraints
     *
     * @return void
     */
    public function test_getting_epub_usage_constraints()
    {
        // TDM not allowed is not marked / no constraints
        $groschen = new Groschen('9789510441275');
        $this->assertEmpty($groschen->getEpubUsageConstraints());

        // Hardback - no ePub usage constraints
        $groschen = new Groschen('9789520448158');
        $this->assertEmpty($groschen->getEpubUsageConstraints());

        // Pocket book which falsely has "TDM not allowed"
        $groschen = new Groschen('9789510527405');
        $this->assertEmpty($groschen->getEpubUsageConstraints());

        // TDM not allowed
        $groschen = new Groschen('9789523523456');

        $this->assertContains([
            'EpubUsageType' => '11',
            'EpubUsageStatus' => '03',
        ], $groschen->getEpubUsageConstraints());

        $this->assertCount(1, $groschen->getEpubUsageConstraints());
    }

    /**
     * Test getting EUDR related exemptions
     *
     * @return void
     */
    public function test_getting_eudr_exemptions()
    {
        // Product with no exemptions or exceptions
        $groschen = new Groschen('9789523765634');
        $this->assertSame(0, $groschen->getProductFormFeatures()->whereIn('ProductFormFeatureType', ['53', '54', '56'])->count());

        // Product with exception "Stock present" / TARIC Y132
        $groschen = new Groschen('9789510503959');
        $this->assertSame(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '54')->count());

        // Product with exception "Beyond scope" aka product does not contain EUDR-relevant commodities - TARIC 129
        $groschen = new Groschen('9789524035125');
        $this->assertSame(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '56')->count());
    }

    /**
     * Test getting DDS numbers on reprint
     *
     * @return void
     */
    public function test_getting_eudr_dds_numbers_on_reprints()
    {
        // Product where first print has exemption "Stock present", second print has DDS number and third one does not
        $groschen = new Groschen('9789523737761');

        // There should not be any exemptions
        $this->assertSame(0, $groschen->getProductFormFeatures()->whereIn('ProductFormFeatureType', ['53', '54', '56'])->count());

        $this->assertCount(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '50')->where('ProductFormFeatureValue', '25SEZE6M153044+KF4BSTHS')->toArray());
        $this->assertCount(1, $groschen->getProductFormFeatures()->where('ProductFormFeatureType', '50')->where('ProductFormFeatureValue', '25SE7HKF153060+DT7CKEF3')->toArray());
    }

    /**
     * Test getting contributors when name is missing
     *
     * @return void
     */
    public function test_getting_contributors_when_name_is_missing()
    {
        $groschen = new Groschen('9789528702641');
        $this->assertSame(2, $groschen->getContributors()->count());
    }

    /**
     * Test getting contract start and end date
     *
     * @return DateTime|null
     */
    public function test_contract_start_and_end_date()
    {
        $this->assertEquals('2010-04-19', $this->groschen->getContractStartDate()->format('Y-m-d'));
        $this->assertNull($this->groschen->getContractEndDate());

        $groschen = new Groschen('9789523826816');

        $this->assertEquals('2022-12-14', $groschen->getContractStartDate()->format('Y-m-d'));
        $this->assertEquals('2029-12-14', $groschen->getContractEndDate()->format('Y-m-d'));
    }
}
