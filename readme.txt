=== Token2 Hardware Tokens ===
Contributors: token2
Donate Link: https://token2.com/
Tags: authentication,otp,password,security,login,android,iphone,blackberry
Requires at least: 3.8
Tested up to: 3.8.1
Stable tag: 0.47

Token2 Hardware Tokens for your WordPress blog.

== Description ==

The Token2 Hardware Tokens plugin for WordPress gives you two-factor authentication using the Token2 Hardware Tokens .


The two-factor authentication requirement can be enabled on a per-user basis by administrators.

== Installation ==
1. Make sure your webhost is capable of providing accurate time information for PHP/WordPress, ie. make sure a NTP daemon is running on the server.
2. Install and activate the plugin.
3. Enter a description on the Users -> Profile and Personal options page, in the Token2 Hardware Tokens section.
4. Scan the generated QR code with your phone, or enter the secret manually, remember to pick the time based one.  
You may also want to write down the secret on a piece of paper and store it in a safe place. 
5. Remember to hit the **Update profile** button at the bottom of the page before leaving the Personal options page.
6. That's it, your WordPress blog is now a little more secure.

== Frequently Asked Questions ==

= Can I use Token2 Hardware Tokens for WordPress with the Android/iPhone apps for WordPress? =

Yes, you can enable the App password feature to make that possible, but notice that the XMLRPC interface isn't protected by two-factor authentication, only a long password.

= I want to update the secret, should I just scan the new QR code after creating a new secret? =
 
= I have several users on my WordPress installation, is that a supported configuration ? =

Yes, each user has his own Token2 Hardware Tokens settings.

= During installation I forgot the thing about making sure my webhost is capable of providing accurate time information, I'm now unable to login, please help. =

If you have SSH or FTP access to your webhosting account, you can manually delete the plugin from your WordPress installation,
just delete the wp-content/plugins/token2-hwtokens directory, and you'll be able to login using username/password again.
 



== Screenshots ==

1. Token2 Hardware Tokens section on the Profile and Personal options page. 
2. The login form with second factor field.

== Changelog ==

= 0.01 =  
First version
 