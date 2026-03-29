<?php
/**
 * Gestisce le richieste AJAX del plugin WC Async Filters.
 *
 * WordPress distingue due tipi di richieste AJAX:
 * - wp_ajax_{action}: solo utenti autenticati (logged in)
 * - wp_ajax_nopriv_{action}: anche utenti non autenticati (visitatori)
 * Registriamo entrambi perché i filtri dello shop devono funzionare
 * per chiunque visiti il sito, non solo per gli utenti registrati.
 *
 * @package WCAsyncFilters
 */

namespace WCAsyncFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe AjaxHandler
 *
 * Espone due endpoint AJAX: il filtraggio prodotti e il recupero termini.
 * Ogni endpoint verifica il nonce, sanitizza l'input e delega la logica
 * di query a QueryBuilder prima di rispondere in JSON.
 */
class AjaxHandler {

	/**
	 * Costruttore: carica QueryBuilder e registra le azioni AJAX.
	 */
	public function __construct() {
		// Carichiamo QueryBuilder qui perché ne abbiamo bisogno in entrambi
		// i metodi handler. require_once garantisce che non venga caricata due volte.
		require_once WC_ASYNC_FILTERS_PATH . 'includes/class-query-builder.php';

		add_action( 'wp_ajax_wc_filter_products',        [ $this, 'handle_filter' ] );
		add_action( 'wp_ajax_nopriv_wc_filter_products', [ $this, 'handle_filter' ] );

		add_action( 'wp_ajax_wc_get_filter_terms',        [ $this, 'handle_get_terms' ] );
		add_action( 'wp_ajax_nopriv_wc_get_filter_terms', [ $this, 'handle_get_terms' ] );
	}

