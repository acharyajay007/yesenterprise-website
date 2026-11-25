import argparse
import base64
import ftplib
import hashlib
import hmac
import io
import json
import os
import posixpath
import re
import secrets
import shutil
import ssl
import subprocess
import time
import urllib.error
import urllib.request
import zipfile
from datetime import datetime
from pathlib import Path
from urllib.parse import urlsplit

REPO = Path(__file__).resolve().parent.parent
RUNTIME_DIRECTORIES = ("app", "bootstrap", "config", "database", "lang", "resources", "routes", "public")
ACCESS_PROBE_EXTENSIONS = ("env", "json", "php", "log", "pdf", "txt", "webp", "css", "js")
BOOTSTRAP_SCRIPT = Path(__file__).with_name("git-bootstrap.php")


class DeploymentError(Exception):
    pass


def read_credentials(path, required=("mysql", "ftp")):
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
        if section not in required:
            continue
        if any(not sections.get(section, {}).get(field) for field in fields):
            raise DeploymentError("Missing required " + section + " configuration.")
    return {section: sections[section] for section in required}


def index_layout(content):
    if b"../yesenterprise-app/storage/framework/maintenance.php" in content:
        return "legacy"
    if b"yesenterprise-app/storage/framework/maintenance.php" in content:
        return "protected"
    raise DeploymentError("Existing entry point does not use a supported Laravel layout.")


def quote_option(value):
    if any(character in str(value) for character in "\r\n\0"):
        raise DeploymentError("Configuration contains unsupported control characters.")
    return '"' + str(value).replace("\\", "\\\\").replace('"', '\\"') + '"'


def digest(content):
    return hashlib.sha256(content).hexdigest()


def maintenance_marker(token):
    return ("<?php\n// Deployment " + token + "\nhttp_response_code(503);\nheader('Retry-After: 60');\n"
            "header('Content-Type: text/html; charset=UTF-8');\necho '<!doctype html><html lang=\"en\"><title>Website update</title>"
            "<h1>Website update in progress</h1><p>Please try again shortly.</p></html>';\nexit;\n").encode()


def preserved(path, area, reuse_vendor=False):
    if area == "private":
        return (path == ".env" or path.startswith((".env.", "storage/", "public/", "deployment-access-probe/"))
                or (path.startswith("bootstrap/cache/") and posixpath.basename(path) not in {".gitignore", "packages.php", "services.php"})
                or (reuse_vendor and path.startswith("vendor/")))
    return path in {".env", "storage"} or path.startswith((".env.", "media/uploads/", ".git/", ".deploy-", "storage/"))


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
            self.layout = index_layout(self.get(self.public + "/index.php"))
            self.private = posixpath.join(self.public if self.layout == "protected" else self.root, "yesenterprise-app")
            if not self.exists(self.private + "/.env") or not self.exists(self.private + "/vendor/autoload.php"):
                raise DeploymentError("Existing application/environment is inaccessible; complete and verify the documented layout migration first.")
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


def public_index(content, layout="legacy"):
    text = content.decode()
    if "__DIR__.'/../vendor/autoload.php'" not in text or "$app->handleRequest(Request::capture());" not in text:
        raise DeploymentError("Public bootstrap changed; update the deployment layout intentionally.")
    if layout not in {"legacy", "protected"}:
        raise DeploymentError("Unknown deployment layout.")
    prefix = "../yesenterprise-app/" if layout == "legacy" else "yesenterprise-app/"
    text = text.replace("__DIR__.'/../", "__DIR__.'/" + prefix)
    text = text.replace("$app->handleRequest(Request::capture());", "$app->usePublicPath(__DIR__);\n$app->handleRequest(Request::capture());")
    return text.encode()


def collect_files(output, reuse_vendor, layout="legacy"):
    files = {}
    for area in ("private", "public"):
        with zipfile.ZipFile(output / (area + ".zip")) as archive:
            for entry in archive.infolist():
                if entry.is_dir() or preserved(entry.filename, area, reuse_vendor):
                    continue
                if entry.filename.startswith("/") or ".." in entry.filename.split("/") or "\\" in entry.filename or ":" in entry.filename:
                    raise DeploymentError("Unsafe package path.")
                content = archive.read(entry)
                if area == "public" and entry.filename == "index.php":
                    content = public_index(content, layout)
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


def health_check(url, protected=False):
    if urlsplit(url).scheme != "https":
        raise DeploymentError("Live verification requires HTTPS.")
    for path in ("/up", "/en"):
        with urllib.request.urlopen(url.rstrip("/") + path, timeout=45) as response:
            content = response.read()
            if response.status != 200 or (path == "/en" and b"Yes Enterprise" not in content):
                raise DeploymentError("Live deployment health check failed.")
    if protected:
        verify_private_paths(url)


