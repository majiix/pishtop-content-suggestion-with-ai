<?php
/**
 * Plugin Uninstall Script
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pishtop_settings = get_option( 'pishtop_ai_settings', [] );
if ( empty( $pishtop_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Drop custom tables
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pishtop_post_embeddings" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pishtop_logs" );

// Delete settings options
delete_option( 'pishtop_ai_settings' );
delete_option( 'pishtop_ai_templates' );

// Delete cron hooks
wp_clear_scheduled_hook( 'pishtop_ai_daily_maintenance' );
wp_clear_scheduled_hook( 'pishtop_ai_regeneration_queue' );
wp_clear_scheduled_hook( 'pishtop_ai_cron_worker_event' );

// Delete transient/options used for quota/usage tracking
delete_option( 'pishtop_ai_quota_usage' );
