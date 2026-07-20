CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "currencies"(
  "code" varchar not null,
  "name" varchar not null,
  "minor_unit" integer not null,
  primary key("code")
);
CREATE TABLE IF NOT EXISTS "accounts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "name" varchar not null,
  "slug" varchar not null,
  "kind" varchar not null,
  "iban" varchar not null,
  "default_currency" varchar not null default 'EUR',
  "created_at" datetime,
  "updated_at" datetime,
  "forecast_min_buffer_minor" integer,
  "opening_balance_minor" integer,
  "opening_balance_as_of_date" date,
  "starting_balance_minor" integer,
  "starting_balance_date" date,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "accounts_user_id_kind_index" on "accounts"("user_id", "kind");
CREATE UNIQUE INDEX "accounts_user_id_iban_unique" on "accounts"(
  "user_id",
  "iban"
);
CREATE UNIQUE INDEX "accounts_user_id_slug_unique" on "accounts"(
  "user_id",
  "slug"
);
CREATE TABLE IF NOT EXISTS "categories"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "parent_id" integer,
  "name" varchar not null,
  "slug" varchar not null,
  "kind" varchar not null,
  "display_order" integer not null default '100',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("parent_id") references "categories"("id") on delete set null
);
CREATE UNIQUE INDEX "categories_user_id_slug_unique" on "categories"(
  "user_id",
  "slug"
);
CREATE UNIQUE INDEX categories_global_slug_uq ON categories(
  slug
) WHERE user_id IS NULL;
CREATE TABLE IF NOT EXISTS "import_runs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "source_format" varchar not null,
  "raw_file_path" varchar not null,
  "sha256" varchar not null,
  "uploaded_at" datetime not null,
  "confirmed_at" datetime,
  "inserted_count" integer not null default '0',
  "duplicate_count" integer not null default '0',
  "error_count" integer not null default '0',
  "status" varchar not null default 'previewed',
  "created_at" datetime,
  "updated_at" datetime,
  "enriched_count" integer not null default '0',
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "import_runs_user_id_sha256_unique" on "import_runs"(
  "user_id",
  "sha256"
);
CREATE TABLE IF NOT EXISTS "merchants"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "name" varchar not null,
  "normalized_name" varchar not null,
  "default_category_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("default_category_id") references "categories"("id") on delete set null
);
CREATE UNIQUE INDEX "merchants_user_id_normalized_name_unique" on "merchants"(
  "user_id",
  "normalized_name"
);
CREATE TABLE IF NOT EXISTS "merchant_memories"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "merchant_id" integer not null,
  "category_id" integer not null,
  "occurrence_count" integer not null default '0',
  "last_seen_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("merchant_id") references "merchants"("id") on delete cascade,
  foreign key("category_id") references "categories"("id") on delete cascade
);
CREATE UNIQUE INDEX "merchant_memories_user_id_merchant_id_category_id_unique" on "merchant_memories"(
  "user_id",
  "merchant_id",
  "category_id"
);
CREATE TABLE IF NOT EXISTS "statement_summaries"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "import_run_id" integer not null,
  "account_id" integer not null,
  "iban_owner" varchar not null,
  "statement_number" varchar,
  "period_start" datetime,
  "period_end" datetime,
  "opening_balance_minor" integer,
  "opening_balance_currency" varchar,
  "opening_balance_date" datetime,
  "closing_balance_minor" integer,
  "closing_balance_currency" varchar,
  "closing_balance_date" datetime,
  "entry_count" integer not null default '0',
  "extras" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("import_run_id") references "import_runs"("id") on delete cascade,
  foreign key("account_id") references "accounts"("id") on delete cascade
);
CREATE UNIQUE INDEX "statement_summaries_user_id_import_run_id_unique" on "statement_summaries"(
  "user_id",
  "import_run_id"
);
CREATE TABLE IF NOT EXISTS "transactions"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "account_id" integer not null,
  "type" varchar not null,
  "posted_at" date not null,
  "booked_at" datetime not null,
  "value_date" date not null,
  "amount_minor" integer not null,
  "currency" varchar not null,
  "settled_amount_minor" integer not null,
  "settled_currency" varchar not null,
  "fx_rate_used" numeric,
  "counterparty_name" varchar,
  "counterparty_iban" varchar,
  "counterparty_normalized" varchar not null,
  "normalization_version" integer not null,
  "description" text,
  "category_id" integer,
  "source_format" varchar not null,
  "import_run_id" integer not null,
  "source_row_index" integer not null,
  "source_ref" varchar,
  "fingerprint" varchar not null,
  "fingerprint_version" integer not null,
  "status" varchar not null default('cleared'),
  "created_at" datetime,
  "updated_at" datetime,
  "enriched_from" text,
  "raw_payload" text,
  "pair_transaction_id" integer,
  "auto_category_provenance" text,
  "payment_type" varchar not null default 'unknown',
  "counterparty_id" integer,
  foreign key("import_run_id") references import_runs("id") on delete no action on update no action,
  foreign key("category_id") references categories("id") on delete set null on update no action,
  foreign key("account_id") references accounts("id") on delete cascade on update no action,
  foreign key("user_id") references users("id") on delete cascade on update no action,
  foreign key("pair_transaction_id") references "transactions"("id") on delete set null
);
CREATE INDEX "transactions_account_id_posted_at_index" on "transactions"(
  "account_id",
  "posted_at"
);
CREATE INDEX "transactions_category_id_posted_at_index" on "transactions"(
  "category_id",
  "posted_at"
);
CREATE UNIQUE INDEX "transactions_fingerprint_sha_uq" on "transactions"(
  "user_id",
  "fingerprint"
);
CREATE UNIQUE INDEX "transactions_fingerprint_uq" on "transactions"(
  "user_id",
  "account_id",
  "posted_at",
  "booked_at",
  "amount_minor",
  "currency",
  "counterparty_normalized"
);
CREATE INDEX "transactions_uncategorized_idx" on "transactions"(
  "user_id",
  "posted_at"
);
CREATE INDEX "transactions_user_id_posted_at_index" on "transactions"(
  "user_id",
  "posted_at"
);
CREATE INDEX transactions_unpaired_transfer_idx ON transactions(
  user_id,
  account_id,
  booked_at
) WHERE pair_transaction_id IS NULL AND type IN(
  'transfer_out',
  'transfer_in'
);
CREATE TABLE IF NOT EXISTS "chain_links"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "from_transaction_id" integer not null,
  "to_transaction_id" integer,
  "kind" varchar not null,
  "state" varchar not null,
  "confidence" numeric not null,
  "resolver" varchar not null,
  "evidence" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("from_transaction_id") references "transactions"("id") on delete cascade,
  foreign key("to_transaction_id") references "transactions"("id") on delete cascade
);
CREATE INDEX "chain_links_from_transaction_id_index" on "chain_links"(
  "from_transaction_id"
);
CREATE INDEX "chain_links_to_transaction_id_index" on "chain_links"(
  "to_transaction_id"
);
CREATE INDEX "chain_links_user_id_state_index" on "chain_links"(
  "user_id",
  "state"
);
CREATE TRIGGER chain_links_state_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN ('candidate','confirmed','rejected')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END;
CREATE TRIGGER chain_links_state_check_update BEFORE UPDATE OF state ON chain_links FOR EACH ROW
             WHEN NEW.state NOT IN ('candidate','confirmed','rejected')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.state value'); END;
