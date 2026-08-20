#!/bin/sh
# Applies (idempotently, upsert) the "default" preset + its two provisions
# to the running GenieACS mongo database. Manual, not run automatically by
# any entrypoint — same posture as this project's earlier cwmp.auth setup
# (genieacs-nbi 1.2.16 has no REST endpoint for presets/provisions, so
# mongosh is the only way). Kept here in git for reproducibility (BOSS-001),
# not because it runs itself.
#
# IMPORTANT after running this: genieacs-cwmp must be RESTARTED (not just
# left running) for a brand-new preset/provision document to actually take
# effect — confirmed empirically: two real devices informed after this
# script ran but BEFORE a restart, and neither picked up the new preset at
# all (their trees stayed exactly as thin as before). After
# `docker compose restart genieacs-cwmp`, the very next real device inform
# for every vendor family tested (F86CE1, A4F33B) picked up the full preset
# with zero faults.
#
# Usage (from the repo root, with the stack already up):
#   docker cp docker/genieacs/presets/default.js mongo:/tmp/default.js
#   docker cp docker/genieacs/presets/default-optical.js mongo:/tmp/default-optical.js
#   docker compose exec mongo sh -c '.../apply via mongosh, see below'
#   docker compose restart genieacs-cwmp

set -e

MONGO_URI="mongodb://${MONGO_DB_USER}:${MONGO_DB_PASSWORD}@localhost/${MONGO_DB_NAME}?authSource=admin"

mongosh --quiet "$MONGO_URI" --eval "
const defaultScript = require('fs').readFileSync('/tmp/default.js', 'utf8');
const opticalScript = require('fs').readFileSync('/tmp/default-optical.js', 'utf8');

db.provisions.updateOne(
  { _id: 'default' },
  { \$set: { script: defaultScript } },
  { upsert: true }
);

db.provisions.updateOne(
  { _id: 'default-optical' },
  { \$set: { script: opticalScript } },
  { upsert: true }
);

db.presets.updateOne(
  { _id: 'default' },
  {
    \$set: {
      channel: 'default',
      configurations: [
        { type: 'provision', name: 'default', args: [] },
        { type: 'provision', name: 'default-optical', args: [] }
      ]
    }
  },
  { upsert: true }
);

print('Applied. Remember: docker compose restart genieacs-cwmp');
"
