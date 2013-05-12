<?php
/**
 * AvocaPluginComponent.php - serves as a framework for plugin modules
 *
 * The AvocaPluginComponent serves as an extendable class for adding modularized functionality to an Avoca
 * Wordpress Engine WP plugin.
 *
 * PHP version 5.3.x
 * Wordpress version 3.4.x
 *
 * @category   Engine Core
 * @package    AvocaWordpressEngine
 * @author     Adam Bosco <adam@avocaweb.net>
 * @copyright  2009-2013 Avoca
 * @license    
 * @version    
 * @since
 */

class AvocaPluginComponent {
	//Overarching plugin information
	protected $_avocaPlugin;	 //Direct reference to the encompassing extended AvocaPlugin class
	protected $_avocaPluginClass;//Name of the encompassing class that extends AvocaPlugin

	//Component configuration
	protected $_name;			//The pre-and-post-fix-less name of the component used primarily for programmatic access
	protected $_className;		//Full name of the class. If meant to be auto-loaded, should follow AP conventions
	protected $_slug;			//Used for URI construction
	protected $_domain;			//Used for namespacing hooks, messages, options, metadata, etc.
	protected $_directory;		//The absolute path to the directory in which this component resides
	protected $_version;		//Component version number. Not yet used
	protected $_dependencies;	//Component dependencies. Can be from other APF Plugins. Not yet used

	//Component assets
	protected $_scripts;			//Array of scripts queued for loading.
	protected $_styles;				//Array of styles queued for loading
	protected $_ajaxHandlers;		//Mapping of Ajax Handler keys to component methods
	protected $_oopApi = array(		//Mapping of API method 'keys' to component methods
			'$'		=> array(),		//exposed through the __get() magic method as AP::APC->${API call}
			'::'	=> array(),		//exposed through the __callStatic() magic method as AP::APC::{API call}
			'->'	=> array()		//exponed through the __call() magic method as AP::APC->{API call}
		);
	protected $_nonce;

	//
	protected $_paths;
	protected $_namespaces;

	//Component constructor - handles interfacing the component with the Wordpress Plugin API. Takes the encompassing plugin's details as arguments (passed during auto-instantiation)
	public function __construct( &$avocaPlugin, $arComponentConfig ) {
		if( !is_subclass_of( $avocaPlugin, 'AvocaPlugin' ) )
			die( 'The 1st argument passed to the APC constructor MUST be an Object that extends AvocaPlugin.' );

		//Associate component with correct AvocaPlugin
		$this->_avocaPlugin = $avocaPlugin;
		$this->_avocaPluginClass = $avocaPlugin->pluginClass;

		//Component loading routine
		$this->_setupConfiguration( $arComponentConfig );	//Establish a runtime component configuration that we can work with
		//self::_registerConfiguredScripts();					//Registers the scripts defined in the configuration
		//self::_registerConfiguredStyles();					//Registers the styles defined in the configuration
		//self::_registerConfiguredOopApis();					//Sets up mappings for the OOP API directives contained in the configuration
		$this->_registerReflectiveAjaxHandlers();				//Register AJAX handlers by checking the class' own methods against conventions from AP
		$this->_registerReflectiveAutoHooks();					//Register conventionally named methods as Wordpress hooks.
		$this->_registerReflectiveApiHandlers();				//Register conventionally named methods to the component OOP API

		$this->addAction( 'init', 'initialize' );				//Generate a nonce for this component

		$this->doComponentAction( 'loaded' );
	}

	//Dynamic properties controller. Allows us to map component properties to methods
	public function __get( $strProperty ) {
		//First things first - if an API handler for this property exists, route the call to it
		if( array_key_exists( $strProperty, $this->_oopApi['$'] ) )
			return call_user_func( array( $this, $this->_getApiHandler( $strProperty, '$' ) ) );	//Execute the registered handler

		if( in_array( $strProperty, array( 'name', 'domain', 'version', 'className' ) ) ) {
			$fieldName = '_' . $strProperty;
			return $this->$fieldName;
		}

		$this->fatal( 'Invalid component API call - API property "' . $strProperty . '" does not exist.' );
	}

