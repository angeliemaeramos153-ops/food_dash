<?php
/**
 * Food Dash - Custom CAPTCHA & Anti-Bot Protection Library
 * Senior Full-Stack Engineering Architecture
 * 
 * Features:
 * - Dynamic generation of custom "I'm not a robot" challenges (randomized math puzzles & security codes)
 * - Threshold-based activation (e.g. >= 2 failed attempts or active lockout)
 * - Strict verification with auto-regeneration on failure & replay prevention
 * - Full first-time bypass for smooth UX
 * - Audio challenge synthesis text support for accessibility
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('CAPTCHA_FAILURE_THRESHOLD')) {
    define('CAPTCHA_FAILURE_THRESHOLD', 2);
}

/**
 * Check if CAPTCHA verification is required for the current request.
 *
 * @param PDO|null $conn Database connection
 * @param string|null $username Username or email attempted
 * @param int $lockout_remaining_seconds Active lockout duration in seconds
 * @return bool True if failed attempts >= threshold or active lockout
 */
function is_captcha_required(?PDO $conn = null, ?string $username = null, int $lockout_remaining_seconds = 0): bool {
    // 1. Active lockout takes highest priority
    if ($lockout_remaining_seconds > 0 || !empty($_SESSION['lockout_remaining_seconds'])) {
        return true;
    }

    // 2. Session-based failed attempts counter
    $session_failures = (int)($_SESSION['login_attempts'] ?? 0);
    if ($session_failures >= CAPTCHA_FAILURE_THRESHOLD) {
        return true;
    }

    // 3. User-specific database check if username provided
    if ($conn !== null && !empty($username)) {
        try {
            $stmt = $conn->prepare("SELECT failed_login_attempts, failed_attempts, lockout_until FROM users WHERE username = :u OR email = :e LIMIT 1");
            $stmt->execute([':u' => $username, ':e' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $db_failed = (int)($user['failed_login_attempts'] ?? $user['failed_attempts'] ?? 0);
                $lockout_until = $user['lockout_until'] ?? null;
                $is_locked = (!empty($lockout_until) && strtotime($lockout_until) > time());
                if ($db_failed >= CAPTCHA_FAILURE_THRESHOLD || $is_locked) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // Fallback to session check on database errors
        }
    }

    return false;
}

/**
 * Generate a randomized CAPTCHA challenge and store it in session.
 * Exclusively uses single-digit addition with numbers between 1 and 9 (e.g. 3 + 4).
 * Multiplication and complex operators are completely removed.
 *
 * @param bool $force_new If true, forces generation of a new challenge
 * @return array Challenge payload [id, type, question, display, answer, audio_text]
 */
function generate_captcha_challenge(bool $force_new = false): array {
    if (!$force_new && !empty($_SESSION['login_captcha']['answer']) && !empty($_SESSION['login_captcha']['display'])) {
        $created = $_SESSION['login_captcha']['created_at'] ?? 0;
        if (time() - $created < 900) { // Valid for 15 minutes
            return $_SESSION['login_captcha'];
        }
    }

    // Single-digit addition operator pool only: numbers between 1 and 9
    $ops = ['+'];
    $op = $ops[0];
    $num1 = rand(1, 9);
    $num2 = rand(1, 9);
    $answer = $num1 + $num2;
    $display = "{$num1} + {$num2} = ?";
    $question = "What is {$num1} + {$num2}?";
    $audio_text = "Security challenge: What is {$num1} plus {$num2}?";

    $challenge = [
        'id'         => bin2hex(random_bytes(8)),
        'type'       => 'math',
        'display'    => $display,
        'question'   => $question,
        'answer'     => (string)$answer,
        'audio_text' => $audio_text,
        'created_at' => time()
    ];

    $_SESSION['login_captcha'] = $challenge;
    return $challenge;
}

/**
 * Validate submitted CAPTCHA answer against the session challenge.
 * Automatically regenerates a fresh challenge to prevent replay attacks.
 *
 * @param string|null $submitted_answer User's input
 * @return bool True if matches correctly, false otherwise
 */
function verify_captcha_submission(?string $submitted_answer): bool {
    if (empty($_SESSION['login_captcha']['answer'])) {
        generate_captcha_challenge(true);
        return false;
    }

    $expected = trim(strtolower((string)$_SESSION['login_captcha']['answer']));
    $actual = trim(strtolower((string)($submitted_answer ?? '')));

    $is_valid = ($expected !== '' && $actual === $expected);

    // Regenerate challenge immediately to prevent re-use/brute-force
    generate_captcha_challenge(true);

    return $is_valid;
}

/**
 * Increment failed login attempts counter in session.
 *
 * @return int New failure count
 */
function record_failed_login_attempt(): int {
    $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
    return $_SESSION['login_attempts'];
}

/**
 * Reset all login failure and CAPTCHA counters upon successful authentication.
 */
function reset_captcha_and_attempts(): void {
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['login_captcha']);
    unset($_SESSION['lockout_remaining_seconds']);
}
