<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Process GET and PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!isset($_GET['id'])) {
            throw new Exception('Missing user ID');
        }

        $userId = $_GET['id'];

        $stmt = $pdo->prepare("SELECT id, username, email, type, points, rank, bio, avatar, user_type FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('User not found');
        }

        echo json_encode([
            'success' => true,
            'data' => $user
        ]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['id'])) {
            throw new Exception('Missing user ID');
        }

        $userId = $data['id'];
        $updateFields = [];
        $params = [':id' => $userId];

        $allowedFields = ['username', 'email', 'type', 'points', 'rank', 'bio', 'avatar', 'user_type'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (isset($data['password'])) {
            $updateFields[] = "password = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updateFields)) {
            throw new Exception('No fields to update');
        }

        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new Exception('User not found or no changes made');
        }

        // Log the successful update
        $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (:user_id, :action, :ip_address)");
        $logStmt->execute([
            ':user_id' => $userId,
            ':action' => 'User settings updated',
            ':ip_address' => $_SERVER['REMOTE_ADDR']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'User settings updated successfully'
        ]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>