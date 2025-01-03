<?php
// Custom error handler for consistent JSON output
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred'
    ]);
    exit;
}
set_error_handler('handleError');

// Enable error reporting but don't display errors
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get and validate POST data
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload');
    }

    if (!isset($data->tournament_id) || !isset($data->user_id)) {
        throw new Exception('Missing required parameters');
    }

    $tournament_id = filter_var($data->tournament_id, FILTER_VALIDATE_INT);
    $user_id = filter_var($data->user_id, FILTER_VALIDATE_INT);

    if ($tournament_id === false || $user_id === false) {
        throw new Exception('Invalid parameters');
    }

    // Database connection
    $db_config = require 'db_config.php';
    if (!$db_config) {
        throw new Exception('Database configuration not found');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // First get tournament type
    $stmt = $pdo->prepare("
    SELECT participation_type 
    FROM tournaments 
    WHERE id = :tournament_id
");
$stmt->execute(['tournament_id' => $tournament_id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    throw new Exception('Tournament not found');
}

// Check registration based on tournament type
if ($tournament['participation_type'] === 'team') {
    // For team tournaments, check team registrations where user is the team owner
    $stmt = $pdo->prepare("
        SELECT tr.id, tr.status, t.name as team_name
        FROM tournament_registrations tr
        JOIN teams t ON tr.team_id = t.id
        WHERE tr.tournament_id = :tournament_id 
        AND t.owner_id = :user_id
    ");
} else {
    // For individual tournaments, check direct user registration
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM tournament_registrations 
        WHERE tournament_id = :tournament_id 
        AND user_id = :user_id
    ");
}

$stmt->execute([
    'tournament_id' => $tournament_id,
    'user_id' => $user_id
]);

$registration = $stmt->fetch();
$hasJoined = $registration !== false;

$response = [
    'success' => true,
    'hasJoined' => $hasJoined,
    'tournamentType' => $tournament['participation_type']
];

if ($hasJoined) {
    $response['status'] = $registration['status'];
    if ($tournament['participation_type'] === 'team') {
        $response['team_name'] = $registration['team_name'];
    }
}

echo json_encode($response);

} catch (PDOException $e) {
http_response_code(500);
echo json_encode([
    'success' => false,
    'message' => 'Database error occurred'
]);
} catch (Exception $e) {
http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => $e->getMessage()
]);
}
?>