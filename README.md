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
- ✅ **PDF** — Génération de factures et devis
- ✅ **Email** — Notifications par email

---

## 🛠️ Stack technique

| Couche | Technologie |
|--------|-------------|
| **Backend** | PHP 8.x, PDO MySQL |
| **Frontend** | HTML5, CSS3, Bootstrap 5.3, Chart.js |
| **Auth** | Sessions PHP, CSRF tokens |
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
│   ├── admin.js
│   ├── notifications.js
│   ├── support.js
│   ├── ui.js                   # Toasts, confirmations, helpers
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
│   ├── pdf.php                 # Génération PDF (factures/devis)
│   ├── email.php               # Notifications email
│   └── ...
│
├── vendor/                     # Dépendances tierces (FPDF, etc.)
├── logs/                       # Logs d'erreurs et d'accès
├── .gitignore
├── docker-compose.yml
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
    'app_debug' => true,
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

### Connexion
1. Aller sur `http://localhost/FacTo/connexion.html`
2. Saisir email et mot de passe
3. Cliquer "Se connecter"

### Compte de test (développement)
- **Email** : `test@exemple.fr`
- **Mot de passe** : `Test1234`

### Sécurité
- Tokens CSRF sur toutes les requêtes POST
- Protection brute-force sur la connexion (10 tentatives max)
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
- Suivi des paiements associés
- Génération PDF

### 📝 Devis
- Créer des devis avec lignes d'articles
- Statuts : brouillon → envoyé → accepté / refusé
- Date de validité configurable
- Génération PDF

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

### ⚙️ Administration
- Tableau de bord admin avec stats plateforme
- Gestion des entreprises
- Croissance des utilisateurs

---

## 🐳 Docker

### Démarrer l'environnement de développement

```bash
# Depuis le dossier racine du projet
docker-compose up -d
```

### Accéder aux services

| Service | URL |
|---------|-----|
| Application | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| MySQL | localhost:3306 |

### Arrêter

```bash
docker-compose down
```

### Variables Docker

Créer un fichier `.env` à la racine :

```env
MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=facto
MYSQL_USER=facto
MYSQL_PASSWORD=facto
```

---

## 🔒 Sécurité

- [x] Protection CSRF sur toutes les requêtes POST
- [x] Rate limiting sur l'authentification
- [x] Sessions sécurisées (HttpOnly, SameSite)
- [x] Passwords hashés (password_hash)
- [x] Validation des entrées côté serveur
- [x] Prepared statements (protection SQL injection)
- [x] Headers de sécurité
- [x] Logging structuré

### À faire en production
- [ ] Activer HTTPS (Let's Encrypt)
- [ ] Configurer un WAF (Web Application Firewall)
- [ ] Limiter l'accès IP à l'admin
- [ ] Activer le CSP (Content Security Policy)
- [ ] Configurer des sauvegardes automatiques

---

## 🚀 Déploiement

### Production (serveur Linux)

```bash
# 1. Cloner le dépôt
git clone https://github.com/votre-repo/facto.git
cd facto

# 2. Installer les dépendances PHP
composer install --optimize-autoloader --no-dev

# 3. Configurer la base de données
mysql -u root -p facto < backend/database.sql

# 4. Configurer le fichier config.php
cp backend/config.example.php backend/config.php
# Éditer backend/config.php avec les identifiants de production

# 5. Configurer le serveur web (Nginx/Apache)
# Voir le fichier nginx.conf.example (à créer)

# 6. Définir les permissions
chmod -R 755 backend/
chmod -R 775 logs/
chown -R www-data:www-data logs/

# 7. Activer HTTPS
sudo certbot --nginx -d votre-domaine.com
```

### Variables d'environnement production

```env
APP_DEBUG=false
DB_HOST=localhost
DB_NAME=facto
DB_USER=facto_prod
DB_PASS=mot_de_passe_securise
FEDAPAY_ENABLED=true
FEDAPAY_SECRET_KEY=fn_live_votre_cle
FEDAPAY_PUBLIC_KEY=fn_public_votre_cle
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USER=apikey
SMTP_PASS=votre_cle_sendgrid
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

---

## 📄 Licence

Ce projet est sous licence **MIT**. Voir le fichier [LICENSE](LICENSE) pour plus de détails.

---

## 📞 Support

- **Email** : support@facto.local
- **Documentation** : [Wiki du projet](https://github.com/votre-repo/facto/wiki)
- **Issues** : [GitHub Issues](https://github.com/votre-repo/facto/issues)

---

<p align="center">
  <b>FacTo</b> — Gérez votre entreprise simplement.
  <br>
  <sub>Construit avec ❤️ en Côte d'Ivoire 🇨🇮</sub>
</p>
