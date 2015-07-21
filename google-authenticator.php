<?php
/*
Plugin Name: Token2 Hardware Tokens
Plugin URI: https://token2.com/
Description: Two-Factor Authentication for WordPress using Token2 Hardware tokens. Tokens can be purchased online via <a href=https://token2.com/?content=hwtokens>https://token2.com/?content=hwtokens</a>
Author: Token2
Version: 0.1
Author URI: https://token2.com/
Compatibility: WordPress 3.8
Text Domain: token2-hwtokens
Domain Path: /lang

----------------------------------------------------------------------------

	Thanks to Bryan Ruiz for his Base32 encode/decode class, found at php.net.
	Thanks to Tobias Bäthge for his major code rewrite and German translation.
	Thanks to Pascal de Bruijn for his relaxed mode idea.
	Thanks to Daniel Werl for his usability tips.
	Thanks to Dion Hulse for his bugfixes.
	Thanks to Aldo Latino for his Italian translation.
	Thanks to Kaijia Feng for his Simplified Chinese translation.
	Thanks to Ian Dunn for fixing some depricated function calls.
	Thanks to Kimmo Suominen for fixing the iPhone description issue.
	Thanks to Alex Concha for some security tips.
	Thanks to Sébastien Prunier for his Spanish and French translations.

----------------------------------------------------------------------------

    Copyright 2015 Token2

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Token2HWTokens {

static $instance; // to store a reference to the plugin, allows other plugins to remove actions

/**
 * Constructor, entry point of the plugin
 */
function __construct() {
    self::$instance = $this;
    add_action( 'init', array( $this, 'init' ) );
}

/**
 * Initialization, Hooks, and localization
 */
function init() {
    require_once( 'base32.php' );
    
    add_action( 'login_form', array( $this, 'loginform' ) );
    add_action( 'login_footer', array( $this, 'loginfooter' ) );
    add_filter( 'authenticate', array( $this, 'check_otp' ), 50, 3 );

    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        add_action( 'wp_ajax_Token2HWTokens_action', array( $this, 'ajax_callback' ) );
    }

    add_action( 'personal_options_update', array( $this, 'personal_options_update' ) );
    add_action( 'profile_personal_options', array( $this, 'profile_personal_options' ) );
    add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ) );
    add_action( 'edit_user_profile_update', array( $this, 'edit_user_profile_update' ) );

	add_action('admin_enqueue_scripts', array($this, 'add_qrcode_script'));

    load_plugin_textdomain( 'token2-hwtokens', false, basename( dirname( __FILE__ ) ) . '/lang' );
}


/**
 * Check the verification code entered by the user.
 */
function verify( $secretkey, $thistry, $relaxedmode, $lasttimeslot ) {

	// Did the user enter 6 digits ?
	if ( strlen( $thistry ) != 6) {
		return false;
	} else {
		$thistry = intval ( $thistry );
	}

	// If user is running in relaxed mode, we allow more time drifting
	// ±4 min, as opposed to ± 30 seconds in normal mode.
	if ( $relaxedmode == 'enabled' ) {
		$firstcount = -8;
		$lastcount  =  8; 
	} else {
		$firstcount = -1;
		$lastcount  =  1; 	
	}
	
	$tm = floor( time() / 30 );
	
	$secretkey=Base32::decode($secretkey);
	// Keys from 30 seconds before and after are valid aswell.
	for ($i=$firstcount; $i<=$lastcount; $i++) {
		// Pack time into binary string
		$time=chr(0).chr(0).chr(0).chr(0).pack('N*',$tm+$i);
		// Hash it with users secret key
		$hm = hash_hmac( 'SHA1', $time, $secretkey, true );
		// Use last nipple of result as index/offset
		$offset = ord(substr($hm,-1)) & 0x0F;
		// grab 4 bytes of the result
		$hashpart=substr($hm,$offset,4);
		// Unpak binary value
		$value=unpack("N",$hashpart);
		$value=$value[1];
		// Only 32 bits
		$value = $value & 0x7FFFFFFF;
		$value = $value % 1000000;
		if ( $value === $thistry ) {
			// Check for replay (Man-in-the-middle) attack.
			// Since this is not Star Trek, time can only move forward,
			// meaning current login attempt has to be in the future compared to
			// last successful login.
			if ( $lasttimeslot >= ($tm+$i) ) {
				error_log("Token2 Hardware Tokens plugin: Man-in-the-middle attack detected (Could also be 2 legit login attempts within the same 30 second period)");
				return false;
			}
			// Return timeslot in which login happened.
			return $tm+$i;
		}
	}
	return false;
}

