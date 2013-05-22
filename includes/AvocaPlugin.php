<?php
/**
 * AvocaPlugin.php - serves as a framework for functionally modularized plugins
 *
 * The AvocaPlugin serves as an extendable class for modularized Wordpress plugins compatible with
 * the proprietary Avoca Wordpress Engine. In particular, the AP automagically handles interfacing components
 * with the Wordpress Plugin API.
 *
 * PHP version 5.3.x
 * Wordpress version 3.x
 * Wordpress version 3.4.x
 *
 * @category   Core
 * @package    AvocaWeb\PluginFramework
 * @author     Adam Bosco <adam@avocaweb.net>
 * @copyright  2009-2013 Avoca Web Services, LLC. (a subsidiary of Alpine Venture Company, LLC)
 * @link       http://avocaweb.net
 * @since      
 */

namespace AvocaWeb\PluginFramework;

class Plugin {
	/* Note that there are no actual public properties. This is because public properties should be reserved
	 * for the use of the extending plugin - either by way of explicit declaration or adding to the AvocaPlugin
	 * OOP API through prefixed method names or association in the plugin configuration array. */
	const FORCE_DEBUG	= PluginFramework::FORCE_DEBUG;	//Force debugging regardless of WP_DEBUG.

	//Filestructure configuration
	protected $_componentDirName		= 'components';		//Modular functionality
	protected $_includeDirName			= 'includes';		//Engine core
	protected $_libraryDirName			= 'lib';			//3rd party packages

	//AvocaPluginComponent convention configuration
	protected $_componentPrefix			= 'AP';				//Autoload components prefixed with this...
	protected $_componentPostfix		= 'Component';		//and post-fixed with this from the components folder
	protected $_autohookPrefix			= 'hook_';			//Automagically attach component methods prefixed with this to the Wordpress Plugin API
	protected $_ajaxHandlerPrefix		= 'ajax_';			//Treat component methods prefixed with this as AJAX handlers
	protected $_apiHandlerPrefix		= 'api_';			//Treat component methods prefixed with this string as handlers for the component's OOP-exposed API
	protected $_componentDomainLength	= 8;				//Limit auto-generated component domains to this number of characters
	protected $_securityKey				= 'secTok';			//Where to look for security nonces in request data

	//Singleton configuration
	private $_instance;				//The actual instance of the extended AvocaPlugin. All AvocaPlugins should be singletons - there is never any need for multiple instances of the same extended AvocaPlugin.
	private $_pluginFile;			//The absolute path to the 'primary' plugin file - i.e. the plugin file that Wordpress loads
	private $_pluginClass;
	private $_name;					//Name of the plugin as whole
	private $_slug;					//URI-friendly derivation of self::_name 
	private $_domain;				//Abbreviated domain appended to name-spaced action hook, WP option, and metadata keys
	private $_paths;				//Lists of filepaths and URIs pertaining to the plugin directory
	private $_debugMode;			//Plugin-level debug mode making use of PhpConsole for output

	protected $_components;			//Array containing singleton instances of loaded components

	//AvocaPlugin constructor
	public function __construct( $strPluginFile, $arInitialConfiguration, $boolDebugMode = FALSE ) {
		//Non-WP Initialization routine
		$this->_initializePaths( $strPluginFile );									//Map out where everything is for quick reference later
		$this->_initializeConfiguration( $strPluginFile, $arInitialConfiguration );	//Set up a runtime config. Child class must provide a "AvocaPluginConfiguration" method that returns a configuration array
		$this->_initializeDebugging( dirname( $strPluginFile ), $boolDebugMode );	//Establish notice/warning/fatal/error message output

		$this->registerConventionalComponents();   //Register and instantiate conventionally named components from the components directory
		$this->registerJavascriptApi();

		//Static hooks
		//add_action( 'wp_enqueue_scripts', array( &$this, 'setupJavascript' ) );
	}

	//Getter method. Will return the VALUE of any APC property listed in the $getAbleFields array
	public function __get( $strProperty ) {
		//First things first - check to see if the requested property is actually a component
		if( isset( $this->_components[ $strProperty ] ) )
			return $this->_components[ $strProperty ];

		$getAbleFieldNames = array( 'name', 'domain', 'slug', 'pluginClass', 'securityKey', 'componentPrefix', 'componentPostfix', 'autohookPrefix', 'ajaxHandlerPrefix', 'apiHandlerPrefix' );
		$fieldVar = '_' . lcfirst( $strProperty );

		if( in_array( $strProperty, $getAbleFieldNames ) )
			return $this->$fieldVar;

		$this->error( 'Requested property "' . $strProperty . '" does not exist or is inaccessible.' );
	}

