<?php
/**
 * Plugin Name: Cloudflare Tag Purge
 * Plugin URI: https://wordpress.org/plugins/cloudflare-yoast/
 * Description: Enables you to purge the cache by tags in Cloudflare (enterprise accounts only).
 * Author: Team Yoast
 * Version: 1.0.0
 * Author URI: https://wordpress.org/
 * Text Domain: cloudflare-tag-purge
 */


define('cloudflare_tag_purge_version', '1.0.0');

if (is_admin()) {
    add_action('init', 'yoast_cloudflare_admin_init');
} else {
    add_filter('body_class', 'yoast_cache_prefix_body_class', 40, 1);
}

/**
 * Yoast/Cloudflare admin init
 */
function yoast_cloudflare_admin_init()
{
    if (\class_exists('CF\WordPress\DataStore') === false) {
        return;
    }

    // Cloudflare plugin activated
    add_filter('cloudflare_purge_by_url', 'yoast_cloudflare_purge_action', 10, 2);
}


/**
 * @param array $urls List of the URLs to be purged
 * @param int $post_id The Post ID of the requested purge action
 * @return array
 */
function yoast_cloudflare_purge_action(array $urls, int $post_id)
{
    if (\class_exists('CF\WordPress\DataStore') === false) {
        return $urls;
    }

    $auth_key = read_yoast_environment_setting('CF_KEY', null);

    if (empty($auth_key)) {
        return $urls;
    }

    $tags = get_yoast_cache_tags_by_post_id((int)$post_id);

    if (\count($tags) === 0) {
        return $urls;
    }

    $mail = read_yoast_environment_setting('CF_EMAIL', null);
    $zone_id = read_yoast_environment_setting('CF_ZONE_ID', null);

    if (empty($mail) || empty($auth_key) || empty($zone_id)) {
        return false;
    }

    $loops = \ceil(\count($tags) / 30); // a maximum of 30 tags per API call
    for ($i = 0; $i <= $loops; $i++) {
        $api_tags = \array_splice($tags, ($i * 30), 30);

        \wp_remote_post(
            'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache',
            [
                'method' => 'POST',
                'blocking' => false,
                'headers' => [
                    'X-Auth-Email' => $mail,
                    'X-Auth-Key' => $auth_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => [
                    'tags' => \array_values($api_tags),
                ],
            ]
        );
    }

    return $urls;
}


/**
 * @param int $post_id
 * @return array|string[]
 */
function get_yoast_cache_tags_by_post_id(int $post_id)
{
    $tags = [get_yoast_cache_prefix() . 'postid-' . (int)$post_id];
    $post = get_post($post_id);

    if ($post instanceof \WP_Post) {
        // add author page
        if (isset($post->post_author) && !empty($post->post_author)) {
            $tags = \array_merge([get_yoast_cache_prefix() . 'author-' . $post->post_author], $tags);
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

    return \array_merge($classes, $prefixedClasses);
}

/**
 * @param $key
 * @param $default
 * @return mixed
 */
function read_yoast_environment_setting($key, $default)
{
    if (!empty(\getenv($key))) {
        return \getenv($key);
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

    $prefix = \str_replace('.yoast.com', '', read_yoast_environment_setting('DOMAIN_CURRENT_SITE', 'staging-local.yoast.com'));
    $prefix = \str_replace('.', '-', $prefix);

    return trim(\strtolower($prefix)) . '-';
}