CREATE TABLE IF NOT EXISTS "card_statements"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "account_id" integer not null,
  "import_run_id" integer,
  "period_start" datetime not null,
  "period_end" datetime not null,
  "total_amount_minor" integer not null,
  "open_balance_minor" integer not null,
  "state" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("account_id") references "accounts"("id") on delete cascade,
  foreign key("import_run_id") references "import_runs"("id") on delete set null
);
CREATE UNIQUE INDEX "card_statements_user_id_account_id_period_start_period_end_unique" on "card_statements"(
  "user_id",
  "account_id",
  "period_start",
  "period_end"
);
CREATE INDEX "card_statements_user_id_state_index" on "card_statements"(
  "user_id",
  "state"
);
CREATE TRIGGER card_statements_state_check_insert BEFORE INSERT ON card_statements FOR EACH ROW
             WHEN NEW.state NOT IN ('open','partially_settled','settled','overpaid')
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statements.state value'); END;
CREATE TRIGGER card_statements_state_check_update BEFORE UPDATE OF state ON card_statements FOR EACH ROW
             WHEN NEW.state NOT IN ('open','partially_settled','settled','overpaid')
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statements.state value'); END;
CREATE TABLE IF NOT EXISTS "card_statement_credits"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "from_statement_id" integer not null,
  "to_statement_id" integer,
  "amount_minor" integer not null,
  "reason" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("from_statement_id") references "card_statements"("id") on delete cascade,
  foreign key("to_statement_id") references "card_statements"("id") on delete set null
);
CREATE INDEX "card_statement_credits_user_id_to_statement_id_index" on "card_statement_credits"(
  "user_id",
  "to_statement_id"
);
CREATE TRIGGER card_statement_credits_reason_check_insert BEFORE INSERT ON card_statement_credits FOR EACH ROW
             WHEN NEW.reason NOT IN ('overpayment','refund_after_close')
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statement_credits.reason value'); END;
CREATE TRIGGER card_statement_credits_reason_check_update BEFORE UPDATE OF reason ON card_statement_credits FOR EACH ROW
             WHEN NEW.reason NOT IN ('overpayment','refund_after_close')
             BEGIN SELECT RAISE(ABORT, 'Invalid card_statement_credits.reason value'); END;
CREATE TABLE IF NOT EXISTS "chain_resolution_runs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "job_uuid" varchar,
  "started_at" datetime,
  "completed_at" datetime,
  "status" varchar not null,
  "linked_count" integer not null default '0',
  "last_error" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "chain_resolution_runs_user_id_created_at_index" on "chain_resolution_runs"(
  "user_id",
  "created_at"
);
CREATE TRIGGER chain_resolution_runs_status_check_insert BEFORE INSERT ON chain_resolution_runs FOR EACH ROW
             WHEN NEW.status NOT IN ('pending','running','complete','failed')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_resolution_runs.status value'); END;
CREATE TRIGGER chain_resolution_runs_status_check_update BEFORE UPDATE OF status ON chain_resolution_runs FOR EACH ROW
             WHEN NEW.status NOT IN ('pending','running','complete','failed')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_resolution_runs.status value'); END;
CREATE TABLE IF NOT EXISTS "inboxes"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "provider" varchar not null,
  "email" varchar not null,
  "backfill_window_months" integer not null default '3',
  "backfill_progress" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "inboxes_user_id_provider_index" on "inboxes"(
  "user_id",
  "provider"
);
CREATE INDEX "inboxes_user_id_created_at_index" on "inboxes"(
  "user_id",
  "created_at"
);
CREATE TRIGGER inboxes_provider_check_insert BEFORE INSERT ON inboxes FOR EACH ROW
             WHEN NEW.provider NOT IN ('gmail','microsoft')
             BEGIN SELECT RAISE(ABORT, 'Invalid inboxes.provider value'); END;
CREATE TRIGGER inboxes_provider_check_update BEFORE UPDATE OF provider ON inboxes FOR EACH ROW
             WHEN NEW.provider NOT IN ('gmail','microsoft')
             BEGIN SELECT RAISE(ABORT, 'Invalid inboxes.provider value'); END;
CREATE TABLE IF NOT EXISTS "inbox_scan_state"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "inbox_id" integer not null,
  "folder" varchar not null default 'INBOX',
  "last_history_id" varchar,
  "last_delta_link" text,
  "last_scan_at" datetime,
  "status" varchar not null default 'idle',
  "error_message" text,
  "retry_attempts" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("inbox_id") references "inboxes"("id") on delete cascade
);
CREATE UNIQUE INDEX "inbox_scan_state_inbox_id_folder_unique" on "inbox_scan_state"(
  "inbox_id",
  "folder"
);
CREATE INDEX "inbox_scan_state_user_id_status_index" on "inbox_scan_state"(
  "user_id",
  "status"
);
CREATE TRIGGER inbox_scan_state_status_check_insert BEFORE INSERT ON inbox_scan_state FOR EACH ROW
             WHEN NEW.status NOT IN ('idle','backfilling','scanning','rate_limited','needs_reauth','error')
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_scan_state.status value'); END;
CREATE TRIGGER inbox_scan_state_status_check_update BEFORE UPDATE OF status ON inbox_scan_state FOR EACH ROW
             WHEN NEW.status NOT IN ('idle','backfilling','scanning','rate_limited','needs_reauth','error')
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_scan_state.status value'); END;
CREATE TABLE IF NOT EXISTS "inbox_messages"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "inbox_id" integer not null,
  "provider_message_id" varchar not null,
  "internal_date" datetime not null,
  "sender_email" varchar not null,
  "sender_name" varchar,
  "subject" varchar,
  "status" varchar not null default 'fetched',
  "fetched_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "matcher_key" varchar,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("inbox_id") references "inboxes"("id") on delete cascade
);
CREATE UNIQUE INDEX "inbox_messages_inbox_id_provider_message_id_unique" on "inbox_messages"(
  "inbox_id",
  "provider_message_id"
);
CREATE INDEX "inbox_messages_user_id_status_index" on "inbox_messages"(
  "user_id",
  "status"
);
CREATE INDEX "inbox_messages_inbox_id_internal_date_index" on "inbox_messages"(
  "inbox_id",
  "internal_date"
);
CREATE TRIGGER inbox_messages_status_check_insert BEFORE INSERT ON inbox_messages FOR EACH ROW
             WHEN NEW.status NOT IN ('fetched','parsed','skipped','unmatched')
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_messages.status value'); END;
CREATE TRIGGER inbox_messages_status_check_update BEFORE UPDATE OF status ON inbox_messages FOR EACH ROW
             WHEN NEW.status NOT IN ('fetched','parsed','skipped','unmatched')
             BEGIN SELECT RAISE(ABORT, 'Invalid inbox_messages.status value'); END;
CREATE TABLE IF NOT EXISTS "known_senders"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "email_pattern" varchar not null,
  "label" varchar not null,
  "source" varchar not null default 'user',
  "added_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "known_senders_user_id_index" on "known_senders"("user_id");
CREATE INDEX "known_senders_source_index" on "known_senders"("source");
CREATE UNIQUE INDEX "known_senders_user_id_email_pattern_unique" on "known_senders"(
  "user_id",
  "email_pattern"
);
CREATE TRIGGER known_senders_source_check_insert BEFORE INSERT ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN ('system','user')
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END;
CREATE TRIGGER known_senders_source_check_update BEFORE UPDATE OF source ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN ('system','user')
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END;
CREATE TABLE IF NOT EXISTS "discovered_senders"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "inbox_id" integer not null,
  "sender_email" varchar not null,
  "sender_name" varchar,
  "occurrence_count" integer not null default '1',
  "last_seen_at" datetime not null,
  "sample_message_id" integer,
  "state" varchar not null default 'candidate',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("inbox_id") references "inboxes"("id") on delete cascade,
  foreign key("sample_message_id") references "inbox_messages"("id") on delete set null
);
CREATE UNIQUE INDEX "discovered_senders_user_id_inbox_id_sender_email_unique" on "discovered_senders"(
  "user_id",
  "inbox_id",
  "sender_email"
);
CREATE INDEX "discovered_senders_user_id_state_index" on "discovered_senders"(
  "user_id",
  "state"
);
CREATE INDEX "discovered_senders_user_id_occurrence_count_index" on "discovered_senders"(
  "user_id",
  "occurrence_count"
);
CREATE TRIGGER discovered_senders_state_check_insert BEFORE INSERT ON discovered_senders FOR EACH ROW
             WHEN NEW.state NOT IN ('candidate','added','dismissed')
             BEGIN SELECT RAISE(ABORT, 'Invalid discovered_senders.state value'); END;