/**
 * Create a new random secret for the Token2 Hardware Tokens app.
 * 16 characters, randomly chosen from the allowed Base32 characters
 * equals 10 bytes = 80 bits, as 256^10 = 32^16 = 2^80
 */ 
function create_secret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // allowed characters in Base32
    $secret = '';
    for ( $i = 0; $i < 16; $i++ ) {
        $secret .= substr( $chars, wp_rand( 0, strlen( $chars ) - 1 ), 1 );
    }
    return $secret;
}

/**
 * Add the script to generate QR codes.
 */
function add_qrcode_script() {
    wp_enqueue_script('jquery');
    wp_register_script('qrcode_script', plugins_url('jquery.qrcode.min.js', __FILE__),array("jquery"));
    wp_enqueue_script('qrcode_script');
}

/**
 * Add verification code field to login form.
 */
function loginform() {
    echo "\t<p>\n";
    echo "\t\t<label title=\"".__('If you don\'t have Token2 Hardware Tokens enabled for your WordPress account, leave this field empty.','token2-hwtokens')."\">".__('Token2 Hardware Tokens code','token2-hwtokens')."<span id=\"google-auth-info\"></span><br />\n";
    echo "\t\t<input type=\"text\" name=\"googleotp\" id=\"user_email\" class=\"input\" value=\"\" size=\"20\" style=\"ime-mode: inactive;\" /></label>\n";
    echo "\t</p>\n";
}

/**
 * Disable autocomplete on Token2 Hardware Tokens code input field.
 */
function loginfooter() {
    echo "\n<script type=\"text/javascript\">\n";
    echo "\ttry{\n";
    echo "\t\tdocument.getElementById('user_email').setAttribute('autocomplete','off');\n";
    echo "\t} catch(e){}\n";
    echo "</script>\n";
}

/**
 * Login form handling.
 * Check Token2 Hardware Tokens verification code, if user has been setup to do so.
 * @param wordpressuser
 * @return user/loginstatus
 */
function check_otp( $user, $username = '', $password = '' ) {
	// Store result of loginprocess, so far.
	$userstate = $user;

	// Get information on user, we need this in case an app password has been enabled,
	// since the $user var only contain an error at this point in the login flow.
	$user = get_user_by( 'login', $username );

	// Does the user have the Token2 Hardware Tokens enabled ?
	if ( isset( $user->ID ) && trim(get_user_option( 'Token2HWTokens_enabled', $user->ID ) ) == 'enabled' ) {

		// Get the users secret
		$TK2HW_secret = trim( get_user_option( 'Token2HWTokens_secret', $user->ID ) );
		
		// Figure out if user is using relaxed mode ?
		$TK2HW_relaxedmode = trim( get_user_option( 'Token2HWTokens_relaxedmode', $user->ID ) );
		
		// Get the verification code entered by the user trying to login
		if ( !empty( $_POST['googleotp'] )) { // Prevent PHP notices when using app password login
			$otp = trim( $_POST[ 'googleotp' ] );
		} else {
			$otp = '';
		}
		// When was the last successful login performed ?
		$lasttimeslot = trim( get_user_option( 'Token2HWTokens_lasttimeslot', $user->ID ) );
		// Valid code ?
		if ( $timeslot = $this->verify( $TK2HW_secret, $otp, $TK2HW_relaxedmode, $lasttimeslot ) ) {
			// Store the timeslot in which login was successful.
			update_user_option( $user->ID, 'Token2HWTokens_lasttimeslot', $timeslot, true );
			return $userstate;
		} else {
			// No, lets see if an app password is enabled, and this is an XMLRPC / APP login ?
			if ( trim( get_user_option( 'Token2HWTokens_pwdenabled', $user->ID ) ) == 'enabled' && ( defined('XMLRPC_REQUEST') || defined('APP_REQUEST') ) ) {
				$TK2HW_passwords 	= json_decode(  get_user_option( 'Token2HWTokens_passwords', $user->ID ) );
				$passwordhash	= trim($TK2HW_passwords->{'password'} );
				$usersha1		= sha1( strtoupper( str_replace( ' ', '', $password ) ) );
				if ( $passwordhash == $usersha1 ) { // ToDo: Remove after some time when users have migrated to new format
					return new WP_User( $user->ID );
				  // Try the new version based on thee wp_hash_password	function
				} elseif (wp_check_password( strtoupper( str_replace( ' ', '', $password ) ), $passwordhash)) {
					return new WP_User( $user->ID );
				} else {
					// Wrong XMLRPC/APP password !
					return new WP_Error( 'invalid_token2_hwtoken_password', __( '<strong>ERROR</strong>: The Token2 Hardware Tokens password is incorrect.', 'token2-hwtokens' ) );
				} 		 
			} else {
				return new WP_Error( 'invalid_token2_hwtoken_token', __( '<strong>ERROR</strong>: The Token2 Hardware Tokens code is incorrect or has expired.', 'token2-hwtokens' ) );
			}	
		}
	}
	// Token2 Hardware Tokens isn't enabled for this account,
	// just resume normal authentication.
	return $userstate;
}


