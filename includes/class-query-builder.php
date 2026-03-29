<?php
/**
 * Costruisce WP_Query in base ai filtri attivi e determina
 * le tassonomie presenti nei risultati della query.
 *
 * Questa classe esiste per rispettare il principio SRP (Single Responsibility
 * Principle): ogni classe dovrebbe fare una sola cosa bene. La logica di
 * costruzione delle query è separata dalla logica AJAX (class-ajax-handler.php)
 * per rendere entrambe più facili da leggere, testare e modificare.
 *
 * @package WCAsyncFilters
 */

namespace WCAsyncFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe QueryBuilder
 *
 * Responsabile della costruzione di WP_Query a partire dai filtri attivi
 * e dell'analisi dei risultati per determinare le tassonomie presenti.
 */
class QueryBuilder {

	/**
	 * Costruisce e restituisce un oggetto WP_Query con i filtri attivi.
	 *
	 * Perché \WP_Query con backslash?
	 * Siamo nel namespace WCAsyncFilters, quindi PHP cercherebbe WCAsyncFilters\WP_Query.
	 * Il backslash iniziale forza PHP a cercare WP_Query nel namespace globale,
	 * dove WordPress l'ha definita. Senza backslash otterremmo un errore.
	 *
	 * Perché tax_query con relation AND?
	 * Se l'utente filtra per brand='nike' E colore='rosso' vogliamo prodotti
	 * che soddisfano ENTRAMBE le condizioni (AND), non basta una (OR).
	 * AND è il comportamento intuitivo per i filtri: "mostrami scarpe Nike rosse".
	 *
	 * Perché aggiungiamo tax_query agli args solo se count > 1?
	 * L'array $tax_query parte con solo 'relation' => 'AND' (1 elemento).
	 * Se non ci sono filtri attivi, passare una tax_query vuota a WP_Query
	 * può causare query che non trovano prodotti o comportamenti inattesi.
	 * Aggiungiamo tax_query solo se c'è almeno un filtro reale (count > 1).
	 *
	 * @param array  $filters       Mappa tassonomia => slug termine attivo.
	 * @param int    $page          Numero di pagina corrente (minimo 1).
	 * @param string $category_path Percorso categoria dal pathname URL, o ''.
	 * @return \WP_Query
	 */
	public function build_query( array $filters, int $page, string $category_path ): \WP_Query {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			// Prima leggiamo l'opzione del plugin; se non impostata, usiamo
			// il valore globale di WordPress (Impostazioni → Lettura).
			'posts_per_page' => (int) get_option( 'wc_async_filters_per_page', get_option( 'posts_per_page', 12 ) ),
			// max(1, $page) garantisce che la paginazione parta sempre da 1
			// anche se per qualche motivo il JS inviasse un valore < 1.
			'paged'          => max( 1, $page ),
		];

		$tax_query = [
			'relation' => 'AND',
		];

		// Aggiungiamo un filtro tax_query per ogni tassonomia attiva.
		foreach ( $filters as $taxonomy => $term_slug ) {
			// Saltiamo la tassonomia product_cat: le categorie vengono gestite
			// separatamente tramite il $category_path estratto dal pathname URL.
			// Inviarle anche in $filters causerebbe un duplicato nella query.
			if ( 'product_cat' === $taxonomy ) {
				continue;
			}

			// Un filtro deselezionato arriva come stringa vuota: lo saltiamo.
			if ( '' === $term_slug ) {
				continue;
			}

			// Sanitizziamo sempre i dati in ingresso anche se arrivano dall'interno
			// del plugin, per difesa in profondità contro manipolazioni impreviste.
			$taxonomy  = sanitize_key( $taxonomy );
			$term_slug = sanitize_text_field( $term_slug );

			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => [ $term_slug ],
				'operator' => 'IN',
			];
		}

		// Se siamo in una pagina categoria, aggiungiamo il filtro product_cat.
		// Perché basename()? Il category_path può essere un percorso gerarchico
		// come 'abbigliamento/camicie'. Noi vogliamo filtrare per la categoria
		// più specifica, cioè l'ultimo segmento: 'camicie'.
		// basename('abbigliamento/camicie') → 'camicie'
		// basename('scarpe') → 'scarpe' (nessun genitore, funziona lo stesso)
		if ( '' !== $category_path ) {
			$category_path = sanitize_text_field( $category_path );
			$category_slug = sanitize_key( basename( $category_path ) );

			$tax_query[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => [ $category_slug ],
				'operator' => 'IN',
			];
		}

		// Aggiungiamo la tax_query agli args solo se ha filtri reali.
		// L'array parte con ['relation' => 'AND'] = 1 elemento.
		// count > 1 significa che c'è almeno un filtro oltre alla relation.
		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query;
		}

		return new \WP_Query( $args );
	}

	/**
	 * Analizza i post trovati dalla query e restituisce le tassonomie presenti.
	 *
	 * Questo metodo implementa i "filtri dinamici dipendenti dal contesto":
	 * se nessun prodotto trovato appartiene a una tassonomia (es. tutti i prodotti
	 * filtrati per brand 'Nike' non hanno attributo 'colore'), quella tassonomia
	 * viene nascosta dalla sidebar perché non porterebbe ad alcun affinamento.
	 *
	 * L'ottimizzazione con in_array:
	 * Una volta che abbiamo trovato almeno un prodotto con termini per una
	 * tassonomia, non ha senso continuare a verificarla per i prodotti successivi.
	 * Il controllo in_array all'inizio del loop interno evita chiamate inutili
	 * a get_the_terms() per tassonomie già confermate come presenti.
	 *
	 * Perché controllare WP_Error?
	 * get_the_terms() può restituire tre tipi di valori:
	 * - Array di WP_Term se trova termini
	 * - false se il post non ha termini per quella tassonomia
	 * - WP_Error se la tassonomia non esiste o c'è un errore DB
	 * Dobbiamo gestire tutti e tre i casi.
	 *
	 * @param \WP_Query $query Query già eseguita con i prodotti trovati.
	 * @return array           Array di slug delle tassonomie presenti nei risultati.
	 */
	public function get_present_taxonomies( \WP_Query $query ): array {
		$configured = get_option( 'wc_async_filters_taxonomies', [] );

		// Se non ci sono tassonomie configurate o la query non ha trovato prodotti,
		// non c'è nulla da analizzare: usciamo subito.
		if ( empty( $configured ) || empty( $query->posts ) ) {
			return [];
		}

		$present = [];

		foreach ( $query->posts as $post ) {
			foreach ( array_keys( $configured ) as $taxonomy_slug ) {
				// Ottimizzazione: se questa tassonomia è già confermata presente,
				// saltiamo — non serve controllare altri prodotti per essa.
				if ( in_array( $taxonomy_slug, $present, true ) ) {
					continue;
				}

				$terms = get_the_terms( $post->ID, $taxonomy_slug );

				// Aggiungiamo la tassonomia alla lista solo se il post ha termini validi.
				if ( false !== $terms && ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$present[] = $taxonomy_slug;
				}
			}
		}

		return $present;
	}
}