CREATE TRIGGER discovered_senders_state_check_update BEFORE UPDATE OF state ON discovered_senders FOR EACH ROW
             WHEN NEW.state NOT IN ('candidate','added','dismissed')
             BEGIN SELECT RAISE(ABORT, 'Invalid discovered_senders.state value'); END;
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "file_imports"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "source_kind" varchar not null,
  "source_filename" varchar not null,
  "provider_message_id" varchar not null,
  "internal_date" datetime not null,
  "sender_email" varchar not null,
  "sender_name" varchar,
  "subject" varchar,
  "eml_path" varchar not null,
  "status" varchar not null default 'fetched',
  "fetched_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "matcher_key" varchar,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "file_imports_user_id_provider_message_id_unique" on "file_imports"(
  "user_id",
  "provider_message_id"
);
CREATE INDEX "file_imports_user_id_status_index" on "file_imports"(
  "user_id",
  "status"
);
CREATE INDEX "file_imports_user_id_internal_date_index" on "file_imports"(
  "user_id",
  "internal_date"
);
CREATE TRIGGER file_imports_status_check_insert BEFORE INSERT ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN ('fetched','parsed','skipped','unmatched')
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END;
CREATE TRIGGER file_imports_status_check_update BEFORE UPDATE OF status ON file_imports FOR EACH ROW
             WHEN NEW.status NOT IN ('fetched','parsed','skipped','unmatched')
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.status value'); END;
CREATE TRIGGER file_imports_source_kind_check_insert BEFORE INSERT ON file_imports FOR EACH ROW
             WHEN NEW.source_kind NOT IN ('eml','mbox')
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.source_kind value'); END;
CREATE TRIGGER file_imports_source_kind_check_update BEFORE UPDATE OF source_kind ON file_imports FOR EACH ROW
             WHEN NEW.source_kind NOT IN ('eml','mbox')
             BEGIN SELECT RAISE(ABORT, 'Invalid file_imports.source_kind value'); END;
CREATE INDEX "inbox_messages_inbox_id_matcher_key_index" on "inbox_messages"(
  "inbox_id",
  "matcher_key"
);
CREATE TABLE IF NOT EXISTS "categorization_rules"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "field" varchar not null,
  "match" varchar not null,
  "value" varchar not null,
  "category_id" integer not null,
  "hits_count" integer not null default '0',
  "active" tinyint(1) not null default '1',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("category_id") references "categories"("id") on delete cascade
);
CREATE UNIQUE INDEX "categorization_rules_user_id_field_match_value_unique" on "categorization_rules"(
  "user_id",
  "field",
  "match",
  "value"
);
CREATE INDEX "categorization_rules_user_id_active_index" on "categorization_rules"(
  "user_id",
  "active"
);
CREATE TRIGGER categorization_rules_field_check_insert BEFORE INSERT ON categorization_rules FOR EACH ROW
             WHEN NEW.field NOT IN ('merchant','description','counterparty')
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.field value'); END;
CREATE TRIGGER categorization_rules_field_check_update BEFORE UPDATE OF field ON categorization_rules FOR EACH ROW
             WHEN NEW.field NOT IN ('merchant','description','counterparty')
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.field value'); END;
CREATE TRIGGER categorization_rules_match_check_insert BEFORE INSERT ON categorization_rules FOR EACH ROW
             WHEN NEW.match NOT IN ('contains','equals','starts_with')
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.match value'); END;
CREATE TRIGGER categorization_rules_match_check_update BEFORE UPDATE OF match ON categorization_rules FOR EACH ROW
             WHEN NEW.match NOT IN ('contains','equals','starts_with')
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.match value'); END;
CREATE TABLE IF NOT EXISTS "pending_enrichment_conflicts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "transaction_id" integer not null,
  "field_name" varchar not null,
  "stored_value" text,
  "incoming_value" text,
  "incoming_source_format" varchar not null,
  "import_run_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("transaction_id") references "transactions"("id") on delete cascade,
  foreign key("import_run_id") references "import_runs"("id") on delete set null
);
CREATE UNIQUE INDEX "pending_enrichment_conflicts_user_id_transaction_id_field_name_unique" on "pending_enrichment_conflicts"(
  "user_id",
  "transaction_id",
  "field_name"
);
CREATE INDEX "pending_enrichment_conflicts_user_id_created_at_index" on "pending_enrichment_conflicts"(
  "user_id",
  "created_at"
);
CREATE TRIGGER chain_links_kind_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN ('paypal_funding','ics_bulk_settle','funded_by_card_hint','refund_of_hint')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END;
CREATE TRIGGER chain_links_kind_check_update BEFORE UPDATE OF kind ON chain_links FOR EACH ROW
             WHEN NEW.kind NOT IN ('paypal_funding','ics_bulk_settle','funded_by_card_hint','refund_of_hint')
             BEGIN SELECT RAISE(ABORT, 'Invalid chain_links.kind value'); END;
CREATE TRIGGER chain_links_to_transaction_id_check_insert BEFORE INSERT ON chain_links FOR EACH ROW
             WHEN NEW.to_transaction_id IS NULL AND NOT (NEW.state = 'candidate' AND NEW.kind = 'ics_bulk_settle' AND json_extract(NEW.evidence, '$.tolerance_used') = 'exceeded') AND NOT (NEW.state = 'candidate' AND NEW.kind IN ('funded_by_card_hint','refund_of_hint'))
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates or candidate hint rows'); END;
CREATE TRIGGER chain_links_to_transaction_id_check_update BEFORE UPDATE ON chain_links FOR EACH ROW
             WHEN NEW.to_transaction_id IS NULL AND NOT (NEW.state = 'candidate' AND NEW.kind = 'ics_bulk_settle' AND json_extract(NEW.evidence, '$.tolerance_used') = 'exceeded') AND NOT (NEW.state = 'candidate' AND NEW.kind IN ('funded_by_card_hint','refund_of_hint'))
             BEGIN SELECT RAISE(ABORT, 'chain_links.to_transaction_id may only be NULL for exceeded-tolerance ics_bulk_settle candidates or candidate hint rows'); END;