	public function __call( $strMethodName, $arArguments ) {
		if( isset( $this->_components[ $strMethodName ] ) )
			return $this->_components[ $strMethodName ];

		$this->error( 'Requested method "' . $strMethodName . '" does not exist or is inaccessible.' );
	}

	//Register's the plugin's JS API with the AvocaPluginFramework
	public function registerJavascriptApi() {
		$data = array();

		if( !isset( $this->_components ) )
			return;

		foreach( $this->_components as $componentName => $component ) {
			$data[ $componentName ] = array(
				'className'		=> $component->className,
				'domain'		=> $component->domain,
				'ajaxHandlers'	=> $component->getAjaxHandlerKeys(),
				'security'		=> $component->getNonce()
			);
		}

		PluginFramework::registerJsApi( $this, $data );
	}

//(GET) Methods for accessing AP configuration data
	public function getDir( $strType = 'plugin' ) {
		return $this->_paths['dir'][ $strType ];
	}

	public function getUri( $strType = 'plugin' ) {
		return $this->_paths['uri'][ $strType ];
	}

//Utility methods

	//Checks to see if the provided string adheres to the conventional component naming scheme
	public function isConventionalComponentName( $strComponentName ) {
		//Not precise, but close enough. Should really be checking if the prefix is at the beginning and the postfix at the end.
		if( strpos( $strComponentName, $this->_componentPrefix ) !== FALSE 
				&& strpos( $strComponentName, $this->_componentPostfix ) !== FALSE )
			return TRUE;

		return FALSE;
	}

	//Conventionalizes the given string by wrapping it with AP::_componentPrefix and AP::_componentPostfix. Plays it safe, but won't double-wrap.
	public function conventionalizeComponentName( $strComponentName ) {
		return $this->isConventionalComponentName( $strComponentName ) ? $strComponentName : $this->_componentPrefix . $strComponentName . $this->_componentPostfix;
	}

	//Strips AP conventions from a string (i.e. de-conventionalizes the passed component name). Use AvocaPluginComponentChildClass::getName() to retrieve the REAL, configured name of a component
	public function deconventionalizeComponentName( $strComponentName ) {
		return str_replace( array( $this->_componentPrefix, $this->_componentPostfix ), '', $strComponentName );
	}

	//Given a string, returns a less-than-8-character string suitable for a LOCAL component domain relative to the plugin's domain.
	//If the component already exists and is loaded, return its configured domain instead. Domain names should not contain
	//any non-alphanumeric characters.
	public function componentDomain( $strComponentName ) {
		$strComponentName = $this->deconventionalizeComponentName( $strComponentName );		//Strip AP component naming conventions from the name

		//If the component is already loaded, return its domain - no sense in calculating a component domain that isn't actually used anywhere.
		if( isset( $this->_components[ $strComponentName ] ) )
			return $this->_components[ $strComponentName ]->getDomain();

		//Otherwise, generate an uppercase-letter-based abbreviation and return that
		return $this->abbreviate( $strComponentName, $this->_componentDomainLength );
	}

