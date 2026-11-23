<?php
/** Envío de correos: modo log (desarrollo) o SMTP real (producción). */

class Mailer {
    private static $ultimaRespuesta = '';

    public static function send(string $para, string $asunto, string $cuerpo): bool {
        $para = trim($para);
        if (!es_email($para)) return false;
        $asunto = str_replace(["\r", "\n"], '', $asunto);
        $desde = cfg('smtp_desde', 'det_35_de18@bue.edu.ar');
        $modo = cfg('mail_mode', 'log');

        if ($modo === 'smtp') return self::smtp($para, $asunto, $cuerpo, $desde);
        if ($modo === 'mail') return self::php_mail($para, $asunto, $cuerpo, $desde);

        if ($modo === 'formsubmit') {
            $ok = self::formsubmit($para, $asunto, $cuerpo, $desde);
            // Aunque el correo falle, las credenciales quedan registradas para entregarlas a mano.
            self::registrar($para, $asunto, $cuerpo, $ok ? 'OK' : 'FALLO: ' . self::$ultimaRespuesta);
            return $ok;
        }

        // Modo desarrollo: se guarda en storage/mail.log para poder comprobar los envíos.
        self::registrar($para, $asunto, $cuerpo, 'LOG');
        return true;
    }

    /** Guarda todo intento de envío en storage/mail.log con su resultado. */
    private static function registrar(string $para, string $asunto, string $cuerpo, string $resultado): void {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $linea = '[' . date('Y-m-d H:i:s') . "] PARA: {$para} | ASUNTO: {$asunto} | RESULTADO: {$resultado}\n";
        $linea .= $cuerpo . "\n" . str_repeat('-', 70) . "\n";
        @file_put_contents($dir . '/mail.log', $linea, FILE_APPEND | LOCK_EX);
    }

    /** Envío por FormSubmit.co: no requiere SMTP ni contraseña, pero cada destinatario se activa una vez. */
    private static function formsubmit(string $para, string $asunto, string $cuerpo, string $desde): bool {
        self::$ultimaRespuesta = '';
        // FormSubmit puede tardar casi un minuto en responder: no cortar el envío por el límite de PHP.
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $url = 'https://formsubmit.co/ajax/' . rawurlencode($para);
        $payload = json_encode([
            '_subject' => $asunto,
            '_from' => $desde,
            'mensaje' => $cuerpo,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) return false;
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n"
                    . "Origin: " . APP_URL . "\r\nReferer: " . APP_URL . "\r\n",
                'content' => $payload,
                'timeout' => 90,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            self::$ultimaRespuesta = 'sin respuesta de FormSubmit';
            return false;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            self::$ultimaRespuesta = 'respuesta inválida';
            return false;
        }
        self::$ultimaRespuesta = (string)($data['message'] ?? 'respuesta sin mensaje');
        $ok = $data['success'] ?? false;
        return filter_var($ok, FILTER_VALIDATE_BOOLEAN);
    }

    /** Envío con la función mail() del servidor (útil en hosting compartido que lo habilita). */
    private static function php_mail(string $para, string $asunto, string $cuerpo, string $desde): bool {
        $cabeceras = "From: {$desde}\r\n"
            . "Reply-To: {$desde}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";
        return @mail($para, self::codificar($asunto), $cuerpo, $cabeceras);
    }

    /** Cliente SMTP mínimo con STARTTLS y AUTH LOGIN (sin dependencias externas). */
    private static function smtp(string $para, string $asunto, string $cuerpo, string $desde): bool {
        $host = cfg('smtp_host', 'smtp.gmail.com');
        $puerto = (int)cfg('smtp_puerto', '587');
        $usuario = cfg('smtp_usuario');
        $clave = cfg('smtp_password');
        $seguro = strtolower(cfg('smtp_seguro', 'tls'));

        // SSL implícito (puerto 465) abre el socket cifrado desde el inicio.
        $destino = $seguro === 'ssl' ? 'ssl://' . $host : $host;
        $fp = @fsockopen($destino, $puerto, $errno, $errstr, 15);
        if (!$fp) return false;
        stream_set_timeout($fp, 20);

        self::leer($fp); // 220
        self::enviar($fp, 'EHLO localhost');
        if ($seguro === 'tls') {
            self::enviar($fp, 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            self::enviar($fp, 'EHLO localhost');
        }
        if ($usuario !== '') {
            self::enviar($fp, 'AUTH LOGIN');
            self::enviar($fp, base64_encode($usuario));
            self::enviar($fp, base64_encode($clave));
        }
        self::enviar($fp, 'MAIL FROM:<' . $desde . '>');
        self::enviar($fp, 'RCPT TO:<' . $para . '>');
        self::enviar($fp, 'DATA');

        $mensaje = "From: {$desde}\r\nTo: {$para}\r\n";
        $mensaje .= 'Subject: ' . self::codificar($asunto) . "\r\n";
        $mensaje .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $mensaje .= str_replace("\n.", "\n..", $cuerpo) . "\r\n.\r\n";
        fwrite($fp, $mensaje);
        self::leer($fp);
        self::enviar($fp, 'QUIT');
        fclose($fp);
        return true;
    }

    private static function enviar($fp, string $cmd): string {
        fwrite($fp, $cmd . "\r\n");
        return self::leer($fp);
    }

    private static function leer($fp): string {
        $resp = '';
        while (($linea = fgets($fp, 4096)) !== false) {
            $resp .= $linea;
            if (isset($linea[3]) && $linea[3] === ' ') break;
        }
        return $resp;
    }

    private static function codificar(string $s): string {
        return function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($s, 'UTF-8', 'B')
            : '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}
