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
		add_action( 'wp', [ $this, 'schedule_cron_events' ] );
	}

	/**
	 * Deactivate cron hooks.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'pishtop_ai_daily_maintenance' );
		wp_clear_scheduled_hook( 'pishtop_ai_regeneration_queue' );
	}

	/**
	 * Setup scheduled tasks.
	 */
	public function schedule_cron_events() {
		if ( ! wp_next_scheduled( 'pishtop_ai_daily_maintenance' ) ) {
			// Schedule daily maintenance task at next midnight
			$local_midnight = strtotime( 'tomorrow' );
			wp_schedule_event( $local_midnight, 'daily', 'pishtop_ai_daily_maintenance' );
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

		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		// Clear cached recommendations for this post since content changed
		Matching::clear_cache( $post_id );

		// Schedule background worker event to generate embedding (delayed by 5 seconds)
		if ( ! wp_next_scheduled( 'pishtop_ai_index_post_event', [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, 'pishtop_ai_index_post_event', [ $post_id ] );
		}
	}

	/**
	 * Cron execution: generate embedding vector in background.
	 */
	public function background_index_post( int $post_id ) {
		$settings = get_option( 'pishtop_ai_settings', [] );
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
			Database::add_log( 'ERROR', "Background indexing failed for post $post_id: " . $vector->get_error_message() );
			return;
		}

		Database::save_embedding( $post_id, Matching::get_post_language( $post_id ), $emb_model, $vector );
		Database::add_log( 'INFO', "Background indexed post $post_id successfully." );
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
		
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $threshold_date ) );
		Database::add_log( 'INFO', sprintf( 'Daily maintenance: Deleted %d old log entries.', $deleted ) );

		// Reset daily usage counters
		$today = wp_date( 'Y-m-d' );
		update_option( 'pishtop_ai_quota_usage', [
			'date'      => $today,
			'embedding' => 0,
			'ranking'   => 0,
		] );
	}
}
