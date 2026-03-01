# MahsaBot 🤖

**Telegram VPN Subscription Management Bot**

A modular, feature-rich Telegram bot for managing VPN subscription services with support for multiple panel types and payment gateways.

---

## ✨ Features

### Panel Support
- **Marzban** — Full REST API integration with JWT authentication
- **Sanaei X-UI** — Cookie-based authentication with dedicated API endpoints
- **Alireza X-UI** — Full client management support
- **Vaxilu X-UI** — Legacy support with full inbound updates
- **Niduka X-UI** — Compatible with Vaxilu endpoints

### VPN Protocols
- **VLess** — With TLS, XTLS, Reality support
- **VMess** — Classic V2Ray protocol
- **Trojan** — Password-based tunneling

### Network Types
- WebSocket (WS), TCP, gRPC, KCP
- CDN/Relay mode support
- Custom SNI and path configuration

### Payment Gateways
- 💳 **Zarinpal** — Iranian payment gateway (SOAP)
- 💳 **NextPay** — Alternative Iranian gateway
- 🪙 **NowPayments** — Cryptocurrency payments
- 🪙 **Tron (TRX)** — Direct blockchain verification via TronGrid
- 🏦 **Cart-to-Cart** — Manual bank transfer with receipt verification
- 🔄 **WeSwap** — Exchange gateway

### User Features
- 📱 Subscription purchase with guided flow
- 🔄 Service renewal and management
- 📊 Traffic monitoring and QR codes
- 💰 Wallet system with balance transfers
- 🎟 Discount/coupon codes
- 🎫 Support ticket system
- 📎 Subscription link endpoint (for V2Ray clients)
- 👥 Referral/invite system with rewards

### Admin Features
- 📈 Comprehensive statistics dashboard
- 👥 User management (search, block, DM, balance)
- 🖥 Server/node CRUD management
- 📦 Package/plan builder
- 👔 Agency/dealer system with custom discounts
- 📢 Broadcast messaging (text, forward, copy)
- ⚙️ Runtime bot configuration
- 🔍 Cross-panel config search
- 📊 Automated daily reports

### Automated Services (Cron)
- 🔔 Expiry warnings and auto-disable
- ✅ TRX payment auto-verification
- 📢 Queue-based broadcast sender
- 🎁 Gift distribution (balance/volume/days)
- 📊 Daily admin reports

---

## 🚀 Installation

### Quick Install (Ubuntu/Debian)
```bash
bash <(curl -fsSL https://raw.githubusercontent.com/benAliAlizadeh/mahsabot/main/mahsabot.sh)
```

### Manual Installation

