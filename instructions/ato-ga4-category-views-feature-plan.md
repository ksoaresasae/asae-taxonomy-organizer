# Feature Plan: GA4 Category Views Report

## Context

This is a new feature for the **ASAE Taxonomy Organizer** WordPress plugin. It adds a GA4-powered "Views by Category" report to the existing **Reports tab**. It must use the same visual style, chart library, UI patterns, accessibility rules, and layout conventions already established in the plugin's existing reports. Do not introduce new dependencies or styling approaches — match what's already there.

## What This Feature Does

Displays a chart showing real user pageviews from Google Analytics 4, broken down by the site's 14 content categories, over a selectable time window. The data source is the GA4 Data API. The taxonomy source is WordPress core category assignments on posts.

## Data Pipeline

### Step 1: GA4 Property ID

Read the GA4 property ID from the plugin's own settings (`ato_ga4_property_id`). This value is configured on the Settings tab in the "Google Analytics 4 Configuration" block — it may have been auto-detected from Site Kit or entered manually. If the property ID is not set, show a clear admin notice on the Reports tab linking to the settings block.

### Step 2: GA4 Authentication

Use a Google service account for GA4 Data API access. Read the service account JSON credentials from the plugin's own settings (`ato_ga4_service_account_json`), configured on the Settings tab. Use the `google/apiclient` PHP library. Add it via Composer if not already present in the plugin.

### Step 3: GA4 Data Fetch

Make a single `runReport` call to the GA4 Data API v1beta:

