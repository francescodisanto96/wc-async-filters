/**
 * WC Async Filters — frontend.js
 *
 * Gestisce i filtri asincroni nella pagina shop di WooCommerce.
 * Tutto il codice è racchiuso in una IIFE (Immediately Invoked Function Expression)
 * per evitare di inquinare lo scope globale: le variabili e le funzioni definite
 * qui non sono visibili agli altri script della pagina.
 *
 * Select2 è l'unica libreria esterna usata (richiede jQuery internamente).
 * window.jQuery viene usato SOLO per inizializzare Select2 e ascoltarne gli eventi.
 * Tutto il resto è Vanilla JavaScript puro.
 */
(function () {
	'use strict';

	/*
	 * STATO DELL'APPLICAZIONE
	 *
	 * Queste due variabili "ricordano" la situazione corrente dell'interfaccia.
	 * Vivono dentro l'IIFE quindi sono invisibili agli altri script della pagina.
	 *
	 * currentPage: tiene traccia di quale pagina di prodotti stiamo visualizzando.
	 *   - 1 = prima pagina (caricamento iniziale o cambio filtro)
	 *   - 2, 3... = pagine successive caricate con il pulsante Load More
	 *
	 * currentFilters: oggetto che mappa ogni tassonomia attiva al suo termine.
	 *   Es: { pa_brand: 'nike', pa_colore: 'rosso' }
	 *   Quando un filtro viene deselezionato la chiave viene rimossa con delete.
	 *   NON contiene product_cat: le categorie usano il pathname dell'URL.
	 */
	let currentPage = 1;
	let currentFilters = {};

	// ---------------------------------------------------------------------------
	// AVVIO — DOMContentLoaded
	// ---------------------------------------------------------------------------

	/*
	 * DOMContentLoaded si attiva quando il browser ha finito di costruire il DOM
	 * (l'albero HTML) ma prima che le immagini e altri asset pesanti siano caricati.
	 * È il momento giusto per inizializzare l'interfaccia: il DOM è pronto,
	 * non dobbiamo aspettare il caricamento completo della pagina.
	 *
	 * Ordine delle operazioni all'avvio:
	 * 1. buildFilters(): crea le select Select2 nella sidebar
	 * 2. readFiltersFromURL(): legge eventuali filtri dall'URL corrente
	 * 3. restoreSelectValues(): mostra visivamente i filtri letti dall'URL
	 * 4. fetchProducts(): carica i prodotti (con i filtri già attivi se presenti)
	 * 5. Collega il pulsante Load More
	 */
	document.addEventListener('DOMContentLoaded', function () {
		buildFilters();
		readFiltersFromURL();

		// Se ci sono filtri nell'URL (es. l'utente ha ricaricato la pagina
		// o è arrivato da un link condiviso), ripristiniamo i valori visivi
		// nelle select PRIMA di caricare i prodotti.
		if (Object.keys(currentFilters).length > 0) {
			// Aspettiamo che tutte le select siano aggiornate visivamente
			// prima di caricare i prodotti. restoreSelectValues() restituisce
			// una Promise che si risolve quando tutte le chiamate AJAX completano.
			restoreSelectValues(currentFilters).then(function () {
				fetchProducts(currentFilters, 1, false);
			});
		} else {
			// Nessun filtro nell'URL: carichiamo subito i prodotti.
			fetchProducts(currentFilters, 1, false);
		}

		const loadMoreBtn = document.getElementById('wcaf-load-more');
		if (loadMoreBtn) {
			loadMoreBtn.addEventListener('click', function () {
				// Richiediamo la pagina successiva aggiungendo prodotti
				// a quelli già mostrati, senza sostituirli.
				fetchProducts(currentFilters, currentPage + 1, false);
			});
		}
	});

	// ---------------------------------------------------------------------------
	// buildFilters()
	// ---------------------------------------------------------------------------

	/*
	 * Legge le tassonomie configurate dall'admin (wcAsyncFilters.configured_taxonomies)
	 * e crea dinamicamente nella sidebar una select Select2 per ognuna.
	 *
	 * Gestisce due tipi di comportamento al cambio di selezione:
	 *
	 * 1. product_cat (categorie prodotto):
	 *    Aggiorna il PATHNAME dell'URL usando history.pushState.
	 *    Es: selezionando 'camicie' l'URL diventa /product-category/abbigliamento/camicie/
	 *    poi carica i prodotti via AJAX. Nessun reload della pagina.
	 *
	 * 2. Tutte le altre tassonomie (pa_brand, pa_colore, pa_taglia...):
	 *    Aggiungono parametri GET all'URL corrente.
	 *    Es: ?brand=nike&colore=rosso
	 *    poi caricano i prodotti via AJAX con history.pushState.
	 */
	function buildFilters() {
		const container = document.getElementById('wcaf-filters-container');
		if (!container) {
			return;
		}

		const taxonomies = wcAsyncFilters.configured_taxonomies || {};

		// Filtriamo solo le tassonomie abilitate dall'admin e le ordiniamo
		// secondo il campo 'order' configurato nella pagina admin.
		const sorted = Object.entries(taxonomies)
			.filter(
				([, config]) =>
					config.enabled === true ||
					config.enabled === '1' ||
					config.enabled === 1,
			)
			.sort((a, b) => (a[1].order || 0) - (b[1].order || 0));

		sorted.forEach(([slug]) => {
			// Usiamo i nomi traducibili passati dal PHP tramite wp_localize_script.
			// Se la tassonomia non è in taxonomy_labels (es. pa_brand, pa_colore)
			// applichiamo la formattazione automatica rimuovendo il prefisso pa_.
			const taxonomyLabels = wcAsyncFilters.taxonomy_labels || {};

			const label =
				taxonomyLabels[slug] ||
				slug
					.replace(/^pa_/, '')
					.replace(/[-_]/g, ' ')
					.replace(/^\w/, (c) => c.toUpperCase());

			// Creiamo il contenitore del filtro con data-taxonomy per identificarlo.
			const item = document.createElement('div');
			item.classList.add('wcaf-filter-item');
			item.dataset.taxonomy = slug;

			const labelEl = document.createElement('label');
			labelEl.textContent = label;

			const select = document.createElement('select');
			select.classList.add('wcaf-filter-select');
			select.dataset.taxonomy = slug;
			// L'ID univoco permette di identificare la select nel DOM
			// e facilita eventuali test automatizzati.
			select.id = 'wcaf-filter-' + slug;

			item.appendChild(labelEl);
			item.appendChild(select);
			container.appendChild(item);

			// Select2 richiede jQuery internamente per gestire il DOM e gli eventi.
			// Usiamo window.jQuery SOLO qui per l'inizializzazione di Select2.
			// La modalità 'ajax' di Select2 carica le opzioni dal server man mano
			// che l'utente digita, invece di precaricarne migliaia all'avvio.
			window.jQuery(select).select2({
				placeholder: 'Seleziona...',
				allowClear: true, // Mostra la X per deselezionare
				width: '100%',
				ajax: {
					url: wcAsyncFilters.ajax_url,
					type: 'POST',
					dataType: 'json',
					delay: 250, // ms di attesa prima di inviare la ricerca (debounce)
					data: function (params) {
						return {
							action: 'wc_get_filter_terms',
							nonce: wcAsyncFilters.nonce,
							taxonomy: slug,
							// params.term è il testo digitato dall'utente in Select2
							search: params.term,
						};
					},
					processResults: function (response) {
						if (!response.success || !response.data) {
							return { results: [] };
						}
						// Gestiamo sia array JSON [] che oggetti JSON {}
						// (PHP può serializzare diversamente in base alle chiavi).
						const items = Array.isArray(response.data)
							? response.data
							: Object.values(response.data);
						// Select2 si aspetta { results: [ { id, text }, ... ] }
						return {
							results: items.map((t) => ({
								id: t.slug,
								text: t.name,
							})),
						};
					},
				},
			});

			// Evento change: si attiva quando l'utente seleziona o deseleziona
			// un'opzione nella dropdown Select2 (inclusa la X per deselezionare).
			window.jQuery(select).on('change', function () {
				const value = window.jQuery(this).val();

				// Gestione speciale per product_cat: aggiorna il pathname dell'URL.
				// Le categorie prodotto hanno URL semantici propri e gerarchici
				// (/product-category/abbigliamento/camicie/) che devono riflettersi
				// nel pathname per correttezza SEO e compatibilità con i breadcrumb.
				if (slug === 'product_cat') {
					if (!value) {
						// Deseleziona categoria: torniamo alla shop page principale.
						const shopPath = wcAsyncFilters.shop_url
							? new URL(wcAsyncFilters.shop_url).pathname
							: '/shop/';

						delete currentFilters['product_cat'];
						currentPage = 1;

						// Costruiamo il nuovo URL mantenendo gli altri filtri GET attivi.
						const params = new URLSearchParams();
						Object.entries(currentFilters).forEach(function ([
							t,
							v,
						]) {
							if (v) {
								// Rimuoviamo pa_ per URL leggibili: ?brand= invece di ?pa_brand=
								const key = t.replace(/^pa_/, '');
								params.set(key, v);
							}
						});
						const query = params.toString();
						const newURL = shopPath + (query ? '?' + query : '');

						history.pushState(
							{ filters: currentFilters },
							'',
							newURL,
						);
						fetchProducts(currentFilters, 1, false);
						return;
					}

					// Selezione categoria: il nuovo pathname è il percorso gerarchico
					// della categoria selezionata (pre-calcolato dal PHP in category_paths).
					const categoryPaths = wcAsyncFilters.category_paths || {};
					const categoryBase =
						wcAsyncFilters.category_base || '/product-category/';
					const newPath = categoryPaths[value]
						? categoryPaths[value]
						: categoryBase + value + '/';

					// Manteniamo gli altri filtri GET nel nuovo URL.
					const params = new URLSearchParams();
					Object.entries(currentFilters).forEach(function ([t, v]) {
						if (v && t !== 'product_cat') {
							// Rimuoviamo pa_ per URL leggibili: ?brand= invece di ?pa_brand=
							const key = t.replace(/^pa_/, '');
							params.set(key, v);
						}
					});
					const query = params.toString();
					const newURL = newPath + (query ? '?' + query : '');

					currentFilters['product_cat'] = value;
					currentPage = 1;

					// history.pushState aggiorna l'URL SENZA ricaricare la pagina.
					// Il primo argomento (state) viene recuperato nell'evento popstate.
					history.pushState({ filters: currentFilters }, '', newURL);
					fetchProducts(currentFilters, 1, false);
					return;
				}

				// Tutte le altre tassonomie: filtri AJAX con parametri GET.
				if (value) {
					currentFilters[slug] = value;
				} else {
					delete currentFilters[slug];
				}
				currentPage = 1;
				// pushState: true → aggiorna l'URL con i nuovi parametri GET
				fetchProducts(currentFilters, 1, true);
			});
		});
	}

	// ---------------------------------------------------------------------------
	// fetchProducts()
	// ---------------------------------------------------------------------------

	/*
	 * È la funzione principale del plugin: invia una richiesta AJAX al server
	 * con i filtri attivi e aggiorna l'interfaccia con i risultati.
	 *
	 * Parametri:
	 * - filters: oggetto { tassonomia: termine } con i filtri attivi
	 * - page: numero di pagina (1 = prima pagina, >1 = Load More)
	 * - pushState: se true aggiorna l'URL del browser con i filtri GET
	 *
	 * Viene chiamata in questi momenti:
	 * - Al caricamento della pagina (DOMContentLoaded)
	 * - Ad ogni cambio di filtro (da buildFilters)
	 * - Al click su Load More
	 * - Nell'evento popstate (back/forward del browser)
	 */
	function fetchProducts(filters, page, pushState) {
		const main = document.querySelector('.wcaf-main');
		// Aggiungiamo la classe 'loading' per mostrare l'overlay semi-trasparente
		// definito nel CSS con ::after. L'utente sa che sta arrivando qualcosa.
		if (main) {
			main.classList.add('loading');
		}

		// URLSearchParams costruisce un corpo per la richiesta POST
		// equivalente a un form HTML inviato. Gestisce automaticamente
		// l'encoding dei caratteri speciali.
		const params = new URLSearchParams();
		params.set('action', 'wc_filter_products');
		params.set('nonce', wcAsyncFilters.nonce);
		params.set('page', page);
		// Passiamo la categoria dal pathname (non dai filtri GET)
		// così il PHP può costruire la tax_query corretta per product_cat.
		params.set('category_path', getCategoryPathFromURL());

		Object.keys(filters).forEach((taxonomy) => {
			if (taxonomy !== 'product_cat') {
				// Rimuoviamo pa_ dal nome della chiave per coerenza con l'URL.
				// Il PHP in class-ajax-handler.php lo riagggiunge prima della query.
				const key = taxonomy.replace(/^pa_/, '');
				params.set('filters[' + key + ']', filters[taxonomy]);
			}
		});

		fetch(wcAsyncFilters.ajax_url, {
			method: 'POST',
			body: params,
		})
			.then((response) => response.json())
			.then((data) => {
				const grid = document.getElementById('wcaf-products-grid');

				if (!data.success) {
					if (grid) {
						grid.innerHTML =
							'<p class="wcaf-no-products">' +
							wcAsyncFilters.i18n.no_products +
							'</p>';
					}
					return;
				}

				const result = data.data;

				if (grid) {
					if (page === 1) {
						// Prima pagina: sostituiamo completamente la griglia.
						grid.innerHTML = result.html;
					} else {
						// Load More (page > 1): aggiungiamo i nuovi prodotti
						// DENTRO il wrapper <ul class="products"> già esistente nel DOM.
						// Il PHP ha restituito solo i <li>, non il <ul> che abbiamo già.
						const productsWrapper = grid.querySelector(
							'ul.products, div.products',
						);
						if (productsWrapper) {
							productsWrapper.insertAdjacentHTML(
								'beforeend',
								result.html,
							);
						} else {
							// Fallback: il wrapper non esiste per qualche motivo imprevisto.
							grid.insertAdjacentHTML('beforeend', result.html);
						}
					}
				}

				// Mostriamo/nascondiamo i filtri in base alle tassonomie
				// effettivamente presenti nei prodotti trovati.
				updateFilterVisibility(result.taxonomies_present);

				const loadMore = document.getElementById('wcaf-load-more');
				const totalPages = result.total_pages;

				if (loadMore) {
					if (page >= totalPages || totalPages === 0) {
						// Siamo all'ultima pagina (o non ci sono prodotti):
						// nascondiamo il pulsante Load More.
						loadMore.style.display = 'none';
					} else {
						// Ci sono altre pagine: mostriamo il pulsante
						// e aggiorniamo currentPage per il prossimo click.
						loadMore.style.display = 'block';
						currentPage = page;
					}
				}

				// pushState aggiorna l'URL del browser solo per i filtri GET.
				// product_cat ha già aggiornato il pathname nel change handler.
				if (pushState === true) {
					updateURL(filters);
				}
			})
			.catch(() => {
				// In caso di errore di rete o parsing, mostriamo un messaggio.
				const grid = document.getElementById('wcaf-products-grid');
				if (grid) {
					grid.innerHTML =
						'<p class="wcaf-no-products">' +
						wcAsyncFilters.i18n.no_products +
						'</p>';
				}
			})
			.finally(() => {
				// finally si esegue sempre, con successo o errore.
				// Rimuoviamo sempre l'overlay di loading per non bloccare l'interfaccia.
				if (main) {
					main.classList.remove('loading');
				}
			});
	}

	// ---------------------------------------------------------------------------
	// updateFilterVisibility()
	// ---------------------------------------------------------------------------

	/*
	 * Mostra o nasconde i filtri nella sidebar in base alle tassonomie presenti
	 * nei prodotti trovati dall'ultima query AJAX.
	 *
	 * Questo implementa il requisito "filtri dinamici dipendenti dal contesto":
	 * se sto guardando solo prodotti Nike e nessuno ha l'attributo 'taglia',
	 * il filtro 'taglia' sparisce dalla sidebar perché non serve a nulla.
	 * Quando il filtro brand viene rimosso e tornano prodotti con taglie diverse,
	 * il filtro 'taglia' riappare.
	 *
	 * product_cat è sempre visibile perché è navigazione, non filtro:
	 * deve sempre essere disponibile per cambiare categoria.
	 */
	function updateFilterVisibility(presentTaxonomies) {
		document.querySelectorAll('.wcaf-filter-item').forEach((item) => {
			const taxonomy = item.dataset.taxonomy;

			// Le categorie prodotto sono sempre visibili: l'utente deve sempre
			// poter cambiare categoria, indipendentemente dai prodotti trovati.
			if (taxonomy === 'product_cat') {
				item.style.display = 'block';
				return;
			}

			// Le altre tassonomie appaiono solo se almeno un prodotto trovato
			// ha termini per quella tassonomia.
			item.style.display = presentTaxonomies.includes(taxonomy)
				? 'block'
				: 'none';
		});
	}

	// ---------------------------------------------------------------------------
	// updateURL()
	// ---------------------------------------------------------------------------

	/*
	 * Aggiorna i parametri GET dell'URL corrente con i filtri AJAX attivi.
	 * Usa history.pushState per cambiare l'URL senza ricaricare la pagina.
	 *
	 * IMPORTANTE: product_cat NON va nei parametri GET.
	 * La categoria è già nel PATHNAME dell'URL (es. /product-category/camicie/).
	 * Il change handler di product_cat in buildFilters aggiorna il pathname
	 * direttamente. Qui gestiamo solo i parametri GET delle altre tassonomie.
	 *
	 * history.pushState(state, title, url):
	 * - state: oggetto salvato nello stack della cronologia del browser.
	 *   Viene recuperato nell'evento popstate quando l'utente naviga back/forward.
	 * - title: ignorato dalla maggior parte dei browser (passiamo '').
	 * - url: il nuovo URL da mostrare nella barra degli indirizzi.
	 */
	function updateURL(filters) {
		const params = new URLSearchParams();

		Object.entries(filters).forEach(function ([taxonomy, term]) {
			// product_cat è nel pathname, non nei parametri GET.
			if (!term || taxonomy === 'product_cat') return;
			// Rimuoviamo il prefisso 'pa_' dallo slug WooCommerce per
			// ottenere URL leggibili: ?brand=nike invece di ?pa_brand=nike.
			// Il prefisso viene riaggiunto quando leggiamo l'URL in readFiltersFromURL().
			const key = taxonomy.replace(/^pa_/, '');
			params.set(key, term);
		});

		const query = params.toString();
		// Manteniamo il pathname corrente (che potrebbe già essere una categoria)
		// e sostituiamo solo la query string.
		const newURL = window.location.pathname + (query ? '?' + query : '');

		history.pushState({ filters }, '', newURL);
	}

	// ---------------------------------------------------------------------------
	// readFiltersFromURL()
	// ---------------------------------------------------------------------------

	/*
	 * Legge i filtri dall'URL corrente al caricamento della pagina.
	 *
	 * Serve in due scenari:
	 * 1. L'utente ricarica la pagina con filtri attivi nell'URL
	 *    (es. ?brand=nike) → dobbiamo riapplicare quei filtri.
	 * 2. L'utente arriva da un link condiviso con filtri nell'URL
	 *    → i filtri devono essere già attivi quando la pagina si apre.
	 *
	 * Legge due fonti di dati:
	 * - Parametri GET (?brand=nike) per le tassonomie normali.
	 * - Pathname (/product-category/camicie/) per product_cat.
	 *   L'ultimo segmento del path è lo slug della categoria corrente.
	 */
	function readFiltersFromURL() {
		const params = new URLSearchParams(window.location.search);

		params.forEach(function (value, key) {
			// Non leggiamo product_cat dai parametri GET.
			if (key === 'product_cat') return;

			// Ripristiniamo il prefisso 'pa_' rimosso da updateURL().
			// I filtri WooCommerce usano internamente lo slug completo (pa_brand)
			// ma nell'URL mostriamo la versione corta (brand).
			// Evitiamo di aggiungere 'pa_' due volte se per qualche motivo
			// il parametro arrivasse già con il prefisso.
			const taxonomy = key.startsWith('pa_') ? key : 'pa_' + key;
			currentFilters[taxonomy] = value;
		});

		// Leggiamo la categoria dal pathname e la aggiungiamo a currentFilters
		// così la select product_cat mostra la categoria corrente come selezionata.
		const categoryPath = getCategoryPathFromURL();
		if (categoryPath) {
			// Prendiamo l'ultimo segmento come slug della categoria più specifica.
			// Es: 'abbigliamento/camicie' → 'camicie'
			const parts = categoryPath.split('/').filter(Boolean);
			if (parts.length > 0) {
				currentFilters['product_cat'] = parts[parts.length - 1];
			}
		}
	}

	// ---------------------------------------------------------------------------
	// restoreSelectValues()
	// ---------------------------------------------------------------------------

	/*
	 * Ripristina visivamente i valori nelle select Select2 dopo un reload
	 * della pagina o dopo la navigazione back/forward del browser.
	 *
	 * Il problema: Select2 in modalità AJAX non precarica le opzioni.
	 * Quando la pagina si carica con ?brand=nike nell'URL, Select2 non sa
	 * come mostrare "Nike" nella dropdown perché non ha mai caricato le opzioni.
	 * Dobbiamo fare una chiamata AJAX per recuperare il nome leggibile del termine
	 * ('The North Face' invece di 'the-north-face') e creare l'opzione manualmente.
	 *
	 * Perché window.jQuery(select).append(option).trigger('change.select2')?
	 * Select2 wrappa la select nativa e mantiene il suo stato interno.
	 * Aggiungere l'option alla select nativa non aggiorna automaticamente l'UI di Select2.
	 * trigger('change.select2') notifica Select2 che la select è cambiata
	 * e aggiorna la sua interfaccia visiva.
	 */
	function restoreSelectValues(filters) {
		// Raccogliamo le Promise di tutte le chiamate AJAX.
		// Promise.all() si risolve quando TUTTE sono completate.
		// Questo permette a chi chiama restoreSelectValues() di aspettare
		// che tutte le select siano aggiornate prima di procedere.
		const promises = [];

		document.querySelectorAll('.wcaf-filter-select').forEach((select) => {
			const taxonomy = select.dataset.taxonomy;
			const value = filters[taxonomy];

			// Per select senza valore attivo non serve nessuna chiamata AJAX.
			if (!value) {
				window.jQuery(select).val(null).trigger('change');
				return;
			}

			// Avviamo la chiamata AJAX e salviamo la Promise nell'array.
			const promise = fetch(wcAsyncFilters.ajax_url, {
				method: 'POST',
				body: new URLSearchParams({
					action: 'wc_get_filter_terms',
					nonce: wcAsyncFilters.nonce,
					taxonomy: taxonomy,
					search: value, // Usiamo lo slug come testo di ricerca
				}),
			})
				.then((response) => response.json())
				.then((data) => {
					if (!data.success || !data.data) return;

					const items = Array.isArray(data.data)
						? data.data
						: Object.values(data.data);

					// Cerchiamo il termine con lo slug esatto che abbiamo nell'URL.
					const term = items.find((t) => t.slug === value);
					if (!term) return;

					// Creiamo l'opzione con il nome leggibile e la selezioniamo.
					// new Option(text, value, defaultSelected, selected)
					const option = new Option(term.name, term.slug, true, true);
					window
						.jQuery(select)
						.append(option)
						.trigger('change.select2');
				})
				.catch(() => {
					// Fallback: se la chiamata AJAX fallisce, mostriamo almeno lo slug.
					// Non è ideale ma meglio di non mostrare nulla.
					const option = new Option(value, value, true, true);
					window
						.jQuery(select)
						.append(option)
						.trigger('change.select2');
				});

			promises.push(promise);
		});

		// Restituiamo una Promise che si risolve quando tutte
		// le chiamate AJAX per i termini sono completate.
		return Promise.all(promises);
	}

	// ---------------------------------------------------------------------------
	// getCategoryPathFromURL()
	// ---------------------------------------------------------------------------

	/*
	 * Estrae il percorso della categoria dal pathname dell'URL corrente.
	 * Viene passato al PHP come 'category_path' nelle richieste AJAX,
	 * così QueryBuilder sa quale categoria filtrare.
	 *
	 * Funzionamento:
	 * - Nella shop page (/shop/) restituisce '' (nessuna categoria)
	 * - In /product-category/abbigliamento/camicie/ restituisce 'abbigliamento/camicie'
	 *
	 * Perché controllare se siamo nella shop page?
	 * Senza questo controllo, il pathname '/shop/' diventerebbe 'shop'
	 * dopo la rimozione degli slash — uno slug di categoria inesistente
	 * che farebbe fallire la query sul server.
	 */
	function getCategoryPathFromURL() {
		const categoryBase =
			wcAsyncFilters.category_base || '/product-category/';
		const shopUrl = wcAsyncFilters.shop_url
			? new URL(wcAsyncFilters.shop_url).pathname
			: '/shop/';
		const pathname = window.location.pathname;

		// Confrontiamo il pathname con la shop URL in tre varianti
		// per gestire sia URLs con slash finale che senza.
		if (
			pathname === shopUrl ||
			pathname === shopUrl.replace(/\/$/, '') ||
			pathname + '/' === shopUrl
		) {
			return '';
		}

		// Rimuoviamo il prefisso della categoria (es. '/product-category/')
		// e gli slash iniziali/finali per ottenere solo il percorso pulito.
		return pathname.replace(categoryBase, '').replace(/^\/|\/$/g, '');
	}

	// ---------------------------------------------------------------------------
	// EVENTO popstate
	// ---------------------------------------------------------------------------

	/*
	 * Si attiva quando l'utente usa i tasti back/forward del browser.
	 * Ogni volta che chiamiamo history.pushState salviamo lo stato (filters)
	 * nella cronologia del browser. Quando l'utente torna indietro,
	 * popstate ci dà quello stato e noi ripristiniamo i filtri e i prodotti.
	 *
	 * Senza questo handler, il browser cambierebbe l'URL (back/forward funziona)
	 * ma la griglia prodotti e le select rimarrebbero nello stato corrente,
	 * creando un disallineamento tra URL e interfaccia.
	 */
	window.addEventListener('popstate', function (event) {
		const filters =
			event.state && event.state.filters ? event.state.filters : {};

		currentFilters = filters;
		currentPage = 1;

		// Aspettiamo che le select siano aggiornate prima di ricaricare i prodotti.
		restoreSelectValues(filters).then(function () {
			fetchProducts(filters, 1, false);
		});
	});
})();
