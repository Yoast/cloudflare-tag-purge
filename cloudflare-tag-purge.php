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
	 * @var string
	 */
	private $auth_key;

	/**
	 * @var string
	 */
	private $mail;

	/**
	 * @var string
	 */
	private $zone_id;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->auth_key = $this->read_environment_setting( 'CF_KEY', null );
		$this->mail     = $this->read_environment_setting( 'CF_EMAIL', null );
		$this->zone_id  = $this->read_environment_setting( 'CF_ZONE_ID', null );

		if ( empty( $this->auth_key ) ) {
			$this->cache_tag_log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare key available.'
			] );

			return;
		}

		if ( empty( $this->mail ) || empty( $this->zone_id ) ) {
			$this->cache_tag_log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare email or zone ID available.'
			] );

			return;
		}

		add_action( 'plugin_loaded', [ $this, 'register_hooks' ] );
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
	 * @param int     $post_id
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function purge_action_edit_post( int $post_id, WP_Post $post ) {
		$this->execute_tag_purge( (int) $post_id, $post );
	}


	/**
	 * @param $key
	 * @param $default
	 *
	 * @return mixed
	 */
	private function read_environment_setting( $key, $default ) {
		if ( ! empty( getenv( $key ) ) ) {
			return getenv( $key );
		}

		return $default;
	}

	/**
	 * @param int $post_id
	 *
	 * @return array|string[]
	 */
	function get_cache_tags_by_post_id( int $post_id ) {
		$tags = [ $this->get_cache_prefix() . 'postid-' . (int) $post_id ];
		$post = get_post( $post_id );

		if ( $post instanceof WP_Post ) {
			// add author page
			if ( isset( $post->post_author ) && ! empty( $post->post_author ) ) {
				$tags = array_merge( [ $this->get_cache_prefix() . 'author-' . $post->post_author ], $tags );
			}

			$post_type  = get_post_type( $post->ID );
			$taxonomies = get_object_taxonomies( $post_type );
			foreach ( $taxonomies as $tax ) {
				foreach ( wp_get_object_terms( $post->ID, $tax ) as $taxonomy_details ) {
					$tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->term_id;
					$tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->slug;
				}
			}
		}

		do_action_ref_array( 'yoast_cloudflare_purge_cache_tags', $tags );

		return $tags;
	}

	/**
	 * @return string|null
	 */
	private function get_cache_prefix() {
		if ( $this->read_environment_setting( 'DOMAIN_CURRENT_SITE', 'staging-local.yoast.com' ) === 'yoast.com' ) {
			// on production: no prefixes are added for the tags
			return null;
		}

		$prefix = str_replace( '.yoast.com', '', $this->read_environment_setting( 'DOMAIN_CURRENT_SITE', 'staging-local.yoast.com' ) );
		$prefix = str_replace( '.', '-', $prefix );

		return trim( strtolower( $prefix ) ) . '-';
	}


	/**
	 * @param string $level
	 * @param array  $data
	 */
	private function cache_tag_log( string $level, array $data ) {
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
	 * @param $classes
	 *
	 * @return mixed
	 */
	private function cache_prefix_body_class( $classes ) {
		if ( $this->get_cache_prefix() === null ) {
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
	 * Get the sitemap url, and flush by url
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	private function get_sitemap_url( int $post_id ) {
		$prefix    = get_site_url() . '/';
		$post_type = get_post_type( $post_id );

		if ( empty( $post_type ) ) {
			return $prefix . 'sitemap_index.xml';
		}

		return $prefix . $post_type . '-sitemap.xml';
	}

	/**
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	private function execute_tag_purge( int $post_id, $post ) {
		$tags = $this->get_cache_tags_by_post_id( $post_id );

		if ( count( $tags ) === 0 ) {
			$this->cache_tag_log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge'   => false,
				'info'    => 'No tags found for post #' . $post_id,
				'post_id' => $post_id
			] );

			return;
		}

		$loops = ceil( count( $tags ) / 30 ); // a maximum of 30 tags per API call
		for ( $i = 0; $i <= $loops; $i ++ ) {
			$api_tags = array_splice( $tags, ( $i * 30 ), 30 );

			if ( \count( $api_tags ) === 0 ) {
				continue;
			}

			$this->cloudflare_cache_clear( [ 'tags' => array_values( $api_tags ) ] );
		}

		$sitemap_url = $this->get_sitemap_url( (int) $post_id );

		if ( ! empty( $sitemap_url ) ) {
			$this->cloudflare_cache_clear( [ 'files' => [ $sitemap_url ] ] );
		}
	}

	/**
	 * @param array $payload
	 *
	 * @return void
	 */
	private function cloudflare_cache_clear( array $payload ): void {
		$this->cache_tag_log( YOAST_CLOUDFLARE_LOG_STATUS_PURGE, [
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
}

$yoast_cloudflare_purge = new Yoast_CloudFlare_Purge();
