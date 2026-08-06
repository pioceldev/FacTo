<?php
// Garde-fou serveur : vérifie la session avant de servir une page privée.
require __DIR__ . '/init.php';

$allowedPages = [
    'dashboard.html',
    'administration.html',
    'clients.html',
    'produits.html',
    'fournisseurs.html',
    'stock.html',
    'devis.html',
    'factures.html',
    'paiements.html',
    'depenses.html',
    'rapports.html',
    'parametres.html',
    'notifications.html',
    'mon-abonnement.html',
    'choix-abonnement.html',
    'support.html',
    'profile.html',
];

$page = $_GET['page'] ?? null;

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    exit('Page introuvable');
}

if (empty($_SESSION['user_id'])) {
    header('Location: connexion.html');
    exit;
}

// Administration réservée aux admins
if ($page === 'administration.html' && ($_SESSION['user_role'] ?? 'user') !== 'admin') {
    header('Location: dashboard.html');
    exit;
}

$file = __DIR__ . '/../' . $page;
if (!is_file($file)) {
    http_response_code(404);
    exit('Page introuvable');
}

readfile($file);
