<?php
/**
 * Plugin Name: Post Import Export
 * Description: Export and import posts/pages with their settings and HTML content
 * Version: 1.0.0
 * Author: Gary Angelone Jr.
 */

class Post_Import_Export {

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('wp_ajax_pie_export_post', array($this, 'ajax_export_post'));
        add_action('wp_ajax_pie_import_post', array($this, 'ajax_import_post'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_meta_box() {
        $screens = array('post', 'page');
        
        foreach ($screens as $screen) {
            add_meta_box(
                'post_import_export_metabox',
                __('Import/Export Post', 'post-import-export'),
                array($this, 'render_meta_box'),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post) {
        ?>
        <div class="pie-metabox">
            <div class="pie-section">
                <a href="#" id="pie-export" class="button button-primary button-large" data-post-id="<?php echo $post->ID; ?>" data-nonce="<?php echo wp_create_nonce('export_post_' . $post->ID); ?>">
                    <span class="dashicons dashicons-download"></span> Export
                </a>
            </div>
            
            <div class="pie-divider"></div>
            
            <div class="pie-section">
                <input type="file" id="pie-import-file" accept=".json" style="display:none;">
                <input type="hidden" id="pie-post-id" value="<?php echo $post->ID; ?>">
                <?php wp_nonce_field('import_post_' . $post->ID, 'pie_import_nonce'); ?>

                <label for="pie-import-file" class="button button-secondary button-large">
                    <span class="dashicons dashicons-upload"></span> Import
                </label>
                <div style="margin-top:8px;">
                    <label><input type="checkbox" id="pie-replace-url" checked> Replace URLs</label>
                    <span>&nbsp;</span>
                    <label><input type="checkbox" id="pie-import-images" checked> Import images</label>
                </div>
            </div>
        </div>
        <style>
            .pie-metabox {
                padding: 12px;
            }
            .pie-section {
                margin-bottom: 12px;
            }
            .pie-section:last-child {
                margin-bottom: 0;
            }
            .pie-section .button {
                width: 100%;
                height: auto;
                padding: 8px 12px;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }
            .pie-section .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            .pie-divider {
                height: 1px;
                background: #ddd;
                margin: 12px 0;
            }
        </style>
        <?php
    }

    public function ajax_export_post() {
        if (!isset($_POST['post_id']) || !isset($_POST['nonce'])) {
            wp_send_json_error(array('message' => 'Missing parameters'));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        
        if (!wp_verify_nonce($_POST['nonce'], 'export_post_' . $post_id) || !current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        $this->export_post($post_id);
    }
    
    public function ajax_import_post() {
        if (!isset($_POST['post_id'])) {
            wp_send_json_error(array('message' => 'No post ID provided'));
            return;
        }
        $post_id = intval($_POST['post_id']);
        if (!isset($_POST['import_post_nonce'])) {
            wp_send_json_error(array('message' => 'No nonce provided'));
            return;
        }
        if (!wp_verify_nonce($_POST['import_post_nonce'], 'import_post_' . $post_id)) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        $this->import_post($post_id);
        // If import_post does not exit, send error
        wp_send_json_error(array('message' => 'Import handler did not return a response'));
    }

    public function export_post($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            wp_die('Post not found');
        }

        $export_data = array(
            'site_url' => get_site_url(),
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
            'post_name' => $post->post_name,
            'post_author' => $post->post_author,
            'post_date' => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'post_password' => $post->post_password,
            'menu_order' => $post->menu_order,
            'post_meta' => array(),
            'taxonomies' => array(),
            'featured_image' => '',
        );

        $post_meta = get_post_meta($post_id);
        foreach ($post_meta as $key => $values) {
            if (!in_array($key, array('_edit_lock', '_edit_last', '_encloseme'))) {
                $export_data['post_meta'][$key] = $values;
            }
        }

        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            if (!is_wp_error($terms) && !empty($terms)) {
                $export_data['taxonomies'][$taxonomy] = array_map(function($term) {
                    return array(
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }, $terms);
            }
        }

        if (has_post_thumbnail($post_id)) {
            $export_data['featured_image'] = get_the_post_thumbnail_url($post_id, 'full');
            $export_data['featured_image_id'] = get_post_thumbnail_id($post_id);
        }

        $filename = sanitize_title($post->post_title) . '-' . date('Y-m-d-H-i-s') . '.json';
        $json = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public function import_post($post_id) {
    error_log('Post Import Export: import_post called for post_id ' . $post_id);
        if (!isset($_FILES['import_file'])) {
            wp_send_json_error(array('message' => 'No file uploaded'));
            return;
        }
        if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            error_log('Post Import Export: File upload error code: ' . $_FILES['import_file']['error']);
            wp_send_json_error(array('message' => 'File upload error code: ' . $_FILES['import_file']['error']));
            return;
        }
        $json_content = file_get_contents($_FILES['import_file']['tmp_name']);
        if ($json_content === false) {
            error_log('Post Import Export: Failed to read uploaded file');
            wp_send_json_error(array('message' => 'Failed to read uploaded file'));
            return;
        }
        $import_data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Post Import Export: Invalid JSON file: ' . json_last_error_msg());
            wp_send_json_error(array('message' => 'Invalid JSON file: ' . json_last_error_msg()));
            return;
        }
        if (!isset($import_data['post_title'])) {
            error_log('Post Import Export: Missing post_title in import data');
            wp_send_json_error(array('message' => 'Missing post_title in import data'));
            return;
        }
        error_log('Post Import Export: JSON parsed, post_title: ' . $import_data['post_title']);
    error_log('Post Import Export: Updating post...');
    error_log('Post Import Export: Post updated, checking for image import...');
    error_log('Post Import Export: Import complete, sending success response.');

        $old_site_url = isset($import_data['site_url']) ? $import_data['site_url'] : '';
        $new_site_url = get_site_url();
        $replace_url = isset($_POST['replace_url']) && $_POST['replace_url'] === '1';
        $import_images = isset($_POST['import_images']) && $_POST['import_images'] === '1';

        // Helper function to replace URLs recursively
        $replace_site_url = function($value) use ($old_site_url, $new_site_url, $replace_url) {
            if (!$replace_url) return $value;
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $replace_site_url($v);
                }
                return $value;
            } elseif (is_string($value) && $old_site_url) {
                return str_replace($old_site_url, $new_site_url, $value);
            }
            return $value;
        };

        $post_content = $replace_site_url($import_data['post_content']);
        if ($import_images) {
            // Find all image URLs in post_content (normal and escaped slashes)
            error_log('Post Import Export: Raw post_content: ' . $post_content);
            $pattern = '/https?:\\*\/\\*\/[^"\s\[\]]+\.(jpg|jpeg|png|gif)/i';
            $pattern2 = '/https?:\/\/[^"\s\[\]]+\.(jpg|jpeg|png|gif)/i';
            preg_match_all($pattern, $post_content, $matches1);
            preg_match_all($pattern2, $post_content, $matches2);
            $all_urls = array_unique(array_merge($matches1[0], $matches2[0]));
            error_log('Post Import Export: Found image URLs in post_content: ' . print_r($all_urls, true));
            foreach ($all_urls as $img_url) {
                $clean_url = str_replace(['\\/', '\\'], ['/', ''], $img_url);
                // If the image URL is not from the original site, reconstruct it
                $orig_host = parse_url($old_site_url, PHP_URL_HOST);
                $img_host = parse_url($clean_url, PHP_URL_HOST);
                if ($img_host !== $orig_host) {
                    // Replace host with original site host
                    $orig_scheme = parse_url($old_site_url, PHP_URL_SCHEME) ?: 'https';
                    $img_path = parse_url($clean_url, PHP_URL_PATH);
                    $clean_url = $orig_scheme . '://' . $orig_host . $img_path;
                    error_log('Post Import Export: Reconstructed original image URL: ' . $clean_url);
                }
                error_log('Post Import Export: Processing image URL: ' . $clean_url);
                $new_img_id = $this->pie_import_image($clean_url);
                if ($new_img_id && !is_wp_error($new_img_id)) {
                    $new_img_url = wp_get_attachment_url($new_img_id);
                    if ($new_img_url) {
                        $post_content = str_replace($img_url, $new_img_url, $post_content);
                        $post_content = str_replace($clean_url, $new_img_url, $post_content);
                    }
                }
            }
        }
        $post_data = array(
            'ID' => $post_id,
            'post_title' => $import_data['post_title'],
            'post_content' => $post_content,
            'post_excerpt' => $replace_site_url($import_data['post_excerpt']),
            'post_status' => $import_data['post_status'],
            'post_name' => $import_data['post_name'],
            'comment_status' => $import_data['comment_status'],
            'ping_status' => $import_data['ping_status'],
            'post_password' => $import_data['post_password'],
            'menu_order' => $import_data['menu_order'],
        );
        wp_update_post($post_data);

        wp_send_json_success(array('message' => 'Import completed successfully'));
    }

    // Download and import images from the old site
    private function pie_import_images_from_old_site($import_data, $old_site_url, $new_site_url) {
        // Example: download featured image and attach to post
        if (!empty($import_data['featured_image'])) {
            $image_url = $import_data['featured_image'];
            // Only import if image is from old site
            if (strpos($image_url, $old_site_url) === 0) {
                $this->pie_import_image($image_url);
            }
        }
    }

    // Download and add image to media library
    private function pie_import_image($image_url) {
        error_log('Post Import Export: Attempting to import image: ' . $image_url);
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            error_log('Post Import Export: download_url failed: ' . $tmp->get_error_message());
            return false;
        }
        $file_name = basename($image_url);
        if (!file_exists($tmp)) {
            error_log('Post Import Export: Downloaded file does not exist: ' . $tmp);
            return false;
        }
        $file_size = filesize($tmp);
        $file_hash = md5_file($tmp);

        // Search for any existing image in media library by file size and hash
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
        );
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $path = get_attached_file($post->ID);
                if ($path && file_exists($path)) {
                    $existing_size = filesize($path);
                    $existing_hash = md5_file($path);
                    if ($existing_size == $file_size && $existing_hash === $file_hash) {
                        error_log('Post Import Export: Image already exists in media library (size/hash match): ' . $path);
                        @unlink($tmp);
                        return $post->ID;
                    }
                }
            }
        }

        $file_array = array();
        $file_array['name'] = $file_name;
        $file_array['tmp_name'] = $tmp;
        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            error_log('Post Import Export: media_handle_sideload failed: ' . $id->get_error_message());
            @unlink($tmp);
            return false;
        }
        error_log('Post Import Export: Image imported successfully, attachment ID: ' . $id);
        return $id;
    }


    public function enqueue_scripts($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                var ajaxurl = '" . admin_url('admin-ajax.php') . "';
                
                $('#pie-export').on('click', function(e) {
                    e.preventDefault();
                    var form = $('<form>', {method: 'POST', action: ajaxurl});
                    form.append($('<input>', {type: 'hidden', name: 'action', value: 'pie_export_post'}));
                    form.append($('<input>', {type: 'hidden', name: 'post_id', value: $(this).data('post-id')}));
                    form.append($('<input>', {type: 'hidden', name: 'nonce', value: $(this).data('nonce')}));
                    $('body').append(form);
                    form.submit();
                    form.remove();
                });
                
                $('#pie-import-file').on('change', function(e) {
                    var file = e.target.files[0];
                    if (!file) return;
                    var formData = new FormData();
                    formData.append('action', 'pie_import_post');
                    formData.append('post_id', $('#pie-post-id').val());
                    formData.append('import_post_nonce', $('#pie_import_nonce').val());
                    formData.append('import_file', file);
                    formData.append('replace_url', $('#pie-replace-url').is(':checked') ? '1' : '0');
                    formData.append('import_images', $('#pie-import-images').is(':checked') ? '1' : '0');
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success) {
                                window.location.reload();
                            } else {
                                console.log('Import failed response:', response);
                                alert('Import failed');
                            }
                        },
                        error: function() {
                            alert('Import failed');
                        }
                    });
                });
            });
        ");
    }
}

new Post_Import_Export();
