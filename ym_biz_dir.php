<?php

/*
Plugin Name: Sample Your Members Business Directory Module
Plugin URI: http://www.yourmembers.co.uk
Description: Simply adds Business Directory option to packages you choose
Version: 0.1
Author: Coding Futures
Author URI: http://www.codingfutures.co.uk
*/

class ym_biz_dir{

	function __construct()
	{
			define('YM_BUSINESS_POST_TYPE', 'ym_business');

			add_action('init',array($this,'init'));

	}

	function init()
	{
		$this->register_post_type();

		//Adding posting privalages post purchase
		add_filter('ym_user_api_expose', array($this,'ym_user_api_expose'));
		add_action('ym_membership_transaction_success', array($this,'transaction_extras'));
		//Note this will only work with YM12!
		add_action('ym_user_is_expired', array($this,'user_is_expired'),10,2);

		//Adding the option to enable on a per package basis
		add_filter('ym_packs_gateways_extra_fields_post', array($this,'membership_extras'));
		add_filter('ym_packs_gateways_extra_fields_load', array($this,'membership_extras_load'));
		add_action('ym_packs_gateways_extra_fields_display', array($this,'membership_extras_display'));

		//Add Shortcode for Business Form Edit
		add_shortcode('ym_business_page_edit', array($this,'business_page_edit'));

	}

	/*
	 * Set Up post Types
	 *
	 */
	function register_post_type()
	{
		$business_args = array(
		'label'					=> 'Business',
		'public'				=> TRUE,
		'supports'				=> array(
				'title',
				'editor',
				'author',
				'custom-fields',
				'has_archives'
			),
			'register_meta_box_cb'	=> array($this,'custom_post_types_meta_box_cb_business'),
		);

		register_post_type(YM_BUSINESS_POST_TYPE, $business_args);

	}

	function custom_post_types_meta_box_cb_business() {
	add_meta_box('ym_business_metabox', 'Details', array($this,'custom_post_types_meta_box_details'));
	add_action( 'save_post', array($this,'custom_post_types_meta_box_cb_business_save'));
	}

	function custom_post_types_meta_box_details($post) {
		wp_nonce_field( plugin_basename( __FILE__ ), 'ym_custom_post_types' );
		echo '
		<table class="form-table">
		<tr><td><label for="ym_business_address">Address</label></td><td><textarea name="ym_business_address" id="ym_business_address" style="width: 100%; min-width: 200px;" rows="5"></textarea></td></tr>
		<tr><td><label for="ym_business_contact_phone">Contact Phone</td><td><input type="text" name="ym_business_contact_phone" /></td></tr>
		<tr><td><label for="ym_business_contact_email">Contact Email</td><td><input type="email" name="ym_business_contact_email" /></td></tr>
		</table>
		';
	}

	function custom_post_types_meta_box_cb_business_save($post_id) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		if ( !wp_verify_nonce( $_POST['ym_custom_post_types'], plugin_basename( __FILE__ ) ) )
			return;

		if (!current_user_can('edit_page', $post_id))
			return;

		// save
		$options = array(
			'ym_business_address',
			'ym_business_contact_phone',
			'ym_business_contact_email'
		);

