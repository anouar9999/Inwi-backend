<?php
header('Access-Control-Allow-Origin: http://localhost:3000');
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

    $pdo->beginTransaction();

    try {
        // Get tournament and registration details
        $tournament = getTournamentDetails($pdo, $data->tournament_id);
        $registrations = getAcceptedRegistrations($pdo, $data->tournament_id);
        
        // Clear existing matches
        clearExistingMatches($pdo, $data->tournament_id);
        
        // Calculate total rounds needed
        $numRounds = ceil(log($tournament['nombre_maximum'], 2));
        
        // Generate and populate matches
        $matchIds = generateMatches($pdo, $data->tournament_id, $numRounds, $registrations);
        
        // Handle automatic progressions
        handleAutomaticProgressions($pdo, $matchIds);
        
        $pdo->commit();
        
        // Return generated matches
        $matches = getGeneratedMatches($pdo, $data->tournament_id);
        echo json_encode(['success' => true, 'data' => $matches]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getTournamentDetails($pdo, $tournamentId) {
    $query = "SELECT * FROM tournaments WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    return $tournament;
}

function getAcceptedRegistrations($pdo, $tournamentId) {
    $query = "
        SELECT 
            tr.*,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN CONCAT('team_', tr.team_id)
                ELSE CONCAT('player_', tr.user_id)
            END as participant_id,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN t.name
                ELSE u.username
            END as name,
            CASE 
                WHEN tr.team_id IS NOT NULL THEN t.image
                ELSE u.avatar
            END as picture
        FROM tournament_registrations tr
        LEFT JOIN teams t ON tr.team_id = t.id
        LEFT JOIN users u ON tr.user_id = u.id
        WHERE tr.tournament_id = ? 
        AND tr.status = 'accepted'
        ORDER BY RAND()
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function clearExistingMatches($pdo, $tournamentId) {
    $query = "DELETE FROM matches WHERE tournament_id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
}

function generateMatches($pdo, $tournamentId, $numRounds, $registrations) {
    $matchIds = [];
    $totalSlots = pow(2, $numRounds);
    $matchesInFirstRound = $totalSlots / 2;
    
    // Calculate proper seeding positions for first round
    $seedPositions = generateSeedPositions($matchesInFirstRound);
    
    // Generate first round matches
    for ($i = 0; $i < $matchesInFirstRound; $i++) {
        $seedPosition = $seedPositions[$i];
        $participant1 = isset($registrations[$seedPosition * 2]) ? $registrations[$seedPosition * 2] : null;
        $participant2 = isset($registrations[$seedPosition * 2 + 1]) ? $registrations[$seedPosition * 2 + 1] : null;
        
        // Create match with tournament seeding position
        $matchId = insertMatch($pdo, [
            'tournament_id' => $tournamentId,
            'tournament_round_text' => '1',
            'position' => $seedPosition,
            'bracket_position' => $i % 2,
            'start_time' => date('Y-m-d'),
            'state' => 'SCHEDULED'
        ]);
        
        $matchIds[] = $matchId;
        
        if ($participant1) {
            insertMatchParticipant($pdo, $matchId, $participant1);
        }
        if ($participant2) {
            insertMatchParticipant($pdo, $matchId, $participant2);
        }
    }
    
    // Generate subsequent rounds
    for ($round = 2; $round <= $numRounds; $round++) {
        $matchesInRound = $totalSlots / pow(2, $round);
        for ($i = 0; $i < $matchesInRound; $i++) {
            $matchId = insertMatch($pdo, [
                'tournament_id' => $tournamentId,
                'tournament_round_text' => $round,
                'position' => $i,
                'bracket_position' => $i % 2,
                'start_time' => date('Y-m-d'),
                'state' => 'SCHEDULED'
            ]);
            $matchIds[] = $matchId;
        }
    }
    
    return $matchIds;
}

function generateSeedPositions($numMatches) {
    $positions = array();
    generateSeedPositionsRecursive($positions, 0, $numMatches - 1, 0);
    return $positions;
}

function generateSeedPositionsRecursive(&$positions, $start, $end, $index) {
    if ($start > $end) return;
    
    $mid = floor(($start + $end) / 2);
    $positions[] = $mid;
    
    generateSeedPositionsRecursive($positions, $start, $mid - 1, $index * 2 + 1);
    generateSeedPositionsRecursive($positions, $mid + 1, $end, $index * 2 + 2);
}

function insertMatch($pdo, $data) {
    $query = "
        INSERT INTO matches (
            tournament_id,
            tournament_round_text,
            position,
            bracket_position,
            start_time,
            state
        ) VALUES (
            :tournament_id,
            :tournament_round_text,
            :position,
            :bracket_position,
            :start_time,
            :state
        )
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'tournament_id' => $data['tournament_id'],
        'tournament_round_text' => $data['tournament_round_text'],
        'position' => $data['position'],
        'bracket_position' => $data['bracket_position'],
        'start_time' => $data['start_time'],
        'state' => $data['state']
    ]);
    
    return $pdo->lastInsertId();
}

function insertMatchParticipant($pdo, $matchId, $participant) {
    $query = "
        INSERT INTO match_participants (
            match_id,
            participant_id,
            name,
            picture,
            status
        ) VALUES (
            :match_id,
            :participant_id,
            :name,
            :picture,
            'NOT_PLAYED'
        )
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'match_id' => $matchId,
        'participant_id' => $participant['participant_id'],
        'name' => $participant['name'],
        'picture' => $participant['picture']
    ]);
}

