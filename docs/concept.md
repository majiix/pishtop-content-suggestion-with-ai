# PishTop Content Suggestion with AI: Plugin Concept Blueprint

This blueprint outlines the technical architecture, security protocols, database schemas, prompt formatting, and user settings for the **PishTop Content Suggestion with AI** WordPress plugin.

---

## 1. High-Level Architecture

The plugin resides fully within WordPress, using OpenRouter.ai to handle embeddings and LLM re-ranking. Vector storage is maintained locally in the database, with queries optimized by filtering candidates before similarity calculations.

![PishTop Content Suggestion with AI Architecture Flowchart](pishtop_architecture_flowchart.jpg)

```mermaid
graph TD
    %% Indexing Flow
    subgraph Indexing Phase (Background Queue)
        A[Post Saved/Updated] --> B[Queue Indexing Task]
        B --> C[Fetch Selected Embedding Fields]
        C --> D[Request Embedding from OpenRouter]
        D --> E[Store Vector & Model in wp_pishtop_post_embeddings]
    end

    %% Retrieval Flow
    subgraph Retrieval Phase (Visitor Request)
        F[Request Page] --> G{Cache Valid?}
        G -- Yes --> H[Fetch Cache from DB]
        G -- No --> I{Is Mutex Lock Active?}
        I -- Yes --> J[Instantly Return Native Fallback]
        I -- No --> K[Acquire Mutex Lock]
        K --> L[Run AJAX/REST API Request]
        L --> M[Fetch Current Post Embedding]
        M --> N[Filter Candidates by Language WPML/Polylang]
        N --> O[Pre-filter to Max N Candidates in SQL]
        O --> P[Calculate Cosine Similarity in PHP]
        P --> Q[Send Top Candidates to OpenRouter LLM]
        Q --> R{Returns Max X IDs?}
        R -- Yes --> S[Cache Result, Release Lock, Render Template]
        R -- No --> T[Fill Remaining Slots with Next Similar Candidates]
        T --> S
    end
end
```

---

## 2. Advanced Features & Enhancements

### Scalable Similarity Search
* **Ceiling Pre-Filtering:** To scale beyond 10,000+ posts, the similarity retrieval does not compute cosine similarity against all posts in PHP. Instead, it queries the database to filter candidates by post type, language, and taxonomy/recent date first, capping the candidate database records to a configurable ceiling (default: 500) before loading embeddings into PHP memory for the similarity calculation.

### Embedding Model Versioning
* **Model Stale Check:** The `wp_pishtop_post_embeddings` table includes an `embedding_model` column. Stale embeddings (mismatched models) are excluded from similarity candidate pools entirely to prevent dimensional comparison errors.
* **Auto-Regeneration Queue:** If the active embedding model changes, stale rows are queued for background regeneration. This queue respects the daily embedding quota to prevent surprise costs, but admins can explicitly bypass this quota limit when running the manual **[Start Bulk Indexing]** tool. Triggering manual bulk indexing prompts a JavaScript confirmation modal displaying the estimated number of API calls and potential costs before launching.

### Cache Stampede Protection (Mutex Lock)
* **Strategy:** During cache misses, the plugin acquires a transient-based mutex lock before executing the API request.
* **Scoping:** The mutex lock is strictly scoped per post ID (e.g., using a transient key like `pishtop_lock_{post_id}`). This ensures that concurrent traffic to one popular post never blocks or delays recommendation queries for other posts.
* **Lock Expiry (TTL):** The lock transient uses a fixed expiry constant (`PISHTOP_LOCK_TTL = 60` seconds). If the OpenRouter request hangs or the PHP process crashes, the lock automatically self-releases after 60 seconds.
* **Fallback Behavior:** If another visitor's concurrent request hits the same post page before the lock is released, it immediately receives native fallback recommendations. This keeps page loads sub-second and prevents duplicate concurrent API calls to OpenRouter.

### API Cost Controls & Quotas
* **Dual Quota Settings:** Admins can configure separate daily limits for:
  1. Embedding generation requests (indexing).
  2. LLM re-ranking requests (retrieval).
* **Quota Reset Boundary:** Counters are tracked and reset on a fixed daily boundary at midnight (00:00) using the site's local timezone configuration. To prevent server-timezone drift, resetting calculations utilize WordPress's native `wp_date()` or `current_time('timestamp')` APIs rather than standard PHP `date()` or `time()`.
* **Cost Safety Valve:** A daily API usage counter tracks requests. Once a limit is reached, the plugin ceases API calls and falls back to native matching until the counter resets.

