"""Synthetic genealogy only. Never commit the user's real genealogy files."""
import importlib.util
import io
from pathlib import Path
import sys
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('gedcom_poc_core', ROOT/'tools/gedcom_poc/core.py')
poc = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = poc
spec.loader.exec_module(poc)


def fixture(version='5.5.1', body=None):
    header = f'0 HEAD\n1 SOUR POC\n1 SUBM @U1@\n1 GEDC\n2 VERS {version}\n'
    if not version.startswith('7.'):
        header += '2 FORM LINEAGE-LINKED\n1 CHAR UTF-8\n'
    return (header + (body or '0 @I1@ INDI\n1 NAME Zoë /Proef/\n') + '0 @U1@ SUBM\n1 NAME POC testsubmitter\n0 TRLR\n').encode()


G7_FAMILY = '''0 @I1@ INDI
1 NAME Zoë /Proef/
1 SEX F
1 FAMS @F1@
1 BIRT
2 DATE 1 JAN 1900
2 PLAC Leiden
2 SOUR @S1@
3 PAGE 12
1 SNOTE @N1@
1 NOTE E-mail test@example.org; literal @example and @@two
2 CONT @@Starts with an at sign
0 @I2@ INDI
1 NAME René /Proef/
1 FAMS @F1@
0 @I3@ INDI
1 NAME Kind /Proef/
1 FAMC @F1@
0 @F1@ FAM
1 WIFE @I1@
1 HUSB @I2@
1 CHIL @I3@
1 MARR
2 DATE 1920
0 @N1@ SNOTE Gedeelde notitie — café
1 CONT Tweede regel
0 @S1@ SOUR
1 TITL Testregister
1 AUTH Testauteur
'''


