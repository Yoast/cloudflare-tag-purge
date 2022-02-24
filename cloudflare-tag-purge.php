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
	 * Authentication key.
	 *
	 * @var string
	 */
	private $auth_key;

	/**
	 * Email registered for the key at Cloudflare.
	 *
	 * @var string
	 */
	private $mail;

	/**
	 * Cloudflare zone we're in.
	 *
	 * @var string
	 */
	private $zone_id;

	/**
	 * The cache tags that be should cleared.
	 *
	 * @var string[]
	 */
	private $tags = [];

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->auth_key = $this->read_environment_setting( 'CF_KEY', null );
		$this->mail     = $this->read_environment_setting( 'CF_EMAIL', null );
		$this->zone_id  = $this->read_environment_setting( 'CF_ZONE_ID', null );

		if ( empty( $this->auth_key ) ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare key available.'
			] );

			return;
		}

		if ( empty( $this->mail ) || empty( $this->zone_id ) ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare email or zone ID available.'
			] );

			return;
		}

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
		$this->execute_tag_purge( $post );
	}


	/**
	 * Read from the system environment.
	 *
	 * @param string $key     The key to read from the environment.
	 * @param string $default The default if no value was  found.
	 *
	 * @return string
	 */
	private function read_environment_setting( string $key, $default ): string {
		if ( ! empty( getenv( $key ) ) ) {
			return getenv( $key );
		}

		return $default;
	}

	/**
	 * Retrieve the cache tags we should be clearing.
	 *
	 * @param WP_post $post The post that has been published/updated.
	 *
	 * @return void
	 */
	private function get_cache_tags( WP_post $post ): void {
		$this->tags = [ $this->get_cache_prefix() . 'postid-' . $post->ID ];

		$this->add_author_page( $post );
		$this->add_taxonomy_tags( $post );

		// Depending on the post type, clear the archive for that post type.
		if ( $post->post_type === 'post' ) {
			$this->tags[] = 'blog';
		}
		else if ( get_post_type_archive_link( $post->post_type ) !== false ) {
			$this->tags[] = 'post-type-archive-' . $post->post_type;
		}

		do_action_ref_array( 'yoast_cloudflare_purge_cache_tags', $this->tags );
	}

	/**
	 * Prefix our cache tag with the environment.
	 *
	 * @return string
	 */
	private function get_cache_prefix(): string {
		if ( $this->read_environment_setting( 'DOMAIN_CURRENT_SITE', 'staging-local.yoast.com' ) === 'yoast.com' ) {
			// on production: no prefixes are added for the tags
			return '';
		}

		$prefix = str_replace( '.yoast.com', '', $this->read_environment_setting( 'DOMAIN_CURRENT_SITE', 'staging-local.yoast.com' ) );
		$prefix = str_replace( '.', '-', $prefix );

		return trim( strtolower( $prefix ) ) . '-';
	}

	/**
	 * Log an action.
	 *
	 * @param string $level Log level.
	 * @param array  $data  The data to log.
	 *
	 * @return void
	 */
	private function log( string $level, array $data ): void {
		$log_location = $this->read_environment_setting( 'CF_LOG_PATH', null );
		if ( $log_location === null ) {
			error_log( 'Cloudflare log file path not configured (CF_LOG_PATH)' );

			return;
		}

		$data['level']   = $level;
		$data['server']  = $_SERVER['SERVER_NAME'];
		$data['site']    = $this->read_environment_setting( 'DOMAIN_CURRENT_SITE', 'staging-local.yoast.com' );
		$data['created'] = \date( 'Y-m-d H:i:s' );

		$file_stream = @fopen( $log_location, 'a' );
		@fwrite( $file_stream, \wp_json_encode( $data ) . "\n" );
		@fclose( $file_stream );

		if ( ! is_writable( $log_location ) ) {
			error_log( 'Cloudflare log file path not writable: ' . $log_location );

			return;
		}
	}

	/**
	 * Prefix the cache classes.
	 *
	 * @param array $classes
	 *
	 * @return array Array of classes.
	 */
	private function cache_prefix_body_class( $classes ): array {
		if ( $this->get_cache_prefix() === '' ) {
			// return default classes, without prefix (production)
			return $classes;
		}

		// return default classes + prefixed classes
		$prefixedClasses = $classes;
		foreach ( $prefixedClasses as $key => $class ) {
			$prefixedClasses[ $key ] = $this->get_cache_prefix() . $class;
		}

		return array_merge( $classes, $prefixedClasses );
	}

	/**
	 * Purge the cache for the updated/published post and all the pages it appears on.
	 *
	 * @param \WP_post $post The post that has been published/updated.
	 *
	 * @return void
	 */
	private function execute_tag_purge( \WP_post $post ): void {
		$this->get_cache_tags( $post );

		if ( count( $this->tags ) === 0 ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge'   => false,
				'info'    => 'No tags found for post #' . $post->ID,
				'post_id' => $post->ID
			] );

			return;
		}

		$loops = ceil( count( $this->tags ) / 30 ); // a maximum of 30 tags per API call
		for ( $i = 0; $i <= $loops; $i ++ ) {
			$api_tags = array_splice( $this->tags, ( $i * 30 ), 30 );

			if ( \count( $api_tags ) === 0 ) {
				continue;
			}

			$this->cloudflare_cache_clear( [ 'tags' => array_values( $api_tags ) ] );
		}
	}

	/**
	 * Do a cache purge on Cloudflare.
	 *
	 * @param array $payload The payload to send to Cloudflare.
	 *
	 * @return void
	 */
	private function cloudflare_cache_clear( array $payload ): void {
		$this->log( YOAST_CLOUDFLARE_LOG_STATUS_PURGE, [
				'purge'   => true,
				'payload' => $payload,
				'zone_id' => $this->zone_id,
			]
		);

		wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . $this->zone_id . '/purge_cache',
			[
				'method'   => 'POST',
				'blocking' => false,
				'headers'  => [
					'X-Auth-Email' => $this->mail,
					'X-Auth-Key'   => $this->auth_key,
					'Content-Type' => 'application/json',
				],
				'body'     => wp_json_encode( [
					$payload,
				] ),
			]
		);
	}

	/**
	 * Add the author page to the tags.
	 *
	 * @param WP_post $post
	 *
	 * @return void
	 */
	private function add_author_page( WP_post $post ): void {
		if ( isset( $post->post_author ) && ! empty( $post->post_author ) ) {
			$url = get_user_meta( $post->post_author, 'author_page_url', true );
			if ( ! empty( $url ) ) {
				$author_page_id = url_to_postid( $url );
				$this->tags[]   = 'postid-' . $author_page_id;
			}
		}
	}

	/**
	 * Add the cache tags for all taxonomies.
	 *
	 * @param WP_post $post
	 *
	 * @return void
	 */
	private function add_taxonomy_tags( WP_post $post ): void {
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $tax ) {
			foreach ( wp_get_object_terms( $post->ID, $tax ) as $taxonomy_details ) {
				$this->tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->term_id;
				$this->tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->slug;
			}
		}
	}
}

$yoast_cloudflare_purge = new Yoast_CloudFlare_Purge();
