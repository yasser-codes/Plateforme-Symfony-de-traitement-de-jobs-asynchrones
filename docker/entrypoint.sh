#!/bin/sh

set -eu

log()
{
    printf '%s\n' "[entrypoint] $1"
}

APP_BOOTSTRAP="${APP_BOOTSTRAP:-0}"
DATABASE_HOST="${DATABASE_HOST:-db}"
DATABASE_PORT="${DATABASE_PORT:-5432}"
DATABASE_WAIT_TIMEOUT="${DATABASE_WAIT_TIMEOUT:-60}"

# ============================================================
# Les opérations de préparation sont exécutées uniquement
# dans le conteneur principal app.
#
# Elles ne seront pas répétées par tous les workers.
# ============================================================
if [ "$APP_BOOTSTRAP" = "1" ]; then
    log "Préparation de l'application Symfony..."

    # ========================================================
    # 1. INSTALLATION DES DÉPENDANCES COMPOSER
    # ========================================================
    if [ ! -f "vendor/autoload.php" ]; then
        log "Le dossier vendor est absent."

        if [ "${APP_ENV:-dev}" = "prod" ]; then
            composer install \
                --no-dev \
                --prefer-dist \
                --no-interaction \
                --no-progress \
                --optimize-autoloader
        else
            composer install \
                --prefer-dist \
                --no-interaction \
                --no-progress
        fi
    else
        log "Les dépendances Composer sont déjà installées."
    fi

    # ========================================================
    # 2. ATTENTE DE POSTGRESQL
    # ========================================================
    log "Attente de PostgreSQL sur ${DATABASE_HOST}:${DATABASE_PORT}..."

    elapsed=0

    until php -r "
        \$connection = @fsockopen(
            '${DATABASE_HOST}',
            ${DATABASE_PORT},
            \$errorCode,
            \$errorMessage,
            2
        );

        if (\$connection === false) {
            exit(1);
        }

        fclose(\$connection);
        exit(0);
    "; do
        elapsed=$((elapsed + 2))

        if [ "$elapsed" -ge "$DATABASE_WAIT_TIMEOUT" ]; then
            log "Erreur : PostgreSQL est inaccessible après ${DATABASE_WAIT_TIMEOUT} secondes."
            exit 1
        fi

        log "PostgreSQL n'est pas encore prêt. Nouvelle tentative dans 2 secondes..."
        sleep 2
    done

    log "PostgreSQL accepte les connexions."

    # ========================================================
    # 3. VÉRIFICATION DE SYMFONY
    # ========================================================
    if [ ! -f "bin/console" ]; then
        log "Erreur : le fichier bin/console est introuvable."
        exit 1
    fi

    # ========================================================
    # 4. CRÉATION DE LA BASE SI NÉCESSAIRE
    # ========================================================
    log "Vérification de la base de données..."

    php bin/console doctrine:database:create \
        --if-not-exists \
        --no-interaction

    # ========================================================
    # 5. EXÉCUTION DES MIGRATIONS
    # ========================================================
    log "Exécution des migrations Doctrine..."

    php bin/console doctrine:migrations:migrate \
        --no-interaction \
        --allow-no-migration

    # ========================================================
    # 6. PRÉPARATION DU CACHE SYMFONY
    # ========================================================
    log "Préparation des dossiers Symfony..."

    mkdir -p var/cache var/log

    chmod -R 777 var

    if [ "${APP_ENV:-dev}" = "prod" ]; then
        log "Nettoyage du cache Symfony de production..."

        rm -rf var/cache/*

        php bin/console cache:clear \
            --env=prod \
            --no-debug \
            --no-interaction \
            --no-warmup

        log "Préchauffage du cache Symfony de production..."

        php bin/console cache:warmup \
            --env=prod \
            --no-debug \
            --no-interaction
    else
        log "Mode développement : nettoyage automatique du cache ignoré."
        log "Symfony reconstruira le cache à la première requête."
    fi

    log "Préparation de Symfony terminée."
else
    log "Préparation Symfony désactivée pour ce conteneur."
fi

# ============================================================
# DÉMARRAGE DE LA COMMANDE PRINCIPALE DU CONTENEUR
# ============================================================
log "Démarrage de la commande : $*"

exec "$@"