CREATE INDEX "file_imports_user_id_matcher_key_index" on "file_imports"(
  "user_id",
  "matcher_key"
);
CREATE TABLE IF NOT EXISTS "recurring_series"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "direction" varchar check("direction" in('expense', 'income')) not null,
  "detected_name" varchar not null,
  "display_name_override" varchar,
  "state" varchar not null default 'pending',
  "cadence" varchar not null default 'irregular',
  "latest_amount_minor" integer not null,
  "latest_currency" varchar not null,
  "latest_fx_rate_used" varchar,
  "monthly_equivalent_minor" integer,
  "variance_tolerance_percent" integer not null default '25',
  "latest_funding_chain_link_id" integer,
  "snoozed_until" datetime,
  "next_expected_at" date,
  "next_expected_confidence_low" tinyint(1) not null default '0',
  "cluster_key" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "cluster_counterparty_key" varchar,
  "drift_threshold_percent" integer,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("latest_funding_chain_link_id") references "chain_links"("id") on delete set null
);
CREATE UNIQUE INDEX "rec_series_uniq" on "recurring_series"(
  "user_id",
  "direction",
  "cluster_key",
  "latest_currency"
);
CREATE INDEX "recurring_series_user_id_state_index" on "recurring_series"(
  "user_id",
  "state"
);
CREATE INDEX "recurring_series_user_id_state_next_expected_at_index" on "recurring_series"(
  "user_id",
  "state",
  "next_expected_at"
);
CREATE TRIGGER recurring_series_state_check_insert BEFORE INSERT ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN ('pending','approved','rejected','snoozed','cadence_changed')
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END;
CREATE TRIGGER recurring_series_state_check_update BEFORE UPDATE OF state ON recurring_series FOR EACH ROW
             WHEN NEW.state NOT IN ('pending','approved','rejected','snoozed','cadence_changed')
             BEGIN SELECT RAISE(ABORT, 'Invalid recurring_series.state value'); END;
CREATE TABLE IF NOT EXISTS "recurring_series_occurrences"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "recurring_series_id" integer not null,
  "transaction_id" integer not null,
  "observed_at" date not null,
  "observed_amount_minor" integer not null,
  "observed_currency" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("recurring_series_id") references "recurring_series"("id") on delete cascade,
  foreign key("transaction_id") references "transactions"("id") on delete cascade
);
CREATE UNIQUE INDEX "rec_occ_uniq" on "recurring_series_occurrences"(
  "recurring_series_id",
  "transaction_id"
);
CREATE INDEX "recurring_series_occurrences_recurring_series_id_observed_at_index" on "recurring_series_occurrences"(
  "recurring_series_id",
  "observed_at"
);
CREATE TABLE IF NOT EXISTS "recurring_series_transitions"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "recurring_series_id" integer not null,
  "from_state" varchar not null,
  "to_state" varchar not null,
  "transition_reason" varchar not null,
  "actor" varchar not null,
  "transitioned_at" datetime not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("recurring_series_id") references "recurring_series"("id") on delete cascade
);
CREATE INDEX "recurring_series_transitions_recurring_series_id_transitioned_at_index" on "recurring_series_transitions"(
  "recurring_series_id",
  "transitioned_at"
);
CREATE TABLE IF NOT EXISTS "user_recovery_codes"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "code_hash" varchar not null,
  "used_at" datetime,
  "created_at" datetime not null default CURRENT_TIMESTAMP,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "user_recovery_codes_user_id_index" on "user_recovery_codes"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "oauth_secrets"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "provider" varchar not null,
  "client_id" varchar not null,
  "client_secret" text not null,
  "redirect_uri" varchar not null,
  "tokens_blob" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "oauth_secrets_user_id_provider_unique" on "oauth_secrets"(
  "user_id",
  "provider"
);
CREATE TRIGGER oauth_secrets_provider_check_insert BEFORE INSERT ON oauth_secrets FOR EACH ROW
             WHEN NEW.provider NOT IN ('gmail','microsoft')
             BEGIN SELECT RAISE(ABORT, 'Invalid oauth_secrets.provider value'); END;
CREATE TRIGGER oauth_secrets_provider_check_update BEFORE UPDATE OF provider ON oauth_secrets FOR EACH ROW
             WHEN NEW.provider NOT IN ('gmail','microsoft')
             BEGIN SELECT RAISE(ABORT, 'Invalid oauth_secrets.provider value'); END;
CREATE INDEX "rec_series_cluster_cp_key_idx" on "recurring_series"(
  "user_id",
  "direction",
  "cluster_counterparty_key",
  "latest_currency"
);
CREATE TABLE IF NOT EXISTS "drift_alerts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "recurring_series_id" integer not null,
  "state" varchar not null default 'open',
  "direction" varchar check("direction" in('expense', 'income')) not null,
  "baseline_amount_minor" integer not null,
  "latest_amount_minor" integer not null,
  "currency" varchar not null,
  "delta_minor" integer not null,
  "annualized_impact_minor" integer not null,
  "threshold_percent_used" integer not null,
  "threshold_source" varchar not null,
  "latest_occurrence_id" integer not null,
  "snoozed_until" datetime,
  "detected_at" datetime not null,
  "actioned_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("recurring_series_id") references "recurring_series"("id") on delete cascade,
  foreign key("latest_occurrence_id") references "recurring_series_occurrences"("id") on delete cascade
);
CREATE UNIQUE INDEX "drift_alerts_uniq" on "drift_alerts"(
  "recurring_series_id",
  "latest_occurrence_id"
);
CREATE INDEX "drift_alerts_user_id_state_index" on "drift_alerts"(
  "user_id",
  "state"
);
CREATE INDEX "drift_alerts_user_id_state_detected_at_index" on "drift_alerts"(
  "user_id",
  "state",
  "detected_at"
);
CREATE INDEX "drift_alerts_user_id_recurring_series_id_state_index" on "drift_alerts"(
  "user_id",
  "recurring_series_id",
  "state"
);
CREATE TRIGGER drift_alerts_state_check_insert BEFORE INSERT ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN ('open','acknowledged','snoozed','dismissed_cancelled')
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END;
CREATE TRIGGER drift_alerts_state_check_update BEFORE UPDATE OF state ON drift_alerts FOR EACH ROW
             WHEN NEW.state NOT IN ('open','acknowledged','snoozed','dismissed_cancelled')
             BEGIN SELECT RAISE(ABORT, 'Invalid drift_alerts.state value'); END;
CREATE TABLE IF NOT EXISTS "forecast_scenarios"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "name" varchar not null,
  "description" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "forecast_scenarios_user_id_name_unique" on "forecast_scenarios"(
  "user_id",
  "name"
);
CREATE INDEX "forecast_scenarios_user_id_created_at_index" on "forecast_scenarios"(
  "user_id",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "drift_alert_transitions"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "drift_alert_id" integer not null,
  "from_state" varchar not null,
  "to_state" varchar not null,
  "transition_reason" varchar not null,
  "actor" varchar not null,
  "transitioned_at" datetime not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("drift_alert_id") references "drift_alerts"("id") on delete cascade
);
CREATE INDEX "drift_alert_transitions_drift_alert_id_transitioned_at_index" on "drift_alert_transitions"(
  "drift_alert_id",
  "transitioned_at"
);
CREATE TABLE IF NOT EXISTS "forecast_scenario_mutations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "forecast_scenario_id" integer not null,
  "kind" varchar not null,
  "target_series_id" integer,
  "payload" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("forecast_scenario_id") references "forecast_scenarios"("id") on delete cascade
);
CREATE INDEX "forecast_scenario_mutations_user_id_forecast_scenario_id_index" on "forecast_scenario_mutations"(
  "user_id",
  "forecast_scenario_id"
);
CREATE INDEX "forecast_scenario_mutations_kind_index" on "forecast_scenario_mutations"(
  "kind"
);
CREATE TRIGGER forecast_scenario_mutations_kind_insert_check
    BEFORE INSERT ON forecast_scenario_mutations
    FOR EACH ROW
    WHEN NEW.kind NOT IN ('cancel_series', 'add_one_off', 'add_recurring', 'change_series_amount', 'shift_series_date')
    BEGIN
        SELECT RAISE(ABORT, 'forecast_scenario_mutations.kind must be one of: cancel_series, add_one_off, add_recurring, change_series_amount, shift_series_date');
    END;
