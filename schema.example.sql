-- =============================================================================
-- Projekt: Set-Artikel Generator
-- Datei: schema.example.sql
-- Zweck: Dokumentation der Datenbankstruktur (nur Schema, keine Daten)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Tabelle: set_job
-- Haupttabelle für Set-Jobs (Aufträge zur Set-Erstellung)
-- -----------------------------------------------------------------------------
CREATE TABLE `set_job` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Eindeutige ID des Set-Jobs',
  `requested_by` varchar(255) DEFAULT NULL COMMENT 'BenutzerID/Veranlasser des Set-Jobs',
  `requested_at` datetime DEFAULT current_timestamp() COMMENT 'Zeitpunkt der Auftragserstellung',
  `status` enum('Offen','In Bearbeitung','Warte auf Set-Komponenten','Komponenten hinzugefügt','Fertig','Fehler') DEFAULT 'Offen',
  `error_message` text DEFAULT NULL,
  `last_error_at` datetime DEFAULT NULL,
  `new_variant_id` int(11) DEFAULT NULL,
  `new_item_id` int(11) DEFAULT NULL,
  `set_type` varchar(255) NOT NULL COMMENT 'Art des Sets',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Projekt: Set-Artikel Generator. Tabelle für Set-Jobs';

-- -----------------------------------------------------------------------------
-- Tabelle: set_job_items
-- Enthält alle VariantIDs pro Set-Job (Komponenten des Sets)
-- -----------------------------------------------------------------------------
CREATE TABLE `set_job_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Eindeutige ID für Eintrag',
  `set_job_id` int(11) DEFAULT NULL COMMENT 'Fremdschlüssel auf set_job.id',
  `variant_id` int(11) DEFAULT NULL COMMENT 'VariantID des Einzelartikels, der zum Set gehört',
  `sort_index` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_setjob` (`set_job_id`),
  CONSTRAINT `fk_setjob` FOREIGN KEY (`set_job_id`) REFERENCES `set_job` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Projekt: Set-Artikel Generator. Enthält alle VariantIDs pro Set-Job.';

-- -----------------------------------------------------------------------------
-- Tabelle: set_barcode
-- Pool verfügbarer GS1-Barcodes für automatisch erstellte Sets
-- -----------------------------------------------------------------------------
CREATE TABLE `set_barcode` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `barcode` varchar(20) NOT NULL,
  `verwendet` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='GS1 Barcodes für automatisch erstellte Sets';

