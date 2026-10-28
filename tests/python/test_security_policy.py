import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("policy", ROOT / "ci/security_policy.py")
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


def image(vulns=None):
    return {
        "SchemaVersion": 2,
        "Metadata": {"OS": {"Family": "debian"}},
        "Results": [{"Class": "os-pkgs", "Target": "debian", "Vulnerabilities": vulns or []}],
    }


def dependencies(vulns=None):
    return {
        "SchemaVersion": 2,
        "Results": [
            {"Type": "composer", "Target": "composer.lock", "Vulnerabilities": vulns or []},
            {"Type": "npm", "Target": "package-lock.json"},
        ],
    }


class SecurityPolicy(unittest.TestCase):
    def test_clean_reports_pass(self):
        self.assertTrue(policy.evaluate(image(), dependencies())["eligible"])

    def test_low_is_allowed(self):
        self.assertTrue(policy.evaluate(image([{"Severity": "LOW"}]), dependencies())["eligible"])

    def test_medium_high_critical_block_even_without_fixes(self):
        for severity in ["MEDIUM", "HIGH", "CRITICAL"]:
            with self.subTest(severity=severity):
                self.assertFalse(
                    policy.evaluate(
                        image([{"Severity": severity, "VulnerabilityID": "CVE-fixture"}]), dependencies()
                    )["eligible"]
                )

    def test_fixed_version_does_not_create_exemption(self):
        self.assertFalse(
            policy.evaluate(image([{"Severity": "MEDIUM", "FixedVersion": "2.0"}]), dependencies())[
                "eligible"
            ]
        )

    def test_composer_npm_findings_also_block(self):
        self.assertFalse(policy.evaluate(image(), dependencies([{"Severity": "MEDIUM"}]))["eligible"])

    def test_unknown_blocks(self):
        self.assertFalse(policy.evaluate(image([{}]), dependencies())["eligible"])

    def test_unrecognised_severity_fails_closed(self):
        with self.assertRaises(ValueError):
            policy.evaluate(image([{"Severity": "typo"}]), dependencies())

    def test_missing_or_malformed_reports_fail_closed(self):
        for value in [None, {}, [], {"SchemaVersion": 2}, {"SchemaVersion": 2, "Results": {}}]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                policy.inspect_report(value, "image")

    def test_missing_os_coverage_fails_closed(self):
        report = image()
        report["Results"] = []
        with self.assertRaises(ValueError):
            policy.evaluate(report, dependencies())

    def test_missing_lockfile_coverage_fails_closed(self):
        report = dependencies()
        report["Results"] = report["Results"][:1]
        with self.assertRaises(ValueError):
            policy.evaluate(image(), report)


if __name__ == "__main__":
    unittest.main()