### Prompt Injection & Sanitization
* **Data Sanitization:** Before post data is interpolated into the LLM prompt template, it is stripped of HTML tags, markdown elements, and quotes/braces are escaped.
* **Defensive Instructions:** The default prompt template contains system rules instructing the LLM to treat the post title/excerpt content strictly as raw semantic data and to ignore any formatting or procedural commands embedded within it.

### Logging Cap & Log Controls
* **Cap Size:** The log database table is capped at a maximum of 5,000 rows.
* **Cleanup:** In addition to day-based retention settings (e.g., 7 days), daily WP-Cron runs delete the oldest records if the table size exceeds the row cap to avoid database bloat.
* **Truncation Warning:** If the 5,000-row cap triggers early deletion of logs before the day-based retention period expires, the diagnostics console displays a warning notice stating: *"Logs are being truncated early due to high event volume."*
* **Admin Controls:** Logging can be disabled entirely.

### Multilingual Support (WPML & Polylang)
* **Isolation:** Automatic integration with WPML (`wpml_element_language_details`) and Polylang (`pll_get_post_language`) to isolate content searches by language. Candidates are filtered at the database level by the current post's language before similarity matching.

### WooCommerce Dynamic Sessions & Cache Isolation
* **Dynamic Content Extraction:** When recommendations are triggered on WooCommerce Cart, Checkout, or Order Received (Thank You) pages, the plugin bypasses the page's generic title and static text. It dynamically queries the customer's active session cart items (via `WC()->cart->get_cart()`) or order items (via referrer-parsed or global query-var order IDs matching `wc_get_order()`) and utilizes these product titles to build the match text.
* **Leakage-Proof Cache Partitioning:** To secure user session separation, the transient keys generated for WooCommerce endpoints append a customer-isolated hash. For Cart and Checkout, the key includes a sorted MD5 checksum of the cart items and their quantities. For the thank-you screen, the key integrates the specific order ID. This prevents cached recommendations from leaking across different user accounts.

### Developer Hook Extensibility
* **`pishtop_ai_post_text` Filter:** Allows developers to filter and override the raw consolidated text context of any post before it gets passed to the OpenRouter embedding API.
  - Arguments: `(string) $text`, `(int) $post_id`
* **`pishtop_ai_recommendations_transient_key` Filter:** Allows filtering the cache key name before transients are written or read, providing deep control over recommendation cache segmenting.
  - Arguments: `(string) $transient_key`, `(int) $post_id`, `(string) $template_id`, `(string) $post_type`

### Viewport Lazy Loading (Intersection Observer)
* **Optimization Strategy:** To minimize bandwidth consumption and control API costs, recommendations are loaded lazily. Instead of triggering AJAX queries immediately upon DOM load, the frontend script enqueues the DOM element under an `IntersectionObserver`.
* **Trigger Threshold:** The observer is configured with a `rootMargin: '100px'`, triggering the AJAX callback slightly before the element scrolls into view. Once loaded, the observer unobserves the target element.
* **Legacy Fallback:** If `IntersectionObserver` is not supported by the browser, the script falls back to executing the load query immediately on document ready.

---

## 3. Dynamic Template System

The plugin allows administrators to create multiple named HTML templates for display.

### Repeater Templates
Admins can define multiple templates (e.g., `default_list`, `product_grid`, `sidebar_widgets`).
Each template consists of:
* **Template ID:** Unique handle used in shortcodes (e.g., `template="product_grid"`).
* **Wrapper HTML:** HTML surrounding the list, containing a placeholder for the items.
* **Item HTML:** The HTML markup for each recommended post/product.

### Placeholders Supported
* `{{title}}` - The title of the post or product.
* `{{permalink}}` - The URL of the post or product.
* `{{image_url}}` - The featured image URL (fallback to default placeholder if empty).
* `{{excerpt}}` - Post excerpt or short description.
* `{{post_date}}` - Date of publication.
* `{{meta:key_name}}` - Any custom field or postmeta. E.g., `{{meta:brand}}` for a custom brand field.
* `{{price:key_name}}` - A price formatter that applies WooCommerce currency formatting (using `wc_price()`) to raw numeric custom fields (e.g., `{{price:_price}}`). If WooCommerce is inactive, the output is suppressed entirely (returning an empty string or dash) to keep the visual design clean.

#### Example: Product Grid Template
* **Wrapper HTML:**
  ```html
  <div class="ai-related-products-grid">
      {{items}}
  </div>
  ```
