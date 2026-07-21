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
	 * The capability the plugin gates its settings and scan endpoints on.
	 *
	 * Resolved in the constructor at plugin load, so it has to be read reflectively.
	 *
	 * @return string
	 */
	protected function get_capability() {
		$property = new ReflectionProperty( BigFileUploads::class, 'capability' );
		$property->setAccessible( true );

		return $property->getValue( $this->bfu() );
	}

	/**
	 * Make the current request look like admin-ajax.php to WordPress.
	 *
	 * Needed for any endpoint that answers with wp_send_json_*(): outside an AJAX request those
	 * helpers call a bare `die`, which takes the test runner down with them. Routing the AJAX die
	 * handler to the test suite's turns it into a catchable WPDieException instead.
	 *
	 * @return void
	 */
	protected function force_ajax_context() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ] );
	}

	/**
	 * Run a request that is expected to end via wp_die(), capturing whatever it echoed.
	 *
	 * @param callable $callback The request to run.
	 *
	 * @return array{output: string, died: bool}
	 */
	protected function capture( callable $callback ) {
		$level = ob_get_level();

		/*
		 * These endpoints send real headers, which php-cli warns about because the test bootstrap
		 * has already printed. That is an artefact of running a web request under CLI, not a
		 * defect, so swallow just that warning - everything else still falls through to PHPUnit's
		 * handler and fails the test.
		 */
		$previous = set_error_handler(
			function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$previous ) {
				if ( false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}

				return $previous ? call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
			}
		);

		ob_start();
		try {
			$callback();

			return [ 'output' => ob_get_clean(), 'died' => false ];
		} catch ( WPDieException $e ) {
			return [ 'output' => ob_get_clean(), 'died' => true ];
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			throw $e;
		} finally {
			restore_error_handler();
		}
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
	 * Empty the media library's upload directory.
	 *
	 * The database is rolled back after every test but the filesystem is not, so a test that
	 * publishes an attachment leaves the file behind and the next "nothing was published" assertion
	 * fails. Core's remove_added_uploads() is not enough on its own: it ignores anything that was
	 * already present when the run started, so files left over from a previous run survive it.
	 *
	 * @return void
	 */
	protected function clear_uploads() {
		$uploads = wp_upload_dir();

		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return;
		}

		/*
		 * Delete the files but leave the directory tree standing. wp_upload_dir() remembers which
		 * year/month directories it has already created and will not recreate one that disappears
		 * underneath it, so removing them makes every later upload fail.
		 */
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isFile() || $file->isLink() ) {
				@unlink( $file->getPathname() );
			}
		}
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
