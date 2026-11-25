import argparse
import importlib.util
import io
import json
import posixpath
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import Mock, patch

SCRIPT = Path(__file__).resolve().parents[1] / "deploy_website.py"
spec = importlib.util.spec_from_file_location("deploy_website", SCRIPT)
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)


class Remote:
    private = "/yesenterprise-app"
    public = "/public_html"

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
            "private": {".env": b"do-not-upload", "storage/app/private/resume.pdf": b"private",
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


if __name__ == "__main__":
    unittest.main()
