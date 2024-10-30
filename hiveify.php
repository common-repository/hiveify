<?php
/*
 * Plugin Name:        Hiveify
 * Version:            1.0.0
 * Description:        Integration for Hiveify and WordPress
 * Author:             WP Zone
 * Author URI          https://wpzone.co
 * License:            GPLv3+
 * License URI:        http://www.gnu.org/licenses/gpl.html
 * Requires at least:  6.0
 * Requires PHP:       8.0
 * Text Domain:        hiveify
*/

/*
Hiveify Plugin for WordPress
Copyright (C) 2024  WP Zone

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.

===

The text of the GNU General Public License version 3 is in
./license/license.txt.

===

This plugin includes code based on WordPress. WordPress licensing and
copyright information is included in ./license/wp-license.txt.
*/

defined('ABSPATH') || exit;

// Created from scratch based on wp-includes/pluggable.php
class Hiveify {
	const API_URL = 'https://email.hiveify.io/api/v1';
	
	private static $hiveify;
	
	static function hiveify() {
		return self::$hiveify;
	}
	
	function __construct() {
		if (isset(self::$hiveify)) {
			throw new Exception();
		}
		
		add_action('admin_menu', [$this, 'register_admin_page']);
		
		self::$hiveify = $this;
	}
	
	private function get_api_token() {
		$apiToken = get_option('hiveify_api_token', '');
		if (!$apiToken && is_multisite()) {
			$apiToken = get_site_option('hiveify_api_token', '');
		}
		return $apiToken;
	}
	
	function register_admin_page() {
		add_menu_page( __('Hiveify', 'hiveify'), __('Hiveify', 'hiveify'), 'manage_options', 'hiveify', [$this, 'render_admin_page'] );
	}
	
	function render_admin_page() {
		if (isset($_POST['hiveify_settings'])) {
			check_admin_referer('hiveify_settings_save');
			
			if (empty($_POST['hiveify_settings']['api_token'])) {
				delete_option('hiveify_api_token');
			} else {
				update_option('hiveify_api_token', sanitize_key($_POST['hiveify_settings']['api_token']));
			}
		}
?>
		<div class="wrap">
			<h1><?php esc_html_e('Hiveify', 'hiveify'); ?></h1>
			<form method="post" action="">
				<p>
					<label>
						<?php esc_html_e('Hiveify API Token', 'hiveify'); ?>
						<input name="hiveify_settings[api_token]" type="password" value="<?php echo(esc_attr(str_repeat('*', strlen($this->get_api_token())))); ?>">
					</label>
				</p>
				
				<?php wp_nonce_field('hiveify_settings_save'); ?>
				<button type="submit" class="button-primary"><?php esc_html_e('Save Settings', 'hiveify'); ?></button>
			</form>
		</div>
<?php
	}
	
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		try {
			$atts = apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );
			$pre_wp_mail = apply_filters( 'pre_wp_mail', null, $atts );

			if ( null !== $pre_wp_mail ) {
				return $pre_wp_mail;
			}

			if ( isset( $atts['to'] ) ) {
				$to = $atts['to'];
			}

			if ( ! is_array( $to ) ) {
				$to = explode( ',', $to );
			}

			if ( isset( $atts['subject'] ) ) {
				$subject = $atts['subject'];
			}

			if ( isset( $atts['message'] ) ) {
				$message = $atts['message'];
			}

			if ( isset( $atts['headers'] ) ) {
				$headers = $atts['headers'];
			}

			if ( isset( $atts['attachments'] ) ) {
				$attachments = $atts['attachments'];
			}

			if ( ! is_array( $attachments ) ) {
				$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
			}
			
			// Headers.
			$cc       = array();
			$bcc      = array();
			$reply_to = array();

			if ( empty( $headers ) ) {
				$headers = array();
			} else {
				if ( ! is_array( $headers ) ) {
					/*
					 * Explode the headers out, so this function can take
					 * both string headers and an array of headers.
					 */
					$tempheaders = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
				} else {
					$tempheaders = $headers;
				}
				$headers = array();

				// If it's actually got contents.
				if ( ! empty( $tempheaders ) ) {
					// Iterate through the raw headers.
					foreach ( (array) $tempheaders as $header ) {
						if ( ! str_contains( $header, ':' ) ) {
							if ( false !== stripos( $header, 'boundary=' ) ) {
								$parts    = preg_split( '/boundary=/i', trim( $header ) );
								$boundary = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
							}
							continue;
						}
						// Explode them out.
						list( $name, $content ) = explode( ':', trim( $header ), 2 );

						// Cleanup crew.
						$name    = trim( $name );
						$content = trim( $content );

						switch ( strtolower( $name ) ) {
							// Mainly for legacy -- process a "From:" header if it's there.
							case 'from':
								$bracket_pos = strpos( $content, '<' );
								if ( false !== $bracket_pos ) {
									// Text before the bracketed email is the "From" name.
									if ( $bracket_pos > 0 ) {
										$from_name = substr( $content, 0, $bracket_pos );
										$from_name = str_replace( '"', '', $from_name );
										$from_name = trim( $from_name );
									}

									$from_email = substr( $content, $bracket_pos + 1 );
									$from_email = str_replace( '>', '', $from_email );
									$from_email = trim( $from_email );

									// Avoid setting an empty $from_email.
								} elseif ( '' !== trim( $content ) ) {
									$from_email = trim( $content );
								}
								break;
							case 'content-type':
								if ( str_contains( $content, ';' ) ) {
									list( $type, $charset_content ) = explode( ';', $content );
									$content_type                   = trim( $type );
									if ( false !== stripos( $charset_content, 'charset=' ) ) {
										$charset = trim( str_replace( array( 'charset=', '"' ), '', $charset_content ) );
									} elseif ( false !== stripos( $charset_content, 'boundary=' ) ) {
										$boundary = trim( str_replace( array( 'BOUNDARY=', 'boundary=', '"' ), '', $charset_content ) );
										$charset  = '';
									}

									// Avoid setting an empty $content_type.
								} elseif ( '' !== trim( $content ) ) {
									$content_type = trim( $content );
								}
								break;
							case 'cc':
								$cc = array_merge( (array) $cc, explode( ',', $content ) );
								break;
							case 'bcc':
								$bcc = array_merge( (array) $bcc, explode( ',', $content ) );
								break;
							case 'reply-to':
								$reply_to = array_merge( (array) $reply_to, explode( ',', $content ) );
								break;
							default:
								// Add it to our grand headers array.
								$headers[ trim( $name ) ] = trim( $content );
								break;
						}
					}
				}
			}

