import argparse
import base64
import ftplib
import hashlib
import io
import json
import os
import posixpath
import re
import secrets
import shutil
import ssl
import subprocess
import urllib.error
import urllib.request
import zipfile
from datetime import datetime
from pathlib import Path
from urllib.parse import urlsplit

REPO = Path(__file__).resolve().parent.parent
RUNTIME_DIRECTORIES = ("app", "bootstrap", "config", "database", "lang", "resources", "routes", "public")


class DeploymentError(Exception):
    pass


def read_credentials(path):
    sections, current = {}, None
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        parts = re.split(r"[:=]", line.strip(), maxsplit=1)
        key = " ".join(re.findall(r"[a-z]+", parts[0].lower()))
        if (len(parts) == 1 or not parts[1].strip()) and key in {"mysql", "ftp", "gmail smtp", "admin"}:
            current = key if key in {"mysql", "ftp"} else None
            if current:
                sections[current] = {}
        elif current and len(parts) == 2:
            sections[current][key] = parts[1].strip()
    for section, fields in {"mysql": ("host", "port", "database", "username", "password"), "ftp": ("hostname", "port", "directory", "username", "password")}.items():
        if any(not sections.get(section, {}).get(field) for field in fields):
            raise DeploymentError("Missing required " + section + " configuration.")
    return {section: sections[section] for section in ("mysql", "ftp")}


def quote_option(value):
    if any(character in str(value) for character in "\r\n\0"):
        raise DeploymentError("Configuration contains unsupported control characters.")
    return '"' + str(value).replace("\\", "\\\\").replace('"', '\\"') + '"'


def digest(content):
    return hashlib.sha256(content).hexdigest()


def preserved(path, area, reuse_vendor=False):
    if area == "private":
        return path == ".env" or path.startswith(("storage/", "public/")) or (reuse_vendor and path.startswith("vendor/"))
    return path.startswith("media/uploads/")


class SecureFiles:
    def __init__(self, values):
        host = urlsplit(values["hostname"] if "://" in values["hostname"] else "//" + values["hostname"]).hostname
        if not host or int(values["port"]) != 21:
            raise DeploymentError("Use Hostinger's explicit FTPS hostname and port 21.")
        self.client = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60)
        self.listings = {}
        try:
            self.client.connect(host, 21)
            self.client.auth()
            self.client.login(values["username"], values["password"])
            self.client.prot_p()
            self.client.cwd(values["directory"])
            self.public = self.client.pwd()
            if posixpath.basename(self.public) != "public_html":
                raise DeploymentError("Configured FTPS directory must identify the existing public_html directory.")
            self.root = posixpath.dirname(self.public)
            self.private = posixpath.join(self.root, "yesenterprise-app")
            if not self.exists(self.private + "/.env") or not self.exists(self.private + "/vendor/autoload.php"):
                raise DeploymentError("Existing private application/environment is inaccessible; do not relocate secrets into public_html.")
        except (OSError, ftplib.Error, DeploymentError):
            self.client.close()
            raise

    def listing(self, directory):
        if directory not in self.listings:
            self.listings[directory] = {
                name: info for name, info in self.client.mlsd(directory)
                if info.get("type") not in {"cdir", "pdir"}
            }
        return self.listings[directory]

    def exists(self, path):
        parent = posixpath.dirname(path)
        if parent != "/" and not self.exists(parent):
            return False
        return posixpath.basename(path) in self.listing(posixpath.dirname(path))

    def get(self, path):
        output = io.BytesIO()
        self.client.retrbinary("RETR " + path, output.write)
        return output.getvalue()

    def ensure_directory(self, directory):
        if directory in {"/", self.root, self.public, self.private}:
            return
        parent = posixpath.dirname(directory)
        self.ensure_directory(parent)
        if not self.exists(directory):
            self.client.mkd(directory)
            self.listings.pop(parent, None)

    def put(self, path, content):
        self.ensure_directory(posixpath.dirname(path))
        temporary = posixpath.join(posixpath.dirname(path), ".deploy-" + secrets.token_hex(8) + "-" + posixpath.basename(path))
        self.client.storbinary("STOR " + temporary, io.BytesIO(content))
        # Never delete an existing destination to work around a failed atomic rename.
        self.client.rename(temporary, path)
        self.listings.pop(posixpath.dirname(path), None)

    def remove_owned(self, path, expected):
        if self.get(path) != expected:
            raise DeploymentError("Deployment marker changed; refusing to remove somebody else's maintenance state.")
        self.client.delete(path)
        self.listings.pop(posixpath.dirname(path), None)

    def close(self):
        self.client.close()


