
# Groschen

## Basics
### Purpose
Groschen is an API wrapper for Mockingbird. Purpose is to provide a general library, that will return basic information for the product like title, stakeholders, media type and so on. Elements that can return multiple values usually return a Laravel Collection and the structure is usually copied from the Onix standard. For example the getProductIdentifiers method returns the following Collection:

    Illuminate\Support\Collection {#224
      #items: array:3 [
        0 => array:5 [
          "PriceType" => "05"
          "PriceAmount" => 16.25
          "Tax" => array:5 [
            "TaxType" => "01"
            "TaxRateCode" => "Z"
            "TaxRatePercent" => 10.0
            "TaxableAmount" => 16.25
            "TaxAmount" => 0.0
          ]
          "CurrencyCode" => "EUR"
          "Territory" => array:1 [
            "RegionsIncluded" => "WORLD"
          ]
        ]
        1 => array:5 [
          "PriceType" => "07"
          "PriceAmount" => 17.87
          "Tax" => array:5 [
            "TaxType" => "01"
            "TaxRateCode" => "S"
            "TaxRatePercent" => 10.0
            "TaxableAmount" => 16.25
            "TaxAmount" => 1.62
          ]
          "CurrencyCode" => "EUR"
          "Territory" => array:1 [
            "RegionsIncluded" => "WORLD"
          ]
        ]
        2 => array:5 [
          "PriceType" => "42"
          "PriceAmount" => 26.0
          "Tax" => array:5 [
            "TaxType" => "01"
            "TaxRateCode" => "S"
            "TaxRatePercent" => 10.0
            "TaxableAmount" => 23.64
            "TaxAmount" => 2.36
          ]
          "CurrencyCode" => "EUR"
          "Territory" => array:1 [
            "RegionsIncluded" => "WORLD"
          ]
        ]
      ]
    }


### Methods

| Method                     | Purpose                                                                                    | Schilling field                            | Example value(s)                                              | Onix codelist |
|----------------------------|--------------------------------------------------------------------------------------------|--------------------------------------------|---------------------------------------------------------------|---------------|
| getProductIdentifiers      | Product identifiers, like Schilling product number and GTIN                                | ProductNumber, EAN                         | 9789510366264, 80000003                                       |               |
| getProductComposition      | Product composition                                                                        | SubGroup                                   | 00' for normal products, '20' for trade items                 | 2             |
| getProductForm             | Products main product form                                                                 | MediaType                                  | BB, EA, BF, AC                                                | 150           |
| getProductFormDetail       | Products more detailed product form                                                        | BindingCode                                | B104, E136                                                    | 175           |
| getMeasures                | Products measurements like physical dimensions and weight                                  | Height, Width, Lentgh, NetWeight           |                                                               |               |
| getCollections             | Products collections, like series, number in series and total amount of books in the serie | BookSeries, NumberInSeries                 |                                                               |               |
| getTitleDetails            | Products official title, including subtitle and original title. Also publishers title.     | Title, SubTitle, LongSubtitle, ProductText | Mielensäpahoittaja, Christmas carol, Joululaulu (pocket book) |               |
| getContributors            | All contributors for the product                                                           | Stakeholder RoleId, KeyNo, Priority        |                                                               |               |
| getLanguages               | Products languages                                                                         | TextLanguage, TranslatedFrom               |                                                               |               |
| getExtents                 | Products extents, like number of pages, duration of audio book                             | PageCount, Unit                            |                                                               |               |
| getTextContents            | Products text contents, like marketing text. Fetched from the latest print Project         |                                            |                                                               |               |
| getPublisher               | Products publisher                                                                         | Owner                                      | Werner Söderström Osakeyhtiö, Kustannusosakeyhtiö Tammi       |               |
| getImprints                | Publishers imprint                                                                         | OriginalPublisher                          | Johnny Kniga                                                  |               |
| getPrice                   | Products prices                                                                            | PriceList->PriceGroup and Salesprice       |                                                               |               |
| getOriginalPublicationDate | Original publication date (first print)                                                    | OriginalPublishingDate                     |                                                               |               |
| getLatestPublicationDate   | Latest publication date (latest print)                                                     | PublishingDate                             |                                                               |               |
| getSubjects                | Products subjects like library class, BIC, Thema, keywords                                 | LiteratureGroup, MainGroup, SubGroup       |                                                               |               |
| getPublishers              | Publishers in Onix fashion, see getPublisher                                               | Owner                                      |                                                               |               |
| getPublishingStatus        | Products publishing status                                                                 | NotifyCode                                 | 4                                                             | 64            |
| getPublishingDates         | Product publishing dates in Onix fashion                                                   | OriginalPublishingDate, PublishingDate     |                                                               |               |
| getPrices                  | Products prices in Onix like fashion                                                       |                                            |                                                               |               |
| getSupportingResources     | Products supporting resources like cover images, audio samples etc.                        |                                            |                                                               |               |
| getRelatedProducts         | Related products like other formats of the same title                                      | InternetInformation->RelatedProducts       |                                                               |               |
| isConfidential             | Boolean whether the product is confidential                                                | NotifyCode                                 | true/false                                                    |               |
| getCostCenter              | Products cost center                                                                       | Dimensions                                 | 335                                                           |               |
| getMediaType               | Products media type                                                                        | MediaType                                  | BB, EA, BF, AC                                                | 150           |
| getBindingCode             | Products binding code                                                                      | BindingCode                                | A103                                                          |               |
| getDiscountGroup           | Products discount group                                                                    | DiscountGroup                              | 4                                                             |               |
| getStatusCode              | Product notify/status code in Schilling                                                    | NotifyCode                                 | 4                                                             |               |
| isImmaterial               | Whether the product is immaterial or not (eBooks etc.)                                     | NotifyCode                                 | true/false                                                    |               |
| isPrintOnDemand            | Whether the product is POD/Shortrun                                                        | NotifyCode                                 | true/false                                                    |               |
| getInternalProdNo          | Products internal product number                                                           | InternalProdNo                             | 533632                                                        |               |
| getCustomsNumber           | Products TARIC code                                                                        | CustomsNumber                              | 49019900                                                      |               |