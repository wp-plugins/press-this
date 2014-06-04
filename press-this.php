<?php
/*
Plugin Name: Press This
Plugin URI: http://wordpress.org/extend/plugins/press-this/
Description: Posting images, links, and cat gifs will never be the same.
Version: 0.0.1a
Author: Press This Team
Author URI: https://corepressthis.wordpress.com/
Text Domain: press-this
Domain Path: /languages
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * Class WpPressThis
 */
class WpPressThis {
	/**
	 * WpPressThis::__construct()
	 * Constructor
	 *
	 * @uses remove_action(), add_action()
	 */
	public function __construct() {

		/*
		 * @TODO: IMPORTANT: must come up with final solution for SAMEORIGIN handling when in modal context (detect, secure, serve).
		 */

		$script_name = self::script_name();

		if ( empty( $script_name ) )
			return;

		if ( ! is_admin() ) {
			if ( false !== strpos( site_url( 'wp-login.php' ), $script_name ) ) {
				/*
				 * Only remove SAMEORIGIN header for /wp-login.php, so it can be displayed in the modal/iframe if needed,
				 * but only if then redirecting to /wp-admin/press-this.php
				 */
				if ( ! empty( $_GET['redirect_to'] )
				     && false !== strpos( $_GET['redirect_to'], self::runtime_url() ) )
					remove_action( 'login_init', 'send_frame_options_header' );
			}
		} else {
			if ( false !== strpos( self::runtime_url(), $script_name ) ) {
				/*
				 * Remove SAMEORIGIN header for /wp-admin/press-this.php on targeted install so it can be used inside the modal's iframe
				 */
				remove_action( 'admin_init', 'send_frame_options_header' );
				/*
				 * Take over /wp-admin/press-this.php
				 */
				add_action( 'admin_init', array( $this, 'press_this_php_override' ), 0 );
			} else if ( false !== strpos( admin_url( 'post.php' ), $script_name ) ) {
				/*
				 * Remove SAMEORIGIN header for /wp-admin/post.php so it can be used inside the modal's iframe,
				 * after saving a draft, but only if referred from /wp-admin/press-this.php or itself
				 */
				if ( ! empty( $_SERVER['HTTP_REFERER'] )
				     && ( false !== strpos( $_SERVER['HTTP_REFERER'], self::runtime_url() )
						  || false !== strpos( $_SERVER['HTTP_REFERER'], admin_url( 'post.php' ) ) ) )
					remove_action( 'admin_init', 'send_frame_options_header' );
			} else if ( false !== strpos( admin_url( 'tools.php' ),$script_name ) ) {
				/*
				 * Take over Press This bookmarklet code in /wp-admin/tools.php
				 */
				add_filter( 'shortcut_link', array( $this, 'shortcut_link_override' ) );
			} else if ( false !== strpos( admin_url( 'admin-ajax.php' ), $script_name ) ) {
				/*
				 * Remove SAMEORIGIN header for /wp-admin/admin-ajax.php so it can be used from the modal's iframe,
				 * after saving a draft, but only if referred from /wp-admin/press-this.php or /wp-admin/post.php
				 */
				if ( ! empty( $_SERVER['HTTP_REFERER'] )
					&& ( false !== strpos( $_SERVER['HTTP_REFERER'], self::runtime_url() )
				     || false !== strpos( $_SERVER['HTTP_REFERER'], admin_url( 'post.php' ) ) ) )
					remove_action( 'admin_init', 'send_frame_options_header' );
				/*
				 * AJAX handling
				 */
				add_action( 'wp_ajax_press_this_site_settings', array( $this, 'press_this_ajax_site_settings' ) );
				add_action( 'wp_ajax_press_this_publish_post', array( $this, 'press_this_ajax_publish_post' ) );
				add_action( 'wp_ajax_press_this_draft_post', array( $this, 'press_this_ajax_draft_post' ) );
			}
		}
	}

