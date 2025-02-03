# Sumai - AI-Powered Daily Summary Generator

Sumai is a WordPress plugin that automatically fetches content from your configured RSS feeds and generates concise daily summaries using OpenAI's GPT technology. Perfect for content aggregators, news sites, or any WordPress site wanting to provide AI-generated summaries of multiple sources.

## 🚀 Features

- Automatic daily summary generation from RSS feeds
- AI-powered content summarization using OpenAI
- Customizable summary generation schedule
- Support for multiple RSS feeds
- Private API key management via `.env` file
- Debug logging and error tracking
- Easy-to-use admin interface

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- OpenAI API key
- Valid RSS feed URLs

## 💻 Installation

1. Download the plugin files
2. Upload the `sumai` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

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

## 🔄 Daily Operation

The plugin will:
1. Fetch new content from your configured RSS feeds
2. Generate a summary using OpenAI
3. Create a new post with the summary
4. Either publish immediately or save as draft (based on your settings)

You can also manually trigger a summary generation using the "Generate Summary Now" button in the admin interface.

## 🔍 Testing Your Setup

1. After configuration, use the "Test RSS Feeds" button to verify feed connectivity
2. Use the "Test API Key" button to verify your OpenAI API key (both admin and .env versions)
3. Check the debug information section for any potential issues

## 📝 Logging and Debugging

- Logs are stored in `/wp-content/uploads/sumai-logs.log`
- Logs are automatically pruned after 30 days
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

2. **API Key Not Found**
   - Verify `.env` file location and format
   - Check file permissions
   - Try the "Test Hidden API" button

3. **Empty Summaries**
   - Check RSS feeds are returning content
   - Verify API key has sufficient credits
   - Check error logs for details

### Getting Help

If you encounter issues:
1. Check the debug information in the admin interface
2. Review the log file at `/wp-content/uploads/sumai-logs.log`
3. Ensure all requirements are met
4. Verify all configuration settings

## 📈 Best Practices

1. Start in draft mode to review AI-generated content
2. Use specific context prompts for better summaries
3. Monitor your OpenAI API usage
4. Regularly check your logs for issues
5. Keep the plugin and WordPress updated

## 🛠️ Advanced Configuration

For developers and advanced users:
- The `.env` file can be placed in the WordPress root directory as a fallback
- Custom hooks and filters are available for extending functionality
- Debug mode can be enabled for detailed logging

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 🔄 Changelog

### Version 1.0.0
- Initial release
- Basic RSS feed aggregation
- OpenAI integration
- Admin interface
- Logging system
