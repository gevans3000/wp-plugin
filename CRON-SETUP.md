# Setting Up Server Cron for WordPress

This guide will help you set up a proper server-level cron job for your WordPress site.

## Step 1: Upload Files
1. Upload `wp-cron-trigger.php` to your WordPress plugins directory
2. Upload `disable-wp-cron.php` to the same location

## Step 2: Disable WordPress Default Cron
1. Navigate to your WordPress plugins directory
2. Run: `php disable-wp-cron.php`
3. This will modify your wp-config.php and create a backup

## Step 3: Set Up Server Cron Job
1. Log into your hosting control panel (cPanel)
2. Find "Cron Jobs" or "Scheduled Tasks"
3. Add a new cron job with these settings:

### For cPanel:
- **Common Settings**: Select "Every 5 minutes"
- **Command**: `php /home/tzjwuepq/public_html/wp-content/plugins/sumai/wp-cron-trigger.php >/dev/null 2>&1`

### For Direct Server Access:
```bash
*/5 * * * * php /home/tzjwuepq/public_html/wp-content/plugins/sumai/wp-cron-trigger.php >/dev/null 2>&1
```

## Verification
1. Go to your Sumai plugin settings page
2. Check the "Debug Information" section
3. You should see your scheduled posts listed
4. Wait 5-10 minutes and refresh to ensure the cron job is running

## Troubleshooting
If posts aren't being created:
1. Check your server's error log
2. Ensure the paths in the cron command match your server setup
3. Verify PHP has permission to execute the script
4. Check that your RSS feeds are accessible
5. Verify your OpenAI API key is valid

## Support
If you need help, please:
1. Check the plugin's error log in wp-content/uploads/sumai-logs.log
2. Contact your hosting provider if you need help setting up cron jobs
3. Ensure your hosting plan supports cron jobs
