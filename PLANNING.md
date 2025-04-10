# Sumai Plugin - Planning

## Overview
WP plugin for RSS feed summarization via OpenAI GPT. Features auto/manual generation with minimal code and compute usage.

**Version: 1.0.2** | Updated: 2025-04-10T12:59:35-04:00

## AI Assistant Guidelines
Start here → TASKS.md → .windsurfrules for development

## Workflow
- Read this file → TASKS.md → execute 3-commit cycle
- After 3 commits: only TASKS.md modified (not committed)
- Code must be minimal, efficient, and maintainable

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
- Transient and object cache integration