# ربات MahsaBot 🤖

**ربات مدیریت و فروش سرویس VPN در تلگرام**

یک ربات تلگرام مدولار و قدرتمند برای مدیریت سرویس‌های VPN با پشتیبانی از پنل‌های مختلف و درگاه‌های متنوع پرداخت.

---

## ✨ امکانات

### پنل‌های پشتیبانی شده
- **Marzban** — اتصال کامل REST API با احراز هویت JWT
- **Sanaei X-UI** — با API اختصاصی addClient/updateClient
- **Alireza X-UI** — پشتیبانی کامل مدیریت کلاینت
- **Vaxilu X-UI** — پشتیبانی از طریق بروزرسانی کامل Inbound
- **Niduka X-UI** — سازگار با Vaxilu

### پروتکل‌های VPN
- **VLess** — با پشتیبانی TLS، XTLS و Reality
- **VMess** — پروتکل کلاسیک V2Ray
- **Trojan** — تانلینگ مبتنی بر رمز عبور

### شبکه‌ها
- WebSocket (WS)، TCP، gRPC، KCP
- حالت CDN/Relay
- پیکربندی سفارشی SNI و مسیر

### درگاه‌های پرداخت
- 💳 **زرین‌پال** — درگاه پرداخت SOAP
- 💳 **NextPay** — درگاه جایگزین
- 🪙 **NowPayments** — پرداخت ارز دیجیتال
- 🪙 **ترون (TRX)** — تایید خودکار از بلاکچین TronGrid
- 🏦 **کارت به کارت** — واریز دستی با ارسال رسید
- 🔄 **WeSwap** — درگاه تبادل ارز

### امکانات کاربران
- 📱 خرید سرویس با فلوی راهنما
- 🔄 تمدید و مدیریت سرویس
- 📊 مشاهده ترافیک و QR Code
- 💰 سیستم کیف پول با انتقال موجودی
- 🎟 کدهای تخفیف
- 🎫 سیستم تیکت پشتیبانی
- 📎 لینک اشتراک (برای کلاینت‌های V2Ray)
- 👥 سیستم دعوت دوستان با پاداش

### امکانات مدیر
- 📈 داشبورد آمار جامع
- 👥 مدیریت کاربران (جستجو، مسدود، پیام، موجودی)
- 🖥 مدیریت سرور/نود (CRUD کامل)
- 📦 ساخت پلن/پکیج
- 👔 سیستم نمایندگی با تخفیف سفارشی
- 📢 ارسال پیام گروهی
- ⚙️ تنظیمات آنلاین ربات
- 🔍 جستجوی کانفیگ در تمام پنل‌ها
- 📊 گزارش خودکار روزانه

---

