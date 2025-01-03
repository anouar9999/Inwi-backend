<?php
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_error_handler('handleError');
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

try {
    $db_config = require 'db_config.php';
    if (!$db_config) throw new Exception('Database configuration not found');

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->beginTransaction();

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Invalid JSON payload');
    if (!isset($data['tournament_id']) || !isset($data['user_id'])) throw new Exception('Missing required parameters');

    // Get tournament details
    $stmt = $pdo->prepare("
        SELECT id, nom_des_qualifications, status, participation_type, nombre_maximum,
               competition_type, image
        FROM tournaments 
        WHERE id = :tournament_id
    ");
    $stmt->execute([':tournament_id' => $data['tournament_id']]);
    $tournament = $stmt->fetch();

    if (!$tournament) throw new Exception('Tournament not found');
    if ($tournament['status'] !== 'Ouvert aux inscriptions') throw new Exception('Tournament is not open for registration');

    // Count current registrations
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM tournament_registrations 
        WHERE tournament_id = :tournament_id AND status IN ('pending', 'accepted')
    ");
    $stmt->execute([':tournament_id' => $data['tournament_id']]);
    $registrationCount = $stmt->fetch()['count'];

    if ($registrationCount >= $tournament['nombre_maximum']) {
        throw new Exception('Tournament is full');
    }

    if ($tournament['participation_type'] === 'team') {
        if (!isset($data['team_id'])) throw new Exception('Team ID required for team tournaments');

        // Verify team ownership and game type
        $stmt = $pdo->prepare("
            SELECT id, name, owner_id, team_game FROM teams 
            WHERE id = :team_id AND owner_id = :user_id AND team_game = :game_type
        ");
        $stmt->execute([
            ':team_id' => $data['team_id'],
            ':user_id' => $data['user_id'],
            ':game_type' => $tournament['competition_type']
        ]);
        $team = $stmt->fetch();
        if (!$team) throw new Exception('You must own a valid team for this game type to register');

        // Check for existing team registration
        $stmt = $pdo->prepare("
            SELECT id FROM tournament_registrations 
            WHERE tournament_id = :tournament_id AND team_id = :team_id
        ");
        $stmt->execute([':tournament_id' => $data['tournament_id'], ':team_id' => $data['team_id']]);
        if ($stmt->fetch()) throw new Exception('Team is already registered for this tournament');

        // Register team
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations (tournament_id, team_id, registration_date, status)
            VALUES (:tournament_id, :team_id, NOW(), 'pending')
        ");
        $stmt->execute([':tournament_id' => $data['tournament_id'], ':team_id' => $data['team_id']]);
        $successMessage = "Team registration successful";
    } else {
        // Check for existing user registration
        $stmt = $pdo->prepare("
            SELECT id FROM tournament_registrations 
            WHERE tournament_id = :tournament_id AND user_id = :user_id
        ");
        $stmt->execute([':tournament_id' => $data['tournament_id'], ':user_id' => $data['user_id']]);
        if ($stmt->fetch()) throw new Exception('You are already registered for this tournament');

        // Register individual
        $stmt = $pdo->prepare("
            INSERT INTO tournament_registrations (tournament_id, user_id, registration_date, status)
            VALUES (:tournament_id, :user_id, NOW(), 'pending')
        ");
        $stmt->execute([':tournament_id' => $data['tournament_id'], ':user_id' => $data['user_id']]);
        $successMessage = "Registration successful";
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $successMessage]);

} catch (PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>