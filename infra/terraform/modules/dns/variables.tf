variable "domain_name" {
  description = "Apex domain to manage in Route 53."
  type        = string
}

variable "static_ip" {
  description = "Lightsail static IP used for the apex A record."
  type        = string
}

variable "create_hosted_zone" {
  description = "Whether to create the hosted zone."
  type        = bool
  default     = true
}

variable "hosted_zone_id" {
  description = "Existing hosted zone ID when create_hosted_zone is false."
  type        = string
  default     = ""
}

variable "www_target" {
  description = "Target hostname for the www CNAME record."
  type        = string
  default     = ""
}

variable "tags" {
  description = "Tags applied to the hosted zone."
  type        = map(string)
  default     = {}
}
