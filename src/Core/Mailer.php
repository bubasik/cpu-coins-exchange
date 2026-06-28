<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = Config::get('MAIL_HOST', 'localhost');
            $mail->Port = (int)Config::get('MAIL_PORT', 587);
            $mail->SMTPAuth = (bool)Config::get('MAIL_USER');
            $mail->Username = Config::get('MAIL_USER', '');
            $mail->Password = Config::get('MAIL_PASSWORD', '');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom(
                Config::get('MAIL_FROM', 'noreply@localhost'),
                Config::get('MAIL_FROM_NAME', 'Exchange')
            );
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            return $mail->send();
        } catch (Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    public static function sendWelcome(string $email, string $lang = 'en'): void
    {
        $subjects = [
            'en' => 'Welcome to Yenten-Sugar Exchange',
            'ru' => 'Добро пожаловать в Yenten-Sugar Exchange',
            'ja' => 'Yenten-Sugar Exchangeへようこそ',
            'zh' => '欢迎使用 Yenten-Sugar 交易所',
        ];
        $bodies = [
            'en' => '<h2>Welcome!</h2><p>Your account is registered on Yenten-Sugar Exchange.</p>',
            'ru' => '<h2>Добро пожаловать!</h2><p>Ваш аккаунт зарегистрирован на Yenten-Sugar Exchange.</p>',
            'ja' => '<h2>ようこそ！</h2><p>Yenten-Sugar Exchangeにアカウントが登録されました。</p>',
            'zh' => '<h2>欢迎！</h2><p>您的账户已在 Yenten-Sugar 交易所注册。</p>',
        ];
        self::send($email, $subjects[$lang] ?? $subjects['en'], $bodies[$lang] ?? $bodies['en']);
    }
}