/**
 * Extend personal profile page with Token2 Hardware Tokens settings.
 */
function profile_personal_options() {
	global $user_id, $is_profile_page;

	// If editing of Token2 Hardware Tokens settings has been disabled, just return
	$TK2HW_hidefromuser = 'disabled';
	if ( $TK2HW_hidefromuser == 'enabled') return;
	
	$TK2HW_secret			= trim( get_user_option( 'Token2HWTokens_secret', $user_id ) );
	$TK2HW_enabled			= trim( get_user_option( 'Token2HWTokens_enabled', $user_id ) );
	$TK2HW_relaxedmode		= trim( get_user_option( 'Token2HWTokens_relaxedmode', $user_id ) );
	$TK2HW_description		= trim( get_user_option( 'Token2HWTokens_description', $user_id ) );
	$TK2HW_pwdenabled		= trim( get_user_option( 'Token2HWTokens_pwdenabled', $user_id ) );
	$TK2HW_password		= trim( get_user_option( 'Token2HWTokens_passwords', $user_id ) );
	
	// We dont store the generated app password in cleartext so there is no point in trying
	// to show the user anything except from the fact that a password exists.
	if ( $TK2HW_password != '' ) {
		$TK2HW_password = "XXXX XXXX XXXX XXXX";
	}

	// In case the user has no secret ready (new install), we create one.
	if ( '' == $TK2HW_secret ) {
		$TK2HW_secret = $this->create_secret();
	}
	
	// Use "WordPress Blog" as default description
	if ( '' == $TK2HW_description ) {
		$TK2HW_description = __( 'WordPressBlog', 'token2-hwtokens' );
	}
	
	echo "<h3>".__( 'Token2 Hardware Tokens Settings', 'token2-hwtokens' )."</h3>\n";

	echo "<table class=\"form-table\">\n";
	echo "<tbody>\n";
	echo "<tr>\n";
	echo "<th scope=\"row\">".__( 'Active', 'token2-hwtokens' )."</th>\n";
	echo "<td>\n";
	echo "<input name=\"TK2HW_enabled\" id=\"TK2HW_enabled\" class=\"tog\" type=\"checkbox\"" . checked( $TK2HW_enabled, 'enabled', false ) . "/>\n";
	echo "</td>\n";
	echo "</tr>\n";

	if ( $is_profile_page || IS_PROFILE_PAGE ) {
		echo "<tr>\n";
		echo "<th scope=\"row\">".__( 'Relaxed mode', 'token2-hwtokens' )."</th>\n";
		echo "<td>\n";
		echo "<input name=\"TK2HW_relaxedmode\" id=\"TK2HW_relaxedmode\" class=\"tog\" type=\"checkbox\"" . checked( $TK2HW_relaxedmode, 'enabled', false ) . "/><span class=\"description\">".__(' Relaxed mode allows for more time drifting on your phone clock (&#177;4 min).','token2-hwtokens')."</span>\n";
		echo "</td>\n";
		echo "</tr>\n";
		
		 

		echo "<tr>\n";
		echo "<th><label for=\"TK2HW_secret\">".__('Secret','token2-hwtokens')."</label></th>\n";
		echo "<td>\n";
		echo "<input name=\"TK2HW_secret\" id=\"TK2HW_secret\"   value=\"{$TK2HW_secret}\"     type=\"text\" size=\"64\" />";
		//echo "<input name=\"TK2HW_newsecret\" id=\"TK2HW_newsecret\" value=\"".__("Create new secret",'token2-hwtokens')."\"   type=\"button\" class=\"button\" />";
		echo "<input name=\"show_qr\" id=\"show_qr\" value=\"".__("Show/Hide QR code",'token2-hwtokens')."\"   type=\"button\" class=\"button\" onclick=\"ShowOrHideQRCode();\" />";
		echo "</td>\n";
		echo "</tr>\n";

		echo "<tr>\n";
		echo "<th></th>\n";
		echo "<td><div id=\"TK2HW_QR_INFO\" style=\"display: none\" >";
		echo "<div id=\"TK2HW_QRCODE\"/></div>";

		echo '<span class="description"><br/> ' . __( 'Scan this with the Token2 Hardware Tokens app.', 'token2-hwtokens' ) . '</span>';
		echo "</div></td>\n";
		echo "</tr>\n";

		 
	}

	echo "</tbody></table>\n";
	echo "<script type=\"text/javascript\">\n";
	echo "var GAnonce='".wp_create_nonce('Token2HWTokensaction')."';\n";

  	echo <<<ENDOFJS
  	//Create new secret and display it
	jQuery('#TK2HW_newsecret').bind('click', function() {
		// Remove existing QRCode
		jQuery('#TK2HW_QRCODE').html("");
		var data=new Object();
		data['action']	= 'Token2HWTokens_action';
		data['nonce']	= GAnonce;
		jQuery.post(ajaxurl, data, function(response) {
  			jQuery('#TK2HW_secret').val(response['new-secret']);
  			var qrcode="otpauth://totp/WordPress:"+escape(jQuery('#TK2HW_description').val())+"?secret="+jQuery('#TK2HW_secret').val()+"&issuer=WordPress";
			jQuery('#TK2HW_QRCODE').qrcode(qrcode);
 			jQuery('#TK2HW_QR_INFO').show('slow');
  		});  	
	});

	// If the user starts modifying the description, hide the qrcode
	jQuery('#TK2HW_description').bind('focus blur change keyup', function() {
		// Only remove QR Code if it's visible
		if (jQuery('#TK2HW_QR_INFO').is(':visible')) {
			jQuery('#TK2HW_QR_INFO').hide('slow');
			jQuery('#TK2HW_QRCODE').html("");
  		}
	});

	// Create new app password
	jQuery('#TK2HW_createpassword').bind('click',function() {
		var data=new Object();
		data['action']	= 'Token2HWTokens_action';
		data['nonce']	= GAnonce;
		data['save']	= 1;
		jQuery.post(ajaxurl, data, function(response) {
  			jQuery('#TK2HW_password').val(response['new-secret'].match(new RegExp(".{0,4}","g")).join(' '));
  			jQuery('#TK2HW_passworddesc').show();
  		});  	
	});
	
	jQuery('#TK2HW_enabled').bind('change',function() {
		Token2HWTokens_apppasswordcontrol();
	});

	jQuery(document).ready(function() {
		jQuery('#TK2HW_passworddesc').hide();
		Token2HWTokens_apppasswordcontrol();
	});
	
	function Token2HWTokens_apppasswordcontrol() {
		if (jQuery('#TK2HW_enabled').is(':checked')) {
			jQuery('#TK2HW_pwdenabled').removeAttr('disabled');
			jQuery('#TK2HW_createpassword').removeAttr('disabled');
		} else {
			jQuery('#TK2HW_pwdenabled').removeAttr('checked')
			jQuery('#TK2HW_pwdenabled').attr('disabled', true);
			jQuery('#TK2HW_createpassword').attr('disabled', true);
		}
	}

	function ShowOrHideQRCode() {
		if (jQuery('#TK2HW_QR_INFO').is(':hidden')) {
			var qrcode="otpauth://totp/WordPress:"+escape(jQuery('#TK2HW_description').val())+"?secret="+jQuery('#TK2HW_secret').val()+"&issuer=WordPress";
			jQuery('#TK2HW_QRCODE').qrcode(qrcode);
	        jQuery('#TK2HW_QR_INFO').show('slow');
		} else {
			jQuery('#TK2HW_QR_INFO').hide('slow');
			jQuery('#TK2HW_QRCODE').html("");
		}
	}
</script>
ENDOFJS;
}

