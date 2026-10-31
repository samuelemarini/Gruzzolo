<p align="center">
  <img src="app/assets/images/icon.svg" width="88" height="88" alt="">
</p>

<h1 align="center">Gruzzolo</h1>

<p align="center">
  Money manager personale e multi-utente, self-hosted.<br>
  Entrate, uscite, conti condivisi, report, consumi dei veicoli e investimenti, in un'app web che si installa sul telefono.
</p>

<p align="center">
  <img alt="PHP 8.4" src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white">
  <img alt="Symfony 8.1" src="https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white">
  <img alt="MariaDB" src="https://img.shields.io/badge/MariaDB-11.4-003545?logo=mariadb&logoColor=white">
  <img alt="Docker" src="https://img.shields.io/badge/Docker-compose-2496ED?logo=docker&logoColor=white">
  <img alt="Senza Node" src="https://img.shields.io/badge/build-niente%20Node-success">
</p>

<p align="center">
  <img src="docs/screenshots/mobile.webp" alt="Gruzzolo sul telefono: home, modifica di un movimento col tastierino, report e statistiche di un'auto">
</p>

## Cosa fa

- **Movimenti** — entrate, uscite e giroconti fra conti, con categorie e sottocategorie, etichette
  (`#viaggio`, `#festa`…), note e ricerca. Sul telefono l'importo si scrive col tastierino dell'app.
- **Conti** — corrente, risparmio, contanti; saldo sempre aggiornato e patrimonio complessivo.
  Un conto si può **condividere** con altri utenti (es. il conto di casa), con le sue categorie.
- **Report** — mese, anno o intervallo libero, su uno o più conti: spese per categoria, entrate e uscite
  degli ultimi mesi, media al giorno, spesa più alta, spese per etichetta e rendimento degli investimenti.
- **Veicoli** — rifornimenti con consumo da pieno a pieno, costo al km e al giorno, autonomia stimata;
  qualsiasi spesa (bollo, tagliando, pedaggi…) si collega al veicolo.
- **Investimenti** — versamenti, prelievi e valore di ogni titolo, rendimento e **stima del valore di
  oggi** dai prezzi di mercato.
- **Importazione** da Spendee, Wallet (BudgetBakers) e Money Manager, annullabile; **backup e
  ripristino** dell'intera istanza da un file.
- **Su misura** — tema chiaro, scuro o automatico, stile classico o *Liquid Glass*, colore d'accento e
  icona dell'app a scelta, importi nascosti con un tocco quando si è in pubblico.
- **PWA** — si aggiunge alla schermata Home e si usa come un'app, con trascina-giù per aggiornare.

## Schermate

**Desktop**

<p align="center">
  <img src="docs/screenshots/desktop-home.webp" alt="Home su desktop: saldo del mese, dove vanno i soldi, ultimi movimenti, conti e investimenti">
</p>

<details>
<summary>Report e veicoli su desktop</summary>
<br>
<p align="center">
  <img src="docs/screenshots/desktop-report.webp" alt="Report su desktop: saldo, spese per categoria, entrate e uscite degli ultimi mesi, spese per etichetta">
  <img src="docs/screenshots/desktop-veicolo.webp" alt="Pagina di un'auto: costo totale, distanza, costi mese per mese, confronto dei costi, contachilometri">
</p>
</details>

**Storico, investimenti, etichette e conti**

<p align="center">
  <img src="docs/screenshots/mobile-altro.webp" alt="Storico dei movimenti, investimenti con grafico, etichetta di un viaggio, patrimonio e conti">
</p>

**Tema scuro**

<p align="center">
  <img src="docs/screenshots/scuro.webp" alt="Home, report e veicolo nel tema scuro">
</p>

<sub>Le schermate usano i dati di esempio di `app:demo` e lo stile Liquid Glass.</sub>

## Provalo in locale

Serve solo Docker.

