(async function () {
await Auth.listo;
const q = new URLSearchParams(location.search);
const portal = q.get('portal') === 'regencia' ? 'regencia' : 'comunidad';

if (Auth.actual()) { location.replace('app.html'); return; }

const f = document.getElementById('f');
const err = document.getElementById('err');
const btn = f.querySelector('button[type=submit]');

document.getElementById('portal').textContent = portal === 'regencia' ? 'Regencia y superiores' : 'Alumnos y profesores';
document.getElementById('extra').innerHTML = portal === 'regencia'
  ? `<div class="nota" style="text-align:left;margin-bottom:.8rem"><b>Cuentas preestablecidas</b><br>Director: <code>director@et35.edu.ar</code><br>Regencia: <code>regencia@et35.edu.ar</code><br>Preceptor: <code>preceptor@et35.edu.ar</code><br>Contraseña: <code>Escuela35</code></div>
     <div class="o"><span>¿Primera vez?</span></div><a class="btn g grande" href="register.html">Crear cuenta de Regencia</a>
     <p class="pie"><a href="login.html?portal=comunidad">Soy alumno o profesor</a></p>`
  : `<p class="nota">Si todavía no tenés cuenta, pedísela a Regencia: ellos crean las cuentas de alumnos y profesores.</p>
     <p class="pie"><a href="login.html?portal=regencia">Soy de Regencia o superiores</a></p>`;

document.querySelectorAll('[data-ojo]').forEach((b) => b.addEventListener('click', () => {
  const i = document.getElementById(b.dataset.ojo);
  i.type = i.type === 'password' ? 'text' : 'password';
  b.textContent = i.type === 'password' ? 'Mostrar' : 'Ocultar';
}));

// Viene de crear cuenta: avisa y deja el correo cargado.
const registrado = q.get('registrado');
if (registrado) {
  document.getElementById('exito').hidden = false;
  f.email.value = registrado;
  f.password.focus();
}

f.addEventListener('submit', async (e) => {
  e.preventDefault();
  err.hidden = true;
  btn.disabled = true;
  btn.textContent = 'Ingresando…';
  try {
    await Auth.login(f.email.value, f.password.value, portal);
    location.assign('app.html');
  } catch (x) {
    err.textContent = x.message;
    err.hidden = false;
    btn.disabled = false;
    btn.textContent = 'Iniciar sesión';
  }
});
})();
