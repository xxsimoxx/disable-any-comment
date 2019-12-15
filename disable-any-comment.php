<?php
/**
 * Plugin Name: Disable Any Comment
 * Plugin URI: https://software.gieffeedizioni.it
 * Description: Allows administrators to globally disable comments on their site.
 * Version: 1.0.0-rc1
 * Author: Gieffe edizioni srl
 * Author URI: https://www.gieffeedizioni.it
 * License: GPL2
 * Text Domain: disable-comments
 * Domain Path: /languages/
 * 
 * 
 * This plugin is forked from Disable Comments v. 1.9.0 by Samir Shah (http://www.rayofsolaris.net/)
 * 
 */
namespace XXSimoXX\DisableAnyComment;

if(!defined('ABSPATH'))
	exit;

class DisableAnyComment {

	private $networkactive;
	
	private $modified_types = [];

	function __construct() {
		$this->networkactive = (is_multisite() && array_key_exists(plugin_basename(__FILE__), (array) get_site_option('active_sitewide_plugins')));
		$this->init_filters();
	}

	private function init_filters() {
		// These need to happen now
		add_filter('wp_headers', [$this, 'filter_wp_headers']);
		add_action('template_redirect', [$this, 'filter_query'], 9);

		// Admin bar filtering has to happen here since WP 3.6
		add_action('template_redirect', [$this, 'filter_admin_bar']);
		add_action('admin_init', [$this, 'filter_admin_bar']);

		// These can happen later
		add_action('plugins_loaded',[$this, 'register_text_domain']); 
		add_action('wp_loaded', [$this, 'init_wploaded_filters']);
	}

