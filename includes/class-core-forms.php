<?php
/**
 * Core Forms tools: forms, fields, actions and submissions through Core Forms' own REST API.
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Core Forms keeps forms as core-form posts with their markup, a field schema, settings (actions
 * included) and messages, all versioned by one fingerprint. These tools call Core Forms' own cf/v1
 * REST routes inside the request, so its capability and form-scope checks, fingerprint conflicts,
 * locks, sanitizing, revisions and field-mapping validation all apply. Site Agent adds the parts an
 * assistant cannot do safely by hand: rendering markup from a field list with Core Forms' renderer,
 * keeping action secrets out of tool output, and saving messages under the same version check.
 */
final class Core_Forms {
	const PLUGIN = 'core-forms/core-forms.php';
	/** Shown in place of a stored secret. Sent back unchanged, it keeps that secret. */
	const STORED = '[stored]';
	/** Setting keys whose values are credentials. */
	const SECRET = '/(secret|token|password|passwd|api_?key|private_?key|access_?key|auth_header_value|license_?key|app_?secret)/i';
	/** Layouts Core Forms' multi-step renderer draws. */
	const STEPPED = array( 'multi-step', 'conversational' );

	/** Core Forms 4.11 or later is active: versioned definitions, schema rendering and the cf/v1 routes. */
	public static function available(): bool {
		return function_exists( 'cf_get_form' ) && function_exists( 'cf_duplicate_form' )
			&& class_exists( '\Core_Forms\FormDefinition' ) && class_exists( '\Core_Forms\Schema\SchemaRenderer' )
			&& class_exists( '\Core_Forms\Integrations\ActionSettings' );
	}

	/**
	 * Dispatch a Core Forms REST request as the current user.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route under /cf/v1.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query parameters.
	 * @return mixed|\WP_Error Plain decoded response data.
	 */
	private static function rest( string $method, string $route, ?array $body = null, array $query = array() ) {
		$request = new \WP_REST_Request( $method, '/cf/v1' . $route );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		return self::plain( $response->get_data() );
	}

	/**
	 * Schema objects become plain arrays, as a REST client would see them.
	 *
	 * @param mixed $data Response data.
	 * @return mixed
	 */
	private static function plain( $data ) {
		return null === $data || is_scalar( $data ) ? $data : json_decode( (string) wp_json_encode( $data ), true );
	}

	/**
	 * The draft definition, as Core Forms' authoring API reads it.
	 *
	 * @return \Core_Forms\Form|\WP_Error
	 */
	private static function form( int $id ) {
		$post = get_post( $id );
		if ( ! $post || 'core-form' !== $post->post_type ) {
			return new \WP_Error( 'site_agent_form_missing', __( 'No Core Forms form has this ID. Call core-forms-list-forms for IDs.', 'site-agent' ) );
		}
		if ( class_exists( '\Core_Forms\Admin\TeamInbox' ) && ! \Core_Forms\Admin\TeamInbox::can_access( $id ) ) {
			return new \WP_Error( 'site_agent_form_forbidden', __( 'You cannot manage this form.', 'site-agent' ) );
		}
		try {
			$form = cf_get_form( $id, 'draft' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'site_agent_form_missing', __( 'No Core Forms form has this ID. Call core-forms-list-forms for IDs.', 'site-agent' ) );
		}
		return $form instanceof \Core_Forms\Form ? $form : new \WP_Error( 'site_agent_form_missing', __( 'No Core Forms form has this ID. Call core-forms-list-forms for IDs.', 'site-agent' ) );
	}

