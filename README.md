Avoca Plugin Framework (APF)
======================

The *Avoca Plugin Framework* is a Wordpress plugin class that enables the rapid creation of modularized, prototype-grade plugins by way of extending the *Avoca Plugin* class.

Reflection-Driven Action Hooks
----------------------

Hooks are monotonous. APF-driven Wordpress plugins examine their methods at instantiation in order to remove some of the more repetitive work of coding a Wordpress plugin. With APF, you can hook into Wordpress by creating a method named after the Wordpress action prefixed a (configurable) tag such as `hook_`.

Need to modify the main query by executing some code at the `pre_get_posts` hook? Create a `hook_pre_get_posts` method in your plugin and APF will take care of hooking up the wires.

Reflection-Driven AJAX Interface
----------------------

Establishing AJAX can suck. APF plugins examine their methods at instantiation to build a list of those prefixed with the (configurable) tag `ajax_`. APF then localizes all of that data into a JS factory script that replicates your plugin's server-side OOP API so you can pass data asynchronously between your plugin and client-side script without ever having to write AJAX routines.

Server-side plugin:

	class MyPlugin extends AvocaPlugin {
		function ajax_uppercase_string( $strMessage ) {
			return strtoupper( $strMessage );
		}
	}

Client-side API call:

	var message = 'Hello World!'
	var upperMessage = MyPlugin.uppercase_string( message );
	alert( upperMessage );

Just like that.

