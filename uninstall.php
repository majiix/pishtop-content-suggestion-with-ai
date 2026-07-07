<?php
/**
 * Plugin Uninstall Script
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pishtop_post_embeddings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pishtop_logs" );

// Delete settings options
delete_option( 'pishtop_ai_settings' );
delete_option( 'pishtop_ai_templates' );

// Delete cron hooks
wp_clear_scheduled_hook( 'pishtop_ai_daily_maintenance' );
wp_clear_scheduled_hook( 'pishtop_ai_regeneration_queue' );

// Delete transient/options used for quota/usage tracking
delete_option( 'pishtop_ai_quota_usage' );
