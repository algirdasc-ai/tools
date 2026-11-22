#!/bin/sh
# Runs the full test suite inside the app container (needs docker compose up).
# Usage: ./tests/run.sh   (from the repo root)
exec docker compose exec -T tools php tests/run.php
