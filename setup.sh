#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SPAM_DIR="$ROOT_DIR/spam"
VENV_DIR="$ROOT_DIR/.venv-tf"
LEGACY_VENV="$ROOT_DIR/.venv"

cleanup_old_venv="false"
if [[ "${1:-}" == "--cleanup-old-venv" ]]; then
  cleanup_old_venv="true"
fi

if [[ ! -f "$SPAM_DIR/requirements_api.txt" ]]; then
  echo "No se encontro requirements_api.txt en $SPAM_DIR" >&2
  exit 1
fi

if command -v python3.13 >/dev/null 2>&1; then
  PYTHON_BIN="python3.13"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON_BIN="python3"
else
  echo "No se encontro Python 3 en el sistema." >&2
  exit 1
fi

echo "[1/3] Creando/actualizando .venv-tf con $PYTHON_BIN"
if [[ ! -d "$VENV_DIR" ]]; then
  "$PYTHON_BIN" -m venv "$VENV_DIR"
fi

PYTHON_EXE="$VENV_DIR/bin/python"

echo "[2/3] Instalando dependencias de Spam API"
"$PYTHON_EXE" -m pip install --upgrade pip setuptools wheel
"$PYTHON_EXE" -m pip install -r "$SPAM_DIR/requirements_api.txt"

if [[ -d "$LEGACY_VENV" ]]; then
  if [[ "$cleanup_old_venv" == "true" ]]; then
    echo "Eliminando entorno legacy .venv"
    rm -rf "$LEGACY_VENV"
  else
    echo "Aviso: existe .venv legacy. Ejecuta ./setup.sh --cleanup-old-venv para eliminarlo."
  fi
fi

echo "[3/3] Setup completado"
echo "Para arrancar la API de spam: ./run-spam.sh"
