#!/bin/bash
set -e

if [ ! -f .env ]; then
    echo "[start] .env not found — copying from .env.example"
    cp .env.example .env
fi

set -a
source <(sed 's/\r//' .env)
set +a

docker compose up -d
docker compose exec app bash
