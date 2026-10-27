#!/usr/bin/env python3
"""Reproduce real-file and downstream parser checks without any database writes."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from core import inspect, digest, parse, audit

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'tests/python'))
from test_gedcom_poc import fixture, G7_FAMILY


def downstream(runtime, output):
    with tempfile.TemporaryDirectory(prefix='schuit-poc-') as folder:
        source, normalized = Path(folder)/'input.ged', Path(folder)/'normalized.ged'
        source.write_bytes(output)
        source.chmod(0o600)
        result = subprocess.run(['php',str(Path(__file__).with_name('parser-check.php')),
                                 str(runtime.resolve()), str(source), str(normalized)],
                                check=True, capture_output=True, text=True)
        metrics = json.loads(result.stdout)
        actual = normalized.read_text()
        # The existing plugin's only expected data change is its requested
        # NAME slash-spacing normalization. Compare every output character.
        expected = '\n'.join(re.sub(r'(?<![ \t])/', ' /', line)
                             if re.match(r'^\d+ NAME ',line) else line
                             for line in output.decode().splitlines()) + '\n'
        if actual != expected:
            raise AssertionError('Downstream parser changed unexpected fields.')
        checked = audit(parse(actual))
        assert not any(checked[k] for k in ('missing_references','wrong_reference_types','nonreciprocal_family_links'))
        return {'accepted': True, 'only_expected_name_normalization': True,
                'counts': metrics['counts'], 'encoding': metrics['encoding'],
                'normalized_names': metrics['normalized_names'],
                'missing_references': metrics['missing_references'],
                'accent_counts': {c: actual.count(c) for c in ('ë','é')}}


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--webtrees-runtime',type=Path,required=True)
    ap.add_argument('--gedcom',type=Path)
    ap.add_argument('--aldfaer',type=Path)
    args = ap.parse_args()
    results = {'live_import':False, 'database_import_tested':False, 'cases':{}}
    samples = {'synthetic_551':fixture(), 'synthetic_7':fixture('7.0',G7_FAMILY)}
    for name, path in (('supplied_gedcom',args.gedcom),('supplied_aldfaer',args.aldfaer)):
        if path:
            samples[name] = path.read_bytes()
    for name, data in samples.items():
        report, output = inspect(data)
        if output:
            report['current_plugin_parser'] = downstream(args.webtrees_runtime, output)
        results['cases'][name] = report
    for name, path in (('supplied_gedcom',args.gedcom),('supplied_aldfaer',args.aldfaer)):
        if path:
            assert digest(path.read_bytes()) == digest(samples[name]), 'Original changed'
    results['originals_unchanged'] = True
    results['plugin_analyzer_sha256'] = digest((ROOT/'wordpress-plugins/schuit-gedcom/includes/analyze.php').read_bytes())
    print(json.dumps(results,ensure_ascii=False,indent=2))


if __name__ == '__main__':
    main()
