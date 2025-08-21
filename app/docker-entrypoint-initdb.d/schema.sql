/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.6.22-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: Rueckstaende
-- ------------------------------------------------------
-- Server version	10.6.22-MariaDB-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `backlog_annotations`
--

DROP TABLE IF EXISTS `backlog_annotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backlog_annotations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `voraussichtlicher_liefertermin` date DEFAULT NULL,
  `serviceberater` varchar(120) DEFAULT NULL,
  `angemahnt` tinyint(1) NOT NULL DEFAULT 0,
  `kundeninfo` tinyint(4) DEFAULT NULL,
  `kommentar` text DEFAULT NULL,
  `updated_by` varchar(120) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order` (`order_id`),
  KEY `idx_liefertermin` (`voraussichtlicher_liefertermin`),
  KEY `idx_angemahnt` (`angemahnt`),
  KEY `idx_serviceberater` (`serviceberater`),
  CONSTRAINT `fk_annotations_order` FOREIGN KEY (`order_id`) REFERENCES `backlog_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1046 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `backlog_orders`
--

DROP TABLE IF EXISTS `backlog_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backlog_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_run_id` bigint(20) unsigned DEFAULT NULL,
  `typ` varchar(100) NOT NULL,
  `bestellkonzern` varchar(50) NOT NULL,
  `bestelldatum` date DEFAULT NULL,
  `rueck_ab_date` date DEFAULT NULL,
  `rueckstand_relevant` tinyint(1) NOT NULL DEFAULT 1,
  `rueck_rule_note` varchar(255) DEFAULT NULL,
  `bestellnummer` varchar(100) NOT NULL,
  `bestellart` varchar(50) DEFAULT NULL,
  `lieferant` varchar(100) DEFAULT NULL,
  `bezugs_kunden_nr` varchar(100) DEFAULT NULL,
  `bezugs_auftrags_nr` varchar(100) DEFAULT NULL,
  `teile_nr` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `teileart` varchar(50) DEFAULT NULL,
  `bestell_menge` decimal(15,3) DEFAULT NULL,
  `bestell_wert` decimal(15,2) DEFAULT NULL,
  `rueckstands_menge` decimal(15,3) DEFAULT NULL,
  `rueckstands_wert` decimal(15,2) DEFAULT NULL,
  `bestellherkunft_code` varchar(20) DEFAULT NULL,
  `bestellherkunft_text` varchar(255) DEFAULT NULL,
  `source_row` int(10) unsigned DEFAULT NULL,
  `row_hash` binary(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business` (`bestellkonzern`,`bestellnummer`,`teile_nr`),
  KEY `fk_backlog_orders_import` (`import_run_id`),
  KEY `idx_bestelldatum` (`bestelldatum`),
  KEY `idx_lieferant` (`lieferant`),
  KEY `idx_teile` (`teile_nr`),
  KEY `idx_rueckstand` (`rueckstands_menge`),
  KEY `idx_typ` (`typ`),
  CONSTRAINT `fk_backlog_orders_import` FOREIGN KEY (`import_run_id`) REFERENCES `import_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2269 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `import_runs`
--

DROP TABLE IF EXISTS `import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_filename` varchar(255) NOT NULL,
  `source_path` varchar(500) DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rows_total` int(10) unsigned DEFAULT NULL,
  `rows_ok` int(10) unsigned DEFAULT NULL,
  `file_hash` binary(32) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `source_system` varchar(50) NOT NULL DEFAULT 'main',
  `supplier` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_imported_at` (`imported_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reconciliation_links`
--

DROP TABLE IF EXISTS `reconciliation_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_item_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `match_confidence` tinyint(3) unsigned DEFAULT NULL,
  `match_rule` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_item` (`supplier_item_id`),
  KEY `fk_recon_order` (`order_id`),
  CONSTRAINT `fk_recon_order` FOREIGN KEY (`order_id`) REFERENCES `backlog_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recon_supplier` FOREIGN KEY (`supplier_item_id`) REFERENCES `supplier_import_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_import_items`
--

DROP TABLE IF EXISTS `supplier_import_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_import_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_run_id` bigint(20) unsigned DEFAULT NULL,
  `partnernummer` varchar(50) DEFAULT NULL,
  `auftragsnummer` varchar(100) NOT NULL,
  `teilenummer` varchar(120) NOT NULL,
  `kundenreferenz` varchar(120) DEFAULT NULL,
  `anlagedatum` date DEFAULT NULL,
  `auftragsart` varchar(50) DEFAULT NULL,
  `bestellte_menge` decimal(15,3) DEFAULT NULL,
  `bestaetigte_menge` decimal(15,3) DEFAULT NULL,
  `offene_menge` decimal(15,3) DEFAULT NULL,
  `vsl_lt_sap` date DEFAULT NULL,
  `vsl_lt_vz` date DEFAULT NULL,
  `info_vz` varchar(1000) DEFAULT NULL,
  `aenderungsdatum` date DEFAULT NULL,
  `teilelocator` varchar(100) DEFAULT NULL,
  `source_row` int(10) unsigned DEFAULT NULL,
  `row_hash` binary(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_business` (`partnernummer`,`auftragsnummer`,`teilenummer`),
  KEY `fk_supplier_import_run` (`import_run_id`),
  KEY `idx_auftragsnummer` (`auftragsnummer`),
  KEY `idx_teilenummer` (`teilenummer`),
  KEY `idx_offene` (`offene_menge`),
  KEY `idx_vsl_sap` (`vsl_lt_sap`),
  KEY `idx_vsl_vz` (`vsl_lt_vz`),
  KEY `idx_aenderung` (`aenderungsdatum`),
  CONSTRAINT `fk_supplier_import_run` FOREIGN KEY (`import_run_id`) REFERENCES `import_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `v_backlog_list`
--

DROP TABLE IF EXISTS `v_backlog_list`;
/*!50001 DROP VIEW IF EXISTS `v_backlog_list`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_backlog_list` AS SELECT
 1 AS `id`,
  1 AS `import_run_id`,
  1 AS `typ`,
  1 AS `bestellkonzern`,
  1 AS `bestelldatum`,
  1 AS `rueck_ab_date`,
  1 AS `rueckstand_relevant`,
  1 AS `rueck_rule_note`,
  1 AS `bestellnummer`,
  1 AS `bestellart`,
  1 AS `lieferant`,
  1 AS `bezugs_kunden_nr`,
  1 AS `bezugs_auftrags_nr`,
  1 AS `teile_nr`,
  1 AS `teileart`,
  1 AS `bestell_menge`,
  1 AS `bestell_wert`,
  1 AS `rueckstands_menge`,
  1 AS `rueckstands_wert`,
  1 AS `bestellherkunft_code`,
  1 AS `bestellherkunft_text`,
  1 AS `source_row`,
  1 AS `row_hash`,
  1 AS `created_at`,
  1 AS `updated_at` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_backlog_orders_enriched`
--

DROP TABLE IF EXISTS `v_backlog_orders_enriched`;
/*!50001 DROP VIEW IF EXISTS `v_backlog_orders_enriched`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_backlog_orders_enriched` AS SELECT
 1 AS `id`,
  1 AS `import_run_id`,
  1 AS `typ`,
  1 AS `bestellkonzern`,
  1 AS `bestelldatum`,
  1 AS `bestellnummer`,
  1 AS `bestellart`,
  1 AS `lieferant`,
  1 AS `bezugs_kunden_nr`,
  1 AS `bezugs_auftrags_nr`,
  1 AS `teile_nr`,
  1 AS `teileart`,
  1 AS `bestell_menge`,
  1 AS `bestell_wert`,
  1 AS `rueckstands_menge`,
  1 AS `rueckstands_wert`,
  1 AS `bestellherkunft_code`,
  1 AS `bestellherkunft_text`,
  1 AS `source_row`,
  1 AS `row_hash`,
  1 AS `created_at`,
  1 AS `updated_at`,
  1 AS `voraussichtlicher_liefertermin`,
  1 AS `serviceberater`,
  1 AS `angemahnt`,
  1 AS `kommentar` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_orders_with_supplier`
--

DROP TABLE IF EXISTS `v_orders_with_supplier`;
/*!50001 DROP VIEW IF EXISTS `v_orders_with_supplier`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_orders_with_supplier` AS SELECT
 1 AS `id`,
  1 AS `import_run_id`,
  1 AS `typ`,
  1 AS `bestellkonzern`,
  1 AS `bestelldatum`,
  1 AS `bestellnummer`,
  1 AS `bestellart`,
  1 AS `lieferant`,
  1 AS `bezugs_kunden_nr`,
  1 AS `bezugs_auftrags_nr`,
  1 AS `teile_nr`,
  1 AS `teileart`,
  1 AS `bestell_menge`,
  1 AS `bestell_wert`,
  1 AS `rueckstands_menge`,
  1 AS `rueckstands_wert`,
  1 AS `bestellherkunft_code`,
  1 AS `bestellherkunft_text`,
  1 AS `source_row`,
  1 AS `row_hash`,
  1 AS `created_at`,
  1 AS `updated_at`,
  1 AS `vsl_lt_sap`,
  1 AS `vsl_lt_vz`,
  1 AS `info_vz`,
  1 AS `supplier_partnernummer`,
  1 AS `supplier_aenderungsdatum` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_supplier_only_missing`
--

DROP TABLE IF EXISTS `v_supplier_only_missing`;
/*!50001 DROP VIEW IF EXISTS `v_supplier_only_missing`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_supplier_only_missing` AS SELECT
 1 AS `id`,
  1 AS `import_run_id`,
  1 AS `partnernummer`,
  1 AS `auftragsnummer`,
  1 AS `teilenummer`,
  1 AS `kundenreferenz`,
  1 AS `anlagedatum`,
  1 AS `auftragsart`,
  1 AS `bestellte_menge`,
  1 AS `bestaetigte_menge`,
  1 AS `offene_menge`,
  1 AS `vsl_lt_sap`,
  1 AS `vsl_lt_vz`,
  1 AS `info_vz`,
  1 AS `aenderungsdatum`,
  1 AS `teilelocator`,
  1 AS `source_row`,
  1 AS `row_hash`,
  1 AS `created_at`,
  1 AS `updated_at` */;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `v_backlog_list`
