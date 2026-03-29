<?php
/**
 * Gestisce la pagina di configurazione del plugin in wp-admin.
 *
 * La Settings API di WordPress funziona in tre passi:
 * 1. register_setting(): dice a WordPress che esiste un'opzione e come sanitizzarla.
 * 2. settings_fields(): nel form HTML, genera il nonce e i campi nascosti necessari.
 * 3. update_option(): WordPress aggiorna automaticamente l'opzione dopo il submit.
 * Seguire questa API garantisce sicurezza (nonce CSRF) e compatibilità futura.
 *
 * @package WCAsyncFilters
 */

namespace WCAsyncFilters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe Admin
 *
 * Aggiunge la voce di menu sotto WooCommerce, registra le impostazioni
 * tramite la Settings API e renderizza il form di configurazione.
 */
class Admin {

	/**
	 * Costruttore: registra gli hook per il menu e le impostazioni.
	 *
	 * Perché due hook separati (admin_menu e admin_init)?
	 * - admin_menu: si attiva quando WordPress costruisce il menu di navigazione.
	 *   È il momento giusto per aggiungere voci di menu.
	 * - admin_init: si attiva prima del rendering di qualsiasi pagina admin.
	 *   È il momento giusto per registrare impostazioni, perché deve avvenire
	 *   prima che il form venga processato (se l'utente ha appena salvato).
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Aggiunge la sottopagina "Filtri Asincroni" sotto il menu WooCommerce.
	 *
	 * Perché capability 'manage_woocommerce'?
	 * È la capacità assegnata ai gestori di negozio in WooCommerce.
	 * Usarla invece di 'manage_options' (solo Super Admin) permette ai
	 * gestori del negozio di configurare i filtri senza essere amministratori.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Filtri Asincroni', 'wc-async-filters' ),
			__( 'Filtri Asincroni', 'wc-async-filters' ),
			'manage_woocommerce',
			'wc-async-filters',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Registra l'opzione del plugin tramite la Settings API.
	 *
	 * register_setting() fa tre cose importanti:
	 * 1. Associa l'opzione a un option_group (usato da settings_fields())
	 * 2. Autorizza WordPress ad aggiornare questa opzione tramite admin-post.php
	 * 3. Collega la callback di sanitizzazione per pulire i dati prima del salvataggio
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wc_async_filters_group',
			'wc_async_filters_taxonomies',
			[
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => [],
			]
		);

		register_setting(
			'wc_async_filters_group',
			'wc_async_filters_per_page',
			[
				'sanitize_callback' => [ $this, 'sanitize_per_page' ],
				'default'           => (int) get_option( 'posts_per_page', 12 ),
			]
		);
	}

	/**
	 * Sanitizza il numero di prodotti per pagina.
	 *
	 * Accetta solo valori interi compresi tra 1 e 100.
	 * Se il valore non è nel range, viene agganciato al limite più vicino.
	 *
	 * @param mixed $raw_input Valore grezzo dal form.
	 * @return int             Valore sanitizzato tra 1 e 100.
	 */
	public function sanitize_per_page( $raw_input ): int {
		return max( 1, min( 100, (int) $raw_input ) );
	}

	/**
	 * Sanitizza i dati del form prima di salvarli nel database.
	 *
	 * Questa funzione è la "guardia di frontiera": tutto ciò che arriva
	 * dal form HTML deve passare da qui prima di essere salvato.
	 * Non ci fidiamo mai dei dati inviati dall'utente — anche se l'utente
	 * è un amministratore, un errore o un attacco XSS potrebbe iniettare
	 * dati malformati.
	 *
	 * product_cat è inclusa intenzionalmente: nella sidebar viene gestita
	 * come filtro speciale che aggiorna il pathname dell'URL tramite
	 * history.pushState invece di aggiungere un parametro GET.
	 *
	 * @param mixed $raw_input Dati grezzi dal form, non ancora sanitizzati.
	 * @return array           Array sanitizzato pronto per il database.
	 */
	public function sanitize_options( $raw_input ): array {
		if ( ! is_array( $raw_input ) ) {
			return [];
		}

		$clean = [];

		foreach ( $raw_input as $key => $value ) {
			// sanitize_key() rimuove caratteri non validi dalle chiavi.
			// Le chiavi delle tassonomie dovrebbero essere slug come 'pa_brand'.
			$key = sanitize_key( $key );

			if ( ! is_array( $value ) ) {
				continue;
			}

			// Il checkbox non invia nulla se non spuntato: isset() controlla
			// se la chiave esiste nel form, === '1' verifica il valore atteso.
			// Risultato: true se spuntato, false se non spuntato.
			$clean[ $key ]['enabled'] = isset( $value['enabled'] ) && $value['enabled'] === '1';

			// (int) con ?? 0 garantisce che l'ordine sia sempre un intero.
			// ?? 0 gestisce il caso in cui la chiave 'order' non sia presente.
			$clean[ $key ]['order']   = (int) ( $value['order'] ?? 0 );
		}

		return $clean;
	}

