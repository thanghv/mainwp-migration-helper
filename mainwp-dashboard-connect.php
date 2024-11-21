<?php
/**
 * Plugin Name: MainWP Dashboard Connect
 *
 * Description: MainWP Dashboard Connect.
 *
 * Author: MainWP
 * Author URI: https://mainwp.com
 * Plugin URI: https://mainwp.com/
 * Text Domain: mainwp
 * Version:  5.0.0
 *
 * @package MainWP/Connect
 */

namespace MainWP\Dashboard\Connect;

if ( ! defined( 'MAINWP_DASHBOARD_CONNECT_FILE' ) ) {
	define( 'MAINWP_DASHBOARD_CONNECT_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR' ) ) {
	define( 'MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR', plugin_dir_path( MAINWP_DASHBOARD_CONNECT_FILE ) );
}

if ( ! defined( 'MAINWP_DASHBOARD_CONNECT_URL' ) ) {
	define( 'MAINWP_DASHBOARD_CONNECT_URL', plugin_dir_url( MAINWP_DASHBOARD_CONNECT_FILE ) );
}


class MainWP_Dashboard_Connect_Activator {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private $child_slug = 'mainwp-child/mainwp-child.php';

	/**
	 * Private variable to hold the load_rest_api_data.
	 *
	 * @var mixed Default null
	 */
	private $load_rest_api_data = array();


	/**
	 * Private variable to hold the running.
	 *
	 * @var bool Default false
	 */
	private $running = false;

	/**
	 * Private variable is_child_plugin_installed.
	 *
	 * @var bool Default null
	 */
	private $is_child_plugin_installed = null;