-- =============================================================================
-- Die folgenden Tabellen werden extern via Synesty/Plentymarkets synchronisiert
-- und sind NICHT Teil des Set-Generators, werden aber von ihm gelesen.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Tabelle: Items
-- Artikelstammdaten (synchronisiert aus Plentymarkets)
-- WICHTIG: ImageUrls sind KOMMA-getrennt!
-- -----------------------------------------------------------------------------
CREATE TABLE `Items` (
  `id` bigint(255) NOT NULL AUTO_INCREMENT,
  `ItemID` int(255) NOT NULL,
  `VariantID` int(255) NOT NULL,
  `MainVariantID` int(255) NOT NULL,
  `IsMainVariant` int(255) NOT NULL,
  `ItemNo` varchar(255) NOT NULL,
  `ExternalItemID` varchar(255) NOT NULL,
  `Model` varchar(255) NOT NULL,
  `MainWarehouseID` int(255) NOT NULL,
  `VariantName` varchar(255) NOT NULL,
  `BundleType` varchar(255) NOT NULL,
  `AvailabilityID` int(255) NOT NULL,
  `IsActive` varchar(255) NOT NULL,
  `PurchasePrice` decimal(8,2) NOT NULL,
  `UnitsContained` int(255) NOT NULL,
  `WidthMM` int(255) NOT NULL,
  `LengthMM` int(255) NOT NULL,
  `HeightMM` int(255) NOT NULL,
  `WeightG` int(255) NOT NULL,
  `UpdatedAt` varchar(255) NOT NULL,
  `RelatedUpdatedAt` varchar(255) NOT NULL,
  `StockLimitation` int(255) NOT NULL,
  `ItemProducerID` int(255) NOT NULL,
  `ItemUpdatedAt` varchar(255) NOT NULL,
  `ItemCreatedAt` varchar(255) NOT NULL,
  `ItemPropertyId` varchar(255) NOT NULL,
  `ItemAmazonFbaPlatform` int(255) NOT NULL,
  `ItemAmazonProductType` int(255) NOT NULL,
  `ItemMarking1ID` int(255) NOT NULL,
  `ItemMarking2ID` int(255) NOT NULL,
  `VariationBarcodes` text NOT NULL,
  `EAN` varchar(255) NOT NULL,
  `EAN2` varchar(255) NOT NULL,
  `OriginalEAN` varchar(255) NOT NULL,
  `VariationSalesPrices` text NOT NULL,
  `UVP` decimal(8,2) NOT NULL,
  `ShopPreisKH24` decimal(8,2) NOT NULL,
  `EbayPreisKH24` decimal(8,2) NOT NULL,
  `AmazonPreisKH24` decimal(8,2) NOT NULL,
  `PreisManuelleEingabe` decimal(8,2) NOT NULL,
  `RealPreisKH24` decimal(18,2) NOT NULL,
  `RealTiefstpreisKH24` decimal(18,2) NOT NULL,
  `CrowdfoxKH24` decimal(18,2) NOT NULL,
  `BruttoMindestpreisKH24` decimal(18,2) NOT NULL,
  `Check24KH24` decimal(18,2) NOT NULL,
  `OttoKH24` decimal(18,2) NOT NULL,
  `ManoManoKH24` decimal(18,2) NOT NULL,
  `IdealoMinpreisKH24` decimal(18,2) NOT NULL,
  `IdealoMaxpreisKH24` decimal(18,2) NOT NULL,
  `VariationClientIDs` text NOT NULL,
  `VariationMarketIDs` text NOT NULL,
  `ImageUrls` text NOT NULL COMMENT 'KOMMA-getrennte URLs (nicht Semikolon!)',
  `ImageUrlsMiddle` text NOT NULL,
  `ImageUrlsPreview` text NOT NULL,
  `VariationMarketNumberASINs` text NOT NULL,
  `ASIN1` varchar(255) NOT NULL,
  `ASIN2` varchar(255) NOT NULL,
  `ItemTextsName1` varchar(255) NOT NULL,
  `ItemTextsName2` varchar(255) NOT NULL,
  `ItemTextsName3` varchar(255) NOT NULL,
  `ItemTextsShortDescription` text NOT NULL,
  `Category1_Path` varchar(255) NOT NULL,
  `Category1_ID` varchar(255) NOT NULL,
  `Category1_Level` varchar(255) NOT NULL,
  `Category1_Name` varchar(255) NOT NULL,
  `Category1_PathNames` varchar(255) NOT NULL,
  `AllCategoryPaths` varchar(255) NOT NULL,
  `ItemProducerName` varchar(255) NOT NULL,
  `VariationTagIDs` varchar(256) NOT NULL,
  `VariationTagNames` text NOT NULL,
  `ItemShippingProfiles` text NOT NULL,
  `VariationPropertyIDs` text NOT NULL COMMENT 'Format: id=wert;id=wert (SEMIKOLON-getrennt)',
  `VariationPropertyIDsJSON` text NOT NULL,
  `VariationProperties` text NOT NULL,
  `VariationPropertiesJSON` text NOT NULL,
  `EEK` varchar(255) NOT NULL,
  `ItemPropertyGroups` text NOT NULL,
  `ItemPropertyIDs` text NOT NULL COMMENT 'Format: id=wert;id=wert (SEMIKOLON-getrennt)',
  `ItemProperties` text NOT NULL,
  `ItemPropertiesJSON` text NOT NULL,
  `ItemPropertiesWithGroups` text NOT NULL,
  `ItemPropertiesWithGroupsJSON` text NOT NULL,
  `EEC_spectrum` text NOT NULL,
  `EEC_labelUrl` text NOT NULL,
  `EEC_dataSheetUrl` text NOT NULL,
  `EEC_version` text NOT NULL COMMENT '0=alt;1=neues Label',
  `ItemTextsUrlPath` text NOT NULL,
  `Artikel_nicht_in_Plenty` int(5) NOT NULL DEFAULT 0 COMMENT 'Artikel wurde wohl in Plenty gelöscht',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- -----------------------------------------------------------------------------
-- Tabelle: Items_Beschreibung
-- HTML-Beschreibungen je Variante (synchronisiert aus Plentymarkets)
-- -----------------------------------------------------------------------------
CREATE TABLE `Items_Beschreibung` (
  `id` bigint(255) NOT NULL AUTO_INCREMENT,
  `ItemID` int(255) NOT NULL,
  `VariantID` int(255) NOT NULL,
  `Beschreibung` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- -----------------------------------------------------------------------------
-- Tabelle: Items_Preise
-- Preise je Variante (synchronisiert aus Plentymarkets)
-- -----------------------------------------------------------------------------
CREATE TABLE `Items_Preise` (
  `id` int(255) NOT NULL AUTO_INCREMENT,
  `ItemID` int(15) NOT NULL,
  `VariantID` int(15) NOT NULL,
  `PurchasePrice` decimal(18,2) NOT NULL,
  `UpdatedAt` varchar(255) NOT NULL,
  `VariationSalesPrices` varchar(255) NOT NULL,
  `UVP` decimal(18,2) NOT NULL,
  `ShopPreisKH24` decimal(18,2) NOT NULL,
  `EbayPreisKH24` decimal(18,2) NOT NULL,
  `AmazonPreisKH24` decimal(18,2) NOT NULL,
  `PreisManuelleEingabe` decimal(18,2) NOT NULL,
  `RealPreisKH24` decimal(18,2) NOT NULL,
  `RealTiefstpreisKH24` decimal(18,2) NOT NULL,
  `CrowdfoxKH24` decimal(18,2) NOT NULL,
  `BruttoMindestpreisKH24` decimal(18,2) NOT NULL,
  `B2B` decimal(18,2) NOT NULL COMMENT 'Für Keenberk B2B Liste. Priorisiert wenn gefüllt.',
  `MediamarktKH24` decimal(18,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
