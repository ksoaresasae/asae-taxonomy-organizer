# Instructions

This is a WordPress plugin. The plugin code lives in the root of this repository.

## Core Information

* Name: ASAE Taxonomy Organizer
* Slug: asae-taxonomy-organizer
* Version: 1.3.7
* Author/developer: Keith M. Soares (ksoares@asaecenter.org)
* Company: ASAE
* License: GPL v2 or later
* Text Domain: asae-taxonomy-organizer
* Description: Use AI (OpenAI) or intelligent keyword matching to automatically analyze WordPress content and categorize it with appropriate taxonomy terms.

## Purpose

ASAE Taxonomy Organizer helps content managers automatically categorize WordPress content using OpenAI-powered analysis or intelligent keyword matching as a fallback. The plugin analyzes posts, pages, or custom post types and suggests the most appropriate category from existing taxonomies, with a preview-and-approve workflow.

## Admin Menu Structure

The plugin follows the standard ASAE submenu pattern. It registers at priority 20 under the shared "ASAE" top-level menu (parent slug `'asae'`) created by ASAE Explore. If Explore is not active, a fallback top-level ASAE menu is created (`dashicons-building`, position 30). The OpenAI Settings page is registered as a hidden page (null parent) accessible via direct link from the main Organizer page.

## Admin Pages

### 1. Organizer (Main Page)
- **Slug:** `asae-taxonomy-organizer`
- **Capability:** `manage_options`
- **Class:** `ASAE_TO_Admin` ([class-admin.php](includes/class-admin.php))
- **Layout:** Two-column (main content + sidebar)
- **Main area contains:**
  - Status banner showing current mode (AI or Keyword Matching)
  - Configuration form with: post type selector, taxonomy selector (AJAX-loaded), date range filter, "ignore previously categorized" toggle, "exclude by existing taxonomy" dropdown, AI toggle, preview mode toggle, confidence threshold slider (0-100%), items count selector (10/25/50/100/All)
  - Results card with approve/reject workflow
- **Inline progress panel** appears after form during batch processing with real-time AJAX polling
- **Resume banner** detects interrupted batches on page load
- **Sidebar contains:**
  - Confidence Levels legend

### 2. OpenAI Settings (Hidden Page)
- **Slug:** `asae-taxonomy-organizer-settings`
- **Parent:** `null` (hidden from menu, accessible via direct URL)
- **Capability:** `manage_options`
- **Class:** `ASAE_TO_Settings` ([class-settings.php](includes/class-settings.php))
- **Contains:**
  - API Configuration card: API key input (password field with show/hide toggle), model selector dropdown, Test Connection button, Save Settings button
  - Analysis Method card: "Use OpenAI for Analysis" toggle with status indicator
  - Cost Controls card: monthly API call limit, delay between API calls (ms), retry delay when blocked (minutes), usage this month display with progress bar, reset counter button
  - Sidebar with benefits info, security note, and model recommendations

## Architecture

### Main Plugin File
- [asae-taxonomy-organizer.php](asae-taxonomy-organizer.php) - Singleton pattern (`ASAE_Taxonomy_Organizer`), bootstraps all dependencies and hooks

### PHP Classes (in `includes/`)
| Class | File | Purpose |
|---|---|---|
| `ASAE_TO_Admin` | [class-admin.php](includes/class-admin.php) | Renders the main Organizer admin page |
| `ASAE_TO_Settings` | [class-settings.php](includes/class-settings.php) | Renders the OpenAI Settings page, defines available models |
| `ASAE_TO_Processor` | [class-processor.php](includes/class-processor.php) | Core content processing: fetches posts, runs analysis, handles preview vs direct save, manages batch initiation |
| `ASAE_TO_Batch_Manager` | [class-batch-manager.php](includes/class-batch-manager.php) | Creates/cancels/pauses/tracks batch jobs, schedules WP-Cron events, stall detection and requeue |
| `ASAE_TO_AI_Analyzer` | [class-ai-analyzer.php](includes/class-ai-analyzer.php) | Content analysis engine: OpenAI API calls, keyword matching fallback, budget/usage tracking, cost estimation |
| `ASAE_TO_Feedback_Logger` | [class-feedback-logger.php](includes/class-feedback-logger.php) | Logs rejection feedback to custom DB table |

### Frontend Assets (in `admin/`)
| File | Purpose |
|---|---|
| [admin/js/admin.js](admin/js/admin.js) | All client-side logic: form handling, AJAX calls, approval workflow, rejection modal, batch polling, settings page |
| [admin/css/admin.css](admin/css/admin.css) | All admin styling: layout, cards, toggles, sliders, results list, confidence badges, modals, responsive breakpoints |

### Constants
| Constant | Value |
|---|---|
| `ASAE_TO_VERSION` | `'1.3.7'` |
| `ASAE_TO_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` |
| `ASAE_TO_PLUGIN_URL` | `plugin_dir_url(__FILE__)` |
| `ASAE_TO_PLUGIN_BASENAME` | `plugin_basename(__FILE__)` |

