<?php
/**
 * Plugin Name: MainWP Migration Helper
 *
 * Description: MainWP Migration Helper.
 *
 * Author: MainWP
 * Author URI: https://mainwp.com
 * Plugin URI: https://mainwp.com/
 * Text Domain: mainwp
 * Version:  5.0.0
 *
 * @package MainWP/Migration
 */

namespace MainWP\Migration;

if ( ! defined( 'MAINWP_MIGRATION_HELPER_FILE' ) ) {
	define( 'MAINWP_MIGRATION_HELPER_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_MIGRATION_HELPER_PLUGIN_DIR' ) ) {
	define( 'MAINWP_MIGRATION_HELPER_PLUGIN_DIR', plugin_dir_path( MAINWP_MIGRATION_HELPER_FILE ) );
}

if ( ! defined( 'MAINWP_MIGRATION_HELPER_URL' ) ) {
	define( 'MAINWP_MIGRATION_HELPER_URL', plugin_dir_url( MAINWP_MIGRATION_HELPER_FILE ) );
}

if ( ! defined( 'MAINWP_MIGRATION_HELPER_DEVELOPMENT' ) ) {
	define( 'MAINWP_MIGRATION_HELPER_DEVELOPMENT', true );
}


class MainWP_Migration_Helper_Activator {

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
	private $my_slug = 'mainwp-migration-helper/mainwp-migration-helper.php';

	/**
	 * Private variable to hold the load_rest_api_data.
	 *
	 * @var mixed Default null
	 */
	private $load_rest_api_data = array();

