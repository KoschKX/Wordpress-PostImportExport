<?php
/**
 * Plugin Name: Post Import Export
 * Description: Export and import posts/pages with their settings and HTML content
 * Version: 1.0.0
 * Author: Gary Angelone Jr.
 */

if (!defined('ABSPATH')) {
    exit;
}

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
    }

    public function export_post($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            wp_die('Post not found');
        }

        $export_data = array(
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
        if (!isset($_FILES['import_file'])) {
            wp_die('No file uploaded');
        }
        
        if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('File upload error code: ' . $_FILES['import_file']['error']);
        }
        
        $json_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $import_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_die('Invalid JSON file');
        }

        $post_data = array(
            'ID' => $post_id,
            'post_title' => $import_data['post_title'],
            'post_content' => $import_data['post_content'],
            'post_excerpt' => $import_data['post_excerpt'],
            'post_status' => $import_data['post_status'],
            'post_name' => $import_data['post_name'],
            'comment_status' => $import_data['comment_status'],
            'ping_status' => $import_data['ping_status'],
            'post_password' => $import_data['post_password'],
            'menu_order' => $import_data['menu_order'],
        );

        wp_update_post($post_data);

        $existing_meta = get_post_meta($post_id);
        foreach ($existing_meta as $key => $value) {
            if (!in_array($key, array('_edit_lock', '_edit_last', '_encloseme'))) {
                delete_post_meta($post_id, $key);
            }
        }
        
        if (isset($import_data['post_meta'])) {
            foreach ($import_data['post_meta'] as $key => $values) {
                if (in_array($key, array('_edit_lock', '_edit_last', '_encloseme'))) {
                    continue;
                }
                
                foreach ($values as $value) {
                    if (is_serialized($value)) {
                        $unserialized_value = unserialize($value);
                    } else {
                        $unserialized_value = $value;
                    }
                    add_post_meta($post_id, $key, $unserialized_value);
                }
            }
        }

        if (isset($import_data['taxonomies'])) {
            foreach ($import_data['taxonomies'] as $taxonomy => $terms) {
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                
                $term_ids = array();
                foreach ($terms as $term_data) {
                    $existing_term = get_term_by('slug', $term_data['slug'], $taxonomy);
                    
                    if ($existing_term) {
                        $term_ids[] = $existing_term->term_id;
                    } else {
                        $existing_term = get_term_by('name', $term_data['name'], $taxonomy);
                        
                        if ($existing_term) {
                            $term_ids[] = $existing_term->term_id;
                        } else {
                            $new_term = wp_insert_term($term_data['name'], $taxonomy, array(
                                'slug' => $term_data['slug']
                            ));
                            
                            if (!is_wp_error($new_term)) {
                                $term_ids[] = $new_term['term_id'];
                            }
                        }
                    }
                }
                
                if (!empty($term_ids)) {
                    wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
                }
            }
        }

        wp_send_json_success();
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
                    
                    if (!confirm('Replace this post with the imported content?')) {
                        $(this).val('');
                        return;
                    }
                    
                    var formData = new FormData();
                    formData.append('action', 'pie_import_post');
                    formData.append('post_id', $('#pie-post-id').val());
                    formData.append('import_post_nonce', $('#pie_import_nonce').val());
                    formData.append('import_file', file);
                    
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