/**
 * Form handling of Token2 Hardware Tokens options added to personal profile page (user editing his own profile)
 */
function personal_options_update() {
	global $user_id;

	// If editing of Token2 Hardware Tokens settings has been disabled, just return
	$TK2HW_hidefromuser = trim( get_user_option( 'Token2HWTokens_hidefromuser', $user_id ) );
	if ( $TK2HW_hidefromuser == 'enabled') return;


	$TK2HW_enabled	= ! empty( $_POST['TK2HW_enabled'] );
	$TK2HW_description	= trim( sanitize_text_field($_POST['TK2HW_description'] ) );
	$TK2HW_relaxedmode	= ! empty( $_POST['TK2HW_relaxedmode'] );
	$TK2HW_secret	= trim( $_POST['TK2HW_secret'] );
	$TK2HW_pwdenabled	= ! empty( $_POST['TK2HW_pwdenabled'] );
	$TK2HW_password	= str_replace(' ', '', trim( $_POST['TK2HW_password'] ) );
	
	if ( ! $TK2HW_enabled ) {
		$TK2HW_enabled = 'disabled';
	} else {
		$TK2HW_enabled = 'enabled';
	}

	if ( ! $TK2HW_relaxedmode ) {
		$TK2HW_relaxedmode = 'disabled';
	} else {
		$TK2HW_relaxedmode = 'enabled';
	}


	if ( ! $TK2HW_pwdenabled ) {
		$TK2HW_pwdenabled = 'disabled';
	} else {
		$TK2HW_pwdenabled = 'enabled';
	}
	
	// Only store password if a new one has been generated.
	if (strtoupper($TK2HW_password) != 'XXXXXXXXXXXXXXXX' ) {
		// Store the password in a format that can be expanded easily later on if needed.
		$TK2HW_password = array( 'appname' => 'Default', 'password' => wp_hash_password( $TK2HW_password ) );
		update_user_option( $user_id, 'Token2HWTokens_passwords', json_encode( $TK2HW_password ), true );
	}
	
	update_user_option( $user_id, 'Token2HWTokens_enabled', $TK2HW_enabled, true );
	update_user_option( $user_id, 'Token2HWTokens_description', $TK2HW_description, true );
	update_user_option( $user_id, 'Token2HWTokens_relaxedmode', $TK2HW_relaxedmode, true );
	update_user_option( $user_id, 'Token2HWTokens_secret', $TK2HW_secret, true );
	update_user_option( $user_id, 'Token2HWTokens_pwdenabled', $TK2HW_pwdenabled, true );

}