	//Dynamic method controller.
	public function __call( $strMethodName, $arArguments ) {
		//First things first - if an API handler for this method exists, route the call to it
		if( array_key_exists( $strMethodName, $this->_oopApi['->'] ) )
			return call_user_func_array( array( $this, $this->_getApiHandler( $strMethodName, '->' ) ), $arArguments );	//Execute the registered handler

		//If the called method starts with "get", strip it out and send the request to the dynamic property controller
		if( strpos( $strMethodName, 'get' ) === 0 ) {
			$strMethodName = lcfirst( substr( $strMethodName, 3 ) );
			return $this->$strMethodName;
		}

		$this->fatal( 'Invalid component API call - API method "' . $strMethodName . '" does not exist.' );
	}

	//Dynamic static method controller. APC::getName() === APC::name() === APC->name() === APC->getName() === APC->name
	public static function __callStatic( $strMethodName, $arArguments ) {
		//First things first - if an API handler for this static method exists, route the call to it
		if( array_key_exists( $strMethodName, self::$_oopApi['::'] ) )
			return call_user_func_array( array( $this, self::_getApiHandler( $strMethodName, '::' ) ), $arArguments );	//Execute the registered handler

		//If the called method starts with "get", strip it out and send the request to the dynamic property controller
		if( strpos( $strMethodName, 'get' ) === 0 ) {
			$strMethodName = lcfirst( substr( $strMethodName, 3 ) );
			return self::$$strMethodName;
		}

		$this->fatal( 'Invalid component API call - static API method "' . $strMethodName . '" does not exist.' );
	}

	public function initialize() {
		$this->getNonce();

		$this->doComponentAction( 'initialized' );
	}

//Explicit getter methods

	public function getAjaxHandlerKeys() {
		return isset( $this->_ajaxHandlers ) ? array_keys( $this->_ajaxHandlers ) : array();
	}

	public function getAbsoluteDomain( $strDelimiter = '-' ) {
		return $this->_avocaPlugin->domain . $strDelimiter . $this->_domain;
	}

	public function getDir( $strType = 'core' ) {
		return $this->_paths['dir'][ $strType ];
	}

	public function getUri( $strType = 'core' ) {
		return $this->_paths['uri'][ $strType ];
	}

	public function getNamespace( $strType = 'handle' ) {
		return $this->_namespaces[ $strType ];
	}

	//Enqueues locally namespaced script handles. If this component registered any non-namespaced scripts, queue them via wp_enqueue_script().
	public function enqueueScript( $strScriptHandle ) {
		$strScriptHandle = $this->namespaceHandle( $strScriptHandle );

		if( !isset( $this->_scripts[ $strScriptHandle ] ) )
			return FALSE;

		$this->_scripts[] = $strScriptHandle;

		return TRUE;
	}

	//Enqueues the style corresponding to the locally namespaced handle. If this component registered any non-namespaced styles, they must be queued via wp_enqueue_style().
	public function enqueueStyle( $strStyleHandle ) {
		$strStyleHandle = $this->namespaceHandle( $strStyleHandle );

		if( !isset( $this->_styles[ $strStyleHandle ] ) )
			return FALSE;

		$this->_styles[] = $strStyleHandle;

		return TRUE;
	}

	public function registerComponentStyle( $strHandle, $arArguments ) {
		return $this->registerStyle( $strHandle, $arArguments, TRUE );
	}

	public function registerComponentScript( $strHandle, $arArguments ) {
		return $this->registerScript( $strHandle, $arArgument, TRUE );
	}

	//Register a script with Wordpress using a namespaced handle (globally queueable via {APName}::{APCName}::enqueueScript( $strScriptHandle ); ). $boolNamespace is overridden by explicitly passing a 'handle' argument.
	public function registerScript( $strHandle, $arArguments, $boolNamespace = TRUE ) {
		$defaultFilename = sanitize_file_name( $strHandle );
		$defaults = array(
				'filename'	=> $defaultFilename,	//Filename relative to the directory it resides in, without the file extension
				'deps'		=> array(),
				'ver'		=> '1.0',
				'infooter'	=> FALSE,
				'src'		=> $this->getDir('scripts') . '/' . $defaultFilename . '.js'
			);

		$arguments = array_merge( $defaults, $arArguments );
		$arguments['handle'] = $boolNamespace ? $this->namespaceHandle( $strHandle ) : $strHandle;

		if( !file_exists( $arguments['src'] ) )
			$this->error( 'Failed to register script "' . $arguments['handle'] . '": The specified file is not accessible ( ' . $arguments['src'] . ' ).' );

		if( wp_register_script( $arguments['handle'], $arguments['src'], $arguments['deps'], $arguments['ver'], $arguments['infooter'] ) )
			return TRUE;

		$this->error( 'Script registration failed for "' . $strHandle . '". Check your filepaths and script configurations.' );

		return FALSE;
	}

