# Terraform Infrastructure

This directory contains the AWS infrastructure for the Schuit portal.

## Layout

- `bootstrap/` creates the remote Terraform state bucket and DynamoDB lock table.
- `environments/prod/` provisions the production Lightsail, S3 and Route 53 stack.
- `modules/` contains the reusable infrastructure modules.

## Bootstrap first

1. Go to `infra/terraform/bootstrap/`.
2. Copy `terraform.tfvars.example` to `terraform.tfvars` and fill in the values.
3. Run `terraform init` and `terraform apply`.
4. Note the state bucket and lock table names from the outputs.

## Configure remote state

Use the bootstrap bucket and lock table with the production backend config example:

- `infra/terraform/environments/prod/backend.hcl.example`

Then run:

```bash
terraform init -backend-config=backend.hcl
terraform apply
```

## DNS handoff

If `manage_route53_zone = true`, Terraform creates the Route 53 hosted zone for `stichtingschu-y-i-ij-t.nl` and outputs the four nameservers. Copy those NS values into the registrar.

## Production stack

The production stack provisions:

- A Lightsail Linux instance
- A Lightsail static IP
- Firewall ports for SSH, HTTP and HTTPS
- A private S3 backup bucket
- An IAM user and key for backup uploads
- Route 53 records for the apex and `www` hostnames

The bootstrap script prepares the server filesystem, package set and environment file; application deployment remains a separate release step.
