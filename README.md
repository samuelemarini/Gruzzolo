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
| `WEB_PORT`, `DB_PORT`, `DB_BIND` | facoltative, default 8080 / 3308 / 127.0.0.1 |

All'avvio il container esegue le migration (`docker/entrypoint.d/60-gruzzolo.sh`). Il codice è
dentro l'immagine: dopo un aggiornamento del repository serve "Pull and redeploy" con rebuild.
Dietro un reverse proxy HTTPS va bene così (`TRUSTED_PROXIES=private_ranges`).

Backup: il volume `db_data`, oppure `mariadb-dump` dal container `database`.

## Come ragiona l'app

- **Movimenti**: importo sempre positivo, il tipo dà il segno. I **giroconti** spostano soldi fra
  conti (o verso un investimento) e non contano nei report di entrate e uscite.
- **Conti**: saldo = saldo iniziale + entrate − uscite ± giroconti. Il conto accantonamento si
  alimenta con giroconti e le spese annuali si pagano direttamente da lì.
- **Rifornimenti**: categoria con l'opzione "Carburante" (di default *Trasporti › Carburante*).
  Dei tre valori prezzo/litri/totale bastano due. Il consumo si calcola da pieno a pieno
  (rabbocchi parziali compresi); "Ho saltato un rifornimento" spezza la catena.
  Qualsiasi spesa può essere collegata a un veicolo per avere il costo totale al km.
- **Investimenti**: per ogni titolo si registrano versamenti/prelievi e il valore del momento.
  Rendimento = ultimo valore − somma dei versamenti. Un versamento può uscire da un conto
  (giroconto, non spesa).
- **Importazione CSV** (Altro › Importa): oggi riconosce l'export di **Spendee**. Il file si parcheggia
  in `app/var/imports`, l'anteprima propone gli abbinamenti (wallet → conti, categorie → categorie con lo
  stesso nome, altrimenti nuove; le etichette mancanti si creano), poi si importa. Ogni riga ha
  un'impronta (`movement.import_hash`): reimportando lo stesso file i doppioni vengono saltati. Ogni
  importazione è annullabile e toglie anche categorie, conti ed etichette creati apposta se inutilizzati.
  Per un nuovo formato basta una classe che implementa `App\Import\ImportFormat` (si registra da sola).
- **Importi nascosti**: l'occhio (Home, Movimenti, Report, Conti, Investimenti, barra laterale) sfoca
  gli importi su quel dispositivo (`localStorage`). `assets/privacy.js` riconosce gli importi dal formato
  ("1.247,80 €") e li avvolge in `.money`; i saldi grandi sono `.hero-amount__value`.
- **Multi-utente**: ogni tabella ha l'utente proprietario; i controller usano
  `AppController::own()`, che risponde 404 sui dati altrui.

## Struttura

- `src/Service/ReportService.php` aggregati SQL per Home e Report
- `src/Service/VehicleStats.php` consumi e costi dei veicoli
- `src/Service/InvestmentStats.php` rendimento dei titoli e del portafoglio
- `src/Service/ChartBuilder.php` geometria dei grafici SVG (disegnati lato server)
- `assets/styles/app.css` design system (token chiaro/scuro + componenti)
- `assets/controllers/` Stimulus: form movimento, tastierino, rifornimento, etichette, sheet
- `resources/icons/` icone Lucide usate (`{{ icon('nome') }}` in Twig)
