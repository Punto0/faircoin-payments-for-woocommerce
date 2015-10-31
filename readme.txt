=== Faircoin Payments for WooCommerce ===
Contributors: santi punto0 Coop -- Forked of Bitcoin Payments For Woocommerce of Bitcoinway
Donate link:  
Tags: faircoin, faircoin wordpress plugin, faircoin plugin, faircoin payments, accept faircoin, faircoins
Requires at least: 3.0.1
Tested up to: 4.2
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Faircoin Payments for WooCommerce is a Wordpress plugin that allows to accept faircoins at WooCommerce-powered online stores. 
It's a fork of Bitcoin Payments for WooCommerce from Bitcoinway

== Description ==


Your online store must use WooCommerce platform (free wordpress plugin).
Once you installed and activated WooCommerce, you may install and activate Bitcoin Payments for WooCommerce.


= Benefits =

* Fully automatic operation
* 100% hack secure - by design it is impossible for hacker to steal your faircoins even if your whole server and database will be hacked.
* 100% safe against losses - no private keys are required or kept anywhere at your online store server.
* Accept payments in faircoins directly into your personal Electrum wallet.
* Electrum wallet payment option completely removes dependency on any third party service and middlemen.
* Accept payment in faircoins for physical and digital downloadable products.
* Add faircoin payments option to your existing online store with alternative main currency.
* Flexible exchange rate calculations fully managed via administrative settings.
* Zero fees and no commissions for faircoin payments processing from any third party.
* Support for many currencies.
* Set main currency of your store in any currency or faircoin.
* Automatic conversion to faircoin via realtime exchange rate feed and calculations.
* Ability to set exchange rate calculation multiplier to compensate for any possible losses due to bank conversions and funds transfer fees.
* Please donate FAI to help development here: fU2bTYrc6yCift4qgibZVQ2t9DiQPnZtbc or BTC here: 1L71XG4rEn8kvp95r4AS4ThTDz5sXL5R3g

== Installation ==

0.  Requeriments: pyfaircoin from Punt0 Coop (a fork of pycoin) and php-gmp must be installed in the system before the plugin install. 
1.  Install WooCommerce plugin and configure your store (if you haven't done so already - http://wordpress.org/plugins/woocommerce/).
2.  Install "Faircoin Payments for WooCommerce" wordpress plugin just like any other Wordpress plugin.
3.  Activate.
4.  Download and install on your computer Electrum wallet program from here: https://electrum.fair-coin.org/
5.  Run and setup your wallet.
6.  Click on "Console" tab and run this command (to extend the size of wallet's gap limit): wallet.storage.put('gap_limit',100)
7.  Grab your wallet's Master Public Key by navigating to:
	    Wallet -> Master Public Key, or (for older versions of Electrum): Preferences -> Import/Export -> Master Public Key -> Show
8.  Within your site's Wordpress admin, navigate to:
	    WooCommerce -> Settings -> Checkout -> Faircoin
	    and paste the value of Master Public Key into "Electrum wallet's Master Public Key" field.
9.  Select "Bitcoin service provider" = "Your own Electrum wallet" and fill-in other settings at Faircoin management panel.
10. Press [Save changes]
11. If you do not see any errors - your store is ready for operation and to access payments in faircoins!
12. Please donate FAI to help development here: fU2bTYrc6yCift4qgibZVQ2t9DiQPnZtbc or BTC here: 1L71XG4rEn8kvp95r4AS4ThTDz5sXL5R3g


== Screenshots ==

1. Checkout with option for bitcoin payment.
2. Order received screen, including QR code of bitcoin address and payment amount.
3. Bitcoin Gateway settings screen.


== Remove plugin ==

1. Deactivate plugin through the 'Plugins' menu in WordPress
2. Delete plugin through the 'Plugins' menu in WordPress


== Changelog ==

== 3.12 ==

* First Faircoin adapt
* Uses only electrum mpk version 2 (BIP32 compatibles).
* Required Woocommerce 2.3
* Tested up Wordpress 4.2

Old Bitcoin Payments for Bitcoin plugin changelog
= 3.12 =
* Fixed "Unable to determine exchange rate error"

= 3.10 =
* Added detailed help for managing hard cron jobs within settings. Improved interface of admin settings area.

= 3.04 =
* Fixed 'cannot determine exchange rates' error for certain rare currencies.

= 3.03 =
* Added validation of connection to BTC exchange rate services in setup screen to prevent running store with disabled internet connections or essential PHP functions disabled.
* Installation instructions updated within this file.

= 3.02 =
* Upgraded to support WooCommerce 2.1+
* Upgraded to support Wordpress 3.9
* Fixed bug in cron forcing excessive generation of new bitcoin addresses.
* Fixed bug disallowing finding of new bitcoin addresses to use for orders.
* Fixed buggy SQL query causing issues with delayed order processing even when desired number of confirmations achieved.
* Added support for many more currencies.
* Corrected bitcoin exchange rate calculation using: bitcoinaverage.com, bitcoincharts.com and bitpay.com
* MtGox APIs, services and references completely eliminated from consideration.

= 2.12 =
* Added 'bitcoins_refunded' field to order to input refunded value for tracking.

= 2.11 =
* Minor upgrade - screenshots fix.

= 2.10 =
* Added support for much faster GMP math library to generate bitcoin addresses. This improves performance of checkout 3x - 4x times.
  Special thanks to Chris Savery: https://github.com/bkkcoins/misc
* Improved compatibility with older versions of PHP now allowing to use plugin in wider range of hosting services.

= 2.04 =
* Improved upgradeability from older versions.

= 2.02 =
* Added full support for Electrum Wallet's Master Public Key - the math algorithms allowing for the most reliable, anonymous and secure way to accept online payments in bitcoins.
* Improved overall speed and responsiveness due to multilevel caching logic.

= 1.28 =
* Added QR code image to Bitcoin checkout screen and email.
  Credits: WebDesZ: http://wordpress.org/support/profile/webdesz

= 1.27 =
* Fixed: very slow loading due to MtGox exchange rate API issues.

= 1.26 =
* Fixed PHP warnings for repeated 'define's within bwwc-include-all.php

= 1.25 =
* Implemented security check (secret_key validation logic) to prevent spoofed IPN requests.

= 1.24 =
* Fixed IPN callback notification invocation specific to WC 2.x

= 1.23 =
* Fixed incoming IP check logic for IPN (payment notification) requests.

= 1.22 =
* Fixed inability to save settings bug.
* Added compatibility with both WooCommmerce 1.x and 2.x

== Upgrade Notice ==

soon

== Frequently Asked Questions ==

soon