	//Calculates a super-basic alphanumeric abbreviation for a string based on capitalization and length. If no length is specified, defaults to componentDomainLength property
	public function abbreviate( $strString, $intLength = NULL ) {
		//Set up the string for processing
		$strString 		= preg_replace("/[^A-Za-z0-9]/", '', $strString);		//Remove non-alphanumeric characters
		$strString 		= ucfirst( $strString );								//Capitalize the first character (helps with abbreviation calcs)
		$stringIndex 	= 0;

		//Figure out everything we need to know about the resulting abbreviation string
		$uppercaseCount 	= preg_match_all('/[A-Z]/', $strString, $uppercaseLetters, PREG_OFFSET_CAPTURE);	//Record occurences of uppercase letters and their indecies in the $uppercaseLetters array, take note of how many there are
		$targetLength 		= isset( $intLength ) ? intval( $intLength ) : $this->_componentDomainLength;			//Maximum length of the abbreviation
		$uppercaseCount 	= $uppercaseCount > $targetLength ? $targetLength : $uppercaseCount; 				//If there are more uppercase letters than the target length, adjust uppercaseCount to ignore overflow
		$targetWordLength 	= round( $targetLength / intval( $uppercaseCount ) );								//How many characters need to be taken from each uppercase-designated "word" in order to best meet the target length?
		$abbrevLength 		= 0;		//How long the abbreviation currently is
		$abbreviation 		= '';		//The actual abbreviation

		//Create respective arrays for the occurence indecies and the actual characters of uppercase characters within the string
		for($i = 0; $i < $uppercaseCount; $i++) {
			//$ucIndicies[] = $uppercaseLetters[1];  //Not actually used. Could be used to calculate abbreviations more efficiently than the routine below by strictly considering indecies
			$ucLetters[] = $uppercaseLetters[0][$i][0];
		}

		$characterDeficit = 0;	//Gets incremented when an uppercase letter is encountered before $targetCharsPerWord characters have been collected since the last UC char.
		$wordIndex = $targetWordLength;			//HACK: keeps track of how many characters have been carried into the abbreviation since the last UC char
		//echo('Abbreviate ' . $strString .': <br />' );
		//echo('Uppercase Count: ' . $uppercaseCount . '   Target length: ' . $targetLength . '   Target Word Length: ' . $targetWordLength . '<br />');

		while( $stringIndex < strlen( $strString ) ) {	//Process the whole input string...
			/*if( $stringIndex !== 0 )
				echo('Abbreviation Chars[ ' . $abbrevLength . ' / ' . $targetLength . ' ]  Word Chars[ ' . $wordIndex . ' (-' . $characterDeficit . ') / ' . $targetWordLength . ' ]   Last Char (\'' . $currentChar . '\') :: ' . $abbreviation . '<br />' );
			*/

			if( $abbrevLength >= $targetLength ) 		//...unless the abbreviation has hit a length cap
				break;

			$currentChar = $strString[ $stringIndex++ ];	//Grab a character from the string, advance the string cursor

			if( in_array( $currentChar, $ucLetters ) ) { 	//UC char, new word
				$characterDeficit += $targetWordLength - $wordIndex;	//If UC chars are closer together than targetWordLength, keeps track of how many extra characters are required to fit the requested length
				$wordIndex = 0;											//Set the wordIndex to reflect a new word
			} else if( $wordIndex >= $targetWordLength ) {
				if( $characterDeficit == 0 )
					continue;	//If the word is full and we're not short any characters, ignore the character
				else
					$characterDefecit--;	//If we are short some characters, decrement the defecit and carry on with adding the character to the abbreviation
			}

			$abbreviation .= $currentChar;	//Add the character to the abbreviation
			$abbrevLength++;				//Increment abbreviation length
			$wordIndex++;					//Increment the number of characters for this word
		}

		return $abbreviation;
	}

	//Executes the given action in a local context (i.e. namespaced)
	public function doPluginAction( $strActionName ) {
		$arguments = func_get_args();
		$arguments[0] = $this->namespaceKey( $strActionName );

		do_action( 'debug_do_plugin_action', $arguments );

		return call_user_func_array( 'do_action', $arguments );
	}

	//Binds a global callback to a local (namespaced) action
	public function addPluginAction( $strActionName, $callback ) {
		return add_action( $this->namespaceKey( $strActionName ), $callback );
	}

	//Executes a the given action in a global context (i.e. withot namespacing anything)
	public function doAction( $strActionName ) {
		$arguments = func_get_args();

		return call_user_func_array( 'do_action', $arguments );
	}

	//Binds a local callback to a global action
	public function addAction( $strActionName, $strMethodName ) {
		return add_action( $strActionName, array( &$this, $strMethodName ) );
	}

	//Namespaces the given string by prepending it with the plugin's domain and a delimiter
	public function namespaceString( $strString, $strNamespaceDelimiter = '-' ) {
		return $this->_domain . $strNamespaceDelimiter . $strString;
	}

	//Returns the passed handle namespaced to this plugin for use in script/style handles
	public function namespaceHandle( $strHandle ) {
		return strtolower( $this->namespaceString( $strHandle, '-' ) );
	}

	//Returns the passed key namespaced to this plugin for use in action names, option keys
	public function namespaceKey( $strKey ) {
		return strtolower( $this->namespaceString( $strKey, '_' ) );
	}

//Namespaced Error Handling and Debugging utility methods

