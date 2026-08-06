<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';
require __DIR__ . '/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];

// Ensure logo_path / signature columns exist (best-effort, compatible MySQL 5.7+ :
// pas de « ADD COLUMN IF NOT EXISTS » avant MySQL 8.0.29).
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'logo_path'");
    if (!(int) $stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN logo_path VARCHAR(255) DEFAULT NULL');
    }
} catch (Exception $e) {
    // ignore if not supported
}
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'signature'");
    if (!(int) $stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN signature LONGTEXT DEFAULT NULL');
    }
} catch (Exception $e) {
    // ignore if not supported
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

if ($action === 'get') {
    $stmt = $pdo->prepare('SELECT id, name, email, company, phone, role, created_at, logo_path, signature FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Utilisateur non trouvé']);
        exit;
    }
    echo json_encode(['user' => $user]);
    exit;
}

if ($action === 'save_signature') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }

    // Corps JSON (comme les autres endpoints) ou formulaire classique
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) { $body = $_POST; }

    $sig = (string) ($body['signature'] ?? '');

    // Signature vide => suppression
    if ($sig === '') {
        $pdo->prepare('UPDATE users SET signature = NULL, updated_at = NOW() WHERE id = ?')->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'Signature supprimée']);
        exit;
    }

    // Validation : data URL d'image (png/jpeg/webp), taille raisonnable
    if (strlen($sig) > 2 * 1024 * 1024 || !preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $sig)) {
        http_response_code(422);
        echo json_encode(['error' => 'Image de signature invalide (PNG, JPEG ou WebP, max 2 Mo)']);
        exit;
    }

    $pdo->prepare('UPDATE users SET signature = ?, updated_at = NOW() WHERE id = ?')->execute([$sig, $userId]);
    echo json_encode(['success' => true, 'message' => 'Signature enregistrée']);
    exit;
}