1. **Requirements:**
   - Ubuntu 20.04+ / Debian 11+
   - PHP 7.4+ with extensions: mysql, curl, xml, soap, gd, mbstring, gmp
   - MySQL 5.7+ / MariaDB 10.3+
   - Apache 2.4+ with mod_rewrite
   - SSL certificate (Let's Encrypt recommended)

2. **Clone repository:**
   ```bash
   git clone https://github.com/benAliAlizadeh/mahsabot.git /var/www/mahsabot
   cd /var/www/mahsabot
   ```

3. **Configure database:**
   ```sql
   CREATE DATABASE mahsabot_db CHARACTER SET utf8mb4;
   CREATE USER 'mahsabot_user'@'localhost' IDENTIFIED BY 'your_password';
   GRANT ALL ON mahsabot_db.* TO 'mahsabot_user'@'localhost';
   ```

4. **Setup configuration:**
   ```bash
   cp config.sample.php config.php
   nano config.php
   ```

5. **Create tables:**
   ```bash
   php -r "
   require 'config.php';
   require 'core/database.php';
   require 'setup/schema.php';
   \$db = new mysqli(ESI_DB_HOST, ESI_DB_USER, ESI_DB_PASS, ESI_DB_NAME);
   esi_create_schema(\$db);
   esi_seed_defaults(\$db);
   echo 'Done!';
   "
   ```

6. **Set webhook:**
   ```bash
   curl "https://api.telegram.org/botYOUR_TOKEN/setWebhook?url=https://yourdomain.com/bot.php"
   ```

7. **Setup cron jobs:**
   ```cron
   * * * * * www-data php /var/www/mahsabot/services/broadcaster.php
   */2 * * * * www-data php /var/www/mahsabot/services/tron_verifier.php
   0 */6 * * * www-data php /var/www/mahsabot/services/expiry_monitor.php
   */5 * * * * www-data php /var/www/mahsabot/services/gift_distributor.php
   0 8 * * * www-data php /var/www/mahsabot/services/report_sender.php
   ```

---

## 📁 Project Structure

```
mahsabot/
├── bot.php                 # Webhook entry point & router
├── config.php              # Configuration (auto-generated)
├── config.sample.php       # Configuration template
├── mahsabot.sh               # Install script
├── backup.sh               # Backup script
├── core/
│   ├── bootstrap.php       # Initialization & update parsing
│   ├── telegram.php        # Telegram Bot API wrapper
│   ├── database.php        # Database helper functions
│   ├── helpers.php         # Utility functions
│   └── middleware.php      # Spam, channel lock, phone verification
├── handlers/
│   ├── start.php           # /start, main menu, profile
│   ├── admin.php           # Admin panel & settings
│   ├── purchase.php        # Purchase flow
│   ├── payment.php         # Payment processing
│   ├── account.php         # Account management
│   ├── wallet.php          # Wallet operations
│   ├── server.php          # Server/node management
│   ├── category.php        # Category management
│   ├── plan.php            # Package/plan management
│   ├── discount.php        # Coupon management
│   ├── ticket.php          # Support tickets
│   ├── agent.php           # Agency system
│   └── search.php          # Config search
├── panels/
│   ├── xui.php             # X-UI panel API (all variants)
│   ├── marzban.php         # Marzban panel API
│   └── connection.php      # Connection link builder
├── services/
│   ├── broadcaster.php     # Broadcast sender (cron)
│   ├── tron_verifier.php   # TRX payment verifier (cron)
│   ├── expiry_monitor.php  # Expiry warnings (cron)
│   ├── gift_distributor.php # Gift distribution (cron)
│   ├── report_sender.php   # Daily reports (cron)
│   └── subscription.php    # Subscription link endpoint
├── gateway/
│   ├── initiate.php        # Payment gateway redirect
│   └── callback.php        # Payment verification callback
├── setup/
│   └── schema.php          # Database schema & migrations
├── locale/
│   ├── messages.php        # All bot messages (Persian)
│   └── buttons.php         # All button labels
├── lib/
│   ├── jdf.php             # Jalali (Persian) date library
│   └── phpqrcode/          # QR code generation library
└── web/
    ├── index.html          # Landing page
    └── lookup.php          # Config search page
```

---

## 🔧 Configuration

All settings are manageable through the bot's admin panel:
- **Bot Settings** — Selling toggle, channel lock, phone lock, spam protection
- **Payment Keys** — Gateway API keys, bank accounts, crypto wallets
- **Invite System** — Reward amounts, minimum requirements
- **Timer Mode** — Start-based or custom timing

---

## 🛡 Security

- All database queries use prepared statements
- Telegram webhook IP validation
- Cookie-based panel sessions with unique files per request
- No debug output to users
- Sensitive files protected via Apache config

---

## 📄 License

MIT License — See [LICENSE](LICENSE) for details.

---

## 🤝 Contributing

Contributions are welcome! Please submit pull requests with clear descriptions.

---

**Made with ❤️ by MahsaBot Team**

---

## Troubleshooting

### Access denied for `mahsabot_user@localhost` during install

If installer fails on schema step with:
`Access denied for user 'mahsabot_user'@'localhost' (using password: YES)`

Root cause:
- Existing MySQL user had an old password while `config.php` had a newer one.

Repair in place (no data loss):
1. Read `ESI_DB_PASS` from `/var/www/mahsabot/config.php`.
2. Sync MySQL password:
   ```bash
   mysql -e "ALTER USER 'mahsabot_user'@'localhost' IDENTIFIED BY '<PASSWORD_FROM_CONFIG>'; FLUSH PRIVILEGES;"
   ```
3. Run installer repair:
   ```bash
   sudo bash /var/www/mahsabot/mahsabot.sh
   ```
   Choose `2) Update/repair existing install`.
4. Verify DB connection:
   ```bash
   php -r "require '/var/www/mahsabot/config.php'; new mysqli(ESI_DB_HOST,ESI_DB_USER,ESI_DB_PASS,ESI_DB_NAME); echo 'OK';"
   ```
5. Verify webhook and bot response:
   ```bash
   curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
   ```
   Then send `/start` to the bot.

Security note:
- If a real bot token was exposed in logs or chats, rotate it in `@BotFather`.

### SQL syntax error near `?` during schema step

If installer fails with:
`You have an error in your SQL syntax ... near '?'`

Use the latest installer version, then run repair:
```bash
cd /var/www/mahsabot && git pull
sudo bash /var/www/mahsabot/mahsabot.sh
```
Choose `2) Update/repair existing install`.

### Certbot redirect conflict

If certificate is issued but Certbot cannot enable redirect enhancement automatically:
- Keep installation running (non-fatal).
- Re-run installer repair, or run:
  ```bash
  certbot install --cert-name <your-domain> --apache --no-redirect
  ```

### Quick repair path

```bash
sudo bash /var/www/mahsabot/mahsabot.sh
```
Then choose `2) Update/repair existing install`.