def verify_private_paths(url):
    if urlsplit(url).scheme != "https":
        raise DeploymentError("Private-path verification requires HTTPS.")
    paths = ["/yesenterprise-app/.env", "/yesenterprise-app/composer.json",
                 "/yesenterprise-app/vendor/autoload.php", "/yesenterprise-app/storage/logs/laravel.log",
                 "/.git/config", "/.env"]
    paths.extend("/yesenterprise-app/deployment-access-probe/check." + extension for extension in ACCESS_PROBE_EXTENSIONS)
    for path in paths:
        try:
            with urllib.request.urlopen(url.rstrip("/") + path, timeout=45):
                raise DeploymentError("Protected application paths are not returning HTTP 403; do not publish or copy secrets.")
        except urllib.error.HTTPError as error:
            error.close()
            if error.code != 403:
                raise DeploymentError("Protected application paths must return HTTP 403; do not publish or copy secrets.") from None


def probe_content(extension):
    return (b"<?php http_response_code(409); echo 'PRIVATE_ACCESS_PROBE';"
            if extension == "php" else b"PRIVATE_ACCESS_PROBE_NOT_A_SECRET")


def write_protection_archive(output, files):
    with zipfile.ZipFile(output / ".deploy-protection.zip", "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(".htaccess", files["public/.htaccess"])
        archive.writestr("yesenterprise-app/.htaccess", files["private/.htaccess"])
        for extension in ACCESS_PROBE_EXTENSIONS:
            archive.writestr("yesenterprise-app/deployment-access-probe/check." + extension, probe_content(extension))


def export_git_release(output, files):
    required = {"private/.htaccess", "private/vendor/autoload.php", "private/app/Support/GitLayoutMigration.php", "public/.htaccess", "public/index.php",
                "public/build/manifest.json"}
    if not required.issubset(files):
        raise DeploymentError("Production Git release is missing protection, dependencies or built assets.")
    if files["private/.htaccess"].strip() != b"Require all denied":
        raise DeploymentError("Private application protection is missing.")
    if b"RewriteRule ^yesenterprise-app(?:/|$) - [F,L,NC]" not in files["public/.htaccess"]:
        raise DeploymentError("Root application protection is missing.")
    if index_layout(files["public/index.php"]) != "protected":
        raise DeploymentError("Git entry point must load the protected application folder.")
    target = output / "git-release"
    target.mkdir()
    for name, content in files.items():
        area, relative = name.split("/", 1)
        if area not in {"private", "public"} or preserved(relative, area) or relative.startswith("/") or ".." in relative.split("/") or "\\" in relative or ":" in relative:
            raise DeploymentError("Git release contains an unsafe or runtime-owned path.")
        if posixpath.basename(relative) == ".gitignore":
            continue
        path = target / ("yesenterprise-app" if area == "private" else "") / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
    (target / ".gitignore").write_text(
        "/yesenterprise-app/.env\n/yesenterprise-app/.env.*\n/yesenterprise-app/storage/\n"
        "/yesenterprise-app/deployment-access-probe/*\n"
        + "".join("!/yesenterprise-app/deployment-access-probe/check." + extension + "\n" for extension in ACCESS_PROBE_EXTENSIONS)
        +
        "/yesenterprise-app/bootstrap/cache/*.php\n"
        "!/yesenterprise-app/bootstrap/cache/packages.php\n!/yesenterprise-app/bootstrap/cache/services.php\n"
        "/media/uploads/\n/.deploy-*\n", encoding="utf-8")
    (target / "deployment-bootstrap.php").write_bytes(BOOTSTRAP_SCRIPT.read_bytes())
    probes = target / "yesenterprise-app" / "deployment-access-probe"
    probes.mkdir()
    for extension in ACCESS_PROBE_EXTENSIONS:
        (probes / ("check." + extension)).write_bytes(probe_content(extension))
    write_protection_archive(output, files)
    with zipfile.ZipFile(output / ".deploy-cutover.zip", "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("index.php", files["public/index.php"])
    (output / ".deploy-maintenance.php").write_bytes(maintenance_marker(secrets.token_hex(16)))
    return target


def export_git_bootstrap(output, target):
    bootstrap = output / "git-bootstrap"
    shutil.copytree(target, bootstrap)
    (bootstrap / "index.php").write_bytes(public_index((REPO / "website" / "public" / "index.php").read_bytes(), "legacy"))
    return bootstrap


def prepare_git_release(arguments):
    credentials = read_credentials(arguments.secrets_file, required=("mysql",))
    output = arguments.secrets_file.parent / "deployments" / (datetime.now().strftime("%Y%m%d-%H%M%S") + "-" + secrets.token_hex(3))
    output.mkdir(parents=True)
    build = Build(output, credentials["mysql"])
    try:
        build.prepare()
        plan = build.tool("plan")
        if plan["pending"]:
            raise DeploymentError("Database migrations are pending. Prepare a backed-up maintenance/migration deployment before publishing a Git release.")
        files = collect_files(output, reuse_vendor=False, layout="protected")
        target = export_git_release(output, files)
        report = {"layout": "protected", "source_commit": subprocess.check_output(["git", "-C", str(REPO), "rev-parse", "HEAD"]).decode().strip(),
                  "source_dirty": bool(subprocess.check_output(["git", "-C", str(REPO), "status", "--porcelain"])),
                  "files": len(files), "record_counts": plan["counts"], "verified_mysql_tls": plan["verified_tls"],
                  "remote_writes": False, "credentials_and_runtime_excluded": True}
        (output / "git-release-report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
        if arguments.publish_git_release:
            if report["source_dirty"]:
                raise DeploymentError("Commit source changes before publishing a production Git release.")
            publish_git_release(target, build)
            if arguments.bootstrap_git_release:
                publish_git_release(export_git_bootstrap(output, target), build, branch="bootstrap")
        print("Protected Git release prepared with production dependencies and built assets.")
        if arguments.publish_git_release:
            print("No FTP connection, database migration or direct hosting-file upload was performed.")
        else:
            print("No FTP connection, Git push, database migration or remote file change was performed.")
        print("Release folder: " + str(target))
    finally:
        build.close()
        print("Private deployment output: " + str(output))


def publish_git_release(target, build, branch="production"):
    if branch not in {"production", "bootstrap"}:
        raise DeploymentError("Unsupported release branch.")
    origin = build.run(["git", "-C", str(REPO), "remote", "get-url", "origin"], "git.log").decode().strip()
    if not re.fullmatch(r"(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)acharyajay007/yesenterprise-website(?:\.git)?", origin):
        raise DeploymentError("Git origin is not the intended website repository; no production push performed.")
    exists = build.run(["git", "ls-remote", "--heads", origin, "refs/heads/" + branch], "git.log").strip()
    clone = build.output / ("git-publish" if branch == "production" else "git-publish-bootstrap")
    if exists:
        build.run(["git", "clone", "--single-branch", "--branch", branch, origin, str(clone)], "git.log")
    else:
        clone.mkdir()
        build.run(["git", "-C", str(clone), "init", "-b", branch], "git.log")
        build.run(["git", "-C", str(clone), "remote", "add", "origin", origin], "git.log")

    def git(*arguments):
        return build.run(["git", "-C", str(clone), *arguments], "git.log")

    if os.name == "nt":
        git("config", "core.longpaths", "true")
    for key in ("user.name", "user.email", "user.signingkey", "commit.gpgsign", "gpg.format", "gpg.program", "gpg.ssh.program"):
        value = subprocess.run(["git", "-C", str(REPO), "config", "--get", key], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if value.returncode == 0:
            git("config", key, value.stdout.decode().strip())
        elif value.returncode != 1:
            raise DeploymentError("Could not read source Git author/signing configuration; no production push performed.")
    hooks = Path(build.run(["git", "-C", str(REPO), "rev-parse", "--git-path", "hooks"], "git.log").decode().strip())
    git("config", "core.hooksPath", str(hooks if hooks.is_absolute() else (REPO / hooks).resolve()))
    tracked = set(filter(None, git("ls-files", "-z").decode().split("\0")))
    current = {path.relative_to(target).as_posix() for path in target.rglob("*") if path.is_file()}
    for name in tracked:
        area, relative = ("private", name[len("yesenterprise-app/"):]) if name.startswith("yesenterprise-app/") else ("public", name)
        known_probe = area == "private" and any(
            relative == "deployment-access-probe/check." + extension
            and (clone / name).read_bytes() == probe_content(extension) for extension in ACCESS_PROBE_EXTENSIONS)
        if preserved(relative, area) and not known_probe:
            raise DeploymentError("Production branch tracks environment/runtime data; stop and remove sensitive data through a reviewed recovery process.")
    removed = sorted(tracked - current)
    for start in range(0, len(removed), 100):
        git("rm", "--", *removed[start:start + 100])
    for name in sorted(current):
        destination = clone / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(target / name, destination)
    git("add", "--all")
    if not git("diff", "--cached", "--name-only").strip():
        print(branch.capitalize() + " branch already matches the prepared snapshot; no commit or push needed.")
        return
    source = build.run(["git", "-C", str(REPO), "rev-parse", "HEAD"], "git.log").decode().strip()
    git("commit", "-m", "Update protected " + branch + " release", "-m",
        "Build production dependencies and public assets from source " + source + ".\n\n"
        "Exclude production credentials, private storage and owner uploads.\n\n"
        "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>")
    git("push", "-u", "origin", branch)
    print(branch.capitalize() + " branch pushed. Hosting deployment and preservation/HTTPS checks are still required.")


class NoMigrationRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        raise DeploymentError("Authenticated migration requests must not redirect.")


def migration_key(archive):
    with zipfile.ZipFile(archive) as package:
        environments = [name for name in (".env", "yesenterprise-app/.env") if name in package.namelist()]
        if len(environments) != 1:
            raise DeploymentError("Use the original private installation archive containing the production environment.")
        environment = package.read(environments[0]).decode("utf-8-sig")
    match = re.search(r"^APP_KEY\s*=\s*[\"']?(base64:[A-Za-z0-9+/=]+)[\"']?\s*$", environment, re.MULTILINE)
    if not match or len(match[1]) < 40:
        raise DeploymentError("The original production application key is missing from the private archive.")
    return hmac.digest(match[1].encode(), b"yesenterprise:git-layout:v1", "sha256")


def migration_request(url, key, action):
    parsed = urlsplit(url)
    staging = action in {"stage-init", "stage-check"}
    allowed = {"/yesenterprise-stage", "/yesenterprise-stage/"} if staging else {"", "/"}
    if parsed.scheme != "https" or not parsed.hostname or parsed.path not in allowed or parsed.query or parsed.fragment or parsed.username is not None:
        raise DeploymentError("Migration requires the live site's root HTTPS URL.")
    body = json.dumps({"action": action}, separators=(",", ":")).encode()
    timestamp, nonce = str(int(time.time())), secrets.token_hex(16)
    signature = hmac.new(key, timestamp.encode() + b"\n" + nonce.encode() + b"\n" + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(url.rstrip("/") + "/deployment-bootstrap.php", data=body,
                                     headers={"Content-Type": "application/json", "X-Deployment-Time": timestamp,
                                              "X-Deployment-Nonce": nonce, "X-Deployment-Signature": signature})
    opener = urllib.request.build_opener(NoMigrationRedirect())
    try:
        with opener.open(request, timeout=60) as response:
            result = json.loads(response.read())
            if response.status != 200 or not isinstance(result, dict) or "phase" not in result:
                raise DeploymentError("Invalid migration response; maintenance remains unchanged.")
            return result
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
        raise DeploymentError("Migration HTTP " + str(status) + "; inspect the private hosting PHP error log. No automatic maintenance removal.") from None


def migrate_git_layout(arguments):
    if arguments.environment_archive is None:
        raise DeploymentError("Provide the original private installation archive; production secrets are never read from Git.")
    key = migration_key(arguments.environment_archive)
    request = lambda action: migration_request(arguments.url, key, action)
    if arguments.migration_action in {"stage-init", "stage-check"}:
        verify_private_paths(arguments.url)
        result = request(arguments.migration_action)
        if result.get("environment_storage_uploads_unchanged") is not True:
            raise DeploymentError("Staging runtime preservation is unverified; do not deploy live.")
        print("Staging environment, private storage and owner-upload probes remain unchanged.")
        return
    if arguments.migration_action == "status":
        print(json.dumps(request("status")))
        return
    if arguments.migration_action == "cancel":
        print(json.dumps(request("cancel")))
        health_check(arguments.url)
        return
    verify_private_paths(arguments.url)
    if arguments.migration_action == "finish":
        migration_until(request, "finish", "cutover_verifying", "finished")
        health_check(arguments.url, protected=True)
        print("Protected production cutover verified. One-time migration endpoint disabled; original application retained.")
        return
    status = request("status")
    if status["phase"] not in {"not_started", "cancelled", "draining", "copying", "copied", "verifying", "ready"}:
        raise DeploymentError("Migration state requires review before continuing.")
    if status["phase"] in {"not_started", "cancelled"}:
        health_check(arguments.url, protected=True)
        status = request("begin")
    try:
        with urllib.request.urlopen(arguments.url.rstrip("/") + "/en", timeout=45):
            raise DeploymentError("The site must return HTTP 503 before backing up and copying runtime.")
    except urllib.error.HTTPError as error:
        error.close()
        if error.code != 503:
            raise DeploymentError("The site must return HTTP 503 before backing up and copying runtime.") from None
    if status["phase"] == "draining":
        wait = max(0, status["ready_after"] - int(time.time()))
        if wait > 600:
            raise DeploymentError("Unexpected request-drain interval; maintenance remains active.")
        time.sleep(wait + 1)
    credentials = read_credentials(arguments.secrets_file, required=("mysql",))
    output = arguments.secrets_file.parent / "deployments" / ("git-migration-" + datetime.now().strftime("%Y%m%d-%H%M%S") + "-" + secrets.token_hex(3))
    output.mkdir(parents=True)
    build = Build(output, credentials["mysql"])
    try:
        build.backup_database()
        if not (output / "database-before.sql").is_file() or not (output / "database-before.sql").stat().st_size:
            raise DeploymentError("The database backup is empty; maintenance remains active.")
    finally:
        build.close()
    if status["phase"] in {"draining", "copying"}:
        migration_until(request, "copy", "copying", "copied")
    result = migration_until(request, "verify", "verifying", "ready")
    if result.get("backups_verified") is not True:
        raise DeploymentError("Runtime verification did not finish; maintenance remains active.")
    print("Private runtime and server backups verified; trusted-TLS database backup saved privately.")
    print("Site remains in maintenance. Deploy production in hPanel, then run the finish operation.")
    print("Private database backup folder: " + str(output))


def migration_until(request, action, pending, complete):
    result, progress = request(action), -1
    while result["phase"] == pending:
        value = result.get("copied" if action == "copy" else "verified_files")
        if type(value) is not int or value <= progress:
            raise DeploymentError("Migration made no measurable progress; maintenance remains active.")
        progress = value
        result = request(action)
    if result["phase"] != complete:
        raise DeploymentError("Unexpected migration phase; maintenance remains active.")
    return result


def apply_changes(remote, build, files, changes, plan, output):
    marker = remote.private + "/storage/framework/maintenance.php"
    if remote.exists(marker):
        raise DeploymentError("The site is already in maintenance mode; resolve it before deploying.")
    old_index = remote.get(remote.public + "/index.php")
    if index_layout(old_index) != remote.layout:
        raise DeploymentError("Active deployment layout changed; stop and review before uploading.")
    with zipfile.ZipFile(output / "files-before.zip", "w", zipfile.ZIP_DEFLATED) as backup:
        for name, old in changes.items():
            if old is not None:
                backup.writestr(name, old)
    token = secrets.token_hex(16)
    maintenance = maintenance_marker(token)
    remote.put(marker, maintenance)
    try:
        if plan["pending"]:
            build.backup_database()
        def priority(name):
            if name in {"public/.htaccess", "private/.htaccess"}:
                return 0
            return 2 if name == "public/index.php" else 1

        for name in sorted(changes, key=priority):
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
        files = collect_files(output, reuse_vendor, layout=remote.layout)
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
            health_check(arguments.url, protected=remote.layout == "protected")
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
    action = parser.add_mutually_exclusive_group()
    action.add_argument("--apply", action="store_true", help="Apply FTPS changes; without this option the script is read-only remotely.")
    action.add_argument("--git-release", action="store_true", help="Build a protected production Git snapshot without uploading or migrating.")
    action.add_argument("--verify-protection", action="store_true", help="Verify HTTPS storefront health and HTTP 403 protection for private paths.")
    action.add_argument("--git-migrate", action="store_true", help="Authenticate a one-time server-side Git layout migration without FTP or File Manager.")
    parser.add_argument("--migration-action", choices=("prepare", "finish", "cancel", "status", "stage-init", "stage-check"), default="status")
    parser.add_argument("--environment-archive", type=Path, help="Original private installation ZIP containing the unchanged production APP_KEY.")
    parser.add_argument("--bootstrap-git-release", action="store_true", help="Also publish bootstrap with a legacy entry point; requires Git publication.")
    parser.add_argument("--publish-git-release", action="store_true", help="With --git-release, commit and push the snapshot to production; hosting may auto-deploy.")
    args = parser.parse_args()
    if args.publish_git_release and not args.git_release:
        parser.error("--publish-git-release requires --git-release")
    if args.bootstrap_git_release and not (args.git_release and args.publish_git_release):
        parser.error("--bootstrap-git-release requires --git-release and --publish-git-release")
    try:
        if args.git_release:
            prepare_git_release(args)
        elif args.git_migrate:
            migrate_git_layout(args)
        elif args.verify_protection:
            health_check(args.url, protected=True)
            print("HTTPS storefront health and protected-path HTTP 403 checks passed.")
        else:
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