	public function script_name() {
		$script_name = ( ! empty( $_SERVER['SCRIPT_NAME'] ) )
			? $_SERVER['SCRIPT_NAME']
			: ( ! empty( $_SERVER['PHP_SELF'] ) )
				? $_SERVER['PHP_SELF']
				: ( ! empty( $_SERVER['REQUEST_URI'] ) )
					? preg_replace( '/^([^\?]+)(\?.*)?$/', '\1', $_SERVER['REQUEST_URI'] )
					: '';
		return ( preg_match('/\/wp-admin\/?$/', $script_name) || ! preg_match('/\.php$/', $script_name ) )
			? rtrim( $script_name, '/' ) . '/index.php'
			: $script_name;
	}

	public function set_url_scheme( $url ) {
		if ( ( function_exists( 'force_ssl_admin' ) && force_ssl_admin() )
		     || ( function_exists( 'force_ssl_login' ) && force_ssl_login() )
		     || ( function_exists( 'force_ssl_content' ) && force_ssl_content() )
		     || ( function_exists( 'is_ssl' ) && is_ssl() )) {
			return set_url_scheme(  $url, 'https' );
		}
		return set_url_scheme( $url, 'http' );
	}

	public function strip_url_scheme( $url ) {
		return preg_replace( '/^https?:(\/\/.+)$/', '\1', $url );
	}

	function plugin_version() {
		$data = get_plugin_data( __FILE__, false, false );
		return ( ! empty( $data ) && ! empty( $data['Version'] ) ) ? $data['Version'] : 0;
	}

	/**
	 * WpPressThis::runtime_url()
	 *
	 * @return string|void Full URL to /admin/press-this.php in current install
	 * @uses admin_url()
	 */
	public function runtime_url() {
		return self::set_url_scheme( admin_url( 'press-this.php' ) );
	}

	/**
	 * WpPressThis::plugin_dir_path()
	 *
	 * @return string|void Full URL to /admin/press-this.php in current install
	 * @uses __FILE__, plugin_dir_path()
	 */
	public function plugin_dir_path() {
		return rtrim( plugin_dir_path( __FILE__ ), '/' );
	}

	/**
	 * WpPressThis::plugin_dir_url()
	 *
	 * @return string
	 * @uses __FILE__, plugin_dir_url()
	 */
	public function plugin_dir_url() {
		return rtrim( self::strip_url_scheme( plugin_dir_url( __FILE__ ) ), '/' );
	}

	/**
	 * WpPressThis::shortcut_link_override()
	 *
	 * @return mixed Press This bookmarklet JS trigger found in /wp-admin/tools.php
	 */
	public function shortcut_link_override() {
		$url  = esc_js( self::runtime_url() . '?v=' . self::plugin_version() );
		$link = "javascript: var u='{$url}';\n";
		$link .= file_get_contents( self::plugin_dir_path() . '/js/bookmarklet.js' );
		return str_replace( array( "\r", "\n", "\t" ), '', $link );
	}

	/**
	 * WpPressThis::press_this_php_override()
	 * Takes over /wp-admin/press-this.php for backward compatibility and while in feature-as-plugin mode
	 *
	 * @uses $_POST
	 */
	public function press_this_php_override() {
		// Simply drop the following test once/if this becomes the standard Press This code in core
		if ( false === strpos( self::runtime_url(), self::script_name() ) )
			return;

		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( get_post_type_object( 'post' )->cap->create_posts ) ) {
			wp_die( __( 'Cheatin&#8217; uh?' ) );
		}