/**
 * Extend profile page with ability to enable/disable Token2 Hardware Tokens authentication requirement.
 * Used by an administrator when editing other users.
 */
function edit_user_profile() {
	global $user_id;
	$TK2HW_enabled      = trim( get_user_option( 'Token2HWTokens_enabled', $user_id ) );
	$TK2HW_hidefromuser = trim( get_user_option( 'Token2HWTokens_hidefromuser', $user_id ) );
	
	$TK2HW_relaxedmode = trim( get_user_option( 'Token2HWTokens_relaxedmode', $user_id ) );
	
	
	echo "<h3>".__('Token2 Hardware Tokens Settings','token2-hwtokens')."</h3>\n";
	echo "<table class=\"form-table\">\n";
	echo "<tbody>\n";

	 

	echo "<tr>\n";
	echo "<th scope=\"row\">".__('Active','token2-hwtokens')."</th>\n";
	echo "<td>\n";
	echo "<div><input name=\"TK2HW_enabled\" id=\"TK2HW_enabled\"  class=\"tog\" type=\"checkbox\"" . checked( $TK2HW_enabled, 'enabled', false ) . "/>\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	
		echo "<tr>\n";
	echo "<th scope=\"row\">".__('Secret key','token2-hwtokens')."</th>\n";
	echo "<td>\n";
	echo "<div><input name=\"TK2HW_secret\" id=\"TK2HW_secret\"  size=64  class=\"tog\" type=\"text\" value=\"" . trim( get_user_option( 'Token2HWTokens_secret', $user_id ) ) . "\" />\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	
		echo "<tr>\n";
		echo "<th scope=\"row\">".__( 'Relaxed mode', 'token2-hwtokens' )."</th>\n";
		echo "<td>\n";
		echo "<input name=\"TK2HW_relaxedmode\" id=\"TK2HW_relaxedmode\" class=\"tog\" type=\"checkbox\"" . checked( $TK2HW_relaxedmode, 'enabled', false ) . "/><span class=\"description\">".__(' Relaxed mode allows for more time drifting on your phone clock (&#177;4 min).','token2-hwtokens')."</span>\n";
		echo "</td>\n";
		echo "</tr>\n";
		
		

	echo "</tbody>\n";
	echo "</table>\n";
}

