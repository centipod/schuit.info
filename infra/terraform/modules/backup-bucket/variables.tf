variable "bucket_name" {
  description = "Private bucket used for backups and archives."
  type        = string
}

variable "iam_user_name" {
  description = "IAM user that gets S3-only access to the backup bucket."
  type        = string
}

variable "backup_retention_days" {
  description = "Retention period for current backup objects."
  type        = number
  default     = 30
}

variable "backup_noncurrent_days" {
  description = "Retention period for non-current object versions."
  type        = number
  default     = 30
}

variable "enable_versioning" {
  description = "Whether S3 versioning should be enabled."
  type        = bool
  default     = true
}

variable "tags" {
  description = "Tags applied to bucket and IAM resources."
  type        = map(string)
  default     = {}
}
