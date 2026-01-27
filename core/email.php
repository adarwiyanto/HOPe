<?php
require_once __DIR__ . '/functions.php';

function mail_settings(): array {
  return [
    'host' => setting('smtp_host', 'mail.hopenoodles.my.id'),
    'port' => (int)setting('smtp_port', '465'),
    'secure' => setting('smtp_secure', 'ssl'),
    'user' => setting('smtp_user', 'admin@hopenoodles.my.id'),
    'pass' => setting('smtp_pass', 'AdminHope'),
    'from_email' => setting('smtp_from_email', 'admin@hopenoodles.my.id'),
    'from_name' => setting('smtp_from_name', 'Hope Noodles'),
  ];
}

function send_email_smtp(string $to, string $subject, string $body): bool {
  $cfg = mail_settings();
  $host = $cfg['host'];
  $port = (int)$cfg['port'];
  $secure = strtolower(trim((string)$cfg['secure']));
  $remoteHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
  $remote = "{$remoteHost}:{$port}";

  $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
  if (!$fp) return false;

  stream_set_timeout($fp, 15);

  $read = function() use ($fp): string {
    $data = '';
    while (!feof($fp)) {
      $line = @fgets($fp, 515);
      if ($line === false) break;
      $data .= $line;
      if (preg_match('/^\d{3} /', $line)) break;
    }
    return $data;
  };

  $send = function(string $command) use ($fp, $read): string {
    $written = @fwrite($fp, $command . "\r\n");
    if ($written === false) return '';
    return $read();
  };

  $read();
  $send('EHLO localhost');
  if ($secure === 'tls') {
    $send('STARTTLS');
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($fp);
      return false;
    }
    $send('EHLO localhost');
  }
  $send('AUTH LOGIN');
  $send(base64_encode($cfg['user']));
  $send(base64_encode($cfg['pass']));

  $fromEmail = $cfg['from_email'];
  $fromName = $cfg['from_name'];
  $headers = [];
  $headers[] = 'From: "' . addslashes($fromName) . "\" <{$fromEmail}>";
  $headers[] = 'To: <' . $to . '>';
  $headers[] = 'Subject: ' . $subject;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/html; charset=UTF-8';
  $headersStr = implode("\r\n", $headers);

  $send("MAIL FROM:<{$fromEmail}>");
  $send("RCPT TO:<{$to}>");
  $send('DATA');
  @fwrite($fp, $headersStr . "\r\n\r\n" . $body . "\r\n.\r\n");
  $read();
  $send('QUIT');
  fclose($fp);
  return true;
}

function send_invite_email(string $email, string $token, string $role): bool {
  $link = base_url('invite.php?token=' . urlencode($token));
  $subject = 'Undangan akun Hope Noodles';
  $body = "<p>Halo,</p>"
    . "<p>Anda diundang sebagai <strong>" . e($role) . "</strong>.</p>"
    . "<p>Silakan klik link berikut untuk melengkapi data akun:</p>"
    . "<p><a href=\"{$link}\">{$link}</a></p>"
    . "<p>Jika Anda tidak meminta akun ini, abaikan email ini.</p>";

  return send_email_smtp($email, $subject, $body);
}
