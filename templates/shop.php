<?php
/**
 * Template personalizzato per la shop page e le pagine categoria.
 *
 * Sostituisce il template WooCommerce tramite il filtro template_include
 * registrato in class-plugin.php con priorità 99.
 * Fornisce solo la struttura HTML: sidebar vuota e griglia vuota.
 * Il JavaScript (frontend.js) popola entrambe via AJAX al caricamento.
 *
 * @package WCAsyncFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

/*
 * do_action('woocommerce_before_main_content') è necessario per compatibilità
 * con il tema attivo: breadcrumb, wrapper CSS e altri elementi vengono
 * agganciati a questo hook da WooCommerce e dai temi (es. Storefront).
 */
do_action( 'woocommerce_before_main_content' );
?>

<div class="wcaf-layout">

	<aside class="wcaf-sidebar">
		<h3><?php esc_html_e( 'Filtra per', 'wc-async-filters' ); ?></h3>
		<div id="wcaf-filters-container"></div>
	</aside>

	<main class="wcaf-main">
		<div id="wcaf-products-grid"></div>

		<div id="wcaf-load-more-container">
			<!-- Nascosto all'avvio: fetchProducts() lo mostra se ci sono più pagine. -->
			<button id="wcaf-load-more" style="display:none;">
				<?php esc_html_e( 'Carica altri prodotti', 'wc-async-filters' ); ?>
			</button>
		</div>
	</main>

</div>

<?php
do_action( 'woocommerce_after_main_content' );
get_footer();