	public function registerStyle( $strHandle, $arArguments, $boolNamespace = TRUE ) {
		$defaultFilename = sanitize_file_name( $strHandle );
		$defaults = array(
				'filename'	=> $defaultFilename,
				'deps'		=> array(),
				'ver'		=> '1.0',
				'media'		=> 'all',
				'src'		=> $this->getDir('styles') . '/' . $defaultFilename . '.css'
			);

		$arguments = array_merge( $defaults, $arArguments );

		if( !file_exists( $arguments['src'] ) )
			$this->error( 'Failed to register style "' . $arguments['handle'] . '": The specified file is not accessible ( ' . $arguments['src'] . ' ).' );

		if( wp_register_style( $strHandle, $arguments['src'], $arguments['deps'], $arguments['ver'], $arguments['media'] ) )
			return TRUE;

		$this->error( 'Style registration failed for "' . $strHandle . '". Check your filepaths and style configurations.' );

		return FALSE;
	}

	//Deregisters the component-domain-namespaced handle from the wordpress scripts
	public function deregisterScript( $strHandle, $namespaced = TRUE ) {
		if( $namespaced )
			return wp_deregister_script( $this->getAbsoluteDomain() . '-' . $strHandle );
		else
			return wp_deregister_script( $strHandle );
	}

	//Deregisters the component-domain-namespaced handle from the wordpress styles
	public function deregisterStyle( $strHandle, $namespaced = TRUE ) {
		if( $namespaced )
			return wp_deregister_style( $this->getAbsoluteDomain() . '-' . $strHandle );
		else
			return wp_deregister_style( $strHandle );
	}

	protected function registerApiHandler( $strHandle, $strType, $strMethodName ) {
		if( !array_key_exists( $strType, $this->_oopApi ) )
			$this->fatal( 'Attempting to register component API handler "' . $strHandle . '" with an invalid API type (' . $strType . ').' );

		if( $this->apiHandlerExists( $strHandle, $strType ) )
			$this->warning( 'Double registration of component API handler "' . $strHandle . '" of API type "' . $strType . '". Former declaration will be overwritten.' );

		$this->_oopApi[ $strType ][ $strHandle ] = $strMethodName;
	}

// * Utility Methods

	//Gets the specified field from the encompassing AvocaPlugin if a 'get'-prefixed, public, static method to
	//retrieve the requested field exists in AvocaPlugin or its child
	/*public static function getPluginInfo( $strDataField ) {
		$strDataField	= ucfirst( $strDataField );

		if( method_exists( self::_avocaPlugin, 'get' . $strDataField ) && is_callable( self::_avocaPluginClass . '::get' . $strDataField ) )
			return call_user_func( self::_avocaPluginClass . '::get' . $strDataField );
		else
			self::error('No "getter" method exists for AvocaPlugin field ' . $strDataField);

		return FALSE;
	}*/

	//Prefixes $strString with the plugin and component domains seperated by $strNamespaceDelimiter
	public function namespaceString( $strString, $strNamespaceDelimiter = '-' ) {
		return $this->getAbsoluteDomain( $strNamespaceDelimiter ) . $strNamespaceDelimiter . $strString;
	}

	public function namespaceHandle( $strHandle ) {
		return $this->namespaceString( $strHandle, '-' );
	}

	public function namespaceKey( $strKey ) {
		return strtolower( $this->namespaceString( $strKey, '_' ) );
	}

	//Removes occurences of $strNamespaceDelimiter, the plugin domain, and the component domain in $strString
	public function localizeString( $strString, $strNamespaceDelimiter = '-' ) {
		return str_ireplace( array(
				$strNamespaceDelimiter,
				$this->_domain,
				$this->_avocaPlugin->domain 
			), '', $strString );
	}

	public function localizeHandle( $strHandle ) {
		return strtolower( $this->localizeString( $strHandle, '-' ) );
	}

	public function localizeKey( $strKey ) {
		return strtolower( $this->localizeString( $strKey, '_' ) );
	}

//   - Namespaced hook and action handling

