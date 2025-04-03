# Sumai - AI-Powered Daily Summary Generator

<img src="https://img.shields.io/badge/version-1.3.1-blue.svg" alt="Version 1.3.1">
<img src="https://img.shields.io/badge/wordpress-5.8+-green.svg" alt="WordPress 5.8+">
<img src="https://img.shields.io/badge/php-7.4+-purple.svg" alt="PHP 7.4+">

Sumai is a WordPress plugin that automatically fetches content from your configured RSS feeds and generates concise daily summaries using OpenAI's GPT technology. Perfect for content aggregators, news sites, or any WordPress site wanting to provide AI-generated summaries of multiple sources.

## 🚀 Features

- ✅ Automatic daily summary generation from RSS feeds
- ✅ AI-powered content summarization using OpenAI's gpt-4o-mini model
- ✅ Background processing with Action Scheduler for improved performance
- ✅ Tracking system to process only unused articles
- ✅ Support for unlimited RSS feeds (with optimized processing)
- ✅ Customizable summary generation schedule
- ✅ Manual summary generation from admin panel
- ✅ Secure API key storage with encryption
- ✅ Comprehensive logging system
- ✅ Feed testing capabilities
- ✅ Draft mode option

## 📋 Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- OpenAI API key
- Valid RSS feed URLs
- Action Scheduler (automatically installed if missing)

## 💻 Installation

1. Download the plugin files
2. Upload the `sumai` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. If Action Scheduler is not already installed, you will be prompted to install it

## ⚙️ Configuration

### Setting Up Your OpenAI API Key

You have two options for configuring your OpenAI API key:

#### Option 1: Using the Admin Interface (Basic)
1. Go to WordPress Admin → Settings → Sumai
2. Enter your OpenAI API key in the "OpenAI API Key" field
3. Click "Save Changes"

#### Option 2: Using a .env File (Recommended for Security)
1. Create a file named `.env` in the plugin directory:
   ```
   /wp-content/plugins/sumai/.env
   ```
2. Add your OpenAI API key to the `.env` file:
   ```
   OPENAI_API_KEY=your-api-key-here
   ```
3. Set proper file permissions (if on Linux/Unix):
   ```bash
   chmod 600 /wp-content/plugins/sumai/.env
   ```
4. Make sure the file is readable by your web server but not publicly accessible

### Configuring RSS Feeds

1. Go to WordPress Admin → Settings → Sumai
2. In the "RSS Feeds" section, enter your feed URLs (one per line)
3. Configure the following settings:
   - Post Schedule: When to generate summaries
   - Context Prompt: How the AI should approach summarization
   - Title Prompt: How the AI should generate titles
   - Draft Mode: Enable to review posts before publishing

## 🔄 How It Works

The Sumai plugin operates with these optimized processes:

### Automated Daily Process
1. WP-Cron triggers the daily summary generation at your scheduled time
2. Sumai fetches new content from your configured RSS feeds
3. Only new, unused articles are processed (up to 3 per feed to optimize resource usage)
4. The content preparation is completed, and the main cron job schedules a background action
5. Action Scheduler takes over for API processing and post creation, freeing up resources
6. OpenAI API processes the content and generates a summary in the background
7. A new WordPress post is created with the AI-generated summary
8. Based on your settings, the post is either published or saved as a draft

### Manual Generation
You can also manually trigger summary generation using the "Generate Summary Now" button in the admin interface, which follows the same optimized process.

### Performance Optimization
The decoupling of API calls from the main process:
- Prevents timeouts during the main cron job
- Frees up server resources more quickly
- Makes the system more resilient to API delays and rate limits

## 🔍 Testing Your Setup

1. After configuration, use the "Test RSS Feeds" button to verify feed connectivity
2. Use the "Test API Key" button to verify your OpenAI API key (both admin and .env versions)
3. Check the debug information section for any potential issues
4. Monitor the first few automated runs to ensure everything works as expected

## 📝 Logging and Debugging

- Logs are stored in `/wp-content/uploads/sumai-logs/sumai.log`
- Logs include timestamps, process steps, and any errors encountered
- Logs are automatically pruned after 30 days to save space
- Check the debug information section in the admin interface for system status

## 🔒 Security Best Practices

1. Always use the `.env` file method for production environments
2. Never commit your `.env` file to version control
3. Ensure your `.env` file has restricted permissions
4. Regularly rotate your API key
5. Use WordPress's built-in user capabilities to restrict plugin access

## 🔄 Updating the Plugin

1. Deactivate the plugin
2. Replace the old plugin files with the new version
3. Reactivate the plugin
4. Check settings to ensure they're still configured correctly
5. Test functionality using the built-in test buttons

## ❗ Troubleshooting

### Common Issues

1. **Summaries Not Generating**
   - Check if WordPress cron is working
   - Verify OpenAI API key is valid
   - Check RSS feed URLs are accessible
   - Ensure Action Scheduler is running properly

2. **API Key Not Found**
   - Verify `.env` file location and format
   - Check file permissions
   - Try the "Test Hidden API" button

3. **Empty Summaries**
   - Check RSS feeds are returning content
   - Verify API key has sufficient credits
   - Check error logs for details

4. **Process Started but Never Completed**
   - Check Action Scheduler admin page for errors
   - Verify the background process has permission to run
   - Check server memory limits

### Getting Help

If you encounter issues:
1. Check the debug information in the admin interface
2. Review the log file at `/wp-content/uploads/sumai-logs/sumai.log`
3. Examine the Action Scheduler admin page for failed jobs
4. Ensure all requirements are met
5. Verify all configuration settings

## 📈 Best Practices

1. Start in draft mode to review AI-generated content
2. Use specific context prompts for better summaries
3. Monitor your OpenAI API usage
4. Regularly check your logs for issues
5. Keep the plugin and WordPress updated
6. Don't configure too many feeds if your server has limited resources

## 🛠️ Advanced Configuration

For developers and advanced users:
- The `.env` file can be placed in the WordPress root directory as a fallback
- Custom hooks and filters are available for extending functionality
- Debug mode can be enabled for detailed logging
- Action Scheduler settings can be adjusted for performance tuning

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 🔄 Changelog

### Version 1.3.1
- Added Action Scheduler integration for background processing
- Decoupled OpenAI API calls from main cron job for better performance
- Improved error handling and status tracking during processing
- Optimized resource usage by processing content in the background
- Added comprehensive logging during background processing
- Fixed potential timeout issues during API processing

### Version 1.3.0
- Updated to use OpenAI's gpt-4o-mini model
- Added support for tracking processed articles
- Limited processing to 3 unused articles per feed for efficiency
- Improved error handling and debugging

### Version 1.0.0
- Initial release
- Basic RSS feed aggregation
- OpenAI integration
- Admin interface
- Logging system
