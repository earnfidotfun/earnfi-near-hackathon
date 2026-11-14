<?php
// /wp-content/plugins/growl-fun/includes/class-database.php
class GROWL_Database {

    /** Task columns for status / capacity checks (avoids loading requirements LONGTEXT). */
    public const SQL_TASK_STATUS_CAPACITY = 'task_id, status, completed_count, user_count';

    /** Creator pause/resume and ownership checks. */
    public const SQL_TASK_CREATOR_STATUS = 'task_id, status, creator_wallet';

    /** Creator job update preflight (avoids requirements LONGTEXT). */
    public const SQL_TASK_CREATOR_UPDATE = 'task_id, creator_wallet, status, base_reward, funded_free_usdc, funded_paid_usdc, fee_paid_usdc, fee_waived';

    /** Completion validation with JSON requirements only. */
    public const SQL_TASK_STATUS_REQUIREMENTS = 'task_id, status, requirements';

    /** Dispute / notification context. */
    public const SQL_TASK_DISPUTE_CONTEXT = 'task_id, creator_wallet, action_type';

    public const SQL_USER_WALLET_MUTE = 'user_id, wallet_address, is_muted';

    public const SQL_USER_ID_WALLET = 'user_id, wallet_address';

    public const SQL_COMPLETION_DISPUTE = 'completion_id, task_id, user_id, reward_amount, status, is_disputed';

    public const SQL_COMPLETION_OWNERSHIP = 'completion_id, task_id, user_id';

    public const SQL_AI_AGENT_ID_WALLET = 'agent_id, wallet_address, agent_name, capabilities, bio, avatar_url, identity_provider, identity_reference, said_verified, said_registered';

    public $wpdb;
    public $table_prefix;
    private $query_cache;
    private $cache_enabled;
    private $cache_version = 1;
    private static $session_tuned = false;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix . 'growl_';
        $this->query_cache = [];
        $this->cache_enabled = true;
        
        if (wp_using_ext_object_cache() || (class_exists('GROWL_Redis') && GROWL_Redis::available())) {
            $this->cache_enabled = true;
        }