	//Component hooks piggy-back off of the Wordpress Hooks/Actions API, but auto-namespce hooknames throughout APCs for consistancy,
	//and ease of use when combined with APC's auto-hooks. It accepts an indefinite number of arguments that will be passed to hooking functions.
	//This function executes the given action namespaced locally.
	public function doComponentAction( $strActionName ) {
		$arguments = func_get_args();
		$arguments[0] = $this->namespaceKey( $strActionName );

		do_action( 'debug_do_component_action', $arguments );

		return call_user_func_array( 'do_action', $arguments );
	}

	//Attaches a component method to a namespaced WP action
	public function addComponentAction( $strActionName, $strCallbackMethodName ) {
		return $this->addAction( $this->namespaceKey( $strActionName ), $strCallbackMethodName );
	}

	//Basically a wrapper around wp's do_action.
	public function doAction() {
		$arguments = func_get_args();

		//self::debug('Executing doAction on action, "' . $arguments[0] . '" with arguments ' . print_r($arguments, TRUE));

		return call_user_func_array( 'do_action', $arguments );
	}

	//Like WP's add_action, save that the callback always references the APC child class - no need to specify the object in a callback array
	public function addAction( $strHookName, $strMethodName ) {
		return add_action( $strHookName, array( $this, $strMethodName ) );
	}

//   - Namespaced error handling and debugging
	//Throws down namespaced debug messages
	public function debug( $strMessage, $arTags = NULL, $strSubdomain = NULL ) {
		$strSubDomain = isset( $strSubDomain ) ? ' :: ' . $strSubdomain : '';
		$arTags[] = $this->_domain;
		return call_user_func_array( array( $this->_avocaPlugin, 'debug' ), array( $strMessage, $arTags, $this->_domain . $strSubdomain ) );
	}

	//Throws down namespaced "notice"s
	public function notice( $strMessage, $arTags = NULL, $strSubdomain = NULL ) {
		$strSubDomain = isset( $strSubDomain ) ? ' :: ' . $strSubdomain : '';
		$arTags[] = $this->_domain;
		return call_user_func_array( array( $this->avocaPlugin, 'notice' ), array( $strMessage, $arTags, $this->_domain . $strSubdomain ) );
	}

	//Throws down "warning" level debug messages namespaced by using the AP domain as a tag
	public function warning( $strMessage, $arTags = NULL, $strSubdomain = NULL ) {
		$strSubDomain = isset( $strSubDomain ) ? ' :: ' . $strSubdomain : '';
		$arTags[] = $this->_domain;
		return call_user_func_array( array( $this->_avocaPlugin, 'warning' ), array( $strMessage, $arTags, $this->_domain . $strSubdomain ) );
	}

	//Throws down namespaced "error" level messages
	public function error( $strMessage, $arTags = NULL, $strSubdomain = NULL ) {
		$strSubDomain = isset( $strSubDomain ) ? ' :: ' . $strSubdomain : '';
		$arTags[] = $this->_domain;
		return call_user_func_array( array( $this->_avocaPlugin, 'error' ), array( $strMessage, $arTags, $this->_domain . $strSubdomain ) );
	}

	//Throws down namespaced "fatal" level messages and terminates execution
	public function fatal( $strMessage, $arTags = NULL, $strSubdomain = NULL ) {
		$strSubDomain = isset( $strSubDomain ) ? ' :: ' . $strSubdomain : '';
		$arTags[] = $this->_domain;
		return call_user_func_array( array( $this->_avocaPlugin, 'fatal' ), array( $strMessage, $arTags, $this->_domain . $strSubdomain ) );
	}

//These are all functions hooking into the WP Plugin API. They are being attached via the APC conventional auto-hooks (self::_register_reflective_auto_hooks(), executed on construction)

	//Enqueues with Wordpress all of the scripts (and their associated dependecies) added through APC's enqueue_script method, right before the scripts are printed 
	public function hook_wp_enqueue_scripts() {
		for( $i = 0; $i < count( $this->_scripts ); $i++ ) {
			$handle = $this->_scripts[$i];
			wp_enqueue_script( $handle, $this->_scripts[ $handle ]['src'], $this->_scripts[ $handle ]['deps'], $this->_scripts[ $handle ]['ver'], $this->_scripts[ $handle ]['infooter'] );
		}
			
	}

	//Enqueues with Wordpress all of the styles (and their associated dependecies) added through APC's enqueue_style method, right before the styles are printed
	public function hook_wp_enqueue_styles() {
		for( $i = 0; $i < count( $this->_styles ); $i++ ) {
			$handle = $this->_styles[ $i ];
			wp_enqueue_style( $handle, $this->_styles[ $handle ]['src'], $this->_styles[ $handle ]['deps'], $this->_styles[ $handle ]['ver'], $this->_styles[ $handle ]['media'] );
		}
	}