```bash
git clone https://github.com/samuelemarini/Gruzzolo.git && cd Gruzzolo
cp .env.example .env          # imposta DB_PASSWORD
echo "APP_SECRET=$(openssl rand -hex 16)" > app/.env.local && chmod 644 app/.env.local
docker compose up -d --build  # web su http://localhost:20029, MariaDB su 30029
docker compose exec -u application -w /app web composer install
docker compose exec -u application -w /app web php bin/console doctrine:migrations:migrate -n
# utente "demo" / "demo1234" con sei mesi di dati di esempio
docker compose exec -u application -w /app web php bin/console app:demo
```

Poi apri <http://localhost:20029> ed entra con `demo` / `demo1234`.

Altri comandi (sempre come utente `application`, altrimenti la cache diventa illeggibile per php-fpm):

```bash
# utente vero (chiede la password); --admin per poter creare altri utenti dall'app
docker compose exec -u application -w /app web php bin/console app:user:create mario "Mario" --admin
```

## Tecnologia

Symfony 8.1 + MariaDB, AssetMapper (niente Node né build), Turbo + Stimulus. I grafici sono SVG
disegnati lato server. Font (Geist, Bricolage Grotesque) e icone (Lucide) sono nel repository:
nessuna risorsa da CDN, nessun tracciamento.

## Produzione (Portainer, stack da Git)

Compose: `docker-compose.prod.yml`. Variabili dello stack:

| Variabile | |
|---|---|
| `DB_PASSWORD` | obbligatoria, solo lettere e numeri |
| `APP_SECRET` | obbligatoria, `openssl rand -hex 16` |
| `ADMIN_PASSWORD` | crea l'utente `admin` al primo avvio (poi cambiala dalle Impostazioni) |
| `TUNNEL_TOKEN` | obbligatoria, token del tunnel Cloudflare (Zero Trust › Networks › Tunnels) |
| `WEB_PORT`, `DB_PORT`, `DB_BIND` | facoltative, default 8080 / 3308 / 127.0.0.1 |

All'avvio il container esegue le migration (`docker/entrypoint.d/60-gruzzolo.sh`). Il codice è
dentro l'immagine: dopo un aggiornamento del repository serve "Pull and redeploy" con rebuild.
Il sito esce su Internet dal tunnel Cloudflare (servizio `cloudflared`): nel *Public Hostname*
del tunnel il servizio è `http://web:80`, nessuna porta da aprire sul router. Gli header
`X-Forwarded-*` del tunnel sono accettati (`TRUSTED_PROXIES=private_ranges`), altrimenti il login
fallirebbe per il controllo CSRF.

Backup: il volume `db_data`, `mariadb-dump` dal container `database`, oppure il backup dell'app
(Altro › Backup, vedi sotto). Per portare i dati da un'istanza all'altra (es. sviluppo → produzione):
"Scarica il backup" da una, "Ripristina" sull'altra.

## Come ragiona l'app

- **Movimenti**: importo sempre positivo, il tipo dà il segno. I **giroconti** spostano soldi fra
  conti (o verso un investimento). Nei totali (Home, Movimenti, Report) contano dal punto di vista dei
  conti considerati — quelli filtrati, quelli mostrati in home o tutti: se escono sono uscite (fetta
  "Giroconti"), se arrivano da fuori entrate, fra due conti considerati non contano. Stessa regola in
  `ReportService` (SQL) e `MovementRepository::totals()`.
- **Conti**: saldo = saldo iniziale + entrate − uscite ± giroconti. Il conto accantonamento si
  alimenta con giroconti e le spese annuali si pagano direttamente da lì.