if ($action === 'update') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? null;

    if (!$name || !$email) {
        http_response_code(422);
        echo json_encode(['error' => 'Le nom et l’e-mail sont requis']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'E-mail invalide']);
        exit;
    }
    if ($phone && !preg_match('/^[0-9+\s.-]{6,20}$/', $phone)) {
        http_response_code(422);
        echo json_encode(['error' => 'Numéro de téléphone invalide']);
        exit;
    }

    // Check email unique
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'E-mail déjà utilisé']);
        exit;
    }

    $fields = ['name' => $name, 'email' => $email, 'company' => $company, 'phone' => $phone];
    if ($password) {
        if (strlen($password) < 8 || !preg_match('/\d/', $password)) {
            http_response_code(422);
            echo json_encode(['error' => 'Le mot de passe doit contenir au moins 8 caractères et un chiffre']);
            exit;
        }
        $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    // Handle file upload with validation, conversion to WebP, resizing and cleanup of previous images (GD)
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['logo'];
        // Basic size limit 2MB
        if ($f['size'] > 2 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['error' => 'Le fichier est trop volumineux (max 2MB)']);
            exit;
        }

        $info = getimagesize($f['tmp_name']);
        if ($info === false) {
            http_response_code(422);
            echo json_encode(['error' => 'Fichier image invalide']);
            exit;
        }

        $mime = $info['mime'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            http_response_code(415);
            echo json_encode(['error' => 'Format non supporté (jpg, png, webp)']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/logos';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $basename = 'user_' . $userId . '_' . time();
        $webpName = $basename . '.webp';
        $webpDest = $uploadDir . '/' . $webpName;

        // Load image resource
        $src = null;
        switch ($mime) {
            case 'image/jpeg': $src = imagecreatefromjpeg($f['tmp_name']); break;
            case 'image/png': $src = imagecreatefrompng($f['tmp_name']); break;
            case 'image/webp': $src = imagecreatefromwebp($f['tmp_name']); break;
        }
        if (!$src) {
            http_response_code(500);
            echo json_encode(['error' => 'Impossible de traiter l’image']);
            exit;
        }

        // Resize to max 800x800
        $max = 800;
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $newW = (int)($w * $scale);
        $newH = (int)($h * $scale);
        $tmp = imagecreatetruecolor($newW, $newH);
        // preserve transparency for png/webp
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);
            $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
            imagefilledrectangle($tmp, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        // Save resized main image as WebP (optimization)
        $saved = imagewebp($tmp, $webpDest, 80);
        imagedestroy($tmp);
        imagedestroy($src);

        if (!$saved) {
            http_response_code(500);
            echo json_encode(['error' => 'Impossible de sauvegarder l’image (webp)']);
            exit;
        }

        // Create thumbnail 160x160 center-crop from the saved webp
        $thumbSize = 160;
        $src2 = imagecreatefromwebp($webpDest);
        if ($src2) {
            $sw = imagesx($src2);
            $sh = imagesy($src2);
            $minSide = min($sw, $sh);
            $sx = (int)(($sw - $minSide) / 2);
            $sy = (int)(($sh - $minSide) / 2);
            $thumb = imagecreatetruecolor($thumbSize, $thumbSize);
            // preserve alpha for webp
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $thumbSize, $thumbSize, $transparent);
            imagecopyresampled($thumb, $src2, 0, 0, $sx, $sy, $thumbSize, $thumbSize, $minSide, $minSide);
            $thumbName = $basename . '_thumb.webp';
            $thumbDest = $uploadDir . '/' . $thumbName;
            imagewebp($thumb, $thumbDest, 80);
            imagedestroy($thumb);
            imagedestroy($src2);

            // store path to thumbnail for UI
            $fields['logo_path'] = 'backend/uploads/logos/' . $thumbName;

            // Cleanup: remove previous logo files if any
            try {
                $stmtOld = $pdo->prepare('SELECT logo_path FROM users WHERE id = ?');
                $stmtOld->execute([$userId]);
                $prev = $stmtOld->fetchColumn();
                if ($prev) {
                    // $prev est stocké sous la forme « backend/uploads/logos/... » :
                    // le chemin réel part de la racine du projet (un niveau au-dessus de backend/).
                    $prevPath = dirname(__DIR__) . '/' . $prev;
                    if (strpos(realpath($prevPath) ?: '', realpath($uploadDir)) === 0 && file_exists($prevPath)) {
                        @unlink($prevPath);
                    }
                    // Supprime aussi le variant principal (non-thumb) de l'ancien logo.
                    $prevBase = pathinfo($prev, PATHINFO_FILENAME);
                    $mainBase = preg_replace('/_thumb$/', '', $prevBase);
                    $uploadsAbs = __DIR__ . '/uploads/logos';
                    $alt1 = $uploadsAbs . '/' . $prevBase . '.webp';
                    $alt2 = $uploadsAbs . '/' . $mainBase . '.webp';
                    if (file_exists($alt1)) @unlink($alt1);
                    if (file_exists($alt2)) @unlink($alt2);
                }
            } catch (Exception $e) {
                // ignore cleanup errors
            }
        } else {
            // fallback
            $fields['logo_path'] = 'backend/uploads/logos/' . $webpName;
        }
    }

    // Build update query
    $setParts = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $setParts[] = "$k = ?";
        $params[] = $v;
    }
    $params[] = $userId;

    $sql = 'UPDATE users SET ' . implode(', ', $setParts) . ', updated_at = NOW() WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Refresh session info
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;

    echo json_encode(['success' => true, 'message' => 'Profil mis à jour']);
    exit;
}

if ($action === 'delete_account') {
    if (!csrfValidateToken($_POST['_csrf'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Token CSRF invalide']); exit; }

    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($password, $hash)) {
        http_response_code(422);
        echo json_encode(['error' => 'Mot de passe incorrect']);
        exit;
    }

    // Suppression en cascade (factures, abonnements, notifications, etc.)
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

    // Détruit la session
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Compte supprimé. Au revoir !']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action inconnue']);
