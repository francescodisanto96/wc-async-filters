# WC Async Filters

Un plugin WordPress che aggiunge filtri asincroni alla pagina shop di WooCommerce.
I filtri si aggiornano senza ricaricare la pagina, si nascondono se non pertinenti,
e l'URL si aggiorna ad ogni selezione per rendere i link condivisibili.

---

## Cosa fa questo plugin

Nella pagina shop di WooCommerce, nella barra laterale sinistra, appaiono dei filtri
basati sugli attributi dei prodotti (brand, colore, taglia, ecc.).

Quando selezioni un filtro succedono tre cose:

1. I prodotti si aggiornano immediatamente, senza ricaricare la pagina
2. I filtri che non hanno prodotti corrispondenti spariscono dalla sidebar
3. L'URL cambia per riflettere i filtri attivi (es. `/shop/?brand=nike&colore=rosso`)

Il tasto Indietro del browser funziona correttamente: torna allo stato precedente
dei filtri e dei prodotti.

---

## Requisiti

- WordPress (versione recente)
- WooCommerce attivo
- PHP 8.0 o superiore
- Tema compatibile con WooCommerce (vedi sezione Installazione)

---

## Come ho preparato l'ambiente di sviluppo

### 1. Installazione di WordPress in locale con Local

Per sviluppare e testare il plugin ho usato **Local by Flywheel** (chiamato semplicemente
"Local"), uno strumento gratuito che installa WordPress sul tuo computer in pochi click
senza configurare manualmente PHP, MySQL o un server web.

Scaricalo da: https://localwp.com

Dopo l'installazione:

1. Apri Local e clicca il **+** in basso a sinistra
2. Dai un nome al sito (es. `wc-filters-dev`)
3. Scegli **Preferred** come ambiente
4. Imposta username e password admin (es. `admin` / `admin`)
5. Clicca **Finish** e aspetta che Local installi tutto

Quando finisce, clicca **WP Admin** per accedere al pannello di amministrazione.

### 2. Installazione del tema Storefront

Ho scelto il tema **Storefront** perché è il tema ufficiale gratuito di WooCommerce,
sviluppato dallo stesso team. È garantito compatibile al 100% con WooCommerce,
segue tutti gli standard del tema WordPress, e non interferisce con il plugin.

Ho scelto Storefront invece di un tema personalizzato perché permette di testare
il plugin in un ambiente pulito e neutro, senza doversi preoccupare di conflitti
con stili o logiche specifiche di altri temi.

Per installarlo:

1. Vai su **wp-admin → Aspetto → Temi → Aggiungi nuovo**
2. Cerca **Storefront**
3. Clicca **Installa** poi **Attiva**

### 3. Installazione di WooCommerce

1. Vai su **wp-admin → Plugin → Aggiungi nuovo**
2. Cerca **WooCommerce**
3. Clicca **Installa** poi **Attiva**
4. Segui il wizard di configurazione (puoi cliccare Skip su ogni passaggio)

---

## Installazione del plugin

### Metodo 1 — Carica lo zip (consigliato)

1. Vai su **wp-admin → Plugin → Aggiungi nuovo → Carica plugin**
2. Seleziona il file `wc-async-filters.zip`
3. Clicca **Installa** poi **Attiva**

### Metodo 2 — Copia manuale

1. Copia la cartella `wc-async-filters/` dentro `wp-content/plugins/`
2. Vai su **wp-admin → Plugin**
3. Trova **WC Async Filters** e clicca **Attiva**

---

## Importare i prodotti di esempio

Nella cartella del plugin è incluso il file `wc-product-import.csv` con
42 prodotti fashion pronti all'uso, completi di categorie e attributi.

Questo file è pensato per testare subito il plugin con dati reali:
abbigliamento, scarpe e accessori di brand come Nike, Adidas, Zara, Gucci e altri.

Per importarli:

1. Vai su **wp-admin → Prodotti → Importa** (pulsante in alto a destra)
2. Carica il file `wc-fashion-import-v4.csv`
3. Nella schermata di mappatura, verifica che le colonne siano associate
   correttamente ai campi WooCommerce (di solito vengono riconosciute in automatico)
4. Clicca **Avvia importazione**

Al termine troverai 42 prodotti divisi in tre categorie principali:
**Abbigliamento**, **Scarpe** e **Accessori**, ognuna con le sue sottocategorie.

---

## Configurazione del plugin

Dopo aver attivato il plugin e importato i prodotti:

1. Vai su **wp-admin → WooCommerce → Filtri Asincroni**
2. Vedrai la lista di tutte le tassonomie disponibili per i prodotti
3. Abilita le tassonomie che vuoi usare come filtri spuntando le checkbox
4. Imposta l'ordine di visualizzazione con i campi numerici (0 = prima)
5. Imposta quanti prodotti mostrare per pagina prima del pulsante Load More
6. Clicca **Salva le modifiche**

### Tassonomie consigliate per i prodotti di esempio

Questo è l'ordine consigliato per testare il comportamento dei filtri dinamici:

