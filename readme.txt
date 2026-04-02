=== ASAE Taxonomy Organizer ===
Contributors: keithmsoares
Tags: taxonomy, categories, ai, automation, content organization
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use AI (OpenAI) or intelligent keyword matching to automatically analyze WordPress content and categorize it with appropriate taxonomy terms.

== Installation ==

1. Upload the `asae-taxonomy-organizer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'ASAE > Taxonomy Organizer' in the admin menu
4. (Optional) Configure OpenAI settings via the link on the Organizer page

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =

No, the plugin works without an API key using keyword matching. However, AI-powered analysis provides significantly more accurate results. You can get an API key from [platform.openai.com](https://platform.openai.com/api-keys).

= Is my API key secure? =

Your API key is stored in the WordPress database. For production environments with high security requirements, we recommend using environment variables. The key is never exposed in page source and is only transmitted to OpenAI's API.

= What's the difference between Preview Mode and direct processing? =

Preview Mode shows you all suggestions before any changes are saved, allowing you to approve or reject each one. Direct processing saves categorizations automatically based on your confidence threshold setting.

= Can I process large numbers of posts? =

Yes! Select "All Items" to use batch processing, which runs in the background using WordPress's scheduling system. You can monitor progress and cancel anytime.

== Changelog ==

= 1.0.2 =
* Added "Ignored Tags" setting on Settings tab (comma-separated list, replaces 80% threshold)
* Defaults to: article, AssociationsNow, ASAEcenter, podcast, video
* Clicking "Other" in tag chart opens a full list of all tags with counts and percentages
* Report caches cleared when settings are saved

= 1.0.1 =
* Tag drill-down title now shows post count: 'Tags in "Category" (1,200 posts)'
* Fixed tag percentages: now based on posts in category, not total tag assignments
* Structural tags filtered out: tags appearing on >80% of posts in a category are omitted
* Tighter table spacing so the data table fits above the fold
* Report caches cleared on plugin upgrade

= 1.0.0 =
* New Reports tab — visual donut chart showing content breakdown by category
* Click any category to drill down into top 20 tags
* Dashboard widget with the same chart and drill-down functionality
* Post type selector to view reports for any content type
* Accessible data table alongside every chart with keyboard-navigable drill-down
* Colorblind-safe palette (Wong 2011)
* AJAX-loaded data with 1-hour transient caching and auto-invalidation
* Reports is now the default tab (before Organizer and Settings)

= 0.7.2 =
* HOTFIX: Removed watchdog from admin_init — was blocking every page load with synchronous API calls

= 0.7.1 =
* Fix: stall detector was rescheduling cron 10s out on every page load, preventing direct execution from ever triggering
* Stall detector now runs chunks directly instead of rescheduling
* Simplified polling direct-execution condition: runs when idle > 60s and unlocked (no longer requires overdue cron)

= 0.7.0 =
* Fixed: WP-Cron loopback broken on many hosts, causing batches to stall indefinitely
* Polling endpoint now runs batch chunks directly when cron events are overdue
* Watchdog also runs chunks directly instead of re-scheduling (bypasses broken cron)
* Handles: overdue paused batches, overdue cron events, orphaned batches with no cron

= 0.6.1 =
* Added real-time diagnostics to batch progress panel: API calls, last activity time, cron status, lock state
* Status line now shows idle time and reason when batch hasn't progressed (locked, waiting for cron, no cron scheduled)
* Diagnostics shown as compact monospace footer below progress bar

= 0.6.0 =
* Fixed batch processing stalling overnight: WP-Cron only fires on page visits, so paused batches with expired retry times were never requeued
* Added recurring watchdog cron (every 5 minutes) that detects overdue paused batches and reschedules them
* Admin page load now runs the watchdog immediately, so visiting the plugin page requeues any stuck batch
* Heartbeat AJAX now calls spawn_cron() to keep cron alive while the tab is open
* Also catches orphaned pending batches with no cron event

= 0.5.2 =
* Improved batch pause/stall UX: progress panel turns amber with clear explanation when paused
* Resume banner now shows pause reason, retry time, and guidance to close the page safely
* Added Page Visibility API listener to restart polling when browser tab wakes from sleep
* Batch status, pause reason, and next retry time now passed to JS on page load

= 0.5.1 =
* Refined per-call cost estimates to account for full prompt size (~900 input + ~50 output tokens)

= 0.5.0 =
* Updated OpenAI model list: added GPT-4.1 family (nano/mini/full), GPT-5.4 family (nano/mini/full)
* Removed retired models: GPT-4, GPT-4 Turbo, GPT-3.5 Turbo
* Default model changed from GPT-4o Mini to GPT-4.1 Mini (better value)
* Updated per-call cost estimates for all models based on current pricing

= 0.4.1 =
* Added "Check for Updates Now" button on Settings tab to bypass 6-hour cache
* Clears GitHub release transient and forces WordPress update check on demand

= 0.4.0 =
* Self-hosted auto-updater: WordPress now checks GitHub Releases for new versions
* One-click update from the Plugins list when a new release is published
* GitHub Actions workflow automatically builds and attaches the zip to each release
* Just push a version tag (e.g. v0.4.0) to trigger a release

= 0.3.1 =
* Added tab navigation (Organizer | Settings) matching Content Ingestor pattern
* Settings (API config, model, cost controls) now accessible via Settings tab instead of hidden page
* Removed separate hidden settings submenu page
* All settings links updated to use tab-based URLs

= 0.3.0 =
* AI now assigns up to 3 keyword tags per post in addition to a category
* Fuzzy tag matching: reuses existing similar tags (exact, slug, and similarity matching) before creating new ones
* Added heartbeat keep-alive during processing to prevent browser tab sleep
* Tags are displayed as pills in preview results and carried through the approval workflow
* Batch processing, direct save, and preview approval all support tag assignment
* Keyword matching mode returns no tags (AI-only feature)

= 0.2.4 =
* Added "Cancel All Jobs" button to resume banner for clearing stale batches before starting a new run
* Added cancel_all_batches AJAX endpoint

= 0.2.3 =
* Fixed batch processing hanging: AJAX polling now triggers WP-Cron spawn so batches progress without requiring separate page loads

= 0.2.2 =
* Fixed batch creation failing silently when DB schema was outdated
* Added automatic DB schema upgrades on plugin version change (no reactivation required)
* Added error handling for batch INSERT failures with actionable error messages
* Improved polling error messages to show actual server error text

= 0.2.1 =
* Version badge now displays next to the plugin title
* Categorization now replaces existing terms instead of appending (one category per post)
* Removed Active Batches and How It Works sidebar blocks for cleaner UI
* Added inline progress tracking panel near Analyze Content button
* Added resume banner for interrupted batch detection on page load
* Added AJAX polling for real-time batch progress updates
* Added retry_delay to settings save

= 0.2.0 =
* Keyword matching is now an explicit user choice, never an automatic fallback when AI is enabled
* Batch processing pauses and retries automatically when the API is unavailable (rate limit, budget, or error)
* Added configurable retry delay setting (1–1440 minutes, default 60)
* Added "paused" batch status with next retry time display
* Paused batches are excluded from stall detection
* Preview mode stops cleanly on API refusal instead of degrading to keywords
* New database columns: next_retry_at, pause_reason

= 0.1.0 =
* Added monthly API call budget with automatic fallback to keyword matching when exceeded
* Added pre-processing cost estimate confirmation dialog
* Added configurable delay between API calls (rate limiting)
* Added HTTP 429 backoff handling with automatic retry
* Added per-item progress saving for crash resilience
* Added stall detection: stuck batches auto-requeue after 5 minutes
* Added chunked AJAX preview processing with progress bar
* Added per-batch API call tracking
* Added usage display with progress bar on Settings page
* Added reset usage counter button

= 0.0.4 =
* Moved admin menu under shared "ASAE" top-level menu (standard ASAE submenu pattern)
* Added fallback top-level ASAE menu when ASAE Explore plugin is not active
* OpenAI Settings page is now a hidden page accessible via direct link
* Added build-zip.php script for WordPress-compatible release packaging
* Added .gitignore for repository cleanliness

= 0.0.3 =
* Added dedicated OpenAI Settings admin page
* Added API key field with show/hide toggle for security
* Added model selection dropdown (GPT-4o, GPT-4o Mini, GPT-4, GPT-3.5 Turbo)
* Added connection test button to verify API access
* Added AI/Keyword matching toggle in main Organizer interface
* Enhanced rejection workflow with category selection modal
* Added rejection notes field for AI training feedback
* Implemented feedback logging system for rejected suggestions
* Limited Preview Mode to maximum 100 items for performance
* Disabled Preview Mode when "All Items" is selected (batch mode only)

= 0.0.2 =
* Added date range picker for filtering content by publication date
* Added preview mode with individual approve/reject functionality
* Added confidence threshold slider for auto-saving high-confidence matches
* Added exclude by existing taxonomy option
* Added confidence score display for each suggestion (High/Medium/Low)
* Improved UI with better result display and batch actions

= 0.0.1 =
* Initial release
* Basic admin interface
* Batch processing with WP-Cron
* AI analyzer with OpenAI support
* Keyword matching fallback

== Upgrade Notice ==

= 0.5.0 =
Updated OpenAI models: added GPT-4.1 and GPT-5.4 families, removed retired models, revised cost estimates.

= 0.4.1 =
Added manual "Check for Updates Now" button to bypass the 6-hour GitHub API cache.

= 0.4.0 =
Self-hosted auto-updater checks GitHub Releases for new versions. One-click update from WP Plugins list.

= 0.3.1 =
Settings moved to tab navigation alongside Organizer, matching Content Ingestor UI pattern.

= 0.3.0 =
AI now assigns keyword tags alongside categories. Includes fuzzy tag matching and heartbeat keep-alive.

= 0.2.4 =
Adds Cancel All Jobs button for cleaning up stale batches before large runs.

= 0.2.3 =
Fixes batch processing stalling when no other page loads trigger WP-Cron.

= 0.2.2 =
Fixes batch creation failure caused by outdated DB schema. Schema now auto-upgrades on version change.

= 0.2.1 =
Cleaner UI with inline progress tracking and one-category-per-post enforcement.

= 0.2.0 =
Keyword matching is now explicit only. Batches pause and retry automatically when the AI API is unavailable.

= 0.1.0 =
Adds resilience features (crash recovery, stall detection) and cost controls (monthly budget, rate limiting, pre-processing estimate).

= 0.0.4 =
Admin menu now appears under the shared ASAE menu. If ASAE Explore is not active, a fallback ASAE menu is created.
