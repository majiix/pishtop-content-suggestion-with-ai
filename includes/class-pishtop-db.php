<?php
namespace PishTop\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database tables, queries, logging, and vector storage.
 */
class Database {

	/**
	 * Activate plugin and install database schema.
	 */
	public static function activate() {
		self::create_tables();
	}

	/**
	 * Create custom tables for embeddings and logs.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_embeddings = $wpdb->prefix . 'pishtop_post_embeddings';
		$table_logs       = $wpdb->prefix . 'pishtop_logs';

		$sql_embeddings = "CREATE TABLE $table_embeddings (
			id BIGINT unsigned NOT NULL AUTO_INCREMENT,
			post_id BIGINT unsigned NOT NULL,
			lang VARCHAR(10) NOT NULL DEFAULT '',
			embedding_model VARCHAR(100) NOT NULL DEFAULT '',
			embedding LONGBLOB NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY post_id (post_id),
			KEY lang (lang),
			KEY embedding_model (embedding_model),
			KEY updated_at (updated_at)
		) $charset_collate;";

		$sql_logs = "CREATE TABLE $table_logs (
			id BIGINT unsigned NOT NULL AUTO_INCREMENT,
			level VARCHAR(12) NOT NULL,
			message TEXT NOT NULL,
			context LONGTEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_embeddings );
		dbDelta( $sql_logs );
	}

	/**
	 * Save embedding for a post.
	 */
	public static function save_embedding( int $post_id, string $lang, string $model, array $embedding ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_post_embeddings';
		$serialized = json_encode( $embedding );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			$table,
			[
				'post_id'         => $post_id,
				'lang'            => $lang,
				'embedding_model' => $model,
				'embedding'       => $serialized,
				'updated_at'      => current_time( 'mysql', 1 ),
			],
			[ '%d', '%s', '%s', '%s', '%s' ]
		);