* **Item HTML:**
  ```html
  <div class="ai-product-card">
      <a href="{{permalink}}">
          <img src="{{image_url}}" alt="{{title}}" class="product-thumb" />
          <h4 class="product-title">{{title}}</h4>
          <span class="price-tag">{{price:_price}}</span>
      </a>
  </div>
  ```

---

## 4. Updated Database Schema

### Table: `wp_pishtop_post_embeddings`

| Column Name | Data Type | Key / Index | Description |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT` unsigned | Primary Key | Unique row identifier |
| `post_id` | `BIGINT` unsigned | Unique Index | Foreign key pointing to `wp_posts.ID` |
| `lang` | `VARCHAR(10)` | Index | Language code of the post (cached on save for fast filtering) |
| `embedding_model` | `VARCHAR(100)` | Index | The model used to generate this embedding (for version checking) |
| `embedding` | `LONGBLOB` | None | Serialized binary array representing the embedding vector |
| `updated_at` | `DATETIME` | Index | Timestamp of last embedding generation |

### Table: `wp_pishtop_logs`

| Column Name | Data Type | Key / Index | Description |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT` unsigned | Primary Key | Unique row identifier |
| `level` | `VARCHAR(12)` | Index | Log level: `INFO`, `DEBUG`, or `ERROR` |
| `message` | `TEXT` | None | Description of the logged event |
| `context` | `LONGTEXT` | None | JSON representation of debugging payload (payloads, response IDs, etc.) |
| `created_at` | `DATETIME` | Index | Timestamp of log creation |

---

## 5. Admin Settings Configuration Update

The settings interface is expanded to include:

### 1. General Settings
* **OpenRouter API Key:** Password text input.
* **Cache Expiry (TTL):** Numeric input with unit selection (Hours/Days).
* **Default Fallback:** Dropdown selecting fallback behavior when API is offline (Native Category/Tag, Recent Posts, Hide Entirely).
* **Force Update Caches (Actions):** 
  * **[Clear Recommendation Caches]** button: Deletes all cached postmeta and transients. Gates access using capability verification and requires JavaScript confirmation dialogs.
  * **[Clear Embeddings Cache]** button: Deletes all local vector embeddings to force full database regeneration. Gates access using capability verification and requires JavaScript confirmation dialogs.

### 2. Matching Engine Settings
* **Embedding Model:** Dropdown to select embedding model.
* **Embedding Fields Selection:** Checkboxes to choose which post fields are concatenated to generate the vector embedding (e.g., Post Title, Excerpt/Description, Full Content, Categories/Tags, and Custom Fields).
* **Ranking Model:** Dropdown to select ranking model.
* **Similarity Candidate Count:** Number of top candidates to find locally (e.g., range 10-100).
* **Max Pre-filtered Candidates:** SQL candidate query cap before similarity checks (default: 500).
* **Max Recommendation Count:** Number of items requested by default (e.g., range 1-20).
* **Payload Fields Selection:** Checkboxes to select which post details are sent to the AI ranking prompt (e.g., Post Title, Excerpt/Description, Categories/Tags, and Specific Custom Fields/Postmeta).
* **Prompt Editor:** Text area displaying the instruction template (with dynamic placeholder tags mapping the selected payload fields).

### 3. API Quota & Security Settings
* **Daily Embedding Quota:** Max requests per day for embedding generations.
* **Daily Ranking Quota:** Max requests per day for LLM recommendation selections.
* **Usage Counters:** Shows live stats: *"X/Y Embedding Requests today"* and *"A/B LLM Ranking Requests today"*.
* **Security & Nonce Verification:** Backend security layers requiring `manage_options` capability check and nonce checks on all REST API and AJAX administrative handlers.

### 4. Display Templates (Repeater Interface)
* **Load Built-in Layout CSS:** Checkbox to enable/disable loading of responsive presets (Grid, List, and Cards).
* A dynamic table where the admin can add new templates:
  * **[Add New Template]**
  * Fields: Template ID, Wrapper HTML, Item HTML, Custom CSS.

### 5. Logging & Diagnostics Settings
* **Enable Diagnostics Logging:** Checkbox to toggle logging on or off.
* **Log Retention Period:** Numeric input for retention threshold (in days, e.g., 7 days).
* **Diagnostics Console:** Paginated table showing recent log records:
  * Columns: Time, Level (INFO, DEBUG, ERROR), Message, and a "View Context Payload" link opening a modal showing raw JSON payloads.
  * Filters: Dropdown to filter by Level, search bar for keyword search, and pagination navigation (Prev/Next page numbers).
  * Diagnostics Buttons: **[Refresh Logs]** to reload list without reloading the page, and **[Clear All Logs]** (requires capability checks, nonces, and confirmation dialog).
