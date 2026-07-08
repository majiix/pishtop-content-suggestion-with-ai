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
* Asynchronous AJAX form saves with top-right floating toast notifications
* Configurable post types selection for indexing and recommendations
* Expose transient mutex lock TTL duration, database log row capacities, API request timeouts, LLM re-ranking temperatures, and log page sizes as editable admin settings
* Expose HTTP header request title details, background queue indexing delays, log capacity cleanup ratios, maintenance cron schedules, fallback image URL paths, and post thumbnail sizes as custom admin settings
* Dynamic prompt resetting feature allowing administrators to revert custom prompts to default JSON format instantly
* Periodic cron worker scheduling and background processing of vector embeddings and recommendations caching
* Staged cron worker execution enforcing fallback matching until vector database indexing is fully complete
* Inline fallback cron runner executing background tasks on page loads if scheduled cron executions are overdue
* Configurable ranking source fields choosing Title, Excerpt, and/or Content as prompt context for LLM re-ranking

## Verification Commands
* standalone similarity and syntax check:
  `php -f C:/Users/Espadana/.gemini/antigravity/brain/66a219a9-6cdf-4df6-b5e5-774495a551dd/scratch/test-standalone.php`
* PHP syntax lint check:
  `Get-ChildItem -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }`