	//Throws down namespaced debug messages
	public function debug( $strMessage, $arTags = array(), $strComponentDomain = NULL ) {
		if( $this->_debugMode === TRUE ) {
			$subdomainString = isset( $strComponentDomain ) ? '::' . $strComponentDomain : '';

			$arTags[] = $this->_domain;
			$arTags[] = 'debug';

			AvocaPluginDebugger::debug( '( ' . $this->_domain . $subdomainString . ' ): ' . $strMessage, $arTags );
		}

		//Do nothing if not debugging. Should be made to dump to file if WP debugging if off but we're debugging anyway
	}

	//Throws down namespaced "notice"s
	public function notice( $strMessage, $arTags = NULL, $strComponentDomain = NULL ) {
		if( $this->_debugMode === TRUE ) {
			$subdomainString = isset( $strComponentDomain ) ? ' :: ' . $strComponentDomain : '';

			$arTags[] = 'notice';
			$arTags[] = $this->domain;

			AvocaPluginDebugger::debug( '[ ' . $this->_domain . $subdomainString . ' ] Notice: ' . $strMessage, implode( ', ', $arTags ) );
		}

		//Do nothing if not debugging. Should be made to dump to file if WP debugging if off but we're debugging anyway
	}

	//Throws down "warning" level debug messages namespaced by using the AP domain as a tag
	public function warning( $strMessage, $arTags = NULL, $strComponentDomain = NULL ) {
		if( $this->_debugMode === TRUE ) {
			$subdomainString = isset( $strComponentDomain ) ? ' :: ' . $strComponentDomain : '';

			$arTags[] = 'warning';
			$arTags[] = $this->_domain;

			AvocaPluginDebugger::debug( '[ ' . $this->_domain . $subdomainString . ' ] Notice: ' . $strMessage, implode( ', ', $arTags ) );
		}
	}

	//Throws down namespaced "error" level messages
	public function error( $strMessage, $arTags = NULL, $strComponentDomain = NULL ) {
		$subdomainString = isset( $strComponentDomain ) ? ' :: ' . $strComponentDomain : '';

		if( $this->_debugMode === TRUE ) {
			$arTags[] = 'error';
			$arTags[] = $this->_domain;
			AvocaPluginDebugger::debug( '[ ' . $this->_domain . $subdomainString . ' ] Error: ' . $strMessage, implode( ', ', $arTags ) );
		} else {
			//If we're not debugging, die. There should be no unhandled errors in an AWE production distro.
			die( 'Unhandled Error (enable WP_DEBUG or ' . $this->_name . '::FORCE_DEBUG for more information): ' . $strMessage );
		}
	}

	//Throws down namespaced "fatal" level messages and terminates execution
	public function fatal( $strMessage, $arTags = NULL, $strComponentDomain = NULL ) {
		$subdomainString = isset( $strComponentDomain ) ? ' :: ' . $strComponentDomain : '';

		if( $this->_debugMode ) {
			$arTags[] = 'fatal';
			$arTags[] = $this->_domain;
			AvocaPluginDebugger::debug( '[ ' . $this->_domain . $subdomainString . ' ] Fatal Error: ' . $strMessage, implode( ', ', $arTags ) );
		} else {
			//If we're not debugging, die. It's a FATAL error, don't act so surprised.
			die( 'Fatal Error (enable WP_DEBUG or ' . $this->_name . '::FORCE_DEBUG for more information): ' . $strMessage );
		}
	}

//Initialization and set-up routines

	//Establishes debugging through the PhpConsole class/chrome extension. If debugging is disabled, warnings will be ignored
	//but errors will still terminate execution.
	private function _initializeDebugging( $strDebugRoot, $boolDebugMode = FALSE ) {
		$this->_debugMode = ( $boolDebugMode === TRUE || self::FORCE_DEBUG ) ? TRUE : FALSE;

		if( $this->_debugMode ) {
			require_once( PluginFramework::getDir('includes') . '/AvocaPluginDebugger.php' );

			AvocaPluginDebugger::start();

			$this->doPluginAction('debug_init');

			/* Broken PHPConsole shit. setcookie() fails because Wordpress already dispatched the header...
			if( !class_exists( 'PhpConsole' ) )
				require_once( self::getDir('libs') . '/PhpConsole.php' );

			PhpConsole::start(TRUE, TRUE, $strDebugRoot);	//Start up PhpConsole. Will be ignored if already instantiated.
			debug('WHAT THE FUCKKKKK', 'someTag');
			//self::debug( 'AvocaPlugin Debugging initialized' );*/
		}
	}

