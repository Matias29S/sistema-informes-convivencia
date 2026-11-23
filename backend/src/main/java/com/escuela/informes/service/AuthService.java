package com.escuela.informes.service;

import static org.springframework.http.HttpStatus.*;

import com.escuela.informes.dto.AuthDtos.*;
import com.escuela.informes.model.Rol;
import com.escuela.informes.model.Usuario;
import com.escuela.informes.repository.UsuarioRepository;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.security.oauth2.jose.jws.MacAlgorithm;
import org.springframework.security.oauth2.jwt.JwsHeader;
import org.springframework.security.oauth2.jwt.JwtClaimsSet;
import org.springframework.security.oauth2.jwt.JwtEncoder;
import org.springframework.security.oauth2.jwt.JwtEncoderParameters;
import org.springframework.stereotype.Service;
import org.springframework.web.server.ResponseStatusException;

@Service
public class AuthService {
  private final UsuarioRepository repo;
  private final PasswordEncoder encoder;
  private final JwtEncoder jwtEncoder;
  private final String codigo;
  private final String dominio;
  private final long horas;

  public AuthService(UsuarioRepository repo, PasswordEncoder encoder, JwtEncoder jwtEncoder,
      @Value("${app.codigo-institucional}") String codigo,
      @Value("${app.dominio-correo:}") String dominio,
      @Value("${app.jwt.horas:8}") long horas) {
    this.repo = repo;
    this.encoder = encoder;
    this.jwtEncoder = jwtEncoder;
    this.codigo = codigo;
    this.dominio = dominio.trim().toLowerCase();
    this.horas = horas;
  }

  public UsuarioDto registrar(Registro r) {
    String nombre = limpio(r.nombre());
    String email = limpio(r.email()).toLowerCase();
    String pass = r.password() == null ? "" : r.password();

    if (nombre.length() < 3) throw error(BAD_REQUEST, "Ingresá tu nombre y apellido.");
    if (!email.matches("[^@\\s]+@[^@\\s]+\\.[^@\\s]+")) throw error(BAD_REQUEST, "El correo no es válido.");
    if (!dominio.isBlank() && !email.endsWith("@" + dominio))
      throw error(BAD_REQUEST, "Usá tu cuenta institucional @" + dominio + ".");
    if (pass.length() < 8 || pass.length() > 72)
      throw error(BAD_REQUEST, "La contraseña debe tener entre 8 y 72 caracteres.");
    if (r.rol() == null) throw error(BAD_REQUEST, "Elegí un rol.");
    if (r.rol() != Rol.FAMILIA && !codigo.equals(r.codigo()))
      throw error(FORBIDDEN, "El código institucional no es correcto.");
    if (repo.existsByEmailBueIgnoreCase(email)) throw error(CONFLICT, "Ya existe una cuenta con ese correo.");

    return dto(repo.save(new Usuario(email, encoder.encode(pass), nombre, r.rol())));
  }

  public Sesion login(Login l) {
    String pass = l.password() == null ? "" : l.password();
    Usuario u = repo.findByEmailBueIgnoreCase(limpio(l.email()))
        .filter(x -> x.isActivo() && encoder.matches(pass, x.getPasswordHash()))
        .orElseThrow(() -> error(UNAUTHORIZED, "Correo o contraseña incorrectos."));

    Instant ahora = Instant.now();
    JwtClaimsSet claims = JwtClaimsSet.builder()
        .subject(u.getEmailBue())
        .issuedAt(ahora)
        .expiresAt(ahora.plus(horas, ChronoUnit.HOURS))
        .claim("rol", u.getRol().name())
        .build();
    String token = jwtEncoder
        .encode(JwtEncoderParameters.from(JwsHeader.with(MacAlgorithm.HS256).build(), claims))
        .getTokenValue();
    return new Sesion(token, dto(u));
  }

  public UsuarioDto perfil(String email) {
    return dto(repo.findByEmailBueIgnoreCase(email).orElseThrow(() -> error(UNAUTHORIZED, "Sesión inválida.")));
  }

  private static String limpio(String s) { return s == null ? "" : s.trim(); }

  private static ResponseStatusException error(HttpStatus estado, String mensaje) {
    return new ResponseStatusException(estado, mensaje);
  }

  private static UsuarioDto dto(Usuario u) {
    return new UsuarioDto(u.getId(), u.getNombre(), u.getEmailBue(), u.getRol());
  }
}