	/**
	 * Gestisce la richiesta AJAX di filtraggio prodotti.
	 *
	 * Flusso:
	 * 1. Verifica il nonce (sicurezza)
	 * 2. Legge e sanitizza i filtri da $_POST
	 * 3. Esegue la query tramite QueryBuilder
	 * 4. Genera l'HTML dei prodotti con ob_start/ob_get_clean
	 * 5. Risponde con JSON
	 *
	 * Perché ob_start() e ob_get_clean()?
	 * Le funzioni WooCommerce (woocommerce_product_loop_start, wc_get_template_part
	 * ecc.) "stampano" direttamente l'HTML invece di restituirlo come stringa.
	 * ob_start() attiva il buffer di output: tutto ciò che viene "stampato"
	 * viene catturato in memoria invece di essere inviato al browser.
	 * ob_get_clean() recupera il contenuto del buffer e lo svuota.
	 * Così possiamo includere l'HTML nella risposta JSON.
	 *
	 * Perché woocommerce_product_loop_start() solo se $page === 1?
	 * Questa funzione genera il tag <ul class="products"> che fa da contenitore
	 * per tutti i prodotti. In Load More (page > 1) il contenitore esiste già
	 * nel DOM del browser: vogliamo solo aggiungere nuovi <li> dentro di esso,
	 * non creare un secondo <ul> annidato.
	 *
	 * @return void
	 */
	public function handle_filter(): void {
		// Il nonce è un token monouso generato dal server e incluso nella
		// richiesta JS. Verificarlo garantisce che la richiesta provenga
		// dalla nostra pagina e non da un sito esterno (protezione CSRF).
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'wc_async_filters_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Richiesta non autorizzata.', 'wc-async-filters' ) ], 403 );
			return;
		}

		// L'operatore ?? restituisce il valore a sinistra se esiste, altrimenti quello a destra.
		// $_POST['filters'] ?? [] gestisce il caso in cui 'filters' non sia presente in $_POST.
		$raw_filters = $_POST['filters'] ?? [];
		$filters     = [];

		if ( is_array( $raw_filters ) ) {
			foreach ( $raw_filters as $taxonomy => $term_slug ) {
				// Scartiamo product_cat dai filtri: viene gestita come category_path.
				if ( 'product_cat' === $taxonomy ) {
					continue;
				}

				$taxonomy = sanitize_key( $taxonomy );

				// Il JavaScript rimuove il prefisso 'pa_' per URL leggibili.
				// Lo riaggiungiamo qui prima di passare i filtri a QueryBuilder,
				// che usa gli slug completi di WooCommerce (pa_brand, pa_colore...).
				// Evitiamo di aggiungere 'pa_' due volte se arrivasse già presente.
				if ( '' !== $taxonomy && strpos( $taxonomy, 'pa_' ) !== 0 ) {
					$taxonomy = 'pa_' . $taxonomy;
				}

				$filters[ $taxonomy ] = sanitize_text_field( $term_slug );
			}
		}

		$page          = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$category_path = sanitize_text_field( $_POST['category_path'] ?? '' );

		$builder = new QueryBuilder();
		$query   = $builder->build_query( $filters, $page, $category_path );

		// Avviamo il buffer di output per catturare l'HTML dei prodotti.
		ob_start();

		if ( $query->have_posts() ) {
			// A pagina 1 generiamo il contenitore <ul class="products">.
			// Dalle pagine successive (Load More) il contenitore esiste già nel DOM:
			// generiamo solo i <li> aggiuntivi che il JS inserirà dentro di esso.
			if ( 1 === $page ) {
				woocommerce_product_loop_start();
			}

			while ( $query->have_posts() ) {
				$query->the_post();
				// wc_get_template_part carica il template 'content-product.php'
				// del tema attivo (o di WooCommerce come fallback).
				wc_get_template_part( 'content', 'product' );
			}

			if ( 1 === $page ) {
				woocommerce_product_loop_end();
			}
		} else {
			echo '<p class="wcaf-no-products">' . esc_html__( 'Nessun prodotto trovato.', 'wc-async-filters' ) . '</p>';
		}

		// wp_reset_postdata() è fondamentale: ripristina la variabile globale $post
		// al valore originale della pagina. Senza di essa, il codice successivo
		// (footer, widget ecc.) userebbe l'ultimo $post del nostro loop
		// invece di quello della pagina corrente, causando bug difficili da trovare.
		wp_reset_postdata();

		$html = ob_get_clean();

		// Analizziamo i prodotti trovati per sapere quali tassonomie sono presenti.
		// Questa informazione viene usata dal JS per mostrare/nascondere i filtri.
		$present_taxonomies = $builder->get_present_taxonomies( $query );

		// wp_send_json_success() invia una risposta JSON nel formato:
		// { "success": true, "data": { ... } }
		// Chiama internamente wp_die() per terminare correttamente l'esecuzione.
		wp_send_json_success( [
			'html'               => $html,
			'taxonomies_present' => $present_taxonomies,
			'total_pages'        => (int) $query->max_num_pages,
			'current_page'       => $page,
		] );
	}

	/**
	 * Gestisce la richiesta AJAX di recupero termini di una tassonomia.
	 *
	 * Viene chiamata da Select2 (via AJAX) ogni volta che l'utente
	 * inizia a digitare nella dropdown di un filtro. Restituisce i termini
	 * corrispondenti alla ricerca nel formato che Select2 si aspetta.
	 *
	 * Perché array_values() prima di wp_send_json_success()?
	 * array_map() restituisce un array che conserva le chiavi originali.
	 * Se le chiavi non sono sequenziali (0, 1, 2...) PHP le serializza
	 * come oggetto JSON {0: ..., 1: ...} invece di array JSON [...].
	 * JavaScript si aspetta un array e chiamerebbe .map() su di esso:
	 * su un oggetto .map() non esiste → errore. array_values() re-indicizza
	 * sempre con chiavi 0, 1, 2... garantendo la serializzazione come array JSON.
	 *
	 * @return void
	 */
	public function handle_get_terms(): void {
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'wc_async_filters_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Richiesta non autorizzata.', 'wc-async-filters' ) ], 403 );
			return;
		}

		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		$search   = sanitize_text_field( $_POST['search'] ?? '' );

		if ( '' === $taxonomy ) {
			wp_send_json_error( __( 'Tassonomia non valida.', 'wc-async-filters' ) );
			return;
		}

		// taxonomy_exists() verifica che la tassonomia sia registrata in WordPress.
		// In certi contesti AJAX le tassonomie personalizzate potrebbero non essere
		// ancora registrate (dipende dall'ordine di init). Questo controllo previene
		// che get_terms() venga chiamata su una tassonomia inesistente, il che
		// bloccherebbe Select2 in stato "Searching..." indefinitamente.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_success( [] );
			return;
		}

		$term_args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => 50,
		];

		// Aggiungiamo la ricerca testuale solo se l'utente ha digitato qualcosa.
		// Select2 chiama questo endpoint anche senza testo (per mostrare le opzioni iniziali).
		if ( '' !== $search ) {
			$term_args['search'] = $search;
		}

		$terms = get_terms( $term_args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			wp_send_json_success( [] );
			return;
		}

		// static function perché il callback non ha bisogno di accedere a $this.
		// Evita il binding implicito dell'oggetto, leggermente più efficiente.
		$mapped = array_map( static function ( $term ) {
			return [
				'slug' => $term->slug,
				// Il nome va nel JSON, non nell'HTML.
				// wp_send_json_success() gestisce la codifica JSON correttamente.
				// esc_html() qui causerebbe &#039; invece di ' nelle dropdown.
				// L'escape HTML va applicato solo quando stampiamo nell'HTML.
				'name' => wp_strip_all_tags( $term->name ),
			];
		}, $terms );

		// array_values() garantisce chiavi sequenziali → serializzazione come array JSON [].
		wp_send_json_success( array_values( $mapped ) );
	}
}
