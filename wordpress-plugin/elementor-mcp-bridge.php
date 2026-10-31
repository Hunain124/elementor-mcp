<?php
/**
 * Plugin Name: Elementor MCP Bridge
 * Description: Token-authenticated REST access for a local Elementor MCP server.
 * Version: 0.2.0
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
});

function elementor_mcp_page_permission($request) {
    return elementor_mcp_authenticate($request) && current_user_can('edit_post', (int) $request['id']);
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
