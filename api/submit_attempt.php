<?php
// Saves one finished level from the game.
// The game sends JSON with POST and the header X-Api-Key.
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'This address works. The game sends results here with POST.']);
}
require_api_key();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    respond(400, ['ok' => false, 'error' => 'body must be JSON']);
}

// ---- check every value before saving ----
$playerUid       = uuid_field($in, 'player_uid');
$clientAttemptId = uuid_field($in, 'client_attempt_id');

$character = $in['character'] ?? '';
if (!is_string($character) || !preg_match('/^[A-Za-z0-9_]{0,20}$/', $character)) {
    respond(422, ['ok' => false, 'error' => 'bad character']);
}
$character = $character === '' ? null : $character;

$playerName = $in['player_name'] ?? '';
if (!is_string($playerName) || ($playerName !== '' && !preg_match('/^[\p{L} \-]{2,20}$/u', $playerName))) {
    respond(422, ['ok' => false, 'error' => 'bad player_name']);
}
$playerName = $playerName === '' ? null : $playerName;
$grade = isset($in['grade']) ? (int) $in['grade'] : 0;
if ($grade < 0 || $grade > 3) {
    respond(422, ['ok' => false, 'error' => 'bad grade']);
}
$grade = $grade === 0 ? null : $grade;

$levelId = $in['level_id'] ?? '';
if (!is_string($levelId) || !preg_match('/^[A-Za-z0-9_]{1,20}$/', $levelId)) {
    respond(422, ['ok' => false, 'error' => 'bad level_id']);
}

$score     = int_field($in, 'score', 0, 10000);
$stars     = int_field($in, 'stars', 0, 3);
$correct   = int_field($in, 'correct_answers', 0, 1000);
$total     = int_field($in, 'total_questions', 0, 1000);
$crystals  = int_field($in, 'crystals_collected', 0, 1000);
$crystTot  = int_field($in, 'total_crystals', 0, 1000);
$hints     = int_field($in, 'hints_used', 0, 1000);
$playedAt  = int_field($in, 'played_at', 1577836800, 4102444800);   // unix time, 2020 to 2100

if (!isset($in['time_seconds']) || !is_numeric($in['time_seconds'])) {
    respond(422, ['ok' => false, 'error' => 'missing time_seconds']);
}
$seconds = round((float) $in['time_seconds'], 2);
if ($seconds <= 0 || $seconds > 86400) {
    log_rejection('time_seconds out of range');
    respond(422, ['ok' => false, 'error' => 'time_seconds out of range']);
}
if ($total < 1 || $correct > $total || $crystals > $crystTot) {
    log_rejection('numbers do not add up');
    respond(422, ['ok' => false, 'error' => 'numbers do not add up']);
}
// SECURITY: the score is calculated in Unity, so the server checks it cannot be
// higher than the answers and crystals allow (stops faked high scores).
$maxScore = $correct * POINTS_PER_CORRECT + $crystals * POINTS_PER_CRYSTAL;
if ($score > $maxScore) {
    log_rejection("score $score above maximum $maxScore");
    respond(422, ['ok' => false, 'error' => 'score higher than allowed']);
}

// ---- answers (optional: older game versions don't send them) ----
$answers = [];
$rawAnswers = $in['answers'] ?? [];
if (!is_array($rawAnswers) || count($rawAnswers) > 100) {
    respond(422, ['ok' => false, 'error' => 'bad answers list']);
}
foreach ($rawAnswers as $n => $a) {
    if (!is_array($a)) {
        respond(422, ['ok' => false, 'error' => "answer $n is not an object"]);
    }
    $text = $a['question_text'] ?? '';
    $op = $a['operation'] ?? '';
    if (!is_string($text) || $text === '' || mb_strlen($text) > 40) {
        respond(422, ['ok' => false, 'error' => "answer $n: bad question_text"]);
    }
    if (!in_array($op, ['+', '-', 'x', '/'], true)) {
        respond(422, ['ok' => false, 'error' => "answer $n: bad operation"]);
    }
    $answers[] = [
        $n + 1,
        $text,
        $op,
        int_field($a, 'correct_answer', -100000, 100000),
        int_field($a, 'first_answer', -100000, 100000),
        empty($a['first_try_correct']) ? 0 : 1,
        empty($a['solved']) ? 0 : 1,
        int_field($a, 'wrong_tries', 0, 50),
        empty($a['used_hint']) ? 0 : 1,
    ];
}

// ---- save ----
try {
    $pdo = db();

    // SECURITY: only levels that exist in the levels table can be saved.
    // (If the ID column in `levels` is not called level_id, change it here.)
    $stmt = $pdo->prepare('SELECT 1 FROM levels WHERE level_id = ?');
    $stmt->execute([$levelId]);
    if (!$stmt->fetchColumn()) {
        log_rejection('unknown level_id');
        respond(422, ['ok' => false, 'error' => 'unknown level_id']);
    }

    $pdo->beginTransaction();

    // find the player, or create them the first time we see this ID
    $stmt = $pdo->prepare(
        'INSERT INTO players (player_uid, character_choice, player_name, grade_level) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE player_id = LAST_INSERT_ID(player_id), last_seen = NOW(),
                                 character_choice = COALESCE(VALUES(character_choice), character_choice),
                                 player_name      = COALESCE(VALUES(player_name), player_name),
                                 grade_level      = COALESCE(VALUES(grade_level), grade_level)'
    );
    $stmt->execute([$playerUid, $character, $playerName, $grade]);
    $playerId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO attempts
           (client_attempt_id, player_id, grade_level, level_id, score, stars, correct_answers, total_questions,
            crystals_collected, total_crystals, hints_used, time_seconds, played_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))'
    );
    $stmt->execute([$clientAttemptId, $playerId, $grade, $levelId, $score, $stars, $correct, $total,
                    $crystals, $crystTot, $hints, $seconds, $playedAt]);
    $duplicate = $stmt->rowCount() === 0;
    $attemptId = $duplicate ? 0 : (int) $pdo->lastInsertId();

    if (!$duplicate && $answers) {
        $stmt = $pdo->prepare(
            'INSERT INTO answers
               (attempt_id, question_no, question_text, operation, correct_answer, first_answer,
                first_try_correct, solved, wrong_tries, used_hint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($answers as $row) {
            $stmt->execute(array_merge([$attemptId], $row));
        }
    }

    $pdo->commit();
    respond(200, ['ok' => true, 'attempt_id' => $attemptId, 'player_id' => $playerId,
                  'duplicate' => $duplicate, 'answers_saved' => $duplicate ? 0 : count($answers)]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('MathQuest submit_attempt: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'server error, see the Apache error log']);
}
