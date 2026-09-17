# Interrogazioni Programmate

Applicazione PHP per organizzare interrogazioni e votazioni con il percorso **Materia → Giorno/opzione → Fatto**. Supporta classi isolate, amministratori separati, notifiche Web Push, link di accesso diretti, calendario, utenti prioritari e aperture programmate.

## Requisiti

- PHP 8.1 o successivo con `pdo_sqlite`, `sodium` e `json`.
- `zip` per importare ed esportare classi.
- nginx o un altro web server con PHP-FPM.
- Node.js 18 o successivo e `npm install` per il gateway Web Push.
- Un timer systemd (o equivalente) che esegua `bin/automation-worker.php` una volta al minuto.

## Archiviazione sicura

I dati applicativi non vengono più letti dai file JSON nel document root. Il registro globale e ogni classe vivono in database SQLite distinti fuori dalla cartella pubblicata da nginx. Nomi, codici di accesso, utenti, materie, risposte, abbonamenti Push e stato dell'automazione sono cifrati con XChaCha20-Poly1305 prima di essere scritti.

Configurare queste variabili nell'ambiente PHP-FPM, CLI e Node:

```text
SCUOLA_STORAGE_REGISTRY_DB=/var/lib/scuola/registry.sqlite3
SCUOLA_CLASS_STORAGE_DIR=/var/lib/scuola/classes
SCUOLA_STORAGE_KEY_FILE=/etc/scuola/storage.key
SCUOLA_LEGACY_ARCHIVE_DIR=/var/lib/scuola/legacy-archive
SCUOLA_ALLOW_CLASS_CREATION=1
SCUOLA_AUTOMATION_LOCK=/var/lib/scuola/automation.lock
SCUOLA_PUSH_URL=http://127.0.0.1:5743
SCUOLA_VAPID_KEY_FILE=/etc/scuola/vapidkeys.json
SCUOLA_PUSH_SUBSCRIPTIONS_FILE=/var/lib/scuola/push-subscriptions.json
HOST=127.0.0.1
PORT=5743
```

Il file [`deploy/scuola.env.example`](deploy/scuola.env.example) contiene lo stesso modello. Generare la chiave una sola volta, fuori dal document root:

```sh
php bin/storage.php generate-key /etc/scuola/storage.key
```

La chiave deve essere leggibile dall'utente PHP e non va mai inserita in Git, nei backup pubblici o nel database. La perdita della chiave rende i dati cifrati irrecuperabili.

## Migrazione automatica

All'avvio, l'app cerca `JSON` e `JSON-*` nella propria cartella. Ogni directory diventa una classe isolata. Prima di spostare i sorgenti, la migrazione:

1. blocca le migrazioni concorrenti;
2. valida tutti i JSON e rifiuta link o file inattesi;
3. importa e cifra i dati;
4. rilegge e confronta utenti, materie e risposte;
5. sposta le cartelle verificate in `SCUOLA_LEGACY_ARCHIVE_DIR`.

Se i JSON ricompaiono dopo un rollback al vecchio ramo, vengono considerati autorevoli e reimportati. Per verificare o ricreare un layout compatibile con il vecchio ramo:

```sh
php bin/storage.php verify
php bin/storage.php export /percorso/nuovo-export
```

La configurazione nginx in [`deploy/nginx/secure-runtime-deny.conf`](deploy/nginx/secure-runtime-deny.conf) impedisce il download diretto dei vecchi JSON e delle chiavi VAPID anche durante un rollback.

## Classi e accesso

- Un codice presente in una sola classe entra direttamente.
- Un codice condiviso tra più classi mostra il selettore della classe.
- I nuovi link usano `?UID=...&class=...`; i vecchi link `?UID=...&profile=...` restano compatibili.
- Il browser salva codice e ultima classe solo dopo che il server conferma l'appartenenza.
- Chi non ha un codice può creare una nuova classe: riceve un codice casuale, diventa il primo admin e non ottiene accesso ad altre classi.
- `SCUOLA_ALLOW_CLASS_CREATION=0` disabilita soltanto la creazione anonima in caso di necessità.

## Priorità e automazione

L'admin può contrassegnare singoli utenti come prioritari. Per ogni materia può impostare data/ora di apertura, preavviso, durata massima della fase prioritaria, intervalli dei promemoria e notifiche facoltative al coordinatore.

Una campagna resta bloccata fino all'apertura. All'orario previsto gli utenti prioritari scelgono per primi; gli altri entrano quando tutti i prioritari hanno risposto oppure alla scadenza della finestra. Gli account spettatore e gli esclusi non bloccano il completamento. Nascondere una materia prevale su ogni stato; bloccarla sospende il voto senza cancellare le risposte.

Installare i modelli in [`deploy/systemd`](deploy/systemd) sostituendo `@APP_ROOT@`, `@PHP_BINARY@` e `@NODE_BINARY@`, poi abilitare `scuola-push.service` e `scuola-automation.timer`. Il gateway Push accetta solo una chiave VAPID esistente: durante la migrazione va copiato il file originale, così gli abbonamenti attuali restano validi.

## Verifica

Controlli JavaScript:

```sh
npm run check:js
```

Test PHP, inclusi cifratura, migrazione, isolamento delle classi, priorità, promemoria e scadenze:

```sh
php tests/run.php
```

I test usano soltanto directory temporanee e dati sintetici.

## Backup e rollback

Prima della prima attivazione creare, fuori dal document root, un archivio con checksum e una copia immediatamente ripristinabile di tutti i `JSON`/`JSON-*`, della chiave VAPID, della configurazione nginx e dei servizi. Conservare anche commit e stato Git del deployment.

Per tornare al ramo `main`: fermare brevemente le scritture, disabilitare il timer, ripristinare i JSON e la chiave VAPID dalla copia verificata, cambiare ramo e riavviare solo i servizi coinvolti. Il deny nginx può restare attivo: impedisce l'accesso HTTP ma non la lettura server-side dei file da parte del vecchio PHP.
