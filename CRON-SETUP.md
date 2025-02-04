# Cron Job Setup Guide for Sumai Plugin

## Why This Matters
Cron jobs ensure your daily summaries generate automatically. Without proper setup:
⚠️ Summaries won't create themselves
⚠️ Manual intervention required
⚠️ Plugin features won't work as intended

## Step 1: File Preparation
1️⃣ **Download** these files from the plugin package:
- `wp-cron-trigger.php`
- `disable-wp-cron.php`

2️⃣ **Upload** both files to:
`your-site-root/wp-content/plugins/sumai/`

## Step 2: Disable WordPress Cron (Required!)
```bash
php disable-wp-cron.php
```
✅ Creates `wp-config.php.backup`
✅ Modifies `wp-config.php`
✅ Confirmation message will appear

## Step 3: Server Cron Setup

### Option A: cPanel (Most Common)
1. Log in to your hosting account
2. Find "Cron Jobs" (usually under "Advanced")
3. Create new job with these settings:

| Field          | Value                                  |
|----------------|----------------------------------------|
| Minute         | */5                                    |
| Command        | `php /path/to/wp-cron-trigger.php`     |

🛠 **Path Finder**:
```
Your Site Root: /home/{username}/public_html/
Full Path: /home/{username}/public_html/wp-content/plugins/sumai/wp-cron-trigger.php
```

### Option B: SSH (Advanced Users)
```bash
crontab -e
```
Add this line:
```
*/5 * * * * php /path/to/wp-cron-trigger.php >/dev/null 2>&1
```

## Verification Checklist
1. Wait 15 minutes
2. Visit Sumai → Settings → Debug Info
3. Look for "Last cron execution" timestamp
4. Check for new summaries in your posts list

## Common Issues & Fixes

❌ "Cron job not running"
➡️ Verify path in cron command
➡️ Check file permissions (644 for PHP files)
➡️ Test PHP CLI version: `php -v`

❌ "Permission denied"
➡️ Contact host about cron access
➡️ Ask about "WP-CLI" alternatives

## Support Resources
► [Video Walkthrough](https://example.com/cron-setup) (3 min)
► Host-Specific Guides:
- SiteGround: https://sg.sg/cron-guide
- Bluehost: https://bh.io/cron-help

💡 Pro Tip: Set up [Health Check Plugin](https://wordpress.org/plugins/health-check/) to monitor cron status!
