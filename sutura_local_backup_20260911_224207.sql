-- MySQL dump 10.13  Distrib 8.4.11, for macos26.6 (arm64)
--
-- Host: localhost    Database: sutura
-- ------------------------------------------------------
-- Server version	8.4.11

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `shop_branch_id` bigint unsigned DEFAULT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `service_id` bigint unsigned DEFAULT NULL,
  `job_order_id` bigint unsigned DEFAULT NULL,
  `appointment_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'consultation',
  `intake_channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'walk_in',
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int NOT NULL DEFAULT '60',
  `assigned_staff_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `outcome` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `priority` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `garment_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_receipt_path` text COLLATE utf8mb4_unicode_ci,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `reference_images` json DEFAULT NULL,
  `reference_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `answers` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `fitting_notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `appointments_shop_id_foreign` (`shop_id`),
  KEY `appointments_shop_branch_id_foreign` (`shop_branch_id`),
  KEY `appointments_customer_id_foreign` (`customer_id`),
  KEY `appointments_service_id_foreign` (`service_id`),
  KEY `appointments_assigned_staff_id_foreign` (`assigned_staff_id`),
  KEY `appointments_job_order_id_foreign` (`job_order_id`),
  CONSTRAINT `appointments_assigned_staff_id_foreign` FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_job_order_id_foreign` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_shop_branch_id_foreign` FOREIGN KEY (`shop_branch_id`) REFERENCES `shop_branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,1,1,6,2,NULL,'consultation','walk_in','2026-09-01 09:21:33',60,NULL,'confirmed',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending','Bespoke suit fitting session.',NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(2,1,2,7,1,NULL,'consultation','walk_in','2026-09-02 09:21:33',60,NULL,'pending',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending','Design discussion for basketball jerseys.',NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(3,1,3,8,2,NULL,'consultation','walk_in','2026-08-29 09:21:33',60,NULL,'completed',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending','Initial consultation completed.',NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(4,1,1,6,1,NULL,'consultation','walk_in','2026-08-25 09:21:33',60,NULL,'cancelled',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending','Cancelled by customer.',NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(5,1,1,9,2,NULL,'consultation','online','2026-09-01 09:21:33',60,NULL,'pending',NULL,NULL,'normal',NULL,'gcash','GC-2201394857',NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(6,1,2,10,4,NULL,'fitting','online','2026-09-03 09:21:33',60,NULL,'pending',NULL,NULL,'normal',NULL,'bank_transfer','BDO-88213340',NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(7,1,3,11,6,NULL,'fitting','online','2026-09-02 09:21:33',30,NULL,'pending',NULL,NULL,'normal','alteration_repair','gcash','GC-3390215671',NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(8,1,1,7,NULL,5,'fitting','walk_in','2026-08-31 10:00:00',45,NULL,'pending',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending','Auto-generated when Job Order JO-1005 became Ready for Fitting — please confirm the actual date/time with the customer.',NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(9,4,6,17,9,NULL,'measurement','walk_in','2026-09-02 09:21:51',60,NULL,'confirmed',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL),(10,4,7,18,10,NULL,'consultation','online','2026-09-04 09:21:51',30,NULL,'pending',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL),(11,5,8,22,11,NULL,'alteration','walk_in','2026-08-31 09:21:51',30,NULL,'pending',NULL,NULL,'normal',NULL,NULL,NULL,NULL,'pending',NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `payload` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_shop_id_foreign` (`shop_id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `audit_logs_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,2,'discount_applied','App\\Models\\CatalogOrder',4,'{\"amount\": 300, \"reason\": \"Repeat customer — 4th order this year\"}',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,2,'discount_applied','App\\Models\\JobOrder',2,'{\"amount\": 500, \"reason\": \"Repeat customer — bulk jersey order\"}',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel-cache-last_seen_2','b:1;',1789137728),('laravel-cache-login5c785c036466adea360111aa28563bfd556b5fba','i:1;',1789063630),('laravel-cache-login5c785c036466adea360111aa28563bfd556b5fba:timer','i:1789063628;',1789063628);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_images`
--

DROP TABLE IF EXISTS `catalog_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `catalog_item_id` bigint unsigned NOT NULL,
  `image_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `view_angle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'front',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `catalog_images_catalog_item_id_foreign` (`catalog_item_id`),
  CONSTRAINT `catalog_images_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_images`
--

LOCK TABLES `catalog_images` WRITE;
/*!40000 ALTER TABLE `catalog_images` DISABLE KEYS */;
INSERT INTO `catalog_images` VALUES (1,1,'/catalog/Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,2,'/catalog/Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,3,'/catalog/Cycling_Jerseys_1.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,4,'/catalog/Shop Long Tail Wedding Gown.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(5,5,'/catalog/Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(6,6,'/catalog/esport tshirt blue.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(7,7,'/catalog/Women\'s Esports Jersey with Customized Design.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(8,8,'/catalog/Bulls-Basketball-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(9,9,'/catalog/Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(10,10,'/catalog/images.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(11,11,'/catalog/KobeBryant-Basketball-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(12,12,'/catalog/Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(13,13,'/catalog/Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(14,14,'/catalog/Bespoke_Suits2.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(15,15,'/catalog/Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery - Elegant Bridal Dress with Corset Back - Floor-Length Wedding Dress for Women.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(16,16,'/catalog/Traditional Ivory Color Barong Tagalog - Formal Fit.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(17,17,'/catalog/Riders_Long_Sleeves.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(18,18,'/catalog/AllStar-Basketball-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(19,19,'/catalog/mens-custom-tuxedos-its-all-about-the-fit.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(20,20,'/catalog/Volleyball Jersey_2.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(21,21,'/catalog/Cycling_Jerseys_3.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(22,22,'/catalog/Riders_Long_Sleeves_2.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(23,23,'/catalog/Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(24,24,'/catalog/Cycling_Jerseys_2.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(25,25,'/catalog/Bespoke_Suits.png','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(26,26,'/catalog/Tailor Made Suits London - The Bespoke Tailor UK.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(27,27,'/catalog/Custom_Tuxedos_men.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(28,28,'/catalog/Luxury_Bridal_Gowns_Long_Tail.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(29,29,'/catalog/Bears-Basketball-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(30,30,'/catalog/rashguard_1.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(31,31,'/catalog/Lebron James-Lakers-Basketball-Jersey.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(32,32,'/catalog/Men\'s - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(33,33,'/catalog/Esports-Jersey-women.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(34,34,'/catalog/Custom Tuxedos for Memorable Events.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(35,35,'/catalog/rashguard_3.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(36,36,'/catalog/Arsenal-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(37,37,'/catalog/Traditional Barong Tagalog Polo Shirt for Men.jpeg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(38,38,'/catalog/esport tshirt.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(39,39,'/catalog/Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(40,40,'/catalog/Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(41,41,'/catalog/9 Luxury Designer Bridesmaid Dresses for the Bridal Crew.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(42,42,'/catalog/Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(43,43,'/catalog/Lakers-Basketball-Jersey.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(44,44,'/catalog/Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(45,45,'/catalog/Barong Tagalog Cloth- Traditional and Elegant Fabrics.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(46,46,'/catalog/volleyballroundneckSET.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(47,47,'/catalog/Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(48,17,'/catalog/Riders_Long_Sleeves.jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(49,48,'/catalog/VBALL_PRE-2001_800x800.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(50,49,'/catalog/Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown.webp','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(51,50,'/catalog/Shop Long Tail Wedding Gown .jpg','front',1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(52,51,'/catalog/Tailor Made Suits London - The Bespoke Tailor UK.jpg','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(53,52,'/catalog/Custom Tuxedos for Memorable Events.jpeg','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(54,53,'/catalog/Traditional Barong Tagalog Polo Shirt for Men.jpeg','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(55,54,'/catalog/Bespoke_Suits.png','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(56,55,'/catalog/Esports-Jersey-women.jpg','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(57,56,'/catalog/VBALL_PRE-2001_800x800.webp','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(58,57,'/catalog/volleyballroundneckSET.webp','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(59,58,'/catalog/Riders_Long_Sleeves.jpg','front',1,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `catalog_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_item_reviews`
--

DROP TABLE IF EXISTS `catalog_item_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_item_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `catalog_item_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `rating` int NOT NULL COMMENT '1 to 5 stars',
  `comment` text COLLATE utf8mb4_unicode_ci,
  `reply` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalog_item_reviews_catalog_item_id_user_id_unique` (`catalog_item_id`,`user_id`),
  KEY `catalog_item_reviews_user_id_foreign` (`user_id`),
  CONSTRAINT `catalog_item_reviews_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `catalog_item_reviews_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_item_reviews`
--

LOCK TABLES `catalog_item_reviews` WRITE;
/*!40000 ALTER TABLE `catalog_item_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `catalog_item_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_item_saves`
--

DROP TABLE IF EXISTS `catalog_item_saves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_item_saves` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `catalog_item_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalog_item_saves_catalog_item_id_user_id_unique` (`catalog_item_id`,`user_id`),
  KEY `catalog_item_saves_user_id_foreign` (`user_id`),
  CONSTRAINT `catalog_item_saves_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `catalog_item_saves_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_item_saves`
--

LOCK TABLES `catalog_item_saves` WRITE;
/*!40000 ALTER TABLE `catalog_item_saves` DISABLE KEYS */;
/*!40000 ALTER TABLE `catalog_item_saves` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_items`
--

DROP TABLE IF EXISTS `catalog_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `garment_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listing_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'made_to_order',
  `price` decimal(10,2) NOT NULL,
  `estimated_days` int DEFAULT '7',
  `material` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fabric_image_url` text COLLATE utf8mb4_unicode_ci,
  `sizes` json DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `features` json DEFAULT NULL,
  `care_instructions` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `external_gallery_url` text COLLATE utf8mb4_unicode_ci,
  `views_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `size_chart_image_url` text COLLATE utf8mb4_unicode_ci,
  `size_chart_columns` json DEFAULT NULL,
  `size_chart_rows` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `catalog_items_shop_id_foreign` (`shop_id`),
  CONSTRAINT `catalog_items_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_items`
--

LOCK TABLES `catalog_items` WRITE;
/*!40000 ALTER TABLE `catalog_items` DISABLE KEYS */;
INSERT INTO `catalog_items` VALUES (1,1,'Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Andrea & Leo A1237 Off Shoulder Slit Leg Floral Tulle A Line Gown made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(2,1,'Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Long Maid Of Honour Dresses Leia Modest Sweetheart Pleated Chiffon Maid Of Honor made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(3,1,'Cycling_Jerseys_1','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Cycling_Jerseys_1 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(4,1,'Shop Long Tail Wedding Gown','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Shop Long Tail Wedding Gown made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(5,1,'Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Mga Pretty Bridesmaid Dresses, Perfect Maid of Honor Gowns - Lunss made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(6,1,'esport tshirt blue','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear esport tshirt blue designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(7,1,'Women\'s Esports Jersey with Customized Design','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Women\'s Esports Jersey with Customized Design designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(8,1,'Bulls-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Bulls-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(9,1,'Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Pink Chiffon Mother of the Bride Dresses Simple Scoop Neck Long Sleeves Pearls Tea-Length A-LINE Evening Mother Gowns made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(10,1,'images','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom images tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(11,1,'KobeBryant-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear KobeBryant-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(12,1,'Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom Greed Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(13,1,'Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Best Custom Tuxedos in NYC - Bespoke Groom Tuxedos crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(14,1,'Bespoke_Suits2','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Bespoke_Suits2 crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(15,1,'Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery - Elegant Bridal Dress with Corset Back - Floor-Length Wedding Dress for Women','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Buy Luxury White Tail Wedding Gown with Champagne-Gold Embroidery - Elegant Bridal Dress with Corset Back - Floor-Length Wedding Dress for Women made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(16,1,'Traditional Ivory Color Barong Tagalog - Formal Fit','barong','made_to_order',4500.00,7,'Pina Cocoon',NULL,NULL,NULL,'Traditional Filipino Traditional Ivory Color Barong Tagalog - Formal Fit featuring delicate hand embroidery.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(17,1,'Riders_Long_Sleeves','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom Riders_Long_Sleeves tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(18,1,'AllStar-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear AllStar-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(19,1,'mens-custom-tuxedos-its-all-about-the-fit','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium mens-custom-tuxedos-its-all-about-the-fit crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(20,1,'Volleyball Jersey_2','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Volleyball Jersey_2 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(21,1,'Cycling_Jerseys_3','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Cycling_Jerseys_3 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(22,1,'Riders_Long_Sleeves_2','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom Riders_Long_Sleeves_2 tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(23,1,'Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Vintage Dark Teal Mother Gowns for Wedding Women 2024 Lace Mother of the Groom Dress Long Sleeve ZXI made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(24,1,'Cycling_Jerseys_2','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Cycling_Jerseys_2 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(25,1,'Bespoke_Suits','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Bespoke_Suits crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(26,1,'Tailor Made Suits London - The Bespoke Tailor UK','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Tailor Made Suits London - The Bespoke Tailor UK crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(27,1,'Custom_Tuxedos_men','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Custom_Tuxedos_men crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(28,1,'Luxury_Bridal_Gowns_Long_Tail','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Luxury_Bridal_Gowns_Long_Tail made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(29,1,'Bears-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Bears-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(30,1,'rashguard_1','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear rashguard_1 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(31,1,'Lebron James-Lakers-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Lebron James-Lakers-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(32,1,'Men\'s - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia','barong','made_to_order',4500.00,7,'Pina Cocoon',NULL,NULL,NULL,'Traditional Filipino Men\'s - Traditional Barong Tagalog - Page 1 - Barong At Bestida Australia featuring delicate hand embroidery.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(33,1,'Esports-Jersey-women','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Esports-Jersey-women designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(34,1,'Custom Tuxedos for Memorable Events','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Custom Tuxedos for Memorable Events crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(35,1,'rashguard_3','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear rashguard_3 designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(36,1,'Arsenal-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Arsenal-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(37,1,'Traditional Barong Tagalog Polo Shirt for Men','barong','made_to_order',4500.00,7,'Pina Cocoon',NULL,NULL,NULL,'Traditional Filipino Traditional Barong Tagalog Polo Shirt for Men featuring delicate hand embroidery.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(38,1,'esport tshirt','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear esport tshirt designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(39,1,'Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy','suit','made_to_order',12000.00,7,'Premium Wool',NULL,NULL,NULL,'Bespoke premium Blue Tuxedo Belt Tuxedo Blue Suit Brown Belt Core Navy crafted for formal attire and weddings.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(40,1,'Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining','barong','made_to_order',4500.00,7,'Pina Cocoon',NULL,NULL,NULL,'Traditional Filipino Barong Tagalog For Sale - Traditional and Modern Filipino Attire for M - Tagged barong with lining featuring delicate hand embroidery.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(41,1,'9 Luxury Designer Bridesmaid Dresses for the Bridal Crew','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer 9 Luxury Designer Bridesmaid Dresses for the Bridal Crew made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(42,1,'Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom Red Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(43,1,'Lakers-Basketball-Jersey','uniform','made_to_order',650.00,7,'Drifit Mesh',NULL,NULL,NULL,'Custom sublimation activewear Lakers-Basketball-Jersey designed for maximum breathability.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(44,1,'Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom Light Pink Regal A-line Flower Floor-Length Satin Corset Mother of the Bride Dress - Glamlora tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(45,1,'Barong Tagalog Cloth- Traditional and Elegant Fabrics','barong','made_to_order',4500.00,7,'Pina Cocoon',NULL,NULL,NULL,'Traditional Filipino Barong Tagalog Cloth- Traditional and Elegant Fabrics featuring delicate hand embroidery.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(46,1,'volleyballroundneckSET','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom volleyballroundneckSET tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(47,1,'Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown','gown','made_to_order',4500.00,7,'Chiffon & Tulle',NULL,NULL,NULL,'Elegant designer Elegant Sequined Off White Wedding Dresses with Puff Sleeves and Long Tail from Dhgate Ball Gown Wedding Gown made to order with custom sizing.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(48,1,'VBALL_PRE-2001_800x800','other','made_to_order',1500.00,7,'Premium Fabric',NULL,NULL,NULL,'High-quality custom VBALL_PRE-2001_800x800 tailored to perfection.','[\"Premium Quality\", \"SUTURA Guaranteed\"]','Handle with care.',1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL),(49,1,'Off-Shoulder Floral Tulle A-Line Gown',NULL,'made_to_order',4500.00,10,'Chiffon & Tulle',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,'[\"Bust (in)\", \"Waist (in)\", \"Hip (in)\"]','[{\"size\": \"Small\", \"values\": [\"32\", \"25\", \"35\"]}, {\"size\": \"Medium\", \"values\": [\"34\", \"27\", \"37\"]}, {\"size\": \"Large\", \"values\": [\"36\", \"29\", \"39\"]}]'),(50,1,'Long-Train Wedding Gown',NULL,'made_to_order',4500.00,14,'Chiffon & Tulle',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,'[\"Bust (in)\", \"Waist (in)\", \"Hip (in)\"]','[{\"size\": \"Small\", \"values\": [\"32\", \"25\", \"35\"]}, {\"size\": \"Medium\", \"values\": [\"34\", \"27\", \"37\"]}, {\"size\": \"Large\", \"values\": [\"36\", \"29\", \"39\"]}]'),(51,4,'Classic Navy Business Suit','suit','made_to_order',5800.00,10,'Premium Wool',NULL,NULL,NULL,'Classic Navy Business Suit — made to order, tailored to your measurements.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(52,4,'Double-Breasted Pinstripe Suit','suit','made_to_order',6500.00,10,'Premium Wool',NULL,NULL,NULL,'Double-Breasted Pinstripe Suit — made to order, tailored to your measurements.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(53,4,'Barong Tagalog — Office Formal','barong','made_to_order',3200.00,10,'Jusi Fabric',NULL,NULL,NULL,'Barong Tagalog — Office Formal — made to order, tailored to your measurements.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(54,4,'Corporate Office Blazer','suit','made_to_order',4200.00,10,'Poly-Wool Blend',NULL,NULL,NULL,'Corporate Office Blazer — made to order, tailored to your measurements.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(55,4,'Standard Office Uniform Set','uniform','made_to_order',900.00,10,'Poly-Cotton',NULL,NULL,NULL,'Standard Office Uniform Set — made to order, tailored to your measurements.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(56,5,'Elementary Uniform Set','uniform','made_to_order',450.00,5,'Poly-Cotton',NULL,NULL,NULL,'Elementary Uniform Set — made to order.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(57,5,'High School PE Uniform','uniform','made_to_order',650.00,5,'Drifit Mesh',NULL,NULL,NULL,'High School PE Uniform — made to order.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL),(58,5,'Simple Alteration Reference — Hemline','other','made_to_order',150.00,5,'N/A',NULL,NULL,NULL,'Simple Alteration Reference — Hemline — made to order.',NULL,NULL,1,NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL);
/*!40000 ALTER TABLE `catalog_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_orders`
--

DROP TABLE IF EXISTS `catalog_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `shop_branch_id` bigint unsigned DEFAULT NULL,
  `catalog_item_id` bigint unsigned NOT NULL,
  `selected_size` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `intake_channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `fulfillment_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shipping',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_receipt_path` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `catalog_orders_shop_id_foreign` (`shop_id`),
  KEY `catalog_orders_customer_id_foreign` (`customer_id`),
  KEY `catalog_orders_catalog_item_id_foreign` (`catalog_item_id`),
  KEY `catalog_orders_shop_branch_id_foreign` (`shop_branch_id`),
  CONSTRAINT `catalog_orders_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `catalog_orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `catalog_orders_shop_branch_id_foreign` FOREIGN KEY (`shop_branch_id`) REFERENCES `shop_branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `catalog_orders_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_orders`
--

LOCK TABLES `catalog_orders` WRITE;
/*!40000 ALTER TABLE `catalog_orders` DISABLE KEYS */;
INSERT INTO `catalog_orders` VALUES (1,1,NULL,49,NULL,NULL,11,'walk_in','pickup','walkin','pending',4500.00,'pending','gcash','GC-5563321190',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,NULL,50,NULL,NULL,9,'walk_in','pickup','walkin','pending',4500.00,'pending','bank_transfer','BPI-77102256',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,1,1,1,NULL,NULL,6,'walk_in','pickup','walkin','completed',4500.00,'paid','gcash',NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,1,2,1,NULL,300.00,6,'walk_in','pickup','walkin','ready',4200.00,'paid','gcash',NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(5,1,NULL,1,NULL,NULL,6,'walk_in','pickup','walkin','pending',4500.00,'partial','gcash',NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(6,1,3,3,NULL,NULL,7,'walk_in','pickup','walkin','completed',1300.00,'paid','cash',NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(7,1,NULL,3,NULL,NULL,7,'walk_in','pickup','walkin','pending',650.00,'pending','gcash','GCASH-REF-884920194','/receipts/gcash-884920194.svg','2026-08-30 01:21:33','2026-08-30 01:21:33'),(8,5,NULL,56,NULL,NULL,23,'walk_in','pickup','walkin','ready',450.00,'paid','cash',NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `catalog_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalog_recommendations`
--

DROP TABLE IF EXISTS `catalog_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `catalog_recommendations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `catalog_item_id` bigint unsigned NOT NULL,
  `recommended_item_id` bigint unsigned NOT NULL,
  `recommendation_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'similar',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `catalog_recommendations_catalog_item_id_foreign` (`catalog_item_id`),
  KEY `catalog_recommendations_recommended_item_id_foreign` (`recommended_item_id`),
  CONSTRAINT `catalog_recommendations_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `catalog_recommendations_recommended_item_id_foreign` FOREIGN KEY (`recommended_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalog_recommendations`
--

LOCK TABLES `catalog_recommendations` WRITE;
/*!40000 ALTER TABLE `catalog_recommendations` DISABLE KEYS */;
/*!40000 ALTER TABLE `catalog_recommendations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_order_staff`
--

DROP TABLE IF EXISTS `job_order_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_order_staff` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `stage` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_order_staff_job_order_id_user_id_stage_unique` (`job_order_id`,`user_id`,`stage`),
  KEY `job_order_staff_user_id_foreign` (`user_id`),
  CONSTRAINT `job_order_staff_job_order_id_foreign` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_order_staff_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_order_staff`
--

LOCK TABLES `job_order_staff` WRITE;
/*!40000 ALTER TABLE `job_order_staff` DISABLE KEYS */;
INSERT INTO `job_order_staff` VALUES (1,1,3,'design','2026-08-24 01:21:33','2026-08-25 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,3,'pattern_making','2026-08-25 01:21:33','2026-08-26 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,1,3,'cutting','2026-08-26 01:21:33','2026-08-28 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,1,3,'sewing','2026-08-28 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(5,2,4,'design','2026-08-26 01:21:33','2026-08-27 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(6,2,4,'pattern_making','2026-08-27 01:21:33','2026-08-29 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(7,2,4,'cutting','2026-08-29 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(8,3,3,'design','2026-08-20 01:21:33','2026-08-21 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(9,3,3,'pattern_making','2026-08-21 01:21:33','2026-08-22 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(10,3,3,'cutting','2026-08-22 01:21:33','2026-08-24 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(11,3,3,'sewing','2026-08-24 01:21:33','2026-08-26 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(12,3,3,'qc_ironing','2026-08-28 01:21:33','2026-08-29 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(13,4,5,'design','2026-08-21 01:21:33','2026-08-22 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(14,4,5,'pattern_making','2026-08-22 01:21:33','2026-08-23 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(15,4,5,'cutting','2026-08-23 01:21:33','2026-08-25 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(16,4,5,'sewing','2026-08-25 01:21:33','2026-08-27 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(17,4,5,'qc_ironing','2026-08-28 01:21:33','2026-08-29 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(18,5,4,'design','2026-08-22 01:21:33','2026-08-23 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(19,5,4,'pattern_making','2026-08-23 01:21:33','2026-08-24 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(20,5,4,'cutting','2026-08-24 01:21:33','2026-08-26 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(21,5,4,'sewing','2026-08-26 01:21:33','2026-08-29 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(22,6,4,'design','2026-08-27 01:21:33','2026-08-28 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(23,6,4,'cutting','2026-08-28 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(24,7,3,'design','2026-08-18 01:21:33','2026-08-19 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(25,7,3,'pattern_making','2026-08-19 01:21:33','2026-08-20 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(26,7,3,'cutting','2026-08-20 01:21:33','2026-08-22 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(27,7,3,'sewing','2026-08-22 01:21:33','2026-08-25 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(28,8,3,'design','2026-08-21 01:21:33','2026-08-22 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(29,8,3,'pattern_making','2026-08-22 01:21:33','2026-08-23 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(30,8,3,'cutting','2026-08-23 01:21:33','2026-08-25 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(31,8,3,'sewing','2026-08-25 01:21:33','2026-08-27 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(32,8,3,'qc_ironing','2026-08-28 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(33,9,4,'design','2026-08-23 01:21:33','2026-08-24 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(34,9,4,'pattern_making','2026-08-24 01:21:33','2026-08-25 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(35,9,4,'cutting','2026-08-25 01:21:33','2026-08-26 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(36,9,4,'sewing','2026-08-26 01:21:33','2026-08-27 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(37,10,4,'design','2026-08-27 01:21:33','2026-08-28 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(38,10,4,'pattern_making','2026-08-28 01:21:33','2026-08-29 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(39,10,4,'cutting','2026-08-29 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(40,10,4,'sewing','2026-08-30 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(41,12,15,'cutting','2026-08-27 01:21:51','2026-08-29 01:21:51','2026-08-30 01:21:51','2026-08-30 01:21:51'),(42,14,15,'cutting','2026-08-27 01:21:51','2026-08-29 01:21:51','2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `job_order_staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_orders`
--

DROP TABLE IF EXISTS `job_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tracking_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intake_channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'walk_in',
  `fulfillment_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pickup',
  `shop_id` bigint unsigned NOT NULL,
  `shop_branch_id` bigint unsigned DEFAULT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `service_id` bigint unsigned NOT NULL,
  `catalog_item_id` bigint unsigned DEFAULT NULL,
  `assigned_staff_id` bigint unsigned DEFAULT NULL,
  `measurement_id` bigint unsigned DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('unpaid','partial','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `held_at` timestamp NULL DEFAULT NULL,
  `ready_for_pickup_at` timestamp NULL DEFAULT NULL,
  `is_rush` tinyint(1) NOT NULL DEFAULT '0',
  `rush_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_outsourced` tinyint(1) NOT NULL DEFAULT '0',
  `partner_shop_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outsourcing_cost` decimal(10,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `custom_order_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `completion_photo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_images` json DEFAULT NULL,
  `reference_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shop_supplied',
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `cancellation_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `garment_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adjustment_count` int unsigned NOT NULL DEFAULT '0',
  `first_adjustment_at` timestamp NULL DEFAULT NULL,
  `hold_reason` text COLLATE utf8mb4_unicode_ci,
  `progress_photos` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_orders_shop_id_order_number_unique` (`shop_id`,`order_number`),
  UNIQUE KEY `job_orders_tracking_code_unique` (`tracking_code`),
  KEY `job_orders_shop_branch_id_foreign` (`shop_branch_id`),
  KEY `job_orders_customer_id_foreign` (`customer_id`),
  KEY `job_orders_service_id_foreign` (`service_id`),
  KEY `job_orders_catalog_item_id_foreign` (`catalog_item_id`),
  KEY `job_orders_assigned_staff_id_foreign` (`assigned_staff_id`),
  KEY `job_orders_measurement_id_foreign` (`measurement_id`),
  CONSTRAINT `job_orders_assigned_staff_id_foreign` FOREIGN KEY (`assigned_staff_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_orders_catalog_item_id_foreign` FOREIGN KEY (`catalog_item_id`) REFERENCES `catalog_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_orders_measurement_id_foreign` FOREIGN KEY (`measurement_id`) REFERENCES `measurements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_orders_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  CONSTRAINT `job_orders_shop_branch_id_foreign` FOREIGN KEY (`shop_branch_id`) REFERENCES `shop_branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_orders_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_orders`
--

LOCK TABLES `job_orders` WRITE;
/*!40000 ALTER TABLE `job_orders` DISABLE KEYS */;
INSERT INTO `job_orders` VALUES (1,'JO-1001',NULL,'walk_in','pickup',1,1,6,2,1,3,NULL,15000.00,7500.00,'partial','sewing',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-09','Pina Cocoon lining custom suit',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(2,'JO-1002',NULL,'online','pickup',1,2,7,1,3,4,NULL,6500.00,2750.00,'partial','cutting',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-13','10 jerseys set for tournament','{\"team_name\": \"Davao Eagles\", \"team_roster\": [{\"name\": \"Juan Dela Cruz\", \"size\": \"L\", \"number\": \"10\", \"print_name\": \"JUAN\"}, {\"name\": \"Pedro Penduko\", \"size\": \"M\", \"number\": \"7\", \"print_name\": \"PEDRO\"}, {\"name\": \"Maria Makiling\", \"size\": \"S\", \"number\": \"23\", \"print_name\": \"MARIA\"}, {\"name\": \"Ramon Bautista\", \"size\": \"L\", \"number\": \"5\", \"print_name\": \"RAMON\"}, {\"name\": \"Carlo Reyes\", \"size\": \"M\", \"number\": \"11\", \"print_name\": \"CARLO\"}, {\"name\": \"Nico Santos\", \"size\": \"XL\", \"number\": \"3\", \"print_name\": \"NICO\"}, {\"name\": \"Ella Villanueva\", \"size\": \"S\", \"number\": \"8\", \"print_name\": \"ELLA\"}, {\"name\": \"Miguel Torres\", \"size\": \"L\", \"number\": \"14\", \"print_name\": \"MIGUEL\"}, {\"name\": \"Diego Fernandez\", \"size\": \"M\", \"number\": \"22\", \"print_name\": \"DIEGO\"}, {\"name\": \"Paolo Cruz\", \"size\": \"L\", \"number\": \"9\", \"print_name\": \"PAOLO\"}]}','2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',500.00,NULL,NULL,NULL,0,NULL,NULL,NULL),(3,'JO-1003',NULL,'walk_in','pickup',1,3,8,2,NULL,3,NULL,12000.00,0.00,'paid','ready_for_pickup',NULL,'2026-08-09 01:21:33',0,0.00,0,NULL,NULL,'2026-08-10','Bespoke corporate dress suit',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(4,'JO-1004',NULL,'online','pickup',1,1,6,1,NULL,5,NULL,3250.00,0.00,'paid','completed',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-28','5 training singlets completed','{\"team_name\": \"Sartorial Club\", \"team_roster\": [{\"name\": \"Jossua Arabejo\", \"size\": \"XL\", \"number\": \"99\", \"print_name\": \"JOSSUA\"}, {\"name\": \"Alex Wright\", \"size\": \"M\", \"number\": \"14\", \"print_name\": \"ALEX\"}, {\"name\": \"Ben Castillo\", \"size\": \"L\", \"number\": \"6\", \"print_name\": \"BEN\"}, {\"name\": \"Ken Morales\", \"size\": \"M\", \"number\": \"2\", \"print_name\": \"KEN\"}, {\"name\": \"Rico Domingo\", \"size\": \"S\", \"number\": \"17\", \"print_name\": \"RICO\"}]}','2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(5,'JO-1005',NULL,'walk_in','pickup',1,1,7,2,NULL,4,NULL,18000.00,9000.00,'partial','ready_for_fitting',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-06','Barong Tagalog — ready for first fitting',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(6,'JO-1006',NULL,'online','pickup',1,2,8,1,NULL,4,NULL,9750.00,4875.00,'partial','mass_cutting_printing',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-11','15 volleyball jerseys — bulk order, skipped Pattern Making','{\"team_name\": \"Matina Spikers\", \"team_roster\": [{\"name\": \"Anna Reyes\", \"size\": \"M\", \"number\": \"4\", \"completed\": false, \"print_name\": \"ANNA\"}, {\"name\": \"Bea Santos\", \"size\": \"S\", \"number\": \"12\", \"completed\": false, \"print_name\": \"BEA\"}, {\"name\": \"Carla Cruz\", \"size\": \"L\", \"number\": \"7\", \"completed\": false, \"print_name\": \"CARLA\"}, {\"name\": \"Diana Mendoza\", \"size\": \"M\", \"number\": \"1\", \"completed\": false, \"print_name\": \"DIANA\"}, {\"name\": \"Elena Garcia\", \"size\": \"S\", \"number\": \"9\", \"completed\": false, \"print_name\": \"ELENA\"}, {\"name\": \"Faith Ramos\", \"size\": \"XL\", \"number\": \"14\", \"completed\": false, \"print_name\": \"FAITH\"}, {\"name\": \"Grace Torres\", \"size\": \"M\", \"number\": \"3\", \"completed\": false, \"print_name\": \"GRACE\"}, {\"name\": \"Hannah Lopez\", \"size\": \"S\", \"number\": \"10\", \"completed\": false, \"print_name\": \"HANNAH\"}, {\"name\": \"Ivy Flores\", \"size\": \"L\", \"number\": \"6\", \"completed\": false, \"print_name\": \"IVY\"}, {\"name\": \"Joyce Castro\", \"size\": \"M\", \"number\": \"8\", \"completed\": false, \"print_name\": \"JOYCE\"}, {\"name\": \"Karen Diaz\", \"size\": \"L\", \"number\": \"15\", \"completed\": false, \"print_name\": \"KAREN\"}, {\"name\": \"Lea Morales\", \"size\": \"M\", \"number\": \"11\", \"completed\": false, \"print_name\": \"LEA\"}, {\"name\": \"Mia Navarro\", \"size\": \"S\", \"number\": \"2\", \"completed\": false, \"print_name\": \"MIA\"}, {\"name\": \"Nicole Salazar\", \"size\": \"XL\", \"number\": \"5\", \"completed\": false, \"print_name\": \"NICOLE\"}, {\"name\": \"Olivia Tan\", \"size\": \"M\", \"number\": \"13\", \"completed\": false, \"print_name\": \"OLIVIA\"}], \"fabric_preference\": \"Drifit Mesh\"}','2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(7,'JO-1007',NULL,'walk_in','pickup',1,1,6,2,NULL,3,NULL,14500.00,7250.00,'partial','final_adjustments',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-02','Fitting revealed a shoulder adjustment — back to Final Adjustments',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(8,'JO-1008',NULL,'walk_in','pickup',1,3,7,2,NULL,3,NULL,11000.00,5500.00,'partial','qc_ironing',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-31','Final quality check and ironing before pickup',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(9,'JO-1009',NULL,'walk_in','pickup',1,1,8,1,NULL,4,NULL,4200.00,1200.00,'partial','completed',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-27','Picked up already — owner let the customer take it and settle the rest later.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(10,'JO-1010',NULL,'walk_in','pickup',1,2,6,2,NULL,4,NULL,7800.00,3900.00,'partial','sewing',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-30','Due today — customer is picking this up this afternoon.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(11,'JO-1011',NULL,'walk_in','pickup',1,1,7,1,NULL,5,NULL,5000.00,5000.00,'unpaid','pending',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-08','Full Sublimation Team Jerseys (5 sets). Red & Gold gradient print with team logo on left chest. Fabric: Drifit Honeycomb. Double-stitched seams on collar and armholes.','{\"team_name\": \"Katipunan Ballers\", \"team_roster\": [{\"name\": \"Andres Bonifacio\", \"size\": \"L\", \"number\": \"1\", \"completed\": false, \"print_name\": \"A. BONIFACIO\"}, {\"name\": \"Emilio Jacinto\", \"size\": \"M\", \"number\": \"2\", \"completed\": false, \"print_name\": \"E. JACINTO\"}, {\"name\": \"Pio Valenzuela\", \"size\": \"XL\", \"number\": \"3\", \"completed\": false, \"print_name\": \"P. VALENZUELA\"}, {\"name\": \"Apolinario Mabini\", \"size\": \"M\", \"number\": \"4\", \"completed\": false, \"print_name\": \"A. MABINI\"}, {\"name\": \"Melchora Aquino\", \"size\": \"S\", \"number\": \"5\", \"completed\": false, \"print_name\": \"M. AQUINO\"}], \"fabric_preference\": \"Honeycomb\"}','2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(12,'DFW-1001',NULL,'walk_in','pickup',4,6,17,9,NULL,15,NULL,5800.00,2900.00,'partial','sewing',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-07','Premium wool 2-piece suit',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(13,'DFW-1002',NULL,'online','pickup',4,7,18,10,NULL,16,NULL,9000.00,9000.00,'unpaid','pending',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-14','10-set office uniform roster','{\"team_name\": \"Bajada Realty Corp\", \"team_roster\": [{\"name\": \"Employee A\", \"size\": \"M\", \"number\": \"\", \"print_name\": \"\"}, {\"name\": \"Employee B\", \"size\": \"L\", \"number\": \"\", \"print_name\": \"\"}]}','2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(14,'DFW-1003',NULL,'walk_in','pickup',4,6,19,9,NULL,15,NULL,3200.00,0.00,'paid','completed',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-27','Standard poly-wool suit — released',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(15,'FA-1001',NULL,'walk_in','pickup',5,8,22,11,NULL,21,NULL,250.00,0.00,'paid','completed',NULL,NULL,0,0.00,0,NULL,NULL,'2026-08-29','Zipper replacement on jacket',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL),(16,'FA-1002',NULL,'walk_in','pickup',5,8,23,12,NULL,21,NULL,1950.00,1950.00,'unpaid','cutting',NULL,NULL,0,0.00,0,NULL,NULL,'2026-09-05','3-set elementary uniform order',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,'shop_supplied',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL);
/*!40000 ALTER TABLE `job_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `measurements`
--

DROP TABLE IF EXISTS `measurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `measurements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shop_owner',
  `profile_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Default Profile',
  `version` int unsigned NOT NULL DEFAULT '1',
  `metrics` json NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `measurements_shop_id_foreign` (`shop_id`),
  KEY `measurements_customer_id_foreign` (`customer_id`),
  CONSTRAINT `measurements_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `measurements_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `measurements`
--

LOCK TABLES `measurements` WRITE;
/*!40000 ALTER TABLE `measurements` DISABLE KEYS */;
INSERT INTO `measurements` VALUES (1,1,6,'shop_owner','Default',1,'{\"Hip\": \"39\", \"Neck\": \"16\", \"Chest\": \"39\", \"Waist\": \"33\", \"Inseam\": \"32\", \"Sleeve\": \"25\", \"Shoulder\": \"18\"}','Initial fitting — prefers a slightly looser fit around the shoulders.','2026-07-31 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,6,'shop_owner','Default',2,'{\"Hip\": \"40\", \"Neck\": \"16\", \"Chest\": \"40\", \"Waist\": \"34\", \"Inseam\": \"32\", \"Sleeve\": \"25\", \"Shoulder\": \"18\"}','Re-measured after a follow-up fitting — chest and waist both grew half an inch.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,1,8,'shop_owner','Wedding Gown Fitting',1,'{\"Hip\": \"36\", \"Chest\": \"34\", \"Waist\": \"27\", \"Sleeve\": \"22\", \"Shoulder\": \"14\", \"Shirt Length\": \"58\"}','Second fitting scheduled after initial alterations.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,4,17,'shop_owner','Default',1,'{\"Chest\": \"41\", \"Waist\": \"35\", \"Sleeve\": \"26\", \"Shoulder\": \"19\"}','Standard corporate fit.',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `measurements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_06_13_184620_create_personal_access_tokens_table',1),(5,'2026_06_13_184633_create_roles_table',1),(6,'2026_06_13_184638_create_role_user_table',1),(7,'2026_06_13_185004_create_shops_table',1),(8,'2026_06_13_185004_create_subscription_plans_table',1),(9,'2026_06_13_185005_create_shop_branches_table',1),(10,'2026_06_13_185005_create_shop_subscriptions_table',1),(11,'2026_06_13_185411_create_apparel_specializations_table',1),(12,'2026_06_13_185411_create_services_table',1),(13,'2026_06_13_185411_create_staff_profiles_table',1),(14,'2026_06_13_185412_create_service_pricings_table',1),(15,'2026_06_13_185747_create_appointments_table',1),(16,'2026_06_13_185747_create_measurements_table',1),(17,'2026_06_13_190056_create_audit_logs_table',1),(18,'2026_06_13_195437_create_catalog_items_table',1),(19,'2026_06_13_195437_create_catalog_recommendations_table',1),(20,'2026_06_13_195438_create_job_orders_table',1),(21,'2026_06_13_195439_create_catalog_images_table',1),(22,'2026_06_13_200656_add_is_available_to_staff_profiles_table',1),(23,'2026_06_13_200938_add_profile_images_to_users_table',1),(24,'2026_06_13_203102_add_booking_fields_to_shops_and_appointments',1),(25,'2026_06_13_203839_create_notifications_table',1),(26,'2026_06_13_203935_add_coordinates_to_shops_table',1),(27,'2026_06_14_161755_create_shop_customers_table',1),(28,'2026_06_14_162704_add_interactions_to_catalog_items_table',1),(29,'2026_06_14_163046_add_profile_fields_to_shops_table',1),(30,'2026_06_14_170130_create_payments_table',1),(31,'2026_06_14_170233_create_job_order_staff_table',1),(32,'2026_06_19_000001_create_support_tickets_table',1),(33,'2026_06_19_074305_add_business_type_to_shops_table',1),(34,'2026_06_19_074305_add_type_fields_to_catalog_items_table',1),(35,'2026_06_19_083002_add_operating_hours_to_shops_table',1),(36,'2026_06_19_102151_create_catalog_orders_table',1),(37,'2026_06_20_130930_add_operating_hours_and_status_to_shop_branches_table',1),(38,'2026_06_20_140552_add_order_type_and_courier_to_job_orders_table',1),(39,'2026_06_20_141921_add_custom_fields_to_services_and_job_orders',1),(40,'2026_06_20_141921_add_custom_order_data_to_job_orders',1),(41,'2026_06_20_150000_add_rental_and_policy_fields_to_shops_table',1),(42,'2026_06_20_150500_add_extra_fields_to_apparel_specializations_table',1),(43,'2026_06_20_181507_add_last_seen_at_to_users_table',1),(44,'2026_06_20_200330_add_attachments_to_support_tickets_tables',1),(45,'2026_06_21_022800_add_category_to_apparel_specializations_table',1),(46,'2026_06_21_030000_add_duration_and_assigned_staff_to_appointments_table',1),(47,'2026_06_21_050000_add_appointment_type_and_rename_duration_in_appointments_table',1),(48,'2026_06_21_141202_update_job_orders_status_enum',1),(49,'2026_06_21_142936_add_job_order_id_to_appointments_table',1),(50,'2026_06_21_143813_add_reply_and_featured_to_shop_reviews_table',1),(51,'2026_06_21_145846_change_specialization_to_json_in_staff_profiles',1),(52,'2026_06_21_150711_add_outsourcing_fields_to_job_orders_table',1),(53,'2026_06_21_150711_add_type_to_catalog_recommendations_table',1),(54,'2026_06_21_153534_add_external_gallery_url_to_catalog_items_table',1),(55,'2026_06_21_153932_add_guide_image_to_branches_and_social_links_to_shops',1),(56,'2026_06_22_000001_fix_catalog_orders_catalog_item_foreign_key',1),(57,'2026_06_22_161808_add_payment_and_courier_fields_to_catalog_orders_table',1),(58,'2026_06_22_161808_add_payment_fields_to_appointments_table',1),(59,'2026_06_22_163810_add_rush_fields_to_job_orders_table',1),(60,'2026_06_25_035504_add_profile_fields_to_users_table',1),(61,'2026_06_26_132031_add_image_url_to_services_table',1),(62,'2026_06_29_145315_add_landmark_to_shops_and_branches',1),(63,'2026_06_30_000000_add_upgrades_to_appointments_table',1),(64,'2026_06_30_213239_create_shop_special_hours_table',1),(65,'2026_06_30_214206_add_rental_fields_to_catalog_orders_table',1),(66,'2026_07_01_000000_add_intake_channel_and_fulfillment_type_to_orders_tables',1),(67,'2026_07_02_155255_add_tags_to_services_table',1),(68,'2026_07_03_100000_add_pricing_color_fabric_to_catalog',1),(69,'2026_07_03_110000_add_source_to_measurements',1),(70,'2026_07_03_120000_add_intake_channel_to_appointments',1),(71,'2026_07_05_000001_add_suki_tag_to_users_table',1),(72,'2026_07_06_070254_add_is_branch_manager_to_staff_profiles_table',1),(73,'2026_07_06_135552_add_service_type_and_min_order_qty_to_services_table',1),(74,'2026_07_06_135552_drop_apparel_specialization_from_service_pricing_and_apparel_specializations_table',1),(75,'2026_07_06_142534_add_max_appointments_per_day_to_shops_table',1),(76,'2026_07_06_143501_add_rental_lifecycle_fields_to_catalog_orders_table',1),(77,'2026_07_06_184106_add_is_active_to_catalog_items_table',1),(78,'2026_07_06_185148_add_completion_photo_url_to_job_orders_table',1),(79,'2026_07_07_070530_create_service_packages_tables',1),(80,'2026_07_07_083230_add_selected_size_to_catalog_orders_table',1),(81,'2026_07_07_091017_add_password_set_at_to_users_table',1),(82,'2026_07_07_124223_add_sale_window_to_catalog_items_table',1),(83,'2026_07_07_125227_add_sale_pricing_to_services_table',1),(84,'2026_07_07_130315_create_coupons_table',1),(85,'2026_07_07_130316_add_coupon_fields_to_catalog_orders_and_job_orders_table',1),(86,'2026_07_07_133031_add_bulk_custom_order_fields_to_appointments_table',1),(87,'2026_07_07_140738_add_size_chart_to_services_table',1),(88,'2026_07_08_064949_add_rejection_reason_to_job_orders_table',1),(89,'2026_07_08_074254_add_multi_select_category_and_type_to_services_table',1),(90,'2026_07_08_123225_create_shop_posts_table',1),(91,'2026_07_08_145111_change_image_url_to_image_urls_on_shop_posts_table',1),(92,'2026_07_08_145111_drop_gallery_images_from_shops_table',1),(93,'2026_07_08_185540_add_receipt_path_to_payments_table',1),(94,'2026_07_08_203304_add_reference_to_payments_table',1),(95,'2026_07_08_211929_add_outsourcing_cost_to_job_orders_table',1),(96,'2026_07_08_213811_add_additional_roles_to_staff_profiles_table',1),(97,'2026_07_09_134030_add_reference_images_to_job_orders_table',1),(98,'2026_07_09_140449_add_material_source_to_job_orders_table',1),(99,'2026_07_09_171131_convert_enum_columns_to_strings_for_role_and_status',1),(100,'2026_07_09_184135_make_estimated_days_nullable_on_services_table',1),(101,'2026_07_09_214348_replace_fit_guide_with_size_chart_on_catalog_items_table',1),(102,'2026_07_09_215514_add_versioning_to_measurements_table',1),(103,'2026_07_13_120000_drop_coupons_and_coupon_id_columns',1),(104,'2026_07_13_121000_drop_sale_rental_fields_add_estimated_days_to_catalog_items',1),(105,'2026_07_13_140634_rename_job_pipeline_statuses_and_stages',1),(106,'2026_07_13_145324_add_announcement_image_url_to_shop_special_hours_table',1),(107,'2026_07_23_225530_widen_image_and_path_url_columns_to_text',1),(108,'2026_07_24_090000_add_rejection_fields_to_payments_table',1),(109,'2026_07_24_090100_add_cancellation_reason_to_job_orders_table',1),(110,'2026_07_25_100000_add_garment_category_to_job_orders_table',1),(111,'2026_07_25_110000_add_specializations_to_shops_table',1),(112,'2026_07_25_120000_add_fitting_notes_to_appointments_table',1),(113,'2026_07_25_130000_add_adjustment_tracking_to_job_orders_table',1),(114,'2026_07_25_140000_add_hold_reason_to_job_orders_table',1),(115,'2026_07_25_150000_add_progress_photos_to_job_orders_table',1),(116,'2026_07_25_160000_add_is_featured_to_shops_table',1),(117,'2026_07_25_170000_add_notes_to_shop_customers_table',1),(118,'2026_07_26_030800_add_is_hidden_to_shops_table',1),(119,'2026_07_26_040000_add_banner_path_to_shops_table',1),(120,'2026_08_06_140230_create_password_reset_tokens_table',1),(121,'2026_08_06_161530_add_gallery_images_to_shops_table',1),(122,'2026_08_09_184817_fix_job_orders_order_number_unique_scope_to_shop',1),(123,'2026_08_10_112340_add_slug_to_shop_branches_table',1),(124,'2026_08_10_162615_remove_rental_and_courier_fields_from_shops_table',1),(125,'2026_08_10_162615_remove_rental_fields_from_catalog_orders_table',1),(126,'2026_08_10_183434_remove_dead_courier_fields_from_catalog_orders_table',1),(127,'2026_08_10_183434_remove_dead_courier_fields_from_job_orders_table',1),(128,'2026_08_11_023200_add_reply_to_catalog_item_reviews_table',1),(129,'2026_08_11_023853_add_reminder_sent_at_to_appointments_table',1),(130,'2026_08_11_024426_add_branch_scope_to_shop_special_hours_table',1),(131,'2026_08_11_031837_add_shop_branch_id_to_catalog_orders_table',1),(132,'2026_08_11_044928_add_expiry_reminder_sent_at_to_shop_subscriptions_table',1),(133,'2026_08_11_053916_add_tracking_code_to_job_orders_table',1),(134,'2026_08_11_062228_add_ready_for_pickup_at_to_job_orders_table',1),(135,'2026_08_11_063029_add_payment_collection_details_to_shops_table',1),(136,'2026_08_11_173708_add_held_at_to_job_orders_table',1),(137,'2026_08_13_154615_add_qr_code_paths_to_shops_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES ('01e52366-8146-4a8d-8f54-e1aea65c6f96','App\\Notifications\\StaffAssignedNotification','App\\Models\\User',3,'{\"type\":\"staff_assigned\",\"title\":\"Assigned to Qc Ironing\",\"message\":\"You\'ve been assigned to the Qc Ironing stage on job order JO-1008 for Andres Bonifacio.\",\"action_url\":\"\\/dashboard\\/jobs\\/8\",\"job_order_id\":8,\"order_number\":\"JO-1008\",\"stage\":\"qc_ironing\"}',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),('0a234f4d-9447-4e03-91f7-4e0c55653aaf','App\\Notifications\\NewJobOrderNotification','App\\Models\\User',2,'{\"type\":\"new_job_order\",\"title\":\"New Job Order Created\",\"message\":\"Job order JO-1011 was created for Andres Bonifacio.\",\"action_url\":\"\\/dashboard\\/jobs\\/11\",\"job_order_id\":11,\"order_number\":\"JO-1011\",\"customer_name\":\"Andres Bonifacio\"}','2026-08-20 03:21:33','2026-08-20 01:21:33','2026-08-20 01:21:33'),('1e123617-843e-461a-ac9d-ee8355bb62a8','App\\Notifications\\PaymentReceivedNotification','App\\Models\\User',2,'{\"type\":\"payment_received\",\"title\":\"Payment Received\",\"message\":\"\\u20b17,500.00 payment received for order JO-1001.\",\"action_url\":\"\\/dashboard\\/jobs\\/1\",\"job_order_id\":1,\"order_number\":\"JO-1001\",\"amount\":7500,\"customer_name\":\"Jose Rizal\"}','2026-08-21 03:21:33','2026-08-21 01:21:33','2026-08-21 01:21:33'),('2519d3ad-e026-4ea2-a110-a749c54b9970','App\\Notifications\\NewJobOrderNotification','App\\Models\\User',2,'{\"type\":\"new_job_order\",\"title\":\"New Job Order Created\",\"message\":\"Job order JO-1010 was created for Jose Rizal.\",\"action_url\":\"\\/dashboard\\/jobs\\/10\",\"job_order_id\":10,\"order_number\":\"JO-1010\",\"customer_name\":\"Jose Rizal\"}','2026-08-22 03:21:33','2026-08-22 01:21:33','2026-08-22 01:21:33'),('2abc802e-bdc1-43c8-9dab-6e2b75f02bc7','App\\Notifications\\NewJobOrderNotification','App\\Models\\User',2,'{\"type\":\"new_job_order\",\"title\":\"New Job Order Created\",\"message\":\"Job order JO-1009 was created for Maria Clara.\",\"action_url\":\"\\/dashboard\\/jobs\\/9\",\"job_order_id\":9,\"order_number\":\"JO-1009\",\"customer_name\":\"Maria Clara\"}','2026-08-23 03:21:33','2026-08-23 01:21:33','2026-08-23 01:21:33'),('2f4937d5-652a-4f8b-a7e9-bf1406a337ef','App\\Notifications\\StaffAssignedNotification','App\\Models\\User',3,'{\"type\":\"staff_assigned\",\"title\":\"Assigned to Sewing\",\"message\":\"You\'ve been assigned to the Sewing stage on job order JO-1001 for Jose Rizal.\",\"action_url\":\"\\/dashboard\\/jobs\\/1\",\"job_order_id\":1,\"order_number\":\"JO-1001\",\"stage\":\"sewing\"}',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),('3ae09d1a-49d0-475d-8a20-9b35cf4ad308','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',2,'{\"type\":\"appointment_booked\",\"title\":\"New Appointment Booked\",\"message\":\"Jose Rizal booked an appointment for Sep 01, 2026 09:21 AM.\",\"action_url\":\"\\/dashboard\\/appointments\",\"appointment_id\":1,\"customer_name\":\"Jose Rizal\",\"scheduled_at\":\"2026-09-01T09:21:33.000000Z\"}','2026-08-24 03:21:33','2026-08-24 01:21:33','2026-08-24 01:21:33'),('52b9f17d-fb9b-4b69-a826-7c2578958a6f','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',2,'{\"type\":\"appointment_booked\",\"title\":\"New Appointment Booked\",\"message\":\"Maria Clara booked an appointment for Aug 29, 2026 09:21 AM.\",\"action_url\":\"\\/dashboard\\/appointments\",\"appointment_id\":3,\"customer_name\":\"Maria Clara\",\"scheduled_at\":\"2026-08-29T09:21:33.000000Z\"}',NULL,'2026-08-25 01:21:33','2026-08-25 01:21:33'),('6b68ac83-7f31-4090-8193-96e2fd33a25b','App\\Notifications\\StaffAssignedNotification','App\\Models\\User',4,'{\"type\":\"staff_assigned\",\"title\":\"Assigned to Sewing\",\"message\":\"You\'ve been assigned to the Sewing stage on job order JO-1010 for Jose Rizal.\",\"action_url\":\"\\/dashboard\\/jobs\\/10\",\"job_order_id\":10,\"order_number\":\"JO-1010\",\"stage\":\"sewing\"}',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),('aa06023a-4942-4096-a1ac-ea0b6c950262','App\\Notifications\\PaymentReceivedNotification','App\\Models\\User',2,'{\"type\":\"payment_received\",\"title\":\"Payment Received\",\"message\":\"\\u20b112,000.00 payment received for order JO-1003.\",\"action_url\":\"\\/dashboard\\/jobs\\/3\",\"job_order_id\":3,\"order_number\":\"JO-1003\",\"amount\":12000,\"customer_name\":\"Maria Clara\"}',NULL,'2026-08-26 01:21:33','2026-08-26 01:21:33'),('b9cb2a9c-02be-43d2-a18d-4ffc1b68d56b','App\\Notifications\\PaymentReceivedNotification','App\\Models\\User',2,'{\"type\":\"payment_received\",\"title\":\"Payment Received\",\"message\":\"\\u20b13,250.00 payment received for order JO-1004.\",\"action_url\":\"\\/dashboard\\/jobs\\/4\",\"job_order_id\":4,\"order_number\":\"JO-1004\",\"amount\":3250,\"customer_name\":\"Jose Rizal\"}',NULL,'2026-08-27 01:21:33','2026-08-27 01:21:33'),('c2e92767-652d-4e6f-bbfb-0059fcff8024','App\\Notifications\\PaymentReceivedNotification','App\\Models\\User',2,'{\"type\":\"payment_received\",\"title\":\"Payment Received\",\"message\":\"\\u20b13,250.00 payment received for order JO-1002.\",\"action_url\":\"\\/dashboard\\/jobs\\/2\",\"job_order_id\":2,\"order_number\":\"JO-1002\",\"amount\":3250,\"customer_name\":\"Andres Bonifacio\"}',NULL,'2026-08-28 01:21:33','2026-08-28 01:21:33'),('de8552a6-1895-4cee-ac84-27ad91042275','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',2,'{\"type\":\"appointment_booked\",\"title\":\"New Appointment Booked\",\"message\":\"Andres Bonifacio booked an appointment for Sep 02, 2026 09:21 AM.\",\"action_url\":\"\\/dashboard\\/appointments\",\"appointment_id\":2,\"customer_name\":\"Andres Bonifacio\",\"scheduled_at\":\"2026-09-02T09:21:33.000000Z\"}',NULL,'2026-08-29 01:21:33','2026-08-29 01:21:33');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_order_id` bigint unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `receipt_path` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_reason` text COLLATE utf8mb4_unicode_ci,
  `rejected_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payments_job_order_id_foreign` (`job_order_id`),
  KEY `payments_recorded_by_foreign` (`recorded_by`),
  KEY `payments_rejected_by_foreign` (`rejected_by`),
  CONSTRAINT `payments_job_order_id_foreign` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,7500.00,'bank_transfer',NULL,2,'Downpayment for custom suit.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL),(2,3,12000.00,'gcash',NULL,2,'Full payment.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL),(3,4,3250.00,'cash',NULL,2,'Settled in cash.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL),(4,2,3250.00,'gcash',NULL,2,'Partial deposit via GCash.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL),(5,12,2900.00,'gcash',NULL,14,'Downpayment for suit.',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL),(6,14,3200.00,'cash',NULL,14,'Paid in full on pickup.',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL),(7,15,250.00,'cash',NULL,20,'Paid on pickup.',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (1,'App\\Models\\User',2,'auth_token','7fdaafb4889b5bfda9b6473bf0f8195dc53622c94d53b821331c159ed641a178','[\"*\"]','2026-09-11 06:42:04',NULL,'2026-09-10 10:06:13','2026-09-11 06:42:04');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_user`
--

DROP TABLE IF EXISTS `role_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_user` (
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `role_user_role_id_foreign` (`role_id`),
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_user`
--

LOCK TABLES `role_user` WRITE;
/*!40000 ALTER TABLE `role_user` DISABLE KEYS */;
INSERT INTO `role_user` VALUES (1,1),(2,2),(12,2),(13,2),(14,2),(20,2),(3,4),(4,4),(5,4),(15,4),(16,4),(21,4),(6,5),(7,5),(8,5),(9,5),(10,5),(11,5),(17,5),(18,5),(19,5),(22,5),(23,5);
/*!40000 ALTER TABLE `role_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Platform superuser','2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,'shop_owner','Tailoring shop owner','2026-08-30 01:21:31','2026-08-30 01:21:31'),(3,'branch_manager','Physical store manager','2026-08-30 01:21:31','2026-08-30 01:21:31'),(4,'staff','Tailoring shop staff member','2026-08-30 01:21:31','2026-08-30 01:21:31'),(5,'customer','Customer','2026-08-30 01:21:31','2026-08-30 01:21:31');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_package_items`
--

DROP TABLE IF EXISTS `service_package_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_package_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_package_id` bigint unsigned NOT NULL,
  `service_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_package_items_service_package_id_service_id_unique` (`service_package_id`,`service_id`),
  KEY `service_package_items_service_id_foreign` (`service_id`),
  CONSTRAINT `service_package_items_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_package_items_service_package_id_foreign` FOREIGN KEY (`service_package_id`) REFERENCES `service_packages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_package_items`
--

LOCK TABLES `service_package_items` WRITE;
/*!40000 ALTER TABLE `service_package_items` DISABLE KEYS */;
INSERT INTO `service_package_items` VALUES (1,1,2,NULL,NULL),(2,1,3,NULL,NULL);
/*!40000 ALTER TABLE `service_package_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_packages`
--

DROP TABLE IF EXISTS `service_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `bundle_price` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_packages_shop_id_foreign` (`shop_id`),
  CONSTRAINT `service_packages_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_packages`
--

LOCK TABLES `service_packages` WRITE;
/*!40000 ALTER TABLE `service_packages` DISABLE KEYS */;
INSERT INTO `service_packages` VALUES (1,1,'Groom & Entourage Package','Bespoke suit for the groom plus Barong Tagalog tailoring for the entourage, bundled at a discount.',12000.00,1,'2026-08-30 01:21:33','2026-08-30 01:21:33');
/*!40000 ALTER TABLE `service_packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_pricing`
--

DROP TABLE IF EXISTS `service_pricing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_pricing` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint unsigned NOT NULL,
  `label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_pricing_service_id_foreign` (`service_id`),
  CONSTRAINT `service_pricing_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_pricing`
--

LOCK TABLES `service_pricing` WRITE;
/*!40000 ALTER TABLE `service_pricing` DISABLE KEYS */;
INSERT INTO `service_pricing` VALUES (1,1,'Mesh Fabric Jersey Set',1000.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,1,'Honeycomb Fabric Jersey Set',1200.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(3,1,'Full Sublimation Premium Set',1500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(4,2,'Classic Wool Suit',3500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(5,2,'Premium Wool Suit with Silk Lining',5500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(6,2,'Tuxedo, Full Canvas Construction',8000.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(7,3,'Plain Cotton Barong',1500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(8,3,'Jusi Fabric Barong',2800.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(9,3,'Premium Piña Barong',4500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(10,4,'Simple A-Line Gown',8000.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(11,4,'Ball Gown with Beadwork',15000.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(12,4,'Mermaid Gown with Train',25000.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(13,5,'Elementary Uniform Set',450.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(14,5,'High School Uniform Set',650.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(15,5,'Complete Set with PE Uniform',950.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(16,6,'Hem Pants / Skirt',150.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(17,6,'Take In / Let Out Waist',200.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(18,6,'Zipper Replacement',250.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(19,6,'Sleeve Shortening',200.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(20,7,'Basic Jersey Set (Top + Shorts)',850.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(21,7,'Full Sublimation Premium Set',1200.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(22,8,'Small Logo Embroidery (up to 2x2 in)',150.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(23,8,'Team Name / Text Embroidery',200.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(24,8,'Large Design / Patch Embroidery',350.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(25,8,'Custom Logo Digitizing (one-time setup)',500.00,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(26,9,'Poly-Wool Blend',3200.00,'2026-08-30 01:21:50','2026-08-30 01:21:50'),(27,9,'Premium Wool',5800.00,'2026-08-30 01:21:50','2026-08-30 01:21:50'),(28,10,'Standard Set',900.00,'2026-08-30 01:21:50','2026-08-30 01:21:50'),(29,11,'Hem Pants',150.00,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(30,11,'Zipper Replacement',250.00,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(31,12,'Elementary Set',450.00,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(32,12,'High School Set',650.00,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `service_pricing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `tags` json DEFAULT NULL,
  `custom_fields` json DEFAULT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categories` json DEFAULT NULL,
  `service_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_types` json DEFAULT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `sale_starts_at` timestamp NULL DEFAULT NULL,
  `sale_ends_at` timestamp NULL DEFAULT NULL,
  `estimated_days` int DEFAULT '7',
  `min_order_qty` int unsigned NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `image_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `size_chart_image_url` text COLLATE utf8mb4_unicode_ci,
  `size_chart_columns` json DEFAULT NULL,
  `size_chart_rows` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `services_shop_id_foreign` (`shop_id`),
  CONSTRAINT `services_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,1,'Custom Sublimation Team Jerseys','Full sublimation jerseys using high-quality drifit fabrics. Perfect for sports teams, tournaments, and athletic wear. Price varies based on quantity, fabric (Mesh, Honeycomb), and design complexity.','[\"Mesh Fabric Jersey Set\", \"Honeycomb Fabric Jersey Set\", \"Full Sublimation Premium Set\"]','[{\"name\": \"fabric_preference\", \"type\": \"dropdown\", \"label\": \"Fabric Preference\", \"options\": [\"Drifit\", \"Cotton\", \"Honeycomb\"], \"required\": true}, {\"name\": \"team_name\", \"type\": \"short_text\", \"label\": \"Team/Organization Name\", \"required\": true}, {\"name\": \"roster\", \"type\": \"short_text\", \"label\": \"Player Name & Number Roster\", \"required\": true}, {\"name\": \"size_breakdown\", \"type\": \"short_text\", \"label\": \"Size Breakdown (e.g. S-5, M-10, L-2)\", \"required\": true}]','Sublimation & Digital Printing','[\"Custom Jersey Printing\", \"Corporate & Team Uniforms\"]',NULL,'[\"bulk_sublimation\"]',1000.00,NULL,NULL,NULL,14,10,1,'https://images.unsplash.com/photo-1587280501635-68a0e82cd5ff?q=80&w=800&auto=format&fit=crop','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(2,1,'Bespoke Suit Tailoring','Premium bespoke custom suits tailored to your exact measurements with premium fabrics, lining, and custom details. Price varies based on wool quality and lining.','[\"Classic Wool Suit\", \"Premium Wool Suit with Silk Lining\", \"Tuxedo, Full Canvas Construction\"]','[]','Custom Tailoring & Bespoke','[\"Suit & Tuxedo Tailoring\", \"Formal & Cultural Wear\"]',NULL,'[\"custom_tailoring\"]',3500.00,NULL,NULL,NULL,15,1,1,'https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,'[\"Chest (in)\", \"Waist (in)\", \"Shoulder (in)\"]','[{\"size\": \"Small\", \"values\": [\"36\", \"30\", \"17\"]}, {\"size\": \"Medium\", \"values\": [\"40\", \"34\", \"18\"]}, {\"size\": \"Large\", \"values\": [\"44\", \"38\", \"19\"]}]'),(3,1,'Barong Tagalog Tailoring','Classic Filipiniana formal wear, hand-tailored to fit. Choose from plain cotton, jusi, or premium piña fabric. Includes one fitting session before final delivery.','[\"Plain Cotton Barong\", \"Jusi Fabric Barong\", \"Premium Piña Barong\"]',NULL,NULL,'[\"Barong Tagalog Tailoring\", \"Formal & Cultural Wear\"]',NULL,'[\"fashion_bridal\"]',1500.00,NULL,NULL,NULL,10,1,1,'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?q=80&w=800&auto=format&fit=crop','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(4,1,'Bridal & Wedding Gown Design','Custom-designed wedding gowns from sketch to final fitting. Two fitting sessions included.','[\"Simple A-Line Gown\", \"Ball Gown with Beadwork\", \"Mermaid Gown with Train\"]',NULL,NULL,'[\"Custom Bridal Tailoring\", \"Gown & Evening Wear Designing\", \"Formal & Cultural Wear\"]',NULL,'[\"fashion_bridal\"]',8000.00,NULL,NULL,NULL,30,1,1,'https://images.unsplash.com/photo-1594552072238-b8a33785b261?q=80&w=800&auto=format&fit=crop','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(5,1,'School & Organization Uniform Sewing','Bulk uniform sewing for schools and organizations, sized per student roster.','[\"Elementary Uniform Set\", \"High School Uniform Set\", \"Complete Set with PE Uniform\"]',NULL,NULL,'[\"School Uniforms\", \"Institutional & Uniform Wear\", \"Corporate & Team Uniforms\"]',NULL,'[\"bulk_sublimation\"]',NULL,NULL,NULL,NULL,20,1,1,'/catalog/Women\'s Esports Jersey with Customized Design .jpg','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(6,1,'Garment Alterations & Repair Services','Resizing, hemming, and repair work on existing garments.','[\"Hem Pants / Skirt\", \"Take In / Let Out Waist\", \"Zipper Replacement\", \"Sleeve Shortening\"]',NULL,NULL,'[\"General Clothing Alterations\", \"Alterations & Adjustments\"]',NULL,'[\"alteration_repair\"]',NULL,NULL,NULL,NULL,3,1,1,'/catalog/Bespoke_Suits2.jpg','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(7,1,'Corporate & Team Jersey Printing','Sublimation-printed jerseys for corporate teams and events.','[\"Basic Jersey Set (Top + Shorts)\", \"Full Sublimation Premium Set\"]',NULL,NULL,'[\"Custom Jersey Printing\", \"Corporate & Team Uniforms\"]',NULL,'[\"bulk_sublimation\"]',NULL,NULL,NULL,NULL,12,1,1,'/catalog/Healong-Customized-Design-Sportswear-Sublimation-Volleyball-Jersey.avif','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(8,1,'Embroidery & Logo Digitizing','Custom embroidery for logos, names, and designs on garments, uniforms, jackets, and accessories. New logo designs include one-time digitizing to convert artwork into a stitchable file.','[\"Small Logo Embroidery (up to 2x2 in)\", \"Team Name / Text Embroidery\", \"Large Design / Patch Embroidery\", \"Custom Logo Digitizing (one-time setup)\"]',NULL,NULL,'[\"Embroidered Logos & Team Names\", \"Custom Apparel, Printing & Embroidery\"]',NULL,'[\"bulk_sublimation\"]',NULL,NULL,NULL,NULL,4,1,1,'/catalog/AllStar-Basketball-Jersey.jpg','2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL),(9,4,'Corporate Suit Tailoring','Made-to-measure business suits for corporate clients, priced per fabric grade.','[\"Poly-Wool Blend\", \"Premium Wool\"]',NULL,'Custom Tailoring & Bespoke','[\"Suit & Tuxedo Tailoring\"]',NULL,'[\"custom_tailoring\"]',3200.00,NULL,NULL,NULL,12,1,1,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL,NULL,NULL),(10,4,'Office Uniform Sewing','Bulk office uniform sets, sized per employee roster.','[\"Standard Set\"]',NULL,'Institutional & Uniform Wear','[\"Corporate & Team Uniforms\"]',NULL,'[\"bulk_sublimation\"]',900.00,NULL,NULL,NULL,15,5,1,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL,NULL,NULL),(11,5,'Clothing Alterations & Repairs','Hemming, resizing, zipper replacement, and general repair work.','[\"Hem Pants\", \"Zipper Replacement\"]',NULL,'Alterations & Adjustments','[\"General Clothing Alterations\"]',NULL,'[\"alteration_repair\"]',150.00,NULL,NULL,NULL,3,1,1,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL),(12,5,'School Uniform Sewing','Elementary and high school uniform sets, sewn per student.','[\"Elementary Set\", \"High School Set\"]',NULL,'Institutional & Uniform Wear','[\"School Uniforms\"]',NULL,'[\"bulk_sublimation\"]',450.00,NULL,NULL,NULL,7,1,1,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('R3Po0UVyvJ7dVBV9pCZem872fPj0K5hfKlE1em37',NULL,'127.0.0.1','curl/8.7.1','eyJfdG9rZW4iOiJnVGNjY3BRSVFpT1ltU3ZFblBtNHZjeW9zd0tXMDhVOHRUa21NWnRZIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwIiwicm91dGUiOm51bGx9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1789063434);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_branches`
--

DROP TABLE IF EXISTS `shop_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `contact_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operating_hours` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT '0',
  `guide_image_url` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_branches_slug_unique` (`slug`),
  KEY `shop_branches_shop_id_foreign` (`shop_id`),
  CONSTRAINT `shop_branches_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_branches`
--

LOCK TABLES `shop_branches` WRITE;
/*!40000 ALTER TABLE `shop_branches` DISABLE KEYS */;
INSERT INTO `shop_branches` VALUES (1,1,'Main Branch','main-branch-6a93f61b867d2','123 Rizal Avenue',NULL,'Davao City',7.07020000,125.60770000,'+63 900 000 0000',NULL,1,NULL,'active','2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,1,'SUTURA (Lanang Branch)','sutura-lanang-branch-6a93f61bc0adc','Lanang Business Park',NULL,'Davao City',7.09880000,125.63120000,'+63 900 111 2222',NULL,0,NULL,'active','2026-08-30 01:21:31','2026-08-30 01:21:31'),(3,1,'SUTURA (Matina Branch)','sutura-matina-branch-6a93f61bc0d6f','Matina Crossing Road',NULL,'Davao City',7.05430000,125.58910000,'+63 900 333 4444',NULL,0,NULL,'active','2026-08-30 01:21:31','2026-08-30 01:21:31'),(4,2,'Main Branch','main-branch-6a93f61dbdedd','45 Bonifacio Street',NULL,'Davao City',7.06440000,125.61080000,'+63 911 111 1111',NULL,1,NULL,'active','2026-08-30 01:21:33','2026-08-30 01:21:33'),(5,3,'Main Branch','main-branch-6a93f61deff7f','78 J.P. Laurel Avenue',NULL,'Davao City',7.07310000,125.61280000,'+63 922 222 2222',NULL,1,NULL,'active','2026-08-30 01:21:33','2026-08-30 01:21:33'),(6,4,'Toril Main Branch','toril-main-branch-6a93f62e1f816','45 Quimpo Blvd, Toril',NULL,'Davao City',6.98140000,125.49640000,'+63 917 123 4567',NULL,1,NULL,'active','2026-08-30 01:21:50','2026-08-30 01:21:50'),(7,4,'Bajada Branch','bajada-branch-6a93f62e1fbb5','JP Laurel Ave, Bajada',NULL,'Davao City',7.10040000,125.61340000,'+63 917 555 8899',NULL,0,NULL,'active','2026-08-30 01:21:50','2026-08-30 01:21:50'),(8,5,'Buhangin Branch','buhangin-branch-6a93f62f532ad','Buhangin Road, Buhangin',NULL,'Davao City',7.11970000,125.62940000,'+63 928 555 1234',NULL,1,NULL,'active','2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `shop_branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_customers`
--

DROP TABLE IF EXISTS `shop_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_customers_shop_id_user_id_unique` (`shop_id`,`user_id`),
  KEY `shop_customers_user_id_foreign` (`user_id`),
  CONSTRAINT `shop_customers_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_customers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_customers`
--

LOCK TABLES `shop_customers` WRITE;
/*!40000 ALTER TABLE `shop_customers` DISABLE KEYS */;
INSERT INTO `shop_customers` VALUES (1,1,6,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL),(2,1,7,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL),(3,1,8,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL),(4,1,9,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL),(5,1,10,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(6,1,11,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(7,4,17,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL),(8,4,18,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL),(9,4,19,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL),(10,5,22,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL),(11,5,23,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL);
/*!40000 ALTER TABLE `shop_customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_posts`
--

DROP TABLE IF EXISTS `shop_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `service_id` bigint unsigned DEFAULT NULL,
  `image_urls` json DEFAULT NULL,
  `caption` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_posts_shop_id_foreign` (`shop_id`),
  KEY `shop_posts_service_id_foreign` (`service_id`),
  CONSTRAINT `shop_posts_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shop_posts_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_posts`
--

LOCK TABLES `shop_posts` WRITE;
/*!40000 ALTER TABLE `shop_posts` DISABLE KEYS */;
INSERT INTO `shop_posts` VALUES (1,1,3,'[\"https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?q=80&w=800&auto=format&fit=crop\"]','Delivered this custom barong for a client\'s wedding — jusi fabric with hand embroidery on the collar. Order placed 2 weeks ago, released today!','2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,2,'[\"https://images.unsplash.com/photo-1594938298603-c8148c4dae35?q=80&w=800&auto=format&fit=crop\"]','Another satisfied customer picking up their bespoke suit today. Full canvas construction, tailored over 3 fitting sessions.','2026-08-30 01:21:33','2026-08-30 01:21:33');
/*!40000 ALTER TABLE `shop_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_reviews`
--

DROP TABLE IF EXISTS `shop_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `rating` int NOT NULL COMMENT '1 to 5 stars',
  `comment` text COLLATE utf8mb4_unicode_ci,
  `reply` text COLLATE utf8mb4_unicode_ci,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shop_reviews_shop_id_user_id_unique` (`shop_id`,`user_id`),
  KEY `shop_reviews_user_id_foreign` (`user_id`),
  CONSTRAINT `shop_reviews_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_reviews_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_reviews`
--

LOCK TABLES `shop_reviews` WRITE;
/*!40000 ALTER TABLE `shop_reviews` DISABLE KEYS */;
INSERT INTO `shop_reviews` VALUES (1,1,6,5,'Exceptional quality. The bespoke suit fits perfectly.',NULL,1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,7,5,'Fast turnaround and high-quality sublimation jerseys. Highly recommended!',NULL,1,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,1,8,4,'Very professional tailor, although scheduling the fitting session took some time. Overall great experience.',NULL,0,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,4,19,5,'Sharp, professional suits. Delivered on time for our company event.',NULL,1,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(5,4,17,4,'Good quality, turnaround took a bit longer than quoted.',NULL,0,'2026-08-30 01:21:51','2026-08-30 01:21:51'),(6,5,22,5,'Mabilis at maganda ang tahi. Sulit na sulit!',NULL,1,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `shop_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_special_hours`
--

DROP TABLE IF EXISTS `shop_special_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_special_hours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `shop_branch_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT '0',
  `special_open_time` time DEFAULT NULL,
  `special_close_time` time DEFAULT NULL,
  `announcement_message` text COLLATE utf8mb4_unicode_ci,
  `announcement_image_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_special_hours_shop_id_foreign` (`shop_id`),
  KEY `shop_special_hours_shop_branch_id_foreign` (`shop_branch_id`),
  CONSTRAINT `shop_special_hours_shop_branch_id_foreign` FOREIGN KEY (`shop_branch_id`) REFERENCES `shop_branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shop_special_hours_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_special_hours`
--

LOCK TABLES `shop_special_hours` WRITE;
/*!40000 ALTER TABLE `shop_special_hours` DISABLE KEYS */;
INSERT INTO `shop_special_hours` VALUES (1,1,NULL,'Christmas Break 2026','2026-12-24','2026-12-26',1,NULL,NULL,'Merry Christmas! SUTURA will be fully closed from December 24 to 26 to celebrate the holidays with our families. Online bookings on these dates are disabled.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(2,1,NULL,'Staff Planning Day','2026-07-04','2026-07-04',0,'10:00:00','15:00:00','We are having our annual Staff Planning Day on July 4. Custom hours apply: 10:00 AM - 3:00 PM.',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33');
/*!40000 ALTER TABLE `shop_special_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shop_subscriptions`
--

DROP TABLE IF EXISTS `shop_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shop_subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `plan_id` bigint unsigned NOT NULL,
  `status` enum('trial','active','expired','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trial',
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `expiry_reminder_sent_at` timestamp NULL DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_subscriptions_shop_id_foreign` (`shop_id`),
  KEY `shop_subscriptions_plan_id_foreign` (`plan_id`),
  CONSTRAINT `shop_subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`),
  CONSTRAINT `shop_subscriptions_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shop_subscriptions`
--

LOCK TABLES `shop_subscriptions` WRITE;
/*!40000 ALTER TABLE `shop_subscriptions` DISABLE KEYS */;
INSERT INTO `shop_subscriptions` VALUES (1,1,3,'trial','2026-08-30 01:21:31','2026-09-29 01:21:31',NULL,'2026-09-29 01:21:31',NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,2,1,'trial','2026-08-30 01:21:33','2026-09-29 01:21:33',NULL,'2026-09-29 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(3,3,2,'trial','2026-08-30 01:21:33','2026-09-29 01:21:33',NULL,'2026-09-29 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33'),(4,4,1,'active','2026-08-10 01:21:50','2026-09-09 01:21:50',NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50');
/*!40000 ALTER TABLE `shop_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shops`
--

DROP TABLE IF EXISTS `shops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shops` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `province` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `postal_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tailoring_shop',
  `social_links` json DEFAULT NULL,
  `logo_path` text COLLATE utf8mb4_unicode_ci,
  `banner_path` text COLLATE utf8mb4_unicode_ci,
  `gallery_images` json DEFAULT NULL,
  `status` enum('pending','approved','rejected','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `booking_policy` text COLLATE utf8mb4_unicode_ci,
  `booking_questions` json DEFAULT NULL,
  `max_appointments_per_day` int unsigned DEFAULT NULL,
  `fitting_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fitting_limit` int NOT NULL DEFAULT '3',
  `gcash_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gcash_account_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gcash_qr_path` text COLLATE utf8mb4_unicode_ci,
  `bank_qr_path` text COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `operating_hours` json DEFAULT NULL,
  `specializations` json DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `shops_slug_unique` (`slug`),
  KEY `shops_owner_id_foreign` (`owner_id`),
  KEY `shops_approved_by_foreign` (`approved_by`),
  CONSTRAINT `shops_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shops_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shops`
--

LOCK TABLES `shops` WRITE;
/*!40000 ALTER TABLE `shops` DISABLE KEYS */;
INSERT INTO `shops` VALUES (1,2,'Thread & Needle Tailoring','thread-needle','Davao City\'s premier provider of full sublimation jerseys, corporate uniforms, and custom tailoring.','123 Rizal Avenue',NULL,'Davao City','Davao del Sur',NULL,'+639000000000','hello@threadneedle.com','tailoring_shop',NULL,'/storage/logos/sutura_logo.png',NULL,NULL,'approved',NULL,'2026-08-30 01:21:31',NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL,NULL,NULL,0.00,3,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'{\"friday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": true}, \"monday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": true}, \"sunday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": false}, \"tuesday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": true}, \"saturday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": false}, \"thursday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": true}, \"wednesday\": {\"open\": \"09:00\", \"close\": \"18:00\", \"is_open\": true}}',NULL,0,0),(2,12,'Bautista Custom Tailors','bautista-tailors','Everyday tailoring and school uniform specialists.','45 Bonifacio Street',NULL,'Davao City','Davao del Sur',NULL,'+639111111111','hello@bautistatailors.com','tailoring_shop',NULL,NULL,NULL,NULL,'approved',NULL,'2026-08-30 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,0.00,3,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'{\"friday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"monday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"sunday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": false}, \"tuesday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"saturday\": {\"open\": \"08:00\", \"close\": \"12:00\", \"is_open\": true}, \"thursday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"wednesday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}}',NULL,0,0),(3,13,'Villanueva Bespoke Atelier','villanueva-atelier','Formal wear and bridal atelier serving multiple branches.','78 J.P. Laurel Avenue',NULL,'Davao City','Davao del Sur',NULL,'+639222222222','hello@villanuevaatelier.com','tailoring_shop',NULL,NULL,NULL,NULL,'approved',NULL,'2026-08-30 01:21:33',NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL,NULL,NULL,0.00,3,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'{\"friday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}, \"monday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}, \"sunday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": false}, \"tuesday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}, \"saturday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}, \"thursday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}, \"wednesday\": {\"open\": \"10:00\", \"close\": \"19:00\", \"is_open\": true}}',NULL,0,0),(4,14,'Davao Formal Wear Co.','davao-formal-wear','Corporate and formal wear specialists — suits, barongs, and office uniforms for Davao City businesses.','45 Quimpo Blvd, Toril',NULL,'Davao City','Davao del Sur',NULL,'+639171234567','hello@davaoformalwear.com','tailoring_shop',NULL,NULL,NULL,NULL,'approved',NULL,'2026-08-30 01:21:50',NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL,NULL,NULL,0.00,3,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'{\"friday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"monday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"sunday\": {\"open\": \"09:00\", \"close\": \"14:00\", \"is_open\": false}, \"tuesday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"saturday\": {\"open\": \"09:00\", \"close\": \"14:00\", \"is_open\": true}, \"thursday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}, \"wednesday\": {\"open\": \"08:00\", \"close\": \"17:00\", \"is_open\": true}}',NULL,0,0),(5,20,'Fely\'s Alterations & Uniforms','felys-alterations','Neighborhood alterations shop specializing in school uniforms and everyday clothing repairs.','Buhangin Road, Buhangin',NULL,'Davao City','Davao del Sur',NULL,'+639285551234','fely.alterations@example.com','tailoring_shop',NULL,NULL,NULL,NULL,'approved',NULL,'2026-08-30 01:21:51',NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL,NULL,NULL,0.00,3,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'{\"friday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}, \"monday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}, \"sunday\": {\"open\": \"08:00\", \"close\": \"12:00\", \"is_open\": true}, \"tuesday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}, \"saturday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}, \"thursday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}, \"wednesday\": {\"open\": \"08:00\", \"close\": \"18:00\", \"is_open\": true}}',NULL,0,0);
/*!40000 ALTER TABLE `shops` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_profiles`
--

DROP TABLE IF EXISTS `staff_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `shop_id` bigint unsigned NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `shop_branch_id` bigint unsigned DEFAULT NULL,
  `is_branch_manager` tinyint(1) NOT NULL DEFAULT '0',
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tailor',
  `additional_roles` json DEFAULT NULL,
  `specialization` text COLLATE utf8mb4_unicode_ci,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `hired_at` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_profiles_user_id_shop_id_unique` (`user_id`,`shop_id`),
  KEY `staff_profiles_shop_id_foreign` (`shop_id`),
  KEY `staff_profiles_shop_branch_id_foreign` (`shop_branch_id`),
  CONSTRAINT `staff_profiles_shop_branch_id_foreign` FOREIGN KEY (`shop_branch_id`) REFERENCES `shop_branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_profiles_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_profiles`
--

LOCK TABLES `staff_profiles` WRITE;
/*!40000 ALTER TABLE `staff_profiles` DISABLE KEYS */;
INSERT INTO `staff_profiles` VALUES (1,3,1,1,1,0,'head_tailor','[\"sublimation_specialist\"]',NULL,NULL,1,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,4,1,1,1,0,'senior_designer',NULL,NULL,NULL,1,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(3,5,1,1,1,0,'cutter_sewer',NULL,NULL,NULL,1,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32'),(4,15,4,1,6,1,'head_tailor',NULL,NULL,NULL,1,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50'),(5,16,4,1,7,0,'cutter_sewer',NULL,NULL,NULL,1,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50'),(6,21,5,1,8,0,'seamstress',NULL,NULL,NULL,1,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51');
/*!40000 ALTER TABLE `staff_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_plans`
--

DROP TABLE IF EXISTS `subscription_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `price_yearly` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_staff` int NOT NULL DEFAULT '3',
  `max_services` int NOT NULL DEFAULT '10',
  `max_appointments_per_month` int NOT NULL DEFAULT '50',
  `features` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_plans_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_plans`
--

LOCK TABLES `subscription_plans` WRITE;
/*!40000 ALTER TABLE `subscription_plans` DISABLE KEYS */;
INSERT INTO `subscription_plans` VALUES (1,'Basic','basic','Perfect for independent tailors just getting started online.',299.00,2990.00,1,10,50,'[\"Customer Management\", \"Appointment Scheduling\", \"Order Tracking\", \"Measurement Recording\", \"Text-Only Profile\", \"Manual Updates (Web Portal)\", \"Standard Search Listing\"]',1,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(2,'Pro','pro','Grow faster with visibility tools, portfolio, and team management.',799.00,7990.00,5,50,200,'[\"All Basic Plan Features\", \"Boosted Search Visibility\", \"Visual Portfolio Gallery\", \"Direct Customer Inquiries\", \"Visual Dashboard & Analytics\", \"SMS/Email Notifications\", \"Measurement History\", \"Multi-User Access\", \"Staff Management\"]',1,'2026-08-30 01:21:31','2026-08-30 01:21:31'),(3,'Premium','premium','Top-tier visibility, custom branding, and advanced reporting for serious shops.',1999.00,19990.00,-1,-1,-1,'[\"All Pro Plan Features\", \"Multi-Branch Management\", \"Custom Branding\", \"Featured Shop Visibility (Top Placement)\", \"Sales Reports & Income Exports\", \"Advanced Dashboard\"]',1,'2026-08-30 01:21:31','2026-08-30 01:21:31');
/*!40000 ALTER TABLE `subscription_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_ticket_replies`
--

DROP TABLE IF EXISTS `support_ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket_replies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachments` json DEFAULT NULL,
  `is_admin_reply` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_ticket_replies_ticket_id_foreign` (`ticket_id`),
  KEY `support_ticket_replies_user_id_foreign` (`user_id`),
  CONSTRAINT `support_ticket_replies_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_ticket_replies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_ticket_replies`
--

LOCK TABLES `support_ticket_replies` WRITE;
/*!40000 ALTER TABLE `support_ticket_replies` DISABLE KEYS */;
INSERT INTO `support_ticket_replies` VALUES (1,2,1,'Done! Your Quezon City branch has been added — you can find it under Branches. Let us know if you need anything else.',NULL,1,'2026-08-30 01:21:33','2026-08-30 01:21:33');
/*!40000 ALTER TABLE `support_ticket_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachments` json DEFAULT NULL,
  `type` enum('problem','update_request','general','billing') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `assigned_to` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_tickets_shop_id_foreign` (`shop_id`),
  KEY `support_tickets_user_id_foreign` (`user_id`),
  KEY `support_tickets_assigned_to_foreign` (`assigned_to`),
  CONSTRAINT `support_tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_tickets_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

LOCK TABLES `support_tickets` WRITE;
/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
INSERT INTO `support_tickets` VALUES (1,1,2,'Payment shows as pending even after customer paid via GCash','A customer sent a GCash payment for their appointment deposit and I have the receipt, but it still shows as \"pending\" on my dashboard. How do I mark it as confirmed?',NULL,'problem','high','open',NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL),(2,1,2,'Requesting a second branch to be added','We just opened a second branch in Quezon City. Can you add it to my account so I can start assigning staff and appointments there?',NULL,'update_request','medium','resolved',NULL,'2026-08-28 01:21:33','2026-08-30 01:21:33','2026-08-30 01:21:33',NULL);
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_set_at` timestamp NULL DEFAULT NULL,
  `profile_picture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_photo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `experience` json DEFAULT NULL,
  `education` json DEFAULT NULL,
  `skills` json DEFAULT NULL,
  `social_links` json DEFAULT NULL,
  `creations_gallery` json DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suki_tag` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System Admin','admin@sutura.com','2026-08-30 01:21:31','$2y$12$rKk2q9USl7AZ89cNfmSMOeTpse36D5SKfHTnVEf/pOtIu1.FRVrmK',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL),(2,'Maria Cruz','owner@sutura.com','2026-08-30 01:21:31','$2y$12$fgj6.QCR5PsWXv9lKF3uXOcfnF3Qw6MfJhjPhfk29vbRC7GPt4Jte',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,'2026-09-11 06:41:08'),(3,'Juan Dela Cruz','staff@sutura.com','2026-08-30 01:21:31','$2y$12$pDvNl0vNBPexD..A9lWQ7eNZ..P1zntlQSJ54ahwkPVrH8uIhOeTK',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL),(4,'Ana Santos','ana.santos@sutura.com','2026-08-30 01:21:31','$2y$12$gGJRo08v1A/mrUXzYG4uD.y/hxkSxAxyChMKav1MmiMVsNmr50kFm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:31','2026-08-30 01:21:31',NULL,NULL),(5,'Pedro Penduko','pedro.penduko@sutura.com','2026-08-30 01:21:32','$2y$12$SV/2oumvGS3Bza4urm7Df.WaJz6CfZmRQZwvMiHe.Qo8nNYw/loIS',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL,NULL),(6,'Jose Rizal','jose.rizal@gmail.com','2026-08-30 01:21:32','$2y$12$0o8rBbQGnAdpEkIulI7CQ.WgSHJveXV5uOS4Iz19bTecPW8jBczZC',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL,NULL),(7,'Andres Bonifacio','andres.b@gmail.com','2026-08-30 01:21:32','$2y$12$4tCRKzZUaCwRdRoJwHVQ7uh/8PyMaoMVzOBjoSsA/e3PjMmlv84QK',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL,NULL),(8,'Maria Clara','maria.clara@gmail.com','2026-08-30 01:21:32','$2y$12$6psY.tgbcnA/TPWpt0jXoOSYyvuB5AJbewqups5ElRdVe0VXNjfGm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL,NULL),(9,'Liza Fernandez','liza.fernandez@example.com','2026-08-30 01:21:32','$2y$12$tHvoVsWkqLnvFYQsaKN5cuNgFY5QRaAFq4LXnvd2qy4PhKpfkJ2XG',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:32','2026-08-30 01:21:32',NULL,NULL),(10,'Mark Villanueva','mark.villanueva@example.com','2026-08-30 01:21:33','$2y$12$Nowx2REKOtxWZCsBJFlwb.YhOMMAK4Qjqm05xmCzsnnSE6BDyxug6',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL),(11,'Cristina Ramos','cristina.ramos@example.com','2026-08-30 01:21:33','$2y$12$fPLuWqLyAHDmSqv8xb9Ju.F7ensk7v3Ta/cPJoy3qFjgu5ByOy5TK',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL),(12,'Ana Bautista','owner2@sutura.com','2026-08-30 01:21:33','$2y$12$iZudzIJrHHTrM6XYpcO5ROq/EOrifa8Vsv1SzlsryE1JNa/OmZ9Va',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL),(13,'Carlos Villanueva','owner3@sutura.com','2026-08-30 01:21:33','$2y$12$Du5oMZk973BjTn962W6.L.VcxdP7MacbhwJnVOOuPYNMP5dd6iBv2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:33','2026-08-30 01:21:33',NULL,NULL),(14,'Ricardo Santos','ricardo@sutura.com','2026-08-30 01:21:50','$2y$12$0bc0OslVjfpunOAlSJ4ms.MuBKJlMVUdAqFSmVpgEh.MEwGkOdyja',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL),(15,'Lito Cruz','lito.cruz@davaoformalwear.com','2026-08-30 01:21:50','$2y$12$5s1Zghhyy2R9f2/cZmlwae5fMKK8NzC2fn/n53hCkputdhfKn0bte',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL),(16,'Grace Uy','grace.uy@davaoformalwear.com','2026-08-30 01:21:50','$2y$12$r8Ysaz9zb2HNOEY4XgzPLuBgUicwyBTI0KB6zf7OXVF8Y2EWr5NAi',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL),(17,'Ferdie Marasigan','ferdie.marasigan@example.com','2026-08-30 01:21:50','$2y$12$Ewh2aAvtpTYt13zb9srJ4.Yx/CmZNcZ8PwjUe7eOSS9vu8OeQxFqO',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL),(18,'Gina Lopez','gina.lopez@example.com','2026-08-30 01:21:50','$2y$12$Df9qP9RehBZdvB5USqtA5eAtgu00dYGBB2M3VbJ7BjarwRbvHbtWa',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:50','2026-08-30 01:21:50',NULL,NULL),(19,'Tonyo Cruz','tonyo.cruz@example.com','2026-08-30 01:21:51','$2y$12$E6vieiF5Tn8x0mNfPRiSbeNvtj4stSwQmAk6kRGAYSjsUNM1OM.0a',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL),(20,'Fely Aquino','fely@sutura.com','2026-08-30 01:21:51','$2y$12$YvmB4S.rvDR1/9niFuwb3OQU9jJ2/NA8fdCl10PmmKzVorjy/9OPm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL),(21,'Nena Dela Cruz','nena.dela.cruz@felys.example.com','2026-08-30 01:21:51','$2y$12$SSfFH0dHeVdJuZgT.Mn3KuaLO/3EBS0cOZZYhr2oONEXhfpsPSY9W',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL),(22,'Rowena Tan','rowena.tan@example.com','2026-08-30 01:21:51','$2y$12$pOl/dkTJOCssHmgDFiovJu2pkign64MQHwZE2Eh6RjGDFAbevVuwa',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL),(23,'Benjie Uy','benjie.uy@example.com','2026-08-30 01:21:51','$2y$12$GpbnN3FoL5gMLATPM.zn8ePLEqjKF2bdTl5AEaQu2dffPX/Sq7DQ2',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-30 01:21:51','2026-08-30 01:21:51',NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-11 22:42:07
