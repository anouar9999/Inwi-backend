<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $query = "
        SELECT 
            t.*,
            COUNT(DISTINCT tr.id) as registered_count
        FROM tournaments t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id AND tr.status = 'accepted'
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tournaments as &$tournament) {
        $max_spots = $tournament['participation_type'] == 'team' ? 
            intval($tournament['nombre_maximum']) : 
            intval($tournament['nombre_maximum']);
        
        $registered = intval($tournament['registered_count']);
        $spots_remaining = max(0, $max_spots - $registered);
        $percentage = $max_spots > 0 ? round(($registered / $max_spots) * 100) : 0;

        $start = new DateTime($tournament['start_date']);
        $end = new DateTime($tournament['end_date']);
        $now = new DateTime();

        $tournament['spots_remaining'] = $spots_remaining;
        $tournament['registration_progress'] = [
            'total' => $max_spots,
            'filled' => $registered,
            'percentage' => $percentage
        ];
        $tournament['time_info'] = [
            'is_started' => $now >= $start,
            'is_ended' => $now > $end,
            'days_remaining' => $start->diff($now)->days
        ];
    }

    echo json_encode([
        'success' => true, 
        'tournaments' => $tournaments,
        'total_count' => count($tournaments)
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch tournaments',
        'debug_message' => $e->getMessage()
    ]);
}