	//Registers with Wordpress any component widgets registered with this component
	/*public function hook_widget_init() {
		for( $i = 0; $i < count( $this->_config['widgets'] ); $i++ )
			register_widget( $this->_config['widgets'][$i] );
	}*/

//Private functions - all of the APC blackbox

	//This function manages all of the ajax actions specified in a component's config.
	//TODO: Should really be updated to use WP_Ajax_Response
	public function ajaxController() {
		if( !$this->_checkAjaxReferer( FALSE ) )
			$this->_ajaxFail( 'Invalid security token for ' . $this->_className );

		$handlerKey = $this->localizeKey( $_POST['action'] );

		if( !$this->ajaxHandlerExists( $handlerKey ) )
			$this->_ajaxFail( 'Invalid ajax handler key "' . $handlerKey . '". Correct your POST $action ( ' . $_POST['action'] . ' )' );

		$this->doComponentAction( 'before_ajax_call', $handlerKey );

		$data = $this->_callAjaxHandler( $handlerKey );

		$this->doComponentAction( 'after_ajax_call', $handlerKey, $data );

		if( $data === FALSE )
			$this->_ajaxFail( 'Call to ajax handler recognized with key "' . $handlerKey . '" has failed. POST $action ( ' . $_POST['action'] . ' )' );
		else if( $data === TRUE )
			$this->_ajaxSuccess();
		else
			$this->_ajaxSuccess( $data );
	}

	//Builds a response and responds to ajax requests. Should be updated to use WP_Ajax_Response, or wait for a JSON patch to the core
	private function _ajaxResponse( $mixData = NULL, $strStatus = 'success' ) {
		static $headerSent = FALSE;
		static $responses = array();

		$acceptableStates = array( 'success', 'failure', 'error', 'partial' );

		if( ! in_array( $strStatus, $acceptableStates ) )
			$this->ajaxError( 'Server Error: "' . $strStatus . '" is not a valid AJAX response status string.' );	//TODO: Being a server error, this should probably report to... you know... the server. Not the front end.

		$response = array(
			'status' => $strStatus
		);

		if( isset( $mixData ) )
			$response['data'] = $mixData;

		if( !$headerSent ) {
			header( "Content-Type: application/json" );
			$headerSent = TRUE;
		}

		$responses[] = $response;

		if( $strStatus == 'success' || $strStatus == 'failure' ) {
			echo json_encode( $responses );
			exit;
		}

		return TRUE;
	}

	//Add a block of arbitrary data to the response (the response will not yet be sent, the function will return)
	private function _ajaxPartial( $strErrorMessage ) {
		return $this->_ajaxResponse( array( 'message' => $strErrorMessage  ), 'partial' );
	}

	//Add a non-fatal error message to the ajax response (the response will not yet be sent, the function will return)
	private function _ajaxError( $strErrorMessage ) {
		return $this->_ajaxResponse( array( 'message' => $strErrorMessage  ), 'error' );
	}

	//Fatally terminate an ajax call with an error message (the response will be sent, the script terminated)
	private function _ajaxFail( $strErrorMessage ) {
		$this->_ajaxResponse( array( 'message' => $strErrorMessage  ), 'failure' );
	}

	//Terminate a successful ajax call, optionally accompanied by a data package (the response will be sent, the script terminated)
	private function _ajaxSuccess( $mixResponseData = NULL ) {
		$this->_ajaxResponse( $mixResponseData, 'success' );
	}
	
	//checks to see if an ajax handler has been registered, and is thusly available to call upon
	public function ajaxHandlerExists( $strAjaxHandlerKey ) {
		return isset( $this->_ajaxHandlers[ $strAjaxHandlerKey ] ) && is_string( $this->_ajaxHandlers[ $strAjaxHandlerKey ] );
	}

	//Checks to see if an API handler of a specific type ("::", "->", or '$', corresponding to Static Method, Method, Property)
	public function apiHandlerExists( $strApiHandlerKey, $strType = NULL ) {
		if( isset( $strType ) && array_key_exists( $strType, $this->_oopApi ) ) {
			return isset( $this->_oopApi[ $strType ][ $strApiHandlerKey ] );
		} else {
			$apiTypes = array_keys( $this->_oopApi );

			for( $i = count( $apiTypes ); $i > 0; $i-- ){
				if( array_key_exists( $strApiHandlerKey, $this->_oopApi[ $apiTypes[$i] ] ) )
					return TRUE;
			}
		}

		return FALSE;
	}

