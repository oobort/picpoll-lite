<?php
/**
 * Plugin Name: PicPoll Photo Voting Game
 * Description: Flexible photo voting game with regions, categories, dynamic vote options, styling options, and a shortcode builder.
 * Version: 1.8.12
 * Author: LeadMuffin
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class Vote_Game_Plugin {
    private static $instance = null;
    private $table;
    private $adj2_table;
    private $region_adj2_table;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table             = $wpdb->prefix . 'vote_game_votes';
        $this->adj2_table        = $wpdb->prefix . 'vote_game_adjustments2';
        $this->region_adj2_table = $wpdb->prefix . 'vote_game_region_adjustments2';

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        add_action('init', array($this, 'register_cpt_tax'));
        add_action('rest_api_init', array($this, 'register_rest'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init_register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Hide the default post type from admin menu
        add_action('admin_menu', array($this, 'hide_vote_item_menu'), 999);

        add_shortcode('vote_game', array($this, 'shortcode_render'));
        add_shortcode('food_vote_game', array($this, 'shortcode_render')); // alias

        // Ajax preview for shortcode builder
        add_action('wp_ajax_do_shortcode', function(){
            if (!current_user_can('manage_options')) { wp_die(''); }
            $sc = isset($_POST['shortcode']) ? wp_unslash($_POST['shortcode']) : '';
            echo do_shortcode($sc);
            wp_die();
        });

        // AJAX fallbacks
        add_action('wp_ajax_vg_images', array($this, 'ajax_images'));
        add_action('wp_ajax_nopriv_vg_images', array($this, 'ajax_images'));
        add_action('wp_ajax_vg_vote', array($this, 'ajax_vote'));
        add_action('wp_ajax_nopriv_vg_vote', array($this, 'ajax_vote'));
        
        // Admin notice dismissal
        add_action('wp_ajax_picpoll_dismiss_welcome', array($this, 'ajax_dismiss_welcome'));
    }

    /* === Activation === */
    public function on_activate() {
        $this->create_tables();
        $opts = get_option('vg_options', array());
        if (empty($opts['regions_json'])) {
            $opts['regions_json'] = json_encode(array(
                array('code'=>'US','label'=>'United States'),
                array('code'=>'CA','label'=>'Canada'),
                array('code'=>'UK','label'=>'United Kingdom'),
                array('code'=>'JP','label'=>'Japan'),
            ));
        }
        if (empty($opts['option_labels_json'])) {
            $opts['option_labels_json'] = json_encode(array(array('text'=>'Vote Option 1')));
        }
        // Set default region mode to prompt
        if (!isset($opts['region_mode'])) {
            $opts['region_mode'] = 'prompt';
        }
        update_option('vg_options', $opts);
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            image_id BIGINT UNSIGNED NOT NULL,
            choice INT NOT NULL,
            country VARCHAR(10) NULL,
            ip VARCHAR(45) NULL,
            ua VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_image_ip (image_id, ip),
            INDEX idx_image (image_id),
            INDEX idx_country (country)
        ) $charset_collate;";
        dbDelta($sql1);

        $sql2 = "CREATE TABLE {$this->adj2_table} (
            image_id BIGINT UNSIGNED NOT NULL,
            option_index INT NOT NULL,
            adj INT NOT NULL DEFAULT 0,
            PRIMARY KEY (image_id, option_index)
        ) $charset_collate;";
        dbDelta($sql2);

        $sql3 = "CREATE TABLE {$this->region_adj2_table} (
            country VARCHAR(10) NOT NULL,
            image_id BIGINT UNSIGNED NOT NULL,
            option_index INT NOT NULL,
            adj INT NOT NULL DEFAULT 0,
            PRIMARY KEY (country, image_id, option_index)
        ) $charset_collate;";
        dbDelta($sql3);
    }

    /* === Content Model === */
    public function register_cpt_tax() {
        register_post_type('vote_item', array(
            'label' => __('Items','vote-game'),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => false, // Hide from main menu since we have our custom interface
            'supports' => array('title','thumbnail','excerpt'),
            'show_in_rest' => true,
            'has_archive' => false,
            'publicly_queryable' => false,
        ));
        register_taxonomy('vote_category', 'vote_item', array(
            'label' => __('Item Categories','vote-game'),
            'public' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'rewrite' => array('slug' => 'vote-category'),
            'show_in_rest' => true,
        ));
    }

    // Hide the default vote_item post type from WordPress admin menu
    public function hide_vote_item_menu() {
        remove_menu_page('edit.php?post_type=vote_item');
    }

    /* === REST === */
    public function register_rest() {
        register_rest_route('vote-game/v1', '/images', array(
            'methods'  => 'GET',
            'callback' => array($this, 'rest_images'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('vote-game/v1', '/vote', array(
            'methods'  => 'POST',
            'callback' => array($this, 'rest_vote'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('vote-game/v1', '/stats/(?P<image_id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array($this, 'rest_stats'),
            'permission_callback' => '__return_true'
        ));
    }

    public function get_option_labels() {
        $o = get_option('vg_options', array());
        $labels = array();
        if (!empty($o['option_labels_json'])) {
            $arr = json_decode($o['option_labels_json'], true);
            if (is_array($arr)) {
                foreach ($arr as $row) {
                    $t = is_array($row) ? (isset($row['text']) ? $row['text'] : '') : (string)$row;
                    $t = trim($t);
                    if ($t !== '') $labels[] = $t;
                }
            }
        }
        if (empty($labels)) $labels = array('Vote Option 1');
        return $labels;
    }

    private function counts_with_adjustments($image_id, $countryCode = null) {
        global $wpdb;
        $labels = $this->get_option_labels();
        $n = count($labels);
        $image_id = absint($image_id);
        $where = $wpdb->prepare("image_id = %d", $image_id);
        $params = array();
        if (!empty($countryCode)) {
            $countryCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$countryCode), 0, 10));
            $where .= $wpdb->prepare(" AND country = %s", $countryCode);
        }
        $rows = $wpdb->get_results("SELECT choice, COUNT(*) c FROM {$this->table} WHERE $where GROUP BY choice", ARRAY_A);
        $counts = array_fill(0, $n, 0);
        foreach ($rows as $r) {
            $i = intval($r['choice']);
            if ($i>=0 && $i<$n) $counts[$i] = intval($r['c']);
        }
        // adjustments
        if (!empty($countryCode)) {
            $rows2 = $wpdb->get_results($wpdb->prepare("SELECT option_index, adj FROM {$this->region_adj2_table} WHERE country=%s AND image_id=%d", $countryCode, $image_id), ARRAY_A);
        } else {
            $rows2 = $wpdb->get_results($wpdb->prepare("SELECT option_index, adj FROM {$this->adj2_table} WHERE image_id=%d", $image_id), ARRAY_A);
        }
        foreach ($rows2 as $r) {
            $idx = intval($r['option_index']); $adj = intval($r['adj']);
            if ($idx>=0 && $idx<$n) { $counts[$idx] += $adj; if ($counts[$idx] < 0) $counts[$idx] = 0; }
        }
        return $counts;
    }

    public function rest_images(WP_REST_Request $req) {
        $limit  = max(1, min(200, intval($req->get_param('limit') ?: 30)));
        $random = intval($req->get_param('random') ?: 1) === 1;
        $catParam = trim((string)$req->get_param('category'));

        $args = array(
            'post_type'      => 'vote_item',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => $random ? 'rand' : 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array('key' => '_thumbnail_id', 'compare' => 'EXISTS')
            )
        );
        if ($catParam !== '') {
            $slugs = array_filter(array_map('trim', explode(',', $catParam)));
            if (!empty($slugs)) {
                $args['tax_query'] = array(array(
                    'taxonomy' => 'vote_category',
                    'field'    => 'slug',
                    'terms'    => $slugs,
                    'operator' => 'IN',
                ));
            }
        }
        $q = new WP_Query($args);
        $items = array();
        foreach ($q->posts as $post) {
            $thumb_id = get_post_thumbnail_id($post);
            $url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
            $items[] = array(
                'id'      => $post->ID,
                'title'   => get_the_title($post),
                'url'     => $url,
                'excerpt' => get_the_excerpt($post),
            );
        }
        return new WP_REST_Response($items, 200);
    }

    public function rest_vote(WP_REST_Request $req) {
        global $wpdb;
        $image_id = absint($req->get_param('image_id'));
        $choice   = max(0, intval($req->get_param('choice')));
        $country  = strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$req->get_param('country')), 0, 10));
        if (!$country) { $o = get_option('vg_options', array()); if (!empty($o['region_mode']) && $o['region_mode']==='cloudflare') { $country = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/','', $_SERVER['HTTP_CF_IPCOUNTRY']),0,10)) : ''; } }
        if (!$image_id) return new WP_REST_Response(array('error'=>'Missing image_id'), 400);

        $o = get_option('vg_options', array());
        $require_login = !empty($o['require_login']);
        if ($require_login && !is_user_logged_in()) {
            return new WP_REST_Response(array('error'=>'login_required'), 403);
        }

        $ip = $this->get_ip();
        $ua = substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 255);
        $now = current_time('mysql');

        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table} (image_id, choice, country, ip, ua, created_at)
             VALUES (%d, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE choice=VALUES(choice), country=VALUES(country), ua=VALUES(ua), created_at=VALUES(created_at)",
            $image_id, $choice, $country, $ip, $ua, $now
        );
        $ok = $wpdb->query($sql);
        if ($ok === false) return new WP_REST_Response(array('error'=>'DB error'), 500);

        $regionsParam = (string)$req->get_param('regions');
        $regionsList = array_filter(array_map('trim', explode(',', $regionsParam)));

        $overall = $this->compute_stats($image_id, null);
        $regions = array();
        foreach ($regionsList as $code) {
            $regions[$code] = $this->compute_stats($image_id, $code);
        }
        return new WP_REST_Response(array('overall'=>$overall, 'regions'=>$regions), 200);
    }

    public function rest_stats(WP_REST_Request $req) {
        $image_id = absint($req['image_id']);
        if (!$image_id) return new WP_REST_Response(array('error'=>'Missing image_id'), 400);

        $regionsParam = (string)$req->get_param('regions');
        $regionsList = array_filter(array_map('trim', explode(',', $regionsParam)));

        $overall = $this->compute_stats($image_id, null);
        $regions = array();
        foreach ($regionsList as $code) {
            $regions[$code] = $this->compute_stats($image_id, $code);
        }
        return new WP_REST_Response(array('overall'=>$overall, 'regions'=>$regions), 200);
    }

    public function compute_stats($image_id, $countryCode = null) {
        $labels = $this->get_option_labels();
        $counts = $this->counts_with_adjustments($image_id, $countryCode);
        $total = array_sum($counts);
        $percent = $total>0 ? array_map(function($c) use($total){ return round($c*100/$total, 1); }, $counts) : array_fill(0,count($counts),0);
        return array('counts'=>$counts, 'percent'=>$percent, 'total'=>$total, 'labels'=>$labels);
    }

    /* === AJAX fallbacks === */
    public function ajax_images() {
        $limit = isset($_GET['limit']) ? max(1, min(200, intval($_GET['limit']))) : 30;
        $random = isset($_GET['random']) ? (intval($_GET['random'])===1) : true;
        $category = isset($_GET['category']) ? sanitize_text_field(wp_unslash($_GET['category'])) : '';
        $req = new WP_REST_Request('GET', '/vote-game/v1/images');
        $req->set_param('limit', $limit);
        $req->set_param('random', $random ? 1 : 0);
        $req->set_param('category', $category);
        $res = $this->rest_images($req);
        wp_send_json($res->get_data());
    }

    public function ajax_vote() {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) $payload = array();
        $req = new WP_REST_Request('POST', '/vote-game/v1/vote');
        foreach (array('image_id','choice','country','regions') as $k) {
            if (isset($payload[$k])) $req->set_param($k, $payload[$k]);
        }
        $res = $this->rest_vote($req);
        wp_send_json($res->get_data());
    }

    public function ajax_dismiss_welcome() {
        if (!wp_verify_nonce($_POST['nonce'], 'picpoll_dismiss_welcome')) {
            wp_die('Invalid nonce');
        }
        update_user_meta(get_current_user_id(), 'picpoll_welcome_dismissed', '1');
        wp_die();
    }

    /* === Admin === */
    public function admin_menu() {
        add_menu_page(__('PicPoll','vote-game'), __('PicPoll','vote-game'), 'manage_options', 'vg_settings', array($this, 'render_settings_styling'), 'dashicons-carrot', 56);

        add_submenu_page('vg_settings', __('Styling','vote-game'), __('Styling','vote-game'), 'manage_options', 'vg_settings', array($this, 'render_settings_styling'));
        add_submenu_page('vg_settings', __('Behavior','vote-game'), __('Behavior','vote-game'), 'manage_options', 'vg_behavior', array($this, 'render_settings_behavior'));
        add_submenu_page('vg_settings', __('Regions','vote-game'), __('Regions','vote-game'), 'manage_options', 'vg_regions', array($this, 'render_settings_regions'));
        
        // Change this line to point to our custom items page instead of the default post editor
        add_submenu_page('vg_settings', __('Items','vote-game'), __('Items','vote-game'), 'manage_options', 'vg_items', array($this, 'render_items_page'));
        
        add_submenu_page('vg_settings', __('Bulk Upload','vote-game'), __('Bulk Upload','vote-game'), 'manage_options', 'vg_upload', array($this, 'render_items_upload'));
        add_submenu_page('vg_settings', __('Vote Options','vote-game'), __('Vote Options','vote-game'), 'manage_options', 'vg_options', array($this, 'render_vote_options'));
        add_submenu_page('vg_settings', __('Shortcode Builder','vote-game'), __('Shortcode Builder','vote-game'), 'manage_options', 'vg_builder', array($this, 'render_shortcode_builder'));
        add_submenu_page('vg_settings', __('Results & Adjustments','vote-game'), __('Results & Adjustments','vote-game'), 'manage_options', 'vg_results', array($this, 'render_results_adjustments'));
        
        // Add PicPoll Pro upgrade page
        add_submenu_page('vg_settings', __('PicPoll Pro','vote-game'), __('PicPoll Pro','vote-game'), 'manage_options', 'vg_pro', array($this, 'render_pro_upgrade'));
    }

    public function render_items_page() {
        if (!current_user_can('manage_options')) return;
        
        // Handle form submission
        if (!empty($_POST['vg_item_nonce']) && wp_verify_nonce($_POST['vg_item_nonce'], 'vg_add_item')) {
            $this->handle_add_item();
        }
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['item_id']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_item_' . $_GET['item_id'])) {
            $this->handle_delete_item(absint($_GET['item_id']));
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Items', 'vote-game') . '</h1>';
        
        // Add new item form
        $this->render_add_item_form();
        
        // List existing items
        $this->render_items_list();
        
        echo '</div>';
    }

    private function render_add_item_form() {
        $categories = get_terms(array('taxonomy' => 'vote_category', 'hide_empty' => false));
        
        echo '<div class="card" style="max-width: 800px; margin-bottom: 20px;">';
        echo '<h2>' . esc_html__('Add New Item', 'vote-game') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('vg_add_item', 'vg_item_nonce');
        
        echo '<table class="form-table"><tbody>';
        
        // Title field
        echo '<tr>';
        echo '<th scope="row"><label for="item_title">' . esc_html__('Title', 'vote-game') . '</label></th>';
        echo '<td><input type="text" id="item_title" name="item_title" class="regular-text" required /></td>';
        echo '</tr>';
        
        // Excerpt field
        echo '<tr>';
        echo '<th scope="row"><label for="item_excerpt">' . esc_html__('Excerpt', 'vote-game') . '</label></th>';
        echo '<td><textarea id="item_excerpt" name="item_excerpt" rows="3" cols="50" class="large-text"></textarea>';
        echo '<p class="description">' . esc_html__('Optional short description of the item.', 'vote-game') . '</p></td>';
        echo '</tr>';
        
        // Image upload field
        echo '<tr>';
        echo '<th scope="row"><label for="item_image">' . esc_html__('Image', 'vote-game') . '</label></th>';
        echo '<td><input type="file" id="item_image" name="item_image" accept="image/*" required />';
        echo '<p class="description">' . esc_html__('Upload an image for this item.', 'vote-game') . '</p></td>';
        echo '</tr>';
        
        // Category field
        echo '<tr>';
        echo '<th scope="row"><label for="item_categories">' . esc_html__('Categories', 'vote-game') . '</label></th>';
        echo '<td>';
        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">';
            foreach ($categories as $category) {
                echo '<label style="display: block; margin-bottom: 5px;">';
                echo '<input type="checkbox" name="item_categories[]" value="' . esc_attr($category->term_id) . '" /> ';
                echo esc_html($category->name);
                echo '</label>';
            }
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('No categories available.', 'vote-game') . ' ';
            echo '<a href="' . admin_url('edit-tags.php?taxonomy=vote_category&post_type=vote_item') . '">' . esc_html__('Create categories', 'vote-game') . '</a></p>';
        }
        echo '<p class="description">' . esc_html__('Select one or more categories for this item.', 'vote-game') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</tbody></table>';
        
        submit_button(__('Add Item', 'vote-game'));
        echo '</form>';
        echo '</div>';
    }

    private function render_items_list() {
        $items = get_posts(array(
            'post_type' => 'vote_item',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        echo '<div class="card" style="max-width: none;">';
        echo '<h2>' . esc_html__('Existing Items', 'vote-game') . '</h2>';
        
        if (empty($items)) {
            echo '<p>' . esc_html__('No items found. Add your first item above.', 'vote-game') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Image', 'vote-game') . '</th>';
            echo '<th>' . esc_html__('Title', 'vote-game') . '</th>';
            echo '<th>' . esc_html__('Excerpt', 'vote-game') . '</th>';
            echo '<th>' . esc_html__('Categories', 'vote-game') . '</th>';
            echo '<th>' . esc_html__('Date', 'vote-game') . '</th>';
            echo '<th>' . esc_html__('Actions', 'vote-game') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($items as $item) {
                $thumb_id = get_post_thumbnail_id($item->ID);
                $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '';
                $categories = wp_get_post_terms($item->ID, 'vote_category');
                $category_names = !empty($categories) ? array_map(function($cat) { return $cat->name; }, $categories) : array();
                
                echo '<tr>';
                echo '<td style="width: 80px;">';
                if ($thumb_url) {
                    echo '<img src="' . esc_url($thumb_url) . '" alt="' . esc_attr(get_the_title($item)) . '" style="max-width: 60px; max-height: 60px; border-radius: 4px;" />';
                } else {
                    echo '<div style="width: 60px; height: 60px; background: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #666;">No image</div>';
                }
                echo '</td>';
                echo '<td><strong>' . esc_html(get_the_title($item)) . '</strong></td>';
                echo '<td>' . esc_html(get_the_excerpt($item)) . '</td>';
                echo '<td>' . esc_html(implode(', ', $category_names)) . '</td>';
                echo '<td>' . esc_html(get_the_date('Y-m-d H:i', $item)) . '</td>';
                echo '<td>';
                echo '<a href="' . get_edit_post_link($item->ID) . '" class="button button-small">' . esc_html__('Edit', 'vote-game') . '</a> ';
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=vg_items&action=delete&item_id=' . $item->ID),
                    'delete_item_' . $item->ID
                );
                echo '<a href="' . esc_url($delete_url) . '" class="button button-small" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this item?', 'vote-game')) . '\')">' . esc_html__('Delete', 'vote-game') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        }
        echo '</div>';
    }

    private function handle_add_item() {
        // Validate required fields
        if (empty($_POST['item_title'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Title is required.', 'vote-game') . '</p></div>';
            return;
        }
        
        if (empty($_FILES['item_image']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Image is required.', 'vote-game') . '</p></div>';
            return;
        }
        
        // Sanitize inputs
        $title = sanitize_text_field($_POST['item_title']);
        $excerpt = sanitize_textarea_field($_POST['item_excerpt']);
        $categories = isset($_POST['item_categories']) && is_array($_POST['item_categories']) 
            ? array_map('absint', $_POST['item_categories']) 
            : array();
        
        // Create the post
        $post_data = array(
            'post_type' => 'vote_item',
            'post_title' => $title,
            'post_excerpt' => $excerpt,
            'post_status' => 'publish'
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Error creating item: ', 'vote-game') . esc_html($post_id->get_error_message()) . '</p></div>';
            return;
        }
        
        // Handle image upload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $upload = wp_handle_upload($_FILES['item_image'], array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_delete_post($post_id, true);
            echo '<div class="notice notice-error"><p>' . esc_html__('Image upload error: ', 'vote-game') . esc_html($upload['error']) . '</p></div>';
            return;
        }
        
        // Create attachment
        $attachment_data = array(
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
            'post_mime_type' => $upload['type']
        );
        
        $attachment_id = wp_insert_attachment($attachment_data, $upload['file'], $post_id);
        
        if (!is_wp_error($attachment_id)) {
            $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            set_post_thumbnail($post_id, $attachment_id);
        }
        
        // Set categories
        if (!empty($categories)) {
            wp_set_post_terms($post_id, $categories, 'vote_category');
        }
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Item added successfully!', 'vote-game') . '</p></div>';
    }

    private function handle_delete_item($item_id) {
        if (!$item_id) return;
        
        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'vote_item') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Invalid item.', 'vote-game') . '</p></div>';
            return;
        }
        
        // Delete associated votes from database
        global $wpdb;
        $wpdb->delete($this->table, array('image_id' => $item_id));
        $wpdb->delete($this->adj2_table, array('image_id' => $item_id));
        $wpdb->delete($this->region_adj2_table, array('image_id' => $item_id));
        
        // Delete the post (this will also delete the featured image)
        $result = wp_delete_post($item_id, true);
        
        if ($result) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Item deleted successfully!', 'vote-game') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Error deleting item.', 'vote-game') . '</p></div>';
        }
    }

    public function admin_init_register_settings() {
        register_setting('vg_options', 'vg_options', array('sanitize_callback' => array($this, 'sanitize_options')));

        // Styling
        add_settings_section('vg_styling', __('Styling Options','vote-game'), function(){ echo '<p>Customize the front-end appearance.</p>'; }, 'vg_settings');
        add_settings_field('accent_color', __('Accent color','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['accent_color']) ? $o['accent_color'] : '#FF7150';
            echo '<input type="text" name="vg_options[accent_color]" value="'.esc_attr($val).'" class="regular-text" />';
        }, 'vg_settings', 'vg_styling');
        add_settings_field('bar_height', __('Bar height (px)','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['bar_height']) ? intval($o['bar_height']) : 12;
            echo '<input type="number" name="vg_options[bar_height]" value="'.esc_attr($val).'" min="6" max="40" />';
        }, 'vg_settings', 'vg_styling');
        add_settings_field('card_radius', __('Card radius (px)','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['card_radius']) ? intval($o['card_radius']) : 16;
            echo '<input type="number" name="vg_options[card_radius]" value="'.esc_attr($val).'" min="0" max="40" />';
        }, 'vg_settings', 'vg_styling');
        add_settings_field('title_size', __('Title size (px)','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['title_size']) ? intval($o['title_size']) : 18;
            echo '<input type="number" name="vg_options[title_size]" value="'.esc_attr($val).'" min="12" max="36" />';
        }, 'vg_settings', 'vg_styling');
        add_settings_field('border_thickness', __('Border thickness (px)','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['border_thickness']) ? intval($o['border_thickness']) : 4;
            echo '<input type="number" name="vg_options[border_thickness]" value="'.esc_attr($val).'" min="0" max="20" />';
        }, 'vg_settings', 'vg_styling');
        add_settings_field('font_family', __('Custom font family','vote-game'), function(){
            $o = get_option('vg_options', array()); $val = isset($o['font_family']) ? $o['font_family'] : '';
            echo '<input type="text" name="vg_options[font_family]" value="'.esc_attr($val).'" class="regular-text" placeholder="e.g., Inter, system-ui" />';
        }, 'vg_settings', 'vg_styling');

        // Regions - Fixed region options
        add_settings_section('vg_regions', __('Regions','vote-game'), function(){
            echo '<p>Region selection is configured for US, Canada, United Kingdom, and Japan.</p>';
        }, 'vg_regions');
        add_settings_field('regions_json', __('Regions list','vote-game'), array($this, 'render_regions_field'), 'vg_regions', 'vg_regions');
    }

    public function admin_assets($hook) {
        if (strpos($hook, 'vg_') !== false) {
            wp_enqueue_style('vg-admin-css', plugins_url('assets/vg-admin.css', __FILE__), array(), @filemtime(__DIR__.'/assets/vg-admin.css'));
            wp_enqueue_script('vg-admin-js', plugins_url('assets/vg-admin.js', __FILE__), array('jquery'), @filemtime(__DIR__.'/assets/vg-admin.js'), true);
            wp_enqueue_style('vg-front', plugins_url('assets/embeddable.css', __FILE__), array(), @filemtime(__DIR__.'/assets/embeddable.css'));
            wp_enqueue_script('vg-front', plugins_url('assets/embeddable.js', __FILE__), array(), @filemtime(__DIR__.'/assets/embeddable.js'), true);

        // Unique scope class and style variables
        $scope = 'fvg-scope-' . wp_generate_uuid4();
        $bar_h  = isset($o['bar_height']) ? intval($o['bar_height']) : 12;
        $radius = isset($o['card_radius']) ? intval($o['card_radius']) : 16;
        $title  = isset($o['title_size']) ? intval($o['title_size']) : 18;
        $border = isset($o['border_thickness']) ? intval($o['border_thickness']) : 4;
        $font   = isset($o['font_family']) ? $o['font_family'] : '';

        // Build HTML
        $style  = '<style>.' . esc_attr($scope) . ' { --fvg-accent: ' . esc_html($brand_color) . '; --fvg-bar-h: ' . esc_html($bar_h) . 'px; --fvg-card-radius: ' . esc_html($radius) . 'px; --fvg-title-size: ' . esc_html($title) . 'px; --fvg-border-thickness: ' . esc_html($border) . 'px; }';
        if (!empty($font)) { $style .= '.' . esc_attr($scope) . ' .fvg-wrap{ font-family: ' . esc_html($font) . '; }'; }
        $style .= '</style>';

        $div  = '<div class="fvg-embed"';
        $div .= ' data-scope="' . esc_attr($scope) . '"';
        $div .= ' data-limit="' . esc_attr(intval($atts['limit'])) . '"';
        $div .= ' data-random="' . esc_attr(intval($atts['random'])) . '"';
        $div .= ' data-brand-color="' . esc_attr($brand_color) . '"';
        $div .= ' data-category="' . esc_attr($atts['category']) . '"';
        $div .= ' data-show-excerpt="' . esc_attr(intval($atts['show_excerpt'])) . '" data-region-mode="prompt"';
        $div .= ' data-regions="' . esc_attr(wp_json_encode($regions_map)) . '">';
        $div .= '<div class="fvg-loading" style="padding:12px;color:#555;">Loading...</div>';
        $div .= '</div>';

        $api  = esc_js(esc_url_raw(get_rest_url(null, 'vote-game/v1/')));
        $ajax = esc_js(admin_url('admin-ajax.php'));
        $script_cfg = '<script>window.FVG_SHORTCODE_CFG=window.FVG_SHORTCODE_CFG||{};(function(cfg){cfg.api="' . $api . '"; cfg.ajax="' . $ajax . '";})(window.FVG_SHORTCODE_CFG);</script>';

        // Options JSON
        $labels = $this->get_option_labels();
        $opts_out = array(
            'title_size'   => isset($o['title_size']) ? intval($o['title_size']) : 18,
            'min_sample'   => 0,
            'hide_everyone'=> 0,
            'font_family'  => isset($o['font_family']) ? $o['font_family'] : '',
            'option_labels'=> $labels,
            'region_mode' => 'prompt',
        );
        $__vg_json = wp_json_encode($opts_out);
        $script_opts = '<script id="vg-options-json" type="application/json">'.$__vg_json.'</script>';

        return $style . $div . $script_cfg . $script_opts;
    }

    private function get_ip() {
        foreach (array('HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR') as $k) {
            if (!empty($_SERVER[$k])) {
                $ip_list = explode(',', $_SERVER[$k]);
                return trim($ip_list[0]);
            }
        }
        return '';
    }
}

