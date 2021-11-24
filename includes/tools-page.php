<?php

namespace XXSimoXX\DisableAnyComment;

if (!defined('ABSPATH')) {
	exit;
}

echo '<div class="wrap">';
echo '<h1>'.esc_html__('Delete Comments', 'disable-comments').'</h1>';

global $wpdb;
$comments_count = $wpdb->get_var("SELECT count(comment_id) from $wpdb->comments");
if ($comments_count <= 0) {
	echo '<p><strong>'.esc_html__('No comments available for deletion.', 'disable-comments').'</strong></p></div>';
	return;
}

if (isset($_POST['delete']) && isset($_POST['delete_mode'])) {

	if (check_admin_referer('delete-comments-admin') === false) {
		wp_die('Nonce error.', 'disable-comments');
	}
	if (!current_user_can('moderate_comments')) {
		wp_die('You are not allowed to do this.', 'disable-comments');
	}

	if ($_POST['delete_mode'] === 'delete_everywhere') {
		if ($wpdb->query("TRUNCATE $wpdb->commentmeta") !== false) {
			if ($wpdb->query("TRUNCATE $wpdb->comments") !== false) {
				$wpdb->query("UPDATE $wpdb->posts SET comment_count  = 0 WHERE post_author != 0");
				$wpdb->query("OPTIMIZE TABLE $wpdb->commentmeta");
				$wpdb->query("OPTIMIZE TABLE $wpdb->comments");
				echo '<p style="color:green"><strong>'.esc_html__('All comments have been deleted.', 'disable-comments').'</strong></p>';
			} else {
				echo '<p style="color:red"><strong>'.esc_html__('Internal error occured. Please try again later.', 'disable-comments').'</strong></p>';
			}
		} else {
			echo '<p style="color:red"><strong>'.esc_html__('Internal error occured. Please try again later.', 'disable-comments').'</strong></p>';
		}
	}

	$comments_count = $wpdb->get_var("SELECT count(comment_id) from $wpdb->comments");
	if ($comments_count <= 0) {
		echo '<p><strong>'.esc_html__('No comments available for deletion.', 'disable-comments').'</strong></p></div>';
		return;
	}

}

echo '<form action="" method="post" id="delete-comments">';
echo '<input type="hidden" id="delete_everywhere" name="delete_mode" value="delete_everywhere" />';
wp_nonce_field('delete-comments-admin');
echo '<h4>'.esc_html__('Total Comments: ', 'disable-comments').(int)$comments_count.'</h4>';
echo '<p class="submit"><input class="button-primary" type="submit" name="delete" value="'.esc_html__('Delete Comments', 'disable-comments').'"></p></form></div>';
