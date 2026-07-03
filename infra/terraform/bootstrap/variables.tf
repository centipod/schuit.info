variable "aws_region" {
  description = "AWS region for the Terraform backend resources."
  type        = string
  default     = "eu-west-1"
}

variable "project_name" {
  description = "Project tag used on Terraform bootstrap resources."
  type        = string
  default     = "schuit-info"
}

variable "state_bucket_name" {
  description = "S3 bucket used for remote Terraform state."
  type        = string
}

variable "lock_table_name" {
  description = "DynamoDB table used for Terraform state locking."
  type        = string
}

variable "tags" {
  description = "Extra tags to apply to bootstrap resources."
  type        = map(string)
  default     = {}
}