/**
 * Form handling of Token2 Hardware Tokens options on edit profile page (admin user editing other user)
 */
function edit_user_profile_update() {
	global $user_id;
	
	$TK2HW_enabled	     = ! empty( $_POST['TK2HW_enabled'] );
	$TK2HW_hidefromuser = ! empty( $_POST['TK2HW_hidefromuser'] );
	$TK2HW_secret	     = ! empty( $_POST['TK2HW_secret'] );
	
	$TK2HW_relaxedmode     = ! empty( $_POST['TK2HW_relaxedmode'] );
	

	if ( ! $TK2HW_enabled ) {
		$TK2HW_enabled = 'disabled';
	} else {
		$TK2HW_enabled = 'enabled';
	}

	
	if ( ! $TK2HW_relaxedmode ) {
		$TK2HW_relaxedmode = 'disabled';
	} else {
		$TK2HW_relaxedmode = 'enabled';
	}
	
	
	if ( ! $TK2HW_hidefromuser ) {
		$TK2HW_hidefromuser = 'disabled';
	} else {
		$TK2HW_hidefromuser = 'enabled';
	}
	
	
	if ( ! $TK2HW_hidefromuser ) {
		$TK2HW_secret = '';
	} else {
		$TK2HW_secret = 'enabled';
	}
	

	update_user_option( $user_id, 'Token2HWTokens_enabled', $TK2HW_enabled, true );
	update_user_option( $user_id, 'Token2HWTokens_relaxedmode', $TK2HW_relaxedmode, true );
	update_user_option( $user_id, 'Token2HWTokens_hidefromuser', $TK2HW_hidefromuser, true );
	update_user_option( $user_id, 'Token2HWTokens_secret', trim($_POST['TK2HW_secret']), true );
	
	 
}


/**
* AJAX callback function used to generate new secret
*/
function ajax_callback() {
	global $user_id;

	// Some AJAX security.
	check_ajax_referer( 'Token2HWTokensaction', 'nonce' );
	
	// Create new secret.
	$secret = $this->create_secret();

	$result = array( 'new-secret' => $secret );
	header( 'Content-Type: application/json' );
	echo json_encode( $result );

	// die() is required to return a proper result
	die(); 
}

} // end class

$token2_hwtoken = new Token2HWTokens;
?>