#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SPAM_DIR="$ROOT_DIR/spam"
PYTHON_EXE="$ROOT_DIR/.venv-tf/bin/python"

if [[ ! -x "$PYTHON_EXE" ]]; then
  echo "No se encontro .venv-tf. Ejecuta primero ./setup.sh" >&2
  exit 1
fi

cd "$SPAM_DIR"
"$PYTHON_EXE" check_spam.py