	/**
	 * Private variable to hold the error.
	 *
	 * @var array Default empty
	 */
	private $errors = array();

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @static
	 * @return self
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new self();
			static::$instance->set_logs( '*********** INIT CLASS INSTANCE ***********', false );
		}
		return static::$instance;
	}

	/**
	 * __construct
	 *
	 * @return void
	 */
	private function __construct() {
		register_deactivation_hook( MAINWP_DASHBOARD_CONNECT_FILE, array( $this, 'callback_deactivation' ) );
		register_activation_hook( MAINWP_DASHBOARD_CONNECT_FILE, array( $this, 'callback_activation' ) );

		add_action( 'admin_enqueue_scripts', array( &$this, 'hook_admin_enqueue_scripts' ) );
		add_action( 'admin_init', array( &$this, 'admin_init' ), 9999 );
		add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
	}

	/**
	 * hook_admin_enqueue_scripts
	 *
	 * @return void
	 */
	public function hook_admin_enqueue_scripts() {
		wp_enqueue_script( 'jquery' );
	}

	/**
	 * admin_init
	 *
	 * @return void
	 */
	public function admin_init() {
		add_action( 'wp_ajax_mainwp_dashboard_connect_get_status', array( $this, 'ajax_get_status' ) );
		$this->begin_install_child();
	}

	/**
	 * admin_notice
	 *
	 * @return void
	 */
	public function admin_notice() {
		if ( $this->is_plugins_page() ) {
			$redirect_count = (int) get_option( 'mainwp_connect_requires_redirect_to_completed' );
			if ( empty( $redirect_count ) ) {
				return;
			}

			if ( ! empty( $this->get_db_option( 'mainwp_child_pubkey' ) ) ) {
				$this->clear_redirect_state(); // redirect one more time.
			}

			if ( ! function_exists( 'wp_create_nonce' ) ) {
				include_once ABSPATH . WPINC . '/pluggable.php'; // NOSONAR - WP compatible.
			}

			$this->set_logs( 'Reloading page to complete' );
			?>
			<div id="message" class="notice is-dismissible updated">
				<p><?php esc_html_e( 'Please wait while the dashboard is connecting. The page will reload once the process is complete.' ); ?></p>
				<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.' ); ?></span></button>
			</div>
			<script type="text/javascript">
				jQuery(function ($) {

					function mainwp_dashboard_connect_trigger_checking_status( reloadPage ){
						if(typeof reloadPage !== 'undefined' && 'reload' === reloadPage){
							setTimeout(
								function(){
									window.location.href = location.href;
								},
							1500);
						} else {
							setTimeout(
								function(){
									mainwp_dashboard_connect_checking_status();
								},
							1500);
						}
					}

					mainwp_dashboard_connect_trigger_checking_status();

					function mainwp_dashboard_connect_checking_status(){
						jQuery.ajax({
							url: ajaxurl,
							data: {
								action: 'mainwp_dashboard_connect_get_status',
								security: '<?php echo wp_create_nonce( 'mainwp-dashboard-connect-status' ); ?>'
							},
							method: 'POST',
							success: function (response) {
								if( response?.connected ){
									mainwp_dashboard_connect_trigger_checking_status('reload'); //trigger reload.
								} else {
									mainwp_dashboard_connect_trigger_checking_status(); // trigger connect checking.
								}
							},
							error:  function (response) {
								mainwp_dashboard_connect_trigger_checking_status('reload'); // trigger reload if error.
							},
							dataType: 'json'
						});
					}
				});

			</script>
			<?php
		}
	}

	/**
	 * ajax_get_status
	 *
	 * @return void
	 */
	public function ajax_get_status() {
		if ( isset( $_POST['security'] ) && wp_verify_nonce( $_POST['security'], 'mainwp-dashboard-connect-status' ) ) {
			$connected = $this->is_connected_status();

			if ( $connected ) {
				$this->clear_redirect_state();
			}

			die(
				wp_json_encode(
					array(
						'connected' => $connected ? 1 : 0,
					)
				)
			);
		} else {
			die( -1 );
		}
	}

	/**
	 * is_plugins_page
	 *
	 * @return bool
	 */
	public function is_plugins_page() {
		// Get the current admin screen.
		$screen = get_current_screen();
		if ( $screen ) {
			$this->set_logs( 'is_plugins_page: ' . $screen->base );
		}
		// Check if we are on the plugins.php page.
		if ( is_object( $screen ) && $screen->base === 'plugins' ) {
			return true;
		}
		return false;
	}


	/**
	 * Method include_files()
	 *
	 * Include necessary files.
	 *
	 * @used-by install_plugin_theme() Plugin & Theme Installation functions.
	 */
	private function include_files() {
		if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
			include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR -- WP compatible.
		}
		include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.
		include_once ABSPATH . '/wp-admin/includes/misc.php'; // NOSONAR -- WP compatible.
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php'; // NOSONAR -- WP compatible.
		include_once ABSPATH . '/wp-admin/includes/plugin.php'; // NOSONAR -- WP compatible.
		include_once ABSPATH . WPINC . '/pluggable.php'; // NOSONAR -- WP compatible.
		require_once ABSPATH . 'wp-admin/includes/user.php'; // NOSONAR - WP compatible.
		include_once MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR . 'includes/mainwp-dashboard-connect-skin.php'; // NOSONAR -- WP compatible.
	}


	/**
	 * load_attached_dashboard_rest_data
	 *
	 * @return bool Success.
	 */
	public function load_attached_dashboard_rest_data() {
		$file = MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR . 'includes/delete-me.txt';
		if ( file_exists( $file ) ) {
			$content = file_get_contents( $file );
			if ( ! empty( $content ) ) {
				$data = explode( '||', base64_decode( $content ) );
				if ( is_array( $data ) && ! empty( $data[0] ) && ! empty( $data[1] ) ) {
					$this->load_rest_api_data = array(
						'api_url'  => $data[0],
						'token'    => $data[1],
						'key_pass' => ! empty( $data[2] ) ? $data[2] : '',
					);
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * begin_install_child
	 *
	 * @return void
	 */
	public function begin_install_child() {

		if ( ! $this->running ) {
			$this->running = true;
		} else {
			$this->set_logs( 'RETURN :: Connection process is running (1).' );
			return;
		}

		if ( get_option( 'mainwp_dashboard_connect_rest_request_is_running' ) || get_option( 'mainwp_dashboard_connect_process_is_running' ) ) {
			$this->set_logs( 'RETURN :: Connection process is running (2).' );
			return;
		}

		$this->set_logs( '************* BEGINNING INSTALLATION AND CONNECTION PROCESS *************', false );

		static::update_option( 'mainwp_dashboard_connect_process_is_running', 1 );

		if ( $this->is_connected_status() ) {
			$this->set_logs( 'Dashboard connected.' );
			// If the site was connected, delete the connect plugin.
			$this->remove_connect_plugin();
			$this->redirect_and_exit( admin_url( 'plugins.php' ) );
		}

		if ( ! $this->load_attached_dashboard_rest_data() ) {
			$this->deactive_child_plugin();
			// If the key loading fails, delete the connect plugin.
			$this->remove_connect_plugin();
			$this->set_logs( 'Failed to load REST API attached info.' );
			$this->redirect_and_exit( admin_url( 'plugins.php' ) );
		}

		$this->include_files();

		// Clear previous data if existed before starting the next steps.
		$this->clear_previous_connection_data();

		if ( ! $this->installed_child_plugin() ) {
			$this->set_logs( 'Starting download and install' );
			static::update_option( 'mainwp_connect_requires_redirect_to_completed', 1 );
			$this->try_download_and_install_plugin();
		}

		if ( $this->installed_child_plugin() && ! is_plugin_active( $this->child_slug ) ) {
			$this->set_logs( 'Activating plugin' );
			$this->active_child_plugin();
		}

		if ( is_plugin_active( $this->child_slug ) ) {
			$this->set_logs( 'Trying to connect' );
			$this->try_to_connect();
		}
		$this->clear_running_state();
	}

	/**
	 * try_to_connect
	 *
	 * @param  bool $second second try.
	 *
	 * @return mixed installed.
	 */
	public function try_to_connect( $second = false ) {
		static::update_option( 'mainwp_dashboard_connect_rest_request_is_running', 1 );

		$redirect_url = '';
		$try_error    = false;

		ignore_user_abort( true );
		if ( false === strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		ob_start();
		$result = false;
		if ( is_array( $this->load_rest_api_data ) && ! empty( $this->load_rest_api_data['api_url'] ) ) {
			$result = $this->fetch_dashboard_rest_api( $this->load_rest_api_data );
		}

		if ( true === $result ) {
			// connected success.
			$redirect_url = admin_url( 'plugins.php?activate=1' );
		} elseif ( is_array( $result ) && isset( $result['errorCode'] ) ) {
			$error_code = (int) $result['errorCode'];
			if ( 401 === $error_code || 404 === $error_code ) {
				$this->deactive_child_plugin(); // auth failed or resource not found.
				$this->remove_connect_plugin();
				$redirect_url = admin_url( 'plugins.php' );
			} elseif ( 200 === $error_code && empty( $result['error'] ) && ! $second ) {
				$this->try_to_connect( true );
			} else {
				$try_error = true;
			}
		} else {
			$try_error = true;
		}

		if ( $try_error ) {
			$this->set_logs( 'Failed to connect to the dashboard.' );
			// If the error persists, avoid excessive retries.
			// The user should review the issue - dashboard and child side
			// and attempt to run it again.
			$this->deactive_child_plugin();
			$this->deactive_this_plugin();
			$redirect_url = admin_url( 'plugins.php' );
		}

		ob_end_clean();

		$this->set_logs( 'Connection process finished' );

		// require here before exit.
		$this->clear_running_state();

		if ( ! empty( $redirect_url ) ) {
			$this->redirect_and_exit( $redirect_url );
		}
	}

	/**
	 * set_logs
	 *
	 * @return void
	 */
	public function set_logs( $log, $wrap_char = true ) {
		if ( defined( 'MAINWP_DASHBOARD_CONNECT_DEBUGGING' ) && MAINWP_DASHBOARD_CONNECT_DEBUGGING ) {
			if ( is_scalar( $log ) ) {
				if ( $wrap_char ) {
					error_log( '/============= ' . $log . ' =============/' );
				} else {
					error_log( $log );
				}
			} elseif ( is_array( $log ) || is_object( $log ) ) {
				error_log( print_r( $log, true ) );
			}
		}
		$this->errors[] = $log;
	}

	/**
	 * redirect_and_exit
	 *
	 * @return void
	 */
	public function redirect_and_exit( $url ) {
		$this->set_logs( 'redirect and exit' );
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * is_connected_status
	 *
	 * @return bool installed.
	 */
	public function is_connected_status() {
		// get_option is ok.
		if ( ! empty( get_option( 'mainwp_child_pubkey' ) ) && ! empty( get_option( 'mainwp_child_server' ) ) && is_plugin_active( $this->child_slug ) ) {
			return true;
		}
		return false; // Forced to false, ensuring it will reconnect to the dashboard for connect.
	}

	/**
	 * Method remove connect plugin when site connected.
	 *
	 * @return void
	 */
	public function remove_connect_plugin() {
		$this->deactive_this_plugin();
		$my_dir = rtrim( MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR, '/' );
		if ( is_dir( $my_dir ) ) {
			$deleted  = $this->delete_dir( $my_dir );
			$del_file = MAINWP_DASHBOARD_CONNECT_PLUGIN_DIR . 'includes/delete-me.txt';
			if ( ! $deleted && file_exists( $del_file ) ) {
				@unlink( $del_file ); // if del the folder failed, then try to del secure file.
			}
		}
	}


	/**
	 * installed_child_plugin
	 *
	 * @return bool installed.
	 */
	public function installed_child_plugin() {
		if ( null === $this->is_child_plugin_installed ) {
			$this->is_child_plugin_installed = is_dir( WP_PLUGIN_DIR . '/mainwp-child' ) ? true : false;
			$this->set_logs( 'Child Plugin Installed: ' . ( $this->is_child_plugin_installed ? 'Yes' : 'No' ) );
		}
		return $this->is_child_plugin_installed;
	}

	/**
	 * active_child_plugin
	 *
	 * @return mixed installed.
	 */
	public function active_child_plugin() {
		$this->set_logs( 'active_child_plugin' );
		$this->disable_auto_updates();
		activate_plugin( $this->child_slug );
	}

	/**
	 * active_child_plugin
	 *
	 * @return mixed installed.
	 */
	public function disable_auto_updates() {
		add_filter( 'auto_update_translation', '__return_false' );
		add_filter( 'automatic_updater_disabled', '__return_true' ); // to prevent auto update on this version check.
		remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );
	}

	/**
	 * deactive_child_plugin
	 *
	 * @return mixed installed.
	 */
	public function deactive_child_plugin() {
		if ( is_plugin_active( $this->child_slug ) && empty( $this->get_db_option( 'mainwp_child_pubkey' ) ) ) {
			$this->set_logs( 'Deactivate child plugin' );
			deactivate_plugins( $this->child_slug, true );
		}
	}


	/**
	 * deactive_this_plugin
	 *
	 * @return mixed installed.
	 */
	public function deactive_this_plugin() {
		$this->callback_deactivation();
		deactivate_plugins( plugin_basename( MAINWP_DASHBOARD_CONNECT_FILE ), true );
	}


	/**
	 * callback_deactivation
	 *
	 * @return mixed installed.
	 */
	public function callback_deactivation() {
		$this->clear_running_state();
		$this->clear_redirect_state();
		$this->set_logs( 'Deactivate Dashboard Connect Plugin' );
	}

	/**
	 * callback_deactivation
	 *
	 * @return mixed installed.
	 */
	public function callback_activation() {
		$this->clear_running_state(); // To fix situations where old values are present.
		$this->clear_redirect_state();
		$this->set_logs( 'Activate Dashboard Connection Plugin' );
	}

	/**
	 * callback_deactivation
	 *
	 * @return mixed installed.
	 */
	public function clear_running_state() {
		delete_option( 'mainwp_dashboard_connect_rest_request_is_running' );
		delete_option( 'mainwp_dashboard_connect_process_is_running' );
	}

	/**
	 * clear_redirect_state
	 *
	 * @return mixed installed.
	 */
	public function clear_redirect_state() {
		delete_option( 'mainwp_connect_requires_redirect_to_completed' );
	}

	/**
	 * clear_previous_connection_data
	 *
	 * @return void
	 */
	public function clear_previous_connection_data() {
		$to_delete   = array(
			'mainwp_child_pubkey',
			'mainwp_child_nonce',
			'mainwp_security',
			'mainwp_child_server',
			'mainwp_child_connected_admin',
		);
		$to_delete[] = 'mainwp_ext_snippets_enabled';
		$to_delete[] = 'mainwp_ext_code_snippets';
		$to_delete[] = 'mainwp_child_openssl_sign_algo';

		foreach ( $to_delete as $delete ) {
			if ( get_option( $delete ) ) {
				delete_option( $delete );
				wp_cache_delete( $delete, 'options' );
			}
		}
	}


	/**
	 * fetch_dashboard_rest_api
	 *
	 * @param  mixed $rest_data api.
	 * @return mixed
	 */
	public function fetch_dashboard_rest_api( $rest_data = array() ) {
		$url   = $rest_data['api_url'];
		$token = $rest_data['token'];

		$admin_name = $this->get_admin_name();

		if ( empty( $url ) || empty( $token ) || empty( $admin_name ) ) {
			return false;
		}

		$unique_id = get_option( 'mainwp_child_uniqueId' );
		if ( empty( $unique_id ) ) {
			$unique_id = \wp_generate_password( 12, false );
			static::update_option( 'mainwp_child_uniqueId', $unique_id );
		}

		$body = array(
			'url'      => get_bloginfo( 'url' ),
			'name'     => get_bloginfo( 'name' ),
			'admin'    => $admin_name,
			'uniqueid' => $unique_id,
			'key_pass' => $rest_data['key_pass'],
		);

		$this->set_logs( 'Remote Posting' );
		$this->set_logs( $body );

		$http_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
			'timeout' => 200,
			'body'    => $body,
		);

		$error = '';

		$request = wp_remote_post( $url, $http_args );

		if ( is_wp_error( $request ) ) {
			$_error = $request->get_error_message();
			$this->set_logs( 'error' );
			$this->set_logs( $_error );
		}

		$data   = wp_remote_retrieve_body( $request );
		$result = ! empty( $data ) ? json_decode( $data, true ) : array();

		$this->set_logs( 'result' );
		$this->set_logs( $result );

		if ( is_array( $result ) ) {
			if ( ! empty( $result['success'] ) ) {
				// site connected.
				$this->remove_connect_plugin();
				return true;
			} elseif ( ! empty( $result['found_id'] ) ) {
				// The connection process is complete: either reconnect_success is not empty.
				// or it is empty but the public key is present.
				if ( ! empty( $result['reconnect_success'] ) ) {
					$this->remove_connect_plugin(); // disable redirect.
					return true;
				} else {
					$pubk = $this->get_db_option( 'mainwp_child_pubkey' );
					if ( ! empty( $pubk ) ) {
						$this->set_logs( 'reconnect_success is empty, but pubkey is not empty, connection process is complete.' );
						return true;
					}
				}
				return false;
			} elseif ( ! empty( $result['error'] ) ) {
				$error = $result['error'];
			}
		}

		$response_code = wp_remote_retrieve_response_code( $request );
		$return_error  = array(
			'errorCode' => $response_code,
			'error'     => $error,
		);
		$this->set_logs( $return_error );

		return $return_error;
	}

	/**
	 * Method update_option()
	 *
	 * Update option.
	 *
	 * @param string $option_name Contains the option name.
	 * @param string $option_value Contains the option value.
	 * @param string $autoload Autoload? Yes or no.
	 *
	 * @return bool $success true|false Option updated.
	 */
	public static function update_option( $option_name, $option_value, $autoload = 'no' ) {
		$success = add_option( $option_name, $option_value, '', $autoload );
		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}
		return $success;
	}

	/**
	 * try_download_and_install_plugin
	 *
	 * @return bool Success.
	 */
	public function try_download_and_install_plugin( $url = '' ) {

		if ( empty( $url ) ) {
			$response = $this->get_plugin_info();
			if ( is_object( $response ) && property_exists( $response, 'download_link' ) ) {
				$url = $response->download_link;
			}
		}

		if ( empty( $url ) ) {
			return false;
		}

		ob_start();

		$this->set_logs( 'Trying to download and install' );

		$this->disable_auto_updates();

		$skin = new MainWP_Dashboard_Connect_Upgrader_Skin();

		$installer = new \WP_Upgrader( $skin );

		$result = $installer->run(
			array(
				'package'                     => $url,
				'destination'                 => WP_PLUGIN_DIR,
				'clear_destination'           => false,
				'clear_working'               => true,
				'abort_if_destination_exists' => false,
				'hook_extra'                  => array(),
			)
		);

		ob_get_clean();

		if ( is_wp_error( $result ) ) {
			$this->set_logs( 'try_download_and_install_plugin::error' );
			$this->set_logs( $result );
		}

		$success = is_array( $result ) && ! empty( $result['source_files'] ) ? true : false;

		return $success;
	}


	/**
	 * get_plugin_info
	 *
	 * @return mixed
	 */
	public function get_plugin_info() {

		$url = 'http://api.wordpress.org/plugins/info/1.2/';
		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => 'mainwp-child',
					'fields' => array(
						'sections' => false,
						'versions' => false,
						'icons'    => false,
					),
				),
			),
			$url
		);

		$http_url = $url;
		$ssl      = wp_http_supports( array( 'ssl' ) );
		if ( $ssl ) {
			$url = set_url_scheme( $url, 'https' );
		}

		global $wp_version;

		$http_args = array(
			'timeout'    => 15,
			'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url( '/' ),
		);
		$request   = wp_remote_get( $url, $http_args );

		if ( $ssl && is_wp_error( $request ) ) {
			$request = wp_remote_get( $http_url, $http_args );
		}

		if ( ! is_wp_error( $request ) ) {
			$res = json_decode( wp_remote_retrieve_body( $request ), true );
			if ( is_array( $res ) ) {
				// Object casting is required in order to match the info/1.0 format.
				return (object) $res;
			}
		}

		return false;
	}


	/**
	 * Method get_db_option()
	 *
	 * @param string $option Option name.
	 *
	 * @return mixed Return option value.
	 */
	public function get_db_option( $option ) {
		$value = get_option( $option );
		if ( ! empty( $value ) ) {
			return $value;
		}
		/**
		 * WP Database object.
		 *
		 * @global object $wpdb WordPress object.
		 */
		global $wpdb;
		$query = $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $option );
		return $wpdb->get_var( $query ); // phpcs:ignore -- ok.
	}


	/**
	 * get_admin_name
	 *
	 * @return string|false admin name.
	 */
	public function get_admin_name() {
		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		if ( is_object( $current_user ) && ! empty( $current_user->ID ) && ( ( property_exists( $current_user, 'wp_user_level' ) && 10 === (int) $current_user->wp_user_level ) || ( isset( $current_user->user_level ) && 10 === (int) $current_user->user_level ) || $this->check_user_has_role( 'administrator', $current_user ) ) ) {
			return $current_user->user_login;
		}

		// Fetch admin users ordered by ID
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
			)
		);

		if ( is_array( $admin_users ) && ! empty( $admin_users ) ) {
			return $admin_users[0]->user_login;
		}

		return false;
	}

	/**
	 * Method current_user_has_role()
	 *
	 * Check if the user has role.
	 *
	 * @param array|string $roles role or array of roles to check.
	 * @param object|null  $user user check.
	 *
	 * @return bool true|false If the user is administrator (Level 10), return true, if not, return false.
	 */
	public function check_user_has_role( $roles, $user ) {

		if ( empty( $user ) || ! is_object( $user ) || empty( $user->ID ) || empty( $user->roles ) ) {
			return false;
		}

		if ( is_string( $roles ) ) {
			$allowed_roles = array( $roles );
		} elseif ( is_array( $roles ) ) {
			$allowed_roles = $roles;
		} else {
			return false;
		}

		if ( array_intersect( $allowed_roles, $user->roles ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Method delete_dir()
	 *
	 * @param string $dir dir path.
	 */
	public function delete_dir( $dir ) {
		$this->get_wp_filesystem();
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) && $wp_filesystem->connect() ) {
			$del = $wp_filesystem->delete( $dir, true );
		} else {
			$del = rmdir( $dir );// phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $del;
	}

	/**
	 * Method get_wp_filesystem()
	 *
	 * Get the WordPress filesystem.
	 *
	 * @return mixed $init WordPress filesystem base.
	 */
	public function get_wp_filesystem() {
		/**
		 * Global variable containing the instance of the (auto-)configured filesystem object after the filesystem "factory" has been run.
		 *
		 * @global object $wp_filesystem Filesystem object.
		 */
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			ob_start();
			if ( file_exists( ABSPATH . '/wp-admin/includes/screen.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/screen.php'; // NOSONAR -- WP compatible.
			}
			if ( file_exists( ABSPATH . '/wp-admin/includes/template.php' ) ) {
				include_once ABSPATH . '/wp-admin/includes/template.php'; // NOSONAR -- WP compatible.
			}
			$creds = request_filesystem_credentials( 'test' );
			ob_end_clean();
			if ( empty( $creds ) ) {
				/**
				 * Defines file system method.
				 *
				 * @const ( string ) Defined file system method.
				 * @source https://code-reference.mainwp.com/classes/MainWP.Child.MainWP_Helper.html
				 */
				if ( ! defined( 'FS_METHOD' ) ) {
					define( 'FS_METHOD', 'direct' );
				}
			}
			$init = \WP_Filesystem( $creds );
		} else {
			$init = true;
		}
		return $init;
	}
}


MainWP_Dashboard_Connect_Activator::instance();