| Ordine | Tassonomia         | Descrizione                                        |
| ------ | ------------------ | -------------------------------------------------- |
| 0      | Categoria prodotto | Naviga tra le categorie (Abbigliamento, Scarpe...) |
| 1      | Brand              | The North Face, Nike, Adidas, Zara...              |
| 2      | Colore             | Nero, Bianco, Blu...                               |
| 3      | Materiale          | Cotone, Pelle, Nylon...                            |
| 4      | Taglia             | XS, S, M, L, XL, XXL                               |
| 5      | Numero scarpa      | 38, 39, 40, 41, 42, 43, 44                         |
| 6      | Tipo suola         | Gomma, Cuoio, Vibram, EVA                          |
| 7      | Forma occhiali     | Aviator, Wayfarer, Rotondo, Cat-eye                |

Con questa configurazione puoi verificare il comportamento dei filtri dinamici:
seleziona **Brand = The North Face** e osserva come i filtri Taglia, Numero scarpa
e Forma occhiali spariscono, perché nessun prodotto The North Face ha quegli attributi.

---

## Come funziona la struttura degli URL

Gli URL seguono questa struttura:

```
/product-category/categoria-padre/categoria-figlio/?brand=valore&colore=valore
```

Esempio reale dopo aver selezionato Brand = Zara e navigato in Abbigliamento > Camicie:

```
/product-category/abbigliamento/camicie/?brand=zara&colore=bianco
```

Le categorie fanno parte del **percorso URL** (prima del `?`).
I filtri per attributi fanno parte dei **parametri GET** (dopo il `?`).

Nota: il prefisso `pa_` degli attributi WooCommerce viene rimosso dall'URL
per renderlo più leggibile. `pa_brand` diventa `brand`, `pa_colore` diventa `colore`.

---

## Struttura dei file del plugin

```
wc-async-filters/
├── wc-async-filters.php          ← File principale: presenta il plugin a WordPress
├── README.md                     ← Questo file
├── includes/
│   ├── class-plugin.php          ← Coordinatore: carica tutto e registra gli hook
│   ├── class-admin.php           ← Pagina di configurazione in wp-admin
│   ├── class-ajax-handler.php    ← Gestisce le richieste AJAX dal browser
│   └── class-query-builder.php   ← Costruisce le query al database con i filtri
├── templates/
│   └── shop.php                  ← Layout della pagina shop (sidebar + griglia)
├── assets/
│   ├── css/frontend.css          ← Stili: layout, overlay loading, responsive
│   └── js/frontend.js            ← Logica: filtri, AJAX, URL, Load More
└── languages/
    └── wc-async-filters.pot      ← Template per le traduzioni
```

---

## Note sulle scelte implementative

### Vanilla JavaScript invece di jQuery

Il file `frontend.js` usa JavaScript puro (Vanilla JS) invece di jQuery,
tranne per l'inizializzazione di Select2 che richiede jQuery internamente.
Questo approccio è più moderno, non aggiunge dipendenze extra, e sfrutta
le API native del browser che oggi sono supportate ovunque.

### La categoria prodotto come navigazione

La tassonomia `product_cat` (Categoria prodotto) non si comporta come
gli altri filtri: selezionando una categoria, l'URL cambia nel **percorso**
(pathname) invece che nei parametri GET. Questo è intenzionale per mantenere
URL semantici e breadcrumb corretti, coerentemente con la struttura permalink
richiesta nelle specifiche.

Questa scelta è frutto di una riflessione architetturale: in un progetto reale,
le categorie si navigano più naturalmente tramite menu o sidebar gerarchica,
lasciando i filtri ai soli attributi (brand, colore, taglia...). La scelta
implementata rispetta le specifiche della traccia, che cita esplicitamente
la categoria prodotto tra i filtri configurabili.

### Filtri dinamici dipendenti dal contesto

Dopo ogni selezione di un filtro, il server analizza i prodotti trovati
e restituisce la lista delle tassonomie che hanno almeno un termine associato
a quei prodotti. Il JavaScript nasconde tutti i filtri non presenti in quella lista.
Questo evita di mostrare filtri che non porterebbero ad alcun risultato.

### Select2 in modalità AJAX

Le dropdown dei filtri non caricano tutte le opzioni all'avvio, ma le richiedono
al server man mano che l'utente digita. Questo è più efficiente con cataloghi grandi
e permette la ricerca testuale all'interno dei termini di ogni filtro.

### Generare il file .pot per le traduzioni

Se vuoi tradurre il plugin in un'altra lingua, installa WP-CLI e dalla
cartella del plugin esegui:

```bash
wp i18n make-pot . languages/wc-async-filters.pot --domain=wc-async-filters
```

---

## Dipendenze esterne

| Libreria | Versione   | Caricamento  | Scopo                             |
| -------- | ---------- | ------------ | --------------------------------- |
| Select2  | 4.1.0-rc.0 | CDN jsDelivr | Dropdown con ricerca per i filtri |

---
