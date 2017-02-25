<?php

namespace Carbon_Fields\Container;

use Carbon_Fields\Datastore\Datastore;
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;

class User_Meta_Container extends Container {
	protected $user_id;

	public $settings = array(
		'show_on' => array(
			'role' => array(),
		),
		'show_for' => array(
			'relation' => 'AND',
			'edit_users',
		),
	);

	/**
	 * Create a new container
	 *
	 * @param string $unique_id Unique id of the container
	 * @param string $title title of the container
	 * @param string $type Type of the container
	 **/
	public function __construct( $unique_id, $title, $type ) {
		parent::__construct( $unique_id, $title, $type );
		$this->fulfillable_collection->set_condition_type_list( array(
			'user_id', // 'user_role'
		), true );

		if ( ! $this->get_datastore() ) {
			$this->set_datastore( Datastore::make( 'user_meta' ), $this->has_default_datastore() );
		}
	}

	/**
	 * Bind attach() and save() to the appropriate WordPress actions.
	 **/
	public function init() {
		add_action( 'admin_init', array( $this, '_attach' ) );
		add_action( 'profile_update', array( $this, '_save' ), 10, 1 );
		add_action( 'user_register', array( $this, '_save' ), 10, 1 );
	}

	/**
	 * Checks whether the current request is valid
	 *
	 * @return bool
	 **/
	public function is_valid_save( $user_id = 0 ) {
		if ( ! $this->verified_nonce_in_request() ) {
			return false;
		}

		if ( ! $this->is_valid_attach_for_request() ) {
			return false;
		}

		return $this->is_valid_attach_for_object( $user_id );
	}

	/**
	 * Perform save operation after successful is_valid_save() check.
	 * The call is propagated to all fields in the container.
	 *
	 * @param int $user_id ID of the user against which save() is ran
	 **/
	public function save( $user_id = null ) {
		// Unhook action to garantee single save
		remove_action( 'profile_update', array( $this, '_save' ) );

		$this->set_user_id( $user_id );

		foreach ( $this->fields as $field ) {
			$field->set_value_from_input( stripslashes_deep( $_POST ) );
			$field->save();
		}

		do_action( 'carbon_after_save_user_meta', $user_id );
	}

	/**
	 * Perform checks whether the container should be attached during the current request
	 *
	 * @return bool True if the container is allowed to be attached
	 **/
	public function is_valid_attach_for_request() {
		global $pagenow;

		if ( ! $this->is_profile_page() || ! $this->is_valid_show_for() ) {
			return false;
		}

		$input = stripslashes_deep( $_GET );

		$user_id = 0;
		if ( $pagenow === 'profile.php' ) {
			$user_id = get_current_user_id();
		}
		if ( isset( $input['user_id'] ) ) {
			$user_id = intval( $input['user_id'] );
		}
		$user = get_userdata( $user_id );

		$environment = array(
			'user_id' => $user ? intval( $user->ID ) : 0,
			'user' => $user ? $user : null,
			'role' => '',
		);

		if ( ! $this->fulfillable_collection->is_fulfilled( $environment ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check container attachment rules against object id
	 *
	 * @param int $object_id
	 * @return bool
	 **/
	public function is_valid_attach_for_object( $object_id = null ) {
		$valid = true;
		$user_id = $object_id;
		$user = get_userdata( $user_id );

		if ( ! $user  ) {
			return false;
		}

		if ( empty( $user->roles ) ) {
			return;
		}

		// Check user role
		if ( ! empty( $this->settings['show_on']['role'] ) ) {
			$allowed_roles = (array) $this->settings['show_on']['role'];

			// array_shift removed the returned role from the $user_profile->roles
			// $roles_to_shift prevents changing of the $user_profile->roles variable
			$roles_to_shift = $user->roles;
			$profile_role = array_shift( $roles_to_shift );
			if ( ! in_array( $profile_role, $allowed_roles ) ) {
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Add the container to the user
	 **/
	public function attach() {
		add_action( 'show_user_profile', array( $this, 'render' ), 10, 1 );
		add_action( 'edit_user_profile', array( $this, 'render' ), 10, 1 );
		add_action( 'user_new_form', array( $this, 'render' ), 10, 1 );
	}

	/**
	 * Whether we're on the user profile page
	 **/
	public function is_profile_page() {
		global $pagenow;

		return $pagenow === 'profile.php' || $pagenow === 'user-new.php' || $pagenow === 'user-edit.php';
	}

	/**
	 * Perform checks whether the container should be seen for the currently logged in user
	 *
	 * @return bool True if the current user is allowed to see the container
	 **/
	public function is_valid_show_for() {
		$show_for = $this->settings['show_for'];

		$relation = $show_for['relation'];
		unset( $show_for['relation'] );

		$validated_capabilities_count = 0;
		foreach ( $show_for as $capability ) {
			if ( current_user_can( $capability ) ) {
				$validated_capabilities_count++;
			}
		}

		/**
		 * When the relation is AND all capabilities must be evaluated to true
		 * When the relation is OR at least 1 must be evaluated to true
		 */
		$min_valid_capabilities_count = $relation === 'AND' ? count( $show_for ) : 1;

		return apply_filters( 'carbon_container_user_meta_is_valid_show_for', $validated_capabilities_count >= $min_valid_capabilities_count, $this );
	}

	/**
	 * Output the container markup
	 **/
	public function render( $user_profile = null ) {
		$profile_role = '';

		if ( is_object( $user_profile ) ) {
			$this->set_user_id( $user_profile->ID );

			// array_shift removed the returned role from the $user_profile->roles
			// $roles_to_shift prevents changing of the $user_profile->roles variable
			$roles_to_shift = $user_profile->roles;
			$profile_role = array_shift( $roles_to_shift );
		}

		include \Carbon_Fields\DIR . '/templates/Container/user_meta.php';
	}

	/**
	 * Set the user ID the container will operate with.
	 *
	 * @param int $user_id
	 **/
	public function set_user_id( $user_id ) {
		$this->user_id = $user_id;
		$this->get_datastore()->set_id( $user_id );
	}

	/**
	 * Validate and parse the show_for logic rules.
	 *
	 * @param array $show_for
	 * @return array
	 */
	protected function parse_show_for( $show_for ) {
		if ( ! is_array( $show_for ) ) {
			Incorrect_Syntax_Exception::raise( 'The argument passed to show_for() must be an array.' );
		}

		$parsed_show_for = array(
			'relation' => Helper::get_relation_type_from_array( $show_for ),
		);

		foreach ( $show_for as $key => $rule ) {
			if ( $key === 'relation' ) {
				continue; // Skip the relation key as it is already handled above
			}

			// Check if the rule is valid
			if ( ! is_string( $rule ) || empty( $rule ) ) {
				Incorrect_Syntax_Exception::raise( 'Invalid show_for logic rule format. ' .
				'The rule should be a string, containing an user capability/role.' );
			}

			$parsed_show_for[] = $rule;
		}

		return $parsed_show_for;
	}

	/**
	 * COMMON USAGE METHODS
	 */

	/**
	 * Show the container only on users who have the $role role.
	 *
	 * @param string $role
	 * @return object $this
	 **/
	public function show_on_user_role( $role ) {
		$this->settings['show_on']['role'] = (array) $role;

		return $this;
	}

	/**
	 * Show the container only for users who have either capabilities or roles setup
	 *
	 * @param array $show_for
	 * @return object $this
	 **/
	public function show_for( $show_for ) {
		$this->settings['show_for'] = $this->parse_show_for( $show_for );

		return $this;
	}
}
