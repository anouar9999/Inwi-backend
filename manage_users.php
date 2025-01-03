<?php
// File: manage_users.php
 header('Access-Control-Allow-Origin: *');
    header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 86400");  // cache preflight request for 1 day


// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load database configuration
$db_config = require 'db_config.php';

// Establish database connection
try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Determine the HTTP method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Fetch all users including passwords
        try {
            $query = "SELECT id, username, password, email, type, points, rank, is_verified, created_at, bio, avatar FROM users";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'users' => $users]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => `Failed to fetch users `]);
        }
        break;

    case 'POST':
        // Edit user
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID is required']);
            exit;
        }

        $fields = ['username', 'email', 'password', 'type', 'points', 'rank', 'is_verified', 'bio', 'avatar'];
        $updates = [];
        $params = [];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'password') {
                    // Hash the password if it's being updated
                    $updates[] = "$field = :$field";
                    $params[$field] = password_hash($data[$field], PASSWORD_DEFAULT);
                } else {
                    $updates[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit;
        }

        try {
            $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->execute(array_merge($params, ['id' => $id]));

            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update user']);
        }
        break;

    case 'DELETE':
        // Remove user
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID is required']);
            exit;
        }

        try {
            $query = "DELETE FROM users WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->execute(['id' => $id]);

            echo json_encode(['success' => true, 'message' => 'User removed successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to remove user']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}