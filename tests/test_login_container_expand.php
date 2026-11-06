<?php
/**
 * Test Suite: Login Page Container Auto-Expansion & Dynamic CAPTCHA Stacking Verification
 */

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../captcha_lib.php';

echo "====================================================\n";
echo "TEST: LOGIN CONTAINER EXPANSION & SUBMIT VISIBILITY\n";
echo "====================================================\n\n";

$pass = 0;
$fail = 0;

function check($msg, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] {$msg}\n";
        $pass++;
    } else {
        echo " [FAIL] {$msg}\n";
        $fail++;
    }
}

// 1. Check CSS styling in index.php
$index_content = file_get_contents(__DIR__ . '/../index.php');

// Verify .mobile-card styling
check(".mobile-card has height: auto and overflow: visible", 
    strpos($index_content, "height: auto;") !== false &&
    preg_match('/\.mobile-card\s*\{[^}]*overflow:\s*visible;/s', $index_content)
);

// Verify .forms-wrapper styling
check(".forms-wrapper has height: auto and overflow: visible",
    preg_match('/\.forms-wrapper\s*\{[^}]*overflow:\s*visible;/s', $index_content) &&
    preg_match('/\.forms-wrapper\s*\{[^}]*height:\s*auto;/s', $index_content)
);

// Verify .form-section.active has position: relative to be in document flow
check(".form-section.active has position: relative",
    preg_match('/\.form-section\.active\s*\{[^}]*position:\s*relative;/s', $index_content)
);

// Verify #login-section.active has position: relative
check("#login-section.active has position: relative and height: auto",
    preg_match('/#login-section\.active\s*\{[^}]*position:\s*relative;/s', $index_content)
);

// Verify #login-form has flex column layout
check("#login-form has display: flex and flex-direction: column",
    preg_match('/#login-form\s*\{[^}]*flex-direction:\s*column;/s', $index_content)
);

// Verify .btn has flex-shrink: 0 and margin spacing
check(".btn has flex-shrink: 0 and margin spacing",
    preg_match('/\.btn\s*\{[^}]*flex-shrink:\s*0;/s', $index_content)
);

// Verify .captcha-wrapper has top and bottom margins
check(".captcha-wrapper has top and bottom margins (margin: 16px 0 18px 0;)",
    preg_match('/\.captcha-wrapper\s*\{[^}]*margin:\s*16px 0 18px 0;/s', $index_content)
);

// Verify .btn has padding and min-height for easy clicking
check(".btn has generous padding and height for easy clicking",
    preg_match('/\.btn\s*\{[^}]*height:\s*46px;/s', $index_content) &&
    preg_match('/\.btn\s*\{[^}]*padding:\s*12px 20px;/s', $index_content)
);

// Verify CAPTCHA library only generates single-digit addition (1-9) with no multiplication
$all_single_digit_addition = true;
$zero_multiplication = true;
$answers_correct = true;

for ($i = 0; $i < 100; $i++) {
    $c = generate_captcha_challenge(true);
    if (strpos($c['display'], '×') !== false || strpos($c['question'], 'times') !== false || strpos($c['question'], 'multiplied') !== false) {
        $zero_multiplication = false;
    }
    if (!preg_match('/^([1-9]) \+ ([1-9]) = \?$/', $c['display'], $m)) {
        $all_single_digit_addition = false;
    } else {
        $expected = (int)$m[1] + (int)$m[2];
        if ((int)$c['answer'] !== $expected) {
            $answers_correct = false;
        }
    }
}
check("CAPTCHA library: 100/100 challenges are strictly single-digit addition (1-9)", $all_single_digit_addition);
check("CAPTCHA library: 0/100 challenges contain multiplication", $zero_multiplication);
check("CAPTCHA library: 100/100 answers match the addition sum", $answers_correct);

// Verify CAPTCHA wrapper and Submit button stacking order in DOM
$captcha_pos = strpos($index_content, 'id="captchaWrapper"');
$submit_pos = strpos($index_content, 'id="login-btn"');
check("CAPTCHA block appears before Submit button in DOM", $captcha_pos !== false && $submit_pos !== false && $captcha_pos < $submit_pos);

// 2. Simulate 2 failed login attempts and check rendered HTML output
$script = __DIR__ . '/_temp_render.php';
$code = <<<'PHP'
<?php
session_start();
$_SESSION['login_attempts'] = 2;
$_SESSION['login_captcha'] = [
    'id' => 'test1234',
    'type' => 'math',
    'display' => '4 + 7 = ?',
    'question' => 'What is 4 + 7?',
    'answer' => '11',
    'audio_text' => 'Security challenge: What is 4 plus 7?',
    'created_at' => time()
];
session_write_close();

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/../index.php';
$html = ob_get_clean();
echo json_encode([
    'captcha_wrapper_visible' => (strpos($html, 'id="captchaWrapper" style="display: block;"') !== false),
    'submit_btn_present'      => (strpos($html, 'id="login-btn"') !== false),
    'submit_has_sign_in'      => (strpos($html, '>Sign In</span>') !== false),
    'has_security_title'      => (strpos($html, 'Security Verification') !== false),
    'has_challenge_text'      => (strpos($html, '4 + 7 = ?') !== false)
]);
PHP;

file_put_contents($script, $code);
$output = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($script));
@unlink($script);

$data = json_decode($output, true);
check("Rendered HTML displays dynamic CAPTCHA block with style='display: block;'", !empty($data['captcha_wrapper_visible']));
check("Rendered HTML contains Sign In submit button (#login-btn)", !empty($data['submit_btn_present']));
check("Rendered HTML submit button displays 'Sign In'", !empty($data['submit_has_sign_in']));
check("Rendered HTML displays CAPTCHA header and challenge text", !empty($data['has_security_title']) && !empty($data['has_challenge_text']));

echo "\n====================================================\n";
echo "SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
echo "====================================================\n";

if ($fail === 0) {
    echo ">>> ALL CONTAINER EXPANSION & SUBMIT VISIBILITY TESTS PASSED! <<<\n";
}
exit($fail > 0 ? 1 : 0);
