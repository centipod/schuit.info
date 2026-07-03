output "lightsail_static_ip" {
  value = module.lightsail_web.static_ip
}

output "lightsail_instance_name" {
  value = module.lightsail_web.instance_name
}

output "backup_bucket_name" {
  value = module.backup_bucket.bucket_name
}

output "backup_bucket_arn" {
  value = module.backup_bucket.bucket_arn
}

output "backup_access_key_id" {
  value = module.backup_bucket.access_key_id
}

output "backup_secret_access_key" {
  value     = module.backup_bucket.secret_access_key
  sensitive = true
}

output "route53_hosted_zone_id" {
  value = module.dns.hosted_zone_id
}

output "route53_name_servers" {
  value = module.dns.name_servers
}
