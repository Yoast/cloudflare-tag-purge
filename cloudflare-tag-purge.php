<?php
/**
 * Plugin Name: Cloudflare Tag Purge
 * Plugin URI: https://wordpress.org/plugins/cloudflare-yoast/
 * Description: Enables you to purge the cache by tags in Cloudflare (enterprise accounts only).
 * Author: Team Yoast
 * Version: 1.0.6
 * Author URI: https://wordpress.org/
 * Text Domain: cloudflare-tag-purge
 */

define('cloudflare_tag_purge_version', '1.0.6');
define('YOAST_CLOUDFLARE_LOG_STATUS_INFO', 'info');
define('YOAST_CLOUDFLARE_LOG_STATUS_PURGE', 'purge');

if (!is_admin()) {
    add_filter('body_class', 'yoast_cache_prefix_body_class', 40, 1);
}

if (is_admin() || wp_is_json_request()) {
    add_action('init', 'yoast_cloudflare_admin_init');
}

/**
 * Yoast/Cloudflare admin init
 */
function yoast_cloudflare_admin_init()
{
//    add_action('save_post', 'yoast_cloudflare_purge_action_save_post', 10, 3);
    add_action('edit_post', 'yoast_cloudflare_purge_action_edit_post', 10, 3);
}

/**
 * @param int $post_id
 * @param $post
 * @param $update
 * @return mixed
 */
function yoast_cloudflare_purge_action_save_post($post_id, $post, $update)
{
    execute_yoast_tag_purge((int)$post_id);
}

/**
 * @param int $post_id
 * @param $post
 * @param $update
 * @return mixed
 */
function yoast_cloudflare_purge_action_edit_post($post_id, $post)
{
    execute_yoast_tag_purge((int)$post_id);
}

/**
 * @param int $post_id
 */
function execute_yoast_tag_purge(int $post_id)
{
    $auth_key = read_yoast_environment_setting('CF_KEY', null);

    if (empty($auth_key)) {
        yoast_cache_tag_log(YOAST_CLOUDFLARE_LOG_STATUS_INFO, ['purge' => false, 'info' => 'No CloudFlare key available']);

        return;
    }

    $tags = get_yoast_cache_tags_by_post_id((int)$post_id);

    if (count($tags) === 0) {
        yoast_cache_tag_log(YOAST_CLOUDFLARE_LOG_STATUS_INFO, ['purge' => false, 'info' => 'No tags found for post #' . $post_id, 'post_id' => $post_id]);

        return;
    }

    $mail = read_yoast_environment_setting('CF_EMAIL', null);
    $zone_id = read_yoast_environment_setting('CF_ZONE_ID', null);

    if (empty($mail) || empty($auth_key) || empty($zone_id)) {
        yoast_cache_tag_log(YOAST_CLOUDFLARE_LOG_STATUS_INFO, ['purge' => false, 'info' => 'No CloudFlare email and zone id available']);

        return;
    }

    $loops = ceil(count($tags) / 30); // a maximum of 30 tags per API call
    for ($i = 0; $i <= $loops; $i++) {
        $api_tags = array_splice($tags, ($i * 30), 30);

        if (\count($api_tags) === 0) {
            continue;
        }

        yoast_cache_tag_log(YOAST_CLOUDFLARE_LOG_STATUS_PURGE, ['purge' => true, 'tags' => $api_tags, 'zone_id' => $zone_id]);

        wp_remote_post(
            'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache',
            [
                'method' => 'POST',
                'blocking' => false,
                'headers' => [
                    'X-Auth-Email' => $mail,
                    'X-Auth-Key' => $auth_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'tags' => array_values($api_tags),
                ]),
            ]
        );
    }

    $sitemap_url = get_yoast_sitemap_url((int)$post_id);

    if (!empty($sitemap_url)) {
        yoast_cache_tag_log(YOAST_CLOUDFLARE_LOG_STATUS_PURGE, ['purge' => true, 'sitemap' => $sitemap_url, 'zone_id' => $zone_id]);

        wp_remote_post(
            'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache',
            [
                'method' => 'POST',
                'blocking' => false,
                'headers' => [
                    'X-Auth-Email' => $mail,
                    'X-Auth-Key' => $auth_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'files' => [$sitemap_url],
                ]),
            ]
        );
    }
}