	/**
	 * Renderizza la pagina HTML di configurazione in wp-admin.
	 *
	 * Perché settings_fields()?
	 * Questa funzione WordPress genera automaticamente:
	 * 1. Un campo nascosto con l'option_group (dice a WordPress quale opzione aggiornare)
	 * 2. Un nonce CSRF per proteggere il form da attacchi cross-site
	 * 3. Un campo con il referer per il redirect dopo il salvataggio
	 * Senza settings_fields() WordPress rifiuterebbe il salvataggio.
	 *
	 * Perché checked()?
	 * È una helper WordPress che stampa 'checked="checked"' se il primo
	 * argomento è truthy. Equivale a: echo $is_enabled ? 'checked="checked"' : ''
	 * Ma è più leggibile e gestisce correttamente i tipi PHP.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// Verifica i permessi prima di mostrare qualsiasi contenuto.
		// wp_die() mostra un messaggio di errore e interrompe l'esecuzione.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'wc-async-filters' ) );
		}

		// Recupera tutte le tassonomie registrate per i prodotti WooCommerce.
		// 'objects' restituisce oggetti WP_Taxonomy invece di semplici stringhe,
		// così possiamo accedere a ->label, ->name ecc.
		$taxonomies = get_object_taxonomies( 'product', 'objects' );
		$saved      = get_option( 'wc_async_filters_taxonomies', [] );

		// product_cat è inclusa: la sidebar la mostra come filtro AJAX
		// che aggiorna il pathname dell'URL tramite history.pushState.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Filtri Asincroni - Configurazione', 'wc-async-filters' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'wc_async_filters_group' ); ?>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Abilita', 'wc-async-filters' ); ?></th>
							<th><?php esc_html_e( 'Tassonomia', 'wc-async-filters' ); ?></th>
							<th><?php esc_html_e( 'Ordine', 'wc-async-filters' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $taxonomies as $slug => $taxonomy ) :
							$is_enabled = ! empty( $saved[ $slug ]['enabled'] );
							$order      = isset( $saved[ $slug ]['order'] ) ? (int) $saved[ $slug ]['order'] : 0;
						?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="<?php echo esc_attr( 'wc_async_filters_taxonomies[' . $slug . '][enabled]' ); ?>"
									value="1"
									<?php checked( $is_enabled ); ?>
								/>
							</td>
							<td><?php echo esc_html( $taxonomy->label ); ?></td>
							<td>
								<input
									type="number"
									name="<?php echo esc_attr( 'wc_async_filters_taxonomies[' . $slug . '][order]' ); ?>"
									value="<?php echo esc_attr( $order ); ?>"
									min="0"
								/>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Impostazioni griglia', 'wc-async-filters' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="wc_async_filters_per_page">
								<?php esc_html_e( 'Prodotti per pagina', 'wc-async-filters' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="wc_async_filters_per_page"
								name="wc_async_filters_per_page"
								value="<?php echo esc_attr( (int) get_option( 'wc_async_filters_per_page', get_option( 'posts_per_page', 12 ) ) ); ?>"
								min="1"
								max="100"
							/>
							<p class="description">
								<?php esc_html_e( 'Numero di prodotti mostrati nella griglia prima del pulsante Load More. Min 1, max 100.', 'wc-async-filters' ); ?>
							</p>
						</td>
					</tr>
				</table>

			<p><?php submit_button(); ?></p>
			</form>
		</div>
		<?php
	}
}
