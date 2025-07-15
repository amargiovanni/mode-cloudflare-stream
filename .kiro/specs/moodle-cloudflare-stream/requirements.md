# Requirements Document

## Introduction

Il plugin Moodle-Cloudflare Stream è un'estensione per Moodle che automatizza l'integrazione con Cloudflare Stream per la gestione e distribuzione di video-corsi. Il plugin permette il caricamento automatico dei video sulla CDN di Cloudflare e garantisce che lo streaming avvenga solo per utenti autenticati, migliorando le performance e la sicurezza della piattaforma e-learning.

## Requirements

### Requirement 1

**User Story:** Come amministratore di Moodle, voglio configurare l'integrazione con Cloudflare Stream attraverso un'interfaccia dedicata, così da poter gestire centralmente le credenziali e le impostazioni di connessione.

#### Acceptance Criteria

1. WHEN l'amministratore accede alle impostazioni del plugin THEN il sistema SHALL mostrare un'interfaccia di configurazione con campi per API Token, Account ID e Zone ID di Cloudflare
2. WHEN l'amministratore inserisce le credenziali THEN il sistema SHALL validare la connessione con l'API di Cloudflare
3. IF la connessione fallisce THEN il sistema SHALL mostrare un messaggio di errore specifico
4. WHEN le credenziali sono valide THEN il sistema SHALL salvare la configurazione in modo sicuro

### Requirement 2

**User Story:** Come docente, voglio caricare video-corsi che vengano automaticamente inviati a Cloudflare Stream, così da non dover gestire manualmente il processo di upload sulla CDN.

#### Acceptance Criteria

1. WHEN un docente carica un video attraverso l'interfaccia standard di Moodle THEN il sistema SHALL automaticamente inviare il file a Cloudflare Stream
2. WHEN l'upload a Cloudflare è completato THEN il sistema SHALL sostituire il file locale con un riferimento al video su Cloudflare Stream
3. IF l'upload a Cloudflare fallisce THEN il sistema SHALL mantenere il file locale e notificare l'errore
4. WHEN il video è processato da Cloudflare THEN il sistema SHALL aggiornare lo stato del video nel database di Moodle

### Requirement 3

**User Story:** Come studente autenticato, voglio visualizzare i video-corsi in streaming dalla CDN di Cloudflare, così da avere un'esperienza di visualizzazione fluida e veloce.

#### Acceptance Criteria

1. WHEN uno studente autenticato accede a un video-corso THEN il sistema SHALL generare un token di accesso temporaneo per Cloudflare Stream
2. WHEN il token è generato THEN il sistema SHALL incorporare il player di Cloudflare Stream nella pagina del corso
3. IF l'utente non è autenticato THEN il sistema SHALL negare l'accesso al video
4. WHEN il token scade THEN il sistema SHALL richiedere una nuova autenticazione per continuare la visualizzazione

### Requirement 4

**User Story:** Come amministratore di sistema, voglio monitorare l'utilizzo e lo stato dei video su Cloudflare Stream, così da poter gestire efficacemente le risorse e identificare eventuali problemi.

#### Acceptance Criteria

1. WHEN l'amministratore accede al pannello di monitoraggio THEN il sistema SHALL mostrare statistiche di utilizzo dei video
2. WHEN un video ha problemi di processing THEN il sistema SHALL notificare l'amministratore
3. WHEN l'amministratore richiede il report di utilizzo THEN il sistema SHALL generare un report dettagliato con metriche di visualizzazione
4. IF ci sono errori di sincronizzazione THEN il sistema SHALL fornire strumenti per la risoluzione manuale

### Requirement 5

**User Story:** Come docente, voglio gestire le impostazioni di privacy e accesso dei miei video-corsi, così da controllare chi può visualizzare i contenuti.

#### Acceptance Criteria

1. WHEN un docente configura un video-corso THEN il sistema SHALL permettere di impostare restrizioni di accesso basate su gruppi o ruoli
2. WHEN le restrizioni sono impostate THEN il sistema SHALL applicare le regole durante la generazione dei token di accesso
3. IF un utente non autorizzato tenta di accedere THEN il sistema SHALL negare l'accesso e registrare il tentativo
4. WHEN il docente modifica le impostazioni di privacy THEN il sistema SHALL aggiornare immediatamente le regole di accesso

### Requirement 6

**User Story:** Come amministratore di sistema, voglio che il plugin gestisca automaticamente la pulizia e l'ottimizzazione dello storage, così da mantenere efficiente l'utilizzo delle risorse.

#### Acceptance Criteria

1. WHEN un video è stato caricato con successo su Cloudflare THEN il sistema SHALL rimuovere automaticamente il file locale dopo un periodo di grazia configurabile
2. WHEN un video viene eliminato da Moodle THEN il sistema SHALL rimuovere anche il corrispondente video da Cloudflare Stream
3. IF ci sono video orfani su Cloudflare THEN il sistema SHALL fornire strumenti per identificarli e gestirli
4. WHEN viene eseguita la manutenzione programmata THEN il sistema SHALL sincronizzare lo stato dei video tra Moodle e Cloudflare