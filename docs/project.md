# PishTop Content Suggestion with AI - Project Documentation

## Overview
WordPress plugin for AI-powered related post recommendations using OpenRouter.ai for text embeddings and LLM ranking. Maintains local vector storage in custom database tables, with pre-filtering in SQL and similarity search + LLM re-ranking.

## Tech Stack
* PHP 7.4+
* WordPress 6.9+
* OpenRouter.ai API (Embeddings & LLM re-ranking)
* MySQL (Custom tables for local embedding vectors and diagnostic logs)

## Dependencies
* None (Native WordPress HTTP APIs like `wp_remote_post()` are used for OpenRouter communication).

## Architecture
* **Bootstrap**: [pishtop-content-suggestion-with-ai.php](file:///e:/wps/dorsanet/app/public/wp-content/plugins/pishtop-content-suggestion-with-ai/pishtop-content-suggestion-with-ai.php) (Main plugin entrypoint).
* **Database Setup**: Custom tables `wp_pishtop_post_embeddings` (stores model, language, serialized vector binary array) and `wp_pishtop_logs` (diagnostic logs). Runs on plugin activation.
* **Matching Engine**: 
  1. Pre-filter candidates by language (WPML/Polylang), post type, taxonomy, and recent date.
  2. Compute Cosine Similarity in PHP.
  3. Send top similarity matches to OpenRouter LLM for final re-ranking.
* **Caching**: Cache recommendations using WordPress transient/postmeta with mutex lock (`transients` with scoped post IDs) for stampede protection.
* **Admin Settings Page**: Native Settings API for API key, model selection, limits, quotas, template repeater, and log diagnostics.

## Current Features
* Custom tables installation & management
* Cost-control budgets counters with automatic midnight timezone resets
* Custom template repeaters and CSS builders
* Capped diagnostic logs paginator
* Mutex lock transients (`pishtop_lock_{post_id}`) preventing stampedes
* Single-event background indexes queues
* Asynchronous dynamic OpenRouter models loading with CSS skeleton effects
* In-app Help & Documentation center covering features and configurations
* High-fidelity, slate-light professional dashboard theme with custom toggles, circular SVG charts, and shadow lifts
* Collapsible template repeater layouts with instant shortcode copy buttons
* Asynchronous AJAX form saves with bottom-right floating toast notifications styled dynamically with high-visibility status-based pastel background colors, matching borders, text, and close controls
* Configurable post types selection for indexing and recommendations
* Expose transient mutex lock TTL duration, database log row capacities, API request timeouts, LLM re-ranking temperatures, and log page sizes as editable admin settings
* Expose HTTP header request title details, background queue indexing delays, log capacity cleanup ratios, maintenance cron schedules, fallback image URL paths, and post thumbnail sizes as custom admin settings
* Dynamic prompt resetting feature allowing administrators to revert custom prompts to default JSON format instantly
* Periodic cron worker scheduling and background processing of vector embeddings and recommendations caching
* Staged cron worker execution enforcing fallback matching until vector database indexing is fully complete
* Inline fallback cron runner executing background tasks on page loads if scheduled cron executions are overdue
* Configurable ranking source fields choosing Title, Excerpt, and/or Content as prompt context for LLM re-ranking
* Extensibility filters `pishtop_ai_post_text` for raw match text and `pishtop_ai_recommendations_transient_key` for recommendation cache keys
* Dynamic WooCommerce session resolution overriding generic titles for Cart, Checkout, and Thank You pages with actual cart/order items
* Dynamic user-isolated transient keys utilizing cart hashes and order IDs preventing cross-user cache leakage on WooCommerce pages
* Frontend suggestions lazy loading utilizing Intersection Observer to trigger requests only when viewport visibility threshold is met
* Dynamic count cache optimization fetching larger recommendation pools once and slicing dynamically to prevent redundant API hits for smaller count requests
* Hybrid AI + Fallback recommendation engine filling count requests exceeding configured Max AI count settings with native fallback items, ensuring AI items stay on top with independent sorting
* WooCommerce out-of-stock catalog visibility exclusion filtering dynamically in SQL candidates and fallback queries
* Active Recommendations dashboard stats card displaying the count of posts with cached transient recommendations
* Robust native fallback matching using recent posts of the configured Target Post Type Filter
* Cache-miss background ranking checks ensuring pre-cached recommendations are served directly from transients even under active cron workers
* Mandatory templates validation in JavaScript: enforces non-empty Wrapper HTML and Item HTML, and verifies Wrapper HTML contains `{{items}}` prior to form submission
* Conditional metadata placeholders: supports single or fallback placeholders like `{{meta:custom_key | {{title}} }}` evaluating recursively from outside-in

## Verification Commands
* standalone similarity and syntax check:
  `php -f C:/Users/Espadana/.gemini/antigravity/brain/66a219a9-6cdf-4df6-b5e5-774495a551dd/scratch/test-standalone.php`
* PHP syntax lint check:
  `python C:\Users\Espadana\.gemini\antigravity\brain\e7b77469-ffe3-4826-b839-7c56da8b0483\scratch\lint.py`
* PowerShell PHP syntax lint check:
  `Get-ChildItem -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }`