- **Metric:** `screenPageViews`
- **Dimension:** `pagePath`
- **Date ranges:** determined by the selected time window
- **Time windows to support:** Last 3 Months, Last 12 Months, All Time (as needed, convert these windows to days or other time blocks to support necessary queries and calls)
- **Row limit:** 10,000 (this covers the realistic working set; most legacy content won't appear in shorter windows)
- **No pagination needed** unless row count exceeds limit — handle gracefully if it does

### Step 4: Path-to-Post-ID Resolution

Do NOT use `url_to_postid()` in a loop. Instead:

1. Extract the slug from each `pagePath` — take the last non-empty segment after trimming trailing slashes.
2. Run a single bulk SQL query against `wp_posts`:

```sql
SELECT ID, post_name FROM wp_posts
WHERE post_name IN ('slug1', 'slug2', ...)
AND post_status = 'publish'
AND post_type = 'post'
```

3. Build a `slug => post_id` lookup array from the result.
4. Discard any GA4 paths that don't resolve (non-post pages, archives, 404s, etc.). It's important to only work with Posts, not Pages or other content types, for this version.

### Step 5: Category Aggregation

Run a single SQL query joining resolved post IDs against the taxonomy tables (example):

```sql
SELECT t.name AS category_name, tr.object_id AS post_id
FROM wp_term_relationships tr
JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
JOIN wp_terms t ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'category'
AND tr.object_id IN (post_id1, post_id2, ...)
```

Then in PHP, loop through results and sum views per category using the post-level view counts from Step 3. Posts with multiple categories should have their views counted toward each assigned category (this is expected and correct — note it in the UI tooltip or footnote).

As needed, reuse or employ all the keep-alive/continuous operation/resource friendly operations you've done elsewhere in the plugin.

### Step 6: Caching

Store the aggregated result as a WordPress transient, keyed per time window:

- `ato_ga4_views_30d`, `ato_ga4_views_90d`, `ato_ga4_views_1y`, `ato_ga4_views_3y`, `ato_ga4_views_5y`
- **TTL:** 24 hours for 3mo windows, 7 days for 12mo/AllTime windows
- Store as JSON: `{ "category_name": total_views, ... }` sorted descending by views
- Include a `generated_at` timestamp in the cached object

### Step 7: REST Endpoint

Register a REST route under the plugin's existing namespace:

- **Route:** `ato/v1/reports/category-views`
- **Method:** GET
- **Parameters:** `window` (required, one of: `3mo`, `12mo`, `All`)
- **Permission:** `manage_options` (admin only)
- **Response:** the cached JSON, or a fresh fetch if cache is empty
- **Error responses:** proper WP_Error objects for missing Site Kit config, missing service account, GA4 API failures

### Step 8: Scheduled Refresh

Register a daily WP-Cron event that refreshes the 3mo caches proactively. The 12mo and AllTime caches refresh on-demand when requested and expired.

## UI / Front-End

### Placement

- Add this report to the **Reports tab**, below the existing reports
- Use a section heading: **"Pageviews by Category"**
- Use the same heading style, card/container style, and spacing as existing report sections

### Chart

- Use the **same chart library and configuration patterns** already used in the plugin's other charts
- Chart type: **horizontal bar chart** (14 categories fits well vertically; avoids label truncation)
- One bar per category, sorted by view count descending
- Bar color: use the plugin's existing chart color palette. If only one color is used for single-series charts elsewhere, match that. If a multi-color palette exists, use it.
- Show view count as a label on or beside each bar
- Format numbers with comma separators (e.g., 12,345)

### Time Window Selector

- Place a dropdown/select above the chart: "[3 Months | 12 Months | All Time]"
- Default to **3 Months**
- On change, fetch from the REST endpoint with the selected window and re-render the chart
- Show a loading indicator while fetching (match existing loading patterns in the plugin)

### Footer Note

Below the chart, include a small footnote:
> "Posts assigned to multiple categories, if any, are counted in each. Data refreshed daily from Google Analytics 4. Last updated: [generated_at timestamp]."

### Empty / Error States

- If GA4 credentials are not configured: show an info notice linking to the "Google Analytics 4 Configuration" block on the Settings tab
- If GA4 returns no data for the window: show "No pageview data available for this time period."
- If the API call fails: show a generic error with a "Retry" button

## Settings

Add a new **independent settings block** on the plugin's existing **Settings tab**, titled **"Google Analytics 4 Configuration"**. This block must be self-contained — it registers, renders, validates, and saves its own fields without modifying or interfering with any other settings blocks on the page. Use the same card/section styling and heading conventions as the other settings blocks already on this tab.

### Fields in this block

1. **GA4 Property ID**
   - **Type:** text input
   - **Description:** "The GA4 property ID (e.g., properties/123456789). Auto-detected from Site Kit if available."
   - **Behavior:** On page load, attempt to read the property ID from Site Kit's stored options (`googlesitekit_analytics-4`, key `propertyID`). If found, pre-populate the field and show a note: "Auto-detected from Google Site Kit." The user can override this value manually.
   - **Validation:** must be non-empty and match the expected format
   - **Storage:** save as its own option, e.g. `ato_ga4_property_id`

2. **Service Account JSON Key**
   - **Type:** textarea (monospace font, ~8 rows)
   - **Description:** "Paste the full contents of your Google service account JSON key file. The service account must have Viewer access to the GA4 property above."
   - **Behavior:** the user pastes the raw JSON content directly into the field — no file upload, no file path
   - **Validation on save:**
     - Must be valid JSON
     - Must contain the required keys: `type`, `project_id`, `private_key`, `client_email`
     - If validation fails, show a specific inline error describing what's wrong
   - **Storage:** save as an encrypted/obfuscated option (at minimum, do not store as plain text visible in the options table). Use `ato_ga4_service_account_json` as the option key.
   - **Display:** once saved successfully, do not re-display the full JSON in the textarea. Instead show a confirmation message like "Service account configured: [client_email from the JSON]" with a "Replace" button that clears the field for re-entry.

3. **Connection Test Button**
   - **Label:** "Test Connection"
   - **Behavior:** AJAX call that attempts a minimal GA4 Data API request (e.g., fetch 1 day of `screenPageViews`) using the stored property ID and service account credentials
   - **Success:** show inline success message with a green indicator: "Connected successfully to GA4 property [property ID]."
   - **Failure:** show inline error with the specific API error message returned

### Block behavior

- This settings block saves independently via its own nonce and submit button (or via AJAX) — it does not require saving the entire settings page
- When credentials are not yet configured, the Reports tab chart should display a notice linking directly to this settings block (anchor link to the block's ID on the Settings tab)

## Dependencies

- `google/apiclient` PHP package (Composer)
- Google Site Kit plugin (optional — used to auto-detect the GA4 property ID, but the user can enter it manually on the Settings tab)

## Files to Create or Modify

This depends on the plugin's existing file structure. Follow the established conventions. Likely:

- A new PHP class for the GA4 data pipeline (fetch, resolve, aggregate, cache)
- A new REST endpoint registration (or add to existing endpoint file)
- A new JS module or addition to the existing reports JS for the chart rendering
- Additions to the settings page for the service account path field
- A Composer dependency addition

## Do NOT

- Do not create a new admin page or tab — this goes on the existing Reports tab
- Do not introduce a new CSS framework or chart library
- Do not use `url_to_postid()` in a loop
- Do not store raw GA4 response data in the database — only store the aggregated category-level result
- Do not require a file path for the service account credentials — the JSON is pasted directly into the settings UI
- For this version, do not attempt to chart tags — only the 14 categories
- Do not add any public-facing routes or pages — this is admin-only

## Final Note

- Use all the best practices already established in this plugin and for ASAE plugins in general, including accessibility