## AJAX Endpoints

All endpoints require `manage_options` capability and verify the `asae_to_nonce` nonce.

| Action | Method in Main Class | Purpose |
|---|---|---|
| `asae_to_get_taxonomies` | `ajax_get_taxonomies()` | Returns taxonomies for a given post type |
| `asae_to_get_terms` | `ajax_get_terms()` | Returns all terms in a taxonomy |
| `asae_to_process_content` | `ajax_process_content()` | Main entry point: analyzes content, returns results or starts batch |
| `asae_to_save_items` | `ajax_save_items()` | Saves approved taxonomy assignments |
| `asae_to_cancel_batch` | `ajax_cancel_batch()` | Cancels a specific batch job |
| `asae_to_get_batch_status` | `ajax_get_batch_status()` | Returns list of active batch jobs |
| `asae_to_log_rejection` | `ajax_log_rejection()` | Logs rejection feedback with optional correct category and notes |
| `asae_to_save_settings` | `ajax_save_settings()` | Saves API key, model, AI toggle, monthly limit, and delay |
| `asae_to_test_connection` | `ajax_test_connection()` | Tests OpenAI API connectivity with a minimal request |
| `asae_to_process_preview_chunk` | `ajax_process_preview_chunk()` | Processes a chunk of items for preview (offset-based pagination) |
| `asae_to_get_cost_estimate` | `ajax_get_cost_estimate()` | Returns estimated cost and item count before processing |
| `asae_to_reset_usage` | `ajax_reset_usage()` | Resets the monthly API call counter |
| `asae_to_get_batch_progress` | `ajax_get_batch_progress()` | Returns progress for a specific batch (status, processed, total, pause info) |
| `asae_to_cancel_all_batches` | `ajax_cancel_all_batches()` | Cancels all active batches |
| `asae_to_heartbeat` | `ajax_heartbeat()` | Lightweight keep-alive ping during processing |

## Database

### Custom Tables (created on activation)

**`{prefix}_asae_to_batches`** - Tracks background batch processing jobs
| Column | Type | Notes |
|---|---|---|
| `id` | bigint(20) PK | Auto-increment |
| `batch_id` | varchar(50) UNIQUE | Format: `batch_{uniqid()}` |
| `post_type` | varchar(100) | |
| `taxonomy` | varchar(100) | |
| `total_items` | int(11) | Default 0 |
| `processed_items` | int(11) | Default 0 |
| `api_calls_made` | int(11) | Default 0 |
| `status` | varchar(20) | `pending`, `processing`, `paused`, `completed`, `cancelled` |
| `ignore_categorized` | tinyint(1) | Default 1 |
| `date_from` | date | Nullable |
| `date_to` | date | Nullable |
| `exclude_taxonomy` | varchar(100) | Nullable |
| `confidence_threshold` | int(11) | Default 0 |
| `next_retry_at` | datetime | Nullable, set when batch is paused |
| `pause_reason` | varchar(50) | Nullable (`rate_limited`, `budget_exceeded`, `error`) |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**`{prefix}_asae_to_feedback`** - Stores rejection feedback
| Column | Type | Notes |
|---|---|---|
| `id` | bigint(20) PK | Auto-increment |
| `post_id` | bigint(20) | Indexed |
| `suggested_term_id` | bigint(20) | The AI/keyword suggestion |
| `selected_term_id` | bigint(20) | User's correction (nullable) |
| `notes` | text | User explanation |
| `taxonomy` | varchar(100) | Indexed |
| `user_id` | bigint(20) | |
| `created_at` | datetime | |

### wp_options Keys
| Option Key | Default | Purpose |
|---|---|---|
| `asae_to_openai_api_key` | `''` | OpenAI API key (stored in plain text) |
| `asae_to_openai_model` | `'gpt-4o-mini'` | Selected OpenAI model |
| `asae_to_use_ai` | `'no'` | Whether AI analysis is enabled (`'yes'`/`'no'`) |
| `asae_to_monthly_api_call_limit` | `0` | Monthly API call limit (0 = unlimited) |
| `asae_to_api_call_delay_ms` | `200` | Delay in milliseconds between API calls |
| `asae_to_api_calls_count` | `0` | Current month's API call count |
| `asae_to_api_calls_reset_month` | `''` | Month string (YYYY-MM) for usage auto-reset |
| `asae_to_api_retry_delay_minutes` | `60` | Minutes to wait before retrying API after refusal |

## Processing Logic

### Analysis Modes