--

/*!50001 DROP VIEW IF EXISTS `v_backlog_list`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`processing`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_backlog_list` AS select `o`.`id` AS `id`,`o`.`import_run_id` AS `import_run_id`,`o`.`typ` AS `typ`,`o`.`bestellkonzern` AS `bestellkonzern`,`o`.`bestelldatum` AS `bestelldatum`,`o`.`rueck_ab_date` AS `rueck_ab_date`,`o`.`rueckstand_relevant` AS `rueckstand_relevant`,`o`.`rueck_rule_note` AS `rueck_rule_note`,`o`.`bestellnummer` AS `bestellnummer`,`o`.`bestellart` AS `bestellart`,`o`.`lieferant` AS `lieferant`,`o`.`bezugs_kunden_nr` AS `bezugs_kunden_nr`,`o`.`bezugs_auftrags_nr` AS `bezugs_auftrags_nr`,`o`.`teile_nr` AS `teile_nr`,`o`.`teileart` AS `teileart`,`o`.`bestell_menge` AS `bestell_menge`,`o`.`bestell_wert` AS `bestell_wert`,`o`.`rueckstands_menge` AS `rueckstands_menge`,`o`.`rueckstands_wert` AS `rueckstands_wert`,`o`.`bestellherkunft_code` AS `bestellherkunft_code`,`o`.`bestellherkunft_text` AS `bestellherkunft_text`,`o`.`source_row` AS `source_row`,`o`.`row_hash` AS `row_hash`,`o`.`created_at` AS `created_at`,`o`.`updated_at` AS `updated_at` from `backlog_orders` `o` where `o`.`rueckstand_relevant` = 1 and `o`.`rueck_ab_date` is not null and curdate() >= `o`.`rueck_ab_date` and (`o`.`rueckstands_menge` is null or `o`.`rueckstands_menge` > 0) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_backlog_orders_enriched`
--

/*!50001 DROP VIEW IF EXISTS `v_backlog_orders_enriched`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_backlog_orders_enriched` AS select `o`.`id` AS `id`,`o`.`import_run_id` AS `import_run_id`,`o`.`typ` AS `typ`,`o`.`bestellkonzern` AS `bestellkonzern`,`o`.`bestelldatum` AS `bestelldatum`,`o`.`bestellnummer` AS `bestellnummer`,`o`.`bestellart` AS `bestellart`,`o`.`lieferant` AS `lieferant`,`o`.`bezugs_kunden_nr` AS `bezugs_kunden_nr`,`o`.`bezugs_auftrags_nr` AS `bezugs_auftrags_nr`,`o`.`teile_nr` AS `teile_nr`,`o`.`teileart` AS `teileart`,`o`.`bestell_menge` AS `bestell_menge`,`o`.`bestell_wert` AS `bestell_wert`,`o`.`rueckstands_menge` AS `rueckstands_menge`,`o`.`rueckstands_wert` AS `rueckstands_wert`,`o`.`bestellherkunft_code` AS `bestellherkunft_code`,`o`.`bestellherkunft_text` AS `bestellherkunft_text`,`o`.`source_row` AS `source_row`,`o`.`row_hash` AS `row_hash`,`o`.`created_at` AS `created_at`,`o`.`updated_at` AS `updated_at`,`a`.`voraussichtlicher_liefertermin` AS `voraussichtlicher_liefertermin`,`a`.`serviceberater` AS `serviceberater`,`a`.`angemahnt` AS `angemahnt`,`a`.`kommentar` AS `kommentar` from (`backlog_orders` `o` left join `backlog_annotations` `a` on(`a`.`order_id` = `o`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_orders_with_supplier`
--

/*!50001 DROP VIEW IF EXISTS `v_orders_with_supplier`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_orders_with_supplier` AS select `o`.`id` AS `id`,`o`.`import_run_id` AS `import_run_id`,`o`.`typ` AS `typ`,`o`.`bestellkonzern` AS `bestellkonzern`,`o`.`bestelldatum` AS `bestelldatum`,`o`.`bestellnummer` AS `bestellnummer`,`o`.`bestellart` AS `bestellart`,`o`.`lieferant` AS `lieferant`,`o`.`bezugs_kunden_nr` AS `bezugs_kunden_nr`,`o`.`bezugs_auftrags_nr` AS `bezugs_auftrags_nr`,`o`.`teile_nr` AS `teile_nr`,`o`.`teileart` AS `teileart`,`o`.`bestell_menge` AS `bestell_menge`,`o`.`bestell_wert` AS `bestell_wert`,`o`.`rueckstands_menge` AS `rueckstands_menge`,`o`.`rueckstands_wert` AS `rueckstands_wert`,`o`.`bestellherkunft_code` AS `bestellherkunft_code`,`o`.`bestellherkunft_text` AS `bestellherkunft_text`,`o`.`source_row` AS `source_row`,`o`.`row_hash` AS `row_hash`,`o`.`created_at` AS `created_at`,`o`.`updated_at` AS `updated_at`,`s`.`vsl_lt_sap` AS `vsl_lt_sap`,`s`.`vsl_lt_vz` AS `vsl_lt_vz`,`s`.`info_vz` AS `info_vz`,`s`.`partnernummer` AS `supplier_partnernummer`,`s`.`aenderungsdatum` AS `supplier_aenderungsdatum` from (`backlog_orders` `o` left join `supplier_import_items` `s` on(`s`.`auftragsnummer` = `o`.`bestellnummer` and `s`.`teilenummer` = `o`.`teile_nr`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_supplier_only_missing`
--

/*!50001 DROP VIEW IF EXISTS `v_supplier_only_missing`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_supplier_only_missing` AS select `s`.`id` AS `id`,`s`.`import_run_id` AS `import_run_id`,`s`.`partnernummer` AS `partnernummer`,`s`.`auftragsnummer` AS `auftragsnummer`,`s`.`teilenummer` AS `teilenummer`,`s`.`kundenreferenz` AS `kundenreferenz`,`s`.`anlagedatum` AS `anlagedatum`,`s`.`auftragsart` AS `auftragsart`,`s`.`bestellte_menge` AS `bestellte_menge`,`s`.`bestaetigte_menge` AS `bestaetigte_menge`,`s`.`offene_menge` AS `offene_menge`,`s`.`vsl_lt_sap` AS `vsl_lt_sap`,`s`.`vsl_lt_vz` AS `vsl_lt_vz`,`s`.`info_vz` AS `info_vz`,`s`.`aenderungsdatum` AS `aenderungsdatum`,`s`.`teilelocator` AS `teilelocator`,`s`.`source_row` AS `source_row`,`s`.`row_hash` AS `row_hash`,`s`.`created_at` AS `created_at`,`s`.`updated_at` AS `updated_at` from (`supplier_import_items` `s` left join `backlog_orders` `o` on(`s`.`auftragsnummer` = `o`.`bestellnummer` and `s`.`teilenummer` = `o`.`teile_nr`)) where `o`.`id` is null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-08-21  8:40:42
