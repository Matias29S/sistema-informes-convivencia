package com.escuela.informes.repository;

import com.escuela.informes.model.Usuario;
import java.util.Optional;
import java.util.UUID;
import org.springframework.data.jpa.repository.JpaRepository;

public interface UsuarioRepository extends JpaRepository<Usuario, UUID> {
  Optional<Usuario> findByEmailBueIgnoreCase(String emailBue);
  boolean existsByEmailBueIgnoreCase(String emailBue);
}
