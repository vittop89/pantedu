# Immagine dell'applicazione: nginx + php-fpm + codice + asset costruiti.
#
# Perché un'immagine sola e non uno stack. Il guadagno che si cerca qui è
# tutto sul rilascio: scambio atomico, byte identici fra quello che si prova e
# quello che va in servizio, ritorno indietro riportando un'etichetta. Quei tre
# guadagni si incassano **per rilascio**, e in sei mesi l'applicazione è
# cambiata 863 volte contro le 8 del servizio TeX: containerizzare quest'ultimo
# comprerebbe lo stesso vantaggio a un centesimo del valore. Il database non
# cambia mai, e metterlo qui vorrebbe dire migrare dati veri di docenti — il
# punto dove un errore non è un disservizio ma un incidente da notificare.
#
# Restano quindi fuori, sull'host: MariaDB, i dati d'istanza (montati), il
# servizio TeX, la terminazione TLS e il proxy nginx che sta davanti.
#
# Perché Debian e non Alpine: la produzione è Debian con PHP 8.4. Un'immagine
# che gira su una libc diversa da quella dove il codice è sempre girato
# introduce una variabile nuova proprio nel passo che dovrebbe toglierne.
#
# Le quattro `FROM` sono fissate con il digest, non solo con il tag: un tag
# come `bookworm-slim` punta a un'immagine diversa ogni volta che viene
# ripubblicato, e una ricostruzione dal ripiego (cache persa, runner nuovo)
# può partire da una base diversa da quella provata, senza che niente lo dica.
# Il digest lo tiene aggiornato Dependabot (ecosistema `docker`, D-3); il
# tag resta accanto al digest solo perché un umano lo legga.

# ── 1. Le dipendenze PHP ──────────────────────────────────────────────────
FROM composer:2@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b AS dipendenze-php

WORKDIR /app
COPY composer.json composer.lock ./
# Scripts accesi: `pre-autoload-dump` (`Google\Task\Composer::cleanup`, da
# `extra.google/apiclient-services` in composer.json) toglie da qui i servizi
# Google diversi da Drive prima che l'autoloader si generi. Legge solo
# `composer.json` e `vendor/`, che a questo punto ci sono già: non serve il
# codice dell'applicazione, che arriva solo nello stadio finale (D-1).
#
# `--ignore-platform-req=ext-pdo_mysql`: l'immagine ufficiale di Composer non
# la compila (non le serve: qui si scaricano pacchetti, non ci si connette a
# un database). La estensione vera la installa lo stadio 4 (`runtime`), con
# `docker-php-ext-install`, prima che il codice giri davvero: la piattaforma
# dichiarata in composer.json (D-16) resta un controllo vero lì, sui
# `composer install` di sviluppo e di CI, che girano su un PHP con tutte le
# estensioni o falliscono per davvero.
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-req=ext-pdo_mysql

# ── 2. Gli asset del front-end ────────────────────────────────────────────
FROM node:22-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9 AS asset

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Serve tutto l'albero: `vite build` legge gli ingressi in js/entries/ e i
# fogli di stile in css/, e `prebuild` copia gli asset di terze parti.
COPY . .
RUN npm run build

# ── 3. L'editor drawio ────────────────────────────────────────────────────
# Le mappe si disegnano con drawio ospitato qui, sotto `/drawio-app/`. Non è
# versionato (145 MB, `.gitignore`), e fino al 15 settembre 2026 lo installava
# solo il rilascio, **sull'host** (tools/webhook/deploy.sh): nel container non
# c'è mai stato. Dall'8 settembre ogni «Apri editor drawio» apriva una pagina
# che non esiste, e nessuna prova lo guardava. Misurato sul container in
# servizio: `/drawio-app/js/app.min.js` 404.
#
# La versione e l'impronta stanno nello script: il file scaricato si verifica
# prima di estrarlo. Il livello resta in cache finché script e patch non
# cambiano, quindi non si scarica a ogni costruzione.
FROM debian:bookworm-slim@sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251 AS drawio

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl unzip; \
    rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY tools/install-drawio.sh tools/install-drawio.sh
COPY tools/drawio-patches tools/drawio-patches
RUN bash tools/install-drawio.sh \
    && test -s public/drawio-app/js/app.min.js \
    && grep -q 'pantedu-library-relay' public/drawio-app/index.html

# ── 4. L'immagine che gira ────────────────────────────────────────────────
FROM php:8.4-fpm-bookworm@sha256:0a5638c2c48eb04987107ae448a398dd18345bcf659e15fd99a422617e754264 AS runtime

