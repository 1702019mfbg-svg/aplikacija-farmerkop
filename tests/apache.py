"""
Pokreće aplikaciju pod pravim Apache serverom (kao na cPanel hostingu) sa php-cgi,
da bi se proverio .htaccess (zabrane, zaglavlja, tipovi fajlova, HTTPS preusmeravanje).
Aplikacija se uzima iz ZIP paketa koji se otprema na hosting (dist/farmerkop-aplikacija.zip).
"""
import os
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import zipfile
from pathlib import Path

from fk import DB_NAME, DB_PASS, DB_USER, INSTALL_KLJUC, ROOT, slobodan_port

MODULI = [
    ("mpm_prefork_module", "mod_mpm_prefork.so"), ("authz_core_module", "mod_authz_core.so"), ("authz_host_module", "mod_authz_host.so"),
    ("authn_core_module", "mod_authn_core.so"), ("dir_module", "mod_dir.so"), ("mime_module", "mod_mime.so"), ("rewrite_module", "mod_rewrite.so"),
    ("headers_module", "mod_headers.so"), ("alias_module", "mod_alias.so"), ("actions_module", "mod_actions.so"), ("cgi_module", "mod_cgi.so"),
    ("env_module", "mod_env.so"), ("autoindex_module", "mod_autoindex.so"),
    ("setenvif_module", "mod_setenvif.so"),
]


class ApacheSajt:
    def __init__(self, zip_putanja=None):
        self.tmp = Path(tempfile.mkdtemp(prefix="fk_apache_"))
        os.chmod(self.tmp, 0o755)
        self.dir = self.tmp / "www"
        self.dir.mkdir()
        with zipfile.ZipFile(zip_putanja or ROOT / "dist" / "farmerkop-aplikacija.zip") as z:
            z.extractall(self.dir)
        (self.dir / "config.php").write_text(
            "<?php\n"
            "define('DB_HOST','127.0.0.1');\n"
            "define('DB_NAME','%s');\ndefine('DB_USER','%s');\ndefine('DB_PASS','%s');\n"
            "define('INSTALL_KLJUC','%s');\ndefine('DEBUG', true);\n" % (DB_NAME, DB_USER, DB_PASS, INSTALL_KLJUC)
        )
        import pwd
        www = pwd.getpwnam("www-data")
        # kao na cPanel-u: fajlove poseduje isti korisnik pod kojim radi PHP
        for koren, dirs, files in os.walk(self.dir):
            os.chmod(koren, 0o755)
            os.chown(koren, www.pw_uid, www.pw_gid)
            for f in files:
                os.chmod(os.path.join(koren, f), 0o644)
                os.chown(os.path.join(koren, f), www.pw_uid, www.pw_gid)
        os.chown(self.dir, www.pw_uid, www.pw_gid)
        # php-cgi sa isključenom zaštitom "force_redirect" (kao što rade hosting okruženja)
        cgi = self.tmp / "cgi-bin"
        cgi.mkdir()
        omot = cgi / "php-cgi"
        omot.write_text('#!/bin/sh\nexec /usr/bin/php-cgi -d cgi.force_redirect=0 -d display_errors=1 -d log_errors=1 -d error_log=%s "$@"\n' % (self.tmp / "php-greske.log"))
        os.chmod(omot, 0o755)
        self.port = slobodan_port()
        self.base = "http://127.0.0.1:%d" % self.port
        moduli = "\n".join("LoadModule %s /usr/lib/apache2/modules/%s" % m for m in MODULI)
        conf = f"""
ServerRoot "{self.tmp}"
PidFile "{self.tmp}/apache.pid"
Listen 127.0.0.1:{self.port}
User www-data
Group www-data
ServerName 127.0.0.1
{moduli}
ErrorLog "{self.tmp}/error.log"
LogLevel warn
LogFormat "%h %>s %r" brz
CustomLog "{self.tmp}/access.log" brz
TypesConfig /etc/mime.types
DocumentRoot "{self.dir}"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "{self.dir}">
    AllowOverride All
    Require all granted
</Directory>
<Directory "{cgi}">
    Require all granted
</Directory>
ScriptAlias /php-cgi-bin/ "{cgi}/"
AddType application/x-httpd-php .php
Action application/x-httpd-php /php-cgi-bin/php-cgi
SetEnv REDIRECT_STATUS 200
<FilesMatch "^\\.ht">
    Require all denied
</FilesMatch>
"""
        (self.tmp / "httpd.conf").write_text(conf)
        r = subprocess.run(["apache2", "-f", str(self.tmp / "httpd.conf"), "-t"], capture_output=True, text=True)
        if "Syntax OK" not in (r.stdout + r.stderr):
            raise RuntimeError("Apache konfiguracija: " + r.stdout + r.stderr)
        subprocess.run(["apache2", "-f", str(self.tmp / "httpd.conf"), "-k", "start"], check=True, capture_output=True)
        for _ in range(60):
            try:
                socket.create_connection(("127.0.0.1", self.port), timeout=0.2).close()
                break
            except OSError:
                time.sleep(0.1)

    def greske_apache(self):
        f = self.tmp / "error.log"
        return f.read_text(errors="replace") if f.exists() else ""

    def php_log(self):
        f = self.tmp / "php-greske.log"
        return f.read_text(errors="replace") if f.exists() else ""

    def php_problemi(self):
        import re
        return [l for l in self.php_log().splitlines() if re.search(r"PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught|Stack trace", l)]

    def stop(self):
        try:
            subprocess.run(["apache2", "-f", str(self.tmp / "httpd.conf"), "-k", "stop"], capture_output=True, timeout=10)
        except Exception:
            pass
        time.sleep(0.5)
        shutil.rmtree(self.tmp, ignore_errors=True)
