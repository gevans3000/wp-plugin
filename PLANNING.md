# Sumai Plugin - Planning

Last Updated: 2025-04-10T16:00:00-04:00

## Project Overview
Sumai is a WordPress plugin designed to automatically generate AI summaries of articles from RSS feeds. It uses OpenAI's GPT models to create concise, high-quality summaries that can be published as WordPress posts. The plugin focuses on efficiency, minimalism, and clarity while providing robust error handling and logging capabilities.

## Overview
WP plugin for RSS feed summarization via OpenAI GPT. Features auto/manual generation with minimal code and compute usage.

**Version: 1.0.3** | Updated: 2025-04-10T16:00:00-04:00

## AI Assistant Guidelines
Start here → TASKS.md → .windsurfrules for development

## Workflow
- Read this file → TASKS.md → execute 3-commit cycle
- After 3 commits: only TASKS.md modified (not committed)
- Code must be minimal, efficient, and maintainable
- Human must test each version via zipped plugin in WordPress
- Development cannot continue until human provides test results

## Testing Requirements
- After each feature implementation, create a zip package
- Human must install and test in WordPress environment
- Human must provide feedback on functionality and performance
- Next development cycle cannot start without test results
- Debug logs at "C:\Users\lovel\Local Sites\biglife360\app\public\sumai-debug.log" must be checked

## Architecture
- WP Plugin API + OpenAI GPT-4o-mini
- Action Scheduler for background jobs
- WP Options API for storage
- OpenSSL encryption for API keys
- Phased loading with dependency checks
- Caching for optimized performance

## Components
1. **Core**: Constants, activation hooks, feed/content processing, security, caching
2. **Admin**: Settings UI, manual generation, feed testing, status monitoring
3. **API**: OpenAI client, request/response handling, error management
4. **Background**: Action Scheduler integration, async processing

## File Structure
```
sumai/
├── sumai.php                # Main plugin file
├── includes/               
│   ├── admin/              # Admin UI components
│   ├── api/                # API integrations
│   └── core/               # Core functionality
├── assets/                 # CSS, JS, images
└── templates/              # Template files
```

## Security
- API keys encrypted with OpenSSL
- Input sanitization and output escaping
- Capability checks for admin functions
- Nonce verification for forms

## Performance
- Caching for settings, API responses, feed data
- Optimized database queries
- Batch processing for feeds

## Debug & Logging
- WordPress standard logging in uploads directory
- External debug logs at "C:\Users\lovel\Local Sites\biglife360\app\public\sumai-debug.log"
- Comprehensive error handling with admin notifications
- Error suppression for non-critical issues