<?php
/** Configuración institucional y de correo. Solo administradores. */
require_once __DIR__ . '/../config/app.php';
require_role('ADMIN');
global $pdo;

$campos = [
    'escuela_nombre'       => ['Nombre de la escuela', 'text', 180, true],
    'escuela_direccion'    => ['Dirección', 'text', 180, false],
    'escuela_telefono'     => ['Teléfono', 'text', 60, false],
    'escuela_email'        => ['Correo institucional', 'email', 150, true],
    'smtp_host'            => ['Host SMTP', 'text', 150, false],
    'smtp_puerto'          => ['Puerto SMTP', 'text', 10, false],
    'smtp_usuario'         => ['Usuario SMTP', 'text', 150, false],
    'smtp_password'        => ['Contraseña SMTP', 'password', 255, false],
    'smtp_desde'           => ['Remitente (From)', 'email', 150, false],
    'smtp_seguro'          => ['Seguridad SMTP', 'select', 20, false],
    'mail_mode'            => ['Modo de correo', 'select', 20, false],
    'codigo_institucional' => ['Código institucional', 'text', 60, false],
];
$opciones = [
    'smtp_seguro' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'Sin cifrado'],
    'mail_mode'   => ['log' => 'log (desarrollo)', 'mail' => 'mail() de PHP', 'smtp' => 'smtp (producción)', 'formsubmit' => 'FormSubmit'],
];

$valores = [];
$st = $pdo->prepare('SELECT clave, valor FROM configuracion');
$st->execute();
foreach ($st->fetchAll() as $fila) $valores[$fila['clave']] = $fila['valor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nuevos = [];
    $errores = [];
    foreach ($campos as $clave => $def) {
        [$etiqueta, $tipo, $max, $obligatorio] = $def;
        $valor = texto($_POST[$clave] ?? '', 0, $max) ?? '';
        if ($obligatorio && $valor === '') $errores[] = "{$etiqueta} es obligatorio.";
        if ($tipo === 'email' && $valor !== '' && !es_email($valor)) $errores[] = "{$etiqueta} no es un correo válido.";
        if ($clave === 'smtp_puerto' && $valor !== '' && (!ctype_digit($valor) || (int)$valor < 1 || (int)$valor > 65535)) {
            $errores[] = 'El puerto SMTP debe ser un número entre 1 y 65535.';
        }
        if ($clave === 'smtp_seguro' && !array_key_exists($valor, $opciones['smtp_seguro'])) {
            $errores[] = 'La seguridad SMTP no es válida.';
        }
        if ($clave === 'mail_mode' && !array_key_exists($valor, $opciones['mail_mode'])) {
            $errores[] = 'El modo de correo no es válido.';
        }
        $nuevos[$clave] = $valor;
    }
    if ($errores) {
        $valores = array_merge($valores, $nuevos);
        flash('err', implode(' ', $errores));
    } else {
        try {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?)
                                     ON DUPLICATE KEY UPDATE valor=VALUES(valor)');
            foreach ($nuevos as $clave => $valor) $upsert->execute([$clave, $valor]);
            $pdo->commit();
            audit('EDITAR_CONFIGURACION', 'configuracion', null, 'Claves actualizadas: ' . implode(', ', array_keys($nuevos)));
            flash('ok', 'Configuración guardada correctamente.');
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', 'No se pudo guardar la configuración.');
        }
        redirect('admin/configuracion.php');
    }
}

layout_header('Configuración');
?>
<h1>Configuración</h1>
<div class="alert info">
 <b>Seguridad:</b> las credenciales SMTP se guardan en la base de datos. Se recomienda restringir el acceso a la tabla
 <code>configuracion</code> y usar contraseñas de aplicación. Con <code>mail_mode=log</code> los correos no se envían:
 se registran en <code>storage/mail.log</code>.
</div>
<div class="card">
 <form method="post"><?= csrf_field() ?>
  <?php foreach ($campos as $clave => $def): ?>
   <?php [$etiqueta, $tipo, $max, $obligatorio] = $def; ?>
   <label for="<?= e($clave) ?>"><?= e($etiqueta) ?><?= $obligatorio ? ' *' : '' ?></label>
   <?php if ($tipo === 'select'): ?>
    <select id="<?= e($clave) ?>" name="<?= e($clave) ?>" class="select" required>
     <?php foreach ($opciones[$clave] as $valorOpc => $textoOpc): ?>
      <option value="<?= e($valorOpc) ?>" <?= ($valores[$clave] ?? '') === $valorOpc ? 'selected' : '' ?>><?= e($textoOpc) ?></option>
     <?php endforeach; ?>
    </select>
   <?php else: ?>
    <input id="<?= e($clave) ?>" name="<?= e($clave) ?>" type="<?= e($tipo) ?>" class="input"
           maxlength="<?= (int)$max ?>" <?= $obligatorio ? 'required' : '' ?>
           value="<?= e($valores[$clave] ?? '') ?>">
   <?php endif; ?>
  <?php endforeach; ?>
  <p><button class="btn">Guardar configuración</button></p>
 </form>
</div>
<?php layout_footer(); ?>
