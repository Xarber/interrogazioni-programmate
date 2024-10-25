# Interrogazioni Programmate
Questa è una semplice webpage per gestire le prenotazioni per le interrogazioni programmate in tutte quelle classi che non si sanno organizzare nei gruppi (no insult intended)

# Requirements
 - PHP
 - Qualsiasi web server (Custom node, NGinx, Apache), ovviamente compatibile con NGinx
 - > Un IP pubblico (possibilmente statico) o ancora meglio dominio a cui far accedere appunto gli utenti.

# Optional Requirements
 - php-zip ($ sudo apt-get install php-zip) - Scarica/Carica profili zip (Solo profili secondari)
 - WorkInProgress: NodeJS (+ web-push with npm i) - Notifiche push quando vengono aggiunte nuove interrogazioni in materie 

# Usage
Questa app non ha bisogno di nessuna configurazione esterna da parte dell'utente, se non l'installazione dei requirement elencati sopra.
Per creare il primo utente, basta semplicemente accedere all'interno della pagina interrogazioni.php una singola volta, e l'app chiederà di creare un utente. Questo utente avrà i permessi di admin, e sarà possibile modificare ogni configurazione direttamente dalla parte web (Dati utente -> Dashboard).

# TODO (Work In Progress)
 - Notifiche push
 - Pannello di gestione risposte (per admin, in modo da scambiare utenti per prenotazioni)
 - Titoli custom per le interrogazioni (Al posto di "Che giorno vuoi farti interrogare?" e "Sarai interrogato in data ...")
 - Più tipi di sezioni (Materia, Sondaggio e simili)