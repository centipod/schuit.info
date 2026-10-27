"""Offline import feasibility POC. Never connects to WordPress or a database.

Structural validation is not full GEDCOM schema validation. GEDCOM 7 conversion
is deliberately a small, fail-closed profile; unsupported structures block output.
"""
from collections import Counter
from dataclasses import dataclass, replace
import hashlib
import io
import json
from pathlib import PurePosixPath
import re
import stat
import zipfile

MAX_BYTES = 100 * 1024 * 1024
MAX_EXPANDED = 200 * 1024 * 1024
LINE = re.compile(r"^(\d+) (?:@([^@\s]+)@ )?([_A-Z][_A-Z0-9]*)(?: (.*))?$")
POINTER = re.compile(r"^@([^@\s]+)@$")


class Rejected(ValueError):
    pass


@dataclass(frozen=True)
class Row:
    level: int
    xref: str
    tag: str
    value: str
    number: int
    path: tuple
    owner: str


def digest(value):
    return hashlib.sha256(value).hexdigest()


def parse(text):
    rows, stack, owner, ids = [], [], "", set()
    for number, line in enumerate(text.lstrip('\ufeff').splitlines(), 1):
        if not line:
            continue
        if len(line.encode('utf-8')) > 131072 or '\0' in line or '\ufffd' in line:
            raise Rejected(f"Ongeldige tekens of te lange regel: {number}.")
        m = LINE.fullmatch(line)
        if not m:
            raise Rejected(f"Ongeldige GEDCOM-regel: {number}.")
        level, xref, tag, value = int(m[1]), m[2] or '', m[3], m[4] or ''
        if level > 9 or level > len(stack):
            raise Rejected(f"Niet-ondersteund of ontbrekend niveau: {number}.")
        if xref and level != 0:
            raise Rejected(f"Recordidentificatie buiten niveau nul: {number}.")
        if rows and rows[-1].tag == 'TRLR':
            raise Rejected('Gegevens na TRLR.')
        if level == 0:
            owner = xref
            if tag in ('HEAD', 'TRLR'):
                if xref or value:
                    raise Rejected('Ongeldige HEAD/TRLR.')
            elif not xref:
                raise Rejected(f"Record zonder identificatie: {number}.")
            if xref:
                if xref in ids:
                    raise Rejected(f"Dubbele identificatie op regel {number}.")
                ids.add(xref)
        stack = stack[:level] + [tag]
        rows.append(Row(level, xref, tag, value, number, tuple(stack), owner))
        if len(rows) > 1500000:
            raise Rejected('Te veel regels voor deze POC.')
    if not rows or rows[0].path != ('HEAD',) or rows[-1].path != ('TRLR',):
        raise Rejected('HEAD of TRLR ontbreekt.')
    if sum(r.path == ('HEAD',) for r in rows) != 1:
        raise Rejected('Meerdere HEAD-records.')
    return rows


def single(rows, path, required=True):
    values = [r.value for r in rows if r.path == path]
    if len(values) > 1 or (required and not values):
        raise Rejected('Ontbrekende of dubbele header: ' + '.'.join(path))
    return values[0] if values else ''


def decode(data):
    # Only this verified producer/version may resolve an ambiguous ANSI label.
    # Other ANSI producers require an explicit future encoding choice.
    header = re.sub(r'\r\n|\r', '\n', data[:65536].decode('ascii', 'ignore'))
    header = re.split(r'\n0 ', header, maxsplit=1)[0]
    char = re.search(r'^1 CHAR (.+)$', header, re.M)
    declared = char[1].strip() if char else ''
    aldfaer81 = re.search(r'^1 SOUR ALDFAER\n2 VERS 8\.1(?:\n|$)', header, re.M)
    warning = []
    if data.startswith((b'\xff\xfe', b'\xfe\xff')):
        codec = 'utf-16'
    elif data.startswith(b'\xef\xbb\xbf'):
        codec = 'utf-8-sig'
    elif declared == 'ANSI' and aldfaer81:
        codec = 'cp1252'
        warning.append('Aldfaer 8.1 ANSI wordt als Windows-1252 gelezen; controleer de accenten.')
    elif declared in ('UTF-8', ''):
        codec = 'utf-8'
    elif declared == 'ASCII':
        codec = 'ascii'
    else:
        raise Rejected('Tekencodering niet bewezen in deze POC; exporteer als UTF-8.')
    try:
        return data.decode(codec), codec, warning
    except UnicodeError as exc:
        raise Rejected('Ongeldige bytes voor de opgegeven tekencodering.') from exc