	//Checks to see if the provided string fits the AP ajax handler naming convention
	public function isConventionalAjaxHandler( $strMethodName ) {
		//Does the provided method name have the appropriate prefix?
		return ( strpos( $strMethodName, $this->_avocaPlugin->ajaxHandlerPrefix ) === 0 );
	}

	//Must be public for user_call_func() access. Checks to see if the provided string fits the AP ajax handler naming convention
	public function isConventionalAutohook( $strMethodName ) {
		//Does the provided method name have the appropriate prefix?
		return ( strpos( $strMethodName, $this->_avocaPlugin->autohookPrefix ) === 0 && strpos( $strMethodName, $this->_avocaPlugin->autohookPrefix) );
	}

	public function isConventionalApiHandler( $strMethodName ) {
		return ( strpos( $strMethodName, $this->_avocaPlugin->apiHandlerPrefix ) === 0 );
	}

	//Registers new ajax handlers by mapping a key to a member function. If no method is provided, will check to see if a method matching the handler
	//key and AP conventions exists.
	//TODO: allow seperation of admin and front-end ajax calls via component configuration and ajax_/ajax_nopriv_/ajax_admin_
	public function registerAjaxHandler( $strAjaxHandlerKey, $strMethodName = NULL ) {
		//If already registered, bail
		if( $this->ajaxHandlerExists( $strAjaxHandlerKey ) ) {
			$this->error( 'AJAX Handler "' . $strAjaxHandlerKey . '" is already registered.' );
			return FALSE;
		}

		$strMethodName = isset( $strMethodName ) ? $strMethodName : $this->_getAjaxHandler( $strAjaxHandlerKey );

		//If the ajax handler has an accessible handler method, map it out and attach it to the properly namespaced WP action
		if( $strMethodName !== FALSE && $this->_hasMethod( $strMethodName ) ) {
			$this->_ajaxHandlers[ $strAjaxHandlerKey ] = $strMethodName;

			add_action( 'wp_ajax_' . $this->namespaceKey( $strAjaxHandlerKey ), array( &$this, 'ajaxController' ) );
			add_action( 'wp_ajax_nopriv_' . $this->namespaceKey( $strAjaxHandlerKey ), array( &$this, 'ajaxController' ) );

			$this->doComponentAction( 'register_ajax_handler', $strAjaxHandlerKey );
			return TRUE;
		}

		$this->error( 'Cannot bind Ajax Handler "' . $strAjaxHandlerKey . '" to method "' . $strMethodName . '" - the method does not exist.' );

		return FALSE;
	}

	//Returns a nonce to be used in content generated by this component
	public function getNonce( $instance = NULL ) {
		if( !isset( $instance ) )
			$instance = $this;

		if( !isset( $instance->_nonce ) )
			$instance->_nonce = wp_create_nonce( $instance->getAbsoluteDomain() );

		return $instance->_nonce;
	}

	//Returns the AP configured location of the nonce in the request data
	protected function _getNonceKey() {
		return $this->_avocaPlugin->securityKey;
	}

	//Retrieves the security nonce from the request data
	protected function _getRequestNonce() {
		$dataKey = $this->_getNonceKey();

		if( !isset( $_REQUEST[ $dataKey ] ) )
			return FALSE;

		return $_REQUEST[ $dataKey ];
	}

	//Checks $strNonce against the security nonce for this component
	protected function _nonceIsValid( $strNonce ) {
		return ( wp_verify_nonce( $strNonce, $this->getAbsoluteDomain() ) !== FALSE );
	}

	//Checks the validity of the nonce passed with the request data
	protected function _requestNonceIsValid() {
		return $this->_nonceIsValid( $this->_getRequestNonce() );
	}

	//Checks the validity of a nonce passed with an AJAX request
	protected function _checkAjaxReferer( $boolDie = TRUE ) {
		return check_ajax_referer( $this->getAbsoluteDomain(), $this->_getNonceKey(), $boolDie );
	}

	//Returns a nonce field valid for requests to this component
	protected function _getNonceField() {
		return wp_nonce_field( $this->getAbsoluteDomain(), $this->_getNonceKey(), TRUE, FALSE );
	}

	//Returns $strUri appended with the component nonce as GET data
	protected function _getNonceUri( $strUri ) {
		return wp_nonce_url( $strUri, $this->getAbsoluteDomain() );
	}

