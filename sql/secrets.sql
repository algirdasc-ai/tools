SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `secrets` (
  `hash` varchar(32) COLLATE utf8mb4_bin NOT NULL,
  `ciphertext` mediumtext COLLATE utf8mb4_bin NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `max_reads` int unsigned DEFAULT NULL,
  `reads` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `secrets`
  ADD PRIMARY KEY (`hash`);
COMMIT;
