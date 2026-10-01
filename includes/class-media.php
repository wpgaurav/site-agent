<?php
/**
 * Media library discovery, imports and metadata.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Decoded uploads are written to a private temporary file before core sideloads them.
/** Media library discovery, imports and metadata. */
final class Media {
	/** Base64 uploads stay small enough for a JSON-RPC request body; URL imports use WordPress's upload limit. */
	const MAX_INLINE_BYTES = 10485760;

	public static function listing( array $input ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $input['limit'] ?? 20,
			'paged'          => $input['page'] ?? 1,
			's'              => $input['search'] ?? '',
		);
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = $input['mime_type'];
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = self::item( $post->ID );
			}
		}
		return array(
			'media' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	public static function item( int $id ): array {
		$post = get_post( $id );
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'        => $id,
			'title'     => $post ? $post->post_title : '',
			'url'       => (string) wp_get_attachment_url( $id ),
			'mime_type' => $post ? $post->post_mime_type : '',
			'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => $post ? $post->post_excerpt : '',
			'width'     => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
			'height'    => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
			'parent'    => $post ? (int) $post->post_parent : 0,
		);
	}

	/** Import a file into the media library from a URL or base64 data, through core's sideload checks. */
	public static function upload( array $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'cannot_upload', __( 'You cannot upload files.', 'site-agent' ) );
		}
		$parent = (int) ( $input['post_id'] ?? 0 );
		if ( $parent && ! current_user_can( 'edit_post', $parent ) ) {
			return new \WP_Error( 'post_unavailable', __( 'The post is unavailable or inaccessible.', 'site-agent' ) );
		}
		if ( empty( $input['url'] ) === empty( $input['data_base64'] ) ) {
			return new \WP_Error( 'invalid_source', __( 'Supply exactly one of url or data_base64.', 'site-agent' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = empty( $input['url'] ) ? self::decode( $input ) : self::download( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$id = media_handle_sideload(
			$file,
			$parent,
			null,
			array_filter(
				array(
					'post_title'   => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '',
					'post_excerpt' => $input['caption'] ?? '',
					'post_content' => $input['description'] ?? '',
				),
				'strlen'
			)
		);
		if ( file_exists( $file['tmp_name'] ) ) {
			wp_delete_file( $file['tmp_name'] );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( isset( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt'] ) ) );
		}
		return self::item( (int) $id );
	}

	/**
	 * Download a URL to a temporary file within the upload limit.
	 *
	 * @return array{name: string, tmp_name: string}|\WP_Error
	 */
	private static function download( array $input ) {
		$max = (int) wp_max_upload_size();
		$tmp = wp_tempnam( 'site-agent-media' );
		// wp_safe_remote_get() rejects private and loopback addresses.
		$response = wp_safe_remote_get(
			$input['url'],
			array(
				'timeout'             => 60,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $max + 1,
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			return new \WP_Error(
				'download_failed',
				200 === $code
					/* translators: %s: maximum size. */
					? sprintf( __( 'The file exceeds this site\'s upload limit of %s.', 'site-agent' ), size_format( $max ) )
					/* translators: %d: HTTP status code. */
					: ( $code ? sprintf( __( 'The URL returned HTTP %d.', 'site-agent' ), $code ) : __( 'The URL could not be downloaded. Private and loopback addresses are not allowed.', 'site-agent' ) )
			);
		}
		$name = $input['filename'] ?? wp_basename( (string) wp_parse_url( $input['url'], PHP_URL_PATH ) );
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			$type      = strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' );
			$extension = wp_get_default_extension_for_mime_type( trim( (string) $type ) );
			$name     .= $extension ? '.' . $extension : '';
		}
		return array(
			'name'     => sanitize_file_name( '' === $name ? 'upload' : $name ),
			'tmp_name' => $tmp,
		);
	}

	/**
	 * Write base64 data to a temporary file within the inline limit.
	 *
	 * @return array{name: string, tmp_name: string}|\WP_Error
	 */
	private static function decode( array $input ) {
		if ( empty( $input['filename'] ) || '' === pathinfo( $input['filename'], PATHINFO_EXTENSION ) ) {
			return new \WP_Error( 'missing_filename', __( 'Base64 uploads need a filename with an extension.', 'site-agent' ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an uploaded file; core validates its type before saving.
		$bytes = base64_decode( (string) preg_replace( '/\s+/', '', $input['data_base64'] ), true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > min( self::MAX_INLINE_BYTES, (int) wp_max_upload_size() ) ) {
			return new \WP_Error( 'invalid_data', __( 'data_base64 must be valid base64 within the upload limit (10 MiB at most). Use url for larger files.', 'site-agent' ) );
		}
		$tmp = wp_tempnam( 'site-agent-media' );
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'write_failed', __( 'The upload could not be staged.', 'site-agent' ) );
		}
		return array(
			'name'     => sanitize_file_name( $input['filename'] ),
			'tmp_name' => $tmp,
		);
	}

	public static function update( array $input ) {
		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'media_unavailable', __( 'The media item is unavailable or inaccessible.', 'site-agent' ) );
		}
		$data = array( 'ID' => $id );
		foreach ( array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		) as $field => $key ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $key ] = 'title' === $field ? sanitize_text_field( $input[ $field ] ) : $input[ $field ];
			}
		}
		if ( count( $data ) > 1 ) {
			$result = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt'] ) ) );
		}
		return self::item( $id );
	}
}
