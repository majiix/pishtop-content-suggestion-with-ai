=== PishTop Content Suggestion with AI ===
Contributors: pishtop
Tags: related posts, ai recommendations, vector embeddings, semantic search, openrouter
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered related post recommendations using OpenRouter.ai for text embeddings and LLM ranking, paired with local vector similarity search.

== Description ==

PishTop Content Suggestion with AI is a next-generation related posts plugin that uses advanced semantic modeling. Rather than relying on simple keyword matching, it leverages local vector embeddings (generated via OpenRouter.ai) and cosine similarity in PHP to find contextually relevant recommendations.

To achieve maximum precision, the plugin performs local similarity pre-filtering and passes the top candidates to a selected OpenRouter Chat LLM (e.g., Gemini, GPT-4) for a final re-ranking step.

= Key Features =
* **Local Vector Storage:** Custom database tables store text embedding vectors, preventing external API calls on every recommendation render.
* **OpenRouter.ai Integration:** Fetch high-quality text embeddings and perform LLM re-ranking using state-of-the-art models.
* **Cost-Control Quotas:** Separate daily limits block embedding and ranking operations once exceeded, automatically falling back to native category recommendations to prevent surprise API charges.
* **Mutex Lock Stampede Protection:** Restricts concurrent API calls during content updates, protecting your budgets under traffic surges.
* **Display Layout Templates:** Create custom wrapper and item markup with CSS stylesheets right inside the settings dashboard. Include WooCommerce price and custom metadata variables instantly.
* **Diagnostics Log Console:** Capped diagnostic log tables record API calls, errors, and system activity directly inside the admin panel.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/pishtop-content-suggestion-with-ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Settings > AI Suggestions** to enter your OpenRouter API Key and configure your parameters.
4. Use the shortcode `[pishtop_suggestions]` or the Gutenberg block to insert suggestions on your pages/posts.

== Frequently Asked Questions ==

= How do I display suggestions? =
You can insert suggestions using the shortcode `[pishtop_suggestions]` or by adding the Gutenberg block "PishTop AI Suggestions" to your posts.

= What API models does it support? =
The plugin automatically pulls all available embedding and chat models from the OpenRouter API. You can choose any supported model (e.g., Google Gemini, OpenAI GPT, Cohere) in the Matching Engine settings tab.

= How do cost budgets work? =
You can set daily quota limits for both embedding generation and LLM re-ranking. Once a limit is reached, the plugin will seamlessly fall back to native WordPress matching to prevent any additional charges.

== Changelog ==

= 1.0.0 =
* Initial release. Expose all parameters, timeouts, logging rates, delays, and customization options to the administrator dashboard.
