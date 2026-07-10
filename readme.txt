=== PishTop Content Suggestion with AI ===
Contributors: micromax2
Tags: related posts, ai recommendations, vector embeddings, semantic search, openrouter
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered related post recommendations using OpenRouter.ai for text embeddings and LLM ranking, paired with local vector similarity search.

== Description ==

PishTop Content Suggestion with AI is a next-generation related posts plugin that uses advanced semantic modeling. Rather than relying on simple keyword matching, it leverages local vector embeddings (generated via OpenRouter.ai) and cosine similarity in PHP to find contextually relevant recommendations.

To achieve maximum precision, the plugin performs local similarity pre-filtering and passes the top candidates to a selected OpenRouter Chat LLM (e.g., Gemini, GPT-4) for a final re-ranking step.

= Key Features =
* **Embedding-Only Mode:** Option to completely bypass the OpenRouter LLM re-ranking phase, using raw vector similarity to fetch recommendations locally, cutting API billing and optimizing site performance.
* **Configurable Similarity Threshold:** Enforce similarity score limits (0-100%) in the embedding-only phase to filter out low-relevance match candidates.
* **Local Vector Storage:** Custom database tables store text embedding vectors, preventing external API calls on every recommendation render.
* **Embedding Model Versioning:** Embeddings are versioned with the model ID. Stale embeddings (e.g., if you change models) are automatically filtered out.
* **OpenRouter.ai Integration:** Fetch high-quality text embeddings and perform LLM re-ranking using state-of-the-art models.
* **Cost-Control Quotas:** Separate daily limits block embedding and ranking operations once exceeded, automatically falling back to native category recommendations to prevent surprise API charges. Aligned with local WordPress timezone settings.
* **Mutex Lock Stampede Protection:** Restricts concurrent API calls during content updates, protecting your budgets under traffic surges.
* **Display Layout Templates:** Create custom wrapper and item markup right inside the settings dashboard. Include WooCommerce price and custom metadata variables instantly.
* **Dynamic WooCommerce Contexts:** Automatically overrides generic page titles on Cart, Checkout, and Thank You pages with active cart products and purchased items to yield relevant recommendations.
* **WooCommerce Out-of-Stock Filter:** Respects WooCommerce catalog visibility configurations, automatically hiding out-of-stock items from recommendations.
* **User-Isolated Caching Keys:** Cache transients on WooCommerce pages vary dynamically using secure cart item hashes and order IDs, preventing cross-user caching leakage.
* **Developer Extensibility Filters:** Hook into `pishtop_ai_post_text` to modify source text dynamically, and `pishtop_ai_recommendations_transient_key` to route custom transient cache partitions.
* **Viewport Lazy Loading:** Trigger frontend AJAX suggestion loading via Intersection Observer, saving bandwidth and reducing API costs by querying only when the recommendations block becomes visible.

== External Services ==

This plugin relies on the third-party API service OpenRouter (openrouter.ai) to generate vector embeddings and perform LLM re-ranking of content suggestions.

Specifically, it makes remote requests to:
* **https://openrouter.ai/api/v1/embeddings** (Sends post title, excerpt, content, taxonomies, and/or custom fields to generate numeric representation vectors of the content when a post is created/updated or during background indexing).
* **https://openrouter.ai/api/v1/chat/completions** (Sends post text and lists of recommendation candidate metadata to rank recommendations dynamically upon content retrieval).
* **https://openrouter.ai/api/v1/models** (Fetches list of available LLM and embedding models in the admin settings dashboard).

Use of these services is subject to the OpenRouter Terms of Service and Privacy Policy:
* **OpenRouter Terms of Service:** https://openrouter.ai/terms
* **OpenRouter Privacy Policy:** https://openrouter.ai/privacy

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/pishtop-content-suggestion-with-ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Settings > AI Suggestions** to enter your OpenRouter API Key and configure your parameters.
4. Use the shortcode `[pishtop_suggestions count="5" template="default_list"]` or the Gutenberg block "PishTop AI Suggestions" to insert suggestions on your pages/posts.

== Frequently Asked Questions ==

= How do I display suggestions? =
You can insert suggestions using the shortcode `[pishtop_suggestions count="5" template="default_list"]` or by adding the Gutenberg block "PishTop AI Suggestions" to your posts.

= What API models does it support? =
The plugin automatically pulls all available embedding and chat models from the OpenRouter API. You can choose any supported model (e.g., Google Gemini, OpenAI GPT, Cohere) in the Matching Engine settings tab.

= How do cost budgets work? =
You can set daily quota limits for both embedding generation and LLM re-ranking. Once a limit is reached, the plugin will seamlessly fall back to native WordPress matching to prevent any additional charges. Quotas reset at local midnight.

