--
-- Clinical Co-Pilot module tables (all mod_copilot_*), shipped via the module installer.
--
-- Every table here is module-owned and append-only where noted (T7). The module treats
-- ALL existing OpenEMR tables as strictly read-only (T6) — no rows, columns, or triggers
-- are added to core. Uninstall drops only these tables + the background_services row (I9).
--
-- @package   OpenEMR\Modules\ClinicalCopilot
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable mod_copilot_doc
CREATE TABLE `mod_copilot_doc` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `pid` BIGINT NOT NULL COMMENT 'pinned patient id (host patient_data.pid)',
  `fact_digest` CHAR(64) NOT NULL COMMENT 'sha3-256 content address (facts + versions), no timestamps',
  `doc_type` VARCHAR(64) NOT NULL DEFAULT 'endo-previsit-v1' COMMENT 'digest input; column exists for querying',
  `appt_id` BIGINT DEFAULT NULL COMMENT 'metadata only, never a key',
  `doc` LONGTEXT NOT NULL COMMENT 'JSON: facts + citations + narrative (the served document)',
  `capability_versions` VARCHAR(512) NOT NULL COMMENT 'JSON map capability=>version',
  `prompt_version` VARCHAR(64) NOT NULL,
  `computed_at` DATETIME NOT NULL COMMENT 'display only; never participates in freshness (I1)',
  `correlation_id` CHAR(36) NOT NULL,
  `llm_latency_ms` INT DEFAULT NULL,
  `tokens_in` INT DEFAULT NULL,
  `tokens_out` INT DEFAULT NULL,
  `cost_usd` DECIMAL(10,6) DEFAULT NULL,
  `excluded_counts` VARCHAR(1024) DEFAULT NULL COMMENT 'JSON per-analyte incl. unitless-exclusion rate',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pid_digest` (`pid`, `fact_digest`),
  KEY `idx_pid_computed` (`pid`, `computed_at`)
) ENGINE=InnoDB COMMENT='Append-only content-addressed synthesis docs (T7)';
#EndIf

#IfNotTable mod_copilot_cadence
CREATE TABLE `mod_copilot_cadence` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(96) NOT NULL COMMENT 'e.g. code_set:acr, unit:a1c, turnaround:a1c, ratelimit:per_user_hour, breaker:daily_cap_usd',
  `config_value` VARCHAR(1024) NOT NULL COMMENT 'interval days / conversion / limit value / JSON payload',
  `version` VARCHAR(64) NOT NULL COMMENT 'digest input; bump to invalidate affected docs (E5)',
  `updated_at` DATETIME DEFAULT NULL COMMENT 'module-owned so a mutable timestamp is allowed here',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_version` (`config_key`, `version`)
) ENGINE=InnoDB COMMENT='Versioned cadence/unit/turnaround/rate-limit/breaker config';
#EndIf

#IfNotTable mod_copilot_chat_session
CREATE TABLE `mod_copilot_chat_session` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `pid` BIGINT NOT NULL COMMENT 'server-side pin; no tool ever accepts a patient arg (I10)',
  `user_id` BIGINT NOT NULL COMMENT 'host authUserID; re-checked every turn',
  `doc_id` BIGINT DEFAULT NULL COMMENT 'fk mod_copilot_doc the session seeded from',
  `fact_digest` CHAR(64) NOT NULL COMMENT 'digest of the seed fact set (drift check per turn)',
  `status` ENUM('active','frozen') NOT NULL DEFAULT 'active' COMMENT 'frozen = verifier V3 sev-1 trip; preserved as evidence',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB COMMENT='pid-pinned chat sessions';
#EndIf

#IfNotTable mod_copilot_chat_turn
CREATE TABLE `mod_copilot_chat_turn` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT NOT NULL,
  `seq` INT NOT NULL COMMENT 'turn ordinal within the session',
  `role` ENUM('user','assistant','tool') NOT NULL,
  `content` LONGTEXT NOT NULL COMMENT 'JSON turn content (verified prose / user message / tool result)',
  `tool_calls` LONGTEXT DEFAULT NULL COMMENT 'JSON list of tool requests + results for this turn',
  `verification_verdict` LONGTEXT DEFAULT NULL COMMENT 'JSON per-check V1-V6 verdicts',
  `correlation_id` CHAR(36) NOT NULL,
  `tokens_in` INT DEFAULT NULL,
  `tokens_out` INT DEFAULT NULL,
  `cost_usd` DECIMAL(10,6) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session_seq` (`session_id`, `seq`),
  KEY `idx_correlation` (`correlation_id`)
) ENGINE=InnoDB COMMENT='Append-only turn ledger (T7): byte-for-byte what the physician saw';
#EndIf

#IfNotTable mod_copilot_trace
CREATE TABLE `mod_copilot_trace` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `correlation_id` CHAR(36) NOT NULL,
  `span_id` CHAR(36) NOT NULL,
  `parent_span_id` CHAR(36) DEFAULT NULL,
  `kind` ENUM('extract','digest','cache_lookup','llm_reduce','chat_turn','tool_call','verify','render','warm','alert_eval') NOT NULL,
  `started_at` DATETIME(3) NOT NULL,
  `duration_ms` INT DEFAULT NULL,
  `status` ENUM('ok','error','retried','degraded') NOT NULL DEFAULT 'ok',
  `error_class` VARCHAR(191) DEFAULT NULL,
  `error_detail` TEXT DEFAULT NULL COMMENT 'never contains PHI or raw exception text shown to users',
  `model` VARCHAR(96) DEFAULT NULL,
  `tokens_in` INT DEFAULT NULL,
  `tokens_out` INT DEFAULT NULL,
  `cost_usd` DECIMAL(10,6) DEFAULT NULL,
  `pid` BIGINT DEFAULT NULL,
  `user_id` BIGINT DEFAULT NULL,
  `payload_ref` VARCHAR(191) DEFAULT NULL COMMENT 'pointer to stored PHI payload; lives in this MySQL, never third-party SaaS (T16)',
  PRIMARY KEY (`id`),
  KEY `idx_correlation` (`correlation_id`),
  KEY `idx_kind_started` (`kind`, `started_at`),
  KEY `idx_started` (`started_at`)
) ENGINE=InnoDB COMMENT='Append-only observability source of truth (I12)';
#EndIf

#IfNotRow2D background_services name mod_copilot_warm function mod_copilot_warm_run
INSERT INTO `background_services`
  (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES
  ('mod_copilot_warm', 'Clinical Co-Pilot Warm + Alert Worker', 1, 0, NOW(), 5,
   'mod_copilot_warm_run',
   '/interface/modules/custom_modules/oe-module-clinical-copilot/src/worker_entry.php', 200);
#EndIf