**OpenAI API Mode** (when enabled and configured):
- Checks monthly budget before each call; falls back to keyword matching if budget exceeded
- Applies configurable delay (`asae_to_api_call_delay_ms`, default 200ms) before each call
- Sends post title + body (truncated to 3000 chars in prompt, 5000 chars total prepared) to OpenAI Chat Completions API
- System prompt instructs model to return JSON: `{"term_id": <number>, "confidence": <0-100>, "tags": ["tag1", "tag2", "tag3"]}`
- Up to 3 keyword tags suggested per item; fuzzy matched against existing `post_tag` terms before creating new ones
- Uses `temperature: 0.3` for consistency, `max_tokens: 100`
- Validates returned `term_id` exists in the available terms list
- Tracks each API call via `track_api_call()` (increments monthly counter)
- On HTTP 429 (rate limited): returns null with `last_status='rate_limited'` — signals caller to pause and retry
- On other API failure: returns null with `last_status='error'` — signals caller to pause and retry
- When AI is enabled, keyword matching is NEVER used as a fallback

**Keyword Matching Mode** (fallback):
- Combines title + content + excerpt into one lowercase string
- Scores each term by counting keyword occurrences:
  - Term name words: 3 points per match (words > 2 chars)
  - Term slug words: 2 points per match (words > 2 chars)
  - Term description words: 1 point per match (words > 3 chars)
- Confidence based on: dominance ratio (top score / total scores) and keyword density
- Confidence clamped to 15-85% range
- If no keywords match, returns first term with 10% confidence

### Available OpenAI Models
- `gpt-4o` - "GPT-4o (Most Capable)"
- `gpt-4o-mini` - "GPT-4o Mini (Recommended)" (default)
- `gpt-4-turbo` - "GPT-4 Turbo"
- `gpt-4` - "GPT-4"
- `gpt-3.5-turbo` - "GPT-3.5 Turbo (Fastest/Cheapest)"

### Processing Modes

**Preview Mode** (default, max 100 items):
- Uses chunked AJAX processing (10 items per chunk) with progress bar
- When AI is enabled, shows cost estimate confirmation before starting
- API refusal detection: stops cleanly with a message (no keyword fallback)
- Returns results for user review without saving anything
- User can approve individually, approve selected, approve all, or reject
- Rejection opens a modal to optionally select correct category and provide notes

**Direct Save Mode** (preview off, specific count):
- Auto-saves items whose confidence meets or exceeds the threshold
- Items below threshold are flagged for review

**Batch Mode** (triggered when "All Items" selected):
- Preview mode is automatically disabled
- Creates a batch record in `asae_to_batches` table (includes `api_calls_made` tracking)
- Pre-schedules next chunk BEFORE processing current chunk (crash resilience)
- Uses transient-based locking (`asae_to_lock_{batch_id}`, 5-min expiry) to prevent overlap
- Processes 20 items per chunk with per-item progress saves
- Try-catch per item: single item failures don't abort the chunk
- API refusal handling (429, budget exceeded, error): saves progress, sets batch to `paused` status, reschedules with configurable retry delay (default 60 minutes)
- Stall detection: batches stuck in 'processing' >5 minutes are auto-requeued
- Auto-saves items meeting confidence threshold
- Batch is cancellable at any time; already-processed items retain their assignments

### Confidence Levels
- **High (75%+)**: Strong match
- **Medium (50-74%)**: Reasonable match, review recommended
- **Low (Below 50%)**: Weak match, manual review strongly recommended

## Activation / Deactivation

- **Activation:** Creates both custom database tables via `dbDelta()`, flushes rewrite rules
- **Deactivation:** Clears the `asae_to_process_batch` scheduled hook

## Security

- All AJAX requests verify nonce (`asae_to_nonce`) and `manage_options` capability
- All user inputs are sanitized (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_key`, `intval`)
- Database queries use `$wpdb->prepare()` for parameterized queries
- Output is escaped with `esc_attr()`, `esc_html()`
- JavaScript uses `escapeHtml()` helper for all user-generated content in DOM
- OpenAI API key is stored in plain text in `wp_options` (noted as a security consideration)
- Batch IDs are validated against format `/^batch_[a-f0-9]+$/` before processing

## JavaScript Localization

Script handle: `asae-to-admin`. Localized object `asaeToAdmin`:
- `ajaxUrl` - WordPress admin-ajax.php URL
- `nonce` - Security nonce
- `version` - Plugin version
- `useAI` - Current AI enabled state
- `runningBatchId` - Batch ID of any active batch (for resume detection)
- `runningBatchProcessed` - Processed items count of running batch
- `runningBatchTotal` - Total items count of running batch

## WP-Cron

- Hook: `asae_to_process_batch`
- Registered in [class-batch-manager.php](includes/class-batch-manager.php) via anonymous function
- Validates batch_id format (`/^batch_[a-f0-9]+$/`) before processing
- Fires as single events (not recurring), pre-scheduled before each chunk with 30-second delay
- Cancelled if batch completes or is explicitly cancelled
- API-refused batches pause and reschedule with configurable retry delay (default 60 minutes)

## Responsive Design

- Main layout switches from side-by-side to stacked below 1200px
- Result items and date range inputs stack vertically below 600px