	//Checks to see if the provided method actually exists in the public scope of this object
	protected function _hasMethod( $strMethodName ) {
		//Do both the class and the method exist?
		return ( class_exists( $this->_className ) && method_exists( $this->_className, $strMethodName ) );
	}

	//Returns the name of the method that a particular handler key corresponds to
	protected function _getAjaxHandler( $strAjaxHandlerKey ) {
		//If the handler is already registered, return it
		if( $this->ajaxHandlerExists( $strAjaxHandlerKey ) )
			return $this->_ajaxHandlers[ $strAjaxHandlerKey ];

		//If the handler is not registered, 
		$methodName = $this->_avocaPlugin->ajaxHandlerPrefix . $strAjaxHandlerKey;
		if( $this->_hasMethod( $methodName ) )
			return $methodName;

		return FALSE;
	}

	//Returns the name of the method that a particular handler key of a particular API type corresponds to
	protected function _getApiHandler( $strApiHandlerKey, $strType ) {
		if( array_key_exists( $strType, $this->_oopApi ) ) {
			if( $this->apiHandlerExists( $strApiHandlerKey, $strType ) )
				return $this->_oopApi[ $strType ][ $strApiHandlerKey ];
			else
				$this->error( 'Requested API Handler "' . $strApiHandlerKey . '" does not exist in this component.' );
		} else {
			$this->error( 'Invalid component API type: "' . $strType . '"' );
		}

		return FALSE;
	}

	//Examines the component class and creates Wordpress hooks for ajax handler method names that adhere to the naming convention defined in RSP's constants
	private function _registerReflectiveAjaxHandlers( $arMethodList = NULL ) {
		$methods = isset( $arMethodList ) ? $arMethodList : get_class_methods( $this->_className );
		$ajaxHandlerMethods = array_filter( $methods, array( $this, 'isConventionalAjaxHandler' ) );	//Get the method names that follow convention

		while( $handlerMethod = array_pop( $ajaxHandlerMethods ) ) {
			$key = substr( $handlerMethod, strlen( $this->_avocaPlugin->ajaxHandlerPrefix ) );
			$this->registerAjaxHandler( $key, $handlerMethod );
		}
	}

	//Examines the component class's methods and creates hooks for conventionally named functions, i.e. hook_wp_enqueue_styles() would hook to the wp_enqueue_styles hook.
	private function _registerReflectiveAutohooks( $arMethodList = NULL ) {
		$methods = isset( $arMethodList ) ? $arMethodList : get_class_methods( $this->_className );
		$autoHookMethods = array_filter( $methods, array( $this, 'isConventionalAutoHook' ) );	//Get the method names that follow convention

		while( $autoHook = array_pop( $autoHookMethods ) )
			$this->_registerAutohook( substr( $autoHook, strlen( $this->_avocaPlugin->autohookPrefix ) ), $autoHook );
	}

	//Examines the component class's mehtods and maps conventionally named methods to the OOP Component API
	private function _registerReflectiveApiHandlers( $arMethodList = NULL ) {
		$methods = isset( $arMethodList ) ? $arMethodList : get_class_methods( $this->_className );
		$oopApiMethods = array_filter( $methods, array( $this, 'isConventionalApiHandler' ) );

		while( $apiMethod = array_pop( $oopApiMethods ) ) {
			$parts = explode( '_', $apiMethod );

			switch( $parts[1] ) {
				case 'method':
					$apiType = '->';
					break;
				case 'property':
					$apiType = '$';
					break;
				case 'static':
					$apiType = '::';
					break;
			}

			$handle = str_replace( $parts[0] . '_' . $parts[1] . '_', '', $apiMethod );
			$this->registerApiHandler( $handle, $apiType, $apiMethod );
		}
	}

	//Executes the given ajax handler with an indefinite number of arguments
	private function _callAjaxHandler( $strAjaxHandlerKey ) {
		$arguments = func_get_args();
		array_shift( $arguments );

		return call_user_func_array( array( $this, $this->_getAjaxHandler( $strAjaxHandlerKey ) ), $arguments );
	}

	//Executes the given api handler of the specified type with an indefinite number of arguments
	private function _callApiHandler( $strApiHandlerKey, $strApiType ) {
		$arguments = func_get_args();
		$arguments = array_slice( $arguments, 2 );	//Throw out the APIHandlerKey and the API type.

		return call_user_func_array( array( $this, $this->_getApiHandler( $strApiHandlerKey, $strApiType ) ), $arguments );
	}