= Can I bypass the LLM re-ranking phase? =
Yes! Under the Matching Engine settings tab, you can disable the "Enable LLM Re-ranking" option. In this "Embedding-Only" mode, the plugin recommends items based purely on their local vector similarity, completely bypassing the OpenRouter LLM chat API call to save on cost and improve page load speeds. You can also configure a "Similarity Threshold (%)" so only items above a certain similarity match score are recommended.

= Does it support WooCommerce pages? =
Yes! When used inside WooCommerce Cart, Checkout, or Order Received (Thank You) pages, the plugin dynamically extracts active cart products or purchased order items to match suggestions, rather than using generic page titles. Out-of-stock items can also be hidden.

= How does it handle caching on WooCommerce pages? =
To prevent user cache cross-leakage, the caching transient keys are dynamically partitioned per customer session using MD5 hashes of cart items and order IDs.

= What happens during a Cache Stampede? =
If a popular post cache expires, a mutex lock is set. Parallel visitor hits on the page immediately receive native fallback recommendations while the first request fetches the new OpenRouter results in the background, keeping page loads fast and preventing multiple duplicate API charges.

= How do developer filters work? =
The plugin provides two filters:
* `pishtop_ai_post_text` (filters post source text before embedding).
* `pishtop_ai_recommendations_transient_key` (filters cache keys).

= How does the diagnostic log cap work? =
The log table is capped at 5,000 rows. Pruning prunes old rows down to a configured ratio (e.g., 90%) to prevent database stress. A warning is logged if high volume causes early truncation.

== Settings Reference ==

= General Settings =
* **OpenRouter API Key:** Configures authorization to OpenRouter.
* **Cache Expiry (TTL):** Set time to cache recommendations (Hours/Days).
* **Default Fallback:** Native Category/Tag, Recent, or Hide.
* **Cache Actions:** Buttons to clear recommendation cache or clear all embeddings.

= Matching Engine =
* **Enable LLM Re-ranking:** Toggle switch to enable/disable the OpenRouter chat API reordering phase.
* **Similarity Threshold (%):** Minimum similarity score percent required for recommendations (available when LLM Re-ranking is disabled).
* **Embedding Model:** OpenRouter embedding vector model.
* **Embedding Fields:** Checkboxes to select which fields are concatenated for embeddings (Title, Excerpt, Content, Taxonomies, Custom Fields).
* **Ranking Model:** Chat model for LLM re-ranking.
* **Similarity Candidate Count:** Number of top similar vectors to query.
* **Max Pre-filtered Candidates:** Maximum candidates to select in SQL before similarity calculations.
* **Max Recommendation Count:** Default recommendation count.
* **Payload Fields:** Checkboxes for post attributes to include in the LLM ranking prompt (Title, Excerpt, Content).
* **Prompt Editor:** Customize system ranking prompt template with placeholder `{{count}}`. Features a default reset button.
* **Final Output Sort:** Sort results by Similarity, Random, Date Descending, Date Ascending, or Title Ascending.
* **Thumbnail Size:** Dropdown for custom thumbnail size query.
* **Limit Candidates by Category:** If checked, similarity search candidate queries are restricted strictly to the current post's category.

= API Quota & Security Settings =
* **Daily Embedding Quota:** Max indexing requests per day.
* **Daily Ranking Quota:** Max retrieval requests per day.
* **Stats Cards:** Shows live counters for embedding and ranking usage today.

= Display Templates =
* **Layout CSS Toggle:** Toggle loading built-in styles.
* **Repeater List:** Create custom HTML repeater template rows with custom Wrapper HTML (using `{{items}}`), Item HTML (using placeholders `{{title}}`, `{{permalink}}`, `{{image_url}}`, `{{excerpt}}`, `{{post_date}}`, `{{id}}`, custom fields `{{meta:key}}`, WooCommerce prices `{{price}}` / `{{price:key}}`).

= Logging & Diagnostics =
* **Enable Logging:** Toggle logging console.
* **Retention Period:** Keep logs for X days.
* **Max Log Rows:** Logs table ceiling count (default 5000).
* **Log Cleanup Threshold Ratio:** Percentage threshold of max rows to prune logs to when full.
* **Cron Indexing Settings:** Customize cron embedding batch size, cron ranking batch size, cron worker interval, post save indexing delay, and active indexes safety queues.

== Changelog ==

= 1.6.0 =
* Fix background ranking worker transient version prefix check and key resolution, resolving worker execution block.
* Enforce that LLM re-ranking (both via cron ranking worker and frontend matching cache misses) is executed only after database vector embedding indexing reaches 100% completion, falling back to local vector similarity matching during the indexing phase.
* Allow real-time frontend matching on cache misses to suggest posts immediately from the subset of already-indexed candidates.

