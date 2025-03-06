<?php
/*
Plugin Name: Custom Publication Dashboard
Description: Adds a shortcode to display the publication dashboard
Version: 1.0
Author: Wahid
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Register the shortcode
function publication_dashboard_shortcode() {
    ob_start();
    include_once(plugin_dir_path(__FILE__) . 'custom_publication.php');
    return ob_get_clean();
}
add_shortcode('publication_dashboard', 'publication_dashboard_shortcode');

// Enqueue required styles and scripts
function publication_dashboard_enqueue_scripts() {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'publication_dashboard')) {
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
        wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
    }
}
add_action('wp_enqueue_scripts', 'publication_dashboard_enqueue_scripts');
