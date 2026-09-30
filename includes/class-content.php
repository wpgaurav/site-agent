<?php
/**
 * WordPress-native content operations preserve block markup.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** WordPress-native content operations preserve block markup. */
final class Content {
	public static function context(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme   = wp_get_theme();
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		return array(
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url( '/' ),
			'wordpress'     => get_bloginfo( 'version' ),
			'php'           => PHP_VERSION,
			'environment'   => wp_get_environment_type(),
			'theme'         => array(
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
			),
			'plugins'       => $plugins,
			'post_types'    => array_values( get_post_types( array( 'show_in_rest' => true ) ) ),
			'enabled_tools' => array_keys( Abilities::enabled_definitions() ),
		);
	}

	public static function listing( array $input ) {
		$type = get_post_type_object( $input['post_type'] ?? 'post' );
		if ( ! $type || ! $type->show_in_rest || 'attachment' === $type->name || ! current_user_can( $type->cap->edit_posts ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'This post type is unavailable or inaccessible.', 'site-agent' ) );
		}
		$query = new \WP_Query(
			array(
				'post_type'      => $type->name,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => $input['limit'] ?? 20,
				'paged'          => $input['page'] ?? 1,
				's'              => $input['search'] ?? '',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$posts[] = array(
					'id'           => $post->ID,
					'title'        => $post->post_title,
					'status'       => $post->post_status,
					'modified_gmt' => $post->post_modified_gmt,
				);
			}
		}
		return array(
			'posts' => $posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	public static function read( array $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'post_unavailable', __( 'The post is unavailable or inaccessible.', 'site-agent' ) );
		}
		return self::data( $post );
	}

	private static function data( \WP_Post $post ): array {
		return array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'content_sha256' => hash( 'sha256', $post->post_content ),
		);
	}

	public static function save( array $input ) {
		$id   = $input['post_id'] ?? 0;
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! $post || ! current_user_can( 'edit_post', $id ) ) ) {
			return new \WP_Error( 'post_unavailable', __( 'The post is unavailable or inaccessible.', 'site-agent' ) );
		}
		$type = get_post_type_object( $post ? $post->post_type : ( $input['post_type'] ?? 'post' ) );
		if ( ! $type || ! $type->show_in_rest || in_array( $type->name, array( 'attachment', 'revision' ), true ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'This post type cannot be edited with the content tool.', 'site-agent' ) );
		}
		if ( ! $id && ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'cannot_create', __( 'You cannot create this post type.', 'site-agent' ) );
		}
		if ( $post && ( empty( $input['expected_content_sha256'] ) || ! hash_equals( hash( 'sha256', $post->post_content ), $input['expected_content_sha256'] ) ) ) {
			return new \WP_Error( 'post_conflict', __( 'Read the post again and supply its current content hash before updating.', 'site-agent' ) );
		}
		$status = $input['status'] ?? ( $post ? $post->post_status : 'draft' );
		if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new \WP_Error( 'cannot_publish', __( 'You cannot publish this post type.', 'site-agent' ) );
		}
		$data = array(
			'post_type'   => $type->name,
			'post_status' => $status,
		);
		if ( $id ) {
			$data['ID'] = $id;
		}
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $field => $key ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $key ] = 'title' === $field ? sanitize_text_field( $input[ $field ] ) : $input[ $field ];
			}
		}
		// Core applies the authenticated author's KSES policy and save hooks.
		$result = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		return is_wp_error( $result ) ? $result : self::data( get_post( $result ) );
	}

	public static function media( array $input ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $input['limit'] ?? 20,
				'paged'          => $input['page'] ?? 1,
				's'              => $input['search'] ?? '',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = array(
					'id'        => $post->ID,
					'title'     => $post->post_title,
					'url'       => wp_get_attachment_url( $post->ID ),
					'mime_type' => $post->post_mime_type,
					'alt'       => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				);
			}
		}
		return array(
			'media' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}
}
