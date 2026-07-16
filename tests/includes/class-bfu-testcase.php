<?php
/**
 * Shared base class for Big File Uploads tests.
 *
 * @package BigFileUploads
 */

/**
 * Resets the plugin's stored state between tests and provides fixture helpers.
 */
abstract class BFU_TestCase extends WP_UnitTestCase {

	/**
	 * Directories to remove during teardown.
	 *
	 * @var string[]
	 */
	protected $scratch_dirs = [];

	/**
	 * The instance's max_upload_size as it was before a test overrode it.
	 *
	 * @var int|null
	 */
	private $original_max_upload_size;

	public function set_up() {
		parent::set_up();

		$this->reset_plugin_state();
		$this->original_max_upload_size = $this->get_max_upload_size();
	}

	public function tear_down() {
		foreach ( $this->scratch_dirs as $dir ) {
			$this->rmdir_recursive( $dir );
		}
		$this->scratch_dirs = [];

		if ( null !== $this->original_max_upload_size ) {
			$this->set_max_upload_size( $this->original_max_upload_size );
		}

		$this->reset_plugin_state();

		unset(
			$_POST['bfu_settings_submit'],
			$_POST['_wpnonce'],
			$_POST['by_role'],
			$_POST['upload_limit'],
			$_POST['upload_limit_format']
		);

		parent::tear_down();
	}

	/**
	 * The plugin singleton under test.
	 *
	 * @return BigFileUploads
	 */
	protected function bfu() {
		return BigFileUploads::get_instance();
	}

	/**
	 * Clear every site option the plugin persists.
	 *
	 * @return void
	 */
	protected function reset_plugin_state() {
		delete_site_option( 'tuxbfu_settings' );
		delete_site_option( 'tuxbfu_max_upload_size' );
		delete_site_option( 'tuxbfu_file_scan' );
	}

	/**
	 * Store a settings array as the plugin would find it.
	 *
	 * @param array $settings Raw settings array.
	 *
	 * @return void
	 */
	protected function set_settings( array $settings ) {
		update_site_option( 'tuxbfu_settings', $settings );
	}

	/**
	 * Read the instance's cached PHP/server upload ceiling.
	 *
	 * Captured in the constructor at plugin load, so it has to be read reflectively.
	 *
	 * @return int
	 */
	protected function get_max_upload_size() {
		$property = new ReflectionProperty( BigFileUploads::class, 'max_upload_size' );
		$property->setAccessible( true );

		return $property->getValue( $this->bfu() );
	}

	/**
	 * Override the instance's cached PHP/server upload ceiling.
	 *
	 * Restored automatically in tear_down().
	 *
	 * @param int $bytes Ceiling in bytes.
	 *
	 * @return void
	 */
	protected function set_max_upload_size( $bytes ) {
		$property = new ReflectionProperty( BigFileUploads::class, 'max_upload_size' );
		$property->setAccessible( true );
		$property->setValue( $this->bfu(), $bytes );
	}

	/**
	 * Create a scratch directory that is removed during teardown.
	 *
	 * @param string $prefix Optional name prefix.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	protected function make_scratch_dir( $prefix = 'bfu-test-' ) {
		$dir = untrailingslashit( get_temp_dir() ) . '/' . $prefix . wp_generate_password( 12, false );
		mkdir( $dir, 0777, true );
		$this->scratch_dirs[] = $dir;

		return $dir;
	}

	/**
	 * Write a fixture file, creating parent directories as needed.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 *
	 * @return string The path written.
	 */
	protected function write_file( $path, $contents ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path.
	 *
	 * @return void
	 */
	protected function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// A test may have chmod'd a directory unreadable on purpose; make it removable again.
		@chmod( $dir, 0755 );

		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			@rmdir( $dir );

			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path );
			}
		}

		@rmdir( $dir );
	}
}