	//Establishes a runtime configuration for the AP singleton by unpacking the data returned by the child class's
	//public static AvocaPluginConfiguration method into class fields. Can deduce a conventional configuration scheme
	//given nothing but a plugin file
	private function _initializeConfiguration( $strPluginFile, $arInitialConfiguration = NULL ) {
		//NOTE: AvocaPlugin debugging is not available at this point
		if( !isset( $arInitialConfiguration ) || empty( $arInitialConfiguration ) )
			die('Empty configuration supplied');

		$this->_pluginClass = get_called_class();
		$this->_pluginFile	= $strPluginFile;
		$this->_name 		= isset( $arInitialConfiguration['name'] ) ? $arInitialConfiguration['name'] : str_replace( array( dirname( $this->_pluginFile ), '.php' ) , '', $this->_pluginFile );
		$this->_slug		= isset( $arInitialConfiguration['slug'] ) ? sanitize_title( $arInitialConfiguration['slug'] ) : sanitize_title( $this->_name );
		$this->_domain		= isset( $arInitialConfiguration['domain'] ) ? sanitize_key( $arInitialConfiguration['domain'] ) : $this->abbreviate( $this->_name );

		//TODO: add configuration directives for conventions, paths, etc
	}

	//Initializes a filepath and uri array for quick reference
	private function _initializePaths( $strPluginFile ) {
		if( !file_exists( $strPluginFile ) )
			$this->fatal( $strPluginFile . ' does not exist.' );

		$pluginRootDir = plugin_dir_path( $strPluginFile );
		$pluginRootURI = plugin_dir_url( $strPluginFile );

		$this->_paths = array(
				'dir' => array(
						'plugin'		=> $pluginRootDir,
						'components'	=> $pluginRootDir . $this->_componentDirName,
						'includes'		=> $pluginRootDir . $this->_includeDirName,
						'libs'			=> $pluginRootDir . $this->_libraryDirName
					),
				'uri' => array(
						'plugin'		=> $pluginRootURI,
						'components'	=> $pluginRootURI . '/' . $this->_componentDirName,
						'includes'		=> $pluginRootURI . '/' . $this->_includeDirName,
						'libs'			=> $pluginRootURI . '/' . $this->_libraryDirName
					)
			);
	}

	//Loads conventionally formatted components from the components directory
	public function registerConventionalComponents() {
		$handle = opendir( $this->getDir('components') );

		$this->doPluginAction( 'register_components' );

		while( $entry = readdir( $handle ) ) {
			if( is_dir( $this->getDir('components') . '/' . $entry ) && $this->isConventionalComponentName( $entry ) ) {
				$this->_registerComponent( $entry, $this->getDir('components') . '/' . $entry . '/' . $entry . '.php'  );
			}
		}
	}

	//Attempts to load the APC-extending class $strComponentClass from $strComponentFile, instantiating it into the AP::$_components array
	private function _registerComponent( $strComponentClass, $strComponentFile ) {
		$strComponentHandle = $this->deconventionalizeComponentName( $strComponentClass );

		$this->doPluginAction( 'load_component', $strComponentHandle, $strComponentClass, $strComponentFile );

		if( isset( $this->_components[ $strComponentHandle ] ) !== FALSE )
			$this->warning( 'Could not register component ' . $strComponentHandle . ' to class ' . $strComponentClass . ' in file ( ' . $strComponentFile . ' ): The component is already registered to class ' . $this->_components[ $strComponentHandle ]->getClassName() . ' in (' . $this->_components[ $strComponentHandle ]->getDirectory() . ').' );

		if( !file_exists( $strComponentFile ) )
			$this->fatal( 'Could not load component ' . $strComponentHandle . ': no such file (' . $strComponentFile . ').' );

		require_once( $strComponentFile );
		
		if( !class_exists( $strComponentClass ) )
			$this->fatal( 'Could not load component "' . $strComponentHandle . '": no such class "' . $strComponentClass . '" in loaded file (' . $strComponentFile . ').' );

		if( !is_subclass_of( $strComponentClass, __NAMESPACE__ . '\\PluginComponent' ) )
			$this->fatal( 'Component class "' . $strCompnentClass . '" must extend AvocaPluginComponent.' );

		//Load the component
		$this->_components[ $strComponentHandle ]	= new $strComponentClass( $this->instance() );

		$this->doPluginAction( 'component_loaded', $strComponentHandle, $strComponentClass, $strComponentFile );
	}
}
?>