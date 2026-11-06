<?php
/**
 * Test Suite for Food Dash Custom CAPTCHA Feature
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../captcha_lib.php';

echo "====================================================\n";
echo "FOOD DASH - CUSTOM CAPTCHA VERIFICATION TEST SUITE\n";
echo "====================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test($description, $condition) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo " [PASS] {$description}\n";
        $pass_count++;
    } else {
        echo " [FAIL] {$description}\n";
        $fail_count++;
    }
}

// Ensure clean test state for user 'admin'
$conn->exec("UPDATE users SET failed_login_attempts = 0, failed_attempts = 0, lockout_until = NULL WHERE username = 'admin'");

// =========================================================================
// TEST 1: First-Time Login (Smooth User Experience & Bypassed CAPTCHA)
// =========================================================================
echo "Test 1: Normal First-Time Login Bypass\n";
$_SESSION = [];
$req = is_captcha_required($conn, 'admin', 0);
assert_test("CAPTCHA is NOT required on first-time login (failed_attempts = 0)", $req === false);

// =========================================================================
// TEST 2: Challenge Generation
// =========================================================================
echo "\nTest 2: Challenge Generation\n";
$challenge = generate_captcha_challenge(true);
assert_test("Challenge array has id, type, display, question, answer", 
    !empty($challenge['id']) && !empty($challenge['type']) && !empty($challenge['display']) && !empty($challenge['question']) && isset($challenge['answer'])
);
assert_test("Challenge stored in session", !empty($_SESSION['login_captcha']['answer']));
assert_test("Session answer matches returned challenge answer", $_SESSION['login_captcha']['answer'] === $challenge['answer']);

// =========================================================================
// TEST 3: Verification & Auto-Regeneration on Failure
// =========================================================================
echo "\nTest 3: CAPTCHA Validation & Anti-Replay Auto-Regeneration\n";
$initial_answer = $_SESSION['login_captcha']['answer'];
$initial_id = $_SESSION['login_captcha']['id'];

// Test wrong answer
$valid_wrong = verify_captcha_submission('wrong_answer_123');
assert_test("verify_captcha_submission returns FALSE for wrong answer", $valid_wrong === false);
assert_test("New challenge generated immediately after verification attempt", $_SESSION['login_captcha']['id'] !== $initial_id);

// Test correct answer on new challenge
$new_answer = $_SESSION['login_captcha']['answer'];
$valid_correct = verify_captcha_submission($new_answer);
assert_test("verify_captcha_submission returns TRUE for correct answer", $valid_correct === true);

// =========================================================================
// TEST 4: Threshold-Based Activation (>= 2 Failed Attempts)
// =========================================================================
echo "\nTest 4: Threshold-Based Activation (>= 2 Failed Attempts)\n";
$_SESSION = [];
assert_test("Initially attempts = 0, captcha required = false", is_captcha_required($conn, null, 0) === false);

record_failed_login_attempt();
assert_test("After 1 failed attempt (attempts = 1), captcha required = false (< 2 threshold)", is_captcha_required($conn, null, 0) === false);

record_failed_login_attempt();
assert_test("After 2 failed attempts (attempts = 2), captcha required = TRUE (threshold reached)", is_captcha_required($conn, null, 0) === true);

// =========================================================================
// TEST 5: Lockout Activation
// =========================================================================
echo "\nTest 5: Active Lockout Triggers CAPTCHA\n";
$_SESSION = [];
assert_test("Active lockout (remaining seconds > 0) triggers captcha", is_captcha_required($conn, null, 120) === true);

// =========================================================================
// TEST 6: Reset on Successful Authentication
// =========================================================================
echo "\nTest 6: State Reset on Authentication\n";
$_SESSION['login_attempts'] = 4;
$_SESSION['login_captcha'] = ['answer' => '42'];
reset_captcha_and_attempts();
assert_test("login_attempts reset to 0", ($_SESSION['login_attempts'] ?? null) === 0);
assert_test("login_captcha removed from session", empty($_SESSION['login_captcha']));

// =========================================================================
// TEST 7: Simulated Full HTTP POST to login.php
// =========================================================================
echo "\nTest 7: Simulated Full POST to login.php\n";

// Helper to run simulated POST via CLI PHP with shutdown capture
function run_login_simulation($post_data, $session_data = []) {
    $script = tempnam(__DIR__, 'cap_');
    $result_file = tempnam(__DIR__, 'res_');
    $serialized_post = base64_encode(serialize($post_data));
    $serialized_session = base64_encode(serialize($session_data));
    $app_dir = addslashes(dirname(__DIR__));
    $escaped_result_file = addslashes($result_file);

    $code = <<<PHP
<?php
session_start();
\$_SESSION = unserialize(base64_decode('{$serialized_session}'));
\$_POST = unserialize(base64_decode('{$serialized_post}'));
\$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
chdir('{$app_dir}');

register_shutdown_function(function() {
    \$output = ob_get_clean();
    file_put_contents('{$escaped_result_file}', json_encode([
        'session'          => \$_SESSION,
        'html'             => \$output,
        'has_captcha_err'  => strpos(\$output, 'Security verification failed') !== false,
        'has_cred_err'     => strpos(\$output, 'Incorrect username or password') !== false,
        'has_captcha_box'  => strpos(\$output, 'id="captchaWrapper" style="display: block;"') !== false,
        'redirect'         => strpos(\$output, 'admin_dashboard.php') !== false || strpos(\$output, 'customer_dashboard.php') !== false
    ]));
});

require '{$app_dir}/login.php';
PHP;
    file_put_contents($script, $code);
    $cmd = "C:\\xampp\\php\\php.exe " . escapeshellarg($script);
    shell_exec($cmd);
    $res = json_decode(file_get_contents($result_file), true);
    @unlink($script);
    @unlink($result_file);
    return $res;
}

// 7a: First attempt with wrong credentials (no captcha provided, none required)
$res1 = run_login_simulation([
    'login' => '1',
    'username' => 'admin',
    'password' => 'wrong_password_1'
], ['login_attempts' => 0]);

assert_test("1st failure: returns incorrect password message", 
    ($res1['has_cred_err'] ?? false) === true
);
assert_test("1st failure: login_attempts incremented to 1", 
    ($res1['session']['login_attempts'] ?? 0) === 1
);
assert_test("1st failure: captcha not rendered yet", 
    ($res1['has_captcha_box'] ?? false) === false
);

// 7b: Second attempt with wrong credentials (reaches threshold of 2)
$res2 = run_login_simulation([
    'login' => '1',
    'username' => 'admin',
    'password' => 'wrong_password_2'
], ['login_attempts' => 1]);

assert_test("2nd failure: login_attempts incremented to 2", 
    ($res2['session']['login_attempts'] ?? 0) === 2
);
assert_test("2nd failure: CAPTCHA is now dynamically rendered on login form", 
    ($res2['has_captcha_box'] ?? false) === true
);

// 7c: Third attempt without CAPTCHA when threshold is reached
$res3 = run_login_simulation([
    'login' => '1',
    'username' => 'admin',
    'password' => 'wrong_password_3'
], ['login_attempts' => 2]);

assert_test("Submission without CAPTCHA rejected with Security verification error", 
    ($res3['has_captcha_err'] ?? false) === true
);

// 7d: Attempt with wrong CAPTCHA answer
$res4 = run_login_simulation([
    'login' => '1',
    'username' => 'admin',
    'password' => 'admin123',
    'captcha_response' => 'wrong_answer'
], [
    'login_attempts' => 2,
    'login_captcha' => [
        'id' => 'abc',
        'type' => 'math',
        'question' => 'What is 5 + 5?',
        'display' => '5 + 5 = ?',
        'answer' => '10',
        'created_at' => time()
    ]
]);

assert_test("Submission with incorrect CAPTCHA rejected even with correct password", 
    ($res4['has_captcha_err'] ?? false) === true
);

// 7e: Attempt with correct CAPTCHA and correct credentials
$res5 = run_login_simulation([
    'login' => '1',
    'username' => 'admin',
    'password' => 'admin123',
    'captcha_response' => '10'
], [
    'login_attempts' => 2,
    'login_captcha' => [
        'id' => 'abc',
        'type' => 'math',
        'question' => 'What is 5 + 5?',
        'display' => '5 + 5 = ?',
        'answer' => '10',
        'created_at' => time()
    ]
]);

assert_test("Submission with correct CAPTCHA authenticates user successfully", 
    ($res5['redirect'] ?? false) === true && ($res5['session']['role'] ?? '') === 'admin'
);
assert_test("login_attempts reset to 0 after success", 
    ($res5['session']['login_attempts'] ?? null) === 0
);

// =========================================================================
// TEST 8: AJAX CAPTCHA API (captcha_api.php)
// =========================================================================
echo "\nTest 8: Interactive AJAX API (captcha_api.php)\n";

// Helper for calling API in isolation
function call_api($query_params, $session = []) {
    $code = 'session_start(); $_SESSION = ' . var_export($session, true) . '; $_GET = ' . var_export($query_params, true) . '; require \'captcha_api.php\';';
    $tmp = tempnam(__DIR__, 'api_');
    file_put_contents($tmp, "<?php " . $code);
    $out = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg($tmp));
    @unlink($tmp);
    return json_decode($out, true);
}

// 8a: Refresh action
$api_res = call_api(['action' => 'refresh']);
assert_test("captcha_api.php?action=refresh returns status=success", ($api_res['status'] ?? '') === 'success');
assert_test("captcha_api.php?action=refresh returns display, question, and type", 
    !empty($api_res['display']) && !empty($api_res['question']) && !empty($api_res['type'])
);

// 8b: Check action with 0 failed attempts
$api_check_res = call_api(['action' => 'check', 'username' => 'admin'], ['login_attempts' => 0]);
assert_test("captcha_api.php?action=check returns captcha_required=false for clean account", 
    ($api_check_res['captcha_required'] ?? true) === false
);

// 8c: Check action with >= 2 failed attempts
$api_check2_res = call_api(['action' => 'check', 'username' => 'admin'], ['login_attempts' => 2]);
assert_test("captcha_api.php?action=check returns captcha_required=true when threshold reached", 
    ($api_check2_res['captcha_required'] ?? false) === true
);

// Reset admin user in database to clean state
$conn->exec("UPDATE users SET failed_login_attempts = 0, failed_attempts = 0, lockout_until = NULL WHERE username = 'admin'");

echo "\n====================================================\n";
echo "TEST RESULTS: {$pass_count} PASSED, {$fail_count} FAILED\n";
echo "====================================================\n";

if ($fail_count === 0) {
    echo "\n>>> ALL CAPTCHA TESTS PASSED PERFECTLY! <<<\n";
}
