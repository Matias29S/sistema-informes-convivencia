<?php
/** Envío de correos: modo log (desarrollo) o SMTP real (producción). */

class Mailer {
    public static function send(string $para, string $asunto, string $cuerpo): bool {
        $para = trim($para);
        if (!es_email($para)) return false;
        $asunto = str_replace(["\r", "\n"], '', $asunto);
        $desde = cfg('smtp_desde', 'det_35_de18@bue.edu.ar');
        $modo = cfg('mail_mode', 'log');

        if ($modo === 'smtp') return self::smtp($para, $asunto, $cuerpo, $desde);

        // Modo desarrollo: se guarda en storage/mail.log para poder comprobar los envíos.
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $linea = '[' . date('Y-m-d H:i:s') . "] PARA: {$para} | ASUNTO: {$asunto}\n";
        $linea .= $cuerpo . "\n" . str_repeat('-', 70) . "\n";
        return (bool)file_put_contents($dir . '/mail.log', $linea, FILE_APPEND | LOCK_EX);
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