# Le estensioni sono quelle che il codice usa davvero, non quelle che la
# macchina di sviluppo si è trovata addosso:
#   pdo_mysql — MariaDB
#   zip       — pacchetti delle verifiche (ZipArchive, 8 file)
#   opcache   — senza, PHP ricompila a ogni richiesta
# mbstring, curl, openssl, dom e libxml sono già nell'immagine ufficiale.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libzip-dev \
        ca-certificates \
        curl \
    ; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip opcache; \
    # `libzip-dev` NON si rimuove. Il primo tentativo faceva
    # `apt-get purge --auto-remove libzip-dev`, e con lui se ne andava
    # `libzip.so.4`, che all'estensione `zip` serve **mentre gira**, non solo
    # per compilarla: l'immagine si costruiva benissimo e poi ogni processo
    # PHP partiva con «Unable to load dynamic library 'zip'». Il risparmio
    # sarebbe stato di qualche centinaio di kilobyte su seicento megabyte.
    rm -rf /var/lib/apt/lists/* /var/log/nginx/*; \
    # nginx nell'immagine scrive i log su file: qui devono andare sullo
    # standard output, altrimenti finiscono in un livello che nessuno legge
    # e che a ogni rilascio si butta via.
    ln -sf /dev/stdout /var/log/nginx/access.log; \
    ln -sf /dev/stderr /var/log/nginx/error.log

COPY docker/php.ini /usr/local/etc/php/conf.d/pantedu.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pantedu.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/pantedu.conf
COPY docker/entrypoint.sh /usr/local/bin/pantedu-entrypoint
RUN chmod 755 /usr/local/bin/pantedu-entrypoint

WORKDIR /var/www/pantedu

# Il codice. `.dockerignore` tiene fuori tutto ciò che non serve a servire una
# richiesta: prove, documenti, wiki, strumenti di sviluppo.
COPY --chown=www-data:www-data . .
COPY --from=dipendenze-php --chown=www-data:www-data /app/vendor ./vendor
COPY --from=asset --chown=www-data:www-data /app/public/build ./public/build
# Anche `public/vendor`: MathJax, il suo font e `vendor-manifest.json` li genera
# `prebuild` in questo stadio (tools/build/vendor-assets.mjs) e non sono
# versionati. Fino al 14 settembre 2026 si copiava solo `public/build`, e
# l'immagine della produzione non aveva MathJax: le formule restavano scritte.
COPY --from=asset --chown=www-data:www-data /app/public/vendor ./public/vendor
# L'editor drawio (stadio 3). La radice di nginx è `public/`: si serve come
# ogni altro file che c'è, senza regole apposta.
COPY --from=drawio --chown=www-data:www-data /app/public/drawio-app ./public/drawio-app

# Il fascio dei fogli di stile: `css/main.bundle.css`, che non è versionato
# perché si genera al rilascio. Va fatto qui e non nello stadio degli asset
# perché lo costruisce PHP, non Node. Senza, le pagine ripiegano su `main.css`
# e ogni `@import` diventa una richiesta a sé: il sito funziona ma va piano, ed
# è il genere di regressione che nessuna prova vede.
RUN php tools/build-css-bundle.php \
    && test -s css/main.bundle.css \
    && chown www-data:www-data css/main.bundle.css

# ── Quale commit è questa immagine ────────────────────────────────────────
# `/version` legge `storage/version.txt`, e quel file finiva nell'immagine con
# dentro il commit dell'ULTIMO rilascio fatto sulla macchina di build: subito
# dopo il primo scambio, l'endpoint che deve dire cosa gira diceva un commit
# vecchio di quaranta minuti. Per un sistema di rilascio è il peggior posto in
# cui mentire.
#
# Adesso lo scrive il build, perché l'immagine **è** la versione: non c'è un
# momento in cui le due cose possono divergere.
ARG COMMIT=sconosciuto
RUN echo "$COMMIT" > storage/version.txt \
    && chown www-data:www-data storage/version.txt \
    && test -s storage/version.txt

# L'autoloader di Composer, generato con gli script nello stadio 1, è arrivato
# fin qui con `vendor/`: lo si controlla, non lo si rigenera.
RUN php -r "file_exists('vendor/autoload.php') || exit(1);"

# L'immagine è immutabile: tutto ciò che si scrive sta sotto PANTEDU_DATA_PATH,
# che è un montaggio. Il valore predefinito qui è volutamente un percorso che
# NON esiste nell'immagine, così un montaggio dimenticato si vede subito
# invece di scrivere in un livello che il prossimo rilascio butta via.
ENV PANTEDU_DATA_PATH=/var/lib/pantedu-data

# Chi controlla la salute passa da nginx e arriva fino a PHP e al database: un
# `200` sulla radice direbbe solo che nginx è vivo. È questo il controllo su
# cui lo scambio deve essere condizionato.
# `start-period` generoso: durante i controlli dell'entrypoint nginx non è
# ancora partito, quindi il controllo di salute fallisce per forza. Con venti
# secondi un container che aspetta un database lento veniva dichiarato malato
# mentre stava facendo il suo mestiere. L'attesa massima dell'entrypoint è di
# sessanta secondi: novanta lasciano margine senza rendere eterna la diagnosi
# di un container che davvero non parte.
HEALTHCHECK --interval=10s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS --max-time 4 http://127.0.0.1:8080/health || exit 1

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/pantedu-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/pantedu.conf"]
