<?php
/**
 * Security questions & smart username generation service
 */

declare(strict_types=1);

/**
 * Returns available security question definitions.
 */
function get_available_security_questions(): array
{
    return [
        'sq_birth_city' => __('sq_birth_city'),
        'sq_elementary_school' => __('sq_elementary_school'),
        'sq_first_pet' => __('sq_first_pet'),
        'sq_favorite_movie' => __('sq_favorite_movie'),
        'sq_favorite_food' => __('sq_favorite_food'),
        'sq_mother_city' => __('sq_mother_city'),
        'sq_father_birth_year' => __('sq_father_birth_year'),
        'sq_childhood_nickname' => __('sq_childhood_nickname'),
        'sq_first_attended_school' => __('sq_first_attended_school'),
    ];
}

/**
 * Generates a clean, standardized, collision-free username from a full name.
 * e.g., "Muhammad Haziq bin Idrus" => "m_haziq_idrus"
 * If "m_haziq_idrus" exists, increments to "m_haziq_idrus2", etc.
 */
function generate_smart_username(string $fullName, ?int $excludeUserId = null): string
{
    $name = trim($fullName);
    if ($name === '') {
        $name = 'staff';
    }

    // Convert accents/diacritics if transliteration available
    if (function_exists('iconv')) {
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($trans !== false && $trans !== '') {
            $name = $trans;
        }
    }

    // Lowercase and strip compound patronymics
    $clean = strtolower($name);
    $clean = preg_replace('/\b(a\/l|a\/p|s\/o|d\/o|bin|binti|bte|bt|anak|ak)\b/i', ' ', $clean);
    $clean = preg_replace('/[\'\`]/', '', $clean); // D'Souza => dsouza
    $clean = preg_replace('/[^a-z0-9\s]/', ' ', $clean);

    $tokens = array_values(array_filter(explode(' ', $clean), fn($t) => strlen($t) > 0));

    // Common Southeast Asian / Malaysian patronymics and stopwords to ignore
    $stopwords = ['bin', 'binti', 'bt', 'b', 'al', 'ap', 'anak', 'ak', 'so', 'do', 'a', 'l', 'p', 's', 'd'];
    $filtered = [];
    foreach ($tokens as $token) {
        if (!in_array($token, $stopwords, true)) {
            $filtered[] = $token;
        }
    }

    if (empty($filtered)) {
        $filtered = ['staff'];
    }

    $count = count($filtered);
    if ($count === 1) {
        $base = $filtered[0];
    } elseif ($count === 2) {
        // e.g. Alice Smith => a_smith
        $base = substr($filtered[0], 0, 1) . '_' . $filtered[1];
    } elseif ($count === 3) {
        // e.g. Muhammad Haziq Idrus => m_haziq_idrus
        $base = substr($filtered[0], 0, 1) . '_' . $filtered[1] . '_' . $filtered[2];
    } else {
        // 4+ words: take first initial, second name, and last name
        $base = substr($filtered[0], 0, 1) . '_' . $filtered[1] . '_' . $filtered[$count - 1];
    }

    $base = preg_replace('/_+/', '_', trim($base, '_'));
    if ($base === '' || strlen($base) < 2) {
        $base = 'staff_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // Truncate base to 45 chars so suffix fits in 64 char column
    $base = substr($base, 0, 45);

    // Collision check
    $candidate = $base;
    $suffix = 1;
    while (true) {
        $sql = 'SELECT id FROM users WHERE username = ?';
        $params = [$candidate];
        if ($excludeUserId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $candidate;
        }

        $suffix++;
        $candidate = $base . $suffix;
    }
}

/**
 * Saves or updates 2 security questions and hashed answers for a user.
 */
function save_user_security_questions(int $userId, string $q1, string $a1, string $q2, string $a2): array
{
    $validQuestions = get_available_security_questions();
    if ($q1 === '' || $q2 === '' || !isset($validQuestions[$q1]) || !isset($validQuestions[$q2])) {
        return ['success' => false, 'message' => __('msg_select_valid_questions')];
    }
    if ($q1 === $q2) {
        return ['success' => false, 'message' => __('msg_questions_must_differ')];
    }

    $ans1 = mb_strtolower(trim($a1), 'UTF-8');
    $ans2 = mb_strtolower(trim($a2), 'UTF-8');

    if (mb_strlen($ans1) < 2 || mb_strlen($ans2) < 2) {
        return ['success' => false, 'message' => __('msg_answers_min_length')];
    }

    $hash1 = password_hash($ans1, PASSWORD_DEFAULT);
    $hash2 = password_hash($ans2, PASSWORD_DEFAULT);

    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM user_security_questions WHERE user_id = ?')->execute([$userId]);

        $ins = db()->prepare('INSERT INTO user_security_questions (user_id, question_key, answer_hash) VALUES (?, ?, ?)');
        $ins->execute([$userId, $q1, $hash1]);
        $ins->execute([$userId, $q2, $hash2]);

        if ($ownsTransaction) db()->commit();
        return ['success' => true, 'message' => ''];
    } catch (Throwable $e) {
        if ($ownsTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        error_log((string) $e);
        return ['success' => false, 'message' => __('msg_process_fail')];
    }
}

/**
 * Fetches configured security questions for a user (without answers).
 */
function get_user_security_questions(int $userId): array
{
    $stmt = db()->prepare('SELECT id, question_key FROM user_security_questions WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $available = get_available_security_questions();
    $result = [];
    foreach ($rows as $r) {
        $key = (string) $r['question_key'];
        $result[] = [
            'id'           => (int) $r['id'],
            'question_key' => $key,
            'label'        => $available[$key] ?? __($key),
        ];
    }
    return $result;
}

/**
 * Verifies answers provided for a user's security questions.
 */
function verify_user_security_questions(int $userId, string $a1, string $a2): bool
{
    $stmt = db()->prepare('SELECT answer_hash FROM user_security_questions WHERE user_id = ? ORDER BY id ASC LIMIT 2');
    $stmt->execute([$userId]);
    $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($hashes) < 2) {
        return false;
    }

    $norm1 = mb_strtolower(trim($a1), 'UTF-8');
    $norm2 = mb_strtolower(trim($a2), 'UTF-8');

    return password_verify($norm1, (string) $hashes[0]) && password_verify($norm2, (string) $hashes[1]);
}

/**
 * Sets a temporary password for a user and sets must_change_password flag.
 */
function set_user_temporary_password(int $userId, string $tempPassword, bool $mustChange = true): bool
{
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = ?, auth_version = auth_version + 1 WHERE id = ?');
    return $stmt->execute([$hash, $mustChange ? 1 : 0, $userId]);
}
