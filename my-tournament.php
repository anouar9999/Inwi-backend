<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $db_config = require 'db_config.php';
    if (!$db_config) {
        throw new Exception('Database configuration not found');
    }

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get user ID from query parameter
    if (!isset($_GET['user_id'])) {
        throw new Exception('User ID is required');
    }
    $user_id = filter_var($_GET['user_id'], FILTER_VALIDATE_INT);

    // Query to get tournaments where user is registered
    $query = "
        SELECT 
            t.*,
            tr.status as registration_status,
            tr.registration_date,
            CASE
                WHEN t.participation_type = 'team' THEN 
                    (SELECT team.name 
                     FROM teams team 
                     JOIN tournament_registrations reg ON reg.team_id = team.id 
                     WHERE reg.tournament_id = t.id AND team.owner_id = :user_id)
                ELSE NULL
            END as team_name,
            COALESCE(
                (SELECT COUNT(*) 
                 FROM tournament_registrations reg 
                 WHERE reg.tournament_id = t.id 
                 AND reg.status IN ('pending', 'accepted')
                ), 0
            ) as registered_count
        FROM tournaments t
        JOIN tournament_registrations tr ON t.id = tr.tournament_id
        WHERE 
            (
                (t.participation_type = 'participant' AND tr.user_id = :user_id)
                OR 
                (t.participation_type = 'team' AND tr.team_id IN 
                    (SELECT id FROM teams WHERE owner_id = :user_id)
                )
            )
        ORDER BY t.created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':user_id' => $user_id]);
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process each tournament to add calculated fields
    $processedTournaments = array_map(function($tournament) {
        // Calculate max and remaining spots
        $maxSpots = $tournament['participation_type'] === 'team' 
            ? (int)$tournament['nombre_maximum']
            : (int)$tournament['nombre_maximum'];

        $registeredCount = (int)$tournament['registered_count'];

        return [
            'id' => (int)$tournament['id'],
            'nom_des_qualifications' => $tournament['nom_des_qualifications'],
            'competition_type' => $tournament['competition_type'],
            'participation_type' => $tournament['participation_type'],
            'slug' => $tournament['slug'],
            'start_date' => $tournament['start_date'],
            'end_date' => $tournament['end_date'],
            'nombre_maximum'=> $tournament['nombre_maximum'],
            'status' => $tournament['status'],
            'description_des_qualifications' => $tournament['description_des_qualifications'],
            'rules' => $tournament['rules'],
            'prize_pool' => (float)$tournament['prize_pool'],
            'format_des_qualifications' => $tournament['format_des_qualifications'],
            'type_de_match' => $tournament['type_de_match'],
            'type_de_jeu' => $tournament['type_de_jeu'],
            'image' => $tournament['image'],
            'created_at' => $tournament['created_at'],
            // Registration specific info
            'registration_status' => $tournament['registration_status'],
            'registration_date' => $tournament['registration_date'],
            'team_name' => $tournament['team_name'],
            // Stats
            'registered_count' => $registeredCount,
            'max_spots' => $maxSpots,
            'spots_remaining' => max(0, $maxSpots - $registeredCount),
            'registration_progress' => [
                'total' => $maxSpots,
                'filled' => $registeredCount,
                'percentage' => $maxSpots > 0 ? round(($registeredCount / $maxSpots) * 100, 1) : 0
            ],
            // Time info
            'time_info' => [
                'is_started' => strtotime($tournament['start_date']) <= time(),
                'is_ended' => strtotime($tournament['end_date']) < time(),
                'days_remaining' => max(0, ceil((strtotime($tournament['end_date']) - time()) / (60 * 60 * 24)))
            ]
        ];
    }, $tournaments);

    echo json_encode([
        'success' => true,
        'tournaments' => $processedTournaments,
        'total_count' => count($processedTournaments)
    ]);

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