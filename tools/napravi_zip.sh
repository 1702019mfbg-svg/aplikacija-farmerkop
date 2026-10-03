#!/bin/sh
# Pravi pakete za cPanel (sadržaj foldera app/):
#   dist/farmerkop-aplikacija.zip  – prvo postavljanje (sa config.php i install.php)
#   dist/farmerkop-azuriranje.zip  – kasnije ažuriranje (BEZ config.php i install.php, da se ne prepišu vaši podaci)
# Pokretanje: sh tools/napravi_zip.sh
set -e
cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/farmerkop-aplikacija.zip dist/farmerkop-azuriranje.zip
( cd app && zip -r -X -q ../dist/farmerkop-aplikacija.zip . -x '*.DS_Store' )
( cd app && zip -r -X -q ../dist/farmerkop-azuriranje.zip . -x '*.DS_Store' -x 'config.php' -x 'install.php' )
for f in dist/farmerkop-aplikacija.zip dist/farmerkop-azuriranje.zip; do
    echo "$f: $(unzip -l "$f" | tail -n 1 | awk '{print $2, $3}')"
done