## 🚀 نصب سریع

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/benAliAlizadeh/mahsabot/main/mahsabot.sh)
```

### پیش‌نیازها
- سرور Ubuntu 20.04+ / Debian 11+
- PHP 7.4+ با ماژول‌های: mysql, curl, xml, soap, gd, mbstring, gmp
- MySQL 5.7+ / MariaDB 10.3+
- Apache 2.4+ با mod_rewrite
- گواهی SSL (Let's Encrypt توصیه می‌شود)
- دامنه متصل به سرور

---

## 📋 مراحل نصب دستی

1. **کلون کردن:**
   ```bash
   git clone https://github.com/benAliAlizadeh/mahsabot.git /var/www/mahsabot
   ```

2. **ساخت دیتابیس:**
   ```sql
   CREATE DATABASE mahsabot_db CHARACTER SET utf8mb4;
   CREATE USER 'mahsabot_user'@'localhost' IDENTIFIED BY 'رمز_شما';
   GRANT ALL ON mahsabot_db.* TO 'mahsabot_user'@'localhost';
   ```

3. **تنظیم کانفیگ:**
   ```bash
   cp config.sample.php config.php
   nano config.php
   ```

4. **ساخت جداول:**
   ```bash
   php -r "require 'config.php'; require 'core/database.php'; require 'setup/schema.php'; \$db=new mysqli(ESI_DB_HOST,ESI_DB_USER,ESI_DB_PASS,ESI_DB_NAME); esi_create_schema(\$db); esi_seed_defaults(\$db); echo 'OK';"
   ```

5. **تنظیم Webhook:**
   ```bash
   curl -G "https://api.telegram.org/botTOKEN/setWebhook" \
     --data-urlencode "url=https://domain.com/bot.php" \
     --data-urlencode 'allowed_updates=["message","callback_query","inline_query","chosen_inline_result"]'
   ```

6. **تنظیم Cron:**
   ```
   * * * * * www-data php /var/www/mahsabot/services/broadcaster.php
   */2 * * * * www-data php /var/www/mahsabot/services/tron_verifier.php
   0 */6 * * * www-data php /var/www/mahsabot/services/expiry_monitor.php
   */5 * * * * www-data php /var/www/mahsabot/services/gift_distributor.php
   0 8 * * * www-data php /var/www/mahsabot/services/report_sender.php
   ```

---

## 🔧 تنظیمات

تمام تنظیمات از طریق پنل ادمین ربات قابل تغییر هستند:
- تنظیمات فروش، قفل کانال، تایید شماره
- کلیدهای درگاه‌های پرداخت
- سیستم دعوت و پاداش
- حالت تایمر سرویس

---

## 📄 لایسنس

MIT — فایل [LICENSE](LICENSE) را ببینید.

---

**ساخته شده با ❤️ توسط تیم MahsaBot**

---

## ??? ???? ???

### ???? Access denied ???? `mahsabot_user@localhost`

??? ????? ??? ?? ????? ???? ????? ???? ??? ?? ??????:
`Access denied for user 'mahsabot_user'@'localhost' (using password: YES)`

???:
- ????? ????? MySQL ????? ????? ??? `config.php` ?? ????? ???? ????? ??? ???.

??? ??? ???? ??? ????:
1. ????? `ESI_DB_PASS` ?? ?? ???? `/var/www/mahsabot/config.php` ???????.
2. ????? MySQL ?? ?? ???? ????? sync ????:
   ```bash
   mysql -e "ALTER USER 'mahsabot_user'@'localhost' IDENTIFIED BY '<PASSWORD_FROM_CONFIG>'; FLUSH PRIVILEGES;"
   ```
3. ??????? ?? ?????? ???? ????:
   ```bash
   sudo bash /var/www/mahsabot/mahsabot.sh
   ```
   ? ????? `2) Update/repair existing install` ?? ?????.
4. ????? ??????? ?? ??? ????:
   ```bash
   php -r "require '/var/www/mahsabot/config.php'; new mysqli(ESI_DB_HOST,ESI_DB_USER,ESI_DB_PASS,ESI_DB_NAME); echo 'OK';"
   ```
5. ????? ? ???? ???? ?? ?? ????:
   ```bash
   curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
   ```
   Make sure `last_error_message` is empty and `allowed_updates` contains `callback_query` and `inline_query`, then test `/start`.

???? ??????:
- ??? ???? ????? ???? ???? ??? ?? ?? ????? ???? ???? ?? ?? ?? `@BotFather` ??? ????.

---

## Troubleshooting (Simple)

### SQL syntax error near `?`
If install fails in schema step with SQL syntax near `?`, update and run repair:
```bash
cd /var/www/mahsabot && git pull
sudo bash /var/www/mahsabot/mahsabot.sh
```
Choose `2) Update/repair existing install`.

### Certbot redirect conflict
If certbot issues cert but fails redirect enhancement, run:
```bash
certbot install --cert-name <your-domain> --apache --no-redirect
```
Then rerun installer repair.

### Quick repair command
```bash
sudo bash /var/www/mahsabot/mahsabot.sh
```
Then choose `2) Update/repair existing install`.

### /start repeats continuously
If `/start` keeps repeating:
- Update source and run repair:
```bash
cd /var/www/mahsabot && git pull
sudo bash /var/www/mahsabot/mahsabot.sh
```
Choose `2) Update/repair existing install`.
- Check webhook:
```bash
curl -s "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
```
Make sure `last_error_message` is empty and `allowed_updates` includes `callback_query` and `inline_query`.
