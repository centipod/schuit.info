variable "aws_region" {
  description = "AWS region for the production stack."
  type        = string
  default     = "eu-west-1"
}

variable "project_name" {
  description = "Project tag used across AWS resources."
  type        = string
  default     = "schuit-info"
}

variable "environment" {
  description = "Environment name for resource tagging."
  type        = string
  default     = "prod"
}

variable "domain_name" {
  description = "Canonical public domain for the site."
  type        = string
  default     = "stichtingschu-y-i-ij-t.nl"
}

variable "manage_route53_zone" {
  description = "Whether Terraform should create and manage the Route 53 hosted zone."
  type        = bool
  default     = true
}

variable "hosted_zone_id" {
  description = "Existing Route 53 hosted zone ID when manage_route53_zone is false."
  type        = string
  default     = ""
}

variable "lightsail_availability_zone" {
  description = "Availability zone for the Lightsail instance."
  type        = string
  default     = "eu-west-1a"
}

variable "lightsail_blueprint_id" {
  description = "Lightsail blueprint ID for the Linux instance."
  type        = string
  default     = "ubuntu_24_04"
}

variable "lightsail_bundle_id" {
  description = "Lightsail bundle ID for the instance size."
  type        = string
}

variable "lightsail_key_pair_name" {
  description = "Lightsail SSH key pair name."
  type        = string
}

variable "instance_name" {
  description = "Lightsail instance name."
  type        = string
  default     = "schuit-info-prod"
}

variable "static_ip_name" {
  description = "Lightsail static IP name."
  type        = string
  default     = "schuit-info-prod-ip"
}

variable "backup_bucket_name" {
  description = "S3 bucket name for application backups."
  type        = string
  default     = "schuit-info-backups"
}

variable "backup_iam_user_name" {
  description = "IAM user name used by the instance or deploy tooling for S3 backups."
  type        = string
  default     = "schuit-info-backup-writer"
}

variable "backup_retention_days" {
  description = "Daily backup retention in days."
  type        = number
  default     = 30
}

variable "backup_noncurrent_retention_days" {
  description = "Non-current version retention in days."
  type        = number
  default     = 30
}

variable "ssh_cidrs" {
  description = "CIDR ranges allowed to reach SSH on the instance. For production, restrict to your office/home IP."
  type        = list(string)
  default     = ["0.0.0.0/0", "::/0"]
}

variable "tags" {
  description = "Additional tags applied to AWS resources."
  type        = map(string)
  default     = {}
}