class GedcomPocTests(unittest.TestCase):
    def test_551_preserves_text_fields_and_utf8(self):
        original = fixture(body='0 @I1@ INDI\n1 NAME Zoë /Proef/\n1 NOTE Café, € en 東京\n2 CONT Nog een regel\n')
        report, output = poc.inspect(original)
        self.assertEqual(output, original)
        self.assertTrue(report['all_nonheader_fields_preserved'])
        self.assertEqual(report['before'], report['after'])

    def test_aldfaer_81_ansi_and_crlf(self):
        original = fixture('5.5').decode().replace('1 SOUR POC', '1 SOUR ALDFAER\n2 VERS 8.1').replace('CHAR UTF-8','CHAR ANSI').replace('\n','\r\n').encode('cp1252')
        report, output = poc.inspect(original)
        self.assertEqual(report['encoding'], 'cp1252')
        self.assertIn('Zoë', output.decode())
        self.assertNotIn('ZoÙ', output.decode())

    def test_other_ansi_is_not_guessed(self):
        with self.assertRaises(poc.Rejected):
            poc.inspect(fixture().replace(b'CHAR UTF-8',b'CHAR ANSI'))

    def test_invalid_utf8_rejected(self):
        with self.assertRaises(poc.Rejected):
            poc.inspect(fixture().replace('Zoë'.encode(),b'Zo\xeb'))

    def test_utf16_preserves_accents(self):
        report, output = poc.inspect(fixture().decode().replace('CHAR UTF-8','CHAR UNICODE').encode('utf16'))
        self.assertEqual(report['encoding'], 'utf-16')
        self.assertIn('Zoë', output.decode())

    def test_7_shared_notes_relationships_sources_and_at_signs(self):
        report, output = poc.inspect(fixture('7.0',G7_FAMILY))
        self.assertEqual(report['blockers'], [])
        self.assertTrue(report['all_nonheader_fields_preserved'])
        self.assertEqual(report['after']['counts'], {'INDI':3,'FAM':1,'NOTE':1,'SOUR':1,'SUBM':1})
        self.assertEqual(report['before']['family_links'], 6)
        self.assertIn(b'1 NOTE @N1@', output)
        self.assertIn(b'0 @N1@ NOTE', output)
        self.assertIn(b'test@@example.org', output)
        self.assertIn(b'@@example and @@@@two', output)
        self.assertIn(b'2 CONT @@Starts', output)

    def test_7_patch_version(self):
        report, _ = poc.inspect(fixture('7.0.18'))
        self.assertTrue(report['ready_for_poc_parser'])

    def test_7_missing_submitter_blocks_instead_of_inventing_one(self):
        report, output = poc.inspect(fixture('7.0').replace(b'1 SUBM @U1@\n', b''))
        self.assertIsNone(output)
        self.assertIn({'code':'submitter_required_for_551'}, report['blockers'])

    def test_7_does_not_silently_drop_unsupported_structures(self):
        for line in ('1 NO DEAT', '1 _CUSTOM value', '1 EXID 123', '1 NOTE text\n2 MIME text/html',
                     '1 BIRT\n2 DATE 1700\n3 PHRASE Before winter',
                     '1 NAME Second /Name/\n2 TRAN Vertaling',
                     '1 NAME Multiple /Parts/\n2 SURN Multiple\n2 SURN Parts',
                     '1 FAMC @F1@\n2 PEDI BIRTH', '1 OBJE @O1@',
                     '1 NOTE text\n2 CONC invalid-for-7'):
            with self.subTest(line=line):
                report, output = poc.inspect(fixture('7.0', '0 @I1@ INDI\n1 NAME Zoë /Proef/\n'+line+'\n'))
                self.assertIsNone(output)
                self.assertTrue(any(b['code']=='unsupported_structure' for b in report['blockers']))

    def test_7_advanced_dates_and_long_lines_block(self):
        for line in ('1 BIRT\n2 DATE JULIAN 1 JAN 1700', '1 BIRT\n2 DATE AFT 1700',
                     '1 NOTE ' + 'x'*260, '1 SEX X', '1 FAMC @VOID@'):
            with self.subTest(line=line[:40]):
                report, output = poc.inspect(fixture('7.0', '0 @I1@ INDI\n'+line+'\n'))
                self.assertIsNone(output)

    def test_7_requires_utf8_and_no_char_header(self):
        for data in (fixture('7.0').replace(b'1 GEDC', b'1 CHAR UTF-8\n1 GEDC'),fixture('7.0').decode().encode('utf16')):
            with self.assertRaises(poc.Rejected):
                poc.inspect(data)

    def test_unsupported_versions(self):
        for version in ('7.1','8.0','5.5.5','garbage'):
            with self.assertRaises(poc.Rejected):
                poc.inspect(fixture(version))

    def test_wrong_user_choice_rejected(self):
        with self.assertRaises(poc.Rejected):
            poc.inspect(fixture(), 'aldfaer')

    def test_duplicates_broken_hierarchy_and_trailer_rejected(self):
        for body in ('0 @I1@ INDI\n0 @I1@ INDI\n', '0 @I1@ INDI\n3 NAME Test\n', '0 TRLR\n0 @I1@ INDI\n'):
            with self.assertRaises(poc.Rejected):
                poc.inspect(fixture(body=body))

    def test_missing_and_nonreciprocal_links_block(self):
        for body in ('0 @I1@ INDI\n1 FAMC @F1@\n', '0 @I1@ INDI\n1 FAMS @F1@\n0 @F1@ FAM\n'):
            report, output = poc.inspect(fixture(body=body))
            self.assertIsNone(output)
            self.assertTrue(report['blockers'])

    def test_relationship_target_types_block(self):
        report, output = poc.inspect(fixture(body='0 @I1@ INDI\n1 FAMC @I2@\n0 @I2@ INDI\n1 CHIL @I1@\n'))
        self.assertIsNone(output)
        self.assertEqual(report['before']['wrong_reference_types'], 1)

    def test_legacy_custom_fields_preserved_and_disclosed(self):
        original = fixture(body='0 @I1@ INDI\n1 _CUSTOM Keep me\n')
        report, output = poc.inspect(original)
        self.assertEqual(original, output)
        self.assertTrue(report['warnings'])

    def test_fingerprint_detects_changes_not_just_counts(self):
        a = poc.parse(fixture().decode())
        b = poc.parse(fixture().decode().replace('Zoë','René'))
        self.assertEqual(poc.audit(a)['counts'],poc.audit(b)['counts'])
        self.assertNotEqual(poc.semantic_fingerprint(a,'5.5.1'),poc.semantic_fingerprint(b,'5.5.1'))

    def test_alx_inspected_but_not_faked_as_gedcom(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data,'w') as z:
            z.writestr('sample.aldf', b'\x00\xe8\xfa\xad' + b'0'*100)
            z.writestr('sample.log', 'Bestandsherstel Persoon: geen pack, want 3 verwijderde records van de 10'.encode('utf16'))
        report, output = poc.inspect(data.getvalue(), 'aldfaer')
        self.assertIsNone(output)
        self.assertEqual(report['archive_crc'],'passed')
        self.assertEqual(report['maintenance_log_observations']['Persoon']['active_inferred'],7)

    def test_zip_traversal_and_zip_bomb_rejected(self):
        for name, content in (('../sample.aldf',b'hello'),('sample.aldf',b'0'*1000000)):
            data = io.BytesIO()
            with zipfile.ZipFile(data,'w',zipfile.ZIP_DEFLATED) as z:
                z.writestr(name,content)
            with self.assertRaises(poc.Rejected):
                poc.inspect(data.getvalue())


if __name__ == '__main__':
    unittest.main()
