#!/usr/bin/env bash
set -euo pipefail

if [[ -f .env ]]; then
  echo ".env already exists"
else
  cp .env.example .env
  echo "Created .env from .env.example"
fi

docker compose up -d --build

echo "Stack is starting on http://localhost:${LOCAL_PORT:-8081}"
