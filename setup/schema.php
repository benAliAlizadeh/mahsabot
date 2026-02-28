<?php
/**
 * MahsaBot - Database Schema Creation
 *
 * Creates all tables for the mahsabot project.
 * Uses mysqli prepared statements. Requires a mysqli connection ($db).
 *
 * Table mapping (old wizwiz → new mahsabot):
 *   users           → esi_members
 *   admins          → esi_admins
 *   servers         → esi_servers
 *   server_info     → esi_node_info
 *   server_config   → esi_node_config
 *   server_categories → esi_groups
 *   server_plans    → esi_packages
 *   orders_list     → esi_subscriptions
 *   pays            → esi_transactions
 *   discounts       → esi_coupons
 *   chats           → esi_tickets
 *   chats_info      → esi_ticket_messages
 *   gift_list       → esi_gifts
 *   increase_day    → esi_addons_day
 *   increase_plan   → esi_addons_volume
 *   increase_order  → esi_addon_orders
 *   needed_sofwares → esi_applications
 *   setting         → esi_options
 *   black_list      → esi_blacklist
 *   send_list       → esi_broadcast
 */

/**
 * Create all mahsabot database tables.
 *
 * @param mysqli $db Active mysqli connection
 * @return bool True on success, false on any failure
 */
function esi_create_schema(mysqli $db): bool
{
    $queries = [];

    // 1. esi_members (was users)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_members` (
            `id`              INT           NOT NULL AUTO_INCREMENT,
            `tg_id`           BIGINT        NOT NULL,
            `display_name`    VARCHAR(255)  DEFAULT NULL,
            `tg_username`     VARCHAR(255)  DEFAULT NULL,
            `phone`           VARCHAR(20)   DEFAULT NULL,
            `balance`         BIGINT        NOT NULL DEFAULT 0,
            `joined_at`       INT           DEFAULT NULL,
            `current_step`    VARCHAR(100)  NOT NULL DEFAULT 'idle',
            `temp_data`       TEXT,
            `referred_by`     BIGINT        NOT NULL DEFAULT 0,
            `first_visit`     TINYINT       NOT NULL DEFAULT 1,
            `spam_data`       VARCHAR(255)  NOT NULL DEFAULT '',
            `is_agent`        TINYINT       NOT NULL DEFAULT 0,
            `discount_config` TEXT,
            `trial_used`      VARCHAR(10)   NOT NULL DEFAULT '',
            `referral_count`  INT           NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_tg_id` (`tg_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 2. esi_admins (was admins)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_admins` (
            `id`           INT           NOT NULL AUTO_INCREMENT,
            `tg_id`        BIGINT        NOT NULL,
            `display_name` VARCHAR(255)  NOT NULL DEFAULT '',
            `role`         VARCHAR(50)   NOT NULL DEFAULT 'admin',
            `created_at`   INT           DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 3. esi_servers (was servers)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_servers` (
            `id`      INT           NOT NULL AUTO_INCREMENT,
            `title`   VARCHAR(255)  DEFAULT NULL,
            `address` VARCHAR(255)  DEFAULT NULL,
            `port`    INT           DEFAULT NULL,
            `type`    VARCHAR(50)   DEFAULT NULL,
            `active`  TINYINT       NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 4. esi_node_info (was server_info)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_node_info` (
            `id`          INT           NOT NULL AUTO_INCREMENT,
            `title`       VARCHAR(255)  DEFAULT NULL,
            `flag`        VARCHAR(50)   NOT NULL DEFAULT '🌐',
            `capacity`    INT           NOT NULL DEFAULT 0,
            `active`      TINYINT       NOT NULL DEFAULT 1,
            `state`       TINYINT       NOT NULL DEFAULT 1,
            `description` TEXT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 5. esi_node_config (was server_config)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_node_config` (
            `id`              INT           NOT NULL AUTO_INCREMENT,
            `panel_url`       VARCHAR(500)  DEFAULT NULL,
            `username`        VARCHAR(255)  DEFAULT NULL,
            `password`        VARCHAR(255)  DEFAULT NULL,
            `panel_type`      VARCHAR(50)   NOT NULL DEFAULT 'sanaei',
            `ip`              TEXT,
            `sni`             VARCHAR(255)  NOT NULL DEFAULT '',
            `request_header`  TEXT,
            `response_header` TEXT,
            `header_type`     VARCHAR(50)   NOT NULL DEFAULT 'none',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 6. esi_groups (was server_categories)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_groups` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `node_id`    INT           NOT NULL,
            `title`      VARCHAR(255)  DEFAULT NULL,
            `active`     TINYINT       NOT NULL DEFAULT 1,
            `sort_order` INT           NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_node_id` (`node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 7. esi_packages (was server_plans)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_packages` (
            `id`                   INT           NOT NULL AUTO_INCREMENT,
            `group_id`             INT           NOT NULL,
            `node_id`              INT           NOT NULL,
            `inbound_id`           INT           NOT NULL DEFAULT 0,
            `title`                VARCHAR(255)  DEFAULT NULL,
            `description`          TEXT,
            `protocol`             VARCHAR(20)   NOT NULL DEFAULT 'vless',
            `volume`               FLOAT         NOT NULL DEFAULT 0,
            `duration`             INT           NOT NULL DEFAULT 30,
            `price`                INT           NOT NULL DEFAULT 0,
            `capacity`             INT           NOT NULL DEFAULT 0,
            `active`               TINYINT       NOT NULL DEFAULT 1,
            `sort_order`           INT           NOT NULL DEFAULT 0,
            `is_test`              TINYINT       NOT NULL DEFAULT 0,
            `net_type`             VARCHAR(20)   NOT NULL DEFAULT 'ws',
            `security`             VARCHAR(20)   NOT NULL DEFAULT 'tls',
            `flow`                 VARCHAR(100)  NOT NULL DEFAULT '',
            `relay_mode`           TINYINT       NOT NULL DEFAULT 0,
            `custom_sni`           TEXT,
            `custom_port`          INT           NOT NULL DEFAULT 0,
            `custom_path`          VARCHAR(255)  NOT NULL DEFAULT '',
            `reality_dest`         VARCHAR(255)  NOT NULL DEFAULT '',
            `reality_sni`          VARCHAR(255)  NOT NULL DEFAULT '',
            `reality_fingerprint`  VARCHAR(100)  NOT NULL DEFAULT '',
            `reality_spider`       VARCHAR(255)  NOT NULL DEFAULT '',
            `limit_ip`             INT           NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_group_id` (`group_id`),
            INDEX `idx_node_id` (`node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 8. esi_subscriptions (was orders_list)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_subscriptions` (
            `id`             INT           NOT NULL AUTO_INCREMENT,
            `member_id`      BIGINT        NOT NULL,
            `token`          VARCHAR(100)  DEFAULT NULL,
            `tx_ref`         VARCHAR(100)  DEFAULT NULL,
            `package_id`     INT           NOT NULL DEFAULT 0,
            `node_id`        INT           NOT NULL DEFAULT 0,
            `inbound_id`     INT           NOT NULL DEFAULT 0,
            `config_name`    VARCHAR(255)  DEFAULT NULL,
            `config_uuid`    VARCHAR(255)  DEFAULT NULL,
            `protocol`       VARCHAR(20)   DEFAULT NULL,
            `expires_at`     INT           DEFAULT NULL,
            `connect_link`   TEXT,
            `amount`         INT           NOT NULL DEFAULT 0,
            `status`         TINYINT       NOT NULL DEFAULT 1,
            `created_at`     INT           DEFAULT NULL,
            `notified`       TINYINT       NOT NULL DEFAULT 0,
            `relay_mode`     TINYINT       NOT NULL DEFAULT 0,
            `agent_purchase` TINYINT       NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_token` (`token`),
            INDEX `idx_member` (`member_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 9. esi_transactions (was pays)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_transactions` (
            `id`             INT           NOT NULL AUTO_INCREMENT,
            `ref_code`       VARCHAR(100)  DEFAULT NULL,
            `memo`           TEXT,
            `gateway_ref`    VARCHAR(255)  NOT NULL DEFAULT '',
            `member_id`      BIGINT        NOT NULL,
            `tx_type`        VARCHAR(50)   DEFAULT NULL,
            `package_id`     INT           NOT NULL DEFAULT 0,
            `volume`         FLOAT         NOT NULL DEFAULT 0,
            `duration`       INT           NOT NULL DEFAULT 0,
            `amount`         INT           NOT NULL DEFAULT 0,
            `created_at`     INT           DEFAULT NULL,
            `status`         VARCHAR(50)   NOT NULL DEFAULT 'pending',
            `agent_purchase` TINYINT       NOT NULL DEFAULT 0,
            `agent_qty`      INT           NOT NULL DEFAULT 0,
            `tron_amount`    FLOAT         NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_member` (`member_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 10. esi_coupons (was discounts)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_coupons` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `code`       VARCHAR(100)  NOT NULL,
            `type`       VARCHAR(20)   NOT NULL DEFAULT 'percent',
            `amount`     INT           NOT NULL DEFAULT 0,
            `max_uses`   INT           NOT NULL DEFAULT 0,
            `used_by`    TEXT,
            `active`     TINYINT       NOT NULL DEFAULT 1,
            `expires_at` INT           NOT NULL DEFAULT 0,
            `created_at` INT           DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 11. esi_tickets (was chats)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_tickets` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `member_id`  BIGINT        NOT NULL,
            `subject`    VARCHAR(255)  NOT NULL DEFAULT '',
            `status`     VARCHAR(20)   NOT NULL DEFAULT 'open',
            `created_at` INT           DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 12. esi_ticket_messages (was chats_info)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_ticket_messages` (
            `id`            INT           NOT NULL AUTO_INCREMENT,
            `ticket_id`     INT           NOT NULL,
            `sender_id`     BIGINT        NOT NULL,
            `message`       TEXT,
            `message_type`  VARCHAR(20)   NOT NULL DEFAULT 'text',
            `tg_message_id` INT           NOT NULL DEFAULT 0,
            `created_at`    INT           DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_ticket` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 13. esi_gifts (was gift_list)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_gifts` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `title`      VARCHAR(255)  DEFAULT NULL,
            `gift_type`  VARCHAR(20)   DEFAULT NULL,
            `amount`     FLOAT         DEFAULT NULL,
            `active`     TINYINT       NOT NULL DEFAULT 0,
            `created_at` INT           DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 14. esi_addons_day (was increase_day)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_addons_day` (
            `id`       INT     NOT NULL AUTO_INCREMENT,
            `duration` INT     DEFAULT NULL,
            `price`    INT     DEFAULT NULL,
            `active`   TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 15. esi_addons_volume (was increase_plan)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_addons_volume` (
            `id`     INT     NOT NULL AUTO_INCREMENT,
            `volume` FLOAT   DEFAULT NULL,
            `price`  INT     DEFAULT NULL,
            `active` TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 16. esi_addon_orders (was increase_order)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_addon_orders` (
            `id`          INT           NOT NULL AUTO_INCREMENT,
            `member_id`   BIGINT        DEFAULT NULL,
            `node_id`     INT           DEFAULT NULL,
            `inbound_id`  INT           DEFAULT NULL,
            `config_name` VARCHAR(255)  DEFAULT NULL,
            `amount`      FLOAT         DEFAULT NULL,
            `addon_type`  VARCHAR(20)   DEFAULT NULL,
            `created_at`  INT           DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 17. esi_applications (was needed_sofwares)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_applications` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `title`      VARCHAR(255)  DEFAULT NULL,
            `platform`   VARCHAR(50)   DEFAULT NULL,
            `link`       TEXT,
            `sort_order` INT           NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 18. esi_options (was setting)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_options` (
            `id`           INT           NOT NULL AUTO_INCREMENT,
            `option_key`   VARCHAR(255)  NOT NULL,
            `option_value` TEXT,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_option_key` (`option_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 19. esi_blacklist (was black_list)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_blacklist` (
            `id`         INT           NOT NULL AUTO_INCREMENT,
            `tg_id`      BIGINT        DEFAULT NULL,
            `reason`     VARCHAR(255)  NOT NULL DEFAULT '',
            `created_at` INT           DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_tg_id` (`tg_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // 20. esi_broadcast (was send_list)
    $queries[] = "
        CREATE TABLE IF NOT EXISTS `esi_broadcast` (
            `id`                INT           NOT NULL AUTO_INCREMENT,
            `message`           TEXT,
            `message_type`      VARCHAR(20)   NOT NULL DEFAULT 'text',
            `tg_message_id`     INT           NOT NULL DEFAULT 0,
            `status`            VARCHAR(20)   NOT NULL DEFAULT 'pending',
            `created_at`        INT           DEFAULT NULL,
            `last_processed_id` BIGINT        NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // Execute all table creation queries
    foreach ($queries as $sql) {
        if (!$db->query($sql)) {
            error_log('esi_create_schema error: ' . $db->error);
            return false;
        }
    }

    return true;
}

/**
 * Insert default option rows into esi_options.
 *
 * Uses INSERT IGNORE so existing keys are never overwritten.
 *
 * @param mysqli $db Active mysqli connection
 * @return void
 */
function esi_seed_defaults(mysqli $db): void
{
    $defaults = [
        // Core bot settings
        'BOT_TOKEN'      => '',
        'ADMIN_ID'       => '',
        'BOT_USERNAME'   => '',
        'BOT_URL'        => '',
        'BOT_DOMAIN'     => '',

        // Feature toggles
        'sellActive'           => 'on',
        'walletActive'         => 'on',
        'cartToCartActive'     => 'on',
        'customPlanActive'     => 'off',
        'testAccount'          => 'off',
        'switchLocationActive' => 'off',
        'addTimeActive'        => 'off',
        'addVolumeActive'      => 'off',

        // Restrictions
        'channelLock' => 'off',
        'channelId'   => '',
        'phoneLock'   => 'off',
        'spamLock'    => 'off',

        // Payment gateways - Zarinpal
        'zarinpalActive' => 'off',
        'zarinpalKey'    => '',

        // Payment gateways - Nextpay
        'nextpayActive' => 'off',
        'nextpayKey'    => '',

        // Payment gateways - NowPayments
        'nowpayWallet' => 'off',
        'nowpayKey'    => '',

        // Payment gateways - TRON / Cart-to-cart
        'tronwallet'   => '',
        'bankAccount'  => '',
        'holderName'   => '',

        // Payment gateways - Weswap
        'weswapActive' => 'off',
        'weswapKey'    => '',

        // Pricing
        'TRXRate'      => '0',
        'dayPrice'     => '100',
        'volumePrice'  => '100',

        // Misc settings
        'remarkType'   => 'username',
        'timerMode'    => 'start',

        // Referral / Invite
        'inviteReward'   => '0',
        'inviteMinimum'  => '0',
        'REFERRAL_REWARD' => '{"amount":0}',
    ];

    $stmt = $db->prepare(
        "INSERT IGNORE INTO `esi_options` (`option_key`, `option_value`) VALUES (?, ?)"
    );

    foreach ($defaults as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }

    $stmt->close();
}
