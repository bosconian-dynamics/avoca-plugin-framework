//AvocaPlugin factory - creates a javascript API to interact with the plugin's server-side code
(function( jsApiConfigs ) {
	for( var pluginConfig in jsApiConfigs ) {
		var pluginClass		= pluginConfig.className;
		var pluginDomain	= pluginConfig.domain;
		var components		= pluginConfig.components;
		var securityKey		= pluginConfig.securityKey;
		var ajaxurl 		= pluginConfig.ajaxUrl;

		//Create the {PluginClass} global
		window[ pluginClass ] = {};

		for( var componentName in components ) {
			var component = components[ componentName ];

			//Create the {PluginClass}.{ComponentName} property
			window[ pluginClass ][ componentName ] = {};

			for( var ahi = 0; ahi < component.ajaxHandlers.length; ahi++ ) {
				var handlerKey = component.ajaxHandlers[ ahi ];

				//Create the actual {PluginClass}.{ComponentName}.{AjaxHandlerKey} function
				window[ pluginClass ][ componentName ][ handlerKey ] = _createAjaxCaller( pluginDomain, component.domain, handlerKey, securityKey, component.security );
			}
		}

		//Returns a Javascript function that takes an array of PHP arguments and a completion callback function as arguments
		function _createAjaxCaller( pluginDomain, componentDomain, handlerKey, securityKey, security ) {
			var action = pluginDomain.toLowerCase() + '_' + componentDomain.toLowerCase() + '_' + handlerKey;

			return function( arData, fnCallback ) {
				var requestData = arData;
				requestData['action'] = action;
				requestData[ securityKey ] = security;
				requestData['AJAX']	= true;

				jQuery.post( ajaxurl, requestData )
					.success( function( data, textStatus, requestObject ) {

						for( var i = 0; i < data.length; i++ ) {
							var response = data[ i ];

							switch( response.status ) {
								case 'success':
									fnCallback( response.data );
									break;
								case 'failure':
									console.log( 'Request failed.' );
									console.log( requestObject );
									break;
								case 'error':
									console.log( 'Request Error ' );
									break;
								case 'partial':
									fnCallback( response.data );
									break;
							}
						}
					} )
					.error( function( requestObject, textStatus, httpError ) {
						alert('ajax error');
						console.log( 'Request failed: ' + textStatus + ' ' + httpError );
					} );
			}
		}
	}

	AvocaPluginJsApiConfigurations = null;

})( AvocaPluginJsApiConfigurations );