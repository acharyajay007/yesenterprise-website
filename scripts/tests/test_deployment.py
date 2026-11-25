import argparse
import base64
import hashlib
import hmac
import importlib.util
import io
import json
import os
import posixpath
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import MagicMock, Mock, patch

SCRIPT = Path(__file__).resolve().parents[1] / "deploy_website.py"
spec = importlib.util.spec_from_file_location("deploy_website", SCRIPT)
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)


class Remote:
    private = "/yesenterprise-app"
    public = "/public_html"
    layout = "legacy"

    def __init__(self, existing=None):
        self.files = existing or {}
        self.writes = []
        self.removals = []

    def exists(self, path):
        return path in self.files

    def get(self, path):
        return self.files[path]

    def listing(self, directory):
        return {posixpath.basename(path): {"size": len(content)} for path, content in self.files.items()
                if posixpath.dirname(path) == directory}

    def put(self, path, content):
        self.writes.append(path)
        self.files[path] = content

    def remove_owned(self, path, expected):
        if self.files[path] != expected:
            raise deployment.DeploymentError("Marker changed")
        self.removals.append(path)
        del self.files[path]


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.addCleanup(self.directory.cleanup)

    def test_credential_reader_accepts_existing_section_format_without_other_account_credentials(self):
        file = self.root / "passwords.txt"
        file.write_text("## MYSQL:\nhost: db.example.invalid\nport: 3306\ndatabase: fixture\nusername: fixture\npassword: secret\n"
                        "## FTP:\nhostname: ftp.example.invalid\nport: 21\ndirectory: /public_html\nusername: fixture\npassword: secret\n"
                        "## GMAIL SMTP:\nusername: mail@example.invalid\npassword: smtp-secret\n", encoding="utf-8")
        data = deployment.read_credentials(file)
        self.assertEqual({"mysql", "ftp"}, set(data))
        self.assertEqual("secret", data["ftp"]["password"])
        file.write_text("## MYSQL:\nhost: db.example.invalid\n", encoding="utf-8")
        with self.assertRaises(deployment.DeploymentError):
            deployment.read_credentials(file)

    def test_packaging_excludes_production_environment_storage_and_owner_uploads(self):
        for area, entries in {
            "private": {".env": b"do-not-upload", ".env.production": b"do-not-upload",
                        "bootstrap/cache/config.php": b"cached-secrets", "storage/app/private/resume.pdf": b"private",
                        "vendor/autoload.php": b"vendor", "app/Models/Product.php": b"code"},
            "public": {"media/uploads/owner.webp": b"owner", "media/accurate-products/new.webp": b"photo", "showcase.css": b"css"},
        }.items():
            with zipfile.ZipFile(self.root / (area + ".zip"), "w") as archive:
                for name, content in entries.items():
                    archive.writestr(name, content)
        result = deployment.collect_files(self.root, reuse_vendor=True)
        self.assertEqual({"private/app/Models/Product.php", "public/media/accurate-products/new.webp", "public/showcase.css"}, set(result))
        self.assertNotIn(b"do-not-upload", result.values())

    def test_existing_media_is_never_overwritten_and_known_unchanged_files_are_skipped(self):
        remote = Remote({"/public_html/media/photo.webp": b"owner edit", "/public_html/showcase.css": b"same"})
        files = {"public/media/photo.webp": b"supplier default", "public/showcase.css": b"same",
                 "private/app/New.php": b"new"}
        state = {"public/showcase.css": {"sha256": deployment.digest(b"same")}}
        changes = deployment.plan_files(remote, files, state)
        self.assertEqual({"private/app/New.php": None}, changes)
        self.assertEqual([], remote.writes)

    def test_public_entry_point_keeps_existing_private_environment_and_storage(self):
        source = b"<?php\nrequire __DIR__.'/../vendor/autoload.php';\n$app=require __DIR__.'/../bootstrap/app.php';\n$app->handleRequest(Request::capture());"
        result = deployment.public_index(source)
        self.assertIn(b"../yesenterprise-app/vendor/autoload.php", result)
        self.assertIn(b"$app->usePublicPath(__DIR__)", result)
        self.assertNotIn(b".env", result)

    def test_apply_backs_up_files_and_database_and_preserves_existing_records_and_secrets(self):
        index = b"<?php // ../yesenterprise-app/storage/framework/maintenance.php"
        remote = Remote({"/public_html/index.php": index, "/yesenterprise-app/.env": b"production-key",
                         "/yesenterprise-app/storage/app/private/resume.pdf": b"resume"})
        build = Mock()
        build.tool.return_value = {"counts": {"users": 1, "products": 507, "submissions": 3}}
        plan = {"pending": ["new-schema"], "counts": build.tool.return_value["counts"]}
        files = {"public/index.php": b"new-index", "private/app/Model.php": b"new"}
        deployment.apply_changes(remote, build, files, dict.fromkeys(files), plan, self.root)
        build.backup_database.assert_called_once()
        build.tool.assert_called_once_with("migrate")
        self.assertEqual(b"production-key", remote.get("/yesenterprise-app/.env"))
        self.assertEqual(b"resume", remote.get("/yesenterprise-app/storage/app/private/resume.pdf"))
        self.assertFalse(remote.exists("/yesenterprise-app/storage/framework/maintenance.php"))
        self.assertLess(remote.writes.index("/yesenterprise-app/app/Model.php"), remote.writes.index("/public_html/index.php"))
        self.assertEqual(["/yesenterprise-app/storage/framework/maintenance.php"], remote.removals)

    def test_failed_migration_leaves_maintenance_and_does_not_publish_success_state(self):
        remote = Remote({"/public_html/index.php": b"<?php // ../yesenterprise-app/storage/framework/maintenance.php"})
        build = Mock()
        build.tool.side_effect = deployment.DeploymentError("Migration failed")
        with self.assertRaises(deployment.DeploymentError):
            deployment.apply_changes(remote, build, {}, {}, {"pending": ["new"], "counts": {}}, self.root)
        self.assertTrue(remote.exists("/yesenterprise-app/storage/framework/maintenance.php"))
        self.assertFalse(remote.exists("/yesenterprise-app/deployment-state.json"))
        self.assertEqual([], remote.removals)

    def test_an_existing_maintenance_marker_is_not_overwritten(self):
        remote = Remote({"/yesenterprise-app/storage/framework/maintenance.php": b"other-owner"})
        with self.assertRaises(deployment.DeploymentError):
            deployment.apply_changes(remote, Mock(), {}, {}, {"pending": [], "counts": {}}, self.root)
        self.assertEqual([], remote.writes)
        self.assertEqual(b"other-owner", remote.get("/yesenterprise-app/storage/framework/maintenance.php"))

    def test_ftps_always_verifies_certificates_and_encrypts_authentication_and_data(self):
        client = Mock()
        client.pwd.return_value = "/public_html"
        client.retrbinary.side_effect = lambda command, callback: callback(b"<?php // ../yesenterprise-app/storage/framework/maintenance.php")
        client.mlsd.side_effect = [
            [("yesenterprise-app", {"type": "dir"})],
            [(".env", {"type": "file"}), ("vendor", {"type": "dir"})],
            [("autoload.php", {"type": "file"})],
        ]
        with patch.object(deployment.ftplib, "FTP_TLS", return_value=client) as factory:
            remote = deployment.SecureFiles({"hostname": "ftp.example.invalid", "port": "21", "directory": "/public_html",
                                             "username": "fixture", "password": "secret"})
        context = factory.call_args.kwargs["context"]
        self.assertTrue(context.check_hostname)
        self.assertEqual(deployment.ssl.CERT_REQUIRED, context.verify_mode)
        client.auth.assert_called_once()
        client.prot_p.assert_called_once()
        self.assertLess(client.method_calls.index(unittest.mock.call.auth()),
                        client.method_calls.index(unittest.mock.call.login("fixture", "secret")))
        remote.close()

    def test_ftps_detects_the_protected_active_application_instead_of_the_old_sibling(self):
        client = Mock()
        client.pwd.return_value = "/public_html"
        client.retrbinary.side_effect = lambda command, callback: callback(b"<?php // yesenterprise-app/storage/framework/maintenance.php")
        listings = {
            "/": [("public_html", {"type": "dir"})],
            "/public_html": [("yesenterprise-app", {"type": "dir"})],
            "/public_html/yesenterprise-app": [(".env", {"type": "file"}), ("vendor", {"type": "dir"})],
            "/public_html/yesenterprise-app/vendor": [("autoload.php", {"type": "file"})],
        }
        client.mlsd.side_effect = lambda path: listings[path]
        with patch.object(deployment.ftplib, "FTP_TLS", return_value=client):
            remote = deployment.SecureFiles({"hostname": "ftp.example.invalid", "port": "21", "directory": "/public_html",
                                             "username": "fixture", "password": "secret"})
        self.assertEqual("protected", remote.layout)
        self.assertEqual("/public_html/yesenterprise-app", remote.private)

    def test_dry_run_has_no_remote_writes_or_migrations(self):
        credentials = {"mysql": {}, "ftp": {}}
        remote = Remote()
        remote.close = Mock()
        build = Mock()
        build.tool.return_value = {"pending": ["new"], "counts": {"users": 1}}
        arguments = argparse.Namespace(secrets_file=self.root / "passwords.txt", apply=False, url="https://example.invalid")
        with patch.object(deployment, "read_credentials", return_value=credentials), \
                patch.object(deployment, "SecureFiles", return_value=remote), \
                patch.object(deployment, "Build", return_value=build), \
                patch.object(deployment, "collect_files", return_value={"public/new.css": b"new"}), \
                patch.object(deployment, "health_check") as health:
            deployment.deploy(arguments)
        self.assertEqual([], remote.writes)
        build.tool.assert_called_once_with("plan")
        build.backup_database.assert_not_called()
        health.assert_not_called()

    def test_database_changes_are_not_accepted_silently(self):
        remote = Remote({"/public_html/index.php": b"<?php // ../yesenterprise-app/storage/framework/maintenance.php"})
        build = Mock()
        build.tool.return_value = {"counts": {"users": 0}}
        with self.assertRaises(deployment.DeploymentError):
            deployment.apply_changes(remote, build, {}, {}, {"pending": ["new"], "counts": {"users": 1}}, self.root)
        self.assertTrue(remote.exists("/yesenterprise-app/storage/framework/maintenance.php"))

    def test_atomic_upload_failure_does_not_delete_the_existing_destination(self):
        remote = deployment.SecureFiles.__new__(deployment.SecureFiles)
        remote.client = Mock()
        remote.client.rename.side_effect = deployment.ftplib.error_perm("550 Rename denied")
        remote.listings = {}
        remote.ensure_directory = Mock()
        with self.assertRaises(deployment.ftplib.error_perm):
            remote.put("/public_html/index.php", b"new entry point")
        remote.client.storbinary.assert_called_once()
        remote.client.delete.assert_not_called()

    def test_owned_marker_removal_checks_content_before_deleting(self):
        remote = deployment.SecureFiles.__new__(deployment.SecureFiles)
        remote.client = Mock()
        remote.get = Mock(return_value=b"another deployment")
        with self.assertRaises(deployment.DeploymentError):
            remote.remove_owned("/yesenterprise-app/storage/framework/maintenance.php", b"our deployment")
        remote.client.delete.assert_not_called()


    def test_protected_entry_point_loads_application_and_storage_below_public_html(self):
        source = (deployment.REPO / "website" / "public" / "index.php").read_bytes()
        result = deployment.public_index(source, "protected")
        self.assertIn(b"__DIR__.'/yesenterprise-app/vendor/autoload.php'", result)
        self.assertIn(b"__DIR__.'/yesenterprise-app/storage/framework/maintenance.php'", result)
        self.assertIn(b"$app->usePublicPath(__DIR__)", result)
        self.assertNotIn(b"__DIR__.'/../", result)
        self.assertEqual("protected", deployment.index_layout(result))
        with self.assertRaises(deployment.DeploymentError):
            deployment.public_index(source, "unknown")

    def release_files(self):
        return {
            "private/.htaccess": b"Require all denied\n",
            "private/vendor/autoload.php": b"<?php // dependency",
            "private/app/Support/GitLayoutMigration.php": b"<?php // migration fixture",
            "private/.gitignore": b"/vendor\n",
            "private/bootstrap/cache/.gitignore": b"*\n",
            "private/bootstrap/cache/packages.php": b"<?php return [];",
            "private/bootstrap/cache/services.php": b"<?php return [];",
            "public/.htaccess": (deployment.REPO / "website" / "public" / ".htaccess").read_bytes(),
            "public/index.php": deployment.public_index((deployment.REPO / "website" / "public" / "index.php").read_bytes(), "protected"),
            "public/build/manifest.json": b"{}",
        }

    def test_git_release_contains_dependencies_and_assets_but_no_runtime_or_conflicting_ignore_files(self):
        target = deployment.export_git_release(self.root, self.release_files())
        self.assertTrue((target / "yesenterprise-app/vendor/autoload.php").is_file())
        self.assertTrue((target / "build/manifest.json").is_file())
        self.assertFalse((target / "yesenterprise-app/.env").exists())
        self.assertFalse((target / "yesenterprise-app/storage").exists())
        self.assertFalse((target / "yesenterprise-app/.gitignore").exists())
        self.assertFalse((target / "yesenterprise-app/bootstrap/cache/.gitignore").exists())
        rules = (target / ".gitignore").read_text()
        self.assertIn("/yesenterprise-app/storage/", rules)
        self.assertIn("!/yesenterprise-app/bootstrap/cache/packages.php", rules)
        self.assertNotIn("/vendor", rules)
        with zipfile.ZipFile(self.root / ".deploy-protection.zip") as archive:
            self.assertTrue({".htaccess", "yesenterprise-app/.htaccess"}.issubset(archive.namelist()))
            self.assertEqual(2 + len(deployment.ACCESS_PROBE_EXTENSIONS), len(archive.namelist()))
        with zipfile.ZipFile(self.root / ".deploy-cutover.zip") as archive:
            self.assertEqual({"index.php"}, set(archive.namelist()))
        self.assertIn(b"http_response_code(503)", (self.root / ".deploy-maintenance.php").read_bytes())
        self.assertTrue((target / "deployment-bootstrap.php").is_file())
        for extension in deployment.ACCESS_PROBE_EXTENSIONS:
            self.assertEqual(deployment.probe_content(extension), (target / "yesenterprise-app/deployment-access-probe" / ("check." + extension)).read_bytes())

    def test_bootstrap_contains_production_files_but_keeps_the_original_application_active(self):
        production = deployment.export_git_release(self.root, self.release_files())
        bootstrap = deployment.export_git_bootstrap(self.root, production)
        self.assertEqual("legacy", deployment.index_layout((bootstrap / "index.php").read_bytes()))
        self.assertEqual("protected", deployment.index_layout((production / "index.php").read_bytes()))
        self.assertEqual((production / ".htaccess").read_bytes(), (bootstrap / ".htaccess").read_bytes())
        self.assertFalse((bootstrap / "yesenterprise-app/.env").exists())
        self.assertFalse((bootstrap / "yesenterprise-app/storage").exists())

    def test_migration_key_uses_existing_private_archive_and_never_a_new_key(self):
        key = "base64:" + base64.b64encode(bytes(32)).decode()
        archive = self.root / "private.zip"
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr(".env", 'APP_KEY="' + key + '"\nAPP_DEBUG=false\n')
        self.assertEqual(hmac.digest(key.encode(), b"yesenterprise:git-layout:v1", "sha256"), deployment.migration_key(archive))
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr("yesenterprise-app/.env", "APP_KEY=" + key + "\n")
        self.assertEqual(hmac.digest(key.encode(), b"yesenterprise:git-layout:v1", "sha256"), deployment.migration_key(archive))
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr("yesenterprise-app/.env", "APP_KEY=" + key + "\n")
            package.writestr(".env", "APP_KEY=" + key + "\n")
        with self.assertRaises(deployment.DeploymentError):
            deployment.migration_key(archive)
        with zipfile.ZipFile(archive, "w") as package:
            package.writestr(".env", "APP_DEBUG=false")
        with self.assertRaises(deployment.DeploymentError):
            deployment.migration_key(archive)

    def test_signed_migration_request_uses_https_post_and_rejects_redirects(self):
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.read.return_value = b'{"phase":"not_started"}'
        opener = Mock()
        opener.open.return_value = response
        key = bytes(32)
        with patch.object(deployment.urllib.request, "build_opener", return_value=opener):
            self.assertEqual("not_started", deployment.migration_request("https://example.invalid", key, "status")["phase"])
        request = opener.open.call_args.args[0]
        signed = (request.headers["X-deployment-time"] + "\n" + request.headers["X-deployment-nonce"] + "\n").encode() + request.data
        self.assertEqual(hmac.new(key, signed, hashlib.sha256).hexdigest(), request.headers["X-deployment-signature"])
        self.assertEqual("POST", request.get_method())
        for url in ("http://example.invalid", "https://example.invalid/stage", "https://user@example.invalid"):
            with self.assertRaises(deployment.DeploymentError):
                deployment.migration_request(url, key, "status")
        with self.assertRaises(deployment.DeploymentError):
            deployment.NoMigrationRedirect().redirect_request(request, None, 302, "", {}, "https://other.invalid")

    def test_migration_loops_require_measurable_progress_and_expected_terminal_state(self):
        request = Mock(side_effect=[{"phase": "copying", "copied": 50}, {"phase": "copying", "copied": 100}, {"phase": "copied"}])
        self.assertEqual("copied", deployment.migration_until(request, "copy", "copying", "copied")["phase"])
        stalled = Mock(side_effect=[{"phase": "copying", "copied": 50}, {"phase": "copying", "copied": 50}])
        with self.assertRaises(deployment.DeploymentError):
            deployment.migration_until(stalled, "copy", "copying", "copied")
        with self.assertRaises(deployment.DeploymentError):
            deployment.migration_until(Mock(return_value={"phase": "wrong"}), "finish", "cutover_verifying", "finished")

    def test_preparation_drains_then_backs_up_before_copy_and_does_not_cut_over(self):
        self.check_preparation_backup_order(fail=False)

    def test_failed_database_backup_leaves_maintenance_and_never_copies_or_finishes(self):
        self.check_preparation_backup_order(fail=True)

    def check_preparation_backup_order(self, fail):
        events = []
        file = self.root / "passwords.txt"
        file.write_text("MYSQL:\nhost: db.example.invalid\nport: 3306\ndatabase: fixture\nusername: fixture\npassword: secret\n")
        args = argparse.Namespace(environment_archive=self.root / "original.zip", migration_action="prepare",
                                  secrets_file=file, url="https://example.invalid")

        def request(url, key, action):
            events.append(action)
            return {"status": {"phase": "not_started"}, "begin": {"phase": "draining", "ready_after": 100},
                    "copy": {"phase": "copied"}, "verify": {"phase": "ready", "backups_verified": True}}[action]

        class Backup:
            def __init__(backup, output, db):
                backup.output = output

            def backup_database(backup):
                events.append("backup")
                if fail:
                    raise deployment.DeploymentError("Fixture database backup failed.")
                (backup.output / "database-before.sql").write_text("-- verified fixture backup")

            def close(backup):
                events.append("cleanup")

        maintenance = deployment.urllib.error.HTTPError(args.url, 503, "Maintenance", {}, None)
        with patch.object(deployment, "migration_key", return_value=bytes(32)), \
                patch.object(deployment, "migration_request", side_effect=request), \
                patch.object(deployment, "verify_private_paths"), patch.object(deployment, "health_check"), \
                patch.object(deployment.urllib.request, "urlopen", side_effect=maintenance), \
                patch.object(deployment.time, "time", return_value=100), \
                patch.object(deployment.time, "sleep", side_effect=lambda value: events.append("drain")), \
                patch.object(deployment, "Build", Backup):
            if fail:
                with self.assertRaises(deployment.DeploymentError):
                    deployment.migrate_git_layout(args)
                self.assertEqual(["status", "begin", "drain", "backup", "cleanup"], events)
            else:
                deployment.migrate_git_layout(args)
                self.assertEqual(["status", "begin", "drain", "backup", "cleanup", "copy", "verify"], events)

    def test_git_release_rejects_missing_assets_and_runtime_owned_paths(self):
        files = self.release_files()
        del files["public/build/manifest.json"]
        with self.assertRaises(deployment.DeploymentError):
            deployment.export_git_release(self.root, files)
        files = self.release_files()
        files["private/.env"] = b"must-not-publish"
        with self.assertRaises(deployment.DeploymentError):
            deployment.export_git_release(self.root, files)

    def test_git_preparation_requires_only_mysql_configuration(self):
        file = self.root / "passwords.txt"
        file.write_text("MYSQL:\nhost: db.example.invalid\nport: 3306\ndatabase: fixture\nusername: fixture\npassword: secret\n")
        self.assertEqual({"mysql"}, set(deployment.read_credentials(file, required=("mysql",))))

    def test_protected_upload_installs_denial_rules_before_application_code_and_index_last(self):
        remote = Remote({"/public_html/index.php": b"<?php // yesenterprise-app/storage/framework/maintenance.php"})
        remote.layout = "protected"
        remote.private = "/public_html/yesenterprise-app"
        build = Mock()
        build.tool.return_value = {"counts": {"users": 1}}
        files = self.release_files()
        files["private/app/New.php"] = b"code"
        deployment.apply_changes(remote, build, files, dict.fromkeys(files), {"pending": [], "counts": {"users": 1}}, self.root)
        self.assertLess(remote.writes.index("/public_html/.htaccess"), remote.writes.index("/public_html/yesenterprise-app/app/New.php"))
        self.assertLess(remote.writes.index("/public_html/yesenterprise-app/.htaccess"), remote.writes.index("/public_html/yesenterprise-app/app/New.php"))
        self.assertLess(remote.writes.index("/public_html/yesenterprise-app/app/New.php"), remote.writes.index("/public_html/index.php"))

    def test_protection_verification_requires_actual_403_responses_not_missing_files(self):
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.read.return_value = b"Yes Enterprise"
        denied = deployment.urllib.error.HTTPError("https://example.invalid", 403, "Forbidden", {}, None)
        with patch.object(deployment.urllib.request, "urlopen", side_effect=[response, response] + [denied] * (6 + len(deployment.ACCESS_PROBE_EXTENSIONS))) as request:
            deployment.health_check("https://example.invalid", protected=True)
            self.assertEqual(8 + len(deployment.ACCESS_PROBE_EXTENSIONS), request.call_count)
        missing = deployment.urllib.error.HTTPError("https://example.invalid", 404, "Missing", {}, None)
        with patch.object(deployment.urllib.request, "urlopen", side_effect=[response, response, missing]):
            with self.assertRaises(deployment.DeploymentError):
                deployment.health_check("https://example.invalid", protected=True)

    def test_git_publication_preserves_history_excludes_runtime_and_runs_source_hooks(self):
        files = self.release_files()
        files["public/obsolete.txt"] = b"old"
        source = self.root / "source"
        remote = self.root / "remote.git"
        config = self.root / "isolated-git-config"
        config.write_text("")
        source.mkdir()

        def git(*arguments):
            result = subprocess.run(["git", *map(str, arguments)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))
            return result.stdout

        class Publisher:
            def __init__(publisher, path):
                publisher.output = path
                path.mkdir()

            def run(publisher, arguments, filename):
                mapped = [str(remote) if argument == "https://github.com/acharyajay007/yesenterprise-website.git" else argument
                          for argument in arguments]
                result = subprocess.run(mapped, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                if result.returncode:
                    raise deployment.DeploymentError("Fixture Git command failed.")
                return result.stdout

        with patch.dict(os.environ, {"GIT_CONFIG_GLOBAL": str(config), "GIT_CONFIG_NOSYSTEM": "1"}), \
                patch.object(deployment, "REPO", source):
            git("init", "--bare", remote)
            git("-C", source, "init", "-b", "main")
            git("-C", source, "config", "user.name", "Deployment fixture")
            git("-C", source, "config", "user.email", "fixture@example.invalid")
            git("-C", source, "remote", "add", "origin", "https://github.com/acharyajay007/yesenterprise-website.git")
            (source / "source.txt").write_text("fixture")
            git("-C", source, "add", "--all")
            git("-C", source, "commit", "-m", "Initial fixture\n\nCo-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>")
            target = deployment.export_git_release(self.root, files)
            hook = source / ".git" / "hooks" / "pre-commit"
            hook.write_text("#!/bin/sh\nexit 1\n", encoding="utf-8")
            hook.chmod(0o755)
            with self.assertRaises(deployment.DeploymentError):
                deployment.publish_git_release(target, Publisher(self.root / "blocked"))
            self.assertEqual(b"", git("ls-remote", "--heads", remote, "production"))
            hook.unlink()
            deployment.publish_git_release(target, Publisher(self.root / "first"))
            (target / "obsolete.txt").unlink()
            (target / "showcase.css").write_text("updated")
            deployment.publish_git_release(target, Publisher(self.root / "second"))
            history = git("--git-dir", remote, "log", "--format=%H", "production").splitlines()
            self.assertEqual(2, len(history))
            tree = git("--git-dir", remote, "ls-tree", "-r", "--name-only", "production").decode().splitlines()
            self.assertIn("yesenterprise-app/vendor/autoload.php", tree)
            self.assertIn("build/manifest.json", tree)
            self.assertNotIn("yesenterprise-app/.env", tree)
            self.assertNotIn("obsolete.txt", tree)
            self.assertTrue(all(not name.startswith("yesenterprise-app/storage/") for name in tree))
            body = git("--git-dir", remote, "log", "-1", "--format=%B", "production").decode()
            self.assertIn("Co-authored-by: Copilot", body)
            self.assertEqual(b"main\n", git("-C", source, "branch", "--show-current"))


if __name__ == "__main__":
    unittest.main()
