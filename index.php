<?php
/*
Plugin Name: CROP DEM GIFS
Plugin URI: http://wordpress.org/plugins/crop-dem-gifs/
Description: Help WordPress to generate animated gif thumbnails
Version: 0.0.1
Author: kaisermann
License: GPLv3
*/

define( 'CROPDEMGIFS_BIN_PATH', plugin_dir_path( __FILE__ ) . 'bin/' );

class CROPDEMGIFS {

	private $dir;
	private $sizes;
	private $namespace = 'CROP-DEM-GIFS';

	function __construct() {
		register_activation_hook( __FILE__, [ $this, 'activated' ] );
		register_deactivation_hook( __FILE__, 'deactivated' );
		register_uninstall_hook( __FILE__, 'deactivated' );

		$enable = get_option( $this->namespace . '-enabled', false );
		if ( $enable ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'media' ], 10, 2 );
		}
	}

	function activated() {
		$allowed = $this->requirement();
		chmod( 755, CROPDEMGIFS_BIN_PATH . 'gifsicle' );
		update_option( $this->namespace . '-enabled', $allowed, false );
	}

	static function deactivated() {
		delete_option( $this->namespace . '-enabled' );
	}

	private function requirement() {
		$res = true;

		try {
			$run = $this->exec( 'gifsicle -v' );
			if ( ! empty( $run['stderr'] ) ) {
				$res = false;
			}
		} catch (Exception $e) {
			$res = false;
		}

		return $res;
	}

	private function allSizes() {

		global $_wp_additional_image_sizes;
		$sizes = [];

		foreach ( get_intermediate_image_sizes() as $_size ) {

			if ( in_array( $_size, [ 'thumbnail', 'medium', 'medium_large', 'large' ] ) ) {
				$sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
				$sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
				$sizes[ $_size ]['crop']   = (bool) get_option( "{$_size}_crop" );
			} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
				$sizes[ $_size ] = [
					'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
					'height' => $_wp_additional_image_sizes[ $_size ]['height'],
					'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
				];
			}
		}

		return $sizes;
	}

	/**
	 * From an Attachment ID, generate all "new" thumbnails
	 *
	 * @param $meta
	 * @param $id
	 *
	 * @return $meta
	 */
	public function media( $meta, $id ) {

		$this->dir = wp_upload_dir();
		$this->sizes = $this->allSizes();

		$src = $this->dir['basedir'] . '/' . $meta['file'];
		if ( empty( $src ) ) {
			return $meta;
		}

		$ext = pathinfo( $src, PATHINFO_EXTENSION );
		if ( empty( $ext ) || strtolower( $ext ) !== 'gif' ) {
			return $meta;
		}
		$alreadyDone = [];
		foreach ( $meta['sizes'] as $altSizeName => $params ) {
			$url = dirname( $src ) . '/' . $params['file'];
			if(!isset($alreadyDone[$url])) {
				$originalSize = [ 'width' => intval( $meta['width'] ), 'height' => intval( $meta['height'] ) ];
				$this->convert( $src, $url, $originalSize, $altSizeName );
				$alreadyDone[$url] = true;
			}
		}
		return $meta;
	}

	// width x height
	private function convert( $src, $dst, $originalSize, $altSizeName ) {

		$opt = $this->sizes[ $altSizeName ];
		$ext = pathinfo( basename( $dst ), PATHINFO_EXTENSION );
		$name = substr( basename( $dst ), 0, (strlen( $ext ) + 1) * -1 );
		$dst = dirname( $dst ) . '/' . $name . '.' . $ext;

		$src_w = $originalSize['width'];
		$src_h = $originalSize['height'];

		$dst_w = min( intval( $opt['width'] ), $src_w );
		$dst_h = min( intval( $opt['height'] ), $src_h );
		// error_log( $altSizeName );
		// error_log( 'final-size: ' . $dst_w . 'x' . $dst_h );
		// error_log( 'src-size: ' . $src_w . 'x' . $src_h );

		if ( $src_w < $dst_w && $src_h < $dst_h ) {
			return;
		}

		// src_w = 900
		// src_h = 600
		// dst_w = 245
		// dst_h = 245
		// resize = 900x
		// new_dst_w = 365
		// new_dst_w <= dst_w = 690 <= 330 = false
		// crop = (413 / 2) - (252 / 2),0 = 81
		// gifsicle 'TMBZ01.gif' --resize-fit 900x | gifsicle --crop 0,20+900x560 -o 'TMBZ01-900x560.gif'

		$resize = '';
		$crop = '';

		$srcOrientation = ($src_h <= $src_w) ? 'horizontal' : 'vertical';
		$dstOrientation = ($dst_h <= $dst_w) ? 'horizontal' : 'vertical';

		if ( ! $opt['crop'] ) {
			$resize = "${dst_w}x${dst_h}";
			$crop = '';
		} else {
			$newPredictedSize = 0;
			$crop = "+${dst_w}x${dst_h}";

			if ( $dstOrientation === 'vertical' ) {
				$newPredictedSize = $src_h * $dst_w / $src_w;
				$dstOrientation = ( $newPredictedSize < $dst_h ) ? 'horizontal' : $dstOrientation;
			} else {
				$newPredictedSize = $src_w * $dst_h / $src_h;
				$dstOrientation = ( $newPredictedSize < $dst_w ) ? 'vertical' : $dstOrientation;
			}

			$cropOrientation = $dstOrientation;
			$resize = ($dstOrientation === 'vertical') ? "${dst_w}x" : "x${dst_h}";
			$newPredictedSize = ( $dstOrientation === 'vertical' ) ? $src_h * $dst_w / $src_w : $src_w * $dst_h / $src_h;

			if ( $cropOrientation === 'vertical' ) {
				$crop = '0,' . (floor( $newPredictedSize / 2 ) - floor( $dst_h / 2 )) . $crop;
			} else {
				$crop = (floor( $newPredictedSize / 2 ) - floor( $dst_w / 2 )) . ',0' . $crop;
			}
		}

		if ( $opt['crop'] && $crop !== '' ) {
			$crop = '--crop ' . $crop;
		} else {
			$crop = '';
		}
		$resize = '--resize-fit ' . $resize;

		$gifsicle_src = CROPDEMGIFS_BIN_PATH . 'gifsicle';

		$cmd = sprintf(
			'%s %s %s | %s %s -o %s',
			$gifsicle_src,
			escapeshellarg( $src ),
			$resize,
			$gifsicle_src,
			$crop,
			escapeshellarg( $dst )
		);

		// error_log( 'cmd:' . $cmd );
		$this->exec( $cmd );
	}

	private function exec( $cmd, $input = '' ) {

		$proc = proc_open($cmd, array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		fwrite( $pipes[0], $input );

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$rtn = proc_close( $proc );
		$ret = [
		'stdout' => $stdout,
		'stderr' => $stderr,
		'return' => $rtn,
		];
		// error_log( print_r( $ret, true ) );

		return $ret;
	}
}

new CROPDEMGIFS();
