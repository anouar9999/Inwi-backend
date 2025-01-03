<?php
// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Check if tournament_id is provided
if (!isset($_GET['tournament_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tournament ID is required']);
    exit();
}

$tournament_id = intval($_GET['tournament_id']);

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // First get the tournament type
    $stmt = $pdo->prepare("
        SELECT participation_type
        FROM tournaments
        WHERE id = :tournament_id
    ");
    $stmt->execute([':tournament_id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }

    // Prepare the appropriate query based on tournament type
    if ($tournament['participation_type'] === 'participant') {
        // Query for individual participants
        $stmt = $pdo->prepare("
            SELECT 
                tr.id as registration_id,
                tr.registration_date,
                tr.status,
                tr.decision_date,
                u.id as user_id,
                u.username,
                u.email,
                u.avatar,
                u.bio,
                'participant' as type
            FROM tournament_registrations tr
            LEFT JOIN users u ON tr.user_id = u.id
            WHERE tr.tournament_id = :tournament_id
              AND tr.status = 'accepted'
              AND tr.admin_id IS NOT NULL
              AND tr.user_id IS NOT NULL
        ");
    } else {
        // Query for team participants
        $stmt = $pdo->prepare("
            SELECT 
                tr.id as registration_id,
                tr.registration_date,
                tr.status,
                tr.decision_date,
                t.id as team_id,
                t.name as team_name,
                t.image as team_avatar,
                t.description as team_bio,
                t.division,
                t.mmr,
                t.win_rate,
                'team' as type
            FROM tournament_registrations tr
            LEFT JOIN teams t ON tr.team_id = t.id
            WHERE tr.tournament_id = :tournament_id
              AND tr.status = 'accepted'
              AND tr.admin_id IS NOT NULL
              AND tr.team_id IS NOT NULL
        ");
    }

    $stmt->execute([':tournament_id' => $tournament_id]);
    $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($registrants)) {
        echo json_encode([
            'success' => true, 
            'message' => 'No accepted registrants found for this tournament', 
            'participants' => [],
            'tournament_type' => $tournament['participation_type']
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'participants' => $registrants,
            'tournament_type' => $tournament['participation_type']
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}