CREATE TRIGGER forecast_scenario_mutations_kind_update_check
    BEFORE UPDATE OF kind ON forecast_scenario_mutations
    FOR EACH ROW
    WHEN NEW.kind NOT IN ('cancel_series', 'add_one_off', 'add_recurring', 'change_series_amount', 'shift_series_date')
    BEGIN
        SELECT RAISE(ABORT, 'forecast_scenario_mutations.kind must be one of: cancel_series, add_one_off, add_recurring, change_series_amount, shift_series_date');
    END;
CREATE TABLE IF NOT EXISTS "forecast_shortfall_windows"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "account_id" integer not null,
  "scenario_id" integer,
  "starts_at" date not null,
  "ends_at" date not null,
  "lowest_balance_minor" integer not null,
  "currency" varchar not null,
  "buffer_used_minor" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("account_id") references "accounts"("id") on delete cascade,
  foreign key("scenario_id") references "forecast_scenarios"("id") on delete cascade
);
CREATE INDEX "forecast_shortfall_windows_user_id_account_id_starts_at_index" on "forecast_shortfall_windows"(
  "user_id",
  "account_id",
  "starts_at"
);
CREATE INDEX "forecast_shortfall_windows_user_id_scenario_id_index" on "forecast_shortfall_windows"(
  "user_id",
  "scenario_id"
);
CREATE INDEX "forecast_shortfall_windows_user_id_ends_at_index" on "forecast_shortfall_windows"(
  "user_id",
  "ends_at"
);
CREATE TABLE IF NOT EXISTS "forecast_runs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "scenario_id" integer,
  "horizon_days" integer not null,
  "started_at" datetime,
  "completed_at" datetime,
  "status" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "result_json" text,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("scenario_id") references "forecast_scenarios"("id") on delete cascade
);
CREATE INDEX "forecast_runs_user_id_scenario_id_horizon_days_status_index" on "forecast_runs"(
  "user_id",
  "scenario_id",
  "horizon_days",
  "status"
);
CREATE INDEX "forecast_runs_user_id_started_at_index" on "forecast_runs"(
  "user_id",
  "started_at"
);
CREATE UNIQUE INDEX "user_recovery_codes_code_hash_unique" on "user_recovery_codes"(
  "code_hash"
);
CREATE TABLE IF NOT EXISTS "system_alerts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "kind" varchar not null,
  "severity" varchar not null,
  "message" text not null,
  "metadata" text,
  "created_at" datetime not null default CURRENT_TIMESTAMP,
  "acknowledged_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "system_alerts_user_id_acknowledged_at_index" on "system_alerts"(
  "user_id",
  "acknowledged_at"
);
CREATE INDEX "system_alerts_kind_acknowledged_at_index" on "system_alerts"(
  "kind",
  "acknowledged_at"
);
CREATE TRIGGER system_alerts_severity_check_insert BEFORE INSERT ON system_alerts FOR EACH ROW
    WHEN NEW.severity NOT IN ('info','warning','critical')
    BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END;
CREATE TRIGGER system_alerts_severity_check_update BEFORE UPDATE OF severity ON system_alerts FOR EACH ROW
    WHEN NEW.severity NOT IN ('info','warning','critical')
    BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END;
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "dev_mode_audit"(
  "id" integer primary key autoincrement not null,
  "log_name" varchar,
  "description" text not null,
  "subject_type" varchar,
  "subject_id" integer,
  "event" varchar,
  "causer_type" varchar,
  "causer_id" integer,
  "attribute_changes" text,
  "properties" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "subject" on "dev_mode_audit"("subject_type", "subject_id");
CREATE INDEX "causer" on "dev_mode_audit"("causer_type", "causer_id");
CREATE INDEX "dev_mode_audit_log_name_index" on "dev_mode_audit"("log_name");
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "password" varchar not null,
  "period_start_day" integer not null default('1'),
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "default_currency_view" varchar not null default('eur_only'),
  "receipt_conflict_resolution" varchar not null default('unset'),
  "auto_import_drop_folder" tinyint(1) not null default('0'),
  "recurring_detection_window_months" integer not null default '2',
  "recurring_income_min_amount_minor" integer not null default('200000'),
  "username" varchar not null,
  "is_developer" tinyint(1) not null default('0'),
  "force_password_change_at_next_login" tinyint(1) not null default('0'),
  "drift_alert_threshold_percent" integer not null default('5'),
  "theme" varchar not null default('system'),
  "close_behavior" varchar,
  "community_settings" text,
  "base_currency" varchar,
  "fx_online_enabled" tinyint(1) not null default '0',
  "tax_country_code" varchar,
  "anomaly_sensitivity_percent" integer not null default '50',
  "anomaly_min_amount_minor" integer not null default '1000',
  "anomaly_backfilled_at" datetime
);
CREATE UNIQUE INDEX "users_username_unique" on "users"("username");
CREATE TABLE IF NOT EXISTS "wizard_progress"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "step_key" varchar not null,
  "status" varchar not null,
  "data" text,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "wizard_progress_user_id_step_key_unique" on "wizard_progress"(
  "user_id",
  "step_key"
);
CREATE INDEX "wizard_progress_user_id_status_index" on "wizard_progress"(
  "user_id",
  "status"
);
CREATE TRIGGER wizard_progress_status_check_insert BEFORE INSERT ON wizard_progress FOR EACH ROW
    WHEN NEW.status NOT IN ('pending','in_progress','done','skipped')
    BEGIN SELECT RAISE(ABORT, 'Invalid wizard_progress.status value'); END;
CREATE TRIGGER wizard_progress_status_check_update BEFORE UPDATE OF status ON wizard_progress FOR EACH ROW
    WHEN NEW.status NOT IN ('pending','in_progress','done','skipped')
    BEGIN SELECT RAISE(ABORT, 'Invalid wizard_progress.status value'); END;
CREATE TABLE IF NOT EXISTS "community_merchant_mappings"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "pattern" varchar not null,
  "generalized_pattern" varchar,
  "name" varchar not null,
  "category" varchar,
  "region" varchar,
  "contributor" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "community_merchant_mappings_user_id_pattern_unique" on "community_merchant_mappings"(
  "user_id",
  "pattern"
);
CREATE INDEX "community_merchant_mappings_generalized_pattern_index" on "community_merchant_mappings"(
  "generalized_pattern"
);
CREATE UNIQUE INDEX community_merchant_mappings_global_pattern_uq ON community_merchant_mappings(
  pattern
) WHERE user_id IS NULL;
CREATE INDEX "transactions_user_id_payment_type_index" on "transactions"(
  "user_id",
  "payment_type"
);
CREATE TRIGGER transactions_payment_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
    WHEN NEW.payment_type NOT IN ('pin','online','transfer','direct_debit','cash','fee','refund','unknown')
    BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END;
CREATE TRIGGER transactions_payment_type_check_update BEFORE UPDATE OF payment_type ON transactions FOR EACH ROW
    WHEN NEW.payment_type NOT IN ('pin','online','transfer','direct_debit','cash','fee','refund','unknown')
    BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END;
CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN ('expense','income','transfer_out','transfer_in','fee','refund','adjustment')
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END;
CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN ('expense','income','transfer_out','transfer_in','fee','refund','adjustment')
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END;
CREATE TABLE IF NOT EXISTS "merchant_aliases"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "pattern" varchar not null,
  "generalized_pattern" varchar not null,
  "friendly_name" varchar not null,
  "merged_from" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "merchant_aliases_user_id_pattern_unique" on "merchant_aliases"(
  "user_id",
  "pattern"
);
CREATE INDEX "merchant_aliases_user_id_generalized_pattern_index" on "merchant_aliases"(
  "user_id",
  "generalized_pattern"
);
CREATE TABLE IF NOT EXISTS "user_preferences"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "counterparty_index_view" varchar not null default 'cards',
  "skipped_update_versions" text not null default '[]', "calendar_entries_accounts" text,
  "calendar_balance_accounts" text,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "user_preferences_user_id_unique" on "user_preferences"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "known_counterparty_ibans"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "real_iban" varchar not null,
  "target_account_kind" varchar not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "known_counterparty_ibans_user_id_real_iban_unique" on "known_counterparty_ibans"(
  "user_id",
  "real_iban"
);
CREATE INDEX "known_counterparty_ibans_real_iban_index" on "known_counterparty_ibans"(
  "real_iban"
);
CREATE TRIGGER known_counterparty_ibans_target_account_kind_check_insert BEFORE INSERT ON known_counterparty_ibans FOR EACH ROW
             WHEN NEW.target_account_kind NOT IN ('bank','ics_card','paypal')
             BEGIN SELECT RAISE(ABORT, 'Invalid known_counterparty_ibans.target_account_kind value'); END;
CREATE TRIGGER known_counterparty_ibans_target_account_kind_check_update BEFORE UPDATE OF target_account_kind ON known_counterparty_ibans FOR EACH ROW
             WHEN NEW.target_account_kind NOT IN ('bank','ics_card','paypal')
             BEGIN SELECT RAISE(ABORT, 'Invalid known_counterparty_ibans.target_account_kind value'); END;
CREATE TABLE IF NOT EXISTS "counterparties"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "type" varchar not null,
  "slug" varchar not null,
  "display_name" varchar not null,
  "iban" varchar,
  "merchant_name" varchar,
  "metadata" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "counterparties_user_id_slug_unique" on "counterparties"(
  "user_id",
  "slug"
);
CREATE INDEX "counterparties_user_id_type_index" on "counterparties"(
  "user_id",
  "type"
);
CREATE TRIGGER counterparties_type_check_insert BEFORE INSERT ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN ('merchant','personal','bank','government','self_account','unknown')
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END;
CREATE TRIGGER counterparties_type_check_update BEFORE UPDATE OF type ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN ('merchant','personal','bank','government','self_account','unknown')
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END;
CREATE INDEX "transactions_user_id_counterparty_id_index" on "transactions"(
  "user_id",
  "counterparty_id"
);
CREATE TRIGGER users_receipt_conflict_resolution_check_insert BEFORE INSERT ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN ('unset','prefer_receipt','prefer_first_write')
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END;
CREATE TRIGGER users_receipt_conflict_resolution_check_update BEFORE UPDATE OF receipt_conflict_resolution ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN ('unset','prefer_receipt','prefer_first_write')
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END;
CREATE TABLE IF NOT EXISTS "category_budgets"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "category_id" integer not null,
  "period_type" varchar not null default 'monthly',
  "budget_minor" integer not null,
  "currency" varchar not null default 'EUR',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("category_id") references "categories"("id") on delete cascade
);
CREATE UNIQUE INDEX "category_budgets_user_category_uniq" on "category_budgets"(
  "user_id",
  "category_id"
);
CREATE TABLE IF NOT EXISTS "savings_insight_dismissals"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "insight_key" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "savings_insight_dismissals_uniq" on "savings_insight_dismissals"(
  "user_id",
  "insight_key"
);
CREATE TABLE IF NOT EXISTS "exchange_rates"(
  "id" integer primary key autoincrement not null,
  "base_currency" varchar not null,
  "quote_currency" varchar not null,
  "rate_date" date not null,
  "rate" numeric not null,
  "source" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "exchange_rates_dated_unique" on "exchange_rates"(
  "base_currency",
  "quote_currency",
  "rate_date",
  "source"
);
CREATE INDEX "exchange_rates_latest_lookup" on "exchange_rates"(
  "base_currency",
  "quote_currency",
  "rate_date"
);
CREATE INDEX "exchange_rates_inverse_lookup" on "exchange_rates"(
  "quote_currency",
  "rate_date"
);
CREATE TABLE IF NOT EXISTS "goals"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "account_id" integer,
  "name" varchar not null,
  "target_minor" integer not null,
  "target_currency" varchar not null default 'EUR',
  "start_date" date not null,
  "target_date" date not null,
  "status" varchar not null default 'active',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("account_id") references "accounts"("id") on delete set null
);
CREATE INDEX "goals_user_id_status_index" on "goals"("user_id", "status");
CREATE TABLE IF NOT EXISTS "pots"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "account_id" integer not null,
  "goal_id" integer,
  "category_id" integer,
  "name" varchar not null,
  "currency" varchar not null,
  "status" varchar not null default 'active',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("account_id") references "accounts"("id") on delete cascade,
  foreign key("goal_id") references "goals"("id") on delete set null,
  foreign key("category_id") references "categories"("id") on delete set null
);
CREATE INDEX "pots_user_id_account_id_status_index" on "pots"(
  "user_id",
  "account_id",
  "status"
);
CREATE TABLE IF NOT EXISTS "pot_movements"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "pot_id" integer not null,
  "counterpart_pot_id" integer,
  "amount_minor" integer not null,
  "currency" varchar not null,
  "kind" varchar not null,
  "memo" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("pot_id") references "pots"("id") on delete cascade,
  foreign key("counterpart_pot_id") references "pots"("id") on delete set null
);
CREATE INDEX "pot_movements_pot_id_index" on "pot_movements"("pot_id");
CREATE INDEX "pot_movements_user_id_pot_id_index" on "pot_movements"(
  "user_id",
  "pot_id"
);
CREATE UNIQUE INDEX pots_active_goal_unique ON pots(
  goal_id
) WHERE goal_id IS NOT NULL AND status = 'active';
CREATE TABLE IF NOT EXISTS "user_app_lock_configs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "pin_hash" varchar,
  "kdf_salt" blob,
  "pin_wrapped_key" text,
  "password_wrapped_key" text,
  "lock_enabled" tinyint(1) not null default '0',
  "idle_timeout_minutes" integer not null default '5',
  "failed_attempts" integer not null default '0',
  "locked_until" datetime,
  "last_activity_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "user_app_lock_configs_user_id_unique" on "user_app_lock_configs"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "user_biometric_credentials"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "credential_id" text not null,
  "device_label" varchar not null,
  "biometric_wrap_secret" blob not null,
  "public_key_cbor" text,
  "counter" integer not null default '0',
  "platform" varchar not null,
  "biometric_failed_count" integer not null default '0',
  "enrolled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "user_biometric_credentials_user_id_index" on "user_biometric_credentials"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "tax_transaction_tags"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "transaction_id" integer not null,
  "deduction_category_id" integer,
  "tax_year_override" integer,
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("transaction_id") references "transactions"("id") on delete cascade,
  foreign key("deduction_category_id") references "tax_deduction_categories"("id") on delete set null
);
CREATE UNIQUE INDEX "tax_transaction_tags_user_id_transaction_id_unique" on "tax_transaction_tags"(
  "user_id",
  "transaction_id"
);
CREATE INDEX "tax_transaction_tags_user_id_deduction_category_id_index" on "tax_transaction_tags"(
  "user_id",
  "deduction_category_id"
);
CREATE TABLE IF NOT EXISTS "tax_deduction_categories"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "name" varchar not null,
  "short_name" varchar,
  "hint" text,
  "corpus_key" varchar,
  "country_code" varchar,
  "status" varchar not null default('active'),
  "sort_order" integer not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references users("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "tax_deduction_categories_user_id_name_unique" on "tax_deduction_categories"(
  "user_id",
  "name"
);
CREATE INDEX "tax_deduction_categories_user_id_status_index" on "tax_deduction_categories"(
  "user_id",
  "status"
);
CREATE TABLE transaction_search_docs(
  transaction_id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL,
  search_body TEXT NOT NULL DEFAULT ''
);
CREATE INDEX tsd_user_id_idx ON transaction_search_docs(user_id);
CREATE VIRTUAL TABLE transaction_search_fts
USING fts5(
  search_body,
  content = 'transaction_search_docs',
  content_rowid = 'transaction_id',
  tokenize = 'trigram'
)
/* transaction_search_fts(
  search_body
) */;
CREATE TABLE IF NOT EXISTS 'transaction_search_fts_data'(id INTEGER PRIMARY KEY, block BLOB);
CREATE TABLE IF NOT EXISTS 'transaction_search_fts_idx'(
  segid,
  term,
  pgno,
  PRIMARY KEY(segid, term)
) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS 'transaction_search_fts_docsize'(id INTEGER PRIMARY KEY, sz BLOB);
CREATE TABLE IF NOT EXISTS 'transaction_search_fts_config'(k PRIMARY KEY, v) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS "anomaly_alerts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "transaction_id" integer not null,
  "state" varchar not null default 'open',
  "direction" varchar check("direction" in('expense', 'income')) not null,
  "reasons" text not null,
  "dismissed_as" varchar,
  "baseline_amount_minor" integer,
  "latest_amount_minor" integer,
  "currency" varchar,
  "sensitivity_percent_used" integer,
  "snoozed_until" datetime,
  "detected_at" datetime,
  "actioned_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("transaction_id") references "transactions"("id") on delete cascade
);
CREATE UNIQUE INDEX "anomaly_alerts_uniq" on "anomaly_alerts"(
  "transaction_id"
);
CREATE INDEX "anomaly_alerts_user_id_state_index" on "anomaly_alerts"(
  "user_id",
  "state"
);
CREATE INDEX "anomaly_alerts_user_id_state_detected_at_index" on "anomaly_alerts"(
  "user_id",
  "state",
  "detected_at"
);
CREATE TRIGGER anomaly_alerts_state_check_insert BEFORE INSERT ON anomaly_alerts FOR EACH ROW
             WHEN NEW.state NOT IN ('open','acknowledged','snoozed','dismissed')
             BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_alerts.state value'); END;