- **Rifornimenti**: categoria con l'opzione "Carburante" (di default *Trasporti › Carburante*).
  Dei tre valori prezzo/litri/totale bastano due. Il consumo si calcola da pieno a pieno
  (rabbocchi parziali compresi); "Ho saltato un rifornimento" spezza la catena.
  Qualsiasi spesa può essere collegata a un veicolo per avere il costo totale al km.
  La pagina del veicolo ha le statistiche alla Drivvo in tre schede (Generale, Rifornimenti, Spese):
  costo al giorno e al km, volumi, prezzo e consumo minimo/massimo, km e giorni fra un rifornimento
  e l'altro, autonomia stimata (serve la capacità del serbatoio), indicatori dell'ultimo pieno e grafici
  mensili. Il costo al km di ogni pieno usa il prezzo alla pompa, non l'importo pagato.
- **Investimenti**: per ogni titolo si registrano versamenti/prelievi e il valore del momento.
  Rendimento = ultimo valore − somma dei versamenti. Un versamento può uscire da un conto
  (giroconto, non spesa).
  **Valore stimato oggi**: fra un aggiornamento e l'altro l'ultimo valore registrato si moltiplica per
  la variazione del prezzo di mercato da quel giorno (`MarketPrices` su Yahoo Finance, gratuito e non
  ufficiale; prezzi in valuta convertiti in euro; cache 20 minuti). Il simbolo si indica sul titolo
  (es. SWDA.MI, BTC-EUR) o si cerca dall'ISIN. Se il servizio non risponde resta l'ultimo valore registrato.
- **Trascina giù per aggiornare** (touch, `assets/pull_refresh.js`): dalla cima della pagina ricarica
  con Turbo. Spento nei form (`form.form`), nei pannelli `<dialog>` e su `[data-no-pull]`; il
  pull-to-refresh nativo del browser è disattivato da `overscroll-behavior-y` per non ricaricare due volte.
- **Visualizza nella home**: ogni utente sceglie quali conti entrano nei totali della home (tabella
  `account_home_hidden`): un conto escluso resta nell'elenco Conti col suo saldo, ma i suoi movimenti
  restano fuori da totali del mese, "Dove vanno i soldi" e ultimi movimenti. Il proprietario lo imposta
  dalla modifica del conto, chi l'ha ricevuto in condivisione dalla pagina del conto.
- **Conti in Movimenti e Report**: di default solo il conto principale (`?account[]=…` per sceglierne
  altri, `?account=all` per tutti; `MovementFilter::accountsFrom()`). I link dalla home portano gli stessi
  conti dei totali della home.
- **Patrimonio** (pagina Conti): saldo dei conti più il valore attuale degli investimenti; dai chip si
  sceglie cosa contare (es. togliere il conto in comune). La scelta è per utente (`app_user.net_worth_excluded`,
  voci escluse: id dei conti o "inv"), così un conto nuovo entra da solo.
- **Investimenti nel Report**: il chip "Investimenti" li aggiunge come un conto (`?account[]=inv`, anche
  insieme ad altri conti o a `all`): versamenti nei titoli = entrate, prelievi = uscite, una voce per titolo;
  un versamento partito da un conto considerato è uno spostamento interno. La card "Rendimento del periodo"
  mostra valore a inizio e fine periodo, versato e rendimento (`ReportService::investmentPeriod()`).
- **Categorie per conto**: ogni conto ha le sue categorie (`category.account_id`); chi condivide il
  conto usa e gestisce le stesse. Nel nuovo movimento le categorie proposte cambiano col conto scelto.
  Un conto nuovo può partire dalle categorie di un altro conto, da quelle predefinite o vuoto; dalla
  pagina Categorie si possono copiare in qualsiasi momento quelle di un altro conto (senza doppioni).
