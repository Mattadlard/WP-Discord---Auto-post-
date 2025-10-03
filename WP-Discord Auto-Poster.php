<?php
/**
 * Plugin Name: WP-Discord Auto-Poster
 * Plugin URI: https://github.com/mattadlard/wp-discord-poster
 * Description: Automatically posts new WordPress content to a specific Discord channel via webhook
 * Version: 1.0.0
 * Author: Matt Adlard
 * License: MIT
 * 
 * TODO: maybe add support for multiple webhooks later? would be cool for different channels
 * FIXME: should probably add some rate limiting at some point
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WP_Discord_Auto_Poster {
    
    private $option_name = 'wp_discord_poster_settings';
    
    public function __construct() {
        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Hook into post publishing
        // NOTE: might want to add custom post types here eventually
        add_action('publish_post', array($this, 'send_to_discord'), 10, 2);
        add_action('publish_page', array($this, 'send_to_discord'), 10, 2);
        
        // Add meta box for manual posting
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'handle_manual_post'));
    }
    
    public function add_settings_page() {
        add_options_page(
            'Discord Auto-Poster Settings',
            'Discord Poster',
            'manage_options',
            'wp-discord-poster',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitize_settings'));
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['webhook_url'])) {
            // probably should validate this is actually a discord webhook URL but eh
            $sanitized['webhook_url'] = esc_url_raw($input['webhook_url']);
        }
        
        if (isset($input['bot_name'])) {
            $sanitized['bot_name'] = sanitize_text_field($input['bot_name']);
        }
        
        if (isset($input['enable_auto_post'])) {
            $sanitized['enable_auto_post'] = (bool) $input['enable_auto_post'];
        }
        
        if (isset($input['post_types'])) {
            $sanitized['post_types'] = array_map('sanitize_text_field', $input['post_types']);
        }
        
        if (isset($input['include_featured_image'])) {
            $sanitized['include_featured_image'] = (bool) $input['include_featured_image'];
        }
        
        if (isset($input['embed_color'])) {
            $sanitized['embed_color'] = sanitize_hex_color($input['embed_color']);
        }
        
        return $sanitized;
    }
    
    public function render_settings_page() {
        $settings = get_option($this->option_name, array(
            'webhook_url' => '',
            'bot_name' => 'WordPress Bot',
            'enable_auto_post' => true,
            'post_types' => array('post'),
            'include_featured_image' => true,
            'embed_color' => '#0099ff'
        ));
        ?>
        <div class="wrap">
            <h1>Discord Auto-Poster Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields($this->option_name); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="webhook_url">Discord Webhook URL</label></th>
                        <td>
                            <input type="url" id="webhook_url" name="<?php echo $this->option_name; ?>[webhook_url]" 
                                   value="<?php echo esc_attr($settings['webhook_url']); ?>" class="regular-text" required>
                            <p class="description">Get your webhook URL from Discord: Server Settings → Integrations → Webhooks</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bot_name">Bot Name</label></th>
                        <td>
                            <input type="text" id="bot_name" name="<?php echo $this->option_name; ?>[bot_name]" 
                                   value="<?php echo esc_attr($settings['bot_name']); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Auto-Posting</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->option_name; ?>[enable_auto_post]" 
                                       value="1" <?php checked($settings['enable_auto_post'], true); ?>>
                                Automatically post to Discord when content is published
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Post Types</th>
                        <td>
                            <?php
                            $post_types = get_post_types(array('public' => true), 'objects');
                            foreach ($post_types as $post_type) {
                                $checked = in_array($post_type->name, $settings['post_types']);
                                ?>
                                <label>
                                    <input type="checkbox" name="<?php echo $this->option_name; ?>[post_types][]" 
                                           value="<?php echo esc_attr($post_type->name); ?>" <?php checked($checked, true); ?>>
                                    <?php echo esc_html($post_type->label); ?>
                                </label><br>
                                <?php
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Include Featured Image</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->option_name; ?>[include_featured_image]" 
                                       value="1" <?php checked($settings['include_featured_image'], true); ?>>
                                Include featured image in Discord embed
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="embed_color">Embed Color</label></th>
                        <td>
                            <input type="color" id="embed_color" name="<?php echo $this->option_name; ?>[embed_color]" 
                                   value="<?php echo esc_attr($settings['embed_color']); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <hr>
            <h2>Test Your Webhook</h2>
            <form method="post" action="">
                <?php wp_nonce_field('test_discord_webhook', 'discord_test_nonce'); ?>
                <input type="hidden" name="action" value="test_discord_webhook">
                <?php submit_button('Send Test Message', 'secondary', 'test_webhook'); ?>
            </form>
            
            <?php
            if (isset($_POST['test_webhook']) && check_admin_referer('test_discord_webhook', 'discord_test_nonce')) {
                $result = $this->send_test_message();
                if ($result['success']) {
                    echo '<div class="notice notice-success"><p>Test message sent successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Error: ' . esc_html($result['message']) . '</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }
    
    public function send_to_discord($post_id, $post) {
        $settings = get_option($this->option_name);
        
        // Check if auto-posting is enabled
        if (empty($settings['enable_auto_post'])) {
            return;
        }
        
        // Check if this post type should be posted
        if (!in_array($post->post_type, $settings['post_types'])) {
            return;
        }
        
        // Check if webhook URL is set
        if (empty($settings['webhook_url'])) {
            return; // fail silently - don't want to spam admin notices
        }
        
        // Don't post revisions or auto-drafts
        if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
            return;
        }
        
        // Check if already posted (prevent double posting on update)
        // TODO: add option to repost on update? some people might want that
        $already_posted = get_post_meta($post_id, '_discord_posted', true);
        if ($already_posted) {
            return;
        }
        
        $this->post_to_discord($post_id, $settings);
    }
    
    private function post_to_discord($post_id, $settings = null) {
        if (!$settings) {
            $settings = get_option($this->option_name);
        }
        
        $post = get_post($post_id);
        $permalink = get_permalink($post_id);
        
        // Strip out shortcodes and html tags for cleaner excerpt
        $excerpt = wp_trim_words($post->post_content, 50, '...');
        $excerpt = strip_tags($excerpt);
        
        // Convert hex color to decimal (discord uses decimal colors for some reason)
        $color = hexdec(str_replace('#', '', $settings['embed_color']));
        
        // Build embed - discord's embed format is pretty nice actually
        $embed = array(
            'title' => $post->post_title,
            'description' => $excerpt,
            'url' => $permalink,
            'color' => $color,
            'timestamp' => date('c', strtotime($post->post_date)), // ISO 8601 format
            'footer' => array(
                'text' => get_bloginfo('name')
            ),
            'author' => array(
                'name' => get_the_author_meta('display_name', $post->post_author),
                'url' => get_author_posts_url($post->post_author)
            )
        );
        
        // Add featured image if enabled
        // NOTE: using 'large' size - might want to make this configurable?
        if ($settings['include_featured_image'] && has_post_thumbnail($post_id)) {
            $thumbnail_url = get_the_post_thumbnail_url($post_id, 'large');
            $embed['image'] = array('url' => $thumbnail_url);
        }
        
        // Build payload
        $payload = array(
            'username' => $settings['bot_name'],
            'embeds' => array($embed)
        );
        
        // Send to Discord
        // TODO: maybe add retry logic if this fails? wordpress transients could work for queue
        $response = wp_remote_post($settings['webhook_url'], array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($payload),
            'timeout' => 15 // discord usually responds quickly but give it some time
        ));
        
        // Discord returns 204 No Content on success (not 200, learned that the hard way)
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204) {
            update_post_meta($post_id, '_discord_posted', time());
            return array('success' => true);
        } else {
            $error = is_wp_error($response) ? $response->get_error_message() : 'Unknown error';
            // silently fail for now, maybe log this somewhere later
            return array('success' => false, 'message' => $error);
        }
    }
    
    private function send_test_message() {
        $settings = get_option($this->option_name);
        
        if (empty($settings['webhook_url'])) {
            return array('success' => false, 'message' => 'Webhook URL not set');
        }
        
        // simple test message, nothing fancy
        $payload = array(
            'username' => $settings['bot_name'],
            'content' => 'This is a test message from WP-Discord Auto-Poster! ✅'
        );
        
        $response = wp_remote_post($settings['webhook_url'], array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($payload),
            'timeout' => 15
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204) {
            return array('success' => true);
        } else {
            $error = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            return array('success' => false, 'message' => $error);
        }
    }
    
    public function add_meta_box() {
        $settings = get_option($this->option_name);
        $post_types = !empty($settings['post_types']) ? $settings['post_types'] : array('post');
        
        add_meta_box(
            'discord_poster_meta',
            'Discord Poster',
            array($this, 'render_meta_box'),
            $post_types,
            'side',
            'default'
        );
    }
    
    public function render_meta_box($post) {
        wp_nonce_field('discord_manual_post', 'discord_meta_nonce');
        
        $posted_time = get_post_meta($post->ID, '_discord_posted', true);
        
        if ($posted_time) {
            // already posted - show timestamp and allow reposting
            echo '<p>✅ Posted to Discord on:<br>' . date('M j, Y @ g:i a', $posted_time) . '</p>';
            echo '<label><input type="checkbox" name="discord_repost" value="1"> Repost to Discord</label>';
        } else {
            // not posted yet - give option to post manually
            echo '<p>Not yet posted to Discord</p>';
            echo '<label><input type="checkbox" name="discord_manual_post" value="1"> Post to Discord on save</label>';
        }
    }
    
    public function handle_manual_post($post_id) {
        // verify nonce
        if (!isset($_POST['discord_meta_nonce']) || !wp_verify_nonce($_POST['discord_meta_nonce'], 'discord_manual_post')) {
            return;
        }
        
        // don't run on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $settings = get_option($this->option_name);
        
        // handle manual post checkbox
        if (isset($_POST['discord_manual_post']) && $_POST['discord_manual_post'] == '1') {
            $this->post_to_discord($post_id, $settings);
        }
        
        // handle repost checkbox - clear the meta first so it'll actually post again
        if (isset($_POST['discord_repost']) && $_POST['discord_repost'] == '1') {
            delete_post_meta($post_id, '_discord_posted');
            $this->post_to_discord($post_id, $settings);
        }
    }
}

// Initialize the plugin
// considered doing this as a singleton but honestly this is fine
new WP_Discord_Auto_Poster();