/**
 * @param int $post_id
 * @return array|string[]
 */
function get_yoast_cache_tags_by_post_id(int $post_id)
{
    $tags = [get_yoast_cache_prefix() . 'postid-' . (int)$post_id];
    $post = get_post($post_id);

    if ($post instanceof WP_Post) {
        // add author page
        if (isset($post->post_author) && !empty($post->post_author)) {
            $tags = array_merge([get_yoast_cache_prefix() . 'author-' . $post->post_author], $tags);
        }

        $post_type = get_post_type($post->ID);
        $taxonomies = get_object_taxonomies($post_type);
        foreach ($taxonomies as $tax) {
            foreach (wp_get_object_terms($post->ID, $tax) as $taxonomy_details) {
                $tags[] = get_yoast_cache_prefix() . $tax . '-' . $taxonomy_details->term_id;
                $tags[] = get_yoast_cache_prefix() . $tax . '-' . $taxonomy_details->slug;
            }
        }
    }

    do_action_ref_array('yoast_cloudflare_purge_cache_tags', $tags);

    return $tags;
}

/**
 * @param $classes
 * @return mixed
 */
function yoast_cache_prefix_body_class($classes)
{
    if (get_yoast_cache_prefix() === null) {
        // return default classes, without prefix (production)
        return $classes;
    }

    // return default classes + prefixed classes
    $prefixedClasses = $classes;
    foreach ($prefixedClasses as $key => $class) {
        $prefixedClasses[$key] = get_yoast_cache_prefix() . $class;
    }

    return array_merge($classes, $prefixedClasses);
}

/**
 * Get the sitemap url, and flush by url
 *
 * @param int $post_id
 * @return string
 */
function get_yoast_sitemap_url(int $post_id)
{
    $prefix = get_site_url() . '/';
    $post_type = get_post_type($post_id);

    if (empty($post_type)) {
        return $prefix . 'sitemap_index.xml';
    }

    return $prefix . $post_type . '-sitemap.xml';
}

/**
 * @param $key
 * @param $default
 * @return mixed
 */
function read_yoast_environment_setting($key, $default)
{
    if (!empty(getenv($key))) {
        return getenv($key);
    }

    return $default;
}

/**
 * @return string|null
 */
function get_yoast_cache_prefix()
{
    if (read_yoast_environment_setting('DOMAIN_CURRENT_SITE', 'staging-local.yoast.com') === 'yoast.com') {
        // on production: no prefixes are added for the tags
        return null;
    }

    $prefix = str_replace('.yoast.com', '', read_yoast_environment_setting('DOMAIN_CURRENT_SITE', 'staging-local.yoast.com'));
    $prefix = str_replace('.', '-', $prefix);

    return trim(strtolower($prefix)) . '-';
}

/**
 * @param string $level
 * @param array $data
 */
function yoast_cache_tag_log(string $level, array $data)
{
    $log_location = read_yoast_environment_setting('CF_LOG_PATH', null);
    if ($log_location === null) {
        error_log('Cloudflare log file path not configured (CF_LOG_PATH)');
        return;
    }

    $data['level'] = $level;
    $data['server'] = $_SERVER['SERVER_NAME'];
    $data['site'] = read_yoast_environment_setting('DOMAIN_CURRENT_SITE', 'staging-local.yoast.com');
    $data['created'] = \date('Y-m-d H:i:s');

    $file_stream = @fopen($log_location, 'a');
    @fwrite($file_stream, \json_encode($data) . "\n");
    @fclose($file_stream);

    if (!is_writable($log_location)) {
        error_log('Cloudflare log file path not writable: ' . $log_location);
        return;
    }
}