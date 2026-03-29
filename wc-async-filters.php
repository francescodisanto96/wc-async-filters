<?php
/**
 * Plugin Name: WC Async Filters
 * Description: Aggiunge filtri asincroni basati su tassonomie allo shop WooCommerce
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Author: Francesco Di Santo
 * Text Domain: wc-async-filters
 * Domain Path: /languages
 *
 * Questo blocco commento in cima al file è speciale: WordPress lo legge
 * come se fosse un file di configurazione. Le righe "Plugin Name:",
 * "Version:", "Text Domain:" ecc. vengono interpretate dal core di
 * WordPress per mostrare le informazioni nella pagina dei plugin e
 * per caricare le traduzioni. NON è codice PHP — è metadata.
 */

/*
 * BLOCCO DI SICUREZZA
 *
 * ABSPATH è una costante definita da WordPress nel suo file di bootstrap.
 * Se qualcuno tenta di aprire questo file direttamente nel browser
 * (es. http://sito.it/wp-content/plugins/wc-async-filters/wc-async-filters.php)
 * ABSPATH non sarà definita e lo script uscirà immediatamente.
 * Questo impedisce di esporre il codice a chiamate dirette non autorizzate.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * COSTANTI DEL PLUGIN
 *
 * Definiamo tre costanti che useremo in tutto il plugin per evitare
 * di ripetere le stesse stringhe ovunque (principio DRY: Don't Repeat Yourself).
 *
 * PATH vs URL: sono due concetti diversi e spesso confusi.
 * - PATH (plugin_dir_path) = percorso nel filesystem del server
 *   Es: /var/www/html/wp-content/plugins/wc-async-filters/
 *   Serve per: include, require, file_exists, fopen...
 *
 * - URL (plugin_dir_url) = indirizzo web accessibile dal browser
 *   Es: https://sito.it/wp-content/plugins/wc-async-filters/
 *   Serve per: src degli script, href dei CSS, img src...
 */
define( 'WC_ASYNC_FILTERS_VERSION', '1.0.0' );
define( 'WC_ASYNC_FILTERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_ASYNC_FILTERS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Inizializza il plugin verificando che WooCommerce sia attivo.
 *
 * Perché plugins_loaded?
 * WordPress carica i plugin in ordine alfabetico e in momenti diversi.
 * L'hook 'plugins_loaded' si attiva DOPO che tutti i plugin attivi
 * sono stati caricati: è il primo momento sicuro per verificare
 * che WooCommerce sia presente e le sue classi siano disponibili.
 *
 * Perché class_exists('WooCommerce') invece di is_plugin_active()?
 * is_plugin_active() non è disponibile nel frontend (solo in admin).
 * class_exists() funziona ovunque e controlla direttamente se
 * WooCommerce ha caricato la sua classe principale — è più affidabile.
 *
 * @return void
 */
function wc_async_filters_init(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'WC Async Filters richiede WooCommerce attivo e installato.', 'wc-async-filters' )
				. '</p></div>';
		} );
		return;
	}

	require_once WC_ASYNC_FILTERS_PATH . 'includes/class-plugin.php';
	new \WCAsyncFilters\Plugin();
}

add_action( 'plugins_loaded', 'wc_async_filters_init' );
