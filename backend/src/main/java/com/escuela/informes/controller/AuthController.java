package com.escuela.informes.controller;

import com.escuela.informes.dto.AuthDtos.*;
import com.escuela.informes.service.AuthService;
import org.springframework.http.HttpStatus;
import org.springframework.security.core.annotation.AuthenticationPrincipal;
import org.springframework.security.oauth2.jwt.Jwt;
import org.springframework.web.bind.annotation.*;

@RestController
@RequestMapping("/api/auth")
public class AuthController {
  private final AuthService service;

  public AuthController(AuthService service) { this.service = service; }

  @PostMapping("/registro")
  @ResponseStatus(HttpStatus.CREATED)
  public UsuarioDto registro(@RequestBody Registro body) { return service.registrar(body); }

  @PostMapping("/login")
  public Sesion login(@RequestBody Login body) { return service.login(body); }

  @GetMapping("/me")
  public UsuarioDto me(@AuthenticationPrincipal Jwt jwt) { return service.perfil(jwt.getSubject()); }
}