	public function register_text_domain() {
		load_plugin_textdomain('disable-comments', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	public function init_wploaded_filters() {
	
		$typeargs = ['public' => true];
		if($this->networkactive) {
			$typeargs['_builtin'] = true;
		}
		$disable_post_types = get_post_types($typeargs);
		foreach(array_keys($disable_post_types) as $type) {
			if(!in_array($type, $this->modified_types) && !post_type_supports($type, 'comments'))
				unset($disable_post_types[$type]);
		}

		if(!empty($disable_post_types)) {
			foreach($disable_post_types as $type) {
				// we need to know what native support was for later
				if(post_type_supports($type, 'comments')) {
					$this->modified_types[] = $type;
					remove_post_type_support($type, 'comments');
					remove_post_type_support($type, 'trackbacks');
				}
			}
			add_filter('comments_array', [$this, 'filter_existing_comments'], 20, 2);
			add_filter('comments_open', [$this, 'filter_comment_status'], 20, 2);
			add_filter('pings_open', [$this, 'filter_comment_status'], 20, 2);
		}

		// Filters for the admin only
		if(is_admin()) {
			if($this->networkactive) {
				add_action('network_admin_menu', [$this, 'tools_menu']);
				add_filter('network_admin_plugin_action_links', [$this, 'plugin_actions_links'], 10, 2);
			}
			else {
				add_action('admin_menu', [$this, 'tools_menu' ]);
				add_filter('plugin_action_links', [$this, 'plugin_actions_links'], 10, 2);
			}

			add_action('admin_menu', [$this, 'filter_admin_menu'], PHP_INT_MAX);
			add_action('admin_print_styles-index.php', [$this, 'admin_css']);
			add_action('admin_print_styles-profile.php', [$this, 'admin_css']);
			add_action('wp_dashboard_setup', [$this, 'filter_dashboard']);
			add_filter('pre_option_default_pingback_flag', '__return_zero');
		}
		
		// Filters for front end only
		else {
			add_action('template_redirect', [$this, 'check_comment_template']);
			add_filter('feed_links_show_comments_feed', '__return_false');
		}
	}

	/*
	 * Replace the theme's comment template with a blank one.
	 * To prevent this, define DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE
	 * and set it to True
	 */
	public function check_comment_template() {
		if(is_singular()) {
			if(!defined('DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE') || DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE == true) {
				// Kill the comments template.
				add_filter('comments_template', [$this, 'dummy_comments_template'], 20);
			}
			// Remove comment-reply script for themes that include it indiscriminately
			wp_deregister_script('comment-reply');
			// feed_links_extra inserts a comments RSS link
			remove_action('wp_head', 'feed_links_extra', 3);
		}
	}

	public function dummy_comments_template() {
		return dirname(__FILE__) . '/includes/comments-template.php';
	}


	/*
	 * Remove the X-Pingback HTTP header
	 */
	public function filter_wp_headers($headers) {
		unset($headers['X-Pingback']);
		return $headers;
	}

	/*
	 * Issue a 403 for all comment feed requests.
	 */
	public function filter_query() {
		if(is_comment_feed()) {
			wp_die(__('Comments are closed.'), '', ['response' => 403]);
		}
	}

	/*
	 * Remove comment links from the admin bar.
	 */
	public function filter_admin_bar() {
		if(is_admin_bar_showing()) {
			// Remove comments links from admin bar
			remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
			if(is_multisite()) {
				add_action('admin_bar_menu', [$this, 'remove_network_comment_links'], 500);
			}
		}
	}

	/*
	 * Remove comment links from the admin bar in a multisite network.
	 */
	public function remove_network_comment_links($wp_admin_bar) {
		if($this->networkactive && is_user_logged_in()) {
			foreach((array) $wp_admin_bar->user->blogs as $blog) {
				$wp_admin_bar->remove_menu('blog-' . $blog->userblog_id . '-c');
			}
		}
		else {
			// We have no way to know whether the plugin is active on other sites, so only remove this one
			$wp_admin_bar->remove_menu('blog-' . get_current_blog_id() . '-c');
		}
	}

	/**
	 * Return context-aware tools page URL
	 */
	private function tools_page_url() {
		$base =  $this->networkactive ? network_admin_url('settings.php') : admin_url('tools.php');
		return add_query_arg('page', 'disable_any_comment_tools', $base);
	}

	public function filter_admin_menu(){
		global $pagenow;

		if ($pagenow == 'comment.php' || $pagenow == 'edit-comments.php')
			wp_die(__('Comments are closed.'), '', ['response' => 403]);

		remove_menu_page('edit-comments.php');

		if ($pagenow == 'options-discussion.php')
			wp_die(__('Comments are closed.'), '', ['response' => 403]);

		remove_submenu_page('options-general.php', 'options-discussion.php');
	}

	public function filter_dashboard(){
		remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
	}

	public function admin_css() {
		echo '<style>
			#dashboard_right_now .comment-count,
			#dashboard_right_now .comment-mod-count,
			#latest-comments,
			#welcome-panel .welcome-comments,
			.user-comment-shortcuts-wrap {
				display: none !important;
			}
		</style>';
	}

	public function filter_existing_comments($comments, $post_id) {
		return [];
	}

	public function filter_comment_status($open, $post_id) {
		return false;
	}

	/**
	 * Add links to Settings page
	*/
	public function plugin_actions_links($links, $file) {
		static $plugin;
		$plugin = plugin_basename(__FILE__);
		if($file == $plugin && current_user_can('manage_options')) {
			array_unshift(
				$links,
				sprintf('<a href="%s"><i style="font: 16px dashicons; vertical-align: text-bottom;" class="dashicon dashicons-admin-tools"></i></a>', esc_attr($this->tools_page_url()))
			);
		}
		return $links;
	}

	public function tools_menu() {
		$title = __('Delete Comments', 'disable-comments');
		if($this->networkactive)
			add_submenu_page('settings.php', $title, $title, 'manage_network_plugins', 'disable_any_comment_tools', [ $this, 'tools_page' ]);
		else
			add_submenu_page('tools.php', $title, $title, 'manage_options', 'disable_any_comment_tools', [ $this, 'tools_page' ]);
	}

	public function tools_page() {
		include dirname(__FILE__) . '/includes/tools-page.php';
	}

}

// Fire.
new DisableAnyComment;