		self::serve_app_html();
	}

	/**
	 * WpPressThis::format_post_data_for_save()
	 *
	 * @return array('post_title' => $title, 'post_content' => $content, 'post_status' => $post_status)
	 *
	 * @uses $_POST
	 */
	public function format_post_data_for_save( $status = 'draft' ) {
		if ( empty( $_POST ) ) {
			$site_settings = self::press_this_site_settings();
			return array(
				'post_title'   => $site_settings['i18n']['New Post'],
				'post_content' => '',
			);
		}

		$post    = array();
		$content = '';

		if ( ! empty( $_POST['wppt_title'] ) ) {
			$post['post_title'] = sanitize_text_field( $_POST['wppt_title'] );
		}

		if ( ! empty( $_POST['wppt_content'] ) ) {
			$content = $_POST['wppt_content']; // we have to allow this one and let wp_insert_post() filter the content
		}

		if ( ! empty( $_POST['wppt_selected_img'] ) ) {
			$content = '<a href="'.esc_url( $_POST['wppt_source_url'] ).'"><img src="'.esc_url( $_POST['wppt_selected_img'] ).'" /></a>'
					. $content;
		}

		$post['post_content'] = $content;

		if ( 'publish' == $status ) {
			if ( current_user_can( 'publish_posts' ) )
				$post['post_status'] = $status;
			else
				$post['post_status'] = 'pending';
		} else {
			$post['post_status'] = 'draft';
		}

		return $post;
	}

	public function side_load_images( $post_id, $content ) {
		$new_content      = $content;
		$upload           = false;
		if ( ! empty( $_POST['wppt_selected_img'] ) && current_user_can( 'upload_files' ) ) {
			foreach( (array) $_POST['wppt_selected_img'] as $key => $image ) {
				// Don't try to sideload file without a file extension, leads to WP upload error,
				// then a "PHP Notice:  Undefined offset: 0 in /wp-admin/includes/media.php on line 811"
				// Matching regex to skip from media_sideload_image() in otherwise erroring /wp-admin/includes/media.php
				if ( ! preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $image ) )
				     continue;
				// See if files exist in content - we don't want to upload non-used selected files.
				if ( false !== strpos( $new_content, htmlspecialchars( $image ) ) ) {
					$upload = media_sideload_image( $image, $post_id );
					// Replace the POSTED content <img> with correct uploaded ones. Regex contains fix for Magic Quotes
					if ( ! is_wp_error( $upload ) )
						$new_content = preg_replace( '/<img ([^>]*)src=\\\?(\"|\')'.preg_quote( htmlspecialchars( $image ), '/' ).'\\\?(\2)([^>\/]*)\/*>/is', $upload, $new_content );
				}
			}
		}

		// Error handling for media_sideload, send original content back
		if ( is_wp_error( $new_content ) )
			return $content;

		return $new_content;
	}

	/**
	 * WpPressThis::save()
	 *
	 * @param string $post_status
	 *
	 * @return bool|int|WP_Error
	 */
	public function save( $post_status = 'draft' ) {
		$wp_error      = false;
		$data          = self::format_post_data_for_save( $post_status );

		$post = array(
			'post_title'     => $data['post_title'],
			'post_content'   => $data['post_content'],
			'post_status'    => 'draft',
			'post_type'      => 'post',
		);

		$post_id = wp_insert_post( $post, $wp_error );

		if ( ! empty( $wp_error ) && is_wp_error( $wp_error ) )
			return $wp_error;

		$post['ID']           = $post_id;
		$post['post_status']  = $data['post_status'];

		$new_content = self::side_load_images( $post_id, $post['post_content'] );

		if ( is_wp_error( $new_content ) )
			$new_content = $post['post_content'];

		// Update the post content if needed, and status to publish/pending if not draft
		if ( $new_content != $post['post_content'] || 'draft' != $post['post_status'] ) {
			if ( $new_content != $post['post_content'] )
				$post['post_content'] = $new_content;
			$post_id = wp_update_post($post, $wp_error);
			if ( ! empty( $wp_error ) && is_wp_error( $wp_error ) ) {
				wp_delete_post($post_id);
				return $wp_error;
			}
		}

		return $post_id;
	}

	public function make_backward_compatible() {
		// Make Press This compatible with $_POST as well as $_GET, as appropriate ($_POST > $_GET), to remain backward compatible
		$data = array_merge_recursive( $_POST, $_GET );

		// Get the legacy QS params, or equiv POST data
		$data['u'] = ( !empty( $data['u'] ) ) ? $data['u'] : '';
		$data['s'] = ( !empty( $data['s'] ) ) ? $data['s'] : '';
		$data['t'] = ( !empty( $data['t'] ) ) ? $data['t'] : '';

		// If no _meta, _img or _links were passed via $_POST, fetch them from source as fallback, makes PT fully backward compatible
		if ( empty( $data['_meta'] ) || empty( $data['_img'] ) || empty( $data['_links'] ) ) {
			// If we indeed have a URL to fetch
			if ( ! empty( $data['u'] ) ) {
				// Download source page to tmp file
				$source_tmp_file = ( ! empty( $data['u'] ) ) ? download_url( $data['u'] ) : '';
				if ( ! is_wp_error( $source_tmp_file ) && file_exists( $source_tmp_file ) ) {
					// Get the content of the source page from the tmp file.
					$source_content = wp_kses(
						file_get_contents( $source_tmp_file ),
						array (
							'img' => array(
								'src'      => array(),
							),
							'link' => array(
								'rel'      => array(),
								'itemprop' => array(),
								'href'     => array(),
							),
							'meta' => array(
								'property' => array(),
								'name'     => array(),
								'content'  => array(),
							)
						)
					);
					// Fetch and gather <meta> data
					if ( empty( $data['_meta'] ) ) {
						if ( preg_match_all( '/<meta (.+)[\s]?\/>/  ', $source_content, $matches ) ) {
							if ( !empty( $matches[0] ) ) {
								foreach ( $matches[0] as $key => $value ) {
									if ( preg_match( '/<meta[^>]+(property|name)="(.+)"[^>]+content="(.+)"[^>]+\/>/', $value, $new_matches ) )
										$data['_meta'][ $new_matches[2] ] = $new_matches[3];
								}
							}
						}
					}
					// Fetch and gather <img> data
					if ( empty( $data['_img'] ) ) {
						if ( preg_match_all( '/<img (.+)[\s]?\/>/', $source_content, $matches ) ) {
							if ( !empty( $matches[0] ) ) {
								foreach ( $matches[0] as $value ) {
									if ( preg_match( '/<img[^>]+src="([^"]+)"[^>]+\/>/', $value, $new_matches ) ) {
										$data['_img'][] = $new_matches[1];
									}
								}
							}
						}
					}
					// Fetch and gather <link> data
					if ( empty( $data['_links'] ) ) {
						if ( preg_match_all( '/<link (.+)[\s]?\/>/', $source_content, $matches ) ) {
							if ( !empty( $matches[0] ) ) {
								foreach ( $matches[0] as $key => $value ) {
									if ( preg_match( '/<link[^>]+(rel|itemprop)="([^"]+)"[^>]+href="([^"]+)"[^>]+\/>/', $value, $new_matches ) ) {
										if ( 'alternate' == $new_matches[2] || 'thumbnailUrl' == $new_matches[2] || 'url' == $new_matches[2] )
											$data['_links'][ $new_matches[2] ] = $new_matches[3];
									}
								}
							}
						}
					}
					// All done with backward compatibility
					// Let's do some cleanup, for good measure :)
					unlink( $source_tmp_file );
				}
			}
		}
		return $data;
	}

	/**
	 * WpPressThis::serve_app_html()
	 *
	 * @uses $_POST, WpPressThis::runtime_url(), WpPressThis::plugin_dir_url()
	 */
	public function serve_app_html() {
		// Get data, new (POST) and old (GET)
		$data = self::make_backward_compatible();

		// Get more env vars
		$runtime_url              = self::strip_url_scheme( self::runtime_url() );
		$nonce                    = wp_create_nonce( 'press_this_site_settings' );

		// Set the passed data
		$data['_version']         = self::plugin_version();
		$data['_runtime_url']     = $runtime_url;
		$data['_plugin_dir_url']  = self::plugin_dir_url();
		$data['_ajax_url']        = self::strip_url_scheme( admin_url( 'admin-ajax.php' ) );
		$data['_nonce']           = $nonce;

		// JSON encode passed data
		$json                     = json_encode( $data );

		// Generate some include paths
		$js_inc_dir               = preg_replace( '/^(.+)\/wp-admin\/.+$/', '\1/wp-includes/js', $runtime_url );
		$json_js_inc              = $js_inc_dir . '/json2.min.js';
		$jquery_js_inc            = $js_inc_dir . '/jquery/jquery.js';
		$app_css_inc              = self::plugin_dir_url() . '/css/press-this.css';
		$load_js_inc              = self::plugin_dir_url() . '/js/load.js';
		$form_action              = $runtime_url;

		// Echo HTML
		echo <<<________HTMLDOC
<!DOCTYPE html>
<html>
<head lang="en">
	<meta charset="UTF-8">
	<title></title>
	<link rel='stylesheet' id='all-css' href='{$app_css_inc}' type='text/css' media='all' />
	<script language="JavaScript">
		window.wp_pressthis_data = {$json};
	</script>
	<script src="{$json_js_inc}" language="JavaScript"></script>
	<script src="{$jquery_js_inc}" language="JavaScript"></script>
	<script src="{$load_js_inc}" language="JavaScript"></script>
</head>
<body>
	<div id='wppt_app_container' class="editor">
		<h2 id='wppt_title_container' class="post__title" contenteditable="true"></h2>
		<div id='wppt_featured_image_container' class="featured-image-container">
			<a href="#" id="wppt_other_images_switch" class="other-images__switch button--secondary"></a>
			<div id='wppt_other_images_widget' class="other-images">
				<div id='wppt_other_images_container'></div>
			</div>
		</div>
		<div id='wppt_suggested_content_container' class="editor--content" contenteditable="true"></div>
	</div>
	<div class="actions">
		<form id="wppt_form" class="post-actions" name="wppt_form" method="POST" action="{$form_action}" target="_self">
			<input type="hidden" name="wppt_nonce" id="wppt_nonce_field" value="{$nonce}"/>
			<input type="hidden" name="wppt_title" id="wppt_title_field" value=""/>
			<input type="hidden" name="wppt_content" id="wppt_content_field" value=""/>
			<input type="hidden" name="wppt_selected_img" id="wppt_selected_img_field" value=""/>
			<input type="hidden" name="wppt_source_url" id="wppt_source_url_field" value=""/>
			<input type="hidden" name="wppt_source_name" id=wppt_source_name_field" value=""/>
			<input type="submit" class="button--subtle" name="wppt_draft" id="wppt_draft_field" value=""/>
			<input type="submit" class="button--primary" name="wppt_publish" id="wppt_publish_field" value=""/>
		</form>
	</div>
</body>
</html>
________HTMLDOC;
		die();
	}

	/**
	 * WpPressThis::press_this_ajax_site_settings()
	 *
	 * @uses admin_url(), wp_create_nonce()
	 */
	public function press_this_site_settings() {
		$domain      = 'press-this';
		$plugin_data = get_plugin_data( __FILE__, false, false );
		return array(
			'version'        => ( ! empty( $plugin_data ) && ! empty( $plugin_data['Version'] ) ) ? $plugin_data['Version'] : 0,
			'i18n'           => array(
				'Welcome to Press This!' => __('Welcome to Press This!', $domain ),
				'Source:'                => __( 'Source:', $domain ),
				'Show other images'      => __( 'Show other images', $domain ),
				'Hide other images'      => __( 'Hide other images', $domain ),
				'Publish'                => __( 'Publish', $domain ),
				'Save Draft'             => __( 'Save Draft', $domain ),
				'New Post'               => __( 'New Post', $domain ),
				'Start typing here.'     => __( 'Start typing here.', $domain ),
				'Sorry, but an unexpected error occurred.' => __( 'Sorry, but an unexpected error occurred.', $domain ),
			),
		);
	}

	/**
	 * WpPressThis::press_this_ajax_site_settings()
	 *
	 * @uses admin_url(), wp_create_nonce()
	 */
	public function press_this_ajax_site_settings() {
		header( 'content-type: application/json' );
		echo json_encode( self::press_this_site_settings() );
		die();
	}

	public function post_save_json_response( $post_id, $post_status = 'draft' ) {
		header( 'content-type: application/json' );
		if ( is_wp_error( $post_id ) || intval( $post_id ) < 1 ) {
			$site_settings = self::press_this_site_settings();
			echo json_encode( array( 'error' => $site_settings['i18n']['Sorry, but an unexpected error occurred.'] ) );
		} else {
			echo json_encode( array( 'post_id' => $post_id, 'post_permalink' => get_post_permalink( $post_id ), 'post_status' => $post_status ) );
		}
		die();
	}

	public function press_this_ajax_draft_post() {
		self::post_save_json_response( self::save( 'draft' ) );
	}

	public function press_this_ajax_publish_post() {
		self::post_save_json_response( self::save( 'publish' ), 'published' );
	}
}

new WpPressThis;