		foreach ($options as $option) {
			update_post_meta($post_id, $option, ym_post($option));
		}
	}

	/*
	 *
	 * Setup Your Members Integration
	 *
	 */
	function ym_user_api_expose($api_expose) {
		// Add these fields to the YM User Object
		$api_expose[] = 'allow_business_page';
		return $api_expose;
	}

	function transaction_extras($packet) {
		if ($packet['status'] == 1) {
			// update api exposed fields
			$pack = ym_get_pack_by_id($packet['pack_id']);

			update_user_meta($packet['user_id'], 'ym_allow_business_page', $pack['allow_business_page']);

			// and api save
			YourMember_User::api_update($packet['user_id']);
		}
	}

	/*
	 *
	 * Deactivate any posts associated with the user if they expire
	 *
	 */
	function user_is_expired($ID,$data=false)
	{
		$user_business_id  = get_user_meta($ID, 'business_page_id', TRUE);
		if($user_business_id)
		{
			$post['ID'] = $user_business_id;
			$post['post_status'] = 'draft';
			wp_update_post($post);
		}
		return;
	
	}

	/*
	 *
	 * Show on the packages page
	 *
	 */

	function membership_extras($data) {
		$data['allow_business_page'] = ym_post('allow_business_page', '0');
		return $data;
	}

	function membership_extras_load($data) {
		$data['allow_business_page'] = isset($data['allow_business_page']) ? $data['allow_business_page'] : 0;
		return $data;
	}

	function membership_extras_display($data) {
		global $ym_formgen;
		// clear styles
		$ym_formgen->tr_class = 'basic';
		$ym_formgen->style = '';
		echo $ym_formgen->render_form_table_divider();
		echo $ym_formgen->render_form_table_checkbox_row('Allow Business Page', 'allow_business_page', $data['allow_business_page']);
	}

	/*
	 *
	 * Setup Frontend Form for Business Directory
	 *
	 */
	
	function business_page_edit() {
		global $ym_user;

		if (!is_user_logged_in()) {
			return do_shortcode('[ym_login redirect="' . get_permalink() . '"]');
		}

		$user_business_id  = get_user_meta($ym_user->ID, 'business_page_id', TRUE);
		// check post still exists
		if (!get_post($user_business_id)) {
			delete_user_meta($ym_user->ID, 'business_page_id');
			$user_business_id = FALSE;
		}
		if ($user_business_id) {
			echo '<p>&nbsp;<a href="' . get_permalink($user_business_id) . '" target="_blank" class="alignright">View Your Page</a></p>';
		}

		if (!$ym_user->allow_business_page && !current_user_can('manage_options')) {
			return '<p>You are not Permitted to Create a Business Entry</p>';
		}

		$html = '';

		if ($_POST) {
			// updating
			$post = array(
				'post_author'	=> $ym_user->ID,
				'post_content'	=> ym_post('ym_business_description'),
				'post_title'	=> ym_post('ym_business_title'),
				'post_type'		=> YM_BUSINESS_POST_TYPE,
			);

			if ($user_business_id) {
				// sec check
				$post_check = get_post($user_business_id);
				if ($post_check->post_author != $ym_user->ID) {
					return '<p>You are not Permitted to Edit this item</p>';
				}
				$post['ID'] = $user_business_id;
				wp_update_post($post);
			} else {
				$post['post_status'] = 'publish';
				$post['comment_status'] = 'closed';
				$post['ping_status'] = 'closed';
				$user_business_id = wp_insert_post($post);
				update_user_meta($ym_user->ID, 'business_page_id', $user_business_id);
				$html .= '<p>&nbsp;<a href="' . get_permalink($user_business_id) . '" target="_blank" class="alignright">View Your Page</a></p>';
			}
			update_post_meta($user_business_id, 'ym_business_address', ym_post('ym_business_address'));
			update_post_meta($user_business_id, 'ym_business_contact_website', ym_post('ym_business_contact_website'));
			update_post_meta($user_business_id, 'ym_business_contact_phone', ym_post('ym_business_contact_phone'));
			update_post_meta($user_business_id, 'ym_business_contact_email', ym_post('ym_business_contact_email'));

			$html .= '<p>Business Page was Updated</p>';
		}

		$title = $description = $address = $phone = $email = '';
		if ($user_business_id) {
			$post = get_post($user_business_id);
			$title = $post->post_title;
			$description = $post->post_content;
			$address = get_post_meta($user_business_id, 'ym_business_address', TRUE);
			$website = get_post_meta($user_business_id, 'ym_business_contact_website', TRUE);
			$phone = get_post_meta($user_business_id, 'ym_business_contact_phone', TRUE);
			$email = get_post_meta($user_business_id, 'ym_business_contact_email', TRUE);

		} else {
			// preload some data
			$email = $ym_user->data->user_email;
			$website = $ym_user->data->user_url;
		}


		global $ym_formgen;
		$ym_formgen->return = TRUE;

		$html .= '
			<form action="" method="post">
			<table>
			';
					$html .= $ym_formgen->render_form_table_text_row('Business Name', 'ym_business_title', $title)
						. $ym_formgen->render_form_table_wp_editor_row('Business Description', 'ym_business_description', $description)

						. $ym_formgen->render_form_table_textarea_row('Business Address', 'ym_business_address', $address)

						. $ym_formgen->render_form_table_url_row('Website', 'ym_business_contact_website', $website, '', 'http://')
						. $ym_formgen->render_form_table_text_row('Contact Phone', 'ym_business_contact_phone', $phone)
						. $ym_formgen->render_form_table_email_row('Contact Email', 'ym_business_contact_email', $email);
					$html .= '
			</table>
			<p>&nbsp;<input type="submit" class="button-secondary alignright" /></p>
			</form>
			';

		return $html;
	}
}

new ym_biz_dir;