# Sumai - AI Summarizer for WordPress 1
 
A WordPress plugin that automatically fetches articles from multiple RSS feeds, summarizes them using OpenAI's GPT models, and posts the summaries as WordPress articles.

**Version: 1.0.3** | Last Updated: 2025-04-10T16:16:35-04:00

## Development

This project uses the master branch as the primary development branch for simplicity and to maintain a clean, efficient workflow. All commits follow a minimalistic approach with clear, descriptive messages.

## Features

- Automatically fetch and summarize content from unlimited RSS feeds
- Use OpenAI's GPT-4o-mini model for high-quality summaries
- Schedule daily summary generation
- Manually generate summaries on demand
- Support for draft mode posting
- Comprehensive logging and status tracking
- Background processing using WordPress Action Scheduler
- Automatic retry with exponential backoff for API failures
- Secure API key management with encryption
- Feed testing and validation
- Custom prompts for summary and title generation with template management
- Post signature customization
- Optimized function loading with dependency management
- Robust fallback mechanisms for critical dependencies
- External debug logging for troubleshooting
- Function duplication prevention system

## Project Structure

This project follows a specific organization pattern:

- **PLANNING.md**: Contains the architectural design, component structure, and implementation details
- **TASKS.md**: Tracks completed, current, and upcoming tasks with dates and details
- **.windsurfrules**: Defines the rules and guidelines for AI assistance with this project
- **Code Organization**: Follows WordPress plugin standards with modular components

## Development Guidelines

When contributing to this project, please follow these guidelines:

1. **Read First**: Always start by reading PLANNING.md and TASKS.md
2. **Commit Frequently**: Make small, focused commits after each meaningful change
3. **Testing**: Create unit tests for all new functionality in the /test directory
4. **Documentation**: Update documentation when adding or changing features
5. **Code Style**: Follow WordPress coding standards throughout
6. **File Size**: Keep files under 500 lines; refactor if approaching this limit
7. **Task Tracking**: Update TASKS.md when completing tasks or discovering new ones
8. **Human Testing**: After each 3-commit cycle, the plugin must be tested by a human in a WordPress environment before development can continue

## Session Documentation Template

### Session Log Template (`Session.md`)

Use this template to manually create session logs documenting progress and decisions.

```markdown
# Session Log - [Date]
## Decisions Made
- [List key decisions]
## Tasks Completed
- [List task IDs/descriptions completed in this session]
## Issues Encountered
- [List any problems or blockers]
## Next Steps
- [List planned next steps for the following session]
```

### Session Start: [TIMESTAMP]

#### Context Summary
- Previous Session: [LINK TO PREVIOUS SESSION]
- Current Task: [TASK_ID]
- Relevant Files: [FILE_LIST]

#### Decisions Made
- [Decision 1]
- [Decision 2]
- [Decision 3]

#### Code Changes
- [Change 1]
- [Change 2]
- [Change 3]

#### Testing Status
- [Test 1]: [PASS/FAIL]
- [Test 2]: [PASS/FAIL]
- [Test 3]: [PASS/FAIL]

#### Next Steps
- [Step 1]
- [Step 2]
- [Step 3]

---

## Prerequisites

- WordPress 5.6+
- PHP 7.4+
- [Optional] WordPress Action Scheduler plugin (if not already installed by another plugin)
- OpenAI API key

## Installation

1. Download the Sumai plugin zip file or clone the repository
2. Upload to your WordPress plugins directory (`/wp-content/plugins/`) or install via the WordPress plugin installer
3. Activate the plugin through the WordPress 'Plugins' menu
4. Navigate to Settings > Sumai to configure your RSS feeds and OpenAI API key

## Configuration

### API Key Setup

There are two ways to configure your OpenAI API key:

1. **Via the Settings Page**: Enter your API key in the Sumai settings page
2. **Via wp-config.php** (recommended for security): Add the following line to your wp-config.php file:
   ```php
   define('SUMAI_OPENAI_API_KEY', 'your-api-key-here');
   ```

### Feed Configuration

Enter each RSS feed URL on a new line in the settings page. The plugin will:
- Fetch up to 3 unused articles per feed during each run
- Track which articles have been processed to avoid duplicates
- Allow testing of feeds directly from the admin interface

### Customizing Summary Generation

You can customize:
- The context prompt used for article summarization
- The title generation prompt
- A signature/attribution to be added to each post
- Whether posts are published immediately or saved as drafts

## Usage

### Automated Summaries

The plugin automatically generates summaries based on your configured schedule. By default, this occurs daily at 3:00 AM in your WordPress timezone. You can change this time in the settings.

### Manual Generation

To manually generate summaries:
1. Navigate to Settings > Sumai
2. Click the "Manual Generation" tab
3. (Optional) Uncheck "Respect already processed articles" if you want to process all articles
4. Click "Generate Now"
5. Progress will be displayed in real-time

Note: By default, the "Generate Now" button will only process articles that haven't been processed before. You can uncheck the "Respect already processed articles" option to process all articles.

### Testing Feeds

To test if your feeds are working correctly:
1. Navigate to Settings > Sumai
2. Click the "Test Feeds" tab
3. Click "Test"
4. View the results for each feed

## Background Processing

Sumai uses WordPress Action Scheduler to process OpenAI API requests in the background, which:
- Prevents timeouts during API calls
- Makes the system more resilient to API delays
- Improves WordPress admin performance
- Allows tracking of processing status

### Error Handling and Retries

As of version 1.0.1, Sumai includes an automatic retry system for API failures:
- Automatically retries failed API calls up to 3 times
- Uses exponential backoff (5, 10, 15 minutes) to avoid overwhelming the API
- Provides clear status updates during the retry process
- Properly logs all retry attempts and outcomes

### Fallback Mechanisms

The plugin includes robust fallback mechanisms for critical dependencies:
- Fallback for OpenAI API failures that provides a simplified summary
- Fallback for Action Scheduler unavailability using WordPress native scheduling
- Fallback logging when the primary logging system is unavailable
- Fallback database operations for tracking processed articles
- Function existence checking to prevent duplicate function declarations

## Logs and Debugging

The plugin provides comprehensive logging:
1. Navigate to Settings > Sumai
2. Click the "Logs" tab
3. View system information and recent log entries

Logs are stored in your WordPress uploads directory at `/wp-content/uploads/sumai-logs/`.

## Advanced Usage

### External Cron Triggering

You can trigger the summary generation using an external cron service by creating a URL with:
```
https://your-site.com/?sumai_cron=1&token=your-token
```

The token can be found in your WordPress options table under `sumai_cron_token`.

### Custom Hooks

The plugin provides several action and filter hooks for developers to extend functionality:
- `sumai_before_generate_summary`
- `sumai_after_generate_summary`
- `sumai_before_api_request`
- `sumai_after_api_request`
- `sumai_modify_post_content`

## Troubleshooting

### Common Issues

1. **API Key Issues**: Verify your API key is valid and has appropriate permissions
2. **Timeout Errors**: If summaries fail to generate, check your server's max execution time
3. **Feed Problems**: Use the feed testing feature to verify feeds are accessible
4. **Missing Articles**: Check the logs to see if articles were already processed
5. **Function Redeclaration Errors**: If you see errors about function redeclaration, check for plugin conflicts

For more help, check the Logs tab in the plugin settings.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- Created by: gevans3000
- Utilizes OpenAI API for summarization