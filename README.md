# 🚀 FacTo — Application de gestion d'entreprise

> Application complète de facturation, gestion de stock, clients, devis, paiements et rapports — construite avec PHP 8.x, MySQL et Bootstrap 5.

[![PHP](https://img.shields.io/badge/PHP-8.x-blue?logo=php)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.x-blue?logo=mysql)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-purple?logo=bootstrap)](https://getbootstrap.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## 📋 Table des matières

- [✨ Fonctionnalités](#-fonctionnalités)
- [🛠️ Stack technique](#️-stack-technique)
- [📁 Architecture](#-architecture)
- [⚡ Installation rapide](#-installation-rapide)
- [🔧 Configuration](#-configuration)
- [🔐 Authentification](#-authentification)
- [📊 Modules](#-modules)
- [🐳 Docker](#-docker)
- [🔒 Sécurité](#-sécurité)
- [🚀 Déploiement](#-déploiement)
- [📝 Contribuer](#-contribuer)
- [📄 Licence](#-licence)

---

## ✨ Fonctionnalités

### Gestion commerciale
- ✅ **Clients** — CRUD complet avec coordonnées complètes
- ✅ **Factures** — CRUD + lignes d'articles + statuts + paiements + quota par plan
- ✅ **Devis** — CRUD + lignes d'articles + statuts + validité
- ✅ **Paiements** — Enregistrement et suivi par facture
- ✅ **Devis → Facture** — Conversion rapide

### Gestion des produits
- ✅ **Produits** — CRUD avec prix, catégorie, SKU, stock
- ✅ **Stock** — Entrées de stock, seuils d'alerte, statuts
- ✅ **Fournisseurs** — CRUD complet

### Finance & Rapports
- ✅ **Dépenses** — Suivi par catégorie avec reçu
- ✅ **Rapports** — Chiffre d'affaires, dépenses, statut des factures, méthodes de paiement
- ✅ **Dashboard** — Tableau de bord avec graphiques en temps réel

### Administration
- ✅ **Profil utilisateur** — Photo, informations, suppression de compte
- ✅ **Administration** — Tableau de bord admin avec données réelles
- ✅ **Notifications** — Centre de notifications
- ✅ **Support** — Centre d'assistance avec FAQ et tickets

### Intégrations
- ✅ **FedaPay** — Paiements en ligne (XOF/FCFA, Afrique de l'Ouest)
- ✅ **Impression** — Factures et devis imprimables en PDF via le navigateur
- ✅ **Email** — Notifications par email

---

## 🛠️ Stack technique

| Couche | Technologie |
|--------|-------------|
| **Backend** | PHP 8.x, PDO MySQL |
| **Frontend** | HTML5, CSS3, Bootstrap 5.3, Chart.js |
| **Auth** | Sessions PHP, CSRF tokens (24 h) |
| **PDF** | HTML → Print PDF (navigateur) |
| **Email** | PHP mail() avec mode debug |
| **DB** | MySQL 8.x / MariaDB |
| **Dev** | WAMP / Docker Compose |

---

## 📁 Architecture

```
FacTo/
├── index.html                  # Page d'accueil publique
├── connexion.html              # Connexion utilisateur
├── inscription.html            # Inscription utilisateur
├── mot-de-passe-oublie.html    # Mot de passe oublié
├── reinitialiser-mot-de-passe.html  # Réinitialisation du mot de passe
├── paiement-return.html        # Retour de paiement FedaPay
├── dashboard.html              # Tableau de bord
│
├── clients.html                # Gestion des clients
├── factures.html               # Gestion des factures
├── devis.html                  # Gestion des devis
├── produits.html               # Gestion des produits
├── stock.html                  # Gestion du stock
├── fournisseurs.html           # Gestion des fournisseurs
├── paiements.html              # Gestion des paiements
├── depenses.html               # Gestion des dépenses
├── rapports.html               # Rapports analytiques
│
├── administration.html         # Admin dashboard
├── profile.html                # Profil utilisateur
├── notifications.html          # Notifications
├── support.html                # Centre de support
├── mon-abonnement.html         # Mon abonnement
├── choix-abonnement.html       # Choix d'abonnement
├── parametres.html             # Paramètres
│
├── styles/                     # Fichiers CSS
│   ├── global.css              # Reset + design system + base
│   ├── dashboard.css
│   ├── clients.css
│   ├── invoices.css
│   ├── devis.css
│   ├── produits.css
│   ├── stock.css
│   ├── fournisseurs.css
│   ├── paiements.css
│   ├── depenses.css
│   ├── rapports.css
│   ├── admin.css
│   ├── profile.css
│   ├── notifications.css
│   ├── support.css
│   ├── auth.css
│   ├── landing.css
│   ├── abonnement.css
│   ├── components.css
│   ├── responsive.css
│   ├── sidebar.css
│   └── animations.css
│
├── scripts/                    # JavaScript côté client
│   ├── auth.js                 # Authentification + protection des pages
│   ├── dashboard.js
│   ├── clients.js
│   ├── factures.js
│   ├── devis.js
│   ├── produits.js
│   ├── stock.js
│   ├── fournisseurs.js
│   ├── paiements.js
│   ├── depenses.js
│   ├── rapports.js
│   ├── notifications.js
│   ├── ui.js                   # Toasts, modales, helpers + token CSRF (source unique)
│   ├── main.js
│   ├── inscription.js
│   ├── choix-abonnement.js
│   └── mon-abonnement.js
│
├── backend/                    # API PHP
│   ├── config.php              # Configuration (DB, FedaPay, email)
│   ├── config.example.php      # Template de configuration
│   ├── db.php                  # Connexion PDO
│   ├── init.php                # Initialisation session + CSRF
│   ├── csrf.php                # Génération/validation tokens CSRF
│   ├── log.php                 # Système de logging
│   ├── database.sql            # Schéma de la base de données
│   ├── auth.php                # Authentification (login/register)
│   ├── clients.php             # API clients (CRUD)
│   ├── invoices.php            # API factures (CRUD + items + paiements)
│   ├── quotes.php              # API devis (CRUD + items)
│   ├── products.php            # API produits (CRUD + stock)
│   ├── stock.php               # API stock (CRUD + produits)
│   ├── suppliers.php           # API fournisseurs (CRUD)
│   ├── payments.php            # API paiements (CRUD)
│   ├── expenses.php            # API dépenses (CRUD)
│   ├── reports.php             # API rapports (overview, charts)
│   ├── admin.php               # API admin (stats plateforme)
│   ├── profile.php             # API profil (get/update/delete)
│   ├── support.php             # API support (tickets)
│   ├── fedapay.php             # API FedaPay (paiements en ligne)
│   ├── subscription.php        # API abonnements
│   ├── gate.php                # Garde d'accès des pages privées
│   ├── session.php             # Gestion de session
│   ├── tools.php               # Outils partagés
│   ├── notifications.php       # API notifications
│   ├── email.php               # Notifications email
│   └── ...
│
├── vendor/                     # Dépendances tierces (composer)
├── logs/                       # Logs d'erreurs et d'accès
├── .gitignore
├── docker-compose.yml
├── tests/
│   ├── bootstrap.php           # Bootstrap PHPUnit
│   ├── DatabaseTest.php        # Tests de base de données
│   ├── e2e-smoke.sh            # Smoke test des flux de création
│   └── csrf-harness.js         # Harnais JS (chargement modules + token CSRF)
├── .github/
│   └── workflows/
│       └── ci.yml              # Pipeline CI/CD
└── README.md
```

---

## ⚡ Installation rapide

### Prérequis
- **Windows** avec WAMP (Apache + MySQL + PHP 8.x)
- **PHP** 8.0+
- **MySQL** 8.x ou MariaDB
- **Navigateur** moderne (Chrome, Firefox, Edge)

### Étapes (5 minutes)

```bash
# 1. Placer le dossier dans WAMP
# Copier FacTo dans C:\wamp64\www\FacTo

# 2. Démarrer Apache et MySQL via WAMP

# 3. Créer la base de données
# Ouvrir phpMyAdmin → http://localhost/phpmyadmin
# Cliquer "Créer une base de données" → nom: facto → Collation: utf8mb4

# 4. Importer le schéma SQL
# Dans phpMyAdmin → facto → onglet "Importer"
# Sélectionner backend/database.sql → Exécuter

# 5. Configurer la connexion DB
# Copier backend/config.example.php → backend/config.php
# Modifier les identifiants si nécessaire

# 6. Ouvrir l'application
# http://localhost/FacTo/
```

---

## 🔧 Configuration

### Fichier `backend/config.php`

```php
<?php
return [
    // Base de données
    'db_host' => '127.0.0.1',
    'db_name' => 'facto',
    'db_user' => 'root',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',

    // Emails administrateurs
    'admin_emails' => [],

    // Mode debug (affiche les emails dans la réponse JSON)
    // ⚠️ false par défaut : en local, définir APP_DEBUG=true pour voir les liens de réinitialisation
    'app_debug' => false,
    'app_name' => 'FacTo',
    'app_url' => 'http://localhost/FacTo',

    // FedaPay (paiements en ligne)
    'fedapay' => [
        'enabled' => false,
        'secret_key' => '',
        'public_key' => '',
        'environment' => 'sandbox',
        'currency' => 'XOF',
        'xof_per_eur' => 655.957,
    ],
];
```

### Variables d'environnement (optionnel)

Créer un fichier `.env` à la racine :

```env
DB_HOST=127.0.0.1
DB_NAME=facto
DB_USER=root
DB_PASS=
APP_DEBUG=true
FEDAPAY_ENABLED=false
```

---

## 🔐 Authentification

### Inscription
1. Aller sur `http://localhost/FacTo/inscription.html`
2. Remplir le formulaire (email, mot de passe, nom, entreprise)
3. Cliquer "Créer mon compte"


### Inscription
> ⚠️ L'inscription démarre toujours avec le **plan gratuit**. Les plans payants
> (Standard, Premium) ne sont attribués qu'après un paiement FedaPay réussi.

### Sécurité
- Tokens CSRF validés sur toutes les mutations (factures, devis, clients, produits, fournisseurs, paiements, dépenses, stock, abonnement, profil, notifications)
- Protection brute-force : connexion 5 essais / 15 min, mot de passe oublié 3 / 15 min, réinitialisation 5 / 15 min
- Rate limiting support : 5 tickets / heure / email
- Sessions sécurisées (HttpOnly, SameSite=Lax)
- Passwords hashés avec `password_hash()`

---

## 📊 Modules

### 📋 Dashboard
Vue d'ensemble avec statistiques clés et graphiques en temps réel.

### 👥 Clients
CRUD complet : ajouter, modifier, supprimer des clients avec coordonnées complètes.

### 🧾 Factures
- Créer des factures avec lignes d'articles
- Statuts : brouillon → envoyé → payée / refusée
- Références automatiques sans doublon (FAC-AAAA-XXX)
- Quota mensuel selon le plan (hors brouillons/annulées)
- Suivi des paiements associés
- Impression PDF (navigateur)

### 📝 Devis
- Créer des devis avec lignes d'articles
- Statuts : brouillon → envoyé → accepté / refusé
- Date de validité configurable
- Impression PDF (navigateur)

### 📦 Produits & Stock
- Gestion des produits (nom, référence, prix, catégorie)
- Entrées de stock avec seuils d'alerte
- Suivi de la quantité en temps réel

### 🏢 Fournisseurs
CRUD complet pour gérer les fournisseurs.

### 💳 Paiements
- Enregistrer des paiements par facture
- Méthodes : virement, chèque, carte, espèces, FedaPay
- Suivi des statuts (payé, en attente, échoué)

### 💰 Dépenses
- Suivi des dépenses par catégorie
- Ajout de reçus (chemin du fichier)
- Filtres par période et catégorie

### 📈 Rapports
- Chiffre d'affaires mensuel (graphique)
- Dépenses par catégorie (camembert)
- Statut des factures (donut)
- Méthodes de paiement (barres)



## 🐳 Docker

### Démarrer l'environnement de développement

```bash
# Depuis le dossier racine du projet
docker-compose up -d
```


### Arrêter

```bash
docker-compose down
```

### Variables Docker

Créer un fichier `.env` à la racine :



paiement, dépense, stock → rapports, admin, abonnement) :

```bash
bash tests/e2e-smoke.sh
```

> Il attend un serveur PHP sur `127.0.0.1:8123` (il en démarre un lui-même)
> et une base `facto` importée avec `backend/database.sql`.

### Harnais JavaScript (chargement des modules)

Le script `tests/csrf-harness.js` simule un navigateur (stubs DOM/fetch) pour
vérifier que `ui.js` + `auth.js` + chaque module se chargent sans erreur et que
le token CSRF centralisé dans `scripts/ui.js` est bien disponible :

```bash
node tests/csrf-harness.js
```

---

## 📝 Contribuer

1. Fork le dépôt
2. Créer une branche : `git checkout -b feature/ma-feature`
3. Commiter : `git commit -m "feat(module): description de la feature"`
4. Pousser : `git push origin feature/ma-feature`
5. Ouvrir une Pull Request

### Convention de commit

| Préfixe | Description |
|---------|-------------|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `docs` | Documentation |
| `style` | Formatage (pas de changement de logique) |
| `refactor` | Refactoring de code |
| `test` | Ajout de tests |
| `chore` | Tâche de maintenance |


