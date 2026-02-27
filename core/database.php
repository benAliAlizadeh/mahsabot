<?php
/**
 * MahsaBot - Database Helper Functions
 * Clean database operations with prepared statements
 * 
 * @package MahsaBot
 */

/**
 * Get member info by Telegram user ID
 */
function esi_get_member(mysqli $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT * FROM `esi_members` WHERE `tg_id` = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

/**
 * Create a new member
 */
function esi_create_member(mysqli $db, int $userId, string $name, string $username, int $referrer = 0): bool {
    $stmt = $db->prepare(
        "INSERT INTO `esi_members` (`tg_id`, `display_name`, `tg_username`, `balance`, `joined_at`, `referred_by`) 
         VALUES (?, ?, ?, 0, ?, ?)"
    );
    $now = time();
    $stmt->bind_param('issii', $userId, $name, $username, $now, $referrer);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Update member field
 */
function esi_update_member(mysqli $db, int $userId, string $field, $value): bool {
    $allowed = ['current_step', 'temp_data', 'phone', 'display_name', 'tg_username', 
                'balance', 'is_admin', 'is_agent', 'first_visit', 'agent_since',
                'discount_config', 'spam_data'];
    if (!in_array($field, $allowed)) return false;
    
    $stmt = $db->prepare("UPDATE `esi_members` SET `$field` = ? WHERE `tg_id` = ?");
    $stmt->bind_param('si', $value, $userId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Set member step (shortcut)
 */
function esi_set_step(mysqli $db, int $userId, string $step = 'idle'): bool {
    return esi_update_member($db, $userId, 'current_step', $step);
}

/**
 * Set member temp data (shortcut)
 */
function esi_set_temp(mysqli $db, int $userId, string $data = ''): bool {
    return esi_update_member($db, $userId, 'temp_data', $data);
}

/**
 * Decode JSON object/array option payload safely.
 */
function esi_decode_option_json(?string $raw): array {
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Build compatibility aliases for legacy option keys.
 */
function esi_apply_option_aliases(array $options, string $groupKey): array {
    if ($groupKey === 'BOT_CONFIG') {
        if (!isset($options['lockChannel']) && !empty($options['channelId'])) {
            $options['lockChannel'] = (string)$options['channelId'];
        }
        if (!isset($options['requirePhone']) && isset($options['phoneLock'])) {
            $options['requirePhone'] = (string)$options['phoneLock'];
        }
        if (!isset($options['custom_plan_enabled']) && isset($options['customPlanActive'])) {
            $options['custom_plan_enabled'] = (string)$options['customPlanActive'];
        }
        if (!isset($options['switch_location_enabled']) && isset($options['switchLocationActive'])) {
            $options['switch_location_enabled'] = (string)$options['switchLocationActive'];
        }
        if (!isset($options['test_account_enabled']) && isset($options['testAccount'])) {
            $options['test_account_enabled'] = (string)$options['testAccount'];
        }
        if (!isset($options['botActive'])) {
            $options['botActive'] = 'on';
        }
    }

    if ($groupKey === 'GATEWAY_KEYS') {
        if (!isset($options['cart_card_number']) && isset($options['bankAccount'])) {
            $options['cart_card_number'] = (string)$options['bankAccount'];
        }
        if (!isset($options['cart_card_holder']) && isset($options['holderName'])) {
            $options['cart_card_holder'] = (string)$options['holderName'];
        }
        if (!isset($options['tron_wallet_address']) && isset($options['tronwallet'])) {
            $options['tron_wallet_address'] = (string)$options['tronwallet'];
        }
        if (!isset($options['zarinpal_merchant'])) {
            if (isset($options['zarinpal'])) {
                $options['zarinpal_merchant'] = (string)$options['zarinpal'];
            } elseif (isset($options['zarinpalKey'])) {
                $options['zarinpal_merchant'] = (string)$options['zarinpalKey'];
            }
        }
        if (!isset($options['nextpay_api_key'])) {
            if (isset($options['nextpay'])) {
                $options['nextpay_api_key'] = (string)$options['nextpay'];
            } elseif (isset($options['nextpayKey'])) {
                $options['nextpay_api_key'] = (string)$options['nextpayKey'];
            }
        }
        if (!isset($options['nowpay_api_key'])) {
            if (isset($options['nowpayment'])) {
                $options['nowpay_api_key'] = (string)$options['nowpayment'];
            } elseif (isset($options['nowpayKey'])) {
                $options['nowpay_api_key'] = (string)$options['nowpayKey'];
            }
        }
        if (!isset($options['tron_usdt_rate']) && isset($options['TRXRate']) && (float)$options['TRXRate'] > 0) {
            $options['tron_usdt_rate'] = (string)$options['TRXRate'];
        }
    }

    return $options;
}

/**
 * Get bot options from settings table.
 * Supports both grouped JSON rows and legacy flat option rows.
 */
function esi_get_options(mysqli $db, string $key): array {
    $stmt = $db->prepare("SELECT `option_value` FROM `esi_options` WHERE `option_key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $grouped = esi_decode_option_json($row['option_value'] ?? null);
    if (!empty($grouped)) {
        return esi_apply_option_aliases($grouped, $key);
    }

    if (!in_array($key, ['BOT_CONFIG', 'GATEWAY_KEYS'], true)) {
        return [];
    }

    $rows = esi_fetch_all($db, "SELECT `option_key`, `option_value` FROM `esi_options`");
    $options = [];

    foreach ($rows as $r) {
        $optKey = (string)($r['option_key'] ?? '');
        $optVal = (string)($r['option_value'] ?? '');
        if ($optKey === '') {
            continue;
        }

        if (in_array($optKey, ['BOT_CONFIG', 'GATEWAY_KEYS'], true)) {
            $decoded = esi_decode_option_json($optVal);
            if (!empty($decoded)) {
                $options = array_merge($options, $decoded);
            }
            continue;
        }

        $options[$optKey] = $optVal;
    }

    return esi_apply_option_aliases($options, $key);
}

/**
 * Save grouped options to settings table.
 */
function esi_save_options(mysqli $db, string $key, array $data): bool {
    $optionValue = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("SELECT `id` FROM `esi_options` WHERE `option_key` = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        $stmt = $db->prepare("UPDATE `esi_options` SET `option_value` = ? WHERE `option_key` = ?");
        $stmt->bind_param('ss', $optionValue, $key);
    } else {
        $stmt = $db->prepare("INSERT INTO `esi_options` (`option_key`, `option_value`) VALUES (?, ?)");
        $stmt->bind_param('ss', $key, $optionValue);
    }

    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get subscriptions for a user
 */
function esi_get_subscriptions(mysqli $db, int $userId, int $limit = 10, int $offset = 0): array {
    $stmt = $db->prepare(
        "SELECT s.*, p.title as plan_title, n.title as node_title 
         FROM `esi_subscriptions` s
         LEFT JOIN `esi_packages` p ON s.`package_id` = p.`id`
         LEFT JOIN `esi_node_info` n ON s.`node_id` = n.`id`
         WHERE s.`member_id` = ? 
         ORDER BY s.`id` DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Count user subscriptions
 */
function esi_count_subscriptions(mysqli $db, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM `esi_subscriptions` WHERE `member_id` = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return (int)$count;
}

/**
 * Create a new transaction record
 */
function esi_create_transaction(mysqli $db, array $data): int {
    $stmt = $db->prepare(
        "INSERT INTO `esi_transactions` 
         (`ref_code`, `memo`, `gateway_ref`, `member_id`, `tx_type`, `package_id`, `volume`, `duration`, `amount`, `created_at`, `status`, `agent_purchase`, `agent_qty`, `tron_amount`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'sssisiiiissiid',
        $data['ref_code'], $data['memo'], $data['gateway_ref'],
        $data['member_id'], $data['tx_type'], $data['package_id'],
        $data['volume'], $data['duration'], $data['amount'],
        $data['created_at'], $data['status'], $data['agent_purchase'],
        $data['agent_qty'], $data['tron_amount']
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Create a subscription order
 */
function esi_create_subscription(mysqli $db, array $data): int {
    $stmt = $db->prepare(
        "INSERT INTO `esi_subscriptions` 
         (`member_id`, `token`, `tx_ref`, `package_id`, `node_id`, `inbound_id`, `config_name`, `config_uuid`, `protocol`, `expires_at`, `connect_link`, `amount`, `status`, `created_at`, `notified`, `relay_mode`, `agent_purchase`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)"
    );
    $stmt->bind_param(
        'issiissssissisi',
        $data['member_id'], $data['token'], $data['tx_ref'],
        $data['package_id'], $data['node_id'], $data['inbound_id'],
        $data['config_name'], $data['config_uuid'], $data['protocol'],
        $data['expires_at'], $data['connect_link'], $data['amount'],
        $data['status'], $data['created_at'], $data['relay_mode'],
        $data['agent_purchase']
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Generic query helper - fetch all rows
 */
function esi_fetch_all(mysqli $db, string $query, string $types = '', ...$params): array {
    $stmt = $db->prepare($query);
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Generic query helper - fetch single row
 */
function esi_fetch_one(mysqli $db, string $query, string $types = '', ...$params): ?array {
    $stmt = $db->prepare($query);
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/**
 * Generic query helper - execute (INSERT/UPDATE/DELETE)
 */
function esi_execute(mysqli $db, string $query, string $types = '', ...$params): bool {
    $stmt = $db->prepare($query);
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get last insert ID after esi_execute
 */
function esi_last_id(mysqli $db): int {
    return $db->insert_id;
}