	//Establishes a runtime configuration for the component
	private function _setupConfiguration( $arConfig = array() ) {
		if( !isset( $arConfig ) || empty( $arConfig ) || !isset( $arConfig['name'] ) || !is_string( $arConfig['name'] ) )
			$this->fatal( 'Component configuration must include a "name" property at the very least.' );
		
		$componentClass = get_called_class();	//Figure out what the child class is
		$this->_className = $componentClass;
		$componentName = $this->_avocaPlugin->deconventionalizeComponentName( $componentClass );	//Strip any AP conventions from the classname and deem it the component's name

		//TODO: there's no reason to generate default fields if they've already been supplied in the configuration.
		//TODO: add configuration directives for asset paths
		$defaultConfig = array(
				'name'			=> $componentName,
				'slug'			=> sanitize_title( $componentName ),
				'domain'		=> $this->_avocaPlugin->componentDomain( $componentName ),			
				'className'		=> $componentClass,
				'version'		=> '1.0',
				'scripts'		=> array(),
				'styles'		=> array(),
				'ajaxHandlers'	=> array(),
				'directory'		=> $this->_avocaPlugin->getDir('components') . '/' . $componentClass,
				'API'			=> array()
				//'widgets'		=> array()
			);

		$config = array_merge( $arConfig, $defaultConfig );

		if( !file_exists( $config['directory'] ) )
			$this->fatal('Component directory "' . $config['directory'] . '" does not exist. Check your component configuration.');

		//Load scripts from config
		//TODO: replace with a for/while loop
		if( isset( $config['scripts'] ) ) {
			foreach( $config['scripts'] as $strScriptHandle => $scriptProperties ) {
				//Namespace the script handle unless the script has a "scope" property set to "global"
				$boolNamespace = isset( $scriptProperties['scope'] ) && $scriptProperties['scope'] == 'global' ? FALSE : TRUE;

				if( script_is( $strScriptHandle, 'registered' ) ) {
					$this->warning( 'Script ' . $strScriptHandle . ' is already registered. Previous handle will be overwritten.'  );
					$this->deregisterScript( $strScriptHandle, $boolNamespace );
				}

				$this->registerScript( $strScriptHandle, $scriptProperties, $boolNamespace );
			}

			unset( $config['scripts'] );	//Throw out the scripts sub-array
		}

		if( isset( $config['styles'] ) ) {
			foreach( $config['styles'] as $strStyleHandle => $styleProperties ) {
				//Namespace the script handle unless the script has a "scope" property set to "global"
				$boolNamespace = isset( $scriptProperties['scope'] ) && $scriptProperties['scope'] == 'global' ? FALSE : TRUE;

				if( style_is( $strStyleHandle, 'registered' ) ) {
					$this->warning( 'Style ' . $strStyleHandle . ' is already registered. Previous handle will be overwritten.'  );
					$this->deregisterStyle( $strStyleHandle, $boolNamespace );
				}
				$this->registerStyle( $strStyleHandle, $styleProperties, $boolNamespace );
			}

			unset( $config['styles'] );		//Throw out the styles sub-array
		}

		//TODO: configured API handling
		if( isset( $config['API'] ) ) {
			unset( $config['API'] );
		}

		//TODO: configured AJAX handler registration
		if( isset( $config['ajaxHandlers'] ) ) {
			unset( $config['ajaxHandlers'] );
		}
		
		//Extract the remainder of the caclulated configuration into the APC instance config
		//TODO: replace with a for or while loop.
		foreach( $config as $directive => $setting ) {
			$componentField = '_' . $directive;
			$this->$componentField = $setting;
		}

		//Set Up Paths
		$rootURI = $this->_avocaPlugin->getUri('components') . '/' . $config['className'];		//Figure out the base absolute URI for this component's files

		//Set up an array of absolute paths for quick reference
		$this->_paths = array(
				'dir' => array(
						'component'		=> $config['directory'],
						'scripts'		=> $config['directory'] . '/js',
						'styles'		=> $config['directory'] . '/css',
						'templates'		=> $config['directory'] . '/templates'
					),
				'uri' => array(
						'component'		=> $rootURI,
						'scripts'		=> $rootURI . '/js',
						'styles'		=> $rootURI . '/css',
						'templates'		=> $rootURI . '/templates'
					)
			);
	}
}

?>