def audit(rows):
    counts = Counter(r.tag for r in rows if r.level == 0 and r.xref)
    ids = {r.xref: r.tag for r in rows if r.xref}
    refs = [r for r in rows if POINTER.fullmatch(r.value)]
    missing = [r for r in refs if r.value[1:-1] not in ids]
    edges = {(r.owner, r.tag, r.value[1:-1]) for r in refs
             if r.level == 1 and r.path[0] in ('INDI', 'FAM')}
    nonreciprocal = 0
    for owner, tag, target in edges:
        if tag in ('HUSB', 'WIFE'):
            nonreciprocal += (target, 'FAMS', owner) not in edges
        elif tag == 'CHIL':
            nonreciprocal += (target, 'FAMC', owner) not in edges
        elif tag == 'FAMC':
            nonreciprocal += (target, 'CHIL', owner) not in edges
        elif tag == 'FAMS':
            nonreciprocal += not ({(target, 'HUSB', owner), (target, 'WIFE', owner)} & edges)
    targets = {'FAMS': 'FAM', 'FAMC': 'FAM', 'HUSB': 'INDI', 'WIFE': 'INDI',
               'CHIL': 'INDI', 'SNOTE': 'SNOTE', 'NOTE': 'NOTE', 'SOUR': 'SOUR',
               'SUBM': 'SUBM', 'OBJE': 'OBJE', 'REPO': 'REPO'}
    wrong = sum(r.tag in targets and r.value[1:-1] in ids and
                ids[r.value[1:-1]] != targets[r.tag] for r in refs)
    return {'counts': dict(counts), 'records': sum(counts.values()),
            'lines': len(rows), 'references': len(refs), 'family_links': len({e for e in edges if e[1] in ('HUSB','WIFE','CHIL','FAMC','FAMS')}),
            'missing_references': len(missing), 'wrong_reference_types': wrong,
            'nonreciprocal_family_links': nonreciprocal,
            'custom_tags': dict(Counter(r.tag for r in rows if r.tag.startswith('_'))),
            'media_references': sum(r.tag == 'OBJE' and r.level > 0 for r in rows)}


def semantic_fingerprint(rows, version):
    """Compare all non-header fields/IDs/order, not merely record counts."""
    values = []
    for r in rows:
        if r.path[0] in ('HEAD', 'TRLR'):
            continue
        value = r.value
        if not POINTER.fullmatch(value):
            value = (value[1:] if value.startswith('@@') else value) if version.startswith('7.') else value.replace('@@', '@')
        values.append((r.level, r.xref, 'NOTE' if r.tag == 'SNOTE' else r.tag, value))
    return digest(json.dumps(values, ensure_ascii=False, separators=(',', ':')).encode())


# Deliberately small profile; unknown paths block conversion, including PHRASE,
# NO, TRAN, EXID, SCHMA, MIME, enum changes, media and calendar conversions.
G7_PATHS = {tuple(p.split('.')) for p in '''
HEAD HEAD.GEDC HEAD.GEDC.VERS HEAD.SOUR HEAD.SOUR.VERS HEAD.SOUR.NAME HEAD.SUBM
TRLR SUBM SUBM.NAME INDI INDI.NAME INDI.SEX INDI.FAMC INDI.FAMS
INDI.BIRT INDI.BIRT.DATE INDI.BIRT.PLAC INDI.DEAT INDI.DEAT.DATE INDI.DEAT.PLAC
FAM FAM.HUSB FAM.WIFE FAM.CHIL FAM.MARR FAM.MARR.DATE FAM.MARR.PLAC
SNOTE SNOTE.CONT SOUR SOUR.TITL SOUR.AUTH SOUR.PUBL SOUR.TEXT SOUR.TEXT.CONT
'''.split()}
for base in ('INDI', 'INDI.NAME', 'INDI.BIRT', 'INDI.DEAT', 'FAM', 'FAM.MARR'):
    for suffix in ('NOTE', 'NOTE.CONT', 'SNOTE', 'SOUR', 'SOUR.PAGE'):
        G7_PATHS.add(tuple((base + '.' + suffix).split('.')))


