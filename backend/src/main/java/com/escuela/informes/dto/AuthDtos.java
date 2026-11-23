package com.escuela.informes.dto;

import com.escuela.informes.model.Rol;
import java.util.UUID;

public final class AuthDtos {
  private AuthDtos() {}

  public record Registro(String nombre, String email, String password, Rol rol, String codigo) {}
  public record Login(String email, String password) {}
  public record UsuarioDto(UUID id, String nombre, String email, Rol rol) {}
  public record Sesion(String token, UsuarioDto usuario) {}
}
