#!/usr/bin/env python3
"""
Pravi test sajt sa uzorkom podataka i snima ekrane kao telefon.
Upotreba: python3 tests/ekrani.py IZLAZNI_FOLDER [tok1,tok2,...]
"""
import os
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from fk import *  # noqa


def pripremi(site):
    pripremi_sajt(site)


if __name__ == "__main__":
    izlaz = sys.argv[1] if len(sys.argv) > 1 else "/tmp/ekrani"
    tokovi = sys.argv[2] if len(sys.argv) > 2 else ""
    os.makedirs(izlaz, exist_ok=True)
    reset_db()
    site = Site()
    try:
        pripremi(site)
        env = dict(os.environ, NODE_PATH="/opt/node-tools/node_modules")
        kod = 0
        for tema in ("light", "dark"):
            # svaki režim kreće od istih podataka
            sql("DELETE FROM dnevnik; DELETE FROM unosi; DELETE FROM kupci")
            r = subprocess.run(["node", str(Path(__file__).parent / "ekrani.cjs"), site.base, izlaz, tokovi, tema], env=env)
            kod = kod or r.returncode
        problemi = site.php_problemi()
        if problemi:
            print("PHP problemi:", *problemi, sep="\n  ")
        sys.exit(kod or (1 if problemi else 0))
    finally:
        site.stop()
