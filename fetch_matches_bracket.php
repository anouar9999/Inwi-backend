<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $data = json_decode(file_get_contents("php://input"));
    if (!isset($data->tournament_id)) {
        throw new Exception('Tournament ID is required');
    }

    // Get tournament details
    $tournament = getTournamentDetails($pdo, $data->tournament_id);
    
    // Get and format matches
    $matches = getFormattedMatches($pdo, $data->tournament_id);
    
    // Calculate total rounds based on participant count
    $totalRounds = ceil(log($tournament['nombre_maximum'], 2));

    echo json_encode([
        'success' => true,
        'data' => [
            'tournament' => $tournament,
            'matches' => $matches,
            'total_rounds' => $totalRounds,
            'is_team_tournament' => $tournament['participation_type'] === 'team'
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getTournamentDetails($pdo, $tournamentId) {
    $query = "
        SELECT 
            t.*,
            COUNT(DISTINCT CASE WHEN tr.status = 'accepted' THEN tr.id END) as participant_count
        FROM tournaments t
        LEFT JOIN tournament_registrations tr ON t.id = tr.tournament_id
        WHERE t.id = ?
        GROUP BY t.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    return $tournament;
}

function getFormattedMatches($pdo, $tournamentId) {
    // Get all matches with proper ordering
    $matchQuery = "
        SELECT 
            m.id,
            m.tournament_id,
            m.tournament_round_text as round,
            m.start_time,
            m.state as status,
            m.position,
            m.bracket_position
        FROM matches m
        WHERE m.tournament_id = ?
        ORDER BY m.tournament_round_text ASC, m.position ASC
    ";
    
    $stmt = $pdo->prepare($matchQuery);
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matches)) {
        return [];
    }

    // Get participants for all matches
    $matchIds = array_column($matches, 'id');
    $participants = getMatchParticipants($pdo, $matchIds);
    
    // Group participants by match
    $participantsByMatch = [];
    foreach ($participants as $participant) {
        $matchId = $participant['match_id'];
        if (!isset($participantsByMatch[$matchId])) {
            $participantsByMatch[$matchId] = [];
        }
        $participantsByMatch[$matchId][] = $participant;
    }

    // Format matches
    foreach ($matches as &$match) {
        $matchParticipants = $participantsByMatch[$match['id']] ?? [];
        
        // Sort participants to ensure consistent order
        usort($matchParticipants, function($a, $b) {
            return $a['id'] - $b['id'];
        });
        
        $match['teams'] = [
            formatTeam($matchParticipants[0] ?? null),
            formatTeam($matchParticipants[1] ?? null)
        ];
    }

    return $matches;
}
function getMatchParticipants($pdo, $matchIds) {
    $placeholders = str_repeat('?,', count($matchIds) - 1) . '?';
    
    $query = "
        SELECT 
            mp.*,
            CASE 
                WHEN mp.participant_id LIKE 'team_%' THEN t.name
                ELSE u.username
            END as participant_name,
            CASE 
                WHEN mp.participant_id LIKE 'team_%' THEN t.image
                ELSE u.avatar
            END as avatar_url
        FROM match_participants mp
        LEFT JOIN users u ON mp.participant_id = CONCAT('player_', u.id)
        LEFT JOIN teams t ON mp.participant_id = CONCAT('team_', t.id)
        WHERE mp.match_id IN ($placeholders)
        ORDER BY mp.match_id, mp.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($matchIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatTeam($participant) {
    if (!$participant) {
        return [
            'id' => null,
            'name' => 'TBD',
            'score' => 0,
            'winner' => false,
            'avatar' => null
        ];
    }

    return [
        'id' => $participant['participant_id'],
        'name' => $participant['participant_name'] ?? 'TBD',
        'score' => intval($participant['result_text'] ?? 0),
        'winner' => (bool)($participant['is_winner'] ?? false),
        'avatar' => $participant['avatar_url']
    ];
}