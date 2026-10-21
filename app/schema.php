<?php
declare(strict_types=1);

const SCHEMA_VERSION = 4;

/** All tables, in dependency order. {name} becomes `name` + TABLE_SUFFIX. Money is BIGINT minor units (paise). */
function mfnc_schema(): array {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $ts = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
    return [
    'meta' => "CREATE TABLE IF NOT EXISTS {meta} (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL) $t",
    'settings' => "CREATE TABLE IF NOT EXISTS {settings} (k VARCHAR(60) PRIMARY KEY, v TEXT NOT NULL) $t",
    'users' => "CREATE TABLE IF NOT EXISTS {users} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(80) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        status ENUM('active','disabled') NOT NULL DEFAULT 'active',
        last_login_at DATETIME NULL,
        $ts
    ) $t",
    'login_attempts' => "CREATE TABLE IF NOT EXISTS {login_attempts} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL, ip VARCHAR(45) NOT NULL DEFAULT '', at_ts INT UNSIGNED NOT NULL,
        INDEX idx_la (email, at_ts), INDEX idx_la_ip (ip, at_ts)
    ) $t",
    'audit_log' => "CREATE TABLE IF NOT EXISTS {audit_log} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id INT UNSIGNED NULL, action VARCHAR(60) NOT NULL, target VARCHAR(190) NOT NULL DEFAULT '',
        detail VARCHAR(500) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_time (created_at)
    ) $t",
    'preferences' => "CREATE TABLE IF NOT EXISTS {preferences} (
        user_id INT UNSIGNED PRIMARY KEY,
        currency CHAR(3) NOT NULL DEFAULT 'INR', locale VARCHAR(10) NOT NULL DEFAULT 'en-IN',
        theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
        reminder_days TINYINT UNSIGNED NOT NULL DEFAULT 7,
        big_purchase_threshold BIGINT NOT NULL DEFAULT 10000000,
        $ts,
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'accounts' => "CREATE TABLE IF NOT EXISTS {accounts} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        name VARCHAR(60) NOT NULL, type ENUM('bank','cash','wallet','card') NOT NULL DEFAULT 'bank',
        opening_balance BIGINT NOT NULL DEFAULT 0, opening_date DATE NOT NULL, archived TINYINT(1) NOT NULL DEFAULT 0,
        $ts,
        UNIQUE KEY uq_acc (user_id, name),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'categories' => "CREATE TABLE IF NOT EXISTS {categories} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        name VARCHAR(60) NOT NULL, grp ENUM('fixed','household','personal','occasional','custom','income') NOT NULL,
        parent_id INT UNSIGNED NULL,
        $ts,
        INDEX idx_cat_user (user_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_id) REFERENCES {categories}(id) ON DELETE CASCADE
    ) $t",
    'budgets' => "CREATE TABLE IF NOT EXISTS {budgets} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, category_id INT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL CHECK (amount >= 0),
        $ts,
        UNIQUE KEY uq_budget (user_id, category_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES {categories}(id) ON DELETE CASCADE
    ) $t",
    'bills' => "CREATE TABLE IF NOT EXISTS {bills} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        kind ENUM('bill','yearly') NOT NULL DEFAULT 'bill', name VARCHAR(100) NOT NULL,
        amount BIGINT NOT NULL CHECK (amount > 0),
        category_id INT UNSIGNED NULL, account_id INT UNSIGNED NULL,
        frequency ENUM('monthly','quarterly','half-yearly','yearly') NOT NULL DEFAULT 'monthly',
        first_due DATE NOT NULL, anchor_day TINYINT UNSIGNED NOT NULL, reminder_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
        active TINYINT(1) NOT NULL DEFAULT 1, notes VARCHAR(500) NULL,
        $ts,
        INDEX idx_bill_user (user_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES {categories}(id) ON DELETE SET NULL,
        FOREIGN KEY (account_id) REFERENCES {accounts}(id) ON DELETE SET NULL
    ) $t",
    'bill_occurrences' => "CREATE TABLE IF NOT EXISTS {bill_occurrences} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, bill_id INT UNSIGNED NOT NULL,
        due_date DATE NOT NULL, amount BIGINT NOT NULL CHECK (amount > 0), skipped TINYINT(1) NOT NULL DEFAULT 0,
        $ts,
        UNIQUE KEY uq_occ (bill_id, due_date),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (bill_id) REFERENCES {bills}(id) ON DELETE CASCADE
    ) $t",
    'transactions' => "CREATE TABLE IF NOT EXISTS {transactions} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type ENUM('income','expense','transfer','refund','adjustment') NOT NULL,
        status ENUM('completed','planned') NOT NULL DEFAULT 'completed',
        amount BIGINT NOT NULL, date DATE NOT NULL,
        account_id INT UNSIGNED NULL, to_account_id INT UNSIGNED NULL, category_id INT UNSIGNED NULL,
        description VARCHAR(200) NOT NULL DEFAULT '', payment_method VARCHAR(40) NULL, notes VARCHAR(500) NULL,
        refund_of INT UNSIGNED NULL, bill_id INT UNSIGNED NULL, occurrence_id INT UNSIGNED NULL, template_id INT UNSIGNED NULL,
        $ts,
        INDEX idx_tx_user_date (user_id, date), INDEX idx_tx_cat (user_id, category_id), INDEX idx_tx_occ (occurrence_id),
        CONSTRAINT chk_tx_amount CHECK ((type = 'adjustment' AND amount <> 0) OR (type <> 'adjustment' AND amount > 0)),
        CONSTRAINT chk_tx_transfer CHECK (type <> 'transfer' OR (account_id IS NOT NULL AND to_account_id IS NOT NULL AND account_id <> to_account_id)),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (account_id) REFERENCES {accounts}(id) ON DELETE RESTRICT,
        FOREIGN KEY (to_account_id) REFERENCES {accounts}(id) ON DELETE RESTRICT,
        FOREIGN KEY (category_id) REFERENCES {categories}(id) ON DELETE SET NULL,
        FOREIGN KEY (refund_of) REFERENCES {transactions}(id) ON DELETE SET NULL,
        FOREIGN KEY (bill_id) REFERENCES {bills}(id) ON DELETE SET NULL,
        FOREIGN KEY (occurrence_id) REFERENCES {bill_occurrences}(id) ON DELETE SET NULL
    ) $t",
    'recurring_templates' => "CREATE TABLE IF NOT EXISTS {recurring_templates} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type ENUM('income','expense') NOT NULL, description VARCHAR(100) NOT NULL, amount BIGINT NOT NULL CHECK (amount > 0),
        category_id INT UNSIGNED NULL, account_id INT UNSIGNED NULL,
        frequency ENUM('monthly','quarterly','half-yearly','yearly') NOT NULL,
        next_date DATE NOT NULL, anchor_day TINYINT UNSIGNED NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        $ts,
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES {categories}(id) ON DELETE SET NULL,
        FOREIGN KEY (account_id) REFERENCES {accounts}(id) ON DELETE SET NULL
    ) $t",
    'goals' => "CREATE TABLE IF NOT EXISTS {goals} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        kind ENUM('long-term','personal') NOT NULL DEFAULT 'long-term', name VARCHAR(80) NOT NULL,
        target_amount BIGINT NOT NULL DEFAULT 0 CHECK (target_amount >= 0), target_date DATE NULL,
        priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
        monthly_target BIGINT NOT NULL DEFAULT 0 CHECK (monthly_target >= 0), monthly_limit BIGINT NOT NULL DEFAULT 0 CHECK (monthly_limit >= 0),
        notes VARCHAR(500) NULL, archived TINYINT(1) NOT NULL DEFAULT 0,
        $ts,
        INDEX idx_goal_user (user_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'purchases' => "CREATE TABLE IF NOT EXISTS {purchases} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        kind ENUM('house','big','occasional') NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(300) NULL, group_label VARCHAR(60) NULL,
        estimated BIGINT NOT NULL CHECK (estimated > 0), actual BIGINT NULL CHECK (actual IS NULL OR actual >= 0),
        priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium', target_date DATE NULL,
        status ENUM('planning','saving','ready','purchased','postponed') NOT NULL DEFAULT 'planning',
        monthly_setaside BIGINT NOT NULL DEFAULT 0 CHECK (monthly_setaside >= 0), purchased_date DATE NULL,
        $ts,
        INDEX idx_pur_user (user_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    // Savings ledger. target_type/target_id is polymorphic (goal | purchase | bill). Positive = reserved, negative = released.
    'contributions' => "CREATE TABLE IF NOT EXISTS {contributions} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        target_type ENUM('goal','purchase','bill') NOT NULL, target_id INT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL CHECK (amount <> 0), date DATE NOT NULL,
        account_id INT UNSIGNED NULL, transaction_id INT UNSIGNED NULL, note VARCHAR(200) NULL,
        $ts,
        INDEX idx_contrib (user_id, target_type, target_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (account_id) REFERENCES {accounts}(id) ON DELETE SET NULL,
        FOREIGN KEY (transaction_id) REFERENCES {transactions}(id) ON DELETE CASCADE
    ) $t",
    'monthly_plans' => "CREATE TABLE IF NOT EXISTS {monthly_plans} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, month CHAR(7) NOT NULL,
        status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft', confirmed_at DATETIME NULL,
        $ts,
        UNIQUE KEY uq_plan (user_id, month),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'plan_lines' => "CREATE TABLE IF NOT EXISTS {plan_lines} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, plan_id INT UNSIGNED NOT NULL,
        section VARCHAR(20) NOT NULL, ref_type VARCHAR(12) NULL, ref_id INT UNSIGNED NULL, label VARCHAR(120) NOT NULL,
        amount BIGINT NOT NULL CHECK (amount >= 0), priority VARCHAR(8) NOT NULL DEFAULT 'medium',
        applied TINYINT(1) NOT NULL DEFAULT 0, sort INT NOT NULL DEFAULT 0,
        $ts,
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES {monthly_plans}(id) ON DELETE CASCADE
    ) $t",
    'notifications' => "CREATE TABLE IF NOT EXISTS {notifications} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, dedupe_key VARCHAR(60) NOT NULL,
        kind VARCHAR(20) NOT NULL, title VARCHAR(160) NOT NULL, body VARCHAR(300) NULL, due_date DATE NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
        $ts,
        UNIQUE KEY uq_note (user_id, dedupe_key),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    // ---- v2: dues & payments (PayU gateway), offline sync, usage tracking ----
    'charge_plans' => "CREATE TABLE IF NOT EXISTS {charge_plans} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL, description VARCHAR(300) NULL, amount BIGINT NOT NULL CHECK (amount > 0),
        day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (day_of_month BETWEEN 1 AND 28),
        start_month CHAR(7) NOT NULL, end_month CHAR(7) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT UNSIGNED NULL,
        $ts,
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'charges' => "CREATE TABLE IF NOT EXISTS {charges} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, plan_id INT UNSIGNED NULL, period CHAR(7) NULL,
        kind ENUM('monthly','one_time') NOT NULL DEFAULT 'one_time', title VARCHAR(160) NOT NULL, description VARCHAR(300) NULL,
        amount BIGINT NOT NULL CHECK (amount > 0), late_fee BIGINT NOT NULL DEFAULT 0 CHECK (late_fee >= 0), late_fee_waived TINYINT(1) NOT NULL DEFAULT 0, late_fee_at DATETIME NULL,
        due_date DATE NOT NULL, status ENUM('pending','paid','waived','cancelled') NOT NULL DEFAULT 'pending',
        paid_at DATETIME NULL, paid_via ENUM('gateway','manual') NULL, payment_id INT UNSIGNED NULL, note VARCHAR(300) NULL, created_by INT UNSIGNED NULL,
        $ts,
        UNIQUE KEY uq_charge_period (plan_id, period), INDEX idx_charge_user (user_id, status, due_date),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES {charge_plans}(id) ON DELETE SET NULL
    ) $t",
    'payments' => "CREATE TABLE IF NOT EXISTS {payments} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, charge_id INT UNSIGNED NOT NULL,
        api_payment_id VARCHAR(60) NOT NULL UNIQUE, embed_url VARCHAR(600) NOT NULL, amount BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'INR',
        status ENUM('pending','captured','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
        gateway_payment_id VARCHAR(80) NULL, method VARCHAR(30) NULL, error VARCHAR(300) NULL, expires_at DATETIME NOT NULL, captured_at DATETIME NULL, last_checked_at DATETIME NULL,
        $ts,
        INDEX idx_pay_charge (charge_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE,
        FOREIGN KEY (charge_id) REFERENCES {charges}(id) ON DELETE CASCADE
    ) $t",
    'webhook_events' => "CREATE TABLE IF NOT EXISTS {webhook_events} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, delivery_id VARCHAR(100) NOT NULL UNIQUE, event VARCHAR(60) NOT NULL, api_payment_id VARCHAR(60) NULL,
        result VARCHAR(60) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $t",
    'sync_ops' => "CREATE TABLE IF NOT EXISTS {sync_ops} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, client_uid VARCHAR(40) NOT NULL, kind VARCHAR(20) NOT NULL, result_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_sync (user_id, client_uid),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    'usage_days' => "CREATE TABLE IF NOT EXISTS {usage_days} (
        user_id INT UNSIGNED NOT NULL, day DATE NOT NULL, opens SMALLINT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (user_id, day),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    // ---- v3: passkeys (WebAuthn) ----
    'passkeys' => "CREATE TABLE IF NOT EXISTS {passkeys} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        credential_id VARBINARY(512) NOT NULL, public_key BLOB NOT NULL, alg SMALLINT NOT NULL, sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        transports VARCHAR(120) NULL, name VARCHAR(60) NOT NULL DEFAULT 'Passkey', last_used_at DATETIME NULL,
        $ts,
        UNIQUE KEY uq_cred (credential_id(255)), INDEX idx_pk_user (user_id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE
    ) $t",
    ];
}

