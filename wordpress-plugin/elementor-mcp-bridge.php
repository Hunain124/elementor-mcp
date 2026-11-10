<?php
/**
 * Plugin Name: Elementor MCP Bridge
 * Description: Token-authenticated REST access for a local Elementor MCP server.
 * Version: 0.3.4
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page('Elementor MCP Bridge', 'Elementor MCP', 'manage_options', 'elementor-mcp-bridge', 'elementor_mcp_settings_page');
});

function elementor_mcp_settings_page() {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elementor_mcp_save'])) {
        check_admin_referer('elementor_mcp_settings');
        $user_id = absint($_POST['elementor_mcp_user_id'] ?? 0);
        $token = trim((string) wp_unslash($_POST['elementor_mcp_token'] ?? ''));
        if (!$user_id || !get_user_by('id', $user_id)) {
            $notice = '<div class="notice notice-error"><p>Select a valid WordPress user.</p></div>';
        } elseif (strlen($token) < 32) {
            $notice = '<div class="notice notice-error"><p>Token must be at least 32 characters. Generate a long random token and save the same value in your local .env file.</p></div>';
        } else {
            update_option('elementor_mcp_token_hash', hash('sha256', $token), false);
            update_option('elementor_mcp_user_id', $user_id, false);
            $notice = '<div class="notice notice-success"><p>Token saved as a hash. Keep your token in a password manager and in your local .env file.</p></div>';
        }
    }
    $selected = (int) get_option('elementor_mcp_user_id', get_current_user_id());
    $has_token = (bool) get_option('elementor_mcp_token_hash');
    echo '<div class="wrap"><h1>Elementor MCP Bridge</h1>' . $notice;
    echo '<p>Creates a separate credential for your local MCP server. Choose the WordPress user whose page permissions should apply. Use a long random token; it is stored as a hash and cannot be displayed again.</p>';
    echo '<form method="post">';
    wp_nonce_field('elementor_mcp_settings');
    echo '<table class="form-table"><tr><th><label for="elementor_mcp_user_id">WordPress user</label></th><td><select name="elementor_mcp_user_id" id="elementor_mcp_user_id">';
    foreach (get_users(['orderby' => 'display_name']) as $user) {
        printf('<option value="%d" %s>%s (%s)</option>', (int) $user->ID, selected($selected, (int) $user->ID, false), esc_html($user->display_name), esc_html($user->user_login));
    }
    echo '</select><p class="description">The selected user must have permission to edit the pages you want to access.</p></td></tr>';
    echo '<tr><th><label for="elementor_mcp_token">New token</label></th><td><input class="regular-text" type="password" autocomplete="new-password" name="elementor_mcp_token" id="elementor_mcp_token" required minlength="32"><p class="description">' . ($has_token ? 'A token is configured. Enter a new token here only to rotate it.' : 'No token configured yet.') . '</p></td></tr></table>';
    echo '<p><button class="button button-primary" name="elementor_mcp_save" value="1">Save token and user</button></p></form></div>';
}

function elementor_mcp_authenticate($request) {
    $expected = (string) get_option('elementor_mcp_token_hash', '');
    $header = (string) $request->get_header('authorization');
    if (!$expected || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) return false;
    $provided = hash('sha256', trim($matches[1]));
    if (!hash_equals($expected, $provided)) return false;
    $user_id = (int) get_option('elementor_mcp_user_id', 0);
    if (!$user_id || !get_user_by('id', $user_id)) return false;
    wp_set_current_user($user_id);
    return true;
}

add_action('rest_api_init', function () {
    register_rest_route('elementor-mcp/v1', '/pages', [
        'methods' => 'GET', 'callback' => 'elementor_mcp_list_pages',
        'permission_callback' => function ($request) { return elementor_mcp_authenticate($request) && current_user_can('edit_pages'); },
        'args' => [
            'per_page' => ['default' => 20, 'sanitize_callback' => 'absint'],
            'search' => ['sanitize_callback' => 'sanitize_text_field'],
            'status' => ['sanitize_callback' => 'sanitize_key'],
        ],
    ]);
    register_rest_route('elementor-mcp/v1', '/pages/(?P<id>\d+)', [
        [
            'methods' => 'GET', 'callback' => 'elementor_mcp_get_page',
            'permission_callback' => function ($request) { return elementor_mcp_page_permission($request); },
        ],
        [
            'methods' => 'PUT', 'callback' => 'elementor_mcp_update_page',
            'permission_callback' => function ($request) { return elementor_mcp_page_permission($request); },
            'args' => ['content' => ['required' => true, 'type' => 'array']],
        ],
    ]);
    register_rest_route('elementor-mcp/v1', '/documents/(?P<id>\d+)', [
        [
            'methods' => 'GET', 'callback' => 'elementor_mcp_get_elementor_document',
            'permission_callback' => 'elementor_mcp_document_permission',
        ],
        [
            'methods' => 'PUT', 'callback' => 'elementor_mcp_update_elementor_document',
            'permission_callback' => 'elementor_mcp_document_permission',
            'args' => ['content' => ['required' => true, 'type' => 'array']],
        ],
    ]);
    register_rest_route('elementor-mcp/v1', '/performance', [
        'methods' => 'GET', 'callback' => 'elementor_mcp_get_performance',
        'permission_callback' => 'elementor_mcp_admin_permission',
    ]);
    register_rest_route('elementor-mcp/v1', '/performance/litespeed', [
        [
            'methods' => 'GET', 'callback' => 'elementor_mcp_get_litespeed_settings',
            'permission_callback' => 'elementor_mcp_admin_permission',
        ],
        [
            'methods' => 'PUT', 'callback' => 'elementor_mcp_update_litespeed_settings',
            'permission_callback' => 'elementor_mcp_admin_permission',
        ],
    ]);
    register_rest_route('elementor-mcp/v1', '/performance/litespeed/purge', [
        'methods' => 'POST', 'callback' => 'elementor_mcp_purge_litespeed_cache',
        'permission_callback' => 'elementor_mcp_admin_permission',
    ]);
    register_rest_route('elementor-mcp/v1', '/seo/home-description', [
        [
            'methods' => 'GET', 'callback' => 'elementor_mcp_get_home_description',
            'permission_callback' => 'elementor_mcp_admin_permission',
        ],
        [
            'methods' => 'PUT', 'callback' => 'elementor_mcp_update_home_description',
            'permission_callback' => 'elementor_mcp_admin_permission',
            'args' => ['description' => ['required' => true, 'type' => 'string']],
        ],
    ]);
    register_rest_route('elementor-mcp/v1', '/elementor/performance/local-fonts', [
        [
            'methods' => 'GET', 'callback' => 'elementor_mcp_get_local_google_fonts',
            'permission_callback' => 'elementor_mcp_admin_permission',
        ],
        [
            'methods' => 'PUT', 'callback' => 'elementor_mcp_update_local_google_fonts',
            'permission_callback' => 'elementor_mcp_admin_permission',
            'args' => ['enabled' => ['required' => true, 'type' => 'boolean']],
        ],
    ]);
});

add_action('wp_head', 'elementor_mcp_print_home_description', 99);

function elementor_mcp_admin_permission($request) {
    return elementor_mcp_authenticate($request) && current_user_can('manage_options');
}

function elementor_mcp_active_plugins() {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugins();
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    $tracked = [
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'autoptimize/autoptimize.php' => 'Autoptimize',
        'wp-rocket/wp-rocket.php' => 'WP Rocket',
        'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php' => 'WP Super Cache',
    ];
    $found = [];
    foreach ($tracked as $file => $label) {
        if (in_array($file, $active, true) && isset($installed[$file])) {
            $found[] = ['name' => $label, 'version' => $installed[$file]['Version'] ?? 'unknown', 'slug' => dirname($file)];
        }
    }
    return $found;
}

function elementor_mcp_get_performance($request) {
    $plugins = elementor_mcp_active_plugins();
    $litespeed_active = false;
    foreach ($plugins as $plugin) if ($plugin['slug'] === 'litespeed-cache') $litespeed_active = true;
    return rest_ensure_response([
        'wordpressVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
        'pageCacheConstant' => defined('WP_CACHE') && WP_CACHE,
        'externalObjectCache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
        'performancePlugins' => $plugins,
        'managedProviders' => $litespeed_active ? ['litespeed-cache'] : [],
        'note' => $litespeed_active ? null : 'No supported performance plugin is active. LiteSpeed Cache controls are available only when that plugin is installed and active.',
    ]);
}

function elementor_mcp_litespeed_boolean_settings_map() {
    // Only boolean settings are exposed. The underlying slugs are defined by LiteSpeed Cache.
    return [
        'cssMinify' => 'optm-css_min',
        'cssCombine' => 'optm-css_comb',
        'ucss' => 'optm-ucss',
        'ucssInline' => 'optm-ucss_inline',
        'cssAsync' => 'optm-css_async',
        'jsMinify' => 'optm-js_min',
        'jsCombine' => 'optm-js_comb',
        'htmlMinify' => 'optm-html_min',
        'lazyImages' => 'media-lazy',
        'lazyIframes' => 'media-iframe_lazy',
        'removeEmoji' => 'optm-emoji_rm',
        'googleFontsAsync' => 'optm-ggfonts_async',
        'removeQueryStrings' => 'optm-qs_rm',
    ];
}

function elementor_mcp_litespeed_settings_map() {
    return array_merge(elementor_mcp_litespeed_boolean_settings_map(), [
        'jsLoading' => 'optm-js_defer',
        'fontDisplay' => 'optm-css_font_display',
    ]);
}

function elementor_mcp_litespeed_is_active() {
    foreach (elementor_mcp_active_plugins() as $plugin) {
        if ($plugin['slug'] === 'litespeed-cache') return true;
    }
    return false;
}

function elementor_mcp_litespeed_api_available() {
    $has_update_hook = has_action('litespeed_save_conf') !== false || has_action('litespeed_update_confs') !== false;
    return has_filter('litespeed_conf') !== false && $has_update_hook && has_action('litespeed_purge_all') !== false;
}

function elementor_mcp_read_litespeed_settings() {
    $settings = [];
    foreach (elementor_mcp_litespeed_boolean_settings_map() as $public_name => $slug) {
        $settings[$public_name] = rest_sanitize_boolean(apply_filters('litespeed_conf', $slug));
    }
    $js_loading = (int) apply_filters('litespeed_conf', 'optm-js_defer');
    $settings['jsLoading'] = [0 => 'off', 1 => 'defer', 2 => 'delay'][$js_loading] ?? 'off';
    $font_display = (string) apply_filters('litespeed_conf', 'optm-css_font_display');
    $settings['fontDisplay'] = in_array($font_display, ['auto', 'block', 'swap', 'fallback', 'optional'], true) ? $font_display : 'auto';
    return $settings;
}

function elementor_mcp_get_litespeed_settings($request) {
    if (!elementor_mcp_litespeed_is_active()) return new WP_Error('litespeed_not_active', 'LiteSpeed Cache is not active.', ['status' => 400]);
    if (!elementor_mcp_litespeed_api_available()) return new WP_Error('litespeed_api_unavailable', 'The active LiteSpeed Cache version does not expose the required configuration hooks.', ['status' => 400]);
    return rest_ensure_response(['provider' => 'litespeed-cache', 'settings' => elementor_mcp_read_litespeed_settings()]);
}

function elementor_mcp_update_litespeed_settings($request) {
    if (!elementor_mcp_litespeed_is_active()) return new WP_Error('litespeed_not_active', 'LiteSpeed Cache is not active.', ['status' => 400]);
    if (!elementor_mcp_litespeed_api_available()) return new WP_Error('litespeed_api_unavailable', 'The active LiteSpeed Cache version does not expose the required configuration hooks.', ['status' => 400]);
    $requested = $request->get_param('settings');
    if (!is_array($requested) || !$requested) return new WP_Error('invalid_settings', 'Provide at least one supported boolean setting.', ['status' => 400]);
    $map = elementor_mcp_litespeed_settings_map();
    $before = elementor_mcp_read_litespeed_settings();
    $changes = [];
    $changed_names = [];
    $js_modes = ['off' => 0, 'defer' => 1, 'delay' => 2];
    $font_display_modes = ['auto', 'block', 'swap', 'fallback', 'optional'];
    foreach ($requested as $name => $value) {
        if (!isset($map[$name])) return new WP_Error('unsupported_setting', 'Unsupported LiteSpeed setting: ' . sanitize_key($name), ['status' => 400]);
        if ($name === 'jsLoading') {
            if (!is_string($value) || !isset($js_modes[$value])) return new WP_Error('invalid_setting_value', 'jsLoading must be off, defer, or delay.', ['status' => 400]);
            $normalized_value = $js_modes[$value];
        } elseif ($name === 'fontDisplay') {
            if (!is_string($value) || !in_array($value, $font_display_modes, true)) return new WP_Error('invalid_setting_value', 'fontDisplay must be auto, block, swap, fallback, or optional.', ['status' => 400]);
            $normalized_value = $value;
        } else {
            if (!is_bool($value)) return new WP_Error('invalid_setting_value', 'LiteSpeed optimization toggles must be true or false.', ['status' => 400]);
            $normalized_value = $value;
        }
        if ($before[$name] !== $value) {
            $changes[$map[$name]] = $normalized_value;
            $changed_names[] = $name;
        }
    }
    if (!$changes) return rest_ensure_response(['provider' => 'litespeed-cache', 'changed' => [], 'settings' => $before, 'cachePurged' => false]);
    if (has_action('litespeed_save_conf')) {
        do_action('litespeed_save_conf', $changes);
    } else {
        do_action('litespeed_update_confs', $changes);
    }
    do_action('litespeed_purge_all', 'Elementor MCP performance settings updated');
    return rest_ensure_response([
        'provider' => 'litespeed-cache',
        'changed' => $changed_names,
        'settings' => elementor_mcp_read_litespeed_settings(),
        'cachePurged' => true,
    ]);
}

function elementor_mcp_purge_litespeed_cache($request) {
    if (!elementor_mcp_litespeed_is_active()) return new WP_Error('litespeed_not_active', 'LiteSpeed Cache is not active.', ['status' => 400]);
    if (!has_action('litespeed_purge_all')) return new WP_Error('litespeed_api_unavailable', 'The active LiteSpeed Cache version does not expose the purge hook.', ['status' => 400]);
    if ($request->get_param('confirm') !== true) return new WP_Error('confirmation_required', 'Set confirm to true to purge all LiteSpeed-managed caches.', ['status' => 400]);
    do_action('litespeed_purge_all', 'Elementor MCP requested cache purge');
    return rest_ensure_response(['provider' => 'litespeed-cache', 'purged' => true]);
}

function elementor_mcp_page_permission($request) {
    return elementor_mcp_authenticate($request) && current_user_can('edit_post', (int) $request['id']);
}

function elementor_mcp_document_permission($request) {
    return elementor_mcp_authenticate($request) && current_user_can('edit_post', (int) $request['id']);
}

function elementor_mcp_get_elementor_document($request) {
    $post = get_post((int) $request['id']);
    if (!$post || !in_array($post->post_type, ['page', 'elementor_library'], true)) return new WP_Error('not_found', 'Elementor document not found.', ['status' => 404]);
    $raw = get_post_meta($post->ID, '_elementor_data', true);
    $content = is_string($raw) ? json_decode($raw, true) : $raw;
    return rest_ensure_response([
        'id' => $post->ID,
        'type' => $post->post_type,
        'templateType' => get_post_meta($post->ID, '_elementor_template_type', true),
        'title' => get_the_title($post),
        'status' => $post->post_status,
        'layout' => ['content' => is_array($content) ? $content : []],
    ]);
}

function elementor_mcp_update_elementor_document($request) {
    $post = get_post((int) $request['id']);
    if (!$post || !in_array($post->post_type, ['page', 'elementor_library'], true)) return new WP_Error('not_found', 'Elementor document not found.', ['status' => 404]);
    if (!did_action('elementor/loaded')) return new WP_Error('elementor_unavailable', 'Elementor is not active.', ['status' => 400]);
    $content = $request->get_param('content');
    if (!is_array($content)) return new WP_Error('invalid_content', 'Content must be a JSON array.', ['status' => 400]);
    wp_save_post_revision($post->ID);
    update_post_meta($post->ID, '_elementor_edit_mode', 'builder');
    update_post_meta($post->ID, '_elementor_data', wp_slash(wp_json_encode($content)));
    if (class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
    return rest_ensure_response(['id' => $post->ID, 'type' => $post->post_type, 'title' => get_the_title($post), 'status' => $post->post_status]);
}

function elementor_mcp_get_home_description($request) {
    return rest_ensure_response(['description' => (string) get_option('elementor_mcp_home_description', '')]);
}

function elementor_mcp_update_home_description($request) {
    $description = sanitize_text_field((string) $request->get_param('description'));
    $length = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
    if ($length > 160) return new WP_Error('description_too_long', 'The homepage description must be 160 characters or fewer.', ['status' => 400]);
    update_option('elementor_mcp_home_description', $description, false);
    $purged = false;
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all', 'Homepage SEO description updated');
        $purged = true;
    }
    return rest_ensure_response(['description' => $description, 'cachePurged' => $purged]);
}

function elementor_mcp_print_home_description() {
    if (!is_front_page()) return;
    if (elementor_mcp_seo_plugin_active()) return;
    $description = trim((string) get_option('elementor_mcp_home_description', ''));
    if ($description === '') return;
    echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
}

function elementor_mcp_seo_plugin_active() {
    if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('SEOPRESS_VERSION') || class_exists('AIOSEO\\Plugin\\Common\\Main')) return true;
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    $seo_plugins = [
        'wordpress-seo/wp-seo.php',
        'wordpress-seo-premium/wp-seo-premium.php',
        'seo-by-rank-math/rank-math.php',
        'all-in-one-seo-pack/all_in_one_seo_pack.php',
        'wp-seopress/seopress.php',
        'autodescription/autodescription.php',
    ];
    return (bool) array_intersect($seo_plugins, $active);
}

function elementor_mcp_get_local_google_fonts($request) {
    if (!defined('ELEMENTOR_VERSION')) return new WP_Error('elementor_not_active', 'Elementor is not active.', ['status' => 400]);
    return rest_ensure_response([
        'enabled' => (string) get_option('elementor_local_google_fonts', '0') === '1',
        'experiment' => (string) get_option('elementor_experiment-e_local_google_fonts', 'default'),
    ]);
}

function elementor_mcp_update_local_google_fonts($request) {
    if (!defined('ELEMENTOR_VERSION')) return new WP_Error('elementor_not_active', 'Elementor is not active.', ['status' => 400]);
    $enabled = rest_sanitize_boolean($request->get_param('enabled'));
    update_option('elementor_local_google_fonts', $enabled ? '1' : '0', false);
    update_option('elementor_experiment-e_local_google_fonts', $enabled ? 'active' : 'inactive', false);
    if (class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
    $purged = false;
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all', 'Elementor local Google Fonts setting updated');
        $purged = true;
    }
    return rest_ensure_response(['enabled' => $enabled, 'cachePurged' => $purged]);
}

function elementor_mcp_list_pages($request) {
    $per_page = min(100, max(1, (int) $request->get_param('per_page')));
    $args = ['post_type' => 'page', 'post_status' => ['publish', 'draft', 'private', 'pending'], 'posts_per_page' => $per_page, 's' => (string) $request->get_param('search'), 'orderby' => 'title', 'order' => 'ASC'];
    $status = sanitize_key((string) $request->get_param('status'));
    if (in_array($status, ['publish', 'draft', 'private', 'pending'], true)) $args['post_status'] = $status;
    $query = new WP_Query($args);
    $pages = [];
    foreach ($query->posts as $post) {
        if (current_user_can('edit_post', $post->ID)) $pages[] = ['id' => $post->ID, 'title' => get_the_title($post), 'link' => get_permalink($post), 'status' => $post->post_status];
    }
    return rest_ensure_response($pages);
}

function elementor_mcp_get_page($request) {
    $post = get_post((int) $request['id']);
    if (!$post || $post->post_type !== 'page') return new WP_Error('not_found', 'Page not found.', ['status' => 404]);
    $raw = get_post_meta($post->ID, '_elementor_data', true);
    $content = is_string($raw) ? json_decode($raw, true) : $raw;
    return rest_ensure_response(['id' => $post->ID, 'title' => get_the_title($post), 'link' => get_permalink($post), 'status' => $post->post_status, 'layout' => ['content' => is_array($content) ? $content : []]]);
}

function elementor_mcp_update_page($request) {
    $post = get_post((int) $request['id']);
    if (!$post || $post->post_type !== 'page') return new WP_Error('not_found', 'Page not found.', ['status' => 404]);
    if (!did_action('elementor/loaded')) return new WP_Error('elementor_unavailable', 'Elementor is not active.', ['status' => 400]);
    $content = $request->get_param('content');
    if (!is_array($content)) return new WP_Error('invalid_content', 'Content must be a JSON array.', ['status' => 400]);
    wp_save_post_revision($post->ID);
    update_post_meta($post->ID, '_elementor_edit_mode', 'builder');
    update_post_meta($post->ID, '_elementor_data', wp_slash(wp_json_encode($content)));
    if (class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
    return rest_ensure_response(['id' => $post->ID, 'title' => get_the_title($post), 'link' => get_permalink($post), 'status' => $post->post_status]);
}
