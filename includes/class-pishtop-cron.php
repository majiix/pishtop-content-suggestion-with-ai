<?php
namespace PishTop\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages background events, post save hooks, daily cleanups, and cron actions.
 */
class Cron {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Hook post save to trigger background indexing
		add_action( 'save_post', [ $this, 'queue_post_indexing' ], 10, 2 );
		add_action( 'pishtop_ai_index_post_event', [ $this, 'background_index_post' ] );

		// Hook daily maintenance
		add_action( 'pishtop_ai_daily_maintenance', [ $this, 'run_daily_maintenance' ] );
		add_action( 'init', [ $this, 'schedule_cron_events' ] );
		add_action( 'updated_option', [ $this, 'schedule_cron_events_on_updated_option' ], 10, 3 );
		add_action( 'added_option', [ $this, 'schedule_cron_events_on_added_option' ], 10, 2 );

		// Hook custom cron intervals and periodic worker event handler
		add_filter( 'cron_schedules', [ $this, 'register_cron_intervals' ] );
		add_action( 'pishtop_ai_cron_worker_event', [ $this, 'run_cron_worker' ] );
		add_action( 'init', [ $this, 'check_inline_fallback_runner' ], 15 );
	}

	/**
	 * Deactivate cron hooks.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'pishtop_ai_daily_maintenance' );
		wp_clear_scheduled_hook( 'pishtop_ai_cron_worker_event' );
	}

	/**
	 * Setup scheduled tasks.
	 */
	public function schedule_cron_events( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'pishtop_ai_settings', [] );
		}
		$schedule = ! empty( $settings['maintenance_schedule'] ) ? $settings['maintenance_schedule'] : 'daily';

		$current_schedule = wp_get_schedule( 'pishtop_ai_daily_maintenance' );

		if ( $current_schedule && $current_schedule !== $schedule ) {
			wp_clear_scheduled_hook( 'pishtop_ai_daily_maintenance' );
			$current_schedule = false;
		}

		if ( ! $current_schedule ) {
			// Schedule daily maintenance task at next midnight
			$local_midnight = strtotime( 'tomorrow' );
			wp_schedule_event( $local_midnight, $schedule, 'pishtop_ai_daily_maintenance' );
		}

		// Periodic cron worker
		$enable_embedding = isset( $settings['enable_cron_embedding'] ) ? (bool) $settings['enable_cron_embedding'] : true;
		$enable_ranking = isset( $settings['enable_cron_ranking'] ) ? (bool) $settings['enable_cron_ranking'] : false;

		if ( $enable_embedding || $enable_ranking ) {
			$current_worker_schedule = wp_get_schedule( 'pishtop_ai_cron_worker_event' );
			$saved_minutes = isset( $settings['cron_interval_minutes'] ) ? intval( $settings['cron_interval_minutes'] ) : 15;
			$schedules = wp_get_schedules();
			$scheduled_interval = $schedules['pishtop_custom_interval']['interval'] ?? 0;

			if ( ! $current_worker_schedule || $scheduled_interval !== ( $saved_minutes * MINUTE_IN_SECONDS ) ) {
				wp_clear_scheduled_hook( 'pishtop_ai_cron_worker_event' );
				wp_schedule_event( time() + 30, 'pishtop_custom_interval', 'pishtop_ai_cron_worker_event' );
			}
		} else {
			wp_clear_scheduled_hook( 'pishtop_ai_cron_worker_event' );
		}
	}

	/**
	 * Save post hook: schedules single-event background cron task.
	 */
	public function queue_post_indexing( $post_id, $post ) {
		// Ignore autosaves, revisions, or non-post types
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$enable_embedding = isset( $settings['enable_cron_embedding'] ) ? (bool) $settings['enable_cron_embedding'] : true;
		if ( ! $enable_embedding ) {
			return;
		}

		$allowed_types = ! empty( $settings['indexed_post_types'] ) ? $settings['indexed_post_types'] : [ 'post' ];

		if ( ! in_array( $post->post_type, $allowed_types, true ) || 'publish' !== $post->post_status ) {
			return;
		}

		// Clear cached recommendations for this post since content changed
		Matching::clear_cache( $post_id );

		// Schedule background worker event to generate embedding (delayed by settings value)
		$delay = isset( $settings['cron_indexing_delay'] ) ? intval( $settings['cron_indexing_delay'] ) : 5;
		if ( ! wp_next_scheduled( 'pishtop_ai_index_post_event', [ $post_id ] ) ) {
			wp_schedule_single_event( time() + $delay, 'pishtop_ai_index_post_event', [ $post_id ] );
		}
	}

	/**
	 * Cron execution: generate embedding vector in background.
	 */
	public function background_index_post( int $post_id ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$enable_embedding = isset( $settings['enable_cron_embedding'] ) ? (bool) $settings['enable_cron_embedding'] : true;
		if ( ! $enable_embedding ) {
			return;
		}

		$emb_model = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';

		$text = Matching::build_post_text( $post_id );
		if ( empty( $text ) ) {
			return;
		}

		// Check if embedding exists and is up to date
		$stored = Database::get_embedding( $post_id );
		if ( $stored && $stored['model'] === $emb_model ) {
			return; // Already up to date
		}

		$vector = API::get_embedding( $text, $emb_model );
		if ( is_wp_error( $vector ) ) {
			\pishtop_log( 'ERROR', "Background indexing failed for post $post_id: " . $vector->get_error_message() );
			return;
		}

		Database::save_embedding( $post_id, Matching::get_post_language( $post_id ), $emb_model, $vector );
		\pishtop_log( 'INFO', "Background indexed post $post_id successfully." );
	}

	/**
	 * Daily maintenance: Clean up diagnostic logs and reset usage.
	 */
	public function run_daily_maintenance() {
		global $wpdb;
		$settings = get_option( 'pishtop_ai_settings', [] );
		$retention_days = isset( $settings['log_retention'] ) ? intval( $settings['log_retention'] ) : 7;

		// Clean up logs older than retention days
		$table = $wpdb->prefix . 'pishtop_logs';
		$threshold_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $threshold_date ) );
		\pishtop_log( 'INFO', sprintf( 'Daily maintenance: Deleted %d old log entries.', $deleted ) );

		// Reset daily usage counters
		$today = wp_date( 'Y-m-d' );
		update_option( 'pishtop_ai_quota_usage', [
			'date'      => $today,
			'embedding' => 0,
			'ranking'   => 0,
		] );
	}

	/**
	 * Options update hooks to process scheduling changes after database commits.
	 */
	public function schedule_cron_events_on_updated_option( $option, $old_value, $value ) {
		if ( 'pishtop_ai_settings' === $option ) {
			$this->schedule_cron_events( $value );
		}
	}

	public function schedule_cron_events_on_added_option( $option, $value ) {
		if ( 'pishtop_ai_settings' === $option ) {
			$this->schedule_cron_events( $value );
		}
	}

	/**
	 * Register custom cron intervals.
	 */
	public function register_cron_intervals( $schedules ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
		$minutes = isset( $settings['cron_interval_minutes'] ) ? intval( $settings['cron_interval_minutes'] ) : 15;
		$schedules['pishtop_custom_interval'] = [
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: number of minutes */
			'display'  => sprintf( __( 'Every %d Minutes', 'pishtop-content-suggestion-with-ai' ), $minutes ),
		];
		return $schedules;
	}

	/**
	 * Periodic cron worker execution callback.
	 */
	public function run_cron_worker() {
		update_option( 'pishtop_ai_cron_last_run', time() );
		$settings = get_option( 'pishtop_ai_settings', [] );
		$enable_embedding = isset( $settings['enable_cron_embedding'] ) ? (bool) $settings['enable_cron_embedding'] : true;
		$enable_ranking = isset( $settings['enable_cron_ranking'] ) ? (bool) $settings['enable_cron_ranking'] : false;

		if ( $enable_embedding ) {
			$this->run_embedding_worker( $settings );
		}

		if ( $enable_ranking ) {
			$this->run_ranking_worker( $settings );
		}
	}

	/**
	 * Run background embedding worker.
	 */
	private function run_embedding_worker( $settings ) {
		global $wpdb;
		$emb_model = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';

		$allowed_types = ! empty( $settings['indexed_post_types'] ) ? $settings['indexed_post_types'] : [ 'post' ];
		$placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );

		$batch_size = isset( $settings['cron_embedding_batch_size'] ) ? max( 1, intval( $settings['cron_embedding_batch_size'] ) ) : 5;
		if ( ! wp_doing_cron() ) {
			$batch_size = 1;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}pishtop_post_embeddings emb ON p.ID = emb.post_id AND emb.embedding_model = %s
			 WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders) AND emb.post_id IS NULL
			 ORDER BY p.ID DESC LIMIT %d",
			array_merge( [ $emb_model ], $allowed_types, [ $batch_size ] )
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col( $query );

		if ( empty( $post_ids ) ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			$text = Matching::build_post_text( $post_id );
			if ( empty( $text ) ) {
				Database::save_embedding( $post_id, Matching::get_post_language( $post_id ), $emb_model, array_fill( 0, 1536, 0.0 ) );
				continue;
			}

			$vector = API::get_embedding( $text, $emb_model );
			if ( is_wp_error( $vector ) ) {
				\pishtop_log( 'ERROR', "Background cron indexing failed for post $post_id: " . $vector->get_error_message() );
				continue;
			}

			Database::save_embedding( $post_id, Matching::get_post_language( $post_id ), $emb_model, $vector );
		}

		wp_cache_delete( 'pishtop_indexed_posts_' . md5( serialize( $allowed_types ) ), 'pishtop_posts' );
	}

	/**
	 * Run background ranking worker.
	 */
	private function run_ranking_worker( $settings ) {
		if ( Matching::has_unindexed_posts() ) {
			\pishtop_log( 'INFO', 'Background ranking worker skipped: Database has unindexed posts.' );
			return;
		}

		global $wpdb;
		$emb_model = ! empty( $settings['embedding_model'] ) ? $settings['embedding_model'] : 'openai/text-embedding-3-small';
		$allowed_types = ! empty( $settings['indexed_post_types'] ) ? $settings['indexed_post_types'] : [ 'post' ];
		$placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );

		$ranking_batch_size = isset( $settings['cron_ranking_batch_size'] ) ? max( 1, intval( $settings['cron_ranking_batch_size'] ) ) : 5;
		if ( ! wp_doing_cron() ) {
			$ranking_batch_size = 1;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = $wpdb->prepare(
			"SELECT DISTINCT emb.post_id FROM {$wpdb->prefix}pishtop_post_embeddings emb
			 JOIN {$wpdb->posts} p ON emb.post_id = p.ID
			 WHERE p.post_status = 'publish' AND p.post_type IN ($placeholders) AND emb.embedding_model = %s
			 ORDER BY p.ID DESC",
			array_merge( $allowed_types, [ $emb_model ] )
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col( $query );

		$templates = get_option( 'pishtop_ai_templates', [] );
		$limit = isset( $settings['max_recommendation_count'] ) ? intval( $settings['max_recommendation_count'] ) : 5;
		$ranking_count = 0;

		if ( ! empty( $templates ) && ! empty( $post_ids ) ) {
			foreach ( $templates as $tpl_id => $tpl ) {
				$tpl_post_type = $tpl['post_type'] ?? '';
				foreach ( $post_ids as $post_id ) {
					$transient_key = "pishtop_rec_{$post_id}_{$tpl_id}_" . sanitize_key( $tpl_post_type );
					$transient_key = apply_filters( 'pishtop_ai_recommendations_transient_key', $transient_key, $post_id, $tpl_id, $tpl_post_type );
					if ( false === get_transient( $transient_key ) ) {
						if ( $ranking_count >= $ranking_batch_size ) {
							break 2;
						}
						Matching::get_recommendations( $post_id, $limit, $tpl_id, $tpl_post_type );
						$ranking_count++;
					}
				}
			}
		}
	}

	/**
	 * Check if the scheduled cron worker is overdue, and run a small batch inline if so.
	 */
	public function check_inline_fallback_runner() {
		// Avoid running on AJAX, cron, or WP-CLI requests
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$settings = get_option( 'pishtop_ai_settings', [] );
		$enable_embedding = isset( $settings['enable_cron_embedding'] ) ? (bool) $settings['enable_cron_embedding'] : true;
		$enable_ranking = isset( $settings['enable_cron_ranking'] ) ? (bool) $settings['enable_cron_ranking'] : false;

		if ( ! $enable_embedding && ! $enable_ranking ) {
			return;
		}

		// Retrieve last run timestamp
		$last_run = get_option( 'pishtop_ai_cron_last_run', 0 );
		$interval_minutes = isset( $settings['cron_interval_minutes'] ) ? intval( $settings['cron_interval_minutes'] ) : 15;
		$threshold = $interval_minutes * MINUTE_IN_SECONDS * 2; // Overdue if missed 2 intervals

		if ( ( time() - $last_run ) > $threshold ) {
			// Update last run time first to prevent concurrent requests from executing it simultaneously
			update_option( 'pishtop_ai_cron_last_run', time() );

			// Run worker inline
			$this->run_cron_worker();
		}
	}
}