        $this->maybe_tune_mysql_session();
    }

    /**
     * Run once per request — avoid repeated SET SESSION on every GROWL_Database instance.
     */
    private function maybe_tune_mysql_session() {
        if (self::$session_tuned) {
            return;
        }
        self::$session_tuned = true;
        $this->wpdb->query("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    }

    private function cache_get($key, $group) {
        if (class_exists('GROWL_Cache')) {
            $v = GROWL_Cache::get($key, $group);
            if ($v === null) {
                return false;
            }
            return $this->normalize_db_row($v);
        }
        $v = wp_cache_get($key, $group);
        return $v === false ? false : $this->normalize_db_row($v);
    }

    /**
     * Redis JSON decode may return associative arrays — DB row helpers expect objects.
     */
    private function normalize_db_row($value) {
        if (is_array($value)) {
            return (object) $value;
        }
        return $value;
    }

    private function cache_set($key, $value, $group, $ttl) {
        if (class_exists('GROWL_Cache')) {
            GROWL_Cache::set($key, $value, $group, $ttl);
            return;
        }
        wp_cache_set($key, $value, $group, $ttl);
    }

    private function cache_delete($key, $group) {
        if (class_exists('GROWL_Cache')) {
            GROWL_Cache::delete($key, $group);
            return;
        }
        wp_cache_delete($key, $group);
    }
    
    /**
     * Whether Equity Guard catalog tables exist (staging may deploy code before schema bump).
     */
    public function equity_guard_tables_ready(): bool {
        $table = $this->table_prefix . 'equity_assets';
        $found = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            $table
        ));
        return (int) $found > 0;
    }

    /**
     * Create all plugin tables if Equity Guard tables are missing.
     */
    public function ensure_equity_guard_tables(): void {
        if (!$this->equity_guard_tables_ready()) {
            $this->create_tables();
            return;
        }
        $this->ensure_equity_guard_schema_patches();
    }

    /**
     * Idempotent Equity Guard schema upgrades on existing installs.
     */
    public function ensure_equity_guard_schema_patches(): void {
        if (!$this->equity_guard_tables_ready()) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $p = $this->table_prefix;
        dbDelta("CREATE TABLE {$p}equity_execution_attempts (
            attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_id VARCHAR(32) NOT NULL,
            wallet_address VARCHAR(44) NOT NULL,
            prepared_fingerprint VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'prepared',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (attempt_id),
            KEY idx_receipt (receipt_id),
            KEY idx_wallet (wallet_address)
        ) $charset_collate;");
        dbDelta("CREATE TABLE {$p}equity_audit_events (
            event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(32) NOT NULL,
            receipt_id VARCHAR(32) NULL,
            wallet_address VARCHAR(44) NULL,
            detail_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id),
            KEY idx_type_created (event_type, created_at)
        ) $charset_collate;");
        $idx = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_created_at'",
            $p . 'equity_decisions'
        ));
        if (!(int) $idx) {
            $this->wpdb->query("ALTER TABLE {$p}equity_decisions ADD KEY idx_created_at (created_at)");
        }
        if (!get_option('growl_equity_guard_catalog_sync_migrated_v1')) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!array_key_exists('equity_guard_catalog_sync_enabled', $settings)) {
                $settings['equity_guard_catalog_sync_enabled'] = true;
                update_option('growl_settings', $settings);
            }
            update_option('growl_equity_guard_catalog_sync_migrated_v1', 1);
        }
        $col = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'policy_id'",
            $p . 'equity_receipts'
        ));
        if (!(int) $col) {
            $this->wpdb->query("ALTER TABLE {$p}equity_receipts ADD COLUMN policy_id BIGINT UNSIGNED NULL AFTER wallet_address");
        }
        if (!get_option('growl_equity_guard_enforce_receipt_migrated_v1')) {
            $settings = get_option('growl_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
            if (!array_key_exists('equity_guard_enforce_swap_receipt', $settings)) {
                $settings['equity_guard_enforce_swap_receipt'] = true;
                update_option('growl_settings', $settings);
            }
            update_option('growl_equity_guard_enforce_receipt_migrated_v1', 1, false);
        }
        $this->ensure_equity_guard_sort_columns_v2();
        $this->ensure_equity_verification_backfill_v1();
    }

    /**
     * Persisted sort keys for Equity Guard markets (avoids ORDER BY missing columns).
     */
    private function ensure_equity_guard_sort_columns_v2(): void {
        if (get_option('growl_equity_guard_sort_columns_v2')) {
            return;
        }
        if (!$this->equity_guard_tables_ready()) {
            return;
        }
        $p = $this->table_prefix;
        $table = $p . 'equity_assets';
        foreach (['market_cap_usd' => 'DECIMAL(24,4) NULL', 'gap_pct' => 'DECIMAL(12,4) NULL'] as $col => $def) {
            $has = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $table,
                $col
            ));
            if (!$has) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
            }
        }
        $this->wpdb->query(
            "UPDATE {$table} SET market_cap_usd = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.mcap')), '') AS DECIMAL(24,4))
             WHERE (market_cap_usd IS NULL OR market_cap_usd <= 0)
               AND meta_json IS NOT NULL AND meta_json <> '' AND meta_json LIKE '%\"mcap\"%'"
        );
        $this->wpdb->query(
            "UPDATE {$table} SET gap_pct = ROUND(((onchain_price_usd - reference_price_usd) / reference_price_usd) * 100, 4)
             WHERE reference_price_usd > 0 AND onchain_price_usd > 0
               AND (gap_pct IS NULL)"
        );
        update_option('growl_equity_guard_catalog_version', (string) time(), false);
        update_option('growl_equity_guard_sort_columns_v2', 1, false);
    }

    private function ensure_equity_verification_backfill_v1(): void {
        if (get_option('growl_equity_guard_verification_backfill_v1')) {
            return;
        }
        $p = $this->table_prefix;
        $rows = $this->wpdb->get_results(
            "SELECT a.asset_id, a.mint, a.tier FROM {$p}equity_assets a WHERE a.tier = 'verified'"
        ) ?: [];
        foreach ($rows as $row) {
            $aid = (int) $row->asset_id;
            $has = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}equity_verifications WHERE asset_id = %d",
                $aid
            ));
            if ($has > 0) {
                continue;
            }
            $this->wpdb->insert("{$p}equity_verifications", [
                'asset_id' => $aid,
                'source_slug' => 'backfill',
                'source_type' => 'migration',
                'evidence_url' => 'https://xstocks.com/',
                'payload_json' => wp_json_encode([
                    'source_snapshot_hash' => hash('sha256', (string) $row->mint),
                    'note' => 'backfill_v1',
                ]),
                'checked_at' => current_time('mysql', true),
            ]);
        }
        $unsupported = $this->wpdb->get_results(
            "SELECT a.asset_id FROM {$p}equity_assets a
             LEFT JOIN {$p}equity_verifications v ON v.asset_id = a.asset_id
             WHERE a.tier = 'verified' AND v.verification_id IS NULL"
        ) ?: [];
        foreach ($unsupported as $u) {
            $this->wpdb->update("{$p}equity_assets", ['tier' => 'provisional'], ['asset_id' => (int) $u->asset_id]);
        }
        update_option('growl_equity_guard_verification_backfill_v1', 1, false);
    }

    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $this->wpdb->get_charset_collate();
        
        $tables = [
            'users' => "CREATE TABLE {$this->table_prefix}users (
                user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id BIGINT UNSIGNED NULL,
                wallet_address VARCHAR(44) NULL,
                wallet_type VARCHAR(32) DEFAULT 'unknown',
                email VARCHAR(190) NULL,
                google_sub VARCHAR(64) NULL,
                x_id VARCHAR(32) DEFAULT NULL,
                x_username VARCHAR(15) DEFAULT NULL,
                avatar VARCHAR(255) DEFAULT NULL,
                x_access_token TEXT,
                x_refresh_token TEXT,
                referral_code VARCHAR(10) NULL,
                user_rank ENUM('basic','pro','elite') DEFAULT 'basic',
                verification_status ENUM('pending','approved','rejected') DEFAULT 'approved',
                usd_balance DECIMAL(18,6) DEFAULT 0.0,
                locked_usd_balance DECIMAL(18,6) DEFAULT 0.0,
                dispute_usd_balance DECIMAL(18,6) DEFAULT 0.0,
                negative_balance DECIMAL(18,9) DEFAULT 0.0,
                is_muted TINYINT(1) DEFAULT 0,
                mute_until DATETIME NULL,
                total_earned DECIMAL(18,9) DEFAULT 0.0,
                tasks_completed INT DEFAULT 0,
                reputation_score INT DEFAULT 5,
                last_login_at DATETIME NULL,
                login_count INT DEFAULT 0,
                is_restricted TINYINT(1) DEFAULT 0,
                restricted_at DATETIME NULL,
                restriction_reason TEXT NULL,
                restriction_notice_seen_at DATETIME NULL,
                last_login_ip VARCHAR(45) NULL,
                bonus_rank ENUM('none','silver','gold') DEFAULT 'none',
                bonus_rank_checked_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                UNIQUE KEY wallet_address (wallet_address),
                UNIQUE KEY google_sub (google_sub),
                UNIQUE KEY x_id (x_id),
                UNIQUE KEY referral_code (referral_code),
                KEY idx_rank (user_rank),
                KEY idx_muted (is_muted, mute_until),
                KEY idx_wallet_type (wallet_type),
                KEY idx_email (email),
                KEY idx_verification_status (verification_status),
                KEY idx_login_count (login_count),
                KEY idx_created_at (created_at),
                KEY idx_updated_at (updated_at),
                KEY idx_restricted (is_restricted),
                KEY idx_last_login_ip (last_login_ip)
            ) $charset_collate;",
            
            'tasks' => "CREATE TABLE {$this->table_prefix}tasks (
                task_id CHAR(8) NOT NULL,
                creator_wallet VARCHAR(44) NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                verification_type ENUM('auto','manual','hybrid') DEFAULT 'auto',
                verification_method ENUM('screenshot','link','both','auto', 'manual') DEFAULT 'auto',
                youtube_url VARCHAR(255) NULL,
                x_post_id VARCHAR(255) NULL,
                base_reward DECIMAL(18,9) NOT NULL,
                user_count INT NOT NULL,
                completed_count INT DEFAULT 0,
                lock_duration_hours INT NOT NULL,
                min_rank ENUM('basic','pro','elite') DEFAULT 'basic',
                rank_mix JSON NULL,
                requirements LONGTEXT NULL,
                keywords_statement VARCHAR(50) NULL,
                custom_instructions TEXT NULL,
                auto_approve_hours INT DEFAULT 72,
                required_actions LONGTEXT NULL,
                status ENUM('active','paused','completed','frozen') DEFAULT 'active',
                total_budget DECIMAL(18,9) NOT NULL,
                budget_used DECIMAL(18,9) DEFAULT 0.0,
                frozen_reason TEXT NULL,
                content_cache_key VARCHAR(64) NULL,
                cached_at DATETIME NULL,
                title VARCHAR(100) NULL,
                execution_mode ENUM('human','agent','hybrid') DEFAULT 'hybrid',
                work_mode ENUM('brief','sprint','pitch','prove','bid') NULL,
                execution_tier ENUM('best_effort','verified','sla') DEFAULT 'best_effort',
                creator_type ENUM('human','agent') DEFAULT 'human',
                creator_agent_id VARCHAR(32) NULL,
                min_human_score DECIMAL(3,2) NULL,
                min_agent_score DECIMAL(3,2) NULL,
                token_gate_enabled TINYINT(1) NOT NULL DEFAULT 0,
                token_mint VARCHAR(44) NULL,
                min_token_amount DECIMAL(36,18) NULL,
                min_token_usd DECIMAL(18,9) NULL,
                token_gate_use_usd TINYINT(1) NOT NULL DEFAULT 0,
                require_hold_on_payment TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                PRIMARY KEY (task_id),
                KEY idx_creator (creator_wallet),
                KEY idx_type (action_type),
                KEY idx_verification_type (verification_type),
                KEY idx_status (status),
                KEY idx_content_cache (content_cache_key),
                KEY idx_creator_status (creator_wallet, status),
                KEY idx_budget_used (budget_used),
                KEY idx_created_status (created_at, status),
                KEY idx_completed_count (completed_count)
            ) $charset_collate;",

            'contests' => "CREATE TABLE {$this->table_prefix}contests (
                contest_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id CHAR(8) NOT NULL,
                mode ENUM('standard','contest') DEFAULT 'standard',
                title VARCHAR(100) NULL,
                description TEXT NULL,
                instructions LONGTEXT NULL,
                attachments TEXT NULL,
                deadline DATETIME NOT NULL,
                participants_type ENUM('fixed','unlimited') DEFAULT 'fixed',
                max_participants INT DEFAULT 100,
                winner_count INT NOT NULL DEFAULT 1,
                winner_selection ENUM('manual','random') DEFAULT 'manual',
                winner_selection_mode ENUM('tiered','equal') DEFAULT 'equal',
                prizes TEXT NOT NULL,
                total_prize_pool DECIMAL(18,9) NOT NULL DEFAULT 0.0,
                display_submissions ENUM('yes','no','after_completion') DEFAULT 'after_completion',
                auto_select_winner_hours INT DEFAULT 24,
                status ENUM('active','deadline_reached','winner_selection','completed') DEFAULT 'active',
                winners_selected_at DATETIME NULL,
                winners_announced_at DATETIME NULL,
                verification_method ENUM('screenshot','link','both') DEFAULT 'both',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (contest_id),
                UNIQUE KEY idx_task (task_id),
                KEY idx_deadline (deadline),
                KEY idx_status (status),
                KEY idx_winner_selection (winner_selection, winner_selection_mode),
                KEY idx_participants_type (participants_type)
            ) $charset_collate;",

            'contest_submissions' => "CREATE TABLE {$this->table_prefix}contest_submissions (
                submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                task_id CHAR(8) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                completion_id BIGINT UNSIGNED NOT NULL,
                submission_data LONGTEXT NOT NULL,
                files LONGTEXT NULL,
                status ENUM('submitted','under_review','winner','loser','disqualified') DEFAULT 'submitted',
                rank_position INT NULL,
                prize_amount DECIMAL(18,9) NULL,
                winner_selected_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (submission_id),
                UNIQUE KEY idx_user_contest (user_id, contest_id),
                KEY idx_contest (contest_id),
                KEY idx_task (task_id),
                KEY idx_status (status),
                KEY idx_rank (rank_position),
                KEY idx_completion (completion_id)
            ) $charset_collate;",

            'contest_comments' => "CREATE TABLE {$this->table_prefix}contest_comments (
                comment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                parent_comment_id BIGINT UNSIGNED NULL,
                content TEXT NOT NULL,
                mentions_json LONGTEXT NULL,
                reaction_counts_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (comment_id),
                KEY idx_contest (contest_id),
                KEY idx_user (user_id),
                KEY idx_parent (parent_comment_id),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'contest_comment_reactions' => "CREATE TABLE {$this->table_prefix}contest_comment_reactions (
                reaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                reaction VARCHAR(16) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reaction_id),
                UNIQUE KEY uq_comment_user_reaction (comment_id, user_id, reaction),
                KEY idx_contest_comment (contest_id, comment_id),
                KEY idx_user (user_id)
            ) $charset_collate;",

            'shortlists' => "CREATE TABLE {$this->table_prefix}shortlists (
                shortlist_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                creator_user_id BIGINT UNSIGNED NOT NULL,
                submission_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (shortlist_id),
                UNIQUE KEY idx_creator_submission (creator_user_id, submission_id),
                KEY idx_contest_creator (contest_id, creator_user_id),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'contest_winners' => "CREATE TABLE {$this->table_prefix}contest_winners (
                winner_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                submission_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                rank_position INT NOT NULL,
                prize_amount DECIMAL(18,9) NOT NULL,
                prize_paid TINYINT(1) DEFAULT 0,
                paid_at DATETIME NULL,
                transaction_hash VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (winner_id),
                UNIQUE KEY idx_contest_rank (contest_id, rank_position),
                KEY idx_user (user_id),
                KEY idx_submission (submission_id),
                KEY idx_paid (prize_paid)
            ) $charset_collate;",

            'contest_meta' => "CREATE TABLE {$this->table_prefix}contest_meta (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (meta_id),
                UNIQUE KEY idx_contest_meta (contest_id, meta_key),
                KEY idx_contest_id (contest_id),
                KEY idx_meta_key (meta_key)
            ) $charset_collate;",
            
            'completions' => "CREATE TABLE {$this->table_prefix}completions (
                completion_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id CHAR(8) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                reward_amount DECIMAL(18,9) NOT NULL,
                rank_at_completion ENUM('basic','pro','elite') NOT NULL,
                status ENUM('pending','locked','unlocked','withdrawn','revoked','disputed') DEFAULT 'pending',
                verification_type ENUM('auto','manual','hybrid') DEFAULT 'auto',
                manual_verification_id BIGINT UNSIGNED NULL,
                action_sequence JSON NULL,
                locked_until DATETIME NOT NULL,
                x_action_id VARCHAR(32) NULL,
                youtube_watched_percent INT NULL,
                verification_attempts INT DEFAULT 0,
                last_verified DATETIME NULL,
                is_disputed TINYINT(1) DEFAULT 0,
                penalty_applied TINYINT(1) DEFAULT 0,
                worker_type ENUM('human','agent') DEFAULT 'human',
                worker_agent_id VARCHAR(32) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (completion_id),
                KEY idx_user (user_id),
                KEY idx_task (task_id),
                KEY idx_worker_agent (worker_agent_id),
                KEY idx_status (status),
                KEY idx_verification_type (verification_type),
                KEY idx_manual_verification (manual_verification_id),
                KEY idx_locked (locked_until),
                KEY idx_verification (last_verified),
                KEY idx_user_status (user_id, status),
                KEY idx_task_status (task_id, status),
                KEY idx_created_status (created_at, status),
                KEY idx_locked_until (locked_until, status)
            ) $charset_collate;",

            'manual_verifications' => "CREATE TABLE {$this->table_prefix}manual_verifications (
                verification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                completion_id BIGINT UNSIGNED NOT NULL,
                task_id CHAR(8) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                submitted_data LONGTEXT NOT NULL,
                status ENUM('pending','approved','rejected','appealed') DEFAULT 'pending',
                creator_reviewed_at DATETIME NULL,
                auto_approved_at DATETIME NULL,
                rejection_reason TEXT NULL,
                appeal_reason TEXT NULL,
                appeal_submitted_at DATETIME NULL,
                appeal_resolved_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (verification_id),
                KEY idx_completion (completion_id),
                KEY idx_task_status (task_id, status),
                KEY idx_user_status (user_id, status),
                KEY idx_auto_approve (auto_approved_at, status),
                KEY idx_creator_review (creator_reviewed_at, status),
                KEY idx_status_created (status, created_at)
            ) $charset_collate;",

            'referrals' => "CREATE TABLE {$this->table_prefix}referrals (
                referral_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                referrer_id BIGINT UNSIGNED NOT NULL,
                referee_id BIGINT UNSIGNED NOT NULL,
                referral_code VARCHAR(10) NOT NULL,
                referral_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active','inactive') DEFAULT 'active',
                total_earned DECIMAL(18,9) DEFAULT 0.0,
                PRIMARY KEY (referral_id),
                UNIQUE KEY unique_referee (referee_id),
                KEY idx_referrer (referrer_id),
                KEY idx_code (referral_code),
                KEY idx_status (status)
            ) $charset_collate;",

            'referral_earnings' => "CREATE TABLE {$this->table_prefix}referral_earnings (
                earning_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                referrer_id BIGINT UNSIGNED NOT NULL,
                referee_id BIGINT UNSIGNED NOT NULL,
                job_id CHAR(8) NOT NULL,
                completion_id BIGINT UNSIGNED NULL,
                earned_amount DECIMAL(18,9) NOT NULL,
                earning_type ENUM('standard_commission','leftover_bonus') NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (earning_id),
                KEY idx_referrer (referrer_id),
                KEY idx_referee (referee_id),
                KEY idx_job (job_id),
                KEY idx_completion (completion_id),
                KEY idx_type (earning_type),
                KEY idx_created (created_at)
            ) $charset_collate;",
            
            'disputes' => "CREATE TABLE {$this->table_prefix}disputes (
                dispute_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id CHAR(8) NOT NULL,
                completion_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                creator_wallet VARCHAR(44) NOT NULL,
                reason TEXT NOT NULL,
                evidence LONGTEXT NULL,
                status ENUM('open','investigating','resolved','rejected') DEFAULT 'open',
                resolution ENUM('user_penalized','creator_refunded','no_action') NULL,
                resolution_text TEXT NULL,
                resolved_by VARCHAR(255) NULL,
                penalty_amount DECIMAL(18,9) NULL,
                refund_amount DECIMAL(18,9) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (dispute_id),
                KEY idx_task (task_id),
                KEY idx_user (user_id),
                KEY idx_status (status),
                KEY idx_creator (creator_wallet),
                KEY idx_created_status (created_at, status)
            ) $charset_collate;",

            'dispute_messages' => "CREATE TABLE {$this->table_prefix}dispute_messages (
                message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id BIGINT UNSIGNED NOT NULL,
                sender_type ENUM('admin','user','creator') NOT NULL,
                sender_id VARCHAR(255) NULL,
                message_text TEXT NOT NULL,
                attachments JSON NULL,
                is_system_message TINYINT(1) DEFAULT 0,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (message_id),
                KEY idx_dispute (dispute_id, created_at),
                KEY idx_sender (sender_type, sender_id),
                KEY idx_read (is_read),
                KEY idx_system (is_system_message)
            ) $charset_collate;",

            'dispute_evidence' => "CREATE TABLE {$this->table_prefix}dispute_evidence (
                evidence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id BIGINT UNSIGNED NOT NULL,
                submitted_by ENUM('user','creator','admin') NOT NULL,
                evidence_type VARCHAR(50) NOT NULL,
                evidence_data JSON NOT NULL,
                file_url VARCHAR(500) NULL,
                file_name VARCHAR(255) NULL,
                file_size INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (evidence_id),
                KEY idx_dispute (dispute_id),
                KEY idx_submitter (submitted_by),
                KEY idx_type (evidence_type),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'dispute_meta' => "CREATE TABLE {$this->table_prefix}dispute_meta (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(255) NOT NULL,
                meta_value LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (meta_id),
                UNIQUE KEY idx_dispute_key (dispute_id, meta_key),
                KEY idx_key (meta_key),
                KEY idx_value (meta_value(100)),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'pending_refunds' => "CREATE TABLE {$this->table_prefix}pending_refunds (
                refund_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(18,9) NOT NULL,
                dispute_id BIGINT UNSIGNED NULL,
                processed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (refund_id),
                KEY idx_user (user_id),
                KEY idx_dispute (dispute_id),
                KEY idx_processed (processed_at),
                KEY idx_created (created_at)
            ) $charset_collate;",
            
            'reports' => "CREATE TABLE {$this->table_prefix}reports (
                report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id CHAR(8) NOT NULL,
                reporter_user_id BIGINT UNSIGNED NOT NULL,
                reason ENUM('scam','inappropriate','misleading','other') NOT NULL,
                description TEXT NULL,
                status ENUM('pending','reviewed','action_taken') DEFAULT 'pending',
                admin_notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (report_id),
                UNIQUE KEY unique_task_reporter (task_id, reporter_user_id),
                KEY idx_task (task_id),
                KEY idx_reporter (reporter_user_id),
                KEY idx_status (status),
                KEY idx_created_status (created_at, status)
            ) $charset_collate;",
            
            'audit_log' => "CREATE TABLE {$this->table_prefix}audit_log (
                log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                admin_email VARCHAR(255) NULL,
                action_type VARCHAR(50) NOT NULL,
                object_type VARCHAR(20) NULL,
                object_id BIGINT UNSIGNED NULL,
                details LONGTEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id),
                KEY idx_user (user_id),
                KEY idx_admin (admin_email),
                KEY idx_action (action_type),
                KEY idx_object (object_type, object_id),
                KEY idx_created (created_at),
                KEY idx_ip_address (ip_address),
                KEY idx_action_created (action_type, created_at)
            ) $charset_collate;",

            'notifications' => "CREATE TABLE {$this->table_prefix}notifications (
                notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('info','warning','success','error') DEFAULT 'info',
                category ENUM('system','task','withdrawal','penalty','dispute','report') DEFAULT 'system',
                is_read TINYINT(1) DEFAULT 0,
                action_url VARCHAR(500) NULL,
                created_by VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                PRIMARY KEY (notification_id),
                KEY idx_user (user_id),
                KEY idx_read (is_read),
                KEY idx_category (category),
                KEY idx_created (created_at),
                KEY idx_user_read (user_id, is_read),
                KEY idx_expires (expires_at),
                KEY idx_created_read (created_at, is_read)
            ) $charset_collate;",

            'notification_reads' => "CREATE TABLE {$this->table_prefix}notification_reads (
                read_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                notification_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (read_id),
                UNIQUE KEY unique_notification_user (notification_id, user_id),
                KEY idx_notification (notification_id),
                KEY idx_user (user_id),
                KEY idx_read_at (read_at)
            ) $charset_collate;",

            'payments' => "CREATE TABLE {$this->table_prefix}payments (
                payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                idempotency_key VARCHAR(64) NULL,
                funding_intent_id CHAR(36) NULL,
                from_wallet VARCHAR(44) NOT NULL,
                to_wallet VARCHAR(44) NOT NULL,
                amount DECIMAL(18,9) NOT NULL,
                payment_asset VARCHAR(12) NOT NULL DEFAULT 'USDC',
                source_amount_atomic DECIMAL(36,0) NULL,
                principal_usdc_atomic BIGINT UNSIGNED NULL,
                fee_usdc_atomic BIGINT UNSIGNED NULL,
                fee_bps SMALLINT UNSIGNED NULL,
                staking_usdc_atomic BIGINT UNSIGNED NULL,
                team_usdc_atomic BIGINT UNSIGNED NULL,
                referral_usdc_atomic BIGINT UNSIGNED NULL,
                bonus_usdc_atomic BIGINT UNSIGNED NULL,
                rewards_usdc_atomic BIGINT UNSIGNED NULL,
                distribution_ready TINYINT(1) NOT NULL DEFAULT 1,
                network VARCHAR(20) DEFAULT 'mainnet-beta',
                status ENUM('pending','completed','failed') DEFAULT 'pending',
                transaction_hash VARCHAR(100) NULL,
                reference_id CHAR(8) NULL,
                reference_type VARCHAR(50) NULL,
                distribution_processed TINYINT(1) DEFAULT 0,
                failure_reason TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                PRIMARY KEY (payment_id),
                UNIQUE KEY idx_idempotency (idempotency_key),
                UNIQUE KEY idx_funding_intent (funding_intent_id),
                KEY idx_from_wallet (from_wallet),
                KEY idx_to_wallet (to_wallet),
                KEY idx_status (status),
                KEY idx_reference (reference_type, reference_id),
                KEY idx_distribution (distribution_processed, created_at),
                KEY idx_distribution_ready (distribution_ready, distribution_processed, created_at),
                KEY idx_created (created_at),
                UNIQUE KEY idx_transaction_hash (transaction_hash),
                KEY idx_from_status (from_wallet, status)
            ) $charset_collate;",

            'withdrawals' => "CREATE TABLE {$this->table_prefix}withdrawals (
                withdrawal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                from_wallet VARCHAR(44) NOT NULL,
                to_wallet VARCHAR(44) NOT NULL,
                amount DECIMAL(18,9) NOT NULL,
                gas_fee DECIMAL(18,9) DEFAULT 0.000005,
                net_amount DECIMAL(18,9) DEFAULT 0,
                requires_manual_approval TINYINT(1) DEFAULT 0,
                failure_reason TEXT NULL,
                admin_notes TEXT NULL,
                network VARCHAR(20) DEFAULT 'mainnet-beta',
                status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
                transaction_hash VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (withdrawal_id),
                KEY idx_user (user_id),
                KEY idx_status (status),
                KEY idx_created (created_at),
                KEY idx_status_manual (status, requires_manual_approval),
                KEY idx_user_created (user_id, created_at),
                UNIQUE KEY idx_transaction_hash (transaction_hash),
                KEY idx_user_status (user_id, status)
            ) $charset_collate;",

            'burns' => "CREATE TABLE {$this->table_prefix}burns (
                burn_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                from_wallet VARCHAR(44) NOT NULL,
                amount DECIMAL(18,9) NOT NULL,
                network VARCHAR(20) DEFAULT 'mainnet-beta',
                type ENUM('auto','manual') DEFAULT 'auto',
                status ENUM('processing','completed','failed') DEFAULT 'processing',
                token_address VARCHAR(44) NOT NULL,
                tokens_purchased DECIMAL(18,2) DEFAULT 0,
                tokens_burned DECIMAL(18,2) DEFAULT 0,
                purchase_transaction_hash VARCHAR(100) NULL,
                burn_transaction_hash VARCHAR(100) NULL,
                triggered_by BIGINT UNSIGNED DEFAULT 0,
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (burn_id),
                KEY idx_status (status),
                KEY idx_created (created_at),
                KEY idx_type (type),
                UNIQUE KEY idx_purchase_tx (purchase_transaction_hash),
                UNIQUE KEY idx_burn_tx (burn_transaction_hash)
            ) $charset_collate;",

            'transactions' => "CREATE TABLE {$this->table_prefix}transactions (
                transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                unique_id VARCHAR(64) NOT NULL,
                from_wallet VARCHAR(44) NOT NULL,
                to_wallet VARCHAR(44) NOT NULL,
                amount DECIMAL(18,9) NOT NULL,
                lamports BIGINT UNSIGNED NOT NULL,
                network VARCHAR(20) DEFAULT 'mainnet-beta',
                type VARCHAR(40) NOT NULL,
                status ENUM('pending', 'signed', 'submitted', 'confirmed', 'failed', 'duplicate') DEFAULT 'pending',
                reference_id CHAR(8) NULL,
                reference_type VARCHAR(50) NULL,
                recent_blockhash VARCHAR(88) NOT NULL,
                transaction_data LONGTEXT NOT NULL,
                signed_transaction LONGTEXT NULL,
                transaction_hash VARCHAR(100) NULL,
                failure_reason TEXT NULL,
                retry_count INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                PRIMARY KEY (transaction_id),
                UNIQUE KEY unique_transaction_id (unique_id),
                UNIQUE KEY idx_transaction_hash (transaction_hash),
                KEY idx_from_wallet (from_wallet),
                KEY idx_status (status),
                KEY idx_type (type),
                KEY idx_reference (reference_type, reference_id),
                KEY idx_created (created_at),
                KEY idx_expires (expires_at),
                KEY idx_from_status (from_wallet, status),
                KEY idx_updated_status (updated_at, status),
                KEY idx_retry_count (retry_count)
            ) $charset_collate;",

            'content_cache' => "CREATE TABLE {$this->table_prefix}content_cache (
                cache_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cache_key VARCHAR(64) NOT NULL,
                content_data JSON NOT NULL,
                source_url VARCHAR(500) NOT NULL,
                action_type VARCHAR(20) NOT NULL,
                cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (cache_id),
                UNIQUE KEY cache_key (cache_key),
                KEY idx_expires (expires_at),
                KEY idx_action_type (action_type),
                KEY idx_cached_at (cached_at)
            ) $charset_collate;",

            'video_progress' => "CREATE TABLE {$this->table_prefix}video_progress (
                progress_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                task_id CHAR(8) NOT NULL,
                video_id VARCHAR(20) NOT NULL,
                watch_percentage INT NOT NULL,
                video_time DECIMAL(10,3) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (progress_id),
                KEY idx_user_task (user_id, task_id),
                KEY idx_video (video_id),
                KEY idx_created (created_at),
                KEY idx_user_video (user_id, video_id)
            ) $charset_collate;",

            'verification_queue' => "CREATE TABLE {$this->table_prefix}verification_queue (
                queue_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type ENUM('account','job','multi') NOT NULL DEFAULT 'job',
                completion_id BIGINT UNSIGNED NOT NULL,
                task_id CHAR(8) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                verification_type ENUM('instant','link_required','multi_action','screenshot') NOT NULL,
                completion_data LONGTEXT NOT NULL,
                verification_links JSON NULL,
                screenshots JSON NULL,
                partial_verification JSON NULL,
                partial_result JSON DEFAULT NULL COMMENT 'Stores verified sub-actions',
                status ENUM('queued','processing','completed','failed') DEFAULT 'queued',
                retry_count INT DEFAULT 0,
                max_retries INT DEFAULT 3,
                last_error TEXT NULL,
                priority INT DEFAULT 0,
                queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When job is allowed to run',
                processed_at DATETIME NULL,
                next_retry_at DATETIME NULL,
                PRIMARY KEY (queue_id),
                UNIQUE KEY idx_completion (completion_id),
                KEY idx_status_priority (status, priority, queued_at),
                KEY idx_task_user (task_id, user_id),
                KEY idx_next_retry (next_retry_at, status),
                KEY idx_processing (status, processed_at)
            ) $charset_collate;",

            'performance_log' => "CREATE TABLE {$this->table_prefix}performance_log (
                log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                response_time DECIMAL(10,3) NOT NULL,
                queries_count INT NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                memory_usage INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id),
                KEY idx_response_time (response_time),
                KEY idx_endpoint (endpoint),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'admins' => "CREATE TABLE {$this->table_prefix}admins (
                admin_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'moderator',
                wp_user_id BIGINT UNSIGNED NULL,
                setup_token VARCHAR(64) NULL,
                setup_token_expires DATETIME NULL,
                status ENUM('invited','active','disabled') DEFAULT 'invited',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_id),
                UNIQUE KEY email (email),
                KEY role (role),
                KEY wp_user_id (wp_user_id),
                KEY status (status)
            ) $charset_collate;",

            'email_logs' => "CREATE TABLE {$this->table_prefix}email_logs (
                email_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                to_email VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                heading VARCHAR(500) NOT NULL DEFAULT '',
                body LONGTEXT NOT NULL,
                is_raw_html TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('queued','sending','sent','failed') DEFAULT 'sending',
                error_message TEXT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (email_id),
                KEY idx_status (status),
                KEY idx_created (created_at),
                KEY idx_to_email (to_email),
                KEY idx_status_created (status, created_at)
            ) $charset_collate;",

            'marketing_campaigns' => "CREATE TABLE {$this->table_prefix}marketing_campaigns (
                campaign_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                subject VARCHAR(500) NOT NULL,
                heading VARCHAR(500) NOT NULL DEFAULT '',
                audience VARCHAR(16) NOT NULL DEFAULT 'single',
                target_email VARCHAR(255) NULL,
                html_body LONGTEXT NOT NULL,
                sent_count INT UNSIGNED NOT NULL DEFAULT 0,
                failed_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (campaign_id),
                KEY idx_created (created_at),
                KEY idx_admin (admin_id)
            ) $charset_collate;",

            'admin_notifications' => "CREATE TABLE {$this->table_prefix}admin_notifications (
                notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id BIGINT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('info','warning','success','error') DEFAULT 'info',
                category VARCHAR(64) DEFAULT 'system',
                action_url VARCHAR(500) NULL,
                min_role_level TINYINT UNSIGNED DEFAULT 1 COMMENT '1=moderator+, 2=super_admin only',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (notification_id),
                KEY idx_admin (admin_id),
                KEY idx_created (created_at),
                KEY idx_role_created (min_role_level, created_at)
            ) $charset_collate;",

            'admin_notification_reads' => "CREATE TABLE {$this->table_prefix}admin_notification_reads (
                read_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_id BIGINT UNSIGNED NOT NULL,
                notification_id BIGINT UNSIGNED NOT NULL,
                read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (read_id),
                UNIQUE KEY unique_admin_notification (admin_id, notification_id),
                KEY idx_admin (admin_id),
                KEY idx_notification (notification_id)
            ) $charset_collate;",

            'user_links' => "CREATE TABLE {$this->table_prefix}user_links (
                link_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id_a BIGINT UNSIGNED NOT NULL,
                user_id_b BIGINT UNSIGNED NOT NULL,
                link_type VARCHAR(50) NOT NULL COMMENT 'same_withdrawal, same_ip',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (link_id),
                UNIQUE KEY unique_user_link (user_id_a, user_id_b, link_type),
                KEY idx_user_a (user_id_a),
                KEY idx_user_b (user_id_b),
                KEY idx_link_type (link_type)
            ) $charset_collate;",

            'recover_sol_actions' => "CREATE TABLE {$this->table_prefix}recover_sol_actions (
                action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                wallet_address VARCHAR(44) NOT NULL,
                action_type ENUM('close','burn') NOT NULL,
                accounts_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                sol_recovered_lamports BIGINT UNSIGNED NOT NULL DEFAULT 0,
                platform_fee_lamports BIGINT UNSIGNED NOT NULL DEFAULT 0,
                referrer_commission_lamports BIGINT UNSIGNED NOT NULL DEFAULT 0,
                conversion_markup_lamports BIGINT UNSIGNED NOT NULL DEFAULT 0,
                transaction_hash VARCHAR(88) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (action_id),
                KEY idx_user (user_id),
                KEY idx_wallet (wallet_address),
                KEY idx_created (created_at),
                KEY idx_action_type (action_type)
            ) $charset_collate;",

            'recover_sol_referral_earnings' => "CREATE TABLE {$this->table_prefix}recover_sol_referral_earnings (
                earning_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                referrer_id BIGINT UNSIGNED NOT NULL,
                referee_id BIGINT UNSIGNED NOT NULL,
                action_id BIGINT UNSIGNED NOT NULL,
                earned_lamports BIGINT UNSIGNED NOT NULL DEFAULT 0,
                earned_usd DECIMAL(18,9) NOT NULL DEFAULT 0.0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (earning_id),
                KEY idx_referrer (referrer_id),
                KEY idx_referee (referee_id),
                KEY idx_action (action_id)
            ) $charset_collate;",

            'user_balance_credits' => "CREATE TABLE {$this->table_prefix}user_balance_credits (
                credit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(18,6) NOT NULL,
                earning_type VARCHAR(50) NOT NULL,
                ref_type VARCHAR(50) NULL,
                ref_id VARCHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (credit_id),
                KEY idx_user (user_id),
                KEY idx_created (created_at),
                KEY idx_type (earning_type)
            ) $charset_collate;",

            'earnfi_idempotency' => "CREATE TABLE {$this->table_prefix}earnfi_idempotency (
                idempotency_key VARCHAR(64) NOT NULL,
                resource VARCHAR(128) NOT NULL,
                response_hash VARCHAR(64) NULL,
                response_body LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (idempotency_key),
                KEY idx_resource (resource),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'earnfi_interrupts' => "CREATE TABLE {$this->table_prefix}earnfi_interrupts (
                interrupt_id VARCHAR(16) NOT NULL,
                task_id CHAR(8) NULL,
                source_agent_id VARCHAR(32) NULL,
                question TEXT NOT NULL,
                context LONGTEXT NULL,
                expected_schema LONGTEXT NULL,
                aggregated_answer LONGTEXT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (interrupt_id),
                KEY idx_task (task_id),
                KEY idx_source_agent (source_agent_id),
                KEY idx_status (status)
            ) $charset_collate;",

            'ai_agents' => "CREATE TABLE {$this->table_prefix}ai_agents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_id VARCHAR(32) NOT NULL,
                wallet_address VARCHAR(44) NOT NULL,
                agent_name VARCHAR(120) NOT NULL,
                identity_provider VARCHAR(32) NOT NULL DEFAULT 'wallet',
                identity_reference VARCHAR(255) NULL,
                partner_id BIGINT UNSIGNED NULL,
                capabilities LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ai_agents_wallet (wallet_address),
                UNIQUE KEY uq_ai_agents_agent_id (agent_id),
                KEY idx_updated (updated_at),
                KEY idx_ai_agents_partner (partner_id, updated_at)
            ) $charset_collate;",

            'earnfi_partners' => "CREATE TABLE {$this->table_prefix}earnfi_partners (
                partner_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_slug VARCHAR(64) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                status ENUM('active','paused','disabled') NOT NULL DEFAULT 'active',
                settlement_mode ENUM('direct_x402','proxy_hmac') NOT NULL DEFAULT 'direct_x402',
                identity_provider VARCHAR(32) NOT NULL DEFAULT 'wallet',
                api_key_hash CHAR(64) NULL,
                api_key_prefix VARCHAR(32) NULL,
                api_key_issued_at DATETIME NULL,
                proxy_config_json LONGTEXT NULL,
                metadata_schema_json LONGTEXT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (partner_id),
                UNIQUE KEY uq_partner_slug (partner_slug),
                UNIQUE KEY uq_partner_api_key (api_key_hash),
                KEY idx_partner_status (status)
            ) $charset_collate;",

            // EarnFi Agent API: long-lived agent access tokens for rate limits / reputation controls.
            // Store only a hash; token is shown once on creation.
            'ai_agent_tokens' => "CREATE TABLE {$this->table_prefix}ai_agent_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_id VARCHAR(32) NOT NULL,
                wallet_address VARCHAR(44) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                status ENUM('active','revoked') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_token_hash (token_hash),
                KEY idx_agent (agent_id, status),
                KEY idx_wallet (wallet_address, status)
            ) $charset_collate;",

            // EarnFi Agent API: per-job secrets for stateless polling.
            'ai_job_access' => "CREATE TABLE {$this->table_prefix}ai_job_access (
                job_id CHAR(8) NOT NULL,
                agent_id VARCHAR(32) NULL,
                secret_hash CHAR(64) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (job_id),
                UNIQUE KEY uq_secret_hash (secret_hash),
                KEY idx_agent (agent_id)
            ) $charset_collate;",

            // Staking: user staking positions
            'staking_positions' => "CREATE TABLE {$this->table_prefix}staking_positions (
                position_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                amount_staked DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                lock_tier ENUM('flexible','7_day','14_day','30_day','60_day','90_day','180_day') NOT NULL DEFAULT '90_day',
                lock_multiplier DECIMAL(5,2) NOT NULL DEFAULT '1.00',
                locked_until DATETIME NULL,
                staked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                unstaked_at DATETIME NULL,
                status ENUM('active','unstake_requested','completed') NOT NULL DEFAULT 'active',
                earned_total DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                stake_tx_hash VARCHAR(100) NULL,
                unstake_tx_hash VARCHAR(100) NULL,
                wallet_address VARCHAR(44) NOT NULL DEFAULT '',
                unstake_requested_at DATETIME NULL,
                unstake_epoch_id BIGINT UNSIGNED NULL,
                platform_fee_paid DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                PRIMARY KEY (position_id),
                KEY idx_user_status (user_id, status),
                KEY idx_stake_tx (stake_tx_hash),
                KEY idx_staked_at (staked_at),
                KEY idx_unstake_epoch (unstake_epoch_id, status)
            ) $charset_collate;",

            // Staking: 7-day epochs that accumulate USDT and distribute EARNFI
            'staking_epochs' => "CREATE TABLE {$this->table_prefix}staking_epochs (
                epoch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                epoch_number INT UNSIGNED NOT NULL,
                start_time DATETIME NOT NULL,
                end_time DATETIME NOT NULL,
                reward_pool_usdt DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                reward_pool_earnfi DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                total_staked DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                total_weighted_staked DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                apy_percent DECIMAL(10,4) NOT NULL DEFAULT '0.0000',
                earnfi_price_usd DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                swap_tx_hash VARCHAR(100) NULL,
                status ENUM('active','distributing','completed','failed') NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                PRIMARY KEY (epoch_id),
                UNIQUE KEY uq_epoch_number (epoch_number),
                KEY idx_status (status)
            ) $charset_collate;",

            // Staking: per-user per-epoch reward records
            'staking_rewards' => "CREATE TABLE {$this->table_prefix}staking_rewards (
                reward_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                epoch_id BIGINT UNSIGNED NOT NULL,
                position_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                staked_amount DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                effective_weight DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                weight_share DECIMAL(12,10) NOT NULL DEFAULT '0.0000000000',
                reward_earnfi DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                claimed TINYINT(1) NOT NULL DEFAULT 0,
                claimed_at DATETIME NULL,
                claim_tx_hash VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reward_id),
                KEY idx_user_claimed (user_id, claimed),
                KEY idx_epoch (epoch_id),
                KEY idx_position (position_id)
            ) $charset_collate;",

            // Staking: tracks USDT contributions to each epoch (from platform fees)
            'staking_contributions' => "CREATE TABLE {$this->table_prefix}staking_contributions (
                contribution_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                epoch_id BIGINT UNSIGNED NOT NULL,
                usdt_amount DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                source VARCHAR(50) NOT NULL DEFAULT 'platform_fee',
                contributed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (contribution_id),
                KEY idx_epoch (epoch_id)
            ) $charset_collate;",

            // EarnFi Hub (@EarnFiHubBot) — community subscribers
            'telegram_subscribers' => "CREATE TABLE {$this->table_prefix}telegram_subscribers (
                subscriber_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chat_id VARCHAR(32) NOT NULL,
                chat_type ENUM('group','supergroup','channel','private') NOT NULL DEFAULT 'supergroup',
                chat_title VARCHAR(255) DEFAULT '',
                chat_username VARCHAR(255) DEFAULT '',
                status ENUM('active','paused','removed','pending_setup') NOT NULL DEFAULT 'pending_setup',
                installed_by_tg_user_id BIGINT NULL,
                linked_wallet VARCHAR(44) NULL,
                tier ENUM('free','official','earnfi_holder','partner') NOT NULL DEFAULT 'free',
                filters_json LONGTEXT NULL,
                permissions_json LONGTEXT NULL,
                locale VARCHAR(10) DEFAULT 'en',
                last_post_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (subscriber_id),
                UNIQUE KEY uq_chat_id (chat_id),
                KEY idx_status (status),
                KEY idx_tier (tier)
            ) $charset_collate;",

            'telegram_outbox' => "CREATE TABLE {$this->table_prefix}telegram_outbox (
                outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id VARCHAR(12) NOT NULL,
                subscriber_id BIGINT UNSIGNED NOT NULL,
                payload_json LONGTEXT NULL,
                status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                telegram_message_id BIGINT NULL,
                error_message VARCHAR(500) DEFAULT '',
                scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (outbox_id),
                UNIQUE KEY uq_task_subscriber (task_id, subscriber_id),
                KEY idx_status_scheduled (status, scheduled_at),
                KEY idx_subscriber (subscriber_id)
            ) $charset_collate;",

            'telegram_events' => "CREATE TABLE {$this->table_prefix}telegram_events (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type VARCHAR(50) NOT NULL,
                subscriber_id BIGINT UNSIGNED NULL,
                task_id VARCHAR(12) NULL,
                slot_id BIGINT UNSIGNED NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id),
                KEY idx_event_type (event_type),
                KEY idx_subscriber (subscriber_id),
                KEY idx_created (created_at)
            ) $charset_collate;",

            'telegram_footer_slots' => "CREATE TABLE {$this->table_prefix}telegram_footer_slots (
                slot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_key VARCHAR(64) NOT NULL,
                label VARCHAR(120) NOT NULL,
                button_text VARCHAR(120) NOT NULL,
                target VARCHAR(500) NOT NULL,
                action_type ENUM('url','command','deep_link') NOT NULL DEFAULT 'url',
                weight INT UNSIGNED NOT NULL DEFAULT 100,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                tier ENUM('platform','partner','paid_verified') NOT NULL DEFAULT 'platform',
                impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (slot_id),
                UNIQUE KEY uq_slot_key (slot_key),
                KEY idx_active (is_active)
            ) $charset_collate;",

            'equity_underlyings' => "CREATE TABLE {$this->table_prefix}equity_underlyings (
                underlying_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticker VARCHAR(16) NOT NULL,
                name VARCHAR(128) NOT NULL DEFAULT '',
                reference_symbol VARCHAR(16) NOT NULL DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (underlying_id),
                UNIQUE KEY uq_ticker (ticker)
            ) $charset_collate;",

            'equity_issuers' => "CREATE TABLE {$this->table_prefix}equity_issuers (
                issuer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(128) NOT NULL,
                website VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (issuer_id),
                UNIQUE KEY uq_slug (slug)
            ) $charset_collate;",

            'equity_assets' => "CREATE TABLE {$this->table_prefix}equity_assets (
                asset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                mint VARCHAR(44) NOT NULL,
                symbol VARCHAR(32) NOT NULL,
                underlying_id BIGINT UNSIGNED NOT NULL,
                issuer_id BIGINT UNSIGNED NULL,
                decimals TINYINT UNSIGNED NOT NULL DEFAULT 6,
                tier ENUM('verified','provisional','unverified','suspended') NOT NULL DEFAULT 'unverified',
                status ENUM('active','warning','suspended') NOT NULL DEFAULT 'active',
                structure_type VARCHAR(32) NOT NULL DEFAULT 'tokenized_equity',
                backing_model VARCHAR(32) NOT NULL DEFAULT '1:1',
                logo_url VARCHAR(512) NULL,
                liquidity_usd DECIMAL(18,2) NULL,
                market_cap_usd DECIMAL(24,4) NULL,
                gap_pct DECIMAL(12,4) NULL,
                onchain_price_usd DECIMAL(18,8) NULL,
                reference_price_usd DECIMAL(18,8) NULL,
                price_updated_at DATETIME NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (asset_id),
                UNIQUE KEY uq_mint (mint),
                KEY idx_symbol (symbol),
                KEY idx_underlying (underlying_id),
                KEY idx_tier_status (tier, status)
            ) $charset_collate;",

            'equity_verifications' => "CREATE TABLE {$this->table_prefix}equity_verifications (
                verification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                asset_id BIGINT UNSIGNED NOT NULL,
                source_slug VARCHAR(64) NOT NULL,
                source_type VARCHAR(32) NOT NULL,
                evidence_url VARCHAR(512) NULL,
                payload_json LONGTEXT NULL,
                checked_at DATETIME NOT NULL,
                PRIMARY KEY (verification_id),
                KEY idx_asset (asset_id),
                KEY idx_checked (checked_at)
            ) $charset_collate;",

            'equity_policies' => "CREATE TABLE {$this->table_prefix}equity_policies (
                policy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                wallet_address VARCHAR(44) NULL,
                name VARCHAR(64) NOT NULL DEFAULT 'default',
                rules_json LONGTEXT NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (policy_id),
                KEY idx_user (user_id),
                KEY idx_wallet (wallet_address)
            ) $charset_collate;",

            'equity_decisions' => "CREATE TABLE {$this->table_prefix}equity_decisions (
                decision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                receipt_id VARCHAR(32) NOT NULL,
                mint VARCHAR(44) NOT NULL,
                side ENUM('buy','sell') NOT NULL,
                amount_usd DECIMAL(18,2) NOT NULL,
                wallet_address VARCHAR(44) NULL,
                decision ENUM('ALLOW','WARN','BLOCK') NOT NULL,
                risk_level VARCHAR(16) NOT NULL,
                score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                reason_codes_json LONGTEXT NULL,
                checks_json LONGTEXT NULL,
                latency_ms INT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (decision_id),
                UNIQUE KEY uq_receipt (receipt_id),
                KEY idx_mint_created (mint, created_at),
                KEY idx_wallet (wallet_address)
            ) $charset_collate;",

            'equity_receipts' => "CREATE TABLE {$this->table_prefix}equity_receipts (
                receipt_id VARCHAR(32) NOT NULL,
                decision ENUM('ALLOW','WARN','BLOCK') NOT NULL,
                mint VARCHAR(44) NOT NULL,
                side ENUM('buy','sell') NOT NULL,
                amount_usd DECIMAL(18,2) NOT NULL,
                wallet_address VARCHAR(44) NULL,
                quote_fingerprint VARCHAR(64) NULL,
                payload_json LONGTEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                executed_at DATETIME NULL,
                tx_signature VARCHAR(88) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (receipt_id),
                KEY idx_expires (expires_at),
                KEY idx_wallet (wallet_address)
            ) $charset_collate;",

            'equity_executions' => "CREATE TABLE {$this->table_prefix}equity_executions (
                execution_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                receipt_id VARCHAR(32) NOT NULL,
                tx_signature VARCHAR(88) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (execution_id),
                KEY idx_receipt (receipt_id),
                KEY idx_tx (tx_signature)
            ) $charset_collate;",

            'equity_circuit_breaker_states' => "CREATE TABLE {$this->table_prefix}equity_circuit_breaker_states (
                mint VARCHAR(44) NOT NULL,
                state ENUM('NORMAL','ELEVATED','PAUSED','SUSPENDED') NOT NULL DEFAULT 'NORMAL',
                reason_code VARCHAR(64) NULL,
                resume_conditions_json LONGTEXT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (mint)
            ) $charset_collate;",

            'equity_corporate_actions' => "CREATE TABLE {$this->table_prefix}equity_corporate_actions (
                action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                underlying_id BIGINT UNSIGNED NOT NULL,
                action_type VARCHAR(32) NOT NULL,
                severity ENUM('info','warn','block') NOT NULL DEFAULT 'info',
                title VARCHAR(255) NOT NULL,
                effective_at DATETIME NULL,
                payload_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (action_id),
                KEY idx_underlying (underlying_id),
                KEY idx_effective (effective_at)
            ) $charset_collate;",

            'equity_stats_daily' => "CREATE TABLE {$this->table_prefix}equity_stats_daily (
                stat_date DATE NOT NULL,
                checks_count INT UNSIGNED NOT NULL DEFAULT 0,
                blocks_count INT UNSIGNED NOT NULL DEFAULT 0,
                allows_count INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (stat_date)
            ) $charset_collate;",

            'equity_execution_attempts' => "CREATE TABLE {$this->table_prefix}equity_execution_attempts (
                attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                receipt_id VARCHAR(32) NOT NULL,
                wallet_address VARCHAR(44) NOT NULL,
                prepared_fingerprint VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'prepared',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (attempt_id),
                KEY idx_receipt (receipt_id),
                KEY idx_wallet (wallet_address)
            ) $charset_collate;",

            'equity_audit_events' => "CREATE TABLE {$this->table_prefix}equity_audit_events (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type VARCHAR(32) NOT NULL,
                receipt_id VARCHAR(32) NULL,
                wallet_address VARCHAR(44) NULL,
                detail_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id),
                KEY idx_type_created (event_type, created_at)
            ) $charset_collate;",
        ];
        
        foreach ($tables as $table_sql) {
            dbDelta($table_sql);
        }

        $this->migrate_earnfi_agent_profiles_to_ai_agents_table();
        $this->ensure_tasks_is_quick_column();
        $this->ensure_tasks_token_gate_columns();
        $this->ensure_tasks_x_account_requirement_column();
        $this->ensure_contest_submissions_updated_at_column();
        $this->ensure_contest_voting_schema();
        $this->ensure_user_x_verification_table();
        $this->ensure_push_subscriptions_table();
        $this->ensure_push_subscriptions_endpoint_column();
        $this->ensure_fcm_tokens_table();
        $this->ensure_reputation_ledger_table();
        $this->ensure_user_streaks_table();
        $this->ensure_user_achievements_table();
        $this->ensure_users_google_columns();
        $this->ensure_user_job_alert_email_column();
        $this->ensure_marketing_campaigns_table();
        $this->ensure_staking_lock_tier_60_day();
        $this->ensure_staking_lock_tier_v2_pools();
        $this->ensure_staking_positions_anchor_columns();
        $this->ensure_staking_delayed_unstake_columns();
        $this->ensure_staking_streamflow_columns();
        $this->ensure_staking_funding_tables();
        $this->ensure_email_logs_queued_status();
        $this->ensure_email_logs_heading_default();
        $this->ensure_banned_identifiers_table();
        $this->ensure_jupiter_swap_logs_table();
        $this->ensure_telegram_platform_tables();
        $this->ensure_earnfi_partners_table();
        $this->ensure_ai_agents_partner_id_column();
        $this->ensure_tasks_partner_columns();
        $this->ensure_earnfi_idempotency_composite_key();
        $this->ensure_user_profile_columns();
        $this->ensure_ai_agent_profile_columns();
        
        $this->ensure_recover_sol_earned_usd_column();
        $this->ensure_creator_wallet_tables();
        $this->ensure_listing_credit_tables();
        $this->ensure_tasks_instant_and_funding_columns();
        $this->ensure_users_client_channel_columns();
        $this->ensure_wallet_auth_challenges_table();
        $this->ensure_sgt_verifications_table();
        $this->ensure_payai_funding_tables();
        $this->ensure_work_money_layer();
        $this->ensure_equity_guard_seed();
        $this->ensure_equity_guard_prestocks_issuer();
        $this->ensure_equity_guard_prestocks_seed();
        $this->create_additional_indexes();
        $this->create_foreign_keys();
    }

    /**
     * Seed Equity Guard issuers, underlyings, and verified xStock mints (idempotent).
     */
    private function ensure_equity_guard_seed() {
        if (!$this->equity_guard_tables_ready()) {
            return;
        }
        if (get_option('growl_equity_guard_seed_v1')) {
            return;
        }
        $seed_path = GROWL_PLUGIN_PATH . 'includes/equity-guard/data/seed-assets.json';
        if (!is_readable($seed_path)) {
            return;
        }
        $raw = file_get_contents($seed_path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
            return;
        }
        $p = $this->table_prefix;
        $issuer_id = null;
        if (!empty($data['issuer']['slug'])) {
            $slug = sanitize_title((string) $data['issuer']['slug']);
            $name = sanitize_text_field((string) ($data['issuer']['name'] ?? 'xStocks'));
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT issuer_id FROM {$p}equity_issuers WHERE slug = %s LIMIT 1",
                $slug
            ));
            if ($existing) {
                $issuer_id = (int) $existing;
            } else {
                $this->wpdb->insert("{$p}equity_issuers", [
                    'slug' => $slug,
                    'name' => $name,
                    'website' => isset($data['issuer']['website']) ? esc_url_raw((string) $data['issuer']['website']) : null,
                ]);
                $issuer_id = (int) $this->wpdb->insert_id;
            }
        }
        foreach ($data['assets'] as $row) {
            if (!is_array($row) || empty($row['mint']) || empty($row['symbol'])) {
                continue;
            }
            $mint = sanitize_text_field((string) $row['mint']);
            $symbol = sanitize_text_field((string) $row['symbol']);
            $ticker = sanitize_text_field((string) ($row['underlying_ticker'] ?? preg_replace('/x$/i', '', $symbol)));
            $uname = sanitize_text_field((string) ($row['underlying_name'] ?? $ticker));
            $uid = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT underlying_id FROM {$p}equity_underlyings WHERE ticker = %s LIMIT 1",
                $ticker
            ));
            if (!$uid) {
                $this->wpdb->insert("{$p}equity_underlyings", [
                    'ticker' => $ticker,
                    'name' => $uname,
                    'reference_symbol' => $ticker,
                ]);
                $uid = (int) $this->wpdb->insert_id;
            } else {
                $uid = (int) $uid;
            }
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT asset_id FROM {$p}equity_assets WHERE mint = %s LIMIT 1",
                $mint
            ));
            if ($exists) {
                continue;
            }
            $this->wpdb->insert("{$p}equity_assets", [
                'mint' => $mint,
                'symbol' => $symbol,
                'underlying_id' => $uid,
                'issuer_id' => $issuer_id ?: null,
                'decimals' => isset($row['decimals']) ? (int) $row['decimals'] : 8,
                'tier' => sanitize_text_field((string) ($row['tier'] ?? 'verified')),
                'status' => 'active',
                'structure_type' => 'tokenized_equity',
                'backing_model' => '1:1',
                'logo_url' => isset($row['logo_url']) ? esc_url_raw((string) $row['logo_url']) : null,
            ]);
            $asset_id = (int) $this->wpdb->insert_id;
            if ($asset_id > 0) {
                $this->wpdb->insert("{$p}equity_verifications", [
                    'asset_id' => $asset_id,
                    'source_slug' => 'seed_registry',
                    'source_type' => 'issuer_registry',
                    'evidence_url' => 'https://xstocks.com/',
                    'payload_json' => wp_json_encode(['seed' => true]),
                    'checked_at' => current_time('mysql', true),
                ]);
            }
        }
        update_option('growl_equity_guard_seed_v1', 1, false);
    }

    /**
     * Ensure PreStocks Private Markets issuer exists (idempotent).
     */
    private function ensure_equity_guard_prestocks_issuer() {
        if (!$this->equity_guard_tables_ready()) {
            return;
        }
        $seed_path = GROWL_PLUGIN_PATH . 'includes/equity-guard/data/seed-prestocks.json';
        if (!is_readable($seed_path)) {
            return;
        }
        $raw = file_get_contents($seed_path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['issuer']['slug'])) {
            return;
        }
        $p = $this->table_prefix;
        $slug = sanitize_title((string) $data['issuer']['slug']);
        $name = sanitize_text_field((string) ($data['issuer']['name'] ?? 'PreStocks'));
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT issuer_id FROM {$p}equity_issuers WHERE slug = %s LIMIT 1",
            $slug
        ));
        if (!$existing) {
            $this->wpdb->insert("{$p}equity_issuers", [
                'slug' => $slug,
                'name' => $name,
                'website' => isset($data['issuer']['website']) ? esc_url_raw((string) $data['issuer']['website']) : null,
            ]);
        }
        if (!get_option('growl_equity_guard_prestocks_issuer_v1')) {
            update_option('growl_equity_guard_prestocks_issuer_v1', 1, false);
        }
    }

    /**
     * Seed PreStocks private-market assets from curated JSON (idempotent).
     */
    private function ensure_equity_guard_prestocks_seed() {
        if (!$this->equity_guard_tables_ready()) {
            return;
        }
        if (get_option('growl_equity_guard_prestocks_seed_v2')) {
            return;
        }
        $seed_path = GROWL_PLUGIN_PATH . 'includes/equity-guard/data/seed-prestocks.json';
        if (!is_readable($seed_path)) {
            return;
        }
        $raw = file_get_contents($seed_path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['issuer']['slug']) || empty($data['assets']) || !is_array($data['assets'])) {
            return;
        }
        $p = $this->table_prefix;
        $slug = sanitize_title((string) $data['issuer']['slug']);
        $issuer_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT issuer_id FROM {$p}equity_issuers WHERE slug = %s LIMIT 1",
            $slug
        ));
        if ($issuer_id <= 0) {
            $this->ensure_equity_guard_prestocks_issuer();
            $issuer_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT issuer_id FROM {$p}equity_issuers WHERE slug = %s LIMIT 1",
                $slug
            ));
        }
        if ($issuer_id <= 0) {
            return;
        }
        foreach ($data['assets'] as $row) {
            if (!is_array($row) || empty($row['mint']) || empty($row['symbol'])) {
                continue;
            }
            $mint = sanitize_text_field((string) $row['mint']);
            $symbol = sanitize_text_field((string) $row['symbol']);
            $ticker = sanitize_text_field((string) ($row['underlying_ticker'] ?? $symbol));
            $uname = sanitize_text_field((string) ($row['underlying_name'] ?? $ticker));
            $uid = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT underlying_id FROM {$p}equity_underlyings WHERE ticker = %s LIMIT 1",
                $ticker
            ));
            if (!$uid) {
                $this->wpdb->insert("{$p}equity_underlyings", [
                    'ticker' => $ticker,
                    'name' => $uname,
                    'reference_symbol' => $ticker,
                ]);
                $uid = (int) $this->wpdb->insert_id;
            } else {
                $uid = (int) $uid;
            }
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT asset_id FROM {$p}equity_assets WHERE mint = %s LIMIT 1",
                $mint
            ));
            if ($exists) {
                continue;
            }
            $meta = isset($row['meta']) && is_array($row['meta']) ? $row['meta'] : [];
            if (empty($meta['asset_class'])) {
                $meta['asset_class'] = 'private';
            }
            $this->wpdb->insert("{$p}equity_assets", [
                'mint' => $mint,
                'symbol' => $symbol,
                'underlying_id' => $uid,
                'issuer_id' => $issuer_id,
                'decimals' => isset($row['decimals']) ? (int) $row['decimals'] : 8,
                'tier' => sanitize_text_field((string) ($row['tier'] ?? 'provisional')),
                'status' => 'active',
                'structure_type' => sanitize_text_field((string) ($row['structure_type'] ?? 'pre_ipo')),
                'backing_model' => sanitize_text_field((string) ($row['backing_model'] ?? 'issuer_attested')),
                'meta_json' => wp_json_encode($meta),
            ]);
        }
        $this->wpdb->query(
            "UPDATE {$p}equity_assets SET status = 'suspended', tier = 'suspended'
             WHERE mint LIKE 'PsSeedMint%'"
        );
        update_option('growl_equity_guard_prestocks_seed_v2', 1, false);
    }

    /**
     * Single-use wallet sign-in challenges.
     *
     * Nonces are stored only as SHA-256 hashes. A challenge is consumed in the
     * same conditional UPDATE that verifies it is still unused and unexpired.
     */
    public function ensure_wallet_auth_challenges_table() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table_prefix . 'wallet_auth_challenges';
        $charset = $this->wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            challenge_id CHAR(36) NOT NULL,
            nonce_hash CHAR(64) NOT NULL,
            requested_wallet VARCHAR(44) NULL,
            consumed_wallet VARCHAR(44) NULL,
            network VARCHAR(24) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'login',
            domain VARCHAR(190) NOT NULL,
            uri VARCHAR(500) NOT NULL,
            statement VARCHAR(255) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (challenge_id),
            UNIQUE KEY uq_nonce_hash (nonce_hash),
            KEY idx_expiry_unused (expires_at, consumed_at),
            KEY idx_requested_wallet (requested_wallet, expires_at)
        ) {$charset};");
    }

    public function ensure_sgt_verifications_table() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table_prefix . 'sgt_verifications';
        $charset = $this->wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            verification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            wallet_address VARCHAR(44) NOT NULL,
            is_holder TINYINT(1) NOT NULL DEFAULT 0,
            sgt_mint VARCHAR(44) NULL,
            observed_slot BIGINT UNSIGNED NOT NULL DEFAULT 0,
            purpose VARCHAR(40) NOT NULL DEFAULT 'profile',
            failure_reason VARCHAR(80) NULL,
            checked_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (verification_id),
            KEY idx_sgt_user_checked (user_id, checked_at),
            KEY idx_sgt_wallet_checked (wallet_address, checked_at),
            KEY idx_sgt_expiry (expires_at),
            KEY idx_sgt_mint (sgt_mint)
        ) {$charset};");
    }

    /**
     * Durable PayAI funding intents and SKR to USDC conversion attempts.
     *
     * Amounts are immutable atomic units. They are never reconstructed from the
     * current settings when a payment is distributed or reconciled.
     */
    public function ensure_payai_funding_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->wpdb->get_charset_collate();
        $intents = $this->table_prefix . 'funding_intents';
        $conversions = $this->table_prefix . 'funding_conversions';

        dbDelta("CREATE TABLE {$intents} (
            intent_id CHAR(36) NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            wallet_address VARCHAR(44) NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            reference_id VARCHAR(64) NULL,
            payload_hash CHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            payment_asset VARCHAR(12) NOT NULL,
            asset_mint VARCHAR(44) NOT NULL,
            asset_decimals TINYINT UNSIGNED NOT NULL,
            payment_amount_atomic DECIMAL(36,0) NOT NULL,
            principal_usdc_atomic BIGINT UNSIGNED NOT NULL,
            fee_usdc_atomic BIGINT UNSIGNED NOT NULL,
            fee_bps SMALLINT UNSIGNED NOT NULL,
            staking_usdc_atomic BIGINT UNSIGNED NOT NULL,
            team_usdc_atomic BIGINT UNSIGNED NOT NULL,
            referral_usdc_atomic BIGINT UNSIGNED NOT NULL,
            bonus_usdc_atomic BIGINT UNSIGNED NOT NULL,
            rewards_usdc_atomic BIGINT UNSIGNED NOT NULL,
            treasury_address VARCHAR(44) NOT NULL,
            sgt_snapshot_json LONGTEXT NULL,
            quote_json LONGTEXT NULL,
            quote_hash CHAR(64) NULL,
            expires_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'quoted',
            settlement_signature VARCHAR(128) NULL,
            payment_id BIGINT UNSIGNED NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            settled_at DATETIME NULL,
            PRIMARY KEY (intent_id),
            UNIQUE KEY uq_intent_idempotency (idempotency_key),
            UNIQUE KEY uq_intent_settlement (settlement_signature),
            KEY idx_intent_wallet (wallet_address, created_at),
            KEY idx_intent_status_expiry (status, expires_at),
            KEY idx_intent_payload (payload_hash)
        ) {$charset};");

        dbDelta("CREATE TABLE {$conversions} (
            conversion_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            intent_id CHAR(36) NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            input_mint VARCHAR(44) NOT NULL,
            output_mint VARCHAR(44) NOT NULL,
            max_input_atomic DECIMAL(36,0) NOT NULL,
            exact_output_atomic BIGINT UNSIGNED NOT NULL,
            actual_input_atomic DECIMAL(36,0) NULL,
            actual_output_atomic BIGINT UNSIGNED NULL,
            source_settlement_signature VARCHAR(128) NOT NULL,
            swap_signature VARCHAR(128) NULL,
            quote_hash CHAR(64) NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            locked_at DATETIME NULL,
            lock_token CHAR(36) NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reconciled_at DATETIME NULL,
            PRIMARY KEY (conversion_id),
            UNIQUE KEY uq_conversion_intent (intent_id),
            UNIQUE KEY uq_conversion_idempotency (idempotency_key),
            UNIQUE KEY uq_conversion_signature (swap_signature),
            KEY idx_conversion_queue (status, next_attempt_at),
            KEY idx_conversion_lock (locked_at)
        ) {$charset};");

        $this->ensure_payai_payment_columns();
    }

    private function ensure_payai_payment_columns() {
        $table = $this->table_prefix . 'payments';
        $columns = $this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (!is_array($columns) || empty($columns)) {
            return;
        }
        $definitions = [
            'funding_intent_id' => "CHAR(36) NULL AFTER idempotency_key",
            'payment_asset' => "VARCHAR(12) NOT NULL DEFAULT 'USDC' AFTER amount",
            'source_amount_atomic' => "DECIMAL(36,0) NULL AFTER payment_asset",
            'principal_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER source_amount_atomic",
            'fee_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER principal_usdc_atomic",
            'fee_bps' => "SMALLINT UNSIGNED NULL AFTER fee_usdc_atomic",
            'staking_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER fee_bps",
            'team_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER staking_usdc_atomic",
            'referral_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER team_usdc_atomic",
            'bonus_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER referral_usdc_atomic",
            'rewards_usdc_atomic' => "BIGINT UNSIGNED NULL AFTER bonus_usdc_atomic",
            'distribution_ready' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER rewards_usdc_atomic",
        ];
        foreach ($definitions as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
        $indexes = $this->wpdb->get_col("SHOW INDEX FROM `{$table}`", 2);
        if (is_array($indexes) && !in_array('idx_funding_intent', $indexes, true)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY idx_funding_intent (funding_intent_id)");
        }
        if (is_array($indexes) && !in_array('idx_distribution_ready', $indexes, true)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD KEY idx_distribution_ready (distribution_ready, distribution_processed, created_at)");
        }
        $transactions = $this->table_prefix . 'transactions';
        $type = $this->wpdb->get_row("SHOW COLUMNS FROM `{$transactions}` LIKE 'type'");
        if ($type && stripos((string) $type->Type, 'enum(') === 0) {
            $this->wpdb->query("ALTER TABLE `{$transactions}` MODIFY COLUMN type VARCHAR(40) NOT NULL");
        }
    }

    /**
     * Google auth + email support on users table (existing installs).
     */
    private function ensure_users_google_columns() {
        $table = $this->table_prefix . 'users';

        // wallet_address nullable to support google-only accounts
        $col = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'wallet_address'",
            DB_NAME,
            $table
        ));
        if (!empty($col) && isset($col[0]->IS_NULLABLE) && $col[0]->IS_NULLABLE === 'NO') {
            $this->wpdb->query("ALTER TABLE {$table} MODIFY wallet_address VARCHAR(44) NULL");
        }

        $columns = [
            'email' => "ALTER TABLE {$table} ADD COLUMN email VARCHAR(190) NULL AFTER wallet_type",
            'google_sub' => "ALTER TABLE {$table} ADD COLUMN google_sub VARCHAR(64) NULL AFTER email",
        ];
        foreach ($columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }

        // Indexes
        $idx_email = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'idx_email'
        ));
        if (empty($idx_email)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_email (email)");
        }
        $uq_google = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'google_sub'
        ));
        if (empty($uq_google)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY google_sub (google_sub)");
        }

        // "Mobile Wallet Adapter" is 22 chars; older installs used VARCHAR(20).
        $wallet_type_col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'wallet_type'");
        if ($wallet_type_col && preg_match('/varchar\((\d+)\)/i', (string) $wallet_type_col->Type, $m)) {
            if ((int) $m[1] < 32) {
                $this->wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN `wallet_type` VARCHAR(32) DEFAULT 'unknown'");
            }
        }
    }

    /**
     * Public profile fields on growl_users.
     */
    private function ensure_user_profile_columns() {
        $table = $this->table_prefix . 'users';
        $columns = [
            'profile_slug' => "ALTER TABLE {$table} ADD COLUMN profile_slug VARCHAR(32) NULL AFTER referral_code",
            'display_name' => "ALTER TABLE {$table} ADD COLUMN display_name VARCHAR(80) NULL AFTER profile_slug",
            'bio' => "ALTER TABLE {$table} ADD COLUMN bio VARCHAR(500) NOT NULL DEFAULT '' AFTER display_name",
            'avatar_url' => "ALTER TABLE {$table} ADD COLUMN avatar_url VARCHAR(255) NULL AFTER bio",
            'avatar_custom' => "ALTER TABLE {$table} ADD COLUMN avatar_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_url",
            'website' => "ALTER TABLE {$table} ADD COLUMN website VARCHAR(255) NULL AFTER avatar_custom",
            'profile_tags' => "ALTER TABLE {$table} ADD COLUMN profile_tags LONGTEXT NULL AFTER website",
            'public_profile_enabled' => "ALTER TABLE {$table} ADD COLUMN public_profile_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_tags",
            'profile_updated_at' => "ALTER TABLE {$table} ADD COLUMN profile_updated_at DATETIME NULL AFTER public_profile_enabled",
        ];
        foreach ($columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }
        $idx_slug = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'uq_profile_slug'
        ));
        if (empty($idx_slug)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_profile_slug (profile_slug)");
        }
        $idx_pub = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'idx_public_profile'
        ));
        if (empty($idx_pub)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_public_profile (public_profile_enabled, profile_updated_at)");
        }
    }

    /**
     * Public profile fields on growl_ai_agents.
     */
    private function ensure_ai_agent_profile_columns() {
        $table = $this->table_prefix . 'ai_agents';
        $columns = [
            'bio' => "ALTER TABLE {$table} ADD COLUMN bio VARCHAR(500) NOT NULL DEFAULT '' AFTER capabilities",
            'avatar_url' => "ALTER TABLE {$table} ADD COLUMN avatar_url VARCHAR(255) NULL AFTER bio",
            'website' => "ALTER TABLE {$table} ADD COLUMN website VARCHAR(255) NULL AFTER avatar_url",
            'profile_tags' => "ALTER TABLE {$table} ADD COLUMN profile_tags LONGTEXT NULL AFTER website",
            'public_profile_enabled' => "ALTER TABLE {$table} ADD COLUMN public_profile_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_tags",
            'profile_updated_at' => "ALTER TABLE {$table} ADD COLUMN profile_updated_at DATETIME NULL AFTER public_profile_enabled",
            'models_json' => "ALTER TABLE {$table} ADD COLUMN models_json LONGTEXT NULL AFTER profile_updated_at",
            'rating_avg' => "ALTER TABLE {$table} ADD COLUMN rating_avg DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER models_json",
            'rating_count' => "ALTER TABLE {$table} ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg",
            'completed_orders' => "ALTER TABLE {$table} ADD COLUMN completed_orders INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_count",
            'x_username' => "ALTER TABLE {$table} ADD COLUMN x_username VARCHAR(15) NULL AFTER completed_orders",
            'x_user_id' => "ALTER TABLE {$table} ADD COLUMN x_user_id VARCHAR(32) NULL AFTER x_username",
            'said_verified' => "ALTER TABLE {$table} ADD COLUMN said_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER x_user_id",
            'said_registered' => "ALTER TABLE {$table} ADD COLUMN said_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER said_verified",
        ];
        foreach ($columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }
        $idx_pub = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'idx_agent_public_profile'
        ));
        if (empty($idx_pub)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_agent_public_profile (public_profile_enabled, profile_updated_at)");
        }
        $idx_said = $this->wpdb->get_results($this->wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'idx_ai_agents_said_flags'
        ));
        if (empty($idx_said)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_ai_agents_said_flags (said_verified, said_registered)");
        }
        // One-time alignment from legacy capabilities JSON (safe to re-run).
        $this->wpdb->query(
            "UPDATE {$table} SET said_verified = 1
             WHERE said_verified = 0 AND JSON_EXTRACT(capabilities, '$.said.verified') = true"
        );
        $this->wpdb->query(
            "UPDATE {$table} SET said_registered = 1
             WHERE said_registered = 0 AND JSON_EXTRACT(capabilities, '$.said.registered') = true"
        );
    }

    /**
     * Job listing alert emails: opt-in stored on growl_users; default ON when user has an email address.
     */
    private function ensure_user_job_alert_email_column() {
        $table = $this->table_prefix . 'users';
        $exists = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'job_alert_email'",
            DB_NAME,
            $table
        ));
        if (empty($exists)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN job_alert_email TINYINT(1) NOT NULL DEFAULT 0 AFTER google_sub");
        }
        if (!get_option('growl_migr_job_alert_email_v1')) {
            $this->wpdb->query(
                "UPDATE {$table} SET job_alert_email = 1 WHERE email IS NOT NULL AND TRIM(email) <> ''"
            );
            update_option('growl_migr_job_alert_email_v1', 1, false);
        }
    }

    /**
     * Allow email_logs.status = queued for batched outbound mail.
     */
    public function ensure_email_logs_queued_status() {
        $table = $this->table_prefix . 'email_logs';
        $col = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            DB_NAME,
            $table
        ));
        if ($col && isset($col->COLUMN_TYPE) && stripos((string) $col->COLUMN_TYPE, 'queued') === false) {
            $this->wpdb->query(
                "ALTER TABLE {$table} MODIFY status ENUM('queued','sending','sent','failed') DEFAULT 'sending'"
            );
        }
        $raw = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'is_raw_html'");
        if (empty($raw)) {
            $this->wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN `is_raw_html` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`"
            );
        }
    }

    /**
     * heading is optional for raw HTML campaigns; allow empty string (not NULL).
     */
    public function ensure_email_logs_heading_default() {
        $table = $this->table_prefix . 'email_logs';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        if ((int) $exists === 0) {
            return;
        }
        $col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'heading'");
        if ($col && (($col->Null ?? '') === 'YES' || ($col->Default ?? null) === null)) {
            $this->wpdb->query(
                "ALTER TABLE `{$table}` MODIFY COLUMN `heading` VARCHAR(500) NOT NULL DEFAULT ''"
            );
        }
        $this->wpdb->query(
            "UPDATE `{$table}` SET heading = '' WHERE heading IS NULL"
        );
    }

    /**
     * Wallets / X accounts unlinked after compromise or suspension cannot be reused on new accounts.
     */
    public function ensure_banned_identifiers_table() {
        $table = $this->table_prefix . 'banned_identifiers';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        if ((int) $exists > 0) {
            return;
        }
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            ban_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier_type ENUM('wallet','x_id','x_username') NOT NULL,
            identifier_value VARCHAR(190) NOT NULL,
            original_user_id BIGINT UNSIGNED NULL,
            reason VARCHAR(64) NOT NULL DEFAULT 'user_unlink',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ban_id),
            UNIQUE KEY uq_identifier (identifier_type, identifier_value),
            KEY idx_type (identifier_type),
            KEY idx_created (created_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @param string $type wallet|x_id|x_username
     */
    public function is_identifier_banned($type, $value) {
        $type = sanitize_key((string) $type);
        $value = $this->normalize_banned_identifier_value($type, $value);
        if ($value === '') {
            return false;
        }
        $row = $this->get_row(
            "SELECT ban_id FROM {$this->table_prefix}banned_identifiers
             WHERE identifier_type = %s AND identifier_value = %s LIMIT 1",
            [$type, $value]
        );
        return !empty($row);
    }

    /**
     * @param string $type wallet|x_id|x_username
     */
    public function normalize_banned_identifier_value($type, $value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if ($type === 'wallet') {
            return $value;
        }
        if ($type === 'x_username') {
            return strtolower(ltrim($value, '@'));
        }
        if ($type === 'x_id') {
            return $value;
        }
        return '';
    }

    /**
     * @param string $type wallet|x_id|x_username
     */
    public function ban_identifier($type, $value, $original_user_id = null, $reason = 'user_unlink') {
        $type = sanitize_key((string) $type);
        if (!in_array($type, ['wallet', 'x_id', 'x_username'], true)) {
            return false;
        }
        $value = $this->normalize_banned_identifier_value($type, $value);
        if ($value === '') {
            return false;
        }
        $existing = $this->get_row(
            "SELECT ban_id FROM {$this->table_prefix}banned_identifiers
             WHERE identifier_type = %s AND identifier_value = %s",
            [$type, $value]
        );
        if ($existing) {
            return true;
        }
        return (bool) $this->insert('banned_identifiers', [
            'identifier_type'   => $type,
            'identifier_value'  => $value,
            'original_user_id'  => $original_user_id ? (int) $original_user_id : null,
            'reason'            => sanitize_text_field((string) $reason),
            'created_at'        => current_time('mysql'),
        ]);
    }

    /**
     * Jupiter DEX swap audit log (staking tab and future swap surfaces).
     */
    public function ensure_jupiter_swap_logs_table() {
        $table = $this->table_prefix . 'jupiter_swap_logs';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        $charset_collate = $this->wpdb->get_charset_collate();
        if ((int) $exists === 0) {
            $sql = "CREATE TABLE {$table} (
            swap_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            wallet_address VARCHAR(64) NOT NULL DEFAULT '',
            tx_signature VARCHAR(128) NOT NULL DEFAULT '',
            input_mint VARCHAR(64) NOT NULL DEFAULT '',
            output_mint VARCHAR(64) NOT NULL DEFAULT '',
            input_symbol VARCHAR(32) NOT NULL DEFAULT '',
            output_symbol VARCHAR(32) NOT NULL DEFAULT '',
            input_amount DECIMAL(36,18) NOT NULL DEFAULT 0,
            output_amount DECIMAL(36,18) NOT NULL DEFAULT 0,
            input_usd DECIMAL(20,6) NULL,
            output_usd DECIMAL(20,6) NULL,
            volume_usd DECIMAL(20,6) NULL,
            fee_usd DECIMAL(20,6) NULL,
            slippage_bps INT UNSIGNED NULL,
            route_label VARCHAR(500) NOT NULL DEFAULT '',
            platform_fee_bps INT UNSIGNED NULL,
            status ENUM('submitted','confirmed','failed') NOT NULL DEFAULT 'submitted',
            source VARCHAR(32) NOT NULL DEFAULT 'staking',
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            PRIMARY KEY (swap_id),
            KEY idx_wallet (wallet_address),
            KEY idx_sig (tx_signature),
            KEY idx_status_created (status, created_at),
            KEY idx_created (created_at),
            KEY idx_user_created (user_id, created_at)
        ) {$charset_collate};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
        $this->ensure_jupiter_swap_logs_usd_columns();
    }

    private function ensure_jupiter_swap_logs_usd_columns() {
        $table = $this->table_prefix . 'jupiter_swap_logs';
        $cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (!is_array($cols)) {
            return;
        }
        if (!in_array('volume_usd', $cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN volume_usd DECIMAL(20,6) NULL AFTER output_usd");
        }
        if (!in_array('fee_usd', $cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN fee_usd DECIMAL(20,6) NULL AFTER volume_usd");
        }
    }

    /**
     * Admin email marketing: persisted campaigns (installs that ran DB before table existed).
     */
    public function ensure_marketing_campaigns_table() {
        $table = $this->table_prefix . 'marketing_campaigns';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        if ((int) $exists > 0) {
            $this->maybe_widen_marketing_campaign_columns($table);
            return;
        }
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            campaign_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject VARCHAR(500) NOT NULL,
            heading VARCHAR(500) NOT NULL DEFAULT '',
            audience VARCHAR(64) NOT NULL DEFAULT 'single',
            audience_segment VARCHAR(64) NOT NULL DEFAULT 'all_email',
            target_email VARCHAR(255) NULL,
            html_body LONGTEXT NOT NULL,
            use_main_template TINYINT(1) NOT NULL DEFAULT 1,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (campaign_id),
            KEY idx_created (created_at),
            KEY idx_admin (admin_id)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        $this->maybe_widen_marketing_campaign_columns($table);
    }

    /**
     * Widen audience column and add audience_segment for installs created before marketing filters.
     */
    private function maybe_widen_marketing_campaign_columns($table) {
        $col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'audience'");
        if ($col && isset($col->Type) && stripos((string) $col->Type, 'varchar(64)') === false) {
            $this->wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN `audience` VARCHAR(64) NOT NULL DEFAULT 'single'");
        }
        $seg = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'audience_segment'");
        if (empty($seg)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `audience_segment` VARCHAR(64) NOT NULL DEFAULT 'all_email' AFTER `audience`");
        }
        $umt = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'use_main_template'");
        if (empty($umt)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `use_main_template` TINYINT(1) NOT NULL DEFAULT 1 AFTER `html_body`");
        }
        $st = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'status'");
        if (empty($st)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER `failed_count`");
        }
        $tr = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'total_recipients'");
        if (empty($tr)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `total_recipients` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`");
        }
        $ua = $this->wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'updated_at'");
        if (empty($ua)) {
            $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`");
        }
        $hd = $this->wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'heading'");
        if ($hd && (($hd->Null ?? '') === 'YES' || ($hd->Default ?? null) === null)) {
            $this->wpdb->query(
                "ALTER TABLE `{$table}` MODIFY COLUMN `heading` VARCHAR(500) NOT NULL DEFAULT ''"
            );
        }
        $this->wpdb->query(
            "UPDATE `{$table}` SET heading = '' WHERE heading IS NULL"
        );
    }

    /**
     * Staking PHP uses 60_day tier; extend ENUM on existing installs.
     */
    private function ensure_staking_lock_tier_60_day() {
        $table = $this->table_prefix . 'staking_positions';
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'lock_tier'",
            DB_NAME,
            $table
        ));
        if (!$row || empty($row->COLUMN_TYPE)) {
            return;
        }
        $ct = strtolower((string) $row->COLUMN_TYPE);
        if (strpos($ct, '60_day') !== false) {
            return;
        }
        $this->wpdb->query(
            "ALTER TABLE {$table} MODIFY COLUMN lock_tier ENUM('flexible','30_day','60_day','90_day','180_day') NOT NULL DEFAULT 'flexible'"
        );
    }

    /**
     * Staking v2: 7-day pool + default 90_day for new positions (legacy flexible/60_day kept for old rows).
     */
    private function ensure_staking_lock_tier_v2_pools() {
        $table = $this->table_prefix . 'staking_positions';
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'lock_tier'",
            DB_NAME,
            $table
        ));
        if (!$row || empty($row->COLUMN_TYPE)) {
            return;
        }
        $ct = strtolower((string) $row->COLUMN_TYPE);
        if (strpos($ct, '14_day') !== false) {
            return;
        }
        $this->wpdb->query(
            "ALTER TABLE {$table} MODIFY COLUMN lock_tier ENUM('flexible','7_day','14_day','30_day','60_day','90_day','180_day') NOT NULL DEFAULT '90_day'"
        );
        $this->wpdb->query(
            "UPDATE {$table} SET lock_tier = '14_day' WHERE lock_tier = '7_day'"
        );
    }

    /**
     * Anchor staking: unique nonce + PDA mirror for non-custodial positions.
     */
    private function ensure_staking_positions_anchor_columns() {
        $table = $this->table_prefix . 'staking_positions';
        $cols = ['position_nonce', 'stake_position_pda', 'earnfi_decimals'];
        foreach ($cols as $col) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $col
            ));
            if (!empty($exists)) {
                continue;
            }
            if ($col === 'position_nonce') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN position_nonce BIGINT UNSIGNED NULL AFTER wallet_address");
                $this->wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_staking_position_nonce (position_nonce)");
            } elseif ($col === 'stake_position_pda') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN stake_position_pda VARCHAR(64) NULL AFTER position_nonce");
                $this->wpdb->query("ALTER TABLE {$table} ADD KEY idx_stake_position_pda (stake_position_pda)");
            } elseif ($col === 'earnfi_decimals') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN earnfi_decimals TINYINT UNSIGNED NOT NULL DEFAULT 9 AFTER stake_position_pda");
            }
        }
    }

    /**
     * Staking v2: delayed unstake + platform fee tracking columns.
     */
    private function ensure_staking_delayed_unstake_columns() {
        $table = $this->table_prefix . 'staking_positions';
        $cols = ['unstake_requested_at', 'unstake_epoch_id', 'platform_fee_paid'];
        foreach ($cols as $col) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $col
            ));
            if (!empty($exists)) {
                continue;
            }
            if ($col === 'unstake_requested_at') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN unstake_requested_at DATETIME NULL AFTER unstake_tx_hash");
            } elseif ($col === 'unstake_epoch_id') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN unstake_epoch_id BIGINT UNSIGNED NULL AFTER unstake_requested_at");
                $this->wpdb->query("ALTER TABLE {$table} ADD KEY idx_unstake_epoch (unstake_epoch_id, status)");
            } elseif ($col === 'platform_fee_paid') {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN platform_fee_paid DECIMAL(18,9) NOT NULL DEFAULT '0.000000000' AFTER unstake_epoch_id");
            }
        }
    }

    /**
     * Streamflow staking: provider + on-chain position metadata.
     */
    private function ensure_staking_streamflow_columns() {
        $table = $this->table_prefix . 'staking_positions';
        $cols = [
            'on_chain_provider' => "ALTER TABLE {$table} ADD COLUMN on_chain_provider ENUM('streamflow','anchor') NOT NULL DEFAULT 'streamflow' AFTER status",
            'stake_entry_pubkey' => "ALTER TABLE {$table} ADD COLUMN stake_entry_pubkey VARCHAR(64) NULL AFTER on_chain_provider",
            'streamflow_pool' => "ALTER TABLE {$table} ADD COLUMN streamflow_pool VARCHAR(64) NULL AFTER stake_entry_pubkey",
        ];
        foreach ($cols as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }
        $idx = $this->wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_provider_status'");
        if (empty($idx)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD KEY idx_provider_status (on_chain_provider, status)");
        }
    }

    /**
     * Reward flywheel idempotency + RECENT TICKS audit tables.
     */
    private function ensure_staking_funding_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $runs = $this->table_prefix . 'staking_funding_runs';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $runs
        ));
        if (!$exists) {
            dbDelta("CREATE TABLE {$runs} (
                run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                trigger_type VARCHAR(20) NOT NULL DEFAULT 'threshold',
                usdc_in DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                usdc_platform DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                usdc_creator DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                sol_claimed DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                earnfi_out DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                swap_tx_hash VARCHAR(100) NULL,
                claim_tx_hash VARCHAR(100) NULL,
                creator_split_tx_hash VARCHAR(100) NULL,
                sol_swap_tx_hash VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                PRIMARY KEY (run_id),
                KEY idx_status_created (status, created_at)
            ) {$charset_collate};");
        }

        $pool_txs = $this->table_prefix . 'staking_funding_pool_txs';
        $exists2 = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $pool_txs
        ));
        if (!$exists2) {
            dbDelta("CREATE TABLE {$pool_txs} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                pool_tier VARCHAR(20) NOT NULL,
                amount_earnfi DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                tx_hash VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_run_tier (run_id, pool_tier),
                KEY idx_run (run_id)
            ) {$charset_collate};");
        }

        $ticks = $this->table_prefix . 'staking_buyback_ticks';
        $exists3 = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $ticks
        ));
        if (!$exists3) {
            dbDelta("CREATE TABLE {$ticks} (
                tick_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                executed_at DATETIME NOT NULL,
                source_label VARCHAR(100) NOT NULL DEFAULT 'Creator + Platform Fees',
                usdc_platform DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                usdc_creator DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                sol_claimed DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                earnfi_buyback DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                earnfi_distributed DECIMAL(18,9) NOT NULL DEFAULT '0.000000000',
                swap_tx_hash VARCHAR(100) NULL,
                claim_tx_hash VARCHAR(100) NULL,
                creator_split_tx_hash VARCHAR(100) NULL,
                sol_swap_tx_hash VARCHAR(100) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (tick_id),
                KEY idx_executed (executed_at),
                KEY idx_run (run_id)
            ) {$charset_collate};");
        }
    }

    /**
     * Quick jobs (Work/API): flag on task row for analytics and policy.
     */
    private function ensure_tasks_is_quick_column() {
        $table = $this->table_prefix . 'tasks';
        $col = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'is_quick'",
            DB_NAME,
            $table
        ));
        if (empty($col)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN is_quick TINYINT(1) NOT NULL DEFAULT 0 AFTER creator_agent_id");
        }
    }

    /**
     * Creator Wallet + ledger (listing funds — not earner balances).
     */
    public function ensure_creator_wallet_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->wpdb->get_charset_collate();
        $wallets = $this->table_prefix . 'creator_wallets';
        $ledger = $this->table_prefix . 'creator_wallet_ledger';

        dbDelta("CREATE TABLE {$wallets} (
            user_id BIGINT UNSIGNED NOT NULL,
            free_credits_usdc DECIMAL(18,6) NOT NULL DEFAULT 0,
            paid_balance_usdc DECIMAL(18,6) NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            PRIMARY KEY (user_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$ledger} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            bucket VARCHAR(8) NOT NULL,
            amount DECIMAL(18,6) NOT NULL,
            job_id VARCHAR(64) NULL,
            campaign_id BIGINT UNSIGNED NULL,
            payment_hash VARCHAR(128) NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_job (job_id),
            KEY idx_campaign (campaign_id),
            KEY idx_entry_payment (entry_type, payment_hash)
        ) {$charset};");

        // Unique idempotency for top-up / close_slot refunds (NULL payment_hash allowed multiple times).
        $idx = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uq_ledger_entry_payment'",
            DB_NAME,
            $ledger
        ));
        if (empty($idx)) {
            // Partial unique via generated approach: add unique on (entry_type, payment_hash) where hash not empty.
            // MySQL unique allows multiple NULLs; empty string would collide — credit() always uses non-empty hashes for idempotent paths.
            $this->wpdb->query("ALTER TABLE {$ledger} ADD UNIQUE KEY uq_ledger_entry_payment (entry_type, payment_hash)");
        }
    }

    /**
     * Listing Credits campaigns + claims.
     */
    public function ensure_listing_credit_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->wpdb->get_charset_collate();
        $campaigns = $this->table_prefix . 'listing_credit_campaigns';
        $claims = $this->table_prefix . 'listing_credit_claims';

        dbDelta("CREATE TABLE {$campaigns} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(191) NOT NULL,
            credit_amount_usdc DECIMAL(18,6) NOT NULL,
            claim_slots_total INT UNSIGNED NOT NULL,
            claim_slots_remaining INT UNSIGNED NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            targeting LONGTEXT NULL,
            created_by_admin BIGINT UNSIGNED NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_active_window (active, starts_at, ends_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$claims} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(18,6) NOT NULL,
            client_channel_at_claim VARCHAR(32) NULL,
            claimed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_campaign_user (campaign_id, user_id),
            KEY idx_user (user_id)
        ) {$charset};");
    }

    /**
     * Instant Jobs effort + funding metadata on tasks.
     */
    public function ensure_tasks_instant_and_funding_columns() {
        $table = $this->table_prefix . 'tasks';
        $cols = [
            'effort_bucket' => "VARCHAR(8) NULL",
            'payment_method' => "VARCHAR(32) NULL",
            'funded_free_usdc' => "DECIMAL(18,6) NOT NULL DEFAULT 0",
            'funded_paid_usdc' => "DECIMAL(18,6) NOT NULL DEFAULT 0",
            'fee_paid_usdc' => "DECIMAL(18,6) NOT NULL DEFAULT 0",
            'fee_waived' => "TINYINT(1) NOT NULL DEFAULT 0",
            'closed_at' => "DATETIME NULL",
            'closed_by' => "BIGINT UNSIGNED NULL",
            'target_clients' => "TEXT NULL",
        ];
        foreach ($cols as $name => $def) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
            }
        }
        // Normalize any legacy status=closed rows to completed (ENUM does not include closed).
        $this->wpdb->query(
            "UPDATE {$table} SET status = 'completed'
             WHERE status = 'closed'"
        );
        // Allow closed status via existing VARCHAR/ENUM — status is typically VARCHAR; no ALTER needed.
        $idx = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_is_quick'",
            DB_NAME,
            $table
        ));
        if (empty($idx)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_is_quick (is_quick)");
        }
    }

    /**
     * Client channel tracking on users (website / pwa / android_app / solana_seeker).
     */
    public function ensure_users_client_channel_columns() {
        $table = $this->table_prefix . 'users';
        $cols = [
            'client_channel' => "VARCHAR(32) NULL",
            'client_channel_at' => "DATETIME NULL",
        ];
        foreach ($cols as $name => $def) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
            }
        }
    }

    /**
     * contest_submissions: code paths expect updated_at; older installs only had created_at.
     */
    private function ensure_contest_submissions_updated_at_column() {
        $table = $this->table_prefix . 'contest_submissions';
        $exists = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'updated_at'",
            DB_NAME,
            $table
        ));
        if (empty($exists)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        }
    }

    /**
     * Community voting: contests columns, contest_votes, contest_awards, submission counters.
     */
    private function ensure_contest_voting_schema() {
        $contests = $this->table_prefix . 'contests';
        $submissions = $this->table_prefix . 'contest_submissions';
        $comments = $this->table_prefix . 'contest_comments';
        $charset_collate = $this->wpdb->get_charset_collate();

        $contest_columns = [
            'decision_mode' => "ALTER TABLE {$contests} ADD COLUMN decision_mode ENUM('creator','community','random') NOT NULL DEFAULT 'creator' AFTER winner_selection_mode",
            'voting_enabled' => "ALTER TABLE {$contests} ADD COLUMN voting_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER decision_mode",
            'voting_binding_strict' => "ALTER TABLE {$contests} ADD COLUMN voting_binding_strict TINYINT(1) NOT NULL DEFAULT 0 AFTER voting_enabled",
            'voting_hours' => "ALTER TABLE {$contests} ADD COLUMN voting_hours INT NOT NULL DEFAULT 24 AFTER voting_binding_strict",
            'voting_ends_at' => "ALTER TABLE {$contests} ADD COLUMN voting_ends_at DATETIME NULL AFTER voting_hours",
            'voting_visibility' => "ALTER TABLE {$contests} ADD COLUMN voting_visibility ENUM('live','hidden_until_close') NOT NULL DEFAULT 'live' AFTER voting_ends_at",
            'weighting_enabled' => "ALTER TABLE {$contests} ADD COLUMN weighting_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER voting_visibility",
            'vote_gate_enabled' => "ALTER TABLE {$contests} ADD COLUMN vote_gate_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER weighting_enabled",
            'vote_token_mint' => "ALTER TABLE {$contests} ADD COLUMN vote_token_mint VARCHAR(44) NULL AFTER vote_gate_enabled",
            'vote_min_token_amount' => "ALTER TABLE {$contests} ADD COLUMN vote_min_token_amount DECIMAL(18,9) NULL AFTER vote_token_mint",
            'vote_min_token_usd' => "ALTER TABLE {$contests} ADD COLUMN vote_min_token_usd DECIMAL(18,2) NULL AFTER vote_min_token_amount",
            'vote_gate_use_usd' => "ALTER TABLE {$contests} ADD COLUMN vote_gate_use_usd TINYINT(1) NOT NULL DEFAULT 0 AFTER vote_min_token_usd",
            'cover_image_url' => "ALTER TABLE {$contests} ADD COLUMN cover_image_url VARCHAR(500) NULL AFTER attachments",
            'cover_image_meta' => "ALTER TABLE {$contests} ADD COLUMN cover_image_meta LONGTEXT NULL AFTER cover_image_url",
            'like_count' => "ALTER TABLE {$contests} ADD COLUMN like_count INT NOT NULL DEFAULT 0 AFTER total_prize_pool",
            'boost_pool_total' => "ALTER TABLE {$contests} ADD COLUMN boost_pool_total DECIMAL(18,9) NOT NULL DEFAULT 0 AFTER like_count",
        ];
        foreach ($contest_columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $contests,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }

        $status_col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$contests}` LIKE 'status'");
        if ($status_col && strpos((string) $status_col->Type, 'voting') === false) {
            $this->wpdb->query(
                "ALTER TABLE {$contests} MODIFY status ENUM('active','deadline_reached','voting','winner_selection','completed') DEFAULT 'active'"
            );
        }

        $submission_columns = [
            'vote_count' => "ALTER TABLE {$submissions} ADD COLUMN vote_count INT NOT NULL DEFAULT 0 AFTER prize_amount",
            'weighted_score' => "ALTER TABLE {$submissions} ADD COLUMN weighted_score DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER vote_count",
            'share_slug' => "ALTER TABLE {$submissions} ADD COLUMN share_slug VARCHAR(16) NULL AFTER weighted_score",
        ];
        foreach ($submission_columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $submissions,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }

        $slug_idx = $this->wpdb->get_results("SHOW INDEX FROM `{$submissions}` WHERE Key_name = 'uq_share_slug'");
        if (empty($slug_idx)) {
            $this->wpdb->query("ALTER TABLE {$submissions} ADD UNIQUE KEY uq_share_slug (share_slug)");
        }

        $votes_table = $this->table_prefix . 'contest_votes';
        $votes_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $votes_table
        ));
        if (!$votes_exists) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$votes_table} (
                vote_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                submission_id BIGINT UNSIGNED NOT NULL,
                voter_user_id BIGINT UNSIGNED NOT NULL,
                voter_wallet VARCHAR(44) NULL,
                weight DECIMAL(18,9) NOT NULL DEFAULT 1,
                bonus_rank_at_vote ENUM('none','silver','gold') NOT NULL DEFAULT 'none',
                trust_rank_at_vote ENUM('basic','pro','elite') NOT NULL DEFAULT 'basic',
                status ENUM('active','revoked','voided') NOT NULL DEFAULT 'active',
                ip_hash CHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (vote_id),
                UNIQUE KEY uq_voter_submission (voter_user_id, submission_id),
                KEY idx_contest (contest_id),
                KEY idx_submission_status (submission_id, status),
                KEY idx_contest_voter (contest_id, voter_user_id)
            ) {$charset_collate};");
        }

        $awards_table = $this->table_prefix . 'contest_awards';
        $awards_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $awards_table
        ));
        if (!$awards_exists) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$awards_table} (
                award_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                submission_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                award_type ENUM('favourite','top10','top25') NOT NULL,
                votes INT NOT NULL DEFAULT 0,
                weighted_score DECIMAL(18,6) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (award_id),
                KEY idx_user (user_id),
                KEY idx_contest (contest_id),
                KEY idx_user_type (user_id, award_type)
            ) {$charset_collate};");
        }

        $likes_table = $this->table_prefix . 'contest_likes';
        $likes_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $likes_table
        ));
        if (!$likes_exists) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$likes_table} (
                like_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (like_id),
                UNIQUE KEY uq_contest_user (contest_id, user_id),
                KEY idx_user_created (user_id, created_at),
                KEY idx_contest_created (contest_id, created_at)
            ) {$charset_collate};");
        }

        $boosts_table = $this->table_prefix . 'contest_boost_contributions';
        $boosts_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $boosts_table
        ));
        if (!$boosts_exists) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$boosts_table} (
                boost_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                wallet_address VARCHAR(44) NULL,
                amount DECIMAL(18,9) NOT NULL DEFAULT 0,
                payment_hash VARCHAR(100) NULL,
                status ENUM('pending','settled','failed','refunded') NOT NULL DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                settled_at DATETIME NULL,
                PRIMARY KEY (boost_id),
                UNIQUE KEY uq_payment_hash (payment_hash),
                KEY idx_contest_status (contest_id, status),
                KEY idx_user_created (user_id, created_at)
            ) {$charset_collate};");
        }

        $this->wpdb->query(
            "UPDATE {$contests} SET decision_mode = 'random' WHERE winner_selection = 'random' AND decision_mode = 'creator'"
        );

        $comment_columns = [
            'parent_comment_id' => "ALTER TABLE {$comments} ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER user_id",
            'mentions_json' => "ALTER TABLE {$comments} ADD COLUMN mentions_json LONGTEXT NULL AFTER content",
            'reaction_counts_json' => "ALTER TABLE {$comments} ADD COLUMN reaction_counts_json LONGTEXT NULL AFTER mentions_json",
            'updated_at' => "ALTER TABLE {$comments} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($comment_columns as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $comments,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }
        $comment_parent_idx = $this->wpdb->get_results("SHOW INDEX FROM `{$comments}` WHERE Key_name = 'idx_parent'");
        if (empty($comment_parent_idx)) {
            $this->wpdb->query("ALTER TABLE {$comments} ADD KEY idx_parent (parent_comment_id)");
        }

        $reactions_table = $this->table_prefix . 'contest_comment_reactions';
        $reactions_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $reactions_table
        ));
        if (!$reactions_exists) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$reactions_table} (
                reaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contest_id BIGINT UNSIGNED NOT NULL,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                reaction VARCHAR(16) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reaction_id),
                UNIQUE KEY uq_comment_user_reaction (comment_id, user_id, reaction),
                KEY idx_contest_comment (contest_id, comment_id),
                KEY idx_user (user_id)
            ) {$charset_collate};");
        }

        $this->ensure_contest_voting_default_settings();
    }

    /**
     * Default growl_settings keys for community voting (idempotent merge).
     */
    private function ensure_contest_voting_default_settings() {
        $settings = get_option('growl_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $defaults = [
            'vote_earnfi_multipliers' => ['none' => 1.0, 'silver' => 1.5, 'gold' => 2.0],
            'vote_trust_factors' => ['basic' => 0.6, 'pro' => 0.85, 'elite' => 1.0, 'new' => 0.25],
            'vote_weight_cap' => 2.0,
            'vote_random_curve' => 'sqrt',
            'vote_rate_limit_per_min' => 30,
            'vote_min_account_age_hours' => 0,
            'vote_confidence_flag_threshold' => 40,
            'agents_can_vote' => 0,
        ];
        $changed = false;
        foreach ($defaults as $key => $val) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = $val;
                $changed = true;
            }
        }
        if ($changed) {
            update_option('growl_settings', $settings, false);
        }
    }

    private function ensure_tasks_token_gate_columns() {
        $table = $this->table_prefix . 'tasks';
        $add = [
            'token_gate_enabled' => "ALTER TABLE {$table} ADD COLUMN token_gate_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER min_agent_score",
            'token_mint' => "ALTER TABLE {$table} ADD COLUMN token_mint VARCHAR(44) NULL AFTER token_gate_enabled",
            'min_token_amount' => "ALTER TABLE {$table} ADD COLUMN min_token_amount DECIMAL(36,18) NULL AFTER token_mint",
            'min_token_usd' => "ALTER TABLE {$table} ADD COLUMN min_token_usd DECIMAL(18,9) NULL AFTER min_token_amount",
            'token_gate_use_usd' => "ALTER TABLE {$table} ADD COLUMN token_gate_use_usd TINYINT(1) NOT NULL DEFAULT 0 AFTER min_token_usd",
            'require_hold_on_payment' => "ALTER TABLE {$table} ADD COLUMN require_hold_on_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER token_gate_use_usd",
        ];
        foreach ($add as $name => $sql) {
            $exists = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($exists)) {
                $this->wpdb->query($sql);
            }
        }
    }

    private function ensure_tasks_x_account_requirement_column() {
        $table = $this->table_prefix . 'tasks';
        $exists = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'x_account_requirement'",
            DB_NAME,
            $table
        ));
        if (empty($exists)) {
            $this->wpdb->query(
                "ALTER TABLE {$table} ADD COLUMN x_account_requirement ENUM('all','verified_only') NOT NULL DEFAULT 'all'"
            );
        }
    }

    private function ensure_user_x_verification_table() {
        $table = $this->table_prefix . 'user_x_verification';
        $charset_collate = $this->wpdb->get_charset_collate();
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            user_id BIGINT UNSIGNED NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_type VARCHAR(32) NULL,
            last_checked_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (user_id),
            KEY idx_checked (last_checked_at)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    /**
     * Web Push subscriptions (VAPID), one row per browser endpoint.
     */
    private function ensure_push_subscriptions_table() {
        $table = $this->table_prefix . 'push_subscriptions';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint VARCHAR(2048) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            user_agent TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_endpoint (endpoint(512)),
            KEY idx_user (user_id)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    /**
     * Widen endpoint for browsers whose push URLs exceed 512 chars (existing installs).
     */
    private function ensure_push_subscriptions_endpoint_column() {
        $table = $this->table_prefix . 'push_subscriptions';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if (! $exists) {
            return;
        }
        $col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'endpoint'");
        if (! $col || ! isset($col->Type)) {
            return;
        }
        $type = strtolower((string) $col->Type);
        if (strpos($type, 'varchar(512)') !== false || strpos($type, 'varchar(255)') !== false) {
            $this->wpdb->query("ALTER TABLE `{$table}` MODIFY endpoint VARCHAR(2048) NOT NULL");
        }
    }

    /**
     * FCM device tokens for EarnFi Capacitor native app (Android / iOS).
     */
    public function ensure_fcm_tokens_table() {
        $table = $this->table_prefix . 'fcm_tokens';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(512) NOT NULL,
            platform VARCHAR(32) NOT NULL DEFAULT 'android',
            user_agent TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token(191)),
            KEY idx_user (user_id),
            KEY idx_platform (platform)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    private function ensure_reputation_ledger_table() {
        $table = $this->table_prefix . 'reputation_ledger';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            delta INT NOT NULL DEFAULT 0,
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            ref_type VARCHAR(32) NOT NULL DEFAULT '',
            ref_id VARCHAR(64) NOT NULL DEFAULT '',
            idempotency_key VARCHAR(128) NOT NULL DEFAULT '',
            metadata_json LONGTEXT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_idempotency (idempotency_key(128)),
            KEY idx_user_created (user_id, created_at),
            KEY idx_reason (reason_code)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    private function ensure_user_streaks_table() {
        $table = $this->table_prefix . 'user_streaks';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            user_id BIGINT UNSIGNED NOT NULL,
            streak_type VARCHAR(32) NOT NULL DEFAULT 'activity',
            current_count INT UNSIGNED NOT NULL DEFAULT 0,
            best_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_streak_utc VARCHAR(32) NOT NULL DEFAULT '',
            updated_at DATETIME NULL,
            PRIMARY KEY (user_id, streak_type),
            KEY idx_last (last_streak_utc)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    private function ensure_user_achievements_table() {
        $table = $this->table_prefix . 'user_achievements';
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table
        ));
        if ($exists) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            achievement_key VARCHAR(64) NOT NULL,
            tier VARCHAR(24) NOT NULL DEFAULT 'bronze',
            unlocked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_key (user_id, achievement_key(64)),
            KEY idx_user (user_id)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    private function ensure_earnfi_partners_table() {
        $table = $this->table_prefix . 'earnfi_partners';
        $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            $charset_collate = $this->wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                partner_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_slug VARCHAR(64) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                status ENUM('active','paused','disabled') NOT NULL DEFAULT 'active',
                settlement_mode ENUM('direct_x402','proxy_hmac') NOT NULL DEFAULT 'direct_x402',
                identity_provider VARCHAR(32) NOT NULL DEFAULT 'wallet',
                api_key_hash CHAR(64) NULL,
                api_key_prefix VARCHAR(32) NULL,
                api_key_issued_at DATETIME NULL,
                proxy_config_json LONGTEXT NULL,
                metadata_schema_json LONGTEXT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (partner_id),
                UNIQUE KEY uq_partner_slug (partner_slug),
                UNIQUE KEY uq_partner_api_key (api_key_hash),
                KEY idx_partner_status (status)
            ) {$charset_collate}";
            dbDelta($sql);
        }
    }

    private function ensure_ai_agents_partner_id_column() {
        $table = $this->table_prefix . 'ai_agents';
        $col = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'partner_id'",
            DB_NAME,
            $table
        ));
        if (empty($col)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN partner_id BIGINT UNSIGNED NULL AFTER identity_reference");
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX IF NOT EXISTS idx_ai_agents_partner (partner_id, updated_at)");
        }
    }

    private function ensure_tasks_partner_columns() {
        $table = $this->table_prefix . 'tasks';
        foreach (['partner_id' => "BIGINT UNSIGNED NULL AFTER creator_agent_id", 'partner_metadata_json' => "LONGTEXT NULL AFTER partner_id"] as $name => $def) {
            $col = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table,
                $name
            ));
            if (empty($col)) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
            }
        }
        $this->wpdb->query("ALTER TABLE {$table} ADD INDEX IF NOT EXISTS idx_tasks_partner (partner_id, created_at)");
        $this->wpdb->query("ALTER TABLE {$table} ADD INDEX IF NOT EXISTS idx_tasks_partner_agent (partner_id, creator_agent_id, created_at)");
    }

    private function ensure_earnfi_idempotency_composite_key() {
        if (get_option('growl_earnfi_idempotency_composite_migrated', '')) {
            return;
        }
        $table = $this->table_prefix . 'earnfi_idempotency';
        $exists = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            update_option('growl_earnfi_idempotency_composite_migrated', '1', false);
            return;
        }
        $idx = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'uq_idem_key_resource'",
            DB_NAME,
            $table
        ));
        if (empty($idx)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_idem_key_resource (idempotency_key, resource)");
        }
        update_option('growl_earnfi_idempotency_composite_migrated', '1', false);
    }

    private function ensure_recover_sol_earned_usd_column() {
        $table = $this->table_prefix . 'recover_sol_referral_earnings';
        $col = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'earned_usd'",
            DB_NAME,
            $table
        ));
        if (empty($col)) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN earned_usd DECIMAL(18,9) NOT NULL DEFAULT 0.0 AFTER earned_lamports");
        }
    }
    
    private function create_additional_indexes() {
        $indexes = [
            // Users table composite indexes
            "idx_user_activity" => "ALTER TABLE {$this->table_prefix}users ADD INDEX IF NOT EXISTS idx_user_activity (verification_status, is_muted, last_login_at)",
            "idx_earnings_rank" => "ALTER TABLE {$this->table_prefix}users ADD INDEX IF NOT EXISTS idx_earnings_rank (user_rank, total_earned, tasks_completed)",
            "idx_users_leaderboard" => "ALTER TABLE {$this->table_prefix}users ADD INDEX IF NOT EXISTS idx_users_leaderboard (is_muted, total_earned, user_id)",
            
            // Tasks table composite indexes  
            "idx_active_tasks" => "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_active_tasks (status, action_type, created_at)",
            "idx_budget_status" => "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_budget_status (status, total_budget, budget_used)",
            
            // Completions table composite indexes
            "idx_user_earnings" => "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_user_earnings (user_id, status, reward_amount)",
            "idx_task_completion" => "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_task_completion (task_id, user_id, status)",
            
            // Transactions table composite indexes
            "idx_wallet_activity" => "ALTER TABLE {$this->table_prefix}transactions ADD INDEX IF NOT EXISTS idx_wallet_activity (from_wallet, type, created_at)",
            "idx_status_retry" => "ALTER TABLE {$this->table_prefix}transactions ADD INDEX IF NOT EXISTS idx_status_retry (status, retry_count, updated_at)",

            "idx_users_wallet_verification" => 
                "ALTER TABLE {$this->table_prefix}users ADD INDEX IF NOT EXISTS idx_users_wallet_verification (wallet_address, verification_status)",
            
            "idx_tasks_creator_status" => 
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_creator_status (creator_wallet, status, created_at)",
            
            "idx_completions_user_task" => 
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_user_task (user_id, task_id, status)",
            
            "idx_completions_task_user" => 
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_task_user (task_id, user_id, status)",
            
            // Covering indexes for common queries
            "idx_tasks_active_optimized" => 
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_active_optimized (status, action_type, created_at, task_id, base_reward, user_count)",
            
            "idx_users_rank_verification" => 
                "ALTER TABLE {$this->table_prefix}users ADD INDEX IF NOT EXISTS idx_users_rank_verification (user_rank, verification_status, is_muted, user_id)",
            
            // Performance for analytics queries
            "idx_completions_created_status" => 
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_created_status (created_at, status, reward_amount)",

            "idx_completions_status_created_user" =>
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_status_created_user (status, created_at, user_id, reward_amount)",

            "idx_completions_user_created_status" =>
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_user_created_status (user_id, created_at, status)",

            "idx_completions_unlock_scan" =>
                "ALTER TABLE {$this->table_prefix}completions ADD INDEX IF NOT EXISTS idx_completions_unlock_scan (status, locked_until, task_id, user_id)",
            
            "idx_tasks_created_action" => 
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_created_action (created_at, action_type, status)",

            "idx_tasks_status_created" =>
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_status_created (status, created_at)",
            
            "idx_manual_verification_status" =>
                "ALTER TABLE {$this->table_prefix}manual_verifications ADD INDEX IF NOT EXISTS idx_manual_verification_status (task_id, status)",
            
            // Referral earnings optimizations
            "idx_referral_earnings_job" => 
                "ALTER TABLE {$this->table_prefix}referral_earnings ADD INDEX IF NOT EXISTS idx_referral_earnings_job (job_id, earning_type, earned_amount)",

            "idx_referral_earnings_referrer_job" => 
                "ALTER TABLE {$this->table_prefix}referral_earnings ADD INDEX IF NOT EXISTS idx_referral_earnings_referrer_job (referrer_id, job_id, earning_type)",

            // Referrals optimizations
            "idx_referrals_referrer_status" => 
                "ALTER TABLE {$this->table_prefix}referrals ADD INDEX IF NOT EXISTS idx_referrals_referrer_status (referrer_id, status, total_earned)",

            // Dispute performance indexes
            "idx_disputes_user_status" => 
                "ALTER TABLE {$this->table_prefix}disputes ADD INDEX IF NOT EXISTS idx_disputes_user_status (user_id, status, created_at)",
            
            "idx_disputes_creator_status" => 
                "ALTER TABLE {$this->table_prefix}disputes ADD INDEX IF NOT EXISTS idx_disputes_creator_status (creator_wallet, status, created_at)",
            
            "idx_disputes_task_completion" => 
                "ALTER TABLE {$this->table_prefix}disputes ADD INDEX IF NOT EXISTS idx_disputes_task_completion (task_id, completion_id)",
            
            "idx_dispute_messages_dispute_created" => 
                "ALTER TABLE {$this->table_prefix}dispute_messages ADD INDEX IF NOT EXISTS idx_dispute_messages_dispute_created (dispute_id, created_at)",
            
            "idx_dispute_evidence_dispute_created" => 
                "ALTER TABLE {$this->table_prefix}dispute_evidence ADD INDEX IF NOT EXISTS idx_dispute_evidence_dispute_created (dispute_id, created_at)",
            
            // Composite index for dispute list queries
            "idx_disputes_comprehensive" => 
                "ALTER TABLE {$this->table_prefix}disputes ADD INDEX IF NOT EXISTS idx_disputes_comprehensive (user_id, status, created_at, dispute_id)",

            "idx_queue_processing" => 
                "ALTER TABLE {$this->table_prefix}verification_queue ADD INDEX IF NOT EXISTS idx_queue_processing (status, priority DESC, queued_at ASC)",

            "idx_unread_user_optimized" =>
                "ALTER TABLE {$this->table_prefix}notifications ADD INDEX IF NOT EXISTS idx_unread_user_optimized (user_id, is_read, expires_at)",

            "idx_user_status_created" =>
                "ALTER TABLE {$this->table_prefix}withdrawals ADD INDEX IF NOT EXISTS idx_user_status_created (user_id, status, created_at)",

            "idx_tasks_creator_wallet" =>
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_creator_wallet (creator_wallet)",

            "idx_withdrawals_user_status" =>
                "ALTER TABLE {$this->table_prefix}withdrawals ADD INDEX IF NOT EXISTS idx_withdrawals_user_status (user_id, status)",

            "idx_notifications_user_read" =>
                "ALTER TABLE {$this->table_prefix}notifications ADD INDEX IF NOT EXISTS idx_notifications_user_read (user_id, is_read, expires_at)",

            "idx_tasks_manual_jobs" => 
                "ALTER TABLE {$this->table_prefix}tasks ADD INDEX IF NOT EXISTS idx_tasks_manual_jobs (creator_wallet, status, verification_type, verification_method, action_type, task_id)",
            
            // Add this index for manual_verifications
            "idx_manual_verif_task_status" => 
                "ALTER TABLE {$this->table_prefix}manual_verifications ADD INDEX IF NOT EXISTS idx_manual_verif_task_status (task_id, status)",
            
            "idx_contest_submissions" =>
                "ALTER TABLE {$this->table_prefix}contest_submissions ADD INDEX IF NOT EXISTS idx_status (status)",

            "idx_contest_submissions_rank" =>
                "ALTER TABLE {$this->table_prefix}contest_submissions ADD INDEX IF NOT EXISTS idx_rank (rank_position)",

            "idx_contests_status_deadline" =>
                "ALTER TABLE {$this->table_prefix}contests ADD INDEX IF NOT EXISTS idx_status_deadline (status, deadline)",

            "idx_contest_submissions_list" =>
                "ALTER TABLE {$this->table_prefix}contest_submissions ADD INDEX IF NOT EXISTS idx_submission_list (contest_id, status, created_at)",

            "idx_contest_submissions_user_status" =>
                "ALTER TABLE {$this->table_prefix}contest_submissions ADD INDEX IF NOT EXISTS idx_contest_status_user (contest_id, status, user_id)",

            "idx_contests_task_status" =>
                "ALTER TABLE {$this->table_prefix}contests ADD INDEX IF NOT EXISTS idx_task_status (task_id, status)",
        ];
        
        foreach ($indexes as $index_name => $index_sql) {
            // Check if index exists first
            $index_exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(1) FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = %s 
                    AND index_name = %s",
                    $this->table_prefix . str_replace('idx_', '', explode(' ', $index_name)[0]),
                    $index_name
                )
            );
            
            if (!$index_exists) {
                $this->wpdb->query($index_sql);
            }
        }
    }

    private function create_foreign_keys() {
        // ✅ Check if foreign key exists before creating
        $foreign_keys = [
            'fk_referrals_referrer' => [
                'table' => 'referrals',
                'sql' => "ALTER TABLE {$this->table_prefix}referrals 
                        ADD CONSTRAINT fk_referrals_referrer 
                        FOREIGN KEY (referrer_id) REFERENCES {$this->table_prefix}users(user_id) ON DELETE CASCADE"
            ],
            'fk_referrals_referee' => [
                'table' => 'referrals',
                'sql' => "ALTER TABLE {$this->table_prefix}referrals 
                        ADD CONSTRAINT fk_referrals_referee 
                        FOREIGN KEY (referee_id) REFERENCES {$this->table_prefix}users(user_id) ON DELETE CASCADE"
            ],
            'fk_earnings_referrer' => [
                'table' => 'referral_earnings',
                'sql' => "ALTER TABLE {$this->table_prefix}referral_earnings 
                        ADD CONSTRAINT fk_earnings_referrer 
                        FOREIGN KEY (referrer_id) REFERENCES {$this->table_prefix}users(user_id) ON DELETE CASCADE"
            ],
            'fk_earnings_referee' => [
                'table' => 'referral_earnings',
                'sql' => "ALTER TABLE {$this->table_prefix}referral_earnings 
                        ADD CONSTRAINT fk_earnings_referee 
                        FOREIGN KEY (referee_id) REFERENCES {$this->table_prefix}users(user_id) ON DELETE CASCADE"
            ],
            'fk_earnings_job' => [
                'table' => 'referral_earnings',
                'sql' => "ALTER TABLE {$this->table_prefix}referral_earnings 
                        ADD CONSTRAINT fk_earnings_job 
                        FOREIGN KEY (job_id) REFERENCES {$this->table_prefix}tasks(task_id) ON DELETE CASCADE"
            ],
            'fk_earnings_completion' => [
                'table' => 'referral_earnings',
                'sql' => "ALTER TABLE {$this->table_prefix}referral_earnings 
                        ADD CONSTRAINT fk_earnings_completion 
                        FOREIGN KEY (completion_id) REFERENCES {$this->table_prefix}completions(completion_id) ON DELETE SET NULL"
            ],
            'fk_wp_user' => [
                'table' => 'admins',
                'sql' => "ALTER TABLE {$this->table_prefix}admins 
                        ADD CONSTRAINT fk_wp_user 
                        FOREIGN KEY (wp_user_id) REFERENCES {$this->wpdb->users}(ID) ON DELETE SET NULL"
            ],
            'fk_contests_task' => [
                'table' => 'contests',
                'sql' => "ALTER TABLE {$this->table_prefix}contests 
                        ADD CONSTRAINT fk_contests_task 
                        FOREIGN KEY (task_id) REFERENCES {$this->table_prefix}tasks(task_id) ON DELETE CASCADE"
            ]
        ];
        
        foreach ($foreign_keys as $fk_name => $fk_data) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND CONSTRAINT_NAME = %s
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            ", $this->table_prefix . $fk_data['table'], $fk_name));
            
            if (!$exists) {
                $this->wpdb->query($fk_data['sql']);
            }
        }
    }
    public function get_prepared_tasks($where_clause, $params = [], $limit = 100) {
        $table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        
        $sql = "SELECT t.*, u.x_username as creator_x_username 
                FROM $table t
                INNER JOIN $users_table u ON t.creator_wallet = u.wallet_address
                WHERE $where_clause 
                ORDER BY t.created_at DESC LIMIT %d";
        
        $params[] = $limit;
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
    }
    
    /**
     * Optimized query methods with caching
     */
    public function get_user_by_wallet($wallet_address, $use_cache = true) {
        // O(1) cache check
        $cache_key = $this->get_cache_key('user_wallet', $wallet_address);
        
        if ($use_cache && $this->cache_enabled) {
            $cached = $this->cache_get($cache_key, 'growl_users');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $table = $this->table_prefix . 'users';
        // O(1) lookup using UNIQUE index
        $user = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM $table FORCE INDEX (wallet_address) WHERE wallet_address = %s", 
                $wallet_address
            )
        );
        
        if ($use_cache && $this->cache_enabled && $user) {
            $this->cache_set($cache_key, $user, 'growl_users', 600);
        }
        
        return $user;
    }

    public function get_user_by_email($email, $use_cache = true) {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') return null;

        $cache_key = $this->get_cache_key('user_email', $email);
        if ($use_cache && $this->cache_enabled) {
            $cached = $this->cache_get($cache_key, 'growl_users');
            if ($cached !== false) return $cached;
        }

        $table = $this->table_prefix . 'users';
        $user = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM $table WHERE email = %s LIMIT 1", $email)
        );
        if ($use_cache && $this->cache_enabled && $user) {
            $this->cache_set($cache_key, $user, 'growl_users', 600);
        }
        return $user;
    }

    /**
     * Resolve @handle, email, or Solana wallet to a users row.
     *
     * @return object|null
     */
    public function find_user_by_query($query) {
        $q = ltrim(trim((string) $query), '@');
        if ($q === '') {
            return null;
        }
        if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $q)) {
            return $this->get_user_by_wallet($q);
        }
        if (is_email($q)) {
            return $this->get_user_by_email($q);
        }
        $table = $this->table_prefix . 'users';
        $id_lower = strtolower($q);
        $slug_hyphen = str_replace('_', '-', $id_lower);
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE (
                     (profile_slug IS NOT NULL AND profile_slug <> '' AND LOWER(TRIM(profile_slug)) IN (%s, %s))
                     OR (x_username IS NOT NULL AND x_username <> '' AND LOWER(TRIM(x_username)) = %s)
                 )
                 LIMIT 1",
                $id_lower,
                $slug_hyphen,
                $id_lower
            )
        );
    }

    public function get_user_by_google_sub($sub, $use_cache = true) {
        $sub = is_string($sub) ? trim($sub) : '';
        if ($sub === '') return null;

        $cache_key = $this->get_cache_key('user_google', $sub);
        if ($use_cache && $this->cache_enabled) {
            $cached = $this->cache_get($cache_key, 'growl_users');
            if ($cached !== false) return $cached;
        }

        $table = $this->table_prefix . 'users';
        $user = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM $table WHERE google_sub = %s LIMIT 1", $sub)
        );
        if ($use_cache && $this->cache_enabled && $user) {
            $this->cache_set($cache_key, $user, 'growl_users', 600);
        }
        return $user;
    }

    /**
     * EarnFi AI Agent API — profile lookup by Solana wallet (base58).
     *
     * @param string $wallet_address
     * @return object|null
     */
    public function get_ai_agent_by_wallet($wallet_address) {
        $wallet_address = is_string($wallet_address) ? trim($wallet_address) : '';
        if ($wallet_address === '') {
            return null;
        }
        $table = $this->table_prefix . 'ai_agents';
        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE wallet_address = %s LIMIT 1", $wallet_address)
        );
    }

    /**
     * Insert or update an AI agent row (unique wallet).
     *
     * @param array $data agent_id, wallet_address, agent_name, identity_provider?, identity_reference?, capabilities? (array)
     * @return string|false agent_id on success
     */
    public function upsert_ai_agent(array $data) {
        $wallet = isset($data['wallet_address']) ? trim((string) $data['wallet_address']) : '';
        $agent_name = isset($data['agent_name']) ? sanitize_text_field($data['agent_name']) : '';
        if ($wallet === '' || $agent_name === '') {
            return false;
        }
        $identity_provider = isset($data['identity_provider']) ? sanitize_text_field($data['identity_provider']) : 'wallet';
        $identity_reference = isset($data['identity_reference']) ? sanitize_text_field($data['identity_reference']) : '';
        $partner_id = isset($data['partner_id']) ? intval($data['partner_id']) : null;
        if ($partner_id !== null && $partner_id <= 0) {
            $partner_id = null;
        }
        $capabilities = isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : [];
        $agent_id = isset($data['agent_id']) ? sanitize_text_field($data['agent_id']) : '';
        $row = $this->get_ai_agent_by_wallet($wallet);
        if ($row) {
            $cap_json = wp_json_encode($capabilities, JSON_UNESCAPED_UNICODE);
            if (strlen($cap_json) > 10000) {
                $cap_json = wp_json_encode(['error' => 'Data too large', 'truncated' => true]);
            }
            $upd = [
                'agent_name' => $agent_name,
                'identity_provider' => $identity_provider,
                'identity_reference' => $identity_reference !== '' ? $identity_reference : null,
                'capabilities' => $cap_json,
            ];
            if ($partner_id !== null) {
                $upd['partner_id'] = $partner_id;
            }
            $this->update('ai_agents', $upd, ['wallet_address' => $wallet]);
            return $row->agent_id;
        }
        if ($agent_id === '') {
            $agent_id = 'ef_agent_' . substr(hash('sha256', $wallet . '|' . wp_rand()), 0, 12);
        }
        $ins = [
            'agent_id' => $agent_id,
            'wallet_address' => $wallet,
            'agent_name' => $agent_name,
            'identity_provider' => $identity_provider,
            'identity_reference' => $identity_reference !== '' ? $identity_reference : null,
            'capabilities' => $capabilities,
        ];
        if ($partner_id !== null) {
            $ins['partner_id'] = $partner_id;
        }
        $ok = $this->insert('ai_agents', $ins);
        return $ok === false ? false : $agent_id;
    }

    /**
     * One-time migration from earnfi_agent_profiles option into ai_agents table.
     */
    private function migrate_earnfi_agent_profiles_to_ai_agents_table() {
        if (get_option('growl_ai_agents_option_migrated', '')) {
            return;
        }
        $profiles = get_option('earnfi_agent_profiles', null);
        if (!is_array($profiles) || empty($profiles)) {
            update_option('growl_ai_agents_option_migrated', '1', false);
            return;
        }
        $table  = $this->table_prefix . 'ai_agents';
        $all_ok = true;
        foreach ($profiles as $wallet => $row) {
            if (!is_string($wallet) || $wallet === '' || !is_array($row)) {
                continue;
            }
            $exists_id = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$table} WHERE wallet_address = %s", $wallet));
            if ($exists_id) {
                continue;
            }
            $agent_id = isset($row['agent_id']) ? sanitize_text_field($row['agent_id']) : '';
            if ($agent_id === '') {
                continue;
            }
            $caps = isset($row['capabilities']) && is_array($row['capabilities']) ? $row['capabilities'] : [];
            $name = isset($row['agent_name']) ? sanitize_text_field($row['agent_name']) : 'migrated_agent';
            $id_prov = isset($row['identity_provider']) ? sanitize_text_field($row['identity_provider']) : 'wallet';
            $id_ref = '';
            if (isset($row['external_identity'])) {
                $id_ref = sanitize_text_field($row['external_identity']);
            } elseif (isset($row['identity_reference'])) {
                $id_ref = sanitize_text_field($row['identity_reference']);
            }
            $ins = $this->insert('ai_agents', [
                'agent_id' => $agent_id,
                'wallet_address' => $wallet,
                'agent_name' => $name,
                'identity_provider' => $id_prov,
                'identity_reference' => $id_ref !== '' ? $id_ref : null,
                'capabilities' => $caps,
            ]);
            if ($ins === false) {
                $all_ok = false;
            }
        }
        if ($all_ok) {
            delete_option('earnfi_agent_profiles');
        }
        update_option('growl_ai_agents_option_migrated', '1', false);
    }
    
    public function get_user_by_x_id($x_id, $use_cache = true) {
        $cache_key = $this->get_cache_key('user_xid', $x_id);
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_users');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $table = $this->table_prefix . 'users';
        // OPTIMIZED: Force index usage for O(1) lookup
        $user = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM $table FORCE INDEX (x_id) WHERE x_id = %s", 
                $x_id
            )
        );
        
        if ($use_cache && $this->cache_enabled && $user) {
            wp_cache_set($cache_key, $user, 'growl_users', 300);
        }
        
        return $user;
    }
    
    /**
     * CRITICAL FIX: Optimized active tasks query - NO N+1
     * Single query with JOIN to get creator info
     */
    public function get_active_tasks_optimized($filters = [], $use_cache = true) {
        // Suffix busts cache when ordering rules change (e.g. token-gate feed boost).
        $cache_key = $this->get_cache_key('active_tasks', md5(json_encode($filters) . '|feed_v2'));
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_tasks');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $tasks_table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        $contests_table = $this->table_prefix . 'contests';
        
        // Base query with all contest data
        $sql = "SELECT 
                    t.*, 
                    u.x_username as creator_x_username,
                    u.reputation_score as creator_reputation_score,
                    a.agent_name as creator_agent_name,
                    a.wallet_address as agent_wallet_address,
                    a.identity_provider as agent_identity_provider,
                    a.identity_reference as agent_identity_reference,
                    a.capabilities as agent_capabilities,
                    c.contest_id,
                    c.title as contest_title,
                    c.total_prize_pool,
                    c.participants_type,
                    c.max_participants,
                    c.deadline as contest_deadline,
                    c.status as contest_status,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM {$this->table_prefix}contest_submissions cs
                        WHERE cs.contest_id = c.contest_id
                          AND cs.status IN ('submitted', 'under_review', 'winner', 'loser')
                    ), 0) as contest_participants_count,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM {$this->table_prefix}contest_comments cc
                        WHERE cc.contest_id = c.contest_id
                    ), 0) as contest_comment_count
                FROM $tasks_table t
                INNER JOIN $users_table u ON t.creator_wallet = u.wallet_address
                LEFT JOIN {$this->table_prefix}ai_agents a ON t.creator_agent_id = a.agent_id
                LEFT JOIN $contests_table c ON t.task_id = c.task_id";
        
        $where = [];
        $params = [];
        
        // Jobs only: exclude contests (for public open/closed job carousels)
        $jobs_only = !empty($filters['jobs_only']);

        // Status filter - handle different categories
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'completed') {
                if ($jobs_only) {
                    $where[] = "(
                        (t.status = 'completed' AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND t.completed_count >= t.user_count AND c.contest_id IS NULL)
                    )";
                } else {
                    // CLOSED JOBS category: Show completed regular jobs AND completed contests
                    $where[] = "(
                        (t.status = 'completed' AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND t.completed_count >= t.user_count AND c.contest_id IS NULL) OR
                        (c.status = 'completed')
                    )";
                }
                
            } elseif ($filters['status'] === 'active') {
                if ($jobs_only) {
                    $where[] = "(t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL)";
                } else {
                    // OPEN JOBS category: Show active regular jobs AND active contests
                    $where[] = "(
                        (t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection'))
                    )";
                }
                
            } elseif ($filters['status'] === 'all') {
                // Show all active tasks (for admin or other use cases)
                $where[] = "t.status = 'active'";
                
            } else {
                // Specific status
                $where[] = "t.status = %s";
                $params[] = $filters['status'];
            }
        } else {
            if ($jobs_only) {
                $where[] = "(t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL)";
            } else {
                // Default: show active jobs and contests
                $where[] = "(
                    (t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL) OR
                    (t.status = 'active' AND c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection'))
                )";
            }
        }
        
        // Action type filter - handle special categories
        if (!empty($filters['action_type'])) {
            $action_types = explode(',', $filters['action_type']);
            
            // Check if this is for contests category
            $is_contest_category = false;
            foreach ($action_types as $type) {
                $t = trim($type);
                if ($t === 'custom_job' || $t === 'contest') {
                    $is_contest_category = true;
                    break;
                }
            }
            
            if ($is_contest_category && count(array_intersect(array_map('trim', $action_types), ['custom_job', 'contest'])) > 0) {
                // Contest rows: action_type contest (or legacy custom_job) with linked contest
                $where[] = "(t.action_type IN ('contest','custom_job'))";
                $where[] = "c.contest_id IS NOT NULL";
                $where[] = "c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection')";
                
                // Remove contest selectors from the array to avoid duplicate IN (...) on action_type
                $action_types = array_filter($action_types, function($type) {
                    $t = trim($type);
                    return $t !== 'custom_job' && $t !== 'contest';
                });
                
                if (!empty($action_types)) {
                    $placeholders = implode(',', array_fill(0, count($action_types), '%s'));
                    $where[] = "t.action_type IN ($placeholders)";
                    $params = array_merge($params, $action_types);
                }
            } else if (count($action_types) > 1) {
                // Multiple action types (e.g., social jobs)
                $placeholders = implode(',', array_fill(0, count($action_types), '%s'));
                $where[] = "t.action_type IN ($placeholders)";
                $params = array_merge($params, $action_types);
            } else if (!empty($action_types[0])) {
                // Single action type
                $where[] = "t.action_type = %s";
                $params[] = trim($action_types[0]);
            }
        }
        
        // VIDEO VIEW category filter
        if (!empty($filters['category']) && $filters['category'] === 'video') {
            $where[] = "t.action_type = 'video'";
            $where[] = "t.status = 'active'";
            $where[] = "t.completed_count < t.user_count";
        }
        
        // SOCIAL JOBS category filter
        if (!empty($filters['category']) && $filters['category'] === 'social') {
            $social_types = ['follow', 'comment', 'quote', 'repost', 'like', 'like_comment', 'comment_retweet', 'like_retweet', 'like_comment_retweet'];
            $placeholders = implode(',', array_fill(0, count($social_types), '%s'));
            $where[] = "t.action_type IN ($placeholders)";
            $params = array_merge($params, $social_types);
            $where[] = "t.status = 'active'";
            $where[] = "t.completed_count < t.user_count";
            $where[] = "c.contest_id IS NULL"; // Exclude contests
        }
        
        // MANUAL JOBS category filter
        if (!empty($filters['category']) && in_array($filters['category'], ['manual', 'website_signup', 'zealy_invite', 'discord_invite', 'telegram_group', 'telegram_channel', 'custom_job', 'interrupt'], true)) {
            if ($filters['category'] === 'manual') {
                $manual_types = ['zealy_invite', 'website_signup', 'discord_invite', 'telegram_group', 'telegram_channel', 'custom_job', 'interrupt'];
                $placeholders = implode(',', array_fill(0, count($manual_types), '%s'));
                $where[] = "t.action_type IN ($placeholders)";
                $params = array_merge($params, $manual_types);
            } else {
                // Specific manual job type
                $where[] = "t.action_type = %s";
                $params[] = $filters['category'];
            }
            $where[] = "t.status = 'active'";
            $where[] = "t.completed_count < t.user_count";
            $where[] = "c.contest_id IS NULL"; // Exclude contests
        }
        
        // CONTESTS category filter
        if (!empty($filters['category']) && $filters['category'] === 'contest') {
            $where[] = "t.action_type IN ('contest','custom_job')";
            $where[] = "c.contest_id IS NOT NULL";
            $where[] = "c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection')";
            $where[] = "t.status = 'active'";
        }
        
        // HIGHEST REWARD category filter
        if (!empty($filters['category']) && $filters['category'] === 'high_reward') {
            // Don't add specific WHERE for this category - sorting will handle it
        }

        // Instant Jobs / effort filters
        if (!empty($filters['is_quick']) || !empty($filters['quick']) || (!empty($filters['category']) && $filters['category'] === 'instant')) {
            $where[] = "t.is_quick = 1";
        }
        if (!empty($filters['effort_bucket'])) {
            $where[] = "t.effort_bucket = %s";
            $params[] = sanitize_text_field((string) $filters['effort_bucket']);
        }

        // Client targeting: hide jobs whose target_clients exclude this user's channel
        if (!empty($filters['client_channel'])) {
            $ch = sanitize_key((string) $filters['client_channel']);
            $where[] = "(t.target_clients IS NULL OR t.target_clients = '' OR t.target_clients = '[]' OR t.target_clients LIKE %s)";
            $params[] = '%' . $this->wpdb->esc_like('"' . $ch . '"') . '%';
        }
        
        // Add WHERE clause if we have conditions
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        // Token-gated jobs rank first (discovery boost), then existing sort within each tier.
        $tg_order = "CASE WHEN COALESCE(t.token_gate_enabled,0) = 1 AND t.token_mint IS NOT NULL AND TRIM(t.token_mint) <> '' THEN 1 ELSE 0 END DESC";

        // Sort handling - optimized for different categories
        $order_by = $tg_order . ", t.created_at DESC";
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'reward_high':
                    // For HIGHEST REWARD category: sort by reward/prize pool
                    $order_by = $tg_order . ",
                        CASE 
                            WHEN c.contest_id IS NOT NULL THEN c.total_prize_pool 
                            ELSE t.base_reward 
                        END DESC, 
                        t.created_at DESC";
                    break;
                case 'reward_low':
                    $order_by = $tg_order . ", t.base_reward ASC, t.created_at DESC";
                    break;
                case 'progress':
                    // Pre-calculate progress to avoid division in ORDER BY
                    $order_by = $tg_order . ",
                        CASE 
                            WHEN t.user_count > 0 THEN t.completed_count / t.user_count 
                            ELSE 0 
                        END DESC, 
                        t.created_at DESC";
                    break;
                case 'newest':
                default:
                    $order_by = $tg_order . ", t.created_at DESC";
                    break;
            }
        } else if (!empty($filters['category']) && $filters['category'] === 'high_reward') {
            // Default sorting for HIGHEST REWARD category
            $order_by = $tg_order . ",
                CASE 
                    WHEN c.contest_id IS NOT NULL THEN c.total_prize_pool 
                    ELSE t.base_reward 
                END DESC, 
                t.created_at DESC";
        }
        
        $sql .= " ORDER BY $order_by";
        
        // Pagination
        if (!empty($filters['page']) && !empty($filters['per_page'])) {
            $offset = ($filters['page'] - 1) * $filters['per_page'];
            $sql .= " LIMIT %d OFFSET %d";
            $params[] = intval($filters['per_page']);
            $params[] = intval($offset);
        } else {
            $sql .= " LIMIT 100";
        }
        
        // Force index hints for optimal performance
        $sql = str_replace(
            'FROM ' . $tasks_table . ' t',
            'FROM ' . $tasks_table . ' t FORCE INDEX (idx_status, idx_active_tasks, idx_tasks_creator_status)',
            $sql
        );
        
        // Prepare the statement if we have parameters
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        // Log slow queries for optimization
        $start_time = microtime(true);
        $results = $this->wpdb->get_results($sql);
        $execution_time = (microtime(true) - $start_time) * 1000;
        
        if ($execution_time > 100) {
            error_log("GROWL_SLOW_TASK_QUERY ({$execution_time}ms): " . substr($sql, 0, 200));
        }
        
        // Post-process results to ensure contests are shown correctly
        $processed_results = [];
        foreach ($results as $task) {
            // Ensure contests are properly marked
            if (!empty($task->contest_id)) {
                $task->is_contest = true;
                // For contests, the task should show as contest, not as custom_job
                $task->display_type = 'contest';
            } else {
                $task->is_contest = false;
                $task->display_type = $task->action_type;
            }
            $processed_results[] = $task;
        }
        
        if ($use_cache && $this->cache_enabled) {
            wp_cache_set($cache_key, $processed_results, 'growl_tasks', 180);
        }
        
        return $processed_results;
    }

    /**
     * One-time: contest-linked tasks use action_type contest (was custom_job).
     *
     * @return int Rows updated
     */
    public function migrate_contest_task_action_types() {
        $tasks = $this->table_prefix . 'tasks';
        $contests = $this->table_prefix . 'contests';
        $sql = "UPDATE {$tasks} t
                INNER JOIN {$contests} c ON t.task_id = c.task_id
                SET t.action_type = 'contest'
                WHERE t.action_type = 'custom_job'";
        $n = (int) $this->wpdb->query($sql);
        if ($n > 0) {
            error_log('GROWL_MIGRATION: contest action_type updated rows=' . $n);
        }
        return $n;
    }

    public function get_active_tasks_count($filters = []) {
        $table = $this->table_prefix . 'tasks';
        $contests_table = $this->table_prefix . 'contests';
        $cache_key = $this->get_cache_key('tasks_count', md5(json_encode($filters) . '|jobs_aware_v2'));

        if ($this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_tasks');
            if ($cached !== false) {
                return intval($cached);
            }
        }

        $jobs_only = !empty($filters['jobs_only']);
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'completed') {
                if ($jobs_only) {
                    $where[] = "(
                        (t.status = 'completed' AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND t.completed_count >= t.user_count AND c.contest_id IS NULL)
                    )";
                } else {
                    $where[] = "(
                        (t.status = 'completed' AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND t.completed_count >= t.user_count AND c.contest_id IS NULL) OR
                        (c.status = 'completed')
                    )";
                }
            } elseif ($filters['status'] === 'active') {
                if ($jobs_only) {
                    $where[] = "(t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL)";
                } else {
                    $where[] = "(
                        (t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL) OR
                        (t.status = 'active' AND c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection'))
                    )";
                }
            } elseif ($filters['status'] === 'all') {
                $where[] = "t.status = 'active'";
            } else {
                $where[] = "t.status = %s";
                $params[] = $filters['status'];
            }
        } else {
            if ($jobs_only) {
                $where[] = "(t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL)";
            } else {
                $where[] = "(
                    (t.status = 'active' AND t.completed_count < t.user_count AND c.contest_id IS NULL) OR
                    (t.status = 'active' AND c.status IN ('active', 'deadline_reached', 'voting', 'winner_selection'))
                )";
            }
        }

        if (!empty($filters['action_type'])) {
            $action_types = array_filter(array_map('trim', explode(',', $filters['action_type'])));
            if (!empty($action_types)) {
                $placeholders = implode(',', array_fill(0, count($action_types), '%s'));
                $where[] = "t.action_type IN ($placeholders)";
                $params = array_merge($params, $action_types);
            }
        }

        if (!empty($filters['category']) && $filters['category'] === 'video') {
            $where[] = "t.action_type = 'video'";
            $where[] = "t.status = 'active'";
            $where[] = "t.completed_count < t.user_count";
            if ($jobs_only) {
                $where[] = "c.contest_id IS NULL";
            }
        }

        if (!empty($filters['min_reward'])) {
            $where[] = "t.base_reward >= %f";
            $params[] = floatval($filters['min_reward']);
        }

        if (!empty($filters['max_reward'])) {
            $where[] = "t.base_reward <= %f";
            $params[] = floatval($filters['max_reward']);
        }

        if (!empty($filters['min_rank'])) {
            $rank_map = ['basic' => 1, 'pro' => 2, 'elite' => 3];
            $min_rank_value = $rank_map[strtolower($filters['min_rank'])] ?? 1;
            $where[] = "(
                CASE t.min_rank
                    WHEN 'basic' THEN 1
                    WHEN 'pro' THEN 2
                    WHEN 'elite' THEN 3
                    ELSE 1
                END >= %d
            )";
            $params[] = $min_rank_value;
        }

        if (!empty($filters['is_quick']) || !empty($filters['quick']) || (!empty($filters['category']) && $filters['category'] === 'instant')) {
            $where[] = "t.is_quick = 1";
        }
        if (!empty($filters['effort_bucket'])) {
            $where[] = "t.effort_bucket = %s";
            $params[] = sanitize_text_field((string) $filters['effort_bucket']);
        }
        if (!empty($filters['client_channel'])) {
            $ch = sanitize_key((string) $filters['client_channel']);
            $where[] = "(t.target_clients IS NULL OR t.target_clients = '' OR t.target_clients = '[]' OR t.target_clients LIKE %s)";
            $params[] = '%' . $this->wpdb->esc_like('"' . $ch . '"') . '%';
        }

        $where_clause = !empty($where) ? implode(' AND ', $where) : '1=1';
        $sql = "SELECT COUNT(*) as total
                FROM $table t
                LEFT JOIN $contests_table c ON t.task_id = c.task_id
                WHERE $where_clause";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $total = intval($this->wpdb->get_var($sql));

        if ($this->cache_enabled) {
            wp_cache_set($cache_key, $total, 'growl_tasks', 300);
        }

        return $total;
    }

    public function get_tasks_by_category($category, $filters = [], $use_cache = true) {
        $cache_key = $this->get_cache_key('tasks_category', $category . '_' . md5(json_encode($filters)));
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_tasks');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        
        $where = ["t.status = 'active'", "t.expires_at > NOW()"];
        $params = [];
        
        // Filter by job category
        switch ($category) {
            case 'auto_verified':
                $where[] = "t.verification_type = 'auto'";
                break;
            case 'manual':
                $where[] = "t.verification_type = 'manual'";
                break;
            case 'multi_action':
                $where[] = "t.verification_type = 'hybrid'";
                break;
        }
        
        if (!empty($filters['action_type'])) {
            $where[] = "t.action_type = %s";
            $params[] = $filters['action_type'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT t.*, u.x_username as creator_x_username 
                FROM $table t
                INNER JOIN $users_table u ON t.creator_wallet = u.wallet_address
                WHERE $where_clause 
                ORDER BY t.created_at DESC 
                LIMIT 100";
        
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        $results = $this->wpdb->get_results($sql);
        
        if ($use_cache && $this->cache_enabled) {
            wp_cache_set($cache_key, $results, 'growl_tasks', 180);
        }
        
        return $results;
    }

    public function get_creator_jobs_optimized($creator_wallet, $filters = []) {
        $cache_key = $this->get_cache_key('creator_jobs', $creator_wallet . '_' . md5(json_encode($filters)));
        
        if ($this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_creator_jobs');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $tasks_table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        
        // OPTIMIZED: Use covering index and simpler query - O(log n)
        $sql = "SELECT 
                    t.task_id,
                    t.action_type,
                    t.status,
                    t.base_reward,
                    t.user_count,
                    t.completed_count,
                    t.created_at,
                    t.expires_at,
                    u.x_username as creator_x_username
                FROM $tasks_table t
                FORCE INDEX (idx_creator_status)
                INNER JOIN $users_table u ON t.creator_wallet = u.wallet_address
                WHERE t.creator_wallet = %s";
        
        $params = [$creator_wallet];
        
        if (!empty($filters['status'])) {
            $sql .= " AND t.status = %s";
            $params[] = $filters['status'];
        }
        
        $sql .= " ORDER BY t.created_at DESC LIMIT 500";
        
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        
        // ✅ FIXED: Handle CHAR(8) task IDs properly for IN clause
        if (!empty($results)) {
            $task_ids = array_column($results, 'task_id');
            
            // ✅ Use proper string formatting for CHAR(8) IDs
            $placeholders = implode(',', array_fill(0, count($task_ids), '%s'));
            
            $verifications_table = $this->table_prefix . 'manual_verifications';
            $pending_counts = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT task_id, COUNT(*) as count 
                    FROM $verifications_table 
                    WHERE task_id IN ($placeholders) AND status = 'pending'
                    GROUP BY task_id",
                    $task_ids
                ),
                OBJECT_K
            );
            
            // Attach counts to results - O(n)
            foreach ($results as $task) {
                $task->pending_verifications = isset($pending_counts[$task->task_id]) 
                    ? (int)$pending_counts[$task->task_id]->count 
                    : 0;
            }
        }
        
        if ($this->cache_enabled) {
            wp_cache_set($cache_key, $results, 'growl_creator_jobs', 300);
        }
        
        return $results;
    }

    /**
     * Optimized paged creator jobs with manual verifications count — O(1) per page
     */
    public function get_creator_jobs_optimized_paged($creator_wallet, $filters = [], $limit = 20, $offset = 0) {
        $creator_wallet = is_string($creator_wallet) ? trim($creator_wallet) : '';
        if ($creator_wallet === '') {
            return [
                'results' => [],
                'total' => 0,
                'total_pending_verifications' => 0,
            ];
        }

        $tasks_table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        $completions_table = $this->table_prefix . 'completions';
        $verifications_table = $this->table_prefix . 'manual_verifications';
        $contests_table = $this->table_prefix . 'contests';

        $where = ["t.creator_wallet = %s"];
        $params = [$creator_wallet];

        // Status filter push-down
        if (!empty($filters['status'])) {
            $where[] = "t.status = %s";
            $params[] = $filters['status'];
        }

        // Search: task id, action type, job title, contest title (requires contests join in main query)
        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            if ($s !== '') {
                $like = '%' . $this->wpdb->esc_like($s) . '%';
                $where[] = '(t.task_id LIKE %s OR t.action_type LIKE %s OR COALESCE(t.title, \'\') LIKE %s OR COALESCE(cts.title, \'\') LIKE %s)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $where_clause = implode(' AND ', $where);

        // Single query using COUNT & window function-style subquery — avoids N+1
        // Now includes contest data
        $sql = "
            SELECT 
                t.*,
                u.x_username as creator_x_username,
                cts.contest_id,
                cts.mode as contest_mode,
                cts.title as contest_title,
                cts.total_prize_pool,
                cts.participants_type,
                cts.max_participants,
                COALESCE(c_stats.actual_completions, 0) as actual_completions,
                COALESCE(v_stats.pending_verifications, 0) as pending_verifications,
                COALESCE(contest_subs.submission_count, 0) as contest_submissions
            FROM {$tasks_table} t
            INNER JOIN {$users_table} u ON t.creator_wallet = u.wallet_address
            LEFT JOIN {$contests_table} cts ON t.task_id = cts.task_id
            LEFT JOIN (
                SELECT task_id, COUNT(*) as actual_completions
                FROM {$completions_table}
                WHERE status IN ('unlocked', 'locked')
                GROUP BY task_id
            ) c_stats ON t.task_id = c_stats.task_id
            LEFT JOIN (
                SELECT task_id, COUNT(*) as pending_verifications
                FROM {$verifications_table}
                WHERE status = 'pending'
                GROUP BY task_id
            ) v_stats ON t.task_id = v_stats.task_id
            LEFT JOIN (
                SELECT task_id, COUNT(*) as submission_count
                FROM {$this->table_prefix}contest_submissions
                WHERE status IN ('submitted', 'under_review', 'winner', 'loser')
                GROUP BY task_id
            ) contest_subs ON t.task_id = contest_subs.task_id
            WHERE {$where_clause}
            ORDER BY t.created_at DESC
            LIMIT %d OFFSET %d";

        $paged_params = array_merge($params, [$limit, $offset]);
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $paged_params));

        // Total count (cached for 5 mins) — must use same joins when search touches contest title
        $count_cache_key = 'creator_total_jobs_' . md5($creator_wallet . serialize($filters));
        $total = wp_cache_get($count_cache_key, 'growl_creator_jobs');
        if ($total === false) {
            if (!empty($filters['search']) && trim((string) $filters['search']) !== '') {
                $count_sql = "
                    SELECT COUNT(DISTINCT t.task_id)
                    FROM {$tasks_table} t
                    INNER JOIN {$users_table} u ON t.creator_wallet = u.wallet_address
                    LEFT JOIN {$contests_table} cts ON t.task_id = cts.task_id
                    WHERE {$where_clause}";
            } else {
                $count_sql = "SELECT COUNT(*) FROM {$tasks_table} t WHERE {$where_clause}";
            }
            $total = intval($this->wpdb->get_var($this->wpdb->prepare($count_sql, $params)));
            wp_cache_set($count_cache_key, $total, 'growl_creator_jobs', 300);
        }

        // Total pending verifications (for badge) - include contest submissions
        $pending_verif_cache_key = 'creator_pending_verif_' . md5($creator_wallet);
        $total_pending = wp_cache_get($pending_verif_cache_key, 'growl_creator_jobs');
        if ($total_pending === false) {
            $pending_sql = "
                SELECT COUNT(*) as total_pending FROM (
                    SELECT 1 FROM {$verifications_table} v
                    INNER JOIN {$tasks_table} t ON v.task_id = t.task_id
                    WHERE t.creator_wallet = %s AND v.status = 'pending'
                    UNION ALL
                    SELECT 1 FROM {$this->table_prefix}contest_submissions cs
                    INNER JOIN {$tasks_table} t ON cs.task_id = t.task_id
                    WHERE t.creator_wallet = %s AND cs.status IN ('submitted', 'under_review')
                ) as all_pending";
            $total_pending = intval($this->wpdb->get_var($this->wpdb->prepare($pending_sql, [$creator_wallet, $creator_wallet])));
            wp_cache_set($pending_verif_cache_key, $total_pending, 'growl_creator_jobs', 300);
        }

        return [
            'results' => $results,
            'total' => $total,
            'total_pending_verifications' => $total_pending
        ];
    }

    /**
     * Optimized analytics: uses covering indexes + avoids full table scans — O(log n)
     */
    public function get_creator_analytics_optimized($creator_wallet, $period = '30d') {
        $tasks_table = $this->table_prefix . 'tasks';
        $completions_table = $this->table_prefix . 'completions';
        $contests_table = $this->table_prefix . 'contests';

        // Compute date threshold
        $days = $period === '7d' ? 7 : ($period === '90d' ? 90 : 30);
        $date_condition = 'DATE_SUB(NOW(), INTERVAL ' . intval($days) . ' DAY)';

        // Spending trend: group by day - CORRECTED to avoid double counting
        $spending_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                DATE(t.created_at) as date, 
                -- Total spending (contest + non-contest)
                SUM(t.total_budget) as daily_spent,
                -- Contest spending (only from contests)
                SUM(CASE WHEN c.contest_id IS NOT NULL THEN c.total_prize_pool ELSE 0 END) as contest_spent,
                -- Standard spending (only from non-contest tasks)
                SUM(CASE WHEN c.contest_id IS NULL THEN t.total_budget ELSE 0 END) as standard_spent,
                -- Per-day completions for performance trend
                SUM(t.completed_count) as completions
            FROM {$tasks_table} t
            LEFT JOIN {$contests_table} c ON t.task_id = c.task_id
            WHERE t.creator_wallet = %s AND t.created_at >= {$date_condition}
            GROUP BY DATE(t.created_at)
            ORDER BY date ASC", [$creator_wallet]));

        // ✅ CRITICAL FIX: Job type distribution - custom jobs are either Contest OR Custom Job, not both
        $job_type_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                CASE 
                    WHEN t.action_type = 'custom_job' AND c.contest_id IS NOT NULL THEN 'Contest'
                    WHEN t.action_type = 'custom_job' THEN 'Custom Job'
                    ELSE t.action_type 
                END as display_type,
                COUNT(*) as count, 
                SUM(CASE 
                    WHEN t.action_type = 'custom_job' AND c.contest_id IS NOT NULL THEN c.total_prize_pool
                    ELSE t.total_budget 
                END) as total_spent,
                SUM(t.completed_count) as completions,
                -- Use a single category to avoid duplication
                CASE 
                    WHEN t.action_type = 'custom_job' AND c.contest_id IS NOT NULL THEN 'contest'
                    WHEN t.action_type = 'custom_job' THEN 'custom_job_standard'
                    ELSE t.action_type 
                END as category_type
            FROM {$tasks_table} t
            LEFT JOIN {$contests_table} c ON t.task_id = c.task_id
            WHERE t.creator_wallet = %s
            GROUP BY 
                CASE 
                    WHEN t.action_type = 'custom_job' AND c.contest_id IS NOT NULL THEN 'Contest'
                    WHEN t.action_type = 'custom_job' THEN 'Custom Job'
                    ELSE t.action_type 
                END,
                CASE 
                    WHEN t.action_type = 'custom_job' AND c.contest_id IS NOT NULL THEN 'contest'
                    WHEN t.action_type = 'custom_job' THEN 'custom_job_standard'
                    ELSE t.action_type 
                END
            HAVING COUNT(*) > 0
            ORDER BY total_spent DESC", [$creator_wallet]));

        // ✅ Contest-specific metrics (only from contest table, not double counting)
        $contest_metrics = $this->wpdb->get_row($this->wpdb->prepare("
            SELECT 
                COUNT(DISTINCT c.contest_id) as total_contests,
                SUM(c.total_prize_pool) as total_contest_spent,
                AVG(c.total_prize_pool) as avg_contest_prize,
                COALESCE(SUM(cs.submission_count), 0) as total_contest_submissions,
                COALESCE(SUM(cw.winner_count), 0) as total_contest_winners
            FROM {$contests_table} c
            LEFT JOIN (
                SELECT contest_id, COUNT(*) as submission_count
                FROM {$this->table_prefix}contest_submissions
                WHERE status IN ('submitted', 'under_review', 'winner')
                GROUP BY contest_id
            ) cs ON c.contest_id = cs.contest_id
            LEFT JOIN (
                SELECT contest_id, COUNT(*) as winner_count
                FROM {$this->table_prefix}contest_winners
                GROUP BY contest_id
            ) cw ON c.contest_id = cw.contest_id
            INNER JOIN {$tasks_table} t ON c.task_id = t.task_id
            WHERE t.creator_wallet = %s", [$creator_wallet]));

        // ✅ Completion stats - CORRECTED to count custom jobs only once
        $completion_stats = $this->wpdb->get_row($this->wpdb->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(user_count) as total_slots,
                SUM(completed_count) as total_completions,
                IFNULL(AVG(completed_count * 100.0 / NULLIF(user_count, 0)), 0) as avg_completion_rate,
                -- Count contests separately
                SUM(CASE WHEN c.contest_id IS NOT NULL THEN 1 ELSE 0 END) as contest_jobs,
                -- Count standard jobs (including standard custom jobs)
                SUM(CASE WHEN c.contest_id IS NULL THEN 1 ELSE 0 END) as standard_jobs
            FROM {$tasks_table} t
            LEFT JOIN {$contests_table} c ON t.task_id = c.task_id
            WHERE t.creator_wallet = %s", [$creator_wallet]));

        // ✅ Unique users - no change needed
        $unique_users_sql = $this->wpdb->prepare("
            SELECT COUNT(DISTINCT user_id) as unique_users
            FROM {$completions_table} c
            INNER JOIN {$tasks_table} t ON c.task_id = t.task_id
            WHERE t.creator_wallet = %s", [$creator_wallet]);
        $unique_users = intval($this->wpdb->get_var($unique_users_sql));

        // Compute derived metrics - CORRECTED calculations
        $total_jobs = intval($completion_stats->total_jobs ?? 0);
        $total_slots = intval($completion_stats->total_slots ?? 0);
        $total_completions = intval($completion_stats->total_completions ?? 0);
        $total_contest_jobs = intval($completion_stats->contest_jobs ?? 0);
        $total_standard_jobs = intval($completion_stats->standard_jobs ?? 0);
        
        // Calculate spending correctly - using the data from spending_trend query
        $total_spent = 0;
        $contest_spent = 0;
        $standard_spent = 0;
        
        if ($spending_data) {
            foreach ($spending_data as $day) {
                $total_spent += floatval($day->daily_spent ?? 0);
                $contest_spent += floatval($day->contest_spent ?? 0);
                $standard_spent += floatval($day->standard_spent ?? 0);
            }
        }
        
        // Alternative calculation from job_type_data if spending_data is empty
        if ($total_spent === 0 && $job_type_data) {
            foreach ($job_type_data as $job) {
                $job_spent = floatval($job->total_spent ?? 0);
                $total_spent += $job_spent;
                if ($job->category_type === 'contest') {
                    $contest_spent += $job_spent;
                } else {
                    $standard_spent += $job_spent;
                }
            }
        }
        
        $avg_cost_per_completion = $total_completions > 0 ? $total_spent / $total_completions : 0;

        return [
            'spending_trend' => $spending_data,
            'job_type_distribution' => $job_type_data,
            'contest_metrics' => [
                'total_contests' => intval($contest_metrics->total_contests ?? 0),
                'total_contest_spent' => floatval($contest_metrics->total_contest_spent ?? 0),
                'avg_contest_prize' => floatval($contest_metrics->avg_contest_prize ?? 0),
                'total_contest_submissions' => intval($contest_metrics->total_contest_submissions ?? 0),
                'total_contest_winners' => intval($contest_metrics->total_contest_winners ?? 0)
            ],
            'completion_stats' => [
                'total_jobs' => $total_jobs,
                'contest_jobs' => $total_contest_jobs,
                'standard_jobs' => $total_standard_jobs,
                'total_slots' => $total_slots,
                'total_completions' => $total_completions,
                'avg_completion_rate' => floatval($completion_stats->avg_completion_rate),
                'unique_users' => $unique_users,
                'avg_cost_per_completion' => $avg_cost_per_completion,
                'total_spent' => $total_spent,
                'contest_spent' => $contest_spent,
                'standard_spent' => $standard_spent
            ],
            'period' => $period
        ];
    }

    /**
     * Optimized paged completions for job detail view — O(log n) per job
     */
    public function get_job_completions_paged($job_id, $limit = 20, $offset = 0) {
        $completions_table = $this->table_prefix . 'completions';
        $users_table = $this->table_prefix . 'users';

        // Changed from %d to %s for string task_id
        $sql = "
            SELECT c.*, u.x_username, u.wallet_address, u.avatar, u.avatar_custom
            FROM {$completions_table} c
            LEFT JOIN {$users_table} u ON c.user_id = u.user_id
            WHERE c.task_id = %s
            ORDER BY c.created_at DESC
            LIMIT %d OFFSET %d";

        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, [$job_id, $limit, $offset]));

        // Get total count (cached)
        $count_cache_key = 'job_completions_count_' . $job_id;
        $total = wp_cache_get($count_cache_key, 'growl_jobs');
        if ($total === false) {
            $total = intval($this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$completions_table} WHERE task_id = %s", $job_id
            )));
            wp_cache_set($count_cache_key, $total, 'growl_jobs', 300);
        }

        return [
            'results' => $results,
            'total' => $total
        ];
    }

    /**
     * Serialize a completion row for REST APIs: professional identity (x_username / agent_id), no internal user_id.
     *
     * @param object $c Row from get_job_completions_paged (includes x_username, wallet_address from users join).
     * @param array  $opts include_disputed, include_wallet_short (truncated fallback when human has no x_username).
     */
    public function format_completion_for_public_api($c, array $opts = []) {
        $include_disputed     = !empty($opts['include_disputed']);
        $include_wallet_short = !empty($opts['include_wallet_short']);

        $wt = (isset($c->worker_type) && (string) $c->worker_type !== '') ? (string) $c->worker_type : 'human';
        $out = [
            'completion_id' => isset($c->completion_id) ? (int) $c->completion_id : null,
            'status'          => isset($c->status) ? $c->status : null,
            'reward_amount'   => isset($c->reward_amount) ? floatval($c->reward_amount) : 0.0,
            'locked_until'    => isset($c->locked_until) ? $c->locked_until : null,
            'created_at'      => isset($c->created_at) ? $c->created_at : null,
            'worker_type'     => $wt,
        ];

        if ($wt === 'agent') {
            $aid = isset($c->worker_agent_id) ? trim((string) $c->worker_agent_id) : '';
            $out['agent_id']   = $aid !== '' ? $aid : null;
            $out['x_username'] = null;
        } else {
            $xu = isset($c->x_username) ? trim((string) $c->x_username) : '';
            $out['x_username'] = $xu !== '' ? $xu : null;
            $out['agent_id']   = null;
            if ($out['x_username'] === null && $include_wallet_short && !empty($c->wallet_address)) {
                $w   = (string) $c->wallet_address;
                $len = strlen($w);
                $out['wallet_short'] = $len > 10 ? substr($w, 0, 6) . '...' . substr($w, -4) : $w;
            }
        }

        if ($include_disputed) {
            $out['is_disputed'] = !empty($c->is_disputed);
        }

        if (isset($c->youtube_watched_percent)) {
            $out['youtube_watched_percent'] = $c->youtube_watched_percent;
        }

        return $out;
    }

    /**
     * Optimized paged verifications — O(log n), pre-decode JSON in DB, use covering index
     */
    /**
     * Count verifications per status for a job (tab badges).
     *
     * @return array{pending: int, approved: int, rejected: int, total: int}
     */
    public function get_job_verification_status_counts($job_id) {
        $verifications_table = $this->table_prefix . 'manual_verifications';
        $cache_key = 'job_verif_counts_' . $job_id;
        $cached = wp_cache_get($cache_key, 'growl_verifications');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt FROM {$verifications_table} WHERE task_id = %s GROUP BY status",
            [$job_id]
        ));
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
        foreach ((array) $rows as $row) {
            $st = (string) $row->status;
            $n = (int) $row->cnt;
            if (isset($counts[$st])) {
                $counts[$st] = $n;
            }
            $counts['total'] += $n;
        }
        wp_cache_set($cache_key, $counts, 'growl_verifications', 120);
        return $counts;
    }

    public function bust_job_verification_cache($job_id) {
        wp_cache_delete('job_verifications_count_' . $job_id, 'growl_verifications');
        wp_cache_delete('job_verif_counts_' . $job_id, 'growl_verifications');
    }

    public function get_job_verifications_paged($job_id, $limit = 10, $offset = 0, $status = '') {
        $verifications_table = $this->table_prefix . 'manual_verifications';
        $tasks_table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        $completions_table = $this->table_prefix . 'completions';

        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : '';
        $status_sql = $status !== '' ? $this->wpdb->prepare(' AND mv.status = %s', $status) : '';

        $agents_table = $this->table_prefix . 'ai_agents';

        $sql = "
            SELECT 
                mv.*,
                u.x_username AS user_x_username,
                u.display_name AS user_display_name,
                u.wallet_address AS user_wallet_address,
                a.agent_id AS worker_agent_id,
                a.agent_name AS agent_name,
                a.x_username AS agent_x_username,
                a.wallet_address AS agent_wallet_address,
                c.worker_type,
                c.worker_agent_id AS completion_worker_agent_id,
                t.base_reward,
                t.action_type,
                t.verification_method,
                t.verification_type,
                t.required_actions,
                c.completion_id,
                mv.submitted_data AS submitted_data_original
            FROM {$verifications_table} mv
            INNER JOIN {$tasks_table} t ON mv.task_id = t.task_id
            LEFT JOIN {$users_table} u ON mv.user_id > 0 AND mv.user_id = u.user_id
            LEFT JOIN {$completions_table} c ON mv.completion_id = c.completion_id
            LEFT JOIN {$agents_table} a ON (
                (c.worker_type = 'agent' AND c.worker_agent_id = a.agent_id)
                OR (COALESCE(c.worker_agent_id, '') <> '' AND c.worker_agent_id = a.agent_id)
            )
            WHERE mv.task_id = %s{$status_sql}
            ORDER BY mv.created_at DESC
            LIMIT %d OFFSET %d";

        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, [$job_id, $limit, $offset]));

        // Post-process: safely decode the entire submitted_data JSON
        foreach ($results as $row) {
            $submitted_data = [];
            if (!empty($row->submitted_data_original)) {
                // Try to decode the JSON
                $decoded = json_decode($row->submitted_data_original, true);
                
                // If decoding fails, try with stripslashes (common issue with WP)
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = json_decode(stripslashes($row->submitted_data_original), true);
                }
                
                // If still fails, use empty array
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $decoded = [];
                }
                
                $submitted_data = $decoded;
            }
            
            $links = [];
            if (!empty($submitted_data['links']) && is_array($submitted_data['links'])) {
                $links = array_values(array_filter(array_map('strval', $submitted_data['links'])));
            }
            $single_link = $submitted_data['link'] ?? $submitted_data['url'] ?? $submitted_data['zealy_link'] ?? '';
            if ($single_link !== '' && empty($links)) {
                $links = [(string) $single_link];
            }
            if ($single_link === '' && !empty($links)) {
                $single_link = (string) $links[0];
            }

            $is_agent = (isset($row->worker_type) && (string) $row->worker_type === 'agent')
                || !empty($row->worker_agent_id)
                || !empty($row->completion_worker_agent_id)
                || ((string) ($submitted_data['worker_type'] ?? '') === 'agent');
            $x_username = trim((string) (
                $submitted_data['x_username']
                ?? $submitted_data['twitter_username']
                ?? ($is_agent ? ($row->agent_x_username ?? '') : ($row->user_x_username ?? ''))
                ?? ''
            ));
            $wallet = trim((string) (
                $is_agent
                    ? ($row->agent_wallet_address ?? $row->user_wallet_address ?? '')
                    : ($row->user_wallet_address ?? '')
            ));
            $row->x_username = $x_username;
            $row->wallet_address = $wallet;
            $row->display_name = trim((string) ($row->user_display_name ?? ''));
            $row->worker_type = $is_agent ? 'agent' : 'human';
            $row->agent_id = (string) ($row->worker_agent_id ?: $row->completion_worker_agent_id ?: '');
            $row->agent_name = trim((string) ($row->agent_name ?? ''));

            // Ensure we have the expected fields with proper defaults
            $row->submitted_data_decoded = [
                'screenshot_urls' => isset($submitted_data['screenshot_urls']) && is_array($submitted_data['screenshot_urls'])
                    ? $submitted_data['screenshot_urls']
                    : (isset($submitted_data['screenshots']) && is_array($submitted_data['screenshots'])
                        ? $submitted_data['screenshots']
                        : []),
                'links' => $links,
                'link' => $single_link,
                'notes' => $submitted_data['notes'] ?? $submitted_data['description'] ?? '',
                'x_username' => $x_username,
                'original_data' => $submitted_data,
            ];
        }

        if ($status !== '') {
            $total = intval($this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$verifications_table} WHERE task_id = %s AND status = %s",
                [$job_id, $status]
            )));
        } else {
            $count_cache_key = 'job_verifications_count_' . $job_id;
            $total = wp_cache_get($count_cache_key, 'growl_verifications');
            if ($total === false) {
                $total = intval($this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$verifications_table} WHERE task_id = %s",
                    [$job_id]
                )));
                wp_cache_set($count_cache_key, $total, 'growl_verifications', 300);
            }
        }

        return [
            'results' => $results,
            'total' => $total
        ];
    }

    public function get_jobs_with_pending_verifications_optimized($creator_wallet, $limit = 20, $offset = 0) {
        $creator_wallet = is_string($creator_wallet) ? trim($creator_wallet) : '';
        if ($creator_wallet === '') {
            return [
                'results' => [],
                'total' => 0,
                'total_pending_verifications' => 0,
            ];
        }

        $tasks_table = $this->table_prefix . 'tasks';
        $verifications_table = $this->table_prefix . 'manual_verifications';
        $users_table = $this->table_prefix . 'users';
        
        // OPTIMIZED: Single query with covering indexes
        $sql = "
            SELECT 
                t.*,
                u.x_username as creator_x_username,
                COALESCE(v_stats.pending_count, 0) as pending_verifications,
                COALESCE(c_stats.actual_completions, 0) as actual_completions
            FROM {$tasks_table} t
            USE INDEX (idx_tasks_creator_status)  # MariaDB 10.6 prefers USE INDEX
            INNER JOIN {$users_table} u ON t.creator_wallet = u.wallet_address
            INNER JOIN (
                SELECT DISTINCT task_id
                FROM {$verifications_table} mv
                USE INDEX (idx_task_status)  # Use single index
                WHERE status = 'pending'
            ) mv_tasks ON t.task_id = mv_tasks.task_id
            LEFT JOIN (
                SELECT task_id, COUNT(*) as pending_count
                FROM {$verifications_table}
                USE INDEX (idx_task_status)
                WHERE status = 'pending'
                GROUP BY task_id
            ) v_stats ON t.task_id = v_stats.task_id
            LEFT JOIN (
                SELECT task_id, COUNT(*) as actual_completions
                FROM {$this->table_prefix}completions
                USE INDEX (idx_task_status)
                WHERE status IN ('unlocked', 'locked')
                GROUP BY task_id
            ) c_stats ON t.task_id = c_stats.task_id
            WHERE t.creator_wallet = %s 
            AND t.status = 'active'
            AND (
                t.verification_type = 'manual'
                OR t.verification_method = 'manual'
                OR t.action_type IN ('zealy_invite', 'website_signup', 'discord_invite', 'telegram_group', 'telegram_channel', 'custom_job')
                OR (t.action_type = 'like' AND t.verification_method = 'manual')
            )
            ORDER BY v_stats.pending_count DESC, t.created_at DESC
            LIMIT %d OFFSET %d";
        
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, [$creator_wallet, $limit, $offset])
        );
        
        // Get total count - O(1) with covering index
        $count_cache_key = 'creator_pending_verif_jobs_' . md5($creator_wallet);
        $total = wp_cache_get($count_cache_key, 'growl_tasks');
        
        if ($total === false) {
            // O(log n) - Uses covering index idx_tasks_creator_status
            $count_sql = "
                SELECT COUNT(DISTINCT t.task_id) as total
                FROM {$tasks_table} t
                USE INDEX (idx_tasks_creator_status)
                WHERE t.creator_wallet = %s 
                AND t.status = 'active'
                AND EXISTS (
                    SELECT 1 FROM {$verifications_table} mv
                    USE INDEX (idx_task_status)
                    WHERE mv.task_id = t.task_id 
                    AND mv.status = 'pending'
                )
                AND (
                    t.verification_type = 'manual'
                    OR t.verification_method = 'manual'
                    OR t.action_type IN ('zealy_invite', 'website_signup', 'discord_invite', 'telegram_group', 'telegram_channel', 'custom_job')
                    OR (t.action_type = 'like' AND t.verification_method = 'manual')
                )";
            
            $total = intval($this->wpdb->get_var(
                $this->wpdb->prepare($count_sql, [$creator_wallet])
            ));
            
            wp_cache_set($count_cache_key, $total, 'growl_tasks', 300);
        }
        
        // Get total pending verifications count - O(log n)
        $total_pending_cache_key = 'creator_total_pending_verif_' . md5($creator_wallet);
        $total_pending = wp_cache_get($total_pending_cache_key, 'growl_verifications');
        
        if ($total_pending === false) {
            // O(log n) - Uses idx_task_status covering index
            $total_pending_sql = "
                SELECT COUNT(*) as total_pending
                FROM {$verifications_table} mv
                USE INDEX (idx_task_status)
                INNER JOIN {$tasks_table} t ON mv.task_id = t.task_id
                WHERE t.creator_wallet = %s 
                AND mv.status = 'pending'
                AND t.status = 'active'";
            
            $total_pending = intval($this->wpdb->get_var(
                $this->wpdb->prepare($total_pending_sql, [$creator_wallet])
            ));
            
            wp_cache_set($total_pending_cache_key, $total_pending, 'growl_verifications', 300);
        }
        
        return [
            'results' => $results,
            'total' => $total,
            'total_pending_verifications' => $total_pending
        ];
    }

    /**
     * Get manual verifications for creator
     */
    public function get_creator_manual_verifications($creator_wallet, $status = 'pending', $use_cache = true) {
        $cache_key = $this->get_cache_key('creator_verifications', $creator_wallet . '_' . $status);
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_verifications');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $verifications_table = $this->table_prefix . 'manual_verifications';
        $tasks_table = $this->table_prefix . 'tasks';
        $users_table = $this->table_prefix . 'users';
        
        // OPTIMIZED: Use covering index for O(log n) performance
        $sql = "SELECT mv.*, t.action_type, t.base_reward, u.x_username, u.wallet_address
                FROM $verifications_table mv
                USE INDEX (idx_manual_verif_task_status)  # MariaDB 10.6 syntax
                INNER JOIN $tasks_table t USE INDEX (idx_tasks_creator_status) 
                    ON mv.task_id = t.task_id
                INNER JOIN $users_table u ON mv.user_id = u.user_id
                WHERE t.creator_wallet = %s AND mv.status = %s
                ORDER BY mv.created_at DESC
                LIMIT 200";
        
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, [$creator_wallet, $status]));
        
        if ($use_cache && $this->cache_enabled) {
            wp_cache_set($cache_key, $results, 'growl_verifications', 300);
        }
        
        return $results;
    }
    
    /**
     * CRITICAL FIX: Single query for task completions with user data - NO N+1
     */
    public function get_task_completions($task_id, $user_id = null, $use_cache = true) {
        $cache_key = $this->get_cache_key('task_completions', $task_id . '_' . ($user_id ?: 'all'));
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_completions');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $completions_table = $this->table_prefix . 'completions';
        $users_table = $this->table_prefix . 'users';
        
        $where = ["c.task_id = %s"]; // Changed from %d to %s
        $params = [$task_id];
        
        if ($user_id) {
            $where[] = "c.user_id = %d";
            $params[] = $user_id;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT c.*, u.x_username, u.wallet_address as user_wallet
                FROM $completions_table c
                LEFT JOIN $users_table u ON c.user_id = u.user_id
                WHERE $where_clause 
                ORDER BY c.created_at DESC";
        
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        
        if ($use_cache && $this->cache_enabled) {
            wp_cache_set($cache_key, $results, 'growl_completions', 300);
        }
        
        return $results;
    }
    
    public function get_user_completions($user_id, $filters = [], $use_cache = true) {
        $cache_key = $this->get_cache_key('user_completions', $user_id . '_' . md5(json_encode($filters)));
        
        if ($use_cache && $this->cache_enabled) {
            $cached = wp_cache_get($cache_key, 'growl_completions');
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $completions_table = $this->table_prefix . 'completions';
        $tasks_table = $this->table_prefix . 'tasks';
        
        $where = ["c.user_id = %d"];
        $params = [$user_id];
        
        if (!empty($filters['status'])) {
            $where[] = "c.status = %s";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['task_id'])) {
            $where[] = "c.task_id = %s";
            $params[] = $filters['task_id'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        // OPTIMIZED: Force covering index usage - O(log n)
        $sql = "SELECT c.completion_id, c.task_id, c.user_id, c.reward_amount, 
                    c.status, c.created_at, c.locked_until,
                    t.action_type as task_action_type,
                    t.creator_wallet,
                    t.base_reward as task_base_reward
                FROM $completions_table c
                FORCE INDEX (idx_user_status)
                INNER JOIN $tasks_table t ON c.task_id = t.task_id
                WHERE $where_clause 
                ORDER BY c.created_at DESC 
                LIMIT 200";
        
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        
        if ($use_cache && $this->cache_enabled) {
            wp_cache_set($cache_key, $results, 'growl_completions', 300);
        }
        
        return $results;
    }
    
    public function insert($table, $data) {
        $table_full = $this->table_prefix . $table;
        
        // OPTIMIZED: Pre-process data once
        $processed_data = [];
        foreach ($data as $key => $value) {
            // Optional email heading: empty string is valid (raw HTML campaigns omit it).
            $keep_empty_string = ($key === 'heading' && in_array($table, ['email_logs', 'marketing_campaigns'], true))
                || ($table === 'work_reviews' && in_array($key, ['comment', 'private_note'], true));
            if ($value === '' && !$keep_empty_string) {
                $processed_data[$key] = NULL;
            } elseif (is_string($value)) {
                // wpdb->insert() escapes strings — never call _real_escape/sanitize_sql_input here or stored HTML is double-escaped (broken emails / logs).
                if ($table === 'email_logs') {
                    $processed_data[$key] = $value;
                } elseif ($table === 'marketing_campaigns' && $key === 'html_body') {
                    $processed_data[$key] = $value;
                } elseif ($table === 'funding_intents' && in_array($key, ['payload_json', 'quote_json', 'sgt_snapshot_json'], true)) {
                    // Immutable canonical JSON. wpdb handles SQL escaping; keyword
                    // stripping here would mutate the payload after it was hashed.
                    $processed_data[$key] = $value;
                } elseif ($table === 'notifications' && $key === 'message' && strpos($value, '<') !== false) {
                    $processed_data[$key] = $value;
                } else {
                    $processed_data[$key] = $this->sanitize_sql_input($value);
                }
            } elseif (is_array($value)) {
                $json_value = json_encode($value, JSON_UNESCAPED_UNICODE);
                $processed_data[$key] = (strlen($json_value) > 10000) 
                    ? json_encode(['error' => 'Data too large', 'truncated' => true])
                    : $json_value;
            } else {
                $processed_data[$key] = $value;
            }
        }
        
        try {
            $result = $this->wpdb->insert($table_full, $processed_data);
            
            if ($result === false) {
                error_log('DATABASE_INSERT_ERROR: ' . $this->wpdb->last_error);
                error_log('INSERT_DATA: ' . json_encode($processed_data));
                return false;
            }
            
            // CRITICAL FIX: Always return insert_id for tables with AUTO_INCREMENT
            // Check if table has an AUTO_INCREMENT column
            $insert_id = $this->wpdb->insert_id;
            
            // For tasks table with CHAR(8) primary key, return the actual ID
            if ($table === 'tasks' && isset($processed_data['task_id'])) {
                return $processed_data['task_id'];
            }

            // Successful inserts are expected hot-path activity (notifications, audit log).
            // Logging every one adds disk I/O and can expose operational metadata.
            if (defined('GROWL_LOG_DB_INSERTS') && GROWL_LOG_DB_INSERTS) {
                error_log('DB_INSERT_SUCCESS: Table ' . $table . ' Insert ID: ' . $insert_id);
            }
            
            // For all other tables, return the AUTO_INCREMENT ID
            return $insert_id;
            
        } catch (Exception $e) {
            error_log('DATABASE_INSERT_EXCEPTION: ' . $e->getMessage());
            error_log('INSERT_DATA: ' . json_encode($processed_data));
            return false;
        }
    }
    
    public function update($table, $data, $where) {
        $table_full = $this->table_prefix . $table;
        
        // Security: Sanitize data
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->sanitize_sql_input($value);
            }
        }
        
        $result = $this->wpdb->update($table_full, $data, $where);
        
        // CRITICAL: Invalidate affected caches immediately
        $this->invalidate_related_caches($table, 'update', $data, $where);
        
        return $result;
    }
    
    public function delete($table, $where) {
        $table_full = $this->table_prefix . $table;
        $result = $this->wpdb->delete($table_full, $where);
        
        // CRITICAL: Invalidate affected caches
        $this->invalidate_related_caches($table, 'delete', [], $where);
        
        return $result;
    }

    /**
     * REPLACE operation (INSERT or UPDATE if exists)
     */
    public function replace($table, $data) {
        $table_full = $this->table_prefix . $table;
        
        // Security: Sanitize data
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->sanitize_sql_input($value);
            } elseif (is_array($value)) {
                $json_value = json_encode($value);
                if (strlen($json_value) > 10000) {
                    $data[$key] = json_encode(['error' => 'Data too large', 'truncated' => true]);
                } else {
                    $data[$key] = $json_value;
                }
            }
        }
        
        try {
            $result = $this->wpdb->replace($table_full, $data);
            
            if ($result === false) {
                error_log('DATABASE_REPLACE_ERROR: ' . $this->wpdb->last_error);
                error_log('REPLACE_DATA: ' . json_encode($data));
                return false;
            }
            
            $insert_id = $this->wpdb->insert_id;
            
            // Invalidate affected caches
            $this->invalidate_related_caches($table, 'replace', $data, $insert_id);
            
            return $insert_id;
        } catch (Exception $e) {
            error_log('DATABASE_REPLACE_EXCEPTION: ' . $e->getMessage());
            error_log('TABLE: ' . $table . ' DATA: ' . json_encode($data));
            return false;
        }
    }
    
    /**
     * ENHANCED: Intelligent cache invalidation system
     */
    private function invalidate_related_caches($table, $operation, $data = [], $identifier = null) {
        switch ($table) {
            case 'users':
                if (isset($data['user_id']) || (is_array($identifier) && isset($identifier['user_id']))) {
                    $user_id = $data['user_id'] ?? $identifier['user_id'];
                    $this->clear_user_cache($user_id);
                }
                if (isset($data['wallet_address'])) {
                    wp_cache_delete($this->get_cache_key('user_wallet', $data['wallet_address']), 'growl_users');
                }
                break;
                
            case 'tasks':
                $task_id = $data['task_id'] ?? ($identifier['task_id'] ?? null);
                if ($task_id) {
                    $this->clear_task_cache($task_id);
                }
                // Clear all active tasks cache
                wp_cache_flush_group('growl_tasks');
                break;
                
            case 'completions':
                if (isset($data['user_id']) || (is_array($identifier) && isset($identifier['user_id']))) {
                    $user_id = $data['user_id'] ?? $identifier['user_id'];
                    $this->clear_user_cache($user_id);
                }
                if (isset($data['task_id']) || (is_array($identifier) && isset($identifier['task_id']))) {
                    $task_id = $data['task_id'] ?? $identifier['task_id'];
                    $this->clear_task_cache($task_id);
                }
                break;
                
            case 'notifications':
                if (isset($data['user_id']) || (is_array($identifier) && isset($identifier['user_id']))) {
                    $user_id = $data['user_id'] ?? $identifier['user_id'];
                    // Clear notification count cache
                    wp_cache_delete($this->get_cache_key('notification_count', $user_id), 'growl_notifications');
                }
                break;
            
            case 'manual_verifications':
                if (isset($data['task_id']) || (is_array($identifier) && isset($identifier['task_id']))) {
                    $task_id = $data['task_id'] ?? $identifier['task_id'];
                    wp_cache_delete('job_verifications_count_' . $task_id, 'growl_verifications');
                    wp_cache_flush_group('growl_verifications');
                }
                break;
            case 'completions':
                if (isset($data['task_id']) || (is_array($identifier) && isset($identifier['task_id']))) {
                    $task_id = $data['task_id'] ?? $identifier['task_id'];
                    wp_cache_delete('job_completions_count_' . $task_id, 'growl_jobs');
                    wp_cache_flush_group('growl_jobs');
                }
                break;
        }
    }
    
    /**
     * Enhanced cache key generation with versioning
     */
    private function get_cache_key($type, $identifier) {
        $id = is_scalar($identifier) ? (string) $identifier : '';
        return 'v' . $this->cache_version . '_' . $type . '_' . md5($id);
    }
    
    /**
     * Cache management methods
     */
    public function clear_user_cache($user_id) {
        $patterns = [
            $this->get_cache_key('user', $user_id),
            $this->get_cache_key('user_completions', $user_id),
            $this->get_cache_key('user_stats', $user_id),
            $this->get_cache_key('notification_count', $user_id)
        ];
        
        foreach ($patterns as $key) {
            $this->cache_delete($key, 'growl_users');
            $this->cache_delete($key, 'growl_completions');
            $this->cache_delete($key, 'growl_notifications');
        }
    }
    
    public function clear_task_cache($task_id) {
        $cache_key = $this->get_cache_key('task', $task_id);
        $this->cache_delete($cache_key, 'growl_tasks');
        $this->cache_delete($this->get_cache_key('task_completions', $task_id), 'growl_completions');
        
        // Clear all active tasks since this task's status may have changed
        wp_cache_flush_group('growl_tasks');
    }
    
    /**
     * Batch operations for better performance
     */
    public function batch_insert($table, $data_array) {
        if (empty($data_array)) {
            return 0;
        }
        
        $table_full = $this->table_prefix . $table;
        $columns = array_keys($data_array[0]);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '%s')) . ')';
        
        $values = [];
        $params = [];
        
        foreach ($data_array as $data) {
            $values[] = $placeholders;
            foreach ($columns as $column) {
                $params[] = $data[$column] ?? null;
            }
        }
        
        $sql = "INSERT INTO $table_full (" . implode(',', $columns) . ") VALUES " . implode(',', $values);
        
        $this->wpdb->query($this->wpdb->prepare($sql, $params));
        
        return (int) $this->wpdb->rows_affected;
    }

    /**
     * Rows changed by the last INSERT/UPDATE/DELETE on this connection.
     */
    public function get_rows_affected() {
        return (int) $this->wpdb->rows_affected;
    }
    
    /**
     * Performance monitoring
     */
    public function log_performance($response_time, $queries_count, $endpoint) {
        $memory_usage = memory_get_peak_usage(true);
        
        $this->wpdb->insert($this->table_prefix . 'performance_log', [
            'response_time' => $response_time,
            'queries_count' => $queries_count,
            'endpoint' => substr($endpoint, 0, 500),
            'memory_usage' => $memory_usage,
            'created_at' => current_time('mysql')
        ]);
        
        // Log slow queries for optimization
        if ($response_time > 1000) {
            error_log("GROWL_SLOW_QUERY: {$response_time}ms for {$endpoint} - {$queries_count} queries");
        }
    }
    
    /**
     * Security helper
     */
    private function sanitize_sql_input($input) {
        $input = preg_replace('/\b(UNION|SELECT|INSERT|DELETE|UPDATE|DROP|EXEC)\b/i', '', $input);
        // Do not _real_escape — wpdb::insert/update/prepare handle escaping; pre-escaping doubles slashes in stored values.
        return $input;
    }
    
    /**
     * Original helper methods preserved
     */
    public function query($sql, $params = []) {
        // OPTIMIZED: Add query performance tracking
        $start_time = microtime(true);

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        // Route DML/TCL through $wpdb->query() which returns int|false.
        // $wpdb->get_results() returns null for every DML statement — both on success
        // AND on error — so callers could never detect failures. SELECT-family
        // statements still go through get_results() so they return an array.
        $verb = strtoupper(strtok(ltrim($sql), " \t\n\r("));
        if (in_array($verb, [
            'UPDATE', 'INSERT', 'DELETE', 'REPLACE',
            'START', 'BEGIN', 'COMMIT', 'ROLLBACK',
            'LOCK', 'UNLOCK', 'SET', 'TRUNCATE',
        ], true)) {
            $result = $this->wpdb->query($sql);
        } else {
            $result = $this->wpdb->get_results($sql);
        }

        $execution_time = (microtime(true) - $start_time) * 1000;

        // Log slow queries for optimization
        if ($execution_time > 100) {
            error_log("SLOW_QUERY ({$execution_time}ms): " . substr($sql, 0, 200));
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Transaction helpers
    // Using $wpdb->query() directly so autocommit-bypass works reliably with
    // InnoDB tables even inside WordPress's connection lifecycle.
    // -------------------------------------------------------------------------

    /**
     * Start a MySQL transaction. Must be paired with commit() or rollback().
     * Example:
     *   $db->begin_transaction();
     *   try {
     *       // ... balance operations ...
     *       $db->commit();
     *   } catch (Exception $e) {
     *       $db->rollback();
     *       throw $e;
     *   }
     */
    public function begin_transaction() {
        return $this->wpdb->query('START TRANSACTION');
    }

    /**
     * Commit the current transaction.
     *
     * @return int|false
     */
    public function commit() {
        return $this->wpdb->query('COMMIT');
    }

    /**
     * Roll back the current transaction. Call this in every catch/error path.
     *
     * @return int|false
     */
    public function rollback() {
        return $this->wpdb->query('ROLLBACK');
    }

    /**
     * Execute a SELECT query with a FOR UPDATE row-level lock.
     *
     * Must be called INSIDE an open transaction (after begin_transaction()).
     * Appends "FOR UPDATE" if not already present so any caller can use a plain
     * SELECT string and get locking behaviour for free.
     *
     * @param  string $sql    Prepared or raw SQL SELECT statement.
     * @param  array  $params Optional $wpdb->prepare() parameters.
     * @return object|null    Single row object, or null if no match.
     */
    public function get_row_for_update($sql, $params = []) {
        $start_time = microtime(true);

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        // Append FOR UPDATE only when not already present.
        if (stripos(rtrim($sql), 'FOR UPDATE') === false) {
            $sql .= ' FOR UPDATE';
        }

        $result = $this->wpdb->get_row($sql);

        $execution_time = (microtime(true) - $start_time) * 1000;
        if ($execution_time > 100) {
            error_log("SLOW_GET_ROW_FOR_UPDATE ({$execution_time}ms): " . substr($sql, 0, 200));
        }

        return $result;
    }

    public function get_var($sql, $params = []) {
        $start_time = microtime(true);
        
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        $result = $this->wpdb->get_var($sql);
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        if ($execution_time > 100) {
            error_log("SLOW_GET_VAR ({$execution_time}ms): " . substr($sql, 0, 200));
        }
        
        return $result;
    }

    public function get_row($sql, $params = []) {
        $start_time = microtime(true);
        
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        $result = $this->wpdb->get_row($sql);
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        if ($execution_time > 100) {
            error_log("SLOW_GET_ROW ({$execution_time}ms): " . substr($sql, 0, 200));
        }
        
        return $result;
    }

    public function get_col($sql, $params = []) {
        $start_time = microtime(true);
        
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        $result = $this->wpdb->get_col($sql);
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        if ($execution_time > 100) {
            error_log("SLOW_GET_COL ({$execution_time}ms): " . substr($sql, 0, 200));
        }
        
        return $result;
    }

    public function get_results($sql, $params = []) {
        $start_time = microtime(true);
        
        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }
        
        $results = $this->wpdb->get_results($sql);
        
        $execution_time = (microtime(true) - $start_time) * 1000;
        if ($execution_time > 100) {
            error_log("SLOW_GET_RESULTS ({$execution_time}ms): " . substr($sql, 0, 200));
        }
        
        return $results;
    }
    
    private function clear_cache_pattern($pattern) {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('growl_tasks');
            wp_cache_flush_group('growl_completions');
            wp_cache_flush_group('growl_users');
        }
    }
    
    public function get_performance_stats($hours = 24) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare("
                SELECT 
                    endpoint,
                    COUNT(*) as request_count,
                    AVG(response_time) as avg_response_time,
                    AVG(queries_count) as avg_queries,
                    MAX(response_time) as max_response_time,
                    MIN(response_time) as min_response_time
                FROM {$this->table_prefix}performance_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)
                GROUP BY endpoint
                ORDER BY avg_response_time DESC
                LIMIT 20
            ", $hours)
        );
    }
    
    public function get_connection_stats() {
        return [
            'last_error' => $this->wpdb->last_error,
            'num_queries' => $this->wpdb->num_queries,
            'last_query' => $this->wpdb->last_query,
            'cache_hits' => $this->cache_enabled ? 'Enabled' : 'Disabled'
        ];
    }

    /**
     * Ensure Telegram platform tables exist and seed default footer slots.
     */
    private function ensure_telegram_platform_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $this->wpdb->get_charset_collate();

        $tables = [
            "CREATE TABLE {$this->table_prefix}telegram_subscribers (
                subscriber_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chat_id VARCHAR(32) NOT NULL,
                chat_type ENUM('group','supergroup','channel','private') NOT NULL DEFAULT 'supergroup',
                chat_title VARCHAR(255) DEFAULT '',
                chat_username VARCHAR(255) DEFAULT '',
                status ENUM('active','paused','removed','pending_setup') NOT NULL DEFAULT 'pending_setup',
                installed_by_tg_user_id BIGINT NULL,
                linked_wallet VARCHAR(44) NULL,
                tier ENUM('free','official','earnfi_holder','partner') NOT NULL DEFAULT 'free',
                filters_json LONGTEXT NULL,
                permissions_json LONGTEXT NULL,
                locale VARCHAR(10) DEFAULT 'en',
                last_post_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (subscriber_id),
                UNIQUE KEY uq_chat_id (chat_id),
                KEY idx_status (status),
                KEY idx_tier (tier)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_outbox (
                outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                task_id VARCHAR(12) NOT NULL,
                subscriber_id BIGINT UNSIGNED NOT NULL,
                payload_json LONGTEXT NULL,
                status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                telegram_message_id BIGINT NULL,
                error_message VARCHAR(500) DEFAULT '',
                scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (outbox_id),
                UNIQUE KEY uq_task_subscriber (task_id, subscriber_id),
                KEY idx_status_scheduled (status, scheduled_at),
                KEY idx_subscriber (subscriber_id)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_events (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type VARCHAR(50) NOT NULL,
                subscriber_id BIGINT UNSIGNED NULL,
                task_id VARCHAR(12) NULL,
                slot_id BIGINT UNSIGNED NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id),
                KEY idx_event_type (event_type),
                KEY idx_subscriber (subscriber_id),
                KEY idx_created (created_at)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_footer_slots (
                slot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_key VARCHAR(64) NOT NULL,
                label VARCHAR(120) NOT NULL,
                button_text VARCHAR(120) NOT NULL,
                target VARCHAR(500) NOT NULL,
                action_type ENUM('url','command','deep_link') NOT NULL DEFAULT 'url',
                weight INT UNSIGNED NOT NULL DEFAULT 100,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                tier ENUM('platform','partner','paid_verified') NOT NULL DEFAULT 'platform',
                impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (slot_id),
                UNIQUE KEY uq_slot_key (slot_key),
                KEY idx_active (is_active)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_gates (
                gate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                subscriber_id BIGINT UNSIGNED NOT NULL,
                chat_id VARCHAR(32) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                gate_mode ENUM('group','channel','topic') NOT NULL DEFAULT 'group',
                topic_ids_json LONGTEXT NULL,
                token_mint VARCHAR(64) NOT NULL DEFAULT '',
                min_token_amount DECIMAL(24,8) NULL,
                min_token_usd DECIMAL(16,4) NULL,
                use_usd TINYINT(1) NOT NULL DEFAULT 0,
                admin_wallet VARCHAR(44) NULL,
                premium_unlocked TINYINT(1) NOT NULL DEFAULT 0,
                premium_checked_at DATETIME NULL,
                settings_json LONGTEXT NULL,
                rules_json LONGTEXT NULL,
                next_recheck_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (gate_id),
                UNIQUE KEY uq_subscriber (subscriber_id),
                KEY idx_chat (chat_id),
                KEY idx_enabled_recheck (enabled, next_recheck_at)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_gate_members (
                member_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gate_id BIGINT UNSIGNED NOT NULL,
                chat_id VARCHAR(32) NOT NULL,
                tg_user_id BIGINT NOT NULL,
                tg_username VARCHAR(255) DEFAULT '',
                wallet_address VARCHAR(44) NULL,
                status ENUM('pending','verified','expired') NOT NULL DEFAULT 'pending',
                holder_tag_applied TINYINT(1) NOT NULL DEFAULT 0,
                topic_strike_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_recheck_at DATETIME NULL,
                verified_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (member_id),
                UNIQUE KEY uq_gate_user (gate_id, tg_user_id),
                UNIQUE KEY uq_gate_wallet (gate_id, wallet_address),
                KEY idx_status (status)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_gate_recheck_queue (
                queue_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gate_id BIGINT UNSIGNED NOT NULL,
                member_id BIGINT UNSIGNED NOT NULL,
                status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (queue_id),
                KEY idx_gate_status (gate_id, status)
            ) $charset_collate",
            "CREATE TABLE {$this->table_prefix}telegram_gate_events (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gate_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                tg_user_id BIGINT NULL,
                meta_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id),
                KEY idx_gate_type (gate_id, event_type),
                KEY idx_created (created_at)
            ) $charset_collate",
        ];

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        $members_table = $this->table_prefix . 'telegram_gate_members';
        $strike_col = $this->wpdb->get_results("SHOW COLUMNS FROM `{$members_table}` LIKE 'topic_strike_count'");
        if (empty($strike_col)) {
            $this->wpdb->query(
                "ALTER TABLE `{$members_table}` ADD COLUMN topic_strike_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER holder_tag_applied"
            );
        }

        $wallet_col = $this->wpdb->get_results("SHOW COLUMNS FROM `{$members_table}` LIKE 'wallet_address'");
        if (!empty($wallet_col)) {
            $this->wpdb->query("ALTER TABLE `{$members_table}` MODIFY wallet_address VARCHAR(44) NULL");
            $this->wpdb->query("UPDATE `{$members_table}` SET wallet_address = NULL WHERE wallet_address = ''");
        }

        $wallet_idx = $this->wpdb->get_results("SHOW INDEX FROM `{$members_table}` WHERE Key_name = 'uq_gate_wallet'");
        if (empty($wallet_idx)) {
            $this->wpdb->query("ALTER TABLE `{$members_table}` ADD UNIQUE KEY uq_gate_wallet (gate_id, wallet_address)");
        }

        if (get_option('growl_telegram_footer_slots_seeded', '')) {
            return;
        }

        $count = (int) $this->get_var(
            "SELECT COUNT(*) FROM {$this->table_prefix}telegram_footer_slots"
        );
        if ($count > 0) {
            update_option('growl_telegram_footer_slots_seeded', '1', false);
            return;
        }

        $defaults = [
            ['add_group', 'Add to your group', '➕ Add to your group', 'https://t.me/EarnFiHubBot?startgroup=setup', 'url', 120],
            ['create_campaign', 'Create a campaign', '🚀 Create a campaign', '/creator-jobs', 'deep_link', 100],
            ['browse_jobs', 'Browse all jobs', '💼 Browse all jobs', '/tasks', 'deep_link', 90],
            ['join_community', 'Join EarnFi Telegram', '💬 Join EarnFi Telegram', 'https://t.me/EarnFidotfun1', 'url', 80],
            ['holder_perks', 'EARNFI holder perks', '💎 $EARNFI holder perks', '/verify', 'command', 70],
        ];

        foreach ($defaults as $row) {
            $this->insert('telegram_footer_slots', [
                'slot_key'     => $row[0],
                'label'        => $row[1],
                'button_text'  => $row[2],
                'target'       => $row[3],
                'action_type'  => $row[4],
                'weight'       => $row[5],
                'is_active'    => 1,
                'tier'         => 'platform',
            ]);
        }

        update_option('growl_telegram_footer_slots_seeded', '1', false);
    }

    /**
     * Work-Money layer: x402 settlements, ledgers, deals, receipts.
     */
    public function ensure_work_money_layer() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p       = $this->table_prefix;
        $charset = $this->wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$p}x402_settlements (
            settlement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tx_hash VARCHAR(128) NULL,
            payer_wallet VARCHAR(44) NULL,
            payee_wallet VARCHAR(44) NULL,
            amount_micro BIGINT NOT NULL,
            network VARCHAR(32) NOT NULL DEFAULT 'solana',
            asset VARCHAR(16) NOT NULL DEFAULT 'USDC',
            x402_resource VARCHAR(500) NULL,
            purpose VARCHAR(40) NOT NULL,
            ref_type VARCHAR(32) NULL,
            ref_id VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            agent_id VARCHAR(32) NULL,
            facilitator_id VARCHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'settled',
            source VARCHAR(20) NOT NULL DEFAULT 'x402',
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (settlement_id),
            UNIQUE KEY uq_settlement_idempotency (idempotency_key),
            KEY idx_tx_purpose (tx_hash, purpose),
            KEY idx_ref (ref_type, ref_id),
            KEY idx_user (user_id, created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}balance_ledger (
            ledger_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            amount_micro BIGINT NOT NULL,
            delta_available BIGINT NOT NULL DEFAULT 0,
            delta_escrow BIGINT NOT NULL DEFAULT 0,
            delta_dispute BIGINT NOT NULL DEFAULT 0,
            delta_restriction BIGINT NOT NULL DEFAULT 0,
            delta_outbound_pending BIGINT NOT NULL DEFAULT 0,
            delta_debt BIGINT NOT NULL DEFAULT 0,
            entry_type VARCHAR(32) NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            ref_type VARCHAR(32) NULL,
            ref_id VARCHAR(64) NULL,
            settlement_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ledger_id),
            UNIQUE KEY uq_balance_idempotency (idempotency_key),
            KEY idx_user_created (user_id, created_at),
            KEY idx_ref (ref_type, ref_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}wallet_transactions (
            tx_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'succeeded',
            amount_micro BIGINT NOT NULL,
            title VARCHAR(120) NOT NULL DEFAULT '',
            subtitle VARCHAR(200) NULL,
            bucket VARCHAR(20) NULL,
            ref_type VARCHAR(32) NULL,
            ref_id VARCHAR(64) NULL,
            tx_hash VARCHAR(128) NULL,
            network VARCHAR(32) NULL,
            meta_json LONGTEXT NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tx_id),
            UNIQUE KEY uq_wallet_tx_idempotency (idempotency_key),
            KEY idx_user_created (user_id, created_at),
            KEY idx_ref (ref_type, ref_id),
            KEY idx_user_type (user_id, type)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}escrow_ledger (
            escrow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            buyer_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            buyer_ref VARCHAR(44) NOT NULL,
            seller_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            seller_ref VARCHAR(44) NOT NULL,
            amount_micro BIGINT NOT NULL,
            status ENUM('locked','released','refunded','disputed') NOT NULL DEFAULT 'locked',
            settlement_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (escrow_id),
            UNIQUE KEY uq_escrow_idempotency (idempotency_key),
            KEY idx_ref_status (ref_type, ref_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}deals (
            deal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            seller_user_id BIGINT UNSIGNED NOT NULL,
            buyer_user_id BIGINT UNSIGNED NULL,
            created_by_role ENUM('seller','buyer') NOT NULL DEFAULT 'seller',
            kind VARCHAR(24) NOT NULL DEFAULT 'service',
            title VARCHAR(200) NOT NULL,
            scope_text TEXT NULL,
            amount_micro BIGINT NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USDC',
            status ENUM('draft','pending_funding','funded','delivered','released','disputed','refunded','expired') NOT NULL DEFAULT 'draft',
            deadline_at DATETIME NULL,
            inspection_seconds INT UNSIGNED NULL,
            auto_release_at DATETIME NULL,
            fund_settlement_id BIGINT UNSIGNED NULL,
            platform_fee_bps INT UNSIGNED NOT NULL DEFAULT 1000,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (deal_id),
            UNIQUE KEY uq_deal_slug (public_slug),
            KEY idx_seller (seller_user_id, status),
            KEY idx_buyer (buyer_user_id, status),
            KEY idx_status_updated (status, updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_deals (
            agent_deal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            created_by_type ENUM('human','agent') NOT NULL,
            created_by_ref VARCHAR(44) NOT NULL,
            created_by_role ENUM('seller','buyer') NOT NULL DEFAULT 'seller',
            seller_type ENUM('human','agent') NOT NULL,
            seller_ref VARCHAR(44) NOT NULL,
            buyer_type ENUM('human','agent') NULL,
            buyer_ref VARCHAR(44) NULL,
            kind VARCHAR(24) NOT NULL DEFAULT 'service',
            title VARCHAR(200) NOT NULL,
            scope_text TEXT NULL,
            deliverable_text TEXT NULL,
            amount_micro BIGINT NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USDC',
            status ENUM('draft','pending_funding','funded','delivered','released','disputed','refunded','cancelled') NOT NULL DEFAULT 'draft',
            inspection_seconds INT UNSIGNED NULL,
            auto_release_at DATETIME NULL,
            fund_settlement_id BIGINT UNSIGNED NULL,
            platform_fee_bps INT UNSIGNED NOT NULL DEFAULT 1000,
            employer_fee_bps INT UNSIGNED NOT NULL DEFAULT 0,
            invite_token CHAR(32) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (agent_deal_id),
            UNIQUE KEY uq_agent_deal_slug (public_slug),
            KEY idx_seller (seller_type, seller_ref, status),
            KEY idx_buyer (buyer_type, buyer_ref, status),
            KEY idx_status_updated (status, updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}deal_deliverables (
            deliverable_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deal_id BIGINT UNSIGNED NOT NULL,
            milestone_id BIGINT UNSIGNED NULL,
            file_url VARCHAR(500) NULL,
            note TEXT NULL,
            submitted_by BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (deliverable_id),
            KEY idx_deal (deal_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_receipts (
            receipt_id VARCHAR(32) NOT NULL,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            payer_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            payer_ref VARCHAR(44) NOT NULL,
            payee_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            payee_ref VARCHAR(44) NOT NULL,
            worker_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            amount_micro BIGINT NOT NULL,
            fee_micro BIGINT NOT NULL DEFAULT 0,
            net_micro BIGINT NOT NULL DEFAULT 0,
            deliverable_hash CHAR(64) NULL,
            settlement_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (receipt_id),
            KEY idx_ref (ref_type, ref_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_balance_ledger (
            ledger_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id VARCHAR(32) NOT NULL,
            amount_micro BIGINT NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            ref_type VARCHAR(32) NULL,
            ref_id VARCHAR(64) NULL,
            settlement_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ledger_id),
            UNIQUE KEY uq_agent_ledger_idempotency (idempotency_key),
            KEY idx_agent (agent_id, created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_payout_prefs (
            agent_id VARCHAR(32) NOT NULL,
            default_settlement ENUM('ledger','onchain') NOT NULL DEFAULT 'ledger',
            auto_settle_enabled TINYINT(1) NOT NULL DEFAULT 0,
            auto_settle_asset VARCHAR(16) NOT NULL DEFAULT 'USDC',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (agent_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}payment_intents (
            intent_id VARCHAR(36) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            agent_id VARCHAR(32) NULL,
            purpose VARCHAR(40) NOT NULL,
            amount_micro BIGINT NOT NULL,
            resource_url VARCHAR(500) NULL,
            status ENUM('quoted','consumed','expired') NOT NULL DEFAULT 'quoted',
            expires_at DATETIME NOT NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (intent_id),
            UNIQUE KEY uq_pay_intent_idem (idempotency_key),
            KEY idx_status_exp (status, expires_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}deal_milestones (
            milestone_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deal_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            amount_micro BIGINT NOT NULL,
            status ENUM('pending','delivered','released') NOT NULL DEFAULT 'pending',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (milestone_id),
            KEY idx_deal (deal_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_disputes (
            dispute_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            opener_user_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NOT NULL,
            status ENUM('open','investigating','resolved','rejected') NOT NULL DEFAULT 'open',
            resolution VARCHAR(40) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (dispute_id),
            UNIQUE KEY uq_work_dispute_ref (ref_type, ref_id),
            KEY idx_opener (opener_user_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}job_listings (
            listing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            poster_user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            category VARCHAR(40) NULL,
            scope_text TEXT NULL,
            budget_micro BIGINT NOT NULL,
            deadline_at DATETIME NULL,
            slots INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('open','accepted','closed') NOT NULL DEFAULT 'open',
            accepted_application_id BIGINT UNSIGNED NULL,
            spawned_deal_id BIGINT UNSIGNED NULL,
            spawned_contract_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (listing_id),
            UNIQUE KEY uq_listing_slug (public_slug),
            KEY idx_poster (poster_user_id, status),
            KEY idx_status (status, created_at),
            KEY idx_category (category, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}job_applications (
            application_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            listing_id BIGINT UNSIGNED NOT NULL,
            applicant_user_id BIGINT UNSIGNED NOT NULL,
            pitch TEXT NULL,
            status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (application_id),
            UNIQUE KEY uq_listing_applicant (listing_id, applicant_user_id),
            KEY idx_listing (listing_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}job_contracts (
            contract_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            application_id BIGINT UNSIGNED NOT NULL,
            employer_user_id BIGINT UNSIGNED NOT NULL,
            worker_user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            scope_text TEXT NULL,
            amount_micro BIGINT NOT NULL,
            employer_fee_micro BIGINT NOT NULL DEFAULT 0,
            earner_fee_micro BIGINT NOT NULL DEFAULT 0,
            deposit_micro BIGINT NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USDC',
            status ENUM('pending_funding','funded','delivered','released','disputed','refunded','cancelled') NOT NULL DEFAULT 'pending_funding',
            fund_settlement_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (contract_id),
            UNIQUE KEY uq_jc_slug (public_slug),
            KEY idx_listing (listing_id),
            KEY idx_employer (employer_user_id, status),
            KEY idx_worker (worker_user_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}job_milestones (
            milestone_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contract_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            amount_micro BIGINT NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('pending','funded','delivered','released') NOT NULL DEFAULT 'pending',
            funded_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (milestone_id),
            KEY idx_contract (contract_id, sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}platform_fee_ledger (
            fee_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            amount_micro BIGINT NOT NULL,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (fee_id),
            UNIQUE KEY uq_fee_idem (idempotency_key),
            KEY idx_ref (ref_type, ref_id),
            KEY idx_created (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}capabilities (
            slug VARCHAR(64) NOT NULL,
            title VARCHAR(120) NOT NULL,
            description TEXT NULL,
            provider VARCHAR(40) NOT NULL DEFAULT 'open',
            catalog_path VARCHAR(120) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (slug)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_services (
            service_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id VARCHAR(32) NOT NULL,
            category VARCHAR(64) NOT NULL,
            title VARCHAR(200) NOT NULL,
            pricing_mode ENUM('fixed','quote') NOT NULL DEFAULT 'fixed',
            price_micro BIGINT NOT NULL DEFAULT 0,
            delivery_type ENUM('instant','protected') NOT NULL DEFAULT 'protected',
            endpoint_base VARCHAR(500) NULL,
            output_schema_json LONGTEXT NULL,
            input_schema_json LONGTEXT NULL,
            capabilities_json LONGTEXT NULL,
            models_json LONGTEXT NULL,
            deliverables_json LONGTEXT NULL,
            description TEXT NULL,
            revision_count INT UNSIGNED NOT NULL DEFAULT 2,
            sla_seconds INT UNSIGNED NOT NULL DEFAULT 86400,
            rating_avg DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            rating_count INT UNSIGNED NOT NULL DEFAULT 0,
            completed_count INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (service_id),
            KEY idx_agent (agent_id, status),
            KEY idx_category (category, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_orders (
            order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            provider_agent_id VARCHAR(32) NOT NULL,
            buyer_type ENUM('human','agent') NOT NULL DEFAULT 'human',
            buyer_ref VARCHAR(44) NOT NULL,
            amount_micro BIGINT NOT NULL,
            delivery_type ENUM('instant','protected') NOT NULL,
            status ENUM('pending_funding','funded','delivered','released','disputed','refunded') NOT NULL DEFAULT 'pending_funding',
            input_json LONGTEXT NULL,
            output_json LONGTEXT NULL,
            fund_settlement_id BIGINT UNSIGNED NULL,
            access_secret_hash CHAR(64) NULL,
            revision_count INT UNSIGNED NOT NULL DEFAULT 0,
            sla_due_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (order_id),
            UNIQUE KEY uq_order_slug (public_slug),
            KEY idx_provider (provider_agent_id, status),
            KEY idx_buyer (buyer_type, buyer_ref),
            KEY idx_sla_due (status, sla_due_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_orders (
            work_order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_slug VARCHAR(16) NOT NULL,
            buyer_user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            budget_micro BIGINT NOT NULL,
            spent_micro BIGINT NOT NULL DEFAULT 0,
            status ENUM('draft','funded','running','complete','cancelled') NOT NULL DEFAULT 'draft',
            fund_settlement_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (work_order_id),
            UNIQUE KEY uq_wo_slug (public_slug),
            KEY idx_buyer (buyer_user_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_order_children (
            child_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            work_order_id BIGINT UNSIGNED NOT NULL,
            child_type ENUM('agent_order','task','deal') NOT NULL,
            child_ref VARCHAR(500) NOT NULL,
            amount_micro BIGINT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (child_id),
            KEY idx_parent (work_order_id, sort_order)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_allowances (
            allowance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            granter_user_id BIGINT UNSIGNED NOT NULL,
            agent_id VARCHAR(32) NOT NULL,
            limit_micro BIGINT NOT NULL,
            spent_micro BIGINT NOT NULL DEFAULT 0,
            status ENUM('active','paused','revoked') NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (allowance_id),
            UNIQUE KEY uq_granter_agent (granter_user_id, agent_id),
            KEY idx_agent (agent_id, status)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}onchain_escrow_accounts (
            escrow_pda VARCHAR(44) NOT NULL,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            amount_micro BIGINT NOT NULL,
            status ENUM('open','released','refunded') NOT NULL DEFAULT 'open',
            last_indexed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (escrow_pda),
            UNIQUE KEY uq_onchain_ref (ref_type, ref_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}telegram_account_links (
            tg_user_id BIGINT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            link_code VARCHAR(12) NULL,
            link_code_expires_at DATETIME NULL,
            linked_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (tg_user_id),
            UNIQUE KEY uq_tg_user (user_id),
            UNIQUE KEY uq_link_code (link_code)
        ) $charset;");

        $tokens = $p . 'ai_agent_tokens';
        $tcols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$tokens}`", 0);
        if (is_array($tcols) && !in_array('token_version', $tcols, true)) {
            $this->wpdb->query("ALTER TABLE `{$tokens}` ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status");
        }

        $settle_cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$p}x402_settlements`", 0);
        if (is_array($settle_cols) && !in_array('facilitator_id', $settle_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}x402_settlements` ADD COLUMN facilitator_id VARCHAR(64) NULL AFTER agent_id");
        }
        $ledger_cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$p}balance_ledger`", 0);
        if (!is_array($ledger_cols)) {
            $ledger_cols = [];
        }
        if (!in_array('delta_dispute', $ledger_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}balance_ledger` ADD COLUMN delta_dispute BIGINT NOT NULL DEFAULT 0 AFTER delta_escrow");
            $ledger_cols[] = 'delta_dispute';
        }
        if (!in_array('delta_restriction', $ledger_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}balance_ledger` ADD COLUMN delta_restriction BIGINT NOT NULL DEFAULT 0 AFTER delta_dispute");
            $ledger_cols[] = 'delta_restriction';
        }
        if (!in_array('delta_outbound_pending', $ledger_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}balance_ledger` ADD COLUMN delta_outbound_pending BIGINT NOT NULL DEFAULT 0 AFTER delta_restriction");
            $ledger_cols[] = 'delta_outbound_pending';
        }
        if (!in_array('delta_debt', $ledger_cols, true)) {
            $after = in_array('delta_outbound_pending', $ledger_cols, true) ? 'delta_outbound_pending' : 'delta_restriction';
            $this->wpdb->query("ALTER TABLE `{$p}balance_ledger` ADD COLUMN delta_debt BIGINT NOT NULL DEFAULT 0 AFTER {$after}");
            $ledger_cols[] = 'delta_debt';
        }
        $order_cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$p}agent_orders`", 0);
        if (is_array($order_cols) && !in_array('revision_count', $order_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}agent_orders` ADD COLUMN revision_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER access_secret_hash");
        }
        $child_cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$p}work_order_children`", 0);
        if (is_array($child_cols) && in_array('child_ref', $child_cols, true)) {
            $this->wpdb->query("ALTER TABLE `{$p}work_order_children` MODIFY child_ref VARCHAR(500) NOT NULL");
        }

        dbDelta("CREATE TABLE {$p}work_threads (
            thread_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (thread_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_thread_refs (
            ref_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            ref_type VARCHAR(32) NOT NULL,
            ref_key VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ref_id),
            UNIQUE KEY uq_thread_ref (ref_type, ref_key),
            KEY idx_thread (thread_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_messages (
            message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_type ENUM('human','agent','system') NOT NULL DEFAULT 'human',
            sender_ref VARCHAR(44) NOT NULL DEFAULT '',
            kind ENUM('user','system') NOT NULL DEFAULT 'user',
            body TEXT NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id),
            KEY idx_thread_msg (thread_id, message_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_thread_reads (
            read_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            participant_type ENUM('human','agent') NOT NULL,
            participant_ref VARCHAR(44) NOT NULL,
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (read_id),
            UNIQUE KEY uq_thread_participant (thread_id, participant_type, participant_ref),
            KEY idx_participant (participant_type, participant_ref)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_reviews (
            review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            rater_type ENUM('human','agent') NOT NULL,
            rater_ref VARCHAR(44) NOT NULL,
            ratee_type ENUM('human','agent') NOT NULL,
            ratee_ref VARCHAR(44) NOT NULL,
            service_id BIGINT UNSIGNED NULL,
            stars TINYINT UNSIGNED NOT NULL,
            comment VARCHAR(500) NOT NULL DEFAULT '',
            private_note VARCHAR(500) NOT NULL DEFAULT '',
            tags_json LONGTEXT NULL,
            status ENUM('public','hidden') NOT NULL DEFAULT 'public',
            edit_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (review_id),
            UNIQUE KEY uq_review_rater (ref_type, ref_id, rater_type, rater_ref),
            KEY idx_ratee (ratee_type, ratee_ref, created_at),
            KEY idx_service (service_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}work_events (
            event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type VARCHAR(32) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            actor_type ENUM('human','agent','system') NOT NULL DEFAULT 'system',
            actor_ref VARCHAR(44) NOT NULL DEFAULT '',
            event_key VARCHAR(64) NOT NULL,
            payload_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id),
            KEY idx_ref_created (ref_type, ref_id, event_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}said_feedback_queue (
            queue_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_wallet VARCHAR(44) NOT NULL,
            task_id VARCHAR(32) NOT NULL DEFAULT '',
            completion_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_slug VARCHAR(16) NULL,
            outcome VARCHAR(16) NOT NULL DEFAULT 'positive',
            signer_wallet VARCHAR(44) NOT NULL DEFAULT '',
            message TEXT NULL,
            signature_json LONGTEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(200) NULL,
            status ENUM('pending','sent','skipped') NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (queue_id),
            UNIQUE KEY uq_said_feedback (worker_wallet, order_slug, completion_id),
            KEY idx_status (status, attempts)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}agent_order_webhooks (
            webhook_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(200) NULL,
            status ENUM('pending','sent','skipped') NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (webhook_id),
            UNIQUE KEY uq_order_event (order_id, event_key),
            KEY idx_webhook_status (status, attempts)
        ) $charset;");

        dbDelta("CREATE TABLE {$p}open_work_submissions (
            submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_type ENUM('task','job_listing') NOT NULL DEFAULT 'task',
            ref_id VARCHAR(64) NOT NULL,
            submitter_type ENUM('human','agent') NOT NULL,
            submitter_ref VARCHAR(44) NOT NULL,
            work_mode ENUM('brief','sprint','pitch','prove','bid') NOT NULL,
            payload_json LONGTEXT NULL,
            bid_micro BIGINT NULL,
            score DECIMAL(5,2) NULL,
            status ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (submission_id),
            KEY idx_ref (ref_type, ref_id, status),
            KEY idx_submitter (submitter_type, submitter_ref, status),
            KEY idx_mode_status (work_mode, status)
        ) $charset;");

        $this->ensure_work_money_layer_columns();
        $this->seed_work_money_capabilities();

        $users = $p . 'users';
        $cols  = $this->wpdb->get_col("SHOW COLUMNS FROM `{$users}`", 0);
        $add   = [
            'available_micro'         => "ALTER TABLE `{$users}` ADD COLUMN available_micro BIGINT NOT NULL DEFAULT 0 AFTER usd_balance",
            'escrow_held_micro'       => "ALTER TABLE `{$users}` ADD COLUMN escrow_held_micro BIGINT NOT NULL DEFAULT 0 AFTER available_micro",
            'dispute_held_micro'      => "ALTER TABLE `{$users}` ADD COLUMN dispute_held_micro BIGINT NOT NULL DEFAULT 0 AFTER escrow_held_micro",
            'restriction_held_micro'  => "ALTER TABLE `{$users}` ADD COLUMN restriction_held_micro BIGINT NOT NULL DEFAULT 0 AFTER dispute_held_micro",
            'outbound_pending_micro'  => "ALTER TABLE `{$users}` ADD COLUMN outbound_pending_micro BIGINT NOT NULL DEFAULT 0 AFTER restriction_held_micro",
            'debt_owed_micro'         => "ALTER TABLE `{$users}` ADD COLUMN debt_owed_micro BIGINT NOT NULL DEFAULT 0 AFTER outbound_pending_micro",
            'rating_avg'              => "ALTER TABLE `{$users}` ADD COLUMN rating_avg DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER debt_owed_micro",
            'rating_count'            => "ALTER TABLE `{$users}` ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg",
        ];
        foreach ($add as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                // rating_avg may already exist AFTER dispute_held_micro on older installs — add new buckets before it.
                if ($col === 'restriction_held_micro' && in_array('rating_avg', $cols, true) && !in_array('restriction_held_micro', $cols, true)) {
                    $this->wpdb->query("ALTER TABLE `{$users}` ADD COLUMN restriction_held_micro BIGINT NOT NULL DEFAULT 0 AFTER dispute_held_micro");
                    $cols[] = 'restriction_held_micro';
                    continue;
                }
                if ($col === 'outbound_pending_micro' && !in_array('outbound_pending_micro', $cols, true)) {
                    $after = in_array('restriction_held_micro', $cols, true) ? 'restriction_held_micro' : 'dispute_held_micro';
                    $this->wpdb->query("ALTER TABLE `{$users}` ADD COLUMN outbound_pending_micro BIGINT NOT NULL DEFAULT 0 AFTER {$after}");
                    $cols[] = 'outbound_pending_micro';
                    continue;
                }
                if ($col === 'debt_owed_micro' && !in_array('debt_owed_micro', $cols, true)) {
                    $after = in_array('outbound_pending_micro', $cols, true) ? 'outbound_pending_micro' : (in_array('restriction_held_micro', $cols, true) ? 'restriction_held_micro' : 'dispute_held_micro');
                    $this->wpdb->query("ALTER TABLE `{$users}` ADD COLUMN debt_owed_micro BIGINT NOT NULL DEFAULT 0 AFTER {$after}");
                    $cols[] = 'debt_owed_micro';
                    continue;
                }
                if ($col === 'rating_avg' || $col === 'rating_count') {
                    // Already present on upgraded installs; skip failed AFTER debt_owed if column exists.
                    if (in_array($col, $cols, true)) {
                        continue;
                    }
                }
                $this->wpdb->query($sql);
                $cols[] = $col;
            }
        }

        // One-time: move restriction_earn_hold escrow into restriction_held_micro.
        if (!get_option('growl_migr_restriction_bucket_v1')) {
            $this->migrate_restriction_escrow_to_bucket();
            update_option('growl_migr_restriction_bucket_v1', 1, false);
        }
        // One-time: mirror legacy negative_balance into debt_owed_micro via ledger.
        if (!get_option('growl_migr_debt_owed_from_negative_v1')) {
            $this->migrate_negative_balance_to_debt();
            update_option('growl_migr_debt_owed_from_negative_v1', 1, false);
        }

        if (!get_option('growl_migr_work_money_micro_v1')) {
            $this->wpdb->query(
                "UPDATE `{$users}` SET available_micro = ROUND(usd_balance * 1000000),
                 dispute_held_micro = ROUND(dispute_usd_balance * 1000000)
                 WHERE available_micro = 0 AND usd_balance > 0"
            );
            update_option('growl_migr_work_money_micro_v1', 1, false);
        }

        if (!get_option('growl_migr_trust_score_100_v1')) {
            $this->wpdb->query(
                "UPDATE `{$users}` SET reputation_score = GREATEST(60, LEAST(100, ROUND(reputation_score / 5)))"
            );
            update_option('growl_migr_trust_score_100_v1', 1, false);
        }

        if (!get_option('growl_migr_withdrawal_ledger_backfill_v1')) {
            $this->backfill_withdrawal_ledger_entries();
            update_option('growl_migr_withdrawal_ledger_backfill_v1', 1, false);
        }
    }

    /**
     * One-time: move net restriction_earn_hold escrow into restriction_held_micro.
     */
    private function migrate_restriction_escrow_to_bucket() {
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        if (!class_exists('Growl_Balance_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-balance-service.php';
        }
        $p = $this->table_prefix;
        $rows = $this->wpdb->get_results(
            "SELECT user_id, COALESCE(SUM(delta_escrow), 0) AS held
             FROM `{$p}balance_ledger`
             WHERE purpose = 'restriction_earn_hold'
             GROUP BY user_id
             HAVING held > 0
             LIMIT 5000"
        );
        if (empty($rows)) {
            return;
        }
        $balance = new Growl_Balance_Service($this);
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $held = Growl_Money::assert_micro($row->held ?? '0');
            if ($uid < 1 || !Growl_Money::is_positive($held)) {
                continue;
            }
            $balance->apply_ledger_entry([
                'user_id'                 => $uid,
                'delta_escrow_micro'      => Growl_Money::sub('0', $held),
                'delta_restriction_micro' => $held,
                'entry_type'              => 'migration',
                'purpose'                 => 'restriction_bucket_migrate',
                'ref_type'                => 'restriction',
                'ref_id'                  => (string) $uid,
                'idempotency_key'         => 'migrate:restriction_bucket:v1:' . $uid,
            ]);
        }
    }

    /**
     * One-time: seed debt_owed_micro from legacy negative_balance via ledger.
     */
    private function migrate_negative_balance_to_debt() {
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        if (!class_exists('Growl_Balance_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-balance-service.php';
        }
        $p = $this->table_prefix;
        $rows = $this->wpdb->get_results(
            "SELECT user_id, negative_balance FROM `{$p}users`
             WHERE COALESCE(negative_balance, 0) > 0
             LIMIT 5000"
        );
        if (empty($rows)) {
            return;
        }
        $balance = new Growl_Balance_Service($this);
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $micro = Growl_Money::to_micro(abs((float) ($row->negative_balance ?? 0)));
            if ($uid < 1 || !Growl_Money::is_positive($micro)) {
                continue;
            }
            $balance->apply_ledger_entry([
                'user_id'          => $uid,
                'delta_debt_micro' => $micro,
                'entry_type'       => 'legacy_bootstrap',
                'purpose'          => 'legacy_debt_bootstrap',
                'ref_type'         => 'system',
                'ref_id'           => 'negative_balance',
                'idempotency_key'  => 'migrate:debt_owed:v1:' . $uid,
            ]);
        }
    }

    /**
     * One-time: ledger debits for withdrawals created before ledger-integrated payouts.
     * Prevents balance sync from restoring spendable funds after legacy-only usd_balance deducts.
     */
    private function backfill_withdrawal_ledger_entries() {
        if (!class_exists('Growl_Money')) {
            require_once GROWL_PLUGIN_PATH . 'includes/money/class-growl-money.php';
        }
        if (!class_exists('Growl_Balance_Service')) {
            require_once GROWL_PLUGIN_PATH . 'includes/work-money/class-growl-balance-service.php';
        }
        $p = $this->table_prefix;
        $withdrawals = $this->wpdb->get_results(
            "SELECT w.withdrawal_id, w.user_id, w.amount, w.status
             FROM `{$p}withdrawals` w
             LEFT JOIN `{$p}balance_ledger` bl
               ON bl.idempotency_key = CONCAT('withdraw:', w.withdrawal_id)
             WHERE bl.ledger_id IS NULL
               AND w.status IN ('pending','processing','completed')
             ORDER BY w.withdrawal_id ASC
             LIMIT 5000"
        );
        if (empty($withdrawals)) {
            return;
        }
        $balance = new Growl_Balance_Service($this);
        foreach ($withdrawals as $row) {
            $wid = (int) $row->withdrawal_id;
            $uid = (int) $row->user_id;
            if ($wid < 1 || $uid < 1) {
                continue;
            }
            $micro = Growl_Money::to_micro((float) $row->amount);
            if (!Growl_Money::is_positive($micro)) {
                continue;
            }
            $balance->debit_withdrawal($uid, $micro, $wid, true);
        }
    }

    /**
     * Additive columns for existing installs (dbDelta does not reliably add every column).
     */
    private function ensure_work_money_layer_columns() {
        $p = $this->table_prefix;
        $this->add_column_if_missing($p . 'agent_services', 'description', 'description TEXT NULL AFTER title');
        $this->add_column_if_missing($p . 'agent_services', 'models_json', 'models_json LONGTEXT NULL AFTER capabilities_json');
        $this->add_column_if_missing($p . 'agent_services', 'deliverables_json', 'deliverables_json LONGTEXT NULL AFTER models_json');
        $this->add_column_if_missing($p . 'agent_services', 'input_schema_json', 'input_schema_json LONGTEXT NULL AFTER output_schema_json');
        $this->add_column_if_missing($p . 'agent_services', 'rating_avg', 'rating_avg DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER sla_seconds');
        $this->add_column_if_missing($p . 'agent_services', 'rating_count', 'rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg');
        $this->add_column_if_missing($p . 'agent_services', 'completed_count', 'completed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_count');
        $this->add_column_if_missing($p . 'agent_services', 'inspection_seconds', 'inspection_seconds INT UNSIGNED NULL AFTER sla_seconds');
        $this->add_column_if_missing($p . 'agent_orders', 'sla_due_at', 'sla_due_at DATETIME NULL AFTER revision_count');
        $this->add_column_if_missing($p . 'ai_agents', 'models_json', 'models_json LONGTEXT NULL AFTER profile_tags');
        $this->add_column_if_missing($p . 'ai_agents', 'rating_avg', 'rating_avg DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER models_json');
        $this->add_column_if_missing($p . 'ai_agents', 'rating_count', 'rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg');
        $this->add_column_if_missing($p . 'ai_agents', 'completed_orders', 'completed_orders INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_count');
        $this->add_column_if_missing($p . 'ai_agents', 'x_username', 'x_username VARCHAR(15) NULL AFTER completed_orders');
        $this->add_column_if_missing($p . 'ai_agents', 'x_user_id', 'x_user_id VARCHAR(32) NULL AFTER x_username');
        $this->add_column_if_missing($p . 'deals', 'invite_token', 'invite_token CHAR(32) NULL AFTER platform_fee_bps');
        $this->add_column_if_missing($p . 'deals', 'employer_fee_bps', 'employer_fee_bps INT UNSIGNED NOT NULL DEFAULT 0 AFTER invite_token');
        $this->add_column_if_missing($p . 'job_listings', 'spawned_contract_id', 'spawned_contract_id BIGINT UNSIGNED NULL AFTER spawned_deal_id');
        $this->add_column_if_missing($p . 'job_listings', 'category', 'category VARCHAR(40) NULL AFTER title');
        $this->add_column_if_missing($p . 'job_listings', 'deadline_at', 'deadline_at DATETIME NULL AFTER budget_micro');
        $this->add_column_if_missing($p . 'telegram_account_links', 'link_code_expires_at', 'link_code_expires_at DATETIME NULL AFTER link_code');
        $this->add_column_if_missing($p . 'job_listings', 'slots', 'slots INT UNSIGNED NOT NULL DEFAULT 1 AFTER budget_micro');
        $this->add_column_if_missing($p . 'deals', 'inspection_seconds', 'inspection_seconds INT UNSIGNED NULL AFTER deadline_at');
        $this->add_column_if_missing($p . 'deals', 'deal_meta_json', 'deal_meta_json LONGTEXT NULL AFTER inspection_seconds');
        $this->add_column_if_missing($p . 'users', 'work_prefs_json', 'work_prefs_json LONGTEXT NULL AFTER profile_tags');
        $this->add_column_if_missing($p . 'users', 'restriction_notice_seen_at', 'restriction_notice_seen_at DATETIME NULL AFTER restriction_reason');
        $this->add_column_if_missing($p . 'job_applications', 'is_shortlisted', 'is_shortlisted TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
        $this->add_column_if_missing($p . 'job_listings', 'payment_structure', "payment_structure VARCHAR(32) NULL DEFAULT 'single' AFTER slots");
        $this->add_column_if_missing($p . 'job_listings', 'milestones_json', 'milestones_json TEXT NULL AFTER payment_structure');
        $this->add_column_if_missing($p . 'job_listings', 'execution_mode', "execution_mode ENUM('human','agent','hybrid') NOT NULL DEFAULT 'hybrid' AFTER milestones_json");
        $this->add_column_if_missing($p . 'job_contracts', 'payment_structure', "payment_structure VARCHAR(32) NULL DEFAULT 'single' AFTER currency");
        $this->add_column_if_missing($p . 'open_work_submissions', 'completion_id', 'completion_id BIGINT UNSIGNED NULL AFTER score');
        $this->add_column_if_missing($p . 'job_milestones', 'funded_at', 'funded_at DATETIME NULL AFTER status');
        $this->add_column_if_missing($p . 'job_milestones', 'delivered_at', 'delivered_at DATETIME NULL AFTER funded_at');
        $this->add_column_if_missing($p . 'tasks', 'work_mode', "work_mode ENUM('brief','sprint','pitch','prove','bid') NULL AFTER execution_mode");
        $this->add_column_if_missing($p . 'work_receipts', 'receipt_meta_json', 'receipt_meta_json LONGTEXT NULL AFTER settlement_id');
        $this->add_column_if_missing($p . 'work_receipts', 'status', "status VARCHAR(32) NOT NULL DEFAULT 'released' AFTER receipt_meta_json");
        $this->add_column_if_missing($p . 'work_receipts', 'completed_at', 'completed_at DATETIME NULL AFTER status');
        if (!get_option('growl_migr_open_work_indexes_v1')) {
            $idx = $this->wpdb->get_results($this->wpdb->prepare(
                "SHOW INDEX FROM `{$p}tasks` WHERE Key_name = %s",
                'idx_tasks_work_mode'
            ));
            if (empty($idx)) {
                $this->wpdb->query("ALTER TABLE `{$p}tasks` ADD INDEX idx_tasks_work_mode (work_mode, status, execution_mode)");
            }
            update_option('growl_migr_open_work_indexes_v1', 1, false);
        }
        if (!get_option('growl_migr_marketplace_indexes_v1')) {
            $idx = $this->wpdb->get_results($this->wpdb->prepare(
                "SHOW INDEX FROM `{$p}agent_services` WHERE Key_name = %s",
                'idx_agent_services_browse'
            ));
            if (empty($idx)) {
                $this->wpdb->query("ALTER TABLE `{$p}agent_services` ADD INDEX idx_agent_services_browse (status, category, price_micro)");
            }
            update_option('growl_migr_marketplace_indexes_v1', 1, false);
        }
    }

    private function add_column_if_missing($table, $column, $ddl) {
        $cols = $this->wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (!is_array($cols) || in_array($column, $cols, true)) {
            return;
        }
        $this->wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    }

    private function seed_work_money_capabilities() {
        $p = $this->table_prefix . 'capabilities';
        $exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$p}'");
        if (!$exists) {
            return;
        }
        $rows = [
            ['web_research', 'Web research', 'Gather and summarize sources.', 'open', null],
            ['content_write', 'Content writing', 'Draft copy, posts, or docs.', 'open', null],
            ['human_qa', 'Human QA', 'Humans review agent output.', 'earnfi_humans', '/jobs/manual'],
            ['image_review', 'Image review', 'Visual inspection and labeling.', 'earnfi_humans', '/interrupt'],
            ['data_label', 'Data labeling', 'Classify or annotate datasets.', 'earnfi_humans', '/actions/research'],
            ['social_engage', 'Social engagement', 'Follows, likes, and posts.', 'earnfi_humans', '/jobs/social'],
            ['code_review', 'Code review', 'Review diffs and report issues.', 'open', null],
            ['translation', 'Translation', 'Translate between languages.', 'open', null],
            ['verify_work', 'Verify work', 'Confirm a deliverable matches scope.', 'earnfi_humans', '/actions/verify'],
        ];
        foreach ($rows as $row) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO {$p} (slug, title, description, provider, catalog_path, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s)",
                $row[0], $row[1], $row[2], $row[3], $row[4], gmdate('Y-m-d H:i:s')
            ));
        }
    }
}