			// Set "From" name and email.

			// If we don't have a name from the input headers.
			if ( ! isset( $from_name ) ) {
				$from_name = 'WordPress';
			}

			/*
			 * If we don't have an email from the input headers, default to wordpress@$sitename
			 * Some hosts will block outgoing mail from this address if it doesn't exist,
			 * but there's no easy alternative. Defaulting to admin_email might appear to be
			 * another option, but some hosts may refuse to relay mail from an unknown domain.
			 * See https://core.trac.wordpress.org/ticket/5007.
			 */
			if ( ! isset( $from_email ) ) {
				// Get the site domain and get rid of www.
				$sitename   = wp_parse_url( network_home_url(), PHP_URL_HOST );
				$from_email = 'wordpress@';

				if ( null !== $sitename ) {
					if ( str_starts_with( $sitename, 'www.' ) ) {
						$sitename = substr( $sitename, 4 );
					}

					$from_email .= $sitename;
				}
			}

			/**
			 * Filters the email address to send from.
			 *
			 * @since 2.2.0
			 *
			 * @param string $from_email Email address to send from.
			 */
			$from_email = apply_filters( 'wp_mail_from', $from_email );

			/**
			 * Filters the name to associate with the "from" email address.
			 *
			 * @since 2.3.0
			 *
			 * @param string $from_name Name associated with the "from" email address.
			 */
			$from_name = apply_filters( 'wp_mail_from_name', $from_name );
			
			// If we don't have a Content-Type from the input headers.
			if ( ! isset( $content_type ) ) {
				$content_type = 'text/plain';
			}

			/**
			 * Filters the wp_mail() content type.
			 *
			 * @since 2.3.0
			 *
			 * @param string $content_type Default wp_mail() content type.
			 */
			$content_type = apply_filters( 'wp_mail_content_type', $content_type );
			
			if ($content_type != 'text/html') {
				$message = '<html><body>'.wpautop(esc_html($message)).'</body></html>';
				$content_type = 'text/html';
			}
			$message = [
				'from_name' => $from_name,
				'from_email' => $from_email,
				'subject' => $subject,
				'content' => $message
			];

			
			$recipients = [];

			// Set destination addresses, using appropriate methods for handling addresses.
			$address_headers = compact( 'to', 'cc', 'bcc', 'reply_to' );

			foreach ( $address_headers as $address_header => $addresses ) {
				if ( empty( $addresses ) ) {
					continue;
				}

				foreach ( (array) $addresses as $address ) {
					// Break $recipient into name and address parts if in the format "Foo <bar@baz.com>".
					$recipient_name = '';

					if ( preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
						if ( count( $matches ) === 3 ) {
							$recipient_name = $matches[1];
							$address        = $matches[2];
						}
					}

					switch ( $address_header ) {
						case 'to':
						case 'cc':
						case 'bcc':
							$recipients[] = [ $recipient_name, $address ];
							break;
						case 'reply_to':
							if (empty($message['reply_to_email'])) {
								$message['reply_to_email'] = $address;
							} else {
								$message['reply_to_email'] .= ','.$address;
							}
							break;
					}
				}
			}
			$mail_data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );
			
			$success = true;
			foreach ( $recipients as $recipient ) {
				$message['to_name'] = $recipient[0];
				$message['to_email'] = $recipient[1];
				
				$sendResult = wp_remote_post(
					self::API_URL.'/messages',
					[
						'headers' => [
							'Accept' => 'application/json',
							'Authorization' => 'Bearer '.esc_html( $this->get_api_token() )
						],
						'body' => $message,
						'timeout' => 10,
						'sslverify' => false // CHANGE THIS LATER
					]
				);
				
				if (is_wp_error($sendResult)) {
					$success = false;
				} else {
					$response = @json_decode(wp_remote_retrieve_body($sendResult));
					$success = $success && !empty($response->data->id);
				}
			}
			
			if (!$success) {
				throw new Exception();
			}
			
			do_action( 'wp_mail_succeeded', $mail_data ?? [] );
		
			return true;
			
		} catch (Exception $ex) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $ex->getMessage() ), $mail_data ?? [] );
			return false;
		}
	}
}

new Hiveify();

if ( ! function_exists( 'wp_mail' ) ) :
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		return Hiveify::hiveify()->wp_mail($to, $subject, $message, $headers, $attachments);
	}
endif;