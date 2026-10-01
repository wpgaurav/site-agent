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
	const LIVE     = array( 'publish', 'private', 'future' );
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

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
		$tools = array();
		foreach ( Abilities::enabled_definitions() as $name => $definition ) {
			if ( Permissions::allowed( $definition['group'] ) ) {
				$tools[] = $name;
			}
		}
		return array(
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url( '/' ),
			'wordpress'     => get_bloginfo( 'version' ),
			'php'           => PHP_VERSION,
			'environment'   => wp_get_environment_type(),
			'timezone'      => wp_timezone_string(),
			'theme'         => array(
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
			),
			'plugins'       => $plugins,
			'post_types'    => array_values( get_post_types( array( 'show_in_rest' => true ) ) ),
			'taxonomies'    => array_values( get_taxonomies( array( 'show_in_rest' => true ) ) ),
			'enabled_tools' => $tools,
		);
	}

	/** Post types the content tools may read and write. */
	public static function editable_type( string $name ): bool {
		$type = get_post_type_object( $name );
		return $type && $type->show_in_rest && ! in_array( $type->name, array( 'attachment', 'revision' ), true );
	}

	public static function listing( array $input ) {
		$type = get_post_type_object( $input['post_type'] ?? 'post' );
		if ( ! $type || ! self::editable_type( $type->name ) || ! current_user_can( $type->cap->edit_posts ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'This post type is unavailable or inaccessible.', 'site-agent' ) );
		}
		$orderby = $input['orderby'] ?? 'modified';
		$query   = new \WP_Query(
			array(
				'post_type'           => $type->name,
				'post_status'         => isset( $input['status'] ) ? $input['status'] : self::STATUSES,
				'posts_per_page'      => $input['limit'] ?? 20,
				'paged'               => $input['page'] ?? 1,
				's'                   => $input['search'] ?? '',
				'orderby'             => 'id' === $orderby ? 'ID' : $orderby,
				'order'               => strtoupper( $input['order'] ?? ( 'title' === $orderby ? 'asc' : 'desc' ) ),
				'ignore_sticky_posts' => true,
			)
		);
		$posts   = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$posts[] = array(
					'id'           => $post->ID,
					'title'        => $post->post_title,
					'slug'         => $post->post_name,
					'status'       => $post->post_status,
					'date_gmt'     => $post->post_date_gmt,
					'modified_gmt' => $post->post_modified_gmt,
					'link'         => (string) get_permalink( $post ),
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
		$post = self::find( $input );
		return is_wp_error( $post ) ? $post : self::data( $post );
	}

	/**
	 * Find an editable post by ID, permalink or slug.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private static function find( array $input ) {
		$post = null;
		if ( ! empty( $input['post_id'] ) ) {
			$post = get_post( (int) $input['post_id'] );
		} elseif ( ! empty( $input['url'] ) ) {
			$id   = url_to_postid( $input['url'] );
			$post = $id ? get_post( $id ) : null;
		} elseif ( ! empty( $input['slug'] ) ) {
			$posts = get_posts(
				array(
					'name'             => sanitize_title( $input['slug'] ),
					'post_type'        => $input['post_type'] ?? 'post',
					'post_status'      => self::STATUSES,
					'numberposts'      => 1,
					'suppress_filters' => false,
				)
			);
			$post  = $posts[0] ?? null;
		} else {
			return new \WP_Error( 'missing_identifier', __( 'Supply post_id, url or slug.', 'site-agent' ) );
		}
		if ( ! $post instanceof \WP_Post || ! self::editable_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'post_unavailable', __( 'The post is unavailable or inaccessible.', 'site-agent' ) );
		}
		return $post;
	}

	private static function data( \WP_Post $post ): array {
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->show_in_rest ) {
				$ids = wp_get_object_terms( $post->ID, $taxonomy->name, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $ids ) ) {
					$terms[ $taxonomy->name ] = array_map( 'intval', $ids );
				}
			}
		}
		$meta = array();
		foreach ( self::meta_keys( $post->post_type ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_scalar( $value ) && '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}
		$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
		return array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'author'         => (int) $post->post_author,
			'date_gmt'       => $post->post_date_gmt,
			'modified_gmt'   => $post->post_modified_gmt,
			'link'           => (string) get_permalink( $post ),
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			// Objects keep empty maps as {} rather than [] in JSON.
			'terms'          => (object) $terms,
			'meta'           => (object) $meta,
			'autosave'       => $autosave ? array(
				'id'             => $autosave->ID,
				'modified_gmt'   => $autosave->post_modified_gmt,
				'title'          => $autosave->post_title,
				'content_sha256' => hash( 'sha256', $autosave->post_content ),
			) : null,
			'content_sha256' => hash( 'sha256', $post->post_content ),
		);
	}

	/**
	 * Post meta keys the content tools read and write: SEO fields of active SEO plugins plus
	 * single, scalar meta registered with show_in_rest.
	 *
	 * @return string[]
	 */
	public static function meta_keys( string $post_type ): array {
		$keys = array();
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$keys = array_merge( $keys, array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ) );
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$keys = array_merge( $keys, array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ) );
		}
		foreach ( array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) ) as $key => $args ) {
			if ( ! empty( $args['show_in_rest'] ) && ! empty( $args['single'] ) && in_array( $args['type'] ?? '', array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
				$keys[] = $key;
			}
		}
		/**
		 * Filters the post meta keys Site Agent may read and write.
		 *
		 * @param string[] $keys      Meta keys.
		 * @param string   $post_type Post type.
		 */
		return array_values( array_unique( array_filter( (array) apply_filters( 'site_agent_post_meta_keys', $keys, $post_type ), 'is_string' ) ) );
	}

	public static function save( array $input ) {
		$id   = (int) ( $input['post_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( $id && ( ! $post || ! current_user_can( 'edit_post', $id ) ) ) {
			return new \WP_Error( 'post_unavailable', __( 'The post is unavailable or inaccessible.', 'site-agent' ) );
		}
		$type = get_post_type_object( $post ? $post->post_type : ( $input['post_type'] ?? 'post' ) );
		if ( ! $type || ! self::editable_type( $type->name ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'This post type cannot be edited with the content tool.', 'site-agent' ) );
		}
		if ( ! $id && ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'cannot_create', __( 'You cannot create this post type.', 'site-agent' ) );
		}
		if ( $post && ( empty( $input['expected_content_sha256'] ) || ! hash_equals( hash( 'sha256', $post->post_content ), $input['expected_content_sha256'] ) ) ) {
			return new \WP_Error( 'post_conflict', __( 'Read the post again and supply its current content hash before updating.', 'site-agent' ) );
		}
		// Going live is explicit: edits to live posts are staged unless the caller names a status.
		if ( $post && ! isset( $input['status'] ) && in_array( $post->post_status, self::LIVE, true ) ) {
			return self::stage( $post, $type, $input );
		}
		$status = $input['status'] ?? ( $post ? $post->post_status : 'draft' );
		if ( in_array( $status, self::LIVE, true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new \WP_Error( 'cannot_publish', __( 'You cannot publish this post type.', 'site-agent' ) );
		}
		$data = array(
			'post_type'   => $type->name,
			'post_status' => $status,
		);
		if ( $id ) {
			$data['ID'] = $id;
		}
		if ( isset( $input['date_gmt'] ) ) {
			$timestamp = rest_parse_date( $input['date_gmt'], true );
			if ( false === $timestamp ) {
				return new \WP_Error( 'invalid_date', __( 'Use an ISO 8601 date such as 2026-10-05T09:00:00Z for date_gmt.', 'site-agent' ) );
			}
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			$data['post_date']     = get_date_from_gmt( $data['post_date_gmt'] );
			$data['edit_date']     = true;
		}
		if ( 'future' === $status ) {
			$scheduled = $data['post_date_gmt'] ?? ( $post ? $post->post_date_gmt : '' );
			if ( strtotime( $scheduled . ' UTC' ) <= time() ) {
				// WordPress would publish a "future" post with a past date immediately.
				return new \WP_Error( 'invalid_date', __( 'Scheduling needs a future date_gmt.', 'site-agent' ) );
			}
		}
		$extras = self::prepare_extras( $type, $input );
		if ( is_wp_error( $extras ) ) {
			return $extras;
		}
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'slug'    => 'post_name',
		) as $field => $key ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $key ] = 'title' === $field ? self::title( $input[ $field ] ) : $input[ $field ];
			}
		}
		// Core applies the authenticated author's KSES policy and save hooks.
		$result = $id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$applied = self::apply_extras( (int) $result, $extras );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		return array( 'staged' => false ) + self::data( get_post( $result ) );
	}

	/** Titles are plain text, but tags-as-text like <head> and sequences like %20 are kept. Core still applies KSES. */
	private static function title( string $title ): string {
		return trim( (string) preg_replace( '/\s*[\r\n]+\s*/', ' ', $title ) );
	}

	/** Save title, content and excerpt changes to a live post as the user's autosave, through core's REST controller. */
	private static function stage( \WP_Post $post, \WP_Post_Type $type, array $input ) {
		foreach ( array( 'slug', 'date_gmt', 'terms', 'featured_media', 'meta' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				return new \WP_Error( 'stage_unsupported', __( 'Only title, content and excerpt changes can be staged. Pass status to update this live post directly.', 'site-agent' ) );
			}
		}
		$body = array_intersect_key( $input, array_flip( array( 'title', 'content', 'excerpt' ) ) );
		if ( ! $body ) {
			return new \WP_Error( 'nothing_to_stage', __( 'Supply a title, content or excerpt to stage, or pass status to change the live post.', 'site-agent' ) );
		}
		if ( isset( $body['title'] ) ) {
			$body['title'] = self::title( $body['title'] );
		}
		$request = new \WP_REST_Request( 'POST', sprintf( '/%s/%s/%d/autosaves', $type->rest_namespace ? $type->rest_namespace : 'wp/v2', $type->rest_base ? $type->rest_base : $type->name, $post->ID ) );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		return array( 'staged' => true ) + self::data( get_post( $post->ID ) );
	}

	/**
	 * Validate taxonomy terms, featured image and meta before anything is saved.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function prepare_extras( \WP_Post_Type $type, array $input ) {
		$extras = array();
		foreach ( (array) ( $input['terms'] ?? array() ) as $name => $items ) {
			$taxonomy = get_taxonomy( (string) $name );
			if ( ! $taxonomy || ! $taxonomy->show_in_rest || ! is_object_in_taxonomy( $type->name, $taxonomy->name ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				/* translators: %s: taxonomy name. */
				return new \WP_Error( 'invalid_taxonomy', sprintf( __( 'Terms cannot be assigned in the %s taxonomy for this post type.', 'site-agent' ), $name ) );
			}
			$terms = array();
			foreach ( (array) $items as $item ) {
				if ( is_int( $item ) ) {
					$term = get_term( $item, $taxonomy->name );
					if ( ! $term instanceof \WP_Term ) {
						/* translators: 1: term ID, 2: taxonomy name. */
						return new \WP_Error( 'invalid_term', sprintf( __( 'Term %1$d does not exist in %2$s.', 'site-agent' ), $item, $taxonomy->name ) );
					}
					$terms[] = $term->term_id;
					continue;
				}
				$label = trim( (string) $item );
				$term  = '' === $label ? false : get_term_by( 'name', $label, $taxonomy->name );
				if ( $term instanceof \WP_Term ) {
					$terms[] = $term->term_id;
				} elseif ( '' !== $label && current_user_can( $taxonomy->cap->edit_terms ) ) {
					// Created after the post saves, so a rejected post leaves no stray terms.
					$terms[] = $label;
				} else {
					/* translators: 1: term name, 2: taxonomy name. */
					return new \WP_Error( 'invalid_term', sprintf( __( 'The term "%1$s" does not exist in %2$s and cannot be created.', 'site-agent' ), $label, $taxonomy->name ) );
				}
			}
			$extras['terms'][ $taxonomy->name ] = $terms;
		}
		if ( isset( $input['featured_media'] ) ) {
			$media = (int) $input['featured_media'];
			if ( ! post_type_supports( $type->name, 'thumbnail' ) || ( $media && ! wp_attachment_is_image( $media ) ) ) {
				return new \WP_Error( 'invalid_featured_media', __( 'Use an image attachment ID, or 0 to remove the featured image, on a post type that supports featured images.', 'site-agent' ) );
			}
			$extras['featured_media'] = $media;
		}
		if ( isset( $input['meta'] ) ) {
			$allowed = self::meta_keys( $type->name );
			foreach ( (array) $input['meta'] as $key => $value ) {
				if ( ! in_array( $key, $allowed, true ) || ! is_scalar( $value ) ) {
					/* translators: 1: meta key, 2: allowed meta keys. */
					return new \WP_Error( 'invalid_meta', sprintf( __( 'The meta key %1$s cannot be written. Allowed keys: %2$s.', 'site-agent' ), $key, $allowed ? implode( ', ', $allowed ) : __( 'none', 'site-agent' ) ) );
				}
			}
			$extras['meta'] = (array) $input['meta'];
		}
		return $extras;
	}

	/**
	 * Apply validated terms, featured image and meta after the post saves.
	 *
	 * @return true|\WP_Error
	 */
	private static function apply_extras( int $post_id, array $extras ) {
		foreach ( $extras['terms'] ?? array() as $taxonomy => $terms ) {
			$ids = array();
			foreach ( $terms as $term ) {
				if ( is_int( $term ) ) {
					$ids[] = $term;
					continue;
				}
				$created = wp_insert_term( $term, $taxonomy );
				if ( is_wp_error( $created ) ) {
					return self::partial( $created );
				}
				$ids[] = (int) $created['term_id'];
			}
			$set = wp_set_object_terms( $post_id, $ids, $taxonomy );
			if ( is_wp_error( $set ) ) {
				return self::partial( $set );
			}
		}
		if ( isset( $extras['featured_media'] ) ) {
			if ( $extras['featured_media'] ) {
				set_post_thumbnail( $post_id, $extras['featured_media'] );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
		foreach ( $extras['meta'] ?? array() as $key => $value ) {
			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
		return true;
	}

	private static function partial( \WP_Error $error ): \WP_Error {
		/* translators: %s: error message. */
		return new \WP_Error( 'partially_saved', sprintf( __( 'The post was saved, but its terms could not be set: %s', 'site-agent' ), $error->get_error_message() ) );
	}

	public static function terms( array $input ) {
		$taxonomy = get_taxonomy( $input['taxonomy'] ?? 'category' );
		if ( ! $taxonomy || ! $taxonomy->show_in_rest || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'This taxonomy is unavailable or inaccessible.', 'site-agent' ) );
		}
		$limit = (int) ( $input['limit'] ?? 50 );
		$page  = (int) ( $input['page'] ?? 1 );
		$args  = array(
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => false,
			'search'     => $input['search'] ?? '',
		);
		$terms = get_terms(
			$args + array(
				'number'  => $limit,
				'offset'  => ( $page - 1 ) * $limit,
				'orderby' => 'name',
			)
		);
		$total = wp_count_terms( $args );
		$total = is_wp_error( $total ) ? 0 : (int) $total;
		$items = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$items[] = array(
				'id'     => $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'parent' => $term->parent,
				'count'  => $term->count,
			);
		}
		return array(
			'taxonomy' => $taxonomy->name,
			'terms'    => $items,
			'total'    => $total,
			'pages'    => (int) ceil( $total / max( 1, $limit ) ),
		);
	}
}
