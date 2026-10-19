# Gruzzolo

Money manager personale e multi-utente: entrate, uscite, giroconti fra conti, categorie con
sottocategorie, etichette (#viaggio, #festa…), report, rifornimenti con consumi dei veicoli
e portafoglio investimenti con rendimento.

Symfony 8.1 + MariaDB, AssetMapper (niente Node), Turbo + Stimulus. Font (Geist, Bricolage
Grotesque) e icone (Lucide) sono nel repository: nessuna risorsa da CDN. Tema chiaro, scuro o
automatico. Installabile sul telefono come app (manifest PWA).

## Sviluppo

```bash
cp .env.example .env          # imposta DB_PASSWORD
docker compose up -d --build  # web su http://localhost:20029, MariaDB su 30029
docker compose exec -u application -w /app web composer install
docker compose exec -u application -w /app web php bin/console doctrine:migrations:migrate -n
```

In `app/.env.local` serve `APP_SECRET=...` (`openssl rand -hex 16`), con permessi 644.

Comandi utili (sempre come utente `application`, altrimenti la cache diventa illeggibile per php-fpm):

```bash
# utente vero (chiede la password); --admin per poter creare altri utenti dall'app
docker compose exec -u application -w /app web php bin/console app:user:create mario "Mario" --admin
# utente "demo" / "demo1234" con sei mesi di dati finti (lo ricrea da zero ogni volta)
docker compose exec -u application -w /app web php bin/console app:demo
```

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

Backup: il volume `db_data`, oppure `mariadb-dump` dal container `database`.

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
