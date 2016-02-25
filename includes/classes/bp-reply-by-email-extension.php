<?php
/**
 * BP Reply By Email Extension Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract base class for adding support for RBE to your plugin.
 *
 * This class relies on the activity component.  If your plugin doesn't rely
 * on the activity component (eg. PMs), don't extend this class.
 *
 * Extend this class and in your constructor call the bootstrap() method.
 * See inline docs in the bootstrap() method for more details.
 *
 * Next, override the post_by_email() method to do your custom checks and
 * posting routine.
 *
 * You should call this class anytime *after* the 'bp_include' hook of priority 10,
 * but before or equal to the 'bp_loaded' hook.
 *
 * @since 1.0-RC1
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
abstract class BP_Reply_By_Email_Extension {
	/**
	 * Holds our custom variables.
	 *
	 * These variables are stored in a protected array that is magically
	 * updated using PHP 5.2+ methods.
	 *
	 * @see BP_Reply_By_Email::bootstrap() This is where $data is defined
	 * @var array
	 */
	protected $data;

	/**
	 * Magic method for checking the existence of a certain data variable.
	 *
	 * @param string $key
	 */
	public function __isset( $key ) { return isset( $this->data[$key] ); }

	/**
	 * Magic method for getting a certain data variable.
	 *
	 * @param string $key
	 */
	public function __get( $key ) { return isset( $this->data[$key] ) ? $this->data[$key] : null; }

	/**
	 * Extensions must use this method in their constructor.
	 *
	 * @param array $data See inline doc.
	 */
	protected function bootstrap( $data = array() ) {
		if ( empty( $data ) ) {
			_doing_it_wrong( __METHOD__, 'array $data cannot be empty.' );
			return;
		}

		/*
		Paramaters for $data are as follows:

		$data = array(
			'id'                      => 'your-unique-id',   // your plugin name
			'activity_type'           => 'my_activity_type', // activity 'type' you want to match
			'item_id_param'           => '',                 // short parameter name for your 'item_id',
			'secondary_item_id_param' => '',                 // short paramater name for your 'secondary_item_id' (optional)
		);

		*/
		$this->data = $data;

		$this->setup_hooks();
	}

	/**
	 * Detect your extension and do your post routine in this method.
	 *
	 * Yup, you must declare this in your class and actually write some code! :)
	 * The actual contents of this method will differ for each extension.
	 *
	 * See {@link BP_Reply_By_Email::post()} for an example.
	 *
	 * You should return the posted ID of your extension on success and a {@link WP_Error}
	 * object on failure.
	 *
	 * @param bool $retval Defeults to boolean true.
	 * @param array $data The data from the parsing. Includes email content, user ID, subject.
	 * @param array $params Holds an array of params used by RBE. Also holds the params registered in
	 *  the bootstrap() method.
	 * @return array|object On success, return an array of the posted ID recommended. On failure, return a
	 *  WP_Error object.
	 */
	abstract public function post( $retval, $data, $params );

	/**
	 * Hooks! We do the dirty work here, so you don't have to! :)
	 */
	protected function setup_hooks() {
		add_action( 'bp_rbe_extend_activity_listener',          array( $this, 'extend_activity_listener' ),  10, 2 );
		add_filter( 'bp_rbe_extend_querystring',                array( $this, 'extend_querystring' ),        10, 2 );
		add_filter( 'bp_rbe_allowed_params',                    array( $this, 'register_params' ) );
		add_filter( 'bp_rbe_parse_completed',                   array( $this, 'post' ),                      10, 3 );

		// (recommended to extend) custom hooks to log unmet conditions during posting
		// your extension should do some error handling to let RBE know what's happening
		// and optionally, you should inform the sender that their email failed to post
		add_filter( 'bp_rbe_extend_log_no_match',               array( $this, 'internal_rbe_log' ),          10, 5 );
		add_filter( 'bp_rbe_extend_log_no_match_email_message', array( $this, 'failure_message_to_sender' ), 10, 5 );
	}

	/**
	 * RBE activity listener for your plugin.
	 *
	 * Override this in your class if your 'item_id' / 'secondary_item_id' needs to be calculated
	 * in a different manner.
	 *
	 * If your plugin doesn't rely on the activity component, you will probably want to override
	 * this method and make it an empty method.
	 *
	 * @param obj $listener Registers your component with RBE's activity listener
	 * @param obj $item The activity object generated by BP during save.
	 */
	public function extend_activity_listener( $listener, $item ) {
		if ( ! empty( $this->activity_type ) && $item->type == $this->activity_type ) {
			$listener->component = $this->id;
			$listener->item_id   = $item->item_id;

			if ( ! empty( $this->secondary_item_id_param ) )
				$listener->secondary_item_id = $item->secondary_item_id;
		}
	}

	/**
	 * Sets up the querystring used in the 'Reply-To' email address.
	 *
	 * Override this if needed.
	 *
 	 * @param string $querystring Querystring used to form the "Reply-To" email address.
 	 * @param obj $listener The listener object registered in the extend_activity_listener() method.
 	 * @param string $querystring
	 */
	public function extend_querystring( $querystring, $listener ) {
		// check to see if the listener component matches our extension's unique ID
		// if it does, proceed with setting up our custom querystring
		if ( $listener->component == $this->id ) {
			$querystring = "{$this->item_id_param}={$listener->item_id}";

			// some querystrings only use one parameter; if a second one exists,
			// add it.
			if ( ! empty( $this->secondary_item_id_param ) )
				$querystring .= "&{$this->secondary_item_id_param}={$listener->secondary_item_id}";
		}

		return $querystring;
	}

	/**
	 * This method registers your 'item_id_param' / 'secondary_item_id_param' with RBE.
	 *
	 * You shouldn't have to override this.
	 *
	 * @param array $params Whitelisted parameters used by RBE for the querystring
	 * @return array $params
	 */
	public function register_params( $params ) {
		if ( ! empty( $params[$this->item_id_param] ) ) {
			_doing_it_wrong( __METHOD__, 'Your "item_id_param" is already registered in RBE.  Please change your "item_id_param" to a more, unique identifier.' );
			return $params;
		}

		if ( ! empty( $this->secondary_item_id_param ) && ! empty( $params[$this->secondary_item_id_param] ) ) {
			_doing_it_wrong( __METHOD__, 'Your "secondary_item_id_param" is already registered in RBE.  Please change your "secondary_item_id_param" to a more, unique identifier.' );
			return $params;
		}

		$params[$this->item_id_param] = false;

		if ( ! empty( $this->secondary_item_id_param ) )
			$params[$this->secondary_item_id_param] = false;

		return $params;
	}

	/**
	 * Log your extension's error messages during the post_by_email() method.
	 *
	 * Extend away!
	 *
	 * @param mixed $log Should override to string in method.  Defaults to boolean false.
	 * @param string $type Type of error message
	 * @param array $headers The email headers
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return mixed $log
	 */
	public function internal_rbe_log( $log, $type, $headers, $i, $connection ) {
		return $log;
	}

	/**
	 * Setup your extension's failure message to send back to the sender.
	 *
	 * Extend away!
	 *
	 * @param mixed $message Should override to string in method.  Defaults to boolean false.
	 * @param string $type Type of error message
	 * @param array $headers The email headers
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return mixed $message
	 */
	public function failure_message_to_sender( $message, $type, $headers, $i, $connection ) {
		return $message;
	}

}