#!/usr/bin/env python3
"""Fail-closed release policy: every MEDIUM+ finding blocks, even without a fix."""

import argparse
import datetime
import hashlib
import json
import re
import sys
from pathlib import Path

BLOCKED = {"MEDIUM", "HIGH", "CRITICAL", "UNKNOWN"}


def inspect_report(report, kind):
    if (
        not isinstance(report, dict)
        or report.get("SchemaVersion") != 2
        or not isinstance(report.get("Results"), list)
    ):
        raise ValueError("Missing or unsupported scan report")
    results = report["Results"]
    if kind == "image" and (
        not report.get("Metadata", {}).get("OS", {}).get("Family")
        or not any(r.get("Class") == "os-pkgs" for r in results)
    ):
        raise ValueError("Image OS-package coverage missing")
    if kind == "dependencies" and not {"composer", "npm"}.issubset({r.get("Type") for r in results}):
        raise ValueError("Composer/npm dependency coverage missing")
    findings = []
    for result in results:
        for vuln in result.get("Vulnerabilities") or []:
            severity = str(vuln.get("Severity", "UNKNOWN")).upper()
            if severity not in {"LOW", "MEDIUM", "HIGH", "CRITICAL", "UNKNOWN"}:
                raise ValueError("Unrecognised severity")
            if severity in BLOCKED:
                findings.append(
                    {
                        "id": vuln.get("VulnerabilityID", "unknown"),
                        "severity": severity,
                        "package": vuln.get("PkgName", "unknown"),
                        "fixed_version": vuln.get("FixedVersion"),
                        "target": result.get("Target"),
                    }
                )
    return findings


def evaluate(image, dependencies):
    findings = inspect_report(image, "image") + inspect_report(dependencies, "dependencies")
    return {
        "policy": "deny-medium-and-above-v1",
        "eligible": not findings,
        "blocking_findings": len(findings),
        "findings": findings,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--image-report", required=True)
    parser.add_argument("--dependency-report", required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--image-id", required=True)
    parser.add_argument("--output", required=True)
    a = parser.parse_args()
    try:
        if not re.fullmatch("[a-f0-9]{40}", a.revision):
            raise ValueError("Full source commit required")
        if not re.fullmatch("sha256:[a-f0-9]{64}", a.image_id):
            raise ValueError("Immutable image ID required")
        image = Path(a.image_report).read_bytes()
        deps = Path(a.dependency_report).read_bytes()
        result = evaluate(json.loads(image), json.loads(deps))
        result.update(
            revision=a.revision,
            image_id=a.image_id,
            checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
            report_sha256={
                "image": hashlib.sha256(image).hexdigest(),
                "dependencies": hashlib.sha256(deps).hexdigest(),
            },
        )
    except (ValueError, OSError, TypeError, KeyError) as error:
        result = {"eligible": False, "error": str(error)}
    Path(a.output).write_text(json.dumps(result, indent=2) + "\n")
    print(
        "CVE gate: "
        + ("PASS" if result["eligible"] else "BLOCKED")
        + "; blocking findings="
        + str(result.get("blocking_findings", "scan unavailable"))
    )
    return 0 if result["eligible"] else 1


if __name__ == "__main__":
    sys.exit(main())