= 1.5.0 =
* Implement cache transient key versioning (`pishtop_rec_v{version}_`) to guarantee cache invalidation on external object caches (like Redis or Memcached).
* Resolve query crashes on MySQL strict `ONLY_FULL_GROUP_BY` SQL mode.
* Update taxonomy queries to use `tt_ids` (term taxonomy IDs) for strict relationship matching.
* Remove third-party Google Fonts imports and styling to comply with GDPR privacy guidelines and WordPress.org directory policies.
* Bypass nonce check on the public suggestions AJAX endpoint to prevent page-cached guest visitor requests from breaking.
* Add key character filters on Template ID settings input fields to prevent template mismatch errors.
* Update default LLM re-ranking prompt templates with priority criteria, security rules, and JSON output contracts.

= 1.4.0 =
* Add LLM Shortfall Behavior option to let users choose between filling empty slots with similarity results or hiding them.
* Reorganize settings screen layout: rename OpenRouter Integration section to General, move Enable Caching before Cache Expiry (TTL), move Default Thumbnail Size after Final Output Sorting, and configure settings-dependent toggling fields.
* Auto-clear transient recommendations caches when saving settings to immediately show updated recommendations to visitors.
* Clear outdated embedding vector cache instantly on post updates.
* Improve log descriptiveness by adding context such as post ID, embedding model, API codes, and reasons.

= 1.3.1 =
* Remove Custom CSS styling from display templates and backend dashboard to comply with WordPress.org security policies regarding arbitrary code/styling insertion.
* Remove the shortcode alias `[ai_related_posts]` completely to enforce proper prefixing standards and simplify usage.
* Add dedicated External Services section to readme documenting integration, data transfer details, terms, and privacy policy links for OpenRouter.ai API services.

= 1.2.0 =
* Introduce Embedding-Only matching phase to completely bypass the OpenRouter LLM re-ranking step, saving on API costs and server overhead.
* Implement a configurable Similarity Threshold (0-100%) setting to filter out low-relevance candidate recommendations.
* Update settings in-app documentation and help tabs to cover the new features.

= 1.1.0 =
* Bypass database embedding storage and retrieval for WooCommerce dynamic pages (Cart, Checkout, and Thank You) to prevent different users from receiving mixed-up cart recommendations.

= 1.0.9 =
* Wrap all key AJAX endpoints, shortcode rendering, and background cron callbacks in defensive try-catch blocks to prevent HTTP 500 server errors.

= 1.0.8 =
* Guard WooCommerce cart access to prevent PHP fatal errors when WooCommerce cart is uninitialized.
* Resolve query crash by checking if taxonomy terms is WP_Error.
* Filter LLM-ranked IDs against candidates to drop prose numbers.
* Query configured post types in sorting logic to support hidden types.
* Prepare transient timeout LIKE query placeholder in database.

= 1.0.7 =
* Fix cron reschedule loop during direct hits to wp-cron.php (prevents worker execution blocking).
* Prevent PHP type errors on post saving hook when post is null.
* Add weekly custom cron interval schedule registration.
* Optimize database queries in ranking worker by checking active transients timeout option first.
* Guard against SQL syntax errors in cron queries when allowed post types is empty.
* Clear background indexing cron single events on deactivation and uninstall.

= 1.0.6 =
* Add template fields validation: enforces non-empty Wrapper HTML and Item HTML, and verifies Wrapper HTML contains {{items}} prior to submission.
* Add conditional metadata placeholders: supports single or fallback placeholders like {{meta:custom_key | {{title}} }} evaluating recursively from outside-in.

= 1.0.5 =
* Remove Native Category/Tag matching from default fallback options.
* Update Recent posts fallback to target the template's Target Post Type Filter.

= 1.0.4 =
* Move background ranking fallback checks to the cache miss stage to ensure pre-cached suggestions are correctly served from transients when background worker is active.
* Implement robust multi-stage fallback queries: if the category matching fallback returns fewer posts than requested, the plugin automatically fills up the remaining spots using recent posts of the same post type.

= 1.0.3 =
* Bypass transient cache lookups entirely during active background indexing or ranking runs to always serve fresh native fallback posts.

= 1.0.2 =
* Enforce native fallback rendering during active background indexing or ranking runs.

= 1.0.1 =
* Expand in-app Help and Documentation tab with comprehensive user guidelines.
* Add shortcut Settings link on Plugins list screen.
* Support {{id}} template layout placeholder.

= 1.0.0 =
* Initial release. Expose all parameters, timeouts, logging rates, delays, and customization options to the administrator dashboard.
