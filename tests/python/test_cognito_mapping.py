import importlib.util,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('prepare',Path(__file__).resolve().parents[2]/'scripts/prepare-cognito-users.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class IdentityMapping(unittest.TestCase):
    def test_preserves_ids_across_apps_without_roles(self):
        d=m.mappings({'wordpress':[{'id':2,'email':'Jan@example.invalid','roles':['administrator']}],'webtrees':[{'id':9,'email':'jan@example.invalid','approved':True,'verified':True}]})
        self.assertEqual(d,{'jan@example.invalid':{'email':'jan@example.invalid','wordpress':2,'webtrees':9}})
    def test_duplicate_email_cannot_claim_another_account(self):
        with self.assertRaises(ValueError):m.mappings({'wordpress':[{'id':2,'email':'x@example.invalid','roles':['subscriber']},{'id':3,'email':'X@example.invalid','roles':['administrator']}]})
    def test_unapproved_accounts_are_not_provisioned(self):
        with self.assertRaises(ValueError):m.mappings({'webtrees':[{'id':1,'email':'x@example.invalid','approved':False,'verified':True}]})
    def test_invalid_email_is_rejected(self):
        with self.assertRaises(ValueError):m.mappings({'wordpress':[{'id':2,'email':'invalid','roles':['administrator']}]})