CREATE TRIGGER anomaly_alerts_state_check_update BEFORE UPDATE OF state ON anomaly_alerts FOR EACH ROW
             WHEN NEW.state NOT IN ('open','acknowledged','snoozed','dismissed')
             BEGIN SELECT RAISE(ABORT, 'Invalid anomaly_alerts.state value'); END;
CREATE TABLE IF NOT EXISTS "anomaly_alert_transitions"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "anomaly_alert_id" integer not null,
  "from_state" varchar not null,
  "to_state" varchar not null,
  "transition_reason" varchar not null,
  "actor" varchar not null,
  "transitioned_at" datetime not null,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("anomaly_alert_id") references "anomaly_alerts"("id") on delete cascade
);
CREATE INDEX "anomaly_alert_transitions_anomaly_alert_id_transitioned_at_index" on "anomaly_alert_transitions"(
  "anomaly_alert_id",
  "transitioned_at"
);
CREATE TABLE IF NOT EXISTS "anomaly_suppression_rules"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "counterparty_id" integer,
  "detector" varchar not null,
  "direction" varchar check("direction" in('expense', 'income')) not null,
  "amount_band_low_minor" integer not null,
  "amount_band_high_minor" integer not null,
  "currency" varchar not null,
  "source_anomaly_alert_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("counterparty_id") references "counterparties"("id") on delete set null,
  foreign key("source_anomaly_alert_id") references "anomaly_alerts"("id") on delete set null
);
CREATE INDEX "anomaly_suppression_rules_user_id_counterparty_id_detector_index" on "anomaly_suppression_rules"(
  "user_id",
  "counterparty_id",
  "detector"
);