Vote_Game_Plugin::instance();__), array(), @filemtime(__DIR__.'/assets/embeddable.js'), true);
        }
    }

    public function admin_notices() {
        // Show welcome notice on PicPoll admin pages
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'vg_') !== false) {
            // Check if user hasn't dismissed this notice
            $dismissed = get_user_meta(get_current_user_id(), 'picpoll_welcome_dismissed', true);
            if (!$dismissed) {
                echo '<div class="notice notice-info is-dismissible" id="picpoll-welcome-notice">';
                echo '<p><strong>Thanks for installing PicPoll!</strong> This is my first plugin and I\'m open to feature suggestions and I\'m actively fixing bugs. If you\'re having issues, kindly email <a href="mailto:hi@leadmuffin.com">hi@leadmuffin.com</a> and I\'ll be happy to help.</p>';
                echo '</div>';
                echo '<script>
                jQuery(document).ready(function($) {
                    $(document).on("click", "#picpoll-welcome-notice .notice-dismiss", function() {
                        $.post(ajaxurl, {
                            action: "picpoll_dismiss_welcome",
                            nonce: "'.wp_create_nonce('picpoll_dismiss_welcome').'"
                        });
                    });
                });
                </script>';
            }
        }
    }

    public function render_regions_field() {
        $o = get_option('vg_options', array());
        $json = isset($o['regions_json']) && $o['regions_json'] ? $o['regions_json'] : json_encode(array(
            array('code'=>'US','label'=>'United States'),
            array('code'=>'CA','label'=>'Canada'),
            array('code'=>'UK','label'=>'United Kingdom'),
            array('code'=>'JP','label'=>'Japan'),
        ));
        echo '<input type="hidden" id="vg_regions_json" name="vg_options[regions_json]" value="'.esc_attr($json).'">';
        echo '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 6px;">';
        echo '<p><strong>Fixed Regions:</strong></p>';
        echo '<ul style="margin: 0; padding-left: 20px;">';
        echo '<li>US - United States</li>';
        echo '<li>CA - Canada</li>';
        echo '<li>UK - United Kingdom</li>';
        echo '<li>JP - Japan</li>';
        echo '</ul>';
        echo '<p style="margin-top: 10px; color: #666; font-style: italic;">Region mode is set to "Prompt user at start"</p>';
        echo '</div>';
    }

    public function sanitize_options($opts) {
        $prev = get_option('vg_options', array());
        $out = $prev;

        if (isset($opts['accent_color'])) $out['accent_color'] = sanitize_hex_color($opts['accent_color']);
        if (isset($opts['bar_height']))   $out['bar_height'] = max(6, min(40, intval($opts['bar_height'])));
        if (isset($opts['card_radius']))  $out['card_radius'] = max(0, min(40, intval($opts['card_radius'])));
        if (isset($opts['title_size']))   $out['title_size'] = max(12, min(36, intval($opts['title_size'])));
        if (isset($opts['border_thickness'])) $out['border_thickness'] = max(0, min(20, intval($opts['border_thickness'])));
        if (isset($opts['font_family']))  $out['font_family'] = sanitize_text_field($opts['font_family']);

        // Force specific settings
        $out['unique_ip'] = 1;
        $out['require_login'] = 0;
        $out['hide_everyone'] = 0;
        $out['region_mode'] = 'prompt';
        $out['min_sample'] = 0;

        // Force specific regions
        $out['regions_json'] = json_encode(array(
            array('code'=>'US','label'=>'United States'),
            array('code'=>'CA','label'=>'Canada'),
            array('code'=>'UK','label'=>'United Kingdom'),
            array('code'=>'JP','label'=>'Japan'),
        ));

        if (isset($opts['option_labels_json'])) $out['option_labels_json'] = wp_kses_post($opts['option_labels_json']);
        if (empty($out['option_labels_json'])) $out['option_labels_json'] = json_encode(array(array('text'=>'Vote Option 1')));
        if (!isset($out['border_thickness'])) $out['border_thickness'] = 4;

        return $out;
    }

    public function render_settings_styling() {
        echo '<div class="wrap"><h1>'.esc_html__('Styling Options','vote-game').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('vg_options'); do_settings_sections('vg_settings'); submit_button();
        echo '</form></div>';
    }
    
    public function render_settings_behavior() {
        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Behavior Settings','vote-game').'</h1>';
        echo '<div style="text-align: center; padding: 40px;">';
        echo '<img src="'.plugins_url('assets/behavior.png', __FILE__).'" alt="Behavior Settings" style="max-width: 100%; height: auto;" />';
        echo '</div>';
        echo '</div>';
    }
    
    public function render_settings_regions() {
        echo '<div class="wrap"><h1>'.esc_html__('Regions','vote-game').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('vg_options'); do_settings_sections('vg_regions'); submit_button();
        echo '</form></div>';
    }

    public function render_vote_options() {
        $o = get_option('vg_options', array());
        $json = isset($o['option_labels_json']) && $o['option_labels_json'] ? $o['option_labels_json'] : json_encode(array(array('text'=>'Vote Option 1')));
        echo '<div class="wrap"><h1>'.esc_html__('Vote Options','vote-game').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('vg_options');
        echo '<input type="hidden" id="vg_option_labels_json" name="vg_options[option_labels_json]" value="'.esc_attr($json).'">';
        echo '<div id="vg-options-ui"></div>';
        submit_button();
        echo '</form>';
        echo '<script type="text/template" id="vg-opt-row-tpl">
            <div class="vg-row">
              <input class="vg-opt-label" type="text" placeholder="Vote Option 1 Text">
              <button type="button" class="button vg-add">+</button>
              <button type="button" class="button vg-del">−</button>
            </div>
        </script>';
        echo '<script>
        (function(){
            function parseJSON(s){try{return JSON.parse(s||"[]")}catch(e){return []}}
            var input = document.getElementById("vg_option_labels_json");
            var data = parseJSON(input.value);
            var host = document.getElementById("vg-options-ui");
            function row(text){
                var tpl = document.getElementById("vg-opt-row-tpl").textContent;
                var div = document.createElement("div"); div.innerHTML = tpl.trim();
                var el = div.firstChild;
                el.querySelector(".vg-opt-label").value = text||"";
                el.querySelector(".vg-add").addEventListener("click", function(){ host.appendChild(row("")); sync(); });
                el.querySelector(".vg-del").addEventListener("click", function(){ el.remove(); sync(); });
                el.querySelector(".vg-opt-label").addEventListener("input", sync);
                return el;
            }
            function sync(){
                var arr = []; host.querySelectorAll(".vg-row").forEach(function(r){
                    var t = r.querySelector(".vg-opt-label").value.trim();
                    if (t) arr.push({text:t});
                });
                if (!arr.length) arr = [{text:"Vote Option 1"}];
                input.value = JSON.stringify(arr);
            }
            host.innerHTML = "";
            if (!data.length) data = [{text:"Vote Option 1"}];
            data.forEach(function(it){ host.appendChild(row(it.text)); });
        })();
        </script>';
        echo '</div>';
    }

    public function render_items_upload() {
        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Bulk Upload','vote-game').'</h1>';
        echo '<div style="text-align: center; padding: 40px;">';
        echo '<img src="'.plugins_url('assets/bulk.png', __FILE__).'" alt="Bulk Upload" style="max-width: 100%; height: auto;" />';
        echo '</div>';
        echo '</div>';
    }

    public function render_shortcode_builder() {
        if (!current_user_can('manage_options')) return;
        $o = get_option('vg_options', array());
        $regions = array(
            array('code'=>'US','label'=>'United States'),
            array('code'=>'CA','label'=>'Canada'),
            array('code'=>'UK','label'=>'United Kingdom'),
            array('code'=>'JP','label'=>'Japan'),
        );
        $cats = get_terms(array('taxonomy'=>'vote_category','hide_empty'=>false));
        echo '<div class="wrap"><h1>'.esc_html__('Shortcode Builder','vote-game').'</h1>';
        echo '<div class="card" style="max-width:880px;padding:16px;">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Limit</th><td><input type="number" id="b_limit" value="30" min="1" max="200"> <span class="description">How many items to show.</span></td></tr>';
        echo '<tr><th>Order</th><td><label><input type="checkbox" id="b_random" checked> Randomize</label></td></tr>';
        echo '<tr><th>Accent color</th><td><input type="text" id="b_color" value="#FF7150"></td></tr>';
        echo '<tr><th>Show excerpt</th><td><label><input type="checkbox" id="b_show_excerpt" checked> Show excerpt under the title</label></td></tr>';
        echo '<tr><th>Category filter</th><td>';
        if (!empty($cats) && !is_wp_error($cats)) {
            foreach ($cats as $t) {
                $slug = esc_attr($t->slug); $name = esc_html($t->name);
                echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" class="b_cat" value="'.$slug.'"> '.$name.'</label>';
            }
        } else {
            echo '<em>No categories yet.</em>';
        }
        echo '</td></tr>';
        echo '<tr><th>Regions to include</th><td>';
        foreach ($regions as $r) {
            $code = esc_html($r['code']); $label = esc_html($r['label']);
            echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" class="b_region" value="'.$code.'"> '.$label.' ('.$code.')</label>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p><strong>Shortcode:</strong> <code id="b_sc"></code> <button type="button" class="button" id="b_copy">Copy</button></p>';
        echo '<p><button type="button" class="button button-primary" id="b_preview">Preview Below</button></p>';
        echo '<div id="vg-preview" style="background:#fff; padding:12px; border:1px solid #e5e5e5; border-radius:8px;">(preview will appear here)</div>';
        echo '</div>';
        echo '<script>
          (function(){
            function build(){
              var limit = document.getElementById("b_limit").value||30;
              var random= document.getElementById("b_random").checked ? 1 : 0;
              var color = document.getElementById("b_color").value||"#FF7150";
              var showex= document.getElementById("b_show_excerpt").checked ? 1 : 0;
              var cats = Array.from(document.querySelectorAll(".b_cat:checked")).map(function(el){return el.value;}).join(",");
              var regs = Array.from(document.querySelectorAll(".b_region:checked")).map(function(el){
                return el.value + ":" + el.parentNode.textContent.trim().replace(/ \\(.+\\)$/, "");
              }).join("|");
              var sc = "[vote_game limit=\\"" + limit + "\\" random=\\"" + random + "\\" color=\\"" + color + "\\" show_excerpt=\\"" + showex + "\\"";
              if (cats) sc += " category=\\"" + cats.replace(/\\\"/g,"&quot;") + "\\"";
              if (regs) sc += " regions=\\"" + regs.replace(/\\\"/g,"&quot;") + "\\"";
              sc += "]";
              document.getElementById("b_sc").textContent = sc;
              return sc;
            }
            ["b_limit","b_random","b_color","b_show_excerpt"].forEach(function(id){
              document.getElementById(id).addEventListener("input", build);
              document.getElementById(id).addEventListener("change", build);
            });
            Array.from(document.querySelectorAll(".b_cat")).forEach(function(cb){ cb.addEventListener("change", build); });
            Array.from(document.querySelectorAll(".b_region")).forEach(function(cb){ cb.addEventListener("change", build); });
            build();
            document.getElementById("b_copy").addEventListener("click", function(){
              navigator.clipboard.writeText(document.getElementById("b_sc").textContent);
            });
            document.getElementById("b_preview").addEventListener("click", function(){
              var sc = build();
              var data = new FormData();
              data.append("action", "do_shortcode");
              data.append("shortcode", sc);
              fetch(ajaxurl, {method:"POST", body:data, credentials:"same-origin"}).then(function(r){return r.text();}).then(function(html){
                document.getElementById("vg-preview").innerHTML = html;
                if (window.FVG_initEmbeds) { try{ window.FVG_initEmbeds(); }catch(e){} }
              });
            });
          })();
        </script>';
        echo '</div>';
    }

    public function render_results_adjustments() {
        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Results & Adjustments','vote-game').'</h1>';
        echo '<div style="text-align: center; padding: 40px;">';
        echo '<img src="'.plugins_url('assets/adjustments.png', __FILE__).'" alt="Results & Adjustments" style="max-width: 100%; height: auto;" />';
        echo '</div>';
        echo '</div>';
    }

    public function render_pro_upgrade() {
        echo '<div class="wrap">';
        echo '<h1 style="color: #2d3748; font-weight: 600; margin-bottom: 30px;">PicPoll Pro</h1>';
        
        echo '<div class="card" style="max-width: 800px; padding: 30px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">';
        echo '<h2 style="color: white; font-size: 28px; margin-bottom: 10px;">Upgrade to PicPoll Pro</h2>';
        echo '<p style="font-size: 18px; color: rgba(255,255,255,0.9); margin-bottom: 20px;">Unlock powerful features to enhance your voting games</p>';
        echo '<div style="font-size: 48px; font-weight: bold; margin: 20px 0; color: #ffd700;">$9.99</div>';
        echo '<p style="font-size: 16px; color: rgba(255,255,255,0.8); margin-bottom: 30px;">One-time payment • Lifetime updates</p>';
        echo '<a href="https://leadmuffin.com/plugins/picpoll" target="_blank" class="button" style="background: #ffd700; color: #333; border: none; padding: 15px 30px; font-size: 18px; font-weight: 600; border-radius: 50px; text-decoration: none; display: inline-block; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(255,215,0,0.3);">Upgrade Now →</a>';
        echo '</div>';
        
        echo '<div class="card" style="max-width: 800px; padding: 25px; margin-top: 20px;">';
        echo '<h3 style="color: #2d3748; font-size: 22px; margin-bottom: 20px; text-align: center;">Pro Features</h3>';
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">';
        
        // Feature 1
        echo '<div style="padding: 20px; border: 2px solid #e2e8f0; border-radius: 10px; text-align: center;">';
        echo '<div style="width: 50px; height: 50px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 20px;">⚙️</div>';
        echo '<h4 style="color: #2d3748; margin-bottom: 10px;">Advanced Behavior Settings</h4>';
        echo '<p style="color: #4a5568; font-size: 14px; margin: 0;">Control voting requirements, IP limits, login requirements, minimum sample sizes, and regional detection modes.</p>';
        echo '</div>';
        
        // Feature 2
        echo '<div style="padding: 20px; border: 2px solid #e2e8f0; border-radius: 10px; text-align: center;">';
        echo '<div style="width: 50px; height: 50px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 20px;">📤</div>';
        echo '<h4 style="color: #2d3748; margin-bottom: 10px;">Bulk CSV Upload</h4>';
        echo '<p style="color: #4a5568; font-size: 14px; margin: 0;">Upload hundreds of items at once using CSV files with automatic image importing and category assignment.</p>';
        echo '</div>';
        
        // Feature 3
        echo '<div style="padding: 20px; border: 2px solid #e2e8f0; border-radius: 10px; text-align: center;">';
        echo '<div style="width: 50px; height: 50px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 20px;">📊</div>';
        echo '<h4 style="color: #2d3748; margin-bottom: 10px;">Results & Adjustments</h4>';
        echo '<p style="color: #4a5568; font-size: 14px; margin: 0;">View detailed voting statistics and manually adjust vote counts for testing or balancing purposes.</p>';
        echo '</div>';
        
        // Feature 4
        echo '<div style="padding: 20px; border: 2px solid #e2e8f0; border-radius: 10px; text-align: center;">';
        echo '<div style="width: 50px; height: 50px; background: #667eea; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; color: white; font-size: 20px;">🌍</div>';
        echo '<h4 style="color: #2d3748; margin-bottom: 10px;">Custom Region Management</h4>';
        echo '<p style="color: #4a5568; font-size: 14px; margin: 0;">Create unlimited custom regions with full geographic targeting and Cloudflare integration support.</p>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        
        echo '<div class="card" style="max-width: 800px; padding: 25px; margin-top: 20px; text-align: center; background: #f7fafc;">';
        echo '<h3 style="color: #2d3748; margin-bottom: 15px;">Need Help?</h3>';
        echo '<p style="color: #4a5568; margin-bottom: 20px;">Questions about PicPoll Pro? Get in touch with our support team.</p>';
        echo '<a href="mailto:hi@leadmuffin.com" class="button" style="background: #2d3748; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">Contact Support</a>';
        echo '</div>';
        
        echo '</div>';
    }

    /* === Shortcode === */
    public function shortcode_render($atts = array(), $content = '') {
        $atts = shortcode_atts(array(
            'limit'   => 30,
            'random'  => 1,
            'color'   => '',
            'category'=> '',
            'regions' => '',
            'show_excerpt' => 1,
        ), $atts, 'vote_game');

        $o = get_option('vg_options', array());

        // Fixed regions map
        $regions_map = array(
            'US' => 'United States',
            'CA' => 'Canada', 
            'UK' => 'United Kingdom',
            'JP' => 'Japan'
        );
        
        if (!empty($atts['regions'])) {
            $regions_map = array();
            $parts = array_filter(array_map('trim', explode('|', $atts['regions'])));
            foreach ($parts as $p) {
                if (strpos($p, ':') !== false) {
                    list($code, $label) = array_map('trim', explode(':', $p, 2));
                    if ($code && $label) $regions_map[$code] = $label;
                }
            }
        }

        $brand_color = !empty($atts['color']) ? $atts['color'] : (isset($o['accent_color']) ? $o['accent_color'] : '#FF7150');

        // Enqueue assets only here
        wp_enqueue_style('vg-front', plugins_url('assets/embeddable.css', __FILE__), array(), @filemtime(__DIR__.'/assets/embeddable.css'));
        wp_enqueue_script('vg-front', plugins_url('assets/embeddable.js', __FILE