		wp_cache_delete( 'pishtop_embedding_' . $post_id, 'pishtop_embeddings' );
		wp_cache_delete( 'pishtop_candidates_' . $post_id, 'pishtop_embeddings' );
		wp_cache_delete( 'pishtop_has_unindexed', 'pishtop_posts' );
	}

	/**
	 * Fetch embedding for a post.
	 */
	public static function get_embedding( int $post_id ) {
		$cache_key = 'pishtop_embedding_' . $post_id;
		$cached = wp_cache_get( $cache_key, 'pishtop_embeddings' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_post_embeddings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT embedding_model, embedding FROM $table WHERE post_id = %d", $post_id ) );
		if ( ! $row ) {
			wp_cache_set( $cache_key, null, 'pishtop_embeddings', 3600 );
			return null;
		}
		$data = [
			'model'     => $row->embedding_model,
			'embedding' => json_decode( $row->embedding, true ),
		];
		wp_cache_set( $cache_key, $data, 'pishtop_embeddings', 3600 );
		return $data;
	}

	/**
	 * Delete embedding for a post.
	 */
	public static function delete_embedding( int $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_post_embeddings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
		wp_cache_delete( 'pishtop_embedding_' . $post_id, 'pishtop_embeddings' );
		wp_cache_delete( 'pishtop_candidates_' . $post_id, 'pishtop_embeddings' );
		wp_cache_delete( 'pishtop_has_unindexed', 'pishtop_posts' );
	}

	/**
	 * Get candidate post IDs with existing embeddings for similarity search.
	 */
	public static function get_candidates( int $post_id, string $lang, string $model, int $limit = 500, string $post_type = '' ) {
		$cache_key = 'pishtop_candidates_' . $post_id . '_' . md5( $lang . '_' . $model . '_' . $limit . '_' . $post_type );
		$cached = wp_cache_get( $cache_key, 'pishtop_embeddings' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table_emb = $wpdb->prefix . 'pishtop_post_embeddings';

		// Get current post details
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$post_type = ! empty( $post_type ) ? sanitize_key( $post_type ) : $post->post_type;

		// Fetch term taxonomies (categories, tags, etc) for pre-filtering similarity ranking
		$terms = wp_get_post_terms( $post_id, get_object_taxonomies( $post_type ), [ 'fields' => 'tt_ids' ] );

		$select = "SELECT emb.post_id, emb.embedding";
		$from   = "FROM $table_emb emb JOIN {$wpdb->posts} p ON emb.post_id = p.ID";
		$where  = "WHERE p.post_status = 'publish' AND p.post_type = %s AND emb.post_id != %d AND emb.embedding_model = %s";
		$params = [ $post_type, $post_id, $model ];

		if ( ! empty( $lang ) ) {
			$where  .= " AND emb.lang = %s";
			$params[] = $lang;
		}

		// WooCommerce hide out of stock items
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$from  .= " JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'";
			$where .= " AND pm_stock.meta_value != 'outofstock'";
		}

		// Priority/pre-filtering using taxonomy matching
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$settings = get_option( 'pishtop_ai_settings', [] );
			$restrict_cats = ! empty( $settings['limit_candidates_same_category'] );
			$join_type = $restrict_cats ? 'JOIN' : 'LEFT JOIN';

			$term_list = implode( ',', array_map( 'intval', $terms ) );
			$from  .= " {$join_type} {$wpdb->term_relationships} tr ON p.ID = tr.object_id AND tr.term_taxonomy_id IN ($term_list)";
			$select .= ", COUNT(tr.term_taxonomy_id) as term_matches";
			$groupby = "GROUP BY emb.post_id, emb.embedding";
			$orderby = "ORDER BY term_matches DESC, p.post_date DESC";
		} else {
			$select .= ", 0 as term_matches";
			$groupby = "";
			$orderby = "ORDER BY p.post_date DESC";
		}

		$query  = "$select $from $where $groupby $orderby LIMIT %d";
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

		$candidates = [];
		foreach ( $results as $row ) {
			$vector = json_decode( $row->embedding, true );
			if ( is_array( $vector ) ) {
				$candidates[] = [
					'post_id'   => (int) $row->post_id,
					'embedding' => $vector,
				];
			}
		}

		wp_cache_set( $cache_key, $candidates, 'pishtop_embeddings', 900 );
		return $candidates;
	}

	/**
	 * Log error or info to custom database log table.
	 */
	public static function add_log( string $level, string $message, $context = null ) {
		// Respect admin settings toggle
		$settings = get_option( 'pishtop_ai_settings', [] );
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'level'      => sanitize_text_field( $level ),
				'message'    => sanitize_textarea_field( $message ),
				'context'    => $context ? json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', 1 ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		// Execute log table cap checks
		self::cap_logs_table();
	}

	/**
	 * Enforce maximum rows in log database table.
	 */
	private static function cap_logs_table() {
		if ( get_transient( 'pishtop_logs_cap_checked' ) ) {
			return;
		}
		set_transient( 'pishtop_logs_cap_checked', true, 600 ); // 10 minutes

		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_logs';

		$settings = get_option( 'pishtop_ai_settings', [] );
		$max_rows = isset( $settings['max_log_rows'] ) ? max( 100, intval( $settings['max_log_rows'] ) ) : 5000;
		$ratio = isset( $settings['log_cleanup_threshold_ratio'] ) ? max( 10, min( 100, intval( $settings['log_cleanup_threshold_ratio'] ) ) ) : 90;
		$cleanup_threshold = intval( $max_rows * ( $ratio / 100 ) );

		// Get total logs count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count <= $max_rows ) {
			return;
		}

		// Find ID threshold to delete (delete down to threshold to prevent frequent deleting)
		$delete_limit = $count - $cleanup_threshold;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$threshold_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table ORDER BY id ASC LIMIT %d, 1", $delete_limit ) );

		if ( $threshold_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", $threshold_id ) );

			// Insert warning log (disable trigger check via direct insertion to avoid loop)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				[
					'level'      => 'WARNING',
					'message'    => 'Logs are being truncated early due to high event volume.',
					'context'    => null,
					'created_at' => current_time( 'mysql', 1 ),
				],
				[ '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Get logs from DB.
	 */
	public static function get_logs( int $limit = 50, int $offset = 0, string $level = '', string $search = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_logs';

		$where = [];
		$params = [];

		if ( ! empty( $level ) ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		if ( ! empty( $search ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = $wpdb->prepare( "SELECT * FROM $table $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $query );
	}

	/**
	 * Get total logs count matching query.
	 */
	public static function get_logs_count( string $level = '', string $search = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_logs';

		$where = [];
		$params = [];

		if ( ! empty( $level ) ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		if ( ! empty( $search ) ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = ! empty( $where ) ? $wpdb->prepare( "SELECT COUNT(*) FROM $table $where_sql", $params ) : "SELECT COUNT(*) FROM $table";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Delete all logs.
	 */
	public static function clear_all_logs() {
		global $wpdb;
		$table = $wpdb->prefix . 'pishtop_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}
