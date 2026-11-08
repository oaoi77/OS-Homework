<?php
const MAIL_HOST = '192.168.10.10';
const MAIL_PORT = 25;
const MAIL_FROM = 'noreply@dobaonhi.com';
const MAIL_TO = 'hr1@dobaonhi.com';
const RATE_LIMIT = 5;          
const RATE_WINDOW = 3600;     

require '/usr/share/php/libphp-phpmailer/src/Exception.php';
require '/usr/share/php/libphp-phpmailer/src/PHPMailer.php';
require '/usr/share/php/libphp-phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, bool $success, string $message = ''): void
{
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Trả về false nếu IP đã gửi quá số lần cho phép trong RATE_WINDOW
function within_rate_limit(string $ip): bool
{
    $path = sys_get_temp_dir() . '/contact_rate_' . sha1($ip);
    $file = fopen($path, 'c+');
    if ($file === false || !flock($file, LOCK_EX)) {
        return true; // lỗi bộ đếm thì không chặn người dùng thật
    }

    $now = time();
    $hits = json_decode(stream_get_contents($file), true);
    $hits = array_values(array_filter(
        is_array($hits) ? $hits : [],
        fn($time) => $time > $now - RATE_WINDOW
    ));

    $allowed = count($hits) < RATE_LIMIT;
    if ($allowed) {
        $hits[] = $now;
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, json_encode($hits));
    }

    flock($file, LOCK_UN);
    fclose($file);
    return $allowed;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed');
}

// Bot điền vào ô ẩn: báo thành công nhưng không gửi
if (!empty($_POST['botcheck'])) {
    respond(200, true);
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if (
    $name === '' || strlen($name) > 100
    || $message === '' || strlen($message) > 5000
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    respond(400, false, 'Invalid input');
}

if (!within_rate_limit($_SERVER['REMOTE_ADDR'])) {
    respond(429, false, 'Too many messages');
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->Port = MAIL_PORT;
    $mail->SMTPAuth = false;
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(MAIL_FROM, 'Website I2T2');
    $mail->addAddress(MAIL_TO);
    $mail->addReplyTo($email, $name);
    $mail->Subject = 'Tin nhắn mới từ website I2T2';
    $mail->Body = "Tên: $name\nEmail: $email\n\n$message";

    $mail->send();
    respond(200, true);
} catch (\Throwable $e) {
    error_log('contact.php: ' . $e->getMessage());
    respond(500, false, 'Send failed');
}
