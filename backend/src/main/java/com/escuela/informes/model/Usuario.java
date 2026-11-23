package com.escuela.informes.model;

import jakarta.persistence.*;
import java.time.Instant;
import java.util.UUID;

@Entity
@Table(name = "usuarios")
public class Usuario {
  @Id @GeneratedValue(strategy = GenerationType.UUID)
  private UUID id;
  @Column(name = "email_bue", nullable = false, unique = true, length = 120)
  private String emailBue;
  @Column(name = "password_hash", nullable = false, length = 100)
  private String passwordHash;
  @Column(nullable = false, length = 120)
  private String nombre;
  @Enumerated(EnumType.STRING) @Column(nullable = false, length = 10)
  private Rol rol;
  @Column(nullable = false)
  private boolean activo = true;
  @Column(name = "creado_en", nullable = false)
  private Instant creadoEn = Instant.now();

  protected Usuario() {}

  public Usuario(String emailBue, String passwordHash, String nombre, Rol rol) {
    this.emailBue = emailBue;
    this.passwordHash = passwordHash;
    this.nombre = nombre;
    this.rol = rol;
  }

  public UUID getId() { return id; }
  public String getEmailBue() { return emailBue; }
  public String getPasswordHash() { return passwordHash; }
  public String getNombre() { return nombre; }
  public Rol getRol() { return rol; }
  public boolean isActivo() { return activo; }
}