def convert7(rows):
    blockers, changes, output = [], Counter(), []
    if not any(r.path == ('HEAD','SUBM') for r in rows):
        # SUBM is optional in 7, required by 5.5.1. Never invent a submitter.
        blockers.append({'code': 'submitter_required_for_551'})
    for r in rows:
        if r.path not in G7_PATHS:
            blockers.append({'line': r.number, 'code': 'unsupported_structure', 'path': '.'.join(r.path)})
        if r.xref and not re.fullmatch(r'[A-Za-z0-9:_.-]{1,20}', r.xref):
            blockers.append({'line': r.number, 'code': 'unsupported_record_id'})
        if r.value == '@VOID@':
            blockers.append({'line': r.number, 'code': 'void_pointer'})
        if r.tag == 'DATE' and not re.fullmatch(r'(?:(?:[1-9]|[12][0-9]|3[01]) )?(?:(?:JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC) )?[0-9]{4}', r.value):
            blockers.append({'line': r.number, 'code': 'date_semantics_require_review'})
        if r.tag == 'SEX' and r.value not in ('M', 'F', 'U'):
            blockers.append({'line': r.number, 'code': 'sex_value_requires_review'})
        if r.tag in ('BIRT', 'DEAT', 'MARR') and r.value not in ('', 'Y'):
            blockers.append({'line': r.number, 'code': 'event_value_requires_review'})
        if r.tag in ('FAMC','FAMS','HUSB','WIFE','CHIL','SNOTE','SUBM') and r.level > 0 and not POINTER.fullmatch(r.value):
            blockers.append({'line': r.number, 'code': 'expected_pointer'})
        if r.tag == 'NOTE' and POINTER.fullmatch(r.value):
            blockers.append({'line': r.number, 'code': 'gedcom7_note_requires_text'})
        tag = 'NOTE' if r.tag == 'SNOTE' else r.tag
        if tag != r.tag:
            changes['shared_note_tag_mappings'] += 1
        value = r.value
        if r.path == ('HEAD', 'GEDC', 'VERS'):
            value = '5.5.1'
            changes['version_headers'] += 1
        elif not POINTER.fullmatch(value):
            text = value[1:] if value.startswith('@@') else value
            value = text.replace('@', '@@')
            if value != r.value:
                changes['text_at_sign_escaping'] += 1
        output.append(replace(r, tag=tag, value=value))
        if r.path == ('HEAD', 'GEDC', 'VERS'):
            output.append(Row(2, '', 'FORM', 'LINEAGE-LINKED', 0, (), ''))
            changes['required_form_headers'] += 1
        if r.path == ('HEAD',):
            output.append(Row(1, '', 'CHAR', 'UTF-8', 0, (), ''))
            changes['utf8_headers'] += 1
    for r in output:
        if len(render_row(r).encode()) > 255:
            blockers.append({'line': r.number, 'code': 'legacy_line_length_limit'})
    return output, blockers, dict(changes)


def render_row(row):
    return f'{row.level} ' + (f'@{row.xref}@ ' if row.xref else '') + row.tag + (' ' + row.value if row.value else '')


def inspect_aldfaer(data):
    report = {'format': 'Aldfaer', 'ready_for_poc_parser': False,
              'blockers': [{'code': 'native_converter_unavailable',
                            'message': 'Exporteer dit bestand in Aldfaer als GEDCOM. De binaire database wordt nog niet geconverteerd.'}]}
    if data[:4] == bytes.fromhex('00e8faad'):
        report['container'] = 'ALDF binary database'
        return report, None
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            entries = z.infolist()
            if len(entries) > 20 or sum(i.file_size for i in entries) > MAX_EXPANDED:
                raise Rejected('Aldfaer-archief overschrijdt de uitpaklimiet.')
            names = set()
            for i in entries:
                path = PurePosixPath(i.filename.replace('\\', '/'))
                mode = i.external_attr >> 16
                if (path.is_absolute() or '..' in path.parts or ':' in i.filename or
                    i.filename in names or stat.S_ISLNK(mode) or i.flag_bits & 1 or
                    i.file_size > max(i.compress_size, 1) * 500):
                    raise Rejected('Onveilig of niet-ondersteund Aldfaer-archief.')
                names.add(i.filename)
            if sum(i.filename.lower().endswith('.aldf') for i in entries) != 1:
                raise Rejected('Verwacht precies één ALDF-database in de reservekopie.')
            types = Counter()
            logs = []
            # Read every entry in bounded chunks to check CRC; never extract paths.
            for i in entries:
                suffix = PurePosixPath(i.filename).suffix.lower()
                types[suffix] += 1
                chunks, size = [], 0
                with z.open(i) as f:
                    while chunk := f.read(65536):
                        size += len(chunk)
                        if size > i.file_size or size > MAX_EXPANDED:
                            raise Rejected('Uitpaklimiet overschreden.')
                        if suffix == '.log' and size <= 1024 * 1024:
                            chunks.append(chunk)
                if suffix == '.log' and size <= 1024 * 1024:
                    try:
                        logs.append(b''.join(chunks).decode('utf-16'))
                    except UnicodeError:
                        pass
            report.update(container='ALX ZIP', archive_crc='passed', entry_types=dict(types),
                          expanded_bytes=sum(i.file_size for i in entries),
                          embedded_gedcom=bool(types['.ged']))
            # These are maintenance-log observations, never verified ALDF counts.
            observed = {}
            for log in logs:
                for label in ('Persoon', 'Relatie', 'Materiaal'):
                    for match in re.finditer(r'(?m)^.*\b' + label + r'\b[^\r\n]*', log):
                        counts = re.search(r'(\d+) verwijderde records van de (\d+)', match[0])
                        if counts:
                            deleted, total = map(int, counts.groups())
                            if total >= deleted:
                                observed[label] = {'total': total, 'deleted': deleted, 'active_inferred': total-deleted}
            report['maintenance_log_observations'] = observed
            report['counts_verified_from_database'] = False
            return report, None
    except (zipfile.BadZipFile, RuntimeError, NotImplementedError) as exc:
        raise Rejected('Het Aldfaer-archief kan niet veilig worden gelezen.') from exc