INSERT INTO migrations VALUES(1,'2026_05_12_000001_create_users_table',1);
INSERT INTO migrations VALUES(2,'2026_05_12_000002_create_password_reset_tokens_table',1);
INSERT INTO migrations VALUES(3,'2026_05_12_000003_create_sessions_table',1);
INSERT INTO migrations VALUES(4,'2026_05_12_010001_create_currencies_table',1);
INSERT INTO migrations VALUES(5,'2026_05_12_010002_create_accounts_table',1);
INSERT INTO migrations VALUES(6,'2026_05_12_010003_create_categories_table',1);
INSERT INTO migrations VALUES(7,'2026_05_12_010004_create_import_runs_table',1);
INSERT INTO migrations VALUES(8,'2026_05_12_010005_create_transactions_table',1);
INSERT INTO migrations VALUES(9,'2026_05_12_010006_create_merchants_table',1);
INSERT INTO migrations VALUES(10,'2026_05_12_010007_create_merchant_memories_table',1);
INSERT INTO migrations VALUES(11,'2026_05_13_010001_add_default_currency_view_to_users',1);
INSERT INTO migrations VALUES(12,'2026_05_13_010001_rederive_fingerprints_to_v3',1);
INSERT INTO migrations VALUES(13,'2026_05_13_010002_add_enriched_from_to_transactions',1);
INSERT INTO migrations VALUES(14,'2026_05_13_010003_add_enriched_count_to_import_runs',1);
INSERT INTO migrations VALUES(15,'2026_05_13_010004_replace_transactions_fingerprint_unique_index',1);
INSERT INTO migrations VALUES(16,'2026_05_13_010005_create_statement_summaries_table',1);
INSERT INTO migrations VALUES(17,'2026_05_15_010001_add_raw_payload_to_transactions',1);
INSERT INTO migrations VALUES(18,'2026_05_15_010002_add_pair_transaction_id_to_transactions',1);
INSERT INTO migrations VALUES(19,'2026_05_16_010001_create_chain_links_table',1);
INSERT INTO migrations VALUES(20,'2026_05_16_010002_create_card_statements_table',1);
INSERT INTO migrations VALUES(21,'2026_05_16_010003_create_card_statement_credits_table',1);
INSERT INTO migrations VALUES(22,'2026_05_16_010004_backpopulate_card_statements_from_statement_summaries',1);
INSERT INTO migrations VALUES(23,'2026_05_16_010005_create_chain_resolution_runs_table',1);
INSERT INTO migrations VALUES(24,'2026_05_16_020001_create_inboxes_table',1);
INSERT INTO migrations VALUES(25,'2026_05_16_020002_create_inbox_scan_state_table',1);
INSERT INTO migrations VALUES(26,'2026_05_16_020003_create_inbox_messages_table',1);
INSERT INTO migrations VALUES(27,'2026_05_16_020004_create_known_senders_table',1);
INSERT INTO migrations VALUES(28,'2026_05_16_020005_create_discovered_senders_table',1);
INSERT INTO migrations VALUES(29,'2026_05_16_174022_create_failed_jobs_table',1);
INSERT INTO migrations VALUES(30,'2026_05_17_010001_create_file_imports_table',1);
INSERT INTO migrations VALUES(31,'2026_05_17_010002_add_matcher_key_to_inbox_messages',1);
INSERT INTO migrations VALUES(32,'2026_05_17_010003_create_categorization_rules_table',1);
INSERT INTO migrations VALUES(33,'2026_05_17_010004_add_receipt_conflict_resolution_to_users',1);
INSERT INTO migrations VALUES(34,'2026_05_17_010005_create_pending_enrichment_conflicts_table',1);
INSERT INTO migrations VALUES(35,'2026_05_17_010006_add_auto_category_provenance_to_transactions',1);
INSERT INTO migrations VALUES(36,'2026_05_17_010006_extend_chain_links_kind_with_hint_variants',1);
INSERT INTO migrations VALUES(37,'2026_05_17_010007_add_auto_import_drop_folder_to_users',1);
INSERT INTO migrations VALUES(38,'2026_05_17_010008_add_matcher_key_to_file_imports',1);
INSERT INTO migrations VALUES(39,'2026_05_17_020001_recreate_transactions_type_triggers',1);
INSERT INTO migrations VALUES(40,'2026_05_18_010001_create_recurring_series_table',1);
INSERT INTO migrations VALUES(41,'2026_05_18_010002_create_recurring_series_occurrences_table',1);
INSERT INTO migrations VALUES(42,'2026_05_18_010003_create_recurring_series_transitions_table',1);
INSERT INTO migrations VALUES(43,'2026_05_18_010004_add_recurring_settings_to_users',1);
INSERT INTO migrations VALUES(44,'2026_05_19_000001_drop_email_add_username_to_users_table',1);
INSERT INTO migrations VALUES(45,'2026_05_19_000002_add_is_developer_to_users_table',1);
INSERT INTO migrations VALUES(46,'2026_05_19_000003_add_force_password_change_to_users_table',1);
INSERT INTO migrations VALUES(47,'2026_05_19_000004_create_user_recovery_codes_table',1);
INSERT INTO migrations VALUES(48,'2026_05_19_000005_create_oauth_secrets_table',1);
INSERT INTO migrations VALUES(49,'2026_05_19_010001_add_cluster_counterparty_key_to_recurring_series',1);
INSERT INTO migrations VALUES(50,'2026_05_19_010001_create_drift_alerts_table',1);
INSERT INTO migrations VALUES(51,'2026_05_19_010001_create_forecast_scenarios_table',1);
INSERT INTO migrations VALUES(52,'2026_05_19_010002_add_drift_threshold_percent_to_recurring_series',1);
INSERT INTO migrations VALUES(53,'2026_05_19_010002_create_drift_alert_transitions_table',1);
INSERT INTO migrations VALUES(54,'2026_05_19_010002_create_forecast_scenario_mutations_table',1);
INSERT INTO migrations VALUES(55,'2026_05_19_010003_add_drift_alert_threshold_percent_to_users',1);
INSERT INTO migrations VALUES(56,'2026_05_19_010003_create_forecast_shortfall_windows_table',1);
INSERT INTO migrations VALUES(57,'2026_05_19_010004_create_forecast_runs_table',1);
INSERT INTO migrations VALUES(58,'2026_05_19_010005_add_forecast_columns_to_accounts',1);
INSERT INTO migrations VALUES(59,'2026_05_19_010006_add_result_json_to_forecast_runs',1);
INSERT INTO migrations VALUES(60,'2026_05_20_000001_add_unique_index_to_user_recovery_codes',1);
INSERT INTO migrations VALUES(61,'2026_05_20_000002_rename_legacy_email_oauth_json',1);
INSERT INTO migrations VALUES(62,'2026_05_20_010001_create_system_alerts_table',1);
INSERT INTO migrations VALUES(63,'2026_05_21_001844_create_cache_table',1);
INSERT INTO migrations VALUES(64,'2026_05_21_001844_create_job_batches_table',1);
INSERT INTO migrations VALUES(65,'2026_05_21_001844_create_jobs_table',1);
INSERT INTO migrations VALUES(66,'2026_05_22_000001_add_theme_to_users',1);
INSERT INTO migrations VALUES(67,'2026_05_22_000002_add_close_behavior_to_users',1);
INSERT INTO migrations VALUES(68,'2026_05_24_000001_create_dev_mode_audit_table',1);
INSERT INTO migrations VALUES(69,'2026_05_24_233044_lower_recurring_detection_window_default_to_two_months',1);
INSERT INTO migrations VALUES(70,'2026_05_26_000001_create_wizard_progress_table',1);
INSERT INTO migrations VALUES(71,'2026_05_26_000002_create_community_merchant_mappings_table',1);
INSERT INTO migrations VALUES(72,'2026_05_26_000003_add_payment_type_to_transactions',1);
INSERT INTO migrations VALUES(73,'2026_05_26_000004_create_merchant_aliases_table',1);
INSERT INTO migrations VALUES(74,'2026_05_26_000005_add_community_settings_to_users',1);
INSERT INTO migrations VALUES(75,'2026_05_27_000001_add_starting_balance_to_accounts_table',1);
INSERT INTO migrations VALUES(76,'2026_05_27_000002_backfill_starting_balance_from_statement_summaries',1);
INSERT INTO migrations VALUES(77,'2026_05_27_000003_create_user_preferences_table',1);
INSERT INTO migrations VALUES(78,'2026_05_27_010001_create_known_counterparty_ibans_table',1);
INSERT INTO migrations VALUES(79,'2026_05_27_020001_create_counterparties_table',1);
INSERT INTO migrations VALUES(80,'2026_05_27_020002_add_counterparty_id_to_transactions',1);
INSERT INTO migrations VALUES(81,'2026_05_28_000001_add_counterparty_index_view_to_user_preferences',1);
INSERT INTO migrations VALUES(82,'2026_05_28_000001_add_skipped_update_versions_to_user_preferences',1);
INSERT INTO migrations VALUES(83,'2026_05_28_010001_restore_users_receipt_conflict_resolution_triggers',1);
INSERT INTO migrations VALUES(84,'2026_06_06_000001_rename_generic_source_formats',1);
INSERT INTO migrations VALUES(85,'2026_06_07_000001_create_category_budgets_table',1);
INSERT INTO migrations VALUES(86,'2026_06_07_010001_create_savings_insight_dismissals_table',1);
INSERT INTO migrations VALUES(87,'2026_06_08_000001_create_exchange_rates_table',1);
INSERT INTO migrations VALUES(88,'2026_06_08_000001_create_goals_table',1);
INSERT INTO migrations VALUES(89,'2026_06_08_000002_add_base_currency_to_users',1);
INSERT INTO migrations VALUES(90,'2026_06_10_000001_create_pots_table',1);
INSERT INTO migrations VALUES(91,'2026_06_10_000002_create_pot_movements_table',1);
INSERT INTO migrations VALUES(92,'2026_06_10_000003_add_active_goal_unique_index_to_pots',1);
INSERT INTO migrations VALUES(93,'2026_06_11_000001_create_user_app_lock_configs_table',1);
INSERT INTO migrations VALUES(94,'2026_06_11_000002_create_user_biometric_credentials_table',1);
INSERT INTO migrations VALUES(95,'2026_06_12_000001_add_calendar_account_prefs_to_user_preferences',1);
INSERT INTO migrations VALUES(96,'2026_06_12_000001_create_tax_deduction_categories_table',1);
INSERT INTO migrations VALUES(97,'2026_06_12_000002_create_tax_transaction_tags_table',1);
INSERT INTO migrations VALUES(98,'2026_06_12_000003_add_tax_country_code_to_users_table',1);
INSERT INTO migrations VALUES(99,'2026_06_12_000004_alter_tax_deduction_categories_short_name_nullable',1);
INSERT INTO migrations VALUES(100,'2026_06_13_000001_create_transaction_search_docs_fts5_table',1);
INSERT INTO migrations VALUES(101,'2026_06_13_010001_create_anomaly_alerts_table',1);
INSERT INTO migrations VALUES(102,'2026_06_13_010002_create_anomaly_alert_transitions_table',1);
INSERT INTO migrations VALUES(103,'2026_06_13_010003_create_anomaly_suppression_rules_table',1);
INSERT INTO migrations VALUES(104,'2026_06_13_010004_add_anomaly_settings_to_users',1);
