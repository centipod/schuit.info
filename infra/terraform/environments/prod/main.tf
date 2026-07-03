provider "aws" {
  region = var.aws_region

  default_tags {
    tags = merge(
      {
        Project     = var.project_name
        Environment = var.environment
        ManagedBy   = "terraform"
      },
      var.tags
    )
  }
}

locals {
  tags = merge(
    {
      Project     = var.project_name
      Environment = var.environment
      ManagedBy   = "terraform"
    },
    var.tags
  )
}

module "backup_bucket" {
  source = "../../modules/backup-bucket"

  bucket_name            = var.backup_bucket_name
  iam_user_name          = var.backup_iam_user_name
  backup_retention_days  = var.backup_retention_days
  backup_noncurrent_days = var.backup_noncurrent_retention_days
  enable_versioning      = true
  tags                   = local.tags
}

module "lightsail_web" {
  source = "../../modules/lightsail-web"

  aws_region         = var.aws_region
  project_name       = var.project_name
  environment        = var.environment
  domain_name        = var.domain_name
  instance_name      = var.instance_name
  static_ip_name     = var.static_ip_name
  availability_zone  = var.lightsail_availability_zone
  blueprint_id       = var.lightsail_blueprint_id
  bundle_id          = var.lightsail_bundle_id
  key_pair_name      = var.lightsail_key_pair_name
  backup_bucket_name = module.backup_bucket.bucket_name
  ssh_cidrs          = var.ssh_cidrs
  tags               = local.tags
}

module "dns" {
  source = "../../modules/dns"

  domain_name        = var.domain_name
  static_ip          = module.lightsail_web.static_ip
  create_hosted_zone = var.manage_route53_zone
  hosted_zone_id     = var.hosted_zone_id
  www_target         = var.domain_name
  tags               = local.tags
}