	/**
	 * Private variable to hold the finished_redirect_path.
	 *
	 * @var mixed Default null
	 */
	private $finished_redirect_path = null;

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
		}
		return static::$instance;
	}

	/**
	 * __construct
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ), 9999 );
	}

	/**
	 * admin_init
	 *
	 * @return void
	 */
	public function admin_init() {
		$this->begin_install_child();
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
		include_once MAINWP_MIGRATION_HELPER_PLUGIN_DIR . 'includes/mainwp-migration-helper-skin.php'; // NOSONAR -- WP compatible.
	}


	/**
	 * load_attached_dashboard_rest_data
	 *
	 * @return bool Success.
	 */
	public function load_attached_dashboard_rest_data() {
		$file = MAINWP_MIGRATION_HELPER_PLUGIN_DIR . 'includes/delete-me.txt';
		if ( file_exists( $file ) ) {
			$content = file_get_contents( $file );
			if ( ! empty( $content ) ) {
				$data = explode( '||', base64_decode( $content ) );
				if ( is_array( $data ) && ! empty( $data[0] ) && ! empty( $data[1] ) ) {
					$this->load_rest_api_data = array(
						'api_url' => $data[0],
						'token'   => $data[1],
						'pass'    => ! empty( $data[2] ) ? $data[2] : '',
					);
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * is_development_valid_check
	 *
	 * @return mixed values.
	 */
	public function is_development_valid_check() {

		if ( ! defined( 'MAINWP_MIGRATION_HELPER_DEVELOPMENT' ) || ! MAINWP_MIGRATION_HELPER_DEVELOPMENT ) {
			return true;
		}

		global $pagenow;

		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( 'index.php' === $pagenow || 'plugins.php' === $pagenow ) {
			$this->finished_redirect_path = $pagenow;
			return true;
		}
		return false;
	}

	/**
	 * begin_install_child
	 *
	 * @return mixed values.
	 */
	public function begin_install_child() {

		if ( ! $this->is_development_valid_check() ) {
			return;
		}

		if ( $this->load_attached_dashboard_rest_data() ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WPCS.

			if ( ! $this->installed_child_plugin() ) {
				$this->download_and_install_plugin();
			}
			if ( $this->installed_child_plugin() && ! is_plugin_active( 'mainwp-child/mainwp-child.php' ) ) {
				$this->active_child_plugin();
			}

			if ( is_plugin_active( 'mainwp-child/mainwp-child.php' ) ) {
				if ( ! $this->is_connected_child() ) {
					$connected = $this->request_dashboard_connect();
					if ( $connected ) {
						deactivate_plugins( $this->my_slug );
						$this->remove_migration_plugin();
					}
				} else {
					deactivate_plugins( $this->my_slug );
					$this->remove_migration_plugin();
				}
			}
		}
	}


	/**
	 * is_connected_child
	 *
	 * @return bool installed.
	 */
	public function is_connected_child() {
		if ( ! empty( get_option( 'mainwp_child_pubkey' ) ) && ! empty( get_option( 'mainwp_child_server' ) ) ) {
			return true;
		}
		return false;
	}


	/**
	 * remove_migration_plugin
	 *
	 * @return void
	 */
	public function remove_migration_plugin() {
		$my_dir = rtrim( MAINWP_MIGRATION_HELPER_PLUGIN_DIR, '/' );
		if ( is_dir( $my_dir ) ) {
			$deleted  = $this->delete_dir( $my_dir );
			$del_file = MAINWP_MIGRATION_HELPER_PLUGIN_DIR . 'includes/delete-me.txt';
			if ( ! $deleted && file_exists( $del_file ) ) {
				@unlink( $del_file ); // if del the folder failed, then try to del secure file.
			}
			if ( ! empty( $this->finished_redirect_path ) ) {
				wp_safe_redirect( get_admin_url( null, $this->finished_redirect_path ) );
				exit();
			}
		}
	}

	/**
	 * installed_child_plugin
	 *
	 * @return bool installed.
	 */
	public function installed_child_plugin() {
		return is_dir( dirname( MAINWP_MIGRATION_HELPER_PLUGIN_DIR ) . '/mainwp-child' ) ? true : false;
	}

	/**
	 * active_child_plugin
	 *
	 * @return mixed installed.
	 */
	public function active_child_plugin() {
		activate_plugin( WP_PLUGIN_DIR . '/mainwp-child/mainwp-child.php' );
	}

	/**
	 * request_dashboard_connect
	 *
	 * @return mixed installed.
	 */
	public function request_dashboard_connect() {
		if ( ! empty( get_option( 'mainwp_child_pubkey' ) ) || ! empty( get_option( 'mainwp_child_server' ) ) ) {
			return false;
		}

		if ( is_array( $this->load_rest_api_data ) && ! empty( $this->load_rest_api_data['api_url'] ) ) {
			return $this->fetch_dashboard_rest_api( $this->load_rest_api_data );
		}
		return false;
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

		$http_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => array(
				'url'            => get_bloginfo( 'url' ),
				'name'           => get_bloginfo( 'name' ),
				'admin'          => $admin_name,
				'uniqueid'       => $unique_id,
				'migration_pass' => $rest_data['pass'],
			),
		);

		$request = wp_remote_post( $url, $http_args );

		if ( is_array( $request ) && ! empty( $request['success'] ) ) {
			return true;
		}

		return false;
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
	 * download_and_install_plugin
	 *
	 * @return bool Success.
	 */
	public function download_and_install_plugin() {
		$this->include_files();
		ob_start();
		$result  = $this->try_install();
		$success = is_array( $result ) && ! empty( $result['source_files'] ) ? true : false;
		ob_get_clean();
		return $success;
	}

	/**
	 * try_install
	 *
	 * @param  mixed $url
	 * @param  mixed $second
	 * @return mixed
	 */
	private function try_install( $url = '', $second = false ) {

		if ( empty( $url ) ) {
			$response = $this->get_plugin_info();
			if ( is_object( $response ) && property_exists( $response, 'download_link' ) ) {
				$url = $response->download_link;
			}
		}

		if ( empty( $url ) ) {
			return false;
		}

		add_filter( 'automatic_updater_disabled', '__return_true' ); // to prevent auto update on this version check.
		remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );

		$skin = new MainWP_Migration_Helper_Upgrader_Skin();

		$installer = new \WP_Upgrader( $skin );

		$result = $installer->run(
			array(
				'package'           => $url,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => false,
				'clear_working'     => true,
				'hook_extra'        => array(),
			)
		);

		if ( is_wp_error( $result ) && ! $second ) {
			usleep( 10000 );
			$this->try_install( $url, true ); // try one more time.
		}

		return $result;
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

		if ( is_object( $current_user ) && ! empty( $current_user->ID ) && ( ( property_exists( $current_user, 'wp_user_level' ) && 10 === (int) $current_user->wp_user_level ) || ( isset( $current_user->user_level ) && 10 === (int) $current_user->user_level ) || $this->check_user_has_role( 'administrator' ) ) ) {
			return $current_user->user_login;
		}

		$email = get_bloginfo( 'admin_email' );
		$user  = get_user_by( 'email', $email );

		if ( is_object( $user ) && ! empty( $user->ID ) && ( ( property_exists( $user, 'wp_user_level' ) && 10 === (int) $user->wp_user_level ) || ( isset( $user->user_level ) && 10 === (int) $user->user_level ) || $this->check_user_has_role( 'administrator' ) ) ) {
			return $user->user_login;
		}

		$super_admins = get_super_admins();

		if ( $super_admins && count( $super_admins ) ) {
			return current( $super_admins );
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


MainWP_Migration_Helper_Activator::instance();