def inspect(data, selected='auto'):
    if not 20 <= len(data) <= MAX_BYTES:
        raise Rejected('Bestand moet tussen 20 bytes en 100 MB zijn.')
    native = data.startswith(b'PK\x03\x04') or data[:4] == bytes.fromhex('00e8faad')
    kind = 'aldfaer' if native else 'gedcom'
    if selected not in ('auto', 'aldfaer', 'gedcom') or selected not in ('auto', kind):
        raise Rejected('Gekozen bestandstype komt niet overeen met de inhoud.')
    if native:
        report, output = inspect_aldfaer(data)
    else:
        text, encoding, warnings = decode(data)
        rows = parse(text)
        version = single(rows, ('HEAD','GEDC','VERS'))
        char = single(rows, ('HEAD','CHAR'), False)
        if version not in ('5.5', '5.5.1') and not re.fullmatch(r'7\.0(?:\.\d+)?', version):
            raise Rejected('Niet-ondersteunde GEDCOM-versie; POC ondersteunt 5.5, 5.5.1 en een beperkt 7.0-profiel.')
        if version.startswith('7.') and (encoding not in ('utf-8','utf-8-sig') or char):
            raise Rejected('GEDCOM 7 vereist UTF-8 zonder CHAR-header.')
        before = audit(rows)
        blockers = []
        for key in ('missing_references', 'wrong_reference_types', 'nonreciprocal_family_links'):
            if before[key]:
                blockers.append({'code': key, 'count': before[key]})
        if not before['counts'].get('INDI'):
            blockers.append({'code': 'no_individuals'})
        if before['media_references']:
            warnings.append('Media worden niet geüpload; alleen bestaande verwijzingen blijven behouden bij GEDCOM 5.x.')
        if before['custom_tags']:
            warnings.append('Eigen tags worden bij GEDCOM 5.x ongewijzigd bewaard; hun betekenis in webtrees is niet bewezen.')
        if version.startswith('7.'):
            converted, unsupported, changes = convert7(rows)
            blockers.extend(unsupported)
            target = '5.5.1'
        else:
            converted = [replace(r, value='UTF-8') if r.path == ('HEAD','CHAR') else r for r in rows]
            if not char:
                converted.insert(1, Row(1,'','CHAR','UTF-8',0,(),''))
            changes = {'encoding_to_utf8': int(encoding not in ('utf-8','utf-8-sig'))}
            target = version
        candidate = ('\n'.join(map(render_row, converted)) + '\n').encode()
        after_rows = parse(candidate.decode())
        after = audit(after_rows)
        preserved = semantic_fingerprint(rows, version) == semantic_fingerprint(after_rows, target)
        if not preserved:
            blockers.append({'code': 'semantic_fingerprint_mismatch'})
        report = {'format': 'GEDCOM', 'source_version': version, 'target_version': target,
                  'encoding': encoding, 'before': before, 'after': after,
                  'all_nonheader_fields_preserved': preserved, 'changes': changes,
                  'blockers': blockers, 'warnings': warnings,
                  'ready_for_poc_parser': not blockers,
                  'validation_scope': 'Structure, pointers and conversion profile; not a complete GEDCOM schema or webtrees database import test.'}
        output = None if blockers else candidate
        if output:
            report['output_sha256'] = digest(output)
    report.update(input_bytes=len(data), input_sha256=digest(data), live_import=False)
    return report, output
