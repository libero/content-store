#!/bin/bash
set -e

function finish {
    docker-compose --file docker-compose.yaml logs
    docker-compose --file docker-compose.yaml down --volumes
}

trap finish EXIT

docker-compose --file docker-compose.yaml up --detach web

.scripts/docker/wait-healthy.sh "${COMPOSE_PROJECT_NAME:-contentstore}_app_1"

docker-compose --file docker-compose.yaml exec app bin/console --version
nc -z localhost 8080