class Build:
    def __init__(self, output, db):
        self.output, self.db = output, db
        self.input = output / "build-input"
        self.input.mkdir()
        self.tag = "ye-deploy-" + secrets.token_hex(6)
        self.volume = self.tag + "-files"
        self.created = []

    def run(self, arguments, filename="build.log"):
        result = subprocess.run(arguments, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        with (self.output / filename).open("ab") as log:
            log.write(result.stdout)
        if result.returncode:
            raise DeploymentError("Build/database command failed; diagnostics are in the private " + filename + ".")
        return result.stdout

    def prepare(self):
        for executable in ("docker", "python", "npm.cmd" if os.name == "nt" else "npm"):
            if not shutil.which(executable):
                raise DeploymentError("Required executable missing: " + executable)
        npm = shutil.which("npm.cmd" if os.name == "nt" else "npm")
        self.run([npm, "--prefix", str(REPO / "website"), "run", "build"])
        app = self.input / "app"
        app.mkdir()
        for directory in RUNTIME_DIRECTORIES:
            shutil.copytree(REPO / "website" / directory, app / directory, ignore=shutil.ignore_patterns("*.sqlite", "*.sqlite-*", "uploads"))
        for name in ("artisan", "composer.json", "composer.lock"):
            shutil.copyfile(REPO / "website" / name, app / name)
        for path in (app / "bootstrap" / "cache").glob("*.php"):
            path.unlink()
        for folder in ("framework/cache/data", "framework/views", "framework/sessions", "logs", "app/private"):
            (app / "storage" / folder).mkdir(parents=True, exist_ok=True)
        env = {
            "APP_ENV": "production", "APP_DEBUG": "false", "APP_URL": "https://deployment.invalid",
            "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
            "DB_CONNECTION": "mysql", "DB_HOST": self.db["host"], "DB_PORT": self.db["port"],
            "DB_DATABASE": self.db["database"], "DB_USERNAME": self.db["username"], "DB_PASSWORD": self.db["password"],
            "MYSQL_ATTR_SSL_CA": "/etc/ssl/certs/ca-certificates.crt", "MYSQL_ATTR_SSL_VERIFY_SERVER_CERT": "true",
            "CACHE_STORE": "file", "SESSION_DRIVER": "file", "LOG_CHANNEL": "single",
        }
        for value in env.values():
            quote_option(value)
        (self.input / "build.env").write_text("".join(key + "=" + value + "\n" for key, value in env.items()), encoding="utf-8")
        shutil.copyfile(REPO / "scripts" / "deployment-tools.php", self.input / "tools.php")
        self.run(["docker", "volume", "create", self.volume])
        self.created.append(("volume", self.volume))
        self.run(["docker", "run", "-d", "--name", self.tag, "--env-file", str(self.input / "build.env"),
                  "--mount", f"type=bind,source={self.input},target=/input,readonly",
                  "--mount", f"type=volume,source={self.volume},target=/release",
                  "--mount", "type=volume,source=yesenterprise_composer-cache,target=/tmp/composer-cache",
                  "--env", "COMPOSER_CACHE_DIR=/tmp/composer-cache", "yesenterprise-app", "sleep", "infinity"])
        self.created.append(("container", self.tag))
        self.run(["docker", "exec", self.tag, "cp", "-a", "/input/app", "/release/app"])
        self.run(["docker", "exec", "-w", "/release/app", self.tag, "composer", "install", "--no-dev", "--prefer-dist", "--optimize-autoloader", "--no-interaction", "--no-progress"])
        self.run(["docker", "exec", "-w", "/release/app", self.tag, "composer", "check-platform-reqs", "--no-dev"])
        self.tool("package")
        for name in ("private", "public"):
            self.run(["docker", "cp", self.tag + ":/release/" + name + ".zip", str(self.output / (name + ".zip"))])

    def tool(self, mode):
        return json.loads(self.run(["docker", "exec", self.tag, "php", "/input/tools.php", mode], "database.log"))

    def backup_database(self):
        config = "[client]\n" + "".join(key + "=" + quote_option(self.db[source]) + "\n" for key, source in
                                      {"host": "host", "port": "port", "user": "username", "password": "password", "database": "database"}.items())
        path = self.input / "backup.cnf"
        path.write_text(config, encoding="utf-8")
        arguments = ["docker", "run", "--rm", "--mount", f"type=bind,source={path},target=/run/secrets/client.cnf,readonly",
                     "mariadb:11.4", "mariadb-dump", "--defaults-extra-file=/run/secrets/client.cnf",
                     "--protocol=TCP", "--connect-timeout=20", "--ssl", "--ssl-ca=/etc/ssl/certs/ca-certificates.crt",
                     "--ssl-verify-server-cert", "--single-transaction", "--skip-lock-tables", "--no-tablespaces", self.db["database"]]
        with (self.output / "database-before.sql").open("wb") as backup:
            result = subprocess.run(arguments, stdout=backup, stderr=subprocess.PIPE)
        if result.returncode:
            (self.output / "database-backup-error.log").write_bytes(result.stderr)
            raise DeploymentError("Verified-TLS database backup failed; no migrations will run.")

    def close(self):
        failed = []
        for kind, name in reversed(self.created):
            arguments = ["docker", "rm", "-f", name] if kind == "container" else ["docker", kind, "rm", name]
            result = subprocess.run(arguments, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if result.returncode:
                failed.append(name)
        shutil.rmtree(self.input)
        if failed:
            raise DeploymentError("Remove these named local deployment helpers: " + ", ".join(failed))


def public_index(content):
    text = content.decode()
    if "__DIR__.'/../vendor/autoload.php'" not in text or "$app->handleRequest(Request::capture());" not in text:
        raise DeploymentError("Public bootstrap changed; update the deployment layout intentionally.")
    text = text.replace("__DIR__.'/../", "__DIR__.'/../yesenterprise-app/")
    text = text.replace("$app->handleRequest(Request::capture());", "$app->usePublicPath(__DIR__);\n$app->handleRequest(Request::capture());")
    return text.encode()


def collect_files(output, reuse_vendor):
    files = {}
    for area in ("private", "public"):
        with zipfile.ZipFile(output / (area + ".zip")) as archive:
            for entry in archive.infolist():
                if entry.is_dir() or preserved(entry.filename, area, reuse_vendor):
                    continue
                if entry.filename.startswith("/") or ".." in entry.filename.split("/"):
                    raise DeploymentError("Unsafe package path.")
                content = archive.read(entry)
                if area == "public" and entry.filename == "index.php":
                    content = public_index(content)
                files[area + "/" + entry.filename] = content
    return files


def remote_path(files, name):
    area, relative = name.split("/", 1)
    return posixpath.join(files.private if area == "private" else files.public, relative)


def plan_files(remote, files, state):
    changes = {}
    for name, content in files.items():
        path = remote_path(remote, name)
        exists = remote.exists(path)
        if name.startswith("public/media/") and exists:
            continue
        previous = state.get(name)
        if exists and previous and previous["sha256"] == digest(content):
            info = remote.listing(posixpath.dirname(path))[posixpath.basename(path)]
            if int(info.get("size", -1)) == len(content):
                continue
        old = remote.get(path) if exists else None
        if old == content:
            continue
        changes[name] = old
    return changes


def health_check(url):
    if urlsplit(url).scheme != "https":
        raise DeploymentError("Live verification requires HTTPS.")
    for path in ("/up", "/en"):
        with urllib.request.urlopen(url.rstrip("/") + path, timeout=45) as response:
            content = response.read()
            if response.status != 200 or (path == "/en" and b"Yes Enterprise" not in content):
                raise DeploymentError("Live deployment health check failed.")


def apply_changes(remote, build, files, changes, plan, output):
    marker = remote.private + "/storage/framework/maintenance.php"
    if remote.exists(marker):
        raise DeploymentError("The site is already in maintenance mode; resolve it before deploying.")
    old_index = remote.get(remote.public + "/index.php")
    if b"../yesenterprise-app/storage/framework/maintenance.php" not in old_index:
        raise DeploymentError("Existing entry point does not use the supported private/shared layout.")
    with zipfile.ZipFile(output / "files-before.zip", "w", zipfile.ZIP_DEFLATED) as backup:
        for name, old in changes.items():
            if old is not None:
                backup.writestr(name, old)
    token = secrets.token_hex(16)
    maintenance = ("<?php\n// Deployment " + token + "\nhttp_response_code(503);\nheader('Retry-After: 60');\n"
                   "header('Content-Type: text/html; charset=UTF-8');\necho '<!doctype html><html lang=\"en\"><title>Website update</title>"
                   "<h1>Website update in progress</h1><p>Please try again shortly.</p></html>';\nexit;\n").encode()
    remote.put(marker, maintenance)
    try:
        if plan["pending"]:
            build.backup_database()
        for name in sorted(changes, key=lambda name: name == "public/index.php"):
            remote.put(remote_path(remote, name), files[name])
        result = build.tool("migrate")
        if result["counts"] != plan["counts"]:
            raise DeploymentError("Critical database record counts changed; review before reopening the site.")
        for name in ("config.php", "routes-v7.php"):
            path = remote.private + "/bootstrap/cache/" + name
            if remote.exists(path):
                content = remote.get(path)
                (output / ("previous-cache-" + name)).write_bytes(content)
                remote.remove_owned(path, content)
        state = {name: {"sha256": digest(content), "bytes": len(content)} for name, content in files.items()}
        remote.put(remote.private + "/deployment-state.json", json.dumps(state, indent=2).encode())
        remote.remove_owned(marker, maintenance)
    except (DeploymentError, OSError, ftplib.Error):
        print("Deployment stopped. If maintenance is active, keep it active until the private logs/backups are reviewed.")
        raise


def deploy(arguments):
    credentials = read_credentials(arguments.secrets_file)
    remote, build = None, None
    output = arguments.secrets_file.parent / "deployments" / (datetime.now().strftime("%Y%m%d-%H%M%S") + "-" + secrets.token_hex(3))
    output.mkdir(parents=True)
    try:
        remote = SecureFiles(credentials["ftp"])
        print("FTPS authentication and certificate verified.")
        lock = remote.private + "/composer.lock"
        reuse_vendor = remote.exists(lock) and remote.get(lock) == (REPO / "website" / "composer.lock").read_bytes()
        build = Build(output, credentials["mysql"])
        build.prepare()
        plan = build.tool("plan")
        state_path = remote.private + "/deployment-state.json"
        state = json.loads(remote.get(state_path)) if remote.exists(state_path) else {}
        files = collect_files(output, reuse_vendor)
        changes = plan_files(remote, files, state)
        report = {"apply": arguments.apply, "changed_files": list(changes), "pending_migrations": plan["pending"],
                  "reuse_vendor": reuse_vendor, "record_counts": plan["counts"], "completed": False}
        (output / "plan.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(f"Verified MySQL TLS. {len(changes)} files changed; {len(plan['pending'])} migrations pending; vendor reuse: {reuse_vendor}.")
        if not arguments.apply:
            print("Preview only: no remote files, database records or permissions were changed.")
            return
        apply_changes(remote, build, files, changes, plan, output)
        try:
            health_check(arguments.url)
        except (OSError, urllib.error.URLError, DeploymentError):
            raise DeploymentError("Uploads/migrations finished, but live HTTPS verification failed. Review the site and private backups before declaring success.") from None
        report["completed"] = True
        (output / "result.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
        print("Deployment completed and live HTTPS health checks passed.")
    finally:
        if remote:
            remote.close()
        if build:
            build.close()
        print("Private deployment output: " + str(output))


def main():
    parser = argparse.ArgumentParser(description="Deploy an existing Laravel website using verified FTPS and remote MySQL.")
    parser.add_argument("--secrets-file", type=Path, required=True)
    parser.add_argument("--url", default="https://fair-sandybrown-kangaroo-55.hostingersite.com")
    parser.add_argument("--apply", action="store_true", help="Apply changes; without this option the script is read-only remotely.")
    args = parser.parse_args()
    try:
        deploy(args)
    except (DeploymentError, OSError, ftplib.Error, ValueError) as error:
        if isinstance(error, ssl.SSLCertVerificationError):
            print("Blocked: FTPS certificate validation failed. Correct the hostname/certificate; verification will not be bypassed.")
        elif isinstance(error, DeploymentError):
            print("Blocked: " + str(error))
        else:
            print("Blocked by " + type(error).__name__ + ". Credential values are withheld.")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
