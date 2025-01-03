<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/error.log');

error_log('Incoming request: ' . print_r($_POST, true));
error_log('Raw input: ' . file_get_contents('php://input'));

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Handle both FormData and JSON requests
    $input = [];
    if (isset($_POST['email'])) {
        // FormData submission
        $input = $_POST;
    } else {
        // JSON submission
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    }

    if (empty($input['email']) || empty($input['password']) || empty($input['username'])) {
        throw new Exception('Missing required fields');
    }

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }

    if (strlen($input['password']) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }

    // Handle avatar upload
    $avatarPath = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $_FILES['avatar']['tmp_name']);
        finfo_close($fileInfo);

        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed.');
        }

        // Generate unique filename
        $extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('avatar_') . '.' . $extension;
        $targetPath = $uploadDir . $filename;

        // Move uploaded file
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
            $avatarPath = '/uploads/avatars/' . $filename;
        } else {
            throw new Exception('Failed to save avatar file');
        }
    }

    // Check existing email/username
    $stmt = $pdo->prepare('SELECT email FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$input['email'], $input['username']]);
    if ($stmt->fetch()) {
        throw new Exception('Email or username already exists');
    }

    // Create user with avatar
    $stmt = $pdo->prepare('
        INSERT INTO users (username, email, password, avatar, bio, type, created_at)
        VALUES (?, ?, ?, ?, ?, "participant", NOW())
    ');

    $stmt->execute([
        $input['username'],
        $input['email'],
        password_hash($input['password'], PASSWORD_DEFAULT),
        $avatarPath,
        $input['bio'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'avatar' => $avatarPath
    ]);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}