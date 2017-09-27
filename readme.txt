=== Faircoin Payments for WooCommerce ===
Contributors: santi, gesman, bitcoinway.com
Donate link: http://www.punto0.org/donate/
Tags: faircoin, faircoin wordpress plugin, faircoin plugin, faircoin payments, accept faircoin, faircoins, altcoin, altcoins
Requires at least: 3.0.1
Tested up to: 4.8.1
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Faircoin Payments for WooCommerce is a Wordpress plugin that allows to accept faircoins at WooCommerce-powered online stores.
It's forkes from Bitcoin Payments for WooCommerce plugin, http://bitcoinway.com

== Description ==

Your online store must use WooCommerce platform (free wordpress plugin).
Once you installed and activated WooCommerce, you may install and activate Faircoin Payments for WooCommerce.

= Benefits =

* Fully automatic operation.
* Full automatic support for Electrum 1.x and 2.x Master Public Keys (MPK). Use any MPK and plugin recognizes it automatically.
* 100% hacker safe - by design of MPK it is impossible for hacker to steal your faircoins even if your whole server and database is compromised and hacked.
* 100% safe against losses - no private keys are required or kept anywhere at your online store server.
* Accept payments in faircoins directly into your personal Electrum wallet.
* Electrum wallet payment option completely removes dependency on any third party service and middlemen.
* Accept payment in faircoins for physical and digital downloadable products.
* Add faircoin payments option to your existing online store with alternative main currency.
* Flexible exchange rate calculations fully managed via administrative settings.
* Zero fees and no commissions for faircoin payments processing from any third party.
* Support for many currencies.
* Set main currency of your store in any currency or faircoin.
* Ability to set exchange rate calculation multiplier to compensate for any possible losses due to bank conversions and funds transfer fees.
* Please donate BTC to help development here: 1CjY2nkvBNsE9pQMerzzgdrAFyyGnXVnaa or FAIR to: fYhabpU9ZGWS9EazhwLUHmWFTaEob1fiFH


== Installation ==


1.  Install WooCommerce plugin and configure your store (if you haven't done so already - http://wordpress.org/plugins/woocommerce/).
2.  Install "Faircoin Payments for WooCommerce" wordpress plugin just like any other Wordpress plugin.
3.  Activate.
4.  Download and install on your computer Electrum wallet program from here: https://download.faircoin.world/
5.  Run and setup your wallet.
6.  Click on "Console" tab and run this command (to extend the size of wallet's gap limit): wallet.storage.put('gap_limit',100)
7.  Grab your wallet's Master Public Key by navigating to:
	    Wallet -> Master Public Key, or (for older versions of Electrum): Preferences -> Import/Export -> Master Public Key -> Show
8.  Within your site's Wordpress admin, navigate to:
	    WooCommerce -> Settings -> Checkout -> Faircoin
	    and paste the value of Master Public Key into "Electrum wallet's Master Public Key" field.
9.  Select "Faircoin service provider" = "Your own Electrum wallet" and fill-in other settings at Faircoin management panel.
10. Press [Save changes]
11. If you do not see any errors - your store is ready for operation and to access payments in faircoins!
12. Please donate BTC to: 1CjY2nkvBNsE9pQMerzzgdrAFyyGnXVnaa or FAIR to: fYhabpU9ZGWS9EazhwLUHmWFTaEob1fiFH 


== Screenshots ==

1. Checkout with option for faircoin payment.
2. Order received screen, including QR code of faircoin address and payment amount.
3. Faircoin Gateway settings screen.


== Remove plugin ==

1. Deactivate plugin through the 'Plugins' menu in WordPress
2. Delete plugin through the 'Plugins' menu in WordPress


== Changelog ==

= 0.01 =

* First version forked from Bitcoin Payments Gateway for Woocommerce v4.11

== Frequently Asked Questions ==

soon
