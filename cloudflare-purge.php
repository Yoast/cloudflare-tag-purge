<?php
/**
 * Plugin Name: Cloudflare Tag Purge
 * Plugin URI: https://wordpress.org/plugins/cloudflare-yoast/
 * Description: Enables you to purge the cache by tags in Cloudflare (enterprise accounts only).
 * Author: Team Yoast
 * Version: 1.0.5
 * Author URI: https://wordpress.org/
 * Text Domain: cloudflare-tag-purge
 */

define( 'cloudflare_tag_purge_version', '1.0.5' );
define( 'YOAST_CLOUDFLARE_LOG_STATUS_INFO', 'info' );
define( 'YOAST_CLOUDFLARE_LOG_STATUS_PURGE', 'purge' );

class Yoast_CloudFlare_Purge {
	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'register_hooks' ] );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! is_admin() ) {
			add_filter( 'body_class', [ $this, 'cache_prefix_body_class' ], 40, 1 );
		}

		if ( is_admin() || wp_is_json_request() ) {
			add_action( 'edit_post', [ $this, 'purge_action_edit_post' ], 10, 2 );
		}
	}

	/**
	 * Purge the post that has been published/updated and all related URLs.
	 *
	 * @param int     $post_id ID for the post that has been published/updated (unused).
	 * @param WP_post $post    The post that has been published/updated.
	 *
	 * @return void
	 */
	public function purge_action_edit_post( $post_id, $post ): void {
		if ( ! is_a( $post, WP_post::class ) ) {
			return;
		}
		$purge_cache = new Yoast_CloudFlare_Tag_Purge( $post );
		$purge_cache->execute_tag_purge();
	}
}

$yoast_cloudflare_purge = new Yoast_CloudFlare_Purge();
