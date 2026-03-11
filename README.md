# SecurEvent

Plateforme de gestion et réservation de conférences, CTF et workshops autour de la cybersécurité.
Développée avec Symfony 8 (LTS) — projet ESDI.

## Prérequis

- PHP >= 8.4 avec les extensions `pdo_mysql`, `intl`, `mbstring`, `ctype`
- MySQL / MariaDB
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download) (optionnel)

## Installation

```bash
# 1. Cloner le dépôt
git clone <url-du-repo>
cd SecureEvents

# 2. Installer les dépendances
composer install

# 3. Configurer la base de données
cp .env .env.local
# Modifier DATABASE_URL dans .env.local, ex :
# DATABASE_URL="mysql://root:@127.0.0.1:3306/securevent?serverVersion=8.0&charset=utf8mb4"

# 4. Créer la base de données et appliquer les migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. Charger les fixtures (comptes de test + événements)
php bin/console doctrine:fixtures:load

# 6. Lancer le serveur
symfony serve
# ou : php -S localhost:8000 -t public/
```

## Comptes de test (après fixtures)

| Rôle  | Email                   | Mot de passe |
|-------|-------------------------|--------------|
| Admin | admin@securevent.fr     | Admin1234!   |
| User  | user@securevent.fr      | User1234!    |

## Fonctionnalités implémentées

### Front-office
- Page d'accueil : catalogue des événements publiés à venir
- Fiche détaillée d'un événement
- Inscription / désinscription à un événement (utilisateurs connectés)
- Tableau de bord utilisateur : mes inscriptions

### Authentification
- Inscription sécurisée (formulaire CSRF, mot de passe haché Argon2id)
- Connexion via formulaire (protection CSRF)
- Login throttling : blocage après 3 tentatives échouées (1 minute)
- Message d'erreur générique (pas d'énumération d'utilisateurs)

### Administration (`/admin` — ROLE_ADMIN requis)
- CRUD complet sur les événements
- Publication / dépublication
- Suppression sécurisée (POST + token CSRF)
- Liste des participants par événement

### API REST (`/api/events`)
- `GET /api/events` : liste des événements publiés à venir (JSON)

## Sécurité

- **SQL Injection** : ORM Doctrine uniquement, zéro SQL natif concaténé
- **XSS** : auto-échappement Twig sur toutes les vues
- **CSRF** : protection sur tous les formulaires et actions sensibles
- **Brute Force** : login throttling natif Symfony (3 tentatives / 1 min)
- **Contrôle d'accès** : `/admin` → ROLE_ADMIN, `/profile` → ROLE_USER
- **Mots de passe** : Argon2id (algorithme par défaut Symfony)

## PostMortem

### Difficultés rencontrées
- Configuration initiale du provider de sécurité (migration de `users_in_memory` vers le provider Doctrine)
- Installation de `symfony/rate-limiter` nécessaire pour le login throttling
- Gestion de l'unicité des emails lors de l'inscription (contrainte BDD + validation formulaire)

### Réussites
- Architecture MVC propre : contrôleurs fins, logique dans les entités/repositories
- API et front-office coexistent sans conflit de routes
- Protection CSRF systématique sur toutes les actions sensibles (suppression, inscription)
- Fixtures fonctionnelles avec comptes prêts à l'emploi

### Améliorations possibles
- Ajout de la gestion du profil utilisateur (modification email/mot de passe)
- Pagination du catalogue d'événements
- Envoi d'email de confirmation lors de l'inscription
- Tests automatisés (PHPUnit + WebTestCase)
- Déploiement Docker (docker-compose.yml)
