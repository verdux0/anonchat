#!/usr/bin/env bash
set -euo pipefail

# Comprueba si se ejecuta como root; si no, usa sudo
SUDO=""
if [[ $EUID -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "Este script requiere permisos de root o sudo." >&2
    exit 1
  fi
fi

install_debian() {
  $SUDO apt-get update -y
  # Cliente MySQL (puedes cambiar mysql-client por mariadb-client si prefieres)
  $SUDO apt-get install -y mariadb-client php php-mysql
}

install_rhel() {
  # Habilita repositorios necesarios según el sistema
  if command -v dnf >/dev/null 2>&1; then
    $SUDO dnf install -y @mysql mysql php php-mysqlnd
  else
    $SUDO yum install -y @mysql mysql php php-mysqlnd
  fi
}

if command -v apt-get >/dev/null 2>&1; then
  install_debian
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
  install_rhel
else
  echo "Gestor de paquetes no soportado. Usa apt, dnf o yum." >&2
  exit 1
fi

echo "Instalación completada."