function handleAutomaticProgressions($pdo, $matchIds) {
    // Group matches by round
    $matchesByRound = [];
    foreach ($matchIds as $matchId) {
        $match = getMatchDetails($pdo, $matchId);
        $round = $match['tournament_round_text'];
        if (!isset($matchesByRound[$round])) {
            $matchesByRound[$round] = [];
        }
        $matchesByRound[$round][] = $match;
    }

    // Process each round
    foreach ($matchesByRound as $round => $matches) {
        // Process pairs of matches
        for ($i = 0; $i < count($matches); $i += 2) {
            $upperMatch = $matches[$i];
            $lowerMatch = $matches[$i + 1] ?? null;

            if (!$lowerMatch) continue;

            // Get participant counts
            $upperCount = $upperMatch['participant_count'];
            $lowerCount = $lowerMatch['participant_count'];

            // Calculate next round position
            $nextPosition = floor($upperMatch['position'] / 2);
            
            // Get next round match with specific position
            $nextRoundMatch = getNextRoundMatch($pdo, $upperMatch['tournament_id'], intval($round), $nextPosition);
            
            if ($nextRoundMatch) {
                // Case 1: Upper match has participants, lower is empty
                if ($upperCount > 0 && $lowerCount === 0) {
                    processMatchProgression($pdo, $upperMatch, $nextRoundMatch);
                }
                // Case 2: Lower match has participants, upper is empty
                else if ($upperCount === 0 && $lowerCount > 0) {
                    processMatchProgression($pdo, $lowerMatch, $nextRoundMatch);
                }
            }
        }
    }
}

function getNextRoundMatch($pdo, $tournamentId, $currentRound, $nextPosition) {
    $nextRound = $currentRound + 1;
    
    $query = "
        SELECT * FROM matches 
        WHERE tournament_id = ? 
        AND tournament_round_text = ?
        AND position = ?
        AND (
            SELECT COUNT(*) 
            FROM match_participants 
            WHERE match_id = matches.id
        ) = 0
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId, $nextRound, $nextPosition]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function processMatchProgression($pdo, $sourceMatch, $nextRoundMatch) {
    if ($sourceMatch['participant_count'] === 1) {
        // Single participant case
        $participant = getSingleParticipant($pdo, $sourceMatch['id']);
        if ($participant) {
            insertMatchParticipant($pdo, $nextRoundMatch['id'], $participant);
            updateMatchStatus($pdo, $sourceMatch['id'], 'AUTO_PROGRESSED');
        }
    }
    else if ($sourceMatch['participant_count'] === 2) {
        // Two participants case
        $participants = getAllParticipants($pdo, $sourceMatch['id']);
        foreach ($participants as $participant) {
            insertMatchParticipant($pdo, $nextRoundMatch['id'], $participant);
        }
        updateMatchStatus($pdo, $sourceMatch['id'], 'AUTO_PROGRESSED');
    }
}



function getAllParticipants($pdo, $matchId) {
    $query = "
        SELECT * FROM match_participants
        WHERE match_id = ?
        ORDER BY id ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$matchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function getMatchDetails($pdo, $matchId) {
    $query = "
        SELECT m.*, 
               COUNT(mp.id) as participant_count
        FROM matches m
        LEFT JOIN match_participants mp ON m.id = mp.match_id
        WHERE m.id = ?
        GROUP BY m.id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$matchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getSingleParticipant($pdo, $matchId) {
    $query = "
        SELECT * FROM match_participants
        WHERE match_id = ?
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$matchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateMatchStatus($pdo, $matchId, $status) {
    $query = "
        UPDATE matches 
        SET state = ?
        WHERE id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $matchId]);
}

function getGeneratedMatches($pdo, $tournamentId) {
    $query = "
        SELECT m.*, 
               mp.participant_id,
               mp.name as participant_name,
               mp.picture,
               mp.result_text,
               mp.is_winner
        FROM matches m
        LEFT JOIN match_participants mp ON m.id = mp.match_id
        WHERE m.tournament_id = ?
        ORDER BY m.tournament_round_text ASC, m.position ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>