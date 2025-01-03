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

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // GET request to fetch admin information
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!isset($_GET['id'])) {
            throw new Exception('Missing admin ID');
        }
        $admin_id = $_GET['id'];
        $stmt = $pdo->prepare("SELECT id, username, email, created_at FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            throw new Exception('Admin not found');
        }
        
        echo json_encode(['success' => true, 'data' => $admin]);
    }
    // PUT request to update admin information
    elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['id'])) {
            throw new Exception('Missing admin ID');
        }

        $admin_id = $data['id'];
        $updates = [];
        $params = [':id' => $admin_id];

        if (isset($data['username'])) {
            $updates[] = "username = :username";
            $params[':username'] = $data['username'];
        }

        if (isset($data['email'])) {
            $updates[] = "email = :email";
            $params[':email'] = $data['email'];
        }

        if (isset($data['password'])) {
            $updates[] = "password = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (empty($updates)) {
            throw new Exception('No fields to update');
        }

        $sql = "UPDATE admins SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Admin information updated successfully']);
    }
    else {
        throw new Exception('Method Not Allowed');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>