- **Importazione CSV** (Altro › Importa): riconosce gli export di **Spendee**, **Wallet (BudgetBakers)** e **Money Manager**
  (le celle non UTF-8 si leggono come Windows-1252 o Mac Roman).
  Il file si parcheggia in `app/var/imports`, l'anteprima propone gli abbinamenti (wallet → conti,
  categorie → categorie con lo stesso nome nel conto di destinazione, altrimenti nuove create in quel
  conto; le etichette mancanti si creano), poi si importa. Ogni riga ha
  un'impronta (`movement.import_hash`): reimportando lo stesso file i doppioni vengono saltati. Ogni
  importazione è annullabile e toglie anche categorie, conti ed etichette creati apposta se inutilizzati.
  Per un nuovo formato basta una classe che implementa `App\Import\ImportFormat` (si registra da sola).
  Da terminale, per conto di un utente (i movimenti risultano suoi):
  `php bin/console app:import <utente> <file.csv> --account=<id> [--dry-run]`, e `--undo=<id>` per annullare.
- **Backup e ripristino** (Altro › Backup, solo admin; `BackupService`): un file JSON con tutte le tabelle
  del database (lette da `information_schema`, quindi una tabella nuova entra da sola), utenti e password
  cifrate compresi, e l'elenco delle migration. Il ripristino **sostituisce tutto**: anteprima con le righe
  del file e quelle attuali, conferma scrivendo `RIPRISTINA`, poi in una transazione svuota le tabelle e
  reinserisce le righe con i loro id (vincoli spenti, riferimenti controllati prima del commit: se qualcosa
  non torna resta tutto com'era). Rifiuta backup di una versione più recente dell'app, colonne sconosciute e
  file senza un admin attivo; alla fine fa logout. Accetta anche `.json.gz`. Da terminale:
  `php bin/console app:backup:export [file.json|file.json.gz|-]` e `app:backup:restore <file> [--force]`.
- **Stile Liquid Glass** (Impostazioni › Stile, `app_user.style`): superfici di vetro traslucido alla iOS 26/27
  sopra il tema chiaro o scuro. Tutto in `assets/styles/glass.css`, scoperto da `html[data-style="glass"]`:
  blur solo sulle superfici di primo livello (card, barre, bottoni), quelle interne sono solo velate; con
  `prefers-reduced-transparency` o senza `backdrop-filter` il vetro diventa quasi opaco.
- **Importi nascosti**: l'occhio (Home, Movimenti, Report, Conti, Investimenti, barra laterale) sfoca
  gli importi su quel dispositivo (`localStorage`). `assets/privacy.js` riconosce gli importi dal formato
  ("1.247,80 €") e li avvolge in `.money`; i saldi grandi sono `.hero-amount__value`.
- **Multi-utente**: ogni tabella ha l'utente proprietario; i controller usano
  `AppController::own()`, che risponde 404 sui dati altrui.
- **Conti condivisi**: il proprietario condivide un conto con altri utenti (tabella `account_member`,
  dalla modifica del conto). I membri vedono saldo e movimenti e ne aggiungono con le
  categorie del conto; `movement.user` resta l'autore. Visibilità dei movimenti = conto accessibile
  (`AccountRepository::ACCESSIBLE_DQL/SQL`, `AppController::accessibleMovement()`); nei report le
  categorie con lo stesso nome dei due utenti si sommano. Modifica ed eliminazione del conto solo al proprietario.

## Struttura

- `src/Service/ReportService.php` aggregati SQL per Home e Report
- `src/Service/VehicleStats.php` consumi e costi dei veicoli
- `src/Service/InvestmentStats.php` rendimento dei titoli e del portafoglio
- `src/Service/ChartBuilder.php` geometria dei grafici SVG (disegnati lato server)
- `assets/styles/app.css` design system (token chiaro/scuro + componenti)
- `assets/controllers/` Stimulus: form movimento, tastierino, rifornimento, etichette, sheet
- `resources/icons/` icone Lucide usate (`{{ icon('nome') }}` in Twig)

## Crediti

Icone [Lucide](https://lucide.dev) (ISC, vedi `app/resources/icons/LICENSE-lucide`), font
[Geist](https://vercel.com/font) e [Bricolage Grotesque](https://github.com/ateliertriay/bricolage)
(SIL Open Font License). Prezzi di mercato da Yahoo Finance (servizio non ufficiale).
