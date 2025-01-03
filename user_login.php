<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        throw new Exception('Missing email or password');
    }

    $email = $data['email'];
    $password = $data['password'];

    $stmt = $pdo->prepare("SELECT id, username, email, password, type, is_verified FROM users WHERE email = :email");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Invalid email or password');
    }

    if (!password_verify($password, $user['password'])) {
        // Increment failed attempts
        $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
        throw new Exception('Invalid email or password');
    }

    if (!$user['is_verified']) {
        throw new Exception('Account not verified. Please check your email for verification instructions.');
    }

    // Reset failed attempts on successful login
    $updateStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = :id");
    $updateStmt->execute([':id' => $user['id']]);

    // Generate a session token (you may want to use a more secure method in production)
    $session_token = bin2hex(random_bytes(32));

    // Log the successful login
    $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (:user_id, :action, :ip_address)");
    $logStmt->execute([
        ':user_id' => $user['id'],
        ':action' => 'Connexion réussie',
        ':ip_address' => $_SERVER['REMOTE_ADDR']
    ]);

    echo json_encode([
        'success' => true, 
        'message' => 'Login successful',
        'user_id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'user_type' => $user['type'],
        'session_token' => $session_token
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>