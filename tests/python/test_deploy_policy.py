import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('deploy_policy', Path(__file__).resolve().parents[2] / 'ci/deploy_policy.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)
SHA = 'a' * 40
IMAGE = 'sha256:' + 'b' * 64

def evidence():
    run = {'id': 123, 'head_sha': SHA, 'head_branch': 'main', 'event': 'push', 'path': '.github/workflows/ci.yml', 'head_repository': {'full_name': 'centipod/schuit.info'}}
    jobs = {'total_count': 5, 'jobs': [{'name': name, 'status': 'completed', 'conclusion': 'success'} for name in sorted(policy.REQUIRED_JOBS)]}
    return run, jobs

class DeployPolicy(unittest.TestCase):
    def test_forced_command_retains_escaped_argument_quotes(self):
        import json
        entry = policy.authorized_key('ssh-ed25519 AAAA test')
        command, end = json.JSONDecoder().raw_decode(entry[len('restrict,command='):])
        self.assertTrue(command.endswith('"$SSH_ORIGINAL_COMMAND"'))
        self.assertIn(r'\"$SSH_ORIGINAL_COMMAND\"', entry)
        self.assertEqual(entry.count('\n'), 1)
        with self.assertRaises(ValueError):
            policy.authorized_key('ssh-ed25519 AAAA\nssh-ed25519 BBBB')

    def test_valid_release(self):
        run, jobs = evidence()
        policy.verify_run(run, jobs, SHA, '123', SHA)
        self.assertEqual(policy.parse_command('deploy ' + SHA + ' ' + IMAGE + ' 123'), (SHA, IMAGE, '123'))

    def test_arbitrary_commands_rejected(self):
        for command in ['', 'bash', 'deploy main latest 123', 'deploy ' + SHA + ' ' + IMAGE + ' 123; id', 'deploy ' + SHA + ' ' + IMAGE + ' 123\n']:
            with self.subTest(command=command), self.assertRaises(ValueError):
                policy.parse_command(command)

    def test_every_required_job_must_really_pass(self):
        for name in policy.REQUIRED_JOBS:
            for outcome in ['failure', 'cancelled', 'skipped', None]:
                run, jobs = evidence()
                next(job for job in jobs['jobs'] if job['name'] == name)['conclusion'] = outcome
                with self.subTest(name=name, outcome=outcome), self.assertRaises(ValueError):
                    policy.verify_run(run, jobs, SHA, '123', SHA)

    def test_old_commit_fork_and_other_workflow_rejected(self):
        for field, value in [('id', 124), ('head_sha', 'c'*40), ('head_branch', 'feature'), ('event', 'pull_request'), ('path', 'other.yml'), ('head_repository', {'full_name': 'fork/schuit.info'})]:
            run, jobs = evidence()
            run[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                policy.verify_run(run, jobs, SHA, '123', SHA)
        run, jobs = evidence()
        with self.assertRaises(ValueError):
            policy.verify_run(run, jobs, SHA, '123', 'c'*40)

    def test_missing_duplicate_incomplete_and_running_jobs_rejected(self):
        for mutation in ['missing', 'duplicate', 'incomplete', 'running']:
            run, jobs = evidence()
            if mutation == 'missing':
                jobs['jobs'].pop(); jobs['total_count'] -= 1
            elif mutation == 'duplicate':
                jobs['jobs'].append(copy.copy(jobs['jobs'][0])); jobs['total_count'] += 1
            elif mutation == 'incomplete':
                jobs['total_count'] += 1
            else:
                jobs['jobs'][0]['status'] = 'in_progress'
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                policy.verify_run(run, jobs, SHA, '123', SHA)

    def test_image_is_bound_to_commit_run_id_and_architecture(self):
        image = {'Id': IMAGE, 'Architecture': 'amd64', 'Config': {'Labels': {'org.opencontainers.image.revision': SHA, 'io.centipod.ci-run': '123'}}}
        policy.verify_image(image, SHA, '123', IMAGE)
        for field, value in [('Id', 'sha256:'+'c'*64), ('Architecture', 'arm64'), ('Config', {})]:
            changed = copy.deepcopy(image); changed[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                policy.verify_image(changed, SHA, '123', IMAGE)
        with self.assertRaises(ValueError):
            policy.verify_image(image, SHA, '124', IMAGE)

if __name__ == '__main__':
    unittest.main()
