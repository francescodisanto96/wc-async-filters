<?php
/**
 * Coordinatore principale del plugin WC Async Filters.
 *
 * Questa classe funziona come un "direttore d'orchestra": non fa direttamente
 * il lavoro (quello spetta ad Admin, AjaxHandler, QueryBuilder) ma coordina
 * tutto. Carica le dipendenze, registra gli hook WordPress e si occupa degli
 * asset frontend e del template della shop page.
 *
 * @package WCAsyncFilters
 */

namespace WCAsyncFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe Plugin
 *
 * Punto di ingresso centrale del plugin. Istanzia i sottosistemi (Admin,
 * AjaxHandler) e collega i propri metodi agli hook di WordPress.
 */
class Plugin {

	/**
	 * Costruttore: carica le dipendenze e registra gli hook WordPress.
	 *
	 * Perché add_action con [$this, 'metodo'] invece di una funzione globale?
	 * Perché il metodo appartiene a questa classe e ha bisogno di accedere
	 * a $this (ai propri altri metodi e proprietà). Una funzione globale
	 * non avrebbe quel contesto. La sintassi [$this, 'nome'] è il modo
	 * PHP per passare un metodo di un oggetto come callback.
	 *
	 * Differenza tra add_action e add_filter:
	 * - add_action: WordPress chiama il nostro codice in un determinato momento
	 *   (es. quando è ora di caricare gli script) senza aspettarsi un valore di ritorno.
	 * - add_filter: WordPress ci passa un valore, noi lo modifichiamo e lo restituiamo.
	 *   (es. il percorso del template — noi possiamo cambiarlo).
	 */
	public function __construct() {
		// require_once include il file una sola volta anche se chiamato più volte.
		// Previene l'errore "Cannot redeclare class Admin" se il file
		// venisse incluso accidentalmente due volte durante l'esecuzione.
		require_once WC_ASYNC_FILTERS_PATH . 'includes/class-admin.php';
		new Admin();

		require_once WC_ASYNC_FILTERS_PATH . 'includes/class-ajax-handler.php';
		new AjaxHandler();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'template_include',   [ $this, 'override_shop_template' ], 99 );
		add_filter( 'is_active_sidebar',  [ $this, 'disable_theme_sidebar' ], 10, 2 );
	}

	/**
	 * Carica CSS e JavaScript del plugin nelle pagine shop e categoria prodotto.
	 *
	 * Perché deve essere public?
	 * WordPress chiama questo metodo "dall'esterno" tramite il sistema di hook.
	 * In PHP, un metodo private non è accessibile dall'esterno della classe,
	 * quindi causerebbe un Fatal Error. I metodi collegati ad hook devono
	 * sempre essere public.
	 *
	 * Come funziona wp_localize_script?
	 * È il "ponte" ufficiale di WordPress per passare dati dal PHP al JavaScript.
	 * Genera una variabile JavaScript globale (qui: wcAsyncFilters) con i dati
	 * che specifichiamo, rendendoli accessibili nel file frontend.js.
	 * È sicuro perché WordPress gestisce correttamente l'escape dei dati JSON.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		// Usciamo subito se non siamo nella shop page o in una categoria prodotto.
		// Non ha senso caricare script e stili in altre pagine del sito.
		if ( ! is_shop() && ! is_product_category() ) {
			return;
		}

		// Select2 — libreria per dropdown con ricerca AJAX.
		// La carichiamo dal CDN jsDelivr invece che includerla nel plugin
		// per sfruttare la cache del browser (se già caricata da altro).
		wp_enqueue_style(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
			[],
			'4.1.0-rc.0'
		);

		wp_enqueue_script(
			'select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
			[ 'jquery' ],
			'4.1.0-rc.0',
			true // in_footer: true = caricato prima di </body>, non in <head>
		);

		// CSS del plugin con dipendenza da select2.
		// WordPress carica prima select2, poi questo file: l'ordine è garantito.
		wp_enqueue_style(
			'wc-async-filters-css',
			WC_ASYNC_FILTERS_URL . 'assets/css/frontend.css',
			[ 'select2' ],
			WC_ASYNC_FILTERS_VERSION
		);

		// JavaScript del plugin con dipendenze da jquery e select2.
		wp_enqueue_script(
			'wc-async-filters-js',
			WC_ASYNC_FILTERS_URL . 'assets/js/frontend.js',
			[ 'jquery', 'select2' ],
			WC_ASYNC_FILTERS_VERSION,
			true
		);

		// wp_localize_script: crea un oggetto JavaScript globale wcAsyncFilters
		// accessibile nel file frontend.js. È il modo ufficiale WordPress
		// per passare dati dinamici dal PHP (server) al JS (browser).
		wp_localize_script(
			'wc-async-filters-js',
			'wcAsyncFilters',
			[
				// URL dell'endpoint AJAX di WordPress (admin-ajax.php)
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				// Nonce per verificare l'autenticità delle richieste AJAX
				'nonce'                 => wp_create_nonce( 'wc_async_filters_nonce' ),
				// URL della pagina shop (usato nel JS per riconoscere la shop page)
				'shop_url'              => get_permalink( wc_get_page_id( 'shop' ) ),
				// Prefisso delle URL categoria (es. '/product-category/')
				'category_base'         => $this->get_category_base(),
				// Mappa slug → percorso completo di ogni categoria prodotto
				'category_paths'        => $this->get_category_paths(),
				// Numero di prodotti per pagina: prima l'opzione del plugin,
				// poi il fallback alle impostazioni globali di WordPress.
				'products_per_page'     => (int) get_option( 'wc_async_filters_per_page', get_option( 'posts_per_page', 12 ) ),
				// Tassonomie configurate dall'admin con ordine e stato abilitato
				'configured_taxonomies' => get_option( 'wc_async_filters_taxonomies', [] ),
				// Stringhe traducibili per il JavaScript
				'i18n'                  => [
					'load_more'   => __( 'Carica altri prodotti', 'wc-async-filters' ),
					'loading'     => __( 'Caricamento...', 'wc-async-filters' ),
					'no_products' => __( 'Nessun prodotto trovato.', 'wc-async-filters' ),
				],
				// Nomi leggibili per le tassonomie native di WooCommerce.
				// Passati dal PHP per sfruttare il sistema di traduzioni WordPress.
				// Le tassonomie pa_ vengono formattate automaticamente nel JS,
				// ma quelle native hanno nomi non leggibili (es. product_cat).
				'taxonomy_labels'       => [
					'product_cat'  => __( 'Categoria prodotto', 'wc-async-filters' ),
					'product_tag'  => __( 'Tag prodotto', 'wc-async-filters' ),
					'product_type' => __( 'Tipo prodotto', 'wc-async-filters' ),
				],
			]
		);
	}

	/**
	 * Sostituisce il template della shop page con il template personalizzato del plugin.
	 *
	 * Perché priorità 99?
	 * WordPress esegue i filtri in ordine di priorità crescente (default: 10).
	 * Molti temi (es. Storefront) agganciano il loro override a priorità 10-20.
	 * Con priorità 99 siamo sicuri di intervenire DOPO il tema, sovrascrivendo
	 * qualsiasi override precedente con il nostro template.
	 *
	 * @param string $template Percorso al template che WordPress sta per usare.
	 * @return string          Il percorso al template che vogliamo usare noi.
	 */
	public function override_shop_template( string $template ): string {
		if ( ! is_shop() && ! is_product_category() ) {
			return $template;
		}

		$custom_template = WC_ASYNC_FILTERS_PATH . 'templates/shop.php';

		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}

		return $template;
	}

	/**
	 * Disabilita la sidebar del tema nelle pagine gestite dal plugin.
	 *
	 * Il plugin ha la propria sidebar (con i filtri AJAX), quindi non vogliamo
	 * che il tema aggiunga la sua sidebar di widget dentro il nostro layout.
	 * Restituendo false per is_active_sidebar diciamo a WordPress che
	 * non ci sono sidebar attive — il tema smetterà di renderizzarle.
	 *
	 * @param bool       $is_active Se la sidebar è attiva.
	 * @param string|int $index     ID o nome della sidebar.
	 * @return bool
	 */
	public function disable_theme_sidebar( bool $is_active, $index ): bool {
		if ( is_shop() || is_product_category() ) {
			return false;
		}
		return $is_active;
	}

	/**
	 * Recupera il prefisso base delle URL delle categorie prodotto.
	 *
	 * WooCommerce salva la struttura permalink in un'opzione del database.
	 * L'utente può configurarla in WooCommerce → Impostazioni → Prodotti → Permalink.
	 * Leggiamo quella opzione invece di hardcodare '/product-category/'
	 * per rispettare la configurazione di ogni installazione.
	 *
	 * Esempio: se l'admin ha impostato "catalogue" come base,
	 * questa funzione restituisce '/catalogue/'.
	 *
	 * @return string Il prefisso con slash iniziale e finale.
	 */
	private function get_category_base(): string {
		$permalinks    = get_option( 'woocommerce_permalinks', [] );
		$category_base = $permalinks['category_base'] ?? 'product-category';

		return '/' . trim( $category_base, '/' ) . '/';
	}

	/**
	 * Costruisce un dizionario slug → percorso URL completo per ogni categoria prodotto.
	 *
	 * Il JavaScript ha bisogno di sapere a quale URL navigare quando l'utente
	 * seleziona una categoria. Invece di ricostruire i percorsi nel JS
	 * (che non ha accesso al database), li pre-calcoliamo qui in PHP e li
	 * passiamo tramite wp_localize_script.
	 *
	 * Esempio risultato:
	 * [
	 *   'camicie'    => '/product-category/abbigliamento/camicie/',
	 *   'pantaloni'  => '/product-category/abbigliamento/pantaloni/',
	 *   'giacche'    => '/product-category/abbigliamento/giacche/',
	 * ]
	 *
	 * @return array<string, string>
	 */
	private function get_category_paths(): array {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		// Costruiamo una mappa id → termine per poter risalire ai genitori.
		// Es: se una categoria ha parent=5, con questa mappa troviamo subito
		// il termine con ID 5 senza scorrere tutta l'array ogni volta.
		$terms_by_id = [];
		foreach ( $terms as $term ) {
			$terms_by_id[ $term->term_id ] = $term;
		}

		$paths = [];
		$base  = $this->get_category_base();

		foreach ( $terms as $term ) {
			$paths[ $term->slug ] = $base . $this->build_term_path( $term, $terms_by_id ) . '/';
		}

		return $paths;
	}

	/**
	 * Costruisce il percorso gerarchico di una categoria risalendo l'albero dei genitori.
	 *
	 * WooCommerce supporta categorie annidate (es. Abbigliamento > Uomo > Giacche).
	 * Per costruire il percorso URL corretto dobbiamo risalire la gerarchia
	 * finché non troviamo una categoria senza genitore (parent === 0).
	 *
	 * Il ciclo while funziona così:
	 * - Partiamo dalla categoria "Giacche" (parent = ID di "Uomo")
	 * - Troviamo "Uomo" nella mappa, aggiungiamo 'uomo/' in testa
	 * - "Uomo" ha parent = ID di "Abbigliamento"
	 * - Troviamo "Abbigliamento", aggiungiamo 'abbigliamento/' in testa
	 * - "Abbigliamento" ha parent = 0 → fine
	 * - Risultato: 'abbigliamento/uomo/giacche'
	 *
	 * @param \WP_Term $term        Il termine di partenza (la categoria foglia).
	 * @param array    $terms_by_id Mappa id → \WP_Term per accesso veloce ai genitori.
	 * @return string               Il percorso slug/slug/slug senza slash iniziale/finale.
	 */
	private function build_term_path( \WP_Term $term, array $terms_by_id ): string {
		$path    = $term->slug;
		$current = $term;

		while ( $current->parent !== 0 && isset( $terms_by_id[ $current->parent ] ) ) {
			$current = $terms_by_id[ $current->parent ];
			$path    = $current->slug . '/' . $path;
		}

		return $path;
	}
}