/** Idempotent installer: runs once per schema version. Safe to call on every request. */
function mfnc_install(): void {
    try {
        $v = val('SELECT v FROM {meta} WHERE k = ?', ['schema']);
        if ($v !== null && (int) $v >= SCHEMA_VERSION) { return; }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') { throw $e; }   // anything other than "table missing" is a real error
    }
    foreach (mfnc_schema() as $sql) { db()->exec(sqlt($sql)); }
    mfnc_migrate();
    run('INSERT INTO {meta} (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['schema', (string) SCHEMA_VERSION]);
    run('INSERT IGNORE INTO {settings} (k, v) VALUES (?, ?), (?, ?), (?, ?), (?, ?)',
        ['registration_open', '1', 'default_currency', 'INR', 'default_locale', 'en-IN', 'site_notice', '']);
}

function column_exists(string $table, string $col): bool {
    return (int) val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table . TABLE_SUFFIX, $col]) > 0;
}
/** Columns added after v1 (CREATE TABLE IF NOT EXISTS never alters an existing table). Idempotent. */
function mfnc_migrate(): void {
    $adds = [
        ['users', 'billing_enforced', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['preferences', 'intro_first_day', 'DATE NULL'],
        ['preferences', 'intro_shown', 'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ['accounts', 'purpose', "ENUM('spending','income','savings','other') NOT NULL DEFAULT 'spending'"],
        ['accounts', 'label', 'VARCHAR(40) NULL'],
        ['purchases', 'account_id', 'INT UNSIGNED NULL'],
    ];
    foreach ($adds as [$t, $c, $def]) { if (!column_exists($t, $c)) { db()->exec(sqlt("ALTER TABLE {{$t}} ADD COLUMN `$c` $def")); } }
    $defaults = ['billing_enabled' => '1', 'grace_days' => '5', 'late_fee_type' => 'none', 'late_fee_flat' => '0', 'late_fee_percent' => '0', 'restrict_after_days' => '14', 'lock_after_days' => '30'];
    foreach ($defaults as $k => $v) { run('INSERT IGNORE INTO {settings} (k, v) VALUES (?, ?)', [$k, $v]); }
    // Tables created by older versions or a different server default can mix collations ("Illegal mix of collations" on joins): make them all the same.
    $st = db()->prepare('SELECT DISTINCT t.TABLE_NAME FROM information_schema.TABLES t LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.COLLATION_NAME IS NOT NULL AND c.COLLATION_NAME <> ? WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME LIKE ? AND t.TABLE_TYPE = ? AND (t.TABLE_COLLATION <> ? OR c.COLUMN_NAME IS NOT NULL)');
    $st->execute(['utf8mb4_unicode_ci', '%' . addcslashes(TABLE_SUFFIX, '_%') . '', 'BASE TABLE', 'utf8mb4_unicode_ci']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tn) { db()->exec('ALTER TABLE `' . str_replace('`', '', $tn) . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
}
