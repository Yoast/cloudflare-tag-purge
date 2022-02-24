<?php

/**
 * Class that executes a tag purge.
 */
class Yoast_CloudFlare_Tag_Purge {
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
	 * The post we're clearing the cache for.
	 *
	 * @var WP_Post
	 */
	private $post;

	/**
	 * Class constructor.
	 */
	public function __construct( WP_Post $post ) {
		$this->post     = $post;
		$this->auth_key = $this->read_environment_setting( 'CF_KEY', null );
		$this->mail     = $this->read_environment_setting( 'CF_EMAIL', null );
		$this->zone_id  = $this->read_environment_setting( 'CF_ZONE_ID', null );
	}

	/**
	 * Purge the cache for the updated/published post and all the pages it appears on.
	 *
	 * @return void
	 */
	public function execute_tag_purge(): void {
		if ( ! $this->check_settings() ) {
			return;
		}

		$this->get_cache_tags();

		if ( count( $this->tags ) === 0 ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge'   => false,
				'info'    => 'No tags found for post #' . $this->post->ID,
				'post_id' => $this->post->ID
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
	 * Read from the system environment.
	 *
	 * @param string $key     The key to read from the environment.
	 * @param string $default The default if no value was  found.
	 *
	 * @return string
	 */
	private function read_environment_setting( string $key, string $default ): string {
		if ( ! empty( getenv( $key ) ) ) {
			return getenv( $key );
		}

		return $default;
	}

	/**
	 * Retrieve the cache tags we should be clearing.
	 *
	 * @return void
	 */
	private function get_cache_tags(): void {
		$this->tags = [ $this->get_cache_prefix() . 'postid-' . $this->post->ID ];

		$this->add_author_page( $this->post );
		$this->add_taxonomy_tags( $this->post );

		// Depending on the post type, clear the archive for that post type.
		if ( $this->post->post_type === 'post' ) {
			$this->tags[] = 'blog';
			$this->tags[] = 'home';
		}
		else if ( get_post_type_archive_link( $this->post->post_type ) !== false ) {
			$this->tags[] = 'post-type-archive-' . $this->post->post_type;
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
	 * @return void
	 */
	private function add_author_page(): void {
		if ( isset( $this->post->post_author ) && ! empty( $this->post->post_author ) ) {
			$url = get_user_meta( $this->post->post_author, 'author_page_url', true );
			if ( ! empty( $url ) ) {
				$author_page_id = url_to_postid( $url );
				$this->tags[]   = 'postid-' . $author_page_id;
			}
		}
	}

	/**
	 * Add the cache tags for all taxonomies.
	 *
	 * @return void
	 */
	private function add_taxonomy_tags(): void {
		$taxonomies = get_object_taxonomies( $this->post->post_type );
		foreach ( $taxonomies as $tax ) {
			foreach ( wp_get_object_terms( $this->post->ID, $tax ) as $taxonomy_details ) {
				$this->tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->term_id;
				$this->tags[] = $this->get_cache_prefix() . $tax . '-' . $taxonomy_details->slug;
			}
		}
	}

	/**
	 * Checks whether we have all the needed Cloudflare config.
	 *
	 * @return bool
	 */
	private function check_settings(): bool {
		if ( empty( $this->auth_key ) ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare key available.'
			] );

			return false;
		}

		if ( empty( $this->mail ) || empty( $this->zone_id ) ) {
			$this->log( YOAST_CLOUDFLARE_LOG_STATUS_INFO, [
				'purge' => false,
				'info'  => 'No CloudFlare email or zone ID available.'
			] );

			return false;
		}

		return true;
	}
}

$yoast_cloudflare_purge = new Yoast_CloudFlare_Purge();
