<?php
/*
Plugin Name: Avoca Plugin Framework
Plugin URI: https://github.com/avocaweb/avoca-plugin-framework
Description: Serves as an extendable base enabling rapid development of modularized Wordpress plugins
Version: 0.2
Author: Adam Bosco
Author URI: http://github.com/KuroTsuto
*/

/* A singleton serving to load the framework, establish the JS api */
class AvocaPluginFramework {
	const VERSION = '0.2';						//APF Version
	const FORCE_DEBUG = FALSE;					//Force debug mode for ALL active AvocaPlugins

	private static $_instance;					//THERE CAN BE ONLY ONE
	private static $_directories;				//Array of APF directories for quick reference
	private static $_uris;						//Array of APF URIs for quick reference

	private static $_plugins;					//Array of active Avoca Plugins

	private static $_jsApiConfigs;
	private static $_jsApiFactoryScriptHandle = 'avoca-jsapifactory';

	public function __construct( $strFrameworkDirectory ) {
		self::_setupPaths( $strFrameworkDirectory );
		self::_loadFramework();
	}

	public static function instance() {
		if( isset( self::$_instance ) )
			return self::$_instance;

		return self::$_instance = new AvocaPluginFramework( __DIR__ );
	}

	public static function addPlugin( $pluginFile ) {

	}

	public static function getDir( $strDirType = 'root' ) {
		return self::$_directories[ $strDirType ];
	}

	public static function getUri( $strUriType = 'root' ) {
		return self::$_uris[ $strUriType ];
	}

	public static function registerJsApi( $pluginInstance, $componentData ) {
		$data = array(
			'name'			=> $pluginInstance->name,
			'className'		=> $pluginInstance->pluginClass,
			'domain'		=> $pluginInstance->domain,
			'securityKey'	=> $pluginInstance->securityKey,
			'ajaxUrl'		=> admin_url('admin-ajax.php'),
			'components'	=> $componentData
		);

		$configVar = $data['domain'] . 'Config';

		self::$_jsApiConfigs[ $configVar ] = $data;
	}

	public static function enqueueScripts() {
		wp_enqueue_script(
			self::$_jsApiFactoryScriptHandle,
			self::getUri( 'scripts' ) . '/js-api-factory.js',
			array( 'jquery' ),
			self::VERSION
		);

		wp_localize_script( self::$_jsApiFactoryScriptHandle, 'AvocaPluginJsApiConfigurations', self::$_jsApiConfigs );
	}

	private static function _setupPaths( $strRootDir ) {
		self::$_directories = array(
				'root'		=> $strRootDir,
				'includes'	=> $strRootDir . '/includes',
				'scripts'	=> $strRootDir . '/js',
				'styles'	=> $strRootDir . '/css'
			);

		$strRootURI = plugins_url( '', __FILE__ );

		self::$_uris = array(
				'root'		=> $strRootURI,
				'scripts'	=> $strRootURI . '/js',
				'styles'	=> $strRootURI . '/css'
			);
	}

	private static function _loadFramework() {
		$includesDir = self::getDir( 'includes' );

		require( $includesDir . '/AvocaPlugin.php' );
		require( $includesDir . '/AvocaPluginComponent.php' );
	}
}

AvocaPluginFramework::instance();

echo(AvocaPluginFramework::getDir('scripts'));