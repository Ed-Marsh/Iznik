-- Idempotent production SQL for 2026_10_25_000001_create_ai_image_aliases_and_embeddings_tables.php
-- Run once on production BEFORE deploying the Go API and batch that use it. Two new, empty tables.
-- CREATE TABLE IF NOT EXISTS is safe to run twice. The foreign keys reference ai_images (60k rows)
-- but take only a brief metadata lock on it, because the new tables are empty.
SET SESSION lock_wait_timeout = 5;

CREATE TABLE IF NOT EXISTS `ai_image_aliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aiimageid` bigint unsigned NOT NULL,
  `method` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score` double DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_image_aliases_name` (`name`),
  KEY `ai_image_aliases_aiimageid` (`aiimageid`),
  CONSTRAINT `ai_image_aliases_aiimageid_foreign` FOREIGN KEY (`aiimageid`) REFERENCES `ai_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_images_embeddings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `aiimageid` bigint unsigned NOT NULL,
  `embedding` blob NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_images_embeddings_aiimageid` (`aiimageid`),
  CONSTRAINT `ai_images_embeddings_aiimageid_foreign` FOREIGN KEY (`aiimageid`) REFERENCES `ai_images` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
