<?php

namespace Perxel\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serving layer.
 *
 * Primary: a managed .htaccess block that lets Apache swap in foo.jpg.webp
 * when the browser sends Accept: image/webp. No PHP per request.
 *
 * Fallback (non-Apache or unwritable .htaccess): rewrite <img> to <picture>.
 *
 * The effective mode is cached in an option so the front end never touches the
 * filesystem; admin_init reconciles it.
 */
class Serve {

	const MARKER      = 'Perxel Image Optimizer';
	const MODE_OPTION = 'perxel_image_optimizer_serve_mode'; // apache|fallback|off

	/**
	 * Hooks.
	 */
	public function register() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'reconcile' ) );
		}

		if ( 'fallback' === $this->mode() ) {
			add_filter( 'wp_content_img_tag', array( $this, 'filter_img_tag' ), 20, 3 );
			add_filter( 'post_thumbnail_html', array( $this, 'filter_thumbnail_html' ), 20 );
		}
	}

	/**
	 * Keep the cached mode + .htaccess block honest. Admin only.
	 */
	public function reconcile() {
		if ( ! Settings::get( 'serve' ) ) {
			$this->set_mode( 'off' );
			return;
		}

		if ( Environment::is_apache() && Environment::htaccess_writable() ) {
			if ( ! $this->block_present() ) {
				$this->write_block();
			}
			$this->set_mode( $this->block_present() ? 'apache' : 'fallback' );
			return;
		}

		$this->set_mode( 'fallback' );
	}

	/**
	 * Effective serving mode (cached).
	 *
	 * @return string apache|fallback|off
	 */
	public function mode() {
		if ( ! Settings::get( 'serve' ) ) {
			return 'off';
		}

		$mode = get_option( self::MODE_OPTION, '' );

		if ( in_array( $mode, array( 'apache', 'fallback' ), true ) ) {
			return $mode;
		}

		// Not computed yet. In admin, derive it now (touches the filesystem);
		// on the front end assume the fallback until admin_init reconciles.
		if ( ! is_admin() ) {
			return 'fallback';
		}

		return Environment::is_apache() && $this->block_present() ? 'apache' : 'fallback';
	}

	/**
	 * Turn serving on.
	 *
	 * @return array {@type bool $ok, @type string $mode, @type string $message}
	 */
	public function enable() {
		$this->set_serve_setting( true );

		if ( Environment::is_apache() && Environment::htaccess_writable() ) {
			$ok = $this->write_block();
			$this->set_mode( $ok ? 'apache' : 'fallback' );

			return array(
				'ok'      => $ok,
				'mode'    => $ok ? 'apache' : 'fallback',
				'message' => $ok
					? __( 'Serving via .htaccess.', 'perxel-image-optimizer' )
					: __( 'Could not write .htaccess — using the <picture> fallback.', 'perxel-image-optimizer' ),
			);
		}

		$this->set_mode( 'fallback' );

		return array(
			'ok'      => true,
			'mode'    => 'fallback',
			'message' => __( 'Not Apache or .htaccess not writable — using the <picture> fallback.', 'perxel-image-optimizer' ),
		);
	}

	/**
	 * Turn serving off — removes the block.
	 */
	public function disable() {
		$this->set_serve_setting( false );
		$this->remove_block();
		$this->set_mode( 'off' );
	}

	/**
	 * @return bool
	 */
	public function block_present() {
		$path = Environment::htaccess_path();

		if ( ! file_exists( $path ) ) {
			return false;
		}

		return false !== strpos( (string) file_get_contents( $path ), '# BEGIN ' . self::MARKER );
	}

	/**
	 * @return bool
	 */
	public function write_block() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return insert_with_markers( Environment::htaccess_path(), self::MARKER, $this->rules() );
	}

	/**
	 * @return bool
	 */
	public function remove_block() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$path = Environment::htaccess_path();

		if ( ! file_exists( $path ) ) {
			return true;
		}

		return insert_with_markers( $path, self::MARKER, array() );
	}

	/**
	 * The rewrite rules, as an array of lines.
	 *
	 * @return string[]
	 */
	public function rules() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_get_upload_dir();

		// uploads path relative to the directory that holds this .htaccess
		// (the home path) — works for sub-directory installs too.
		$home   = untrailingslashit( str_replace( '\\', '/', get_home_path() ) );
		$base   = str_replace( '\\', '/', $upload['basedir'] );
		$prefix = ltrim( str_replace( $home, '', $base ), '/' );

		if ( '' === $prefix || $prefix === $base ) {
			// Fallback: derive from the URL path.
			$prefix = ltrim( (string) wp_parse_url( $upload['baseurl'], PHP_URL_PATH ), '/' );
		}

		$prefix = preg_quote( $prefix, '/' );

		return array(
			'<IfModule mod_rewrite.c>',
			'	RewriteEngine On',
			'	RewriteCond %{HTTP_ACCEPT} image/webp',
			'	RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}\.webp -f',
			'	RewriteRule ^' . $prefix . '/(.+)\.(jpe?g|png)$ ' . $prefix . '/$1.$2.webp [T=image/webp,E=WEBP:1,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'	<FilesMatch "\.(jpe?g|png)$">',
			'		Header append Vary Accept',
			'	</FilesMatch>',
			'</IfModule>',
			'<IfModule mod_mime.c>',
			'	AddType image/webp .webp',
			'</IfModule>',
		);
	}

	/**
	 * @return string Preview for the admin page.
	 */
	public function rules_preview() {
		return '# BEGIN ' . self::MARKER . "\n" . implode( "\n", $this->rules() ) . "\n# END " . self::MARKER;
	}

	/**
	 * @param bool $on Serve setting.
	 */
	private function set_serve_setting( $on ) {
		$all          = Settings::all();
		$all['serve'] = (bool) $on;
		update_option( Settings::OPTION, $all, false );
	}

	/**
	 * @param string $mode apache|fallback|off.
	 */
	private function set_mode( $mode ) {
		update_option( self::MODE_OPTION, $mode, true );
	}

	/* --------------------------------------------------------------------- *
	 * <picture> fallback
	 * --------------------------------------------------------------------- */

	/**
	 * @param string $filtered_image <img> HTML.
	 * @param string $context        Filter context.
	 * @param int    $attachment_id  Attachment ID.
	 * @return string
	 */
	public function filter_img_tag( $filtered_image, $context = '', $attachment_id = 0 ) {
		return $this->wrap_picture( $filtered_image );
	}

	/**
	 * @param string $html Thumbnail HTML.
	 * @return string
	 */
	public function filter_thumbnail_html( $html ) {
		return $this->wrap_picture( $html );
	}

	/**
	 * Wrap an <img> whose sources have .webp siblings in a <picture>.
	 *
	 * @param string $img_html <img ...> markup.
	 * @return string
	 */
	private function wrap_picture( $img_html ) {
		if ( false === stripos( $img_html, '<img' ) || false !== stripos( $img_html, '<picture' ) ) {
			return $img_html;
		}

		$upload = wp_get_upload_dir();

		$to_webp = static function ( $url ) use ( $upload ) {
			if ( strpos( $url, $upload['baseurl'] ) !== 0 ) {
				return null;
			}
			if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $url ) ) {
				return null;
			}
			$path = str_replace( $upload['baseurl'], $upload['basedir'], strtok( $url, '?' ) );

			return file_exists( $path . '.webp' ) ? $url . '.webp' : null;
		};

		$srcset_webp = null;

		if ( preg_match( '/srcset=("|\')(.*?)\1/is', $img_html, $m ) ) {
			$parts = array_map( 'trim', explode( ',', $m[2] ) );
			$out   = array();
			foreach ( $parts as $part ) {
				$bits = preg_split( '/\s+/', trim( $part ), 2 );
				$w    = $to_webp( $bits[0] );
				if ( ! $w ) {
					return $img_html; // partial coverage — leave it alone
				}
				$out[] = $w . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
			}
			$srcset_webp = implode( ', ', $out );
		} elseif ( preg_match( '/src=("|\')(.*?)\1/is', $img_html, $m ) ) {
			$srcset_webp = $to_webp( $m[2] );
		}

		if ( ! $srcset_webp ) {
			return $img_html;
		}

		$sizes = '';
		if ( preg_match( '/sizes=("|\')(.*?)\1/is', $img_html, $m ) ) {
			$sizes = ' sizes="' . esc_attr( $m[2] ) . '"';
		}

		$source = '<source type="image/webp" srcset="' . esc_attr( $srcset_webp ) . '"' . $sizes . ' />';

		return '<picture>' . $source . $img_html . '</picture>';
	}
}