	/**
	 * Replace credential values with STORED, at any depth.
	 *
	 * @param mixed $value Settings.
	 * @return mixed
	 */
	public static function redact( $value, string $key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $child => $item ) {
				$value[ $child ] = self::redact( $item, is_string( $child ) ? $child : $key );
			}
			return $value;
		}
		return '' !== $key && preg_match( self::SECRET, $key ) && is_string( $value ) && '' !== $value ? self::STORED : $value;
	}

	/**
	 * Put stored secrets back where incoming settings still carry STORED.
	 *
	 * @param mixed $incoming Settings from the client.
	 * @param mixed $original Stored settings, or null when there are none.
	 * @return mixed|\WP_Error
	 */
	public static function unredact( $incoming, $original ) {
		if ( self::STORED === $incoming ) {
			return is_string( $original ) && '' !== $original ? $original : new \WP_Error( 'site_agent_secret_unknown', __( 'A value is [stored] but this action has no stored value there. Send the real value, or keep the action unchanged with keep_index and keep_fingerprint.', 'site-agent' ) );
		}
		if ( ! is_array( $incoming ) ) {
			return $incoming;
		}
		foreach ( $incoming as $key => $value ) {
			$restored = self::unredact( $value, is_array( $original ) ? ( $original[ $key ] ?? null ) : null );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			$incoming[ $key ] = $restored;
		}
		return $incoming;
	}

	/** Shortcode, field list, actions with their references, publishing state and submission counts. */
	private static function describe( int $id ) {
		$data = self::rest( 'GET', '/forms/' . $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$form = self::form( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$schema    = self::rest( 'GET', '/forms/' . $id . '/schema' );
		$stored    = is_array( $schema ) && ! empty( $schema['exists'] ) && is_array( $schema['schema'] ?? null );
		$fields    = $stored ? (array) ( $schema['schema']['fields'] ?? array() ) : self::plain( \Core_Forms\Schema\MarkupParser::parse( (string) $form->markup )->to_array()['fields'] ?? array() );
		$available = (array) apply_filters( 'cf_available_form_actions', array() );
		$actions   = array();
		foreach ( (array) ( $form->settings['actions'] ?? array() ) as $index => $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type      = (string) ( $action['type'] ?? '' );
			$actions[] = array(
				'index'       => $index,
				'fingerprint' => \Core_Forms\Integrations\ActionSettings::fingerprint( $action ),
				'type'        => $type,
				'label'       => (string) ( $available[ $type ] ?? '' ),
				'available'   => isset( $available[ $type ] ),
				'settings'    => self::redact( $action ),
			);
		}
		$settings = (array) self::redact( (array) $form->settings );
		unset( $settings['actions'] );
		$live   = get_post_meta( $id, '_cf_live_definition', true );
		$drift  = class_exists( '\Core_Forms\Schema\SchemaSync' ) ? get_post_meta( $id, \Core_Forms\Schema\SchemaSync::DRIFT_META, true ) : array();
		$counts = function_exists( 'cf_count_all_submissions' );
		$post   = get_post( $id );
		return array(
			'form_id'      => $id,
			'fingerprint'  => (string) $data['fingerprint'],
			'title'        => (string) $data['title'],
			'slug'         => (string) $data['slug'],
			'status'       => $post ? (string) $post->post_status : '',
			'shortcode'    => '[cf_form slug="' . $data['slug'] . '"]',
			'edit_url'     => admin_url( 'admin.php?page=core-forms&view=edit&form_id=' . $id ),
			'schema'       => $stored ? 'stored' : 'markup',
			'layout'       => $stored ? (array) ( $schema['schema']['layout'] ?? array( 'type' => 'single' ) ) : array( 'type' => 'single' ),
			'fields'       => array_values( (array) $fields ),
			'markup'       => (string) $data['markup'],
			'settings'     => $settings,
			'actions'      => $actions,
			'messages'     => (array) $data['messages'],
			'publishing'   => array(
				'draft_publishing' => is_array( $live ) && array_key_exists( 'markup', $live ),
				// Publishing stores a snapshot, so an unchanged draft serializes to the same array.
				'draft_changed'    => is_array( $live ) && array_key_exists( 'markup', $live ) && \Core_Forms\FormDefinition::snapshot( $id ) !== $live,
			),
			'schema_drift' => is_array( $drift ) ? array_values( $drift ) : array(),
			'submissions'  => array(
				'total' => $counts ? (int) cf_count_all_submissions( array( 'form_id' => $id ) ) : 0,
				'new'   => $counts ? (int) cf_count_all_submissions(
					array(
						'form_id' => $id,
						'status'  => 'new',
					)
				) : 0,
			),
		);
	}

	public static function get_form( array $input ) {
		return self::describe( (int) $input['form_id'] );
	}

	/** Forms this user can manage, by title, with what each one does. */
	public static function list_forms( array $input ) {
		$options = self::rest( 'GET', '/forms/options' );
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$search = strtolower( (string) ( $input['search'] ?? '' ) );
		$rows   = array_values(
			array_filter(
				(array) $options,
				static function ( $row ) use ( $search ) {
					return '' === $search || false !== strpos( strtolower( $row['title'] . ' ' . $row['slug'] ), $search );
				}
			)
		);
		$limit  = (int) ( $input['limit'] ?? 50 );
		$page   = max( 1, (int) ( $input['page'] ?? 1 ) );
		$total  = count( $rows );
		$forms  = array();
		foreach ( array_slice( $rows, ( $page - 1 ) * $limit, $limit ) as $row ) {
			$id   = (int) $row['id'];
			$form = self::form( $id );
			if ( is_wp_error( $form ) ) {
				continue;
			}
			$post    = get_post( $id );
			$live    = get_post_meta( $id, '_cf_live_definition', true );
			$forms[] = array(
				'form_id'          => $id,
				'title'            => (string) $row['title'],
				'slug'             => (string) $row['slug'],
				'status'           => $post ? (string) $post->post_status : '',
				'shortcode'        => '[cf_form slug="' . $row['slug'] . '"]',
				'schema'           => \Core_Forms\Schema\FormSchema::exists( $id ) ? 'stored' : 'markup',
				'actions'          => array_values( array_filter( array_map( static fn( $action ) => is_array( $action ) ? (string) ( $action['type'] ?? '' ) : '', (array) ( $form->settings['actions'] ?? array() ) ) ) ),
				'draft_publishing' => is_array( $live ) && array_key_exists( 'markup', $live ),
				'submissions'      => function_exists( 'cf_count_all_submissions' ) ? (int) cf_count_all_submissions( array( 'form_id' => $id ) ) : 0,
			);
		}
		return array(
			'forms' => $forms,
			'total' => $total,
			'pages' => (int) ceil( $total / $limit ),
		);
	}

	/**
	 * Markup for a field list, drawn by Core Forms' own renderer as its builder and templates do.
	 *
	 * @param array $fields Field objects.
	 * @param array $layout Layout object.
	 * @return array{0: string, 1: array}|\WP_Error Markup and the schema array.
	 */
	public static function render( array $fields, array $layout, string $submit_text = '', array $form_settings = array() ) {
		$names = array();
		$types = array();
		foreach ( $fields as $position => $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				return new \WP_Error( 'site_agent_field_name', __( 'Every field needs a name.', 'site-agent' ) );
			}
			if ( isset( $names[ $name ] ) ) {
				/* translators: %s: field name. */
				return new \WP_Error( 'site_agent_field_duplicate', sprintf( __( 'Two fields are named %s. Field names must be unique.', 'site-agent' ), $name ) );
			}
			$names[ $name ]      = true;
			$types[]             = (string) ( $field['type'] ?? 'text' );
			$fields[ $position ] = $field + array( 'position' => $position );
		}
		$layout = array( 'type' => (string) ( $layout['type'] ?? 'single' ) ) + $layout;
		// Single-page forms need a button; Core Forms' multi-step renderer adds its own.
		if ( ! in_array( $layout['type'], self::STEPPED, true ) && ! in_array( 'submit', $types, true ) ) {
			$fields[] = array(
				// The builder's name: a leading underscore keeps the button out of the submitted answers.
				'name'     => '_submit',
				'type'     => 'submit',
				'label'    => '' !== $submit_text ? $submit_text : __( 'Submit', 'site-agent' ),
				'position' => count( $fields ),
			);
		}
		$schema = \Core_Forms\Schema\FormSchema::from_array(
			array(
				'version'       => 1,
				'fields'        => array_values( $fields ),
				'layout'        => $layout,
				'form_settings' => $form_settings,
			)
		);
		$markup = in_array( $layout['type'], self::STEPPED, true ) && class_exists( '\Core_Forms\SmartForms\MultiStep' )
			? \Core_Forms\SmartForms\MultiStep::render( $schema )
			: \Core_Forms\Schema\SchemaRenderer::render( $schema );
		return array( $markup, self::plain( $schema->to_array() ) );
	}

	/**
	 * Settings that may change through save-form; actions have their own tool.
	 *
	 * @param array $settings Incoming settings.
	 * @return array|\WP_Error
	 */
	private static function settings( array $settings ) {
		if ( array_key_exists( 'actions', $settings ) ) {
			return new \WP_Error( 'site_agent_use_save_actions', __( 'Change actions with core-forms-save-actions, which keeps stored secrets and the action order.', 'site-agent' ) );
		}
		if ( isset( $settings['field_wrapper_class'] ) && function_exists( 'cf_sanitize_class_list' ) ) {
			$settings['field_wrapper_class'] = cf_sanitize_class_list( sanitize_text_field( (string) $settings['field_wrapper_class'] ) );
		}
		foreach ( $settings as $key => $value ) {
			if ( self::STORED === $value ) {
				unset( $settings[ $key ] );
			}
		}
		return $settings;
	}

	/** Create a form, or update its fields, markup, title, slug, settings and messages under its fingerprint. */
	public static function save( array $input ) {
		$id       = (int) ( $input['form_id'] ?? 0 );
		$settings = self::settings( (array) ( $input['settings'] ?? array() ) );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( isset( $input['fields'] ) && isset( $input['markup'] ) ) {
			return new \WP_Error( 'site_agent_fields_or_markup', __( 'Send fields (the visual builder schema) or markup (code view), not both.', 'site-agent' ) );
		}
		$rendered = null;
		if ( isset( $input['fields'] ) ) {
			// Replacing the fields keeps the stored layout (unless one is sent) and schema-level settings.
			$current  = $id && \Core_Forms\Schema\FormSchema::exists( $id ) ? \Core_Forms\Schema\FormSchema::load( $id ) : null;
			$layout   = (array) ( $input['layout'] ?? ( $current ? $current->layout : array() ) );
			$rendered = self::render( $input['fields'], $layout, (string) ( $settings['submit_button_text'] ?? '' ), $current ? (array) $current->form_settings : array() );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
		}
		$saved = array();
		if ( ! $id ) {
			if ( ! $rendered && ! isset( $input['markup'] ) ) {
				return new \WP_Error( 'site_agent_form_empty', __( 'A new form needs fields or markup.', 'site-agent' ) );
			}
			$body = array(
				'title'  => (string) ( $input['title'] ?? __( 'Untitled Form', 'site-agent' ) ),
				'markup' => $rendered ? $rendered[0] : (string) $input['markup'],
			);
			if ( isset( $input['slug'] ) ) {
				$body['slug'] = (string) $input['slug'];
			}
			if ( $rendered ) {
				$body['schema'] = $rendered[1];
			}
			if ( $settings ) {
				$body['settings'] = $settings;
			}
			$created = self::rest( 'POST', '/forms', $body );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$id          = (int) $created['id'];
			$fingerprint = (string) $created['fingerprint'];
			$saved[]     = 'form';
		} else {
			$fingerprint = (string) ( $input['expected_fingerprint'] ?? '' );
			if ( '' === $fingerprint ) {
				return new \WP_Error( 'site_agent_fingerprint_required', __( 'Pass expected_fingerprint from core-forms-get-form to change an existing form.', 'site-agent' ) );
			}
			$form = self::form( $id );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			if ( $rendered && has_blocks( (string) $form->markup ) ) {
				return new \WP_Error( 'site_agent_block_form', __( 'This form is built in the Block Editor: its fields are blocks. Change them as block markup through markup, or in the editor.', 'site-agent' ) );
			}
			if ( $rendered ) {
				$result = self::rest(
					'PUT',
					'/forms/' . $id . '/editor-state',
					array(
						'fingerprint' => $fingerprint,
						'markup'      => $rendered[0],
						'schema'      => $rendered[1],
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$fingerprint = (string) $result['fingerprint'];
				$saved[]     = 'fields';
			}
			$patch = array_intersect_key( $input, array_flip( array( 'title', 'slug', 'markup' ) ) );
			if ( $settings ) {
				$patch['settings'] = $settings;
			}
			if ( $patch ) {
				$result = self::rest( 'PATCH', '/forms/' . $id, $patch + array( 'fingerprint' => $fingerprint ) );
				if ( is_wp_error( $result ) ) {
					return self::partial( $result, $saved, $id );
				}
				$fingerprint = (string) $result['fingerprint'];
				$saved       = array_merge( $saved, array_keys( $patch ) );
			}
			if ( isset( $input['markup'] ) && ! empty( $input['schema_from_markup'] ) ) {
				$result = self::rest(
					'POST',
					'/forms/' . $id . '/schema/generate',
					array(
						'fingerprint' => $fingerprint,
						'save'        => true,
					)
				);
				if ( is_wp_error( $result ) ) {
					return self::partial( $result, $saved, $id );
				}
				$fingerprint = (string) $result['fingerprint'];
				$saved[]     = 'schema';
			}
		}
		if ( ! empty( $input['messages'] ) ) {
			$result = self::messages( $id, $fingerprint, (array) $input['messages'] );
			if ( is_wp_error( $result ) ) {
				return self::partial( $result, $saved, $id );
			}
			$saved[] = 'messages';
		}
		if ( ! $saved ) {
			return new \WP_Error( 'site_agent_nothing_to_save', __( 'Nothing to save. Pass fields, markup, title, slug, settings or messages.', 'site-agent' ) );
		}
		$result = self::describe( $id );
		return is_wp_error( $result ) ? $result : array( 'saved' => $saved ) + $result;
	}

	/** A later step failed after earlier ones saved; say which, so the client reloads before retrying. */
	private static function partial( \WP_Error $error, array $saved, int $id ): \WP_Error {
		if ( ! $saved ) {
			return $error;
		}
		return new \WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: error message, 2: saved parts, 3: form ID. */
				__( '%1$s Already saved: %2$s. Read form %3$d again before retrying the rest.', 'site-agent' ),
				$error->get_error_message(),
				implode( ', ', $saved ),
				$id
			),
			$error->get_error_data()
		);
	}

	/**
	 * Save form messages the way Core Forms' editor does, under the form's version lock.
	 *
	 * @param array<string, mixed> $messages Message key => text.
	 * @return true|\WP_Error
	 */
	private static function messages( int $id, string $fingerprint, array $messages ) {
		$known = function_exists( 'cf_get_form_default_messages' ) ? cf_get_form_default_messages() : array();
		foreach ( array_keys( $messages ) as $key ) {
			if ( ! isset( $known[ $key ] ) ) {
				/* translators: 1: message key, 2: known keys. */
				return new \WP_Error( 'site_agent_message_unknown', sprintf( __( 'Core Forms has no %1$s message. Known messages: %2$s.', 'site-agent' ), $key, implode( ', ', array_keys( $known ) ) ) );
			}
		}
		$result = \Core_Forms\FormDefinition::atomic_mutation(
			$id,
			$fingerprint,
			static function () use ( $id, $messages ) {
				foreach ( $messages as $key => $message ) {
					$message = (string) $message;
					update_post_meta( $id, 'cf_message_' . sanitize_key( $key ), wp_slash( current_user_can( 'unfiltered_html' ) ? $message : wp_kses_post( $message ) ) );
				}
				return true;
			}
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/** Core Forms' own duplicate: a new form with the same fields, settings and actions, without the published snapshot. */
	public static function duplicate( array $input ) {
		$source = self::form( (int) $input['form_id'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$copy = cf_duplicate_form( $source->ID );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		$result = self::describe( (int) $copy );
		return is_wp_error( $result ) ? $result : array( 'source_form_id' => $source->ID ) + $result;
	}

	/** Move a form to the trash under its fingerprint; entries are kept and the form can be restored. */
	public static function trash( array $input ) {
		$id   = (int) $input['form_id'];
		$form = self::form( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$result = \Core_Forms\FormDefinition::atomic_mutation(
			$id,
			(string) $input['expected_fingerprint'],
			static function () use ( $id ) {
				return wp_trash_post( $id ) ? true : new \WP_Error( 'site_agent_trash_failed', __( 'WordPress could not move the form to the trash.', 'site-agent' ) );
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'form_id' => $id,
			'status'  => (string) get_post_status( $id ),
			'note'    => __( 'The form is in the trash and its shortcode no longer renders. Its entries are kept. Restore it from the trash in WordPress.', 'site-agent' ),
		);
	}

	/** Start draft publishing, publish the draft, or discard it, through Core Forms' publishing route. */
	public static function publish( array $input ) {
		$id     = (int) $input['form_id'];
		$result = self::rest(
			'POST',
			'/forms/' . $id . '/publishing',
			array(
				'action'      => (string) $input['action'],
				'fingerprint' => (string) $input['expected_fingerprint'],
			)
		);
		return is_wp_error( $result ) ? $result : self::describe( $id );
	}

	/** Replace a form's ordered action list. Unchanged actions can be kept by reference; [stored] keeps secrets. */
	public static function save_actions( array $input ) {
		$id   = (int) $input['form_id'];
		$form = self::form( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$by_id = array();
		foreach ( (array) ( $form->settings['actions'] ?? array() ) as $action ) {
			if ( is_array( $action ) && isset( $action['_cf_action_id'] ) && is_scalar( $action['_cf_action_id'] ) ) {
				$by_id[ (string) $action['_cf_action_id'] ] = $action;
			}
		}
		$available = (array) apply_filters( 'cf_available_form_actions', array() );
		$actions   = array();
		foreach ( $input['actions'] as $action ) {
			if ( isset( $action['keep_index'] ) ) {
				// Core Forms' own reference: the stored action, if it still matches its fingerprint.
				$actions[] = array(
					'_cf_preserve_index' => $action['keep_index'],
					'_cf_preserve_hash'  => (string) ( $action['keep_fingerprint'] ?? '' ),
				);
				continue;
			}
			if ( ! is_string( $action['type'] ?? null ) || '' === $action['type'] ) {
				return new \WP_Error( 'site_agent_action_type', __( 'Each action needs a type from core-forms-overview, or keep_index and keep_fingerprint to keep a saved action.', 'site-agent' ) );
			}
			if ( ! isset( $available[ $action['type'] ] ) ) {
				// Core Forms keeps unavailable actions it already has, but a new or edited one would never run.
				return new \WP_Error(
					'site_agent_action_unavailable',
					sprintf(
						/* translators: 1: action type, 2: available types. */
						__( 'The %1$s action is not available on this site: its module or integration plugin is off (webhooks need the Webhooks module). Available: %2$s.', 'site-agent' ),
						$action['type'],
						implode( ', ', array_keys( $available ) )
					)
				);
			}
			$identity = isset( $action['_cf_action_id'] ) && is_scalar( $action['_cf_action_id'] ) ? (string) $action['_cf_action_id'] : '';
			$action   = self::unredact( $action, $by_id[ $identity ] ?? null );
			if ( is_wp_error( $action ) ) {
				return $action;
			}
			if ( '' === $identity ) {
				// A stable identity, as Core Forms gives copied actions, so reordering never shifts delivery records.
				$action['_cf_action_id'] = 'action-' . wp_generate_uuid4();
			}
			$actions[] = $action;
		}
		$result = self::rest(
			'PATCH',
			'/forms/' . $id,
			array(
				'fingerprint' => (string) $input['expected_fingerprint'],
				'settings'    => array( 'actions' => $actions ),
			)
		);
		return is_wp_error( $result ) ? $result : self::describe( $id );
	}

	/** Run one action for real with sample answers, through Core Forms' action test route. */
	public static function test_action( array $input ) {
		$id   = (int) $input['form_id'];
		$form = self::form( $id );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$saved = (array) ( $form->settings['actions'] ?? array() );
		if ( isset( $input['index'] ) ) {
			$action = $saved[ $input['index'] ] ?? null;
			if ( ! is_array( $action ) ) {
				return new \WP_Error( 'site_agent_action_missing', __( 'This form has no action at that index. Read the form again.', 'site-agent' ) );
			}
		} elseif ( isset( $input['action'] ) ) {
			$action   = $input['action'];
			$identity = isset( $action['_cf_action_id'] ) && is_scalar( $action['_cf_action_id'] ) ? (string) $action['_cf_action_id'] : '';
			$original = null;
			foreach ( $saved as $candidate ) {
				if ( is_array( $candidate ) && '' !== $identity && (string) ( $candidate['_cf_action_id'] ?? '' ) === $identity ) {
					$original = $candidate;
				}
			}
			$action = self::unredact( $action, $original );
			if ( is_wp_error( $action ) ) {
				return $action;
			}
		} else {
			return new \WP_Error( 'site_agent_action_missing', __( 'Pass index for a saved action, or action for unsaved settings.', 'site-agent' ) );
		}
		$body = array(
			'action_type' => (string) ( $action['type'] ?? '' ),
			'form_id'     => $id,
			'settings'    => $action,
		);
		if ( isset( $input['test_data'] ) ) {
			$body['test_data'] = $input['test_data'];
		}
		$result = self::rest( 'POST', '/actions/test', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array_intersect_key( (array) $result, array_flip( array( 'success', 'status', 'message' ) ) );
	}

	/** Validate sample answers and plan the actions and payment without storing, sending or charging anything. */
	public static function rehearse( array $input ) {
		$body = array( 'data' => (object) ( $input['data'] ?? array() ) );
		if ( isset( $input['revision_id'] ) ) {
			$body['revision_id'] = (int) $input['revision_id'];
		}
		return self::rest( 'POST', '/forms/' . (int) $input['form_id'] . '/rehearsal', $body );
	}

	public static function list_submissions( array $input ) {
		$query = array(
			'per_page' => (int) ( $input['limit'] ?? 20 ),
			'page'     => (int) ( $input['page'] ?? 1 ),
		);
		foreach ( array( 'status', 'search', 'date_from', 'date_to', 'order' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$query[ $key ] = (string) $input[ $key ];
			}
		}
		if ( isset( $input['spam'] ) ) {
			$query['is_spam'] = $input['spam'] ? '1' : '0';
		}
		$route  = isset( $input['form_id'] ) ? '/forms/' . (int) $input['form_id'] . '/submissions' : '/submissions';
		$result = self::rest( 'GET', $route, null, $query );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			// Core Forms keys entries by ID, which JSON turns into an object; clients expect a list.
			'submissions' => array_values( (array) ( $result['data'] ?? array() ) ),
			'total'       => (int) ( $result['total'] ?? 0 ),
			'pages'       => (int) ( $result['pages'] ?? 0 ),
		);
	}

	public static function get_submission( array $input ) {
		return self::rest( 'GET', '/submissions/' . (int) $input['submission_id'] );
	}

	public static function update_submission( array $input ) {
		$body = array_intersect_key( $input, array_flip( array( 'status' ) ) );
		if ( isset( $input['spam'] ) ) {
			$body['is_spam'] = (bool) $input['spam'];
		}
		if ( ! $body ) {
			return new \WP_Error( 'site_agent_nothing_to_save', __( 'Pass status or spam.', 'site-agent' ) );
		}
		return self::rest( 'PATCH', '/submissions/' . (int) $input['submission_id'], $body );
	}

	/**
	 * Installed version, modules, action types with their settings, field types, default form settings and
	 * messages, global settings with secrets hidden, and which settings wp-config.php constants supply.
	 */
	public static function overview(): array {
		$modules = array();
		if ( class_exists( '\Core_Forms\Modules\Modules' ) ) {
			foreach ( \Core_Forms\Modules\Modules::definitions() as $module => $definition ) {
				$modules[] = array(
					'id'      => (string) $module,
					'name'    => (string) ( $definition['name'] ?? $module ),
					'enabled' => \Core_Forms\Modules\Modules::enabled( (string) $module ),
				);
			}
		}
		$constants = array();
		if ( class_exists( '\Core_Forms\Credentials' ) ) {
			foreach ( \Core_Forms\Credentials::SETTINGS as $path => $names ) {
				$constants[] = array(
					'setting'   => $path,
					'constants' => $names,
					'source'    => \Core_Forms\Credentials::source( $path ),
				);
			}
			$constants[] = array(
				'setting'   => 'license',
				'constants' => array( \Core_Forms\Credentials::LICENSE ),
				'source'    => '' !== \Core_Forms\Credentials::constant( \Core_Forms\Credentials::LICENSE ) ? \Core_Forms\Credentials::LICENSE : '',
			);
		}
		$custom = class_exists( '\Core_Forms\Schema\CustomFields' ) && method_exists( '\Core_Forms\Schema\CustomFields', 'catalog' ) ? array_column( \Core_Forms\Schema\CustomFields::catalog(), 'type' ) : array();
		return array(
			'version'       => defined( 'CORE_FORMS_VERSION' ) ? (string) CORE_FORMS_VERSION : '',
			'modules'       => $modules,
			'action_types'  => self::action_types(),
			'field_types'   => array_values( array_unique( array_merge( \Core_Forms\Schema\Field::TYPES, $custom ) ) ),
			'form_settings' => function_exists( 'cf_get_form_default_settings' ) ? cf_get_form_default_settings() : array(),
			'messages'      => function_exists( 'cf_get_form_default_messages' ) ? cf_get_form_default_messages() : array(),
			'settings'      => function_exists( 'cf_get_settings' ) ? self::redact( (array) cf_get_settings() ) : array(),
			'constants'     => $constants,
		);
	}

	/**
	 * Registered action types with the default settings their editors start from, where an action exposes them.
	 *
	 * @return array<int, array{type: string, label: string, defaults: array}>
	 */
	private static function action_types(): array {
		global $wp_filter;
		$labels    = (array) apply_filters( 'cf_available_form_actions', array() );
		$instances = array();
		if ( isset( $wp_filter['cf_available_form_actions'] ) ) {
			foreach ( $wp_filter['cf_available_form_actions']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$target = is_array( $callback['function'] ) ? $callback['function'][0] : null;
					if ( is_object( $target ) && isset( $target->type ) && is_string( $target->type ) ) {
						$instances[ $target->type ] = $target;
					}
				}
			}
		}
		$types = array();
		foreach ( $labels as $type => $label ) {
			$defaults = array();
			$instance = $instances[ $type ] ?? null;
			if ( $instance && method_exists( $instance, 'get_default_settings' ) ) {
				try {
					$method = new \ReflectionMethod( $instance, 'get_default_settings' );
					if ( 0 === $method->getNumberOfRequiredParameters() ) {
						if ( PHP_VERSION_ID < 80100 ) {
							// Private methods are callable through reflection from PHP 8.1 on.
							$method->setAccessible( true );
						}
						$defaults = (array) self::redact( (array) $method->invoke( $instance ) );
					}
				} catch ( \Throwable $error ) {
					$defaults = array();
				}
			}
			$types[] = array(
				'type'     => (string) $type,
				'label'    => (string) $label,
				'defaults' => $defaults,
			);
		}
		return $types;
	}
}
