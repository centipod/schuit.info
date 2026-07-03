variable "aws_region" {
  description = "AWS region for deployment metadata."
  type        = string
}

variable "project_name" {
  description = "Project name used in tags and generated files."
  type        = string
}

variable "environment" {
  description = "Environment name used in generated files."
  type        = string
}

variable "domain_name" {
  description = "Canonical domain used by the bootstrap script."
  type        = string
}

variable "instance_name" {
  description = "Lightsail instance name."
  type        = string
}

variable "static_ip_name" {
  description = "Lightsail static IP name."
  type        = string
}

variable "availability_zone" {
  description = "Lightsail availability zone."
  type        = string
}

variable "blueprint_id" {
  description = "Lightsail blueprint ID."
  type        = string
}

variable "bundle_id" {
  description = "Lightsail bundle ID."
  type        = string
}

variable "key_pair_name" {
  description = "Lightsail SSH key pair name."
  type        = string
}

variable "backup_bucket_name" {
  description = "Backup bucket name embedded into the bootstrap environment file."
  type        = string
}

variable "ssh_cidrs" {
  description = "CIDR ranges allowed for SSH."
  type        = list(string)
  default     = ["0.0.0.0/0", "::/0"]
}

variable "tags" {
  description = "Tags applied to Lightsail resources."
  type        = map(string)
  default     = {}
}
