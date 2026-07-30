# Plateforme Symfony de traitement de jobs asynchrones

Projet du module **Technologie Web avancée** réalisé avec **Symfony 7**, **PHP 8.3**, **Docker Compose**, **RabbitMQ/AMQP**, **PostgreSQL**, **Redis** et plusieurs workers spécialisés.

## 1. Objectif

L’application permet de soumettre des tâches lourdes sous forme de jobs, de les placer dans des files RabbitMQ, de les traiter de manière asynchrone, de suivre leur état, de consulter leur journal d’exécution et de relancer les jobs échoués.

Exemples de jobs :

- génération de rapports ;
- campagnes d’e-mails ;
- traitement d’images ;
- tâches métier longues ou prioritaires.

## 2. Architecture

Les services Docker principaux sont :

| Service | Rôle |
|---|---|
| `app` | Application Symfony exécutée avec PHP-FPM |
| `nginx` | Reverse proxy HTTP |
| `db` | PostgreSQL 16 |
| `redis` | Cache et rate limiting |
| `rabbitmq` | Broker AMQP et interface de gestion |
| `worker-1` | Traitement des jobs normaux |
| `worker-2` | Traitement des jobs prioritaires |
| `worker-dead-letter` | Gestion et persistance des échecs définitifs |

Les messages sont routés vers les files :

- `jobs.normal`
- `jobs.priority`
- `jobs.dead_letter`

## 3. Fonctionnalités principales

- création et suivi des jobs ;
- validation stricte des données avec Symfony Validator ;
- sérialisation JSON avec Symfony Serializer ;
- persistance avec Doctrine ORM et PostgreSQL ;
- traitement asynchrone avec Symfony Messenger et RabbitMQ ;
- routage normal, prioritaire et Dead Letter ;
- retries automatiques et retry manuel ;
- audit métier avec `JobLog` ;
- persistance des échecs avec `FailedJob` ;
- cache Redis ;
- limitation du nombre de requêtes par IP ;
- endpoint de santé `/health` ;
- endpoint de métriques `/metrics` ;
- logs structurés avec Monolog ;
- scaling horizontal des workers ;
- tests unitaires, intégration, fonctionnels, E2E et AMQP ;
- analyse statique et qualité du code.

## 4. Prérequis

- Docker Desktop ;
- Docker Compose v2 ;
- Git ;
- PowerShell sous Windows ;
- ports 80, 5432, 6379, 5672 et 15672 disponibles.

PHP et Composer ne sont pas obligatoires sur la machine hôte, car ils sont exécutés dans le conteneur `app`.

## 5. Installation

Cloner le dépôt :

```powershell
git clone <URL_DU_DEPOT>
cd symfony-amqp-platform
```

Créer le fichier d’environnement à partir du modèle :

```powershell
Copy-Item .env.example .env
```

Ne jamais déposer de secrets réels dans Git.

Construire et démarrer l’environnement :

```powershell
docker compose build
docker compose up -d
```

Vérifier les conteneurs :

```powershell
docker compose ps
```

L’application est accessible sur :

```text
http://localhost
```

Interface RabbitMQ :

```text
http://localhost:15672
```

## 6. Initialisation Symfony

L’entrypoint attend PostgreSQL, crée la base si nécessaire, exécute les migrations Doctrine puis démarre PHP-FPM.

Commandes manuelles utiles :

```powershell
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console messenger:setup-transports
```

## 7. API REST

| Méthode | Endpoint | Description |
|---|---|---|
| `POST` | `/api/jobs` | Soumettre un job |
| `GET` | `/api/jobs` | Lister et filtrer les jobs |
| `GET` | `/api/jobs/{id}` | Consulter un job |
| `GET` | `/api/jobs/{id}/logs` | Consulter son historique |
| `POST` | `/api/jobs/{id}/retry` | Relancer un job échoué |
| `DELETE` | `/api/jobs/{id}` | Supprimer ou annuler un job autorisé |
| `GET` | `/api/stats` | Consulter les statistiques |
| `GET` | `/health` | Vérifier la santé des composants |
| `GET` | `/metrics` | Exposer les métriques |

Exemple de création :

```powershell
$body = @{
    type = "report"
    payload = @{
        title = "Rapport mensuel"
    }
    priority = 0
} | ConvertTo-Json -Depth 5

Invoke-RestMethod `
  -Uri "http://localhost/api/jobs" `
  -Method Post `
  -ContentType "application/json" `
  -Body $body
```

Job prioritaire :

```json
{
  "type": "report",
  "payload": {
    "title": "Traitement urgent"
  },
  "priority": 10
}
```

## 8. Tests

Exécuter toute la suite :

```powershell
docker compose exec app php bin/phpunit
```

Suites séparées :

```powershell
docker compose exec app php bin/phpunit --testsuite Unit
docker compose exec app php bin/phpunit --testsuite Integration
docker compose exec app php bin/phpunit --testsuite Functional
docker compose exec app php bin/phpunit --testsuite E2E
docker compose exec app php bin/phpunit --testsuite AMQP
```

Qualité :

```powershell
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec app vendor/bin/rector process --dry-run
```

Le fichier `Verification-Avant-GitHub.ps1` automatise les contrôles essentiels avant le push.

## 9. Scaling

Exemple de scaling manuel :

```powershell
docker compose up -d --scale worker-1=3 --scale worker-2=2
docker compose ps
```

Retour à une instance :

```powershell
docker compose up -d --scale worker-1=1 --scale worker-2=1
```

## 10. Arrêt

```powershell
docker compose down
```

Pour supprimer aussi les volumes de développement :

```powershell
docker compose down -v
```

Attention : la seconde commande supprime les données PostgreSQL, Redis et RabbitMQ.

## 11. Structure indicative

```text
symfony-amqp-platform/
├── app/
│   ├── config/
│   ├── migrations/
│   ├── src/
│   ├── tests/
│   ├── composer.json
│   └── phpunit.dist.xml
├── docker/
├── scripts/
├── .github/workflows/
├── docker-compose.yml
├── docker-compose.override.yml
├── .env.example
├── .gitignore
└── README.md
```

## 12. Sécurité Git

Avant chaque push :

```powershell
git status
git diff
git ls-files | Select-String "\.env$|vendor|var/cache|var/log"
```

Les éléments suivants ne doivent jamais être versionnés :

- `.env` et secrets réels ;
- mots de passe et clés privées ;
- dossier `vendor/` ;
- caches et logs Symfony ;
- données des volumes Docker ;
- fichiers personnels de l’IDE.

## 13. Auteur

**EL KHANCHOUF YASSER**  

